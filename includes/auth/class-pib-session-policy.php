<?php
/**
 * Managed session policy and logout handling.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces identity-bound session lifetime/revalidation for bridge-authenticated users.
 */
final class PIB_Session_Policy {
	/**
	 * Browser cookie marker used to prevent repeated auto-retry loops.
	 */
	const COOKIE_CLIENT_MISMATCH_RETRY = 'pib_client_mismatch_retry';

	/**
	 * Meta key: managed session marker.
	 */
	const META_MANAGED = 'pib_managed_session';

	/**
	 * Meta key: session start timestamp.
	 */
	const META_STARTED_AT = 'pib_session_started_at';

	/**
	 * Meta key: last revalidation timestamp.
	 */
	const META_LAST_REVALIDATED_AT = 'pib_last_revalidated_at';

	/**
	 * Meta key: bound Shopify customer ID for managed session.
	 */
	const META_BOUND_CUSTOMER_ID = 'pib_session_customer_id';

	/**
	 * Meta key: bound company/account ID for managed session.
	 */
	const META_BOUND_COMPANY_ID = 'pib_session_company_id';

	/**
	 * Meta key: bound mapping ID snapshot for managed session.
	 */
	const META_BOUND_MAPPING_ID = 'pib_session_mapping_id';

	/**
	 * Meta key: user-agent hash bound to managed session.
	 */
	const META_BOUND_UA_HASH = 'pib_session_ua_hash';

	/**
	 * Meta key: IP-prefix fingerprint bound to managed session.
	 */
	const META_BOUND_IP_FINGERPRINT = 'pib_session_ip_fingerprint';

	/**
	 * Meta key: WP session token hash bound to managed session.
	 */
	const META_BOUND_WP_TOKEN_HASH = 'pib_session_wp_token_hash';

	/**
	 * Singleton.
	 *
	 * @var PIB_Session_Policy|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Session_Policy|null
	 */
	public static function instance( $plugin = null ) {
		if ( null === self::$instance ) {
			if ( ! $plugin instanceof PIB_Plugin ) {
				return null;
			}
			self::$instance = new self( $plugin );
		}

		return self::$instance;
	}

	/**
	 * Build object and register hooks.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'pib_auth_intake_success', array( $this, 'mark_managed_session' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'clear_managed_session_for_login' ), 20, 2 );
		add_action( 'admin_init', array( $this, 'restrict_wp_admin_for_portal_customers' ), 1 );
		add_filter( 'show_admin_bar', array( $this, 'filter_show_admin_bar_for_portal_customers' ), 20 );
		add_action( 'init', array( $this, 'enforce_policy' ), 2 );
		add_action( 'admin_post_pib_logout', array( $this, 'handle_logout' ) );
		add_action( 'admin_post_nopriv_pib_logout', array( $this, 'handle_logout' ) );
		add_shortcode( 'portal_logout_link', array( $this, 'render_logout_shortcode' ) );
	}

	/**
	 * Mark current user session as plugin-managed and bind identity snapshot.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 * @param int                  $user_id User ID.
	 *
	 * @return void
	 */
	public function mark_managed_session( $claims, $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$claims      = is_array( $claims ) ? $claims : array();
		$customer_id = PIB_Customer_Mapping::customer_id_from_claims( $claims );
		$company_id  = PIB_Customer_Mapping::company_id_from_claims( $claims );
		$mapping_id  = 0;
		$identity    = PIB_Authorization::current_identity();
		if ( is_array( $identity ) && ! empty( $identity['mapping_id'] ) ) {
			$mapping_id = absint( $identity['mapping_id'] );
		}

		$now = time();
		update_user_meta( $user_id, self::META_MANAGED, 1 );
		update_user_meta( $user_id, self::META_STARTED_AT, $now );
		update_user_meta( $user_id, self::META_LAST_REVALIDATED_AT, $now );
		update_user_meta( $user_id, self::META_BOUND_CUSTOMER_ID, $customer_id );
		update_user_meta( $user_id, self::META_BOUND_COMPANY_ID, $company_id );
		update_user_meta( $user_id, self::META_BOUND_MAPPING_ID, $mapping_id );

		$settings       = $this->plugin->get_settings();
		$ua_hash        = $this->current_user_agent_hash();
		$ip_fingerprint = $this->current_ip_fingerprint( $settings );
		$wp_token_hash  = $this->current_wp_session_token_hash();

		if ( '' !== $ua_hash ) {
			update_user_meta( $user_id, self::META_BOUND_UA_HASH, $ua_hash );
		} else {
			delete_user_meta( $user_id, self::META_BOUND_UA_HASH );
		}

		if ( '' !== $ip_fingerprint ) {
			update_user_meta( $user_id, self::META_BOUND_IP_FINGERPRINT, $ip_fingerprint );
		} else {
			delete_user_meta( $user_id, self::META_BOUND_IP_FINGERPRINT );
		}

		if ( '' !== $wp_token_hash ) {
			update_user_meta( $user_id, self::META_BOUND_WP_TOKEN_HASH, $wp_token_hash );
		} else {
			delete_user_meta( $user_id, self::META_BOUND_WP_TOKEN_HASH );
		}
	}

