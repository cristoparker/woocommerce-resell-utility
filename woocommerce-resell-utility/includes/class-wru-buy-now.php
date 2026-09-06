<?php
/**
 * Buy Now Button Handler for Single Product and Shop Loops.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Buy_Now {

	/**
	 * Init class.
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
		if ( WRU_Settings::is_buy_now_enabled() ) {
			// Single product page: output Buy Now button inside add to cart form.
			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_single_buy_now_button' ), 5 );

			// Ensure add-to-cart parameter is populated in $_POST/$_REQUEST when wru_buy_now is submitted.
			add_action( 'wp_loaded', array( $this, 'maybe_populate_add_to_cart_post' ), 5 );
		}

		if ( WRU_Settings::is_shop_buy_now_enabled() ) {
			// Shop loop: wrap Add to Cart and Buy Now buttons together cleanly in the same action container.
			add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_add_to_cart_link' ), 20, 3 );

			// Quick Buy Now modal in shop footer.
			add_action( 'wp_footer', array( $this, 'render_quick_buy_modal' ) );
		}

		// Handle redirect to checkout on Buy Now.
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'handle_buy_now_redirect' ), 999, 2 );

		// AJAX handler for quick buy from shop page modal.
		add_action( 'wp_ajax_wru_quick_buy_now', array( $this, 'ajax_quick_buy_now' ) );
		add_action( 'wp_ajax_nopriv_wru_quick_buy_now', array( $this, 'ajax_quick_buy_now' ) );
	}

	/**
	 * Render Buy Now button on Single Product page.
	 */
	public function render_single_buy_now_button() {
		global $product;
		$product_id = is_a( $product, 'WC_Product' ) ? $product->get_id() : 0;
		?>
		<button type="submit" 
			name="wru_buy_now" 
			value="1" 
			class="button alt wru-buy-now-btn" 
			id="wru-single-buy-now"
			data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
			<span><?php esc_html_e( 'এখনই অর্ডার করুন (Buy Now)', 'woocommerce-resell-utility' ); ?></span>
		</button>
		<input type="hidden" name="wru_buy_now_product_id" value="<?php echo esc_attr( $product_id ); ?>" />
		<?php
	}

	/**
	 * Ensure add-to-cart parameter is populated when wru_buy_now is submitted.
	 */
	public function maybe_populate_add_to_cart_post() {
		if ( ! empty( $_POST['wru_buy_now'] ) || ! empty( $_REQUEST['wru_buy_now'] ) ) {
			if ( empty( $_REQUEST['add-to-cart'] ) ) {
				$prod_id = 0;
				if ( ! empty( $_POST['wru_buy_now_product_id'] ) ) {
					$prod_id = absint( $_POST['wru_buy_now_product_id'] );
				} elseif ( ! empty( $_POST['product_id'] ) ) {
					$prod_id = absint( $_POST['product_id'] );
				}

				if ( $prod_id ) {
					$_REQUEST['add-to-cart'] = $prod_id;
					$_POST['add-to-cart']    = $prod_id;
				}
			}
		}
	}

	/**
	 * Append Buy Now button inside the same action container as loop Add to Cart.
	 *
	 * @param string      $html    Existing add to cart button HTML.
	 * @param \WC_Product $product Product object.
	 * @param array       $args    Arguments.
	 * @return string
	 */
	public function filter_loop_add_to_cart_link( $html, $product, $args = array() ) {
		if ( ! WRU_Settings::is_shop_buy_now_enabled() || ! is_a( $product, 'WC_Product' ) ) {
			return $html;
		}

		$product_id      = $product->get_id();
		$wholesale_price = (float) $product->get_price();
		$packaging_fee   = WRU_Settings::get_packaging_fee();
		$product_url     = get_permalink( $product_id );
		$is_simple       = $product->is_type( 'simple' );

		if ( $is_simple ) {
			$buy_now_btn = sprintf(
				'<button type="button" class="button alt wru-loop-buy-now-btn wru-trigger-quick-buy" data-product-id="%1$d" data-product-title="%2$s" data-product-price="%3$s" data-packaging-fee="%4$s" data-product-url="%5$s" title="%6$s"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg><span>%7$s</span></button>',
				esc_attr( $product_id ),
				esc_attr( $product->get_name() ),
				esc_attr( $wholesale_price ),
				esc_attr( $packaging_fee ),
				esc_url( $product_url ),
				esc_attr__( 'এখনই কিনুন', 'woocommerce-resell-utility' ),
				esc_html__( 'Buy Now', 'woocommerce-resell-utility' )
			);
		} else {
			$buy_now_btn = sprintf(
				'<a href="%1$s" class="button alt wru-loop-buy-now-btn" title="%2$s"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg><span>%3$s</span></a>',
				esc_url( $product_url ),
				esc_attr__( 'পণ্যটি দেখুন', 'woocommerce-resell-utility' ),
				esc_html__( 'Buy Now', 'woocommerce-resell-utility' )
			);
		}

		return '<div class="wru-loop-actions">' . $html . $buy_now_btn . '</div>';
	}

	/**
	 * Render Quick Buy Modal in footer for shop page interactions.
	 */
	public function render_quick_buy_modal() {
		if ( ! is_shop() && ! is_product_taxonomy() && ! is_front_page() ) {
			return;
		}

		$currency_symbol = get_woocommerce_currency_symbol();
		?>
		<div id="wru-quick-buy-modal" class="wru-modal" style="display: none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-dialog">
				<button type="button" class="wru-modal-close" aria-label="<?php esc_attr_e( 'Close', 'woocommerce-resell-utility' ); ?>">&times;</button>
				
				<div class="wru-modal-header">
					<span class="wru-modal-badge"><?php esc_html_e( 'রিসেলার কুইক অর্ডার', 'woocommerce-resell-utility' ); ?></span>
					<h3 class="wru-modal-title" id="wru-modal-product-title"><?php esc_html_e( 'পণ্য অর্ডার করুন', 'woocommerce-resell-utility' ); ?></h3>
				</div>

				<div class="wru-modal-body">
					<div class="wru-modal-meta">
						<span class="wru-meta-item"><?php esc_html_e( 'পাইকারি মূল্য:', 'woocommerce-resell-utility' ); ?> <strong id="wru-modal-wholesale"><?php echo esc_html( $currency_symbol ); ?>0</strong></span>
						<span class="wru-meta-item"><?php esc_html_e( 'প্যাকেজিং খরচ:', 'woocommerce-resell-utility' ); ?> <strong id="wru-modal-packaging"><?php echo esc_html( $currency_symbol ); ?>0</strong></span>
					</div>

					<div class="wru-form-group">
						<label for="wru_modal_reseller_price" class="wru-label">
							<?php esc_html_e( 'আপনার বিক্রয়মূল্য লিখুন (কাস্টমার থেকে যা নিবেন):', 'woocommerce-resell-utility' ); ?>
							<span class="wru-required">*</span>
						</label>
						<div class="wru-input-group">
							<span class="wru-input-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" id="wru_modal_reseller_price" class="wru-reseller-input" placeholder="যেমন: ১২০০" step="any" min="0" required />
						</div>
					</div>

					<div class="wru-form-group wru-qty-group">
						<label for="wru_modal_qty" class="wru-label"><?php esc_html_e( 'পরিমাণ (Quantity):', 'woocommerce-resell-utility' ); ?></label>
						<input type="number" id="wru_modal_qty" class="wru-qty-input" value="1" min="1" step="1" />
					</div>

					<div class="wru-modal-profit-card">
						<span><?php esc_html_e( 'আপনার সম্ভাব্য মোট লাভ:', 'woocommerce-resell-utility' ); ?></span>
						<strong id="wru-modal-profit-display"><?php echo esc_html( $currency_symbol ); ?>0.00</strong>
					</div>

					<div class="wru-price-warning" id="wru-modal-warning" style="display: none;">
						<?php esc_html_e( 'সতর্কতা: বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।', 'woocommerce-resell-utility' ); ?>
					</div>
				</div>

				<div class="wru-modal-footer">
					<button type="button" class="button alt wru-modal-submit-btn" id="wru-modal-confirm-btn">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
						<?php esc_html_e( 'অর্ডার কনফার্ম করুন (চেকআউট)', 'woocommerce-resell-utility' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle redirect to checkout on Buy Now button submission.
	 *
	 * @param string $url Redirection URL.
	 * @return string
	 */
	public function handle_buy_now_redirect( $url, $adding_to_cart = null ) {
		if ( ! empty( $_REQUEST['wru_buy_now'] ) || ! empty( $_POST['wru_buy_now'] ) ) {
			return wc_get_checkout_url();
		}
		return $url;
	}

	/**
	 * AJAX endpoint for shop page quick buy now.
	 */
	public function ajax_quick_buy_now() {
		check_ajax_referer( 'wru_frontend_nonce', 'nonce' );

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$reseller_price = isset( $_POST['reseller_price'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['reseller_price'] ) ) : 0;
		$quantity       = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'পণ্য পাওয়া যায়নি।', 'woocommerce-resell-utility' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => __( 'পণ্যটি ক্রয়যোগ্য নয়।', 'woocommerce-resell-utility' ) ) );
		}

		$wholesale = (float) $product->get_price();
		if ( WRU_Settings::is_min_price_enforced() && $reseller_price < $wholesale ) {
			wp_send_json_error( array( 'message' => __( 'বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।', 'woocommerce-resell-utility' ) ) );
		}

		$cart_item_data = array(
			'wru_reseller_price' => $reseller_price,
			'unique_key'         => md5( microtime() . rand() ),
		);

		WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );

		wp_send_json_success( array(
			'redirect_url' => wc_get_checkout_url(),
		) );
	}
}
