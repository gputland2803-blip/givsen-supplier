# Givsen Supplier — Changelog

## 0.16.1 — 2026-09-26
- **Gift addresses checked before the claim completes** (needs Givsen 1.4.3, which adds the two filters). The recipient's claim page and the sender's address form leave out your selling countries that none of the gift's items can reach, and an address in a country no warehouse can deliver to is refused on the form with a clear message ("Sorry — Pearl Necklace can't be delivered to France. If there's an address in another country you can use, enter it instead…") — nothing is saved, and Givsen notes the attempt on the order. Business gifting claims (their address comes from Givsen's own service) are still caught at ordering.

## 0.16.0 — 2026-09-26
**Selling worldwide, phase 3: ordering, gifts and clean-up.**
- **Automatic ordering places each line from the right warehouse.** The customer's choice (from the product page / checkout re-check) when it still delivers to the order's shipping country with enough stock; otherwise, in order: a warehouse in the destination country → the fastest other warehouse that reaches it (days from the reach table) → China. If a warehouse has no delivery method that day, the next one is tried. The warehouse used is saved on the line (`_gsup_wh_used`) and an order note explains when it differs from the customer's choice. The loss guard is unchanged, using that warehouse's real cost and delivery fee. Products with only their main warehouse order exactly as before.
- **Givsen gifts**: when a gift is claimed (order → Processing), the warehouse is chosen for the recipient's country by the same rules, ignoring the sender's choice. If nothing reaches the recipient's country it isn't placed: the reason goes on the order, as an order note, and by email.
- **Claim-address check — needed a small addition to Givsen.** Givsen 1.4.2 checks a claim address only against your shipping zones, with no hook for other plugins. Givsen Supplier answers the filter proposed for it (`GSUP_Givsen::claim_deliverable()`); added in Givsen 1.4.3 (see 0.16.1).
- **Country restrictions**: the warehouse → countries mapping is off while selling worldwide (products are shown wherever a warehouse can deliver). Restrictions this plugin sets are now recorded (`_gsup_cbr_set`). **Givsen Supplier → Clean-up → "Remove Givsen Supplier's country restrictions"** lists only the ones it set — recorded, or (from before recording) exactly what it sets for the product's warehouse — with a preview and confirmation; restrictions you set by hand are never touched, even if ticked.
- **Merge warehouse versions** (same Clean-up screen): products sharing an AliExpress listing (e.g. separate AU and US versions), previewed with sales; merging keeps the one with more sales, moves the other's warehouses across as sources (matched by option values without Ships From; options with no match are reported), switches the other to draft, unlinks it (no more syncing or ordering from it; old link kept in `_gsup_merged_ae`), and redirects its address with a 301 to the kept product. Nothing merges without confirming.
- Tests: routing rules (choice, destination warehouse, fastest, China last, stock, no route, delivery-method fallback, loss guard, notes), gift routing and unreachable countries, the claim check, restriction removal scope, merge and redirects.

