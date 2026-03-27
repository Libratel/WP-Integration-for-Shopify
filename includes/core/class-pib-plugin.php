<?php
/**
 * Main plugin container.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin container for shared config and module bootstrapping.
 */
final class PIB_Plugin {
	/**
	 * Option key for plugin settings.
	 */
	const OPTION_KEY = 'pib_settings';

	/**
	 * Singleton instance.
	 *
	 * @var PIB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

	/**
	 * Build plugin object.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->boot_modules();
	}

	/**
	 * Singleton accessor.
	 *
	 * @return PIB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load required class files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-settings.php';
		require_once PIB_PLUGIN_DIR . 'includes/security/class-pib-destination.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-jwt-verifier.php';
		require_once PIB_PLUGIN_DIR . 'includes/security/class-pib-security.php';
		require_once PIB_PLUGIN_DIR . 'includes/mapping/class-pib-customer-mapping.php';
		require_once PIB_PLUGIN_DIR . 'includes/mapping/class-pib-authorization.php';
		require_once PIB_PLUGIN_DIR . 'includes/access/class-pib-protected-access.php';
		require_once PIB_PLUGIN_DIR . 'includes/spark/class-pib-spark-launch.php';
		require_once PIB_PLUGIN_DIR . 'includes/materials/class-pib-materials.php';
		require_once PIB_PLUGIN_DIR . 'includes/backorders/class-pib-backorders-source.php';
		require_once PIB_PLUGIN_DIR . 'includes/backorders/class-pib-backorders.php';
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-audit-log.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-session-policy.php';
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-admin-tools.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-auth-intake.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-signin-link.php';
	}

	/**
	 * Start plugin modules.
	 *
	 * @return void
	 */
	private function boot_modules() {
		PIB_Settings::instance( $this );
		PIB_Customer_Mapping::instance( $this );
		PIB_Protected_Access::instance( $this );
		PIB_Spark_Launch::instance( $this );
		PIB_Materials::instance( $this );
		PIB_Backorders::instance( $this );
		PIB_Audit_Log::instance( $this );
		PIB_Session_Policy::instance( $this );
		PIB_Admin_Tools::instance( $this );
		PIB_Auth_Intake::instance( $this );
		PIB_Signin_Link::instance( $this );
	}

