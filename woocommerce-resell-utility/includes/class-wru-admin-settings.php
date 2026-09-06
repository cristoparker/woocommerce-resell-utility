<?php
/**
 * Admin Settings Page under WooCommerce -> Resell Utility.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Admin_Settings {

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
		add_action( 'admin_menu', array( $this, 'register_menu_page' ), 60 );
		add_action( 'admin_init', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register sub-menu under WooCommerce.
	 */
	public function register_menu_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Resell Utility সেটিংস', 'woocommerce-resell-utility' ),
			__( 'Resell Utility', 'woocommerce-resell-utility' ),
			'manage_woocommerce',
			'wru-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin stylesheet and scripts.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		wp_enqueue_style(
			'wru-admin-styles',
			plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ),
			array(),
			WRU_VERSION
		);

		wp_enqueue_script(
			'wru-admin-scripts',
			plugins_url( 'assets/js/admin.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			WRU_VERSION,
			true
		);

		wp_localize_script( 'wru-admin-scripts', 'wru_admin', array(
			'copied_text' => __( 'কুরিয়ার নোট কপি হয়েছে!', 'woocommerce-resell-utility' ),
		) );
	}

	/**
	 * Save admin settings.
	 */
	public function save_settings() {
		if ( ! isset( $_POST['wru_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'wru_save_settings_nonce', 'wru_nonce' );

		$fee         = isset( $_POST[ WRU_Settings::OPTION_PACKAGING_FEE ] ) ? (float) sanitize_text_field( wp_unslash( $_POST[ WRU_Settings::OPTION_PACKAGING_FEE ] ) ) : 20.0;
		$fee_type    = isset( $_POST[ WRU_Settings::OPTION_PACKAGING_TYPE ] ) && 'item' === $_POST[ WRU_Settings::OPTION_PACKAGING_TYPE ] ? 'item' : 'order';
		$enforce_min = isset( $_POST[ WRU_Settings::OPTION_ENFORCE_MIN_PRICE ] ) ? 'yes' : 'no';
		$buy_now     = isset( $_POST[ WRU_Settings::OPTION_ENABLE_BUY_NOW ] ) ? 'yes' : 'no';
		$shop_buy    = isset( $_POST[ WRU_Settings::OPTION_ENABLE_SHOP_BUY_NOW ] ) ? 'yes' : 'no';
		$tools       = isset( $_POST[ WRU_Settings::OPTION_ENABLE_TOOLS ] ) ? 'yes' : 'no';
		$dashboard   = isset( $_POST[ WRU_Settings::OPTION_ENABLE_DASHBOARD ] ) ? 'yes' : 'no';
		$market_lbl  = isset( $_POST[ WRU_Settings::OPTION_MARKET_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ WRU_Settings::OPTION_MARKET_LABEL ] ) ) : '';
		$resell_lbl  = isset( $_POST[ WRU_Settings::OPTION_RESELLER_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ WRU_Settings::OPTION_RESELLER_LABEL ] ) ) : '';

		update_option( WRU_Settings::OPTION_PACKAGING_FEE, max( 0, $fee ) );
		update_option( WRU_Settings::OPTION_PACKAGING_TYPE, $fee_type );
		update_option( WRU_Settings::OPTION_ENFORCE_MIN_PRICE, $enforce_min );
		update_option( WRU_Settings::OPTION_ENABLE_BUY_NOW, $buy_now );
		update_option( WRU_Settings::OPTION_ENABLE_SHOP_BUY_NOW, $shop_buy );
		update_option( WRU_Settings::OPTION_ENABLE_TOOLS, $tools );
		update_option( WRU_Settings::OPTION_ENABLE_DASHBOARD, $dashboard );

		if ( ! empty( $market_lbl ) ) {
			update_option( WRU_Settings::OPTION_MARKET_LABEL, $market_lbl );
		}
		if ( ! empty( $resell_lbl ) ) {
			update_option( WRU_Settings::OPTION_RESELLER_LABEL, $resell_lbl );
		}

		add_settings_error( 'wru_settings', 'wru_updated', __( 'সেটিংস সফলভাবে সংরক্ষিত হয়েছে।', 'woocommerce-resell-utility' ), 'updated' );
	}

	/**
	 * Render the Settings Page HTML.
	 */
	public function render_settings_page() {
		$fee         = WRU_Settings::get_packaging_fee();
		$fee_type    = WRU_Settings::get_packaging_type();
		$enforce_min = WRU_Settings::is_min_price_enforced();
		$buy_now     = WRU_Settings::is_buy_now_enabled();
		$shop_buy    = WRU_Settings::is_shop_buy_now_enabled();
		$tools       = WRU_Settings::are_product_tools_enabled();
		$dashboard   = WRU_Settings::is_dashboard_enabled();
		$market_lbl  = WRU_Settings::get_market_label();
		$resell_lbl  = WRU_Settings::get_reseller_label();
		$currency    = get_woocommerce_currency_symbol();
		?>
		<div class="wrap wru-settings-wrap">
			<div class="wru-settings-header">
				<h1>🚀 <?php esc_html_e( 'WooCommerce Resell Utility সেটিংস', 'woocommerce-resell-utility' ); ?></h1>
				<p class="wru-settings-subtitle">
					<?php esc_html_e( 'ড্রপশিপিং রিসেলার প্ল্যাটফর্ম কনফিগারেশন: প্যাকেজিং খরচ, বিক্রয়মূল্য ইনপুট ও বাটন কন্ট্রোল।', 'woocommerce-resell-utility' ); ?>
				</p>
			</div>

			<?php settings_errors( 'wru_settings' ); ?>

			<form method="post" action="" class="wru-settings-form">
				<?php wp_nonce_field( 'wru_save_settings_nonce', 'wru_nonce' ); ?>

				<!-- Packaging & Profit Section -->
				<div class="wru-settings-card">
					<h2>📦 <?php esc_html_e( 'প্যাকেজিং খরচ ও প্রফিট হিসাব', 'woocommerce-resell-utility' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'রিসেলারের লেখা বিক্রয়মূল্য থেকে পণ্যের পাইকারি দাম এবং এই প্যাকেজিং খরচ বাদ দিয়ে নিট প্রফিট হিসাব করা হবে।', 'woocommerce-resell-utility' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wru_packaging_fee"><?php esc_html_e( 'ডিফল্ট প্যাকেজিং খরচ', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<div class="wru-currency-input-inline">
									<span><?php echo esc_html( $currency ); ?></span>
									<input type="number" step="any" min="0" name="<?php echo esc_attr( WRU_Settings::OPTION_PACKAGING_FEE ); ?>" id="wru_packaging_fee" value="<?php echo esc_attr( $fee ); ?>" class="regular-text" />
								</div>
								<p class="description"><?php esc_html_e( 'প্রতি অর্ডার বা পণ্যে প্যাকেজিং ও বাবল র‍্যাপের খরচ (যেমন: ২০ ৳)।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'প্যাকেজিং ফি প্রয়োগের নিয়ম', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="radio" name="<?php echo esc_attr( WRU_Settings::OPTION_PACKAGING_TYPE ); ?>" value="order" <?php checked( $fee_type, 'order' ); ?> />
									<strong><?php esc_html_e( 'অর্ডার প্রতি একবার (Per Order)', 'woocommerce-resell-utility' ); ?></strong>
									<span class="description">- <?php esc_html_e( 'একটি অর্ডারে কয়টি আইটেম আছে নির্বিশেষে পুরো অর্ডারে একবার প্যাকেজিং খরচ কাটা হবে। (সুপারিশকৃত)', 'woocommerce-resell-utility' ); ?></span>
								</label><br><br>
								<label>
									<input type="radio" name="<?php echo esc_attr( WRU_Settings::OPTION_PACKAGING_TYPE ); ?>" value="item" <?php checked( $fee_type, 'item' ); ?> />
									<strong><?php esc_html_e( 'প্রতি পণ্যে আলাদা (Per Item / Quantity)', 'woocommerce-resell-utility' ); ?></strong>
									<span class="description">- <?php esc_html_e( 'প্রতিটি পণ্যের পরিমাণের সাথে প্যাকেজিং ফি গুণ হবে।', 'woocommerce-resell-utility' ); ?></span>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wru_enforce_min_price"><?php esc_html_e( 'সর্বনিম্ন মূল্য যাচাই', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( WRU_Settings::OPTION_ENFORCE_MIN_PRICE ); ?>" id="wru_enforce_min_price" value="yes" <?php checked( $enforce_min, true ); ?> />
									<strong><?php esc_html_e( 'পাইকারি দামের নিচে বিক্রি বন্ধ রাখুন', 'woocommerce-resell-utility' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'রিসেলার আমাদের পাইকারি মূল্যের চেয়ে কম বিক্রয়মূল্য লিখতে পারবে না।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Buy Now & Frontend Buttons -->
				<div class="wru-settings-card">
					<h2>⚡ <?php esc_html_e( 'Buy Now ও ফ্রন্টএন্ড বাটনসমূহ', 'woocommerce-resell-utility' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wru_enable_buy_now"><?php esc_html_e( 'সিঙ্গেল প্রোডাক্টে Buy Now', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( WRU_Settings::OPTION_ENABLE_BUY_NOW ); ?>" id="wru_enable_buy_now" value="yes" <?php checked( $buy_now, true ); ?> />
									<strong><?php esc_html_e( 'Add to Cart এর পাশে "এখনই অর্ডার করুন (Buy Now)" বাটন দেখান', 'woocommerce-resell-utility' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'ক্লিক করলে বিক্রয়মূল্যসহ সরাসরি চেকআউট পেজে নিয়ে যাবে।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wru_enable_shop_buy_now"><?php esc_html_e( 'শপ পেজে Buy Now', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( WRU_Settings::OPTION_ENABLE_SHOP_BUY_NOW ); ?>" id="wru_enable_shop_buy_now" value="yes" <?php checked( $shop_buy, true ); ?> />
									<strong><?php esc_html_e( 'শপ ও ক্যাটাগরি গ্রিডে Add to Cart এর পাশে Buy Now বাটন দেখান', 'woocommerce-resell-utility' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'ক্লিক করলে দ্রুত কুইক পপআপে বিক্রয়মূল্য বসিয়ে অর্ডার করা যাবে।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wru_enable_product_tools"><?php esc_html_e( 'রিসেলার কুইক টুলস', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( WRU_Settings::OPTION_ENABLE_TOOLS ); ?>" id="wru_enable_product_tools" value="yes" <?php checked( $tools, true ); ?> />
									<strong><?php esc_html_e( '"ছবি ডাউনলোড" ও "ডেসক্রিপশন কপি" বাটন প্রদর্শন করুন', 'woocommerce-resell-utility' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'রিসেলাররা ফেসবুকে পোস্ট করার জন্য এক ক্লিকে ছবি ও পণ্যের বিবরণ কপি করতে পারবে।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wru_enable_reseller_dashboard"><?php esc_html_e( 'রিসেলার ড্যাশবোর্ড', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( WRU_Settings::OPTION_ENABLE_DASHBOARD ); ?>" id="wru_enable_reseller_dashboard" value="yes" <?php checked( $dashboard, true ); ?> />
									<strong><?php esc_html_e( 'My Account এ আপগ্রেডেড রিসেলার হাব চালু রাখুন', 'woocommerce-resell-utility' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'রিসেলার তাদের মোট প্রফিট, পেন্ডিং টাকা, অর্ডার হিসাব এবং বিকাশ/নগদ পেআউট নম্বর সেভ করতে পারবে।', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Custom Labels -->
				<div class="wru-settings-card">
					<h2>🏷️ <?php esc_html_e( 'মূল্য প্রদর্শন লেবেল (Price Labels)', 'woocommerce-resell-utility' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wru_market_price_label"><?php esc_html_e( 'মার্কেট প্রাইস লেবেল', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<input type="text" name="<?php echo esc_attr( WRU_Settings::OPTION_MARKET_LABEL ); ?>" id="wru_market_price_label" value="<?php echo esc_attr( $market_lbl ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'রেগুলার প্রাইসের আগে কী লেখা থাকবে (যেমন: Market Price: বা বাজারমূল্য:)', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wru_reseller_price_label"><?php esc_html_e( 'রিসেলার প্রাইস লেবেল', 'woocommerce-resell-utility' ); ?></label>
							</th>
							<td>
								<input type="text" name="<?php echo esc_attr( WRU_Settings::OPTION_RESELLER_LABEL ); ?>" id="wru_reseller_price_label" value="<?php echo esc_attr( $resell_lbl ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'সেল প্রাইসের আগে কী লেখা থাকবে (যেমন: Reseller Price: বা রিসেলার মূল্য:)', 'woocommerce-resell-utility' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<p class="submit">
					<button type="submit" name="wru_save_settings" value="1" class="button button-primary button-hero">
						💾 <?php esc_html_e( 'পরিবর্তনসমূহ সংরক্ষণ করুন', 'woocommerce-resell-utility' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
