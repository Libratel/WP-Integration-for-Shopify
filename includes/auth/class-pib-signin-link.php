<?php
/**
 * Sign-in entry point utilities.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode and URL builder for starting Shopify auth flow.
 */
final class PIB_Signin_Link {
	/**
	 * Singleton.
	 *
	 * @var PIB_Signin_Link|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Request-local handoff state cache.
	 *
	 * @var string
	 */
	private $handoff_state = '';

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Signin_Link|null
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

		add_shortcode( 'portal_sign_in', array( $this, 'render_shortcode' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_shopify_redirect_host' ) );
	}

	/**
	 * Render signin link shortcode.
	 *
	 * Usage:
	 * [portal_sign_in]
	 * [portal_sign_in label="Continue to Portal"]
	 * [portal_sign_in dest="/dashboard/backorders?view=open"]
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => '',
				'dest'  => '',
			),
			$atts,
			'portal_sign_in'
		);

		$signin_url = $this->build_launch_url( $atts['dest'] );
		if ( '' === $signin_url ) {
			$signin_url = $this->build_signin_url( $atts['dest'] );
		}
		if ( '' === $signin_url ) {
			return '';
		}

		$settings = $this->plugin->get_settings();
		$label    = trim( (string) $atts['label'] );
		if ( '' === $label ) {
			$label = (string) $settings['sign_in_label'];
		}

		$link_markup = sprintf(
			'<a class="pib-signin-link" href="%1$s">%2$s</a>',
			esc_url( $signin_url ),
			esc_html( $label )
		);

		$notice_markup = $this->render_login_notice();
		if ( '' !== $notice_markup ) {
			return $notice_markup . $link_markup;
		}

		return $link_markup;
	}

	/**
	 * Build Shopify app route URL with return URL and destination.
	 *
	 * @param string $destination Optional destination override.
	 *
	 * @return string
	 */
	public function build_signin_url( $destination = '' ) {
		$settings        = $this->plugin->get_settings();
		$shopify_app_url = trim( (string) $settings['shopify_login_url'] );

		if ( '' === $shopify_app_url ) {
			return '';
		}

		$destination = $this->sanitize_allowed_destination( $destination, $settings );

		$query_args = array(
			'return_url' => PIB_Plugin::intake_url(),
			'intake'     => PIB_Plugin::intake_url(),
			'dest'       => $destination,
			'source'     => 'partner_portal',
		);

		$state = $this->current_handoff_state( $settings );
		// Worker handoff requires state for CSRF/replay protections.
		// Never continue to Shopify without a launch-bound state.
		if ( '' === $state ) {
			if ( ! $this->is_launch_request() ) {
				$launch_url = $this->build_launch_url( $destination );
				if ( '' !== $launch_url ) {
					return $launch_url;
				}
			}

			// Never continue without state.
			return '';
		}
		$query_args['state'] = $state;

		$client_id = isset( $settings['client_id'] ) ? trim( (string) $settings['client_id'] ) : '';
		if ( '' === $client_id && isset( $settings['expected_audience'] ) ) {
			$client_id = trim( (string) $settings['expected_audience'] );
		}
		if ( '' !== $client_id ) {
			$query_args['client_id'] = $client_id;
		}

		/**
		 * Filter the outbound Shopify auth query params.
		 *
		 * @param array<string, string> $query_args  Query args.
		 * @param string                $destination Validated destination.
		 */
		$query_args = apply_filters( 'pib_shopify_login_query_args', $query_args, $destination );

		return add_query_arg( $query_args, $shopify_app_url );
	}

	/**
	 * Build local launch entry URL that bootstraps state/cookie before Shopify redirect.
	 *
	 * @param string $destination Optional destination override.
	 * @return string
	 */
	public function build_launch_url( $destination = '' ) {
		$settings = $this->plugin->get_settings();
		if ( empty( $settings['spark_launch_enabled'] ) ) {
			return '';
		}

		$destination = $this->sanitize_allowed_destination( $destination, $settings );
		if ( ! PIB_Destination::is_allowed( $destination, $settings['spark_launch_allowed_prefixes'] ) ) {
			$destination = (string) $settings['default_destination'];
		}

		return add_query_arg(
			array(
				'pib_launch' => '1',
				'dest'       => $destination,
			),
			home_url( '/' )
		);
	}

	/**
	 * Sanitize destination using allowed destination policy.
	 *
	 * @param string               $destination Destination candidate.
	 * @param array<string, mixed> $settings    Plugin settings.
	 * @return string
	 */
	private function sanitize_allowed_destination( $destination, $settings ) {
		$destination = $destination ? $destination : PIB_Destination::current_request_destination();
		$destination = PIB_Destination::sanitize_destination( $destination, (string) $settings['default_destination'] );
		if ( ! PIB_Destination::is_allowed( $destination, $settings['allowed_destination_prefixes'] ) ) {
			$destination = (string) $settings['default_destination'];
		}

		return $destination;
	}

	/**
	 * Determine whether current request is already the launch bootstrap endpoint.
	 *
	 * @return bool
	 */
	private function is_launch_request() {
		return isset( $_GET['pib_launch'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Resolve or issue request-local handoff state value.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return string
	 */
	private function current_handoff_state( $settings ) {
		if ( '' !== $this->handoff_state ) {
			return $this->handoff_state;
		}

		$ttl = isset( $settings['handoff_state_ttl_seconds'] ) ? absint( $settings['handoff_state_ttl_seconds'] ) : 300;
		$ttl = max( 60, min( 1800, $ttl ) );

		$this->handoff_state = PIB_Security::issue_handoff_state( $ttl );
		return $this->handoff_state;
	}

	/**
	 * Render contextual portal-login notice from query args.
	 *
	 * @return string
	 */
	private function render_login_notice() {
		$error_code = '';
		if ( isset( $_GET['pib_auth_error'] ) && is_scalar( $_GET['pib_auth_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_code = sanitize_key( (string) wp_unslash( $_GET['pib_auth_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$level   = 'info';
		$message = '';

		$error_messages = array(
			'pib_signin_url_unavailable' => 'Secure sign-in state could not be created. Please retry from the portal login page.',
			'pib_signin_module_unavailable' => 'Portal sign-in module is unavailable. Please contact support.',
			'pib_email_verification_required' => 'Check your email to verify first-time portal access, then continue.',
			'pib_email_verification_rate_limited' => 'Verification email requests are temporarily rate-limited. Please wait a minute and try again.',
			'pib_email_verification_send_failed' => 'We could not send a verification email right now. Please try again shortly.',
			'pib_verification_invalid_token' => 'That verification link is invalid. Start sign-in again to request a fresh link.',
			'pib_verification_already_used' => 'That verification link has already been used. Start sign-in again if you need another link.',
			'pib_verification_expired_token' => 'That verification link expired. Start sign-in again to receive a new one.',
			'pib_verification_mapping_inactive' => 'That verification link is no longer valid for this account state. Start sign-in again.',
			'pib_verification_mapping_update_failed' => 'We could not activate your mapping from that link. Please try signing in again.',
			'pib_verification_user_missing' => 'Your WordPress account is unavailable. Please contact support.',
			'pib_session_client_binding_mismatch' => 'Your portal session changed devices or network fingerprint and was reset for safety. Please sign in again.',
		);

		if ( '' !== $error_code && isset( $error_messages[ $error_code ] ) ) {
			$level   = 'warning';
			$message = (string) $error_messages[ $error_code ];
		}

		if ( '' === $message && isset( $_GET['pib_logged_out'] ) && is_scalar( $_GET['pib_logged_out'] ) && '1' === (string) wp_unslash( $_GET['pib_logged_out'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = 'You are signed out of the partner portal session.';
			$level   = 'info';
		}

		if ( '' === $message && isset( $_GET['pib_verified'] ) && is_scalar( $_GET['pib_verified'] ) && '1' === (string) wp_unslash( $_GET['pib_verified'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = 'Your email was verified successfully. Continue to sign in.';
			$level   = 'success';
		}

		if ( '' === $message ) {
			return '';
		}

		$style = 'background:#eef6ff;border:1px solid #b7d5ff;color:#0a4f8a;';
		if ( 'warning' === $level ) {
			$style = 'background:#fff8e5;border:1px solid #ecd08f;color:#6e4a00;';
		} elseif ( 'success' === $level ) {
			$style = 'background:#edf7ed;border:1px solid #b8ddb8;color:#1e6b1e;';
		}

		return '<div class="pib-login-notice" style="margin:0 0 12px 0;padding:10px 12px;border-radius:6px;' . esc_attr( $style ) . '">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Allow configured Shopify host for wp_safe_redirect() calls.
	 *
	 * WordPress safe redirects only permit local hosts by default.
	 * Our sign-in flow intentionally redirects to Shopify, so we
	 * allowlist the configured Shopify Portal URL host.
	 *
	 * @param array<int, string> $hosts Allowed hosts.
	 *
	 * @return array<int, string>
	 */
	public function allow_shopify_redirect_host( $hosts ) {
		$hosts    = is_array( $hosts ) ? $hosts : array();
		$settings = $this->plugin->get_settings();

		$shopify_url = isset( $settings['shopify_login_url'] ) ? trim( (string) $settings['shopify_login_url'] ) : '';
		if ( '' === $shopify_url ) {
			return $hosts;
		}

		$host = wp_parse_url( $shopify_url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return $hosts;
		}

		$host = strtolower( $host );
		if ( ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}

		return $hosts;
	}
}
