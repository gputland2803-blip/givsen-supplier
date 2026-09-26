// Browser test for the extension's bulk import (bulk.js) against saved sample AliExpress pages.
// Run: node tests/browser/bulk.test.js   (needs the "playwright" package and Chromium)
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const root = path.join(__dirname, '..', '..');
const fixture = (name) => fs.readFileSync(path.join(__dirname, '..', 'fixtures', name), 'utf8');
const bulk = fs.readFileSync(path.join(root, 'givsen-supplier-extension', 'bulk.js'), 'utf8');

let fail = 0;
function ok(cond, msg) {
  console.log((cond ? 'PASS ' : 'FAIL ') + msg);
  if (!cond) fail++;
}

// Stands in for the extension's background worker and storage; records every message.
function stub(statuses) {
  window.__sent = [];
  window.__stored = {};
  // The bar lives in a closed shadow root; open it for the test so it can be read.
  const attach = Element.prototype.attachShadow;
  Element.prototype.attachShadow = function (init) { return attach.call(this, Object.assign({}, init, { mode: 'open' })); };
  window.chrome = {
    runtime: {
      lastError: null,
      sendMessage(msg, cb) {
        window.__sent.push(JSON.parse(JSON.stringify(msg)));
        let res = { ok: false };
        if (msg.type === 'gsup:status') {
          const out = {};
          msg.product_ids.forEach((id) => { out[id] = statuses[id] || ''; });
          res = { ok: true, statuses: out };
        } else if (msg.type === 'gsup:categories') {
          res = { ok: true, categories: [{ id: 15, name: 'Necklaces' }, { id: 16, name: 'Earrings' }] };
        } else if (msg.type === 'gsup:import-batch') {
          const n = msg.payload.items.length;
          res = { ok: true, added: n - 1, already: 1, failed: [], import_list: 'https://givsen.test/wp-admin/admin.php?page=givsen-supplier' };
        }
        setTimeout(() => cb(res), 10);
      },
    },
    storage: { local: { get(keys, cb) { cb({ lastCategory: '16' }); }, set(o) { Object.assign(window.__stored, o); } } },
  };
}

async function open(browser, url, html, statuses) {
  const page = await browser.newPage();
  await page.route('**/*', (route) => {
    if (route.request().url() === url) return route.fulfill({ status: 200, contentType: 'text/html', body: html });
    return route.abort(); // Pictures etc.: nothing leaves the machine.
  });
  await page.addInitScript(stub, statuses || {});
  await page.goto(url);
  await page.addScriptTag({ content: bulk });
  await page.waitForTimeout(100);
  return page;
}

const cardIds = (page) => page.$$eval('.gsup-bulk-pick', (els) => els.map((el) => {
  const a = el.parentElement.querySelector('a[href*="/item/"]');
  return /(\d{6,32})\.html/.exec(a.getAttribute('href'))[1];
}));
const bar = (page, sel) => page.evaluate((s) => {
  const host = document.getElementById('gsup-bulk-host');
  const el = host && host.shadowRoot.querySelector(s);
  return el ? { text: el.textContent, value: el.value, disabled: el.disabled } : null;
}, sel);
const sent = (page, type) => page.evaluate((t) => window.__sent.filter((m) => m.type === t), type);

