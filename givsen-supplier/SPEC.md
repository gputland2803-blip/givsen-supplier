# Givsen Supplier — Specification

Version: 0.5.0 · Replaces DSers for givsen.com (WooCommerce, Stripe, CBR country segmentation).

## Core rule
The supplier link lives on the WooCommerce product, by ID. Titles, descriptions, attribute names and option names are never used to find a supplier item, so renaming anything can't break a link.

| Where | Meta key | Holds |
|---|---|---|
| Product (simple or variable parent) | `_gsup_ae_product_id` | AliExpress product ID |
| Variation, or simple product | `_gsup_ae_sku_id` | AliExpress SKU ID (identifies the option, including ships-from) |
| Variation, or simple product | `_gsup_ship_from` | 2-letter country code the option ships from |
| Variation, or simple product | `_gsup_ae_option` | Option text as AliExpress shows it (human guide for ordering) |
| Variation, or simple product | `_gsup_cost` | AliExpress cost when added/last synced (store currency) |
| Product | `_gsup_sync_status` | '' / `missing` / `removed` |
| Product | `_gsup_sync_missing` | Consecutive syncs where AliExpress had no details |
| Product | `_gsup_drafted_by_sync` | When the sync drafted it (cleared when reported back on sale) |
| Product | `_gsup_synced_at` | Last successful sync |
| Variation, or simple product | `_gsup_option_gone` | Set when the option is no longer on the listing |
| Order line item | `_gsup_ae_order_no` | AliExpress order number |
| Order line item | `_gsup_tracking_no` | Tracking number |

Variable parents never hold SKU / ships-from / option — saving a variable product clears them from the parent.

## Import list
Table `{prefix}gsup_import`. Statuses: `new` (To link), `linked`, `dismissed`. The same product ID + SKU ID updates the existing row instead of adding a duplicate (a dismissed row returns to To link). Linking:
- to a variation → product ID on the parent, SKU / ships-from / option on the variation;
- to a simple product → all on the product;
- to a variable parent → product ID only (warns that the option wasn't stored);
- refused if the parent is already linked to a different AliExpress product.
Empty captured values never overwrite existing ones.

## Extension endpoints
Namespace `givsen-supplier/v1`. Every request sends `X-Gsup-Timestamp` (unix seconds) and `X-Gsup-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw body>"` with the connection key (option `gsup_secret`). Older than 5 minutes → refused.
- `GET /ping` → `{ok, site, version}`
- `GET /categories` → `{ok, categories:[{id, name (full path), depth}]}`
- `POST /import` body `{product_id | url, sku_id, ship_from, option, title, image, price, currency, category_ids[]}` → `{ok, id, duplicate, status, product_id, sku_id, ship_from, option, title, api_note, categories[], linked:[{id,name,edit_url}], import_list}`. Unknown category IDs are dropped.
`ship_from` accepts a code (AU) or a name as AliExpress shows it (Australia, United States).

## AliExpress API (Dropshipping)
Gateway `https://api-sg.aliexpress.com` (`/sync` for methods, `/rest/...` for auth). Signing: all parameters sorted by name, `name+value` concatenated, REST path prefixed for `/auth/...` calls, HMAC-SHA256 with the App Secret, upper-case hex. Options: `gsup_ae_app_key`, `gsup_ae_app_secret`, `gsup_ae_token` (not autoloaded).
- Connect: `/oauth/authorize` → back to `givsen-supplier/v1/ae-callback` with a one-time state (15 minutes) → `/auth/token/create`.
- Access token renews via `/auth/token/refresh` when under 3 days remain; if that fails and it has expired, calls return "Reconnect AliExpress".
- Product: `aliexpress.ds.product.get` with ship_to_country (AU default; US when the import row ships from US), store currency, English. Responses decoded with big IDs kept as strings.
- Import auto-fill never blocks an import. It overwrites the row's title/image/option text/ships-from/price with AliExpress's values; picks the only option of single-option products (unless that option is already in the list).
- Access token renews when less than a quarter of its life (max 3 days) remains; cron `gsup_keep_alive` (twice daily) renews if under 18 hours remain.

## Add to store
Screen: Import list → "Add to store as new product" (or Settings → Test → same button) → `tab=create&ae=<id>&ship=<code|none>`.
- Options grouped by ships-from; one warehouse per product. Ships-from never becomes a customer-facing attribute.
- Several options → variable product with local attributes in AliExpress order; one option (or no attributes) → simple product. Duplicate option combinations are skipped.
- Categories: ticked from the import row (`row=` in the URL) when it has some, else the user's last-used (`gsup_last_categories` user meta, saved on each create). None ticked → WooCommerce default category.
- Created as draft. Title editable; description = AliExpress `detail` with script/style/iframe/noscript blocks removed, then `wp_kses_post`. Up to 6 photos (thumbnail suffixes removed), option photos (up to 12 unique) on variations.
- Price = cost × multiplier + extra, optionally rounded up to .95 (never below cost). Options: `gsup_price_multiplier` (2), `gsup_price_add` (0), `gsup_price_round` (yes). Stock from AliExpress; "in stock, amount unknown" → stock not managed.
- Import rows for the same AliExpress product: rows whose option was added → Linked to that variation/product; rows without an option → Linked to the product; other rows untouched.

## Daily sync
Action Scheduler (group `givsen-supplier`): recurring `gsup_sync_start` daily at 03:00 store time while `gsup_sync_enabled` = yes; batches of 15 via `gsup_sync_batch`. Run state in `gsup_sync_run`, last report in `gsup_sync_last`. One AliExpress call per (product ID, delivery country) per batch; delivery country AU or US from the product's ships-from, else the store's base country.
- Found and on sale: stock (number → managed quantity; "in stock, amount unknown" → unmanaged, in stock), `_gsup_cost`; regular price = pricing rule when `gsup_sync_prices` = yes. Clears missing/removed/gone flags. Options not linked to a SKU are only updated when the listing has a single option.
- Option not on the listing: out of stock (quantity 0 if managed), `_gsup_option_gone`; reported once.
- Not for sale (status other than onSelling) or error message saying not exist/offline/not found/removed → removed now. Empty result or unclear product error → missing; removed on the second consecutive miss. Removed = draft if published, reported once.
- Network, sign-in, signature, permission, or rate/system-busy errors → run stops, nothing drafted, reported.
- Back on sale after the sync drafted it: reported; stays draft.
- Email (`gsup_sync_email`, default admin email) only when removed / options gone / back / errors.
- Never writes titles, descriptions, images, categories or sale prices.

## Order panel rules
- `givsen-pending` (Awaiting Givsen Address): red "Don't order yet", address hidden.
- `processing`: green "Ready to order". Other statuses: red "Not ready".
- Address: shipping address if present, else billing (labelled). Phone: shipping phone, else billing phone (labelled as the buyer's). State and country written out in full, country without the "(US)" suffix.
- Quantity shown is net of refunds.

## Uninstall
Drops the import list table and the plugin's options. Keeps product links and order numbers/tracking, so reinstalling picks up where you left off.

## Not in this version (next stages)
CBR country from ships-from, automatic ordering, tracking sync.

## Regression checklist
Run after any change, on a staging copy with MySQL.
1. Activate → WooCommerce → Givsen Supplier opens; Settings shows a 40-character key.
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
