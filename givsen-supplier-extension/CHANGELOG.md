# Givsen Supplier (Chrome extension) — Changelog

## 0.4.2 — 2026-09-26
- Sends the product's words from the page — AliExpress's "AI overview of item", any written description, and the specifications — so image-only listings still get a starting description in your store (plugin 0.10.2+). The card shows how many words it found.

## 0.4.1 — 2026-09-26
- Review import removed (and with it the permission to read feedback.aliexpress.com).
- Stronger request signing: each request also signs its method and endpoint, and the store accepts it only once. Needs plugin 0.8.2 or later — update both together.

## 0.4.0 — 2026-09-26
- **Import reviews** on the Add to Givsen card: reads the listing's reviews (stars, text in English, photos, buyer country) with filters — minimum stars, with text, with photos only, how many — and adds them to the linked product(s) in your store (needs plugin 0.8.0). Duplicates are skipped.
- Needs permission to read feedback.aliexpress.com (AliExpress's review feed); Chrome asks when you update.

## 0.3.0 — 2026-09-26
- "Category in your store" dropdown on the Add to Givsen card, filled with your store's categories (needs plugin 0.4.0). Remembers the last one you picked. The choice is carried to the Add to store screen.

## 0.2.0 — 2026-09-26
- After sending, shows the option as AliExpress confirmed it (when the store is connected to the AliExpress API), or why it couldn't be checked.

## 0.1.0 — 2026-09-26
First release (stage 2).
- "Add to Givsen" button on AliExpress product pages (aliexpress.com and aliexpress.us), hidden elsewhere.
- Reads the product ID from the link and works out the selected option's SKU ID from the option data AliExpress embeds in the page, matched against the options you've picked.
- Check-before-send card: product ID, option text, ships-from and SKU ID (editable). Tells you which option still needs picking.
- Sends to the store's import list over a signed request (connection key never leaves your browser). Reports whether the item is already linked in the store.
- Settings page (toolbar icon): site address + connection key, Save & test.
