/* Givsen Supplier — delivery by country on the shop's pages. */
(function ($) {
  'use strict';

  // "Deliver to": choosing a country saves it straight away.
  $(document).on('change', '.gsup-deliver-form select', function () {
    this.form.submit();
  });

  // Variable products: show the chosen option's delivery choice (sent with WooCommerce's variation data).
  $(document).on('found_variation', '.variations_form', function (e, variation) {
    var box = $(this).find('[data-gsup-delivery]');
    box.html(variation && variation.gsup_delivery ? variation.gsup_delivery : '');
  });
  $(document).on('reset_data', '.variations_form', function () {
    $(this).find('[data-gsup-delivery]').empty();
  });
})(jQuery);
