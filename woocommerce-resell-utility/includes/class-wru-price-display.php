<?php
/**
 * Market & Reseller Price Display Module.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Price_Display {

	/**
	 * Main instance getter.
	 *
	 * @return WRU_Price_Display
	 */
	public static function init() {
		$instance = new self();
		$instance->register_hooks();
		return $instance;
	}

	/**
	 * Register WooCommerce hooks.
	 */
	public function register_hooks() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 100, 2 );
			add_filter( 'woocommerce_available_variation', array( $this, 'filter_available_variation' ), 100, 3 );
			add_filter( 'woocommerce_sale_flash', array( $this, 'filter_sale_flash' ), 100, 3 );
		}
	}

	/**
	 * Suppress standard "Sale!" flash badge.
	 *
	 * @param string      $html    Sale flash HTML.
	 * @param \WP_Post    $post    Post object.
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	public function filter_sale_flash( $html, $post, $product ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}

		$hide_sale_flash = apply_filters( 'wru_hide_sale_flash', true, $product );
		return $hide_sale_flash ? '' : $html;
	}

	/**
	 * Filter WooCommerce price HTML globally on the frontend.
	 *
	 * @param string      $price_html Formatted price HTML from WooCommerce.
	 * @param \WC_Product $product    Product object.
	 * @return string Formatted Market & Reseller price HTML.
	 */
	public function filter_price_html( $price_html, $product ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $price_html;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $price_html;
		}

		if ( '' === $product->get_price() && '' === $product->get_regular_price() ) {
			return $price_html;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->format_variable_product_price( $product, $price_html );
		}

		if ( $product->is_type( 'grouped' ) ) {
			return $this->format_grouped_product_price( $product, $price_html );
		}

		return $this->format_standard_product_price( $product, $price_html );
	}

	/**
	 * Format price HTML for standard products (Simple, External, Variation, etc.).
	 *
	 * @param \WC_Product $product    Product object.
	 * @param string      $price_html Original price HTML.
	 * @return string
	 */
	public function format_standard_product_price( $product, $price_html ) {
		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();

		if ( '' === $regular_price && '' !== $product->get_price() ) {
			$regular_price = $product->get_price();
		}

		if ( '' === $regular_price || null === $regular_price || ! is_numeric( $regular_price ) ) {
			return $price_html;
		}

		$regular_display = wc_get_price_to_display( $product, array( 'price' => $regular_price ) );
		$market_price    = wc_price( $regular_display );

		$has_reseller_price = ( '' !== (string) $sale_price && null !== $sale_price && is_numeric( $sale_price ) && (float) $sale_price < (float) $regular_price );

		$market_label   = apply_filters( 'wru_market_price_label', WRU_Settings::get_market_label() );
		$reseller_label = apply_filters( 'wru_reseller_price_label', WRU_Settings::get_reseller_label() );

		if ( $has_reseller_price ) {
			$sale_display   = wc_get_price_to_display( $product, array( 'price' => $sale_price ) );
			$reseller_price = wc_price( $sale_display );

			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div><div class="rpd-reseller-price"><span class="rpd-price-label">%3$s</span> <span class="rpd-price-value">%4$s</span></div></div>',
				esc_html( $market_label ),
				$market_price,
				esc_html( $reseller_label ),
				$reseller_price
			);
		} else {
			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div></div>',
				esc_html( $market_label ),
				$market_price
			);
		}

		return apply_filters( 'wru_formatted_price_html', $output, $product, $regular_price, $sale_price );
	}

	/**
	 * Format price HTML for Variable Products.
	 *
	 * @param \WC_Product_Variable $product    Variable Product object.
	 * @param string               $price_html Original price HTML.
	 * @return string
	 */
	private function format_variable_product_price( $product, $price_html ) {
		$prices = $product->get_variation_prices( true );

		if ( empty( $prices['price'] ) ) {
			return $price_html;
		}

		$min_price = current( $prices['price'] );
		$max_price = end( $prices['price'] );

		if ( empty( $prices['regular_price'] ) ) {
			$min_regular_price = $min_price;
			$max_regular_price = $max_price;
		} else {
			$min_regular_price = current( $prices['regular_price'] );
			$max_regular_price = end( $prices['regular_price'] );
		}

		$min_reg_display = wc_get_price_to_display( $product, array( 'price' => $min_regular_price ) );
		$max_reg_display = wc_get_price_to_display( $product, array( 'price' => $max_regular_price ) );

		if ( (float) $min_reg_display !== (float) $max_reg_display ) {
			$market_price_html = wc_format_price_range( $min_reg_display, $max_reg_display );
		} else {
			$market_price_html = wc_price( $min_reg_display );
		}

		$is_on_sale     = $product->is_on_sale() && ( (float) $min_price < (float) $min_regular_price || (float) $max_price < (float) $max_regular_price );
		$market_label   = apply_filters( 'wru_market_price_label', WRU_Settings::get_market_label() );
		$reseller_label = apply_filters( 'wru_reseller_price_label', WRU_Settings::get_reseller_label() );

		if ( $is_on_sale ) {
			$min_price_display = wc_get_price_to_display( $product, array( 'price' => $min_price ) );
			$max_price_display = wc_get_price_to_display( $product, array( 'price' => $max_price ) );

			if ( (float) $min_price_display !== (float) $max_price_display ) {
				$reseller_price_html = wc_format_price_range( $min_price_display, $max_price_display );
			} else {
				$reseller_price_html = wc_price( $min_price_display );
			}

			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div><div class="rpd-reseller-price"><span class="rpd-price-label">%3$s</span> <span class="rpd-price-value">%4$s</span></div></div>',
				esc_html( $market_label ),
				$market_price_html,
				esc_html( $reseller_label ),
				$reseller_price_html
			);
		} else {
			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div></div>',
				esc_html( $market_label ),
				$market_price_html
			);
		}

		return apply_filters( 'wru_formatted_variable_price_html', $output, $product, $prices );
	}

	/**
	 * Format price HTML for Grouped Products.
	 *
	 * @param \WC_Product_Grouped $product    Grouped Product object.
	 * @param string              $price_html Original price HTML.
	 * @return string
	 */
	private function format_grouped_product_price( $product, $price_html ) {
		$child_ids = $product->get_children();

		if ( empty( $child_ids ) ) {
			return $price_html;
		}

		$regular_prices = array();
		$active_prices  = array();

		foreach ( $child_ids as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! is_a( $child, 'WC_Product' ) || ! $child->is_visible() ) {
				continue;
			}

			$child_reg_price = $child->get_regular_price();
			$child_act_price = $child->get_price();

			if ( '' !== $child_reg_price && is_numeric( $child_reg_price ) ) {
				$regular_prices[] = wc_get_price_to_display( $child, array( 'price' => $child_reg_price ) );
			}
			if ( '' !== $child_act_price && is_numeric( $child_act_price ) ) {
				$active_prices[] = wc_get_price_to_display( $child, array( 'price' => $child_act_price ) );
			}
		}

		if ( empty( $regular_prices ) && empty( $active_prices ) ) {
			return $price_html;
		}

		$min_regular = ! empty( $regular_prices ) ? min( $regular_prices ) : min( $active_prices );
		$min_active  = ! empty( $active_prices ) ? min( $active_prices ) : $min_regular;

		$market_label   = apply_filters( 'wru_market_price_label', WRU_Settings::get_market_label() );
		$reseller_label = apply_filters( 'wru_reseller_price_label', WRU_Settings::get_reseller_label() );

		if ( (float) $min_active < (float) $min_regular ) {
			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div><div class="rpd-reseller-price"><span class="rpd-price-label">%3$s</span> <span class="rpd-price-value">%4$s</span></div></div>',
				esc_html( $market_label ),
				wc_price( $min_regular ),
				esc_html( $reseller_label ),
				wc_price( $min_active )
			);
		} else {
			$output = sprintf(
				'<div class="rpd-product-prices"><div class="rpd-market-price"><span class="rpd-price-label">%1$s</span> <span class="rpd-price-value">%2$s</span></div></div>',
				esc_html( $market_label ),
				wc_price( $min_regular )
			);
		}

		return apply_filters( 'wru_formatted_grouped_price_html', $output, $product, $child_ids );
	}

	/**
	 * Filter available variations for variable product selection.
	 *
	 * @param array                 $data      Variation data.
	 * @param \WC_Product_Variable  $product   Parent product.
	 * @param \WC_Product_Variation $variation Variation product.
	 * @return array
	 */
	public function filter_available_variation( $data, $product, $variation ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $data;
		}

		if ( is_a( $variation, 'WC_Product_Variation' ) ) {
			$variation_price_html = $this->format_standard_product_price( $variation, '' );
			if ( ! empty( $variation_price_html ) ) {
				$data['price_html'] = '<span class="price">' . $variation_price_html . '</span>';
			}
			$data['wru_wholesale_price'] = (float) $variation->get_price();
		}

		return $data;
	}
}
