<?php
/**
 * Inventory service: stock movement log, purchases, suppliers and average cost.
 *
 * WooCommerce's own `_stock` value stays the single source of truth. This class listens to every
 * stock change WooCommerce makes (checkout, cancelled orders, refunds, the product editor, other
 * plugins) and writes exactly one movement row per change. Purchases and manual adjustments also
 * go through wc_update_product_stock(), so the log can never drift from what customers see.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Inventory {

	const META_AVG_COST = '_wru_avg_cost';
	const MAX_QTY       = 1000000;

	/**
	 * Stack of "why is stock changing" contexts. The innermost one labels the movement.
	 *
	 * @var array[]
	 */
	private static $context_stack = array();

	/**
	 * Stock value read from the database just before WooCommerce changes it, keyed by product ID.
	 *
	 * @var array<int,int|null>
	 */
	private static $old_stock = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Capture the previous stock before WooCommerce writes the new one.
		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'capture_old_stock' ) );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'capture_old_stock' ) );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'capture_old_stock_on_save' ) );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'capture_old_stock_on_save' ) );

		// Record the change once WooCommerce has written it.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'record_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'record_stock_change' ) );

		// Label stock changes made while WooCommerce processes an order (priority 10 is WooCommerce's own handler).
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_completed', 'woocommerce_order_status_processing', 'woocommerce_order_status_on-hold', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_pending' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'begin_order_context' ), 5 );
			add_action( $hook, array( __CLASS__, 'end_context' ), 15 );
		}
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'begin_refund_context' ), 5, 2 );
		add_action( 'woocommerce_refund_created', array( __CLASS__, 'end_context' ), 5 );

		// Keep the tables clean when a product is deleted permanently.
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ) );

		// Average cost field on the product editor (Inventory tab).
		add_action( 'woocommerce_product_options_stock_fields', array( __CLASS__, 'render_product_cost_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_cost_field' ) );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation_cost_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_cost_field' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Labels
	 * ------------------------------------------------------------------- */

	/**
	 * Movement types and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'purchase'        => __( 'Purchase', 'woocommerce-resell-utility' ),
			'purchase_delete' => __( 'Purchase deleted', 'woocommerce-resell-utility' ),
			'sale'            => __( 'Sale', 'woocommerce-resell-utility' ),
			'sale_return'     => __( 'Sale return', 'woocommerce-resell-utility' ),
			'adjustment'      => __( 'Adjustment', 'woocommerce-resell-utility' ),
			'edit'            => __( 'Edited elsewhere', 'woocommerce-resell-utility' ),
		);
	}

	/**
	 * Reasons offered for a manual adjustment.
	 *
	 * @return array<string,string>
	 */
	public static function adjustment_reasons() {
		return array(
			'count'   => __( 'Stock count correction', 'woocommerce-resell-utility' ),
			'damaged' => __( 'Damaged', 'woocommerce-resell-utility' ),
			'lost'    => __( 'Lost or stolen', 'woocommerce-resell-utility' ),
			'return'  => __( 'Returned by courier', 'woocommerce-resell-utility' ),
			'opening' => __( 'Opening stock', 'woocommerce-resell-utility' ),
			'other'   => __( 'Other', 'woocommerce-resell-utility' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Context
	 * ------------------------------------------------------------------- */

	/**
	 * Push a context that labels stock changes until it is popped.
	 *
	 * @param array $ctx Keys: type, ref_type, ref_id, ref_label, note, unit_cost.
	 */
	public static function push_context( array $ctx ) {
		self::$context_stack[] = $ctx;
	}

	/**
	 * Pop the innermost context.
	 */
	public static function end_context() {
		array_pop( self::$context_stack );
	}

	/**
	 * Context for an order whose stock WooCommerce is about to reduce or restore.
	 *
	 * @param int|\WC_Order $order_id Order ID.
	 */
	public static function begin_order_context( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		$id    = $order ? $order->get_id() : absint( $order_id );
		self::push_context( array(
			'ref_type'  => 'order',
			'ref_id'    => $id,
			/* translators: %s: order number */
			'ref_label' => sprintf( __( 'Order #%s', 'woocommerce-resell-utility' ), $order ? $order->get_order_number() : $id ),
		) );
	}

	/**
	 * Context for a refund that may restock items.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @param array            $args   Refund args.
	 */
	public static function begin_refund_context( $refund, $args = array() ) {
		$order_id = $refund instanceof WC_Order_Refund ? $refund->get_parent_id() : 0;
		self::begin_order_context( $order_id );
		$last = array_pop( self::$context_stack );
		$last['note'] = __( 'Refund restock', 'woocommerce-resell-utility' );
		self::$context_stack[] = $last;
	}

	/**
	 * Innermost context, or a best guess for admin order-item edits made over AJAX.
	 *
	 * @return array|null
	 */
	private static function current_context() {
		if ( ! empty( self::$context_stack ) ) {
			return end( self::$context_stack );
		}

		// Editing line items on an order screen adjusts stock without any status hook.
		$ajax_actions = array( 'woocommerce_save_order_items', 'woocommerce_add_order_item', 'woocommerce_remove_order_item', 'woocommerce_delete_order_item' );
		if ( wp_doing_ajax() && isset( $_REQUEST['action'], $_REQUEST['order_id'] ) && in_array( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), $ajax_actions, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$order = wc_get_order( absint( $_REQUEST['order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $order ) {
				return array(
					'ref_type'  => 'order',
					'ref_id'    => $order->get_id(),
					/* translators: %s: order number */
					'ref_label' => sprintf( __( 'Order #%s', 'woocommerce-resell-utility' ), $order->get_order_number() ),
					'note'      => __( 'Order items edited', 'woocommerce-resell-utility' ),
				);
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Stock change capture
	 * ------------------------------------------------------------------- */

	/**
	 * Stock currently stored in the database, bypassing object caches.
	 *
	 * @param int $product_id Product ID.
	 * @return int|null Null when the product does not track stock.
	 */
	private static function read_stored_stock( $product_id ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' LIMIT 1",
			$product_id
		) );
		return ( null === $value || '' === $value ) ? null : (int) round( (float) $value );
	}

	/**
	 * Before wc_update_product_stock() writes.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function capture_old_stock( $product ) {
		$id = $product instanceof WC_Product ? $product->get_id() : 0;
		if ( $id && ! array_key_exists( $id, self::$old_stock ) ) {
			self::$old_stock[ $id ] = self::read_stored_stock( $id );
		}
	}

	/**
	 * Before a product object is saved with a changed stock quantity (product editor, REST, imports).
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function capture_old_stock_on_save( $product ) {
		$id = $product instanceof WC_Product ? $product->get_id() : 0;
		if ( ! $id || array_key_exists( $id, self::$old_stock ) ) {
			return;
		}
		$changes = $product->get_changes();
		if ( array_key_exists( 'stock_quantity', $changes ) || array_key_exists( 'manage_stock', $changes ) ) {
			self::$old_stock[ $id ] = self::read_stored_stock( $id );
		}
	}

	/**
	 * Write one movement row for a stock change WooCommerce just made.
	 *
	 * @param \WC_Product $product Product (the one that holds the stock).
	 */
	public static function record_stock_change( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
			return;
		}
		$id = $product->get_id();

		if ( array_key_exists( $id, self::$old_stock ) ) {
			$old = self::$old_stock[ $id ];
			unset( self::$old_stock[ $id ] );
		} else {
			$old = self::last_stock_after( $id );
		}

		$was_tracked = null !== $old;
		$new_raw     = $product->get_stock_quantity();
		$is_tracked  = $product->get_manage_stock() && null !== $new_raw;
		$new         = $is_tracked ? (int) round( (float) $new_raw ) : 0;
		$delta       = $new - (int) $old;

		if ( 0 === $delta ) {
			return;
		}

		$ctx  = self::current_context();
		$note = isset( $ctx['note'] ) ? $ctx['note'] : '';

		if ( isset( $ctx['type'] ) ) {
			$type = $ctx['type'];
		} elseif ( isset( $ctx['ref_type'] ) && 'order' === $ctx['ref_type'] ) {
			$type = $delta < 0 ? 'sale' : 'sale_return';
		} else {
			$type = 'edit';
			if ( ! $was_tracked ) {
				$note = __( 'Stock tracking turned on', 'woocommerce-resell-utility' );
			} elseif ( ! $is_tracked ) {
				$note = __( 'Stock tracking turned off', 'woocommerce-resell-utility' );
			} elseif ( '' === $note ) {
				$note = is_admin() ? __( 'Changed on the product page', 'woocommerce-resell-utility' ) : __( 'Changed by another plugin or import', 'woocommerce-resell-utility' );
			}
		}

		self::insert_movement( array(
			'product_id'   => $id,
			'product_name' => $product->get_name(),
			'type'         => $type,
			'qty_change'   => $delta,
			'stock_after'  => $is_tracked ? $new : null,
			'unit_cost'    => isset( $ctx['unit_cost'] ) ? (float) $ctx['unit_cost'] : null,
			'ref_type'     => isset( $ctx['ref_type'] ) ? $ctx['ref_type'] : 'product',
			'ref_id'       => isset( $ctx['ref_id'] ) ? (int) $ctx['ref_id'] : $id,
			'ref_label'    => isset( $ctx['ref_label'] ) ? $ctx['ref_label'] : __( 'Product editor', 'woocommerce-resell-utility' ),
			'note'         => $note,
		) );
	}

	/**
	 * Stock after the most recent logged movement for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|null
	 */
	private static function last_stock_after( $product_id ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare(
			'SELECT stock_after FROM ' . WRU_Inventory_DB::table( 'stock_movements' ) . ' WHERE product_id = %d ORDER BY id DESC LIMIT 1',
			$product_id
		) );
		return null === $value ? null : (int) $value;
	}

	/**
	 * Insert a movement row.
	 *
	 * @param array $row Row data.
	 * @return int Insert ID (0 on failure).
	 */
	private static function insert_movement( array $row ) {
		global $wpdb;
		$wpdb->insert(
			WRU_Inventory_DB::table( 'stock_movements' ),
			array(
				'product_id'   => (int) $row['product_id'],
				'product_name' => mb_substr( wp_strip_all_tags( (string) $row['product_name'] ), 0, 255 ),
				'type'         => (string) $row['type'],
				'qty_change'   => (int) $row['qty_change'],
				'stock_after'  => null === $row['stock_after'] ? null : (int) $row['stock_after'],
				'unit_cost'    => null === $row['unit_cost'] ? null : wc_format_decimal( $row['unit_cost'], 4 ),
				'ref_type'     => mb_substr( (string) $row['ref_type'], 0, 20 ),
				'ref_id'       => $row['ref_id'] ? (int) $row['ref_id'] : null,
				'ref_label'    => mb_substr( (string) $row['ref_label'], 0, 64 ),
				'note'         => mb_substr( (string) $row['note'], 0, 255 ),
				'user_id'      => get_current_user_id(),
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Stock changes made by this plugin
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a product can hold its own stock line in a purchase.
	 *
	 * @param \WC_Product|false $product Product.
	 * @return bool
	 */
	public static function is_stockable( $product ) {
		return $product instanceof WC_Product && ! in_array( $product->get_type(), array( 'variable', 'grouped', 'external' ), true );
	}

	/**
	 * Change stock by a signed amount through WooCommerce, labelled with a context.
	 * Turns stock tracking on for the product when it is off.
	 *
	 * @param \WC_Product $product Product.
	 * @param int         $delta   Signed quantity.
	 * @param array       $ctx     Context (see push_context()).
	 * @return int|\WP_Error New stock.
	 */
	public static function change_stock( $product, $delta, array $ctx ) {
		$delta = (int) $delta;
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'wru_no_product', __( 'Product not found.', 'woocommerce-resell-utility' ) );
		}
		if ( 0 === $delta ) {
			return (int) $product->get_stock_quantity();
		}

		if ( ! $product->managing_stock() ) {
			$product->set_manage_stock( true );
			if ( null === $product->get_stock_quantity() ) {
				$product->set_stock_quantity( 0 );
			}
			$product->save();
		}

		self::push_context( $ctx );
		try {
			$new = wc_update_product_stock( $product, abs( $delta ), $delta > 0 ? 'increase' : 'decrease' );
		} finally {
			self::end_context();
		}

		if ( false === $new || null === $new ) {
			return new WP_Error( 'wru_stock_failed', __( 'WooCommerce did not accept the stock change.', 'woocommerce-resell-utility' ) );
		}
		return (int) $new;
	}

	/**
	 * Manual adjustment from the Stock screen.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $mode       add|remove|set.
	 * @param int    $qty        Quantity (non-negative).
	 * @param string $reason     Key from adjustment_reasons().
	 * @param string $note       Free text.
	 * @return int|\WP_Error New stock.
	 */
	public static function adjust_stock( $product_id, $mode, $qty, $reason, $note = '' ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product || ! $product->managing_stock() ) {
			return new WP_Error( 'wru_no_product', __( 'This product does not track stock.', 'woocommerce-resell-utility' ) );
		}

		$qty = (int) $qty;
		if ( $qty < 0 || $qty > self::MAX_QTY ) {
			return new WP_Error( 'wru_bad_qty', __( 'Enter a quantity between 0 and 1,000,000.', 'woocommerce-resell-utility' ) );
		}

		$current = (int) $product->get_stock_quantity();
		switch ( $mode ) {
			case 'add':
				$delta = $qty;
				break;
			case 'remove':
				$delta = -$qty;
				break;
			case 'set':
				$delta = $qty - $current;
				break;
			default:
				return new WP_Error( 'wru_bad_mode', __( 'Choose add, remove or set.', 'woocommerce-resell-utility' ) );
		}

		if ( 0 === $delta ) {
			return new WP_Error( 'wru_no_change', __( 'Nothing to change: the stock would stay the same.', 'woocommerce-resell-utility' ) );
		}

		$reasons = self::adjustment_reasons();
		$label   = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reasons['other'];
		$note    = trim( $note );

		return self::change_stock( $product, $delta, array(
			'type'      => 'adjustment',
			'ref_type'  => 'manual',
			'ref_id'    => 0,
			'ref_label' => __( 'Manual', 'woocommerce-resell-utility' ),
			'note'      => '' !== $note ? $label . ': ' . $note : $label,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Average cost
	 * ------------------------------------------------------------------- */

	/**
	 * Average landed cost of a product, or null when unknown.
	 *
	 * @param int $product_id Product ID.
	 * @return float|null
	 */
	public static function get_avg_cost( $product_id ) {
		$value = get_post_meta( $product_id, self::META_AVG_COST, true );
		return ( '' === $value || false === $value || ! is_numeric( $value ) ) ? null : (float) $value;
	}

	/**
	 * Store an average cost.
	 *
	 * @param int        $product_id Product ID.
	 * @param float|null $cost       Cost, null clears it.
	 */
	private static function set_avg_cost( $product_id, $cost ) {
		if ( null === $cost ) {
			delete_post_meta( $product_id, self::META_AVG_COST );
			return;
		}
		update_post_meta( $product_id, self::META_AVG_COST, wc_format_decimal( max( 0, (float) $cost ), 4 ) );
	}

	/* ---------------------------------------------------------------------
	 * Suppliers
	 * ------------------------------------------------------------------- */

	/**
	 * One supplier.
	 *
	 * @param int $id Supplier ID.
	 * @return object|null
	 */
	public static function get_supplier( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WRU_Inventory_DB::table( 'suppliers' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * All suppliers with purchase count, total bought and amount still owed.
	 *
	 * @return object[]
	 */
	public static function get_suppliers_with_stats() {
		global $wpdb;
		$s = WRU_Inventory_DB::table( 'suppliers' );
		$p = WRU_Inventory_DB::table( 'purchases' );
		return $wpdb->get_results(
			"SELECT s.*, COUNT(p.id) AS purchase_count, COALESCE(SUM(p.total), 0) AS bought, COALESCE(SUM(GREATEST(p.total - p.paid, 0)), 0) AS owed
			FROM {$s} s LEFT JOIN {$p} p ON p.supplier_id = s.id
			GROUP BY s.id ORDER BY s.name ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Suppliers as id => name.
	 *
	 * @return array<int,string>
	 */
	public static function get_supplier_options() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, name FROM ' . WRU_Inventory_DB::table( 'suppliers' ) . ' ORDER BY name ASC' );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = $row->name;
		}
		return $out;
	}

	/**
	 * Create or update a supplier.
	 *
	 * @param array $data name, phone, email, address, note.
	 * @param int   $id   Existing supplier ID, 0 to create.
	 * @return int|\WP_Error Supplier ID.
	 */
	public static function save_supplier( array $data, $id = 0 ) {
		global $wpdb;

		$name  = isset( $data['name'] ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wru_supplier_name', __( 'Enter the supplier name.', 'woocommerce-resell-utility' ) );
		}
		if ( ! empty( $data['email'] ) && ! is_email( $email ) ) {
			return new WP_Error( 'wru_supplier_email', __( 'That email address does not look right.', 'woocommerce-resell-utility' ) );
		}

		$row = array(
			'name'       => mb_substr( $name, 0, 191 ),
			'phone'      => mb_substr( isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '', 0, 50 ),
			'email'      => mb_substr( $email, 0, 191 ),
			'address'    => isset( $data['address'] ) ? sanitize_textarea_field( $data['address'] ) : '',
			'note'       => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'updated_at' => current_time( 'mysql', true ),
		);

		$table = WRU_Inventory_DB::table( 'suppliers' );
		if ( $id ) {
			if ( ! self::get_supplier( $id ) ) {
				return new WP_Error( 'wru_supplier_missing', __( 'Supplier not found.', 'woocommerce-resell-utility' ) );
			}
			$wpdb->update( $table, $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}

		$row['created_at'] = $row['updated_at'];
		if ( false === $wpdb->insert( $table, $row ) ) {
			return new WP_Error( 'wru_db', __( 'The supplier could not be saved.', 'woocommerce-resell-utility' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a supplier. Refused while purchases still point at it.
	 *
	 * @param int $id Supplier ID.
	 * @return true|\WP_Error
	 */
	public static function delete_supplier( $id ) {
		global $wpdb;
		$supplier = self::get_supplier( $id );
		if ( ! $supplier ) {
			return new WP_Error( 'wru_supplier_missing', __( 'Supplier not found.', 'woocommerce-resell-utility' ) );
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WRU_Inventory_DB::table( 'purchases' ) . ' WHERE supplier_id = %d', $id ) );
		if ( $count > 0 ) {
			return new WP_Error(
				'wru_supplier_in_use',
				/* translators: 1: supplier name, 2: number of purchases */
				sprintf( _n( '%1$s is linked to %2$d purchase. Delete that purchase first, or keep the supplier.', '%1$s is linked to %2$d purchases. Delete those purchases first, or keep the supplier.', $count, 'woocommerce-resell-utility' ), $supplier->name, $count ),
				array( 'supplier_id' => (int) $id )
			);
		}

		$wpdb->delete( WRU_Inventory_DB::table( 'suppliers' ), array( 'id' => (int) $id ) );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Purchases
	 * ------------------------------------------------------------------- */

	/**
	 * One purchase with its supplier name.
	 *
	 * @param int $id Purchase ID.
	 * @return object|null
	 */
	public static function get_purchase( $id ) {
		global $wpdb;
		$p = WRU_Inventory_DB::table( 'purchases' );
		$s = WRU_Inventory_DB::table( 'suppliers' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT p.*, s.name AS supplier_name FROM {$p} p LEFT JOIN {$s} s ON s.id = p.supplier_id WHERE p.id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Lines of a purchase.
	 *
	 * @param int $purchase_id Purchase ID.
	 * @return object[]
	 */
	public static function get_purchase_items( $purchase_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WRU_Inventory_DB::table( 'purchase_items' ) . ' WHERE purchase_id = %d ORDER BY id ASC', $purchase_id ) );
	}

	/**
	 * Validate and save a purchase, then add its stock and update average costs.
	 *
	 * @param array $data {
	 *     @type int|string $supplier_id   Existing supplier ID, '' for none, 'new' to create one.
	 *     @type array      $new_supplier  name, phone (when supplier_id is 'new').
	 *     @type string     $purchase_date Y-m-d.
	 *     @type string     $bill_no       Supplier bill number.
	 *     @type string     $note          Note.
	 *     @type float      $transport_cost, $labour_cost, $other_cost, $discount, $paid
	 *     @type array      $items         List of [ product_id, qty, unit_cost ].
	 * }
	 * @return int|\WP_Error Purchase ID.
	 */
	public static function create_purchase( array $data ) {
		global $wpdb;

		$errors = new WP_Error();

		// Date.
		$date = isset( $data['purchase_date'] ) ? (string) $data['purchase_date'] : '';
		$dt   = DateTime::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			$errors->add( 'date', __( 'Pick a valid purchase date.', 'woocommerce-resell-utility' ) );
		}

		// Supplier.
		$supplier_id  = 0;
		$new_supplier = null;
		if ( isset( $data['supplier_id'] ) && 'new' === $data['supplier_id'] ) {
			$new_supplier = isset( $data['new_supplier'] ) ? (array) $data['new_supplier'] : array();
			if ( empty( $new_supplier['name'] ) || '' === trim( $new_supplier['name'] ) ) {
				$errors->add( 'supplier', __( 'Enter the new supplier name, or pick an existing supplier.', 'woocommerce-resell-utility' ) );
			}
		} elseif ( ! empty( $data['supplier_id'] ) ) {
			$supplier_id = absint( $data['supplier_id'] );
			if ( ! self::get_supplier( $supplier_id ) ) {
				$errors->add( 'supplier', __( 'That supplier no longer exists.', 'woocommerce-resell-utility' ) );
			}
		}

		// Money fields.
		$money = array();
		foreach ( array( 'transport_cost', 'labour_cost', 'other_cost', 'discount', 'paid' ) as $key ) {
			$raw = isset( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
			if ( '' === $raw ) {
				$money[ $key ] = 0.0;
				continue;
			}
			if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
				$errors->add( $key, __( 'Costs, discount and paid amount must be zero or more.', 'woocommerce-resell-utility' ) );
				$money[ $key ] = 0.0;
				continue;
			}
			$money[ $key ] = round( (float) $raw, 2 );
		}

		// Lines.
		$lines = array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		foreach ( $items as $i => $item ) {
			$pid  = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
			$qraw = isset( $item['qty'] ) ? trim( (string) $item['qty'] ) : '';
			$craw = isset( $item['unit_cost'] ) ? trim( (string) $item['unit_cost'] ) : '';
			if ( ! $pid ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! self::is_stockable( $product ) ) {
				/* translators: %d: line number */
				$errors->add( 'item', sprintf( __( 'Line %d: pick a single product or a specific variation, not a variable or grouped product.', 'woocommerce-resell-utility' ), $i + 1 ) );
				continue;
			}
			if ( ! ctype_digit( $qraw ) || (int) $qraw < 1 || (int) $qraw > self::MAX_QTY ) {
				/* translators: %s: product name */
				$errors->add( 'item', sprintf( __( '%s: quantity must be a whole number of at least 1.', 'woocommerce-resell-utility' ), $product->get_name() ) );
				continue;
			}
			if ( '' === $craw || ! is_numeric( $craw ) || (float) $craw < 0 ) {
				/* translators: %s: product name */
				$errors->add( 'item', sprintf( __( '%s: unit cost must be zero or more.', 'woocommerce-resell-utility' ), $product->get_name() ) );
				continue;
			}
			$lines[] = array(
				'product'   => $product,
				'qty'       => (int) $qraw,
				'unit_cost' => round( (float) $craw, 4 ),
			);
		}
		if ( empty( $lines ) && ! $errors->has_errors() ) {
			$errors->add( 'items', __( 'Add at least one product to the purchase.', 'woocommerce-resell-utility' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		// Totals and landed-cost allocation (by line value; by quantity if everything was free).
		$subtotal  = 0.0;
		$total_qty = 0;
		foreach ( $lines as $line ) {
			$subtotal  += $line['qty'] * $line['unit_cost'];
			$total_qty += $line['qty'];
		}
		$subtotal = round( $subtotal, 2 );
		$extras   = $money['transport_cost'] + $money['labour_cost'] + $money['other_cost'];

		if ( $money['discount'] > $subtotal + $extras ) {
			return new WP_Error( 'discount', __( 'The discount is larger than the whole purchase.', 'woocommerce-resell-utility' ) );
		}

		$total     = round( $subtotal + $extras - $money['discount'], 2 );
		$net_extra = $extras - $money['discount'];

		foreach ( $lines as &$line ) {
			$value              = $line['qty'] * $line['unit_cost'];
			$share              = $subtotal > 0 ? $value / $subtotal : $line['qty'] / $total_qty;
			$line['line_total'] = round( $value, 2 );
			$line['landed']     = round( max( 0, ( $value + $share * $net_extra ) / $line['qty'] ), 4 );
		}
		unset( $line );

		// Save rows atomically; stock is only touched after the commit succeeds.
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );

		if ( $new_supplier ) {
			$supplier_id = self::save_supplier( $new_supplier );
			if ( is_wp_error( $supplier_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $supplier_id;
			}
		}

		$ok = $wpdb->insert( WRU_Inventory_DB::table( 'purchases' ), array(
			'ref_no'         => null,
			'supplier_id'    => $supplier_id ? $supplier_id : null,
			'purchase_date'  => $date,
			'bill_no'        => mb_substr( isset( $data['bill_no'] ) ? sanitize_text_field( $data['bill_no'] ) : '', 0, 64 ),
			'subtotal'       => $subtotal,
			'transport_cost' => $money['transport_cost'],
			'labour_cost'    => $money['labour_cost'],
			'other_cost'     => $money['other_cost'],
			'discount'       => $money['discount'],
			'total'          => $total,
			'paid'           => $money['paid'],
			'note'           => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'created_by'     => get_current_user_id(),
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		$purchase_id = (int) $wpdb->insert_id;

		if ( ! $ok || ! $purchase_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'wru_db', __( 'The purchase could not be saved. Please try again.', 'woocommerce-resell-utility' ) );
		}

		$ref_no = sprintf( 'PO-%s-%04d', $dt->format( 'Y' ), $purchase_id );
		$wpdb->update( WRU_Inventory_DB::table( 'purchases' ), array( 'ref_no' => $ref_no ), array( 'id' => $purchase_id ) );

		foreach ( $lines as $line ) {
			$ok = $wpdb->insert( WRU_Inventory_DB::table( 'purchase_items' ), array(
				'purchase_id'  => $purchase_id,
				'product_id'   => $line['product']->get_id(),
				'product_name' => mb_substr( wp_strip_all_tags( $line['product']->get_name() ), 0, 255 ),
				'sku'          => mb_substr( (string) $line['product']->get_sku(), 0, 100 ),
				'qty'          => $line['qty'],
				'unit_cost'    => $line['unit_cost'],
				'landed_cost'  => $line['landed'],
				'line_total'   => $line['line_total'],
			) );
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'wru_db', __( 'The purchase could not be saved. Please try again.', 'woocommerce-resell-utility' ) );
			}
		}
		$wpdb->query( 'COMMIT' );

		// Stock and cost.
		$supplier_name = $supplier_id ? (string) self::get_supplier( $supplier_id )->name : '';
		foreach ( $lines as $line ) {
			$product = wc_get_product( $line['product']->get_id() ); // Fresh: an earlier line may be the same product.
			$on_hand = max( 0, (int) $product->get_stock_quantity() );
			$avg     = self::get_avg_cost( $product->get_id() );
			$new_avg = ( null === $avg || 0 === $on_hand ) ? $line['landed'] : ( $on_hand * $avg + $line['qty'] * $line['landed'] ) / ( $on_hand + $line['qty'] );
			self::set_avg_cost( $product->get_id(), $new_avg );

			self::change_stock( $product, $line['qty'], array(
				'type'      => 'purchase',
				'ref_type'  => 'purchase',
				'ref_id'    => $purchase_id,
				'ref_label' => $ref_no,
				'unit_cost' => $line['landed'],
				'note'      => $supplier_name,
			) );
		}

		return $purchase_id;
	}

	/**
	 * Update the editable header fields of a saved purchase.
	 *
	 * @param int   $id   Purchase ID.
	 * @param array $data bill_no, note, paid.
	 * @return true|\WP_Error
	 */
	public static function update_purchase_header( $id, array $data ) {
		global $wpdb;
		if ( ! self::get_purchase( $id ) ) {
			return new WP_Error( 'wru_purchase_missing', __( 'Purchase not found.', 'woocommerce-resell-utility' ) );
		}
		$paid = isset( $data['paid'] ) ? trim( (string) $data['paid'] ) : '0';
		if ( '' === $paid ) {
			$paid = '0';
		}
		if ( ! is_numeric( $paid ) || (float) $paid < 0 ) {
			return new WP_Error( 'paid', __( 'Paid amount must be zero or more.', 'woocommerce-resell-utility' ) );
		}
		$wpdb->update( WRU_Inventory_DB::table( 'purchases' ), array(
			'bill_no'    => mb_substr( isset( $data['bill_no'] ) ? sanitize_text_field( $data['bill_no'] ) : '', 0, 64 ),
			'note'       => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'paid'       => round( (float) $paid, 2 ),
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Delete a purchase: take its stock back out, roll back average cost, then remove the rows.
	 * Refused when any product no longer has enough stock (it was already sold).
	 *
	 * @param int $id Purchase ID.
	 * @return true|\WP_Error
	 */
	public static function delete_purchase( $id ) {
		global $wpdb;

		$purchase = self::get_purchase( $id );
		if ( ! $purchase ) {
			return new WP_Error( 'wru_purchase_missing', __( 'Purchase not found.', 'woocommerce-resell-utility' ) );
		}
		$items = self::get_purchase_items( $id );

		// Check every product first so nothing is half-reversed.
		$need = array();
		foreach ( $items as $item ) {
			if ( $item->product_id ) {
				$need[ (int) $item->product_id ] = ( isset( $need[ (int) $item->product_id ] ) ? $need[ (int) $item->product_id ] : 0 ) + (int) $item->qty;
			}
		}
		$short = array();
		foreach ( $need as $pid => $qty ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			$stock = (int) $product->get_stock_quantity();
			if ( $stock < $qty ) {
				/* translators: 1: product name, 2: stock now, 3: quantity in this purchase */
				$short[] = sprintf( __( '%1$s has %2$d in stock but this purchase added %3$d', 'woocommerce-resell-utility' ), $product->get_name(), $stock, $qty );
			}
		}
		if ( $short ) {
			return new WP_Error(
				'wru_purchase_sold',
				__( 'This purchase can\'t be deleted because some of its stock is already sold:', 'woocommerce-resell-utility' ) . ' ' . implode( '; ', $short ) . '. ' . __( 'Use a stock adjustment instead.', 'woocommerce-resell-utility' )
			);
		}

		foreach ( $items as $item ) {
			$product = $item->product_id ? wc_get_product( (int) $item->product_id ) : false;
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			$on_hand   = (int) $product->get_stock_quantity();
			$avg       = self::get_avg_cost( $product->get_id() );
			$remaining = $on_hand - (int) $item->qty;
			if ( null !== $avg && $remaining > 0 ) {
				self::set_avg_cost( $product->get_id(), ( $on_hand * $avg - (int) $item->qty * (float) $item->landed_cost ) / $remaining );
			}

			self::change_stock( $product, -(int) $item->qty, array(
				'type'      => 'purchase_delete',
				'ref_type'  => 'purchase',
				'ref_id'    => (int) $purchase->id,
				'ref_label' => $purchase->ref_no,
				'unit_cost' => (float) $item->landed_cost,
				'note'      => __( 'Purchase deleted', 'woocommerce-resell-utility' ),
			) );
		}

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( WRU_Inventory_DB::table( 'purchase_items' ), array( 'purchase_id' => (int) $id ) );
		$wpdb->delete( WRU_Inventory_DB::table( 'purchases' ), array( 'id' => (int) $id ) );
		$wpdb->query( 'COMMIT' );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Cleanup
	 * ------------------------------------------------------------------- */

	/**
	 * A product or variation is being deleted permanently: drop its movement rows and detach purchase lines
	 * (they keep their name and SKU snapshot).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_post_deleted( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( WRU_Inventory_DB::table( 'stock_movements' ), array( 'product_id' => (int) $post_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . WRU_Inventory_DB::table( 'purchase_items' ) . ' SET product_id = NULL WHERE product_id = %d', $post_id ) );
		unset( self::$old_stock[ (int) $post_id ] );
	}

	/* ---------------------------------------------------------------------
	 * Product editor field
	 * ------------------------------------------------------------------- */

	/**
	 * Average cost field on the Inventory tab of simple products.
	 */
	public static function render_product_cost_field() {
		global $product_object;
		if ( ! $product_object instanceof WC_Product || ! self::is_stockable( $product_object ) ) {
			return;
		}
		$cost = self::get_avg_cost( $product_object->get_id() );
		woocommerce_wp_text_input( array(
			'id'                => 'wru_avg_cost',
			'value'             => null === $cost ? '' : wc_format_localized_decimal( $cost ),
			/* translators: %s: currency symbol */
			'label'             => sprintf( __( 'Average cost (%s)', 'woocommerce-resell-utility' ), get_woocommerce_currency_symbol() ),
			'description'       => __( 'Updated automatically by purchases. Set it once for stock you already had.', 'woocommerce-resell-utility' ),
			'desc_tip'          => true,
			'data_type'         => 'price',
		) );
	}

	/**
	 * Save the average cost field.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save_product_cost_field( $product ) {
		if ( ! isset( $_POST['wru_avg_cost'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the product nonce.
			return;
		}
		$raw = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['wru_avg_cost'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $raw ) {
			$product->delete_meta_data( self::META_AVG_COST );
		} elseif ( is_numeric( $raw ) && (float) $raw >= 0 ) {
			$product->update_meta_data( self::META_AVG_COST, wc_format_decimal( $raw, 4 ) );
		}
	}

	/**
	 * Average cost field for one variation.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function render_variation_cost_field( $loop, $variation_data, $variation ) {
		$cost = self::get_avg_cost( $variation->ID );
		woocommerce_wp_text_input( array(
			'id'            => "wru_variation_avg_cost{$loop}",
			'name'          => "wru_variation_avg_cost[{$loop}]",
			'value'         => null === $cost ? '' : wc_format_localized_decimal( $cost ),
			/* translators: %s: currency symbol */
			'label'         => sprintf( __( 'Average cost (%s)', 'woocommerce-resell-utility' ), get_woocommerce_currency_symbol() ),
			'data_type'     => 'price',
			'wrapper_class' => 'form-row form-row-first',
		) );
	}

	/**
	 * Save a variation's average cost.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Index.
	 */
	public static function save_variation_cost_field( $variation_id, $i ) {
		if ( ! isset( $_POST['wru_variation_avg_cost'][ $i ] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the variations nonce.
			return;
		}
		$raw = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['wru_variation_avg_cost'][ $i ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $raw ) {
			delete_post_meta( $variation_id, self::META_AVG_COST );
		} elseif ( is_numeric( $raw ) && (float) $raw >= 0 ) {
			update_post_meta( $variation_id, self::META_AVG_COST, wc_format_decimal( $raw, 4 ) );
		}
	}
}
