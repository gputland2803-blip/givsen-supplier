/* Givsen Supplier — background service worker.
 * Signs requests with the connection key and sends them to the store's import endpoint. */
'use strict';

const API_BASE = '?rest_route=/givsen-supplier/v1/';
let categoryCache = null; // { at, res } — store categories, kept for 10 minutes

async function getSettings() {
  const s = await chrome.storage.local.get(['site', 'key']);
  return { site: s.site || '', key: s.key || '' };
}

async function hmacHex(key, message) {
  const enc = new TextEncoder();
  const k = await crypto.subtle.importKey('raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = await crypto.subtle.sign('HMAC', k, enc.encode(message));
  return Array.from(new Uint8Array(sig)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function callStore(route, method, payload, settings) {
  const { site, key } = settings || (await getSettings());
  if (!site || !key) {
    return { ok: false, message: 'Not set up yet. Click the Givsen Supplier icon in Chrome’s toolbar and paste your site address and connection key.' };
  }
  const origin = new URL(site).origin + '/*';
  if (chrome.permissions && !(await chrome.permissions.contains({ origins: [origin] }))) {
    return { ok: false, message: 'Chrome hasn’t been allowed to reach your site yet. Open the extension settings and click Save & test.' };
  }
  const body = method === 'GET' ? '' : JSON.stringify(payload || {});
  const ts = String(Math.floor(Date.now() / 1000));
  // Signs the method and endpoint too (signature version 2), so a request can't be reused elsewhere.
  const signature = await hmacHex(key, ts + '.' + method + '.' + '/' + route + '.' + body);
  let res;
  try {
    res = await fetch(site + API_BASE + route, {
      method,
      credentials: 'omit',
      cache: 'no-store',
      headers: Object.assign(
        { 'X-Gsup-Timestamp': ts, 'X-Gsup-Signature': signature, 'X-Gsup-Sig-Version': '2' },
        method === 'GET' ? {} : { 'Content-Type': 'application/json' }
      ),
      body: method === 'GET' ? undefined : body,
    });
  } catch (e) {
    return { ok: false, message: 'Couldn’t reach ' + site + ' — check the site address and your connection.' };
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  if (!res.ok || !data || data.ok !== true) {
    const msg = data && data.message ? data.message : 'The site answered with an error (' + res.status + ').';
    return { ok: false, message: msg, status: res.status };
  }
  return Object.assign({ ok: true }, data);
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || typeof msg.type !== 'string') return false;
  if (msg.type === 'gsup:import') {
    callStore('import', 'POST', msg.payload).then(sendResponse);
    return true;
  }
  if (msg.type === 'gsup:categories') {
    const fresh = categoryCache && Date.now() - categoryCache.at < 10 * 60 * 1000;
    if (fresh && !msg.reload) {
      sendResponse(categoryCache.res);
      return false;
    }
    callStore('categories', 'GET').then((res) => {
      if (res && res.ok) categoryCache = { at: Date.now(), res };
      sendResponse(res);
    });
    return true;
  }
  if (msg.type === 'gsup:ping') {
    callStore('ping', 'GET', null, msg.settings).then(sendResponse);
    return true;
  }
  if (msg.type === 'gsup:open-settings') {
    chrome.runtime.openOptionsPage();
    return false;
  }
  return false;
});

chrome.action.onClicked.addListener(() => chrome.runtime.openOptionsPage());
