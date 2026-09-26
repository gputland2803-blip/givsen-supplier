# Tests

`tests/run.sh` runs everything: PHP syntax, the Chrome extension's syntax, the unit tests, and a render of every
settings screen. It runs on GitHub for every push and pull request (`.github/workflows/tests.yml`).

The tests use small stand-ins for WordPress and WooCommerce functions, so they need only PHP 7.4+ and Node —
no WordPress install. They cover the parts most likely to break quietly:

| File | Covers |
|---|---|
| `test-aliexpress.php` | Reading AliExpress replies: delivery quotes (and the older fallback), placing orders, order status and tracking, parcel events and "delivered" wording; shipping-method choice; pricing; phone numbers; the diagnostics log hiding secrets |
| `test-aliexpress-parallel.php` | Several AliExpress calls at once: batching, matching replies to requests |
| `test-ai.php` | Rewrite with AI: the request sent to Claude (model, structured output, prompt rules, product facts) and handling replies, refusals and errors |
| `test-backup.php` | Save as backup supplier: matching, same shape as Change supplier, replace/confirm, same-as-main refusal, and request signing (v2 only, wrong key, replay, endpoint-bound) |
| `test-eta.php` | Delivery estimate wording |
| `test-tidy.php` | Title, option and description tidy-up, seller-note removal, specifics, description photos |
| `test-remap.php` | Change supplier's automatic option matching |
| `test-rename.php` | Renamed options staying distinct |
| `test-givsen.php` | Working with the Givsen gift plugin: Business gifting shares, privacy, delivered emails |
| `render-settings.php` | Renders a settings screen (`php tests/render-settings.php overview`) |

They don't replace trying a change on a staging site — see the regression checklist in `givsen-supplier/SPEC.md`.
