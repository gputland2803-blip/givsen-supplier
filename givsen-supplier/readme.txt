=== Givsen Supplier ===
Contributors: givsen
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 0.16.1
License: Proprietary

Links WooCommerce products to AliExpress by ID and adds an "Order on AliExpress" panel to every order. Replaces DSers for givsen.com.

== Description ==

* Product ID stored on the product; SKU ID, ships-from and option stored on each variation. Renaming never breaks a link.
* Import list fed by the Givsen Supplier Chrome extension — one product at a time, or up to 30 at once from search results and store pages — or by pasting a link.
* Connects to the AliExpress Dropshipping API to fill in exact option details.
* Creates draft store products from AliExpress listings — all warehouses in one product (each kept as a source) or one per warehouse — with your pricing rule.
* Delivery by visitor country in the shop (switch on in Settings): “Deliver to” switcher, products that can’t reach the visitor hidden from lists, delivery choice by warehouse with days and dates, “Shipping from” filter, checkout re-check — page-cache safe.
* Knows where every warehouse can deliver: checked weekly for each of your selling countries (delivery cost, method, days, stock).
* Daily sync with AliExpress for stock, cost and removed listings.
* Order panel with the exact option to buy, copy-ready address, and fields for AliExpress order and tracking numbers.
* Automatic ordering on AliExpress when an order reaches Processing, and tracking numbers back into the order.
* Profit and margin on every product and order, with low-margin flags.
* AliExpress delivery fees in your costs and pricing rule.
* Country Based Restrictions set from where each product ships (until you sell worldwide), and a tool to remove the ones it set.
* Orders placed from the warehouse the customer chose, or the best one that reaches the address; merge separate warehouse versions of a product into one.
* Change supplier when a seller removes a listing, with options matched automatically.
* Clean, WooCommerce-style titles, option names, descriptions and specifications — for new products and in bulk for existing ones.
* Backup suppliers that take over automatically.
* Late-parcel alerts and delivery tracking with a "delivered" email.
* Monthly profit report.
* Rewrite titles and descriptions in bulk with AI (Claude), in your store's voice.

See SPEC.md for the full behaviour and regression checklist.

== Changelog ==

= 0.16.1 =
* With Givsen 1.4.3: gift address forms leave out countries the gift can't reach, and an address there is refused with a clear message before the claim completes.

= 0.16.0 =
* Selling worldwide, phase 3: orders placed from the customer's warehouse or the best other one that reaches the address (gifts: the recipient's country), warehouse used recorded; country restrictions no longer set when selling worldwide, with a tool to remove the ones this plugin set; merge warehouse versions of a product with 301 redirects. Ready for a Givsen claim-address hook (proposed).

= 0.15.0 =
* Selling worldwide, phase 2: what shoppers see — visitor country and “Deliver to” switcher, undeliverable products hidden (direct links show “Not available in {country}” with similar products), delivery choice per warehouse, optional local-warehouse surcharge, “Shipping from” filter, checkout re-check, page caching by country. Off until you switch it on.

= 0.14.0 =
* Selling worldwide, phase 1 (nothing changes for customers yet): every AliExpress warehouse kept as a source for each option, weekly reach check per selling country, daily sync of every source, Add to store with all warehouses in one product.

= 0.13.0 =
* Trial (off by default): send items from the same seller to AliExpress in one request, with order numbers matched back to each item and a log of what AliExpress did and charged.

= 0.12.0 =
* Bulk import with the Chrome extension (0.6.0): tick products on AliExpress search results, store pages or a product's related items and add up to 30 at once; they're checked with AliExpress in the background. Cards already in your store or import list are labelled.

= 0.11.0 =
* Save a backup supplier straight from AliExpress with the Chrome extension (0.5.0): options matched automatically, cost compared; never switches supplier or changes prices.

= 0.10.2 =
* Descriptions from the product page: with Chrome extension 0.4.2, AliExpress's "AI overview of item", written description and specifications are captured as you add a product and used when the listing's description is only images.

= 0.10.1 =
* Fix: listings whose AliExpress description is only images no longer end up with an empty description.

= 0.10.0 =
* Rewrite with AI: bulk-rewrite titles, descriptions and short descriptions with Claude in your store's voice, side by side, editable, with undo.

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
