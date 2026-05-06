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
