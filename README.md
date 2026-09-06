# WooCommerce Resell Utility

**WooCommerce Resell Utility** is a complete, modern dropshipping and reseller engine for WordPress & WooCommerce, specifically built for platforms operating reseller networks (e.g. in Bangladesh with Steadfast, Pathao, RedX, eCourier, Paperfly, etc.).

---

## Key Features

### 1. Market & Reseller Price Display
- **Regular Price** = `Market Price` (displayed cleanly in a lighter font with no strikethrough).
- **Sale Price** = `Reseller Price` (displayed prominently in bold).
- 100% non-destructive and tax-aware (`wc_price()`).
- Suppresses confusing "Save" / discount badges on Cart and Checkout pages.

### 2. Reseller Custom Selling Price ("আপনার বিক্রয়মূল্য লিখুন")
- Added right inside the single product page above the action buttons.
- Features dynamic live calculation of the reseller's estimated net profit:
  $$\text{Reseller Profit} = (\text{Customer Selling Price} - \text{Wholesale Price}) \times \text{Qty} - \text{Packaging Fee}$$
- Minimum price enforcement: Resellers cannot sell below wholesale cost.
- Automatically handles simple and variable products (recalculates on variation selection).

### 3. "Buy Now" (এখনই অর্ডার করুন) Buttons
- **Single Product Page:** Next to standard "Add to Cart", a high-contrast Buy Now button routes directly to checkout with the custom reseller price.
- **Shop & Archive Loops:** Buy Now button alongside Add to Cart with a sleek Quick Resell Modal popup for instant ordering without leaving the catalog.

### 4. Product Grid Card Alignment Fix
- Fixes the classic WooCommerce catalog issue where cards with varying title lengths cause misaligned prices and buttons.
- Normalized 2-line title clamp, `margin-top: auto` on price elements, and flexbox button rows ensure **every Add to Cart and Buy Now button is 100% horizontally aligned across all cards** in a clean 4-column desktop grid.

### 5. Packaging Cost (প্যাকেজিং খরচ) & Payout Accounting
- Store owners can set packaging fee (e.g. ৳20) in **WooCommerce -> Resell Utility**.
- Choice of **Per Order** or **Per Item** packaging fee deduction.
- Net reseller profit is automatically calculated and recorded in order metadata.

### 6. Dropshipping & Courier COD Order Meta (Steadfast / Pathao / RedX)
- Stores end-customer collection amount, wholesale subtotal, packaging cost, and reseller net payout in order metadata (HPOS and classic compatible).
- **Admin Order Screen Meta Box:**
  - কুরিয়ার কালেকশন (COD Amount)
  - পণ্যের পাইকারি দাম (Wholesale Total)
  - প্যাকেজিং খরচ (Packaging Cost)
  - রিসেলারের পাওনা (Net Reseller Payout)
  - **এক ক্লিকে কুরিয়ার নোট কপি:** Formatted for instant paste into Steadfast / Pathao / RedX booking forms!
- Resellers can view their profit breakdown on the Order Received (Thank You) and View Order pages.

### 7. Upgraded My Account Reseller Hub
- Dedicated tab in WooCommerce My Account: `/my-account/reseller-dashboard/`.
- 4 High-Impact KPI Stat Cards:
  1. **সর্বমোট প্রফিট (Total Profit)**
  2. **পেন্ডিং প্রফিট (Pending Profit - In transit)**
  3. **পরিশোধিত / অর্জিত প্রফিট (Completed Profit)**
  4. **মোট রিসেল অর্ডার (Total Orders count)**
- Reseller Payout details form: Resellers save their bKash (Personal/Agent), Nagad, Rocket, or Bank Account number.
- Reseller order history table with profit and status breakdown.

### 8. Printable Packing Slip / Invoice Generator & Email Integration
- Automatic printable invoice / packing slip generator with COD collection amount and courier notes (`?wru_action=print_invoice`).
- Dropshipping & courier collection summary automatically included in WooCommerce customer and admin order emails.

### 9. Bonus Reseller Tools
- **পণ্যর ছবি ডাউনলোড:** One-click download of high-resolution product images for posting on Facebook Marketplace, Facebook Pages, or TikTok.
- **ডেসক্রিপশন কপি:** One-click copy of title, specs, and prices to clipboard with toast notification ("ডেসক্রিপশন কপি হয়েছে!").

---

## File Structure

```
woocommerce-resell-utility/
├── woocommerce-resell-utility.php         # Main bootstrap & hooks
├── includes/
│   ├── class-wru-settings.php             # Centralized settings & options
│   ├── class-wru-price-display.php        # Market & Reseller price filters & Store API cleaner
│   ├── class-wru-reseller-fields.php      # Selling price input & live profit calculator
│   ├── class-wru-buy-now.php              # Buy Now handlers & quick modal
│   ├── class-wru-order-manager.php        # Cart, Order meta, HPOS, Admin courier box
│   ├── class-wru-reseller-dashboard.php   # My Account Reseller Hub & payout settings
│   ├── class-wru-invoice-email.php        # Printable packing slip invoice & email summary
│   └── class-wru-admin-settings.php       # WooCommerce -> Resell Utility settings page
├── assets/
│   ├── css/
│   │   ├── frontend.css                   # Grid alignment fix, badges, modals, dashboard
│   │   └── admin.css                      # Admin order meta box & settings styling
│   └── js/
│       ├── frontend.js                    # Live profit math, variation sync, copy tools, Buy Now fix
│       └── admin.js                       # One-click courier note copy
├── languages/                             # Translation directory
├── readme.txt                             # WordPress standard repository readme
└── README.md                              # Complete documentation
```

---

## Configuration

Navigate to **WordPress Admin -> WooCommerce -> Resell Utility**:
1. Set **Default Packaging Fee** (e.g. ৳20).
2. Choose **Packaging Fee Rule** (Per Order vs Per Item).
3. Toggle **Minimum Selling Price Enforcement** (disallow selling below wholesale).
4. Configure **Invoice & Packing Slip** details (Store Name, Hotline/Phone, Courier Note).
5. Toggle **Buy Now** buttons for single product and shop loop pages.
6. Toggle **Product Tools** (Image download & copy description).
7. Toggle **Reseller Dashboard** in My Account.

---

## Compatibility

- **WordPress:** 5.8+ (Tested up to 6.7)
- **WooCommerce:** 5.0+ (Tested up to 9.5)
- **HPOS:** 100% compatible with High-Performance Order Storage (`custom_order_tables`)
- **Cart & Checkout Blocks:** Fully compatible
- **PHP:** 7.4, 8.0, 8.1, 8.2, 8.3+
