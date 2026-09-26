# Givsen Supplier — Chrome extension

Adds an **Add to Givsen** button to AliExpress product pages, and checkboxes for adding many products at once on search results and store pages. It reads the product ID and the option you've selected (colour, size, ships-from…), shows them so you can check, and sends them to your store's import list (WooCommerce → Givsen Supplier).

Needs the **Givsen Supplier** WordPress plugin on the store.

## Install (unpacked)
1. Unzip `givsen-supplier-extension-<version>.zip` somewhere you'll keep it (e.g. Documents/Givsen). Don't delete the folder — Chrome runs it from there.
2. In Chrome go to `chrome://extensions`.
3. Turn on **Developer mode** (top right).
4. Click **Load unpacked** and choose the unzipped folder (the one containing `manifest.json`).
5. Pin it: puzzle-piece icon → pin **Givsen Supplier**.

## Connect it to your store
1. In WordPress: **WooCommerce → Givsen Supplier → Settings**.
2. Click the black **G** icon in Chrome's toolbar. Paste the **Site address** and **Connection key**.
3. Click **Save & test** and choose **Allow** when Chrome asks. You should see "Connected".

## Use it
1. Open an AliExpress product page and pick the options you want to sell (including **Ships From**).
2. Click **Add to Givsen** (bottom right). Check the option, ships-from and SKU ID, and pick the store category (or "Choose later").
   The card also says how much product text it found on the page (AliExpress's overview, description and specifications) — that becomes the starting description in your store. Descriptions load as you scroll, so scroll down to it first if you want it included.
3. Click **Send to import list**. Repeat for each option you want (e.g. Red/Australia, Blue/Australia).
4. In WordPress, link each import-list row to the matching product or variation.

## Add many at once
On an AliExpress search (tip: filter **Ships from: Australia**), a store's page, or the related items under a product, each product card has a **Givsen** checkbox. Tick the ones you want (up to 30), pick the store category in the bar at the bottom left, and click **Add N to Givsen**. The bar then says how many were added, how many were already there, and any that failed.
Cards already in your store say **In store**; ones already in your import list say **In import list** — they start unticked so you don't add them twice.
Bulk-added products have no option chosen yet: in WordPress, click **Add to store** on each and choose the warehouse and options there. Your store checks each one with AliExpress in the background (usually within a minute), filling in the title, picture and — for single-option listings — the option and ships-from.

## Save a backup supplier
On a listing that sells the same thing as one of your products (e.g. a second seller), open the **Add to Givsen** card, pick the warehouse on the page, then under **Use as backup for a product in your store** search for your product and click **Save as backup supplier**. Its options are matched to yours automatically; nothing in your store changes except the saved backup. If the main listing disappears, runs out or gets much dearer, the daily sync switches over.

## Updating
Replace the folder's contents with the new version, then click the reload arrow on the extension in `chrome://extensions` and refresh any open AliExpress tabs.
