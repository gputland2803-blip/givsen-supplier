/* Givsen Supplier — bulk import from AliExpress search results, store pages and "related items".
 *
 * Adds a checkbox to every product card and a floating "Add N to Givsen" bar. Cards are found from their links
 * (/item/<id>.html), never from AliExpress's class names, so layout changes don't break it. If a page has no
 * product cards, nothing is shown. Cards already in your store or import list are labelled and start unticked. */
(function () {
  'use strict';
  if (window.__gsupBulkReady) return;
  window.__gsupBulkReady = true;

  var MAX = 30;
  var ITEM_RE = /\/item\/(?:[^\/?#]*\/)?(\d{6,32})\.html/i;
  var cards = {};      // product ID → { el, box, label, data }
  var status = {};     // product ID → 'store' | 'import' | '' (asked already)
  var bar, shadow, countEl, addBtn, catSel, msgEl;

  function pageItemId() {
    var m = ITEM_RE.exec(location.pathname);
    return m ? m[1] : '';
  }

  /** The product ID a link points to, or ''. */
  function idOf(a) {
    var href = a.getAttribute('href') || '';
    var m = ITEM_RE.exec(href);
    return m ? m[1] : '';
  }

  /**
   * The card for a product link: the largest ancestor that still only links to this one product and holds its
   * picture — i.e. the tile in the grid. Stops before a container that holds other products.
   */
  function cardFor(a, id) {
    var el = a;
    var best = null;
    for (var i = 0; i < 8 && el && el !== document.body; i++) {
      var ids = {};
      var links = el.querySelectorAll ? el.querySelectorAll('a[href*="/item/"]') : [];
      for (var j = 0; j < links.length; j++) {
        var x = idOf(links[j]);
        if (x) ids[x] = true;
      }
      if (Object.keys(ids).length > 1) break;
      if (el.querySelector && el.querySelector('img')) best = el;
      el = el.parentElement;
    }
    return best;
  }

  function text(el) {
    return (el && (el.innerText || el.textContent) || '').replace(/\s+/g, ' ').trim();
  }

  /** Title, picture and price as the card shows them (checked with AliExpress later anyway). */
  function readCard(card, a) {
    var img = card.querySelector('img');
    var src = img ? (img.getAttribute('src') || img.getAttribute('data-src') || '') : '';
    if (src.indexOf('//') === 0) src = 'https:' + src;
    var heading = card.querySelector('h1,h2,h3');
    var titled = card.querySelector('[title]');
    var title = a.getAttribute('title') || (heading && heading.textContent) || (titled && titled.getAttribute('title')) || (img && img.getAttribute('alt')) || '';
    if (!title) {
      var lines = (card.innerText || '').split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l.length > 15; });
      title = lines[0] || '';
    }
    // The first price on the card is the one you'd pay (a crossed-out old price comes after it).
    var price = '', currency = '';
    var m = /(AU|US|NZ|CA)?\s?(\$|€|£)\s?(\d[\d,]*(?:\.\d{1,2})?)/.exec(text(card));
    if (m) {
      price = m[3].replace(/,/g, '');
      currency = m[1] ? m[1] + 'D' : ({ '€': 'EUR', '£': 'GBP' }[m[2]] || '');
    }
    return {
      title: title.replace(/\s+/g, ' ').trim().slice(0, 500),
      image: /^https:\/\//.test(src) ? src : '',
      price: price.slice(0, 32),
      currency: currency,
    };
  }

  function selected() {
    return Object.keys(cards).filter(function (id) { return cards[id].box.checked; });
  }

  function refreshBar() {
    if (!bar) return;
    var n = selected().length;
    countEl.textContent = n ? n + ' selected' + (n >= MAX ? ' (max ' + MAX + ')' : '') : 'Tick products to add';
    addBtn.disabled = !n;
    addBtn.textContent = 'Add ' + (n || '') + ' to Givsen';
    Object.keys(cards).forEach(function (id) {
      var c = cards[id];
      c.box.disabled = !c.box.checked && n >= MAX;
    });
  }

  function mark(id) {
    var c = cards[id];
    var s = status[id];
    if (!c || !s) return;
    c.label.textContent = s === 'store' ? 'In store' : 'In import list';
    c.label.style.background = s === 'store' ? '#1e6b34' : '#8a5a00';
    c.box.checked = false;
  }

  function addCheckbox(id, card, a) {
    if (cards[id] || card.querySelector('.gsup-bulk-pick')) return;
    if (getComputedStyle(card).position === 'static') card.style.position = 'relative';
    var wrap = document.createElement('label');
    wrap.className = 'gsup-bulk-pick';
    wrap.style.cssText = 'position:absolute;top:6px;left:6px;z-index:20;display:flex;gap:4px;align-items:center;background:rgba(17,17,17,.85);color:#fff;border-radius:999px;padding:3px 8px 3px 5px;font:600 11px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;cursor:pointer';
    var box = document.createElement('input');
    box.type = 'checkbox';
    box.style.cssText = 'margin:0;width:14px;height:14px;cursor:pointer';
    var label = document.createElement('span');
    label.textContent = 'Givsen';
    wrap.appendChild(box);
    wrap.appendChild(label);
    // Ticking shouldn't open the product.
    wrap.addEventListener('click', function (e) { e.stopPropagation(); });
    box.addEventListener('change', refreshBar);
    card.appendChild(wrap);
    cards[id] = { el: card, box: box, label: label, link: a };
  }

  function scan() {
    var own = pageItemId();
    var links = document.querySelectorAll('a[href*="/item/"]');
    var fresh = [];
    for (var i = 0; i < links.length; i++) {
      var id = idOf(links[i]);
      if (!id || id === own || cards[id]) continue;
      var card = cardFor(links[i], id);
      if (!card) continue;
      addCheckbox(id, card, links[i]);
      if (cards[id] && !(id in status)) fresh.push(id);
    }
    if (Object.keys(cards).length) {
      ensureBar();
      refreshBar();
    }
    if (fresh.length) askStatus(fresh);
  }

  function askStatus(ids) {
    ids.forEach(function (id) { status[id] = ''; });
    try {
      chrome.runtime.sendMessage({ type: 'gsup:status', product_ids: ids }, function (res) {
        if (chrome.runtime.lastError || !res || !res.ok || !res.statuses) return;
        Object.keys(res.statuses).forEach(function (id) {
          status[id] = res.statuses[id] || '';
          mark(id);
        });
        refreshBar();
      });
    } catch (e) { /* extension reloaded: page refresh needed */ }
  }

  function ensureBar() {
    if (bar) return;
    bar = document.createElement('div');
    bar.id = 'gsup-bulk-host';
    shadow = bar.attachShadow({ mode: 'closed' });
    var style = document.createElement('style');
    style.textContent = [
      ':host{all:initial}',
      '.bar{position:fixed;left:20px;bottom:24px;z-index:2147483645;display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:calc(100vw - 180px);background:#fff;color:#1d2327;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);padding:10px 12px;font:13px -apple-system,Segoe UI,Roboto,sans-serif}',
      '.count{color:#50575e}',
      'select{padding:6px 8px;border:1px solid #c3c4c7;border-radius:6px;font-size:13px;max-width:220px}',
      'button{border-radius:6px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #111;background:#111;color:#fff}',
      'button[disabled]{opacity:.5;cursor:default}',
      '.link{background:#fff;color:#111}',
      '.msg{flex-basis:100%;font-size:12px}',
      '.msg.ok{color:#1e6b34}.msg.err{color:#b32d2e}',
      '.msg a{color:inherit;font-weight:600}',
    ].join('');
    shadow.appendChild(style);
    var box = document.createElement('div');
    box.className = 'bar';
    countEl = document.createElement('span');
    countEl.className = 'count';
    catSel = document.createElement('select');
    catSel.setAttribute('aria-label', 'Category in your store');
    catSel.appendChild(new Option('Category: choose later', ''));
    addBtn = document.createElement('button');
    addBtn.type = 'button';
    var none = document.createElement('button');
    none.type = 'button';
    none.className = 'link';
    none.textContent = 'Clear';
    msgEl = document.createElement('div');
    msgEl.className = 'msg';
    box.appendChild(countEl);
    box.appendChild(catSel);
    box.appendChild(addBtn);
    box.appendChild(none);
    box.appendChild(msgEl);
    shadow.appendChild(box);
    document.documentElement.appendChild(bar);

    none.addEventListener('click', function () {
      Object.keys(cards).forEach(function (id) { cards[id].box.checked = false; });
      refreshBar();
    });
    catSel.addEventListener('change', function () {
      try { chrome.storage.local.set({ lastCategory: catSel.value }); } catch (e) { /* ignore */ }
    });
    addBtn.addEventListener('click', send);
    loadCategories();
  }

  function loadCategories() {
    try {
      chrome.runtime.sendMessage({ type: 'gsup:categories' }, function (res) {
        if (chrome.runtime.lastError || !res || !res.ok) return;
        (res.categories || []).forEach(function (c) { catSel.appendChild(new Option(c.name, String(c.id))); });
        chrome.storage.local.get(['lastCategory'], function (s) {
          var last = s && s.lastCategory ? String(s.lastCategory).replace(/[^0-9]/g, '') : '';
          if (last && catSel.querySelector('option[value="' + last + '"]')) catSel.value = last;
        });
      });
    } catch (e) { /* ignore */ }
  }

  function send() {
    var ids = selected().slice(0, MAX);
    if (!ids.length) return;
    var items = ids.map(function (id) {
      var d = readCard(cards[id].el, cards[id].link);
      return { product_id: id, title: d.title, image: d.image, price: d.price, currency: d.currency };
    });
    addBtn.disabled = true;
    addBtn.textContent = 'Adding ' + ids.length + '…';
    msgEl.className = 'msg';
    msgEl.textContent = '';
    try {
      chrome.runtime.sendMessage({ type: 'gsup:import-batch', payload: { items: items, category_ids: catSel.value ? [parseInt(catSel.value, 10)] : [] } }, function (res) {
        refreshBar();
        if (chrome.runtime.lastError || !res) {
          msgEl.className = 'msg err';
          msgEl.textContent = 'The extension was updated or restarted. Refresh this page and try again.';
          return;
        }
        if (!res.ok) {
          msgEl.className = 'msg err';
          msgEl.textContent = res.message || 'Something went wrong.';
          return;
        }
        var failed = res.failed || [];
        msgEl.className = 'msg ok';
        msgEl.textContent = 'Added ' + res.added + ', already there ' + res.already + (failed.length ? ', failed ' + failed.length + ' (' + failed.map(function (f) { return f.product_id + ': ' + f.reason; }).join('; ') + ')' : '') + '. They’re checked with AliExpress in the background. ';
        if (res.import_list) {
          var a = document.createElement('a');
          a.href = res.import_list; a.target = '_blank'; a.rel = 'noopener noreferrer';
          a.textContent = 'Open import list';
          msgEl.appendChild(a);
        }
        ids.forEach(function (id) {
          if (!failed.some(function (f) { return f.product_id === id; })) { status[id] = status[id] === 'store' ? 'store' : 'import'; mark(id); }
        });
        refreshBar();
      });
    } catch (e) {
      msgEl.className = 'msg err';
      msgEl.textContent = 'The extension was updated or restarted. Refresh this page and try again.';
    }
  }

  // Results load as you scroll: look again when the page changes (at most once a second).
  var timer = null;
  function later() {
    clearTimeout(timer);
    timer = setTimeout(scan, 800);
  }
  scan();
  new MutationObserver(later).observe(document.documentElement, { childList: true, subtree: true });
})();
