<?php
/**
 * Dedicated Reseller Registration page.
 *
 * Normal customers register through the stock WooCommerce My Account form. Resellers apply here
 * ([wru_reseller_registration] shortcode, auto-created page) with their business details and NID.
 * Logged-in customers can also use this page to apply with their existing account.
 *
 * @package WooCommerce_Resell_Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRU_Reseller_Registration {

	const SHORTCODE    = 'wru_reseller_registration';
	const NONCE_ACTION = 'wru_reseller_register';
	const MAX_SIZE     = 5242880; // 5MB.

	/**
	 * Validation errors of the current request.
	 *
	 * @var WP_Error|null
	 */
	private $errors = null;

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
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_submission' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_page' ) );

		// Entry point for resellers on the normal My Account login/register screen.
		add_action( 'woocommerce_after_customer_login_form', array( $this, 'render_login_page_link' ) );
	}

	/**
	 * Create the registration page once (also repairs it if it was deleted).
	 */
	public static function maybe_create_page() {
		$page_id = (int) get_option( WRU_Settings::OPTION_REG_PAGE_ID, 0 );
		if ( $page_id && get_post( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		// Reuse an existing page that already carries the shortcode.
		$existing = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			's'              => '[' . self::SHORTCODE . ']',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );
		if ( ! empty( $existing ) ) {
			update_option( WRU_Settings::OPTION_REG_PAGE_ID, (int) $existing[0] );
			return;
		}

		$new_id = wp_insert_post( array(
			'post_title'   => __( 'Reseller Registration', 'woocommerce-resell-utility' ),
			'post_name'    => 'reseller-registration',
			'post_content' => '[' . self::SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( $new_id && ! is_wp_error( $new_id ) ) {
			update_option( WRU_Settings::OPTION_REG_PAGE_ID, (int) $new_id );
		}
	}

	/**
	 * "Become a reseller" link under the My Account login / register forms.
	 */
	public function render_login_page_link() {
		$url = WRU_Settings::get_reseller_registration_url();
		if ( ! $url ) {
			return;
		}
		printf(
			'<p class="wru-reseller-signup-link">%s <a href="%s">%s</a></p>',
			esc_html__( 'রিসেলার হিসেবে কাজ করতে চান?', 'woocommerce-resell-utility' ),
			esc_url( $url ),
			esc_html__( 'রিসেলার রেজিস্ট্রেশন করুন', 'woocommerce-resell-utility' )
		);
	}

	/**
	 * Read a posted text value back for re-populating the form after an error.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function old( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Validate the submitted application.
	 *
	 * @param bool $needs_account Whether an account is created by this submission.
	 * @return WP_Error
	 */
	private function validate( $needs_account ) {
		$errors = new WP_Error();

		if ( $needs_account ) {
			$name     = $this->old( 'wru_reg_name' );
			$email    = isset( $_POST['wru_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['wru_reg_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$password = isset( $_POST['wru_reg_password'] ) ? (string) wp_unslash( $_POST['wru_reg_password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

			if ( '' === $name ) {
				$errors->add( 'wru_reg_name', __( 'দয়া করে আপনার নাম লিখুন।', 'woocommerce-resell-utility' ) );
			}
			if ( ! is_email( $email ) ) {
				$errors->add( 'wru_reg_email', __( 'দয়া করে একটি সঠিক ইমেইল ঠিকানা দিন।', 'woocommerce-resell-utility' ) );
			} elseif ( email_exists( $email ) ) {
				$errors->add( 'wru_reg_email_exists', sprintf(
					/* translators: %s: login URL */
					__( 'এই ইমেইল দিয়ে ইতিমধ্যে একাউন্ট আছে। অনুগ্রহ করে <a href="%s">লগইন</a> করে এই পেজে ফিরে এসে আবেদন করুন।', 'woocommerce-resell-utility' ),
					esc_url( wc_get_page_permalink( 'myaccount' ) )
				) );
			}
			if ( strlen( $password ) < 8 ) {
				$errors->add( 'wru_reg_password', __( 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।', 'woocommerce-resell-utility' ) );
			}
		}

		if ( '' === $this->old( 'wru_reg_phone' ) ) {
			$errors->add( 'wru_reg_phone', __( 'দয়া করে আপনার মোবাইল নাম্বার প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}
		if ( '' === $this->old( 'wru_reg_store_url' ) ) {
			$errors->add( 'wru_reg_store_url', __( 'দয়া করে আপনার ফেসবুক পেজ বা ওয়েবসাইট লিংক প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}
		if ( '' === $this->old( 'wru_reg_whatsapp' ) ) {
			$errors->add( 'wru_reg_whatsapp', __( 'দয়া করে আপনার WhatsApp নাম্বার বা চ্যাট লিংক প্রদান করুন।', 'woocommerce-resell-utility' ) );
		}

		// Anti-spam math captcha (token is bound to the answer and expires after one hour).
		$answer = $this->old( 'wru_reg_captcha' );
		$token  = $this->old( 'wru_reg_captcha_token' );
		$time   = isset( $_POST['wru_reg_captcha_time'] ) ? absint( $_POST['wru_reg_captcha_time'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $answer || '' === $token ) {
			$errors->add( 'wru_reg_captcha', __( 'অনুগ্রহ করে স্প্যাম নিরাপত্তা গণিতের উত্তর প্রদান করুন।', 'woocommerce-resell-utility' ) );
		} elseif ( ! hash_equals( wp_hash( $answer . '|' . $time . '|wru_reg_math_salt' ), $token ) || $time < time() - HOUR_IN_SECONDS ) {
			$errors->add( 'wru_reg_captcha', __( 'স্প্যাম নিরাপত্তা গণিতের উত্তর ভুল হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ) );
		}

		$allowed = array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
		foreach ( array(
			'front' => __( 'সামনের', 'woocommerce-resell-utility' ),
			'back'  => __( 'পেছনের', 'woocommerce-resell-utility' ),
		) as $side => $label ) {
			$field = 'wru_reg_nid_' . $side;
			if ( empty( $_FILES[ $field ]['name'] ) || ! empty( $_FILES[ $field ]['error'] ) ) {
				/* translators: %s: front/back */
				$errors->add( $field, sprintf( __( 'দয়া করে আপনার NID কার্ডের %s অংশের ছবি আপলোড করুন।', 'woocommerce-resell-utility' ), $label ) );
				continue;
			}
			$ext = strtolower( pathinfo( sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed, true ) ) {
				/* translators: %s: front/back */
				$errors->add( $field . '_type', sprintf( __( 'NID %s ছবি অবশ্যই JPG, PNG, WEBP অথবা PDF ফরম্যাটে হতে হবে।', 'woocommerce-resell-utility' ), $label ) );
			}
			if ( ! empty( $_FILES[ $field ]['size'] ) && $_FILES[ $field ]['size'] > self::MAX_SIZE ) {
				/* translators: %s: front/back */
				$errors->add( $field . '_size', sprintf( __( 'NID %s ছবির সাইজ সর্বোচ্চ ৫ মেগাবাইট (5MB) হতে পারবে।', 'woocommerce-resell-utility' ), $label ) );
			}
		}

		return $errors;
	}

	/**
	 * Process the form: create the account when needed, store NID files, mark the application pending.
	 */
	public function maybe_handle_submission() {
		if ( empty( $_POST['wru_reseller_register'] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$this->errors = new WP_Error();

		$nonce = isset( $_POST['wru_reg_nonce'] ) ? sanitize_key( wp_unslash( $_POST['wru_reg_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->errors->add( 'wru_nonce', __( 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। পেজটি রিফ্রেশ করে আবার চেষ্টা করুন।', 'woocommerce-resell-utility' ) );
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id && ( WRU_Reseller_Manager::is_reseller( $user_id ) || get_user_meta( $user_id, '_wru_reseller_status', true ) ) ) {
			// Already a reseller or an applicant: never re-submit over an existing application.
			return;
		}

		$needs_account = ! $user_id;
		$errors        = $this->validate( $needs_account );
		if ( $errors->has_errors() ) {
			$this->errors = $errors;
			return;
		}

		if ( $needs_account ) {
			$email    = sanitize_email( wp_unslash( $_POST['wru_reg_email'] ) );
			$password = (string) wp_unslash( $_POST['wru_reg_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$name     = $this->old( 'wru_reg_name' );

			$user_id = wc_create_new_customer( $email, wc_create_new_customer_username( $email ), $password );
			if ( is_wp_error( $user_id ) ) {
				$this->errors = $user_id;
				return;
			}
			wp_update_user( array(
				'ID'           => $user_id,
				'display_name' => $name,
				'first_name'   => $name,
			) );
		}

		// Store the NID documents privately.
		foreach ( array( 'front', 'back' ) as $side ) {
			$result = WRU_Reseller_Manager::store_nid_upload( $user_id, $side, $_FILES[ 'wru_reg_nid_' . $side ] );
			if ( is_wp_error( $result ) ) {
				$this->errors = $result;
				$this->rollback( $user_id, $needs_account );
				return;
			}
		}

		$this->save_application( $user_id );

		if ( $needs_account ) {
			wc_set_customer_auth_cookie( $user_id );
		}

		wp_safe_redirect( add_query_arg( 'wru_applied', '1', WRU_Settings::get_reseller_registration_url() ?: wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * Undo a half-finished submission (upload failure).
	 *
	 * @param int  $user_id       User ID.
	 * @param bool $created_here  Whether this request created the account.
	 */
	private function rollback( $user_id, $created_here ) {
		if ( $created_here ) {
			// Removes the account together with any NID file already stored.
			WRU_Reseller_Manager::clean_reseller_files_and_records( $user_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			return;
		}
		foreach ( array( 'front', 'back' ) as $side ) {
			$file = get_user_meta( $user_id, '_wru_nid_' . $side . '_file', true );
			if ( $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			delete_user_meta( $user_id, '_wru_nid_' . $side . '_file' );
			delete_user_meta( $user_id, '_wru_nid_' . $side . '_url' );
		}
	}

	/**
	 * Save application details, mark pending and notify the store admin.
	 *
	 * @param int $user_id User ID.
	 */
	private function save_application( $user_id ) {
		$phone   = $this->old( 'wru_reg_phone' );
		$wa      = $this->old( 'wru_reg_whatsapp' );
		$company = $this->old( 'wru_reg_company_name' );
		$store   = isset( $_POST['wru_reg_store_url'] ) ? esc_url_raw( wp_unslash( $_POST['wru_reg_store_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		update_user_meta( $user_id, '_wru_reseller_phone', $phone );
		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, '_wru_payout_number', $phone );
		update_user_meta( $user_id, '_wru_reseller_whatsapp', $wa );
		if ( $store ) {
			update_user_meta( $user_id, '_wru_reseller_store_url', $store );
		}
		if ( $company ) {
			update_user_meta( $user_id, '_wru_reseller_company_name', $company );
			update_user_meta( $user_id, 'billing_company', $company );
		}

		// The user keeps the customer role until the admin approves the application.
		update_user_meta( $user_id, '_wru_reseller_status', 'pending' );
		update_user_meta( $user_id, '_wru_reseller_applied_at', current_time( 'mysql' ) );

		$admin_email = get_option( 'admin_email' );
		$user        = get_userdata( $user_id );
		if ( $admin_email && $user ) {
			$subject = sprintf( __( '[নতুন রিসেলার আবেদন] %1$s (%2$s)', 'woocommerce-resell-utility' ), $user->display_name, $phone );
			$body    = sprintf(
				__( "নতুন রিসেলার পার্টনার রেজিস্ট্রেশন আবেদন জমা পড়েছে:\n\nনাম: %1\$s\nইমেইল: %2\$s\nমোবাইল: %3\$s\nWhatsApp: %4\$s\nপেজ / ওয়েবসাইট: %5\$s\n\nএডমিন প্যানেলে আবেদন ও NID কার্ড যাচাই করে অনুমোদন দিন:\n%6\$s", 'woocommerce-resell-utility' ),
				$user->display_name,
				$user->user_email,
				$phone,
				$wa,
				$store,
				admin_url( 'admin.php?page=wru-resellers&tab=pending_resellers' )
			);
			wp_mail( $admin_email, $subject, $body );
		}
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		ob_start();
		echo '<div class="wru-reg-wrap">';

		$user_id = get_current_user_id();
		$status  = $user_id ? get_user_meta( $user_id, '_wru_reseller_status', true ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( $user_id && ( ! empty( $_GET['wru_applied'] ) || 'pending' === $status ) ) {
			$this->render_notice(
				__( 'আপনার আবেদন জমা হয়েছে', 'woocommerce-resell-utility' ),
				__( 'এডমিন আপনার তথ্য ও NID যাচাই করে একাউন্ট অনুমোদন করবেন। অনুমোদনের পর আপনি রিসেলার মূল্য দেখতে, নিজের বিক্রয়মূল্য দিয়ে অর্ডার করতে এবং রিসেলার ড্যাশবোর্ড ব্যবহার করতে পারবেন।', 'woocommerce-resell-utility' ),
				'success'
			);
		} elseif ( $user_id && WRU_Reseller_Manager::is_reseller( $user_id ) ) {
			$this->render_notice(
				__( 'আপনি ইতিমধ্যে একজন রিসেলার', 'woocommerce-resell-utility' ),
				__( 'আপনার রিসেলার ড্যাশবোর্ডে গিয়ে অর্ডার ও লাভ-ক্ষতি দেখুন।', 'woocommerce-resell-utility' ),
				'info',
				wc_get_account_endpoint_url( WRU_Reseller_Dashboard::ENDPOINT ),
				__( 'রিসেলার ড্যাশবোর্ড', 'woocommerce-resell-utility' )
			);
		} elseif ( $user_id && 'rejected' === $status ) {
			$this->render_notice(
				__( 'আবেদন অনুমোদিত হয়নি', 'woocommerce-resell-utility' ),
				__( 'আপনার রিসেলার আবেদনটি বাতিল করা হয়েছে। বিস্তারিত জানতে আমাদের সাথে যোগাযোগ করুন।', 'woocommerce-resell-utility' ),
				'error'
			);
		} else {
			$this->render_form( ! $user_id );
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Status panel.
	 *
	 * @param string $title Title.
	 * @param string $text  Message.
	 * @param string $type  success|info|error.
	 * @param string $url   Optional button URL.
	 * @param string $label Optional button label.
	 */
	private function render_notice( $title, $text, $type, $url = '', $label = '' ) {
		?>
		<div class="wru-reg-notice wru-reg-notice-<?php echo esc_attr( $type ); ?>">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( $url ) : ?>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Registration / application form.
	 *
	 * @param bool $needs_account Show the account fields (guest visitors).
	 */
	private function render_form( $needs_account ) {
		$n1    = wp_rand( 2, 9 );
		$n2    = wp_rand( 1, 9 );
		$time  = time();
		$token = wp_hash( ( $n1 + $n2 ) . '|' . $time . '|wru_reg_math_salt' );
		?>
		<div class="wru-reg-intro">
			<h3><?php esc_html_e( 'রিসেলার পার্টনার রেজিস্ট্রেশন', 'woocommerce-resell-utility' ); ?></h3>
			<p><?php esc_html_e( 'ড্রপশিপিং রিসেলার হিসেবে কাজ করতে নিচের তথ্য ও NID কার্ডের উভয় পাশের ছবি দিন। এডমিন যাচাই করে অনুমোদন দিলে আপনি রিসেলার মূল্য দেখতে ও নিজের বিক্রয়মূল্য দিয়ে অর্ডার করতে পারবেন।', 'woocommerce-resell-utility' ); ?></p>
			<?php if ( ! $needs_account ) : ?>
				<p><strong><?php esc_html_e( 'আপনি লগইন অবস্থায় আছেন — আপনার বর্তমান একাউন্টেই রিসেলার আবেদন জমা হবে।', 'woocommerce-resell-utility' ); ?></strong></p>
			<?php else : ?>
				<p><?php
					printf(
						/* translators: %s: login URL */
						wp_kses( __( 'ইতিমধ্যে একাউন্ট আছে? <a href="%s">লগইন করুন</a> এবং এখান থেকে আবেদন করুন।', 'woocommerce-resell-utility' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( wc_get_page_permalink( 'myaccount' ) )
					);
				?></p>
			<?php endif; ?>
		</div>

		<?php if ( $this->errors && $this->errors->has_errors() ) : ?>
			<ul class="woocommerce-error" role="alert">
				<?php foreach ( $this->errors->get_error_messages() as $message ) : ?>
					<li><?php echo wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" class="wru-reg-form woocommerce-form">
			<?php wp_nonce_field( self::NONCE_ACTION, 'wru_reg_nonce' ); ?>

			<?php if ( $needs_account ) : ?>
				<p class="form-row form-row-wide">
					<label for="wru_reg_name"><?php esc_html_e( 'আপনার নাম', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
					<input type="text" class="input-text" name="wru_reg_name" id="wru_reg_name" value="<?php echo esc_attr( $this->old( 'wru_reg_name' ) ); ?>" required />
				</p>
				<p class="form-row form-row-wide">
					<label for="wru_reg_email"><?php esc_html_e( 'ইমেইল ঠিকানা', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
					<input type="email" class="input-text" name="wru_reg_email" id="wru_reg_email" value="<?php echo esc_attr( $this->old( 'wru_reg_email' ) ); ?>" autocomplete="email" required />
				</p>
				<p class="form-row form-row-wide">
					<label for="wru_reg_password"><?php esc_html_e( 'পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
					<input type="password" class="input-text" name="wru_reg_password" id="wru_reg_password" autocomplete="new-password" minlength="8" required />
				</p>
			<?php endif; ?>

			<p class="form-row form-row-wide">
				<label for="wru_reg_company_name"><?php esc_html_e( 'আপনার শপ / ফেসবুক পেজ / ব্র্যান্ডের নাম (ঐচ্ছিক)', 'woocommerce-resell-utility' ); ?></label>
				<input type="text" class="input-text" name="wru_reg_company_name" id="wru_reg_company_name" value="<?php echo esc_attr( $this->old( 'wru_reg_company_name' ) ); ?>" />
			</p>
			<p class="form-row form-row-wide">
				<label for="wru_reg_phone"><?php esc_html_e( 'মোবাইল নাম্বার', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="tel" class="input-text" name="wru_reg_phone" id="wru_reg_phone" value="<?php echo esc_attr( $this->old( 'wru_reg_phone' ) ); ?>" placeholder="017XXXXXXXX" required />
			</p>
			<p class="form-row form-row-wide">
				<label for="wru_reg_store_url"><?php esc_html_e( 'ফেসবুক পেজ বা ওয়েবসাইট লিংক (যেখানে পণ্য বিক্রয় করেন)', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="url" class="input-text" name="wru_reg_store_url" id="wru_reg_store_url" value="<?php echo esc_attr( $this->old( 'wru_reg_store_url' ) ); ?>" placeholder="https://facebook.com/yourpage" required />
			</p>
			<p class="form-row form-row-wide">
				<label for="wru_reg_whatsapp"><?php esc_html_e( 'WhatsApp নাম্বার বা চ্যাট লিংক', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
				<input type="text" class="input-text" name="wru_reg_whatsapp" id="wru_reg_whatsapp" value="<?php echo esc_attr( $this->old( 'wru_reg_whatsapp' ) ); ?>" required />
			</p>

			<div class="wru-reg-nid-grid">
				<p class="form-row">
					<label for="wru_reg_nid_front"><?php esc_html_e( 'NID কার্ডের সামনের ছবি', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
					<input type="file" name="wru_reg_nid_front" id="wru_reg_nid_front" accept="image/*,application/pdf" required />
					<small><?php esc_html_e( 'পরিষ্কার ও পাঠযোগ্য ছবি (JPG/PNG/PDF, সর্বোচ্চ 5MB)', 'woocommerce-resell-utility' ); ?></small>
				</p>
				<p class="form-row">
					<label for="wru_reg_nid_back"><?php esc_html_e( 'NID কার্ডের পেছনের ছবি', 'woocommerce-resell-utility' ); ?> <span class="required">*</span></label>
					<input type="file" name="wru_reg_nid_back" id="wru_reg_nid_back" accept="image/*,application/pdf" required />
					<small><?php esc_html_e( 'পরিষ্কার ও পাঠযোগ্য ছবি (JPG/PNG/PDF, সর্বোচ্চ 5MB)', 'woocommerce-resell-utility' ); ?></small>
				</p>
			</div>

			<div class="wru-reg-captcha">
				<label for="wru_reg_captcha">
					<?php /* translators: 1, 2: numbers to add */ printf( esc_html__( 'স্প্যাম নিরাপত্তা পরীক্ষা: %1$d + %2$d = কত?', 'woocommerce-resell-utility' ), (int) $n1, (int) $n2 ); ?>
					<span class="required">*</span>
				</label>
				<input type="hidden" name="wru_reg_captcha_token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="wru_reg_captcha_time" value="<?php echo esc_attr( $time ); ?>" />
				<input type="number" class="input-text" name="wru_reg_captcha" id="wru_reg_captcha" required />
			</div>

			<p class="form-row">
				<button type="submit" class="button alt" name="wru_reseller_register" value="1"><?php esc_html_e( 'রিসেলার হিসেবে আবেদন করুন', 'woocommerce-resell-utility' ); ?></button>
			</p>
		</form>
		<?php
	}
}
