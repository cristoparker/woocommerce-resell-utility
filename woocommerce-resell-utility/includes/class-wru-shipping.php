<?php
/**
 * Per-product and per-location delivery charges.
 *
 * Each product may define its own delivery charge (optionally per country / state). Products
 * without one use the store's normal WooCommerce delivery charge. A cart ships as one parcel, so the
 * delivery charge is the highest charge any item in it requires.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Shipping {

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
		add_filter( 'woocommerce_package_rates', array( $this, 'apply_custom_charges' ), 100, 2 );
	}

	/**
	 * Highest custom charge among the package items, and whether any item uses the default charge.
	 *
	 * @param array $package WooCommerce shipping package.
	 * @return array array( float|null $custom_max, bool $has_default_items )
	 */
	private function summarize_package( $package ) {
		$country = isset( $package['destination']['country'] ) ? (string) $package['destination']['country'] : '';
		$state   = isset( $package['destination']['state'] ) ? (string) $package['destination']['state'] : '';

		$custom_max   = null;
		$has_defaults = false;

		foreach ( (array) $package['contents'] as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$charge = WRU_Product_Fields::get_shipping_charge_for_destination( $item['data'], $country, $state );
			if ( null === $charge ) {
				$has_defaults = true;
			} else {
				$custom_max = null === $custom_max ? $charge : max( $custom_max, $charge );
			}
		}

		return array( $custom_max, $has_defaults );
	}

	/**
	 * Replace the cost of paid shipping rates by the products' custom charges.
	 *
	 * @param array $rates   Shipping rates keyed by rate ID.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function apply_custom_charges( $rates, $package ) {
		list( $custom_max, $has_defaults ) = $this->summarize_package( $package );
		if ( null === $custom_max ) {
			return $rates; // Every item uses the default delivery charge.
		}

		$skip = apply_filters( 'wru_shipping_skip_methods', array( 'free_shipping', 'local_pickup' ) );

		foreach ( $rates as $rate_id => $rate ) {
			if ( in_array( $rate->get_method_id(), $skip, true ) ) {
				continue;
			}

			// Mixed carts keep the default rate when it is higher than the custom charge.
			$cost = $has_defaults ? max( $custom_max, (float) $rate->get_cost() ) : $custom_max;
			$cost = (float) apply_filters( 'wru_custom_shipping_cost', $cost, $rate, $package );

			$taxed = array_sum( (array) $rate->get_taxes() ) > 0;
			$rate->set_cost( $cost );
			if ( $taxed && wc_tax_enabled() ) {
				$rate->set_taxes( WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() ) );
			}
		}

		return $rates;
	}
}
