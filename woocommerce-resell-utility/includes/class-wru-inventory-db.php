<?php
/**
 * Inventory database schema: install, upgrade and removal.
 *
 * Tables (all InnoDB, WordPress prefix + "wru_"):
 *  - suppliers        People you buy stock from.
 *  - purchases        One row per supplier bill. supplier_id -> suppliers.id ON DELETE RESTRICT.
 *  - purchase_items   Lines of a purchase. purchase_id -> purchases.id ON DELETE CASCADE.
 *                     product_id is NULLed when the WooCommerce product is deleted (name/SKU are snapshots).
 *  - stock_movements  Append-only log of every stock change. Rows for a product are removed with the product.
 *
 * The stock quantity itself stays in WooCommerce (_stock), the single source of truth.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Inventory_DB {

	const VERSION        = '1.0.0';
	const VERSION_OPTION = 'wru_inventory_db_version';

	/**
	 * Full table name.
	 *
	 * @param string $name suppliers|purchases|purchase_items|stock_movements.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wru_' . $name;
	}

	/**
	 * Install or upgrade when the stored schema version differs.
	 */
	public static function maybe_install() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create / upgrade all inventory tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate   = $wpdb->get_charset_collate();
		$suppliers = self::table( 'suppliers' );
		$purchases = self::table( 'purchases' );
		$items     = self::table( 'purchase_items' );
		$moves     = self::table( 'stock_movements' );

		// dbDelta is strict: one field per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
		dbDelta( "CREATE TABLE {$suppliers} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  phone varchar(50) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  address text NULL,
  note text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name)
) ENGINE=InnoDB {$collate};" );

		dbDelta( "CREATE TABLE {$purchases} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref_no varchar(32) NULL,
  supplier_id bigint(20) unsigned NULL,
  purchase_date date NOT NULL,
  bill_no varchar(64) NOT NULL DEFAULT '',
  subtotal decimal(14,2) NOT NULL DEFAULT 0,
  transport_cost decimal(14,2) NOT NULL DEFAULT 0,
  labour_cost decimal(14,2) NOT NULL DEFAULT 0,
  other_cost decimal(14,2) NOT NULL DEFAULT 0,
  discount decimal(14,2) NOT NULL DEFAULT 0,
  total decimal(14,2) NOT NULL DEFAULT 0,
  paid decimal(14,2) NOT NULL DEFAULT 0,
  note text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ref_no (ref_no),
  KEY supplier_id (supplier_id),
  KEY purchase_date (purchase_date)
) ENGINE=InnoDB {$collate};" );

		dbDelta( "CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  purchase_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NULL,
  product_name varchar(255) NOT NULL DEFAULT '',
  sku varchar(100) NOT NULL DEFAULT '',
  qty int(11) NOT NULL DEFAULT 0,
  unit_cost decimal(14,4) NOT NULL DEFAULT 0,
  landed_cost decimal(14,4) NOT NULL DEFAULT 0,
  line_total decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY purchase_id (purchase_id),
  KEY product_id (product_id)
) ENGINE=InnoDB {$collate};" );

		dbDelta( "CREATE TABLE {$moves} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  product_name varchar(255) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL,
  qty_change int(11) NOT NULL,
  stock_after int(11) NULL,
  unit_cost decimal(14,4) NULL,
  ref_type varchar(20) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NULL,
  ref_label varchar(64) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY product_created (product_id,created_at),
  KEY type (type),
  KEY ref (ref_type,ref_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {$collate};" );

		self::add_foreign_keys();

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Add real foreign keys where the engine supports them. The PHP layer enforces the same
	 * rules, so the plugin still behaves correctly on hosts that refuse the constraints.
	 */
	private static function add_foreign_keys() {
		global $wpdb;

		$suppliers = self::table( 'suppliers' );
		$purchases = self::table( 'purchases' );
		$items     = self::table( 'purchase_items' );

		$constraints = array(
			array( $purchases, $wpdb->prefix . 'wru_fk_purchase_supplier', "FOREIGN KEY (supplier_id) REFERENCES {$suppliers} (id) ON DELETE RESTRICT ON UPDATE CASCADE" ),
			array( $items, $wpdb->prefix . 'wru_fk_item_purchase', "FOREIGN KEY (purchase_id) REFERENCES {$purchases} (id) ON DELETE CASCADE ON UPDATE CASCADE" ),
		);

		$suppress = $wpdb->suppress_errors( true );
		foreach ( $constraints as $c ) {
			list( $table, $name, $definition ) = $c;
			$name = substr( $name, 0, 64 ); // MySQL identifier limit.

			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
				$table,
				$name
			) );
			if ( $exists ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE {$table} ADD CONSTRAINT `{$name}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Drop every inventory table and option (uninstall only).
	 */
	public static function drop_all() {
		global $wpdb;
		// Children first so foreign keys never block the drop.
		foreach ( array( 'purchase_items', 'stock_movements', 'purchases', 'suppliers' ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::VERSION_OPTION );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wru_avg_cost' ) );
	}
}
