/* Givsen Supplier blocks: "Deliver to (country)" and "Shipping from (filter)", drawn by the server. */
(function (blocks, el, SSR, be) {
  [['givsen-supplier/deliver-to', 'Deliver to (country)', 'location'], ['givsen-supplier/shipping-from', 'Shipping from (filter)', 'filter']].forEach(function (b) {
    blocks.registerBlockType(b[0], {
      apiVersion: 2,
      title: b[1],
      category: 'woocommerce',
      icon: b[2],
      edit: function () {
        return el.createElement('div', be.useBlockProps(), el.createElement(SSR, { block: b[0] }));
      },
      save: function () { return null; }
    });
  });
})(window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor);
