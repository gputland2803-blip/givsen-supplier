# Givsen Supplier — Changelog

## 0.8.2 — 2026-09-26
Your store, not AliExpress's.
- **Choose what Add to store brings in** ("What to bring into your store", remembered for next time):
  - Description: *the wording only, to rewrite* (default — paragraphs, lists and spec rows, no layout), *cleaned with its images* (images copied to your site), or *nothing*.
  - Photos: up to 10 product photos (default all, up to 10), each option's photo on its variation, and the description's own photos (detail shots, size charts) added to the gallery — so rewriting the text loses no pictures.
  - Item specifics and the short description, as before.
- **Seller notes removed** from descriptions: "leave 5-star feedback", "visit our store", "contact us before opening a dispute", AliExpress/Alibaba mentions, shipping/payment/return policy blocks.
- **Nothing customer-facing points at AliExpress**: tracking links go to 17TRACK (works out the carrier from the number) instead of Cainiao; only everyday carrier names (Australia Post, StarTrack, CouriersPlease, Aramex…) are shown to customers, never "AliExpress Standard Shipping"; Advanced Shipment Tracking only gets tracking with a recognised carrier; description images can be copied to your site.
- Bulk tidy-up: description cleaning is off by default (so it won't touch descriptions you've rewritten) and copies description images to your site when used.
- **Review import removed** (plugin endpoint, setting and extension button).
- **Security hardening**:
  - Requests from the Chrome extension are signed together with their method and endpoint (extension 0.4.1) and each is accepted only once; older extensions still work for their original endpoints.
  - AliExpress app details, connecting/disconnecting, automatic ordering and payment, and the connection key are administrators-only (shop managers can use everything else).
  - Country-restriction keys can't be pointed at WooCommerce's own product data (price, stock…).

