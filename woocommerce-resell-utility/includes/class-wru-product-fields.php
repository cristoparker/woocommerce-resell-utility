<?php
/**
 * Product-specific Reselling fields (Packaging Fee & Shipping Charge).
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRU_Product_Fields
 */
class WRU_Product_Fields {

	/**
	 * Meta key for product-specific packaging fee.
	 */
	const META_PACKAGING_FEE = '_wru_product_packaging_fee';

	/**
	 * Meta key for product-specific shipping charge.
	 */
	const META_SHIPPING_CHARGE = '_wru_product_shipping_charge';

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
		// Product edit screen fields (General tab).
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_product_resell_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_resell_fields' ) );

		// Filter display labels on product edit dashboard: Regular price -> Market price, Sale price -> Resell price.
		add_filter( 'gettext', array( $this, 'filter_product_price_labels' ), 99, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_product_price_labels_context' ), 99, 4 );
	}

	/**
	 * Filter WooCommerce gettext labels on product edit dashboard:
	 * Regular price -> Market price, Sale price -> Resell price.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_product_price_labels( $translation, $text, $domain ) {
		if ( 'woocommerce' === $domain && is_admin() ) {
			if ( 'Regular price' === $text || 'Regular Price' === $text ) {
				return __( 'Market price', 'woocommerce-resell-utility' );
			}
			if ( 'Sale price' === $text || 'Sale Price' === $text ) {
				return __( 'Resell price', 'woocommerce-resell-utility' );
			}
			if ( 'Regular price (%s)' === $text || 'Regular Price (%s)' === $text ) {
				return __( 'Market price (%s)', 'woocommerce-resell-utility' );
			}
			if ( 'Sale price (%s)' === $text || 'Sale Price (%s)' === $text ) {
				return __( 'Resell price (%s)', 'woocommerce-resell-utility' );
			}
			if ( 'Variation price (required)' === $text ) {
				return __( 'Market price (required)', 'woocommerce-resell-utility' );
			}
		}
		return $translation;
	}

	/**
	 * Filter WooCommerce gettext_with_context labels.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $context     Context information.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_product_price_labels_context( $translation, $text, $context, $domain ) {
		return $this->filter_product_price_labels( $translation, $text, $domain );
	}

	/**
	 * Render product specific packaging & shipping fields in Product Data metabox.
	 */
	public function render_product_resell_fields() {
		echo '<div class="options_group wru-product-resell-options" style="border-top: 1px dashed #ccd0d4; margin-top: 12px; padding-top: 8px;">';
		echo '<p style="margin: 0 0 10px 12px; font-weight: 700; color: #1e293b;">' . esc_html__( 'রিসেলিং কাস্টম খরচ (ঐচ্ছিক)', 'woocommerce-resell-utility' ) . '</p>';

		$default_packaging = WRU_Settings::get_packaging_fee();

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_PACKAGING_FEE,
				'label'       => __( 'প্যাকেজিং ফি (৳)', 'woocommerce-resell-utility' ),
				'placeholder' => sprintf( __( 'ডিফল্ট: ৳%s', 'woocommerce-resell-utility' ), $default_packaging ),
				'description' => __( 'এই নির্দিষ্ট প্রোডাক্টের জন্য প্যাকেজিং ফি। খালি রাখলে গ্লোবাল ডিফল্ট সেটিংস ব্যবহৃত হবে।', 'woocommerce-resell-utility' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_SHIPPING_CHARGE,
				'label'       => __( 'স্পেসিফিক শিপিং চার্জ (৳)', 'woocommerce-resell-utility' ),
				'placeholder' => __( 'যেমন: 60 বা 120', 'woocommerce-resell-utility' ),
				'description' => __( 'এই প্রোডাক্টের ডেলিভারি চার্জ যদি সাধারণ চার্জের চেয়ে ভিন্ন হয় (ঐচ্ছিক)। খালি রাখলে সাধারণ শিপিং প্রযোজ্য হবে।', 'woocommerce-resell-utility' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);

		echo '</div>';
	}

	/**
	 * Save product resell meta fields.
	 *
	 * @param int $post_id Product post ID.
	 */
	public function save_product_resell_fields( $post_id ) {
		// Packaging fee.
		if ( isset( $_POST[ self::META_PACKAGING_FEE ] ) ) {
			$pack_val = sanitize_text_field( wp_unslash( $_POST[ self::META_PACKAGING_FEE ] ) );
			if ( '' === $pack_val ) {
				delete_post_meta( $post_id, self::META_PACKAGING_FEE );
			} else {
				update_post_meta( $post_id, self::META_PACKAGING_FEE, max( 0, (float) $pack_val ) );
			}
		}

		// Shipping charge.
		if ( isset( $_POST[ self::META_SHIPPING_CHARGE ] ) ) {
			$ship_val = sanitize_text_field( wp_unslash( $_POST[ self::META_SHIPPING_CHARGE ] ) );
			if ( '' === $ship_val ) {
				delete_post_meta( $post_id, self::META_SHIPPING_CHARGE );
			} else {
				update_post_meta( $post_id, self::META_SHIPPING_CHARGE, max( 0, (float) $ship_val ) );
			}
		}
	}

	/**
	 * Get packaging fee for a given product or fallback to global default.
	 *
	 * @param int|WC_Product $product Product ID or WC_Product object.
	 * @return float
	 */
	public static function get_packaging_fee( $product ) {
		$product_id = is_numeric( $product ) ? (int) $product : ( $product instanceof WC_Product ? $product->get_id() : 0 );
		if ( ! $product_id ) {
			return WRU_Settings::get_packaging_fee();
		}

		// For variable variations, check parent if variation has no meta.
		$val = get_post_meta( $product_id, self::META_PACKAGING_FEE, true );
		if ( '' === $val || false === $val ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$val = get_post_meta( $parent_id, self::META_PACKAGING_FEE, true );
			}
		}

		if ( '' !== $val && false !== $val && is_numeric( $val ) ) {
			return max( 0, (float) $val );
		}

		return WRU_Settings::get_packaging_fee();
	}

	/**
	 * Get shipping charge for a given product or return null if not set.
	 *
	 * @param int|WC_Product $product Product ID or WC_Product object.
	 * @return float|null
	 */
	public static function get_shipping_charge( $product ) {
		$product_id = is_numeric( $product ) ? (int) $product : ( $product instanceof WC_Product ? $product->get_id() : 0 );
		if ( ! $product_id ) {
			return null;
		}

		$val = get_post_meta( $product_id, self::META_SHIPPING_CHARGE, true );
		if ( '' === $val || false === $val ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$val = get_post_meta( $parent_id, self::META_SHIPPING_CHARGE, true );
			}
		}

		if ( '' !== $val && false !== $val && is_numeric( $val ) ) {
			return max( 0, (float) $val );
		}

		return null;
	}
}
