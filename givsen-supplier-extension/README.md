# Givsen Supplier — Chrome extension

Adds an **Add to Givsen** button to AliExpress product pages. It reads the product ID and the option you've selected (colour, size, ships-from…), shows them so you can check, and sends them to your store's import list (WooCommerce → Givsen Supplier).

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

## Updating
Replace the folder's contents with the new version, then click the reload arrow on the extension in `chrome://extensions` and refresh any open AliExpress tabs.