## 0.15.0 — 2026-09-26
**Selling worldwide, phase 2: what shoppers see.** Off until you tick Settings → Selling worldwide → "Show delivery by country in the shop" (run "Check all products now" first).
- **Visitor country**: from Cloudflare's CF-IPCountry header, else WooCommerce geolocation, else your store's country; remembered in a cookie. **"Deliver to: 🇬🇧 United Kingdom — change"** switcher — shortcode `[gsup_deliver_to]`, block "Deliver to (country)", or added to a menu automatically (setting). Your selling countries are listed first, then every other country if you sell there too. Changing it also sets the WooCommerce customer's shipping country — so someone in Australia can shop for a friend in the UK. Works without JavaScript.
- **Nobody lands on something they can't buy**: shop, categories, tags, search, related products, up-sells, cross-sells, product blocks/shortcodes and the Store API leave out products no warehouse can deliver in stock to the visitor's country. Products never checked for that country (or not from AliExpress) are never hidden, so a shop is never emptied by missing data. A product opened directly (e.g. from a video) still shows, says **"Not available for delivery to {country}"**, can't be added to the cart, and shows **"Similar products we deliver to {country}"** (same categories first). Options that can't reach the visitor say so when picked.
- **Product page delivery choice** with the add-to-cart button: each warehouse that reaches the visitor with the chosen option in stock, e.g. "Australia — 3–6 days (Tue 1 Oct – Fri 4 Oct)", "China — 10–18 days (…)" — fastest first and chosen, your processing days added (Delivery estimate settings). One warehouse → a single "Delivery:" line. Variable products update it for the chosen option. Replaces the plain delivery estimate on those products.
- **Price by warehouse**: same price from every warehouse (default) or "local warehouses cost extra" (+x% + $y, shown next to the choice, added in the cart).
- The choice goes into the **cart item and order line**: customers see "Ships from: Australia · 3–6 days"; the order line keeps the warehouse for ordering (`_gsup_wh`).
- **"Shipping from" filter**: All + each warehouse reaching the visitor's country with product counts (in the category being viewed), like AliExpress's filter — block "Shipping from (filter)", shortcode `[gsup_shipping_from]`, or automatically above the product grid (setting; only when there's more than one warehouse). Filters through the reach table, in stock only.
- **Checkout re-check**: when the delivery country entered at checkout differs, every line is checked again — kept, switched to the fastest warehouse that can deliver with a notice ("… will ship from China instead (10–18 days)"), or an error for that item if nothing can. Runs for the classic and block checkout; the shop's "Deliver to" follows the country typed.
- **Countries you sell to**: lists are filtered from the weekly reach data. Other countries (when "Also sell to other countries" is on) are checked live per product page, cached 6 hours. Countries you don't sell to: nothing is hidden (the shop isn't emptied), products say they're not available, and a bar says "We don't deliver to {country} yet" with the switcher.
- **Page caching — why pages vary by country rather than loading parts over REST**: hiding products has to happen in the product queries themselves (search, categories, related, blocks), so whole pages depend on the country; fetching every product list over REST would mean rebuilding the theme's grids in JavaScript and hurt SEO. So each country gets its own cached copy: LiteSpeed Cache varies by the `gsup_country` cookie (registered with the plugin, plus the X-LiteSpeed-Vary header for server-level caching); for Cloudflare (which can't vary HTML by cookie below Enterprise) and any other cache keyed on the address, every page carries its country in a meta tag and a few lines of script reload it as `?gsup_c=GB` — its own cache entry, noindex — when the cached copy is for a different country (asking `/givsen-supplier/v1/country`, uncached, on a first visit). Cart, checkout and My Account are never cached. Same approach as WooCommerce's "Geolocate (with page caching support)".
- Tests: country detection and switcher, delivery choice per country (stored and live), surcharge, cart and order line, hiding in every kind of list, filter counts, checkout re-check, and a browser test of the page-cache reload (right copy, first visit, no loops, store unreachable).

