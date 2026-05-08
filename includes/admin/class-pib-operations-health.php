<?php
/**
 * Operational health checks and diagnostic snapshot utilities.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes plugin health checks for admin tooling and support workflows.
 */
final class PIB_Operations_Health {
	/**
	 * Singleton.
	 *
	 * @var PIB_Operations_Health|null
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
	 * @return PIB_Operations_Health|null
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
	 * Build object.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Build a full read-only support snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function build_snapshot() {
		$settings            = $this->plugin->get_settings();
		$settings_diagnostics = PIB_Settings_Contract::diagnostics( $settings );
		$health              = $this->run_checks( $settings, $settings_diagnostics );
		$registry            = $this->plugin->get_module_registry();
		$registered_modules  = $registry instanceof PIB_Module_Registry ? $registry->all_module_ids() : array();
		$booted_modules      = array_keys( $this->plugin->get_modules() );
		$mapping_counts      = $this->mapping_counts();
		$auth_counts         = $this->auth_window_counts( 24 );

		$snapshot = array(
			'generated_at_utc'      => gmdate( 'c' ),
			'plugin_version'        => defined( 'PIB_VERSION' ) ? (string) PIB_VERSION : '',
			'db_version'            => (string) get_option( 'pib_db_version', '' ),
			'intake_url'            => PIB_Plugin::intake_url(),
			'health'                => $health,
			'settings_status'       => isset( $settings_diagnostics['status'] ) ? (string) $settings_diagnostics['status'] : 'healthy',
			'settings_references'   => isset( $settings_diagnostics['references'] ) && is_array( $settings_diagnostics['references'] ) ? $settings_diagnostics['references'] : array(),
			'effective_settings'    => isset( $settings_diagnostics['effective_values'] ) && is_array( $settings_diagnostics['effective_values'] ) ? $settings_diagnostics['effective_values'] : array(),
			'module_registry'       => array(
				'registered' => $registered_modules,
				'booted'     => $booted_modules,
			),
			'mapping_counts'        => $mapping_counts,
			'auth_window_24h'       => $auth_counts,
			'trim_schedule_next_utc' => $this->next_trim_schedule_iso8601(),
		);

		/**
		 * Filter exported support snapshot payload.
		 *
		 * @param array<string,mixed> $snapshot Snapshot payload.
		 * @param array<string,mixed> $settings Settings payload.
		 * @param array<string,mixed> $health   Health payload.
		 * @param PIB_Plugin          $plugin   Plugin container.
		 * @param PIB_Operations_Health $module Health module instance.
		 */
		$snapshot = apply_filters( 'pib_operations_health_snapshot', $snapshot, $settings, $health, $this->plugin, $this );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Run health checks against normalized runtime settings.
	 *
	 * @param array<string, mixed>|null        $settings             Optional settings override.
	 * @param array<string, mixed>|null        $settings_diagnostics Optional settings diagnostics payload.
	 *
	 * @return array<string, mixed>
	 */
	public function run_checks( $settings = null, $settings_diagnostics = null ) {
		$settings             = is_array( $settings ) ? $settings : $this->plugin->get_settings();
		$settings_diagnostics = is_array( $settings_diagnostics ) ? $settings_diagnostics : PIB_Settings_Contract::diagnostics( $settings );
		$checks               = array();
		$setup_url            = $this->settings_page_url();
		$mappings_url         = $this->tools_panel_url( 'mappings' );
		$failures_url         = $this->tools_panel_url( 'failures' );
		$extensions_url       = $this->tools_panel_url( 'observability', 'extensions' );
		$logs_url             = $this->tools_panel_url( 'observability', 'logs' );
		$token_url            = $this->tools_panel_url( 'observability', 'token' );

		$settings_status = isset( $settings_diagnostics['status'] ) ? sanitize_key( (string) $settings_diagnostics['status'] ) : 'healthy';
		if ( 'action_needed' === $settings_status ) {
			$this->add_check(
				$checks,
				'settings_contract',
				'critical',
				'Settings diagnostics report critical configuration issues.',
				array(
					'action_label' => 'Open Setup',
					'action_url'   => $setup_url,
				)
			);
		} elseif ( 'warnings' === $settings_status ) {
			$this->add_check(
				$checks,
				'settings_contract',
				'warning',
				'Settings diagnostics report non-blocking warnings.',
				array(
					'action_label' => 'Open Setup',
					'action_url'   => $setup_url,
				)
			);
		} else {
			$this->add_check( $checks, 'settings_contract', 'ok', 'Settings diagnostics are healthy.' );
		}

		$mapping_table_exists = $this->table_exists( PIB_Customer_Mapping::table_name() );
		if ( $mapping_table_exists ) {
			$this->add_check( $checks, 'mapping_table', 'ok', 'Customer mapping table is available.' );
		} else {
			$this->add_check(
				$checks,
				'mapping_table',
				'critical',
				'Customer mapping table is missing. Re-run plugin activation/upgrade.',
				array(
					'action_label' => 'Open Setup',
					'action_url'   => $setup_url,
				)
			);
		}

		$audit_table_exists = $this->table_exists( PIB_Audit_Log::table_name() );
		$audit_enabled      = ! empty( $settings['audit_logging_enabled'] );
		if ( $audit_table_exists ) {
			$this->add_check( $checks, 'audit_table', 'ok', 'Audit log table is available.' );
		} elseif ( $audit_enabled ) {
			$this->add_check(
				$checks,
				'audit_table',
				'critical',
				'Audit logging is enabled but audit table is missing.',
				array(
					'action_label' => 'Open Setup',
					'action_url'   => $setup_url,
				)
			);
		} else {
			$this->add_check( $checks, 'audit_table', 'ok', 'Audit logging is disabled; audit table availability is optional.' );
		}

		$verification_table_exists = class_exists( 'PIB_Registration_Verifier' ) && $this->table_exists( PIB_Registration_Verifier::table_name() );
		$first_time_verification   = ! empty( $settings['first_time_verification_enabled'] );
		if ( $verification_table_exists ) {
			$this->add_check( $checks, 'verification_table', 'ok', 'Verification token table is available.' );
		} elseif ( $first_time_verification ) {
			$this->add_check(
				$checks,
				'verification_table',
				'critical',
				'First-time verification is enabled but verification token table is missing.',
				array(
					'action_label' => 'Open Setup',
					'action_url'   => $setup_url,
				)
			);
		} else {
			$this->add_check( $checks, 'verification_table', 'ok', 'First-time verification is disabled; verification token table is optional.' );
		}

		$next_trim = wp_next_scheduled( PIB_Audit_Log::TRIM_CRON_HOOK );
		if ( $audit_enabled && false === $next_trim ) {
			$this->add_check(
				$checks,
				'audit_trim_schedule',
				'warning',
				'Audit log trim schedule is missing; retention cleanup is not automated.',
				array(
					'action_label' => 'Open Audit Logs',
					'action_url'   => $logs_url,
				)
			);
		} elseif ( $audit_enabled ) {
			$this->add_check( $checks, 'audit_trim_schedule', 'ok', 'Audit log trim schedule is active.' );
		} else {
			$this->add_check( $checks, 'audit_trim_schedule', 'ok', 'Audit logging disabled; trim schedule is optional.' );
		}

		$registry = $this->plugin->get_module_registry();
		if ( ! $registry instanceof PIB_Module_Registry ) {
			$this->add_check(
				$checks,
				'module_registry',
				'critical',
				'Module registry is unavailable.',
				array(
					'action_label' => 'Open Extensions',
					'action_url'   => $extensions_url,
				)
			);
		} else {
			$required           = array(
				'settings',
				'registration_verifier',
				'customer_mapping',
				'protected_access',
				'spark_launch',
				'materials',
				'backorders',
				'portal_modules',
				'audit_log',
				'operations_health',
				'session_policy',
				'admin_tools',
				'auth_intake',
				'bridge_simulator',
				'signin_link',
			);
			$registered_modules = array_map( 'sanitize_key', $registry->all_module_ids() );
			$missing_defs       = array_values( array_diff( $required, $registered_modules ) );

			if ( ! empty( $missing_defs ) ) {
				$this->add_check(
					$checks,
					'module_registry_required',
					'critical',
					'Required modules are not registered: ' . implode( ', ', $missing_defs ) . '.',
					array(
						'action_label' => 'Open Extensions',
						'action_url'   => $extensions_url,
					)
				);
			} else {
				$missing_booted = array();
				foreach ( $required as $module_id ) {
					if ( null === $registry->get( $module_id ) ) {
						$missing_booted[] = $module_id;
					}
				}

				if ( ! empty( $missing_booted ) ) {
					$this->add_check(
						$checks,
						'module_registry_boot',
						'warning',
						'Some required modules were registered but not booted: ' . implode( ', ', $missing_booted ) . '.',
						array(
							'action_label' => 'Open Extensions',
							'action_url'   => $extensions_url,
						)
					);
				} else {
					$this->add_check( $checks, 'module_registry_boot', 'ok', 'All required modules are registered and booted.' );
				}
			}
		}

		$mapping_counts = $this->mapping_counts();
		if ( isset( $mapping_counts['total'] ) && 0 === absint( $mapping_counts['total'] ) ) {
			$this->add_check(
				$checks,
				'mapping_population',
				'warning',
				'No Shopify customer mappings exist yet.',
				array(
					'action_label' => 'Open Mappings',
					'action_url'   => $mappings_url,
				)
			);
		} elseif ( isset( $mapping_counts['active'] ) && 0 === absint( $mapping_counts['active'] ) ) {
			$this->add_check(
				$checks,
				'mapping_population',
				'warning',
				'Mappings exist but none are currently active.',
				array(
					'action_label' => 'Open Mappings',
					'action_url'   => $mappings_url,
				)
			);
		} else {
			$this->add_check( $checks, 'mapping_population', 'ok', 'Active customer mappings are present.' );
		}

		if ( isset( $mapping_counts['blocked'], $mapping_counts['active'] ) && absint( $mapping_counts['blocked'] ) > absint( $mapping_counts['active'] ) ) {
			$this->add_check(
				$checks,
				'mapping_blocked_ratio',
				'warning',
				'Blocked mappings outnumber active mappings.',
				array(
					'action_label' => 'Open Failures',
					'action_url'   => $failures_url,
				)
			);
		} else {
			$this->add_check( $checks, 'mapping_blocked_ratio', 'ok', 'Blocked/active mapping ratio is within expected range.' );
		}

		if ( isset( $mapping_counts['pending_verification'] ) && absint( $mapping_counts['pending_verification'] ) > 0 ) {
			$this->add_check(
				$checks,
				'mapping_pending_verification',
				'warning',
				'Some mappings are awaiting first-time email verification.',
				array(
					'action_label' => 'Open Mappings',
					'action_url'   => $mappings_url,
				)
			);
		} else {
			$this->add_check( $checks, 'mapping_pending_verification', 'ok', 'No mappings are waiting on email verification.' );
		}

		$auth_counts = $this->auth_window_counts( 24 );
		$failures    = isset( $auth_counts['failure'] ) ? absint( $auth_counts['failure'] ) : 0;
		$successes   = isset( $auth_counts['success'] ) ? absint( $auth_counts['success'] ) : 0;
		$activity_total = $failures + $successes;
		$total       = max( 1, $failures + $successes );
		$fail_ratio  = $failures / $total;

		if ( 0 === $activity_total ) {
			$this->add_check(
				$checks,
				'auth_activity_window',
				'warning',
				'No auth handoff activity was observed in the last 24 hours.',
				array(
					'action_label' => 'Open Token Check',
					'action_url'   => $token_url,
				)
			);
		} else {
			$this->add_check(
				$checks,
				'auth_activity_window',
				'ok',
				sprintf( 'Auth handoff activity observed in the last 24 hours (%d total events).', $activity_total )
			);
		}

		if ( $failures >= 50 || ( $failures >= 20 && $fail_ratio >= 0.70 ) ) {
			$this->add_check(
				$checks,
				'auth_failure_window',
				'critical',
				'Auth failures are unusually high in the last 24 hours.',
				array(
					'action_label' => 'Open Failures',
					'action_url'   => $failures_url,
				)
			);
		} elseif ( $failures >= 10 && $fail_ratio >= 0.50 ) {
			$this->add_check(
				$checks,
				'auth_failure_window',
				'warning',
				'Auth failure ratio is elevated in the last 24 hours.',
				array(
					'action_label' => 'Open Failures',
					'action_url'   => $failures_url,
				)
			);
		} else {
			$this->add_check( $checks, 'auth_failure_window', 'ok', 'Auth success/failure pattern is within expected range.' );
		}

		$checks = apply_filters(
			'pib_operations_health_checks',
			$checks,
			$settings,
			array(
				'settings_diagnostics' => $settings_diagnostics,
				'mapping_counts'       => $mapping_counts,
				'auth_window_24h'      => $auth_counts,
			),
			$this->plugin,
			$this
		);
		$checks = $this->normalize_checks( $checks );

		$status           = $this->status_from_checks( $checks );
		$critical_count   = 0;
		$warning_count    = 0;
		$ok_count         = 0;

		foreach ( $checks as $check ) {
			$level = isset( $check['level'] ) ? sanitize_key( (string) $check['level'] ) : 'ok';
			if ( 'critical' === $level ) {
				++$critical_count;
			} elseif ( 'warning' === $level ) {
				++$warning_count;
			} else {
				++$ok_count;
			}
		}

		return array(
			'status'  => $status,
			'summary' => array(
				'critical' => $critical_count,
				'warning'  => $warning_count,
				'ok'       => $ok_count,
				'total'    => count( $checks ),
			),
			'checks'  => $checks,
		);
	}