## 0.8.1 — 2026-09-26
Works with the Givsen gift plugin (tested against Givsen for WooCommerce 1.4.2; only active when it is).
- **Business gifting**: the paid parent order is never placed on AliExpress (it ships nothing; the panel says so). Each recipient's $0 order is placed as normal — the "would lose money" check and its profit now use the parent's price, shipping and fees per recipient instead of $0.
- **Privacy**: tracking numbers and links aren't shown to the buyer of a personal gift (they'd reveal where it went) and aren't sent to Advanced Shipment Tracking for those orders — unless the buyer typed the address themselves.
- **Delivered emails, gift-worded**: personal gifts → the buyer, "Your gift to Jo has been delivered" (who, never where; Givsen doesn't collect the recipient's email). Business gifting → the recipient, at the email they gave when claiming, "Your gift from Acme has been delivered"; no email if they gave none.
- **Phone for the courier** (Settings → Ordering & tracking): AliExpress needs one; used when an order has none, e.g. a recipient who left theirs blank. Business gifting tries the business's phone first.
- **Profit report** counts payments with no products (Givsen's extra-postage orders) as revenue.

## 0.8.0 — 2026-09-26
Eight additions.
1. **Backup supplier per product**: on Change supplier, "Save as backup supplier" remembers the new listing and its option matches without switching. The daily sync switches over automatically when the listing is removed or not for sale, an option disappears, or its cost rises more than a set % (default 15) and the backup is cheaper — the old listing then becomes the backup. Prices are never changed by a switch; you're emailed. Settings → Daily sync; backup shown (with Remove) on the Supplier tab and products list.
2. **Late parcel alerts**: no tracking N days after ordering (default 7), or not delivered by AliExpress's latest estimate + grace days (default 5; 35 days without an estimate) → order note, one email per check, Overview item, and an "Open on AliExpress / dispute" link on the order.
3. **Delivered status**: parcels in transit are checked twice a day (in parallel); delivery is read from AliExpress's tracking events. Delivered date per item, order note, optional "your order has been delivered" email to the customer in WooCommerce's style (recipients can be changed with the `gsup_delivered_email_recipients` filter, e.g. for gift recipients). Orders can now be marked Completed when tracking arrives, when delivered, or never.
4. **Profit report** tab: last 12 months (orders, revenue, AliExpress cost, fees, profit, margin) and each product's profit for a chosen month. Cached; recalculate link.
5. **Smarter repricing**: sync prices can be never changed, raised only when a price's margin falls below your minimum (up to your pricing rule, never lowered), or always follow the rule.
6. **Stock buffer**: show sold out when AliExpress has fewer than N left, and cap the stock shown.
7. **Bulk tidy-up**: Products → Bulk actions → "Tidy text (Givsen Supplier)" for up to 50 products: preview with editable titles, then titles, descriptions, item specifics (from AliExpress) and custom option names/values. "Undo tidy-up" on each product's Supplier tab.
8. **Review import** (Chrome extension 0.4.0): "Import reviews" on the Add to Givsen card, with filters (minimum stars, with text, with photos, how many). Added to every linked store product as WooCommerce reviews with stars and photos, pending your approval unless you choose otherwise; never duplicated.

## 0.7.0 — 2026-09-26
Change supplier, tidy product text, speed.
- **Change supplier** (product's Supplier tab, products list, order panel and sync email wherever a listing is removed): paste a new AliExpress link, pick the warehouse, and each variation is matched to the new listing's options automatically — by its option values (even after renaming), then by what it was on AliExpress. Change any match, see the new cost and margin at your current price, optionally reprice and republish. Title, description, photos, prices, reviews and URL stay as they are. Unmatched options are set out of stock. "Search AliExpress for a replacement" link. Earlier listings are kept in the product's history.
- **Tidy product text on Add to store**: title cleaned of sales filler, years, piece counts, repeats and shouting (original one click away); option names and values tidied ("1PC-RED" → "Red") and editable before creating; description cleaned of AliExpress styling, fonts, fixed sizes and links back to AliExpress, images lazy-loaded; item specifics (Material, Capacity…) added to WooCommerce's Additional information tab, noise like Origin/CN/Brand Name: None left out; optional short description from the specifics. Links stay by ID, so renaming never affects ordering or sync.
- Renamed options always stay distinct (a clash falls back to the AliExpress name, then a number), so no two variations can end up identical.
- **Faster, lighter**:
  - Background jobs make several AliExpress calls at once (5 at a time; filter `gsup_ae_parallel`). The daily sync fetches a whole batch of listings and delivery quotes together (batch 15 → 25); the tracking check fetches all waiting AliExpress orders together.
  - Delivery quotes refresh weekly per product (filter `gsup_ship_quote_days`) instead of daily — about half the sync's AliExpress calls.
  - Shop pages no longer do any Givsen Supplier database work on load: schedule and upgrade checks run only in the admin and background jobs, at most every 12 hours.
  - Products list reads a stored margin summary instead of loading every variation; orders list caches profit per order until the order changes.
  - Import list and Add to store look up existing links with two queries per page instead of up to two per row.
  - Overview counts no longer load every matching order or product.

## 0.6.1 — 2026-09-26
Settings redesign and fixes.
- **Settings redesigned**: a side menu of sections (Overview, AliExpress connection, Ordering & tracking, Pricing & profit, Daily sync, Country restrictions, Chrome extension), each with an on/off/needs-attention badge. Overview lists anything waiting on you (not connected, import list items, orders that couldn't be placed, low-margin products, a stopped sync) and a card per area. Save buttons stay on their section, a warning appears if you leave with unsaved changes, CBR storage keys are tucked under "Advanced", and the menu becomes a tab row on narrow screens.
- Fix: Tracking queue rotates so dead orders can't block new ones.
- Fix: Cancelled/unknown AliExpress orders and fully refunded items stop waiting for tracking.
- Fix: Refunded items aren't reported as ordering problems.
- Fix: The order note says when an order still needs paying on AliExpress.
- Fix: AliExpress amounts in another currency aren't used as cost.
- Fix: A new AliExpress order number clears the old cancellation warning.
- Fix: Refunded tax no longer counted twice in order profit.
- Fix: No false "cost went up" alerts on the first sync after upgrading.

## 0.6.0 — 2026-09-26
Automatic ordering, tracking, profit, real shipping cost, CBR.
- **Automatic ordering** (Settings → Automatic ordering, off until you turn it on): when an order reaches Processing — paid, or a Givsen gift once claimed — each item is placed on AliExpress in the background with the customer's address and the exact option, using your shipping method preference (cheapest with tracking by default). Optional automatic payment with your AliExpress account's saved method. The AliExpress order number, delivery method and cost are saved on the order.
- Safety: items are only placed when they're linked to an exact option, still sold, in stock, deliverable to that country, and (unless switched off) cost less than the customer paid. Anything else is left for you with the reason on the order panel, an order note and an email. Items already on AliExpress are never placed twice; if AliExpress doesn't answer, it's not retried automatically.
- Order panel: status line, "Place on AliExpress now" and "Check tracking now" buttons, AliExpress status, delivery method and carrier per item.
- **Tracking**: every 4 hours, orders with AliExpress order numbers (automatic or typed in) are checked; tracking numbers and carriers come back into the order with a note. Order marked Completed once everything has tracking (optional, on by default). Tracking links in customer emails and My Account; sent to Advanced Shipment Tracking instead when it's installed.
- **Profit**: margin and profit per product on the products list (range for variable products), red "Low margin" below your minimum (default 30%) and a "Low margin" filter. Cost, delivery and margin on the product editor. Profit per item and per order on the order panel, and a Profit column on the orders list. Each item's cost is saved at checkout so later cost changes don't rewrite past orders; the real AliExpress amount replaces the estimate once known. Optional payment fee (% + fixed) in Settings → Profit.
- Daily sync flags products whose margin a cost rise has pushed below the minimum (report and email).
- **Real shipping cost**: AliExpress's delivery fee is quoted when adding to store and kept up to date by the daily sync. Pricing rule can include it (on by default; Settings → Pricing). Add to store shows the delivery fee, method and margin per option.
- **Country restrictions (CBR)** from ships-from (Settings → Country restrictions, off until you turn it on): warehouse → countries map (Australia → AU, United States → US to start). New products get the restriction automatically; "Apply to linked products" does existing ones, keeping restrictions you set by hand unless you choose to replace them. "Look at a product" shows exactly how CBR saved its setting so the keys can be matched. Products list shows "Shown to: …".
- Uninstall also removes the new settings and scheduled checks (order data and product costs are kept).

## 0.5.0 — 2026-09-26
Daily sync.
- Every day at about 3am (WooCommerce's job queue, 15 products per batch): stock and AliExpress cost for every linked product and option.
- Optional: update regular prices from the pricing rule when costs change (off by default; sale prices never touched).
- Option no longer on AliExpress → that variation set out of stock (restored automatically if it comes back).
- Product removed on AliExpress → switched to draft (only if published). Immediately when AliExpress says it doesn't exist / is offline / not for sale; otherwise only after two missed syncs in a row.
- Connection, sign-in or AliExpress server problems stop the run and never draft anything.
- One summary email per run, only when something needs attention (removed products, gone options, products back on sale, or a stopped run). Address set in Settings.
- Settings → Daily sync: on/off, price updates on/off, email address, "Sync now", last-run report with links, progress while running.
- Products list: "Removed on AliExpress", "Not found at last sync", options no longer on AliExpress, "Synced … ago".
- Order panel warns when an item's product or option is no longer sold on AliExpress.
- Never changes titles, descriptions, photos or categories.

## 0.4.0 — 2026-09-26
Categories.
- Add to store screen: "3. Category" picker with your store's categories as a tree. Ticked from what you chose in the extension, otherwise your last-used categories. Nothing ticked → WooCommerce's default category.
- Chrome extension (0.3.0+) can choose a category while you're on AliExpress: new signed endpoint `givsen-supplier/v1/categories`; `/import` accepts `category_ids` and reports the category names back.
- Import list shows each row's chosen category.
- Import list table gains `category_ids` (upgrades itself).

## 0.3.0 — 2026-09-26
Add to store.
- "Add to store as new product" from any import-list row and from the Settings product test.
- Choose the warehouse (Australia, United States, China…) — one product per warehouse to match country segmentation — then tick the options to include. Options already in the store and out-of-stock options start unticked.
- Creates a draft WooCommerce product: title (editable), AliExpress description (scripts and unsafe code removed), up to 6 photos copied to the Media Library at full size, option photos on variations. Variable product for several options, simple product for one. Ships-from is kept in the supplier fields, not shown to customers.
- Sets every supplier link (product ID, SKU ID, ships-from, option text), AliExpress cost, stock, and your price from the pricing rule.
- Settings → Pricing for new products: multiplier, extra amount, round up to .95, with a live example.
- Import-list rows for that product and warehouse are marked Linked automatically.
- Warns when photos couldn't be downloaded.
- AliExpress connection: short-lived connections now renew at a quarter of their life instead of on every call; background renewal twice a day keeps it alive on quiet days.

## 0.2.0 — 2026-09-26
Stage 3a: AliExpress API connection and auto-fill.
- Settings → AliExpress API: App Key, App Secret (stored on the site only), Callback URL to paste into the AliExpress console, Connect / Reconnect / Disconnect, connection status and expiry.
- Secure connection flow: one-time state code, replayed links refused. Access renews automatically a few days before it expires; plain "Reconnect" message if it can't.
- "Test with a product": fetches a product for delivery to Australia or the United States and lists every option with ships-from, SKU ID, price and stock.
- Import list auto-fill: items from the extension or added by hand are checked with AliExpress — title, image, the option's exact text, ships-from and price. Products with a single option get it picked automatically. Notes flag removed products, options no longer listed, out-of-stock options and duplicates.
- "Check with AliExpress" action on import-list rows.
- Long AliExpress IDs are kept exactly (no rounding).
- Import list table gains api_note and api_checked_at (upgrades itself).
- Uninstall also removes the App Key, App Secret and connection.

## 0.1.0 — 2026-09-26
First release (stage 1: plugin foundation). Runs alongside DSers; nothing is shared with it.

- Supplier tab on products: AliExpress product ID (paste the link or the number). Simple products also store the option's SKU ID, ships-from country and the option text as AliExpress shows it.
- Supplier fields on every variation: SKU ID, ships-from, option text.
- Products list: "Supplier" column (linked ID, options linked e.g. 1/2, ships-from) and a Linked / Not linked filter.
- WooCommerce → Givsen Supplier → Import list: link captured AliExpress items to a product or variation by search; dismiss, restore, delete; add by hand from a link; menu badge counts items waiting.
- Settings tab: site address and connection key for the Chrome extension, with a button to make a new key.
- Signed REST endpoints for the extension: `givsen-supplier/v1/ping` (GET) and `givsen-supplier/v1/import` (POST).
- Order screen: "Order on AliExpress" panel — readiness banner (blocks unclaimed Givsen gifts), per-item AliExpress link, exact option, ships-from, SKU and quantity, copy-ready address in AliExpress field order, per-item AliExpress order number and tracking number with an order note on change.
- Works with WooCommerce's classic order storage and HPOS.
