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
    '.rev{margin-top:12px;padding-top:10px;border-top:1px solid #e0e0e0}',
    '.rev h4{margin:0 0 8px;font-size:13px}',
    '.rev .opts{display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px;color:#50575e}',
    '.rev select{padding:4px 6px;border:1px solid #c3c4c7;border-radius:6px;font-size:12px}',
    '.rev label{display:flex;gap:4px;align-items:center}',
    '.rev .go{margin-top:8px;background:#fff;color:#111;border:1px solid #111;border-radius:6px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer}',
    '.rev .go[disabled]{opacity:.6;cursor:default}',
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
    card.appendChild(reviewsBox(productId));
    shadow.appendChild(card);
  }

  /* ---------- Reviews ---------- */

  function reviewsBox(productId) {
    var box = el('div', { class: 'rev' });
    box.appendChild(el('h4', {}, 'Reviews'));
    var opts = el('div', { class: 'opts' });
    var stars = el('select', { name: 'rev_stars', 'aria-label': 'Minimum stars' });
    [['4', '4★ and up'], ['5', '5★ only'], ['3', '3★ and up'], ['1', 'All']].forEach(function (o) { stars.appendChild(el('option', { value: o[0] }, o[1])); });
    var count = el('select', { name: 'rev_count', 'aria-label': 'How many' });
    ['10', '20', '30', '50'].forEach(function (n) { count.appendChild(el('option', { value: n }, n + ' reviews')); });
    count.value = '20';
    var textOnly = el('input', { type: 'checkbox', name: 'rev_text' });
    textOnly.checked = true;
    var photos = el('input', { type: 'checkbox', name: 'rev_photos' });
    var l1 = el('label', {}); l1.appendChild(textOnly); l1.appendChild(document.createTextNode('with text'));
    var l2 = el('label', {}); l2.appendChild(photos); l2.appendChild(document.createTextNode('with photos only'));
    opts.appendChild(stars); opts.appendChild(count); opts.appendChild(l1); opts.appendChild(l2);
    box.appendChild(opts);
    var go = el('button', { class: 'go', type: 'button' }, 'Import reviews to my store');
    go.addEventListener('click', function () {
      importReviews(go, productId, {
        minStars: parseInt(stars.value, 10),
        max: parseInt(count.value, 10),
        textOnly: textOnly.checked,
        photosOnly: photos.checked,
      });
    });
    box.appendChild(go);
    return box;
  }

  function feedbackUrl(productId, page) {
    return 'https://feedback.aliexpress.com/pc/searchEvaluation.do?productId=' + encodeURIComponent(productId) +
      '&lang=en_US&country=AU&page=' + page + '&pageSize=20&filter=all&sort=complex_default';
  }

  function getFeedback(url) {
    return fetch(url, { credentials: 'include', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .catch(function () {
        return new Promise(function (resolve) {
          try {
            chrome.runtime.sendMessage({ type: 'gsup:feedback', url: url }, function (res) {
              resolve(res && res.ok ? res.data : null);
            });
          } catch (e) { resolve(null); }
        });
      });
  }

  /** AliExpress's review list, whatever it's called this month. */
  function reviewList(data) {
    var d = data && (data.data || data.result || data);
    var list = d && (d.evaViewList || d.evaluationList || d.feedbackList || d.list);
    return Array.isArray(list) ? list : [];
  }

  function toReview(r) {
    var raw = r.buyerEval != null ? r.buyerEval : (r.evalStar != null ? r.evalStar : r.star);
    var rating = Number(raw) || 0;
    if (rating > 5) rating = Math.round(rating / 20); // 100 → 5 stars
    var text = (r.buyerTranslationFeedback || r.buyerFeedback || r.content || r.feedback || '').replace(/\s+/g, ' ').trim();
    var images = r.images || r.imageList || r.evalImages || [];
    if (!Array.isArray(images)) images = [];
    images = images.map(function (i) { return typeof i === 'string' ? i : (i && (i.url || i.imgUrl)) || ''; }).filter(Boolean);
    return {
      id: String(r.evaluationId || r.evaluationIdStr || r.id || ''),
      name: r.buyerName || r.anonymousName || '',
      country: r.buyerCountry || r.country || '',
      rating: rating,
      text: text,
      date: r.evalDate || r.evaluationDate || r.date || '',
      images: images,
    };
  }

  async function importReviews(btn, productId, o) {
    btn.disabled = true;
    btn.textContent = 'Reading reviews…';
    var picked = [];
    for (var page = 1; page <= 10 && picked.length < o.max; page++) {
      var data = await getFeedback(feedbackUrl(productId, page));
      var list = reviewList(data);
      if (!list.length) break;
      list.map(toReview).forEach(function (r) {
        if (picked.length >= o.max) return;
        if (r.rating < o.minStars) return;
        if (o.textOnly && r.text.length < 3) return;
        if (o.photosOnly && !r.images.length) return;
        picked.push(r);
      });
    }
    if (!picked.length) {
      btn.disabled = false;
      btn.textContent = 'Import reviews to my store';
      showMsg(false, ['No reviews matched (or AliExpress didn’t share them on this page). Try “All” stars or untick the filters.']);
      return;
    }
    btn.textContent = 'Sending ' + picked.length + ' reviews…';
    try {
      chrome.runtime.sendMessage({ type: 'gsup:reviews', payload: { product_id: productId, reviews: picked } }, function (res) {
        btn.disabled = false;
        btn.textContent = 'Import reviews to my store';
        if (chrome.runtime.lastError || !res) {
          showMsg(false, ['The extension was updated or restarted. Refresh this page and try again.']);
          return;
        }
        if (!res.ok) {
          showMsg(false, [res.message || 'Something went wrong.']);
          return;
        }
        var parts = [res.added + ' review(s) added' + (res.skipped ? ', ' + res.skipped + ' already there or skipped' : '') + ' to ' +
          (res.products || []).map(function (p) { return p.name; }).join(', ') + '. '];
        if (res.pending && res.products && res.products[0]) {
          parts.push('They’re waiting for your approval: ');
          parts.push(link(res.products[0].edit_url, 'Products → Reviews'));
        }
        showMsg(true, parts);
      });
    } catch (e) {
      btn.disabled = false;
      btn.textContent = 'Import reviews to my store';
      showMsg(false, ['The extension was updated or restarted. Refresh this page and try again.']);
    }
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
