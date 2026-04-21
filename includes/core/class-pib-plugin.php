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
	 * Module registry.
	 *
	 * @var PIB_Module_Registry|null
	 */
	private $module_registry = null;

	/**
	 * Booted module instances.
	 *
	 * @var array<string, mixed>
	 */
	private $modules = array();

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
		require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-settings-contract.php';
		require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-settings.php';
		require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-module-registry.php';
		require_once PIB_PLUGIN_DIR . 'includes/security/class-pib-destination.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-jwt-verifier.php';
		require_once PIB_PLUGIN_DIR . 'includes/security/class-pib-security.php';
		require_once PIB_PLUGIN_DIR . 'includes/mapping/class-pib-customer-mapping.php';
		require_once PIB_PLUGIN_DIR . 'includes/mapping/class-pib-authorization.php';
		require_once PIB_PLUGIN_DIR . 'includes/access/class-pib-protected-route-policy.php';
		require_once PIB_PLUGIN_DIR . 'includes/access/class-pib-protected-access.php';
		require_once PIB_PLUGIN_DIR . 'includes/spark/class-pib-spark-launch.php';
		require_once PIB_PLUGIN_DIR . 'includes/materials/class-pib-materials.php';
		require_once PIB_PLUGIN_DIR . 'includes/backorders/class-pib-backorders-source.php';
		require_once PIB_PLUGIN_DIR . 'includes/backorders/class-pib-backorders.php';
		require_once PIB_PLUGIN_DIR . 'includes/portal/class-pib-portal-modules.php';
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-audit-log.php';
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-operations-health.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-session-policy.php';
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-admin-tools.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-auth-intake.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-bridge-simulator.php';
		require_once PIB_PLUGIN_DIR . 'includes/auth/class-pib-signin-link.php';
	}

	/**
	 * Start plugin modules.
	 *
	 * @return void
	 */
	private function boot_modules() {
		$registry = new PIB_Module_Registry();
		$this->register_core_modules( $registry );

		/**
		 * Register additional or replacement modules before boot.
		 *
		 * @param PIB_Module_Registry $registry Module registry.
		 * @param PIB_Plugin          $plugin   Plugin container.
		 */
		do_action( 'pib_register_modules', $registry, $this );

		/**
		 * Fires after module registration is complete and before boot begins.
		 *
		 * @param PIB_Module_Registry $registry Module registry.
		 * @param PIB_Plugin          $plugin   Plugin container.
		 */
		do_action( 'pib_modules_registered', $registry, $this );

		$this->module_registry = $registry;

		/**
		 * Fires immediately before booting modules.
		 *
		 * @param PIB_Module_Registry $registry Module registry.
		 * @param PIB_Plugin          $plugin   Plugin container.
		 */
		do_action( 'pib_before_modules_boot', $registry, $this );

		$this->modules = $registry->boot( $this );

		/**
		 * Fires after all registered modules have been booted.
		 *
		 * @param array<string,mixed> $modules  Booted module instances.
		 * @param PIB_Module_Registry $registry Module registry.
		 * @param PIB_Plugin          $plugin   Plugin container.
		 */
		do_action( 'pib_modules_booted', $this->modules, $registry, $this );
	}

	/**
	 * Register the built-in module set in deterministic order.
	 *
	 * @param PIB_Module_Registry $registry Module registry.
	 * @return void
	 */
	private function register_core_modules( PIB_Module_Registry $registry ) {
		$registry->register_class( 'settings', 'PIB_Settings' );
		$registry->register_class( 'customer_mapping', 'PIB_Customer_Mapping' );
		$registry->register_class( 'protected_access', 'PIB_Protected_Access' );
		$registry->register_class( 'spark_launch', 'PIB_Spark_Launch' );
		$registry->register_class( 'materials', 'PIB_Materials' );
		$registry->register_class( 'backorders', 'PIB_Backorders' );
		$registry->register_class( 'portal_modules', 'PIB_Portal_Modules' );
		$registry->register_class( 'audit_log', 'PIB_Audit_Log' );
		$registry->register_class( 'operations_health', 'PIB_Operations_Health' );
		$registry->register_class( 'session_policy', 'PIB_Session_Policy' );
		$registry->register_class( 'admin_tools', 'PIB_Admin_Tools' );
		$registry->register_class( 'auth_intake', 'PIB_Auth_Intake' );
		$registry->register_class( 'bridge_simulator', 'PIB_Bridge_Simulator' );
		$registry->register_class( 'signin_link', 'PIB_Signin_Link' );
	}

	/**
	 * Get module registry.
	 *
	 * @return PIB_Module_Registry|null
	 */
	public function get_module_registry() {
		return $this->module_registry;
	}

	/**
	 * Get module instance by identifier.
	 *
	 * @param string $module_id Module ID.
	 * @return mixed|null
	 */
	public function get_module( $module_id ) {
		if ( ! $this->module_registry instanceof PIB_Module_Registry ) {
			return null;
		}

		return $this->module_registry->get( $module_id );
	}

	/**
	 * Get all booted module instances.
	 *
	 * @return array<string, mixed>
	 */
	public function get_modules() {
		return $this->modules;
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

		$this->settings = PIB_Settings_Contract::normalize( $stored, $stored );

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
		if ( ! class_exists( 'PIB_Settings_Contract' ) ) {
			require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-settings-contract.php';
		}

		return PIB_Settings_Contract::defaults();
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
