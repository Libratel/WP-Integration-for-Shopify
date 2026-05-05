<?php
/**
 * Plugin activation/install routines.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles install-time setup such as custom tables.
 */
final class PIB_Installer {
	/**
	 * Internal schema/version marker.
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Role slug for Shopify customer identities.
	 */
	const ROLE_SHOPIFY_CUSTOMER = 'shopify_customer';

	/**
	 * Role slug for internal staff users.
	 */
	const ROLE_INTERNAL_EMPLOYEE = 'internal_employee';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_mapping_table();
		self::create_audit_log_table();
		self::initialize_settings();
		self::ensure_roles();
		self::maybe_migrate_default_customer_role();
		self::ensure_audit_log_schedule();
		update_option( 'pib_db_version', self::DB_VERSION );
	}

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_audit_log_schedule();
	}

	/**
	 * Run lightweight upgrade checks.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'pib_db_version', '' );
		if ( self::DB_VERSION !== (string) $current ) {
			self::create_mapping_table();
			self::create_audit_log_table();
			self::initialize_settings();
			update_option( 'pib_db_version', self::DB_VERSION );
		}

		self::ensure_roles();
		self::maybe_migrate_default_customer_role();
		self::ensure_audit_log_schedule();
	}

	/**
	 * Create customer mapping table.
	 *
	 * @return void
	 */
	private static function create_mapping_table() {
		global $wpdb;

		$table_name      = PIB_Customer_Mapping::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			shopify_customer_id VARCHAR(64) NOT NULL,
			company_id VARCHAR(64) NOT NULL DEFAULT '',
			wp_user_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			last_seen_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY shopify_company_unique (shopify_customer_id, company_id),
			KEY wp_user_idx (wp_user_id),
			KEY email_idx (email),
			KEY status_idx (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create event audit log table.
	 *
	 * @return void
	 */
	private static function create_audit_log_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'pib_event_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(80) NOT NULL,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			shopify_customer_id VARCHAR(64) NULL,
			company_id VARCHAR(64) NULL,
			ip_address VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_type_idx (event_type),
			KEY level_idx (level),
			KEY created_at_idx (created_at),
			KEY user_id_idx (user_id),
			KEY customer_idx (shopify_customer_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure plugin settings exist.
	 *
	 * @return void
	 */
	private static function initialize_settings() {
		$existing = get_option( PIB_Plugin::OPTION_KEY, null );
		if ( null === $existing ) {
			add_option( PIB_Plugin::OPTION_KEY, PIB_Plugin::default_settings() );
		}
	}

	/**
	 * Ensure custom portal roles exist.
	 *
	 * @return void
	 */
	private static function ensure_roles() {
		$customer_caps = array(
			'read' => true,
		);

		add_role(
			self::ROLE_SHOPIFY_CUSTOMER,
			'Shopify Customer',
			$customer_caps
		);

		add_role(
			self::ROLE_INTERNAL_EMPLOYEE,
			'Internal Employee',
			$customer_caps
		);

		$customer_role = get_role( self::ROLE_SHOPIFY_CUSTOMER );
		if ( $customer_role instanceof WP_Role ) {
			$customer_role->add_cap( 'read' );
		}

		$employee_role = get_role( self::ROLE_INTERNAL_EMPLOYEE );
		if ( $employee_role instanceof WP_Role ) {
			$employee_role->add_cap( 'read' );
		}
	}

	/**
	 * Migrate legacy auto-created user default role to `shopify_customer`.
	 *
	 * @return void
	 */
	private static function maybe_migrate_default_customer_role() {
		$flag_key = 'pib_role_default_migrated_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		$settings = get_option( PIB_Plugin::OPTION_KEY, null );
		if ( is_array( $settings ) ) {
			$current_role = isset( $settings['new_user_role'] ) ? sanitize_key( (string) $settings['new_user_role'] ) : '';
			if ( '' === $current_role || 'subscriber' === $current_role ) {
				$settings['new_user_role'] = self::ROLE_SHOPIFY_CUSTOMER;
				update_option( PIB_Plugin::OPTION_KEY, $settings );
			}
		}

		add_option( $flag_key, 1, '', false );
	}

	/**
	 * Ensure audit-log trim cron job exists.
	 *
	 * @return void
	 */
	private static function ensure_audit_log_schedule() {
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-audit-log.php';
		PIB_Audit_Log::ensure_trim_schedule();
	}

	/**
	 * Clear audit-log trim cron job.
	 *
	 * @return void
	 */
	private static function clear_audit_log_schedule() {
		require_once PIB_PLUGIN_DIR . 'includes/admin/class-pib-audit-log.php';
		PIB_Audit_Log::clear_trim_schedule();
	}
}
