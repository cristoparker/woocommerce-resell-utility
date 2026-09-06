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

		$collection = (float) $order->get_meta( '_wru_total_collection_amount' );
		$wholesale  = (float) $order->get_meta( '_wru_total_wholesale_amount' );
		$packaging  = (float) $order->get_meta( '_wru_total_packaging_fee' );
		$profit     = (float) $order->get_meta( '_wru_total_reseller_profit' );

		if ( $plain_text ) {
			echo "\n=================================================\n";
			echo esc_html__( 'ড্রপশিপিং ও কুরিয়ার কালেকশন সামারি', 'woocommerce-resell-utility' ) . "\n";
			echo "-------------------------------------------------\n";
			printf( esc_html__( "কুরিয়ার কালেকশন (COD): %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $collection ) ) );
			printf( esc_html__( "পণ্যের পাইকারি মূল্য: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $wholesale ) ) );
			printf( esc_html__( "প্যাকেজিং খরচ: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $packaging ) ) );
			printf( esc_html__( "রিসেলার নিট লাভ: %s\n", 'woocommerce-resell-utility' ), wp_strip_all_tags( wc_price( $profit ) ) );
			printf( esc_html__( "ইনভয়েস / প্যাকিং স্লিপ প্রিন্ট লিংক: %s\n", 'woocommerce-resell-utility' ), esc_url( self::get_invoice_url( $order->get_id() ) ) );
			echo "=================================================\n\n";
			return;
		}
		?>
		<div style="margin: 24px 0; padding: 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-family: sans-serif;">
			<h3 style="margin: 0 0 12px 0; font-size: 16px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
				<?php esc_html_e( 'ড্রপশিপিং ও কুরিয়ার কালেকশন হিসাব', 'woocommerce-resell-utility' ); ?>
			</h3>
			<table style="width: 100%; border-collapse: collapse; font-size: 14px; color: #334155;">
				<tr>
					<td style="padding: 6px 0;"><strong><?php esc_html_e( 'কুরিয়ার কালেকশন (COD Amount):', 'woocommerce-resell-utility' ); ?></strong></td>
					<td style="padding: 6px 0; text-align: right; font-weight: bold; color: #0f172a;"><?php echo wc_price( $collection ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 0;"><?php esc_html_e( 'পণ্যের পাইকারি মূল্য:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 0; text-align: right;"><?php echo wc_price( $wholesale ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 0;"><?php esc_html_e( 'প্যাকেজিং খরচ:', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 6px 0; text-align: right;"><?php echo wc_price( $packaging ); ?></td>
				</tr>
				<tr style="border-top: 1px dashed #94a3b8;">
					<td style="padding: 8px 0; font-weight: bold; color: #16a34a;"><?php esc_html_e( 'রিসেলার নিট লাভ (Profit):', 'woocommerce-resell-utility' ); ?></td>
					<td style="padding: 8px 0; text-align: right; font-weight: bold; color: #16a34a; font-size: 15px;"><?php echo wc_price( $profit ); ?></td>
				</tr>
			</table>
			<div style="margin-top: 14px; text-align: center;">
				<a href="<?php echo esc_url( self::get_invoice_url( $order->get_id() ) ); ?>" target="_blank" style="display: inline-block; padding: 9px 18px; background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 13px;">
					<?php esc_html_e( 'ইনভয়েস ও প্যাকিং স্লিপ দেখুন / প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
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
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button wc-action-button" title="<?php esc_attr_e( 'ইনভয়েস / প্যাকিং স্লিপ প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>">
			<?php esc_html_e( 'ইনভয়েস', 'woocommerce-resell-utility' ); ?>
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
				<?php esc_html_e( 'ইনভয়েস ও প্যাকিং স্লিপ প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
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

		$store_name  = get_option( 'wru_invoice_store_name', get_bloginfo( 'name' ) );
		$store_phone = get_option( 'wru_invoice_store_phone', '' );
		$inv_note    = get_option( 'wru_invoice_footer_note', __( 'ডেলিভারির সময় পার্সেল চেক করে টাকা পরিশোধ করুন।', 'woocommerce-resell-utility' ) );

		$collection = (float) $order->get_meta( '_wru_total_collection_amount' );
		if ( $collection <= 0 ) {
			$collection = (float) $order->get_total();
		}

		$customer_name    = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
		$customer_phone   = $order->get_billing_phone();
		$customer_address = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
		$customer_city    = $order->get_shipping_city() ?: $order->get_billing_city();

		// Output standalone print-ready HTML
		?>
		<!DOCTYPE html>
		<html lang="bn">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php printf( esc_html__( 'ইনভয়েস #%s - %s', 'woocommerce-resell-utility' ), esc_html( $order_id ), esc_html( $store_name ) ); ?></title>
			<style>
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
					color: #0f172a;
					background: #f1f5f9;
					margin: 0;
					padding: 20px;
					font-size: 14px;
					line-height: 1.5;
				}
				.invoice-card {
					background: #ffffff;
					max-width: 760px;
					margin: 0 auto;
					padding: 36px 40px;
					border-radius: 10px;
					box-shadow: 0 4px 15px rgba(0,0,0,0.05);
				}
				.invoice-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					border-bottom: 2px solid #e2e8f0;
					padding-bottom: 20px;
					margin-bottom: 24px;
				}
				.store-title { font-size: 22px; font-weight: 800; margin: 0 0 4px 0; color: #0284c7; }
				.store-sub { font-size: 13px; color: #64748b; margin: 0; }
				.invoice-meta { text-align: right; }
				.invoice-meta h2 { margin: 0 0 4px 0; font-size: 20px; color: #0f172a; }
				.invoice-meta p { margin: 0; color: #64748b; font-size: 13px; }
				.info-grid {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 24px;
					margin-bottom: 24px;
				}
				.info-box {
					background: #f8fafc;
					border: 1px solid #e2e8f0;
					border-radius: 8px;
					padding: 16px;
				}
				.info-box h4 { margin: 0 0 8px 0; font-size: 13px; text-transform: uppercase; color: #64748b; }
				.info-box p { margin: 0 0 4px 0; font-size: 14px; }
				.items-table {
					width: 100%;
					border-collapse: collapse;
					margin-bottom: 24px;
				}
				.items-table th, .items-table td {
					padding: 12px 14px;
					border-bottom: 1px solid #e2e8f0;
					text-align: left;
				}
				.items-table th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; }
				.items-table td.text-right, .items-table th.text-right { text-align: right; }
				.cod-banner {
					background: #ecfdf5;
					border: 2px solid #10b981;
					border-radius: 8px;
					padding: 16px 20px;
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin-bottom: 24px;
				}
				.cod-title { font-size: 15px; font-weight: 700; color: #065f46; margin: 0; }
				.cod-amount { font-size: 26px; font-weight: 900; color: #047857; margin: 0; }
				.invoice-footer {
					text-align: center;
					border-top: 1px solid #e2e8f0;
					padding-top: 18px;
					font-size: 13px;
					color: #64748b;
				}
				.print-bar {
					max-width: 760px;
					margin: 0 auto 16px auto;
					display: flex;
					justify-content: space-between;
					align-items: center;
				}
				.print-btn {
					background: #0284c7;
					color: #ffffff;
					border: none;
					padding: 10px 20px;
					border-radius: 6px;
					font-size: 14px;
					font-weight: 700;
					cursor: pointer;
				}
				@media print {
					body { background: #ffffff; padding: 0; }
					.invoice-card { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
					.print-bar { display: none !important; }
				}
			</style>
		</head>
		<body>
			<div class="print-bar">
				<span><?php esc_html_e( 'প্রিন্ট ভিউ (Printable Packing Slip)', 'woocommerce-resell-utility' ); ?></span>
				<button type="button" class="print-btn" onclick="window.print();"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 5px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg><?php esc_html_e( 'প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?></button>
			</div>

			<div class="invoice-card">
				<div class="invoice-header">
					<div>
						<h1 class="store-title"><?php echo esc_html( $store_name ); ?></h1>
						<?php if ( ! empty( $store_phone ) ) : ?>
							<p class="store-sub"><?php printf( esc_html__( 'হটলাইন: %s', 'woocommerce-resell-utility' ), esc_html( $store_phone ) ); ?></p>
						<?php endif; ?>
					</div>
					<div class="invoice-meta">
						<h2><?php printf( esc_html__( 'প্যাকিং স্লিপ #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></h2>
						<p><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
					</div>
				</div>

				<div class="info-grid">
					<div class="info-box">
						<h4><?php esc_html_e( 'কাস্টমার ও ডেলিভারি ঠিকানা:', 'woocommerce-resell-utility' ); ?></h4>
						<p><strong><?php echo esc_html( $customer_name ); ?></strong></p>
						<p><?php printf( esc_html__( 'মোবাইল: %s', 'woocommerce-resell-utility' ), esc_html( $customer_phone ) ); ?></p>
						<p><?php echo esc_html( $customer_address ); ?><?php echo $customer_city ? ', ' . esc_html( $customer_city ) : ''; ?></p>
					</div>
					<div class="info-box">
						<h4><?php esc_html_e( 'অর্ডার তথ্য:', 'woocommerce-resell-utility' ); ?></h4>
						<p><?php printf( esc_html__( 'অর্ডার নম্বর: #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></p>
						<p><?php printf( esc_html__( 'পেমেন্ট মেথড: %s', 'woocommerce-resell-utility' ), esc_html( $order->get_payment_method_title() ) ); ?></p>
						<p><?php printf( esc_html__( 'শিপিং মাধ্যম: %s', 'woocommerce-resell-utility' ), esc_html( $order->get_shipping_method() ?: __( 'কুরিয়ার ডেলিভারি', 'woocommerce-resell-utility' ) ) ); ?></p>
					</div>
				</div>

				<!-- Courier Cash on Delivery (COD) Banner -->
				<div class="cod-banner">
					<div>
						<p class="cod-title"><?php esc_html_e( 'কুরিয়ারে কাস্টমার থেকে কালেকশন করবেন (COD):', 'woocommerce-resell-utility' ); ?></p>
						<small style="color:#047857;"><?php esc_html_e( 'ডেলিভারির সময় এই নির্ধারিত টাকা কাস্টমার হতে গ্রহণ করুন', 'woocommerce-resell-utility' ); ?></small>
					</div>
					<div class="cod-amount"><?php echo wc_price( $collection ); ?></div>
				</div>

				<!-- Items Table -->
				<table class="items-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'পণ্য বিবরণী', 'woocommerce-resell-utility' ); ?></th>
							<th class="text-right"><?php esc_html_e( 'পরিমাণ', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order->get_items() as $item ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item->get_name() ); ?></strong>
								</td>
								<td class="text-right"><strong><?php echo esc_html( $item->get_quantity() ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="invoice-footer">
					<p><?php echo esc_html( $inv_note ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}
}
