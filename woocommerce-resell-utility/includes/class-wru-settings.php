<?php
/**
 * Settings Manager for WooCommerce Resell Utility.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Settings {

	const OPTION_PACKAGING_FEE       = 'wru_packaging_fee';
	const OPTION_PACKAGING_TYPE      = 'wru_packaging_type'; // 'order' or 'item'
	const OPTION_ENFORCE_MIN_PRICE   = 'wru_enforce_min_price';
	const OPTION_ENABLE_BUY_NOW      = 'wru_enable_buy_now';
	const OPTION_ENABLE_SHOP_BUY_NOW = 'wru_enable_shop_buy_now';
	const OPTION_ENABLE_TOOLS        = 'wru_enable_product_tools';
	const OPTION_ENABLE_DASHBOARD    = 'wru_enable_reseller_dashboard';
	const OPTION_MARKET_LABEL        = 'wru_market_price_label';
	const OPTION_RESELLER_LABEL      = 'wru_reseller_price_label';

	/**
	 * Get packaging fee amount.
	 *
	 * @return float
	 */
	public static function get_packaging_fee() {
		return (float) get_option( self::OPTION_PACKAGING_FEE, 20.0 );
	}

	/**
	 * Get packaging fee type: 'order' (once per order) or 'item' (per quantity item).
	 *
	 * @return string
	 */
	public static function get_packaging_type() {
		return get_option( self::OPTION_PACKAGING_TYPE, 'order' );
	}

	/**
	 * Check if minimum selling price rule is enforced (selling price >= wholesale price).
	 *
	 * @return bool
	 */
	public static function is_min_price_enforced() {
		return 'yes' === get_option( self::OPTION_ENFORCE_MIN_PRICE, 'yes' );
	}

	/**
	 * Check if single product Buy Now button is enabled.
	 *
	 * @return bool
	 */
	public static function is_buy_now_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLE_BUY_NOW, 'yes' );
	}

	/**
	 * Check if shop loop Buy Now button is enabled.
	 *
	 * @return bool
	 */
	public static function is_shop_buy_now_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLE_SHOP_BUY_NOW, 'yes' );
	}

	/**
	 * Check if reseller product tools (copy description, image download) are enabled.
	 *
	 * @return bool
	 */
	public static function are_product_tools_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLE_TOOLS, 'yes' );
	}

	/**
	 * Check if Reseller Account Dashboard is enabled.
	 *
	 * @return bool
	 */
	public static function is_dashboard_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLE_DASHBOARD, 'yes' );
	}

	/**
	 * Get Market Price label.
	 *
	 * @return string
	 */
	public static function get_market_label() {
		return get_option( self::OPTION_MARKET_LABEL, __( 'Market Price:', 'woocommerce-resell-utility' ) );
	}

	/**
	 * Get Reseller Price label.
	 *
	 * @return string
	 */
	public static function get_reseller_label() {
		return get_option( self::OPTION_RESELLER_LABEL, __( 'Reseller Price:', 'woocommerce-resell-utility' ) );
	}
}
