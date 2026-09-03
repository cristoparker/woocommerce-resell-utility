=== Market & Reseller Price Display ===
Contributors: sabit
Tags: woocommerce, price display, market price, reseller price, pricing
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Displays WooCommerce Regular Price as "Market Price" and Sale Price as "Reseller Price" across all frontend product views without modifying backend values or checkout logic.

== Description ==

**Market & Reseller Price Display** is a lightweight, zero-configuration WordPress/WooCommerce plugin tailored for B2B stores, reseller networks, wholesale catalogs, and discount storefronts.

By default, WooCommerce formats products with regular and sale prices using a strikethrough sale badge. This plugin cleanly transforms frontend price presentation into explicit, professional labels:

* **Market Price:** Displays the standard Regular Price without any strikethrough.
* **Reseller Price:** Displays the active Sale/Reseller Price in bold, prominent styling.

### Key Features

* **100% Non-Destructive:** Does NOT alter product data, regular prices, sale prices, cart items, checkout calculations, orders, taxes, or database records.
* **Universal Frontend Coverage:** Automatically hooks into shop loops, category archives, tag archives, single product pages, related products, upsells, cross-sells, product grids, and variation dropdown selectors.
* **Variable & Grouped Product Ready:** Safely handles variable product price ranges and dynamic variation selection without breaking dropdowns or cart interactions.
* **Native WooCommerce Formatting:** Utilizes `wc_price()` to automatically respect your configured currency symbol, decimal separators, thousand separators, and tax display rules.
* **Ultra-Lightweight & Clean:** No admin settings bloat, no database queries, no external scripts or trackers, and zero jQuery dependencies.
* **HPOS & Blocks Compatible:** Fully compatible with High-Performance Order Storage (HPOS) and modern Cart/Checkout blocks.

== Installation ==

1. Download `market-reseller-price-display.zip`.
2. Go to your WordPress Admin dashboard: **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.
5. Your store will immediately display "Market Price" and "Reseller Price" on all frontend views.

== Frequently Asked Questions ==

= Does this modify my database or existing product prices? =
No. The plugin operates strictly at the frontend display layer using WooCommerce's `woocommerce_get_price_html` filter. Your database values, WooCommerce admin edit screens, cart, and checkout calculations remain 100% untouched.

= What happens if a product only has a Regular Price? =
Only "Market Price: [price]" is displayed. The "Reseller Price" line is automatically omitted.

= Does this support Variable Products? =
Yes. It supports variable product price ranges (e.g., `Market Price: ৳1,200 – ৳1,800` and `Reseller Price: ৳900 – ৳1,500`) and dynamically updates to the specific variation price when a customer selects attributes on the single product page.

= Can I customize the labels? =
Yes! Developers can use the provided WordPress filters:
`add_filter( 'rpd_market_price_label', function() { return 'Retail Price:'; } );`
`add_filter( 'rpd_reseller_price_label', function() { return 'Wholesale Price:'; } );`

== Changelog ==

= 1.0.0 =
* Initial release.