	/**
	 * Get merged plugin settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::default_settings() );

		$settings['allowed_destination_prefixes'] = PIB_Destination::sanitize_prefixes( $settings['allowed_destination_prefixes'] );
		$settings['protected_route_prefixes']     = PIB_Destination::sanitize_prefixes( $settings['protected_route_prefixes'] );
		$settings['spark_launch_allowed_prefixes'] = PIB_Destination::sanitize_prefixes( $settings['spark_launch_allowed_prefixes'] );
		$settings['default_destination']          = PIB_Destination::sanitize_destination( $settings['default_destination'], '/dashboard' );
		$settings['failure_destination']          = PIB_Destination::sanitize_destination( $settings['failure_destination'], '/' );
		$settings['leeway_seconds']               = max( 0, min( 300, absint( $settings['leeway_seconds'] ) ) );
		$settings['backorders_api_timeout']       = max( 3, min( 60, absint( $settings['backorders_api_timeout'] ) ) );
		$settings['backorders_default_limit']     = max( 1, min( 5000, absint( $settings['backorders_default_limit'] ) ) );
		$settings['managed_session_ttl_hours']    = max( 1, min( 720, absint( $settings['managed_session_ttl_hours'] ) ) );
		$settings['revalidate_interval_minutes']  = max( 1, min( 1440, absint( $settings['revalidate_interval_minutes'] ) ) );
		$settings['auth_failure_limit']           = max( 1, min( 500, absint( $settings['auth_failure_limit'] ) ) );
		$settings['auth_failure_window_minutes']  = max( 1, min( 1440, absint( $settings['auth_failure_window_minutes'] ) ) );
		$settings['audit_log_retention_days']     = max( 1, min( 3650, absint( $settings['audit_log_retention_days'] ) ) );
		$settings['auto_create_users']            = empty( $settings['auto_create_users'] ) ? 0 : 1;
		$settings['auto_link_existing_users_by_email'] = empty( $settings['auto_link_existing_users_by_email'] ) ? 0 : 1;
		$settings['require_active_mapping']       = empty( $settings['require_active_mapping'] ) ? 0 : 1;
		$settings['spark_launch_enabled']         = empty( $settings['spark_launch_enabled'] ) ? 0 : 1;
		$settings['backorders_enabled']           = empty( $settings['backorders_enabled'] ) ? 0 : 1;
		$settings['periodic_revalidation_enabled'] = empty( $settings['periodic_revalidation_enabled'] ) ? 0 : 1;
		$settings['coordinated_logout_enabled']   = empty( $settings['coordinated_logout_enabled'] ) ? 0 : 1;
		$settings['audit_logging_enabled']        = empty( $settings['audit_logging_enabled'] ) ? 0 : 1;
		$settings['backorders_api_endpoint']      = esc_url_raw( trim( (string) $settings['backorders_api_endpoint'] ) );
		$settings['backorders_api_token']         = sanitize_text_field( (string) $settings['backorders_api_token'] );
		$settings['upstream_logout_url']          = esc_url_raw( trim( (string) $settings['upstream_logout_url'] ) );
		$settings['upstream_logout_return_param'] = sanitize_key( (string) $settings['upstream_logout_return_param'] );
		if ( '' === $settings['upstream_logout_return_param'] ) {
			$settings['upstream_logout_return_param'] = 'return_to';
		}

		$roles      = wp_roles();
		$role_names = $roles instanceof WP_Roles ? $roles->get_names() : array();
		$role       = sanitize_key( (string) $settings['new_user_role'] );
		if ( ! isset( $role_names[ $role ] ) ) {
			$role = 'subscriber';
		}
		$settings['new_user_role'] = $role;

		$this->settings = $settings;

		return $this->settings;
	}

	/**
	 * Clear settings cache after option updates.
	 *
	 * @return void
	 */
	public function refresh_settings_cache() {
		$this->settings = null;
	}

	/**
	 * Plugin defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings() {
		return array(
			'shopify_login_url'             => '',
			'token_secret'                  => '',
			'expected_issuer'               => '',
			'leeway_seconds'                => 30,
			'default_destination'           => '/dashboard',
			'allowed_destination_prefixes'  => array(
				'/dashboard',
				'/materials',
				'/downloads',
			),
			'failure_destination'           => '/portal-login',
			'sign_in_label'                 => 'Sign in to Portal',
			'protected_route_prefixes'      => array(
				'/dashboard',
				'/materials',
				'/downloads',
			),
			'spark_launch_allowed_prefixes' => array(
				'/dashboard',
				'/materials',
				'/downloads',
			),
			'auto_create_users'             => 1,
			'auto_link_existing_users_by_email' => 1,
			'new_user_role'                 => 'subscriber',
			'require_active_mapping'        => 1,
			'spark_launch_enabled'          => 1,
			'backorders_enabled'            => 1,
			'backorders_api_endpoint'       => '',
			'backorders_api_token'          => '',
			'backorders_api_timeout'        => 15,
			'backorders_default_limit'      => 200,
			'managed_session_ttl_hours'     => 8,
			'periodic_revalidation_enabled' => 1,
			'revalidate_interval_minutes'   => 30,
			'coordinated_logout_enabled'    => 0,
			'upstream_logout_url'           => '',
			'upstream_logout_return_param'  => 'return_to',
			'auth_failure_limit'            => 10,
			'auth_failure_window_minutes'   => 15,
			'audit_logging_enabled'         => 1,
			'audit_log_retention_days'      => 90,
		);
	}

	/**
	 * Build the WordPress intake endpoint URL.
	 *
	 * @return string
	 */
	public static function intake_url() {
		return add_query_arg(
			array(
				'action' => 'pib_auth_intake',
			),
			admin_url( 'admin-post.php' )
		);
	}
}
