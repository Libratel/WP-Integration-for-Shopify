<?php
/**
 * Auth intake endpoint.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives signed Shopify handoff tokens and creates WP sessions.
 */
final class PIB_Auth_Intake {
	/**
	 * Singleton.
	 *
	 * @var PIB_Auth_Intake|null
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
	 * @return PIB_Auth_Intake|null
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

		add_action( 'admin_post_nopriv_pib_auth_intake', array( $this, 'handle' ) );
		add_action( 'admin_post_pib_auth_intake', array( $this, 'handle' ) );
	}

	/**
	 * Handle signed handoff token intake.
	 *
	 * @return void
	 */
	public function handle() {
		nocache_headers();

		$settings = $this->plugin->get_settings();
		if ( PIB_Security::is_auth_rate_limited( (int) $settings['auth_failure_limit'] ) ) {
			$this->fail_and_exit( 'pib_auth_rate_limited', 'Too many failed attempts. Please try again later.', 429, $settings );
		}

		$token = '';
		if ( isset( $_REQUEST['token'] ) && is_scalar( $_REQUEST['token'] ) ) {
			$token = sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) );
		}

		if ( '' === $token ) {
			$this->fail_and_exit( 'pib_missing_token', 'Missing handoff token.', 400, $settings );
		}

		$claims = PIB_JWT_Verifier::verify_hs256(
			$token,
			(string) $settings['token_secret'],
			(string) $settings['expected_issuer'],
			(int) $settings['leeway_seconds']
		);

		if ( is_wp_error( $claims ) ) {
			$this->fail_and_exit( $claims->get_error_code(), $claims->get_error_message(), 403, $settings );
		}

		$claims['shopify_customer_id'] = PIB_Customer_Mapping::customer_id_from_claims( $claims );
		$claims['company_id']          = PIB_Customer_Mapping::company_id_from_claims( $claims );

		if ( '' === $claims['shopify_customer_id'] ) {
			$this->fail_and_exit( 'pib_missing_customer_id', 'Token is missing a stable customer identifier.', 403, $settings, $claims );
		}

		$dest = PIB_Destination::sanitize_destination(
			isset( $claims['dest'] ) ? (string) $claims['dest'] : '',
			(string) $settings['default_destination']
		);

		if ( ! PIB_Destination::is_allowed( $dest, $settings['allowed_destination_prefixes'] ) ) {
			$this->fail_and_exit( 'pib_invalid_dest', 'Destination is not allowed.', 403, $settings, $claims );
		}

		$replay_check = $this->verify_and_store_jti( $claims, (int) $settings['leeway_seconds'] );
		if ( is_wp_error( $replay_check ) ) {
			$this->fail_and_exit( $replay_check->get_error_code(), $replay_check->get_error_message(), 403, $settings, $claims );
		}

		$user = $this->resolve_wordpress_user( $claims );
		if ( ! $user instanceof WP_User ) {
			$this->fail_and_exit( 'pib_user_not_found', 'No linked WordPress user found for this identity.', 403, $settings, $claims );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		do_action( 'pib_auth_intake_success', $claims, $user->ID, $dest );
		PIB_Security::clear_auth_failures();

		wp_safe_redirect( PIB_Destination::to_absolute_url( $dest ) );
		exit;
	}

	/**
	 * Resolve WP user from token claims.
	 *
	 * Phase 5 behavior:
	 * - Prefer wp_user_id if provided.
	 * - Hand off to mapping module for stable customer mapping resolution.
	 *
	 * @param array<string, mixed> $claims Verified token claims.
	 *
	 * @return WP_User|false
	 */
	private function resolve_wordpress_user( $claims ) {
		$user = false;

		if ( isset( $claims['wp_user_id'] ) ) {
			$user_id = absint( $claims['wp_user_id'] );
			if ( $user_id > 0 ) {
				$user = get_user_by( 'id', $user_id );
			}
		}

		/**
		 * Filter allows mapping module to plug in stable key resolution.
		 *
		 * @param WP_User|false        $user   Resolved user object.
		 * @param array<string, mixed> $claims Verified claims payload.
		 */
		return apply_filters( 'pib_resolve_user_from_claims', $user, $claims );
	}

	/**
	 * Enforce one-time token usage by jti.
	 *
	 * @param array<string, mixed> $claims         Verified claims.
	 * @param int                  $leeway_seconds Clock skew leeway.
	 *
	 * @return true|WP_Error
	 */
	private function verify_and_store_jti( $claims, $leeway_seconds ) {
		if ( empty( $claims['jti'] ) || ! is_string( $claims['jti'] ) ) {
			return new WP_Error( 'pib_missing_jti', 'Missing jti claim.' );
		}

		$jti = $claims['jti'];
		$key = 'pib_jti_' . md5( $jti );

		if ( get_transient( $key ) ) {
			return new WP_Error( 'pib_replay_detected', 'Replay token detected.' );
		}

		$exp = isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ? (int) $claims['exp'] : time() + 60;
		$ttl = max( 60, ( $exp - time() ) + max( 0, absint( $leeway_seconds ) ) );

		set_transient( $key, 1, $ttl );

		return true;
	}

	/**
	 * Handle auth failure and redirect safely.
	 *
	 * @param string               $code     Failure code.
	 * @param string               $message  Failure message.
	 * @param int                  $status   HTTP status.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @param array<string, mixed> $claims   Optional claim context.
	 *
	 * @return void
	 */
	private function fail_and_exit( $code, $message, $status, $settings, $claims = array() ) {
		PIB_Security::register_auth_failure( (int) $settings['auth_failure_window_minutes'] );
		do_action( 'pib_auth_intake_failure', $code, $message, $status, $claims );

		$failure_destination = PIB_Destination::sanitize_destination(
			(string) $settings['failure_destination'],
			'/'
		);

		$redirect_url = PIB_Destination::to_absolute_url( $failure_destination );
		$redirect_url = add_query_arg(
			array(
				'pib_auth_error' => (string) $code,
			),
			$redirect_url
		);

		status_header( absint( $status ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