	/**
	 * Clear managed markers for regular non-bridge logins.
	 *
	 * Bridge flow triggers wp_login first, then pib_auth_intake_success
	 * re-establishes managed markers in the same request.
	 *
	 * @param string  $user_login User login.
	 * @param WP_User $user       User object.
	 *
	 * @return void
	 */
	public function clear_managed_session_for_login( $user_login, $user ) {
		unset( $user_login );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->clear_managed_session_meta( $user->ID );
	}

	/**
	 * Determine whether current logged-in user has managed session markers.
	 *
	 * @return bool
	 */
	public static function is_current_session_managed() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		return absint( get_user_meta( $user_id, self::META_MANAGED, true ) ) === 1;
	}

	/**
	 * Enforce managed session TTL and periodic revalidation.
	 *
	 * @return void
	 */
	public function enforce_policy() {
		if ( ! is_user_logged_in() || $this->skip_for_request_context() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$is_managed = self::is_current_session_managed();
		if ( ! $is_managed ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		$ttl      = max( 1, min( 720, absint( $settings['managed_session_ttl_hours'] ) ) ) * HOUR_IN_SECONDS;
		$started  = absint( get_user_meta( $user_id, self::META_STARTED_AT, true ) );
		$now      = time();

		if ( $started <= 0 ) {
			update_user_meta( $user_id, self::META_STARTED_AT, $now );
			$started = $now;
		}

		if ( ( $now - $started ) > $ttl ) {
			$this->expire_session( 'pib_session_ttl_expired' );
		}

		if ( ! $this->session_identity_matches_binding( $user_id, $settings ) ) {
			$this->expire_session(
				'pib_session_identity_mismatch',
				array(
					'user_id' => $user_id,
				)
			);
		}

		$client_binding = $this->session_client_binding_matches( $user_id, $settings );
		if ( is_wp_error( $client_binding ) ) {
			$this->expire_session(
				(string) $client_binding->get_error_code(),
				array(
					'user_id' => $user_id,
				)
			);
		}

		if ( empty( $settings['periodic_revalidation_enabled'] ) ) {
			return;
		}

		$interval      = max( 1, min( 1440, absint( $settings['revalidate_interval_minutes'] ) ) ) * MINUTE_IN_SECONDS;
		$last_reval_at = absint( get_user_meta( $user_id, self::META_LAST_REVALIDATED_AT, true ) );
		if ( $last_reval_at <= 0 ) {
			$last_reval_at = $now;
			update_user_meta( $user_id, self::META_LAST_REVALIDATED_AT, $last_reval_at );
		}

		if ( ( $now - $last_reval_at ) < $interval ) {
			return;
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			$this->expire_session(
				'pib_session_revalidation_failed',
				array(
					'user_id' => $user_id,
				)
			);
		}

		$revalidation = apply_filters(
			'pib_validate_managed_session_identity',
			true,
			$identity,
			$user_id,
			$settings
		);

		if ( is_wp_error( $revalidation ) ) {
			$this->expire_session(
				(string) $revalidation->get_error_code(),
				array(
					'user_id' => $user_id,
				)
			);
		}
		if ( false === $revalidation ) {
			$this->expire_session(
				'pib_session_revalidation_failed',
				array(
					'user_id' => $user_id,
				)
			);
		}

		update_user_meta( $user_id, self::META_LAST_REVALIDATED_AT, $now );
	}

	/**
	 * Render local/coordinated logout link shortcode.
	 *
	 * Usage:
	 * [portal_logout_link]
	 * [portal_logout_link label="Sign out"]
	 *
	 * @param array<string, string> $atts Shortcode attrs.
	 *
	 * @return string
	 */
	public function render_logout_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'label' => 'Sign out',
			),
			$atts,
			'portal_logout_link'
		);

		$url = $this->logout_url();
		return sprintf(
			'<a class="pib-logout-link" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( (string) $atts['label'] )
		);
	}

	/**
	 * Handle explicit logout action.
	 *
	 * @return void
	 */
	public function handle_logout() {
		if ( ! isset( $_REQUEST['pib_logout_nonce'] ) || ! is_scalar( $_REQUEST['pib_logout_nonce'] ) ) {
			wp_die( esc_html__( 'Invalid logout request.', 'portal-identity-bridge' ) );
		}

		$nonce = sanitize_text_field( (string) wp_unslash( $_REQUEST['pib_logout_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'pib_logout' ) ) {
			wp_die( esc_html__( 'Invalid logout request.', 'portal-identity-bridge' ) );
		}

		$settings = $this->plugin->get_settings();
		$user_id = get_current_user_id();
		$identity = PIB_Authorization::current_identity();
		$context = array(
			'user_id'             => absint( $user_id ),
			'shopify_customer_id' => is_array( $identity ) && isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
			'company_id'          => is_array( $identity ) && isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
		);

		do_action( 'pib_logout_requested', absint( $user_id ), $context );

		if ( $user_id > 0 ) {
			$this->clear_managed_session_meta( $user_id );
		}

		wp_logout();

		$local_redirect = $this->logged_out_redirect_url( $settings );
		if ( ! empty( $settings['coordinated_logout_enabled'] ) ) {
			$upstream = esc_url_raw( trim( (string) $settings['upstream_logout_url'] ) );
			if ( '' !== $upstream ) {
				$param = sanitize_key( (string) $settings['upstream_logout_return_param'] );
				if ( '' === $param ) {
					$param = 'return_to';
				}
				$upstream = add_query_arg(
					array(
						$param => $local_redirect,
					),
					$upstream
				);
				$context['redirect_url'] = $upstream;
				$context['return_to']    = $local_redirect;
				do_action( 'pib_logout_completed', absint( $user_id ), 'coordinated', $context );
				wp_safe_redirect( $upstream );
				exit;
			}
		}

		$context['redirect_url'] = $local_redirect;
		do_action( 'pib_logout_completed', absint( $user_id ), 'local', $context );
		wp_safe_redirect( $local_redirect );
		exit;
	}

	/**
	 * Prevent Shopify customer role users from opening wp-admin screens.
	 *
	 * Keeps admin-post entrypoints available for bridge intake/logout actions.
	 *
	 * @return void
	 */
	public function restrict_wp_admin_for_portal_customers() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		// Keep admin-post flows available (auth intake, logout, verification, exports, etc.).
		global $pagenow;
		if ( 'admin-post.php' === (string) $pagenow ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! in_array( 'shopify_customer', (array) $user->roles, true ) ) {
			return;
		}

		$settings    = $this->plugin->get_settings();
		$destination = PIB_Destination::sanitize_destination(
			isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard',
			'/dashboard'
		);

		wp_safe_redirect( PIB_Destination::to_absolute_url( $destination ) );
		exit;
	}

	/**
	 * Hide frontend admin bar for Shopify customer portal users.
	 *
	 * @param bool $show Current admin-bar visibility.
	 * @return bool
	 */
	public function filter_show_admin_bar_for_portal_customers( $show ) {
		if ( ! is_user_logged_in() ) {
			return (bool) $show;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return (bool) $show;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User ) {
			return (bool) $show;
		}

		if ( in_array( 'shopify_customer', (array) $user->roles, true ) ) {
			return false;
		}

		return (bool) $show;
	}

	/**
	 * Build logout action URL.
	 *
	 * @return string
	 */
	public function logout_url() {
		$url = add_query_arg(
			array(
				'action' => 'pib_logout',
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'pib_logout', 'pib_logout_nonce' );
	}

	/**
	 * Expire session and redirect through failure flow.
	 *
	 * @param string               $reason  Reason code.
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return void
	 */
	private function expire_session( $reason, $context = array() ) {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$this->clear_managed_session_meta( $user_id );
		}

		do_action( 'pib_session_expired', $user_id, $reason, is_array( $context ) ? $context : array() );

		wp_logout();

		$settings = $this->plugin->get_settings();
		if ( $this->maybe_retry_client_binding_session( $reason, $settings ) ) {
			exit;
		}

		$dest     = PIB_Destination::safe_failure_destination(
			isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '',
			'/portal-login'
		);

		$url = add_query_arg(
			array(
				'pib_auth_error' => (string) $reason,
			),
			PIB_Destination::to_absolute_url( $dest )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Attempt one automatic managed-session re-auth for client-binding mismatch.
	 *
	 * @param string               $reason   Expiration reason code.
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return bool True when the retry flow handled output/redirect.
	 */
	private function maybe_retry_client_binding_session( $reason, $settings ) {
		$reason = sanitize_key( (string) $reason );
		if ( 'pib_session_client_binding_mismatch' !== $reason ) {
			return false;
		}

		if ( $this->has_client_mismatch_retry_cookie() ) {
			return false;
		}

		$signin = PIB_Signin_Link::instance( $this->plugin );
		if ( ! $signin instanceof PIB_Signin_Link ) {
			return false;
		}

		$settings    = is_array( $settings ) ? $settings : array();
		$destination = PIB_Destination::current_request_destination();
		$destination = PIB_Destination::sanitize_destination(
			$destination,
			isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard'
		);

		$signin_url = $signin->build_signin_url( $destination );
		if ( '' === $signin_url ) {
			return false;
		}

		$this->set_client_mismatch_retry_cookie( 180 );
		$this->render_reconnect_wait_page( $signin_url );
		return true;
	}

	/**
	 * Check whether client-binding retry already ran recently for this browser.
	 *
	 * @return bool
	 */
	private function has_client_mismatch_retry_cookie() {
		if ( ! isset( $_COOKIE[ self::COOKIE_CLIENT_MISMATCH_RETRY ] ) || ! is_scalar( $_COOKIE[ self::COOKIE_CLIENT_MISMATCH_RETRY ] ) ) {
			return false;
		}

		return '1' === trim( (string) wp_unslash( $_COOKIE[ self::COOKIE_CLIENT_MISMATCH_RETRY ] ) );
	}

	/**
	 * Set a short-lived retry cookie to prevent re-auth redirect loops.
	 *
	 * @param int $ttl_seconds Cookie lifetime in seconds.
	 *
	 * @return void
	 */
	private function set_client_mismatch_retry_cookie( $ttl_seconds ) {
		if ( headers_sent() ) {
			return;
		}

		$expires   = time() + max( 60, absint( $ttl_seconds ) );
		$path      = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain    = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		$secure    = is_ssl();
		$http_only = true;

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE_CLIENT_MISMATCH_RETRY,
				'1',
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => $http_only,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE_CLIENT_MISMATCH_RETRY, '1', $expires, $path, $domain, $secure, $http_only );
		}

		$_COOKIE[ self::COOKIE_CLIENT_MISMATCH_RETRY ] = '1';
	}

	/**
	 * Render a lightweight interstitial while re-auth redirect starts.
	 *
	 * @param string $signin_url Next redirect URL.
	 *
	 * @return void
	 */
	private function render_reconnect_wait_page( $signin_url ) {
		$signin_url = esc_url_raw( (string) $signin_url );
		if ( '' === $signin_url ) {
			return;
		}

		nocache_headers();
		status_header( 200 );
		$escaped_url = esc_url( $signin_url );

		echo '<!doctype html><html><head><meta charset="utf-8" /><meta name="robots" content="noindex,nofollow" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Reconnecting Secure Session</title>';
		echo '<meta http-equiv="refresh" content="1;url=' . esc_attr( $escaped_url ) . '" />';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:24px;}main{max-width:560px;margin:8vh auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 22px;}h1{font-size:20px;line-height:1.35;margin:0 0 10px 0;}p{margin:0 0 10px 0;line-height:1.5;}a.button{display:inline-block;margin-top:8px;padding:8px 14px;border-radius:6px;background:#2271b1;color:#fff;text-decoration:none;font-weight:600;}</style></head><body>';
		echo '<main><h1>Please wait...</h1><p>We detected a session change and are reconnecting your secure portal session.</p><p>You should be redirected automatically in a moment.</p><p><a class="button" href="' . $escaped_url . '">Continue</a></p></main>';
		echo '<script>window.setTimeout(function(){window.location.assign(' . wp_json_encode( $signin_url ) . ');}, 1000);</script>';
		echo '</body></html>';
	}

	/**
	 * Avoid forced redirects for API/AJAX/admin contexts.
	 *
	 * @return bool
	 */
	private function skip_for_request_context() {
		if ( is_admin() || wp_doing_ajax() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return false;
	}

	/**
	 * Validate active identity still matches session-bound identity snapshot.
	 *
	 * @param int                  $user_id  User ID.
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return bool
	 */
	private function session_identity_matches_binding( $user_id, $settings ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			return false;
		}

		$bound_customer = trim( (string) get_user_meta( $user_id, self::META_BOUND_CUSTOMER_ID, true ) );
		$bound_company  = trim( (string) get_user_meta( $user_id, self::META_BOUND_COMPANY_ID, true ) );
		$bound_mapping  = absint( get_user_meta( $user_id, self::META_BOUND_MAPPING_ID, true ) );

		$current_customer = trim( (string) $identity['shopify_customer_id'] );
		$current_company  = isset( $identity['company_id'] ) ? trim( (string) $identity['company_id'] ) : '';
		$current_mapping  = isset( $identity['mapping_id'] ) ? absint( $identity['mapping_id'] ) : 0;

		// Backfill binding keys for older managed sessions that predate this policy.
		if ( '' === $bound_customer ) {
			update_user_meta( $user_id, self::META_BOUND_CUSTOMER_ID, $current_customer );
			$bound_customer = $current_customer;
		}
		if ( '' === $bound_company && '' !== $current_company ) {
			update_user_meta( $user_id, self::META_BOUND_COMPANY_ID, $current_company );
			$bound_company = $current_company;
		}
		if ( $bound_mapping <= 0 && $current_mapping > 0 ) {
			update_user_meta( $user_id, self::META_BOUND_MAPPING_ID, $current_mapping );
			$bound_mapping = $current_mapping;
		}

		if ( '' === $bound_customer || $bound_customer !== $current_customer ) {
			return false;
		}

		if ( ! empty( $settings['require_company_id'] ) && ( '' === $current_company || '' === $bound_company ) ) {
			return false;
		}
		if ( '' !== $bound_company && $bound_company !== $current_company ) {
			return false;
		}

		if ( $bound_mapping > 0 && $current_mapping > 0 && $bound_mapping !== $current_mapping ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate browser/session client binding for managed sessions.
	 *
	 * @param int                  $user_id  User ID.
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return true|WP_Error
	 */
	private function session_client_binding_matches( $user_id, $settings ) {
		$user_id  = absint( $user_id );
		$settings = is_array( $settings ) ? $settings : array();

		if ( $user_id <= 0 ) {
			return new WP_Error( 'pib_session_client_binding_mismatch', 'Invalid session user context.' );
		}

		if ( empty( $settings['session_client_fingerprint_enabled'] ) ) {
			return true;
		}

		$current_ua_hash        = $this->current_user_agent_hash();
		$current_ip_fingerprint = $this->current_ip_fingerprint( $settings );
		$current_wp_token_hash  = $this->current_wp_session_token_hash();

		$bound_ua_hash        = trim( (string) get_user_meta( $user_id, self::META_BOUND_UA_HASH, true ) );
		$bound_ip_fingerprint = trim( (string) get_user_meta( $user_id, self::META_BOUND_IP_FINGERPRINT, true ) );
		$bound_wp_token_hash  = trim( (string) get_user_meta( $user_id, self::META_BOUND_WP_TOKEN_HASH, true ) );

		// Backfill binding for older sessions or first protected request after login.
		if ( '' === $bound_ua_hash && '' !== $current_ua_hash ) {
			update_user_meta( $user_id, self::META_BOUND_UA_HASH, $current_ua_hash );
			$bound_ua_hash = $current_ua_hash;
		}
		if ( '' === $bound_ip_fingerprint && '' !== $current_ip_fingerprint ) {
			update_user_meta( $user_id, self::META_BOUND_IP_FINGERPRINT, $current_ip_fingerprint );
			$bound_ip_fingerprint = $current_ip_fingerprint;
		}
		if ( '' === $bound_wp_token_hash && '' !== $current_wp_token_hash ) {
			update_user_meta( $user_id, self::META_BOUND_WP_TOKEN_HASH, $current_wp_token_hash );
			$bound_wp_token_hash = $current_wp_token_hash;
		}

		if ( '' !== $bound_ua_hash && '' !== $current_ua_hash && ! hash_equals( $bound_ua_hash, $current_ua_hash ) ) {
			return new WP_Error( 'pib_session_client_binding_mismatch', 'Session user-agent binding changed.' );
		}

		if ( '' !== $bound_ip_fingerprint && '' !== $current_ip_fingerprint && ! hash_equals( $bound_ip_fingerprint, $current_ip_fingerprint ) ) {
			return new WP_Error( 'pib_session_client_binding_mismatch', 'Session IP binding changed.' );
		}

		if ( '' !== $bound_wp_token_hash && '' !== $current_wp_token_hash && ! hash_equals( $bound_wp_token_hash, $current_wp_token_hash ) ) {
			return new WP_Error( 'pib_session_client_binding_mismatch', 'Session token binding changed.' );
		}

		return true;
	}

	/**
	 * Get hash of current request user agent.
	 *
	 * @return string
	 */
	private function current_user_agent_hash() {
		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		if ( '' === $user_agent ) {
			return '';
		}

		return hash( 'sha256', $user_agent );
	}

	/**
	 * Get hash of active WordPress session token.
	 *
	 * @return string
	 */
	private function current_wp_session_token_hash() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$session_token = wp_get_session_token();
		if ( ! is_string( $session_token ) || '' === $session_token ) {
			return '';
		}

		return hash( 'sha256', $session_token );
	}

	/**
	 * Build masked IP-prefix fingerprint for the request.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return string
	 */
	private function current_ip_fingerprint( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$ip       = PIB_Security::client_ip();
		if ( '' === $ip ) {
			return '';
		}

		$packed = @inet_pton( $ip );
		if ( false === $packed || ! is_string( $packed ) ) {
			return '';
		}

		$is_v4      = strlen( $packed ) === 4;
		$prefix_max = $is_v4 ? 32 : 128;
		$prefix_min = $is_v4 ? 16 : 32;
		$prefix_key = $is_v4 ? 'session_ip_prefix_v4' : 'session_ip_prefix_v6';
		$prefix     = isset( $settings[ $prefix_key ] ) ? absint( $settings[ $prefix_key ] ) : ( $is_v4 ? 24 : 56 );
		$prefix     = max( $prefix_min, min( $prefix_max, $prefix ) );

		$masked = $this->mask_ip_binary( $packed, $prefix );
		if ( '' === $masked ) {
			return '';
		}

		return bin2hex( $masked ) . '/' . $prefix;
	}

	/**
	 * Apply prefix mask to packed binary IP address.
	 *
	 * @param string $packed_ip   Packed binary IP address.
	 * @param int    $prefix_bits Prefix length in bits.
	 *
	 * @return string
	 */
	private function mask_ip_binary( $packed_ip, $prefix_bits ) {
		$packed_ip   = is_string( $packed_ip ) ? $packed_ip : '';
		$prefix_bits = max( 0, absint( $prefix_bits ) );
		if ( '' === $packed_ip ) {
			return '';
		}

		$unpacked = unpack( 'C*', $packed_ip );
		if ( ! is_array( $unpacked ) ) {
			return '';
		}

		$bytes = array_values( $unpacked );
		if ( empty( $bytes ) ) {
			return '';
		}

		$total_bits = count( $bytes ) * 8;
		$prefix_bits = min( $prefix_bits, $total_bits );
		$full_bytes  = intdiv( $prefix_bits, 8 );
		$remainder   = $prefix_bits % 8;

		for ( $index = 0; $index < count( $bytes ); $index++ ) {
			if ( $index < $full_bytes ) {
				continue;
			}

			if ( $index === $full_bytes && $remainder > 0 ) {
				$mask          = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;
				$bytes[ $index ] = $bytes[ $index ] & $mask;
				continue;
			}

			$bytes[ $index ] = 0;
		}

		return pack( 'C*', ...$bytes );
	}

	/**
	 * Clear managed-session user meta markers.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	private function clear_managed_session_meta( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, self::META_MANAGED );
		delete_user_meta( $user_id, self::META_STARTED_AT );
		delete_user_meta( $user_id, self::META_LAST_REVALIDATED_AT );
		delete_user_meta( $user_id, self::META_BOUND_CUSTOMER_ID );
		delete_user_meta( $user_id, self::META_BOUND_COMPANY_ID );
		delete_user_meta( $user_id, self::META_BOUND_MAPPING_ID );
		delete_user_meta( $user_id, self::META_BOUND_UA_HASH );
		delete_user_meta( $user_id, self::META_BOUND_IP_FINGERPRINT );
		delete_user_meta( $user_id, self::META_BOUND_WP_TOKEN_HASH );
	}

	/**
	 * Build local logged-out destination URL.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return string
	 */
	private function logged_out_redirect_url( $settings ) {
		$dest = PIB_Destination::safe_failure_destination(
			isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '',
			'/portal-login'
		);

		return add_query_arg(
			array(
				'pib_logged_out' => '1',
			),
			PIB_Destination::to_absolute_url( $dest )
		);
	}
}
