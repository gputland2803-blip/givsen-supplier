/* Givsen Supplier — runs in the AliExpress page itself (not the extension sandbox) so it can read the
 * product data AliExpress embeds in the page: the list of options (SKUs) and their property IDs.
 * It only answers when the extension asks, and only sends back option data. */
(function () {
  'use strict';
  if (window.__gsupPageReady) return;
  window.__gsupPageReady = true;

  var ROOTS = ['runParams', '_d_c_', '__INIT_DATA__', '__AER_DATA__', '__INITIAL_STATE__'];

  function scan(root, found, seen, depth) {
    if (!root || typeof root !== 'object' || depth > 10 || seen.size > 40000 || seen.has(root)) return;
    seen.add(root);
    if (Array.isArray(root)) {
      if (root.length && root[0] && typeof root[0] === 'object') {
        var a = root[0];
        if (!found.skus && ('skuId' in a || 'skuIdStr' in a) && ('skuAttr' in a || 'skuPropIds' in a)) found.skus = root;
        if (!found.props && 'skuPropertyValues' in a) found.props = root;
      }
      for (var i = 0; i < root.length && i < 500; i++) scan(root[i], found, seen, depth + 1);
      return;
    }
    var keys;
    try { keys = Object.keys(root); } catch (e) { return; }
    for (var k = 0; k < keys.length; k++) {
      var v;
      try { v = root[keys[k]]; } catch (e) { continue; }
      if (v && typeof v === 'object') scan(v, found, seen, depth + 1);
    }
  }

  function collect() {
    var found = {};
    var seen = new Set();
    for (var r = 0; r < ROOTS.length; r++) {
      try { scan(window[ROOTS[r]], found, seen, 0); } catch (e) { /* ignore */ }
      if (found.skus && found.props) break;
    }
    var skus = (found.skus || []).map(function (s) {
      return {
        skuId: String(s.skuIdStr || s.skuId || ''),
        attr: String(s.skuAttr || ''),
        propIds: String(s.skuPropIds || ''),
      };
    }).filter(function (s) { return s.skuId; });
    var props = (found.props || []).map(function (p) {
      return {
        id: String(p.skuPropertyId || ''),
        name: String(p.skuPropertyName || ''),
        values: (p.skuPropertyValues || []).map(function (v) {
          return {
            id: String(v.propertyValueIdLong || v.propertyValueId || ''),
            name: String(v.propertyValueName || ''),
            display: String(v.propertyValueDisplayName || v.propertyValueName || ''),
          };
        }),
      };
    });
    return { skus: skus, props: props };
  }

  window.addEventListener('message', function (e) {
    if ((e.source && e.source !== window) || !e.data || e.data.source !== 'gsup-ext' || e.data.type !== 'req') return;
    var payload;
    try { payload = collect(); } catch (err) { payload = { skus: [], props: [] }; }
    window.postMessage({ source: 'gsup-page', type: 'res', nonce: e.data.nonce, data: payload }, window.location.origin);
  });
})();
