# Givsen Supplier — Changelog

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
