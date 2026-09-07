<?php
/**
 * Reseller Selling Price Input & Live Profit Calculator.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Reseller_Fields {

	/**
	 * Main instance init.
	 */
	public static function init() {
		$instance = new self();
		$instance->register_hooks();
		return $instance;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Single product page selling price input & live calculator.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_reseller_input_field' ), 15 );

		// Product quick tools (download image, copy description).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_tools' ), 35 );

		// Validate custom selling price on add to cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_reseller_price_on_add' ), 10, 3 );
	}

	/**
	 * Render the reseller selling price input and live profit calculator.
	 */
	public function render_reseller_input_field() {
		global $product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$wholesale_price = (float) $product->get_price();
		$packaging_fee   = WRU_Product_Fields::get_packaging_fee( $product );
		$custom_shipping = WRU_Product_Fields::get_shipping_charge( $product );
		$currency_symbol = get_woocommerce_currency_symbol();
		$min_price       = WRU_Settings::is_min_price_enforced() ? $wholesale_price : 0;
		?>
		<div class="wru-reseller-box" 
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-base-price="<?php echo esc_attr( $wholesale_price ); ?>"
			data-packaging-fee="<?php echo esc_attr( $packaging_fee ); ?>"
			data-custom-shipping="<?php echo esc_attr( null !== $custom_shipping ? $custom_shipping : '' ); ?>"
			data-currency-symbol="<?php echo esc_attr( $currency_symbol ); ?>">
			
			<div class="wru-box-header">
				<span class="wru-resell-badge">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
					<?php esc_html_e( 'রিসেলার কালেকশন প্রাইস', 'woocommerce-resell-utility' ); ?>
				</span>
				<label for="wru_reseller_price" class="wru-input-label">
					<?php esc_html_e( 'আপনার বিক্রয়মূল্য লিখুন (কাস্টমার থেকে যা নিবেন):', 'woocommerce-resell-utility' ); ?>
					<span class="wru-required">*</span>
				</label>
			</div>

			<div class="wru-input-group">
				<span class="wru-input-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
				<input type="number"
					id="wru_reseller_price"
					name="wru_reseller_price"
					class="wru-reseller-input"
					placeholder="<?php esc_attr_e( 'যেমন: ১২০০', 'woocommerce-resell-utility' ); ?>"
					min="<?php echo esc_attr( $min_price ); ?>"
					step="any"
					required
					autocomplete="off" />
			</div>

			<!-- Dynamic Live Profit Breakdown -->
			<div class="wru-profit-preview" id="wru-profit-preview">
				<div class="wru-calc-item">
					<span class="wru-calc-label"><?php esc_html_e( 'পাইকারি মূল্য:', 'woocommerce-resell-utility' ); ?></span>
					<span class="wru-calc-val" id="wru-wholesale-display"><?php echo wc_price( $wholesale_price ); ?></span>
				</div>
				<div class="wru-calc-item">
					<span class="wru-calc-label"><?php esc_html_e( 'প্যাকেজিং খরচ:', 'woocommerce-resell-utility' ); ?></span>
					<span class="wru-calc-val" id="wru-packaging-display"><?php echo wc_price( $packaging_fee ); ?></span>
				</div>
				<div class="wru-calc-item wru-profit-highlight">
					<span class="wru-calc-label"><?php esc_html_e( 'আপনার সম্ভাব্য লাভ:', 'woocommerce-resell-utility' ); ?></span>
					<span class="wru-calc-val wru-profit-amount" id="wru-profit-display"><?php echo esc_html( $currency_symbol ); ?>0.00</span>
				</div>
			</div>

			<div class="wru-price-warning" id="wru-price-warning" style="display: none;">
				<?php esc_html_e( 'সতর্কতা: বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।', 'woocommerce-resell-utility' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render reseller product tools: One-click Image Download & Copy Description.
	 */
	public function render_product_tools() {
		if ( ! WRU_Settings::are_product_tools_enabled() ) {
			return;
		}

		global $product;
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';
		$title     = wp_strip_all_tags( $product->get_name() );
		$short_desc = wp_strip_all_tags( $product->get_short_description() );
		$full_desc  = wp_strip_all_tags( $product->get_description() );
		$desc_to_copy = ! empty( $short_desc ) ? $short_desc : wp_trim_words( $full_desc, 60 );

		$copy_text = sprintf(
			"%s\n\n%s\n\nমার্কেট প্রাইস: %s\nরিসেলার প্রাইস: %s",
			$title,
			$desc_to_copy,
			$product->get_regular_price() ? $product->get_regular_price() . ' Tk' : '',
			$product->get_sale_price() ? $product->get_sale_price() . ' Tk' : $product->get_price() . ' Tk'
		);
		?>
		<div class="wru-quick-tools-wrapper">
			<button type="button" class="wru-btn wru-btn-copy" id="wru-copy-details-btn" data-copy="<?php echo esc_attr( $copy_text ); ?>">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
				<?php esc_html_e( 'ডেসক্রিপশন কপি করুন', 'woocommerce-resell-utility' ); ?>
			</button>

			<?php if ( ! empty( $image_url ) ) : ?>
				<a href="<?php echo esc_url( $image_url ); ?>" download="<?php echo esc_attr( sanitize_title( $title ) ); ?>.jpg" class="wru-btn wru-btn-download" target="_blank" rel="noopener">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					<?php esc_html_e( 'ছবি ডাউনলোড করুন', 'woocommerce-resell-utility' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<div id="wru-toast" class="wru-toast"></div>
		<?php
	}

	/**
	 * Validate custom selling price on add to cart.
	 *
	 * @param bool $passed     Validation status.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_reseller_price_on_add( $passed, $product_id, $quantity ) {
		if ( ! isset( $_POST['wru_reseller_price'] ) ) {
			return $passed;
		}

		$raw_price = trim( sanitize_text_field( wp_unslash( $_POST['wru_reseller_price'] ) ) );

		if ( '' === $raw_price ) {
			wc_add_notice( __( 'দয়া করে আপনার বিক্রয়মূল্য লিখুন।', 'woocommerce-resell-utility' ), 'error' );
			return false;
		}

		$reseller_price = (float) $raw_price;
		$product        = wc_get_product( $product_id );

		if ( ! $product ) {
			return $passed;
		}

		$wholesale_price = (float) $product->get_price();

		if ( WRU_Settings::is_min_price_enforced() && $reseller_price < $wholesale_price ) {
			/* translators: %s: minimum wholesale price */
			wc_add_notice( sprintf( __( 'আপনার বিক্রয়মূল্য অবশ্যই আমাদের পাইকারি মূল্যের (%s) চেয়ে বেশি হতে হবে।', 'woocommerce-resell-utility' ), wc_price( $wholesale_price ) ), 'error' );
			return false;
		}

		return $passed;
	}
}