	/**
	 * Get mapping status counters.
	 *
	 * @return array<string, int>
	 */
	private function mapping_counts() {
		$module = PIB_Customer_Mapping::instance();
		if ( ! $module instanceof PIB_Customer_Mapping ) {
			return array(
				'total'    => 0,
				'active'   => 0,
				'blocked'  => 0,
				'archived' => 0,
				'pending_verification' => 0,
			);
		}

		return array(
			'total'    => absint( $module->count_mappings() ),
			'active'   => absint( $module->count_mappings( array( 'status' => 'active' ) ) ),
			'blocked'  => absint( $module->count_mappings( array( 'status' => 'blocked' ) ) ),
			'archived' => absint( $module->count_mappings( array( 'status' => 'archived' ) ) ),
			'pending_verification' => absint( $module->count_mappings( array( 'status' => 'pending_verification' ) ) ),
		);
	}

	/**
	 * Count auth success/failure events in rolling hour window.
	 *
	 * @param int $hours Rolling-hour window.
	 *
	 * @return array<string, int>
	 */
	private function auth_window_counts( $hours ) {
		$hours = max( 1, min( 168, absint( $hours ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( HOUR_IN_SECONDS * $hours ) );

		return array(
			'window_hours' => $hours,
			'success'      => $this->count_event_since( 'auth_success', $since ),
			'failure'      => $this->count_event_since( 'auth_failure', $since ),
		);
	}

	/**
	 * Count event records from the audit table since a UTC timestamp.
	 *
	 * @param string $event_type Event type key.
	 * @param string $since_utc  UTC mysql datetime.
	 *
	 * @return int
	 */
	private function count_event_since( $event_type, $since_utc ) {
		global $wpdb;

		$table = PIB_Audit_Log::table_name();
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) {
			return 0;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s",
				$event_type,
				$since_utc
			)
		);

		return absint( $count );
	}

