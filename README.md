# Market & Reseller Price Display (WooCommerce Plugin)

A clean, lightweight, and modern WordPress/WooCommerce plugin that presents WooCommerce's standard **Regular Price** as **Market Price** and **Sale Price** as **Reseller Price** across all frontend views.

---

## 🌟 Overview & Behavior

In WooCommerce:
- **Regular Price** = Market Price
- **Sale Price** = Reseller Price

### Frontend Display:
```
Market Price:   ৳1,500
Reseller Price: ৳1,200
```

- **Market Price:** Rendered cleanly in a lighter, standard font with **no strikethrough**.
- **Reseller Price:** Rendered in bold, prominent styling with strong visual contrast.
- **Single Regular Price (No Sale Price):** Only `Market Price: [price]` is shown.
- **Empty Price:** Respects WooCommerce's native empty / contact pricing behavior.
- **Variable Products:** Accurately formats price ranges (e.g. `Market Price: ৳1,200 – ৳1,800` & `Reseller Price: ৳900 – ৳1,500`) and dynamically updates when variations are selected on single product pages.

---

## 🔒 Safety & Non-Destructive Design

- ❌ **No database alterations:** Activating or deactivating the plugin leaves all product prices and database tables completely untouched.
- ❌ **No admin changes:** Backend product editing screens (`wp-admin/post.php`) remain completely standard.
- ❌ **No checkout / cart calculation alterations:** WooCommerce continues to handle order calculations, coupon logic, taxes, and cart prices natively.
- ❌ **No external requests or tracking:** 100% self-contained with zero external dependencies.

---

## 📁 File Structure

```
market-reseller-price-display/
├── market-reseller-price-display.php  # Main plugin code and hooks
├── assets/
│   └── css/
│       └── frontend.css               # Clean, conflict-free presentation styling
├── readme.txt                         # WordPress standard repository readme
└── README.md                          # Documentation
```

---

## 🚀 Installation

### Option 1: Upload via WordPress Admin
1. Download `market-reseller-price-display.zip`.
2. In your WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose `market-reseller-price-display.zip` and click **Install Now**.
4. Click **Activate Plugin**.

### Option 2: Manual FTP / SFTP
1. Upload the `market-reseller-price-display` directory to your server at `/wp-content/plugins/`.
2. Go to **WordPress Admin → Plugins** and click **Activate**.

---

## 🎨 HTML Markup & CSS Scaffolding

```html
<div class="rpd-product-prices">
    <div class="rpd-market-price">
        <span class="rpd-price-label">Market Price:</span>
        <span class="rpd-price-value"><!-- wc_price() output --></span>
    </div>
    <div class="rpd-reseller-price">
        <span class="rpd-price-label">Reseller Price:</span>
        <span class="rpd-price-value"><!-- wc_price() output --></span>
    </div>
</div>
```

---

## 🛠️ Developer Filters & Customization

You can customize the labels or output from your child theme's `functions.php`:

```php
// Change Market Price label
add_filter( 'rpd_market_price_label', function() {
    return 'Retail Price:';
} );

// Change Reseller Price label
add_filter( 'rpd_reseller_price_label', function() {
    return 'Wholesale Price:';
} );
```

---

## 📋 Compatibility

- **WordPress:** 5.8+ (Tested up to 6.7)
- **WooCommerce:** 5.0+ (Tested up to 9.5)
- **HPOS:** Fully compatible with High-Performance Order Storage (Custom Order Tables)
- **Gutenberg / Blocks:** Fully compatible with Cart & Checkout Blocks
- **PHP:** 7.4, 8.0, 8.1, 8.2, 8.3+
