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
 * Enforces session lifetime/revalidation for bridge-authenticated users.
 */
final class PIB_Session_Policy {
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
		add_action( 'init', array( $this, 'enforce_policy' ), 2 );
		add_action( 'admin_post_pib_logout', array( $this, 'handle_logout' ) );
		add_action( 'admin_post_nopriv_pib_logout', array( $this, 'handle_logout' ) );
		add_shortcode( 'portal_logout_link', array( $this, 'render_logout_shortcode' ) );
	}

	/**
	 * Mark current user session as plugin-managed.
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

		$now = time();
		update_user_meta( $user_id, self::META_MANAGED, 1 );
		update_user_meta( $user_id, self::META_STARTED_AT, $now );
		update_user_meta( $user_id, self::META_LAST_REVALIDATED_AT, $now );
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

		$is_managed = absint( get_user_meta( $user_id, self::META_MANAGED, true ) ) === 1;
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

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_MANAGED );
			delete_user_meta( $user_id, self::META_STARTED_AT );
			delete_user_meta( $user_id, self::META_LAST_REVALIDATED_AT );
		}

		wp_logout();

		$settings = $this->plugin->get_settings();
		if ( ! empty( $settings['coordinated_logout_enabled'] ) ) {
			$upstream = esc_url_raw( trim( (string) $settings['upstream_logout_url'] ) );
			if ( '' !== $upstream ) {
				$param = sanitize_key( (string) $settings['upstream_logout_return_param'] );
				if ( '' === $param ) {
					$param = 'return_to';
				}
				$upstream = add_query_arg(
					array(
						$param => home_url( '/' ),
					),
					$upstream
				);
				wp_safe_redirect( $upstream );
				exit;
			}
		}

		wp_safe_redirect( home_url( '/?pib_logged_out=1' ) );
		exit;
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
			delete_user_meta( $user_id, self::META_MANAGED );
			delete_user_meta( $user_id, self::META_STARTED_AT );
			delete_user_meta( $user_id, self::META_LAST_REVALIDATED_AT );
		}

		do_action( 'pib_session_expired', $user_id, $reason, is_array( $context ) ? $context : array() );

		wp_logout();

		$settings = $this->plugin->get_settings();
		$dest     = PIB_Destination::sanitize_destination(
			(string) $settings['failure_destination'],
			'/'
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
}
