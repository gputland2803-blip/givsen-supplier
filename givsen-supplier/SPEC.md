# Givsen Supplier — Specification

Version: 0.15.0 · Replaces DSers for givsen.com (WooCommerce, Stripe, CBR country segmentation).

## Core rule
The supplier link lives on the WooCommerce product, by ID. Titles, descriptions, attribute names and option names are never used to find a supplier item, so renaming anything can't break a link.

| Where | Meta key | Holds |
|---|---|---|
| Product (simple or variable parent) | `_gsup_ae_product_id` | AliExpress product ID |
| Variation, or simple product | `_gsup_ae_sku_id` | AliExpress SKU ID (identifies the option, including ships-from) |
| Variation, or simple product | `_gsup_ship_from` | 2-letter country code the option ships from |
| Variation, or simple product | `_gsup_ae_option` | Option text as AliExpress shows it (human guide for ordering) |
| Variation, or simple product | `_gsup_cost` | AliExpress cost when added/last synced (store currency) |
| Variation, or simple product | `_gsup_ship_cost` | AliExpress delivery fee for one item (shipping method preference, quote country) |
| Variation, or simple product | `_gsup_ship_method` | Delivery method code that fee is for |
| Product | `_gsup_low_margin` | `yes` while any option's margin is below the minimum |
| Product | CBR keys (default `_fz_country_restriction_type`, `_restricted_countries`) | Set from ships-from when CBR setting is on |
| Product | `_gsup_sync_status` | '' / `missing` / `removed` |
| Product | `_gsup_sync_missing` | Consecutive syncs where AliExpress had no details |
| Product | `_gsup_drafted_by_sync` | When the sync drafted it (cleared when reported back on sale) |
| Product | `_gsup_synced_at` | Last successful sync |
| Variation, or simple product | `_gsup_option_gone` | Set when the option is no longer on the listing |
| Variation, or simple product | `_gsup_sources` | Every warehouse that has the option: {warehouse: {sku, option, cost, stock, seen_at}}, main warehouse first; unstated ships-from = `CN`. Absent = main warehouse only |
| Product | `_gsup_reach_at` | When its sources and reach were last refreshed |
| Order line item | `_gsup_wh` | Warehouse the customer chose (or the checkout re-check switched to); visible meta "Ships from" |
| Order line item | `_gsup_ae_order_no` | AliExpress order number |
| Order line item | `_gsup_tracking_no` | Tracking number(s), comma-separated |
| Order line item | `_gsup_carrier` | Carrier reported by AliExpress |
| Order line item | `_gsup_unit_cost` | Cost of one item incl. delivery when the order was created (checkout) |
| Order line item | `_gsup_ae_cost` | AliExpress order cost: quote when placed, replaced by AliExpress's amount once known |
| Order line item | `_gsup_auto_problem` | Why automatic ordering couldn't place it (or AliExpress cancelled it) |
| Order line item | `_gsup_placing` | Set just before the place-order call; left set if AliExpress didn't answer (no automatic retry) |
| Order line item | `_gsup_ae_ship_method`, `_gsup_ae_status` | Delivery method used; last AliExpress order status |
| Order | `_gsup_auto_state` | `queued` / `placed` / `partial` / `failed` |
| Order | `_gsup_awaiting_tracking` | `yes` while any line has an AliExpress order number but no tracking |

Variable parents never hold SKU / ships-from / option — saving a variable product clears them from the parent.

## Import list
Table `{prefix}gsup_import`. Statuses: `new` (To link), `linked`, `dismissed`. The same product ID + SKU ID updates the existing row instead of adding a duplicate (a dismissed row returns to To link). Linking:
- to a variation → product ID on the parent, SKU / ships-from / option on the variation;
- to a simple product → all on the product;
- to a variable parent → product ID only (warns that the option wasn't stored);
- refused if the parent is already linked to a different AliExpress product.
Empty captured values never overwrite existing ones.
Bulk rows (source `bulk`, from `/import-batch`) have an empty SKU and show "Choose warehouse and options on Add to store"; they're checked with AliExpress in the background (`gsup_enrich_rows` → `GSUP_Import::enrich_many()`: one `get_products()` batch for up to 60 rows, ships-to = row's warehouse else `default_ship_to()`). Many options → note "Checked with AliExpress: N options — choose the warehouse and options on Add to store", price filled from the first option only if the card had none; one option → SKU, option, ships-from and price set (unless that product + SKU is already a row); errors → `api_note`, `api_checked_at` set.

