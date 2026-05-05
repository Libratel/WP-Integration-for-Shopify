<?php
/**
 * Auth intake endpoint.
 *
 * JWT handoff flow:
 * Shopify/app side redirects back to this endpoint with:
 * - token (required)
 * - state (optional)
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives verified Shopify JWT handoff payloads and creates WP sessions.
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
	 * Handle customer identity intake from Shopify/app handoff.
	 *
	 * @return void
	 */
	public function handle() {
		nocache_headers();

		$settings = $this->plugin->get_settings();

		if ( PIB_Security::is_auth_rate_limited( (int) $settings['auth_failure_limit'] ) ) {
			$this->fail_and_exit( 'pib_auth_rate_limited', 'Too many failed attempts. Please try again later.', 429, $settings );
		}

		$token = $this->request_scalar_text( 'token' );
		$state = $this->request_scalar_text( 'state' ); // Optional. Reserved for future/state correlation.

		if ( '' === $token ) {
			$this->fail_and_exit( 'pib_missing_token', 'Missing handoff token.', 400, $settings );
		}

		$shared_secret = '';
		if ( isset( $settings['token_secret'] ) && is_string( $settings['token_secret'] ) ) {
			$shared_secret = trim( $settings['token_secret'] );
		}

		if ( '' === $shared_secret ) {
			$this->fail_and_exit( 'pib_missing_shared_secret', 'Shared secret is not configured.', 500, $settings );
		}

		$verified_claims = PIB_JWT_Verifier::verify_hs256(
			$token,
			$shared_secret,
			isset( $settings['expected_issuer'] ) ? (string) $settings['expected_issuer'] : '',
			isset( $settings['leeway_seconds'] ) ? absint( $settings['leeway_seconds'] ) : 30,
			$this->expected_audience( $settings )
		);

		if ( is_wp_error( $verified_claims ) ) {
			$status = 'pib_missing_token' === $verified_claims->get_error_code() ? 400 : 403;
			$this->fail_and_exit(
				$verified_claims->get_error_code(),
				$verified_claims->get_error_message(),
				$status,
				$settings
			);
		}

		$shopify_customer_id = PIB_Customer_Mapping::customer_id_from_claims( $verified_claims );
		$company_id          = PIB_Customer_Mapping::company_id_from_claims( $verified_claims );
		$email               = PIB_Customer_Mapping::email_from_claims( $verified_claims );
		if ( '' === $state && isset( $verified_claims['state'] ) && is_scalar( $verified_claims['state'] ) ) {
			$state = sanitize_text_field( (string) $verified_claims['state'] );
		}

		if ( '' === $shopify_customer_id ) {
			$this->fail_and_exit( 'pib_missing_customer_id', 'Missing customer ID claim.', 403, $settings, $verified_claims );
		}

		if ( ! empty( $settings['require_company_id'] ) && '' === $company_id ) {
			$this->fail_and_exit( 'pib_missing_company_id', 'Missing company/account claim.', 403, $settings, $verified_claims );
		}

		$dest_raw = isset( $verified_claims['dest'] ) ? (string) $verified_claims['dest'] : '';
		if ( '' === trim( $dest_raw ) ) {
			$this->fail_and_exit( 'pib_missing_dest', 'Missing destination claim.', 403, $settings, $verified_claims );
		}

		$dest = PIB_Destination::sanitize_destination( $dest_raw, (string) $settings['default_destination'] );
		if ( ! PIB_Destination::is_allowed( $dest, $settings['allowed_destination_prefixes'] ) ) {
			$this->fail_and_exit( 'pib_invalid_dest', 'Destination is not allowed.', 403, $settings, $verified_claims );
		}

		$jti         = isset( $verified_claims['jti'] ) ? sanitize_text_field( (string) $verified_claims['jti'] ) : '';
		$exp         = isset( $verified_claims['exp'] ) ? (int) $verified_claims['exp'] : 0;
		$now         = time();
		$leeway      = isset( $settings['leeway_seconds'] ) ? absint( $settings['leeway_seconds'] ) : 30;

		if ( '' === $jti ) {
			$this->fail_and_exit( 'pib_missing_jti', 'Token is missing a jti claim.', 403, $settings, $verified_claims );
		}

		$replay_ttl  = max( 60, ( $exp + $leeway ) - $now + 30 );
		$replay_key  = 'pib_jti_' . md5( $jti );

		if ( get_transient( $replay_key ) ) {
			$this->fail_and_exit(
				'pib_replay_detected',
				'Replay detected for this authentication handoff.',
				403,
				$settings,
				$verified_claims
			);
		}

		// Optional state verification hook.
		// Return WP_Error from filter to fail verification.
		$state_check = apply_filters(
			'pib_verify_handoff_state',
			true,
			$state,
			array(
				'shopify_customer_id' => $shopify_customer_id,
				'company_id'          => $company_id,
				'email'               => $email,
				'dest'                => $dest,
				'jti'                 => $jti,
			)
		);

		if ( is_wp_error( $state_check ) ) {
			$this->fail_and_exit(
				'pib_invalid_state',
				$state_check->get_error_message(),
				403,
				$settings
			);
		}

		// Store replay marker for the remaining token lifetime plus small buffer.
		set_transient( $replay_key, 1, $replay_ttl );

		$claims = array(
			'shopify_customer_id' => $shopify_customer_id,
			'company_id'          => $company_id,
			'email'               => $email,
			'dest'                => $dest,
			'jti'                 => $jti,
			'state'               => $state,
			'iss'                 => isset( $verified_claims['iss'] ) ? sanitize_text_field( (string) $verified_claims['iss'] ) : '',
			'aud'                 => isset( $verified_claims['aud'] ) ? $verified_claims['aud'] : '',
			'exp'                 => $exp,
			'iat'                 => isset( $verified_claims['iat'] ) ? (int) $verified_claims['iat'] : 0,
			'nbf'                 => isset( $verified_claims['nbf'] ) ? (int) $verified_claims['nbf'] : 0,
		);

		if ( isset( $verified_claims['wp_user_id'] ) ) {
			$claims['wp_user_id'] = absint( $verified_claims['wp_user_id'] );
		}

		$user = $this->resolve_wordpress_user( $claims );
		if ( ! $user instanceof WP_User ) {
			$this->fail_and_exit(
				'pib_user_not_found',
				'No linked WordPress user found for this identity.',
				403,
				$settings,
				$claims
			);
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
	 * Resolve WP user from verified claims.
	 *
	 * @param array<string, mixed> $claims Verified claim payload.
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
		 * Allow mapping module to resolve the stable user from verified claims.
		 *
		 * @param WP_User|false        $user   Current resolved user.
		 * @param array<string,mixed>  $claims Verified claim payload.
		 */
		return apply_filters( 'pib_resolve_user_from_claims', $user, $claims );
	}

	/**
	 * Get a scalar request value sanitized as text.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_scalar_text( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_REQUEST[ $key ] ) );
	}

	/**
	 * Handle auth failure and redirect safely.
	 *
	 * @param string               $code     Failure code.
	 * @param string               $message  Failure message.
	 * @param int                  $status   HTTP status.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @param array<string, mixed> $claims   Optional claim context.
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

	/**
	 * Get expected audience value from settings.
	 *
	 * Keeps compatibility with legacy expected_audience while using client_id
	 * as the preferred admin field.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return string
	 */
	private function expected_audience( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$audience = '';
		if ( isset( $settings['expected_audience'] ) && is_string( $settings['expected_audience'] ) ) {
			$audience = trim( $settings['expected_audience'] );
		}

		if ( '' === $audience && isset( $settings['client_id'] ) && is_string( $settings['client_id'] ) ) {
			$audience = trim( $settings['client_id'] );
		}

		return $audience;
	}
}
