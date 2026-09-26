# Tests

`tests/run.sh` runs everything: PHP syntax, the Chrome extension's syntax, the unit tests, browser tests of the
extension on saved AliExpress pages, and a render of every settings screen. It runs on GitHub for every push and pull request (`.github/workflows/tests.yml`).

The tests use small stand-ins for WordPress and WooCommerce functions, so they need only PHP 7.4+ and Node —
no WordPress install. The browser tests also need Playwright with Chromium (`npm i -g playwright && npx playwright
install chromium`); without it they're skipped locally (never on GitHub). They cover the parts most likely to break quietly:

| File | Covers |
|---|---|
| `test-aliexpress.php` | Reading AliExpress replies: delivery quotes (and the older fallback), placing orders, order status and tracking, parcel events and "delivered" wording; shipping-method choice; pricing; phone numbers; the diagnostics log hiding secrets |
| `test-aliexpress-parallel.php` | Several AliExpress calls at once: batching, matching replies to requests |
| `test-ai.php` | Rewrite with AI: the request sent to Claude (model, structured output, prompt rules, product facts) and handling replies, refusals and errors |
| `test-backup.php` | Save as backup supplier: matching, same shape as Change supplier, replace/confirm, same-as-main refusal, and request signing (v2 only, wrong key, replay, endpoint-bound) |
| `test-bulk.php` | Bulk import: batch dedupe (in the batch, in the store, in the import list, dismissed rows brought back), invalid IDs, 30-per-batch limit, "In store"/"In import list" status in two queries, and the background AliExpress check |
| `test-combine.php` | Same-seller trial: grouping by seller and warehouse, one request per group, order numbers matched back to items, refused → one by one, no answer → never retried, the trial log, and splitting a shared order's cost |
| `test-eta.php` | Delivery estimate wording |
| `test-sources.php` | Selling worldwide: matching options across warehouses, union of per-country listings, reach refresh (deliverable / not offered / no quote / no answer / warehouse dropped), stock rules (main warehouse vs any; promotion; option gone), Add to store with all warehouses |
| `test-tidy.php` | Title, option and description tidy-up, seller-note removal, specifics, description photos |
| `test-remap.php` | Change supplier's automatic option matching |
| `test-rename.php` | Renamed options staying distinct |
| `test-givsen.php` | Working with the Givsen gift plugin: Business gifting shares, privacy, delivered emails |
| `browser/bulk.test.js` | The extension's bulk import on saved pages in `fixtures/` (search results, a store page, a product page's related items, a page with no products): checkboxes found from `/item/<id>.html` links, status labels, the 30 limit, what's sent, and new results loading as you scroll |
| `render-settings.php` | Renders a settings screen (`php tests/render-settings.php overview`) |

They don't replace trying a change on a staging site — see the regression checklist in `givsen-supplier/SPEC.md`.