## 0.14.0 — 2026-09-26
**Selling worldwide, phase 1: sources and reach.** Data and sync only — the shop looks and works the same afterwards.
- **Sources per option.** Every simple product and variation can keep all its AliExpress warehouses, e.g. Australia, China and Turkey, each with its SKU, cost and stock (`_gsup_sources`). The existing SKU / ships-from stay the **main warehouse**, so ordering, profit, the delivery estimate and everything else work as before. Options are matched across warehouses by their values without Ships From (exactly first, then Change supplier's word matching).
- **Refresh sources**: button on the Supplier tab (straight away) and bulk action "Refresh sources (Givsen Supplier)" (in the background). The Supplier tab lists each option's warehouses with cost, stock and how many selling countries each reaches (hover for delivery times).
- **Settings → Selling worldwide** (new): selling countries (default Australia, New Zealand, United States, Canada, United Kingdom, Ireland, Germany, France, Netherlands, Sweden), "Also sell to other countries" (checked live when needed, not stored), which stock the shop shows, the estimated AliExpress calls a week, and "Check all products now".
- **Reach table** (`{prefix}gsup_reach`, indexed for shop-page queries): for every option × selling country × warehouse — deliverable, in stock, stock, item cost, delivery fee, method and days, when checked. Refreshed **weekly** (Mondays ~4am, while the daily sync is on) in small background batches: the listing is fetched once per selling country (AliExpress only returns the options it can deliver there — that's also how warehouses that only ship locally, like a US warehouse, are found) plus one delivery quote per warehouse and country, all with the existing parallel calls. A country AliExpress didn't answer for keeps its previous results.
- **Daily sync**: updates cost and stock for every source (one listing call per warehouse home country — usually one or two per product). Which stock the shop shows is a setting: **main warehouse** (default — unchanged behaviour, recommended until orders can be placed from any warehouse) or **any warehouse that reaches a selling country** (in stock if any has it, never adding amounts up; when the main warehouse drops an option, the best other one takes over and you're emailed; "option gone" only when no warehouse has it).
- **Add to store**: new default **All warehouses in one product** — one row per option with a chip per warehouse (cost · stock · delivery and days); the main warehouse is the preferred one with stock (Australia, your store's country, United States, the rest, China last) and sets the price. No country restriction is applied to these products; reach is checked in the background after creating. **One warehouse only** is still there (the previous screen).
- Change supplier resets the product's sources and queues a refresh (they belonged to the old listing).
- Uninstall also drops the reach table and clears the new jobs and settings.

## 0.13.0 — 2026-09-26
- **Trial: combine items from the same seller** (Settings → Ordering & tracking → Same seller; off by default). AliExpress's order API takes a list of items and returns a list of order numbers, but its documentation doesn't say whether same-seller items become one order with one delivery fee — this finds out on real orders.
  - When on, an order's items are all checked first (option, stock, delivery, loss guard — unchanged, each quoted on its own), then items from the same seller and warehouse go to AliExpress in one request. Items whose seller AliExpress didn't name, or from different warehouses, still go on their own.
  - The order number(s) AliExpress returns are matched back to each item by looking the orders up (which products each contains). An item that can't be pinned to one order keeps all the numbers, so tracking still finds it.
  - If AliExpress refuses the combined request, nothing was ordered, so each item is placed on its own as usual. If it gives no clear answer, nothing is retried and every item is flagged to check (as before).
  - Each try is recorded under **Combined orders so far**: items, one order or several, what the items cost quoted on their own vs. what AliExpress charged — plus an order note.
  - Profit: when several items share one AliExpress order, the amount AliExpress charged is split between them by their quoted costs instead of being counted for each.
- Listings now remember their seller (store ID); order look-ups read the products in the order and its seller.

## 0.12.0 — 2026-09-26
- **Bulk import from search results and store pages** (with Chrome extension 0.6.0). Tick products on AliExpress search results, store pages or a product page's related items, choose a category, and "Add N to Givsen" — up to 30 at a time. Each becomes an import-list row with no option chosen yet ("Choose warehouse and options on Add to store"), then Add to store works as usual.
- Products already in your store or already waiting in the import list are skipped (dismissed rows are brought back); the extension labels those cards "In store" / "In import list" before you tick anything. The reply says how many were added, already there, and failed (with the reason).
- Added rows are checked with AliExpress in the background (Action Scheduler job `gsup_enrich_rows`, the same parallel calls as the daily sync), so adding 30 is instant: title and picture updated, a single-option listing gets its option, warehouse and price; otherwise the note says how many options there are. A failed check is noted on the row and never blocks anything.
- New signed endpoints `POST /import-batch` and `POST /status` (signature version 2 only; status answers a whole page in two queries). Uninstall also clears pending background checks.
- Tests: saved sample search, store and product pages with a browser test of the extension, and batch dedupe / background check tests. Browser tests run on GitHub too.

## 0.11.0 — 2026-09-26
- **Save as backup supplier from AliExpress** (with Chrome extension 0.5.0). On the Add to Givsen card, pick one of your linked store products and click "Save as backup supplier": the listing's options (for the warehouse chosen on the page) are matched to the product's options automatically — the same matching as Change supplier — and saved as its backup in exactly the same form. It never switches the supplier and never changes prices; automatic switch-over keeps its rule (only when every mapped option exists and is in stock).
- The reply shows options matched (e.g. 2 of 3), the ones that weren't by name, cost with delivery now vs. the backup, and a link to the Supplier tab. Refused when the listing is already the product's main supplier, when it has no options from that warehouse, or when nothing matches. Replacing an existing backup needs confirming.
- Supplier tab: "N option(s) not matched on the backup — review" with the names and a link that opens Change supplier with the backup listing ready to match by hand (works for backups saved either way).
- New signed endpoints `GET /linked-products?search=` and `POST /backup` — signature version 2 only (method and endpoint signed, each request accepted once). Settings text on what the connection key allows updated.

## 0.10.2 — 2026-09-26
- **Descriptions from the product page.** AliExpress's "AI overview of item" and the specifications table are only on the web page — its API doesn't include them. With Chrome extension 0.4.2, they're captured (with any written description) when you click Add to Givsen, kept with the import-list item, and used by Add to store when the listing's own description is only images: overview and specifications as bullet lists with bold headings, seller notes removed. Order: the listing's words → the page's words → AliExpress's mobile description → a starter from the facts.
- Import list table gains `page_text` (upgrades itself).

## 0.10.1 — 2026-09-26
- **Fix: empty descriptions on image-only listings.** Many AliExpress descriptions are just a stack of images, so "wording only" left nothing. Now, when the description has no words, Add to store uses AliExpress's mobile description (which often has the text), and failing that writes a plain starter description from the title, your chosen options and the item specifics — ready to rewrite or run through Rewrite with AI. A message after creating says which was used. The images still go into the gallery.

## 0.10.0 — 2026-09-26
- **Rewrite with AI**: select products → Bulk actions → "Rewrite with AI" (or the link under a product's name). Claude writes a new title, description and short description for each, two at a time; you see old and new side by side, edit anything, and apply only the ones you tick. The old text is saved — "Undo text changes" on the Supplier tab (also covers bulk tidy-up, now including short descriptions).
  - Settings → AI writing: your Claude API key (administrators only, stored on your site), model — Claude Haiku 4.5 (cheapest, about 0.2¢ a product, default) or Claude Sonnet 5 (better writing, about 0.4¢) — your store's voice, extra instructions, title length, Australian/British/American spelling.
  - Claude is told to use only facts already on the product (no invented materials, sizes or claims), keep every size/material detail, and never mention AliExpress, suppliers, shipping times or reviews. Descriptions come back as simple HTML (paragraphs, a bullet list, bold) and anything else is stripped.
  - Running cost shown as it goes. Clear messages for a wrong key, rate limits, no credit, or a declined rewrite, with Retry.

## 0.9.0 — 2026-09-26
- **Delivery estimate on product pages** (Settings → Pricing, profit & delivery estimate; off until you turn it on): under the price, as dates ("Estimated delivery: Tue 1 Oct – Fri 4 Oct") or days ("Delivered in 3–6 business days"), from AliExpress's estimate for your shipping method plus your processing days, business days optional. Variable products show the range, then the chosen option's own estimate. The estimate is saved on Add to store, Change supplier and the weekly delivery quote — existing products get it at their next weekly quote.
- **Diagnostics log** (Settings → AliExpress connection → Diagnostics): the last 30 AliExpress calls with their replies, "Copy all" to paste to whoever's helping. Access token, app key, signatures and customers' addresses are never kept. Saved once per page load; can be switched off.
- **Photos**: product photos default to all (up to 10); the description's own photos (detail shots, size charts) can go into the gallery (on by default) — so choosing "wording only" loses no pictures.
- **Safer description clean-up**: only short, self-contained lines are ever removed as seller notes, so one "visit our store" line can no longer take real product wording (or a whole description) with it. Links to AliExpress are removed along with their text.
- **Automated tests** (`tests/run.sh`, also run on GitHub for every push and pull request).

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
