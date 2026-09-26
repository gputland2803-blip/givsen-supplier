'use strict';

const $ = (id) => document.getElementById(id);

function normaliseSite(raw) {
  let s = String(raw || '').trim();
  if (!s) return '';
  if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
  const u = new URL(s);
  let path = u.pathname.replace(/\/?(wp-admin.*|wp-json.*)?$/i, '');
  return u.origin + path.replace(/\/+$/, '') + '/';
}

function status(ok, text) {
  const el = $('status');
  el.className = ok ? 'ok' : 'err';
  el.textContent = text;
}

async function load() {
  const s = await chrome.storage.local.get(['site', 'key']);
  $('site').value = s.site || '';
  $('key').value = s.key || '';
}

$('save').addEventListener('click', async () => {
  let site;
  try {
    site = normaliseSite($('site').value);
  } catch (e) {
    status(false, 'That site address doesn’t look right. Example: https://givsen.com/');
    return;
  }
  const key = $('key').value.trim();
  if (!site || !key) {
    status(false, 'Fill in both the site address and the connection key.');
    return;
  }
  // Chrome asks once to allow the extension to talk to your site.
  const origin = new URL(site).origin + '/*';
  const granted = await chrome.permissions.request({ origins: [origin] });
  if (!granted) {
    status(false, 'Chrome needs your permission to contact ' + new URL(site).host + '. Click Save & test again and choose Allow.');
    return;
  }
  await chrome.storage.local.set({ site, key });
  $('site').value = site;
  $('save').disabled = true;
  status(true, 'Testing…');
  const res = await chrome.runtime.sendMessage({ type: 'gsup:ping', settings: { site, key } });
  $('save').disabled = false;
  if (res && res.ok) {
    status(true, 'Connected to ' + (res.site || site) + ' (Givsen Supplier ' + res.version + '). You’re ready — open any AliExpress product page.');
  } else {
    status(false, (res && res.message) || 'Couldn’t connect.');
  }
});

load();
