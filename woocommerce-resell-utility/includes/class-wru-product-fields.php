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
	 * Meta key for product-specific per-location delivery fees: array of array( 'location' => 'BD:BD-13', 'fee' => 60 ).
	 */
	const META_SHIPPING_RULES = '_wru_product_shipping_rules';

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
				'label'       => __( 'কাস্টম ডেলিভারি চার্জ (৳)', 'woocommerce-resell-utility' ),
				'placeholder' => __( 'খালি = ডিফল্ট চার্জ', 'woocommerce-resell-utility' ),
				'description' => __( 'সব লোকেশনের জন্য এই প্রোডাক্টের ডেলিভারি চার্জ (ঐচ্ছিক)। খালি রাখলে ডিফল্ট ডেলিভারি চার্জ প্রযোজ্য হবে। নিচের লোকেশন ভিত্তিক চার্জ থাকলে সেটি অগ্রাধিকার পাবে।', 'woocommerce-resell-utility' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);

		$this->render_shipping_rules_editor();

		echo '</div>';
	}

	/**
	 * Location options for the rule selector: every country the store ships to, plus its states.
	 *
	 * @return array Map of "CC" / "CC:STATE" => label.
	 */
	private function get_location_options() {
		$options   = array();
		$countries = WC()->countries->get_shipping_countries();
		foreach ( $countries as $cc => $country_name ) {
			$options[ $cc ] = sprintf( /* translators: %s: country */ __( '%s (পুরো দেশ)', 'woocommerce-resell-utility' ), $country_name );
			$states         = WC()->countries->get_states( $cc );
			if ( is_array( $states ) ) {
				foreach ( $states as $state_code => $state_name ) {
					$options[ $cc . ':' . $state_code ] = $country_name . ' — ' . $state_name;
				}
			}
		}
		return $options;
	}

	/**
	 * Repeater for per-location delivery fees on the product edit screen.
	 */
	private function render_shipping_rules_editor() {
		global $post;

		$options = $this->get_location_options();
		$rules   = $post ? self::get_shipping_rules( $post->ID, false ) : array();
		if ( empty( $rules ) ) {
			$rules = array();
		}

		$row = function ( $location, $fee ) use ( $options ) {
			ob_start();
			?>
			<tr class="wru-ship-rule-row">
				<td>
					<select name="wru_ship_loc[]" style="max-width:100%;">
						<?php foreach ( $options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $location, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input type="number" name="wru_ship_fee[]" value="<?php echo esc_attr( $fee ); ?>" min="0" step="any" placeholder="0" style="width:90px;" /></td>
				<td><a href="#" class="wru-ship-rule-remove" aria-label="<?php esc_attr_e( 'মুছুন', 'woocommerce-resell-utility' ); ?>">&times;</a></td>
			</tr>
			<?php
			return ob_get_clean();
		};
		?>
		<div class="wru-ship-rules" style="margin: 0 12px 10px;">
			<p style="margin: 8px 0 4px; font-weight: 600;"><?php esc_html_e( 'লোকেশন ভিত্তিক ডেলিভারি চার্জ (ঐচ্ছিক)', 'woocommerce-resell-utility' ); ?></p>
			<p class="description" style="margin: 0 0 6px;"><?php esc_html_e( 'কাস্টমারের ডেলিভারি ঠিকানার জেলা/স্টেট মিললে সেই চার্জ, না মিললে দেশ অনুযায়ী চার্জ, তাও না থাকলে উপরের কাস্টম চার্জ বা ডিফল্ট চার্জ প্রযোজ্য হবে।', 'woocommerce-resell-utility' ); ?></p>
			<table class="widefat" style="max-width:520px;">
				<thead><tr><th><?php esc_html_e( 'লোকেশন', 'woocommerce-resell-utility' ); ?></th><th><?php esc_html_e( 'চার্জ (৳)', 'woocommerce-resell-utility' ); ?></th><th></th></tr></thead>
				<tbody class="wru-ship-rules-body">
					<?php foreach ( $rules as $rule ) { echo $row( $rule['location'], $rule['fee'] ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</tbody>
			</table>
			<p><button type="button" class="button wru-ship-rule-add"><?php esc_html_e( '+ লোকেশন চার্জ যোগ করুন', 'woocommerce-resell-utility' ); ?></button></p>
			<script type="text/template" class="wru-ship-rule-template"><?php echo $row( '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
		</div>
		<script>
		jQuery(function ($) {
			var $box = $('.wru-ship-rules');
			$box.on('click', '.wru-ship-rule-add', function () {
				$box.find('.wru-ship-rules-body').append($box.find('.wru-ship-rule-template').html());
			}).on('click', '.wru-ship-rule-remove', function (e) {
				e.preventDefault();
				$(this).closest('tr').remove();
			});
		});
		</script>
		<?php
	}

	/**
	 * Get a product's per-location delivery rules.
	 *
	 * @param int  $product_id    Product or variation ID.
	 * @param bool $parent_fallback Fall back to the parent product's rules (variations).
	 * @return array List of array( 'location' => string, 'fee' => float ).
	 */
	public static function get_shipping_rules( $product_id, $parent_fallback = true ) {
		$product_id = (int) $product_id;
		$rules      = get_post_meta( $product_id, self::META_SHIPPING_RULES, true );
		if ( empty( $rules ) && $parent_fallback ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$rules = get_post_meta( $parent_id, self::META_SHIPPING_RULES, true );
			}
		}
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Delivery charge a product defines for a destination, or null to use the default delivery charge.
	 * Most specific match wins: state, then country, then the product's all-locations charge.
	 *
	 * @param int|WC_Product $product Product or ID.
	 * @param string         $country Destination country code.
	 * @param string         $state   Destination state code.
	 * @return float|null
	 */
	public static function get_shipping_charge_for_destination( $product, $country, $state = '' ) {
		$product_id = is_numeric( $product ) ? (int) $product : ( $product instanceof WC_Product ? $product->get_id() : 0 );
		if ( ! $product_id ) {
			return null;
		}

		$by_state   = null;
		$by_country = null;
		foreach ( self::get_shipping_rules( $product_id ) as $rule ) {
			if ( ! isset( $rule['location'], $rule['fee'] ) ) {
				continue;
			}
			if ( $state && $rule['location'] === $country . ':' . $state ) {
				$by_state = (float) $rule['fee'];
			} elseif ( $rule['location'] === $country ) {
				$by_country = (float) $rule['fee'];
			}
		}

		if ( null !== $by_state ) {
			return $by_state;
		}
		if ( null !== $by_country ) {
			return $by_country;
		}
		return self::get_shipping_charge( $product_id );
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

		// Per-location delivery fees (the last row wins for a repeated location).
		if ( isset( $_POST['wru_ship_loc'], $_POST['wru_ship_fee'] ) && is_array( $_POST['wru_ship_loc'] ) && is_array( $_POST['wru_ship_fee'] ) ) {
			$locations = array_map( 'sanitize_text_field', wp_unslash( $_POST['wru_ship_loc'] ) );
			$fees      = array_map( 'sanitize_text_field', wp_unslash( $_POST['wru_ship_fee'] ) );
			$allowed   = WC()->countries ? $this->get_location_options() : array();
			$rules     = array();
			foreach ( $locations as $i => $location ) {
				if ( ! isset( $allowed[ $location ] ) || ! isset( $fees[ $i ] ) || '' === $fees[ $i ] || ! is_numeric( $fees[ $i ] ) ) {
					continue; // Skip blank rows, unknown locations and non-numeric fees.
				}
				$rules[ $location ] = array( 'location' => $location, 'fee' => max( 0, (float) $fees[ $i ] ) );
			}
			if ( $rules ) {
				update_post_meta( $post_id, self::META_SHIPPING_RULES, array_values( $rules ) );
			} else {
				delete_post_meta( $post_id, self::META_SHIPPING_RULES );
			}
		} elseif ( isset( $_POST['woocommerce_meta_nonce'] ) ) {
			// Form submitted with every row removed.
			delete_post_meta( $post_id, self::META_SHIPPING_RULES );
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
