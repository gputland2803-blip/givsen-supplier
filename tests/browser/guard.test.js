// Page caching: a cached page for the wrong country reloads as its own ?gsup_c=XX variant, once, and never loops.
// Run: node tests/browser/guard.test.js   (needs the "playwright" package and Chromium)
'use strict';
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

let fail = 0;
function ok(cond, msg) { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) fail++; }
const guard = (c) => execFileSync('php', [path.join(__dirname, 'guard.php'), c]).toString();

// A page cache: without ?gsup_c the same (Australian) copy is served to everyone; with it, that country's copy.
async function site(browser, { cookie, restCountry, restFails }) {
  const ctx = await browser.newContext();
  if (cookie) await ctx.addCookies([{ name: 'gsup_country', value: cookie, url: 'https://givsen.test/' }]);
  const page = await ctx.newPage();
  const seen = { pages: [], rest: 0 };
  await page.route('**/*', (route) => {
    const u = new URL(route.request().url());
    if (u.pathname === '/wp-json/givsen-supplier/v1/country') {
      seen.rest++;
      if (restFails) return route.fulfill({ status: 500, body: 'error' });
      return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'Set-Cookie': 'gsup_country=' + restCountry + '; Path=/' }, body: JSON.stringify({ country: restCountry }) });
    }
    if (u.hostname !== 'givsen.test') return route.abort();
    const c = u.searchParams.get('gsup_c') || 'AU';
    seen.pages.push(u.pathname + u.search);
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><head><meta name="gsup-country" content="' + c + '"><script>' + guard(c) + '</script></head><body><h1>Shop for ' + c + '</h1></body></html>' });
  });
  return { page, seen, ctx };
}

(async () => {
  const browser = await chromium.launch();
  try {
    let s = await site(browser, { cookie: 'GB' });
    await s.page.goto('https://givsen.test/shop/?orderby=price');
    await s.page.waitForTimeout(400);
    ok(s.page.url() === 'https://givsen.test/shop/?orderby=price&gsup_c=GB' && (await s.page.textContent('h1')) === 'Shop for GB', 'visitor chose UK, cached page is Australian → reloads the UK copy (other parameters kept)');
    ok(s.seen.pages.length === 2 && s.seen.rest === 0, 'one reload, no extra request (cookie already known)');
    await s.ctx.close();

    s = await site(browser, { cookie: 'AU' });
    await s.page.goto('https://givsen.test/shop/');
    await s.page.waitForTimeout(400);
    ok(s.page.url() === 'https://givsen.test/shop/' && s.seen.pages.length === 1, 'cached copy already for the visitor’s country → nothing happens');
    await s.ctx.close();

    s = await site(browser, { restCountry: 'US' });
    await s.page.goto('https://givsen.test/product/pearl-necklace/');
    await s.page.waitForTimeout(600);
    ok(s.seen.rest === 1 && s.page.url() === 'https://givsen.test/product/pearl-necklace/?gsup_c=US' && (await s.page.textContent('h1')) === 'Shop for US', 'first visit, no cookie: asks the store once (uncached), then loads the US copy');
    ok(s.seen.pages.length === 2, 'no loop after landing on the right copy');
    await s.ctx.close();

    s = await site(browser, { cookie: 'NZ' });
    await s.page.goto('https://givsen.test/shop/?gsup_c=NZ');
    await s.page.waitForTimeout(400);
    ok(s.seen.pages.length === 1, 'already on the country’s copy → stays');
    await s.ctx.close();

    s = await site(browser, { cookie: 'AU' });
    await s.page.goto('https://givsen.test/shop/?gsup_c=GB');
    await s.page.waitForTimeout(400);
    ok(s.page.url() === 'https://givsen.test/shop/?gsup_c=AU', 'a shared link to another country’s copy → the visitor’s own country');
    ok(s.seen.pages.length === 2, 'and only once');
    await s.ctx.close();

    s = await site(browser, { restFails: true });
    await s.page.goto('https://givsen.test/shop/');
    await s.page.waitForTimeout(400);
    ok(s.seen.pages.length === 1 && s.page.url() === 'https://givsen.test/shop/', 'store unreachable → the page just stays as it is');
    await s.ctx.close();
  } finally {
    await browser.close();
  }
  console.log(fail ? fail + ' FAILED' : 'ALL PASSED');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
