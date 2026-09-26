/* Givsen Supplier — pure helpers for working out which option (SKU) is selected.
 * Loaded before content.js; also usable from Node for testing. */
(function (root) {
  'use strict';

  function norm(s) {
    return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function productIdFromUrl(href) {
    var s = String(href || '');
    try { s = decodeURIComponent(decodeURIComponent(s)); } catch (e) { /* keep raw */ }
    var m = s.match(/\/item\/(?:[^/?#]*\/)?(\d{6,32})\.html/i);
    return m ? m[1] : '';
  }

  function skuIdFromUrl(href) {
    var s = String(href || '');
    try { s = decodeURIComponent(decodeURIComponent(s)); } catch (e) { /* keep raw */ }
    var m = s.match(/sku_?id["']?\s*[=:]\s*["']?(\d{5,64})/i);
    return m ? m[1] : '';
  }

  /** "Color: Red" → { name: "Color", value: "Red" } */
  function splitTitle(text) {
    var t = String(text || '').replace(/\s+/g, ' ').trim();
    var i = t.indexOf(':');
    if (i < 1) return { name: t, value: '' };
    return { name: t.slice(0, i).trim(), value: t.slice(i + 1).trim() };
  }

  function isShipsFrom(name) {
    return /ships?\s*from/i.test(String(name || ''));
  }

  /** Pairs of "propertyId:valueId" a SKU is made of. */
  function skuPairs(sku) {
    var pairs = [];
    String(sku.attr || '').split(';').forEach(function (part) {
      var pv = part.split('#')[0].trim();
      if (/^\d+:\d+$/.test(pv)) pairs.push(pv);
    });
    return pairs;
  }

  /**
   * selected: [{ name, value }] from the page (e.g. Color: Red, Ships From: Australia)
   * data:     { skus: [{skuId, attr, propIds}], props: [{id, name, values:[{id, name, display}]}] }
   * Returns { skuId, shipFrom, option, reason }.
   */
  function resolve(selected, data) {
    selected = (selected || []).filter(function (s) { return s && s.name; });
    data = data || { skus: [], props: [] };
    var skus = data.skus || [];
    var props = data.props || [];
    var option = selected.filter(function (s) { return s.value; }).map(function (s) { return s.name + ': ' + s.value; }).join(' · ');
    var shipSel = selected.filter(function (s) { return isShipsFrom(s.name); })[0];
    var result = { skuId: '', shipFrom: shipSel ? shipSel.value : '', option: option, reason: '' };

    if (skus.length === 1) {
      result.skuId = skus[0].skuId;
      result.reason = 'single option';
      return result;
    }
    if (!skus.length) {
      result.reason = 'no option data on page';
      return result;
    }

    var wanted = [];
    var missing = [];
    props.forEach(function (p) {
      var sel = selected.filter(function (s) { return norm(s.name) === norm(p.name); })[0];
      if (!sel || !sel.value) { missing.push(p.name); return; }
      var val = p.values.filter(function (v) { return norm(v.display) === norm(sel.value) || norm(v.name) === norm(sel.value); })[0];
      if (!val) { missing.push(p.name); return; }
      wanted.push({ pair: p.id + ':' + val.id, valueId: val.id });
      if (isShipsFrom(p.name) && !result.shipFrom) result.shipFrom = val.display || val.name;
    });

    if (!props.length) {
      result.reason = 'no option list on page';
      return result;
    }
    if (missing.length) {
      result.reason = 'choose: ' + missing.join(', ');
      return result;
    }

    var hits = skus.filter(function (s) {
      var pairs = skuPairs(s);
      if (pairs.length) {
        return wanted.every(function (w) { return pairs.indexOf(w.pair) !== -1; });
      }
      var ids = String(s.propIds || '').split(/[,;]/).map(function (x) { return x.trim(); });
      return wanted.every(function (w) { return ids.indexOf(w.valueId) !== -1; });
    });

    if (hits.length === 1) {
      result.skuId = hits[0].skuId;
      result.reason = 'matched';
    } else {
      result.reason = hits.length ? 'several options match' : 'no option matches';
    }
    return result;
  }

  var api = { norm: norm, productIdFromUrl: productIdFromUrl, skuIdFromUrl: skuIdFromUrl, splitTitle: splitTitle, isShipsFrom: isShipsFrom, resolve: resolve };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.GsupMatch = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
