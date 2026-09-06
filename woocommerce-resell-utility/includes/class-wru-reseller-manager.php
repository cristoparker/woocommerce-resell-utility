<?php
/**
 * Reseller Role, Management Hub, Editable Balance, and Cashout System.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Reseller_Manager {

	const ROLE_RESELLER = 'wru_reseller';
	const CPT_CASHOUT   = 'wru_cashout';

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
		add_action( 'init', array( $this, 'register_role' ) );
		add_action( 'init', array( $this, 'register_cashout_cpt' ) );

		// Admin menu & pages.
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 55 );

		// Admin post actions.
		add_action( 'admin_post_wru_adjust_balance', array( $this, 'handle_balance_adjustment' ) );
		add_action( 'admin_post_wru_approve_cashout', array( $this, 'handle_approve_cashout' ) );
		add_action( 'admin_post_wru_reject_cashout', array( $this, 'handle_reject_cashout' ) );
		add_action( 'admin_post_wru_assign_role', array( $this, 'handle_assign_reseller_role' ) );
		add_action( 'admin_post_wru_create_reseller', array( $this, 'handle_create_reseller' ) );
		add_action( 'admin_post_wru_delete_reseller', array( $this, 'handle_delete_reseller' ) );
		add_action( 'admin_post_wru_delete_cashout', array( $this, 'handle_delete_cashout' ) );

		// Clean up orphan cashouts when a user is deleted anywhere in WordPress.
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 2 );

		// AJAX for ledger modal.
		add_action( 'wp_ajax_wru_get_reseller_ledger', array( $this, 'ajax_get_reseller_ledger' ) );

		// Frontend cashout request handler.
		add_action( 'template_redirect', array( $this, 'handle_frontend_cashout_request' ) );
	}

	/**
	 * Register custom 'wru_reseller' role.
	 */
	public function register_role() {
		if ( ! get_role( self::ROLE_RESELLER ) ) {
			add_role(
				self::ROLE_RESELLER,
				__( 'রিসেলার', 'woocommerce-resell-utility' ),
				array(
					'read'         => true,
					'edit_posts'   => false,
					'delete_posts' => false,
				)
			);
		}
	}

	/**
	 * Register Cashout CPT for storing cashout requests natively.
	 */
	public function register_cashout_cpt() {
		$labels = array(
			'name'               => __( 'ক্যাশআউট রিকোয়েস্ট', 'woocommerce-resell-utility' ),
			'singular_name'      => __( 'ক্যাশআউট', 'woocommerce-resell-utility' ),
			'add_new'            => __( 'নতুন রিকোয়েস্ট', 'woocommerce-resell-utility' ),
			'add_new_item'       => __( 'নতুন ক্যাশআউট রিকোয়েস্ট', 'woocommerce-resell-utility' ),
			'edit_item'          => __( 'ক্যাশআউট এডিট', 'woocommerce-resell-utility' ),
			'all_items'          => __( 'সকল ক্যাশআউট', 'woocommerce-resell-utility' ),
			'search_items'       => __( 'ক্যাশআউট খুঁজুন', 'woocommerce-resell-utility' ),
			'not_found'          => __( 'কোনো ক্যাশআউট পাওয়া যায়নি', 'woocommerce-resell-utility' ),
			'not_found_in_trash' => __( 'ট্র্যাশে কোনো ক্যাশআউট নেই', 'woocommerce-resell-utility' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false, // UI is handled in our custom admin hub
			'show_in_menu'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'custom-fields' ),
		);

		register_post_type( self::CPT_CASHOUT, $args );
	}

	/**
	 * Count pending cashouts for badge display.
	 *
	 * @return int
	 */
	public static function get_pending_cashout_count() {
		$posts = get_posts( array(
			'post_type'      => self::CPT_CASHOUT,
			'post_status'    => 'pending',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		return count( $posts );
	}

	/**
	 * Register sub-menu under WooCommerce.
	 */
	public function register_admin_menu() {
		$pending_count = self::get_pending_cashout_count();
		$menu_title    = __( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' );

		if ( $pending_count > 0 ) {
			$menu_title .= sprintf(
				' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>',
				$pending_count,
				$pending_count
			);
		}

		add_submenu_page(
			'woocommerce',
			__( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' ),
			$menu_title,
			'manage_woocommerce',
			'wru-resellers',
			array( $this, 'render_admin_resellers_page' )
		);
	}

	/**
	 * Calculate full balance details for a reseller.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public static function get_reseller_balance_data( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array(
				'completed_profit'   => 0.0,
				'pending_profit'     => 0.0,
				'cancelled_penalty'  => 0.0,
				'manual_adjustments' => 0.0,
				'total_earned'       => 0.0,
				'completed_cashouts' => 0.0,
				'pending_cashouts'   => 0.0,
				'account_balance'    => 0.0,
				'available_balance'  => 0.0,
				'orders_count'       => 0,
				'completed_count'    => 0,
			);
		}

		// 1. Calculate orders profit & penalty
		$orders = wc_get_orders( array(
			'customer' => $user_id,
			'limit'    => -1,
		) );

		$cancellation_fee_rate = WRU_Settings::get_cancellation_fee();
		$completed_profit      = 0.0;
		$pending_profit        = 0.0;
		$cancelled_penalty     = 0.0;
		$orders_count          = 0;
		$completed_count       = 0;

		foreach ( $orders as $order ) {
			$profit_meta = $order->get_meta( '_wru_total_reseller_profit' );
			if ( '' === $profit_meta || false === $profit_meta ) {
				continue;
			}

			$profit = (float) $profit_meta;
			$status = $order->get_status();
			$orders_count++;

			if ( 'completed' === $status ) {
				$completed_profit += $profit;
				$completed_count++;
			} elseif ( in_array( $status, array( 'processing', 'on-hold', 'pending', 'packed', 'shipped' ), true ) ) {
				$pending_profit += $profit;
			} elseif ( in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
				$breakdown          = WRU_Order_Manager::get_order_cancellation_breakdown( $order );
				$cancelled_penalty += $breakdown['total_loss'];
			}
		}

		// 2. Calculate manual balance adjustments from ledger
		$ledger             = self::get_ledger( $user_id );
		$manual_adjustments = 0.0;
		foreach ( $ledger as $entry ) {
			if ( isset( $entry['type'] ) && isset( $entry['amount'] ) ) {
				if ( 'credit' === $entry['type'] ) {
					$manual_adjustments += (float) $entry['amount'];
				} elseif ( 'debit' === $entry['type'] ) {
					$manual_adjustments -= (float) $entry['amount'];
				}
			}
		}

		// 3. Calculate cashout totals
		$cashout_posts = get_posts( array(
			'post_type'      => self::CPT_CASHOUT,
			'post_status'    => array( 'pending', 'publish', 'completed' ),
			'meta_key'       => '_wru_reseller_id',
			'meta_value'     => $user_id,
			'posts_per_page' => -1,
		) );

		$completed_cashouts = 0.0;
		$pending_cashouts   = 0.0;

		foreach ( $cashout_posts as $cp ) {
			$amt = (float) get_post_meta( $cp->ID, '_wru_amount', true );
			if ( 'pending' === $cp->post_status ) {
				$pending_cashouts += $amt;
			} elseif ( in_array( $cp->post_status, array( 'publish', 'completed' ), true ) ) {
				$completed_cashouts += $amt;
			}
		}

		// 4. Totals
		// Total earned net = Completed profits - Cancelled penalties + Manual adjustments
		$total_earned = $completed_profit - $cancelled_penalty + $manual_adjustments;

		// Account balance = Total earned - Already paid cashouts
		$account_balance = $total_earned - $completed_cashouts;

		// Available balance for new cashout = Account balance - Pending cashouts on hold
		$available_balance = $account_balance - $pending_cashouts;

		return array(
			'completed_profit'   => (float) $completed_profit,
			'pending_profit'     => (float) $pending_profit,
			'cancelled_penalty'  => (float) $cancelled_penalty,
			'manual_adjustments' => (float) $manual_adjustments,
			'total_earned'       => (float) $total_earned,
			'completed_cashouts' => (float) $completed_cashouts,
			'pending_cashouts'   => (float) $pending_cashouts,
			'account_balance'    => (float) $account_balance,
			'available_balance'  => (float) $available_balance,
			'orders_count'       => $orders_count,
			'completed_count'    => $completed_count,
		);
	}

	/**
	 * Retrieve ledger array for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_ledger( $user_id ) {
		$ledger = get_user_meta( $user_id, '_wru_balance_ledger', true );
		if ( ! is_array( $ledger ) ) {
			return array();
		}
		return $ledger;
	}

	/**
	 * Record a manual balance adjustment into user ledger.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type 'credit' or 'debit'.
	 * @param float  $amount Amount to add or subtract.
	 * @param string $reason Note / reason for the adjustment.
	 * @param int    $admin_id Admin who made the change.
	 * @return bool
	 */
	public static function record_ledger_entry( $user_id, $type, $amount, $reason = '', $admin_id = 0 ) {
		$user_id = absint( $user_id );
		$amount  = abs( (float) $amount );

		if ( ! $user_id || $amount <= 0 ) {
			return false;
		}

		if ( ! in_array( $type, array( 'credit', 'debit' ), true ) ) {
			return false;
		}

		$current_balance_data = self::get_reseller_balance_data( $user_id );
		$balance_before       = $current_balance_data['available_balance'];

		$balance_after = ( 'credit' === $type ) ? ( $balance_before + $amount ) : ( $balance_before - $amount );

		$admin_name = 'Admin';
		if ( $admin_id ) {
			$admin_user = get_userdata( $admin_id );
			if ( $admin_user ) {
				$admin_name = $admin_user->display_name;
			}
		}

		$entry = array(
			'id'             => uniqid( 'adj_' ),
			'timestamp'      => current_time( 'timestamp' ),
			'date_formatted' => current_time( 'mysql' ),
			'type'           => $type,
			'amount'         => $amount,
			'balance_before' => $balance_before,
			'balance_after'  => $balance_after,
			'reason'         => sanitize_text_field( $reason ),
			'admin_id'       => $admin_id,
			'admin_name'     => $admin_name,
		);

		$ledger   = self::get_ledger( $user_id );
		$ledger[] = $entry;

		update_user_meta( $user_id, '_wru_balance_ledger', $ledger );
		return true;
	}

	/**
	 * Handle admin manual balance adjustment form submit.
	 */
	public function handle_balance_adjustment() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'আপনার এই পরিবর্তন করার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_adjust_balance_action', 'wru_nonce' );

		$reseller_id = isset( $_POST['reseller_id'] ) ? absint( $_POST['reseller_id'] ) : 0;
		$type        = isset( $_POST['adjustment_type'] ) ? sanitize_key( $_POST['adjustment_type'] ) : '';
		$amount      = isset( $_POST['adjustment_amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['adjustment_amount'] ) ) : 0;
		$reason      = isset( $_POST['adjustment_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['adjustment_reason'] ) ) : '';

		if ( ! $reseller_id || $amount <= 0 ) {
			wp_safe_redirect( add_query_arg( array(
				'page'    => 'wru-resellers',
				'tab'     => 'resellers',
				'message' => 'invalid_amount',
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		$admin_id = get_current_user_id();
		self::record_ledger_entry( $reseller_id, $type, $amount, $reason, $admin_id );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'resellers',
			'message' => 'balance_updated',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle Reseller frontend cashout request.
	 */
	public function handle_frontend_cashout_request() {
		if ( ! is_user_logged_in() || ! isset( $_POST['wru_request_cashout'] ) ) {
			return;
		}

		if ( ! isset( $_POST['wru_cashout_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wru_cashout_nonce'] ), 'wru_cashout_request_action' ) ) {
			wc_add_notice( __( 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$user_id = get_current_user_id();

		// Verify user has reseller role or admin
		if ( ! user_can( $user_id, self::ROLE_RESELLER ) && ! user_can( $user_id, 'manage_woocommerce' ) ) {
			wc_add_notice( __( 'শুধুমাত্র নিবন্ধিত রিসেলারগণ ক্যাশআউট রিকোয়েস্ট করতে পারবেন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$amount = isset( $_POST['wru_cashout_amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['wru_cashout_amount'] ) ) : 0.0;
		$method = isset( $_POST['wru_cashout_method'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_cashout_method'] ) ) : '';
		$number = isset( $_POST['wru_cashout_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_cashout_number'] ) ) : '';
		$notes  = isset( $_POST['wru_cashout_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wru_cashout_notes'] ) ) : '';

		if ( $amount <= 0 ) {
			wc_add_notice( __( 'সঠিক ক্যাশআউট অ্যামাউন্ট লিখুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		if ( empty( $number ) ) {
			wc_add_notice( __( 'পেমেন্ট রিসিভ করার মোবাইল বা একাউন্ট নাম্বার প্রদান করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		$balance_data      = self::get_reseller_balance_data( $user_id );
		$available_balance = $balance_data['available_balance'];

		if ( $amount > $available_balance ) {
			wc_add_notice( sprintf(
				__( 'আপনার একাউন্টে পর্যাপ্ত ব্যালেন্স নেই। সর্বোচ্চ উত্তোলনযোগ্য ব্যালেন্স: %s', 'woocommerce-resell-utility' ),
				wc_price( $available_balance )
			), 'error' );
			return;
		}

		// Insert cashout post
		$user       = get_userdata( $user_id );
		$post_title = sprintf( 'ক্যাশআউট # - %s - %s', $user->user_login, wc_price( $amount ) );

		$cashout_id = wp_insert_post( array(
			'post_title'  => $post_title,
			'post_type'   => self::CPT_CASHOUT,
			'post_status' => 'pending',
			'post_author' => $user_id,
		) );

		if ( is_wp_error( $cashout_id ) || ! $cashout_id ) {
			wc_add_notice( __( 'ক্যাশআউট রিকোয়েস্ট তৈরি করা সম্ভব হয়নি। পুনরায় চেষ্টা করুন।', 'woocommerce-resell-utility' ), 'error' );
			return;
		}

		// Save metadata
		update_post_meta( $cashout_id, '_wru_reseller_id', $user_id );
		update_post_meta( $cashout_id, '_wru_amount', $amount );
		update_post_meta( $cashout_id, '_wru_payout_method', $method );
		update_post_meta( $cashout_id, '_wru_payout_number', $number );
		update_post_meta( $cashout_id, '_wru_payout_notes', $notes );

		// Update user default payout details for next time
		update_user_meta( $user_id, '_wru_payout_method', $method );
		update_user_meta( $user_id, '_wru_payout_number', $number );

		// Notify store admin via email
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			$subject = sprintf( __( '[নতুন ক্যাশআউট রিকোয়েস্ট] %s - %s', 'woocommerce-resell-utility' ), $user->display_name, strip_tags( wc_price( $amount ) ) );
			$body    = sprintf(
				__( "নতুন ক্যাশআউট রিকোয়েস্ট জমা পড়েছে:\n\nরিসেলার: %s (%s)\nঅ্যামাউন্ট: %s\nপেমেন্ট মাধ্যম: %s\nনম্বর: %s\nনোট: %s\n\nএডমিন প্যানেলে রিভিউ ও পেমেন্ট পাঠান:\n%s", 'woocommerce-resell-utility' ),
				$user->display_name,
				$user->user_email,
				strip_tags( wc_price( $amount ) ),
				strtoupper( $method ),
				$number,
				$notes ? $notes : 'N/A',
				admin_url( 'admin.php?page=wru-resellers&tab=cashouts' )
			);
			wp_mail( $admin_email, $subject, $body );
		}

		wc_add_notice( sprintf(
			__( 'আপনার %s টাকার ক্যাশআউট রিকোয়েস্ট সফলভাবে জমা হয়েছে। এটি বর্তমানে "পেন্ডিং" অবস্থায় আছে। এডমিন পেমেন্ট পাঠানোর পর আপনার ব্যালেন্স হতে সমন্বয় হবে।', 'woocommerce-resell-utility' ),
			wc_price( $amount )
		), 'success' );

		wp_safe_redirect( wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT ) );
		exit;
	}

	/**
	 * Handle admin approving cashout (sending money & entering TrxID).
	 */
	public function handle_approve_cashout() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_approve_cashout_action', 'wru_nonce' );

		$cashout_id = isset( $_POST['cashout_id'] ) ? absint( $_POST['cashout_id'] ) : 0;
		$trx_id     = isset( $_POST['wru_trx_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_trx_id'] ) ) : '';
		$admin_note = isset( $_POST['wru_admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wru_admin_note'] ) ) : '';

		if ( ! $cashout_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'cashouts' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Update post status to completed (or publish)
		wp_update_post( array(
			'ID'          => $cashout_id,
			'post_status' => 'publish', // publish treated as completed
		) );

		update_post_meta( $cashout_id, '_wru_trx_id', $trx_id );
		update_post_meta( $cashout_id, '_wru_admin_note', $admin_note );
		update_post_meta( $cashout_id, '_wru_paid_date', current_time( 'mysql' ) );
		update_post_meta( $cashout_id, '_wru_approved_by', get_current_user_id() );

		// Notify reseller via email
		$reseller_id   = get_post_meta( $cashout_id, '_wru_reseller_id', true );
		$reseller_user = get_userdata( $reseller_id );
		$amount        = get_post_meta( $cashout_id, '_wru_amount', true );
		$method        = get_post_meta( $cashout_id, '_wru_payout_method', true );
		$number        = get_post_meta( $cashout_id, '_wru_payout_number', true );

		if ( $reseller_user && ! empty( $reseller_user->user_email ) ) {
			$subject = sprintf( __( 'আপনার ক্যাশআউট পেমেন্ট সম্পন্ন হয়েছে - %s', 'woocommerce-resell-utility' ), strip_tags( wc_price( $amount ) ) );
			$body    = sprintf(
				__( "অভিনন্দন %s,\n\nআপনার %s টাকার ক্যাশআউট সফলভাবে পরিশোধ করা হয়েছে।\n\nপেমেন্ট মাধ্যম: %s\nমোবাইল/একাউন্ট: %s\nট্রানজেকশন আইডি (TrxID): %s\nএডমিন নোট: %s\n\nআপনার রিসেলার ড্যাশবোর্ড চেক করতে লগইন করুন:\n%s", 'woocommerce-resell-utility' ),
				$reseller_user->display_name,
				strip_tags( wc_price( $amount ) ),
				strtoupper( $method ),
				$number,
				$trx_id ? $trx_id : 'N/A',
				$admin_note ? $admin_note : 'N/A',
				wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT )
			);
			wp_mail( $reseller_user->user_email, $subject, $body );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'cashouts',
			'message' => 'cashout_approved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle admin rejecting a cashout request.
	 */
	public function handle_reject_cashout() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_reject_cashout_action', 'wru_nonce' );

		$cashout_id = isset( $_POST['cashout_id'] ) ? absint( $_POST['cashout_id'] ) : 0;
		$reason     = isset( $_POST['rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';

		if ( ! $cashout_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'cashouts' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Moving to trash or custom rejected status releases the hold on the balance
		wp_trash_post( $cashout_id );
		update_post_meta( $cashout_id, '_wru_rejection_reason', $reason );
		update_post_meta( $cashout_id, '_wru_rejected_by', get_current_user_id() );

		// Notify reseller of rejection with reason
		$reseller_id   = get_post_meta( $cashout_id, '_wru_reseller_id', true );
		$reseller_user = get_userdata( $reseller_id );
		$amount        = get_post_meta( $cashout_id, '_wru_amount', true );

		if ( $reseller_user && ! empty( $reseller_user->user_email ) ) {
			$subject = sprintf( __( 'আপনার ক্যাশআউট রিকোয়েস্ট সংক্রান্ত আপডেট - %s', 'woocommerce-resell-utility' ), strip_tags( wc_price( $amount ) ) );
			$body    = sprintf(
				__( "প্রিয় %s,\n\nআপনার %s টাকার ক্যাশআউট রিকোয়েস্টটি এডমিন দ্বারা বাতিল করা হয়েছে এবং অর্থ আপনার ব্যালেন্সে ফিরিয়ে দেওয়া হয়েছে।\n\nবাতিলের কারণ: %s\n\nবিস্তারিত জানতে লগইন করুন:\n%s", 'woocommerce-resell-utility' ),
				$reseller_user->display_name,
				strip_tags( wc_price( $amount ) ),
				$reason ? $reason : 'N/A',
				wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT )
			);
			wp_mail( $reseller_user->user_email, $subject, $body );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'cashouts',
			'message' => 'cashout_rejected',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Assign reseller role to existing user.
	 */
	public function handle_assign_reseller_role() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_assign_role_action', 'wru_nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user->add_role( self::ROLE_RESELLER );
				wp_safe_redirect( add_query_arg( array(
					'page'    => 'wru-resellers',
					'tab'     => 'resellers',
					'message' => 'role_assigned',
				), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'add_reseller', 'message' => 'user_not_found' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Create a new user with reseller role.
	 */
	public function handle_create_reseller() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_create_reseller_action', 'wru_nonce' );

		$username   = isset( $_POST['reseller_username'] ) ? sanitize_user( wp_unslash( $_POST['reseller_username'] ) ) : '';
		$email      = isset( $_POST['reseller_email'] ) ? sanitize_email( wp_unslash( $_POST['reseller_email'] ) ) : '';
		$company    = isset( $_POST['reseller_company'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_company'] ) ) : '';
		$first_name = isset( $_POST['reseller_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_first_name'] ) ) : '';
		$last_name  = isset( $_POST['reseller_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_last_name'] ) ) : '';
		$phone      = isset( $_POST['reseller_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_phone'] ) ) : '';
		$password   = isset( $_POST['reseller_password'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_password'] ) ) : '';

		if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'add_reseller', 'message' => 'missing_fields' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			wp_die( esc_html( $user_id->get_error_message() ) );
		}

		$user = get_userdata( $user_id );
		$user->set_role( self::ROLE_RESELLER );

		if ( ! empty( $company ) ) {
			update_user_meta( $user_id, '_wru_reseller_company_name', $company );
			update_user_meta( $user_id, 'billing_company', $company );
		}
		if ( ! empty( $first_name ) ) {
			update_user_meta( $user_id, 'first_name', $first_name );
		}
		if ( ! empty( $last_name ) ) {
			update_user_meta( $user_id, 'last_name', $last_name );
		}
		if ( ! empty( $phone ) ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
			update_user_meta( $user_id, '_wru_reseller_phone', $phone );
			update_user_meta( $user_id, '_wru_payout_number', $phone );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'resellers',
			'message' => 'reseller_created',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle admin request to delete a reseller and clean up their records.
	 */
	public function handle_delete_reseller() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'আপনার এই কাজটি করার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_delete_reseller_action', 'wru_nonce' );

		$reseller_id   = isset( $_POST['reseller_id'] ) ? (int) $_POST['reseller_id'] : 0;
		$delete_orders = isset( $_POST['delete_orders'] ) && 'yes' === $_POST['delete_orders'];

		if ( $reseller_id <= 1 ) {
			wp_die( esc_html__( 'অবৈধ ইউজার বা অ্যাডমিন একাউন্ট ডিলিট করা সম্ভব নয়।', 'woocommerce-resell-utility' ) );
		}

		// 1. Permanently delete orders if requested by admin.
		if ( $delete_orders ) {
			$orders = wc_get_orders( array(
				'customer' => $reseller_id,
				'limit'    => -1,
			) );
			foreach ( $orders as $order ) {
				$order->delete( true );
			}
		}

		// 2. Permanently delete all cashout history for this reseller.
		$cashouts = get_posts( array(
			'post_type'   => self::CPT_CASHOUT,
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_key'    => '_wru_reseller_id',
			'meta_value'  => $reseller_id,
			'fields'      => 'ids',
		) );
		foreach ( $cashouts as $cid ) {
			wp_delete_post( $cid, true );
		}

		// 3. Delete user account and all usermeta cleanly.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $reseller_id );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'resellers',
			'message' => 'reseller_deleted',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle admin request to delete an individual cashout request.
	 */
	public function handle_delete_cashout() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'আপনার এই কাজটি করার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_delete_cashout_action', 'wru_nonce' );

		$cashout_id = isset( $_POST['cashout_id'] ) ? (int) $_POST['cashout_id'] : 0;
		if ( $cashout_id > 0 ) {
			wp_delete_post( $cashout_id, true );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'cashouts',
			'message' => 'cashout_deleted',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Automatically clean up orphan cashout posts when any user is deleted from WP.
	 *
	 * @param int      $user_id      ID of the deleted user.
	 * @param int|null $reassign_id  Reassign ID if specified.
	 */
	public function on_user_deleted( $user_id, $reassign_id = null ) {
		if ( $user_id > 1 ) {
			$cashouts = get_posts( array(
				'post_type'   => self::CPT_CASHOUT,
				'post_status' => 'any',
				'numberposts' => -1,
				'meta_key'    => '_wru_reseller_id',
				'meta_value'  => $user_id,
				'fields'      => 'ids',
			) );
			foreach ( $cashouts as $cid ) {
				wp_delete_post( $cid, true );
			}
		}
	}

	/**
	 * AJAX endpoint to return reseller ledger.
	 */
	public function ajax_get_reseller_ledger() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) ) );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'ইউজার পাওয়া যায়নি।', 'woocommerce-resell-utility' ) ) );
		}

		$user         = get_userdata( $user_id );
		$balance_data = self::get_reseller_balance_data( $user_id );
		$ledger       = self::get_ledger( $user_id );

		ob_start();
		?>
		<div class="wru-ledger-modal-content">
			<div class="wru-ledger-header">
				<h4><?php printf( esc_html__( '%s এর ব্যালেন্স স্টেটমেন্ট ও লেনদেন হিস্ট্রি', 'woocommerce-resell-utility' ), esc_html( $user->display_name ) ); ?></h4>
				<p><strong><?php esc_html_e( 'বর্তমান অবশিষ্ট ব্যালেন্স:', 'woocommerce-resell-utility' ); ?></strong> <span style="font-size:16px; color:#16a34a; font-weight:800;"><?php echo wc_price( $balance_data['available_balance'] ); ?></span></p>
			</div>

			<?php if ( empty( $ledger ) ) : ?>
				<p style="padding: 16px; background:#f8fafc; border-radius:6px; color:#64748b;"><?php esc_html_e( 'কোনো ম্যানুয়াল ব্যালেন্স সমন্বয় রেকর্ড এখনও নেই।', 'woocommerce-resell-utility' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'তারিখ ও সময়', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'লেনদেনের ধরন', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'অ্যামাউন্ট', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'পূর্বের ব্যালেন্স', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'পরবর্তী ব্যালেন্স', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'কারণ / নোট', 'woocommerce-resell-utility' ); ?></th>
							<th><?php esc_html_e( 'সম্পাদনকারী', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $ledger ) as $row ) : 
							$type_label = ( 'credit' === $row['type'] ) ? __( 'টাকা যোগ (+)', 'woocommerce-resell-utility' ) : __( 'টাকা কর্তন (-)', 'woocommerce-resell-utility' );
							$type_color = ( 'credit' === $row['type'] ) ? '#16a34a' : '#dc2626';
						?>
							<tr>
								<td><?php echo esc_html( $row['date_formatted'] ); ?></td>
								<td><strong style="color: <?php echo esc_attr( $type_color ); ?>;"><?php echo esc_html( $type_label ); ?></strong></td>
								<td><strong style="color: <?php echo esc_attr( $type_color ); ?>;"><?php echo ( 'credit' === $row['type'] ? '+' : '-' ) . wc_price( $row['amount'] ); ?></strong></td>
								<td><?php echo wc_price( $row['balance_before'] ); ?></td>
								<td><strong><?php echo wc_price( $row['balance_after'] ); ?></strong></td>
								<td><?php echo esc_html( $row['reason'] ); ?></td>
								<td><?php echo esc_html( $row['admin_name'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render Admin Resellers & Cashout Management Hub.
	 */
	public function render_admin_resellers_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'resellers';
		$pending_cnt = self::get_pending_cashout_count();

		// Success / Error messages
		$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
		?>
		<div class="wrap wru-admin-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' ); ?>
			</h1>
			<hr class="wp-header-end">

			<?php if ( 'balance_updated' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'রিসেলারের ব্যালেন্স সফলভাবে সমন্বয় করা হয়েছে এবং লেনদেন হিস্ট্রিতে রেকর্ড করা হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'cashout_approved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ক্যাশআউট পেমেন্ট সফল হিসেবে চিহ্নিত করা হয়েছে এবং ট্রানজেকশন তথ্য সংরক্ষিত হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'cashout_rejected' === $message ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'ক্যাশআউট রিকোয়েস্ট বাতিল করা হয়েছে এবং অর্থ রিসেলারের ব্যালেন্সে ফেরত দেওয়া হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'role_assigned' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ব্যবহারকারীকে সফলভাবে রিসেলার রোল দেওয়া হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'reseller_created' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'নতুন রিসেলার একাউন্ট সফলভাবে তৈরি হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'invalid_amount' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'ভুল অ্যামাউন্ট দেওয়া হয়েছে। অনুগ্রহ করে সঠিক সংখ্যা লিখুন।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php endif; ?>

			<!-- Navigation Tabs -->
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=resellers' ) ); ?>" class="nav-tab <?php echo 'resellers' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=cashouts' ) ); ?>" class="nav-tab <?php echo 'cashouts' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'ক্যাশআউট রিকোয়েস্ট', 'woocommerce-resell-utility' ); ?>
					<?php if ( $pending_cnt > 0 ) : ?>
						<span class="wru-tab-badge"><?php echo esc_html( $pending_cnt ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=add_reseller' ) ); ?>" class="nav-tab <?php echo 'add_reseller' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'নতুন রিসেলার যুক্ত করুন', 'woocommerce-resell-utility' ); ?>
				</a>
			</nav>

			<div class="wru-tab-content-area" style="margin-top: 20px;">
				<?php
				if ( 'cashouts' === $current_tab ) {
					$this->render_cashouts_tab();
				} elseif ( 'add_reseller' === $current_tab ) {
					$this->render_add_reseller_tab();
				} else {
					$this->render_resellers_tab();
				}
				?>
			</div>
		</div>

		<!-- Balance Adjustment Modal -->
		<div id="wru-adjust-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3><?php esc_html_e( 'রিসেলার ব্যালেন্স সমন্বয় করুন', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_adjust_balance_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_adjust_balance" />
					<input type="hidden" name="reseller_id" id="wru_adj_reseller_id" value="" />

					<div class="wru-modal-body">
						<div class="wru-form-group">
							<label><strong><?php esc_html_e( 'রিসেলার নাম:', 'woocommerce-resell-utility' ); ?></strong></label>
							<div id="wru_adj_reseller_name" style="font-size: 15px; color:#0f172a; margin-top:3px;"></div>
						</div>
						<div class="wru-form-group" style="margin-top: 10px;">
							<label><strong><?php esc_html_e( 'বর্তমান ব্যালেন্স:', 'woocommerce-resell-utility' ); ?></strong></label>
							<div id="wru_adj_current_balance" style="font-size: 17px; font-weight:800; color:#16a34a; margin-top:3px;"></div>
						</div>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_adj_type"><strong><?php esc_html_e( 'অ্যাকশন ধরন:', 'woocommerce-resell-utility' ); ?></strong></label>
							<select name="adjustment_type" id="wru_adj_type" class="widefat" required>
								<option value="credit"><?php esc_html_e( 'টাকা যোগ করুন (Credit / বোনাস / ম্যানুয়াল ডিপোজিট)', 'woocommerce-resell-utility' ); ?></option>
								<option value="debit"><?php esc_html_e( 'টাকা কর্তন করুন (Debit / অফলাইন পেমেন্ট / জরিমানা)', 'woocommerce-resell-utility' ); ?></option>
							</select>
						</div>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_adj_amount"><strong><?php esc_html_e( 'অ্যামাউন্ট (টাকা):', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="number" step="0.01" min="1" name="adjustment_amount" id="wru_adj_amount" class="widefat" placeholder="যেমন: 500" required />
						</div>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_adj_reason"><strong><?php esc_html_e( 'কারণ / নোট (হিস্ট্রিতে সেভ থাকবে):', 'woocommerce-resell-utility' ); ?></strong></label>
							<textarea name="adjustment_reason" id="wru_adj_reason" rows="3" class="widefat" placeholder="যেমন: অফলাইনে নগদ টাকা প্রদান করা হয়েছে অথবা ঈদ বোনাস..." required></textarea>
						</div>
					</div>
					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বাতিল', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'ব্যালেন্স আপডেট করুন', 'woocommerce-resell-utility' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- Approve Cashout (Mark Paid) Modal -->
		<div id="wru-cashout-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3><?php esc_html_e( 'ক্যাশআউট পেমেন্ট সম্পন্ন করুন', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_approve_cashout_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_approve_cashout" />
					<input type="hidden" name="cashout_id" id="wru_co_id" value="" />

					<div class="wru-modal-body">
						<p><?php esc_html_e( 'টাকা পাঠিয়ে থাকলে নিচে ট্রানজেকশন আইডি (TrxID) প্রদান করুন। এটি সাবমিট করলে রিকোয়েস্টটি সফল হিসেবে রেকর্ড হবে এবং রিসেলারের ব্যালেন্স হতে টাকা চূড়ান্তভাবে কর্তন হবে।', 'woocommerce-resell-utility' ); ?></p>
						<div class="wru-form-group" style="margin-top: 10px;">
							<label><strong><?php esc_html_e( 'রিসেলার:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_co_user"></span></label>
						</div>
						<div class="wru-form-group" style="margin-top: 6px;">
							<label><strong><?php esc_html_e( 'অ্যামাউন্ট:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_co_amount" style="font-weight:800; color:#16a34a;"></span></label>
						</div>
						<div class="wru-form-group" style="margin-top: 6px;">
							<label><strong><?php esc_html_e( 'পেমেন্ট নাম্বার ও মাধ্যম:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_co_method"></span></label>
						</div>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_trx_id"><strong><?php esc_html_e( 'ট্রানজেকশন আইডি (TrxID):', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="text" name="wru_trx_id" id="wru_trx_id" class="widefat" placeholder="যেমন: 9J21KD89L" required />
						</div>
						<div class="wru-form-group" style="margin-top: 12px;">
							<label for="wru_admin_note"><strong><?php esc_html_e( 'এডমিন নোট (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></strong></label>
							<textarea name="wru_admin_note" id="wru_admin_note" rows="2" class="widefat" placeholder="যেমন: বিকাশ পার্সোনালে টাকা পাঠানো সম্পন্ন..."></textarea>
						</div>
					</div>
					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বাতিল', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-primary" style="background:#16a34a; border-color:#15803d;"><?php esc_html_e( 'পেমেন্ট সম্পন্ন ও সেভ করুন', 'woocommerce-resell-utility' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- Reject Cashout Modal -->
		<div id="wru-reject-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3><?php esc_html_e( 'ক্যাশআউট বাতিল করুন', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_reject_cashout_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_reject_cashout" />
					<input type="hidden" name="cashout_id" id="wru_rej_id" value="" />

					<div class="wru-modal-body">
						<p><?php esc_html_e( 'রিকোয়েস্ট বাতিল করলে এই অর্থ রিসেলারের ব্যালেন্সে পুনরায় আনলক হয়ে ফেরত চলে যাবে।', 'woocommerce-resell-utility' ); ?></p>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_rej_reason"><strong><?php esc_html_e( 'বাতিল করার কারণ:', 'woocommerce-resell-utility' ); ?></strong></label>
							<textarea name="rejection_reason" id="wru_rej_reason" rows="3" class="widefat" placeholder="যেমন: নাম্বার ভুল দেওয়া হয়েছে অথবা পর্যাপ্ত ডেলিভারি বাকি..." required></textarea>
						</div>
					</div>
					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'ফিরে যান', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-secondary" style="color:#dc2626; border-color:#dc2626;"><?php esc_html_e( 'হ্যাঁ, বাতিল করুন', 'woocommerce-resell-utility' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- View Ledger Statement Modal -->
		<div id="wru-ledger-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box wru-modal-large">
				<div class="wru-modal-header">
					<h3><?php esc_html_e( 'ব্যালেন্স স্টেটমেন্ট ও লেনদেন হিস্ট্রি', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<div class="wru-modal-body" id="wru-ledger-modal-body">
					<p><?php esc_html_e( 'লোড হচ্ছে...', 'woocommerce-resell-utility' ); ?></p>
				</div>
				<div class="wru-modal-footer">
					<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বন্ধ করুন', 'woocommerce-resell-utility' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Delete Reseller Modal -->
		<div id="wru-delete-reseller-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3 style="color:#dc2626; margin:0;"><?php esc_html_e( 'রিসেলার একাউন্ট ডিলিট নিশ্চিতকরণ', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_delete_reseller_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_delete_reseller" />
					<input type="hidden" name="reseller_id" id="wru_del_reseller_id" value="" />
					
					<div class="wru-modal-body">
						<p style="font-size:14px; margin-bottom:12px;">
							<strong><?php esc_html_e( 'রিসেলার:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_del_reseller_name" style="color:#0f172a; font-weight:700;"></span>
						</p>
						
						<div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:12px; margin-bottom:15px; color:#991b1b; font-size:13px;">
							<strong><?php esc_html_e( 'সতর্কতা:', 'woocommerce-resell-utility' ); ?></strong>
							<?php esc_html_e( 'এই রিসেলারের ইউজার প্রোফাইল, ব্যালেন্স এবং সমস্ত ক্যাশআউট রিকোয়েস্ট ডাটাবেস থেকে পার্মানেন্টলি ডিলিট হয়ে যাবে।', 'woocommerce-resell-utility' ); ?>
						</div>

						<div style="margin-bottom:15px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
							<label style="font-weight:600; cursor:pointer; display:flex; align-items:flex-start; gap:8px;">
								<input type="checkbox" name="delete_orders" value="yes" style="margin-top:3px;" />
								<span>
									<?php esc_html_e( 'এই রিসেলারের সমস্ত অর্ডারও ডাটাবেস থেকে পার্মানেন্টলি মুছে ফেলুন', 'woocommerce-resell-utility' ); ?>
									<br><small style="color:#64748b; font-weight:normal;"><?php esc_html_e( 'টিক না দিলে অর্ডারগুলো অক্ষত থাকবে কিন্তু কোনো রিসেলারের সাথে আর লিঙ্ক থাকবে না।', 'woocommerce-resell-utility' ); ?></small>
								</span>
							</label>
						</div>
					</div>

					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বাতিল', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-primary" style="background:#dc2626; border-color:#b91c1c; color:#fff;">
							<?php esc_html_e( 'হ্যাঁ, পার্মানেন্টলি ডিলিট করুন', 'woocommerce-resell-utility' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Tab 1: Resellers List & Editable Balances.
	 */
	private function render_resellers_tab() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$user_args = array(
			'role'    => self::ROLE_RESELLER,
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$user_args['search']         = '*' . $search . '*';
			$user_args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}

		$resellers = get_users( $user_args );
		?>
		<div class="wru-tab-card">
			<div class="wru-card-header-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
				<h2 style="margin:0; font-size:18px;"><?php esc_html_e( 'রিসেলারদের তালিকা ও একাউন্ট ব্যালেন্স', 'woocommerce-resell-utility' ); ?></h2>
				
				<form method="get" action="" style="display:flex; gap:8px;">
					<input type="hidden" name="page" value="wru-resellers" />
					<input type="hidden" name="tab" value="resellers" />
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'রিসেলার নাম বা ইমেইল...', 'woocommerce-resell-utility' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'সার্চ করুন', 'woocommerce-resell-utility' ); ?></button>
					<?php if ( ! empty( $search ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=resellers' ) ); ?>" class="button"><?php esc_html_e( 'রিসেট', 'woocommerce-resell-utility' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( empty( $resellers ) ) : ?>
				<div class="wru-admin-empty-state">
					<p><?php esc_html_e( 'কোনো রিসেলার পাওয়া যায়নি। আপনি "নতুন রিসেলার যুক্ত করুন" ট্যাব থেকে বিদ্যমান কোনো কাস্টমারকে রিসেলার রোল দিতে পারেন অথবা নতুন রিসেলার একাউন্ট তৈরি করতে পারেন।', 'woocommerce-resell-utility' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=add_reseller' ) ); ?>" class="button button-primary"><?php esc_html_e( 'রিসেলার যুক্ত করুন', 'woocommerce-resell-utility' ); ?></a>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped table-view-list">
					<thead>
						<tr>
							<th style="width:20%;"><?php esc_html_e( 'রিসেলার পরিচিতি', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:16%;"><?php esc_html_e( 'পেআউট মেথড ও নাম্বার', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'অর্ডার সংখ্যা', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'অর্জিত মোট নিট লাভ', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'উত্তোলন সম্পন্ন', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'বর্তমান ব্যালেন্স', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:16%;"><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $resellers as $reseller ) : 
							$uid          = $reseller->ID;
							$balance_data = self::get_reseller_balance_data( $uid );
							$p_method     = get_user_meta( $uid, '_wru_payout_method', true );
							$p_number     = get_user_meta( $uid, '_wru_payout_number', true );
						?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>">
											<?php echo esc_html( $reseller->display_name ); ?>
										</a>
									</strong>
									<?php
									$r_shop = get_user_meta( $uid, '_wru_reseller_company_name', true ) ?: get_user_meta( $uid, 'billing_company', true );
									if ( ! empty( $r_shop ) ) : ?>
										<br><span style="color:#0284c7; font-weight:700; font-size:12px;"><?php printf( esc_html__( 'শপ: %s', 'woocommerce-resell-utility' ), esc_html( $r_shop ) ); ?></span>
									<?php endif; ?>
									<br>
									<span style="color:#64748b; font-size:12px;"><?php echo esc_html( $reseller->user_email ); ?></span>
									<?php 
									$phone = get_user_meta( $uid, 'billing_phone', true ) ?: get_user_meta( $uid, '_wru_reseller_phone', true );
									if ( $phone ) : ?>
										<br><span style="color:#64748b; font-size:12px;"><?php echo esc_html( $phone ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $p_number ) ) : ?>
										<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $p_method ) ) ); ?></strong>
										<br><code><?php echo esc_html( $p_number ); ?></code>
									<?php else : ?>
										<span style="color:#94a3b8; font-style:italic;"><?php esc_html_e( 'সেট করা নেই', 'woocommerce-resell-utility' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<strong><?php echo esc_html( $balance_data['completed_count'] ); ?></strong> / <?php echo esc_html( $balance_data['orders_count'] ); ?>
								</td>
								<td>
									<strong style="color:#16a34a;"><?php echo wc_price( $balance_data['total_earned'] ); ?></strong>
								</td>
								<td>
									<?php echo wc_price( $balance_data['completed_cashouts'] ); ?>
								</td>
								<td>
									<strong style="font-size:15px; color: <?php echo $balance_data['available_balance'] >= 0 ? '#16a34a' : '#dc2626'; ?>;">
										<?php echo wc_price( $balance_data['available_balance'] ); ?>
									</strong>
									<?php if ( $balance_data['available_balance'] < 0 ) : ?>
										<br><span style="color:#dc2626; font-size:11px; font-weight:700;"><?php esc_html_e( '(বকেয়া ঋণাত্মক ব্যালেন্স)', 'woocommerce-resell-utility' ); ?></span>
									<?php endif; ?>
									<?php if ( $balance_data['pending_cashouts'] > 0 ) : ?>
										<br><small style="color:#d97706; font-weight:600;">(পেন্ডিং: <?php echo wc_price( $balance_data['pending_cashouts'] ); ?>)</small>
									<?php endif; ?>
								</td>
								<td>
									<div class="wru-actions-group" style="display:flex; flex-direction:column; gap:4px;">
										<button type="button" class="button button-small button-primary wru-btn-adjust-balance" 
											data-user-id="<?php echo esc_attr( $uid ); ?>"
											data-user-name="<?php echo esc_attr( $reseller->display_name . ' (' . $reseller->user_email . ')' ); ?>"
											data-current-balance="<?php echo esc_attr( wc_price( $balance_data['available_balance'] ) ); ?>">
											<?php esc_html_e( 'ব্যালেন্স সমন্বয়', 'woocommerce-resell-utility' ); ?>
										</button>
										<button type="button" class="button button-small wru-btn-view-ledger" data-user-id="<?php echo esc_attr( $uid ); ?>">
											<?php esc_html_e( 'লেনদেন হিস্ট্রি', 'woocommerce-resell-utility' ); ?>
										</button>
										<button type="button" class="button button-small button-link-delete wru-btn-delete-reseller" 
											style="color:#dc2626; text-decoration:none; margin-top:2px;"
											data-user-id="<?php echo esc_attr( $uid ); ?>"
											data-user-name="<?php echo esc_attr( $reseller->display_name . ' (' . $reseller->user_email . ')' ); ?>"
											data-orders-count="<?php echo esc_attr( $balance_data['orders_count'] ); ?>">
											<?php esc_html_e( 'রিসেলার ডিলিট', 'woocommerce-resell-utility' ); ?>
										</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Tab 2: Cashout Requests (Pending, Completed, Rejected).
	 */
	private function render_cashouts_tab() {
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'pending';

		$query_status = ( 'all' === $status_filter ) ? array( 'pending', 'publish', 'trash' ) : ( ( 'completed' === $status_filter ) ? 'publish' : ( ( 'rejected' === $status_filter ) ? 'trash' : 'pending' ) );

		$cashout_posts = get_posts( array(
			'post_type'      => self::CPT_CASHOUT,
			'post_status'    => $query_status,
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		?>
		<div class="wru-tab-card">
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=cashouts&status=pending' ) ); ?>" class="<?php echo 'pending' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'পেন্ডিং রিকোয়েস্ট', 'woocommerce-resell-utility' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=cashouts&status=completed' ) ); ?>" class="<?php echo 'completed' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'পরিশোধিত / সম্পন্ন', 'woocommerce-resell-utility' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=cashouts&status=rejected' ) ); ?>" class="<?php echo 'rejected' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'বাতিলকৃত', 'woocommerce-resell-utility' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=cashouts&status=all' ) ); ?>" class="<?php echo 'all' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'সব রিকোয়েস্ট', 'woocommerce-resell-utility' ); ?></a></li>
			</ul>
			<div class="clear"></div>

			<?php if ( empty( $cashout_posts ) ) : ?>
				<div class="wru-admin-empty-state" style="margin-top: 20px;">
					<p><?php esc_html_e( 'এই ফিল্টারে কোনো ক্যাশআউট রিকোয়েস্ট পাওয়া যায়নি।', 'woocommerce-resell-utility' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped table-view-list" style="margin-top:16px;">
					<thead>
						<tr>
							<th style="width:12%;"><?php esc_html_e( 'রিকোয়েস্ট নং', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:20%;"><?php esc_html_e( 'রিসেলার', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'অ্যামাউন্ট', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'পেমেন্ট মাধ্যম ও নাম্বার', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'রিকোয়েস্ট তারিখ', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'স্ট্যাটাস', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cashout_posts as $post ) : 
							$reseller_id = (int) get_post_meta( $post->ID, '_wru_reseller_id', true );
							$amount      = (float) get_post_meta( $post->ID, '_wru_amount', true );
							$method      = get_post_meta( $post->ID, '_wru_payout_method', true );
							$number      = get_post_meta( $post->ID, '_wru_payout_number', true );
							$notes       = get_post_meta( $post->ID, '_wru_payout_notes', true );
							$trx_id      = get_post_meta( $post->ID, '_wru_trx_id', true );
							$admin_note  = get_post_meta( $post->ID, '_wru_admin_note', true );
							$rej_reason  = get_post_meta( $post->ID, '_wru_rejection_reason', true );
							$user        = get_userdata( $reseller_id );
							$post_status = $post->post_status;
						?>
							<tr>
								<td>
									<strong>#WRU-CO-<?php echo esc_html( $post->ID ); ?></strong>
								</td>
								<td>
									<?php if ( $user ) : ?>
										<strong><?php echo esc_html( $user->display_name ); ?></strong>
										<br><span style="font-size:12px; color:#64748b;"><?php echo esc_html( $user->user_email ); ?></span>
									<?php else : ?>
										<span style="color:#94a3b8;"><?php esc_html_e( 'ইউজার বিলুপ্ত', 'woocommerce-resell-utility' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<strong style="font-size:15px; color:#16a34a;"><?php echo wc_price( $amount ); ?></strong>
								</td>
								<td>
									<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $method ) ) ); ?></strong>
									<br><code style="font-size:13px; font-weight:700;"><?php echo esc_html( $number ); ?></code>
									<?php if ( ! empty( $notes ) ) : ?>
										<br><small style="color:#64748b;"><?php echo esc_html( $notes ); ?></small>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( get_the_date( 'd M Y, h:i A', $post->ID ) ); ?>
								</td>
								<td>
									<?php if ( 'pending' === $post_status ) : ?>
										<span class="wru-badge wru-badge-pending"><?php esc_html_e( 'পেন্ডিং', 'woocommerce-resell-utility' ); ?></span>
									<?php elseif ( 'publish' === $post_status ) : ?>
										<span class="wru-badge wru-badge-success"><?php esc_html_e( 'পরিশোধিত', 'woocommerce-resell-utility' ); ?></span>
										<?php if ( ! empty( $trx_id ) ) : ?>
											<br><small>TrxID: <code><?php echo esc_html( $trx_id ); ?></code></small>
										<?php endif; ?>
									<?php elseif ( 'trash' === $post_status ) : ?>
										<span class="wru-badge wru-badge-danger"><?php esc_html_e( 'বাতিলকৃত', 'woocommerce-resell-utility' ); ?></span>
										<?php if ( ! empty( $rej_reason ) ) : ?>
											<br><small style="color:#dc2626;"><?php echo esc_html( $rej_reason ); ?></small>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'pending' === $post_status ) : ?>
										<div style="display:flex; flex-direction:column; gap:4px;">
											<button type="button" class="button button-small button-primary wru-btn-approve-cashout" 
												data-id="<?php echo esc_attr( $post->ID ); ?>"
												data-user="<?php echo esc_attr( $user ? $user->display_name : 'User' ); ?>"
												data-amount="<?php echo esc_attr( wc_price( $amount ) ); ?>"
												data-method="<?php echo esc_attr( ucfirst( str_replace( '_', ' ', $method ) ) . ' - ' . $number ); ?>"
												style="background:#16a34a; border-color:#15803d;">
												<?php esc_html_e( 'পেমেন্ট সম্পন্ন করুন', 'woocommerce-resell-utility' ); ?>
											</button>
											<button type="button" class="button button-small wru-btn-reject-cashout" data-id="<?php echo esc_attr( $post->ID ); ?>" style="color:#dc2626;">
												<?php esc_html_e( 'বাতিল করুন', 'woocommerce-resell-utility' ); ?>
											</button>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'আপনি কি এই ক্যাশআউট রিকোয়েস্টটি ডাটাবেস থেকে মুছে ফেলতে চান?', 'woocommerce-resell-utility' ); ?>');">
												<?php wp_nonce_field( 'wru_delete_cashout_action', 'wru_nonce' ); ?>
												<input type="hidden" name="action" value="wru_delete_cashout" />
												<input type="hidden" name="cashout_id" value="<?php echo esc_attr( $post->ID ); ?>" />
												<button type="submit" class="button button-small button-link-delete" style="color:#dc2626; text-decoration:none; padding:0; font-size:11px;">
													<?php esc_html_e( 'মুছে ফেলুন', 'woocommerce-resell-utility' ); ?>
												</button>
											</form>
										</div>
									<?php else : ?>
										<div style="display:flex; flex-direction:column; gap:4px;">
											<span style="color:#64748b; font-size:12px;"><?php esc_html_e( 'সম্পন্ন হয়েছে', 'woocommerce-resell-utility' ); ?></span>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'আপনি কি এই ক্যাশআউট হিস্ট্রি রেকর্ডটি ডাটাবেস থেকে সম্পূর্ণ ডিলিট করতে চান?', 'woocommerce-resell-utility' ); ?>');">
												<?php wp_nonce_field( 'wru_delete_cashout_action', 'wru_nonce' ); ?>
												<input type="hidden" name="action" value="wru_delete_cashout" />
												<input type="hidden" name="cashout_id" value="<?php echo esc_attr( $post->ID ); ?>" />
												<button type="submit" class="button button-small button-link-delete" style="color:#dc2626; text-decoration:none; padding:0; font-size:11px;">
													<?php esc_html_e( 'রেকর্ড মুছুন', 'woocommerce-resell-utility' ); ?>
												</button>
											</form>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Tab 3: Assign or Add New Reseller.
	 */
	private function render_add_reseller_tab() {
		// Fetch existing customers who are not yet resellers
		$customers = get_users( array(
			'role__not_in' => array( self::ROLE_RESELLER ),
			'number'       => 50,
			'orderby'      => 'registered',
			'order'        => 'DESC',
		) );
		?>
		<div class="wru-add-reseller-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
			<!-- Box 1: Assign Reseller Role to existing user -->
			<div class="wru-tab-card">
				<h3 style="margin-top:0;"><?php esc_html_e( 'বিদ্যমান কাস্টমারকে "রিসেলার" রোল দিন', 'woocommerce-resell-utility' ); ?></h3>
				<p style="color:#64748b;"><?php esc_html_e( 'আপনার স্টোরে অলরেডি একাউন্ট থাকা যেকোনো গ্রাহক বা ইউজারকে রিসেলার হিসেবে রূপান্তর করতে পারেন।', 'woocommerce-resell-utility' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php wp_nonce_field( 'wru_assign_role_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_assign_role" />
					
					<div class="wru-form-group">
						<label for="wru_user_select"><strong><?php esc_html_e( 'ইউজার নির্বাচন করুন:', 'woocommerce-resell-utility' ); ?></strong></label>
						<select name="user_id" id="wru_user_select" class="widefat" required style="margin-top:6px;">
							<option value=""><?php esc_html_e( '-- ইউজার সিলেক্ট করুন --', 'woocommerce-resell-utility' ); ?></option>
							<?php foreach ( $customers as $cust ) : ?>
								<option value="<?php echo esc_attr( $cust->ID ); ?>">
									<?php echo esc_html( $cust->display_name . ' (' . $cust->user_email . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div style="margin-top:16px;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'রিসেলার রোল এসাইন করুন', 'woocommerce-resell-utility' ); ?></button>
					</div>
				</form>
			</div>

			<!-- Box 2: Create new Reseller account -->
			<div class="wru-tab-card">
				<h3 style="margin-top:0;"><?php esc_html_e( 'নতুন রিসেলার একাউন্ট তৈরি করুন', 'woocommerce-resell-utility' ); ?></h3>
				<p style="color:#64748b;"><?php esc_html_e( 'নতুন কোনো রিসেলারের তথ্য দিয়ে সরাসরি একটি "রিসেলার" একাউন্ট তৈরি করুন।', 'woocommerce-resell-utility' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php wp_nonce_field( 'wru_create_reseller_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_create_reseller" />

					<div class="wru-form-group" style="margin-bottom:12px;">
						<label><strong><?php esc_html_e( 'ইউজারনেম (Username):', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="text" name="reseller_username" class="widefat" required />
					</div>
					<div class="wru-form-group" style="margin-bottom:12px;">
						<label><strong><?php esc_html_e( 'ইমেইল (Email):', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="email" name="reseller_email" class="widefat" required />
					</div>
					<div class="wru-form-group" style="margin-bottom:12px;">
						<label><strong><?php esc_html_e( 'শপ / পেজ / কোম্পানির নাম (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="text" name="reseller_company" class="widefat" placeholder="<?php esc_attr_e( 'যেমন: Trendz Fashion BD', 'woocommerce-resell-utility' ); ?>" />
					</div>
					<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
						<div>
							<label><strong><?php esc_html_e( 'নামের প্রথম অংশ:', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="text" name="reseller_first_name" class="widefat" />
						</div>
						<div>
							<label><strong><?php esc_html_e( 'নামের শেষ অংশ:', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="text" name="reseller_last_name" class="widefat" />
						</div>
					</div>
					<div class="wru-form-group" style="margin-bottom:12px;">
						<label><strong><?php esc_html_e( 'ফোন নাম্বার:', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="text" name="reseller_phone" class="widefat" placeholder="017XXXXXXXX" />
					</div>
					<div class="wru-form-group" style="margin-bottom:16px;">
						<label><strong><?php esc_html_e( 'পাসওয়ার্ড:', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="password" name="reseller_password" class="widefat" required />
					</div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'রিসেলার তৈরি করুন', 'woocommerce-resell-utility' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
