/* Givsen Supplier — "Add to Givsen" button on AliExpress product pages.
 * Reads the product ID and the selected option, shows them for a quick check, then sends them to the import list. */
(function () {
  'use strict';
  if (window.__gsupContentReady) return;
  window.__gsupContentReady = true;

  var M = window.GsupMatch;
  var host, shadow, card, button;

  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}',
    '.btn{position:fixed;right:20px;bottom:24px;z-index:2147483646;background:#111;color:#fff;border:0;border-radius:999px;padding:12px 18px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25)}',
    '.btn:hover{background:#333}',
    '.card{position:fixed;right:20px;bottom:80px;z-index:2147483647;width:360px;max-width:calc(100vw - 40px);background:#fff;color:#1d2327;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.3);padding:16px;font-size:13px;line-height:1.4}',
    '.card h3{margin:0 0 10px;font-size:15px}',
    '.row{margin-bottom:9px}',
    '.row label{display:block;color:#646970;font-size:12px;margin-bottom:3px}',
    '.row input{width:100%;padding:7px 9px;border:1px solid #c3c4c7;border-radius:6px;font-size:13px;color:#1d2327;background:#fff}',
    '.row input[readonly]{background:#f6f7f7}',
    '.row select{width:100%;padding:7px 9px;border:1px solid #c3c4c7;border-radius:6px;font-size:13px;color:#1d2327;background:#fff}',
    '.hint{font-size:12px;color:#996800;margin:-4px 0 9px}',
    '.hint.ok{color:#1e6b34}',
    '.actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}',
    '.actions button{border-radius:6px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #111}',
    '.send{background:#111;color:#fff}',
    '.send[disabled]{opacity:.6;cursor:default}',
    '.cancel{background:#fff;color:#111}',
    '.msg{margin-top:10px;padding:9px 10px;border-radius:6px;font-size:13px}',
    '.msg.ok{background:#edfaef;color:#1e6b34}',
    '.msg.err{background:#fcf0f1;color:#b32d2e}',
    '.msg a{color:inherit;font-weight:600}',
    '.bk{margin-top:12px;padding-top:10px;border-top:1px solid #e0e0e0}',
    '.bk h4{margin:0 0 6px;font-size:13px}',
    '.bk .list{max-height:150px;overflow:auto;border:1px solid #c3c4c7;border-radius:6px;margin-top:4px}',
    '.bk .opt{padding:6px 8px;cursor:pointer;border-bottom:1px solid #f0f0f1;font-size:12px}',
    '.bk .opt:hover,.bk .opt.sel{background:#f0f6fc}',
    '.bk .opt small{display:block;color:#646970}',
    '.bk .go{margin-top:8px;background:#fff;color:#111;border:1px solid #111;border-radius:6px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer}',
    '.bk .go[disabled]{opacity:.5;cursor:default}',
    '.bk .note{font-size:12px;color:#996800;margin-top:6px}',
    '.bk table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}',
    '.bk td{padding:2px 4px;border-bottom:1px solid #f0f0f1}',
  ].join('');

  function isItemPage() {
    return !!M.productIdFromUrl(location.href);
  }

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text) e.textContent = text;
    return e;
  }

  function ensureUi() {
    if (host) return;
    host = el('div', { id: 'gsup-host' });
    shadow = host.attachShadow({ mode: 'closed' });
    var style = el('style');
    style.textContent = CSS;
    shadow.appendChild(style);
    button = el('button', { class: 'btn', type: 'button' }, 'Add to Givsen');
    button.addEventListener('click', openCard);
    shadow.appendChild(button);
    document.documentElement.appendChild(host);
  }

  function toggleUi() {
    if (isItemPage()) {
      ensureUi();
      host.style.display = '';
    } else if (host) {
      host.style.display = 'none';
      closeCard();
    }
  }

  function askPage() {
    return new Promise(function (resolve) {
      var nonce = Math.random().toString(36).slice(2);
      var done = false;
      function onMsg(e) {
        if ((e.source && e.source !== window) || !e.data || e.data.source !== 'gsup-page' || e.data.nonce !== nonce) return;
        done = true;
        window.removeEventListener('message', onMsg);
        resolve(e.data.data || { skus: [], props: [] });
      }
      window.addEventListener('message', onMsg);
      window.postMessage({ source: 'gsup-ext', type: 'req', nonce: nonce }, location.origin);
      setTimeout(function () {
        if (!done) {
          window.removeEventListener('message', onMsg);
          resolve({ skus: [], props: [] });
        }
      }, 1500);
    });
  }

  /** Selected options as the page shows them, e.g. [{name:'Color', value:'Red'}, {name:'Ships From', value:'Australia'}]. */
  function readSelected() {
    var out = [];
    var seen = {};
    var blocks = document.querySelectorAll('[class*="sku-item--property"], .sku-property, [class*="sku--property"], [class*="skuProperty"]');
    Array.prototype.forEach.call(blocks, function (b) {
      var titleEl = b.querySelector('[class*="sku-item--title"], .sku-title, [class*="sku-title"], [class*="skuTitle"]');
      var t = M.splitTitle(titleEl ? titleEl.textContent : '');
      if (!t.name) return;
      var value = t.value;
      if (!value) {
        var sel = b.querySelector('[class*="selected"]');
        if (sel) {
          var img = sel.querySelector('img');
          value = (sel.getAttribute('title') || (img && img.getAttribute('alt')) || sel.textContent || '').replace(/\s+/g, ' ').trim();
        }
      }
      var key = M.norm(t.name);
      if (seen[key]) return;
      seen[key] = true;
      out.push({ name: t.name, value: value });
    });
    return out;
  }

  function pageTitle() {
    var h1 = document.querySelector('h1');
    var og = document.querySelector('meta[property="og:title"]');
    var t = (h1 && h1.textContent) || (og && og.getAttribute('content')) || document.title || '';
    return t.replace(/\s+/g, ' ').trim().slice(0, 500);
  }

  function pageImage() {
    var og = document.querySelector('meta[property="og:image"]');
    var src = og ? og.getAttribute('content') || '' : '';
    if (src.indexOf('//') === 0) src = 'https:' + src;
    return /^https:\/\//.test(src) ? src : '';
  }

  function pagePrice() {
    var p = document.querySelector('[class*="price--current"], [class*="product-price-current"], [class*="price-default--current"], [class*="price--currentPriceText"]');
    return p ? p.textContent.replace(/\s+/g, ' ').trim().slice(0, 32) : '';
  }

  /**
   * Words about the product that are on the page but not in AliExpress's API: its "AI overview of item",
   * any written description, and the specifications. Used as a starting description in your store.
   * (Descriptions load as you scroll — scroll down to the description first to capture it too.)
   */
  function pageText() {
    var parts = [];
    var seen = {};
    function add(label, node) {
      if (!node) return;
      var t = (node.innerText || '').replace(/\r/g, '');
      t = t.split('\n').map(function (l) { return l.replace(/\s+/g, ' ').trim(); }).filter(function (l) {
        return l && !/^(AI overview of item|Disclaimer\s*:|View (More|less)$|Description$|Specifications?$|Report Item)/i.test(l);
      }).join('\n');
      if (t.length < 20 || seen[t]) return;
      seen[t] = true;
      parts.push(label + ':\n' + t);
    }
    // "AI overview of item": find the heading, then the block around it.
    var labels = document.querySelectorAll('div, span, h2, h3, h4');
    for (var i = 0; i < labels.length; i++) {
      var el = labels[i];
      if (el.childElementCount <= 2 && /^\s*AI overview/i.test(el.textContent || '') && (el.textContent || '').length < 40) {
        var box = el.parentElement;
        for (var up = 0; box && up < 4 && (box.innerText || '').length < 120; up++) box = box.parentElement;
        // Each bullet starts with a bold heading: keep it apart ("Heading: text").
        var items = box ? box.querySelectorAll('li') : [];
        if (items.length) {
          var lines = Array.prototype.map.call(items, function (li) {
            var t = (li.innerText || '').replace(/\s+/g, ' ').trim();
            var b = li.querySelector('b, strong');
            var h = b ? (b.innerText || '').replace(/\s+/g, ' ').trim() : '';
            return h && t.indexOf(h) === 0 && t.length > h.length ? h.replace(/[:.]$/, '') + ': ' + t.slice(h.length).trim() : t;
          });
          add('Overview', { innerText: lines.join('\n') });
        } else {
          add('Overview', box);
        }
        break;
      }
    }
    add('Description', document.querySelector('#product-description, [class*="description--product-description"], .detail-desc-decorate-richtext, .detailmodule_text'));
    // Specifications: each row is a name and a value in separate boxes — join them as "Name: value".
    var specList = document.querySelector('[class*="specification--list"], #nav-specification ul, [class*="specification--wrap"]');
    if (specList) {
      var rows = [];
      Array.prototype.forEach.call(specList.querySelectorAll('li, [class*="specification--prop"]'), function (li) {
        var cells = Array.prototype.map.call(li.children, function (c) { return (c.innerText || '').replace(/\s+/g, ' ').trim(); }).filter(Boolean);
        var line = cells.length >= 2 ? cells[0].replace(/:$/, '') + ': ' + cells.slice(1).join(' ') : (li.innerText || '').replace(/\s+/g, ' ').trim();
        if (line && rows.indexOf(line) < 0) rows.push(line);
      });
      if (rows.length) {
        var fake = { innerText: rows.join('\n') };
        add('Specifications', fake);
      }
    }
    return parts.join('\n\n').slice(0, 8000);
  }

  function closeCard() {
    if (card) {
      card.remove();
      card = null;
    }
  }

  function field(label, name, value, readonly) {
    var row = el('div', { class: 'row' });
    row.appendChild(el('label', { for: 'gsup-' + name }, label));
    var input = el('input', { id: 'gsup-' + name, name: name, type: 'text', autocomplete: 'off' });
    input.value = value || '';
    if (readonly) input.setAttribute('readonly', '');
    row.appendChild(input);
    return row;
  }

  function categoryField() {
    var row = el('div', { class: 'row' });
    row.appendChild(el('label', { for: 'gsup-category' }, 'Category in your store'));
    var select = el('select', { id: 'gsup-category', name: 'category' });
    select.appendChild(el('option', { value: '' }, 'Loading categories…'));
    select.disabled = true;
    row.appendChild(select);
    select.addEventListener('change', function () {
      try { chrome.storage.local.set({ lastCategory: select.value }); } catch (e) { /* ignore */ }
    });
    try {
      chrome.runtime.sendMessage({ type: 'gsup:categories' }, function (res) {
        select.innerHTML = '';
        if (chrome.runtime.lastError || !res || !res.ok) {
          select.appendChild(el('option', { value: '' }, 'Choose later (couldn’t load categories)'));
          select.disabled = false;
          return;
        }
        select.appendChild(el('option', { value: '' }, 'Choose later'));
        (res.categories || []).forEach(function (c) {
          select.appendChild(el('option', { value: String(c.id) }, c.name));
        });
        select.disabled = false;
        chrome.storage.local.get(['lastCategory'], function (s) {
          var last = s && s.lastCategory ? String(s.lastCategory) : '';
          if (last && select.querySelector('option[value="' + last.replace(/[^0-9]/g, '') + '"]')) select.value = last;
        });
      });
    } catch (e) {
      select.innerHTML = '';
      select.appendChild(el('option', { value: '' }, 'Choose later'));
      select.disabled = false;
    }
    return row;
  }

  async function openCard() {
    closeCard();
    var productId = M.productIdFromUrl(location.href);
    var selected = readSelected();
    var data = await askPage();
    var found = M.resolve(selected, data);
    var urlSku = M.skuIdFromUrl(location.href);
    if (!found.skuId && urlSku) {
      found.skuId = urlSku;
      found.reason = 'from link';
    }

    card = el('div', { class: 'card', role: 'dialog', 'aria-label': 'Add to Givsen' });
    card.appendChild(el('h3', {}, 'Add to Givsen'));
    card.appendChild(field('AliExpress product ID', 'product_id', productId, true));
    card.appendChild(field('Option', 'option', found.option));
    card.appendChild(field('Ships from', 'ship_from', found.shipFrom));
    card.appendChild(field('SKU ID', 'sku_id', found.skuId));
    card.appendChild(categoryField());
    var captured = pageText();
    var words = captured ? captured.split(/\s+/).length : 0;
    card.appendChild(el('div', { class: 'hint' + (words ? ' ok' : '') },
      words ? 'Product text from this page: ' + words + ' words (used as the starting description).'
            : 'No product text found on the page yet — scroll down to the description, then click Add to Givsen again if you want it.'));

    var hint = el('div', { class: 'hint' });
    if (found.skuId) {
      hint.className = 'hint ok';
      hint.textContent = 'Option found. Check it matches what you picked.';
    } else if (/^choose:/.test(found.reason)) {
      hint.textContent = 'Pick every option on the page first (' + found.reason.replace(/^choose:\s*/, '') + '), then click Add to Givsen again.';
    } else {
      hint.textContent = 'Couldn’t read the option ID from this page (' + found.reason + '). You can still send it — the option text is kept, and you can add the SKU later.';
    }
    card.appendChild(hint);

    var actions = el('div', { class: 'actions' });
    var cancel = el('button', { class: 'cancel', type: 'button' }, 'Close');
    var send = el('button', { class: 'send', type: 'button' }, 'Send to import list');
    cancel.addEventListener('click', closeCard);
    send.addEventListener('click', function () { sendIt(send); });
    actions.appendChild(cancel);
    actions.appendChild(send);
    card.appendChild(actions);
    card.appendChild(backupBox(productId));
    shadow.appendChild(card);
  }

  function value(name) {
    var i = card.querySelector('input[name="' + name + '"]');
    return i ? i.value.trim() : '';
  }

  function showMsg(ok, nodes) {
    var old = card.querySelector('.msg');
    if (old) old.remove();
    var m = el('div', { class: 'msg ' + (ok ? 'ok' : 'err') });
    nodes.forEach(function (n) { m.appendChild(typeof n === 'string' ? document.createTextNode(n) : n); });
    card.appendChild(m);
  }

  /* ---------- Use as backup for a product in your store ---------- */

  function backupBox(productId) {
    var box = el('div', { class: 'bk' });
    box.appendChild(el('h4', {}, 'Use as backup for a product in your store'));
    var search = el('input', { type: 'search', placeholder: 'Search your linked products…', 'aria-label': 'Search your linked products' });
    search.style.cssText = 'width:100%;padding:7px 9px;border:1px solid #c3c4c7;border-radius:6px;font-size:13px';
    var list = el('div', { class: 'list', role: 'listbox' });
    var note = el('div', { class: 'note' });
    var go = el('button', { class: 'go', type: 'button' }, 'Save as backup supplier');
    var out = el('div', {});
    go.disabled = true;
    box.appendChild(search); box.appendChild(list); box.appendChild(note); box.appendChild(go); box.appendChild(out);

    var chosen = null;
    var confirmReplace = false;
    var timer = null;

    function choose(p, row) {
      chosen = p;
      confirmReplace = false;
      Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('sel'); });
      row.classList.add('sel');
      out.textContent = '';
      if (p.ae_product_id === productId) {
        note.textContent = 'This listing is already that product’s main supplier.';
        go.disabled = true;
      } else {
        note.textContent = p.has_backup ? 'This replaces the current backup (ID ' + p.backup_id + ').' : '';
        go.disabled = false;
      }
      go.textContent = 'Save as backup supplier';
    }

    function load(q) {
      list.textContent = '';
      list.appendChild(el('div', { class: 'opt' }, 'Loading…'));
      try {
        chrome.runtime.sendMessage({ type: 'gsup:linked', search: q }, function (res) {
          list.textContent = '';
          if (chrome.runtime.lastError || !res || !res.ok) {
            list.appendChild(el('div', { class: 'opt' }, (res && res.message) || 'Couldn’t load your products.'));
            return;
          }
          if (!res.products.length) {
            list.appendChild(el('div', { class: 'opt' }, 'No linked products match.'));
            return;
          }
          res.products.forEach(function (p) {
            var row = el('div', { class: 'opt', role: 'option' }, p.name);
            var sub = 'AliExpress ' + p.ae_product_id + (p.ships_from ? ' · ships from ' + p.ships_from : '') + (p.has_backup ? ' · has a backup (' + p.backup_id + ')' : '') + (p.ae_product_id === productId ? ' · this listing is its supplier' : '');
            row.appendChild(el('small', {}, sub));
            row.addEventListener('click', function () { choose(p, row); });
            list.appendChild(row);
          });
        });
      } catch (e) {
        list.textContent = '';
        list.appendChild(el('div', { class: 'opt' }, 'The extension was updated or restarted. Refresh this page and try again.'));
      }
    }
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { load(search.value.trim()); }, 300);
    });
    load('');

    go.addEventListener('click', function () {
      if (!chosen) return;
      if (chosen.has_backup && !confirmReplace) {
        confirmReplace = true;
        note.textContent = 'This replaces the current backup (ID ' + chosen.backup_id + '). Click again to confirm.';
        go.textContent = 'Confirm: replace the backup';
        return;
      }
      var ship = value('ship_from');
      go.disabled = true;
      go.textContent = 'Matching options…';
      chrome.runtime.sendMessage({ type: 'gsup:backup', payload: { wc_product_id: chosen.id, product_id: productId, ship_from: ship, replace: confirmReplace } }, function (res) {
        go.textContent = 'Save as backup supplier';
        go.disabled = false;
        out.textContent = '';
        if (chrome.runtime.lastError || !res) {
          out.appendChild(el('div', { class: 'msg err' }, 'The extension was updated or restarted. Refresh this page and try again.'));
          return;
        }
        if (!res.ok) {
          if (res.code === 'gsup_backup_exists' && res.data && res.data.current_backup) {
            chosen.has_backup = true; chosen.backup_id = res.data.current_backup; confirmReplace = false;
            note.textContent = 'This replaces the current backup (ID ' + chosen.backup_id + '). Click Save again, then confirm.';
          }
          out.appendChild(el('div', { class: 'msg err' }, res.message || 'Something went wrong.'));
          return;
        }
        var m = el('div', { class: 'msg ok' });
        m.appendChild(document.createTextNode('Saved as backup: ' + res.matched + ' of ' + res.total + ' options matched' + (res.replaced ? ' (replaced ' + res.replaced + ')' : '') + '. '));
        if (res.unmatched && res.unmatched.length) {
          m.appendChild(document.createTextNode('Not matched: ' + res.unmatched.join('; ') + '. '));
        }
        if (res.cost && (res.cost.current || res.cost.backup)) {
          m.appendChild(document.createTextNode('Cost with delivery — now ' + res.cost.current.toFixed(2) + ', backup ' + res.cost.backup.toFixed(2) + ' ' + (res.cost.currency || '') + '. '));
        }
        m.appendChild(link(res.supplier_url, 'Open the Supplier tab'));
        out.appendChild(m);
        if (res.cost && res.cost.items && res.cost.items.length > 1) {
          var t = el('table', {});
          res.cost.items.forEach(function (r) {
            var tr = el('tr', {});
            tr.appendChild(el('td', {}, r.name));
            tr.appendChild(el('td', {}, r.current == null ? '—' : r.current.toFixed(2)));
            tr.appendChild(el('td', {}, r.backup == null ? '—' : '→ ' + r.backup.toFixed(2)));
            t.appendChild(tr);
          });
          out.appendChild(t);
        }
        chosen.has_backup = true; chosen.backup_id = productId; confirmReplace = false;
      });
    });
    return box;
  }

  function link(href, text) {
    var a = el('a', { href: href, target: '_blank', rel: 'noopener noreferrer' }, text);
    return a;
  }

  function sendIt(btn) {
    var payload = {
      product_id: value('product_id'),
      sku_id: value('sku_id'),
      ship_from: value('ship_from'),
      option: value('option'),
      title: pageTitle(),
      image: pageImage(),
      price: pagePrice(),
      url: location.href.split('#')[0],
      page_text: pageText(),
      category_ids: (function () {
        var c = card.querySelector('select[name="category"]');
        return c && c.value ? [parseInt(c.value, 10)] : [];
      })(),
    };
    btn.disabled = true;
    btn.textContent = 'Sending…';
    try {
      chrome.runtime.sendMessage({ type: 'gsup:import', payload: payload }, function (res) {
        btn.disabled = false;
        btn.textContent = 'Send to import list';
        if (chrome.runtime.lastError || !res) {
          showMsg(false, ['The extension was updated or restarted. Refresh this page and try again.']);
          return;
        }
        if (!res.ok) {
          showMsg(false, [res.message || 'Something went wrong.']);
          return;
        }
        var parts = [res.duplicate ? 'Already in your import list — updated. ' : 'Added to your import list. '];
        if (res.categories && res.categories.length) {
          parts.push('Category: ' + res.categories.join(', ') + '. ');
        }
        if (res.api_note && res.api_note.indexOf('Checked with AliExpress') !== 0) {
          parts.push(res.api_note + ' ');
        } else if (res.option) {
          parts.push('AliExpress confirmed: ' + res.option + '. ');
        }
        if (res.linked && res.linked.length) {
          parts.push('Already linked in your store: ');
          res.linked.forEach(function (p, i) {
            if (i) parts.push(', ');
            parts.push(link(p.edit_url, p.name));
          });
          parts.push('. ');
        }
        if (res.import_list) parts.push(link(res.import_list, 'Open import list'));
        showMsg(true, parts);
      });
    } catch (e) {
      btn.disabled = false;
      btn.textContent = 'Send to import list';
      showMsg(false, ['The extension was updated or restarted. Refresh this page and try again.']);
    }
  }

  toggleUi();
  var last = location.href;
  setInterval(function () {
    if (location.href !== last) {
      last = location.href;
      toggleUi();
    }
  }, 1000);
})();
