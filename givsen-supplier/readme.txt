=== Givsen Supplier ===
Contributors: givsen
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 0.9.0
License: Proprietary

Links WooCommerce products to AliExpress by ID and adds an "Order on AliExpress" panel to every order. Replaces DSers for givsen.com.

== Description ==

* Product ID stored on the product; SKU ID, ships-from and option stored on each variation. Renaming never breaks a link.
* Import list fed by the Givsen Supplier Chrome extension (or by pasting a link).
* Connects to the AliExpress Dropshipping API to fill in exact option details.
* Creates draft store products from AliExpress listings, one per warehouse, with your pricing rule.
* Daily sync with AliExpress for stock, cost and removed listings.
* Order panel with the exact option to buy, copy-ready address, and fields for AliExpress order and tracking numbers.
* Automatic ordering on AliExpress when an order reaches Processing, and tracking numbers back into the order.
* Profit and margin on every product and order, with low-margin flags.
* AliExpress delivery fees in your costs and pricing rule.
* Country Based Restrictions set from where each product ships.
* Change supplier when a seller removes a listing, with options matched automatically.
* Clean, WooCommerce-style titles, option names, descriptions and specifications — for new products and in bulk for existing ones.
* Backup suppliers that take over automatically.
* Late-parcel alerts and delivery tracking with a "delivered" email.
* Monthly profit report.

See SPEC.md for the full behaviour and regression checklist.

== Changelog ==

= 0.9.0 =
* Delivery estimate on product pages, diagnostics log for AliExpress calls, description photos into the gallery, safer description clean-up, automated tests.

= 0.8.2 =
* Choose what Add to store brings in (description as plain text to rewrite / cleaned with images / none; photos; description photos to gallery; specifics), remembered. Nothing customer-facing mentions or loads from AliExpress. Review import removed. Security hardening.

= 0.8.1 =
* Works with the Givsen gift plugin: Business gifting parents never ordered, $0 recipient orders checked and costed at the parent's price, gift tracking kept from the buyer, gift-worded delivered emails (recipient for Business gifting), fallback courier phone, extra-postage payments in the profit report.

= 0.8.0 =
* Backup suppliers with automatic switching, late-parcel alerts and delivered status (customer email, complete on delivery), profit report, smarter repricing, stock buffer, bulk tidy-up with undo, AliExpress review import (extension 0.4.0).

= 0.7.0 =
* Change supplier: point a product at a new AliExpress listing with options matched automatically. Tidy titles, option names and descriptions on Add to store, with item specifics in WooCommerce's Additional information tab. Faster: parallel AliExpress calls, weekly delivery quotes, nothing extra on shop pages, cached margins and profit.

= 0.6.1 =
* Settings redesigned into sections with status badges and an Overview of what needs attention. Fixes from review: tracking queue, refunded items, payment note, currency of AliExpress amounts, cancelled-order warnings, order profit with refunds, first-sync cost alerts.

= 0.6.0 =
* Automatic ordering and tracking sync, profit/margin on products and orders, real AliExpress delivery fees in costs and pricing, CBR country restrictions from ships-from.

= 0.5.0 =
* Daily sync: stock, cost, optional price updates, removed products to draft, gone options out of stock, one summary email. Sync now, last-run report, product-list badges, order-panel warning.

= 0.4.0 =
* Category picker on Add to store (pre-ticked from the extension or last used); categories endpoint for the Chrome extension.

= 0.3.0 =
* Add to store: create draft WooCommerce products from AliExpress (per warehouse, chosen options, photos, supplier links, pricing rule). Pricing settings. Smarter connection renewal.

= 0.2.0 =
* AliExpress API connection (App Key/Secret, connect flow, automatic renewal), product tester, import-list auto-fill and "Check with AliExpress".

= 0.1.0 =
* First release: supplier fields, products-list column and filter, import list, signed extension endpoints, Order on AliExpress panel. HPOS compatible.