## Extension endpoints
Namespace `givsen-supplier/v1`. Every request sends `X-Gsup-Timestamp` (unix seconds) and `X-Gsup-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw body>"` with the connection key (option `gsup_secret`). Older than 5 minutes → refused.
- `GET /ping` → `{ok, site, version}`
- `GET /categories` → `{ok, categories:[{id, name (full path), depth}]}`
- `GET /linked-products?search=` → `{ok, products:[{id, name, ae_product_id, ships_from, has_backup, backup_id, edit_url}]}` (linked products, up to 20, title search). v2 signature only; the query string isn't signed.
- `POST /backup` body `{wc_product_id, product_id, ship_from, replace}` → `GSUP_Remap::save_backup_from_listing()`: refuses same-as-main (`gsup_same_as_main`), existing backup without `replace` (`gsup_backup_exists`, `data.current_backup`, HTTP 409), no options in that warehouse (`gsup_no_warehouse`; empty ship_from = the listing's only warehouse), nothing matched (`gsup_no_match`). Matches with `GSUP_Remap::auto_match()` over the warehouse's SKUs; saves `_gsup_backup` {product_id, ship, map, saved_at} (unmatched options simply absent from map); never switches or reprices. Returns `{ok, matched, total, unmatched:[names], cost:{current, backup, currency, items:[{name, current, backup}]}, replaced, supplier_url}` — current = `GSUP_Profit::unit_cost()`, backup = SKU price + one delivery quote. v2 signature only.
- `POST /import-batch` body `{items:[{product_id, title, image, price, currency}], category_ids[]}` (max 30, `gsup_too_many` otherwise) → `GSUP_Import::add_batch()`: skips IDs in the batch twice, linked to a store product, or with a non-dismissed row (one `statuses()` lookup); dismissed rows return to To link; invalid IDs → failed. Returns `{ok, added, already, failed:[{product_id, reason}], import_list}` and queues `gsup_enrich_rows` with the new row IDs. No AliExpress calls in the request. v2 signature only.
- `POST /status` body `{product_ids[]}` (max 200) → `{ok, statuses:{id: "store"|"import"|""}}` — "store" = a product (not trashed) has `_gsup_ae_product_id` = id; "import" = a non-dismissed import row. Two queries. v2 signature only.
- `POST /import` body `{product_id | url, sku_id, ship_from, option, title, image, price, currency, category_ids[], page_text}` (page_text: the page's AI overview, description and specifications as plain text, max 8000) → `{ok, id, duplicate, status, product_id, sku_id, ship_from, option, title, api_note, categories[], linked:[{id,name,edit_url}], import_list}`. Unknown category IDs are dropped.
`ship_from` accepts a code (AU) or a name as AliExpress shows it (Australia, United States).

## AliExpress API (Dropshipping)
Gateway `https://api-sg.aliexpress.com` (`/sync` for methods, `/rest/...` for auth). Signing: all parameters sorted by name, `name+value` concatenated, REST path prefixed for `/auth/...` calls, HMAC-SHA256 with the App Secret, upper-case hex. Options: `gsup_ae_app_key`, `gsup_ae_app_secret`, `gsup_ae_token` (not autoloaded).
- Connect: `/oauth/authorize` → back to `givsen-supplier/v1/ae-callback` with a one-time state (15 minutes) → `/auth/token/create`.
- Access token renews via `/auth/token/refresh` when under 3 days remain; if that fails and it has expired, calls return "Reconnect AliExpress".
- Product: `aliexpress.ds.product.get` with ship_to_country (AU default; US when the import row ships from US), store currency, English. Responses decoded with big IDs kept as strings.
- SKUs also carry `sku_attr` (needed to place orders).
- Delivery: `aliexpress.ds.freight.query` (`queryDeliveryReq`), falling back to `aliexpress.logistics.buyer.freight.calculate` when the app lacks permission. Options sorted cheapest first; chosen by `gsup_ship_pref`: `cheapest_tracked` (default), `cheapest`, `fastest`.
- Orders: `aliexpress.ds.order.create` (`param_place_order_request4_open_api_d_t_o` + `ds_extend_request.payment.try_to_pay` when auto-pay is on), falling back to `aliexpress.trade.buy.placeorder`. Status/tracking/amount: `aliexpress.trade.ds.order.get` (`single_order_query`).
- Import auto-fill never blocks an import. It overwrites the row's title/image/option text/ships-from/price with AliExpress's values; picks the only option of single-option products (unless that option is already in the list).
- Access token renews when less than a quarter of its life (max 3 days) remains; cron `gsup_keep_alive` (twice daily) renews if under 18 hours remain.

## Add to store
Screen: Import list → "Add to store as new product" (or Settings → Test → same button) → `tab=create&ae=<id>&ship=<code|none>&mode=<all|one>`.
- `mode=all` (default): `GSUP_Creator::all_warehouses()` fetches the listing for every selling country + AU/US in parallel, unions it (`GSUP_Sources::union()`), groups options by `GSUP_Sources::key_of()` (values without Ships From) and quotes delivery once per warehouse to its home country. Main warehouse per option = `primary_of()`: first in preference order (AU, store country, US, others A–Z, CN) whose stock isn't 0. Checkbox value = the main SKU. `create( $listing, '*', … )`: each option's main SKU/ships-from/cost/fee as today, `_gsup_sources` = every warehouse's SKU, price from the main warehouse's cost + fee; no CBR; `gsup_sources_refresh` queued. Import rows whose SKU is another warehouse of an added option are linked to it.
- `mode=one`: options grouped by ships-from; one warehouse per product (as before). Ships-from never becomes a customer-facing attribute.
- Several options → variable product with local attributes in AliExpress order; one option (or no attributes) → simple product. Duplicate option combinations are skipped.
- Categories: ticked from the import row (`row=` in the URL) when it has some, else the user's last-used (`gsup_last_categories` user meta, saved on each create). None ticked → WooCommerce default category.
- Created as draft. Title editable; description = AliExpress `detail` with script/style/iframe/noscript blocks removed, then `wp_kses_post`. Up to 6 photos (thumbnail suffixes removed), option photos (up to 12 unique) on variations.
- Price = (cost + delivery fee when `gsup_price_shipping` = yes, default) × multiplier + extra, optionally rounded up to .95 (never below cost). Options: `gsup_price_multiplier` (2), `gsup_price_add` (0), `gsup_price_round` (yes).
- One delivery quote per product and warehouse (first chosen option, to the quote country: AU/US warehouse → that country, else store country); saved on every option. No quote → prices without delivery, warning shown.
- After creating: CBR applied (if on), low-margin flag set. Stock from AliExpress; "in stock, amount unknown" → stock not managed.
- Import rows for the same AliExpress product: rows whose option was added → Linked to that variation/product; rows without an option → Linked to the product; other rows untouched.

## Daily sync
- Sources: listings fetched for the main warehouse's country plus each source warehouse's home country (`gsup_quote_country`). Every source's cost/stock/seen_at updated (`_gsup_sources` only written when it exists and changed). `gsup_stock_rule` = `primary` (default): stock/"option gone" from the main SKU as below. `any`: main SKU missing → `promote()` the best other source seen this run (`GSUP_Sources::best()`: reaching a selling country per the reach table and in stock, else any) — SKU/option/ships-from switched, delivery fee/method/days from its reach row for the store country, report `promoted` (emailed); "option gone" only when no source was seen. Stock = `GSUP_Sources::any_stock()` over reachable sources (in stock if any; qty = max, never summed; unknown amount → unmanaged).
Action Scheduler (group `givsen-supplier`): recurring `gsup_sync_start` daily at 03:00 store time while `gsup_sync_enabled` = yes; batches of 15 via `gsup_sync_batch`. Run state in `gsup_sync_run`, last report in `gsup_sync_last`. One AliExpress call per (product ID, delivery country) per batch; delivery country AU or US from the product's ships-from, else the store's base country.
- Delivery fee: one quote per (product, country) per batch; failed quote keeps the stored fee. Counted as "delivery fee changes". A product's first delivery fee (e.g. after upgrading) isn't treated as a cost rise.
- Cost + delivery went up and the product's margin is now below the minimum → listed as low margin (email + report).
- Found and on sale: stock (number → managed quantity; "in stock, amount unknown" → unmanaged, in stock), `_gsup_cost`; regular price = pricing rule when `gsup_sync_prices` = yes. Clears missing/removed/gone flags. Options not linked to a SKU are only updated when the listing has a single option.
- Option not on the listing: out of stock (quantity 0 if managed), `_gsup_option_gone`; reported once.
- Not for sale (status other than onSelling) or error message saying not exist/offline/not found/removed → removed now. Empty result or unclear product error → missing; removed on the second consecutive miss. Removed = draft if published, reported once.
- Network, sign-in, signature, permission, or rate/system-busy errors → run stops, nothing drafted, reported.
- Back on sale after the sync drafted it: reported; stays draft.
- Email (`gsup_sync_email`, default admin email) only when removed / options gone / back / low margin after a cost rise / errors.
- Never writes titles, descriptions, images, categories or sale prices.

## Automatic ordering (`gsup_auto_order`, default off)
- `woocommerce_order_status_processing` → Action Scheduler `gsup_place_order` (order ID) one minute later. Covers paid orders and claimed gifts (Awaiting Givsen Address → Processing). Only while AliExpress is connected.
- Re-checked when it runs: still Processing, not locked (5-minute transient per order).
- One AliExpress order per line (`out_order_id` = order number-item ID) unless the same-seller trial is on (see below). Lines with an AliExpress order number are skipped; lines not linked to AliExpress are ignored.
- Per line, refused with a reason when: refunded; product removed / option gone at last sync; variable parent only; option not on the listing for the delivery country; no `sku_attr`; stock below quantity; no delivery method; loss guard (`gsup_auto_loss_guard`, default on) — AliExpress price × qty + delivery fee > line total after refunds.
- Address: shipping, else billing; name, street, city, postcode, country and phone required. Company prefixed to the street. State written out. Phone split into `phone_country` (+61) and digits without the trunk 0 (not for US/CA).
- Delivery: freight quote for the actual quantity and country, chosen by the preference.
- Payment: `gsup_auto_pay` (default on) asks AliExpress to pay with the account's saved method; off → orders wait for payment on AliExpress.
- Fully refunded lines are skipped silently.
- Success → order number, delivery method, cost on the line; order note (says "waiting for you to pay it" whenever payment wasn't requested, including when the older order method was used). Failure → reason on the line, order note, email. Network/unreadable reply/accepted-without-number → `_gsup_placing` stays, no automatic retry, message says to check AliExpress.
- Order panel: status line, "Place on AliExpress now" (Processing, unplaced lines; also retries unanswered lines), "Check tracking now".

## Tracking
- Action Scheduler `gsup_tracking_check` every 4 hours: up to 40 orders with `_gsup_awaiting_tracking` = yes, least recently checked first (each check stamps `_gsup_tracking_checked` and the modified date). Stops on connection errors.
- Lines stop waiting when AliExpress reports the order cancelled/closed or has no such order (`_gsup_ae_dead`, reason shown), or when fully refunded. Cancelled/refunded/failed store orders stop waiting. Entering a new AliExpress order number clears the old order's problem and status.
- AliExpress's order amount is only used as the cost when it's in the order's currency.
- Per line with an AliExpress order number and no tracking: `trade.ds.order.get` → tracking numbers + carrier saved, order note, passed to Advanced Shipment Tracking (`ast_insert_tracking_number`, provider filter `gsup_ast_provider`, default Cainiao) when active. Cancelled/closed → problem on the line + note. Amount → `_gsup_ae_cost` (single-order lines).
- Entering an AliExpress order number by hand sets the flag too.
- All AliExpress lines tracked → flag removed; order marked Completed if `gsup_complete_on_tracking` (default on) and still Processing. Flag dropped after 60 days.
- Customers see tracking links (filter `gsup_tracking_url`, default Cainiao global tracking) in emails and My Account, unless Advanced Shipment Tracking is active.

## Profit
- Unit cost = `_gsup_cost` + `_gsup_ship_cost`. Fees = price × `gsup_fee_percent` % (+ `gsup_fee_fixed` per order on orders). Margin = (price − cost − fees) ÷ price. Before tax.
- Products list: "Margin x% · profit" (ranges for variable), red "Low margin" under `gsup_min_margin` (30); filter "Low margin" (`_gsup_low_margin`). Flag refreshed on product/variation save, sync, create, and when profit settings are saved.
- Product editor: cost + delivery (method) + margin at current price, per simple product and variation.
- Orders: `_gsup_unit_cost` saved at checkout. Line cost = `_gsup_ae_cost`, else unit cost × net qty, else today's cost. Order revenue = (total − refunds) − (tax − refunded tax). Payment fees on the original total. Order panel shows per-line and order profit; orders list has a Profit column (HPOS and classic).

## Country restrictions (CBR)
- Off until `gsup_cbr_enabled`. Map warehouse → countries (`gsup_cbr_map`, default AU→AU, US→US).
- Keys configurable: type key = type value (default `_fz_country_restriction_type` = `specific`), countries key (default `_restricted_countries`) stored as a list or comma text. "Look at a product" lists a product's country-related meta to confirm them.
- Applied on Add to store and by "Apply to linked products". Products with several warehouses or none are left alone. An existing restriction (type not empty/`all`) is kept unless "replace" is ticked.
- Products list shows "Shown to: AU".

## Order panel rules
- `givsen-pending` (Awaiting Givsen Address): red "Don't order yet", address hidden.
- `processing`: green "Ready to order". Other statuses: red "Not ready".
- Address: shipping address if present, else billing (labelled). Phone: shipping phone, else billing phone (labelled as the buyer's). State and country written out in full, country without the "(US)" suffix.
- Quantity shown is net of refunds.

## Uninstall
Drops the import list and reach tables and the plugin's options, and unschedules its background jobs (incl. `gsup_enrich_rows`). Keeps product links and order numbers/tracking, so reinstalling picks up where you left off.

## Not in this version
Per-option delivery quotes (one quote per product and warehouse is used).

## Sources and reach (`GSUP_Sources`)
- Settings → Selling worldwide: `gsup_sell_countries` (default AU NZ US CA GB IE DE FR NL SE; none → store country), `gsup_sell_others` (default yes; `live( $product_id, $country )` = one listing call + a quote per warehouse, cached 6 h in a transient, never in the table), `gsup_stock_rule` (primary/any). Shows `estimate()`: products × countries × (1 + average warehouses per product, from the reach table) a week; daily ≈ products × min(2, warehouses).
- Table `{prefix}gsup_reach` (DB version 6): product_id, item_id, country, warehouse, deliverable, in_stock, stock, cost, ship_cost, method, days_min, days_max, checked_at. PK (item_id, country, warehouse); KEY shop (country, warehouse, deliverable, in_stock, product_id); KEY product_id.
- `refresh( ids )`: per product, `get_products()` for `fetch_countries()` (selling countries + each warehouse's home country + store country); union; `match()` (per warehouse: the main SKU's `key_of()` exact, else `GSUP_Remap::auto_match()` ≥ 0.8 among the rest; a lone item and lone SKU always match); one `freights()` quote per (product, warehouse, selling country) for an option of that warehouse present in that country's reply. Row deliverable = option in that country's reply AND a quote; in_stock = deliverable and stock ≠ 0. Countries with no reply (or a connection-type quote error) keep their old rows and are reported as skipped. A non-main source missing from every reply (all countries answered) is dropped. Rows for the checked countries replaced (DELETE by product+country, multi-row REPLACE).
- Weekly `gsup_reach_start` (Monday 04:00 store time, while the daily sync is on) → `gsup_reach_batch` (3 products per step, filter `gsup_reach_batch_size`); run state `gsup_reach_run`, last `gsup_reach_last`. Supplier tab "Refresh sources" (`admin_post_gsup_sources_refresh`, runs now); bulk action `gsup_sources` → `gsup_sources_refresh` jobs. Change supplier to another listing → sources removed, reach rows forgotten, refresh queued.

## Shop by country (`gsup_worldwide_shop`, default no)
- `GSUP_Visitor::country()`: `?gsup_c` → cookie `gsup_country` → `HTTP_CF_IPCOUNTRY` → `WC_Geolocation::geolocate_ip()` → base country; `clean()` (UK→GB, WooCommerce countries only). Cookie set on first uncached page (`remember()`), by `?gsup_set_country=XX` (switcher; also WooCommerce customer shipping country, and billing if empty; redirects without the parameter), by `/givsen-supplier/v1/country` (GET, public, no-store) and when a different shipping country is typed at checkout. Switcher: shortcode `gsup_deliver_to`, block `givsen-supplier/deliver-to`, `wp_nav_menu_items` for `gsup_switcher_menu` location.
- Cache: `litespeed_vary_cookies` + `X-LiteSpeed-Vary: cookie=gsup_country` (without LSCWP); `<meta name="gsup-country">` + inline `guard_js()` (cookie or REST → `location.replace(?gsup_c=XX)` when different; skipped on cart/checkout/account); `?gsup_c` pages noindex.
- `GSUP_Shop::reach_for( product, country )`: selling countries → reach rows (deliverable AND in_stock); unlisted + `gsup_sell_others` → `GSUP_Sources::live()`; unlisted otherwise → nothing reaches; no rows → null (unknown: shown and sold as usual). `options()` fastest first (days_max, days_min, warehouse rank) with `extra_for()` (`gsup_wh_pricing` = local: warehouse = country → price × `gsup_local_pct`% + `gsup_local_fixed`).
- Hiding: `hidden_ids( country )` = products with rows for that country and none deliverable in stock (transient per country + `gsup_reach_ver`, bumped on every reach write). `pre_get_posts` on product queries seen by shoppers (front end, front-end AJAX, Store API REST only; not singular main query, not `gsup_all`) adds them to `post__not_in`; `woocommerce_related_products`, up-sell and cross-sell IDs filtered. `?ships_from=XX` on the main query → `post__in` from `ids_from()`.
- Product page: `woocommerce_is_purchasable` / `woocommerce_variation_is_purchasable` false when nothing reaches (only after `wp`, never while the cart loads from the session); notice at summary 25; similar products at after-summary 15 (`similar_ids()`: same category slugs, then any; in stock; live countries checked; WooCommerce related products removed). Delivery choice at `woocommerce_before_add_to_cart_button` (radios `gsup_wh`, one warehouse → hidden input + "Delivery:" line); variations carry `gsup_delivery` HTML (`shop.js` on `found_variation`). `GSUP_Eta` steps aside where `GSUP_Shop::handles()`.
- Cart: `woocommerce_add_to_cart_validation` + `woocommerce_store_api_validate_add_to_cart` refuse undeliverable; `woocommerce_add_cart_item_data` → `gsup_wh` (posted if valid, else fastest) + `gsup_c`; item data "Ships from"; line item `_gsup_wh` + "Ships from"; surcharge in `woocommerce_before_calculate_totals` from the product's fresh price.
- Checkout: `recheck( country )` from `woocommerce_check_cart_items` (cart, checkout, Store API, order submit; country = customer shipping country) and `woocommerce_checkout_update_order_review` (`s_country` else `country`): keep / switch to fastest + notice / error per line; notices not repeated.
- Filter: `counts( country, scope )` (published, deliverable, in stock; scope = current product category/tag with children); `filter_html()` shortcode `gsup_shipping_from`, block `givsen-supplier/shipping-from`, `woocommerce_before_shop_loop` 25 when `gsup_filter_auto` and ≥ 2 warehouses.
- Not selling to the country: `wp_body_open` bar.

## Same-seller trial (`gsup_combine_seller`, default no)
`GSUP_Orders::place()` checks every line first (`prepare_line()` → plan {item, product_id, qty, sku_attr, freight, cost, store_id, ship_from}), then `group()`: key `store_id|ship_from` when on and store_id known, else one per line. `submit()` sends a group in one `place_order()` (`out_order_id` = number-firstItemID-xN). Success → `match_orders()`: `get_orders()` on the returned numbers; a line whose product ID is in exactly one order gets that number, else all numbers. Refused (not a network/unknown error) → logged, then each line submitted alone (`number-itemID`). Unknown → every line flagged `gsup_unknown`, `_gsup_placing` kept, not retried. Each group of 2+ is logged in `gsup_combine_log` (last 20: at, order, store, lines {name, qty, product, method, fee, cost, orders}, result one/split/error/unknown, orders {number: amount, currency, products}) and shown under Settings → Ordering → Combined orders so far; order note added. Item cost stays its own quote; when tracking later reads an order's amount, `cost_shares()` splits a number shared by several lines by their current costs. Listing `store_id` from `ae_store_info`; order `products`/`store_id` from `child_order_list` / `store_info`.

## Givsen gift plugin
`GSUP_Givsen` (active when `givsen_is_gift_order()` exists). `_givsen_mode` corporate → never queued/placed, panel explains. corporate_child → loss check and profit from `child_share()` = parent line total ÷ quantity + parent shipping ÷ quantity, fees ÷ quantity (not cached on the child). Phone: shipping → billing → parent billing (child) → `gsup_fallback_phone`. `hide_from_buyer()` = `givsen_is_gift_order()` and `_givsen_address_by` ≠ sender → no tracking in customer emails/My Account, no AST. Delivered email: personal gift → buyer ("to {first name}", via `givsen_greeting_first_name()`); corporate_child → billing email (the recipient's) or nobody; corporate parent → nobody. Report adds fee-only orders (`_givsen_postage_for`) as revenue.

## Backup supplier
`_gsup_backup` = {product_id, ship, map {item ID: SKU ID}, saved_at}. Saved from Change supplier ("Save as backup"). Sync `try_backup()`: triggers — listing gone/not for sale (before drafting), any option gone, max option cost rise > `gsup_backup_rise`% (default 15) when the backup (price + one delivery quote) is cheaper for the mapped items. Usable only if on sale, every mapped SKU exists in the backup's warehouse and isn't out of stock. Switch via `GSUP_Remap::switch_to()` (no reprice, republish if drafted by sync); for a price rise the old listing becomes the backup. Report keys `switched`, `backup_failed`. Off with `gsup_backup_auto` = no.

## Parcels
Item meta: `_gsup_placed_at`, `_gsup_eta_days` (freight max days when placed automatically), `_gsup_delivered_at`, `_gsup_parcel_last`, `_gsup_alert_notrack`, `_gsup_alert_late`. Order meta: `_gsup_in_transit`, `_gsup_parcel_alert`, `_gsup_delivered_at`. No-tracking alert raised in the tracking check after `gsup_late_notrack_days` (7). `gsup_parcel_check` (every 12 h): up to 40 in-transit orders, least recently checked first, `aliexpress.ds.order.tracking.get` in parallel; delivered when an event reads delivered/signed/picked up by recipient (not attempted/failed/out for delivery). Late when past placed + (eta + `gsup_late_grace_days` (5)) days, or 35 days without an eta. Delivered email (`gsup_delivered_email`, default on; filters `gsup_delivered_email_recipients`, `_heading`, `_body`). `gsup_complete_when`: tracking (default) / delivered / no. Watching stops for closed orders and after 120 days.

## Profit report
Tab `reports`: `GSUP_Report::month()` over paid statuses, 100 orders a page, using cached order summaries (lines carry product, name, qty). Transient per month: current 30 min, past a week.

## Repricing and stock buffer
`gsup_sync_prices`: no / low / yes. Low: regular price raised to the rule price only when margin at the current regular price < minimum and the rule price is higher. `gsup_stock_min` (sold out below), `gsup_stock_cap` (max shown) via `GSUP_Sync::store_qty()` in sync, Add to store and Change supplier.

## Bulk tidy-up
Bulk action `gsup_tidy` → `tab=tidy&ids=` (max 50). Options: titles (editable), descriptions, specs (AliExpress listings fetched in parallel; only names not already on the product), custom option names/values (unique; variations updated, case/slug-tolerant). `_gsup_tidy_undo` saves title, description, `_product_attributes` and variations' attribute meta; "Undo tidy-up" restores them.

## Add to store: what to bring
Empty result (no words, e.g. image-only listings) → words captured from the page by the extension (import row `page_text`, latest for the product; `GSUP_Tidy::page_text_html()`) → `GSUP_Tidy::mobile_text()` of `ae_item_base_info_dto.mobile_detail` (JSON modules or HTML) → else `GSUP_Tidy::starter_description()` (title, renamed options, specifics); `GSUP_Creator::$description_note` shown after creating. Per-user `gsup_import_prefs`: description `text` (`GSUP_Tidy::description_text()` — p/ul/ol/li/strong/em only, table rows "Name: value", boilerplate removed) / `clean` (`GSUP_Tidy::description()` + `GSUP_Creator::localize_images()`, max 15) / `empty`; `photos` 0–10 (default 10); `option_photos`; `desc_photos` (description images from AliExpress hosts, no GIFs, not already among the listing photos, max 8, appended to the gallery; not with `clean`); `specs`; `short`. Boilerplate: `GSUP_Tidy::is_boilerplate()` (filter `gsup_tidy_boilerplate`).

## Rewrite with AI
`GSUP_AI`: bulk action `gsup_ai` and row link → `tab=ai&ids=` (max 50). Browser calls `wp_ajax_gsup_ai_rewrite` per product (nonce, `edit_product`), two at a time. Request: `POST https://api.anthropic.com/v1/messages`, headers `x-api-key` (option `gsup_ai_key`, admin-only), `anthropic-version: 2023-06-01`; model `gsup_ai_model` (`claude-haiku-4-5` default, `claude-sonnet-5`); `max_tokens` 2000; system prompt built from voice/extra/title length/spelling with fixed rules (facts only, keep specs, never mention sourcing/shipping/reviews, HTML limits); user message = JSON of current title, description text, short description, attributes, categories; `output_config.format` json_schema {title, description_html, short_description}, additionalProperties false. Handles refusal, max_tokens, 401/429/529/credit errors. Output: title `sanitize_text_field`, description `wp_kses` p/ul/li/strong, short plain text. Apply (`gsup_ai_apply`, `edit_products` + per-product `edit_product`) saves `_gsup_tidy_undo` (now with short description) first. No prompt caching: the prompt is below Haiku 4.5's minimum cacheable length.

## Delivery estimate
`_gsup_ship_days` "min-max" on products/variations (freight quote). `GSUP_Eta` (off by default, `gsup_eta_show`): `woocommerce_single_product_summary` at 15 and `woocommerce_available_variation` (added to availability_html). Days + `gsup_eta_processing` (1); `gsup_eta_format` dates/days; `gsup_eta_business` skips weekends. Filter `gsup_eta_text`. Hidden when out of stock or no estimate.

## Diagnostics
`gsup_ae_log_entries` (last 30; not autoloaded), written at shutdown. Params without session/sign/app_key/tokens/code; order requests keep only product items and country. /auth calls never logged. Off with `gsup_ae_log` = no.

## Tests
`tests/run.sh`: PHP lint, extension syntax, `tests/test-*.php` (stand-ins for WordPress/WooCommerce), `tests/browser/*.test.js` (Playwright + Chromium on saved pages in `tests/fixtures/`; skipped locally without Playwright, required in CI), render of every settings section. GitHub Actions: `.github/workflows/tests.yml`.

## Customer-facing
Tracking link `https://t.17track.net/en#nums=` (filter `gsup_tracking_url`). Carrier shown only via `GSUP_Orders::local_carrier()` (filter `gsup_local_carriers`); AST only when a local carrier is recognised (item `_gsup_in_ast`), otherwise the plugin's own display.

## Security
Signed requests: `X-Gsup-Sig-Version: 2` → HMAC of `ts.METHOD./route.body`; v1 (`ts.body`) accepted only for /ping, /import, /categories. Each signature accepted once (transient for 10 min). Admin-only (`manage_options`): save app, connect, disconnect, automatic ordering, new connection key. CBR keys refused when `GSUP_CBR::protected_key()`.

## Change supplier
`tab=remap&product=<id>` (Supplier tab button; links from products list, order panel, sync email). New link → listing fetched for the product's warehouse (single ships-from) else AU; warehouse switchable. Auto-match (`GSUP_Remap::auto_match`): words from each item's attribute values + stored AliExpress option text vs each new option's text (filler, "color/size", piece counts dropped; numbers kept); score = overlap ÷ smaller side (×0.8) + Jaccard (×0.2); greedy one-to-one, threshold 0.5; single item + single option always match; below 0.8 flagged "best guess". Save: SKU/option/cost/ships-from/delivery fee+method/stock on matched items (regular price kept unless "reprice"); unmatched → SKU/option removed, out of stock, `_gsup_option_gone`. Parent: new product ID, `_gsup_supplier_history` (last 10), sync flags cleared, `_gsup_ship_quoted` set, republished if chosen and drafted by the sync, margin flag, CBR (overwritten when the warehouse changed).

## Product text (Add to store)
`GSUP_Tidy`: title (filler list `gsup_tidy_title_words`, years, piece counts, repeated words, ALL-CAPS → Title Case keeping acronyms/sizes `gsup_tidy_acronyms`, max `gsup_tidy_title_length` 90); option names/values (editable, kept unique per option); description (removes script/style/iframe/forms, comments, style/class/size attributes, font/span, AliExpress links; images https + lazy + alt; empty blocks); specs from `ae_item_properties` minus `gsup_tidy_skip_specs` and empty values, max 12, as visible non-variation attributes (skipping names used by variations); optional short description (first 5). `_gsup_ae_option` keeps AliExpress's own wording for ordering.

## Performance
- AliExpress: `request_many()` via `WpOrg\Requests\Requests::request_multiple`, chunks of `gsup_ae_parallel` (5), WP CA bundle and proxy; sequential fallback. Batch helpers `get_products()`, `freights()`, `get_orders()`.
- Sync prefetches each batch (25) in parallel; delivery quote due when `_gsup_ship_quoted` is older than `gsup_ship_quote_days` (7) or an option lacks a fee.
- Nothing runs on shop page loads: `maybe_upgrade()` and `gsup_ensure_schedules()` only in admin/cron/CLI, schedules re-checked at most every 12 h (`gsup_schedules_ok` transient).
- `_gsup_margin` product summary (refreshed with the low-margin flag); `_gsup_profit_cache` on orders keyed by modified date + profit settings.
- `gsup_prime_links()` answers link lookups for a whole screen in two queries.

## Settings screen
`tab=settings&section=<overview|aliexpress|ordering|pricing|sync|worldwide|ai|countries|extension>` (default overview; `gsup_settings_url()`). Side menu with a status badge per section (tab row under 960px). Overview: "Needs your attention" (AliExpress not connected, import list waiting, Processing orders with auto state failed/partial, low-margin products, last sync stopped) and one card per section. Every save/connect/test action returns to its own section. Save forms (`.gsup-save-form`) warn on leaving with unsaved changes.

## Regression checklist
Run after any change, on a staging copy with MySQL.
1. Activate → WooCommerce → Givsen Supplier opens; Settings → Overview shows every section; Chrome extension section shows a 40-character key. Each section's badge matches its state; each Save lands back on the same section; editing a field then clicking another section asks before leaving.
2. Simple product → Supplier tab → paste a full AliExpress link as product ID → Update → field shows just the number, "Open on AliExpress" works.
3. Type nonsense in the product ID → Update → error notice, previous ID kept.
4. Variable product → Variations → fill SKU / ships-from / option on one variation → Save changes → values persist after reload.
5. Products list → Supplier column shows ID, "1/2 options linked" in amber, ships-from; Linked / Not linked filter works.
6. Rename the product and a variation's attribute value → links still show everywhere.
7. Import list → add by hand from a link with `sku_id` in it → SKU is picked up. Add the same again → "already in the import list — updated".
8. Link an import row to a variation → success notice; row moves to Linked; variation shows the SKU.
9. Link a different AliExpress product to that same parent → refused with explanation.
10. Dismiss → Restore → Delete a row.
11. Extension "Test connection" succeeds; with a wrong key it reports the key mismatch.
12. Processing order → green banner, correct option/ships-from/qty per item, address in full with working Copy buttons.
13. Enter AliExpress order + tracking → Update → values persist and an order note is added. Clear one → note says removed.
14. Awaiting Givsen Address order → red banner, no address shown.
15. Repeat 12–13 with HPOS on (WooCommerce → Settings → Advanced → Features).
16. Settings → save App Key/Secret → Secret field shows "Saved"; Connect AliExpress → approve → "AliExpress is connected" with account and expiry.
17. Test with a product (a variable listing) → every option listed with ships-from, SKU ID, price, stock; switch AU/US and stock changes accordingly.
18. Test with a removed product ID → plain error.
19. Add to Givsen on AliExpress → import row shows AliExpress's option text and "Checked with AliExpress."; extension message shows the confirmed option.
20. Change the App Secret to a wrong value → Test shows the signature message; restore it.
21. Import list → Add to store as new product → warehouse buttons show counts; switching warehouse reloads its options; options already in the store are unticked and labelled.
22. Create with two options → lands on the new draft with a success message; variable product, Color attribute only, prices follow the rule, stock set, photos in Media Library, each variation's Supplier fields filled.
23. Create with one option → simple product with Supplier tab filled.
24. Untick everything → "Tick at least one option to add."
25. Settings → Pricing → change multiplier/extra/rounding → example price updates.
26. Extension card → Category dropdown lists store categories with full paths, remembers the last one; message shows "Category: …".
27. Import list row shows "Category: …"; Add to store from that row has it ticked and says "Ticked from what you chose in the extension".
28. Create → product has that category; next Add to store without a row has it ticked as "last time".
29. Create with no category ticked → Uncategorized (WooCommerce default).
30. Settings → Daily sync → Sync now → "Sync running: x of y" → refresh until "Last sync" shows the report.
31. Change stock/cost on AliExpress (or wait for a real change) → stock and cost updated; prices unchanged unless price updates are on.
32. A product whose AliExpress listing is gone → drafted, email received, products list shows "Removed on AliExpress", order panel warns.
33. Disconnect AliExpress → Sync now → report says not connected; nothing drafted.
34. Check wp-content/debug.log (WP_DEBUG on) → no Givsen Supplier warnings.
35. Add to store → "Delivery to Australia: $x with …" line; Delivery and Margin columns; price includes delivery. Created product's Supplier tab shows cost + delivery + margin.
36. Settings → Pricing → untick Delivery → example price drops; Add to store prices exclude delivery.
37. Sync now → "Delivery fee changes" in the report; raise a product's `_gsup_cost` sharply by hand → next sync (if AliExpress is lower it resets; to test the flag, lower your price instead) → product shows red "Low margin"; filter "Low margin" finds it.
38. Settings → Profit → fees 1.75% + 0.30 → margins drop everywhere; order panel shows Payment fees row; orders list Profit column.
39. Automatic ordering off → new paid order: panel says "Automatic ordering is off", nothing placed.
40. Turn on (auto-pay off for testing) → place a real small order in the store → within ~2 minutes: AliExpress order number on the line, note with delivery method and cost; order visible in AliExpress awaiting payment.
41. Gift order in Awaiting Givsen Address → nothing placed; after claim (→ Processing) it's placed.
42. Order with an unlinked item and a linked one → linked placed; unlinked ignored. Order with an option gone → reason shown, email received; "Place on AliExpress now" after fixing places it.
43. Lower a product's price below cost → order → refused by the loss guard with both amounts.
44. Enter an AliExpress order number by hand → Update → "Check tracking now" appears; once shipped, tracking + carrier fill in, note added, order Completed, customer email has the tracking link.
45. Settings → Country restrictions → Look at a product with CBR set by hand → its keys are listed and match step 2.
46. Turn on → Add to store (AU warehouse) → product has "Shown to: AU". Apply to linked products → summary counts; products with own restriction kept unless "replace" ticked.
47. Repeat 40–44 with HPOS on.
48. Extension 0.5.0 on a listing → "Use as backup for a product in your store" lists linked products (search narrows it); the product whose supplier this listing is shows the button disabled with "already that product's main supplier".
49. Pick a product with no backup → Save → "N of M options matched", unmatched names, cost now vs backup, link opens the Supplier tab showing the backup and "N option(s) not matched — review" (link opens Change supplier with the listing). Prices and main supplier unchanged.
50. Pick a product that has a backup → note "This replaces the current backup (ID …)"; first click only asks to confirm, second click replaces.
51. On a listing whose options all ship from one warehouse (e.g. AU), save it as a backup with a different ships-from chosen (e.g. United States) → refused with "no options shipping from …", nothing saved. An older extension (0.4.x) can still send to the import list but can't use /backup.
52. Extension 0.6.0 on an AliExpress search (Ships from: Australia) → a "Givsen" checkbox on every product card and the "Add N to Givsen" bar bottom-left; cards already linked say "In store", cards in the import list say "In import list", all unticked. Scroll → new results get checkboxes too.
53. Tick 3 new products + 1 "In import list" product, choose a category → Add 4 → "Added 3, already there 1"; the ticked cards now say "In import list". Import list shows 3 new rows with "Choose warehouse and options on Add to store" and the category; within a minute (WP-Cron/Action Scheduler) each row's note says it was checked with AliExpress (title/picture updated; single-option listings show their option and ships-from).
54. Try to tick a 31st product → can't (boxes disabled, bar says "max 30"). Add to store on a bulk row → choose warehouse and options as usual → linked; the card then shows "In store" after a page refresh.
55. A store page and a product page's "More to love" section → checkboxes on the products (not on the product you're viewing). An AliExpress page without products (orders, cart) → no checkboxes, no bar.
56. Same-seller trial on → order with 2 items from one AU seller → placed in one request: order note "Combined order trial: 2 items … → one AliExpress order (…)" or "… 2 AliExpress orders"; each item shows its own order number; Settings → Ordering → Combined orders so far lists it with quoted vs charged. Compare with the AliExpress order page (one parcel? one delivery fee?).
57. Trial on, same order with one item out of stock on AliExpress → the out-of-stock item is refused before sending; the other is placed alone. Trial off → one AliExpress order per item as before. Profit on a shared order after tracking arrives = AliExpress's total, split between the items (not doubled).
58. Settings → Selling worldwide → defaults show the 10 countries, "Also sell to other countries" ticked, stock "From each option's main warehouse"; the calls estimate matches linked products × 10 × (1 + warehouses). Remove a country, save → lands back on the section, list kept.
59. Existing AU product whose listing also ships from China → Supplier tab → Refresh sources → message lists Australia and China; each option shows two chips (Australia bold), hover shows delivery days per country. Shop page for that product unchanged (price, stock, delivery estimate).
60. Products list → select 5 linked products → Bulk actions → Refresh sources → notice; after the background jobs run, their Supplier tabs show warehouses. Settings → Selling worldwide → Check all products now → progress, then "Last check" with the number of results.
61. Import list → Add to store (default "All warehouses in one product") on a listing with AU and CN options → one row per option with a chip per warehouse; an option sold out in AU has China as its main warehouse. Create → one draft product; each variation's Supplier fields show its main warehouse; Supplier tab lists all warehouses; no "Shown to:" restriction. "One warehouse only" still works as before.
62. Stock rule "main warehouse": an option sold out in AU but in stock in CN stays out of stock after the daily sync. Switch to "any warehouse" → next sync shows it in stock; remove the AU option on AliExpress (or change its SKU on a test product) → sync email "now supplied from another warehouse", option stays buyable, its ships-from is China.
63. Settings → Selling worldwide → tick "Show delivery by country in the shop" → save. In a private window the header menu (if chosen) shows "Deliver to: 🇦🇺 Australia — change"; change to United Kingdom → page reloads for the UK, WooCommerce's shipping calculator shows the UK.
64. As a UK visitor: shop, a category, search and a product's related products don't list products that can't reach the UK; the "Shipping from" filter shows only warehouses reaching the UK with counts that match the filtered grid. Open an unreachable product by its link → "Not available for delivery to United Kingdom", no add to cart, "Similar products we deliver to United Kingdom".
65. Product with AU and CN warehouses, as an Australian visitor → "Delivery" lists Australia (fastest, ticked) and China with days and dates; pick China → add to cart → cart shows "Ships from: China · 10–18 days"; order line shows it after checkout. With "local warehouses cost extra" (+10%) the Australian choice shows "+$x" and the cart price includes it.
66. Checkout with an Australian-warehouse item, change the shipping country to New Zealand (not in the list, AU can't deliver) → notice that it will ship from China instead; to a country nothing reaches → error for that item, order can't be placed. Same with the block checkout.
67. With LiteSpeed Cache on: visit as AU, switch to GB, go back to the shop → UK version (no reload). With Cloudflare "Cache Everything": same steps → the page reloads once as ?gsup_c=GB; view source shows noindex on that copy. Cart and checkout never reload.
68. Visitor from a country you don't sell to (untick "Also sell to other countries", switch to France) → bar "We don't deliver to France yet", products shown but not buyable.
