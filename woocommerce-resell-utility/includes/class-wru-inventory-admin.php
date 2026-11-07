<?php
/**
 * Inventory admin screens: Stock, Purchases, New purchase, Movements, Suppliers.
 *
 * Forms post back to their own screen and are handled on the screen's `load-` hook,
 * so a failed purchase keeps everything the user typed. Successful actions redirect (PRG).
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Inventory_Admin {

	const CAP       = 'manage_woocommerce';
	const PER_PAGE  = 25;
	const SLUG      = 'wru-inventory';

	/**
	 * Screen hook suffixes keyed by view.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Errors from a failed form submit, shown on the re-rendered form.
	 *
	 * @var string[]
	 */
	private $form_errors = array();

	/**
	 * Init.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_assets' ), 20 ); // After WooCommerce registers selectWoo.
		add_filter( 'woocommerce_screen_ids', array( $instance, 'add_screen_ids' ) );
		add_action( 'wp_ajax_wru_inv_search_products', array( $instance, 'ajax_search_products' ) );
		return $instance;
	}

	/* ---------------------------------------------------------------------
	 * Menu, assets, routing
	 * ------------------------------------------------------------------- */

	/**
	 * Register the Inventory menu.
	 */
	public function register_menu() {
		$this->hooks['stock'] = add_menu_page(
			__( 'Inventory', 'woocommerce-resell-utility' ),
			__( 'Inventory', 'woocommerce-resell-utility' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-archive',
			56
		);

		$pages = array(
			'stock'        => array( self::SLUG, __( 'Stock', 'woocommerce-resell-utility' ) ),
			'purchases'    => array( self::SLUG . '-purchases', __( 'Purchases', 'woocommerce-resell-utility' ) ),
			'new-purchase' => array( self::SLUG . '-new-purchase', __( 'New purchase', 'woocommerce-resell-utility' ) ),
			'movements'    => array( self::SLUG . '-movements', __( 'Stock movements', 'woocommerce-resell-utility' ) ),
			'suppliers'    => array( self::SLUG . '-suppliers', __( 'Suppliers', 'woocommerce-resell-utility' ) ),
		);
		foreach ( $pages as $view => $page ) {
			$hook = add_submenu_page( self::SLUG, $page[1], $page[1], self::CAP, $page[0], array( $this, 'render' ) );
			if ( 'stock' !== $view ) {
				$this->hooks[ $view ] = $hook;
			}
		}

		foreach ( $this->hooks as $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * Current view from the page slug.
	 *
	 * @return string
	 */
	private function current_view() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$view = ( self::SLUG === $page ) ? 'stock' : substr( $page, strlen( self::SLUG ) + 1 );
		return isset( $this->hooks[ $view ] ) ? $view : 'stock';
	}

	/**
	 * Admin URL of a view.
	 *
	 * @param string $view View.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $view = 'stock', array $args = array() ) {
		$page = ( 'stock' === $view ) ? self::SLUG : self::SLUG . '-' . $view;
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Let WooCommerce load its admin styles and selectWoo on our screens.
	 *
	 * @param string[] $ids Screen IDs.
	 * @return string[]
	 */
	public function add_screen_ids( $ids ) {
		return array_merge( $ids, array_values( $this->hooks ) );
	}

	/**
	 * Inventory CSS / JS on inventory screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'wru-inventory', plugins_url( 'assets/css/inventory.css', WRU_PLUGIN_FILE ), array(), WRU_VERSION );
		wp_enqueue_script( 'wru-inventory', plugins_url( 'assets/js/inventory.js', WRU_PLUGIN_FILE ), array( 'jquery', 'selectWoo' ), WRU_VERSION, true );
		wp_localize_script( 'wru-inventory', 'wruInv', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'searchNonce' => wp_create_nonce( 'wru_inv_search' ),
			'currency'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'    => wc_get_price_decimals(),
			'i18n'        => array(
				'search'       => __( 'Search product or variation to add', 'woocommerce-resell-utility' ),
				'inStock'      => __( 'In stock now:', 'woocommerce-resell-utility' ),
				'notTracked'   => __( 'not tracked yet', 'woocommerce-resell-utility' ),
				'remove'       => __( 'Remove line', 'woocommerce-resell-utility' ),
				'owed'         => __( 'Still owed to supplier', 'woocommerce-resell-utility' ),
				'overpaid'     => __( 'Overpaid', 'woocommerce-resell-utility' ),
				'paidFull'     => __( 'Fully paid', 'woocommerce-resell-utility' ),
				'newStock'     => __( 'New stock will be', 'woocommerce-resell-utility' ),
				'noItems'      => __( 'Add at least one product to the purchase.', 'woocommerce-resell-utility' ),
				'discountHigh' => __( 'The discount is larger than the whole purchase.', 'woocommerce-resell-utility' ),
				'typeMore'     => __( 'Type at least 2 letters', 'woocommerce-resell-utility' ),
				'noResults'    => __( 'No matching products', 'woocommerce-resell-utility' ),
			),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------- */

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $type success|error.
	 * @param string $text Message.
	 */
	private function flash( $type, $text ) {
		set_transient( 'wru_inv_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
	}

	/**
	 * Print and clear the one-time notice.
	 */
	private function render_flash() {
		$key    = 'wru_inv_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="wru-inv-notice wru-inv-notice--%1$s" role="%2$s">%3$s</div>',
			'error' === $notice['type'] ? 'error' : 'success',
			'error' === $notice['type'] ? 'alert' : 'status',
			esc_html( $notice['text'] )
		);
	}

	/**
	 * Redirect after a handled form.
	 *
	 * @param string $url URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Form handling
	 * ------------------------------------------------------------------- */

	/**
	 * Handle POSTs and CSV export for inventory screens.
	 */
	public function handle_request() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage inventory.', 'woocommerce-resell-utility' ), '', array( 'response' => 403 ) );
		}

		// CSV export (GET).
		if ( isset( $_GET['wru_export'] ) && 'stock' === $_GET['wru_export'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'wru_inv_export' );
			$this->export_stock_csv();
		}

		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['wru_inv_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wru_inv_action'] ) );
		check_admin_referer( 'wru_inv_' . $action );

		switch ( $action ) {
			case 'adjust_stock':
				$this->do_adjust_stock();
				break;
			case 'create_purchase':
				$this->do_create_purchase();
				break;
			case 'update_purchase':
				$this->do_update_purchase();
				break;
			case 'delete_purchase':
				$this->do_delete_purchase();
				break;
			case 'save_supplier':
				$this->do_save_supplier();
				break;
			case 'delete_supplier':
				$this->do_delete_supplier();
				break;
		}
	}

	/**
	 * Manual stock adjustment.
	 */
	private function do_adjust_stock() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle_request().
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$qty_raw    = isset( $_POST['qty'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['qty'] ) ) ) : '';
		$reason     = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'other';
		$note       = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$back       = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : self::url();
		// phpcs:enable

		if ( '' === $qty_raw || ! ctype_digit( $qty_raw ) ) {
			$this->flash( 'error', __( 'Enter a whole number for the quantity.', 'woocommerce-resell-utility' ) );
			$this->redirect( wp_validate_redirect( $back, self::url() ) );
		}

		$result = WRU_Inventory::adjust_stock( $product_id, $mode, (int) $qty_raw, $reason, $note );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$product = wc_get_product( $product_id );
			/* translators: 1: product name, 2: new stock */
			$this->flash( 'success', sprintf( __( 'Saved. %1$s now has %2$d in stock.', 'woocommerce-resell-utility' ), $product ? $product->get_name() : '', $result ) );
		}
		$this->redirect( wp_validate_redirect( $back, self::url() ) );
	}

	/**
	 * Create a purchase. On error the form re-renders with the submitted values.
	 */
	private function do_create_purchase() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle_request().
		$post  = wp_unslash( $_POST );
		$items = array();
		if ( isset( $post['items'] ) && is_array( $post['items'] ) ) {
			foreach ( $post['items'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$items[] = array(
					'product_id' => isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0,
					'qty'        => isset( $row['qty'] ) ? sanitize_text_field( $row['qty'] ) : '',
					'unit_cost'  => isset( $row['unit_cost'] ) ? sanitize_text_field( $row['unit_cost'] ) : '',
				);
			}
		}
		$supplier = isset( $post['supplier_id'] ) ? sanitize_text_field( $post['supplier_id'] ) : '';
		// phpcs:enable

		$result = WRU_Inventory::create_purchase( array(
			'supplier_id'    => 'new' === $supplier ? 'new' : absint( $supplier ),
			'new_supplier'   => array(
				'name'  => isset( $post['new_supplier_name'] ) ? sanitize_text_field( $post['new_supplier_name'] ) : '',
				'phone' => isset( $post['new_supplier_phone'] ) ? sanitize_text_field( $post['new_supplier_phone'] ) : '',
			),
			'purchase_date'  => isset( $post['purchase_date'] ) ? sanitize_text_field( $post['purchase_date'] ) : '',
			'bill_no'        => isset( $post['bill_no'] ) ? sanitize_text_field( $post['bill_no'] ) : '',
			'note'           => isset( $post['note'] ) ? sanitize_textarea_field( $post['note'] ) : '',
			'transport_cost' => isset( $post['transport_cost'] ) ? sanitize_text_field( $post['transport_cost'] ) : '',
			'labour_cost'    => isset( $post['labour_cost'] ) ? sanitize_text_field( $post['labour_cost'] ) : '',
			'other_cost'     => isset( $post['other_cost'] ) ? sanitize_text_field( $post['other_cost'] ) : '',
			'discount'       => isset( $post['discount'] ) ? sanitize_text_field( $post['discount'] ) : '',
			'paid'           => isset( $post['paid'] ) ? sanitize_text_field( $post['paid'] ) : '',
			'items'          => $items,
		) );

		if ( is_wp_error( $result ) ) {
			$this->form_errors = $result->get_error_messages();
			return; // Re-render the form with what was typed.
		}

		$purchase = WRU_Inventory::get_purchase( $result );
		/* translators: %s: purchase reference */
		$this->flash( 'success', sprintf( __( 'Purchase %s saved and stock added.', 'woocommerce-resell-utility' ), $purchase ? $purchase->ref_no : '' ) );
		$this->redirect( self::url( 'purchases', array( 'purchase' => $result ) ) );
	}

	/**
	 * Update bill number, paid amount and note of a purchase.
	 */
	private function do_update_purchase() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle_request().
		$id     = isset( $_POST['purchase_id'] ) ? absint( $_POST['purchase_id'] ) : 0;
		$result = WRU_Inventory::update_purchase_header( $id, array(
			'bill_no' => isset( $_POST['bill_no'] ) ? sanitize_text_field( wp_unslash( $_POST['bill_no'] ) ) : '',
			'paid'    => isset( $_POST['paid'] ) ? sanitize_text_field( wp_unslash( $_POST['paid'] ) ) : '',
			'note'    => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		) );
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', __( 'Purchase updated.', 'woocommerce-resell-utility' ) );
		}
		$this->redirect( self::url( 'purchases', array( 'purchase' => $id ) ) );
	}

	/**
	 * Delete a purchase (reverses its stock).
	 */
	private function do_delete_purchase() {
		$id       = isset( $_POST['purchase_id'] ) ? absint( $_POST['purchase_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$purchase = WRU_Inventory::get_purchase( $id );
		$result   = WRU_Inventory::delete_purchase( $id );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
			$this->redirect( self::url( 'purchases', $purchase ? array( 'purchase' => $id ) : array() ) );
		}
		/* translators: %s: purchase reference */
		$this->flash( 'success', sprintf( __( 'Purchase %s deleted and its stock taken back out.', 'woocommerce-resell-utility' ), $purchase ? $purchase->ref_no : '' ) );
		$this->redirect( self::url( 'purchases' ) );
	}

	/**
	 * Create or update a supplier.
	 */
	private function do_save_supplier() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle_request().
		$id     = isset( $_POST['supplier_id'] ) ? absint( $_POST['supplier_id'] ) : 0;
		$result = WRU_Inventory::save_supplier( array(
			'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'phone'   => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'email'   => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
			'address' => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'note'    => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		), $id );
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			$this->form_errors = $result->get_error_messages();
			return;
		}
		$this->flash( 'success', $id ? __( 'Supplier updated.', 'woocommerce-resell-utility' ) : __( 'Supplier added.', 'woocommerce-resell-utility' ) );
		$this->redirect( self::url( 'suppliers' ) );
	}

	/**
	 * Delete a supplier (refused while purchases use it).
	 */
	private function do_delete_supplier() {
		$id     = isset( $_POST['supplier_id'] ) ? absint( $_POST['supplier_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$result = WRU_Inventory::delete_supplier( $id );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', __( 'Supplier deleted.', 'woocommerce-resell-utility' ) );
		}
		$this->redirect( self::url( 'suppliers' ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Product search for the purchase form: simple products and variations with stock and average cost.
	 */
	public function ajax_search_products() {
		check_ajax_referer( 'wru_inv_search', 'security' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}

		$term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$data_store = WC_Data_Store::load( 'product' );
		$ids        = $data_store->search_products( $term, '', true, false, 30 );
		$results    = array();

		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $id ) {
			$product = $id ? wc_get_product( $id ) : false;
			if ( ! WRU_Inventory::is_stockable( $product ) || 'trash' === $product->get_status() ) {
				continue;
			}
			$cost      = WRU_Inventory::get_avg_cost( $id );
			$results[] = array(
				'id'      => $id,
				'text'    => wp_strip_all_tags( $product->get_name() ) . ( $product->get_sku() ? ' (' . $product->get_sku() . ')' : '' ),
				'name'    => wp_strip_all_tags( $product->get_name() ),
				'sku'     => (string) $product->get_sku(),
				'tracked' => $product->managing_stock(),
				'stock'   => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
				'cost'    => null === $cost ? '' : wc_format_decimal( $cost, wc_get_price_decimals() ),
			);
		}

		wp_send_json_success( $results );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the current inventory screen.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$view = $this->current_view();
		echo '<div class="wrap wru-inv">';
		$this->render_nav( $view );
		$this->render_flash();

		switch ( $view ) {
			case 'purchases':
				$id = isset( $_GET['purchase'] ) ? absint( $_GET['purchase'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
				$id ? $this->render_purchase( $id ) : $this->render_purchases();
				break;
			case 'new-purchase':
				$this->render_new_purchase();
				break;
			case 'movements':
				$this->render_movements();
				break;
			case 'suppliers':
				$this->render_suppliers();
				break;
			default:
				$this->render_stock();
		}
		echo '</div>';
	}

	/**
	 * Section tabs.
	 *
	 * @param string $view Current view.
	 */
	private function render_nav( $view ) {
		$tabs = array(
			'stock'     => __( 'Stock', 'woocommerce-resell-utility' ),
			'purchases' => __( 'Purchases', 'woocommerce-resell-utility' ),
			'movements' => __( 'Movements', 'woocommerce-resell-utility' ),
			'suppliers' => __( 'Suppliers', 'woocommerce-resell-utility' ),
		);
		$active = 'new-purchase' === $view ? 'purchases' : $view;
		echo '<nav class="wru-inv-tabs" aria-label="' . esc_attr__( 'Inventory sections', 'woocommerce-resell-utility' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="wru-inv-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $key ) ),
				$active === $key ? ' is-active' : '',
				$active === $key ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Page header.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Subtitle.
	 * @param string $actions  Pre-escaped action HTML.
	 */
	private function header( $title, $subtitle, $actions = '' ) {
		echo '<header class="wru-inv-header"><div>';
		echo '<h1 class="wru-inv-title">' . esc_html( $title ) . '</h1>';
		echo '<p class="wru-inv-subtitle">' . esc_html( $subtitle ) . '</p>';
		echo '</div>';
		if ( $actions ) {
			echo '<div class="wru-inv-actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		echo '</header>';
	}

	/**
	 * Inline errors for a re-rendered form.
	 */
	private function render_form_errors() {
		if ( ! $this->form_errors ) {
			return;
		}
		echo '<div class="wru-inv-notice wru-inv-notice--error" role="alert"><strong>' . esc_html__( 'Please fix the following:', 'woocommerce-resell-utility' ) . '</strong><ul>';
		foreach ( array_unique( $this->form_errors ) as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Pagination links.
	 *
	 * @param int    $total   Total rows.
	 * @param int    $paged   Current page.
	 * @param int    $per     Per page.
	 */
	private function pagination( $total, $paged, $per ) {
		$pages = (int) ceil( $total / $per );
		$from  = $total ? ( $paged - 1 ) * $per + 1 : 0;
		$to    = min( $total, $paged * $per );
		echo '<div class="wru-inv-pager"><span>';
		/* translators: 1: first row, 2: last row, 3: total rows */
		printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'woocommerce-resell-utility' ), (int) $from, (int) $to, (int) $total );
		echo '</span>';
		if ( $pages > 1 ) {
			echo '<span class="wru-inv-pager-links">';
			echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => __( 'Previous', 'woocommerce-resell-utility' ),
				'next_text' => __( 'Next', 'woocommerce-resell-utility' ),
			) );
			echo '</span>';
		}
		echo '</div>';
	}

	/**
	 * Hidden nonce + action fields.
	 *
	 * @param string $action Action key.
	 */
	private function form_fields( $action ) {
		wp_nonce_field( 'wru_inv_' . $action );
		echo '<input type="hidden" name="wru_inv_action" value="' . esc_attr( $action ) . '">';
	}

	/* ---------------------------------------------------------------------
	 * Stock
	 * ------------------------------------------------------------------- */

	/**
	 * SQL pieces shared by the stock list, its counts and the CSV export.
	 *
	 * @param string $search Search term.
	 * @return array{0:string,1:string,2:array,3:string,4:string} joins, where, params, qty expr, low expr.
	 */
	private function stock_query_parts( $search = '' ) {
		global $wpdb;
		$low_default = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );

		$joins = "INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock'
			LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = '_sku'
			LEFT JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = '_low_stock_amount'
			LEFT JOIN {$wpdb->postmeta} ac ON ac.post_id = p.ID AND ac.meta_key = '" . WRU_Inventory::META_AVG_COST . "'
			LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent";

		$where  = "p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND ( p.post_parent = 0 OR pp.post_status NOT IN ('trash','auto-draft') )";
		$params = array();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( p.post_title LIKE %s OR sk.meta_value LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		$qty = "CAST(COALESCE(NULLIF(st.meta_value, ''), '0') AS DECIMAL(14,3))";
		$low = 'CAST(COALESCE(NULLIF(lo.meta_value, \'\'), ' . $low_default . ') AS DECIMAL(14,3))';

		return array( $joins, $where, $params, $qty, $low );
	}

	/**
	 * Stock screen.
	 */
	private function render_stock() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		list( $joins, $where, $params, $qty, $low ) = $this->stock_query_parts( $search );

		$counts_sql = "SELECT COUNT(*) AS total,
				SUM( {$qty} <= 0 ) AS out_count,
				SUM( {$qty} > 0 AND {$qty} <= {$low} ) AS low_count,
				SUM( {$qty} > {$low} ) AS in_count,
				SUM( GREATEST({$qty}, 0) * CAST(COALESCE(NULLIF(ac.meta_value, ''), '0') AS DECIMAL(14,4)) ) AS stock_value
			FROM {$wpdb->posts} p {$joins} WHERE {$where}";
		$counts = $params ? $wpdb->get_row( $wpdb->prepare( $counts_sql, $params ) ) : $wpdb->get_row( $counts_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		$filters = array(
			'out' => " AND {$qty} <= 0",
			'low' => " AND {$qty} > 0 AND {$qty} <= {$low}",
			'in'  => " AND {$qty} > {$low}",
		);
		$filter_sql = isset( $filters[ $status ] ) ? $filters[ $status ] : '';
		if ( ! isset( $filters[ $status ] ) ) {
			$status = 'all';
		}

		$total_rows = (int) ( 'all' === $status ? $counts->total : $counts->{$status . '_count'} );
		$offset     = ( $paged - 1 ) * self::PER_PAGE;
		$list_sql   = "SELECT p.ID, {$qty} AS qty, {$low} AS low_at FROM {$wpdb->posts} p {$joins} WHERE {$where}{$filter_sql} ORDER BY {$qty} <= 0 DESC, {$qty} <= {$low} DESC, p.post_title ASC LIMIT %d OFFSET %d";
		$rows       = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( self::PER_PAGE, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$export_url = wp_nonce_url( self::url( 'stock', array( 'wru_export' => 'stock' ) ), 'wru_inv_export' );
		$actions    = '<a class="wru-inv-btn wru-inv-btn--ghost" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'woocommerce-resell-utility' ) . '</a>'
			. '<a class="wru-inv-btn wru-inv-btn--primary" href="' . esc_url( self::url( 'new-purchase' ) ) . '">' . esc_html__( 'New purchase', 'woocommerce-resell-utility' ) . '</a>';

		$this->header( __( 'Inventory', 'woocommerce-resell-utility' ), __( 'Stock, cost and value of every tracked product · পণ্যের মজুত ও মূল্য', 'woocommerce-resell-utility' ), $actions );

		$tiles = array(
			array( 'all', __( 'Tracked products', 'woocommerce-resell-utility' ), (int) $counts->total, __( 'Stock management on', 'woocommerce-resell-utility' ), '' ),
			array( 'all', __( 'Stock value (at cost)', 'woocommerce-resell-utility' ), wc_price( (float) $counts->stock_value ), __( 'Qty × average cost', 'woocommerce-resell-utility' ), '' ),
			array( 'low', __( 'Low stock', 'woocommerce-resell-utility' ), (int) $counts->low_count, __( 'At or under the low-stock level', 'woocommerce-resell-utility' ), 'warn' ),
			array( 'out', __( 'Out of stock', 'woocommerce-resell-utility' ), (int) $counts->out_count, __( 'Zero or below', 'woocommerce-resell-utility' ), 'danger' ),
		);
		echo '<section class="wru-inv-tiles" aria-label="' . esc_attr__( 'Summary', 'woocommerce-resell-utility' ) . '">';
		foreach ( $tiles as $t ) {
			printf(
				'<a class="wru-inv-tile%1$s" href="%2$s"><span class="wru-inv-tile-label">%3$s</span><strong class="wru-inv-tile-value">%4$s</strong><span class="wru-inv-tile-hint">%5$s</span></a>',
				$t[4] ? ' wru-inv-tile--' . esc_attr( $t[4] ) : '',
				esc_url( self::url( 'stock', 'all' === $t[0] ? array() : array( 'status' => $t[0] ) ) ),
				esc_html( $t[1] ),
				is_int( $t[2] ) ? esc_html( number_format_i18n( $t[2] ) ) : wp_kses_post( $t[2] ),
				esc_html( $t[3] )
			);
		}
		echo '</section>';

		echo '<section class="wru-inv-card" aria-label="' . esc_attr__( 'Products', 'woocommerce-resell-utility' ) . '">';
		echo '<form method="get" class="wru-inv-toolbar">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		if ( 'all' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		}
		echo '<label class="wru-inv-search"><span class="screen-reader-text">' . esc_html__( 'Search products', 'woocommerce-resell-utility' ) . '</span>';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name or SKU', 'woocommerce-resell-utility' ) . '"></label>';
		echo '<button type="submit" class="wru-inv-btn wru-inv-btn--ghost">' . esc_html__( 'Search', 'woocommerce-resell-utility' ) . '</button>';
		echo '<div class="wru-inv-chips" role="group" aria-label="' . esc_attr__( 'Filter by status', 'woocommerce-resell-utility' ) . '">';
		$chips = array(
			'all' => array( __( 'All', 'woocommerce-resell-utility' ), (int) $counts->total ),
			'in'  => array( __( 'In stock', 'woocommerce-resell-utility' ), (int) $counts->in_count ),
			'low' => array( __( 'Low', 'woocommerce-resell-utility' ), (int) $counts->low_count ),
			'out' => array( __( 'Out', 'woocommerce-resell-utility' ), (int) $counts->out_count ),
		);
		foreach ( $chips as $key => $chip ) {
			$args = array_filter( array( 'status' => 'all' === $key ? '' : $key, 's' => $search ) );
			printf(
				'<a class="wru-inv-chip%1$s" href="%2$s"%3$s>%4$s <span>%5$s</span></a>',
				$status === $key ? ' is-active' : '',
				esc_url( self::url( 'stock', $args ) ),
				$status === $key ? ' aria-current="true"' : '',
				esc_html( $chip[0] ),
				esc_html( number_format_i18n( $chip[1] ) )
			);
		}
		echo '</div></form>';

		if ( ! $rows ) {
			echo '<div class="wru-inv-empty"><p><strong>' . esc_html__( 'No products here yet.', 'woocommerce-resell-utility' ) . '</strong></p><p>'
				. esc_html__( 'Products show up once stock tracking is on. Recording a purchase turns it on automatically, or tick "Track stock quantity" on the product\'s Inventory tab.', 'woocommerce-resell-utility' ) . '</p></div>';
		} else {
			$back = self::url( 'stock', array_filter( array( 'status' => 'all' === $status ? '' : $status, 's' => $search, 'paged' => $paged > 1 ? $paged : '' ) ) );
			echo '<div class="wru-inv-table-wrap"><table class="wru-inv-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Product', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'In stock', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Avg cost', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Reseller price', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Value', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Status', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Actions', 'woocommerce-resell-utility' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$product = wc_get_product( (int) $row->ID );
				if ( ! $product ) {
					continue;
				}
				$stock   = (int) round( (float) $row->qty );
				$cost    = WRU_Inventory::get_avg_cost( $product->get_id() );
				$edit_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

				if ( $stock <= 0 ) {
					$badge = '<span class="wru-inv-badge wru-inv-badge--danger">' . esc_html__( 'Out', 'woocommerce-resell-utility' ) . '</span>';
				} elseif ( $stock <= (float) $row->low_at ) {
					$badge = '<span class="wru-inv-badge wru-inv-badge--warn">' . esc_html__( 'Low', 'woocommerce-resell-utility' ) . '</span>';
				} else {
					$badge = '<span class="wru-inv-badge wru-inv-badge--ok">' . esc_html__( 'In stock', 'woocommerce-resell-utility' ) . '</span>';
				}

				echo '<tr>';
				echo '<td><div class="wru-inv-cell-main"><a href="' . esc_url( self::url( 'movements', array( 'product' => $product->get_id() ) ) ) . '">' . esc_html( wp_strip_all_tags( $product->get_name() ) ) . '</a>';
				echo '<span class="wru-inv-muted">' . ( $product->get_sku() ? esc_html( sprintf( /* translators: %s: SKU */ __( 'SKU %s', 'woocommerce-resell-utility' ), $product->get_sku() ) ) . ' · ' : '' );
				echo '<a href="' . esc_url( get_edit_post_link( $edit_id ) ) . '">' . esc_html__( 'Edit product', 'woocommerce-resell-utility' ) . '</a></span></div></td>';
				echo '<td class="num"><strong>' . esc_html( number_format_i18n( $stock ) ) . '</strong></td>';
				echo '<td class="num">' . ( null === $cost ? '<span class="wru-inv-muted">—</span>' : wp_kses_post( wc_price( $cost ) ) ) . '</td>';
				echo '<td class="num">' . ( '' !== $product->get_price() ? wp_kses_post( wc_price( (float) $product->get_price() ) ) : '<span class="wru-inv-muted">—</span>' ) . '</td>';
				echo '<td class="num">' . ( null === $cost ? '<span class="wru-inv-muted">—</span>' : wp_kses_post( wc_price( max( 0, $stock ) * $cost ) ) ) . '</td>';
				echo '<td>' . $badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				printf(
					'<td class="num"><button type="button" class="wru-inv-btn wru-inv-btn--small js-wru-adjust" data-id="%1$d" data-name="%2$s" data-stock="%3$d">%4$s</button></td>',
					(int) $product->get_id(),
					esc_attr( wp_strip_all_tags( $product->get_name() ) ),
					(int) $stock,
					esc_html__( 'Adjust', 'woocommerce-resell-utility' )
				);
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( $total_rows, $paged, self::PER_PAGE );
			$this->render_adjust_dialog( $back );
		}
		echo '</section>';
	}

	/**
	 * Adjust-stock dialog.
	 *
	 * @param string $back URL to return to.
	 */
	private function render_adjust_dialog( $back ) {
		?>
		<dialog id="wru-inv-adjust" class="wru-inv-dialog" aria-labelledby="wru-inv-adjust-title">
			<form method="post" class="wru-inv-dialog-body">
				<?php $this->form_fields( 'adjust_stock' ); ?>
				<input type="hidden" name="product_id" value="">
				<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
				<div class="wru-inv-dialog-head">
					<div>
						<h2 id="wru-inv-adjust-title"><?php esc_html_e( 'Adjust stock', 'woocommerce-resell-utility' ); ?> · <span class="js-wru-adjust-name"></span></h2>
						<p class="wru-inv-muted"><?php esc_html_e( 'Every change is written to the movement log with your name and reason.', 'woocommerce-resell-utility' ); ?></p>
					</div>
					<button type="button" class="wru-inv-icon-btn js-wru-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'woocommerce-resell-utility' ); ?>">&times;</button>
				</div>
				<fieldset class="wru-inv-segmented">
					<legend><?php esc_html_e( 'Mode', 'woocommerce-resell-utility' ); ?></legend>
					<label><input type="radio" name="mode" value="add" checked> <span><?php esc_html_e( 'Add', 'woocommerce-resell-utility' ); ?></span></label>
					<label><input type="radio" name="mode" value="remove"> <span><?php esc_html_e( 'Remove', 'woocommerce-resell-utility' ); ?></span></label>
					<label><input type="radio" name="mode" value="set"> <span><?php esc_html_e( 'Set to', 'woocommerce-resell-utility' ); ?></span></label>
				</fieldset>
				<div class="wru-inv-grid-2">
					<label class="wru-inv-field"><?php esc_html_e( 'Quantity', 'woocommerce-resell-utility' ); ?>
						<input type="number" name="qty" min="0" step="1" max="<?php echo esc_attr( WRU_Inventory::MAX_QTY ); ?>" required inputmode="numeric">
					</label>
					<label class="wru-inv-field"><?php esc_html_e( 'Reason', 'woocommerce-resell-utility' ); ?>
						<select name="reason">
							<?php foreach ( WRU_Inventory::adjustment_reasons() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label class="wru-inv-field"><?php esc_html_e( 'Note', 'woocommerce-resell-utility' ); ?> <span class="wru-inv-muted"><?php esc_html_e( 'Optional', 'woocommerce-resell-utility' ); ?></span>
					<input type="text" name="note" maxlength="200">
				</label>
				<div class="wru-inv-dialog-foot">
					<span class="js-wru-adjust-preview" aria-live="polite"></span>
					<button type="submit" class="wru-inv-btn wru-inv-btn--primary"><?php esc_html_e( 'Save adjustment', 'woocommerce-resell-utility' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * Stream all tracked products as CSV.
	 */
	private function export_stock_csv() {
		global $wpdb;
		list( $joins, $where, $params, $qty ) = $this->stock_query_parts();
		$ids = $wpdb->get_results( "SELECT p.ID, {$qty} AS qty FROM {$wpdb->posts} p {$joins} WHERE {$where} ORDER BY p.post_title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stock-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Bangla correctly.
		fputcsv( $out, array( 'ID', 'Product', 'SKU', 'Stock', 'Average cost', 'Stock value', 'Reseller price' ) );
		foreach ( $ids as $row ) {
			$product = wc_get_product( (int) $row->ID );
			if ( ! $product ) {
				continue;
			}
			$stock = (int) round( (float) $row->qty );
			$cost  = WRU_Inventory::get_avg_cost( $product->get_id() );
			$name  = wp_strip_all_tags( $product->get_name() );
			// Neutralise spreadsheet formulas.
			if ( preg_match( '/^[=+\-@]/', $name ) ) {
				$name = "'" . $name;
			}
			fputcsv( $out, array(
				$product->get_id(),
				$name,
				$product->get_sku(),
				$stock,
				null === $cost ? '' : wc_format_decimal( $cost, 2 ),
				null === $cost ? '' : wc_format_decimal( max( 0, $stock ) * $cost, 2 ),
				$product->get_price(),
			) );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Purchases
	 * ------------------------------------------------------------------- */

	/**
	 * Purchase list.
	 */
	private function render_purchases() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification
		$supplier_id = isset( $_GET['supplier'] ) ? absint( $_GET['supplier'] ) : 0;
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$p = WRU_Inventory_DB::table( 'purchases' );
		$s = WRU_Inventory_DB::table( 'suppliers' );
		$i = WRU_Inventory_DB::table( 'purchase_items' );

		$where  = '1=1';
		$params = array();
		if ( $supplier_id ) {
			$where   .= ' AND p.supplier_id = %d';
			$params[] = $supplier_id;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( p.ref_no LIKE %s OR p.bill_no LIKE %s OR s.name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(*) FROM {$p} p LEFT JOIN {$s} s ON s.id = p.supplier_id WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$list_sql  = "SELECT p.*, s.name AS supplier_name, ( SELECT COALESCE(SUM(qty), 0) FROM {$i} WHERE purchase_id = p.id ) AS units
			FROM {$p} p LEFT JOIN {$s} s ON s.id = p.supplier_id WHERE {$where} ORDER BY p.purchase_date DESC, p.id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$actions = '<a class="wru-inv-btn wru-inv-btn--primary" href="' . esc_url( self::url( 'new-purchase' ) ) . '">' . esc_html__( 'New purchase', 'woocommerce-resell-utility' ) . '</a>';
		$this->header( __( 'Purchases', 'woocommerce-resell-utility' ), __( 'Stock you bought from suppliers · ক্রয়ের ইতিহাস', 'woocommerce-resell-utility' ), $actions );

		$suppliers = WRU_Inventory::get_supplier_options();

		echo '<section class="wru-inv-card">';
		echo '<form method="get" class="wru-inv-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG . '-purchases' ) . '">';
		echo '<label class="wru-inv-search"><span class="screen-reader-text">' . esc_html__( 'Search purchases', 'woocommerce-resell-utility' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Reference, bill no. or supplier', 'woocommerce-resell-utility' ) . '"></label>';
		echo '<label class="wru-inv-inline-field"><span class="screen-reader-text">' . esc_html__( 'Supplier', 'woocommerce-resell-utility' ) . '</span><select name="supplier"><option value="">' . esc_html__( 'All suppliers', 'woocommerce-resell-utility' ) . '</option>';
		foreach ( $suppliers as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $supplier_id, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label><button type="submit" class="wru-inv-btn wru-inv-btn--ghost">' . esc_html__( 'Filter', 'woocommerce-resell-utility' ) . '</button></form>';

		if ( ! $rows ) {
			echo '<div class="wru-inv-empty"><p><strong>' . esc_html__( 'No purchases found.', 'woocommerce-resell-utility' ) . '</strong></p><p><a href="' . esc_url( self::url( 'new-purchase' ) ) . '">' . esc_html__( 'Record your first purchase', 'woocommerce-resell-utility' ) . '</a></p></div>';
		} else {
			echo '<div class="wru-inv-table-wrap"><table class="wru-inv-table"><thead><tr>';
			foreach ( array( __( 'Reference', 'woocommerce-resell-utility' ), __( 'Date', 'woocommerce-resell-utility' ), __( 'Supplier', 'woocommerce-resell-utility' ) ) as $h ) {
				echo '<th scope="col">' . esc_html( $h ) . '</th>';
			}
			foreach ( array( __( 'Units', 'woocommerce-resell-utility' ), __( 'Total', 'woocommerce-resell-utility' ), __( 'Paid', 'woocommerce-resell-utility' ), __( 'Due', 'woocommerce-resell-utility' ), __( 'Actions', 'woocommerce-resell-utility' ) ) as $h ) {
				echo '<th scope="col" class="num">' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$due = max( 0, (float) $row->total - (float) $row->paid );
				echo '<tr>';
				echo '<td><a class="wru-inv-strong" href="' . esc_url( self::url( 'purchases', array( 'purchase' => $row->id ) ) ) . '">' . esc_html( $row->ref_no ) . '</a>' . ( $row->bill_no ? '<div class="wru-inv-muted">' . esc_html( sprintf( /* translators: %s: bill number */ __( 'Bill %s', 'woocommerce-resell-utility' ), $row->bill_no ) ) . '</div>' : '' ) . '</td>';
				echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->purchase_date ) ) ) . '</td>';
				echo '<td>' . ( $row->supplier_name ? esc_html( $row->supplier_name ) : '<span class="wru-inv-muted">' . esc_html__( 'No supplier', 'woocommerce-resell-utility' ) . '</span>' ) . '</td>';
				echo '<td class="num">' . esc_html( number_format_i18n( (int) $row->units ) ) . '</td>';
				echo '<td class="num">' . wp_kses_post( wc_price( $row->total ) ) . '</td>';
				echo '<td class="num">' . wp_kses_post( wc_price( $row->paid ) ) . '</td>';
				echo '<td class="num">' . ( $due > 0 ? '<span class="wru-inv-due">' . wp_kses_post( wc_price( $due ) ) . '</span>' : '<span class="wru-inv-muted">—</span>' ) . '</td>';
				echo '<td class="num"><a class="wru-inv-btn wru-inv-btn--small" href="' . esc_url( self::url( 'purchases', array( 'purchase' => $row->id ) ) ) . '">' . esc_html__( 'View', 'woocommerce-resell-utility' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( $total, $paged, self::PER_PAGE );
		}
		echo '</section>';
	}

	/**
	 * One purchase.
	 *
	 * @param int $id Purchase ID.
	 */
	private function render_purchase( $id ) {
		$purchase = WRU_Inventory::get_purchase( $id );
		if ( ! $purchase ) {
			$this->header( __( 'Purchase not found', 'woocommerce-resell-utility' ), __( 'It may have been deleted.', 'woocommerce-resell-utility' ) );
			echo '<p><a href="' . esc_url( self::url( 'purchases' ) ) . '">' . esc_html__( '← Back to purchases', 'woocommerce-resell-utility' ) . '</a></p>';
			return;
		}
		$items   = WRU_Inventory::get_purchase_items( $id );
		$creator = get_userdata( (int) $purchase->created_by );
		$due     = (float) $purchase->total - (float) $purchase->paid;

		echo '<p class="wru-inv-back"><a href="' . esc_url( self::url( 'purchases' ) ) . '">' . esc_html__( '← Purchases', 'woocommerce-resell-utility' ) . '</a></p>';
		$this->header(
			$purchase->ref_no,
			sprintf(
				/* translators: 1: date, 2: supplier, 3: user */
				__( '%1$s · %2$s · recorded by %3$s', 'woocommerce-resell-utility' ),
				date_i18n( get_option( 'date_format' ), strtotime( $purchase->purchase_date ) ),
				$purchase->supplier_name ? $purchase->supplier_name : __( 'No supplier', 'woocommerce-resell-utility' ),
				$creator ? $creator->display_name : __( 'a deleted user', 'woocommerce-resell-utility' )
			)
		);

		echo '<div class="wru-inv-split"><div class="wru-inv-split-main">';
		echo '<section class="wru-inv-card"><div class="wru-inv-table-wrap"><table class="wru-inv-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product', 'woocommerce-resell-utility' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Qty', 'woocommerce-resell-utility' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Unit cost', 'woocommerce-resell-utility' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Line total', 'woocommerce-resell-utility' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Landed / unit', 'woocommerce-resell-utility' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td><div class="wru-inv-cell-main">';
			if ( $item->product_id ) {
				echo '<a href="' . esc_url( self::url( 'movements', array( 'product' => $item->product_id ) ) ) . '">' . esc_html( $item->product_name ) . '</a>';
			} else {
				echo '<span>' . esc_html( $item->product_name ) . '</span><span class="wru-inv-muted">' . esc_html__( 'Product deleted', 'woocommerce-resell-utility' ) . '</span>';
			}
			if ( $item->sku ) {
				echo '<span class="wru-inv-muted">' . esc_html( sprintf( /* translators: %s: SKU */ __( 'SKU %s', 'woocommerce-resell-utility' ), $item->sku ) ) . '</span>';
			}
			echo '</div></td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (int) $item->qty ) ) . '</td>';
			echo '<td class="num">' . wp_kses_post( wc_price( $item->unit_cost ) ) . '</td>';
			echo '<td class="num">' . wp_kses_post( wc_price( $item->line_total ) ) . '</td>';
			echo '<td class="num wru-inv-accent">' . wp_kses_post( wc_price( $item->landed_cost ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		// Delete.
		echo '<section class="wru-inv-card wru-inv-danger-zone"><div><h2>' . esc_html__( 'Delete this purchase', 'woocommerce-resell-utility' ) . '</h2><p class="wru-inv-muted">'
			. esc_html__( 'Its stock is taken back out and average cost is rolled back. Not possible once that stock has been sold.', 'woocommerce-resell-utility' ) . '</p></div>';
		echo '<form method="post" class="js-wru-confirm" data-confirm="' . esc_attr( sprintf( /* translators: %s: purchase reference */ __( 'Delete %s and take its stock back out?', 'woocommerce-resell-utility' ), $purchase->ref_no ) ) . '">';
		$this->form_fields( 'delete_purchase' );
		echo '<input type="hidden" name="purchase_id" value="' . esc_attr( $purchase->id ) . '"><button type="submit" class="wru-inv-btn wru-inv-btn--danger">' . esc_html__( 'Delete purchase', 'woocommerce-resell-utility' ) . '</button></form></section>';
		echo '</div>';

		// Totals + payment form.
		echo '<aside class="wru-inv-split-side"><section class="wru-inv-card wru-inv-pad wru-inv-summary">';
		$lines = array(
			array( __( 'Items', 'woocommerce-resell-utility' ), wc_price( $purchase->subtotal ) ),
			array( __( 'Transport', 'woocommerce-resell-utility' ), wc_price( $purchase->transport_cost ) ),
			array( __( 'Labour', 'woocommerce-resell-utility' ), wc_price( $purchase->labour_cost ) ),
			array( __( 'Other', 'woocommerce-resell-utility' ), wc_price( $purchase->other_cost ) ),
			array( __( 'Discount', 'woocommerce-resell-utility' ), '− ' . wc_price( $purchase->discount ) ),
		);
		foreach ( $lines as $l ) {
			echo '<div class="wru-inv-sum-row"><span>' . esc_html( $l[0] ) . '</span><span>' . wp_kses_post( $l[1] ) . '</span></div>';
		}
		echo '<div class="wru-inv-sum-row wru-inv-sum-total"><strong>' . esc_html__( 'Total', 'woocommerce-resell-utility' ) . '</strong><strong>' . wp_kses_post( wc_price( $purchase->total ) ) . '</strong></div>';
		printf(
			'<div class="wru-inv-due-box %1$s"><span>%2$s</span><span>%3$s</span></div>',
			$due > 0.004 ? 'is-owed' : 'is-paid',
			esc_html( $due > 0.004 ? __( 'Still owed to supplier', 'woocommerce-resell-utility' ) : ( $due < -0.004 ? __( 'Overpaid', 'woocommerce-resell-utility' ) : __( 'Fully paid', 'woocommerce-resell-utility' ) ) ),
			wp_kses_post( wc_price( abs( $due ) ) )
		);

		echo '<form method="post" class="wru-inv-stack">';
		$this->form_fields( 'update_purchase' );
		echo '<input type="hidden" name="purchase_id" value="' . esc_attr( $purchase->id ) . '">';
		echo '<label class="wru-inv-field">' . esc_html__( 'Paid to supplier', 'woocommerce-resell-utility' ) . '<input type="number" name="paid" min="0" step="0.01" value="' . esc_attr( wc_format_decimal( $purchase->paid, 2 ) ) . '"></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Supplier bill no.', 'woocommerce-resell-utility' ) . '<input type="text" name="bill_no" maxlength="64" value="' . esc_attr( $purchase->bill_no ) . '"></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Note', 'woocommerce-resell-utility' ) . '<textarea name="note" rows="3">' . esc_textarea( $purchase->note ) . '</textarea></label>';
		echo '<button type="submit" class="wru-inv-btn wru-inv-btn--primary">' . esc_html__( 'Save changes', 'woocommerce-resell-utility' ) . '</button>';
		echo '</form></section></aside></div>';
	}

	/**
	 * New purchase form.
	 */
	private function render_new_purchase() {
		// phpcs:disable WordPress.Security.NonceVerification -- re-render of our own verified POST.
		$posted   = ! empty( $this->form_errors ) ? wp_unslash( $_POST ) : array();
		// phpcs:enable
		$val      = function ( $key, $default = '' ) use ( $posted ) {
			return isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? (string) $posted[ $key ] : $default;
		};
		$suppliers = WRU_Inventory::get_supplier_options();
		$supplier  = $val( 'supplier_id' );

		echo '<p class="wru-inv-back"><a href="' . esc_url( self::url( 'purchases' ) ) . '">' . esc_html__( '← Purchases', 'woocommerce-resell-utility' ) . '</a></p>';
		$this->header( __( 'New purchase', 'woocommerce-resell-utility' ), __( 'Stock goes up and average cost is updated when you save · সেভ করলেই মজুত বাড়বে', 'woocommerce-resell-utility' ) );
		$this->render_form_errors();
		?>
		<form method="post" class="wru-inv-split js-wru-purchase" novalidate>
			<?php $this->form_fields( 'create_purchase' ); ?>
			<div class="wru-inv-split-main">
				<section class="wru-inv-card wru-inv-pad">
					<h2 class="wru-inv-h2"><?php esc_html_e( 'Details', 'woocommerce-resell-utility' ); ?></h2>
					<div class="wru-inv-grid-3">
						<label class="wru-inv-field"><?php esc_html_e( 'Supplier', 'woocommerce-resell-utility' ); ?>
							<select name="supplier_id" class="js-wru-supplier">
								<option value=""><?php esc_html_e( 'No supplier', 'woocommerce-resell-utility' ); ?></option>
								<?php foreach ( $suppliers as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $supplier, (string) $id ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
								<option value="new" <?php selected( $supplier, 'new' ); ?>><?php esc_html_e( '+ Add a new supplier', 'woocommerce-resell-utility' ); ?></option>
							</select>
						</label>
						<label class="wru-inv-field"><?php esc_html_e( 'Purchase date', 'woocommerce-resell-utility' ); ?>
							<input type="date" name="purchase_date" required value="<?php echo esc_attr( $val( 'purchase_date', current_time( 'Y-m-d' ) ) ); ?>">
						</label>
						<label class="wru-inv-field"><?php esc_html_e( 'Supplier bill no.', 'woocommerce-resell-utility' ); ?> <span class="wru-inv-muted"><?php esc_html_e( 'Optional', 'woocommerce-resell-utility' ); ?></span>
							<input type="text" name="bill_no" maxlength="64" value="<?php echo esc_attr( $val( 'bill_no' ) ); ?>">
						</label>
					</div>
					<div class="wru-inv-grid-2 js-wru-new-supplier" <?php echo 'new' === $supplier ? '' : 'hidden'; ?>>
						<label class="wru-inv-field"><?php esc_html_e( 'New supplier name', 'woocommerce-resell-utility' ); ?>
							<input type="text" name="new_supplier_name" maxlength="191" value="<?php echo esc_attr( $val( 'new_supplier_name' ) ); ?>">
						</label>
						<label class="wru-inv-field"><?php esc_html_e( 'Phone', 'woocommerce-resell-utility' ); ?> <span class="wru-inv-muted"><?php esc_html_e( 'Optional', 'woocommerce-resell-utility' ); ?></span>
							<input type="tel" name="new_supplier_phone" maxlength="50" value="<?php echo esc_attr( $val( 'new_supplier_phone' ) ); ?>">
						</label>
					</div>
				</section>

				<section class="wru-inv-card">
					<div class="wru-inv-card-head">
						<h2 class="wru-inv-h2"><?php esc_html_e( 'Items', 'woocommerce-resell-utility' ); ?></h2>
						<label class="wru-inv-product-picker"><span class="screen-reader-text"><?php esc_html_e( 'Add product', 'woocommerce-resell-utility' ); ?></span>
							<select class="js-wru-product-search" style="width:100%"></select>
						</label>
					</div>
					<div class="wru-inv-table-wrap">
						<table class="wru-inv-table wru-inv-lines">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Product', 'woocommerce-resell-utility' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Qty', 'woocommerce-resell-utility' ); ?></th>
								<th scope="col"><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( 'Unit cost %s', 'woocommerce-resell-utility' ), html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Line total', 'woocommerce-resell-utility' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Landed / unit', 'woocommerce-resell-utility' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'woocommerce-resell-utility' ); ?></span></th>
							</tr></thead>
							<tbody class="js-wru-lines">
								<?php
								$rows = isset( $posted['items'] ) && is_array( $posted['items'] ) ? $posted['items'] : array();
								$index = 0;
								foreach ( $rows as $row ) {
									if ( ! is_array( $row ) ) {
										continue;
									}
									$pid     = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
									$product = $pid ? wc_get_product( $pid ) : false;
									if ( ! $product ) {
										continue;
									}
									$this->render_line_row(
										$product,
										isset( $row['qty'] ) ? sanitize_text_field( $row['qty'] ) : '1',
										isset( $row['unit_cost'] ) ? sanitize_text_field( $row['unit_cost'] ) : '',
										$index++
									);
								}
								?>
							</tbody>
						</table>
					</div>
					<p class="wru-inv-empty-lines js-wru-empty-lines" <?php echo $rows ? 'hidden' : ''; ?>><?php esc_html_e( 'Search above to add the products you bought.', 'woocommerce-resell-utility' ); ?></p>
					<p class="wru-inv-hint"><?php esc_html_e( 'Landed cost = unit cost + its share of extra costs − its share of discount, split by line value. It becomes the product\'s average cost.', 'woocommerce-resell-utility' ); ?></p>
				</section>

				<section class="wru-inv-card wru-inv-pad">
					<label class="wru-inv-field"><?php esc_html_e( 'Note', 'woocommerce-resell-utility' ); ?> <span class="wru-inv-muted"><?php esc_html_e( 'Optional, only staff see it', 'woocommerce-resell-utility' ); ?></span>
						<textarea name="note" rows="2"><?php echo esc_textarea( $val( 'note' ) ); ?></textarea>
					</label>
				</section>
			</div>

			<aside class="wru-inv-split-side">
				<section class="wru-inv-card wru-inv-pad">
					<h2 class="wru-inv-h2"><?php esc_html_e( 'Extra costs', 'woocommerce-resell-utility' ); ?></h2>
					<div class="wru-inv-grid-2">
						<?php
						$money_fields = array(
							'transport_cost' => __( 'Transport', 'woocommerce-resell-utility' ),
							'labour_cost'    => __( 'Labour', 'woocommerce-resell-utility' ),
							'other_cost'     => __( 'Other', 'woocommerce-resell-utility' ),
							'discount'       => __( 'Discount', 'woocommerce-resell-utility' ),
						);
						foreach ( $money_fields as $key => $label ) :
							?>
							<label class="wru-inv-field"><?php echo esc_html( $label ); ?>
								<input type="number" name="<?php echo esc_attr( $key ); ?>" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $val( $key ) ); ?>" placeholder="0" class="js-wru-money">
							</label>
						<?php endforeach; ?>
					</div>
				</section>
				<section class="wru-inv-card wru-inv-pad wru-inv-summary">
					<div class="wru-inv-sum-row"><span><?php esc_html_e( 'Items', 'woocommerce-resell-utility' ); ?> (<span class="js-wru-units">0</span>)</span><span class="js-wru-subtotal">0</span></div>
					<div class="wru-inv-sum-row"><span><?php esc_html_e( 'Extra costs', 'woocommerce-resell-utility' ); ?></span><span class="js-wru-extra">0</span></div>
					<div class="wru-inv-sum-row"><span><?php esc_html_e( 'Discount', 'woocommerce-resell-utility' ); ?></span><span class="js-wru-discount">0</span></div>
					<div class="wru-inv-sum-row wru-inv-sum-total"><strong><?php esc_html_e( 'Total', 'woocommerce-resell-utility' ); ?></strong><strong class="js-wru-total">0</strong></div>
					<label class="wru-inv-field"><?php esc_html_e( 'Paid to supplier', 'woocommerce-resell-utility' ); ?>
						<input type="number" name="paid" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $val( 'paid' ) ); ?>" placeholder="0" class="js-wru-money">
					</label>
					<div class="wru-inv-due-box js-wru-due"><span class="js-wru-due-label"></span><span class="js-wru-due-value"></span></div>
					<p class="wru-inv-client-error js-wru-client-error" role="alert" hidden></p>
					<button type="submit" class="wru-inv-btn wru-inv-btn--primary wru-inv-btn--block"><?php esc_html_e( 'Save and add to stock', 'woocommerce-resell-utility' ); ?></button>
					<p class="wru-inv-hint wru-inv-center"><?php esc_html_e( 'You can delete a purchase later; deleting takes its stock back out.', 'woocommerce-resell-utility' ); ?></p>
				</section>
			</aside>
		</form>

		<template id="wru-inv-line-template">
			<?php $this->render_line_row( null, '1', '', '__i__' ); ?>
		</template>
		<?php
	}

	/**
	 * One editable purchase line. With no product it renders the JS template.
	 *
	 * @param \WC_Product|null $product Product.
	 * @param string           $qty     Quantity.
	 * @param string           $cost    Unit cost.
	 * @param int|string       $index   Row index ('__i__' in the template; JS renumbers rows).
	 */
	private function render_line_row( $product, $qty, $cost, $index ) {
		$name_base = 'items[' . $index . ']';
		$id      = $product ? $product->get_id() : '';
		$name    = $product ? wp_strip_all_tags( $product->get_name() ) : '';
		$tracked = $product ? $product->managing_stock() : false;
		$stock   = $tracked ? (int) $product->get_stock_quantity() : '';
		?>
		<tr class="js-wru-line">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name_base ); ?>[product_id]" value="<?php echo esc_attr( $id ); ?>" class="js-wru-line-id">
				<div class="wru-inv-cell-main">
					<strong class="js-wru-line-name"><?php echo esc_html( $name ); ?></strong>
					<span class="wru-inv-muted js-wru-line-stock"><?php echo $product ? esc_html( __( 'In stock now:', 'woocommerce-resell-utility' ) . ' ' . ( $tracked ? $stock : __( 'not tracked yet', 'woocommerce-resell-utility' ) ) ) : ''; ?></span>
				</div>
			</td>
			<td><input type="number" name="<?php echo esc_attr( $name_base ); ?>[qty]" min="1" step="1" inputmode="numeric" value="<?php echo esc_attr( $qty ); ?>" class="wru-inv-input-qty js-wru-line-qty" aria-label="<?php esc_attr_e( 'Quantity', 'woocommerce-resell-utility' ); ?>"></td>
			<td><input type="number" name="<?php echo esc_attr( $name_base ); ?>[unit_cost]" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $cost ); ?>" class="wru-inv-input-cost js-wru-line-cost" aria-label="<?php esc_attr_e( 'Unit cost', 'woocommerce-resell-utility' ); ?>"></td>
			<td class="num js-wru-line-total">0</td>
			<td class="num wru-inv-accent js-wru-line-landed">0</td>
			<td><button type="button" class="wru-inv-icon-btn wru-inv-icon-btn--danger js-wru-line-remove" aria-label="<?php esc_attr_e( 'Remove line', 'woocommerce-resell-utility' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Movements
	 * ------------------------------------------------------------------- */

	/**
	 * Movement log.
	 */
	private function render_movements() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		$type       = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$from       = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to         = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$labels = WRU_Inventory::type_labels();
		$table  = WRU_Inventory_DB::table( 'stock_movements' );
		$where  = '1=1';
		$params = array();

		if ( $product_id ) {
			$where   .= ' AND product_id = %d';
			$params[] = $product_id;
		}
		if ( isset( $labels[ $type ] ) ) {
			$where   .= ' AND type = %s';
			$params[] = $type;
		} else {
			$type = '';
		}
		// Dates are picked in site time, stored in UTC.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = get_gmt_from_date( $from . ' 00:00:00' );
		} else {
			$from = '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = get_gmt_from_date( $to . ' 23:59:59' );
		} else {
			$to = '';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$per       = 50;
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $paged - 1 ) * $per ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$product = $product_id ? wc_get_product( $product_id ) : false;
		$this->header(
			$product ? sprintf( /* translators: %s: product name */ __( 'Movements · %s', 'woocommerce-resell-utility' ), wp_strip_all_tags( $product->get_name() ) ) : __( 'Stock movements', 'woocommerce-resell-utility' ),
			__( 'Every change to stock, from any source. Rows are never edited; fix mistakes with a new adjustment.', 'woocommerce-resell-utility' )
		);

		echo '<section class="wru-inv-card">';
		echo '<form method="get" class="wru-inv-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG . '-movements' ) . '">';
		if ( $product_id ) {
			echo '<input type="hidden" name="product" value="' . esc_attr( $product_id ) . '">';
			echo '<a class="wru-inv-chip is-active" href="' . esc_url( self::url( 'movements', array_filter( array( 'type' => $type, 'from' => $from, 'to' => $to ) ) ) ) . '">' . esc_html( $product ? wp_strip_all_tags( $product->get_name() ) : '#' . $product_id ) . ' <span aria-hidden="true">&times;</span><span class="screen-reader-text">' . esc_html__( 'Show all products', 'woocommerce-resell-utility' ) . '</span></a>';
		}
		echo '<label class="wru-inv-field wru-inv-field--inline">' . esc_html__( 'Type', 'woocommerce-resell-utility' ) . '<select name="type"><option value="">' . esc_html__( 'All types', 'woocommerce-resell-utility' ) . '</option>';
		foreach ( $labels as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="wru-inv-field wru-inv-field--inline">' . esc_html__( 'From', 'woocommerce-resell-utility' ) . '<input type="date" name="from" value="' . esc_attr( $from ) . '"></label>';
		echo '<label class="wru-inv-field wru-inv-field--inline">' . esc_html__( 'To', 'woocommerce-resell-utility' ) . '<input type="date" name="to" value="' . esc_attr( $to ) . '"></label>';
		echo '<button type="submit" class="wru-inv-btn wru-inv-btn--ghost">' . esc_html__( 'Filter', 'woocommerce-resell-utility' ) . '</button></form>';

		if ( ! $rows ) {
			echo '<div class="wru-inv-empty"><p><strong>' . esc_html__( 'No movements yet.', 'woocommerce-resell-utility' ) . '</strong></p><p>' . esc_html__( 'They appear as soon as stock changes: a purchase, an order, an adjustment or an edit on the product page.', 'woocommerce-resell-utility' ) . '</p></div>';
		} else {
			$users = array();
			echo '<div class="wru-inv-table-wrap"><table class="wru-inv-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'When', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Product', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Type', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Change', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Stock after', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Source', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'By', 'woocommerce-resell-utility' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$uid = (int) $row->user_id;
				if ( ! isset( $users[ $uid ] ) ) {
					$u             = $uid ? get_userdata( $uid ) : false;
					$users[ $uid ] = $uid ? ( $u ? $u->display_name : __( 'Deleted user', 'woocommerce-resell-utility' ) ) : __( 'System', 'woocommerce-resell-utility' );
				}
				$delta = (int) $row->qty_change;
				echo '<tr>';
				echo '<td class="wru-inv-nowrap">' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ) ) . '</td>';
				echo '<td><a class="wru-inv-strong" href="' . esc_url( self::url( 'movements', array( 'product' => $row->product_id ) ) ) . '">' . esc_html( $row->product_name ) . '</a></td>';
				echo '<td><span class="wru-inv-badge wru-inv-badge--' . esc_attr( $row->type ) . '">' . esc_html( isset( $labels[ $row->type ] ) ? $labels[ $row->type ] : $row->type ) . '</span></td>';
				echo '<td class="num"><strong class="' . ( $delta < 0 ? 'wru-inv-neg' : 'wru-inv-pos' ) . '">' . esc_html( ( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ) ) ) . '</strong></td>';
				echo '<td class="num">' . ( null === $row->stock_after ? '<span class="wru-inv-muted">—</span>' : esc_html( number_format_i18n( (int) $row->stock_after ) ) ) . '</td>';
				echo '<td>' . $this->movement_source( $row ) . ( $row->note ? '<div class="wru-inv-muted">' . esc_html( $row->note ) . '</div>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- movement_source() escapes.
				echo '<td>' . esc_html( $users[ $uid ] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( $total, $paged, $per );
		}
		echo '</section>';
	}

	/**
	 * Linked label for where a movement came from.
	 *
	 * @param object $row Movement row.
	 * @return string Escaped HTML.
	 */
	private function movement_source( $row ) {
		$label = $row->ref_label ? $row->ref_label : ucfirst( $row->ref_type );
		$url   = '';
		if ( 'order' === $row->ref_type && $row->ref_id ) {
			$order = wc_get_order( (int) $row->ref_id );
			$url   = $order ? $order->get_edit_order_url() : '';
		} elseif ( 'purchase' === $row->ref_type && $row->ref_id && WRU_Inventory::get_purchase( (int) $row->ref_id ) ) {
			$url = self::url( 'purchases', array( 'purchase' => (int) $row->ref_id ) );
		} elseif ( 'product' === $row->ref_type && $row->ref_id ) {
			$post = get_post( (int) $row->ref_id );
			if ( $post ) {
				$url = get_edit_post_link( $post->post_parent ? $post->post_parent : $post->ID, 'raw' );
			}
		}
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : '<span>' . esc_html( $label ) . '</span>';
	}

	/* ---------------------------------------------------------------------
	 * Suppliers
	 * ------------------------------------------------------------------- */

	/**
	 * Supplier list with add / edit form.
	 */
	private function render_suppliers() {
		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$editing  = $edit_id ? WRU_Inventory::get_supplier( $edit_id ) : null;
		// phpcs:disable WordPress.Security.NonceVerification -- re-render of our own verified POST.
		$posted   = ! empty( $this->form_errors ) ? wp_unslash( $_POST ) : array();
		// phpcs:enable
		$field    = function ( $key ) use ( $posted, $editing ) {
			if ( isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ) {
				return (string) $posted[ $key ];
			}
			return $editing && isset( $editing->$key ) ? (string) $editing->$key : '';
		};
		$rows = WRU_Inventory::get_suppliers_with_stats();

		$this->header( __( 'Suppliers', 'woocommerce-resell-utility' ), __( 'Who you buy from and how much you still owe them · সরবরাহকারী', 'woocommerce-resell-utility' ) );

		echo '<div class="wru-inv-split"><section class="wru-inv-card wru-inv-split-main">';
		if ( ! $rows ) {
			echo '<div class="wru-inv-empty"><p><strong>' . esc_html__( 'No suppliers yet.', 'woocommerce-resell-utility' ) . '</strong></p><p>' . esc_html__( 'Add one here, or type a new name while recording a purchase.', 'woocommerce-resell-utility' ) . '</p></div>';
		} else {
			echo '<div class="wru-inv-table-wrap"><table class="wru-inv-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Supplier', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Purchases', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Bought', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Owed', 'woocommerce-resell-utility' ) . '</th>';
			echo '<th scope="col" class="num">' . esc_html__( 'Actions', 'woocommerce-resell-utility' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$count = (int) $row->purchase_count;
				echo '<tr>';
				echo '<td><div class="wru-inv-cell-main"><strong>' . esc_html( $row->name ) . '</strong>';
				$meta = array_filter( array( $row->phone, $row->email ) );
				if ( $meta ) {
					echo '<span class="wru-inv-muted">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
				}
				echo '</div></td>';
				echo '<td class="num">' . ( $count ? '<a href="' . esc_url( self::url( 'purchases', array( 'supplier' => $row->id ) ) ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>' : '0' ) . '</td>';
				echo '<td class="num">' . wp_kses_post( wc_price( $row->bought ) ) . '</td>';
				echo '<td class="num">' . ( (float) $row->owed > 0 ? '<span class="wru-inv-due">' . wp_kses_post( wc_price( $row->owed ) ) . '</span>' : '<span class="wru-inv-muted">—</span>' ) . '</td>';
				echo '<td class="num wru-inv-nowrap"><a class="wru-inv-btn wru-inv-btn--small" href="' . esc_url( self::url( 'suppliers', array( 'edit' => $row->id ) ) ) . '">' . esc_html__( 'Edit', 'woocommerce-resell-utility' ) . '</a> ';
				if ( $count ) {
					// Deleting would orphan purchase history: explain instead of offering a button that fails.
					echo '<span class="wru-inv-btn wru-inv-btn--small is-disabled" title="' . esc_attr__( 'Delete this supplier\'s purchases first', 'woocommerce-resell-utility' ) . '" aria-disabled="true">' . esc_html__( 'Delete', 'woocommerce-resell-utility' ) . '</span>';
				} else {
					echo '<form method="post" class="wru-inv-inline js-wru-confirm" data-confirm="' . esc_attr( sprintf( /* translators: %s: supplier name */ __( 'Delete supplier %s?', 'woocommerce-resell-utility' ), $row->name ) ) . '">';
					$this->form_fields( 'delete_supplier' );
					echo '<input type="hidden" name="supplier_id" value="' . esc_attr( $row->id ) . '"><button type="submit" class="wru-inv-btn wru-inv-btn--small wru-inv-btn--danger-ghost">' . esc_html__( 'Delete', 'woocommerce-resell-utility' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<p class="wru-inv-hint">' . esc_html__( 'A supplier with purchases can\'t be deleted, so purchase history never points at a missing supplier.', 'woocommerce-resell-utility' ) . '</p>';
		}
		echo '</section>';

		echo '<aside class="wru-inv-split-side"><section class="wru-inv-card wru-inv-pad">';
		echo '<h2 class="wru-inv-h2">' . esc_html( $editing ? __( 'Edit supplier', 'woocommerce-resell-utility' ) : __( 'Add supplier', 'woocommerce-resell-utility' ) ) . '</h2>';
		$this->render_form_errors();
		echo '<form method="post" class="wru-inv-stack">';
		$this->form_fields( 'save_supplier' );
		echo '<input type="hidden" name="supplier_id" value="' . esc_attr( $editing ? $editing->id : 0 ) . '">';
		echo '<label class="wru-inv-field">' . esc_html__( 'Name', 'woocommerce-resell-utility' ) . '<input type="text" name="name" required maxlength="191" value="' . esc_attr( $field( 'name' ) ) . '"></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Phone', 'woocommerce-resell-utility' ) . '<input type="tel" name="phone" maxlength="50" value="' . esc_attr( $field( 'phone' ) ) . '"></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Email', 'woocommerce-resell-utility' ) . ' <span class="wru-inv-muted">' . esc_html__( 'Optional', 'woocommerce-resell-utility' ) . '</span><input type="email" name="email" maxlength="191" value="' . esc_attr( $field( 'email' ) ) . '"></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Address', 'woocommerce-resell-utility' ) . ' <span class="wru-inv-muted">' . esc_html__( 'Optional', 'woocommerce-resell-utility' ) . '</span><textarea name="address" rows="2">' . esc_textarea( $field( 'address' ) ) . '</textarea></label>';
		echo '<label class="wru-inv-field">' . esc_html__( 'Note', 'woocommerce-resell-utility' ) . ' <span class="wru-inv-muted">' . esc_html__( 'Optional', 'woocommerce-resell-utility' ) . '</span><textarea name="note" rows="2">' . esc_textarea( $field( 'note' ) ) . '</textarea></label>';
		echo '<button type="submit" class="wru-inv-btn wru-inv-btn--primary">' . esc_html( $editing ? __( 'Save changes', 'woocommerce-resell-utility' ) : __( 'Save supplier', 'woocommerce-resell-utility' ) ) . '</button>';
		if ( $editing ) {
			echo '<a class="wru-inv-center" href="' . esc_url( self::url( 'suppliers' ) ) . '">' . esc_html__( 'Cancel', 'woocommerce-resell-utility' ) . '</a>';
		}
		echo '</form></section></aside></div>';
	}
}
