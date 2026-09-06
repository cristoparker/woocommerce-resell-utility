<?php
/**
 * Invoice Generator & Order Email Integration.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Invoice_Email {

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
		// Include dropshipping & courier collection details in WooCommerce order emails.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_order_meta' ), 20, 4 );

		// Printable invoice / packing slip template handler.
		add_action( 'template_redirect', array( $this, 'handle_invoice_print_request' ) );

		// Add "Print Invoice / Packing Slip" button in admin order actions.
		add_action( 'woocommerce_admin_order_actions_end', array( $this, 'add_admin_order_action_button' ) );

		// Add "Print Invoice" button inside order details on frontend.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_frontend_print_button' ), 25 );
	}

	/**
	 * Render dropshipping collection details in WooCommerce order emails.
	 *
	 * @param \WC_Order $order         Order object.
	 * @param bool      $sent_to_admin Whether sent to site admin.
	 * @param bool      $plain_text    Whether plain text email.
	 * @param \WC_Email $email         Email object.
	 */
	public function render_email_order_meta( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( ! $order ) {
			return;
		}

		$is_resell_order = 'yes' === $order->get_meta( '_wru_is_resell_order' );
		if ( ! $is_resell_order ) {
			return;
		}

		$collection  = (float) $order->get_meta( '_wru_total_collection_amount' );
		$wholesale   = (float) $order->get_meta( '_wru_total_wholesale_amount' );
		$packaging   = (float) $order->get_meta( '_wru_total_packaging_fee' );
		$profit      = (float) $order->get_meta( '_wru_total_reseller_profit' );
		$items_total = (float) $order->get_meta( '_wru_customer_items_total' );
		$shipping    = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();

		if ( $collection <= 0 ) {
			$collection = ( $items_total > 0 ? $items_total : (float) $order->get_total() ) + $shipping;
		}

		if ( $plain_text ) {
			echo "\n=================================================\n";
			echo esc_html__( 'ড্রপশিপিং ও কুরিয়ার কালেকশন সামারি', 'woocommerce-resell-utility' ) . "\n";
			echo "-------------------------------------------------\n";
			printf( esc_html__( "কুরিয়ার কালেকশন (COD: বিক্রয়মূল্য + ডেলিভারি): %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $collection ) ) );
			printf( esc_html__( "কাস্টমার পণ্য বিক্রয়মূল্য: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $items_total ) ) );
			printf( esc_html__( "কুরিয়ার ডেলিভারি চার্জ: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $shipping ) ) );
			printf( esc_html__( "পণ্যের পাইকারি মূল্য: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $wholesale ) ) );
			printf( esc_html__( "প্যাকেজিং খরচ: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $packaging ) ) );
			printf( esc_html__( "রিসেলার নিট লাভ: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $profit ) ) );
			printf( esc_html__( "প্যাকেজিং স্লিপ প্রিন্ট লিংক: %s\n", 'woocommerce-resell-utility' ), esc_url( self::get_invoice_url( $order->get_id() ) ) );
			echo "=================================================\n\n";
			return;
		}
		?>
		<div style="margin: 24px 0; padding: 16px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; font-family: sans-serif;">
			<h3 style="margin: 0 0 12px 0; font-size: 16px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
				<?php esc_html_e( 'ড্রপশিপিং ও কুরিয়ার কালেকশন হিসাব', 'woocommerce-resell-utility' ); ?>
			</h3>
			<table style="width: 100%; border-collapse: collapse; font-size: 14px; color: #334155;">
				<tr style="background: #f0fdf4;">
					<td style="padding: 8px 6px; font-weight: bold; color: #065f46;"><strong><?php esc_html_e( 'কুরিয়ার কালেকশন (COD: বিক্রয়মূল্য + ডেলিভারি):', 'woocommerce-resell-utility' ); ?></strong></td>
					<td style="padding: 8px 6px; text-align: right; font-weight: 800; color: #047857; font-size: 16px;"><?php echo wc_price( $collection ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 6px;"><?php esc_html_e( 'কাস্টমার পণ্য বিক্রয়মূল্য:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 6px; text-align: right;"><?php echo wc_price( $items_total ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 6px;"><?php esc_html_e( 'কুরিয়ার ডেলিভারি চার্জ:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 6px; text-align: right;"><?php echo wc_price( $shipping ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 6px;"><?php esc_html_e( 'পণ্যের পাইকারি মূল্য:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 6px; text-align: right;"><?php echo wc_price( $wholesale ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 6px;"><?php esc_html_e( 'প্যাকেজিং খরচ:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 6px; text-align: right;"><?php echo wc_price( $packaging ); ?></td>
				</tr>
				<tr style="border-top: 1px dashed #94a3b8;">
					<td style="padding: 8px 6px; font-weight: bold; color: #16a34a;"><?php esc_html_e( 'রিসেলার নিট লাভ (Profit):', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 8px 6px; text-align: right; font-weight: bold; color: #16a34a; font-size: 15px;"><?php echo wc_price( $profit ); ?></td>
				</tr>
			</table>
			<div style="margin-top: 14px; text-align: center;">
				<a href="<?php echo esc_url( self::get_invoice_url( $order->get_id() ) ); ?>" target="_blank" style="display: inline-block; padding: 9px 18px; background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 13px;">
					<?php esc_html_e( 'প্যাকেজিং স্লিপ / ইনভয়েস প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Get print invoice URL for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_invoice_url( $order_id ) {
		return add_query_arg( array(
			'wru_action' => 'print_invoice',
			'order_id'   => $order_id,
			'nonce'      => wp_create_nonce( 'wru_print_invoice_' . $order_id ),
		), home_url( '/' ) );
	}

	/**
	 * Add print invoice button inside admin order list.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function add_admin_order_action_button( $order ) {
		if ( ! $order ) {
			return;
		}
		$url = self::get_invoice_url( $order->get_id() );
		?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button wc-action-button" title="<?php esc_attr_e( 'প্যাকেজিং স্লিপ / ইনভয়েস প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>" style="font-weight: 600;">
			<?php esc_html_e( 'প্যাকেজিং লেবেল', 'woocommerce-resell-utility' ); ?>
		</a>
		<?php
	}

	/**
	 * Render print button on frontend order received / view order screen.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function render_frontend_print_button( $order ) {
		if ( ! $order ) {
			return;
		}
		$url = self::get_invoice_url( $order->get_id() );
		?>
		<div style="margin: 16px 0; text-align: right;">
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button wru-print-btn" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 6px; background: #0f172a; color: #ffffff; text-decoration: none; font-weight: 600;">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
				<?php esc_html_e( 'প্যাকেজিং স্লিপ প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Handle print invoice request and render clean, print-ready HTML page.
	 */
	public function handle_invoice_print_request() {
		if ( ! isset( $_GET['wru_action'] ) || 'print_invoice' !== $_GET['wru_action'] ) {
			return;
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'wru_print_invoice_' . $order_id ) ) {
			wp_die( esc_html__( 'অননুমোদিত অনুরোধ। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'অর্ডার পাওয়া যায়নি।', 'woocommerce-resell-utility' ) );
		}

		// Check permission: Admin or order owner.
		$current_user_id = get_current_user_id();
		$order_user_id   = $order->get_customer_id();

		if ( ! current_user_can( 'manage_woocommerce' ) && ( 0 === $order_user_id || $current_user_id !== $order_user_id ) ) {
			wp_die( esc_html__( 'আপনার এই ইনভয়েস দেখার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		// Resolve Reseller Sender Details (Reseller Name / Company Name, NOT Khushir Baksho)
		$is_resell_order  = 'yes' === $order->get_meta( '_wru_is_resell_order' );
		$sender_company   = $order->get_meta( '_wru_reseller_company_name' );
		$sender_phone     = $order->get_meta( '_wru_reseller_phone' );

		if ( empty( $sender_company ) && $order->get_billing_company() ) {
			$sender_company = $order->get_billing_company();
		}

		if ( empty( $sender_company ) && $order_user_id ) {
			$sender_company = get_user_meta( $order_user_id, '_wru_reseller_company_name', true );
			if ( empty( $sender_company ) ) {
				$sender_company = get_user_meta( $order_user_id, 'billing_company', true );
			}
			if ( empty( $sender_company ) ) {
				$reseller_user = get_userdata( $order_user_id );
				if ( $reseller_user ) {
					$sender_company = $reseller_user->display_name;
				}
			}
		}

		if ( empty( $sender_phone ) && $order_user_id ) {
			$sender_phone = get_user_meta( $order_user_id, '_wru_reseller_phone', true );
			if ( empty( $sender_phone ) ) {
				$sender_phone = get_user_meta( $order_user_id, '_wru_payout_number', true );
			}
		}

		// Fallback for company name if still empty
		if ( empty( $sender_company ) ) {
			if ( $is_resell_order ) {
				$reseller_user  = get_userdata( $order_user_id );
				$sender_company = $reseller_user ? $reseller_user->display_name : __( 'রিসেলার শপ', 'woocommerce-resell-utility' );
			} else {
				$sender_company = get_option( 'wru_invoice_store_name', get_bloginfo( 'name' ) );
				$sender_phone   = get_option( 'wru_invoice_store_phone', '' );
			}
		}

		$inv_note = get_option( 'wru_invoice_footer_note', __( 'ডেলিভারির সময় পার্সেল চেক করে টাকা পরিশোধ করুন।', 'woocommerce-resell-utility' ) );

		$collection  = (float) $order->get_meta( '_wru_total_collection_amount' );
		$shipping    = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$items_total = (float) $order->get_meta( '_wru_customer_items_total' );

		if ( $collection <= 0 ) {
			$calc_items_total = 0.0;
			foreach ( $order->get_items() as $item ) {
				$res_price = $item->get_meta( '_wru_reseller_price', true );
				$qty       = (int) $item->get_quantity();
				if ( '' !== $res_price && false !== $res_price && (float) $res_price > 0 ) {
					$calc_items_total += ( (float) $res_price * $qty );
				} else {
					$prod             = $item->get_product();
					$p_price          = $prod ? ( (float) $prod->get_regular_price() ?: (float) $prod->get_price() ) : (float) $item->get_subtotal() / max( 1, $qty );
					$calc_items_total += ( $p_price * $qty );
				}
			}
			$items_total = $calc_items_total;
			$collection  = $items_total + $shipping;
		}

		$customer_name    = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
		$customer_phone   = $order->get_shipping_phone() ?: $order->get_billing_phone();
		$customer_address = ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) . ( ( $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) ? ', ' . ( $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) : '' );
		$customer_city    = $order->get_shipping_city() ?: $order->get_billing_city();
		$customer_postcode= $order->get_shipping_postcode() ?: $order->get_billing_postcode();

		// Output standalone print-ready HTML
		?>
		<!DOCTYPE html>
		<html lang="bn">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php printf( esc_html__( 'প্যাকেজিং লেবেল ও ইনভয়েস #%s - %s', 'woocommerce-resell-utility' ), esc_html( $order_id ), esc_html( $sender_company ) ); ?></title>
			<style>
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hind Siliguri", Arial, sans-serif;
					color: #0f172a;
					background: #f1f5f9;
					margin: 0;
					padding: 20px;
					font-size: 14px;
					line-height: 1.5;
				}
				.print-bar {
					max-width: 720px;
					margin: 0 auto 16px auto;
					display: flex;
					justify-content: space-between;
					align-items: center;
					background: #0f172a;
					color: #ffffff;
					padding: 12px 20px;
					border-radius: 8px;
					box-shadow: 0 4px 10px rgba(0,0,0,0.1);
				}
				.print-bar-info {
					font-size: 13px;
					font-weight: 500;
				}
				.print-actions {
					display: flex;
					gap: 10px;
					align-items: center;
				}
				.print-btn {
					background: #16a34a;
					color: #ffffff;
					border: none;
					padding: 8px 18px;
					border-radius: 6px;
					font-size: 14px;
					font-weight: 700;
					cursor: pointer;
					display: inline-flex;
					align-items: center;
					gap: 6px;
					transition: background 0.2s;
				}
				.print-btn:hover {
					background: #15803d;
				}
				
				/* Packaging Label / Slip Container */
				.packing-slip-wrapper {
					max-width: 720px;
					margin: 0 auto;
					background: #ffffff;
					border: 2px dashed #94a3b8;
					border-radius: 12px;
					padding: 30px;
					box-shadow: 0 4px 15px rgba(0,0,0,0.06);
					position: relative;
				}
				.cut-line-badge {
					position: absolute;
					top: -11px;
					left: 24px;
					background: #ffffff;
					padding: 0 10px;
					color: #64748b;
					font-size: 11px;
					font-weight: 600;
					letter-spacing: 0.05em;
					text-transform: uppercase;
				}
				.slip-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					border-bottom: 2px solid #0f172a;
					padding-bottom: 16px;
					margin-bottom: 20px;
				}
				.reseller-brand-name {
					font-size: 24px;
					font-weight: 900;
					margin: 0 0 4px 0;
					color: #0f172a;
					letter-spacing: -0.02em;
				}
				.reseller-contact {
					font-size: 13px;
					color: #475569;
					margin: 0;
					font-weight: 600;
				}
				.order-meta-box {
					text-align: right;
				}
				.order-meta-title {
					font-size: 18px;
					font-weight: 800;
					color: #0f172a;
					margin: 0 0 4px 0;
				}
				.order-meta-date {
					font-size: 12px;
					color: #64748b;
					margin: 0;
				}

				/* 2-Column Delivery Grid: Sender & Recipient */
				.delivery-grid {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 16px;
					margin-bottom: 20px;
				}
				.delivery-box {
					border: 1.5px solid #cbd5e1;
					border-radius: 8px;
					padding: 14px 16px;
					background: #f8fafc;
				}
				.delivery-box-title {
					font-size: 11px;
					text-transform: uppercase;
					font-weight: 800;
					letter-spacing: 0.05em;
					color: #64748b;
					margin: 0 0 8px 0;
					border-bottom: 1px solid #e2e8f0;
					padding-bottom: 4px;
				}
				.delivery-name {
					font-size: 16px;
					font-weight: 800;
					color: #0f172a;
					margin: 0 0 4px 0;
				}
				.delivery-phone {
					font-size: 14px;
					font-weight: 700;
					color: #0f172a;
					margin: 0 0 6px 0;
				}
				.delivery-address {
					font-size: 13px;
					color: #334155;
					margin: 0;
					line-height: 1.4;
				}

				/* Prominent Courier Cash Collection (COD) Box */
				.cod-collection-card {
					background: #0f172a;
					color: #ffffff;
					border-radius: 8px;
					padding: 16px 20px;
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin-bottom: 20px;
				}
				.cod-label-title {
					font-size: 14px;
					font-weight: 700;
					margin: 0 0 2px 0;
					color: #f8fafc;
				}
				.cod-label-sub {
					font-size: 11px;
					color: #94a3b8;
					margin: 0;
				}
				.cod-amount-badge {
					font-size: 26px;
					font-weight: 900;
					color: #4ade80;
					letter-spacing: -0.02em;
				}

				/* Products Table (Customer-safe: NO wholesale or profit displayed) */
				.products-table {
					width: 100%;
					border-collapse: collapse;
					margin-bottom: 20px;
				}
				.products-table th {
					background: #f1f5f9;
					border-top: 1px solid #cbd5e1;
					border-bottom: 1px solid #cbd5e1;
					padding: 10px 12px;
					text-align: left;
					font-size: 12px;
					font-weight: 800;
					color: #475569;
					text-transform: uppercase;
				}
				.products-table td {
					padding: 10px 12px;
					border-bottom: 1px solid #f1f5f9;
					font-size: 13px;
				}
				.products-table td.text-right, .products-table th.text-right {
					text-align: right;
				}
				.product-title {
					font-weight: 700;
					color: #0f172a;
				}

				/* Slip Footer */
				.slip-footer {
					text-align: center;
					border-top: 1px dashed #cbd5e1;
					padding-top: 14px;
					font-size: 12px;
					color: #64748b;
				}
				.slip-footer p {
					margin: 0 0 4px 0;
				}

				/* Print Styles */
				@media print {
					body {
						background: #ffffff !important;
						padding: 0 !important;
						margin: 0 !important;
					}
					.print-bar {
						display: none !important;
					}
					.packing-slip-wrapper {
						box-shadow: none !important;
						border: 2px dashed #000000 !important;
						border-radius: 0 !important;
						padding: 20px !important;
						max-width: 100% !important;
						width: 100% !important;
					}
					.cut-line-badge {
						display: none !important;
					}
					.cod-collection-card {
						background: #000000 !important;
						color: #ffffff !important;
						-webkit-print-color-adjust: exact;
						print-color-adjust: exact;
					}
					.cod-amount-badge {
						color: #ffffff !important;
					}
				}
			</style>
		</head>
		<body>

			<!-- Top Print Action Bar (Hidden during print) -->
			<div class="print-bar">
				<div class="print-bar-info">
					<strong><?php esc_html_e( 'প্যাকেজিং লেবেল প্রিন্টার', 'woocommerce-resell-utility' ); ?></strong>
					<span style="opacity: 0.7; margin-left: 8px;"><?php esc_html_e( '(কুরিয়ার পার্সেল বক্সে লাগানোর জন্য প্রিন্ট করুন)', 'woocommerce-resell-utility' ); ?></span>
				</div>
				<div class="print-actions">
					<button type="button" class="print-btn" onclick="window.print();">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
						<?php esc_html_e( 'প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
					</button>
				</div>
			</div>

			<!-- Packaging Slip / Label Body -->
			<div class="packing-slip-wrapper">
				<div class="cut-line-badge"><?php esc_html_e( 'প্যাকেজিং লেবেল (পার্সেল বক্সে সংযুক্ত করুন)', 'woocommerce-resell-utility' ); ?></div>

				<!-- Header: Reseller Brand & Order Meta -->
				<div class="slip-header">
					<div>
						<!-- Displays Reseller's Shop / Company Name -->
						<h1 class="reseller-brand-name"><?php echo esc_html( $sender_company ); ?></h1>
						<?php if ( ! empty( $sender_phone ) ) : ?>
							<p class="reseller-contact"><?php printf( esc_html__( 'হটলাইন / মোবাইল: %s', 'woocommerce-resell-utility' ), esc_html( $sender_phone ) ); ?></p>
						<?php endif; ?>
					</div>
					<div class="order-meta-box">
						<div class="order-meta-title"><?php printf( esc_html__( 'পার্সেল #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></div>
						<p class="order-meta-date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
						<p class="order-meta-date">
							<strong><?php echo esc_html( $order->get_shipping_method() ?: __( 'কুরিয়ার হোম ডেলিভারি', 'woocommerce-resell-utility' ) ); ?></strong>
						</p>
					</div>
				</div>

				<!-- 2-Column Delivery Info -->
				<div class="delivery-grid">
					<!-- Sender Box (Reseller Shop) -->
					<div class="delivery-box">
						<div class="delivery-box-title"><?php esc_html_e( 'প্রেরক (From / Seller):', 'woocommerce-resell-utility' ); ?></div>
						<div class="delivery-name"><?php echo esc_html( $sender_company ); ?></div>
						<?php if ( ! empty( $sender_phone ) ) : ?>
							<div class="delivery-phone"><?php printf( esc_html__( 'মোবাইল: %s', 'woocommerce-resell-utility' ), esc_html( $sender_phone ) ); ?></div>
						<?php endif; ?>
						<p class="delivery-address"><?php esc_html_e( 'অনলাইন ড্রপশিপিং ও পার্সেল সার্ভিস', 'woocommerce-resell-utility' ); ?></p>
					</div>

					<!-- Recipient Box (Customer) -->
					<div class="delivery-box" style="border-color: #0f172a; background: #ffffff;">
						<div class="delivery-box-title" style="color: #0f172a;"><?php esc_html_e( 'প্রাপক (Deliver To / Customer):', 'woocommerce-resell-utility' ); ?></div>
						<div class="delivery-name"><?php echo esc_html( $customer_name ); ?></div>
						<div class="delivery-phone" style="font-size: 15px; color: #0284c7;">
							<?php printf( esc_html__( 'মোবাইল: %s', 'woocommerce-resell-utility' ), esc_html( $customer_phone ) ); ?>
						</div>
						<p class="delivery-address">
							<?php echo esc_html( $customer_address ); ?>
							<?php if ( $customer_city ) : ?>
								<br><strong><?php echo esc_html( $customer_city ); ?><?php echo $customer_postcode ? ' - ' . esc_html( $customer_postcode ) : ''; ?></strong>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<!-- High-Visibility Courier Cash on Delivery (COD) Collection Banner -->
				<div class="cod-collection-card">
					<div>
						<div class="cod-label-title"><?php esc_html_e( 'কুরিয়ার কালেকশন (Cash on Delivery - COD):', 'woocommerce-resell-utility' ); ?></div>
						<div class="cod-label-sub">
							<?php esc_html_e( 'কাস্টমার হতে এই পরিমাণ টাকা ডেলিভারির সময় বুঝে নিবেন।', 'woocommerce-resell-utility' ); ?>
						</div>
					</div>
					<div class="cod-amount-badge">
						<?php echo wc_price( $collection ); ?>
					</div>
				</div>

				<!-- Parcel Contents / Products Table (Clean, no wholesale or profit prices) -->
				<table class="products-table">
					<thead>
						<tr>
							<th style="width: 75%;"><?php esc_html_e( 'পার্সেল পণ্যের বিবরণ (Package Contents)', 'woocommerce-resell-utility' ); ?></th>
							<th class="text-right" style="width: 25%;"><?php esc_html_e( 'পরিমাণ (Qty)', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order->get_items() as $item ) : ?>
							<tr>
								<td>
									<span class="product-title"><?php echo esc_html( $item->get_name() ); ?></span>
								</td>
								<td class="text-right">
									<strong><?php echo esc_html( $item->get_quantity() ); ?> টি</strong>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Footer Note -->
				<div class="slip-footer">
					<p><strong><?php echo esc_html( $inv_note ); ?></strong></p>
					<p style="font-size: 11px; opacity: 0.7;"><?php printf( esc_html__( 'কুরিয়ার ট্র্যাকিং ও ডেলিভারি পার্সেল স্লিপ | অর্ডার #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></p>
				</div>
			</div>

		</body>
		</html>
		<?php
		exit;
	}
}
