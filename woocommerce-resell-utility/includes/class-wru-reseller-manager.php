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
	 * Check if a user is an approved reseller or store administrator/manager.
	 *
	 * @param int $user_id User ID (0 for current user).
	 * @return bool
	 */
	public static function is_reseller( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id && ! empty( $_POST['billing_email'] ) ) {
			$found_user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['billing_email'] ) ) );
			if ( $found_user ) {
				$user_id = $found_user->ID;
			}
		}
		if ( ! $user_id && function_exists( 'WC' ) && isset( WC()->checkout ) && is_object( WC()->checkout ) ) {
			$email = WC()->checkout()->get_value( 'billing_email' );
			if ( ! empty( $email ) ) {
				$found_user = get_user_by( 'email', sanitize_email( $email ) );
				if ( $found_user ) {
					$user_id = $found_user->ID;
				}
			}
		}
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}
		if ( user_can( $user_id, self::ROLE_RESELLER ) ) {
			return true;
		}
		$status = get_user_meta( $user_id, '_wru_reseller_status', true );
		if ( 'approved' === $status ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( $user && in_array( self::ROLE_RESELLER, (array) $user->roles, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get localized error message explaining why checkout order confirmation was blocked.
	 *
	 * @param int $user_id User ID (0 for current user).
	 * @return string
	 */
	public static function get_reseller_checkout_error_message( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id && ! empty( $_POST['billing_email'] ) ) {
			$found_user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['billing_email'] ) ) );
			if ( $found_user ) {
				$user_id = $found_user->ID;
			}
		}

		if ( ! $user_id ) {
			$login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
			return sprintf(
				/* translators: %s: login URL */
				__( 'অর্ডার কনফার্ম করার জন্য অনুমোদিত রিসেলার একাউন্টে লগইন থাকা আবশ্যক। আপনার যদি রিসেলার একাউন্ট না থাকে, তবে অনুগ্রহ করে <a href="%s" style="text-decoration:underline; font-weight:bold;">লগইন অথবা নতুন রিসেলার হিসেবে রেজিস্ট্রেশন</a> করুন।', 'woocommerce-resell-utility' ),
				esc_url( $login_url )
			);
		}

		$status = get_user_meta( $user_id, '_wru_reseller_status', true );
		if ( 'pending' === $status ) {
			return __( 'অর্ডার কনফার্ম করা সম্ভব নয়! আপনার রিসেলার একাউন্টের আবেদনটি বর্তমানে পেন্ডিং (অ্যাডমিন অনুমোদনের অপেক্ষায়) রয়েছে। অ্যাডমিন অনুমোদন প্রদান করলে আপনি অর্ডার করতে পারবেন।', 'woocommerce-resell-utility' );
		} elseif ( 'rejected' === $status ) {
			return __( 'অর্ডার কনফার্ম করা সম্ভব নয়! আপনার রিসেলার আবেদনটি বাতিল করা হয়েছে। বিস্তারিত তথ্যের জন্য অ্যাডমিনের সাথে যোগাযোগ করুন।', 'woocommerce-resell-utility' );
		}

		return __( 'অর্ডার কনফার্ম করা সম্ভব নয়! এই স্টোরে শুধুমাত্র অনুমোদিত রিসেলারগণই ড্রপশিপিং অর্ডার করতে পারবেন। আপনার একাউন্টে রিসেলার রোল (Reseller Role) নেই।', 'woocommerce-resell-utility' );
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
		add_action( 'admin_post_wru_approve_reseller', array( $this, 'handle_approve_reseller' ) );
		add_action( 'admin_post_wru_reject_reseller', array( $this, 'handle_reject_reseller' ) );
		add_action( 'admin_post_wru_delete_applicant', array( $this, 'handle_delete_applicant' ) );
		add_action( 'admin_post_wru_export_reseller_pdf', array( $this, 'handle_export_reseller_pdf' ) );

		// Reseller Registration on WooCommerce My Account.
		add_action( 'woocommerce_register_form_tag', array( $this, 'add_register_form_enctype' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render_registration_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration_fields' ), 10, 4 );
		add_action( 'woocommerce_created_customer', array( $this, 'handle_reseller_registration' ) );

		// Clean up physical NID files & orphan records before/after a user is deleted anywhere in WordPress.
		add_action( 'delete_user', array( $this, 'on_user_delete_action' ), 10, 1 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 2 );

		// AJAX for ledger modal.
		add_action( 'wp_ajax_wru_get_reseller_ledger', array( $this, 'ajax_get_reseller_ledger' ) );
		add_action( 'wp_ajax_wru_get_reseller_profile', array( $this, 'ajax_get_reseller_profile' ) );

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
					'read'              => true,
					self::ROLE_RESELLER => true,
					'edit_posts'        => false,
					'delete_posts'      => false,
				)
			);
		} else {
			$role = get_role( self::ROLE_RESELLER );
			if ( $role && ! $role->has_cap( self::ROLE_RESELLER ) ) {
				$role->add_cap( self::ROLE_RESELLER );
			}
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
	 * Count pending reseller applications for badge display.
	 *
	 * @return int
	 */
	public static function get_pending_reseller_count() {
		$users = get_users( array(
			'meta_key'   => '_wru_reseller_status',
			'meta_value' => 'pending',
			'fields'     => 'ID',
		) );
		return count( $users );
	}

	/**
	 * Register sub-menu under WooCommerce.
	 */
	public function register_admin_menu() {
		$pending_co_count  = self::get_pending_cashout_count();
		$pending_app_count = self::get_pending_reseller_count();
		$total_pending     = $pending_co_count + $pending_app_count;
		$menu_title        = __( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' );

		if ( $total_pending > 0 ) {
			$menu_title .= sprintf(
				' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>',
				$total_pending,
				$total_pending
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

		wp_safe_redirect( wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT_PAYOUTS ) );
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
				update_user_meta( $user_id, '_wru_reseller_status', 'approved' );
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
	 * Handle admin approving a pending reseller application.
	 */
	public function handle_approve_reseller() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_approve_reseller_action', 'wru_nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'pending_resellers' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$user = get_userdata( $user_id );
		if ( $user ) {
			// Grant reseller role
			$user->add_role( self::ROLE_RESELLER );
			update_user_meta( $user_id, '_wru_reseller_status', 'approved' );
			update_user_meta( $user_id, '_wru_reseller_approved_at', current_time( 'mysql' ) );
			update_user_meta( $user_id, '_wru_reseller_approved_by', get_current_user_id() );

			// Send congratulations email
			if ( ! empty( $user->user_email ) ) {
				$subject = __( 'অভিনন্দন! আপনার রিসেলার একাউন্ট অনুমোদিত হয়েছে', 'woocommerce-resell-utility' );
				$body    = sprintf(
					__( "প্রিয় %s,\n\nঅভিনন্দন! আমাদের প্ল্যাটফর্মে আপনার রিসেলার পার্টনার আবেদনটি সফলভাবে অনুমোদিত হয়েছে। এখন থেকে আপনি পাইকারি মূল্যে পণ্য ক্রয় ও অর্ডার করতে পারবেন এবং রিসেলার ড্যাশবোর্ড ব্যবহার করে আপনার সমস্ত লাভ ও লেনদেন ট্র্যাক করতে পারবেন।\n\nএখনই লগইন করে আপনার রিসেলার ড্যাশবোর্ড ভিজিট করুন:\n%s\n\nধন্যবাদ সাথে থাকার জন্য!", 'woocommerce-resell-utility' ),
					$user->display_name,
					wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT )
				);
				wp_mail( $user->user_email, $subject, $body );
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'pending_resellers',
			'message' => 'reseller_approved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle admin rejecting a pending reseller application.
	 */
	public function handle_reject_reseller() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_reject_reseller_action', 'wru_nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$reason  = isset( $_POST['rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';

		if ( ! $user_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wru-resellers', 'tab' => 'pending_resellers' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$user = get_userdata( $user_id );
		if ( $user ) {
			// Revoke reseller role if present
			$user->remove_role( self::ROLE_RESELLER );
			update_user_meta( $user_id, '_wru_reseller_status', 'rejected' );
			update_user_meta( $user_id, '_wru_reseller_rejected_at', current_time( 'mysql' ) );
			update_user_meta( $user_id, '_wru_reseller_rejection_reason', $reason );

			if ( ! empty( $user->user_email ) ) {
				$subject = __( 'আপনার রিসেলার আবেদন সংক্রান্ত তথ্য', 'woocommerce-resell-utility' );
				$body    = sprintf(
					__( "প্রিয় %s,\n\nআপনার রিসেলার পার্টনার আবেদনটি পর্যালোচনার পর এই মুহূর্তে অনুমোদন করা সম্ভব হয়নি।\n\nবাতিলের কারণ: %s\n\nসঠিক তথ্য প্রদান করে পুনরায় যোগাযোগ করতে পারেন।\nধন্যবাদ।", 'woocommerce-resell-utility' ),
					$user->display_name,
					$reason ? $reason : __( 'প্রদত্ত ডকুমেন্টস বা তথ্য অসম্পূর্ণ ছিল।', 'woocommerce-resell-utility' )
				);
				wp_mail( $user->user_email, $subject, $body );
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'pending_resellers',
			'message' => 'reseller_rejected',
		), admin_url( 'admin.php' ) ) );
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
		$wa         = isset( $_POST['reseller_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['reseller_whatsapp'] ) ) : '';
		$store_url  = isset( $_POST['reseller_store_url'] ) ? esc_url_raw( wp_unslash( $_POST['reseller_store_url'] ) ) : '';
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
		if ( ! empty( $wa ) ) {
			update_user_meta( $user_id, '_wru_reseller_whatsapp', $wa );
		}
		if ( ! empty( $store_url ) ) {
			update_user_meta( $user_id, '_wru_reseller_store_url', $store_url );
		}

		// Handle NID Document Uploads (if admin attached them)
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$upload_overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'pdf'          => 'application/pdf',
			),
		);

		if ( ! empty( $_FILES['reseller_nid_front']['name'] ) && empty( $_FILES['reseller_nid_front']['error'] ) ) {
			$upload_front = wp_handle_upload( $_FILES['reseller_nid_front'], $upload_overrides );
			if ( ! empty( $upload_front['url'] ) ) {
				update_user_meta( $user_id, '_wru_nid_front_url', $upload_front['url'] );
				update_user_meta( $user_id, '_wru_nid_front_file', $upload_front['file'] );
			}
		}

		if ( ! empty( $_FILES['reseller_nid_back']['name'] ) && empty( $_FILES['reseller_nid_back']['error'] ) ) {
			$upload_back = wp_handle_upload( $_FILES['reseller_nid_back'], $upload_overrides );
			if ( ! empty( $upload_back['url'] ) ) {
				update_user_meta( $user_id, '_wru_nid_back_url', $upload_back['url'] );
				update_user_meta( $user_id, '_wru_nid_back_file', $upload_back['file'] );
			}
		}

		update_user_meta( $user_id, '_wru_reseller_status', 'approved' );
		update_user_meta( $user_id, '_wru_reseller_approved_at', current_time( 'mysql' ) );
		update_user_meta( $user_id, '_wru_reseller_approved_by', get_current_user_id() );

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

		// 2. Permanently delete physical NID files, cashout history, and usermeta.
		self::clean_reseller_files_and_records( $reseller_id );

		// 3. Delete user account.
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
	 * Handle admin request to delete a pending/rejected applicant.
	 */
	public function handle_delete_applicant() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'আপনার এই কাজটি করার অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_delete_applicant_action', 'wru_nonce' );

		$applicant_id = isset( $_POST['applicant_id'] ) ? (int) $_POST['applicant_id'] : ( isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0 );

		if ( $applicant_id <= 1 ) {
			wp_die( esc_html__( 'অবৈধ আবেদনকারী বা অ্যাডমিন একাউন্ট ডিলিট করা সম্ভব নয়।', 'woocommerce-resell-utility' ) );
		}

		// Clean up physical NID files, cashouts, and meta
		self::clean_reseller_files_and_records( $applicant_id );

		// Delete user account
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $applicant_id );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'wru-resellers',
			'tab'     => 'pending_resellers',
			'message' => 'applicant_deleted',
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
	 * Permanently delete physical NID image files, associated cashouts, and user meta for a user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function clean_reseller_files_and_records( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id <= 1 ) {
			return;
		}

		// 1. Delete physical NID front file
		$front_file = get_user_meta( $user_id, '_wru_nid_front_file', true );
		if ( ! empty( $front_file ) && file_exists( $front_file ) ) {
			@unlink( $front_file );
		}
		$front_url = get_user_meta( $user_id, '_wru_nid_front_url', true );
		if ( ! empty( $front_url ) ) {
			self::delete_file_by_upload_url( $front_url );
		}

		// 2. Delete physical NID back file
		$back_file = get_user_meta( $user_id, '_wru_nid_back_file', true );
		if ( ! empty( $back_file ) && file_exists( $back_file ) ) {
			@unlink( $back_file );
		}
		$back_url = get_user_meta( $user_id, '_wru_nid_back_url', true );
		if ( ! empty( $back_url ) ) {
			self::delete_file_by_upload_url( $back_url );
		}

		// 3. Delete any cashout posts associated with this user
		$cashouts = get_posts( array(
			'post_type'   => self::CPT_CASHOUT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
				'relation' => 'OR',
				array(
					'key'   => '_wru_reseller_id',
					'value' => $user_id,
				),
			),
		) );
		foreach ( $cashouts as $cid ) {
			wp_delete_post( $cid, true );
		}

		$author_cashouts = get_posts( array(
			'post_type'   => self::CPT_CASHOUT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'author'      => $user_id,
		) );
		foreach ( $author_cashouts as $acid ) {
			wp_delete_post( $acid, true );
		}

		// 4. Clean all _wru_ usermeta entries
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, '_wru_%' ) );
	}

	/**
	 * Helper to delete a file given its upload URL if inside the WordPress uploads directory.
	 *
	 * @param string $file_url File URL.
	 */
	public static function delete_file_by_upload_url( $file_url ) {
		if ( empty( $file_url ) ) {
			return;
		}
		$upload_dir = wp_upload_dir();
		$base_url   = set_url_scheme( $upload_dir['baseurl'] );
		$base_dir   = $upload_dir['basedir'];
		$norm_url   = set_url_scheme( $file_url );

		if ( 0 === strpos( $norm_url, $base_url ) ) {
			$rel_path  = substr( $norm_url, strlen( $base_url ) );
			$full_path = wp_normalize_path( $base_dir . $rel_path );
			if ( file_exists( $full_path ) ) {
				@unlink( $full_path );
			}
		}
	}

	/**
	 * Hooked to core 'delete_user' (fires before user & meta are deleted) to remove NID files and records.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public function on_user_delete_action( $user_id ) {
		self::clean_reseller_files_and_records( $user_id );
	}

	/**
	 * Export single Reseller / Applicant full data dossier as printable A4 PDF layout.
	 */
	public function handle_export_reseller_pdf() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_die( esc_html__( 'ইউজার আইডি প্রদান করা হয়নি।', 'woocommerce-resell-utility' ) );
		}

		check_admin_referer( 'wru_export_pdf_' . $user_id );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'ব্যবহারকারী পাওয়া যায়নি।', 'woocommerce-resell-utility' ) );
		}

		$balance_data  = self::get_reseller_balance_data( $user_id );
		$status        = get_user_meta( $user_id, '_wru_reseller_status', true ) ?: ( in_array( self::ROLE_RESELLER, (array) $user->roles, true ) ? 'approved' : 'pending' );
		$company       = get_user_meta( $user_id, '_wru_reseller_company_name', true ) ?: get_user_meta( $user_id, 'billing_company', true );
		$phone         = get_user_meta( $user_id, '_wru_reseller_phone', true ) ?: get_user_meta( $user_id, 'billing_phone', true );
		$store_url     = get_user_meta( $user_id, '_wru_reseller_store_url', true );
		$wa            = get_user_meta( $user_id, '_wru_reseller_whatsapp', true );
		$nid_front      = get_user_meta( $user_id, '_wru_nid_front_url', true );
		$nid_back       = get_user_meta( $user_id, '_wru_nid_back_url', true );
		$nid_front_file = get_user_meta( $user_id, '_wru_nid_front_file', true );
		$nid_back_file  = get_user_meta( $user_id, '_wru_nid_back_file', true );

		// Convert local image files to base64 Data URIs for 100% reliable PDF rendering & printing
		$nid_front_src = $nid_front;
		if ( ! empty( $nid_front_file ) && file_exists( $nid_front_file ) && ! preg_match( '/\.(pdf)$/i', $nid_front_file ) ) {
			$mime_front = wp_check_filetype( $nid_front_file )['type'] ?: 'image/jpeg';
			$f_data     = @file_get_contents( $nid_front_file );
			if ( $f_data ) {
				$nid_front_src = 'data:' . $mime_front . ';base64,' . base64_encode( $f_data );
			}
		}

		$nid_back_src = $nid_back;
		if ( ! empty( $nid_back_file ) && file_exists( $nid_back_file ) && ! preg_match( '/\.(pdf)$/i', $nid_back_file ) ) {
			$mime_back = wp_check_filetype( $nid_back_file )['type'] ?: 'image/jpeg';
			$b_data    = @file_get_contents( $nid_back_file );
			if ( $b_data ) {
				$nid_back_src = 'data:' . $mime_back . ';base64,' . base64_encode( $b_data );
			}
		}

		$applied_at    = get_user_meta( $user_id, '_wru_reseller_applied_at', true ) ?: $user->user_registered;
		$approved_at   = get_user_meta( $user_id, '_wru_reseller_approved_at', true );
		$approved_by   = get_user_meta( $user_id, '_wru_reseller_approved_by', true );
		$approver      = $approved_by ? get_userdata( $approved_by ) : null;
		$rej_reason    = get_user_meta( $user_id, '_wru_reseller_rejection_reason', true );
		$payout_method = get_user_meta( $user_id, '_wru_payout_method', true );
		$payout_number = get_user_meta( $user_id, '_wru_payout_number', true );
		$payout_notes  = get_user_meta( $user_id, '_wru_payout_notes', true );
		$site_name     = get_bloginfo( 'name' );
		$export_time   = current_time( 'd M Y, h:i A' );

		// Status Badge Labels
		$status_labels = array(
			'approved' => array( 'label' => __( 'অনুমোদিত রিসেলার (Active Reseller)', 'woocommerce-resell-utility' ), 'color' => '#16a34a', 'bg' => '#dcfce7' ),
			'pending'  => array( 'label' => __( 'পেন্ডিং আবেদন (Pending Application)', 'woocommerce-resell-utility' ), 'color' => '#d97706', 'bg' => '#fef3c7' ),
			'rejected' => array( 'label' => __( 'বাতিলকৃত আবেদন (Rejected)', 'woocommerce-resell-utility' ), 'color' => '#dc2626', 'bg' => '#fee2e2' ),
		);
		$current_badge = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : array( 'label' => ucfirst( $status ), 'color' => '#64748b', 'bg' => '#f1f5f9' );
		?>
		<!DOCTYPE html>
		<html lang="bn">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( sprintf( 'Reseller_Dossier_%s_%s', $user->user_login, date( 'Ymd' ) ) ); ?></title>
			<style>
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					background: #f1f5f9;
					margin: 0;
					padding: 24px;
					color: #0f172a;
					font-size: 13.5px;
					line-height: 1.5;
				}
				.pdf-container {
					max-width: 800px;
					margin: 0 auto;
					background: #ffffff;
					padding: 36px 40px;
					border-radius: 8px;
					box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
				}
				.pdf-toolbar {
					max-width: 800px;
					margin: 0 auto 16px auto;
					display: flex;
					justify-content: space-between;
					align-items: center;
				}
				.btn-print {
					background: #0284c7;
					color: #ffffff;
					padding: 8px 20px;
					border: none;
					border-radius: 6px;
					font-weight: 700;
					cursor: pointer;
					font-size: 14px;
				}
				.btn-print:hover { background: #0369a1; }
				.btn-close {
					background: #64748b;
					color: #ffffff;
					padding: 8px 18px;
					border: none;
					border-radius: 6px;
					font-weight: 600;
					cursor: pointer;
					font-size: 14px;
				}
				.pdf-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					border-bottom: 2px solid #0f172a;
					padding-bottom: 16px;
					margin-bottom: 24px;
				}
				.pdf-title {
					font-size: 20px;
					font-weight: 800;
					color: #0f172a;
					margin: 0 0 4px 0;
				}
				.pdf-subtitle {
					font-size: 12.5px;
					color: #475569;
					margin: 0;
				}
				.status-badge {
					display: inline-block;
					padding: 6px 14px;
					border-radius: 20px;
					font-weight: 800;
					font-size: 12px;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.section-title {
					font-size: 14px;
					font-weight: 700;
					color: #1e293b;
					border-bottom: 1.5px solid #e2e8f0;
					padding-bottom: 6px;
					margin: 20px 0 12px 0;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.info-table {
					width: 100%;
					border-collapse: collapse;
					margin-bottom: 16px;
				}
				.info-table th {
					width: 28%;
					text-align: left;
					padding: 8px 10px;
					background: #f8fafc;
					color: #475569;
					font-size: 12.5px;
					border: 1px solid #e2e8f0;
				}
				.info-table td {
					padding: 8px 10px;
					border: 1px solid #e2e8f0;
					font-size: 13px;
				}
				.stats-grid {
					display: grid;
					grid-template-columns: repeat(4, 1fr);
					gap: 10px;
					margin-bottom: 16px;
				}
				.stat-box {
					background: #f8fafc;
					border: 1px solid #cbd5e1;
					border-radius: 6px;
					padding: 10px 12px;
					text-align: center;
				}
				.stat-box span {
					display: block;
					font-size: 11px;
					color: #64748b;
					font-weight: 600;
					margin-bottom: 2px;
				}
				.stat-box strong {
					font-size: 15px;
					font-weight: 800;
					color: #0f172a;
				}
				.nid-grid {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 16px;
					margin-top: 10px;
				}
				.nid-card {
					border: 1px solid #cbd5e1;
					border-radius: 8px;
					padding: 10px;
					text-align: center;
					background: #fafafa;
				}
				.nid-card h4 {
					margin: 0 0 8px 0;
					font-size: 12px;
					color: #334155;
				}
				.nid-card img {
					max-width: 100%;
					max-height: 180px;
					object-fit: contain;
					border-radius: 4px;
					border: 1px solid #e2e8f0;
				}
				.pdf-footer {
					margin-top: 32px;
					padding-top: 14px;
					border-top: 1px solid #e2e8f0;
					display: flex;
					justify-content: space-between;
					font-size: 11px;
					color: #94a3b8;
				}
				@media print {
					body { background: #ffffff; padding: 0; }
					.pdf-container { box-shadow: none; padding: 0; max-width: 100%; }
					.pdf-toolbar { display: none !important; }
					@page { size: A4 portrait; margin: 12mm; }
				}
			</style>
		</head>
		<body>
			<div class="pdf-toolbar">
				<button type="button" class="btn-print" onclick="window.print();"><?php esc_html_e( 'প্রিন্ট / Save as PDF', 'woocommerce-resell-utility' ); ?></button>
				<button type="button" class="btn-close" onclick="window.close();"><?php esc_html_e( 'বন্ধ করুন', 'woocommerce-resell-utility' ); ?></button>
			</div>

			<div class="pdf-container">
				<div class="pdf-header">
					<div>
						<h1 class="pdf-title"><?php echo esc_html( $site_name ); ?></h1>
						<p class="pdf-subtitle"><?php esc_html_e( 'রিসেলার পার্টনার অফিশিয়াল পরিচিতি ও প্রোফাইল ডাটাবেজ', 'woocommerce-resell-utility' ); ?></p>
						<p style="margin:4px 0 0; font-size:11px; color:#64748b;">Dossier Ref: #RES-<?php echo esc_html( $user_id ); ?> | Date: <?php echo esc_html( $export_time ); ?></p>
					</div>
					<div style="text-align:right;">
						<span class="status-badge" style="background:<?php echo esc_attr( $current_badge['bg'] ); ?>; color:<?php echo esc_attr( $current_badge['color'] ); ?>;">
							<?php echo esc_html( $current_badge['label'] ); ?>
						</span>
					</div>
				</div>

				<div class="section-title"><?php esc_html_e( '১। ব্যক্তিগত ও যোগাযোগের তথ্য', 'woocommerce-resell-utility' ); ?></div>
				<table class="info-table">
					<tr>
						<th><?php esc_html_e( 'পূর্ণ নাম', 'woocommerce-resell-utility' ); ?></th>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
						<th><?php esc_html_e( 'ইউজারনেম', 'woocommerce-resell-utility' ); ?></th>
						<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'ইমেইল এড্রেস', 'woocommerce-resell-utility' ); ?></th>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<th><?php esc_html_e( 'মোবাইল নম্বর', 'woocommerce-resell-utility' ); ?></th>
						<td><strong><?php echo esc_html( $phone ?: __( 'দেওয়া হয়নি', 'woocommerce-resell-utility' ) ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'শপ / ব্র্যান্ডের নাম', 'woocommerce-resell-utility' ); ?></th>
						<td><?php echo esc_html( $company ?: __( 'দেওয়া হয়নি', 'woocommerce-resell-utility' ) ); ?></td>
						<th>WhatsApp</th>
						<td><?php echo esc_html( $wa ?: __( 'দেওয়া হয়নি', 'woocommerce-resell-utility' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'ফেসবুক / স্টোর লিংক', 'woocommerce-resell-utility' ); ?></th>
						<td colspan="3"><?php echo $store_url ? '<a href="' . esc_url( $store_url ) . '">' . esc_html( $store_url ) . '</a>' : esc_html__( 'দেওয়া হয়নি', 'woocommerce-resell-utility' ); ?></td>
					</tr>
				</table>

				<div class="section-title"><?php esc_html_e( '২। একাউন্ট টাইমলাইন ও অনুমোদন তথ্য', 'woocommerce-resell-utility' ); ?></div>
				<table class="info-table">
					<tr>
						<th><?php esc_html_e( 'আবেদনের তারিখ', 'woocommerce-resell-utility' ); ?></th>
						<td><?php echo esc_html( date_i18n( 'd M Y, h:i A', strtotime( $applied_at ) ) ); ?></td>
						<th><?php esc_html_e( 'বর্তমান স্ট্যাটাস', 'woocommerce-resell-utility' ); ?></th>
						<td><strong><?php echo esc_html( ucfirst( $status ) ); ?></strong></td>
					</tr>
					<?php if ( ! empty( $approved_at ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'অনুমোদনের তারিখ', 'woocommerce-resell-utility' ); ?></th>
							<td><?php echo esc_html( date_i18n( 'd M Y, h:i A', strtotime( $approved_at ) ) ); ?></td>
							<th><?php esc_html_e( 'অনুমোদনকারী', 'woocommerce-resell-utility' ); ?></th>
							<td><?php echo esc_html( $approver ? $approver->display_name : 'Admin' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $rej_reason ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'বাতিলের কারণ', 'woocommerce-resell-utility' ); ?></th>
							<td colspan="3" style="color:#dc2626;"><?php echo esc_html( $rej_reason ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<?php if ( 'approved' === $status ) : ?>
					<div class="section-title"><?php esc_html_e( '৩। আর্থিক বিবরণী ও ব্যালেন্স সামারি', 'woocommerce-resell-utility' ); ?></div>
					<div class="stats-grid">
						<div class="stat-box" style="border-left: 3px solid #16a34a;">
							<span><?php esc_html_e( 'উত্তোলনযোগ্য ব্যালেন্স', 'woocommerce-resell-utility' ); ?></span>
							<strong style="color:<?php echo $balance_data['available_balance'] >= 0 ? '#16a34a' : '#dc2626'; ?>;">
								<?php echo wc_price( $balance_data['available_balance'] ); ?>
							</strong>
						</div>
						<div class="stat-box" style="border-left: 3px solid #0284c7;">
							<span><?php esc_html_e( 'সর্বমোট অর্জিত নিট লাভ', 'woocommerce-resell-utility' ); ?></span>
							<strong><?php echo wc_price( $balance_data['total_earned'] ); ?></strong>
						</div>
						<div class="stat-box" style="border-left: 3px solid #64748b;">
							<span><?php esc_html_e( 'মোট ডেলিভারি অর্ডার', 'woocommerce-resell-utility' ); ?></span>
							<strong><?php echo esc_html( $balance_data['completed_count'] . ' / ' . $balance_data['orders_count'] ); ?></strong>
						</div>
						<div class="stat-box" style="border-left: 3px solid #d97706;">
							<span><?php esc_html_e( 'পরিশোধিত ক্যাশআউট', 'woocommerce-resell-utility' ); ?></span>
							<strong><?php echo wc_price( $balance_data['completed_cashouts'] ); ?></strong>
						</div>
					</div>

					<div class="section-title"><?php esc_html_e( '৪। পেমেন্ট ও পেআউট সেটিংস', 'woocommerce-resell-utility' ); ?></div>
					<table class="info-table">
						<tr>
							<th><?php esc_html_e( 'পেআউট মাধ্যম', 'woocommerce-resell-utility' ); ?></th>
							<td><strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $payout_method ) ) ?: __( 'সেট করা নেই', 'woocommerce-resell-utility' ) ); ?></strong></td>
							<th><?php esc_html_e( 'একাউন্ট / মোবাইল নম্বর', 'woocommerce-resell-utility' ); ?></th>
							<td><code><?php echo esc_html( $payout_number ?: __( 'সেট করা নেই', 'woocommerce-resell-utility' ) ); ?></code></td>
						</tr>
						<?php if ( ! empty( $payout_notes ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'পেমেন্ট সংক্রান্ত বিশেষ নোট', 'woocommerce-resell-utility' ); ?></th>
								<td colspan="3"><?php echo esc_html( $payout_notes ); ?></td>
							</tr>
						<?php endif; ?>
					</table>
				<?php endif; ?>

				<div class="section-title"><?php esc_html_e( 'NID কার্ড ডকুমেন্টস (National ID Card)', 'woocommerce-resell-utility' ); ?></div>
				<div class="nid-grid">
					<div class="nid-card">
						<h4><?php esc_html_e( 'NID কার্ডের সামনের অংশ (Front)', 'woocommerce-resell-utility' ); ?></h4>
						<?php if ( ! empty( $nid_front_src ) ) : ?>
							<?php if ( preg_match( '/\.(pdf)$/i', $nid_front ) ) : ?>
								<p><a href="<?php echo esc_url( $nid_front ); ?>" target="_blank" class="button"><?php esc_html_e( 'View / Download PDF Document', 'woocommerce-resell-utility' ); ?></a></p>
							<?php else : ?>
								<img src="<?php echo esc_attr( $nid_front_src ); ?>" alt="NID Front" style="max-width:100%; max-height:220px; object-fit:contain; border-radius:6px; border:1px solid #cbd5e1;" />
							<?php endif; ?>
						<?php else : ?>
							<p style="color:#94a3b8; font-style:italic; padding:30px 0;"><?php esc_html_e( 'কোনো NID ছবি পাওয়া যায়নি', 'woocommerce-resell-utility' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="nid-card">
						<h4><?php esc_html_e( 'NID কার্ডের পেছনের অংশ (Back)', 'woocommerce-resell-utility' ); ?></h4>
						<?php if ( ! empty( $nid_back_src ) ) : ?>
							<?php if ( preg_match( '/\.(pdf)$/i', $nid_back ) ) : ?>
								<p><a href="<?php echo esc_url( $nid_back ); ?>" target="_blank" class="button"><?php esc_html_e( 'View / Download PDF Document', 'woocommerce-resell-utility' ); ?></a></p>
							<?php else : ?>
								<img src="<?php echo esc_attr( $nid_back_src ); ?>" alt="NID Back" style="max-width:100%; max-height:220px; object-fit:contain; border-radius:6px; border:1px solid #cbd5e1;" />
							<?php endif; ?>
						<?php else : ?>
							<p style="color:#94a3b8; font-style:italic; padding:30px 0;"><?php esc_html_e( 'কোনো NID ছবি পাওয়া যায়নি', 'woocommerce-resell-utility' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="pdf-footer">
					<span><?php echo esc_html( sprintf( 'Generated automatically by WooCommerce Resell Utility for %s', $site_name ) ); ?></span>
					<span><?php esc_html_e( 'গোপনীয় ও অফিসিয়াল ব্যবহারের জন্য সংরক্ষিত', 'woocommerce-resell-utility' ); ?></span>
				</div>
			</div>
		</body>
		</html>
		<?php
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
	 * AJAX endpoint to return complete reseller profile and NID data for admin modal.
	 */
	public function ajax_get_reseller_profile() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'অনুমতি নেই।', 'woocommerce-resell-utility' ) ) );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'ইউজার পাওয়া যায়নি।', 'woocommerce-resell-utility' ) ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'ব্যবহারকারী ডাটাবেসে নেই।', 'woocommerce-resell-utility' ) ) );
		}

		$balance_data = self::get_reseller_balance_data( $user_id );
		$company      = get_user_meta( $user_id, '_wru_reseller_company_name', true ) ?: get_user_meta( $user_id, 'billing_company', true );
		$phone        = get_user_meta( $user_id, '_wru_reseller_phone', true ) ?: get_user_meta( $user_id, 'billing_phone', true );
		$wa           = get_user_meta( $user_id, '_wru_reseller_whatsapp', true );
		$store_url    = get_user_meta( $user_id, '_wru_reseller_store_url', true );
		$nid_front    = get_user_meta( $user_id, '_wru_nid_front_url', true );
		$nid_back     = get_user_meta( $user_id, '_wru_nid_back_url', true );
		$status       = get_user_meta( $user_id, '_wru_reseller_status', true ) ?: ( in_array( self::ROLE_RESELLER, (array) $user->roles, true ) ? 'approved' : 'pending' );
		$p_method     = get_user_meta( $user_id, '_wru_payout_method', true );
		$p_number     = get_user_meta( $user_id, '_wru_payout_number', true );
		$p_notes      = get_user_meta( $user_id, '_wru_payout_notes', true );
		$applied_at   = get_user_meta( $user_id, '_wru_reseller_applied_at', true ) ?: $user->user_registered;
		$rej_reason   = get_user_meta( $user_id, '_wru_reseller_rejection_reason', true );
		$pdf_url      = wp_nonce_url( admin_url( 'admin-post.php?action=wru_export_reseller_pdf&user_id=' . $user_id ), 'wru_export_pdf_' . $user_id );

		$response = array(
			'id'           => $user_id,
			'name'         => $user->display_name,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'phone'        => (string) $phone,
			'company'      => (string) $company,
			'whatsapp'     => (string) $wa,
			'store_url'    => (string) $store_url,
			'nid_front'    => (string) $nid_front,
			'nid_back'     => (string) $nid_back,
			'registered'   => date_i18n( 'd M Y, h:i A', strtotime( $user->user_registered ) ),
			'applied_at'   => date_i18n( 'd M Y, h:i A', strtotime( $applied_at ) ),
			'orders_count' => (int) $balance_data['orders_count'],
			'completed'    => (int) $balance_data['completed_count'],
			'earned'       => wc_price( $balance_data['total_earned'] ),
			'withdrawn'    => wc_price( $balance_data['completed_cashouts'] ),
			'balance'      => wc_price( $balance_data['available_balance'] ),
			'raw_balance'  => (float) $balance_data['available_balance'],
			'status'       => $status,
			'p_method'     => $p_method ? ucfirst( str_replace( '_', ' ', $p_method ) ) : __( 'সেট করা নেই', 'woocommerce-resell-utility' ),
			'p_number'     => (string) $p_number,
			'p_notes'      => (string) $p_notes,
			'rej_reason'   => (string) $rej_reason,
			'pdf_url'      => $pdf_url,
		);

		wp_send_json_success( $response );
	}

	/**
	 * Render Admin Resellers & Cashout Management Hub.
	 */
	public function render_admin_resellers_page() {
		$current_tab       = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'resellers';
		$pending_cnt       = self::get_pending_cashout_count();
		$pending_app_count = self::get_pending_reseller_count();

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
			<?php elseif ( 'reseller_approved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'রিসেলার আবেদন সফলভাবে অনুমোদন করা হয়েছে এবং রিসেলার রোল প্রদান করা হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'reseller_rejected' === $message ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'রিসেলার আবেদনটি বাতিল করা হয়েছে এবং কারণ জানিয়ে ইমেইল পাঠানো হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'reseller_deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'রিসেলার একাউন্ট, আপলোডকৃত NID ফাইল এবং সংশ্লিষ্ট ডাটা সম্পূর্ণ মুছে ফেলা হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'applicant_deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'আবেদনকারী, আপলোডকৃত NID কার্ডের ছবি এবং যাবতীয় তথ্য সার্ভার থেকে স্থায়ীভাবে মুছে ফেলা হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'cashout_deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ক্যাশআউট রিকোয়েস্ট সফলভাবে মুছে ফেলা হয়েছে।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php elseif ( 'invalid_amount' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'ভুল অ্যামাউন্ট দেওয়া হয়েছে। অনুগ্রহ করে সঠিক সংখ্যা লিখুন।', 'woocommerce-resell-utility' ); ?></p></div>
			<?php endif; ?>

			<!-- Navigation Tabs -->
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=resellers' ) ); ?>" class="nav-tab <?php echo 'resellers' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'রিসেলার তালিকা ও ব্যালেন্স', 'woocommerce-resell-utility' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=pending_resellers' ) ); ?>" class="nav-tab <?php echo 'pending_resellers' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'পেন্ডিং রিসেলার', 'woocommerce-resell-utility' ); ?>
					<?php if ( $pending_app_count > 0 ) : ?>
						<span class="wru-tab-badge" style="background:#d97706;"><?php echo esc_html( $pending_app_count ); ?></span>
					<?php endif; ?>
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
				if ( 'pending_resellers' === $current_tab ) {
					$this->render_pending_resellers_tab();
				} elseif ( 'cashouts' === $current_tab ) {
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

		<!-- Reject Reseller Application Modal -->
		<div id="wru-reject-applicant-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3><?php esc_html_e( 'রিসেলার আবেদন বাতিল করুন', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_reject_reseller_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_reject_reseller" />
					<input type="hidden" name="user_id" id="wru_rej_applicant_id" value="" />

					<div class="wru-modal-body">
						<p><?php esc_html_e( 'আবেদন বাতিল করলে আবেদনকারীকে কারণ সম্বলিত একটি ইমেইল পাঠানো হবে।', 'woocommerce-resell-utility' ); ?></p>
						<div class="wru-form-group" style="margin-top: 10px;">
							<label><strong><?php esc_html_e( 'আবেদনকারী:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_rej_applicant_name" style="font-weight:700; color:#0f172a;"></span></label>
						</div>
						<div class="wru-form-group" style="margin-top: 15px;">
							<label for="wru_applicant_rej_reason"><strong><?php esc_html_e( 'বাতিল করার কারণ / বিস্তারিত নোট:', 'woocommerce-resell-utility' ); ?></strong></label>
							<textarea name="rejection_reason" id="wru_applicant_rej_reason" rows="3" class="widefat" placeholder="<?php esc_attr_e( 'যেমন: NID ছবি অস্পষ্ট বা ভুল ফেসবুক পেজ লিংক দেওয়া হয়েছে...', 'woocommerce-resell-utility' ); ?>" required></textarea>
						</div>
					</div>
					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'ফিরে যান', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-secondary" style="color:#dc2626; border-color:#dc2626; font-weight:600;"><?php esc_html_e( 'হ্যাঁ, আবেদন বাতিল করুন', 'woocommerce-resell-utility' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- View Reseller Profile & NID Modal -->
		<div id="wru-view-reseller-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box wru-modal-large" style="max-width:760px;">
				<div class="wru-modal-header" style="display:flex; justify-content:space-between; align-items:center;">
					<h3 style="margin:0; font-size:16px;"><?php esc_html_e( 'রিসেলার বিস্তারিত প্রোফাইল ও NID ভেরিফিকেশন', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<div class="wru-modal-body" id="wru_view_profile_body" style="max-height:75vh; overflow-y:auto; padding:20px;">
					<!-- Populated by JavaScript -->
				</div>
				<div class="wru-modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
					<span id="wru_view_profile_pdf_holder"></span>
					<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বন্ধ করুন', 'woocommerce-resell-utility' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Delete Applicant Confirmation Modal -->
		<div id="wru-delete-applicant-modal" class="wru-admin-modal" style="display:none;">
			<div class="wru-modal-overlay"></div>
			<div class="wru-modal-box">
				<div class="wru-modal-header">
					<h3 style="color:#dc2626; margin:0;"><?php esc_html_e( 'আবেদনকারী পার্মানেন্টলি ডিলিট', 'woocommerce-resell-utility' ); ?></h3>
					<button type="button" class="wru-modal-close">&times;</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wru_delete_applicant_action', 'wru_nonce' ); ?>
					<input type="hidden" name="action" value="wru_delete_applicant" />
					<input type="hidden" name="applicant_id" id="wru_del_applicant_id" value="" />
					
					<div class="wru-modal-body">
						<p style="font-size:14px; margin-bottom:12px;">
							<strong><?php esc_html_e( 'আবেদনকারী:', 'woocommerce-resell-utility' ); ?></strong> <span id="wru_del_applicant_name" style="color:#0f172a; font-weight:700;"></span>
						</p>
						
						<div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:12px; margin-bottom:15px; color:#991b1b; font-size:13px;">
							<strong><?php esc_html_e( 'সতর্কতা:', 'woocommerce-resell-utility' ); ?></strong>
							<?php esc_html_e( 'এই আবেদনকারীর সমস্ত ডাটা, আপলোডকৃত NID কার্ডের ছবি (সার্ভার থেকে স্থায়ীভাবে) এবং ইউজার একাউন্ট সম্পূর্ণ মুছে ফেলা হবে। কোনো ওরফান ফাইল বা ডাটা অবশিষ্ট থাকবে না।', 'woocommerce-resell-utility' ); ?>
						</div>
					</div>

					<div class="wru-modal-footer">
						<button type="button" class="button wru-modal-cancel"><?php esc_html_e( 'বাতিল', 'woocommerce-resell-utility' ); ?></button>
						<button type="submit" class="button button-primary" style="background:#dc2626; border-color:#b91c1c; color:#fff;">
							<?php esc_html_e( 'হ্যাঁ, পার্মানেন্টলি মুছে ফেলুন', 'woocommerce-resell-utility' ); ?>
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
							<th style="width:14%;"><?php esc_html_e( 'পেআউট মেথড ও নাম্বার', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:9%;"><?php esc_html_e( 'অর্ডার সংখ্যা', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:11%;"><?php esc_html_e( 'অর্জিত নিট লাভ', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'উত্তোলন', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'বর্তমান ব্যালেন্স', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:22%;"><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $resellers as $reseller ) : 
							$uid          = $reseller->ID;
							$balance_data = self::get_reseller_balance_data( $uid );
							$p_method     = get_user_meta( $uid, '_wru_payout_method', true );
							$p_number     = get_user_meta( $uid, '_wru_payout_number', true );
							$r_company    = get_user_meta( $uid, '_wru_reseller_company_name', true ) ?: get_user_meta( $uid, 'billing_company', true );
							$r_phone      = get_user_meta( $uid, '_wru_reseller_phone', true ) ?: get_user_meta( $uid, 'billing_phone', true );
							$r_wa         = get_user_meta( $uid, '_wru_reseller_whatsapp', true );
							$r_store_url  = get_user_meta( $uid, '_wru_reseller_store_url', true );
							$r_nid_front  = get_user_meta( $uid, '_wru_nid_front_url', true );
							$r_nid_back   = get_user_meta( $uid, '_wru_nid_back_url', true );
							$pdf_url      = wp_nonce_url( admin_url( 'admin-post.php?action=wru_export_reseller_pdf&user_id=' . $uid ), 'wru_export_pdf_' . $uid );
						?>
							<tr>
								<td style="vertical-align:top;">
									<div class="wru-clickable-reseller wru-btn-view-reseller" data-user-id="<?php echo esc_attr( $uid ); ?>" style="cursor:pointer; padding:8px 10px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; transition:all 0.15s ease-in-out;" title="<?php esc_attr_e( 'ক্লিক করে সম্পূর্ণ প্রোফাইল ও আপলোডকৃত NID দেখুন', 'woocommerce-resell-utility' ); ?>">
										<div style="display:flex; align-items:center; gap:8px;">
											<div style="width:34px; height:34px; border-radius:50%; background:#e0f2fe; color:#0369a1; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; flex-shrink:0; border:1px solid #bae6fd;">
												<?php echo esc_html( mb_substr( $reseller->display_name, 0, 1 ) ); ?>
											</div>
											<div style="overflow:hidden;">
												<strong style="color:#0284c7; font-size:13.5px; display:block; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;">
													<?php echo esc_html( $reseller->display_name ); ?> &rarr;
												</strong>
												<span style="color:#64748b; font-size:11px; display:block;">@<?php echo esc_html( $reseller->user_login ); ?></span>
											</div>
										</div>
										<div style="margin-top:6px; font-size:12px; color:#475569; line-height:1.45;">
											<?php if ( ! empty( $r_company ) ) : ?>
												<span style="display:inline-block; background:#ffffff; border:1px solid #cbd5e1; border-radius:4px; padding:1px 6px; font-weight:600; color:#0f172a; margin-bottom:3px; font-size:11px;"><?php echo esc_html( $r_company ); ?></span><br>
											<?php endif; ?>
											<span style="color:#64748b; font-size:11.5px;">Email: <?php echo esc_html( $reseller->user_email ); ?></span>
											<?php if ( $r_phone ) : ?>
												<br><span style="color:#64748b; font-size:11.5px;">Phone: <?php echo esc_html( $r_phone ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $r_nid_front ) || ! empty( $r_nid_back ) ) : ?>
												<br><span style="display:inline-block; margin-top:4px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:3px; padding:1px 6px; font-size:10.5px; font-weight:700;">NID সংযুক্ত</span>
											<?php else : ?>
												<br><span style="display:inline-block; margin-top:4px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:3px; padding:1px 6px; font-size:10.5px;">NID নেই</span>
											<?php endif; ?>
										</div>
									</div>
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
									<div class="wru-actions-group" style="display:flex; flex-direction:column; gap:5px;">
										<button type="button" class="button button-small wru-btn-view-reseller" data-user-id="<?php echo esc_attr( $uid ); ?>" style="color:#0f172a; font-weight:700; text-align:center; background:#f8fafc; border-color:#cbd5e1; display:flex; align-items:center; justify-content:center; gap:4px;" title="<?php esc_attr_e( 'প্রোফাইল ও আপলোডকৃত NID কার্ড দেখুন', 'woocommerce-resell-utility' ); ?>">
											<?php esc_html_e( 'প্রোফাইল ও NID', 'woocommerce-resell-utility' ); ?>
										</button>
										<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="button button-small button-primary wru-btn-export-pdf" style="background:#0284c7; border-color:#0369a1; color:#fff; font-weight:700; text-align:center; display:flex; align-items:center; justify-content:center; gap:5px; text-decoration:none;" title="<?php esc_attr_e( 'এক ক্লিকে রিসেলারের সমস্ত তথ্য ও NID সহ PDF ডাউনলোড করুন', 'woocommerce-resell-utility' ); ?>">
											<?php esc_html_e( 'এক ক্লিকে PDF', 'woocommerce-resell-utility' ); ?>
										</a>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;" enctype="multipart/form-data">
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
					<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
						<div>
							<label><strong><?php esc_html_e( 'ফোন নাম্বার:', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="text" name="reseller_phone" class="widefat" placeholder="017XXXXXXXX" />
						</div>
						<div>
							<label><strong><?php esc_html_e( 'WhatsApp নম্বর:', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="text" name="reseller_whatsapp" class="widefat" placeholder="017XXXXXXXX" />
						</div>
					</div>
					<div class="wru-form-group" style="margin-bottom:12px;">
						<label><strong><?php esc_html_e( 'ফেসবুক পেজ বা ওয়েবসাইটের লিংক (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></strong></label>
						<input type="url" name="reseller_store_url" class="widefat" placeholder="https://facebook.com/page" />
					</div>
					<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
						<div>
							<label style="font-size:12px;"><strong><?php esc_html_e( 'NID কার্ডের সামনের অংশ (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="file" name="reseller_nid_front" accept="image/*,application/pdf" style="font-size:12px; width:100%; margin-top:4px;" />
						</div>
						<div>
							<label style="font-size:12px;"><strong><?php esc_html_e( 'NID কার্ডের পেছনের অংশ (ঐচ্ছিক):', 'woocommerce-resell-utility' ); ?></strong></label>
							<input type="file" name="reseller_nid_back" accept="image/*,application/pdf" style="font-size:12px; width:100%; margin-top:4px;" />
						</div>
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

	/**
	 * Render Tab: Pending Reseller Applications & NID Verification.
	 */
	private function render_pending_resellers_tab() {
		$status_filter = isset( $_GET['app_status'] ) ? sanitize_key( $_GET['app_status'] ) : 'pending';
		$query_status  = ( 'rejected' === $status_filter ) ? 'rejected' : 'pending';

		$applicants = get_users( array(
			'meta_key'   => '_wru_reseller_status',
			'meta_value' => $query_status,
			'orderby'    => 'registered',
			'order'      => 'DESC',
		) );
		?>
		<div class="wru-tab-card">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
				<ul class="subsubsub" style="margin:0;">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=pending_resellers&app_status=pending' ) ); ?>" class="<?php echo 'pending' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'পেন্ডিং আবেদনসমূহ', 'woocommerce-resell-utility' ); ?></a> |</li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wru-resellers&tab=pending_resellers&app_status=rejected' ) ); ?>" class="<?php echo 'rejected' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'বাতিলকৃত আবেদন', 'woocommerce-resell-utility' ); ?></a></li>
				</ul>
			</div>
			<div class="clear"></div>

			<?php if ( empty( $applicants ) ) : ?>
				<div class="wru-admin-empty-state" style="margin-top: 20px;">
					<p><?php echo 'rejected' === $status_filter ? esc_html__( 'কোনো বাতিলকৃত আবেদন পাওয়া যায়নি।', 'woocommerce-resell-utility' ) : esc_html__( 'বর্তমানে কোনো পেন্ডিং রিসেলার আবেদন নেই। নতুন কোনো রিসেলার আবেদন ফরম পূরণ করলে সমস্ত বিস্তারিত এখানে দেখা যাবে।', 'woocommerce-resell-utility' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped table-view-list" style="margin-top:16px;">
					<thead>
						<tr>
							<th style="width:18%;"><?php esc_html_e( 'আবেদনকারী', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:16%;"><?php esc_html_e( 'শপ / পেজ / ওয়েবসাইট', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'যোগাযোগ ও WhatsApp', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:22%;"><?php esc_html_e( 'NID কার্ড (সামনে ও পেছনে)', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'তারিখ', 'woocommerce-resell-utility' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'অ্যাকশন', 'woocommerce-resell-utility' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $applicants as $app ) : 
							$uid        = $app->ID;
							$company    = get_user_meta( $uid, '_wru_reseller_company_name', true ) ?: get_user_meta( $uid, 'billing_company', true );
							$phone      = get_user_meta( $uid, '_wru_reseller_phone', true ) ?: get_user_meta( $uid, 'billing_phone', true );
							$store_url  = get_user_meta( $uid, '_wru_reseller_store_url', true );
							$wa         = get_user_meta( $uid, '_wru_reseller_whatsapp', true );
							$nid_front  = get_user_meta( $uid, '_wru_nid_front_url', true );
							$nid_back   = get_user_meta( $uid, '_wru_nid_back_url', true );
							$applied_at = get_user_meta( $uid, '_wru_reseller_applied_at', true ) ?: $app->user_registered;
							$rej_reason = get_user_meta( $uid, '_wru_reseller_rejection_reason', true );

							// Format WhatsApp link
							$wa_clean = preg_replace( '/[^0-9]/', '', (string) $wa );
							if ( strpos( (string) $wa, 'http' ) === 0 ) {
								$wa_link = $wa;
							} elseif ( ! empty( $wa_clean ) ) {
								if ( strpos( $wa_clean, '01' ) === 0 && strlen( $wa_clean ) === 11 ) {
									$wa_clean = '88' . $wa_clean;
								}
								$wa_link = 'https://wa.me/' . $wa_clean;
							} else {
								$wa_link = '';
							}
						?>
							<tr>
								<td>
									<div class="wru-clickable-reseller wru-btn-view-reseller" data-user-id="<?php echo esc_attr( $uid ); ?>" style="cursor:pointer; padding:6px 8px; border-radius:6px; border:1px solid #e2e8f0; background:#f8fafc; transition:all 0.15s ease-in-out;" title="<?php esc_attr_e( 'ক্লিক করে সম্পূর্ণ প্রোফাইল ও আপলোডকৃত NID দেখুন', 'woocommerce-resell-utility' ); ?>">
										<strong style="color:#0284c7; font-size:13.5px;"><?php echo esc_html( $app->display_name ); ?> &rarr;</strong>
										<br><span style="color:#64748b; font-size:12px;"><?php echo esc_html( $app->user_email ); ?></span>
										<br><small style="color:#94a3b8;">User: <?php echo esc_html( $app->user_login ); ?></small>
									</div>
								</td>
								<td>
									<?php if ( ! empty( $company ) ) : ?>
										<strong style="color:#0f172a; font-size:13px;"><?php echo esc_html( $company ); ?></strong><br>
									<?php endif; ?>
									<?php if ( ! empty( $store_url ) ) : ?>
										<a href="<?php echo esc_url( $store_url ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#0284c7; text-decoration:none; margin-top:4px; font-weight:600;">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
											<?php esc_html_e( 'পেজ / ওয়েবসাইট', 'woocommerce-resell-utility' ); ?>
										</a>
									<?php else : ?>
										<span style="color:#94a3b8; font-style:italic; font-size:12px;"><?php esc_html_e( 'দেওয়া হয়নি', 'woocommerce-resell-utility' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $phone ) ) : ?>
										<div style="margin-bottom:4px;">
											<strong><?php esc_html_e( 'মোবাইল:', 'woocommerce-resell-utility' ); ?></strong>
											<a href="tel:<?php echo esc_attr( $phone ); ?>" style="text-decoration:none; color:#0f172a; font-weight:600;"><?php echo esc_html( $phone ); ?></a>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $wa ) ) : ?>
										<div>
											<strong>WhatsApp:</strong> 
											<?php if ( ! empty( $wa_link ) ) : ?>
												<a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener noreferrer" style="color:#16a34a; font-weight:700; text-decoration:none;">
													<?php echo esc_html( $wa ); ?> &rarr;
												</a>
											<?php else : ?>
												<span><?php echo esc_html( $wa ); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<div style="display:flex; gap:10px; align-items:center;">
										<!-- NID Front -->
										<div style="text-align:center;">
											<?php if ( ! empty( $nid_front ) ) : ?>
												<?php if ( preg_match( '/\.(pdf)$/i', $nid_front ) ) : ?>
													<a href="<?php echo esc_url( $nid_front ); ?>" target="_blank" class="button button-small" style="font-size:11px;">
														<?php esc_html_e( 'NID Front (PDF)', 'woocommerce-resell-utility' ); ?>
													</a>
												<?php else : ?>
													<a href="<?php echo esc_url( $nid_front ); ?>" target="_blank" title="<?php esc_attr_e( 'বড় করে দেখতে ক্লিক করুন', 'woocommerce-resell-utility' ); ?>">
														<img src="<?php echo esc_url( $nid_front ); ?>" alt="NID Front" style="width:75px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1; box-shadow:0 1px 3px rgba(0,0,0,0.1);" />
													</a>
													<small style="display:block; font-size:10px; color:#64748b; margin-top:2px;"><?php esc_html_e( 'সামনে (Front)', 'woocommerce-resell-utility' ); ?></small>
												<?php endif; ?>
											<?php else : ?>
												<span style="color:#dc2626; font-size:11px;"><?php esc_html_e( 'ছবি নেই', 'woocommerce-resell-utility' ); ?></span>
											<?php endif; ?>
										</div>
										<!-- NID Back -->
										<div style="text-align:center;">
											<?php if ( ! empty( $nid_back ) ) : ?>
												<?php if ( preg_match( '/\.(pdf)$/i', $nid_back ) ) : ?>
													<a href="<?php echo esc_url( $nid_back ); ?>" target="_blank" class="button button-small" style="font-size:11px;">
														<?php esc_html_e( 'NID Back (PDF)', 'woocommerce-resell-utility' ); ?>
													</a>
												<?php else : ?>
													<a href="<?php echo esc_url( $nid_back ); ?>" target="_blank" title="<?php esc_attr_e( 'বড় করে দেখতে ক্লিক করুন', 'woocommerce-resell-utility' ); ?>">
														<img src="<?php echo esc_url( $nid_back ); ?>" alt="NID Back" style="width:75px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1; box-shadow:0 1px 3px rgba(0,0,0,0.1);" />
													</a>
													<small style="display:block; font-size:10px; color:#64748b; margin-top:2px;"><?php esc_html_e( 'পেছনে (Back)', 'woocommerce-resell-utility' ); ?></small>
												<?php endif; ?>
											<?php else : ?>
												<span style="color:#dc2626; font-size:11px;"><?php esc_html_e( 'ছবি নেই', 'woocommerce-resell-utility' ); ?></span>
											<?php endif; ?>
										</div>
									</div>
								</td>
								<td>
									<span style="font-size:12px; color:#475569;">
										<?php echo esc_html( date_i18n( 'd M Y, h:i A', strtotime( $applied_at ) ) ); ?>
									</span>
									<?php if ( 'rejected' === $status_filter && ! empty( $rej_reason ) ) : ?>
										<br><small style="color:#dc2626;"><strong><?php esc_html_e( 'কারণ:', 'woocommerce-resell-utility' ); ?></strong> <?php echo esc_html( $rej_reason ); ?></small>
									<?php endif; ?>
								</td>
								<td>
									<?php 
									$app_pdf_url = wp_nonce_url( admin_url( 'admin-post.php?action=wru_export_reseller_pdf&user_id=' . $uid ), 'wru_export_pdf_' . $uid );
									?>
									<div style="display:flex; flex-direction:column; gap:5px;">
										<?php if ( 'pending' === $status_filter ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'আপনি কি নিশ্চিত যে এই আবেদনকারীকে রিসেলার রোল হিসেবে অনুমোদন দিতে চান?', 'woocommerce-resell-utility' ); ?>');">
												<?php wp_nonce_field( 'wru_approve_reseller_action', 'wru_nonce' ); ?>
												<input type="hidden" name="action" value="wru_approve_reseller" />
												<input type="hidden" name="user_id" value="<?php echo esc_attr( $uid ); ?>" />
												<button type="submit" class="button button-primary button-small" style="background:#16a34a; border-color:#15803d; width:100%; text-align:center;">
													<?php esc_html_e( 'অনুমোদন (Approve)', 'woocommerce-resell-utility' ); ?>
												</button>
											</form>
											<button type="button" class="button button-small wru-btn-reject-applicant" style="color:#d97706; width:100%; text-align:center;"
												data-user-id="<?php echo esc_attr( $uid ); ?>"
												data-user-name="<?php echo esc_attr( $app->display_name . ' (' . $app->user_email . ')' ); ?>">
												<?php esc_html_e( 'বাতিল (Reject)', 'woocommerce-resell-utility' ); ?>
											</button>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'এই আবেদনকারীকে কি পুনরায় রিসেলার হিসেবে অনুমোদন দিতে চান?', 'woocommerce-resell-utility' ); ?>');">
												<?php wp_nonce_field( 'wru_approve_reseller_action', 'wru_nonce' ); ?>
												<input type="hidden" name="action" value="wru_approve_reseller" />
												<input type="hidden" name="user_id" value="<?php echo esc_attr( $uid ); ?>" />
												<button type="submit" class="button button-small" style="background:#16a34a; color:#fff; border-color:#15803d; width:100%; text-align:center;">
													<?php esc_html_e( 'পুনরায় অনুমোদন', 'woocommerce-resell-utility' ); ?>
												</button>
											</form>
										<?php endif; ?>

										<button type="button" class="button button-small wru-btn-view-reseller" data-user-id="<?php echo esc_attr( $uid ); ?>" style="color:#0f172a; font-weight:600; width:100%; text-align:center; box-sizing:border-box;">
											<?php esc_html_e( 'প্রোফাইল ও NID', 'woocommerce-resell-utility' ); ?>
										</button>

										<a href="<?php echo esc_url( $app_pdf_url ); ?>" target="_blank" class="button button-small" style="color:#0284c7; font-weight:600; width:100%; text-align:center; box-sizing:border-box;">
											<?php esc_html_e( 'PDF ডাউনলোড', 'woocommerce-resell-utility' ); ?>
										</a>

										<button type="button" class="button button-small button-link-delete wru-btn-delete-applicant" style="color:#dc2626; width:100%; text-align:center;"
											data-user-id="<?php echo esc_attr( $uid ); ?>"
											data-user-name="<?php echo esc_attr( $app->display_name . ' (' . $app->user_email . ')' ); ?>">
											<?php esc_html_e( 'ডিলিট করুন', 'woocommerce-resell-utility' ); ?>
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
	 * Ensure WooCommerce register form supports file uploads (NID).
	 */
	public function add_register_form_enctype() {
		echo ' enctype="multipart/form-data"';
	}

	/**
	 * Render custom Reseller Registration fields on WooCommerce My Account registration form.
	 */
	public function render_registration_fields() {
		?>
		<div class="wru-reseller-reg-section" style="margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
			<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
				<h4 style="margin:0 0 4px 0; color:#166534; font-size:14px; font-weight:700;">
					<?php esc_html_e( 'রিসেলার পার্টনার রেজিস্ট্রেশন তথ্য', 'woocommerce-resell-utility' ); ?>
				</h4>
				<p style="margin:0; font-size:12px; color:#15803d; line-height:1.5;">
					<?php esc_html_e( 'আমাদের প্ল্যাটফর্মে ড্রপশিপিং রিসেলার হিসেবে কাজ করতে নিচের তথ্য ও NID কার্ডের উভয় পাশের ছবি প্রদান করুন। এডমিন যাচাই করে আপনার একাউন্ট অনুমোদন করবেন।', 'woocommerce-resell-utility' ); ?>
				</p>
			</div>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="wru_reg_company_name"><?php esc_html_e( 'আপনার শপ / ফেসবুক পেজ / ব্র্যান্ডের নাম (ঐচ্ছিক)', 'woocommerce-resell-utility' ); ?></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wru_reg_company_name" id="wru_reg_company_name" value="<?php echo ! empty( $_POST['wru_reg_company_name'] ) ? esc_attr( wp_unslash( $_POST['wru_reg_company_name'] ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'যেমন: Trendz Fashion BD বা আপনার পেজের নাম', 'woocommerce-resell-utility' ); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="wru_reg_phone"><?php esc_html_e( 'মোবাইল নাম্বার', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="wru_reg_phone" id="wru_reg_phone" value="<?php echo ! empty( $_POST['wru_reg_phone'] ) ? esc_attr( wp_unslash( $_POST['wru_reg_phone'] ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'যেমন: 017XXXXXXXX', 'woocommerce-resell-utility' ); ?>" required />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="wru_reg_store_url"><?php esc_html_e( 'ফেসবুক পেজ বা ওয়েবসাইট লিংক (যেখানে পণ্য বিক্রয় করেন)', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="url" class="woocommerce-Input woocommerce-Input--text input-text" name="wru_reg_store_url" id="wru_reg_store_url" value="<?php echo ! empty( $_POST['wru_reg_store_url'] ) ? esc_attr( wp_unslash( $_POST['wru_reg_store_url'] ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'https://facebook.com/yourpage বা ওয়েবসাইটের লিংক', 'woocommerce-resell-utility' ); ?>" required />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="wru_reg_whatsapp"><?php esc_html_e( 'WhatsApp নাম্বার বা চ্যাট লিংক', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wru_reg_whatsapp" id="wru_reg_whatsapp" value="<?php echo ! empty( $_POST['wru_reg_whatsapp'] ) ? esc_attr( wp_unslash( $_POST['wru_reg_whatsapp'] ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'যেমন: 017XXXXXXXX বা https://wa.me/...', 'woocommerce-resell-utility' ); ?>" required />
			</p>

			<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
				<div class="form-row">
					<label for="wru_reg_nid_front" style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;">
						<?php esc_html_e( 'NID কার্ডের সামনের ছবি (Front)', 'woocommerce-resell-utility' ); ?> <span class="required">*</span>
					</label>
					<input type="file" name="wru_reg_nid_front" id="wru_reg_nid_front" accept="image/*,application/pdf" required style="width:100%; font-size:12px;" />
					<small style="color:#64748b; font-size:11px; display:block; margin-top:2px;"><?php esc_html_e( 'পরিষ্কার ও পাঠযোগ্য ছবি (JPG/PNG)', 'woocommerce-resell-utility' ); ?></small>
				</div>
				<div class="form-row">
					<label for="wru_reg_nid_back" style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;">
						<?php esc_html_e( 'NID কার্ডের পেছনের ছবি (Back)', 'woocommerce-resell-utility' ); ?> <span class="required">*</span>
					</label>
					<input type="file" name="wru_reg_nid_back" id="wru_reg_nid_back" accept="image/*,application/pdf" required style="width:100%; font-size:12px;" />
					<small style="color:#64748b; font-size:11px; display:block; margin-top:2px;"><?php esc_html_e( 'পরিষ্কার ও পাঠযোগ্য ছবি (JPG/PNG)', 'woocommerce-resell-utility' ); ?></small>
				</div>
			</div>

			<?php
			$captcha_n1    = wp_rand( 2, 9 );
			$captcha_n2    = wp_rand( 1, 9 );
			$captcha_sum   = $captcha_n1 + $captcha_n2;
			$captcha_token = wp_hash( (string) $captcha_sum . '|wru_reg_math_salt' );
			?>
			<div class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px 14px; margin-top:8px; margin-bottom:12px;">
				<label for="wru_reg_captcha" style="font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; margin-bottom:6px;">
					<span style="display:inline-block; width:8px; height:8px; background:#16a34a; border-radius:50%;"></span>
					<?php printf( esc_html__( 'স্প্যাম নিরাপত্তা পরীক্ষা: %d + %d = কত?', 'woocommerce-resell-utility' ), $captcha_n1, $captcha_n2 ); ?>
					<span class="required">*</span>
				</label>
				<input type="hidden" name="wru_reg_captcha_token" value="<?php echo esc_attr( $captcha_token ); ?>" />
				<input type="number" class="woocommerce-Input woocommerce-Input--text input-text" name="wru_reg_captcha" id="wru_reg_captcha" placeholder="<?php esc_attr_e( 'উত্তর লিখুন...', 'woocommerce-resell-utility' ); ?>" required style="max-width:140px; font-weight:700;" />
				<small style="color:#64748b; font-size:11px; display:block; margin-top:4px;"><?php esc_html_e( 'স্প্যামার ও রোবট রোধে সহজ গাণিতিক প্রশ্নের সঠিক উত্তর দিন।', 'woocommerce-resell-utility' ); ?></small>
			</div>
		</div>
		<?php
	}

	/**
	 * Validate custom Reseller Registration fields on submission.
	 *
	 * @param \WP_Error $errors Validation errors object.
	 * @param string    $username Submitted username.
	 * @param string    $password Submitted password.
	 * @param string    $email Submitted email.
	 * @return \WP_Error
	 */
	public function validate_registration_fields( $errors, $username, $password, $email ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $errors;
		}

		// Only enforce on My Account registration form (do not block checkout account creation)
		if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || isset( $_POST['woocommerce_checkout_place_order'] ) || ( isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax'] ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['ship_to_different_address'] ) ) ) {
			return $errors;
		}

		// Only enforce if registration form submitted with reseller fields
		if ( ! isset( $_POST['register'] ) && ! isset( $_POST['wru_reg_phone'] ) ) {
			return $errors;
		}

		if ( empty( $_POST['wru_reg_phone'] ) || '' === trim( (string) $_POST['wru_reg_phone'] ) ) {
			$errors->add( 'wru_reg_phone_error', __( 'দয়া করে আপনার মোবাইল নাম্বার প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}

		if ( empty( $_POST['wru_reg_store_url'] ) || '' === trim( (string) $_POST['wru_reg_store_url'] ) ) {
			$errors->add( 'wru_reg_store_url_error', __( 'দয়া করে আপনার ফেসবুক পেজ বা ওয়েবসাইট লিংক প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}

		if ( empty( $_POST['wru_reg_whatsapp'] ) || '' === trim( (string) $_POST['wru_reg_whatsapp'] ) ) {
			$errors->add( 'wru_reg_whatsapp_error', __( 'দয়া করে আপনার WhatsApp নাম্বার বা চ্যাট লিংক প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}

		// Validate anti-spam math captcha
		$submitted_ans   = isset( $_POST['wru_reg_captcha'] ) ? trim( (string) $_POST['wru_reg_captcha'] ) : '';
		$submitted_token = isset( $_POST['wru_reg_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reg_captcha_token'] ) ) : '';

		if ( '' === $submitted_ans || empty( $submitted_token ) ) {
			$errors->add( 'wru_reg_captcha_empty', __( 'অনুগ্রহ করে স্প্যাম নিরাপত্তা গণিতের উত্তর প্রদান করুন।', 'woocommerce-resell-utility' ) );
		} else {
			$expected_hash = wp_hash( $submitted_ans . '|wru_reg_math_salt' );
			if ( ! hash_equals( $expected_hash, $submitted_token ) ) {
				$errors->add( 'wru_reg_captcha_invalid', __( 'স্প্যাম নিরাপত্তা গণিতের উত্তর ভুল হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ) );
			}
		}

		$allowed_exts = array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
		$max_size     = 5 * 1024 * 1024; // 5MB

		// Validate NID Front
		if ( empty( $_FILES['wru_reg_nid_front']['name'] ) || ! empty( $_FILES['wru_reg_nid_front']['error'] ) ) {
			$errors->add( 'wru_reg_nid_front_error', __( 'দয়া করে আপনার NID কার্ডের সামনের অংশের ছবি আপলোড করুন।', 'woocommerce-resell-utility' ) );
		} else {
			$ext_front = strtolower( pathinfo( $_FILES['wru_reg_nid_front']['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext_front, $allowed_exts, true ) ) {
				$errors->add( 'wru_reg_nid_front_mime', __( 'NID সামনের ছবি অবশ্যই JPG, PNG, WEBP অথবা PDF ফরম্যাটে হতে হবে।', 'woocommerce-resell-utility' ) );
			}
			if ( ! empty( $_FILES['wru_reg_nid_front']['size'] ) && $_FILES['wru_reg_nid_front']['size'] > $max_size ) {
				$errors->add( 'wru_reg_nid_front_size', __( 'NID সামনের ছবির সাইজ সর্বোচ্চ ৫ মেগাবাইট (5MB) হতে পারবে।', 'woocommerce-resell-utility' ) );
			}
		}

		// Validate NID Back
		if ( empty( $_FILES['wru_reg_nid_back']['name'] ) || ! empty( $_FILES['wru_reg_nid_back']['error'] ) ) {
			$errors->add( 'wru_reg_nid_back_error', __( 'দয়া করে আপনার NID কার্ডের পেছনের অংশের ছবি আপলোড করুন।', 'woocommerce-resell-utility' ) );
		} else {
			$ext_back = strtolower( pathinfo( $_FILES['wru_reg_nid_back']['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext_back, $allowed_exts, true ) ) {
				$errors->add( 'wru_reg_nid_back_mime', __( 'NID পেছনের ছবি অবশ্যই JPG, PNG, WEBP অথবা PDF ফরম্যাটে হতে হবে।', 'woocommerce-resell-utility' ) );
			}
			if ( ! empty( $_FILES['wru_reg_nid_back']['size'] ) && $_FILES['wru_reg_nid_back']['size'] > $max_size ) {
				$errors->add( 'wru_reg_nid_back_size', __( 'NID পেছনের ছবির সাইজ সর্বোচ্চ ৫ মেগাবাইট (5MB) হতে পারবে।', 'woocommerce-resell-utility' ) );
			}
		}

		return $errors;
	}

	/**
	 * Save Reseller Registration details and files upon customer account creation.
	 *
	 * @param int $customer_id Newly created customer user ID.
	 */
	public function handle_reseller_registration( $customer_id ) {
		if ( ! $customer_id ) {
			return;
		}

		// Only process if reseller fields were submitted
		if ( ! isset( $_POST['wru_reg_phone'] ) && ! isset( $_FILES['wru_reg_nid_front'] ) ) {
			return;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$upload_overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'pdf'          => 'application/pdf',
			),
		);

		// Upload NID front
		if ( ! empty( $_FILES['wru_reg_nid_front']['name'] ) && empty( $_FILES['wru_reg_nid_front']['error'] ) ) {
			$upload_front = wp_handle_upload( $_FILES['wru_reg_nid_front'], $upload_overrides );
			if ( ! isset( $upload_front['error'] ) && isset( $upload_front['url'] ) ) {
				update_user_meta( $customer_id, '_wru_nid_front_url', $upload_front['url'] );
				update_user_meta( $customer_id, '_wru_nid_front_file', $upload_front['file'] );
			}
		}

		// Upload NID back
		if ( ! empty( $_FILES['wru_reg_nid_back']['name'] ) && empty( $_FILES['wru_reg_nid_back']['error'] ) ) {
			$upload_back = wp_handle_upload( $_FILES['wru_reg_nid_back'], $upload_overrides );
			if ( ! isset( $upload_back['error'] ) && isset( $upload_back['url'] ) ) {
				update_user_meta( $customer_id, '_wru_nid_back_url', $upload_back['url'] );
				update_user_meta( $customer_id, '_wru_nid_back_file', $upload_back['file'] );
			}
		}

		$phone   = isset( $_POST['wru_reg_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reg_phone'] ) ) : '';
		$store   = isset( $_POST['wru_reg_store_url'] ) ? esc_url_raw( wp_unslash( $_POST['wru_reg_store_url'] ) ) : '';
		$wa      = isset( $_POST['wru_reg_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reg_whatsapp'] ) ) : '';
		$company = isset( $_POST['wru_reg_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wru_reg_company_name'] ) ) : '';

		if ( ! empty( $phone ) ) {
			update_user_meta( $customer_id, '_wru_reseller_phone', $phone );
			update_user_meta( $customer_id, 'billing_phone', $phone );
			update_user_meta( $customer_id, '_wru_payout_number', $phone );
		}
		if ( ! empty( $store ) ) {
			update_user_meta( $customer_id, '_wru_reseller_store_url', $store );
		}
		if ( ! empty( $wa ) ) {
			update_user_meta( $customer_id, '_wru_reseller_whatsapp', $wa );
		}
		if ( ! empty( $company ) ) {
			update_user_meta( $customer_id, '_wru_reseller_company_name', $company );
			update_user_meta( $customer_id, 'billing_company', $company );
		}

		// Application status pending (user retains customer role until admin approval)
		update_user_meta( $customer_id, '_wru_reseller_status', 'pending' );
		update_user_meta( $customer_id, '_wru_reseller_applied_at', current_time( 'mysql' ) );

		// Notify Admin via email
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			$user    = get_userdata( $customer_id );
			$subject = sprintf( __( '[নতুন রিসেলার আবেদন] %s (%s)', 'woocommerce-resell-utility' ), $user ? $user->display_name : 'New User', $phone );
			$body    = sprintf(
				__( "নতুন রিসেলার পার্টনার রেজিস্ট্রেশন আবেদন জমা পড়েছে:\n\nনাম: %s\nইমেইল: %s\nমোবাইল: %s\nWhatsApp: %s\nপেজ / ওয়েবসাইট: %s\n\nএডমিন প্যানেলে আবেদন ও NID কার্ড যাচাই করে অনুমোদন দিন:\n%s", 'woocommerce-resell-utility' ),
				$user ? $user->display_name : 'N/A',
				$user ? $user->user_email : 'N/A',
				$phone,
				$wa,
				$store,
				admin_url( 'admin.php?page=wru-resellers&tab=pending_resellers' )
			);
			wp_mail( $admin_email, $subject, $body );
		}
	}
}
