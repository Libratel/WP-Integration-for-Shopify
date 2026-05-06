<?php
/**
 * Frontend protected-route guard.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces authentication/mapping presence for configured protected paths.
 */
final class PIB_Protected_Access {
	/**
	 * Singleton.
	 *
	 * @var PIB_Protected_Access|null
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
	 * @return PIB_Protected_Access|null
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

		add_action( 'template_redirect', array( $this, 'enforce' ), 1 );
	}

	/**
	 * Enforce protected route policy.
	 *
	 * @return void
	 */
	public function enforce() {
		if ( is_admin() || wp_doing_ajax() || $this->is_rest_request() ) {
			return;
		}

		$settings    = $this->plugin->get_settings();
		$destination = PIB_Destination::current_request_destination();
		$evaluation  = PIB_Protected_Route_Policy::evaluate_destination( $destination, $settings );

		if ( empty( $evaluation['is_protected'] ) ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		do_action( 'pib_protected_route_match', $destination, $evaluation, get_current_user_id() );

		if ( ! is_user_logged_in() ) {
			do_action( 'pib_protected_access_denied', 'login_required', $destination, 0, $evaluation );
			$this->redirect_to_signin( $destination, 'pib_login_required' );
		}

		if ( ! empty( $settings['require_managed_session_for_protected_routes'] ) && ! PIB_Session_Policy::is_current_session_managed() ) {
			do_action( 'pib_protected_access_denied', 'managed_session_required', $destination, get_current_user_id(), $evaluation );
			$this->redirect_to_signin( $destination, 'pib_managed_session_required' );
		}

		if ( ! empty( $settings['require_active_mapping'] ) ) {
			$identity = PIB_Authorization::current_identity();
			if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
				do_action( 'pib_protected_access_denied', 'mapping_required', $destination, get_current_user_id(), $evaluation );
				$this->redirect_to_signin( $destination, 'pib_mapping_required' );
			}
		}
	}

	/**
	 * Redirect unauthenticated/invalid users to sign-in.
	 *
	 * @param string $destination Requested protected destination.
	 * @param string $error_code  Error code for troubleshooting.
	 *
	 * @return void
	 */
	private function redirect_to_signin( $destination, $error_code ) {
		$signin = PIB_Signin_Link::instance( $this->plugin );
		if ( $signin instanceof PIB_Signin_Link ) {
			$signin_url = $signin->build_signin_url( $destination );
			if ( '' !== $signin_url ) {
				wp_safe_redirect( $signin_url );
				exit;
			}
		}

		$settings = $this->plugin->get_settings();
		$fallback = PIB_Destination::safe_failure_destination(
			isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '',
			'/portal-login'
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
	 * Detect REST API request context.
	 *
	 * @return bool
	 */
	private function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$rest_prefix = '/' . rest_get_url_prefix() . '/';
		return false !== strpos( $uri, $rest_prefix );
	}
}