(async () => {
  const browser = await chromium.launch();
  try {
    // --- Search results page.
    const first = '1005006000000001';
    const second = '1005006000000002';
    let page = await open(browser, 'https://www.aliexpress.com/w/wholesale-pearl-necklace.html?shipFromCountry=AU', fixture('ae-search.html'), { [first]: 'store', [second]: 'import' });
    let ids = await cardIds(page);
    ok(ids.length === 24, 'search: a checkbox on each of the 24 product cards');
    ok(ids.every((id, i) => id === String(1005006000000001 + i)), 'search: product IDs read from the /item/<id>.html links');
    ok(!ids.includes('1005009999999999'), 'search: a text link without a picture gets no checkbox');
    const status = await sent(page, 'gsup:status');
    ok(status.length === 1 && status[0].product_ids.length === 24, 'search: one status request for all cards');
    const labels = await page.$$eval('.gsup-bulk-pick', (els) => els.slice(0, 3).map((el) => [el.textContent, el.querySelector('input').checked]));
    ok(labels[0][0] === 'In store' && labels[1][0] === 'In import list' && labels[2][0] === 'Givsen', 'search: cards already in the store / import list are labelled');
    ok(labels.every((l) => !l[1]), 'search: every checkbox starts unticked');
    ok((await bar(page, '.count')).text === 'Tick products to add' && (await bar(page, 'button')).disabled, 'search: bar shows, Add disabled until something is ticked');
    ok((await bar(page, 'select')).value === '16', 'search: category remembers the last choice');

    const boxes = await page.$$('.gsup-bulk-pick input');
    for (const b of boxes.slice(2, 7)) await b.check();
    ok((await bar(page, 'button')).text === 'Add 5 to Givsen' && (await bar(page, '.count')).text === '5 selected', 'search: "Add 5 to Givsen" after ticking 5');
    await page.evaluate(() => document.getElementById('gsup-bulk-host').shadowRoot.querySelector('button').click());
    await page.waitForTimeout(100);
    const batch = await sent(page, 'gsup:import-batch');
    const items = batch[0] && batch[0].payload.items;
    ok(batch.length === 1 && items.length === 5 && items[0].product_id === '1005006000000003', 'search: batch sends the 5 ticked products');
    ok(items && items[0].title === 'Sterling Silver Pearl Pendant Necklace 3' && items[0].image === 'https://ae-pic-a1.aliexpress-media.com/kf/S0003.jpg_480x480.jpg', 'search: title and picture read from the card');
    ok(items && items[0].price === '13.30' && items[0].currency === 'AUD', 'search: price is the current one (not the crossed-out one), with currency');
    ok(batch[0] && JSON.stringify(batch[0].payload.category_ids) === '[16]', 'search: chosen category sent');
    const msg = await bar(page, '.msg');
    ok(/^Added 4, already there 1\b/.test(msg.text) && msg.text.includes('Open import list'), 'search: result summary shown');
    const after = await page.$$eval('.gsup-bulk-pick', (els) => els.slice(2, 7).map((el) => [el.textContent, el.querySelector('input').checked]));
    ok(after.every((a) => a[0] === 'In import list' && !a[1]), 'search: sent cards now say "In import list" and are unticked');

    // More results load as you scroll.
    await page.evaluate(() => { document.getElementById('more').innerHTML = document.getElementById('next-page').textContent; });
    await page.waitForTimeout(1200);
    ids = await cardIds(page);
    const status2 = await sent(page, 'gsup:status');
    ok(ids.length === 36 && status2.length === 2 && status2[1].product_ids.length === 12, 'search: new cards picked up; status asked only for the 12 new ones');

    // At most 30 per batch.
    for (const b of await page.$$('.gsup-bulk-pick input')) { if (!(await b.isDisabled())) await b.check(); }
    const ticked = await page.$$eval('.gsup-bulk-pick input', (els) => [els.filter((e) => e.checked).length, els.filter((e) => e.disabled).length]);
    ok(ticked[0] === 30 && ticked[1] === 6, 'search: stops at 30 ticked, the rest disabled');
    ok((await bar(page, '.count')).text === '30 selected (max 30)', 'search: bar says max 30');
    await page.close();

    // --- Store page (.us links, lazy-loaded pictures, two links per card).
    page = await open(browser, 'https://www.aliexpress.us/store/1102345678/pages/all-items.html', fixture('ae-store.html'));
    ids = await cardIds(page);
    ok(ids.length === 10 && ids[0] === '3256805000000001', 'store: one checkbox per product (10), even with two links per card');
    await (await page.$('.gsup-bulk-pick input')).check();
    await page.evaluate(() => document.getElementById('gsup-bulk-host').shadowRoot.querySelector('button').click());
    await page.waitForTimeout(100);
    const s = (await sent(page, 'gsup:import-batch'))[0].payload.items[0];
    ok(s.title === 'Gold Hoop Earrings 1' && s.image === 'https://ae01.alicdn.com/kf/H001.jpg' && s.price === '6' && s.currency === 'USD', 'store: title, lazy picture, price and currency read');
    await page.close();

    // --- Product page: related items get checkboxes, the product itself doesn't.
    page = await open(browser, 'https://www.aliexpress.com/item/1005006123456789.html', fixture('ae-product.html'));
    ids = await cardIds(page);
    ok(ids.length === 6 && !ids.includes('1005006123456789'), 'product page: 6 related items, not the product you are on');
    await page.close();

    // --- A page without product cards shows nothing.
    page = await open(browser, 'https://www.aliexpress.com/p/order/index.html', fixture('ae-empty.html'));
    ok((await page.$$('.gsup-bulk-pick')).length === 0 && !(await page.$('#gsup-bulk-host')), 'no cards: no checkboxes and no bar');
    ok((await sent(page, 'gsup:status')).length === 0, 'no cards: nothing sent to the store');
    await page.close();
  } finally {
    await browser.close();
  }
  console.log(fail ? fail + ' FAILED' : 'ALL PASSED');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
