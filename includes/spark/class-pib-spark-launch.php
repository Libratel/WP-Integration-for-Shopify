<?php
/**
 * SparkLayer launch entry handling.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles SparkLayer-originated launches into protected portal destinations.
 */
final class PIB_Spark_Launch {
	/**
	 * Singleton.
	 *
	 * @var PIB_Spark_Launch|null
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
	 * @return PIB_Spark_Launch|null
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

		add_action( 'template_redirect', array( $this, 'handle_launch_request' ), 0 );
		add_shortcode( 'portal_launch_link', array( $this, 'render_launch_shortcode' ) );
	}

	/**
	 * Render launch-link shortcode.
	 *
	 * Usage:
	 * [portal_launch_link dest="/materials" label="Partner Materials"]
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_launch_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'dest'  => '/dashboard',
				'label' => 'Open Portal',
			),
			$atts,
			'portal_launch_link'
		);

		$dest = $this->sanitize_launch_destination( (string) $atts['dest'] );
		if ( '' === $dest ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'pib_launch' => '1',
				'dest'       => $dest,
			),
			home_url( '/' )
		);

		return sprintf(
			'<a class="pib-launch-link" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( (string) $atts['label'] )
		);
	}

	/**
	 * Handle SparkLayer launch request (`?pib_launch=1&dest=...`).
	 *
	 * @return void
	 */
	public function handle_launch_request() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! isset( $_GET['pib_launch'] ) ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		if ( empty( $settings['spark_launch_enabled'] ) ) {
			return;
		}

		$dest = '';
		if ( isset( $_GET['dest'] ) && is_scalar( $_GET['dest'] ) ) {
			$dest = (string) wp_unslash( $_GET['dest'] );
		}
		$dest = $this->sanitize_launch_destination( $dest );
		if ( '' === $dest ) {
			$dest = (string) $settings['default_destination'];
		}

		if ( is_user_logged_in() ) {
			if ( ! empty( $settings['require_active_mapping'] ) ) {
				$identity = PIB_Authorization::current_identity();
				if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
					$this->redirect_to_shopify_signin( $dest );
				}
			}

			wp_safe_redirect( PIB_Destination::to_absolute_url( $dest ) );
			exit;
		}

		$this->redirect_to_shopify_signin( $dest );
	}

	/**
	 * Redirect visitor into Shopify sign-in handoff for target destination.
	 *
	 * @param string $dest Destination path.
	 *
	 * @return void
	 */
	private function redirect_to_shopify_signin( $dest ) {
		$signin = PIB_Signin_Link::instance( $this->plugin );
		if ( ! $signin instanceof PIB_Signin_Link ) {
			$this->redirect_to_failure( 'pib_signin_module_unavailable' );
		}

		$signin_url = $signin->build_signin_url( $dest );
		if ( '' === $signin_url ) {
			$this->redirect_to_failure( 'pib_signin_url_unavailable' );
		}

		wp_safe_redirect( $signin_url );
		exit;
	}

	/**
	 * Redirect launch failures to configured fallback page.
	 *
	 * @param string $error_code Error code.
	 *
	 * @return void
	 */
	private function redirect_to_failure( $error_code ) {
		$settings = $this->plugin->get_settings();
		$fallback = PIB_Destination::sanitize_destination(
			(string) $settings['failure_destination'],
			'/'
		);

		$url = add_query_arg(
			array(
				'pib_auth_error' => (string) $error_code,
			),
			PIB_Destination::to_absolute_url( $fallback )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Sanitize destination against launch allowlist.
	 *
	 * @param string $destination Raw destination.
	 *
	 * @return string
	 */
	private function sanitize_launch_destination( $destination ) {
		$settings    = $this->plugin->get_settings();
		$destination = PIB_Destination::sanitize_destination(
			$destination,
			(string) $settings['default_destination']
		);

		if ( ! PIB_Destination::is_allowed( $destination, $settings['spark_launch_allowed_prefixes'] ) ) {
			return '';
		}

		return $destination;
	}
}