	/**
	 * Get next scheduled trim run as ISO8601 UTC, if any.
	 *
	 * @return string
	 */
	private function next_trim_schedule_iso8601() {
		$timestamp = wp_next_scheduled( PIB_Audit_Log::TRIM_CRON_HOOK );
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'c', (int) $timestamp );
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Table name.
	 *
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		$table_name = trim( (string) $table_name );
		if ( '' === $table_name ) {
			return false;
		}

		$like = $wpdb->esc_like( $table_name );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$like
			)
		);

		return is_string( $found ) && strtolower( $found ) === strtolower( $table_name );
	}

	/**
	 * Add check entry.
	 *
	 * @param array<int, array<string,mixed>> $checks  Checks list.
	 * @param string                          $id      Check ID.
	 * @param string                          $level   Check level.
	 * @param string                          $message Check message.
	 * @param array<string, string>           $action  Optional action metadata.
	 *
	 * @return void
	 */
	private function add_check( &$checks, $id, $level, $message, $action = array() ) {
		$check = array(
			'id'      => sanitize_key( (string) $id ),
			'level'   => sanitize_key( (string) $level ),
			'message' => sanitize_text_field( (string) $message ),
		);
		if ( is_array( $action ) ) {
			$action_label = isset( $action['action_label'] ) ? sanitize_text_field( (string) $action['action_label'] ) : '';
			$action_url   = isset( $action['action_url'] ) ? esc_url_raw( (string) $action['action_url'] ) : '';
			if ( '' !== $action_label && '' !== $action_url ) {
				$check['action_label'] = $action_label;
				$check['action_url']   = $action_url;
			}
		}
		$checks[] = $check;
	}

	/**
	 * Normalize check payload shape after extension filters.
	 *
	 * @param mixed $checks Candidate checks.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function normalize_checks( $checks ) {
		if ( ! is_array( $checks ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}

			$id      = isset( $check['id'] ) ? sanitize_key( (string) $check['id'] ) : '';
			$level   = isset( $check['level'] ) ? sanitize_key( (string) $check['level'] ) : 'ok';
			$message = isset( $check['message'] ) ? sanitize_text_field( (string) $check['message'] ) : '';

			if ( '' === $id || '' === $message ) {
				continue;
			}
			if ( ! in_array( $level, array( 'ok', 'warning', 'critical' ), true ) ) {
				$level = 'ok';
			}

			$normalized_check = array(
				'id'      => $id,
				'level'   => $level,
				'message' => $message,
			);
			$action_label = isset( $check['action_label'] ) ? sanitize_text_field( (string) $check['action_label'] ) : '';
			$action_url   = isset( $check['action_url'] ) ? esc_url_raw( (string) $check['action_url'] ) : '';
			if ( '' !== $action_label && '' !== $action_url ) {
				$normalized_check['action_label'] = $action_label;
				$normalized_check['action_url']   = $action_url;
			}
			$normalized[] = $normalized_check;
		}

		return $normalized;
	}

	/**
	 * Build Setup admin URL.
	 *
	 * @return string
	 */
	private function settings_page_url() {
		return add_query_arg(
			array(
				'page' => 'pib-settings',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build Tools admin URL.
	 *
	 * @param string $tab   Tab key.
	 * @param string $panel Optional panel key.
	 *
	 * @return string
	 */
	private function tools_panel_url( $tab = 'observability', $panel = '' ) {
		$tab   = sanitize_key( (string) $tab );
		$panel = sanitize_key( (string) $panel );
		$args  = array(
			'page' => 'pib-tools',
			'tab'  => '' !== $tab ? $tab : 'observability',
		);
		if ( '' !== $panel ) {
			$args['panel'] = $panel;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Compute top-level status from checks.
	 *
	 * @param array<int, array<string,mixed>> $checks Check entries.
	 *
	 * @return string
	 */
	private function status_from_checks( $checks ) {
		$has_warning  = false;
		$has_critical = false;

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$level = isset( $check['level'] ) ? sanitize_key( (string) $check['level'] ) : 'ok';
			if ( 'critical' === $level ) {
				$has_critical = true;
				break;
			}
			if ( 'warning' === $level ) {
				$has_warning = true;
			}
		}

		if ( $has_critical ) {
			return 'action_needed';
		}
		if ( $has_warning ) {
			return 'warnings';
		}

		return 'healthy';
	}
}
