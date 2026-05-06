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

		return sprintf(
			'<a class="pib-signin-link" href="%1$s">%2$s</a>',
			esc_url( $signin_url ),
			esc_html( $label )
		);
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
		if ( '' === $state && ! empty( $settings['require_handoff_state'] ) ) {
			if ( ! $this->is_launch_request() ) {
				$launch_url = $this->build_launch_url( $destination );
				if ( '' !== $launch_url ) {
					return $launch_url;
				}
			}

			// In strict state mode, never continue without state.
			return '';
		}
		if ( '' !== $state ) {
			$query_args['state'] = $state;
		}

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
