=== WooCommerce Resell Utility ===
Contributors: cristoparker
Tags: woocommerce, dropshipping, reseller, resell utility, wholesale, courier, Steadfast, Pathao, profit calculator
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete Dropshipping & Resell Utility for WooCommerce: Custom reseller selling price input, live profit calculator, packaging fee deduction, Buy Now buttons, courier collection data in orders, and upgraded Reseller Dashboard.

== Description ==

**WooCommerce Resell Utility** is an all-in-one dropshipping and reseller engine built specifically for WooCommerce stores that empower resellers to sell products to end-customers at custom prices.

In a modern dropshipping & reselling workflow (especially in Bangladesh and South Asia with Steadfast, Pathao, RedX, eCourier, Paperfly, etc.):
1. Resellers browse wholesale products with clear **Market Price** and **Reseller (Wholesale) Price**.
2. On the product page, resellers enter their customer selling price (**"আপনার বিক্রয়মূল্য লিখুন"**).
3. The plugin calculates their estimated profit in real-time: `(Selling Price - Wholesale Price) * Qty - Packaging Fee`.
4. Reseller clicks **"Buy Now" (এখনই অর্ডার করুন)** to instantly route to checkout.
5. In the WooCommerce order, the end-customer collection amount (COD) is clearly saved for printing shipping labels and dispatching couriers.
6. The store admin and reseller see the exact payout breakdown: Customer Collection, Wholesale Cost, Packaging Fee, and Reseller Profit.
7. Resellers track their earnings, pending profit, and save bKash/Nagad/Rocket/Bank payout details via their upgraded **Reseller Dashboard** in My Account.

### Key Features

* **Market & Reseller Price Presentation:** Automatically renders Regular Price as "Market Price" (no strikethrough) and Sale Price as "Reseller Price" across all shop and product views.
* **Reseller Selling Price Input ("আপনার বিক্রয়মূল্য লিখুন"):** Clean input with currency prefix and real-time live profit breakdown.
* **Packaging Fee (প্যাকেজিং খরচ) Deduction:** Configurable packaging fee (e.g. ৳20 per order or per item) automatically factored into reseller profit.
* **Buy Now (এখনই অর্ডার করুন) Buttons:** Added side-by-side with "Add to Cart" on single product pages and shop loops.
* **Shop Loop Grid Alignment Fix:** Fixes uneven product cards caused by varying title lengths so all buttons across every card align horizontally on the same line.
* **Dropshipping Courier Order Data:** Captures and stores customer collection amount, wholesale cost, packaging fee, and reseller profit in HPOS & classic order meta.
* **Admin Courier Meta Box & 1-Click Note Copy:** Admin order screen features high-impact summary cards and a one-click copy button formatted for instant booking on Steadfast, Pathao, RedX, Paperfly, etc.
* **Upgraded My Account Reseller Hub:** 4 KPI stat cards (Total Profit, Pending, Paid, Total Orders), order history table with profit breakdowns, and bKash/Nagad/Bank payout method settings.
* **Reseller Quick Tools:** One-click "ছবি ডাউনলোড" (Download Product Image) and "ডেসক্রিপশন কপি" (Copy Title & Description).
* **HPOS & Cart/Checkout Blocks Ready:** 100% compatible with High-Performance Order Storage and latest WooCommerce features.

== Installation ==

1. Upload the `woocommerce-resell-utility` folder to your `/wp-content/plugins/` directory, or upload `woocommerce-resell-utility.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure your packaging fee and options under **WooCommerce → Resell Utility**.

== Frequently Asked Questions ==

= Where does the reseller enter their selling price? =
On the single product page right above the Add to Cart button, in the box labeled "আপনার বিক্রয়মূল্য লিখুন". Resellers can also use the Quick Buy Now popup on shop pages.

= How is reseller profit calculated? =
Formula: `Reseller Net Profit = (Reseller Selling Price - Wholesale Price) * Quantity - Packaging Fee`.

= Where can the store manager see courier COD collection? =
Inside each WooCommerce Order edit screen, in the "ড্রপশিপিং ও কুরিয়ার কালেকশন তথ্য" meta box. It also includes a 1-click copy button for quick booking on Steadfast, Pathao, RedX, etc.

= Can resellers see their total profit and add bKash/Nagad info? =
Yes! Logged-in resellers can visit **My Account → রিসেলার ড্যাশবোর্ড** to see their earnings, order history, and save their payout method.

== Changelog ==

= 2.0.0 =
* Major upgrade and rebranding to WooCommerce Resell Utility.
* Added "আপনার বিক্রয়মূল্য লিখুন" custom reseller selling price input.
* Added real-time dynamic profit calculator.
* Added Buy Now buttons on single product pages and shop loop cards.
* Added product card grid alignment fix for uneven title heights.
* Added packaging fee configuration and net profit deduction.
* Added Dropshipping & Courier COD meta box in admin order screen with 1-click courier note copy.
* Added upgraded Reseller Dashboard tab in WooCommerce My Account.
* Added one-click product image download and description copy tools.
* Fully tested with WooCommerce 9.x and HPOS.

= 1.0.0 =
* Initial release of Market & Reseller Price Display.
