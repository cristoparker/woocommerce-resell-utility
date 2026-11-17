<?php
/**
 * Uninstall: removes inventory tables only when the store owner opted in.
 *
 * Reseller balances, cashouts and order data are never removed here (see the
 * "Data persistence" card in the plugin settings).
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( 'yes' !== get_option( 'wru_remove_inventory_data_on_uninstall', 'no' ) ) {
	return;
}

require_once __DIR__ . '/includes/class-wru-inventory-db.php';

WRU_Inventory_DB::drop_all();
delete_option( 'wru_remove_inventory_data_on_uninstall' );
