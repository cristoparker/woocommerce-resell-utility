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
		// Register custom WooCommerce order statuses (Packed & Shipped).
		add_action( 'init', array( $this, 'register_custom_order_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_custom_order_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'add_custom_order_statuses_to_reports' ) );

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

		// Calculate & store order-level reseller totals on order creation & status change.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'calculate_order_reseller_totals' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );

		// Localize and customize checkout fields for dropshipping resellers.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'customize_checkout_fields' ), 999 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'customize_billing_fields' ), 999 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'customize_shipping_fields' ), 999 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'customize_default_address_fields' ), 999 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'filter_checkout_posted_data' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'filter_reseller_checkout_field_values' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_banner' ), 5 );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false', 999 );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 999 );

		// Reseller role verification during checkout confirmation & order processing.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_reseller_role' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_reseller_role_after' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'guard_checkout_create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'sync_order_shipping_address' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_reseller_permission' ) );

		// Live Courier Collection Amount (COD) in Checkout Order Summary Review Table & Card.
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkout_courier_collection_row' ), 20 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_courier_collection_card' ), 5 );

		// Admin Order details Meta Box (Supports both HPOS and traditional CPT).
		add_action( 'add_meta_boxes', array( $this, 'register_admin_order_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_admin_order_reseller_meta' ), 15, 2 );

		// Frontend order received and view order profit summary.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_frontend_order_summary' ), 15 );

		// Add custom columns in WooCommerce Orders list table (HPOS & classic).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_columns' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_list_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column_content' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_list_column_content' ), 10, 2 );
	}

	/**
	 * Register custom order statuses (Packed & Shipped).
	 */
	public function register_custom_order_statuses() {
		register_post_status(
			'wc-packed',
			array(
				'label'                     => _x( 'প্যাকড (Packed)', 'Order status', 'woocommerce-resell-utility' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'প্যাকড <span class="count">(%s)</span>', 'প্যাকড <span class="count">(%s)</span>', 'woocommerce-resell-utility' ),
			)
		);

		register_post_status(
			'wc-shipped',
			array(
				'label'                     => _x( 'শিপড (Shipped)', 'Order status', 'woocommerce-resell-utility' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'শিপড <span class="count">(%s)</span>', 'শিপড <span class="count">(%s)</span>', 'woocommerce-resell-utility' ),
			)
		);
	}

	/**
	 * Add custom statuses to WooCommerce order statuses array.
	 *
	 * @param array $order_statuses Existing statuses.
	 * @return array
	 */
	public function add_custom_order_statuses( $order_statuses ) {
		$new_statuses = array();
		foreach ( $order_statuses as $key => $status ) {
			$new_statuses[ $key ] = $status;
			if ( 'wc-processing' === $key ) {
				$new_statuses['wc-packed']  = _x( 'প্যাকড (Packed)', 'Order status', 'woocommerce-resell-utility' );
				$new_statuses['wc-shipped'] = _x( 'শিপড (Shipped)', 'Order status', 'woocommerce-resell-utility' );
			}
		}
		return $new_statuses;
	}

	/**
	 * Include custom statuses in WooCommerce reports if applicable.
	 *
	 * @param array $order_statuses Existing report statuses.
	 * @return array
	 */
	public function add_custom_order_statuses_to_reports( $order_statuses ) {
		$order_statuses[] = 'packed';
		$order_statuses[] = 'shipped';
		return $order_statuses;
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
		} elseif ( isset( $values['wru_reseller_price'] ) && (float) $values['wru_reseller_price'] > 0 ) {
			$reseller_price = (float) $values['wru_reseller_price'];
		}

		$product         = $values['data'];
		$wholesale_price = (float) $product->get_price();
		$quantity        = (int) $item->get_quantity();

		// If no reseller price was provided, fallback to regular/market price
		if ( $reseller_price <= 0 ) {
			$reseller_price = (float) $product->get_regular_price() ?: $wholesale_price;
		}

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

		$total_collection = 0.0;
		$total_wholesale  = 0.0;
		$total_qty        = 0;

		foreach ( $order->get_items() as $item ) {
			$reseller_price = $item->get_meta( '_wru_reseller_price', true );
			$qty            = (int) $item->get_quantity();
			$total_qty     += $qty;

			if ( '' !== $reseller_price && false !== $reseller_price && (float) $reseller_price > 0 ) {
				$res_price       = (float) $reseller_price;
				$wholesale_price = (float) $item->get_meta( '_wru_wholesale_price', true );
				if ( $wholesale_price <= 0 ) {
					$prod            = $item->get_product();
					$wholesale_price = $prod ? (float) $prod->get_price() : (float) $item->get_subtotal() / max( 1, $qty );
				}
				$total_collection += ( $res_price * $qty );
				$total_wholesale  += ( $wholesale_price * $qty );
			} else {
				// Fallback: use product regular price (market price) if not explicitly set
				$prod            = $item->get_product();
				$wholesale_price = $prod ? (float) $prod->get_price() : (float) $item->get_subtotal() / max( 1, $qty );
				$res_price       = $prod ? (float) $prod->get_regular_price() : $wholesale_price;
				if ( $res_price <= 0 ) {
					$res_price = $wholesale_price;
				}
				$total_collection += ( $res_price * $qty );
				$total_wholesale  += ( $wholesale_price * $qty );
			}
		}

		// Calculate packaging fee per item or order using product-specific fees.
		$packaging_mode  = WRU_Settings::get_packaging_type();
		$total_packaging = 0.0;

		foreach ( $order->get_items() as $item ) {
			$qty           = (int) $item->get_quantity();
			$prod          = $item->get_product();
			$item_pack_fee = $prod ? WRU_Product_Fields::get_packaging_fee( $prod ) : WRU_Settings::get_packaging_fee();
			$total_packaging += ( 'item' === $packaging_mode ) ? ( $item_pack_fee * $qty ) : $item_pack_fee;
		}

		if ( $total_packaging <= 0 ) {
			$total_packaging = WRU_Settings::get_packaging_fee();
		}

		// Courier COD: Total customer selling price + shipping charge.
		$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$cod_collection = $total_collection + $shipping_total;

		// Reseller Profit = Customer Items Selling Total - Wholesale Cost - Packaging Cost.
		$reseller_profit = max( 0, $total_collection - $total_wholesale - $total_packaging );

		// Save meta to order (HPOS and classic).
		$order->update_meta_data( '_wru_is_resell_order', 'yes' );
		$order->update_meta_data( '_wru_total_collection_amount', $cod_collection );
		$order->update_meta_data( '_wru_customer_items_total', $total_collection );
		$order->update_meta_data( '_wru_shipping_charge', $shipping_total );
		$order->update_meta_data( '_wru_total_wholesale_amount', $total_wholesale );
		$order->update_meta_data( '_wru_total_packaging_fee', $total_packaging );
		$order->update_meta_data( '_wru_total_reseller_profit', $reseller_profit );

		// Capture Reseller Company Name & Hotline for the packaging label
		$reseller_id = $order->get_customer_id();
		$company     = '';
		$phone       = '';

		if ( isset( $_POST['billing_company'] ) && ! empty( trim( $_POST['billing_company'] ) ) ) {
			$company = sanitize_text_field( wp_unslash( $_POST['billing_company'] ) );
		} elseif ( isset( $_POST['wru_reseller_company_name'] ) && ! empty( trim( $_POST['wru_reseller_company_name'] ) ) ) {
			$company = sanitize_text_field( wp_unslash( $_POST['wru_reseller_company_name'] ) );
		} elseif ( $reseller_id ) {
			$company = get_user_meta( $reseller_id, '_wru_reseller_company_name', true ) ?: get_user_meta( $reseller_id, 'billing_company', true );
		}

		if ( isset( $_POST['billing_reseller_phone'] ) && ! empty( trim( $_POST['billing_reseller_phone'] ) ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['billing_reseller_phone'] ) );
		} elseif ( isset( $_POST['wru_reseller_phone'] ) && ! empty( trim( $_POST['wru_reseller_phone'] ) ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['wru_reseller_phone'] ) );
		} elseif ( $reseller_id ) {
			$phone = get_user_meta( $reseller_id, '_wru_reseller_phone', true ) ?: get_user_meta( $reseller_id, '_wru_payout_number', true );
		}

		if ( ! empty( $company ) ) {
			$order->update_meta_data( '_wru_reseller_company_name', $company );
			if ( $reseller_id ) {
				update_user_meta( $reseller_id, '_wru_reseller_company_name', $company );
			}
		}

		if ( ! empty( $phone ) ) {
			$order->update_meta_data( '_wru_reseller_phone', $phone );
			if ( $reseller_id ) {
				update_user_meta( $reseller_id, '_wru_reseller_phone', $phone );
			}
		}

		$order->save();
	}

	/**
	 * On order status change, track packed/shipped lifecycle and refresh totals.
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order ) {
			if ( 'packed' === $to ) {
				$order->update_meta_data( '_wru_was_packed', 'yes' );
				$order->save();
			} elseif ( 'shipped' === $to ) {
				$order->update_meta_data( '_wru_was_packed', 'yes' );
				$order->update_meta_data( '_wru_was_shipped', 'yes' );
				$order->save();
			}
		}
		$this->calculate_order_reseller_totals( $order_id, array(), $order );
	}

	/**
	 * Calculate order cancellation or refund deduction based on parcel stage.
	 *
	 * Matrix:
	 * - Failed: 0 deduction (stock issue / store inability to fulfill).
	 * - Shipped: Packaging Fee + Cancellation Penalty Fee + Shipping Fee.
	 * - Packed (before shipping): Packaging Fee only.
	 * - Processing/Pending/On-hold (before packing): 0 deduction.
	 *
	 * @param \WC_Order|int $order Order object or ID.
	 * @return array
	 */
	public static function get_order_cancellation_breakdown( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return array(
				'total_loss'    => 0.0,
				'packaging_fee' => 0.0,
				'shipping_fee'  => 0.0,
				'penalty_fee'   => 0.0,
				'reason'        => '',
				'is_deductible' => false,
			);
		}

		$order_status = $order->get_status();

		// 1. Failed order: 0 deduction (wholesale stock problem, not reseller fault).
		if ( 'failed' === $order_status ) {
			return array(
				'total_loss'    => 0.0,
				'packaging_fee' => 0.0,
				'shipping_fee'  => 0.0,
				'penalty_fee'   => 0.0,
				'reason'        => __( 'ফেইল্ড অর্ডার (স্টক বা শপ জনিত কারণে বাতিল, রিসেলারের কোনো কর্তন নেই)', 'woocommerce-resell-utility' ),
				'is_deductible' => false,
			);
		}

		if ( ! in_array( $order_status, array( 'cancelled', 'refunded' ), true ) ) {
			return array(
				'total_loss'    => 0.0,
				'packaging_fee' => 0.0,
				'shipping_fee'  => 0.0,
				'penalty_fee'   => 0.0,
				'reason'        => '',
				'is_deductible' => false,
			);
		}

		$packaging_fee = (float) $order->get_meta( '_wru_total_packaging_fee' );
		if ( $packaging_fee <= 0 ) {
			$packaging_fee = WRU_Settings::get_packaging_fee();
		}

		$shipping_fee = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		if ( $shipping_fee <= 0 ) {
			$shipping_fee = (float) $order->get_meta( '_wru_shipping_charge' );
		}

		$cancellation_fee_rate = WRU_Settings::get_cancellation_fee();

		$is_refunded = ( 'refunded' === $order_status );
		$was_shipped = ( 'shipped' === $order_status || 'yes' === $order->get_meta( '_wru_was_shipped' ) || $is_refunded );
		$was_packed  = ( 'packed' === $order_status || 'yes' === $order->get_meta( '_wru_was_packed' ) );

		// 2. Refunded (পার্সেল ঘুরে রিফান্ড) or Cancelled after parcel was shipped to courier:
		// Full deduction = Packaging Fee + Penalty Fee + Shipping Fee
		if ( $is_refunded || $was_shipped ) {
			$total_loss = $packaging_fee + $cancellation_fee_rate + $shipping_fee;
			$reason_msg = $is_refunded
				? __( 'পার্সেল রিটার্ন / রিফান্ড (প্যাকেজিং ফি + জরিমানা ফি + শিপিং ফি কর্তন)', 'woocommerce-resell-utility' )
				: __( 'শিপড / কুরিয়ারে প্রেরণের পর বাতিল (প্যাকেজিং ফি + জরিমানা ফি + শিপিং ফি কর্তন)', 'woocommerce-resell-utility' );

			return array(
				'total_loss'    => (float) $total_loss,
				'packaging_fee' => (float) $packaging_fee,
				'shipping_fee'  => (float) $shipping_fee,
				'penalty_fee'   => (float) $cancellation_fee_rate,
				'reason'        => $reason_msg,
				'is_deductible' => true,
			);
		}

		// 3. Cancelled while packed (before being shipped to courier):
		// Deduction = Packaging Fee only
		if ( $was_packed ) {
			return array(
				'total_loss'    => (float) $packaging_fee,
				'packaging_fee' => (float) $packaging_fee,
				'shipping_fee'  => 0.0,
				'penalty_fee'   => 0.0,
				'reason'        => __( 'প্যাকড হওয়ার পর বাতিল (শুধুমাত্র প্যাকেজিং ফি কর্তন)', 'woocommerce-resell-utility' ),
				'is_deductible' => true,
			);
		}

		// 4. Cancelled while pending / on-hold / processing before packing:
		// Deduction = 0
		return array(
			'total_loss'    => 0.0,
			'packaging_fee' => 0.0,
			'shipping_fee'  => 0.0,
			'penalty_fee'   => 0.0,
			'reason'        => __( 'প্যাক করার পূর্বে বাতিল (কোনো ফি কর্তন হয়নি)', 'woocommerce-resell-utility' ),
			'is_deductible' => false,
		);
	}

	/**
	 * Customize checkout fields in Bengali for dropshipping resellers.
	 * Only 5 fields: Customer Name, Customer Mobile, Customer Address, Zip (optional), Reseller Email.
	 *
	 * @param array $fields Checkout fields array.
	 * @return array
	 */
	public function customize_checkout_fields( $fields ) {
		$billing = array();

		// 1. Customer Name (Required)
		$billing['billing_first_name'] = array(
			'label'       => __( 'কাস্টমারের নাম', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'কাস্টমারের সম্পূর্ণ নাম লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 10,
		);

		// 2. Customer Mobile Number (Required)
		$billing['billing_phone'] = array(
			'label'       => __( 'কাস্টমারের মোবাইল নাম্বার', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'যেমন: 017XXXXXXXX', 'woocommerce-resell-utility' ),
			'required'    => true,
			'type'        => 'tel',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 20,
		);

		// 3. Customer Delivery Address (Required)
		$billing['billing_address_1'] = array(
			'label'       => __( 'কাস্টমারের ঠিকানা', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'বাসা/রোড নম্বর, এলাকা বা গ্রাম, থানা ও জেলা লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 30,
		);

		// 4. Postal / Zip Code (Optional)
		$billing['billing_postcode'] = array(
			'label'       => __( 'জিপ কোড (ঐচ্ছিক)', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'যেমন: 1205 (ঐচ্ছিক)', 'woocommerce-resell-utility' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 40,
		);

		// 5. Reseller Email (Required)
		$billing['billing_email'] = array(
			'label'       => __( 'রিসেলারের ইমেইল', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'রিসেলারের ইমেইল লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'type'        => 'email',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 50,
			'default'     => is_user_logged_in() ? wp_get_current_user()->user_email : '',
		);

		$fields['billing']  = $billing;
		$fields['shipping'] = array();
		$fields['order']    = array();

		return $fields;
	}

	/**
	 * Customize billing fields directly.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public function customize_billing_fields( $fields ) {
		$new_fields = array();

		$new_fields['billing_first_name'] = array(
			'label'       => __( 'কাস্টমারের নাম', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'কাস্টমারের সম্পূর্ণ নাম লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 10,
		);

		$new_fields['billing_phone'] = array(
			'label'       => __( 'কাস্টমারের মোবাইল নাম্বার', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'যেমন: 017XXXXXXXX', 'woocommerce-resell-utility' ),
			'required'    => true,
			'type'        => 'tel',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 20,
		);

		$new_fields['billing_address_1'] = array(
			'label'       => __( 'কাস্টমারের ঠিকানা', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'বাসা/রোড নম্বর, এলাকা বা গ্রাম, থানা ও জেলা লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 30,
		);

		$new_fields['billing_postcode'] = array(
			'label'       => __( 'জিপ কোড (ঐচ্ছিক)', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'যেমন: 1205 (ঐচ্ছিক)', 'woocommerce-resell-utility' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 40,
		);

		$new_fields['billing_email'] = array(
			'label'       => __( 'রিসেলারের ইমেইল', 'woocommerce-resell-utility' ),
			'placeholder' => __( 'রিসেলারের ইমেইল লিখুন', 'woocommerce-resell-utility' ),
			'required'    => true,
			'type'        => 'email',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 50,
			'default'     => is_user_logged_in() ? wp_get_current_user()->user_email : '',
		);

		return $new_fields;
	}

	/**
	 * Suppress separate shipping form since billing is the customer delivery address.
	 *
	 * @param array $fields Shipping fields.
	 * @return array
	 */
	public function customize_shipping_fields( $fields ) {
		return array();
	}

	/**
	 * Customize default address fields directly.
	 *
	 * @param array $fields Address fields.
	 * @return array
	 */
	public function customize_default_address_fields( $fields ) {
		if ( isset( $fields['first_name'] ) ) {
			$fields['first_name']['label']       = __( 'কাস্টমারের নাম', 'woocommerce-resell-utility' );
			$fields['first_name']['placeholder'] = __( 'কাস্টমারের সম্পূর্ণ নাম লিখুন', 'woocommerce-resell-utility' );
			$fields['first_name']['required']    = true;
			$fields['first_name']['class']       = array( 'form-row-wide' );
		}
		unset( $fields['last_name'] );

		if ( isset( $fields['address_1'] ) ) {
			$fields['address_1']['label']       = __( 'কাস্টমারের ঠিকানা', 'woocommerce-resell-utility' );
			$fields['address_1']['placeholder'] = __( 'বাসা/রোড নম্বর, এলাকা বা গ্রাম, থানা ও জেলা লিখুন', 'woocommerce-resell-utility' );
			$fields['address_1']['required']    = true;
			$fields['address_1']['class']       = array( 'form-row-wide' );
		}
		unset( $fields['address_2'] );
		unset( $fields['company'] );
		unset( $fields['city'] );
		unset( $fields['state'] );

		if ( isset( $fields['postcode'] ) ) {
			$fields['postcode']['label']       = __( 'জিপ কোড (ঐচ্ছিক)', 'woocommerce-resell-utility' );
			$fields['postcode']['placeholder'] = __( 'যেমন: 1205 (ঐচ্ছিক)', 'woocommerce-resell-utility' );
			$fields['postcode']['required']    = false;
			$fields['postcode']['class']       = array( 'form-row-wide' );
		}

		return $fields;
	}

	/**
	 * Ensure last name & required defaults are populated so WooCommerce core validation succeeds.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public function filter_checkout_posted_data( $data ) {
		if ( empty( $data['billing_last_name'] ) && ! empty( $data['billing_first_name'] ) ) {
			$data['billing_last_name'] = $data['billing_first_name'];
		}
		if ( empty( $data['billing_country'] ) ) {
			$data['billing_country'] = 'BD';
		}
		if ( empty( $data['billing_city'] ) ) {
			$data['billing_city'] = 'Dhaka';
		}

		// Mirror to shipping fields for courier tracking & shipping providers
		$data['shipping_first_name'] = ! empty( $data['billing_first_name'] ) ? $data['billing_first_name'] : '';
		$data['shipping_last_name']  = ! empty( $data['billing_last_name'] ) ? $data['billing_last_name'] : '';
		$data['shipping_phone']      = ! empty( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		$data['shipping_address_1']  = ! empty( $data['billing_address_1'] ) ? $data['billing_address_1'] : '';
		$data['shipping_postcode']   = ! empty( $data['billing_postcode'] ) ? $data['billing_postcode'] : '';
		$data['shipping_country']    = ! empty( $data['billing_country'] ) ? $data['billing_country'] : 'BD';
		$data['shipping_city']       = ! empty( $data['billing_city'] ) ? $data['billing_city'] : 'Dhaka';

		return $data;
	}

	/**
	 * Sync billing address into order shipping fields on checkout creation.
	 *
	 * @param \WC_Order $order Order object.
	 * @param array     $data  Checkout posted data.
	 */
	public function sync_order_shipping_address( $order, $data ) {
		if ( ! $order ) {
			return;
		}
		$order->set_shipping_first_name( $order->get_billing_first_name() );
		$order->set_shipping_last_name( $order->get_billing_last_name() );
		$order->set_shipping_phone( $order->get_billing_phone() );
		$order->set_shipping_address_1( $order->get_billing_address_1() );
		$order->set_shipping_postcode( $order->get_billing_postcode() );
		if ( ! $order->get_shipping_city() ) {
			$order->set_shipping_city( 'Dhaka' );
		}
		if ( ! $order->get_shipping_country() ) {
			$order->set_shipping_country( 'BD' );
		}
	}

	/**
	 * Ensure reseller's personal address/name does not auto-populate customer fields on checkout.
	 *
	 * @param mixed  $value Field value.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public function filter_reseller_checkout_field_values( $value, $input ) {
		if ( is_user_logged_in() && ( current_user_can( WRU_Reseller_Manager::ROLE_RESELLER ) || current_user_can( 'manage_woocommerce' ) ) ) {
			if ( in_array( $input, array( 'billing_first_name', 'billing_phone', 'billing_address_1', 'shipping_first_name', 'shipping_phone', 'shipping_address_1' ), true ) ) {
				if ( ! isset( $_POST[ $input ] ) ) {
					return '';
				}
			}
		}
		return $value;
	}

	/**
	 * Helper to compute live courier collection amount.
	 *
	 * @return float
	 */
	public static function get_cart_courier_collection_amount() {
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return 0.0;
		}

		$total_collection = 0.0;
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$qty     = (int) $cart_item['quantity'];
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( isset( $cart_item['wru_reseller_price'] ) && (float) $cart_item['wru_reseller_price'] > 0 ) {
				$res_price = (float) $cart_item['wru_reseller_price'];
			} else {
				$wholesale_price = $product ? (float) $product->get_price() : 0;
				$res_price       = $product ? ( (float) $product->get_regular_price() ?: $wholesale_price ) : 0;
			}
			$total_collection += ( $res_price * $qty );
		}

		$collection_amount = self::get_cart_courier_collection_amount();
		if ( $collection_amount <= 0 ) {
			return;
		}
		?>
		<tr class="wru-checkout-courier-collection-row" style="background:#ecfdf5; border-top:2px solid #10b981;">
			<th style="padding:12px 14px; font-weight:700; color:#065f46;">
				<span class="wru-collection-title" style="display:block; font-size:14px;">
					<?php esc_html_e( 'কুরিয়ার কালেকশন এমাউন্ট (COD)', 'woocommerce-resell-utility' ); ?>
				</span>
				<small class="wru-collection-subtext" style="display:block; font-size:11px; color:#047857; font-weight:normal;">
					<?php esc_html_e( '(কাস্টমারের কাছ থেকে কুরিয়ার এই মোট টাকা সংগ্রহ করবে)', 'woocommerce-resell-utility' ); ?>
				</small>
			</th>
			<td style="padding:12px 14px; text-align:right;">
				<strong class="wru-checkout-collection-amount" style="font-size:17px; font-weight:800; color:#065f46;"><?php echo wc_price( $collection_amount ); ?></strong>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render prominent Courier Collection COD Summary Card right before payment methods.
	 */
	public function render_checkout_courier_collection_card() {
		$collection_amount = self::get_cart_courier_collection_amount();
		if ( $collection_amount <= 0 ) {
			return;
		}
		?>
		<div class="wru-checkout-courier-collection-box" style="background:#ecfdf5; border:2px solid #10b981; border-radius:10px; padding:15px 18px; margin:16px 0 20px 0; box-shadow:0 1px 3px rgba(16,185,129,0.12);">
			<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
				<div style="display:flex; align-items:center; gap:10px;">
					<span style="font-size:24px; line-height:1;">🚚</span>
					<div>
						<strong style="display:block; font-size:15px; color:#065f46; font-weight:700;">
							<?php esc_html_e( 'কুরিয়ার কালেকশন এমাউন্ট (COD)', 'woocommerce-resell-utility' ); ?>
						</strong>
						<small style="display:block; font-size:12px; color:#047857;">
							<?php esc_html_e( '(কাস্টমারের কাছ থেকে কুরিয়ার এই মোট টাকা সংগ্রহ করবে)', 'woocommerce-resell-utility' ); ?>
						</small>
					</div>
				</div>
				<div style="text-align:right;">
					<strong class="wru-checkout-collection-amount" style="font-size:22px; font-weight:800; color:#065f46;">
						<?php echo wc_price( $collection_amount ); ?>
					</strong>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Validate reseller role during checkout confirmation (runs when Place Order is clicked).
	 * If the user does not have the reseller role, the checkout fails immediately with a notice.
	 */
	public function validate_checkout_reseller_role() {
		if ( ! WRU_Reseller_Manager::is_reseller() ) {
			$message = WRU_Reseller_Manager::get_reseller_checkout_error_message();
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Secondary validation hook with WP_Error object.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors object.
	 */
	public function validate_checkout_reseller_role_after( $data, $errors ) {
		if ( ! WRU_Reseller_Manager::is_reseller() ) {
			$message = WRU_Reseller_Manager::get_reseller_checkout_error_message();
			if ( is_wp_error( $errors ) && ! wc_has_notice( $message, 'error' ) ) {
				$errors->add( 'wru_not_reseller', $message );
			}
		}
	}

	/**
	 * Final barrier right before order creation in DB. Throws exception if not a reseller.
	 *
	 * @param \WC_Order $order Order object.
	 * @param array     $data  Checkout posted data.
	 * @throws \Exception When user lacks reseller role.
	 */
	public function guard_checkout_create_order( $order, $data ) {
		if ( ! WRU_Reseller_Manager::is_reseller() ) {
			$message = WRU_Reseller_Manager::get_reseller_checkout_error_message();
			throw new Exception( wp_strip_all_tags( $message ) );
		}
	}

	/**
	 * Display warning notice on checkout page if customer is not an approved reseller.
	 */
	public function check_cart_reseller_permission() {
		if ( is_checkout() && ! is_order_received_page() ) {
			if ( ! WRU_Reseller_Manager::is_reseller() ) {
				$message = WRU_Reseller_Manager::get_reseller_checkout_error_message();
				if ( ! wc_has_notice( $message, 'error' ) && ! wc_has_notice( $message, 'notice' ) ) {
					wc_add_notice( $message, 'error' );
				}
			}
		}
	}

	/**
	 * Render clear dropshipping instructions or role restriction banner at the top of Checkout.
	 */
	public function render_checkout_banner() {
		$user_id     = get_current_user_id();
		$is_reseller = WRU_Reseller_Manager::is_reseller( $user_id );

		if ( ! $is_reseller ) {
			$status    = $user_id ? get_user_meta( $user_id, '_wru_reseller_status', true ) : '';
			$login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
			?>
			<div class="wru-checkout-restriction-banner" style="background: #fef2f2; border: 1.5px solid #f87171; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;">
				<div style="display:flex; align-items:flex-start; gap:12px;">
					<span style="font-size:24px; line-height:1; flex-shrink:0;">⛔</span>
					<div>
						<h4 style="margin: 0 0 6px 0; color: #991b1b; font-size: 16px; font-weight: 700;">
							<?php esc_html_e( 'শুধুমাত্র অনুমোদিত রিসেলারদের জন্য', 'woocommerce-resell-utility' ); ?>
						</h4>
						<p style="margin: 0; color: #b91c1c; font-size: 13.5px; line-height: 1.6;">
							<?php
							if ( ! $user_id ) {
								printf(
									/* translators: %s: account URL */
									__( 'অর্ডার কনফার্ম করতে অনুমোদিত রিসেলার রোল থাকা বাধ্যতামূলক। অনুগ্রহ করে প্রথমে আপনার <a href="%s" style="color:#7f1d1d; text-decoration:underline; font-weight:700;">রিসেলার একাউন্টে লগইন করুন</a> অথবা নতুন রিসেলার হিসেবে রেজিস্ট্রেশন করুন।', 'woocommerce-resell-utility' ),
									esc_url( $login_url )
								);
							} elseif ( 'pending' === $status ) {
								esc_html_e( 'আপনার রিসেলার একাউন্টের আবেদনটি বর্তমানে পেন্ডিং (অ্যাডমিন অনুমোদনের অপেক্ষায়) রয়েছে। অ্যাডমিন অনুমোদন না করা পর্যন্ত অর্ডার কনফার্ম হবে না।', 'woocommerce-resell-utility' );
							} elseif ( 'rejected' === $status ) {
								esc_html_e( 'আপনার রিসেলার আবেদনটি বাতিল করা হয়েছে। বিস্তারিত জানতে অ্যাডমিনের সাথে যোগাযোগ করুন।', 'woocommerce-resell-utility' );
							} else {
								esc_html_e( 'আপনার একাউন্টে রিসেলার রোল (Reseller Role) নেই। শুধুমাত্র অনুমোদিত রিসেলাররাই এই স্টোরে অর্ডার কনফার্ম করতে পারবেন।', 'woocommerce-resell-utility' );
							}
							?>
						</p>
					</div>
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div class="wru-checkout-guidance-banner" style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
			<h4 style="margin: 0 0 6px 0; color: #166534; font-size: 15px; font-weight: 700;">
				<?php esc_html_e( 'রিসেলার ড্রপশিপিং অর্ডার ফর্ম', 'woocommerce-resell-utility' ); ?>
			</h4>
			<p style="margin: 0; color: #15803d; font-size: 13px; line-height: 1.6;">
				<?php esc_html_e( 'নিচের ফর্মে আপনার কাস্টমারের নাম, মোবাইল নম্বর এবং পূর্ণ ডেলিভারি ঠিকানা দিন যার কাছে কুরিয়ার পার্সেলটি পৌঁছে দিবে। আর ইমেইল বক্সে আপনার (রিসেলারের) ইমেইল দিন যাতে অর্ডারের সকল আপডেট ও প্রফিট হিসাব আপনার কাছে পৌঁছায়।', 'woocommerce-resell-utility' ); ?>
			</p>
		</div>
		<?php
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

		// Auto-calculate if missing or zero
		if ( $collection <= 0 || ! $is_resell_order ) {
			$this->calculate_order_reseller_totals( $order->get_id(), array(), $order );
			$collection = (float) $order->get_meta( '_wru_total_collection_amount' );
			$wholesale  = (float) $order->get_meta( '_wru_total_wholesale_amount' );
			$packaging  = (float) $order->get_meta( '_wru_total_packaging_fee' );
			$profit     = (float) $order->get_meta( '_wru_total_reseller_profit' );
		}

		// Prepare courier note copy text using recipient customer details
		$customer_name = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
		$phone         = $order->get_shipping_phone() ?: $order->get_billing_phone();
		$address       = ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) . ( ( $order->get_shipping_city() ?: $order->get_billing_city() ) ? ', ' . ( $order->get_shipping_city() ?: $order->get_billing_city() ) : '' );
		$item_names    = array();
		foreach ( $order->get_items() as $item ) {
			$item_names[] = $item->get_name() . ' x ' . $item->get_quantity();
		}
		$items_str = implode( ', ', $item_names );

		// Resolve Reseller Sender details for packaging slip & metabox display
		$reseller_id      = $order->get_customer_id();
		$reseller_company = $order->get_meta( '_wru_reseller_company_name' );
		$reseller_phone   = $order->get_meta( '_wru_reseller_phone' );

		if ( empty( $reseller_company ) && $order->get_billing_company() ) {
			$reseller_company = $order->get_billing_company();
		}
		if ( empty( $reseller_company ) && $reseller_id ) {
			$reseller_company = get_user_meta( $reseller_id, '_wru_reseller_company_name', true ) ?: get_user_meta( $reseller_id, 'billing_company', true );
			if ( empty( $reseller_company ) ) {
				$user_obj = get_userdata( $reseller_id );
				if ( $user_obj ) {
					$reseller_company = $user_obj->display_name;
				}
			}
		}

		if ( empty( $reseller_phone ) && $reseller_id ) {
			$reseller_phone = get_user_meta( $reseller_id, '_wru_reseller_phone', true ) ?: get_user_meta( $reseller_id, '_wru_payout_number', true );
		}

		$print_invoice_url = WRU_Invoice_Email::get_invoice_url( $order->get_id() );

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
			<!-- Prominent Packaging Slip / Label Print & Download Bar -->
			<div class="wru-admin-invoice-cta" style="margin-bottom: 20px; padding: 16px 20px; background: #f0fdf4; border: 2px solid #86efac; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
				<div>
					<div style="font-size: 15px; font-weight: 800; color: #166534; margin-bottom: 2px;">
						<?php esc_html_e( 'প্যাকেজিং স্লিপ ও কুরিয়ার লেবেল (প্রিন্ট ও ডাউনলোড)', 'woocommerce-resell-utility' ); ?>
					</div>
					<div style="font-size: 13px; color: #374151;">
						<?php printf( esc_html__( 'লেবেলে প্রেরক হিসেবে থাকবে: %s', 'woocommerce-resell-utility' ), '<strong>' . esc_html( $reseller_company ?: __( 'রিসেলার শপ', 'woocommerce-resell-utility' ) ) . '</strong>' ); ?>
						<?php if ( ! empty( $reseller_phone ) ) : ?>
							<span> (<?php echo esc_html( $reseller_phone ); ?>)</span>
						<?php endif; ?>
					</div>
				</div>
				<div>
					<a href="<?php echo esc_url( $print_invoice_url ); ?>" target="_blank" class="button button-primary" style="background: #16a34a; border-color: #15803d; font-weight: 700; font-size: 14px; padding: 6px 18px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
						<?php esc_html_e( 'প্যাকেজিং স্লিপ প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
					</a>
				</div>
			</div>

			<!-- Reseller Brand Info on Packaging Slip Editor -->
			<div class="wru-admin-reseller-brand-edit" style="margin-bottom: 20px; padding: 14px 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px;">
				<div style="font-weight: 700; color: #1e293b; font-size: 13px; margin-bottom: 8px;">
					<?php esc_html_e( 'লেবেলের প্রেরক তথ্য (রিসেলারের শপ ও মোবাইল পরিবর্তন করতে পারেন):', 'woocommerce-resell-utility' ); ?>
				</div>
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
					<div>
						<label style="font-size: 12px; color: #475569; display: block; margin-bottom: 3px;"><?php esc_html_e( 'শপ / কোম্পানির নাম:', 'woocommerce-resell-utility' ); ?></label>
						<input type="text" name="wru_reseller_company_name" value="<?php echo esc_attr( $reseller_company ); ?>" class="widefat" placeholder="যেমন: Fashion Hub BD" />
					</div>
					<div>
						<label style="font-size: 12px; color: #475569; display: block; margin-bottom: 3px;"><?php esc_html_e( 'শপ হটলাইন / ফোন:', 'woocommerce-resell-utility' ); ?></label>
						<input type="text" name="wru_reseller_phone" value="<?php echo esc_attr( $reseller_phone ); ?>" class="widefat" placeholder="যেমন: 017XXXXXXXX" />
					</div>
				</div>
			</div>
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

			<?php
			$order_status = $order->get_status();
			if ( in_array( $order_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) :
				$breakdown  = self::get_order_cancellation_breakdown( $order );
				$order_loss = $breakdown['total_loss'];
			?>
				<div class="wru-order-cancel-alert" style="margin-top: 15px; padding: 12px 16px; background: <?php echo $order_loss > 0 ? '#fef2f2' : '#f8fafc'; ?>; border: 1.5px solid <?php echo $order_loss > 0 ? '#fca5a5' : '#cbd5e1'; ?>; border-radius: 8px; color: <?php echo $order_loss > 0 ? '#991b1b' : '#334155'; ?>;">
					<strong>
						<?php if ( $order_loss > 0 ) : ?>
							<?php printf( esc_html__( 'অর্ডার স্ট্যাটাস (%s) - রিসেলার ব্যালেন্স হতে মোট কর্তন: %s', 'woocommerce-resell-utility' ), esc_html( wc_get_order_status_name( $order_status ) ), '-' . wc_price( $order_loss ) ); ?>
						<?php else : ?>
							<?php printf( esc_html__( 'অর্ডার স্ট্যাটাস (%s) - রিসেলারের কোনো ফি কর্তন হয়নি (৳০)', 'woocommerce-resell-utility' ), esc_html( wc_get_order_status_name( $order_status ) ) ); ?>
						<?php endif; ?>
					</strong>
					<div style="font-size: 12px; margin-top: 4px; color: <?php echo $order_loss > 0 ? '#7f1d1d' : '#64748b'; ?>;">
						<?php echo esc_html( $breakdown['reason'] ); ?>
						<?php if ( $order_loss > 0 && $breakdown['shipping_fee'] > 0 ) : ?>
							<br><?php printf( esc_html__( 'বিস্তারিত: প্যাকেজিং ফি (%s) + জরিমানা (%s) + ডেলিভারি ফি (%s)', 'woocommerce-resell-utility' ), wc_price( $breakdown['packaging_fee'] ), wc_price( $breakdown['penalty_fee'] ), wc_price( $breakdown['shipping_fee'] ) ); ?>
						<?php elseif ( $order_loss > 0 ) : ?>
							<br><?php printf( esc_html__( 'বিস্তারিত: শুধুমাত্র প্যাকেজিং খরচ (%s)', 'woocommerce-resell-utility' ), wc_price( $breakdown['packaging_fee'] ) ); ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

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

		$collection   = (float) $order->get_meta( '_wru_total_collection_amount' );
		$wholesale    = (float) $order->get_meta( '_wru_total_wholesale_amount' );
		$packaging    = (float) $order->get_meta( '_wru_total_packaging_fee' );
		$profit       = (float) $order->get_meta( '_wru_total_reseller_profit' );
		$order_status = $order->get_status();
		$is_cancelled = in_array( $order_status, array( 'cancelled', 'failed', 'refunded' ), true );
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
				<?php if ( $is_cancelled ) : 
					$breakdown  = self::get_order_cancellation_breakdown( $order );
					$order_loss = $breakdown['total_loss'];
				?>
					<div class="wru-order-profit-item wru-profit-highlight" style="background: <?php echo $order_loss > 0 ? '#fef2f2' : '#f8fafc'; ?>; border-color: <?php echo $order_loss > 0 ? '#fca5a5' : '#cbd5e1'; ?>;">
						<span class="wru-label" style="color: <?php echo $order_loss > 0 ? '#991b1b' : '#334155'; ?>;"><?php esc_html_e( 'অর্ডার কর্তন (Loss):', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-val" style="color: <?php echo $order_loss > 0 ? '#dc2626' : '#64748b'; ?>;"><?php echo $order_loss > 0 ? '-' . wc_price( $order_loss ) : wc_price( 0 ); ?></strong>
					</div>
				<?php else : ?>
					<div class="wru-order-profit-item wru-profit-highlight">
						<span class="wru-label"><?php esc_html_e( 'আপনার নিট লাভ (Profit):', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-val wru-profit-number"><?php echo wc_price( $profit ); ?></strong>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $is_cancelled ) : ?>
				<p class="wru-order-note" style="color: <?php echo $order_loss > 0 ? '#dc2626' : '#64748b'; ?>;">
					<em><?php echo esc_html( $breakdown['reason'] ); ?></em>
				</p>
			<?php else : ?>
				<p class="wru-order-note">
					<em><?php esc_html_e( 'নোট: কাস্টমারকে পার্সেলটি ডেলিভারি করে কুরিয়ার হতে টাকা সংগ্রহের পর আপনার নিট লাভ আপনার একাউন্টে জমা হবে।', 'woocommerce-resell-utility' ); ?></em>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save admin edited reseller company name & phone when saving order in WP-Admin.
	 *
	 * @param int           $order_id Order ID.
	 * @param \WP_Post|null $post Post object if legacy CPT.
	 */
	public function save_admin_order_reseller_meta( $order_id, $post = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( isset( $_POST['wru_reseller_company_name'] ) ) {
			$company = sanitize_text_field( wp_unslash( $_POST['wru_reseller_company_name'] ) );
			$order->update_meta_data( '_wru_reseller_company_name', $company );
		}

		if ( isset( $_POST['wru_reseller_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['wru_reseller_phone'] ) );
			$order->update_meta_data( '_wru_reseller_phone', $phone );
		}

		$order->save();
	}

	/**
	 * Add custom columns to WooCommerce Orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_list_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key || 'order_number' === $key ) {
				$new_columns['wru_reseller_brand'] = __( 'রিসেলার শপ', 'woocommerce-resell-utility' );
				$new_columns['wru_cod_amount']     = __( 'COD কালেকশন', 'woocommerce-resell-utility' );
			}
		}
		if ( ! isset( $new_columns['wru_reseller_brand'] ) ) {
			$new_columns['wru_reseller_brand'] = __( 'রিসেলার শপ', 'woocommerce-resell-utility' );
			$new_columns['wru_cod_amount']     = __( 'COD কালেকশন', 'woocommerce-resell-utility' );
		}
		return $new_columns;
	}

	/**
	 * Render custom column content in WooCommerce Orders list.
	 *
	 * @param string        $column           Column key.
	 * @param int|\WC_Order $post_or_order_id Post ID or Order object.
	 */
	public function render_order_list_column_content( $column, $post_or_order_id ) {
		$order = is_a( $post_or_order_id, 'WC_Order' ) ? $post_or_order_id : wc_get_order( $post_or_order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'wru_reseller_brand' === $column ) {
			$company = $order->get_meta( '_wru_reseller_company_name' ) ?: $order->get_billing_company();
			if ( empty( $company ) ) {
				$uid = $order->get_customer_id();
				if ( $uid ) {
					$company = get_user_meta( $uid, '_wru_reseller_company_name', true );
				}
			}
			if ( ! empty( $company ) ) {
				echo '<strong style="color: #111827; font-size: 13px;">' . esc_html( $company ) . '</strong>';
			} else {
				echo '<span style="color: #9ca3af;">—</span>';
			}
		} elseif ( 'wru_cod_amount' === $column ) {
			$cod = (float) $order->get_meta( '_wru_total_collection_amount' );
			if ( $cod > 0 ) {
				echo '<strong style="color: #16a34a; font-size: 13px;">' . wc_price( $cod ) . '</strong>';
			} else {
				echo '<span style="color: #9ca3af;">—</span>';
			}
		}
	}
}
