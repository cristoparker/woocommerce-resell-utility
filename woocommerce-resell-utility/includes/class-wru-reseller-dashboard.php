<?php
/**
 * Upgraded Reseller Account Dashboard in WooCommerce My Account.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Reseller_Dashboard {

	const ENDPOINT = 'reseller-dashboard';

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
		if ( ! WRU_Settings::is_dashboard_enabled() ) {
			return;
		}

		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_dashboard_content' ) );

		// Save payout details form.
		add_action( 'template_redirect', array( $this, 'save_payout_details' ) );
	}

	/**
	 * Add custom rewrite endpoint.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add custom tab into My Account menu items.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( $items ) {
		$new_items = array();

		foreach ( $items as $key => $title ) {
			// Place reseller dashboard right after dashboard or orders.
			$new_items[ $key ] = $title;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'রিসেলার ড্যাশবোর্ড', 'woocommerce-resell-utility' );
			}
		}

		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'রিসেলার ড্যাশবোর্ড', 'woocommerce-resell-utility' );
		}

		return $new_items;
	}

	/**
	 * Save reseller payout information.
	 */
	public function save_payout_details() {
		if ( ! is_user_logged_in() || ! isset( $_POST['wru_save_payout'] ) ) {
			return;
		}

		if ( ! isset( $_POST['wru_payout_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wru_payout_nonce'] ), 'wru_save_payout_action' ) ) {
			wc_add_notice( __( 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$user_id = get_current_user_id();
		$method  = isset( $_POST['wru_payout_method'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_payout_method'] ) ) : '';
		$number  = isset( $_POST['wru_payout_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_payout_number'] ) ) : '';
		$notes   = isset( $_POST['wru_payout_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wru_payout_notes'] ) ) : '';

		update_user_meta( $user_id, '_wru_payout_method', $method );
		update_user_meta( $user_id, '_wru_payout_number', $number );
		update_user_meta( $user_id, '_wru_payout_notes', $notes );

		wc_add_notice( __( 'আপনার পেআউট ও ব্যাংক/বিকাশ তথ্য সফলভাবে সংরক্ষিত হয়েছে।', 'woocommerce-resell-utility' ), 'success' );
	}

	/**
	 * Render the Reseller Dashboard page content.
	 */
	public function render_dashboard_content() {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'ড্যাশবোর্ড দেখতে দয়া করে লগইন করুন।', 'woocommerce-resell-utility' ) . '</p>';
			return;
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		// Query customer's orders.
		$orders = wc_get_orders( array(
			'customer' => $user_id,
			'limit'    => 50,
			'orderby'  => 'date',
			'order'    => 'DESC',
		) );

		$total_profit     = 0.0;
		$pending_profit   = 0.0;
		$completed_profit = 0.0;
		$resell_orders    = array();

		foreach ( $orders as $order ) {
			$profit_meta = $order->get_meta( '_wru_total_reseller_profit' );
			if ( '' === $profit_meta || false === $profit_meta ) {
				continue;
			}

			$profit = (float) $profit_meta;
			$status = $order->get_status();

			$total_profit += $profit;

			if ( in_array( $status, array( 'processing', 'on-hold', 'pending' ), true ) ) {
				$pending_profit += $profit;
			} elseif ( 'completed' === $status ) {
				$completed_profit += $profit;
			}

			$resell_orders[] = $order;
		}

		$payout_method = get_user_meta( $user_id, '_wru_payout_method', true );
		$payout_number = get_user_meta( $user_id, '_wru_payout_number', true );
		$payout_notes  = get_user_meta( $user_id, '_wru_payout_notes', true );
		?>
		<div class="wru-dashboard-wrap">
			<!-- Header Banner -->
			<div class="wru-dashboard-header">
				<div class="wru-user-welcome">
					<h2><?php printf( esc_html__( 'স্বাগতম, %s!', 'woocommerce-resell-utility' ), esc_html( $user->display_name ) ); ?></h2>
					<p><?php esc_html_e( 'আপনার ড্রপশিপিং রিসেলিং আয়, অর্ডার হিসাব এবং পেমেন্ট ট্র্যাকিং এখানে দেখুন।', 'woocommerce-resell-utility' ); ?></p>
				</div>
				<span class="wru-status-pill"><?php esc_html_e( 'সক্রিয় রিসেলার পার্টনার', 'woocommerce-resell-utility' ); ?></span>
			</div>

			<!-- KPI Stats Cards -->
			<div class="wru-stats-grid">
				<div class="wru-stat-card wru-stat-total">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'সর্বমোট প্রফিট', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number"><?php echo wc_price( $total_profit ); ?></strong>
					</div>
				</div>

				<div class="wru-stat-card wru-stat-pending">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'পেন্ডিং প্রফিট (ডেলিভারি চলছে)', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number"><?php echo wc_price( $pending_profit ); ?></strong>
					</div>
				</div>

				<div class="wru-stat-card wru-stat-completed">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'পরিশোধিত / অর্জিত প্রফিট', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number"><?php echo wc_price( $completed_profit ); ?></strong>
					</div>
				</div>

				<div class="wru-stat-card wru-stat-orders">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'মোট রিসেল অর্ডার', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number"><?php echo count( $resell_orders ); ?> <?php esc_html_e( 'টি', 'woocommerce-resell-utility' ); ?></strong>
					</div>
				</div>
			</div>

			<!-- Payout Settings Box -->
			<div class="wru-payout-box">
				<h3><?php esc_html_e( 'পেআউট ও পেমেন্ট রিসিভ মেথড', 'woocommerce-resell-utility' ); ?></h3>
				<p class="wru-payout-desc">
					<?php esc_html_e( 'আপনার বিক্রির প্রফিটের টাকা যে একাউন্টে নিতে চান (বিকাশ, নগদ, বা ব্যাংক), তা নিচে লিখে রাখুন।', 'woocommerce-resell-utility' ); ?>
				</p>
				<form method="post" action="" class="wru-payout-form">
					<?php wp_nonce_field( 'wru_save_payout_action', 'wru_payout_nonce' ); ?>
					<div class="wru-form-row">
						<div class="wru-field-col">
							<label for="wru_payout_method"><?php esc_html_e( 'পেমেন্ট মাধ্যম নির্বাচন করুন:', 'woocommerce-resell-utility' ); ?></label>
							<select name="wru_payout_method" id="wru_payout_method" class="wru-select">
								<option value="bkash_personal" <?php selected( $payout_method, 'bkash_personal' ); ?>><?php esc_html_e( 'বিকাশ পার্সোনাল (bKash Personal)', 'woocommerce-resell-utility' ); ?></option>
								<option value="bkash_agent" <?php selected( $payout_method, 'bkash_agent' ); ?>><?php esc_html_e( 'বিকাশ এজেন্ট (bKash Agent)', 'woocommerce-resell-utility' ); ?></option>
								<option value="nagad_personal" <?php selected( $payout_method, 'nagad_personal' ); ?>><?php esc_html_e( 'নগদ পার্সোনাল (Nagad Personal)', 'woocommerce-resell-utility' ); ?></option>
								<option value="rocket" <?php selected( $payout_method, 'rocket' ); ?>><?php esc_html_e( 'রকেট (Rocket)', 'woocommerce-resell-utility' ); ?></option>
								<option value="bank" <?php selected( $payout_method, 'bank' ); ?>><?php esc_html_e( 'ব্যাংক একাউন্ট (Bank Transfer)', 'woocommerce-resell-utility' ); ?></option>
							</select>
						</div>
						<div class="wru-field-col">
							<label for="wru_payout_number"><?php esc_html_e( 'মোবাইল / একাউন্ট নাম্বার:', 'woocommerce-resell-utility' ); ?></label>
							<input type="text" name="wru_payout_number" id="wru_payout_number" class="wru-input-text" value="<?php echo esc_attr( $payout_number ); ?>" placeholder="যেমন: 017XXXXXXXX" required />
						</div>
					</div>
					<div class="wru-field-full">
						<label for="wru_payout_notes"><?php esc_html_e( 'অতিরিক্ত নোট (ব্যাংক নাম, ব্রাঞ্চ, রাউটিং ইত্যাদি):', 'woocommerce-resell-utility' ); ?></label>
						<textarea name="wru_payout_notes" id="wru_payout_notes" rows="2" class="wru-textarea" placeholder="<?php esc_attr_e( 'প্রয়োজনীয় বিবরণ...', 'woocommerce-resell-utility' ); ?>"><?php echo esc_textarea( $payout_notes ); ?></textarea>
					</div>
					<button type="submit" name="wru_save_payout" value="1" class="button wru-save-payout-btn">
						<?php esc_html_e( 'পেআউট তথ্য সংরক্ষণ করুন', 'woocommerce-resell-utility' ); ?>
					</button>
				</form>
			</div>

			<!-- Reseller Orders Table -->
			<div class="wru-orders-section">
				<h3><?php esc_html_e( 'রিসেলিং অর্ডার হিস্ট্রি ও প্রফিট তালিকা', 'woocommerce-resell-utility' ); ?></h3>
				<?php if ( empty( $resell_orders ) ) : ?>
					<div class="wru-empty-state">
						<p><?php esc_html_e( 'আপনার কোনো রিসেলিং অর্ডার এখনও পাওয়া যায়নি। প্রোডাক্ট পেজ থেকে আপনার বিক্রয়মূল্য লিখে এখনই অর্ডার করুন!', 'woocommerce-resell-utility' ); ?></p>
					</div>
				<?php else : ?>
					<div class="wru-table-responsive">
						<table class="wru-orders-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'অর্ডার #', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'তারিখ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'কুরিয়ার কালেকশন', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'পাইকারি খরচ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'প্যাকেজিং ফি', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'আপনার নিট লাভ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'স্ট্যাটাস', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $resell_orders as $resell_order ) : 
									$order_id   = $resell_order->get_id();
									$collection = (float) $resell_order->get_meta( '_wru_total_collection_amount' );
									$wholesale  = (float) $resell_order->get_meta( '_wru_total_wholesale_amount' );
									$packaging  = (float) $resell_order->get_meta( '_wru_total_packaging_fee' );
									$profit     = (float) $resell_order->get_meta( '_wru_total_reseller_profit' );
								?>
									<tr>
										<td><strong>#<?php echo esc_html( $order_id ); ?></strong></td>
										<td><?php echo esc_html( wc_format_datetime( $resell_order->get_date_created() ) ); ?></td>
										<td><?php echo wc_price( $collection ); ?></td>
										<td><?php echo wc_price( $wholesale ); ?></td>
										<td><?php echo wc_price( $packaging ); ?></td>
										<td class="wru-profit-td"><strong><?php echo wc_price( $profit ); ?></strong></td>
										<td>
											<span class="wru-status-badge wru-status-<?php echo esc_attr( $resell_order->get_status() ); ?>">
												<?php echo esc_html( wc_get_order_status_name( $resell_order->get_status() ) ); ?>
											</span>
										</td>
										<td>
											<a href="<?php echo esc_url( $resell_order->get_view_order_url() ); ?>" class="button wru-view-order-btn">
												<?php esc_html_e( 'ভিউ', 'woocommerce-resell-utility' ); ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
