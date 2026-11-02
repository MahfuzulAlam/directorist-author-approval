<?php

defined( 'ABSPATH' ) || exit;

final class DAA_Plugin {
	const STATUS_META    = '_daa_author_status';
	const REQUESTED_META = '_daa_author_requested_at';
	const DECIDED_META   = '_daa_author_decided_at';
	const DECIDER_META   = '_daa_author_decided_by';
	const OPTION         = 'daa_author_approval_settings';

	private static $request_created_this_request = false;

	public static function boot() {
		DAA_Admin::boot();

		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! class_exists( 'Directorist_Base' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_directorist_notice' ) );
			return;
		}
		self::migrate_legacy_settings();
		DAA_Settings::boot();

		add_action( 'atbdp_before_user_registration_submit', array( __CLASS__, 'registration_option' ) );
		add_action( 'atbdp_before_processing_submitted_user_registration', array( __CLASS__, 'capture_registration' ) );
		add_action( 'atbdp_user_registration_completed', array( __CLASS__, 'registration_completed' ), 5 );
		add_action( 'user_register', array( __CLASS__, 'mark_regular_registration' ), 20 );
		add_filter( 'atbdp_reg_redirection_page_url', array( __CLASS__, 'registration_redirect' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'registration_confirmation' ) );

		add_filter( 'update_user_metadata', array( __CLASS__, 'protect_author_meta' ), 5, 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'restrict_listing_caps' ), 20, 4 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'prevent_listing_write' ), 20, 2 );
		add_action( 'atbdp_before_processing_submitted_listing_frontend', array( __CLASS__, 'prevent_frontend_submission' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'prevent_rest_submission' ), 10, 3 );

		add_action( 'wp_ajax_atbdp_become_author', array( __CLASS__, 'native_author_request' ), 0 );
		add_action( 'wp_ajax_atbdp_user_type_approved', array( __CLASS__, 'native_approval' ), 0 );
		add_action( 'wp_ajax_atbdp_user_type_deny', array( __CLASS__, 'native_rejection' ), 0 );
		add_action( 'directorist_before_load_dashboard', array( __CLASS__, 'prepare_dashboard' ), 10, 2 );
		add_action( 'directorist_after_dashboard_contents', array( __CLASS__, 'dashboard_notice' ) );
	}

	public static function defaults() {
		return array(
			'enabled'             => 1,
			'admin_notifications' => 1,
			'user_notifications'  => 1,
			'allow_reapply'       => 0,
			'pending_subject'     => 'Your Author request is awaiting approval',
			'pending_message'     => "Hello {username},\n\nYour Author account request on {site_name} is awaiting administrator approval.\n\n{dashboard_url}",
			'approved_subject'    => 'Your Author request has been approved',
			'approved_message'    => "Hello {username},\n\nYour Author account has been approved. You can now submit listings on {site_name}.\n\n{dashboard_url}",
			'rejected_subject'    => 'Your Author request was not approved',
			'rejected_message'    => "Hello {username},\n\nYour Author account request on {site_name} was not approved. Please contact the website administrator for further assistance.",
			'admin_subject'       => '[{site_name}] New Author request',
			'admin_message'       => "A new Author request is awaiting review.\n\nUsername: {username}\nEmail: {email}\nRegistered: {registered}\n\nReview: {review_url}",
			'confirmation_message' => 'Your Author request is awaiting administrator approval.',
			'verification_message' => 'Please verify your email to complete your Author request. An administrator will review it afterward.',
			'dashboard_pending'   => 'Your Author account request is awaiting administrator approval.',
			'dashboard_approved'  => 'Your Author account has been approved. You can now submit listings.',
			'dashboard_rejected'  => 'Your Author account request was not approved. Please contact the website administrator for further assistance.',
			'reapply_label'       => 'Request Author status again',
			'registration_label'  => 'Request Author status to submit listings',
			'registration_help'   => 'An administrator reviews Author requests. Regular User accounts do not require approval.',
		);
	}

	public static function options() {
		$saved    = (array) get_option( 'atbdp_option', array() );
		$options  = self::defaults();
		foreach ( $options as $key => $default ) {
			if ( array_key_exists( 'daa_' . $key, $saved ) ) {
				$options[ $key ] = $saved[ 'daa_' . $key ];
			}
		}
		return $options;
	}

	private static function migrate_legacy_settings() {
		$legacy = get_option( self::OPTION, false );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$saved   = (array) get_option( 'atbdp_option', array() );
		$changed = false;
		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( array_key_exists( $key, $legacy ) && ! array_key_exists( 'daa_' . $key, $saved ) ) {
				$saved[ 'daa_' . $key ] = $legacy[ $key ];
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'atbdp_option', $saved );
		}
		delete_option( self::OPTION );
	}

	public static function enabled() {
		$options = self::options();
		return ! empty( $options['enabled'] ) && defined( 'ATBDP_POST_TYPE' );
	}

	public static function status( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::STATUS_META, true );
	}

	public static function is_restricted( $user_id ) {
		return self::enabled() && in_array( self::status( $user_id ), array( 'regular', 'pending', 'rejected' ), true );
	}

	public static function missing_directorist_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Directorist Author Approval requires the Directorist plugin to be active.', 'directorist-author-approval' ) . '</p></div>';
		}
	}

	private static function registration_key( $email ) {
		return 'daa_reg_' . hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
	}

	private static function registration_requested() {
		$checkbox = isset( $_POST['daa_request_author'] ) ? sanitize_text_field( wp_unslash( $_POST['daa_request_author'] ) ) : '';
		$type     = isset( $_POST['user_type'] ) ? sanitize_key( wp_unslash( $_POST['user_type'] ) ) : '';
		return '1' === $checkbox || 'author' === $type;
	}

	public static function registration_option() {
		if ( ! self::enabled() ) {
			return;
		}

		static $number = 0;
		++$number;
		$id = 'daa-request-author-' . $number;
		?>
		<div class="directorist-form-group directorist-mb-20 daa-author-option">
			<label for="<?php echo esc_attr( $id ); ?>" class="directorist-checkbox__label">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="daa_request_author" value="1" <?php checked( ! empty( $_POST['daa_request_author'] ) ); ?> />
				<?php $options = self::options(); echo esc_html( $options['registration_label'] ); ?>
			</label>
			<p class="description"><?php echo esc_html( $options['registration_help'] ); ?></p>
		</div>
		<?php
	}

	public static function capture_registration() {
		if ( ! self::enabled() ) {
			return;
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			return;
		}

		$key = self::registration_key( $email );
		if ( self::registration_requested() ) {
			set_transient( $key, 1, 2 * DAY_IN_SECONDS );
		} else {
			delete_transient( $key );
		}
	}

	public static function mark_regular_registration( $user_id ) {
		$user = get_userdata( $user_id );
		if ( self::enabled() && $user && in_array( 'subscriber', $user->roles, true ) ) {
			add_user_meta( $user_id, self::STATUS_META, 'regular', true );
			update_user_meta( $user_id, '_user_type', 'general' );
		}
	}

	public static function registration_completed( $user_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$key       = self::registration_key( $user->user_email );
		$requested = (bool) get_transient( $key );
		delete_transient( $key );

		if ( $requested || self::registration_requested() ) {
			self::request_author( $user_id );
		} else {
			add_user_meta( $user_id, self::STATUS_META, 'regular', true );
			update_user_meta( $user_id, '_user_type', 'general' );
		}
	}

	public static function registration_redirect( $url, $page_id ) {
		if ( self::$request_created_this_request ) {
			return add_query_arg( 'daa_author_request', 'pending', $url );
		}
		return $url;
	}

	public static function enqueue_assets() {
		if ( ! self::enabled() ) {
			return;
		}
		$base = plugin_dir_url( DAA_PLUGIN_FILE );
		wp_enqueue_style( 'daa-author-approval', $base . 'assets/author-approval.css', array(), DAA_PLUGIN_VERSION );
		wp_enqueue_script( 'daa-author-approval', $base . 'assets/author-approval.js', array(), DAA_PLUGIN_VERSION, true );
		$options = self::options();
		wp_localize_script( 'daa-author-approval', 'daaAuthorApproval', array(
			'pendingMessage' => $options['confirmation_message'],
			'verifyMessage'  => $options['verification_message'],
		) );
	}

	public static function registration_confirmation() {
		if ( ! self::enabled() || ! isset( $_GET['daa_author_request'] ) || 'pending' !== sanitize_key( wp_unslash( $_GET['daa_author_request'] ) ) ) {
			return;
		}
		$options = self::options();
		echo '<div class="daa-registration-confirmation" role="status">' . esc_html( $options['confirmation_message'] ) . '</div>';
	}

	public static function protect_author_meta( $check, $user_id, $key, $value, $previous ) {
		if ( null !== $check || ! self::enabled() || '_user_type' !== $key || 'author' !== $value || current_user_can( 'manage_options' ) ) {
			return $check;
		}

		if ( 'approved' === self::status( $user_id ) || 'author' === get_user_meta( $user_id, '_user_type', true ) ) {
			return $check;
		}

		update_user_meta( $user_id, '_user_type', 'general' );
		return true;
	}

	public static function restrict_listing_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! self::is_restricted( $user->ID ) || ! empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( preg_match( '/(?:^|_)at_biz_dirs?$/', $cap ) ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	public static function prevent_listing_write( $empty, $postarr ) {
		if ( $empty || ! self::enabled() || ! get_current_user_id() || current_user_can( 'manage_options' ) ) {
			return $empty;
		}

		$type = isset( $postarr['post_type'] ) ? $postarr['post_type'] : '';
		if ( ! $type && ! empty( $postarr['ID'] ) ) {
			$type = get_post_type( (int) $postarr['ID'] );
		}

		return ATBDP_POST_TYPE === $type && self::is_restricted( get_current_user_id() ) ? true : $empty;
	}

	public static function prevent_frontend_submission() {
		if ( get_current_user_id() && self::is_restricted( get_current_user_id() ) ) {
			throw new Exception( __( 'Author approval is required before you can submit a listing.', 'directorist-author-approval' ), 403 );
		}
	}

	public static function prevent_rest_submission( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! get_current_user_id() || ! self::is_restricted( get_current_user_id() ) ) {
			return $result;
		}

		if ( preg_match( '#^/directorist/v[12]/listings(?:/|$)#', $request->get_route() ) && ! in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return new WP_Error( 'daa_author_approval_required', __( 'Author approval is required before you can submit a listing.', 'directorist-author-approval' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	public static function request_author( $user_id ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'daa_disabled', __( 'Author approvals are disabled.', 'directorist-author-approval' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'daa_invalid_user', __( 'The user was not found.', 'directorist-author-approval' ) );
		}

		$status = self::status( $user_id );
		if ( 'pending' === $status || 'approved' === $status || ( 'rejected' === $status && empty( self::options()['allow_reapply'] ) ) ) {
			return new WP_Error( 'daa_request_unavailable', __( 'An Author request is already on record for this account.', 'directorist-author-approval' ) );
		}
		if ( '' === $status && 'author' === get_user_meta( $user_id, '_user_type', true ) ) {
			return new WP_Error( 'daa_already_author', __( 'This account is already an Author.', 'directorist-author-approval' ) );
		}

		update_user_meta( $user_id, self::STATUS_META, 'pending' );
		update_user_meta( $user_id, self::REQUESTED_META, current_time( 'mysql', true ) );
		delete_user_meta( $user_id, self::DECIDED_META );
		delete_user_meta( $user_id, self::DECIDER_META );
		update_user_meta( $user_id, '_user_type', 'general' );
		self::$request_created_this_request = true;
		self::send_request_notifications( $user );
		return true;
	}

	public static function decide( $user_id, $decision ) {
		if ( ! current_user_can( 'manage_options' ) || (int) $user_id === get_current_user_id() || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'daa_forbidden', __( 'You cannot review this request.', 'directorist-author-approval' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || 'pending' !== self::status( $user_id ) ) {
			return new WP_Error( 'daa_not_pending', __( 'This request is no longer pending.', 'directorist-author-approval' ) );
		}

		update_user_meta( $user_id, self::STATUS_META, $decision );
		update_user_meta( $user_id, self::DECIDED_META, current_time( 'mysql', true ) );
		update_user_meta( $user_id, self::DECIDER_META, get_current_user_id() );
		update_user_meta( $user_id, '_user_type', 'approved' === $decision ? 'author' : 'general' );
		self::send_user_notification( $user, $decision );
		return true;
	}

	public static function native_author_request() {
		if ( ! self::enabled() || ! is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'atbdp_become_author' ) ) {
			return;
		}
		$result = self::request_author( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		$options = self::options();
		wp_send_json( $options['dashboard_pending'] );
	}

	public static function native_approval() {
		self::native_decision( 'approved', 'atbdp_user_type_approve' );
	}

	public static function native_rejection() {
		self::native_decision( 'rejected', 'atbdp_user_type_deny' );
	}

	private static function native_decision( $decision, $nonce_action ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$user_id = isset( $_POST['userId'] ) ? absint( $_POST['userId'] ) : 0;
		$nonce   = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! $user_id || ! wp_verify_nonce( $nonce, $nonce_action ) || 'pending' !== self::status( $user_id ) ) {
			return;
		}
		$result = self::decide( $user_id, $decision );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		wp_send_json( array( 'user_type' => 'approved' === $decision ? __( 'Author', 'directorist' ) : __( 'User', 'directorist' ) ) );
	}

	public static function prepare_dashboard( $attributes, $dashboard ) {
		if ( ! self::enabled() || ! is_object( $dashboard ) ) {
			return;
		}
		$status = self::status( get_current_user_id() );
		if ( in_array( $status, array( 'pending', 'rejected' ), true ) ) {
			$dashboard->become_author_button = 0;
		}
	}

	public static function dashboard_notice() {
		if ( ! self::enabled() || ! is_user_logged_in() ) {
			return;
		}
		$status = self::status( get_current_user_id() );
		$options = self::options();
		$messages = array(
			'pending'  => $options['dashboard_pending'],
			'approved' => $options['dashboard_approved'],
			'rejected' => $options['dashboard_rejected'],
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		echo '<div class="daa-dashboard-notice daa-status-' . esc_attr( $status ) . '" role="status">' . esc_html( $messages[ $status ] );
		if ( 'rejected' === $status && ! empty( $options['allow_reapply'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="daa_reapply" />';
			wp_nonce_field( 'daa_reapply' );
			echo '<button type="submit" class="directorist-btn directorist-btn-secondary">' . esc_html( $options['reapply_label'] ) . '</button></form>';
		}
		echo '</div>';
	}

	private static function mail_tokens( $user ) {
		$dashboard = class_exists( 'ATBDP_Permalink' ) ? ATBDP_Permalink::get_dashboard_page_link() : home_url( '/' );
		return array(
			'{username}'      => $user->user_login,
			'{email}'         => $user->user_email,
			'{registered}'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $user->user_registered . ' UTC' ) ),
			'{site_name}'     => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			'{dashboard_url}' => $dashboard,
			'{review_url}'    => DAA_Admin::review_url(),
		);
	}

	private static function send_request_notifications( $user ) {
		$options = self::options();
		$tokens  = self::mail_tokens( $user );
		if ( ! empty( $options['admin_notifications'] ) ) {
			$subject = strtr( $options['admin_subject'], $tokens );
			$body    = strtr( $options['admin_message'], $tokens );
			self::mail( get_option( 'admin_email' ), $subject, $body );
		}
		self::send_user_notification( $user, 'pending' );
	}

	private static function send_user_notification( $user, $status ) {
		$options = self::options();
		if ( empty( $options['user_notifications'] ) ) {
			return;
		}
		$tokens  = self::mail_tokens( $user );
		$subject = strtr( $options[ $status . '_subject' ], $tokens );
		$body    = strtr( $options[ $status . '_message' ], $tokens );
		self::mail( $user->user_email, $subject, $body );
	}

	private static function mail( $to, $subject, $body ) {
		wp_mail( $to, sanitize_text_field( $subject ), $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
}
