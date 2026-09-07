<?php
/**
 * Plugin Name:       WooCommerce Resell Utility
 * Plugin URI:        https://github.com/cristoparker/woocommerce-resell-utility
 * Description:       Complete Dropshipping & Reseller Utility for WooCommerce. Custom reseller selling price input, live profit calculator, packaging fee deduction, Buy Now buttons, courier collection data in orders, and upgraded Reseller Dashboard.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            cristoparker
 * Author URI:        https://github.com/cristoparker
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-resell-utility
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to:   9.5
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'WRU_VERSION', '2.0.0' );
define( 'WRU_PLUGIN_FILE', __FILE__ );
define( 'WRU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WRU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce HPOS (Custom Order Tables) & Cart/Checkout Blocks.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

/**
 * Main Plugin Bootstrap Class.
 */
final class WooCommerce_Resell_Utility {

	/**
	 * Singleton instance.
	 *
	 * @var WooCommerce_Resell_Utility|null
	 */
	private static $instance = null;

	/**
	 * Main instance getter.
	 *
	 * @return WooCommerce_Resell_Utility
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Include required modules.
		$this->includes();

		// Hook initialization.
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Activation hook for rewrite endpoints.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Include plugin dependencies.
	 */
	private function includes() {
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-settings.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-product-fields.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-price-display.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-reseller-fields.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-buy-now.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-order-manager.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-reseller-dashboard.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-admin-settings.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-invoice-email.php';
		require_once WRU_PLUGIN_DIR . 'includes/class-wru-reseller-manager.php';
	}

	/**
	 * Initialize plugin modules.
	 */
	public function init() {
		// Verify WooCommerce is loaded.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Load plugin textdomain.
		load_plugin_textdomain(
			'woocommerce-resell-utility',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		// Initialize all sub-modules.
		WRU_Product_Fields::init();
		WRU_Price_Display::init();
		WRU_Reseller_Fields::init();
		WRU_Buy_Now::init();
		WRU_Order_Manager::init();
		WRU_Reseller_Dashboard::init();
		WRU_Admin_Settings::init();
		WRU_Invoice_Email::init();
		WRU_Reseller_Manager::init();

		// Register frontend scripts and styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice if WooCommerce is inactive.
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error is-dismissible">
			<p><strong><?php esc_html_e( 'WooCommerce Resell Utility:', 'woocommerce-resell-utility' ); ?></strong> <?php esc_html_e( 'এই প্লাগইনটি কাজ করার জন্য WooCommerce একটিভ থাকা আবশ্যক।', 'woocommerce-resell-utility' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue frontend CSS and JavaScript.
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'wru-frontend-styles',
			plugins_url( 'assets/css/frontend.css', __FILE__ ),
			array(),
			WRU_VERSION
		);

		wp_enqueue_script(
			'wru-frontend-scripts',
			plugins_url( 'assets/js/frontend.js', __FILE__ ),
			array( 'jquery' ),
			WRU_VERSION,
			true
		);

		wp_localize_script( 'wru-frontend-scripts', 'wru_vars', array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'checkout_url'    => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'nonce'           => wp_create_nonce( 'wru_frontend_nonce' ),
			'currency_symbol' => get_woocommerce_currency_symbol(),
			'packaging_fee'   => WRU_Settings::get_packaging_fee(),
			'packaging_type'  => WRU_Settings::get_packaging_type(),
			'is_min_enforced' => WRU_Settings::is_min_price_enforced() ? 1 : 0,
			'i18n'            => array(
				'copied'           => __( 'ডেসক্রিপশন কপি হয়েছে!', 'woocommerce-resell-utility' ),
				'profit_prefix'    => __( 'আপনার আনুমানিক লাভ: ', 'woocommerce-resell-utility' ),
				'loss_warning'     => __( 'বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।', 'woocommerce-resell-utility' ),
				'processing_order' => __( 'অর্ডার প্রসেস হচ্ছে...', 'woocommerce-resell-utility' ),
			),
		) );
	}

	/**
	 * Activation callback: flush rewrite rules for custom My Account endpoint.
	 */
	public function activate() {
		WRU_Reseller_Dashboard::init();
		if ( ! get_role( 'wru_reseller' ) ) {
			add_role(
				'wru_reseller',
				__( 'রিসেলার', 'woocommerce-resell-utility' ),
				array(
					'read'         => true,
					'edit_posts'   => false,
					'delete_posts' => false,
				)
			);
		}
		add_rewrite_endpoint( WRU_Reseller_Dashboard::ENDPOINT, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( WRU_Reseller_Dashboard::ENDPOINT_PAYOUTS, EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}
}

// Bootstrap the plugin.
WooCommerce_Resell_Utility::get_instance();
