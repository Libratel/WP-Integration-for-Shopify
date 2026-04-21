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

		$signin_url = $this->build_signin_url( $atts['dest'] );
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
		$settings          = $this->plugin->get_settings();
		$shopify_app_url = trim( (string) $settings['shopify_login_url'] );

		if ( '' === $shopify_app_url ) {
			return '';
		}

		$destination = $destination ? $destination : PIB_Destination::current_request_destination();
		$destination = PIB_Destination::sanitize_destination( $destination, (string) $settings['default_destination'] );

		if ( ! PIB_Destination::is_allowed( $destination, $settings['allowed_destination_prefixes'] ) ) {
			$destination = (string) $settings['default_destination'];
		}

		$query_args = array(
			'return_url' => PIB_Plugin::intake_url(),
			'intake'     => PIB_Plugin::intake_url(),
			'dest'       => $destination,
			'source'     => 'partner_portal',
		);

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
}
