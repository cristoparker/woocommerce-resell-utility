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

		// Register Bulk Actions in WooCommerce Orders list (HPOS & classic CPT).
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );
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
	 * Register bulk action for packaging labels in Orders list.
	 *
	 * @param array $actions Bulk actions list.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		$actions['wru_bulk_print_labels'] = __( 'প্যাকেজিং লেবেল প্রিন্ট করুন (বাল্ক)', 'woocommerce-resell-utility' );
		return $actions;
	}

	/**
	 * Handle bulk print action and redirect to bulk label print view.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $order_ids   Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $order_ids ) {
		if ( 'wru_bulk_print_labels' !== $action ) {
			return $redirect_to;
		}

		if ( empty( $order_ids ) ) {
			return $redirect_to;
		}

		$clean_ids = array_filter( array_map( 'absint', (array) $order_ids ) );
		if ( empty( $clean_ids ) ) {
			return $redirect_to;
		}

		$ids_string = implode( ',', $clean_ids );
		$nonce      = wp_create_nonce( 'wru_bulk_print_labels' );

		return add_query_arg( array(
			'wru_action' => 'print_bulk_labels',
			'order_ids'  => $ids_string,
			'nonce'      => $nonce,
		), home_url( '/' ) );
	}

	/**
	 * Handle print invoice or bulk packaging labels request.
	 */
	public function handle_invoice_print_request() {
		if ( ! isset( $_GET['wru_action'] ) ) {
			return;
		}

		if ( 'print_bulk_labels' === $_GET['wru_action'] ) {
			$this->handle_bulk_print_request();
			return;
		}

		if ( 'print_invoice' !== $_GET['wru_action'] ) {
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

		$inv_note = get_option( 'wru_invoice_footer_note', __( 'ডেলিভারির সময় পার্সেল চেক করে টাকা পরিশোধ করুন।', 'woocommerce-resell-utility' ) );
		?>
		<!DOCTYPE html>
		<html lang="bn">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php printf( esc_html__( 'প্যাকেজিং লেবেল #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></title>
			<?php echo self::get_minimal_slip_css(); ?>
		</head>
		<body>

			<!-- Top Print Action Bar (Hidden during print) -->
			<div class="print-bar">
				<div class="print-bar-info">
					<strong><?php printf( esc_html__( 'প্যাকেজিং স্লিপ প্রিন্টার - পার্সেল #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></strong>
				</div>
				<div class="print-actions">
					<button type="button" class="print-btn" onclick="window.print();">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
						<?php esc_html_e( 'প্রিন্ট করুন', 'woocommerce-resell-utility' ); ?>
					</button>
				</div>
			</div>

			<?php self::render_slip_markup( $order, $inv_note ); ?>

		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Handle bulk packaging labels print request.
	 */
	public function handle_bulk_print_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'আপনার এই প্যাকেজিং লেবেল দেখার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wru_bulk_print_labels' ) ) {
			wp_die( esc_html__( 'অননুমোদিত অনুরোধ। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ) );
		}

		$raw_ids = isset( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'কোনো অর্ডার নির্বাচন করা হয়নি।', 'woocommerce-resell-utility' ) );
		}

		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$orders[] = $order;
			}
		}

		if ( empty( $orders ) ) {
			wp_die( esc_html__( 'কোনো বৈধ অর্ডার পাওয়া যায়নি।', 'woocommerce-resell-utility' ) );
		}

		$inv_note = get_option( 'wru_invoice_footer_note', __( 'ডেলিভারির সময় পার্সেল চেক করে টাকা পরিশোধ করুন।', 'woocommerce-resell-utility' ) );
		$count    = count( $orders );
		?>
		<!DOCTYPE html>
		<html lang="bn">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php printf( esc_html__( 'বাল্ক প্যাকেজিং লেবেল (%d টি পার্সেল)', 'woocommerce-resell-utility' ), $count ); ?></title>
			<?php echo self::get_minimal_slip_css(); ?>
		</head>
		<body>

			<!-- Top Print Action Bar (Hidden during print) -->
			<div class="print-bar">
				<div class="print-bar-info">
					<strong><?php printf( esc_html__( 'বাল্ক প্যাকেজিং স্লিপ প্রিন্টার (%d টি পার্সেল)', 'woocommerce-resell-utility' ), $count ); ?></strong>
					<span style="opacity: 0.7; margin-left: 8px; font-size: 12px;"><?php esc_html_e( '(প্রিন্ট করলে প্রতিটি লেবেল আলাদা আলাদা পেজে প্রিন্ট হবে)', 'woocommerce-resell-utility' ); ?></span>
				</div>
				<div class="print-actions">
					<button type="button" class="print-btn" onclick="window.print();">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
						<?php printf( esc_html__( 'একসাথে সব প্রিন্ট করুন (%d টি)', 'woocommerce-resell-utility' ), $count ); ?>
					</button>
				</div>
			</div>

			<?php foreach ( $orders as $order ) : ?>
				<?php self::render_slip_markup( $order, $inv_note ); ?>
			<?php endforeach; ?>

		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Get CSS style block for minimal ink-saving packaging slips.
	 *
	 * @return string
	 */
	public static function get_minimal_slip_css() {
		return '<style>
			* { box-sizing: border-box; }
			body {
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
				color: #111827;
				background: #f3f4f6;
				margin: 0;
				padding: 24px;
				font-size: 13px;
				line-height: 1.5;
			}
			.print-bar {
				max-width: 650px;
				margin: 0 auto 16px auto;
				display: flex;
				justify-content: space-between;
				align-items: center;
				background: #ffffff;
				border: 1px solid #d1d5db;
				color: #111827;
				padding: 10px 16px;
				border-radius: 6px;
			}
			.print-bar-info {
				font-size: 13px;
				font-weight: 600;
			}
			.print-actions {
				display: flex;
				gap: 8px;
				align-items: center;
			}
			.print-btn {
				background: #111827;
				color: #ffffff;
				border: 1px solid #111827;
				padding: 7px 16px;
				border-radius: 4px;
				font-size: 13px;
				font-weight: 600;
				cursor: pointer;
				display: inline-flex;
				align-items: center;
				gap: 6px;
			}
			.print-btn:hover {
				background: #374151;
			}
			.packing-slip-wrapper {
				max-width: 650px;
				margin: 0 auto 24px auto;
				background: #ffffff;
				border: 1px solid #d1d5db;
				border-radius: 6px;
				padding: 24px 28px;
				page-break-after: always;
				break-after: page;
			}
			.packing-slip-wrapper:last-child {
				page-break-after: avoid;
				break-after: avoid;
				margin-bottom: 0;
			}
			.slip-header {
				display: flex;
				justify-content: space-between;
				align-items: flex-start;
				border-bottom: 2px solid #111827;
				padding-bottom: 12px;
				margin-bottom: 16px;
			}
			.reseller-brand-name {
				font-size: 20px;
				font-weight: 800;
				margin: 0 0 3px 0;
				color: #111827;
				letter-spacing: -0.01em;
			}
			.reseller-contact {
				font-size: 12px;
				color: #4b5563;
				margin: 0;
			}
			.order-meta-box {
				text-align: right;
			}
			.order-meta-tag {
				font-size: 10px;
				text-transform: uppercase;
				letter-spacing: 0.08em;
				color: #6b7280;
				font-weight: 700;
				margin-bottom: 2px;
			}
			.order-meta-title {
				font-size: 16px;
				font-weight: 800;
				color: #111827;
				margin: 0 0 2px 0;
			}
			.order-meta-date {
				font-size: 11px;
				color: #4b5563;
				margin: 0;
			}
			.delivery-grid {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 14px;
				margin-bottom: 16px;
			}
			.delivery-box {
				border: 1px solid #d1d5db;
				border-radius: 4px;
				padding: 12px 14px;
				background: #ffffff;
			}
			.delivery-box-title {
				font-size: 10px;
				text-transform: uppercase;
				font-weight: 700;
				letter-spacing: 0.06em;
				color: #6b7280;
				margin: 0 0 6px 0;
				border-bottom: 1px solid #e5e7eb;
				padding-bottom: 3px;
			}
			.delivery-name {
				font-size: 14px;
				font-weight: 700;
				color: #111827;
				margin: 0 0 3px 0;
			}
			.delivery-phone {
				font-size: 13px;
				font-weight: 600;
				color: #111827;
				margin: 0 0 4px 0;
			}
			.delivery-address {
				font-size: 12px;
				color: #374151;
				margin: 0;
				line-height: 1.4;
			}
			.cod-collection-card {
				background: #ffffff;
				border: 2px solid #111827;
				border-radius: 4px;
				padding: 10px 14px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 16px;
			}
			.cod-label-title {
				font-size: 12px;
				font-weight: 800;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				margin: 0;
				color: #111827;
			}
			.cod-amount-badge {
				font-size: 20px;
				font-weight: 900;
				color: #000000;
				letter-spacing: -0.01em;
			}
			.products-table {
				width: 100%;
				border-collapse: collapse;
				margin-bottom: 16px;
			}
			.products-table th {
				background: #f9fafb;
				border-top: 1px solid #111827;
				border-bottom: 1px solid #111827;
				padding: 8px 10px;
				text-align: left;
				font-size: 11px;
				font-weight: 700;
				color: #374151;
				text-transform: uppercase;
				letter-spacing: 0.04em;
			}
			.products-table td {
				padding: 8px 10px;
				border-bottom: 1px solid #e5e7eb;
				font-size: 12px;
				color: #111827;
			}
			.products-table td.text-right, .products-table th.text-right {
				text-align: right;
			}
			.product-title {
				font-weight: 600;
				color: #111827;
			}
			.slip-footer {
				text-align: center;
				border-top: 1px dashed #d1d5db;
				padding-top: 10px;
				font-size: 11px;
				color: #6b7280;
			}
			.slip-footer p {
				margin: 0;
			}
			@media print {
				@page {
					margin: 8mm;
					size: auto;
				}
				body {
					background: #ffffff !important;
					padding: 0 !important;
					margin: 0 !important;
					color: #000000 !important;
				}
				.print-bar {
					display: none !important;
				}
				.packing-slip-wrapper {
					border: 1px solid #000000 !important;
					border-radius: 0 !important;
					box-shadow: none !important;
					padding: 16px 20px !important;
					max-width: 100% !important;
					width: 100% !important;
					page-break-after: always !important;
					break-after: page !important;
					margin: 0 !important;
				}
				.packing-slip-wrapper:last-child {
					page-break-after: avoid !important;
					break-after: avoid !important;
				}
				.cod-collection-card {
					border: 2px solid #000000 !important;
					background: #ffffff !important;
					color: #000000 !important;
				}
				.cod-amount-badge {
					color: #000000 !important;
				}
			}
		</style>';
	}

	/**
	 * Render single packaging slip wrapper markup for an order.
	 *
	 * @param \WC_Order $order    Order object.
	 * @param string    $inv_note Optional instruction note.
	 */
	public static function render_slip_markup( $order, $inv_note = '' ) {
		if ( ! $order ) {
			return;
		}

		$order_id        = $order->get_id();
		$order_user_id   = $order->get_customer_id();
		$is_resell_order = 'yes' === $order->get_meta( '_wru_is_resell_order' );
		$sender_company  = $order->get_meta( '_wru_reseller_company_name' );
		$sender_phone    = $order->get_meta( '_wru_reseller_phone' );

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

		if ( empty( $sender_company ) ) {
			if ( $is_resell_order ) {
				$reseller_user  = get_userdata( $order_user_id );
				$sender_company = $reseller_user ? $reseller_user->display_name : __( 'রিসেলার শপ', 'woocommerce-resell-utility' );
			} else {
				$sender_company = get_option( 'wru_invoice_store_name', get_bloginfo( 'name' ) );
				$sender_phone   = get_option( 'wru_invoice_store_phone', '' );
			}
		}

		if ( empty( $inv_note ) ) {
			$inv_note = get_option( 'wru_invoice_footer_note', __( 'ডেলিভারির সময় পার্সেল চেক করে টাকা পরিশোধ করুন।', 'woocommerce-resell-utility' ) );
		}

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
		?>
		<div class="packing-slip-wrapper">
			<!-- Header: Reseller Brand & Order Meta -->
			<div class="slip-header">
				<div>
					<h1 class="reseller-brand-name"><?php echo esc_html( $sender_company ); ?></h1>
					<?php if ( ! empty( $sender_phone ) ) : ?>
						<p class="reseller-contact"><?php printf( esc_html__( 'ফোন: %s', 'woocommerce-resell-utility' ), esc_html( $sender_phone ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="order-meta-box">
					<div class="order-meta-tag"><?php esc_html_e( 'SHIPPING LABEL / PACKING SLIP', 'woocommerce-resell-utility' ); ?></div>
					<div class="order-meta-title"><?php printf( esc_html__( 'অর্ডার #%s', 'woocommerce-resell-utility' ), esc_html( $order_id ) ); ?></div>
					<p class="order-meta-date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
					<?php if ( $order->get_shipping_method() ) : ?>
						<p class="order-meta-date"><?php echo esc_html( $order->get_shipping_method() ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- 2-Column Delivery Info -->
			<div class="delivery-grid">
				<!-- Sender Box (Reseller Shop) -->
				<div class="delivery-box">
					<div class="delivery-box-title"><?php esc_html_e( 'প্রেরক (FROM):', 'woocommerce-resell-utility' ); ?></div>
					<div class="delivery-name"><?php echo esc_html( $sender_company ); ?></div>
					<?php if ( ! empty( $sender_phone ) ) : ?>
						<div class="delivery-phone"><?php printf( esc_html__( 'ফোন: %s', 'woocommerce-resell-utility' ), esc_html( $sender_phone ) ); ?></div>
					<?php endif; ?>
				</div>

				<!-- Recipient Box (Customer) -->
				<div class="delivery-box" style="border-color: #111827;">
					<div class="delivery-box-title" style="color: #111827; font-weight: 800;"><?php esc_html_e( 'প্রাপক (TO):', 'woocommerce-resell-utility' ); ?></div>
					<div class="delivery-name"><?php echo esc_html( $customer_name ); ?></div>
					<div class="delivery-phone">
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

			<!-- Minimal High-Contrast Cash on Delivery (COD) Box -->
			<div class="cod-collection-card">
				<div class="cod-label-title">
					<?php esc_html_e( 'কুরিয়ার কালেকশন (COD Amount):', 'woocommerce-resell-utility' ); ?>
				</div>
				<div class="cod-amount-badge">
					<?php echo wc_price( $collection ); ?>
				</div>
			</div>

			<!-- Parcel Contents / Products Table (Clean, minimal, no wholesale or profit) -->
			<table class="products-table">
				<thead>
					<tr>
						<th style="width: 10%;"><?php esc_html_e( '#', 'woocommerce-resell-utility' ); ?></th>
						<th style="width: 70%;"><?php esc_html_e( 'পণ্যের বিবরণ (Item Description)', 'woocommerce-resell-utility' ); ?></th>
						<th class="text-right" style="width: 20%;"><?php esc_html_e( 'পরিমাণ (Qty)', 'woocommerce-resell-utility' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					$item_idx = 1;
					foreach ( $order->get_items() as $item ) : 
					?>
						<tr>
							<td><?php echo esc_html( $item_idx++ ); ?></td>
							<td>
								<span class="product-title"><?php echo esc_html( $item->get_name() ); ?></span>
							</td>
							<td class="text-right">
								<strong><?php echo esc_html( $item->get_quantity() ); ?></strong>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Footer Note (Optional Instruction) -->
			<?php if ( ! empty( $inv_note ) ) : ?>
				<div class="slip-footer">
					<p><?php echo esc_html( $inv_note ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
