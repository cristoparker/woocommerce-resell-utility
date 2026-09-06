<?php
/**
 * Order Manager: Cart items, Order Line Meta, Totals, Admin Courier Meta Box, and Frontend Summaries.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Order_Manager {

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
		// Cart item data handling.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// In checkout order review: display editable reseller selling price input.
		add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'render_checkout_reseller_price_editor' ), 20, 3 );

		// Suppress any theme "You Save: ..." discount badges on cart & checkout.
		add_filter( 'woocommerce_cart_item_price', array( $this, 'filter_clean_cart_price_html' ), 999, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'filter_clean_cart_price_html' ), 999, 3 );

		// AJAX handler for live editing reseller selling price on checkout.
		add_action( 'wp_ajax_wru_update_checkout_reseller_price', array( $this, 'ajax_update_checkout_reseller_price' ) );
		add_action( 'wp_ajax_nopriv_wru_update_checkout_reseller_price', array( $this, 'ajax_update_checkout_reseller_price' ) );

		// Line item meta creation during checkout (HPOS & classic).
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'create_order_line_item_meta' ), 10, 4 );

		// Calculate & store order-level reseller totals on order creation.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'calculate_order_reseller_totals' ), 10, 3 );

		// Admin Order details Meta Box (Supports both HPOS and traditional CPT).
		add_action( 'add_meta_boxes', array( $this, 'register_admin_order_meta_box' ) );

		// Frontend order received and view order profit summary.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_frontend_order_summary' ), 15 );
	}

	/**
	 * Add reseller customer selling price into cart item data.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $_POST['wru_reseller_price'] ) && '' !== trim( $_POST['wru_reseller_price'] ) ) {
			$reseller_price = (float) sanitize_text_field( wp_unslash( $_POST['wru_reseller_price'] ) );
			$cart_item_data['wru_reseller_price'] = $reseller_price;
			// Unique key prevents merging if same item added with different reseller prices.
			$cart_item_data['wru_item_uid'] = md5( microtime() . wp_rand() );
		}
		return $cart_item_data;
	}

	/**
	 * Restore reseller data from session.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 * @return array
	 */
	public function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['wru_reseller_price'] ) ) {
			$cart_item['wru_reseller_price'] = (float) $values['wru_reseller_price'];
		}
		return $cart_item;
	}

	/**
	 * Display reseller selling price & profit on cart table.
	 *
	 * @param array $item_data Existing item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		// On checkout page, the interactive editor handles both display and live editing.
		if ( is_checkout() ) {
			return $item_data;
		}

		if ( isset( $cart_item['wru_reseller_price'] ) ) {
			$reseller_price  = (float) $cart_item['wru_reseller_price'];
			$product         = $cart_item['data'];
			$wholesale_price = (float) $product->get_price();
			$unit_profit     = max( 0, $reseller_price - $wholesale_price );

			$item_data[] = array(
				'key'     => __( 'কাস্টমার কালেকশন মূল্য', 'woocommerce-resell-utility' ),
				'value'   => wc_price( $reseller_price ),
				'display' => wc_price( $reseller_price ),
			);

			$item_data[] = array(
				'key'     => __( 'আপনার আনুমানিক লাভ/পিস', 'woocommerce-resell-utility' ),
				'value'   => wc_price( $unit_profit ),
				'display' => '<span style="color:#16a34a;font-weight:600;">' . wc_price( $unit_profit ) . '</span>',
			);
		}
		return $item_data;
	}

	/**
	 * Render editable reseller selling price input in checkout order review.
	 *
	 * @param string $quantity_html Quantity string HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart key.
	 * @return string
	 */
	public function render_checkout_reseller_price_editor( $quantity_html, $cart_item, $cart_item_key ) {
		if ( ! is_checkout() || ! isset( $cart_item['data'] ) ) {
			return $quantity_html;
		}

		$product         = $cart_item['data'];
		$wholesale_price = (float) $product->get_price();
		$reseller_price  = isset( $cart_item['wru_reseller_price'] ) ? (float) $cart_item['wru_reseller_price'] : $wholesale_price;
		$qty             = (int) $cart_item['quantity'];
		$packaging_fee   = WRU_Settings::get_packaging_fee();
		$packaging_type  = WRU_Settings::get_packaging_type();
		$currency        = get_woocommerce_currency_symbol();
		$min_price       = WRU_Settings::is_min_price_enforced() ? $wholesale_price : 0;

		$total_pack = ( 'item' === $packaging_type ) ? ( $packaging_fee * $qty ) : $packaging_fee;
		$profit     = max( 0, ( $reseller_price - $wholesale_price ) * $qty - $total_pack );

		ob_start();
		?>
		<div class="wru-checkout-edit-box" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
			<label class="wru-checkout-edit-label">
				<?php esc_html_e( 'কাস্টমার কালেকশন মূল্য (আপনার বিক্রয়মূল্য):', 'woocommerce-resell-utility' ); ?>
			</label>
			<div class="wru-checkout-input-group">
				<span class="wru-checkout-currency"><?php echo esc_html( $currency ); ?></span>
				<input type="number" 
					step="any" 
					min="<?php echo esc_attr( $min_price ); ?>"
					name="wru_checkout_prices[<?php echo esc_attr( $cart_item_key ); ?>]" 
					class="wru-checkout-price-input" 
					value="<?php echo esc_attr( $reseller_price > 0 ? $reseller_price : '' ); ?>"
					placeholder="<?php esc_attr_e( 'বিক্রয়মূল্য লিখুন', 'woocommerce-resell-utility' ); ?>"
					data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
					data-wholesale="<?php echo esc_attr( $wholesale_price ); ?>"
					data-qty="<?php echo esc_attr( $qty ); ?>"
					data-packaging-fee="<?php echo esc_attr( $packaging_fee ); ?>"
					data-packaging-type="<?php echo esc_attr( $packaging_type ); ?>" />
			</div>
			<div class="wru-checkout-calc-preview">
				<span><?php esc_html_e( 'আপনার আনুমানিক লাভ:', 'woocommerce-resell-utility' ); ?></span>
				<strong class="wru-checkout-profit-val"><?php echo wc_price( $profit ); ?></strong>
			</div>
		</div>
		<?php
		$editor_html = ob_get_clean();

		return $quantity_html . $editor_html;
	}

	/**
	 * Strip any "Save" or "You Save" discount text generated by themes/plugins on cart & checkout.
	 *
	 * @param string $price_html Price HTML.
	 * @param array  $cart_item  Cart item.
	 * @param string $cart_key   Cart item key.
	 * @return string
	 */
	public function filter_clean_cart_price_html( $price_html, $cart_item, $cart_key ) {
		// If product is valid, return only the active wholesale price so no regular price or del tag is outputted!
		if ( ! empty( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' ) ) {
			return wc_price( $cart_item['data']->get_price() );
		}
		// Strip del tags
		$clean = preg_replace( '/<(?:del)[^>]*>.*?<\/(?:del)>/is', '', $price_html );
		// Strip theme badges like <span class="save-price">Save ৳...</span>
		$clean = preg_replace( '/<(?:span|div|small|p|strong)[^>]*class=[\'"][^\'"]*(?:screen-reader-text|save|saving|discount)[^\'"]*[\'"][^>]*>.*?<\/(?:span|div|small|p|strong)>/is', '', $clean );
		// Strip plain text "You Save: ৳..." or "Save ৳..." or "Previous price: ..."
		$clean = preg_replace( '/\s*(?:Previous price|Discounted price|You Save|Save|আপনি বাঁচিয়েছেন|সাশ্রয়)[:\s]*[^<\n]+/iu', '', $clean );
		return $clean;
	}

	/**
	 * AJAX endpoint for updating reseller customer selling price from checkout page.
	 */
	public function ajax_update_checkout_reseller_price() {
		check_ajax_referer( 'wru_frontend_nonce', 'nonce' );

		$cart_key  = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
		$new_price = isset( $_POST['new_price'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['new_price'] ) ) : 0;

		if ( ! empty( $cart_key ) && isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
			WC()->cart->cart_contents[ $cart_key ]['wru_reseller_price'] = $new_price;
			WC()->cart->set_session();
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	/**
	 * Save line item meta on order checkout.
	 *
	 * @param \WC_Order_Item_Product $item          Line item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart values.
	 * @param \WC_Order              $order         Order object.
	 */
	public function create_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
		$reseller_price = 0.0;
		if ( isset( $_POST['wru_checkout_prices'][ $cart_item_key ] ) && '' !== trim( $_POST['wru_checkout_prices'][ $cart_item_key ] ) ) {
			$reseller_price = (float) sanitize_text_field( wp_unslash( $_POST['wru_checkout_prices'][ $cart_item_key ] ) );
		} elseif ( isset( $values['wru_reseller_price'] ) ) {
			$reseller_price = (float) $values['wru_reseller_price'];
		}

		if ( $reseller_price > 0 ) {
			$product         = $values['data'];
			$wholesale_price = (float) $product->get_price();
			$quantity        = (int) $item->get_quantity();

			$packaging_mode = WRU_Settings::get_packaging_type();
			$unit_packaging = ( 'item' === $packaging_mode ) ? WRU_Settings::get_packaging_fee() : 0;
			$unit_profit    = max( 0, $reseller_price - $wholesale_price - $unit_packaging );
			$total_profit   = $unit_profit * $quantity;

			// Internal hidden meta.
			$item->add_meta_data( '_wru_reseller_price', $reseller_price, true );
			$item->add_meta_data( '_wru_wholesale_price', $wholesale_price, true );
			$item->add_meta_data( '_wru_unit_profit', $unit_profit, true );
			$item->add_meta_data( '_wru_line_profit', $total_profit, true );

			// Visible meta for invoices, packaging slips, and emails.
			$item->add_meta_data( __( 'কাস্টমার কালেকশন মূল্য', 'woocommerce-resell-utility' ), wc_price( $reseller_price ), true );
			$item->add_meta_data( __( 'রিসেলার লাভ (মোট)', 'woocommerce-resell-utility' ), wc_price( $total_profit ), true );
		}
	}

	/**
	 * Calculate & save order-level totals for reseller dropshipping accounting.
	 *
	 * @param int       $order_id Order ID.
	 * @param array     $posted_data Posted form data.
	 * @param \WC_Order $order Order object.
	 */
	public function calculate_order_reseller_totals( $order_id, $posted_data = array(), $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$has_resell_items = false;
		$total_collection = 0.0;
		$total_wholesale  = 0.0;
		$total_qty        = 0;

		foreach ( $order->get_items() as $item ) {
			$reseller_price = $item->get_meta( '_wru_reseller_price', true );
			$qty            = (int) $item->get_quantity();
			$total_qty     += $qty;

			if ( '' !== $reseller_price && false !== $reseller_price ) {
				$has_resell_items = true;
				$res_price        = (float) $reseller_price;
				$wholesale_price  = (float) $item->get_meta( '_wru_wholesale_price', true );

				$total_collection += ( $res_price * $qty );
				$total_wholesale  += ( $wholesale_price * $qty );
			} else {
				// Fallback to standard item subtotal if not entered.
				$total_collection += (float) $item->get_subtotal();
				$total_wholesale  += (float) $item->get_subtotal();
			}
		}

		if ( ! $has_resell_items ) {
			return;
		}

		$packaging_fee_rate = WRU_Settings::get_packaging_fee();
		$packaging_mode     = WRU_Settings::get_packaging_type();

		$total_packaging = ( 'item' === $packaging_mode ) ? ( $packaging_fee_rate * $total_qty ) : $packaging_fee_rate;

		// Courier COD: Total customer selling price + shipping charge.
		$shipping_total = (float) $order->get_shipping_total();
		$cod_collection = $total_collection + $shipping_total;

		// Reseller Payout = (Customer Collection - Shipping) - Wholesale Cost - Packaging Cost.
		$reseller_profit = max( 0, $total_collection - $total_wholesale - $total_packaging );

		// Save meta to order (HPOS and classic).
		$order->update_meta_data( '_wru_is_resell_order', 'yes' );
		$order->update_meta_data( '_wru_total_collection_amount', $cod_collection );
		$order->update_meta_data( '_wru_customer_items_total', $total_collection );
		$order->update_meta_data( '_wru_total_wholesale_amount', $total_wholesale );
		$order->update_meta_data( '_wru_total_packaging_fee', $total_packaging );
		$order->update_meta_data( '_wru_total_reseller_profit', $reseller_profit );
		$order->save();
	}

	/**
	 * Register Meta Box for WooCommerce Admin Order Screen.
	 */
	public function register_admin_order_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wru_admin_order_box',
			__( 'ড্রপশিপিং ও কুরিয়ার কালেকশন তথ্য (Reseller & Courier Info)', 'woocommerce-resell-utility' ),
			array( $this, 'render_admin_order_meta_box' ),
			$screen,
			'normal',
			'high'
		);
	}

	/**
	 * Render the Admin Order Details Meta Box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or Order object.
	 */
	public function render_admin_order_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$is_resell_order = 'yes' === $order->get_meta( '_wru_is_resell_order' );
		$collection      = (float) $order->get_meta( '_wru_total_collection_amount' );
		$wholesale       = (float) $order->get_meta( '_wru_total_wholesale_amount' );
		$packaging       = (float) $order->get_meta( '_wru_total_packaging_fee' );
		$profit          = (float) $order->get_meta( '_wru_total_reseller_profit' );

		// Fallback calculations if legacy order.
		if ( ! $is_resell_order ) {
			$collection = (float) $order->get_total();
			$profit     = 0.0;
		}

		// Prepare courier note copy text.
		$customer_name  = $order->get_formatted_billing_full_name();
		$phone          = $order->get_billing_phone();
		$address        = $order->get_billing_address_1() . ( $order->get_billing_city() ? ', ' . $order->get_billing_city() : '' );
		$item_names     = array();
		foreach ( $order->get_items() as $item ) {
			$item_names[] = $item->get_name() . ' x ' . $item->get_quantity();
		}
		$items_str = implode( ', ', $item_names );

		$courier_copy_text = sprintf(
			"কাস্টমার: %s\nমোবাইল: %s\nঠিকানা: %s\nকালেকশন এমাউন্ট (COD): ৳%s\nপণ্য: %s\nনোট: ডেলিভারির সময় কাস্টমার চেক করে নিবেন।",
			$customer_name,
			$phone,
			$address,
			number_format( $collection, 2, '.', '' ),
			$items_str
		);
		?>
		<div class="wru-admin-metabox">
			<div class="wru-admin-cards-grid">
				<div class="wru-admin-stat-card wru-card-collection">
					<span class="wru-stat-title"><?php esc_html_e( 'কুরিয়ার কালেকশন (COD Amount)', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-stat-value"><?php echo wc_price( $collection ); ?></strong>
					<small class="wru-stat-desc"><?php esc_html_e( 'কুরিয়ারের মাধ্যমে কাস্টমার হতে আদায় করবেন', 'woocommerce-resell-utility' ); ?></small>
				</div>

				<div class="wru-admin-stat-card wru-card-wholesale">
					<span class="wru-stat-title"><?php esc_html_e( 'পণ্যের পাইকারি দাম', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-stat-value"><?php echo wc_price( $wholesale ); ?></strong>
					<small class="wru-stat-desc"><?php esc_html_e( 'আমাদের প্রোডাক্টের আসল কস্ট', 'woocommerce-resell-utility' ); ?></small>
				</div>

				<div class="wru-admin-stat-card wru-card-packaging">
					<span class="wru-stat-title"><?php esc_html_e( 'প্যাকেজিং খরচ', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-stat-value"><?php echo wc_price( $packaging ); ?></strong>
					<small class="wru-stat-desc"><?php esc_html_e( 'কার্টন, বাবল র‍্যাপ ও প্রসেসিং ফি', 'woocommerce-resell-utility' ); ?></small>
				</div>

				<div class="wru-admin-stat-card wru-card-profit">
					<span class="wru-stat-title"><?php esc_html_e( 'রিসেলারের পাওনা (Net Payout)', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-stat-value wru-profit-val"><?php echo wc_price( $profit ); ?></strong>
					<small class="wru-stat-desc"><?php esc_html_e( 'অর্ডার সম্পন্ন হলে রিসেলারকে পরিশোধ করবেন', 'woocommerce-resell-utility' ); ?></small>
				</div>
			</div>

			<div class="wru-admin-courier-action">
				<div class="wru-courier-note-box">
					<label><strong><?php esc_html_e( 'কুরিয়ার বুকিং নোট (Steadfast / Pathao / RedX / Paperfly ইত্যাদির জন্য):', 'woocommerce-resell-utility' ); ?></strong></label>
					<textarea readonly id="wru_courier_copy_text" rows="4"><?php echo esc_textarea( $courier_copy_text ); ?></textarea>
				</div>
				<button type="button" class="button button-primary wru-btn-copy-courier" id="wru_btn_copy_courier" data-copy="<?php echo esc_attr( $courier_copy_text ); ?>">
					<?php esc_html_e( 'কুরিয়ার নোট কপি করুন', 'woocommerce-resell-utility' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render reseller profit summary on Thank You page and View Order page.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function render_frontend_order_summary( $order ) {
		if ( ! $order ) {
			return;
		}

		$is_resell_order = 'yes' === $order->get_meta( '_wru_is_resell_order' );
		if ( ! $is_resell_order ) {
			return;
		}

		$collection = (float) $order->get_meta( '_wru_total_collection_amount' );
		$wholesale  = (float) $order->get_meta( '_wru_total_wholesale_amount' );
		$packaging  = (float) $order->get_meta( '_wru_total_packaging_fee' );
		$profit     = (float) $order->get_meta( '_wru_total_reseller_profit' );
		?>
		<div class="wru-order-profit-box">
			<div class="wru-order-profit-header">
				<h3><?php esc_html_e( 'রিসেলার লাভ ও ড্রপশিপিং সামারি', 'woocommerce-resell-utility' ); ?></h3>
				<span class="wru-badge"><?php esc_html_e( 'অর্ডার হিসাব', 'woocommerce-resell-utility' ); ?></span>
			</div>
			
			<div class="wru-order-profit-grid">
				<div class="wru-order-profit-item">
					<span class="wru-label"><?php esc_html_e( 'কুরিয়ার কালেকশন (COD):', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-val"><?php echo wc_price( $collection ); ?></strong>
				</div>
				<div class="wru-order-profit-item">
					<span class="wru-label"><?php esc_html_e( 'পণ্যের পাইকারি মূল্য:', 'woocommerce-resell-utility' ); ?></span>
					<span class="wru-val"><?php echo wc_price( $wholesale ); ?></span>
				</div>
				<div class="wru-order-profit-item">
					<span class="wru-label"><?php esc_html_e( 'প্যাকেজিং খরচ:', 'woocommerce-resell-utility' ); ?></span>
					<span class="wru-val">-<?php echo wc_price( $packaging ); ?></span>
				</div>
				<div class="wru-order-profit-item wru-profit-highlight">
					<span class="wru-label"><?php esc_html_e( 'আপনার নিট লাভ (Profit):', 'woocommerce-resell-utility' ); ?></span>
					<strong class="wru-val wru-profit-number"><?php echo wc_price( $profit ); ?></strong>
				</div>
			</div>
			<p class="wru-order-note">
				<em><?php esc_html_e( 'নোট: কাস্টমারকে পার্সেলটি ডেলিভারি করে কুরিয়ার হতে টাকা সংগ্রহের পর আপনার নিট লাভ আপনার একাউন্টে জমা হবে।', 'woocommerce-resell-utility' ); ?></em>
			</p>
		</div>
		<?php
	}
}
