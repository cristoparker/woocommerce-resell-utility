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

		// Handle reseller self-cancellation of pending/processing/packed orders.
		add_action( 'template_redirect', array( $this, 'handle_reseller_order_cancellation' ) );
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
				$new_items[ self::ENDPOINT ] = __( 'Reseller Dashboard', 'woocommerce-resell-utility' );
			}
		}

		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'Reseller Dashboard', 'woocommerce-resell-utility' );
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
		$company = isset( $_POST['wru_reseller_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reseller_company_name'] ) ) : '';
		$phone   = isset( $_POST['wru_reseller_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reseller_phone'] ) ) : '';
		$method  = isset( $_POST['wru_payout_method'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_payout_method'] ) ) : '';
		$number  = isset( $_POST['wru_payout_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_payout_number'] ) ) : '';
		$notes   = isset( $_POST['wru_payout_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wru_payout_notes'] ) ) : '';

		if ( ! empty( $company ) ) {
			update_user_meta( $user_id, '_wru_reseller_company_name', $company );
			update_user_meta( $user_id, 'billing_company', $company );
		}
		if ( ! empty( $phone ) ) {
			update_user_meta( $user_id, '_wru_reseller_phone', $phone );
		}
		update_user_meta( $user_id, '_wru_payout_method', $method );
		update_user_meta( $user_id, '_wru_payout_number', $number );
		update_user_meta( $user_id, '_wru_payout_notes', $notes );

		wc_add_notice( __( 'আপনার পেআউট ও শপ/কোম্পানির তথ্য সফলভাবে সংরক্ষিত হয়েছে।', 'woocommerce-resell-utility' ), 'success' );
	}

	/**
	 * Handle reseller self-cancellation of pending, processing, or packed orders.
	 */
	public function handle_reseller_order_cancellation() {
		if ( ! is_user_logged_in() || ! isset( $_POST['wru_cancel_order_action'] ) ) {
			return;
		}

		if ( ! isset( $_POST['wru_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wru_cancel_nonce'] ), 'wru_reseller_cancel_order' ) ) {
			wc_add_notice( __( 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'অর্ডারটি খুঁজে পাওয়া যায়নি।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$user_id = get_current_user_id();
		if ( (int) $order->get_customer_id() !== $user_id && (int) $order->get_meta( '_wru_reseller_user_id' ) !== $user_id ) {
			wc_add_notice( __( 'এই অর্ডারটি বাতিল করার অনুমতি আপনার নেই।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$current_status = $order->get_status();
		$cancellable    = array( 'pending', 'on-hold', 'processing', 'packed' );

		if ( ! in_array( $current_status, $cancellable, true ) ) {
			wc_add_notice( __( 'পার্সেলটি কুরিয়ারে প্রেরণ (Shipped) বা সম্পন্ন হয়ে যাওয়ার কারণে আর ড্যাশবোর্ড হতে সরাসরি বাতিল করা সম্ভব নয়। অনুগ্রহ করে অ্যাডমিনের সাথে যোগাযোগ করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		// Transition status to cancelled with audit note.
		$order->update_status( 'cancelled', __( 'রিসেলার কর্তৃক ড্যাশবোর্ড থেকে অর্ডার বাতিল করা হয়েছে।', 'woocommerce-resell-utility' ) );
		$order->update_meta_data( '_wru_cancelled_by', 'reseller' );
		$order->save();

		wc_add_notice( sprintf( __( 'অর্ডার #%d সফলভাবে বাতিল করা হয়েছে।', 'woocommerce-resell-utility' ), $order_id ), 'success' );

		wp_safe_redirect( wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
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

		// Only users with 'wru_reseller' role or store managers/admins can view reseller dashboard
		if ( ! user_can( $user_id, WRU_Reseller_Manager::ROLE_RESELLER ) && ! user_can( $user_id, 'manage_woocommerce' ) ) {
			?>
			<div class="wru-dashboard-wrap">
				<div class="wru-notice-restricted" style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:12px; padding:36px; text-align:center;">
					<div style="width:60px; height:60px; margin:0 auto 16px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#64748b;">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					</div>
					<h3 style="margin:0 0 8px 0; color:#0f172a; font-size:1.35rem;"><?php esc_html_e( 'শুধুমাত্র অনুমোদিত রিসেলারদের জন্য', 'woocommerce-resell-utility' ); ?></h3>
					<p style="color:#64748b; font-size:0.95rem; max-width:480px; margin:0 auto 16px; line-height:1.6;">
						<?php esc_html_e( 'এই রিসেলার ড্যাশবোর্ডটি শুধুমাত্র আমাদের নিবন্ধিত রিসেলার পার্টনারদের জন্য। আপনি যদি রিসেলার হিসেবে ড্রপশিপিং করতে চান, অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন।', 'woocommerce-resell-utility' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		// Calculate comprehensive balance data via manager
		$balance_data       = WRU_Reseller_Manager::get_reseller_balance_data( $user_id );
		$available_balance  = $balance_data['available_balance'];
		$pending_cashouts   = $balance_data['pending_cashouts'];
		$completed_cashouts = $balance_data['completed_cashouts'];
		$total_earned       = $balance_data['total_earned'];
		$completed_profit   = $balance_data['completed_profit'];
		$pending_profit     = $balance_data['pending_profit'];
		$cancelled_penalty  = $balance_data['cancelled_penalty'];

		// Saved payout & brand details
		$company_name  = get_user_meta( $user_id, '_wru_reseller_company_name', true ) ?: get_user_meta( $user_id, 'billing_company', true );
		$reseller_phone= get_user_meta( $user_id, '_wru_reseller_phone', true );
		$payout_method = get_user_meta( $user_id, '_wru_payout_method', true );
		$payout_number = get_user_meta( $user_id, '_wru_payout_number', true );
		$payout_notes  = get_user_meta( $user_id, '_wru_payout_notes', true );

		// Query reseller's cashout requests
		$cashout_posts = get_posts( array(
			'post_type'      => WRU_Reseller_Manager::CPT_CASHOUT,
			'post_status'    => array( 'pending', 'publish', 'trash' ),
			'meta_key'       => '_wru_reseller_id',
			'meta_value'     => $user_id,
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		// Query ledger adjustments
		$ledger = WRU_Reseller_Manager::get_ledger( $user_id );

		// Query reseller's orders
		$orders = wc_get_orders( array(
			'customer' => $user_id,
			'limit'    => 50,
			'orderby'  => 'date',
			'order'    => 'DESC',
		) );

		$resell_orders = array();
		foreach ( $orders as $order ) {
			$profit_meta = $order->get_meta( '_wru_total_reseller_profit' );
			if ( '' !== $profit_meta && false !== $profit_meta ) {
				$resell_orders[] = $order;
			}
		}

		$cancellation_fee_rate = WRU_Settings::get_cancellation_fee();
		?>
		<div class="wru-dashboard-wrap">
			<!-- Header Banner -->
			<div class="wru-dashboard-header">
				<div class="wru-user-welcome">
					<h2><?php printf( esc_html__( 'স্বাগতম, %s!', 'woocommerce-resell-utility' ), esc_html( ! empty( $company_name ) ? $company_name . ' (' . $user->display_name . ')' : $user->display_name ) ); ?></h2>
					<p><?php esc_html_e( 'আপনার ড্রপশিপিং রিসেলিং আয়, ব্যালেন্স, ক্যাশআউট এবং অর্ডার স্টেটমেন্ট এখানে ট্র্যাক করুন।', 'woocommerce-resell-utility' ); ?></p>
				</div>
				<span class="wru-status-pill"><?php esc_html_e( 'অনুমোদিত রিসেলার পার্টনার', 'woocommerce-resell-utility' ); ?></span>
			</div>

			<!-- KPI Stats Cards -->
			<div class="wru-stats-grid">
				<!-- Available Withdrawable Balance Card -->
				<div class="wru-stat-card wru-stat-total" style="border-left: 4px solid #16a34a;">
					<div class="wru-stat-icon" style="background:#ecfdf5; color:#16a34a;">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'উত্তোলনযোগ্য অবশিষ্ট ব্যালেন্স', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number" style="color: <?php echo $available_balance >= 0 ? '#16a34a' : '#dc2626'; ?>">
							<?php echo wc_price( $available_balance ); ?>
						</strong>
						<?php if ( $available_balance < 0 ) : ?>
							<small style="color: #dc2626; font-size: 11px; font-weight:700; display:block;"><?php esc_html_e( '(বকেয়া ঋণাত্মক ব্যালেন্স)', 'woocommerce-resell-utility' ); ?></small>
						<?php endif; ?>
						<div style="margin-top: 8px;">
							<?php if ( $available_balance > 0 ) : ?>
								<a href="#wru-cashout-section" class="button button-small wru-cashout-trigger-btn" style="background:#16a34a; color:#fff; border-radius:6px; font-weight:700; padding:4px 12px; border:none; text-decoration:none; display:inline-block;">
									<?php esc_html_e( 'টাকা ক্যাশআউট করুন', 'woocommerce-resell-utility' ); ?>
								</a>
							<?php else : ?>
								<span style="color:#94a3b8; font-size:12px; font-style:italic;"><?php esc_html_e( 'ক্যাশআউট অনুপলব্ধ', 'woocommerce-resell-utility' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Pending Cashout Card -->
				<div class="wru-stat-card wru-stat-pending">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'পেন্ডিং ক্যাশআউট রিকোয়েস্ট', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number" style="color:#d97706;"><?php echo wc_price( $pending_cashouts ); ?></strong>
						<small style="color: #64748b; font-size: 11px;"><?php esc_html_e( 'এডমিন অনুমোদন ও পেমেন্ট প্রক্রিয়াধীন', 'woocommerce-resell-utility' ); ?></small>
					</div>
				</div>

				<!-- Total Withdrawn Card -->
				<div class="wru-stat-card wru-stat-completed">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'মোট উত্তোলন সম্পন্ন (Paid)', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number" style="color:#0284c7;"><?php echo wc_price( $completed_cashouts ); ?></strong>
						<small style="color: #64748b; font-size: 11px;"><?php esc_html_e( 'বিকাশ/নগদ/ব্যাংকে পরিশোধিত', 'woocommerce-resell-utility' ); ?></small>
					</div>
				</div>

				<!-- Lifetime Net Profit Card -->
				<div class="wru-stat-card wru-stat-orders">
					<div class="wru-stat-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
					</div>
					<div class="wru-stat-info">
						<span class="wru-stat-title"><?php esc_html_e( 'সর্বমোট অর্জিত নিট লাভ', 'woocommerce-resell-utility' ); ?></span>
						<strong class="wru-stat-number"><?php echo wc_price( $total_earned ); ?></strong>
						<small style="color: #64748b; font-size: 11px;"><?php esc_html_e( 'ডেলিভারি সম্পন্ন অর্ডার ও বোনাস', 'woocommerce-resell-utility' ); ?></small>
					</div>
				</div>
			</div>

			<!-- Cashout Request Section -->
			<div id="wru-cashout-section" class="wru-payout-box" style="border-left: 4px solid #0284c7;">
				<h3><?php esc_html_e( 'টাকা ক্যাশআউট রিকোয়েস্ট করুন', 'woocommerce-resell-utility' ); ?></h3>
				<p class="wru-payout-desc">
					<?php esc_html_e( 'আপনার উত্তোলনযোগ্য ব্যালেন্স হতে টাকা তোলার জন্য নিচের ফর্মটি পূরণ করুন। রিকোয়েস্ট পাঠানোর পর এটি পেন্ডিং থাকবে এবং এডমিন টাকা পাঠিয়ে দিলে তা সফল হিসেবে রেকর্ড হবে।', 'woocommerce-resell-utility' ); ?>
				</p>

				<?php if ( $available_balance < 0 ) : ?>
					<div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:8px; font-weight:600;">
						<?php printf( esc_html__( 'আপনার বর্তমান ব্যালেন্স ঋণাত্মক (%s)। বাতিল/রিটার্ন পার্সেলের ফি কর্তন সমন্বয়ের কারণে এই বকেয়া তৈরি হয়েছে। আপনার পরবর্তী অর্ডার ডেলিভারি সম্পন্ন হলে অর্জিত মুনাফা থেকে এই বকেয়া স্বয়ংক্রিয়ভাবে সমন্বয় হবে।', 'woocommerce-resell-utility' ), wc_price( $available_balance ) ); ?>
					</div>
				<?php elseif ( $available_balance == 0 ) : ?>
					<div style="background:#f8fafc; border:1px solid #cbd5e1; color:#475569; padding:12px 16px; border-radius:8px; font-weight:600;">
						<?php esc_html_e( 'আপনার বর্তমানে কোনো উত্তোলনযোগ্য অবশিষ্ট ব্যালেন্স নেই। আপনার কাস্টমারদের অর্ডার সফলভাবে ডেলিভারি হওয়ার পর প্রফিট যোগ হবে।', 'woocommerce-resell-utility' ); ?>
					</div>
				<?php else : ?>
					<form method="post" action="" class="wru-cashout-form">
						<?php wp_nonce_field( 'wru_cashout_request_action', 'wru_cashout_nonce' ); ?>
						<input type="hidden" name="wru_request_cashout" value="1" />

						<div class="wru-form-row">
							<div class="wru-field-col">
								<label for="wru_cashout_amount">
									<?php esc_html_e( 'ক্যাশআউট অ্যামাউন্ট (টাকা):', 'woocommerce-resell-utility' ); ?>
									<span style="color:#16a34a; font-weight:normal; font-size:12px;">(<?php printf( esc_html__( 'সর্বোচ্চ: %s', 'woocommerce-resell-utility' ), wc_price( $available_balance ) ); ?>)</span>
								</label>
								<input type="number" step="1" min="10" max="<?php echo esc_attr( $available_balance ); ?>" name="wru_cashout_amount" id="wru_cashout_amount" class="wru-input-text" value="<?php echo esc_attr( $available_balance ); ?>" required />
							</div>

							<div class="wru-field-col">
								<label for="wru_cashout_method"><?php esc_html_e( 'পেমেন্ট মাধ্যম:', 'woocommerce-resell-utility' ); ?></label>
								<select name="wru_cashout_method" id="wru_cashout_method" class="wru-select" required>
									<option value="bkash_personal" <?php selected( $payout_method, 'bkash_personal' ); ?>><?php esc_html_e( 'বিকাশ পার্সোনাল (bKash Personal)', 'woocommerce-resell-utility' ); ?></option>
									<option value="bkash_agent" <?php selected( $payout_method, 'bkash_agent' ); ?>><?php esc_html_e( 'বিকাশ এজেন্ট (bKash Agent)', 'woocommerce-resell-utility' ); ?></option>
									<option value="nagad_personal" <?php selected( $payout_method, 'nagad_personal' ); ?>><?php esc_html_e( 'নগদ পার্সোনাল (Nagad Personal)', 'woocommerce-resell-utility' ); ?></option>
									<option value="rocket" <?php selected( $payout_method, 'rocket' ); ?>><?php esc_html_e( 'রকেট (Rocket)', 'woocommerce-resell-utility' ); ?></option>
									<option value="bank" <?php selected( $payout_method, 'bank' ); ?>><?php esc_html_e( 'ব্যাংক একাউন্ট (Bank Transfer)', 'woocommerce-resell-utility' ); ?></option>
								</select>
							</div>

							<div class="wru-field-col">
								<label for="wru_cashout_number"><?php esc_html_e( 'মোবাইল / একাউন্ট নাম্বার:', 'woocommerce-resell-utility' ); ?></label>
								<input type="text" name="wru_cashout_number" id="wru_cashout_number" class="wru-input-text" value="<?php echo esc_attr( $payout_number ); ?>" placeholder="যেমন: 017XXXXXXXX" required />
							</div>
						</div>

						<div class="wru-field-full">
							<label for="wru_cashout_notes"><?php esc_html_e( 'নোট (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></label>
							<input type="text" name="wru_cashout_notes" id="wru_cashout_notes" class="wru-input-text" placeholder="<?php esc_attr_e( 'প্রয়োজনীয় কোনো তথ্য...', 'woocommerce-resell-utility' ); ?>" />
						</div>

						<button type="submit" class="button wru-save-payout-btn" style="background:#16a34a; font-weight:700;">
							<?php esc_html_e( 'ক্যাশআউট রিকোয়েস্ট সাবমিট করুন', 'woocommerce-resell-utility' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Cashout History Table -->
			<div class="wru-orders-section" style="margin-bottom: 28px;">
				<h3><?php esc_html_e( 'ক্যাশআউট রিকোয়েস্ট ও পেমেন্ট হিস্ট্রি', 'woocommerce-resell-utility' ); ?></h3>
				<?php if ( empty( $cashout_posts ) ) : ?>
					<div class="wru-empty-state">
						<p><?php esc_html_e( 'আপনার কোনো ক্যাশআউট রিকোয়েস্টের হিস্ট্রি পাওয়া যায়নি।', 'woocommerce-resell-utility' ); ?></p>
					</div>
				<?php else : ?>
					<div class="wru-table-responsive">
						<table class="wru-orders-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'রিকোয়েস্ট নং', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'তারিখ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'উত্তোলন অ্যামাউন্ট', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'মাধ্যম ও নাম্বার', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'স্ট্যাটাস', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'ট্রানজেকশন তথ্য (TrxID)', 'woocommerce-resell-utility' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cashout_posts as $cp ) : 
									$amt        = (float) get_post_meta( $cp->ID, '_wru_amount', true );
									$met        = get_post_meta( $cp->ID, '_wru_payout_method', true );
									$num        = get_post_meta( $cp->ID, '_wru_payout_number', true );
									$trx        = get_post_meta( $cp->ID, '_wru_trx_id', true );
									$admin_nt   = get_post_meta( $cp->ID, '_wru_admin_note', true );
									$rej_rsn    = get_post_meta( $cp->ID, '_wru_rejection_reason', true );
									$cp_status  = $cp->post_status;
								?>
									<tr>
										<td><strong>#WRU-CO-<?php echo esc_html( $cp->ID ); ?></strong></td>
										<td><?php echo esc_html( get_the_date( 'd M Y, h:i A', $cp->ID ) ); ?></td>
										<td><strong style="color: #16a34a; font-size:15px;"><?php echo wc_price( $amt ); ?></strong></td>
										<td>
											<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $met ) ) ); ?></strong>
											<br><code><?php echo esc_html( $num ); ?></code>
										</td>
										<td>
											<?php if ( 'pending' === $cp_status ) : ?>
												<span class="wru-status-badge wru-status-on-hold"><?php esc_html_e( 'পেন্ডিং (যাচাই চলছে)', 'woocommerce-resell-utility' ); ?></span>
											<?php elseif ( 'publish' === $cp_status ) : ?>
												<span class="wru-status-badge wru-status-completed"><?php esc_html_e( 'সফল / পরিশোধিত', 'woocommerce-resell-utility' ); ?></span>
											<?php elseif ( 'trash' === $cp_status ) : ?>
												<span class="wru-status-badge wru-status-cancelled"><?php esc_html_e( 'বাতিল করা হয়েছে', 'woocommerce-resell-utility' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( ! empty( $trx ) ) : ?>
												<strong>TrxID:</strong> <code><?php echo esc_html( $trx ); ?></code>
												<?php if ( ! empty( $admin_nt ) ) : ?>
													<br><small style="color:#64748b;"><?php echo esc_html( $admin_nt ); ?></small>
												<?php endif; ?>
											<?php elseif ( ! empty( $rej_rsn ) ) : ?>
												<span style="color:#dc2626;"><?php echo esc_html( $rej_rsn ); ?></span>
											<?php else : ?>
												<span style="color:#94a3b8; font-style:italic;"><?php esc_html_e( 'প্রক্রিয়াধীন...', 'woocommerce-resell-utility' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- Balance Adjustments Ledger (if any exist) -->
			<?php if ( ! empty( $ledger ) ) : ?>
				<div class="wru-orders-section" style="margin-bottom: 28px;">
					<h3><?php esc_html_e( 'ব্যালেন্স সমন্বয় ও লেনদেন হিস্ট্রি', 'woocommerce-resell-utility' ); ?></h3>
					<div class="wru-table-responsive">
						<table class="wru-orders-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'তারিখ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'লেনদেনের ধরন', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'অ্যামাউন্ট', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'পরবর্তী ব্যালেন্স', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'বিবরণ / কারণ', 'woocommerce-resell-utility' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_reverse( $ledger ) as $led ) : 
									$is_cr = ( 'credit' === $led['type'] );
								?>
									<tr>
										<td><?php echo esc_html( $led['date_formatted'] ); ?></td>
										<td>
											<strong style="color: <?php echo $is_cr ? '#16a34a' : '#dc2626'; ?>;">
												<?php echo $is_cr ? esc_html__( 'টাকা যোগ (+)', 'woocommerce-resell-utility' ) : esc_html__( 'টাকা কর্তন (-)', 'woocommerce-resell-utility' ); ?>
											</strong>
										</td>
										<td>
											<strong style="color: <?php echo $is_cr ? '#16a34a' : '#dc2626'; ?>;">
												<?php echo ( $is_cr ? '+' : '-' ) . wc_price( $led['amount'] ); ?>
											</strong>
										</td>
										<td><?php echo wc_price( $led['balance_after'] ); ?></td>
										<td><?php echo esc_html( $led['reason'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>

			<!-- Saved Payout Settings Box -->
			<div class="wru-payout-box">
				<h3><?php esc_html_e( 'ডিফল্ট পেআউট ও একাউন্ট সেটিংস', 'woocommerce-resell-utility' ); ?></h3>
				<p class="wru-payout-desc">
					<?php esc_html_e( 'আপনার নিয়মিত পেমেন্ট গ্রহণ করার মোবাইল বা ব্যাংক একাউন্ট তথ্য নিচে সংরক্ষণ করে রাখতে পারেন।', 'woocommerce-resell-utility' ); ?>
				</p>
				<form method="post" action="" class="wru-payout-form">
					<?php wp_nonce_field( 'wru_save_payout_action', 'wru_payout_nonce' ); ?>
					<div class="wru-form-row">
						<div class="wru-field-col">
							<label for="wru_reseller_company_name">
								<?php esc_html_e( 'আপনার শপ / পেজ / কোম্পানির নাম (লেবেলে প্রেরক হিসেবে থাকবে):', 'woocommerce-resell-utility' ); ?>
							</label>
							<input type="text" name="wru_reseller_company_name" id="wru_reseller_company_name" class="wru-input-text" value="<?php echo esc_attr( $company_name ); ?>" placeholder="<?php esc_attr_e( 'যেমন: Trendz Fashion BD বা আপনার পেজের নাম', 'woocommerce-resell-utility' ); ?>" />
						</div>
						<div class="wru-field-col">
							<label for="wru_reseller_phone">
								<?php esc_html_e( 'আপনার শপ হটলাইন / মোবাইল নম্বর:', 'woocommerce-resell-utility' ); ?>
							</label>
							<input type="text" name="wru_reseller_phone" id="wru_reseller_phone" class="wru-input-text" value="<?php echo esc_attr( $reseller_phone ); ?>" placeholder="যেমন: 017XXXXXXXX" />
						</div>
					</div>
					<div class="wru-form-row">
						<div class="wru-field-col">
							<label for="wru_payout_method"><?php esc_html_e( 'পেমেন্ট মাধ্যম:', 'woocommerce-resell-utility' ); ?></label>
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
						<label for="wru_payout_notes"><?php esc_html_e( 'অতিরিক্ত বিবরণ (ব্যাংক নাম, ব্রাঞ্চ, রাউটিং ইত্যাদি):', 'woocommerce-resell-utility' ); ?></label>
						<textarea name="wru_payout_notes" id="wru_payout_notes" rows="2" class="wru-textarea" placeholder="<?php esc_attr_e( 'প্রয়োজনীয় বিবরণ...', 'woocommerce-resell-utility' ); ?>"><?php echo esc_textarea( $payout_notes ); ?></textarea>
					</div>
					<button type="submit" name="wru_save_payout" value="1" class="button wru-save-payout-btn">
						<?php esc_html_e( 'পেআউট তথ্য সংরক্ষণ করুন', 'woocommerce-resell-utility' ); ?>
					</button>
				</form>
			</div>

			<!-- Reseller Orders Table -->
			<div class="wru-orders-section">
				<h3><?php esc_html_e( 'রিসেলিং অর্ডার হিস্ট্রি ও লাভ-লোকসান তালিকা', 'woocommerce-resell-utility' ); ?></h3>
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
									<th><?php esc_html_e( 'শিপিং ফি', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'আপনার নিট লাভ', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'স্ট্যাটাস', 'woocommerce-resell-utility' ); ?></th>
									<th><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $resell_orders as $resell_order ) : 
									$order_id     = $resell_order->get_id();
									$collection   = (float) $resell_order->get_meta( '_wru_total_collection_amount' );
									$wholesale    = (float) $resell_order->get_meta( '_wru_total_wholesale_amount' );
									$packaging    = (float) $resell_order->get_meta( '_wru_total_packaging_fee' );
									$shipping_fee = (float) $resell_order->get_shipping_total() + (float) $resell_order->get_shipping_tax();
									if ( $shipping_fee <= 0 ) {
										$shipping_fee = (float) $resell_order->get_meta( '_wru_shipping_charge' );
									}
									$profit       = (float) $resell_order->get_meta( '_wru_total_reseller_profit' );
								?>
									<tr>
										<td><strong>#<?php echo esc_html( $order_id ); ?></strong></td>
										<td><?php echo esc_html( wc_format_datetime( $resell_order->get_date_created() ) ); ?></td>
										<td><?php echo wc_price( $collection ); ?></td>
										<td><?php echo wc_price( $wholesale ); ?></td>
										<td><?php echo wc_price( $packaging ); ?></td>
										<td><?php echo wc_price( $shipping_fee ); ?></td>
										<td class="wru-profit-td">
											<?php
											$order_status = $resell_order->get_status();
											if ( 'completed' === $order_status ) {
												echo '<strong style="color: #16a34a;">+' . wc_price( $profit ) . '</strong>';
											} elseif ( in_array( $order_status, array( 'processing', 'on-hold', 'pending', 'packed', 'shipped' ), true ) ) {
												echo '<strong style="color: #d97706;">' . wc_price( $profit ) . '</strong><br><small style="color:#64748b; font-size:11px;">(' . esc_html__( 'পেন্ডিং', 'woocommerce-resell-utility' ) . ')</small>';
											} elseif ( in_array( $order_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
												$breakdown  = WRU_Order_Manager::get_order_cancellation_breakdown( $resell_order );
												$order_loss = $breakdown['total_loss'];
												if ( $order_loss > 0 ) {
													echo '<strong style="color: #dc2626;">-' . wc_price( $order_loss ) . '</strong><br><small style="color:#dc2626; font-size:11px;">(' . esc_html( $breakdown['reason'] ) . ')</small>';
												} else {
													echo '<strong style="color: #64748b;">' . wc_price( 0 ) . '</strong><br><small style="color:#64748b; font-size:11px;">(' . esc_html( $breakdown['reason'] ) . ')</small>';
												}
											} else {
												echo wc_price( $profit );
											}
											?>
										</td>
										<td>
											<span class="wru-status-badge wru-status-<?php echo esc_attr( $resell_order->get_status() ); ?>">
												<?php echo esc_html( wc_get_order_status_name( $resell_order->get_status() ) ); ?>
											</span>
										</td>
										<td>
											<div style="display:flex; flex-direction:column; gap:4px;">
												<a href="<?php echo esc_url( $resell_order->get_view_order_url() ); ?>" class="button wru-view-order-btn" style="text-align:center;">
													<?php esc_html_e( 'ভিউ', 'woocommerce-resell-utility' ); ?>
												</a>
												<?php if ( in_array( $order_status, array( 'pending', 'on-hold', 'processing', 'packed' ), true ) ) : ?>
													<form method="post" action="" onsubmit="return confirm('<?php echo 'packed' === $order_status ? sprintf( esc_attr__( 'সতর্কতা: এই অর্ডারটি ইতিমধ্যে প্যাক করা হয়ে গেছে। এখন বাতিল করলে আপনার একাউন্ট হতে প্যাকেজিং ফি (%s) কর্তন করা হবে। আপনি কি নিশ্চিত?', 'woocommerce-resell-utility' ), wc_price( $packaging ) ) : esc_attr__( 'আপনি কি নিশ্চিত যে এই অর্ডারটি বাতিল করতে চান? এটি এখনও প্যাক করা হয়নি, তাই কোনো ফি কর্তন হবে না।', 'woocommerce-resell-utility' ); ?>');">
														<?php wp_nonce_field( 'wru_reseller_cancel_order', 'wru_cancel_nonce' ); ?>
														<input type="hidden" name="wru_cancel_order_action" value="1" />
														<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />
														<button type="submit" class="button wru-cancel-order-btn" style="background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; border-radius:4px; font-size:11px; padding:3px 6px; cursor:pointer; width:100%; text-align:center;">
															<?php esc_html_e( 'বাতিল করুন', 'woocommerce-resell-utility' ); ?>
														</button>
													</form>
												<?php endif; ?>
											</div>
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
