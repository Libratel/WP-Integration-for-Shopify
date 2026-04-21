<?php
/**
 * Audit logging module.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists structured plugin events for diagnostics and audits.
 */
final class PIB_Audit_Log {
	/**
	 * Cron hook used for retention cleanup.
	 */
	const TRIM_CRON_HOOK = 'pib_audit_log_trim_cron';

	/**
	 * Singleton.
	 *
	 * @var PIB_Audit_Log|null
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
	 * @return PIB_Audit_Log|null
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
	 * Get audit log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pib_event_log';
	}

	/**
	 * Build object and register event hooks.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( self::TRIM_CRON_HOOK, array( $this, 'run_scheduled_trim' ) );
		add_action( 'pib_auth_intake_success', array( $this, 'log_auth_success' ), 10, 3 );
		add_action( 'pib_auth_intake_failure', array( $this, 'log_auth_failure' ), 10, 4 );
		add_action( 'pib_backorders_view', array( $this, 'log_backorders_view' ), 10, 4 );
		add_action( 'pib_backorders_export', array( $this, 'log_backorders_export' ), 10, 4 );
		add_action( 'pib_backorders_error', array( $this, 'log_backorders_error' ), 10, 4 );
		add_action( 'pib_material_download', array( $this, 'log_material_download' ), 10, 3 );
		add_action( 'pib_mapping_upsert', array( $this, 'log_mapping_upsert' ), 10, 2 );
		add_action( 'pib_mapping_resolution_denied', array( $this, 'log_mapping_resolution_denied' ), 10, 2 );
		add_action( 'pib_logout_requested', array( $this, 'log_logout_requested' ), 10, 2 );
		add_action( 'pib_logout_completed', array( $this, 'log_logout_completed' ), 10, 3 );
		add_action( 'pib_protected_access_denied', array( $this, 'log_protected_access_denied' ), 10, 3 );
		add_action( 'pib_session_expired', array( $this, 'log_session_expired' ), 10, 3 );

		self::ensure_trim_schedule();
	}

	/**
	 * Insert audit log row.
	 *
	 * @param string               $event_type Event type key.
	 * @param string               $level      Severity level.
	 * @param string               $message    Human-readable message.
	 * @param array<string, mixed> $context    Optional context payload.
	 *
	 * @return void
	 */
	public function log( $event_type, $level, $message, $context = array() ) {
		$settings = $this->plugin->get_settings();
		if ( empty( $settings['audit_logging_enabled'] ) ) {
			return;
		}

		global $wpdb;

		$event_type = sanitize_key( (string) $event_type );
		$level      = sanitize_key( (string) $level );
		$message    = sanitize_text_field( (string) $message );

		if ( '' === $event_type ) {
			return;
		}
		if ( '' === $level ) {
			$level = 'info';
		}

		$context = is_array( $context ) ? $context : array();
		$context = $this->sanitize_context( $context );

		$user_id  = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$customer = isset( $context['shopify_customer_id'] ) ? sanitize_text_field( (string) $context['shopify_customer_id'] ) : '';
		$company  = isset( $context['company_id'] ) ? sanitize_text_field( (string) $context['company_id'] ) : '';
		$ip       = PIB_Security::client_ip();

		$wpdb->insert(
			self::table_name(),
			array(
				'event_type'           => $event_type,
				'level'                => $level,
				'message'              => $message,
				'context'              => wp_json_encode( $context ),
				'user_id'              => $user_id > 0 ? $user_id : null,
				'shopify_customer_id'  => '' !== $customer ? $customer : null,
				'company_id'           => '' !== $company ? $company : null,
				'ip_address'           => '' !== $ip ? $ip : null,
				'created_at'           => current_time( 'mysql', 1 ),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

	}

	/**
	 * Ensure scheduled retention cleanup exists.
	 *
	 * @return void
	 */
	public static function ensure_trim_schedule() {
		if ( wp_next_scheduled( self::TRIM_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::TRIM_CRON_HOOK );
	}

	/**
	 * Remove scheduled retention cleanup tasks.
	 *
	 * @return void
	 */
	public static function clear_trim_schedule() {
		$timestamp = wp_next_scheduled( self::TRIM_CRON_HOOK );
		while ( false !== $timestamp ) {
			$result = wp_unschedule_event( $timestamp, self::TRIM_CRON_HOOK );
			if ( false === $result ) {
				break;
			}
			$timestamp = wp_next_scheduled( self::TRIM_CRON_HOOK );
		}
	}

	/**
	 * Run retention cleanup from cron.
	 *
	 * @return void
	 */
	public function run_scheduled_trim() {
		$settings = $this->plugin->get_settings();
		$this->trim_logs_if_needed( (int) $settings['audit_log_retention_days'] );
	}

	/**
	 * Retrieve logs for admin diagnostics view.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'level'  => '',
				'event'  => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$limit  = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset = max( 0, absint( $args['offset'] ) );
		$search = sanitize_text_field( (string) $args['search'] );
		$level  = sanitize_key( (string) $args['level'] );
		$event  = sanitize_key( (string) $args['event'] );

		$where   = '1=1';
		$params  = array();

		if ( '' !== $level ) {
			$where   .= ' AND level = %s';
			$params[] = $level;
		}
		if ( '' !== $event ) {
			$where   .= ' AND event_type = %s';
			$params[] = $event;
		}
		if ( '' !== $search ) {
			$where   .= ' AND (message LIKE %s OR context LIKE %s OR shopify_customer_id LIKE %s OR company_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM " . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$query = $wpdb->prepare( $sql, $params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return $this->decode_log_rows( $rows );
	}

	/**
	 * Retrieve mapping failure/unmatched records for admin tooling.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_mapping_failures( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'reason' => '',
				'source' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$limit  = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset = max( 0, absint( $args['offset'] ) );
		$search = sanitize_text_field( (string) $args['search'] );
		$reason = sanitize_key( (string) $args['reason'] );
		$source = sanitize_key( (string) $args['source'] );

		$allowed_sources = array( 'mapping_resolution_denied', 'auth_failure' );
		$where           = "event_type IN ('mapping_resolution_denied','auth_failure')";
		$params          = array();

		if ( in_array( $source, $allowed_sources, true ) ) {
			$where   .= ' AND event_type = %s';
			$params[] = $source;
		}
		if ( '' !== $reason ) {
			$where   .= ' AND (context LIKE %s OR context LIKE %s)';
			$params[] = '%"reason":"' . $wpdb->esc_like( $reason ) . '"%';
			$params[] = '%"error_code":"' . $wpdb->esc_like( $reason ) . '"%';
		}
		if ( '' !== $search ) {
			$where   .= ' AND (message LIKE %s OR context LIKE %s OR shopify_customer_id LIKE %s OR company_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM " . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$query = $wpdb->prepare( $sql, $params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return $this->decode_log_rows( $rows );
	}

	/**
	 * Count logs for current filters.
	 *
	 * @param array<string, mixed> $args Filter args.
	 *
	 * @return int
	 */
	public function count_logs( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'level'  => '',
				'event'  => '',
			)
		);

		$search = sanitize_text_field( (string) $args['search'] );
		$level  = sanitize_key( (string) $args['level'] );
		$event  = sanitize_key( (string) $args['event'] );

		$where  = '1=1';
		$params = array();

		if ( '' !== $level ) {
			$where   .= ' AND level = %s';
			$params[] = $level;
		}
		if ( '' !== $event ) {
			$where   .= ' AND event_type = %s';
			$params[] = $event;
		}
		if ( '' !== $search ) {
			$where   .= ' AND (message LIKE %s OR context LIKE %s OR shopify_customer_id LIKE %s OR company_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql   = "SELECT COUNT(*) FROM " . self::table_name() . " WHERE {$where}";
		$query = $wpdb->prepare( $sql, $params );
		$count = $wpdb->get_var( $query );

		return absint( $count );
	}

	/**
	 * Count mapping failure/unmatched records.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return int
	 */
	public function count_mapping_failures( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'reason' => '',
				'source' => '',
			)
		);

		$search = sanitize_text_field( (string) $args['search'] );
		$reason = sanitize_key( (string) $args['reason'] );
		$source = sanitize_key( (string) $args['source'] );

		$allowed_sources = array( 'mapping_resolution_denied', 'auth_failure' );
		$where           = "event_type IN ('mapping_resolution_denied','auth_failure')";
		$params          = array();

		if ( in_array( $source, $allowed_sources, true ) ) {
			$where   .= ' AND event_type = %s';
			$params[] = $source;
		}
		if ( '' !== $reason ) {
			$where   .= ' AND (context LIKE %s OR context LIKE %s)';
			$params[] = '%"reason":"' . $wpdb->esc_like( $reason ) . '"%';
			$params[] = '%"error_code":"' . $wpdb->esc_like( $reason ) . '"%';
		}
		if ( '' !== $search ) {
			$where   .= ' AND (message LIKE %s OR context LIKE %s OR shopify_customer_id LIKE %s OR company_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql   = "SELECT COUNT(*) FROM " . self::table_name() . " WHERE {$where}";
		$query = $wpdb->prepare( $sql, $params );
		$count = $wpdb->get_var( $query );

		return absint( $count );
	}

	/**
	 * Delete all logs.
	 *
	 * @return void
	 */
	public function clear_logs() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table_name() );
	}

	/**
	 * Trim old logs by retention policy.
	 *
	 * @param int $retention_days Retention window in days.
	 *
	 * @return void
	 */
	private function trim_logs_if_needed( $retention_days ) {
		global $wpdb;

		$retention_days = max( 1, min( 3650, absint( $retention_days ) ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days ) );

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
				$cutoff
			)
		);
	}

	/**
	 * Sanitize nested context payload.
	 *
	 * @param array<string, mixed> $context Raw context.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize_context( $context ) {
		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = is_bool( $value ) ? ( $value ? '1' : '0' ) : sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Decode log rows and append `context_decoded`.
	 *
	 * @param mixed $rows Query result rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decode_log_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['context_decoded'] = array();
			if ( isset( $row['context'] ) && is_string( $row['context'] ) && '' !== $row['context'] ) {
				$decoded = json_decode( $row['context'], true );
				if ( is_array( $decoded ) ) {
					$row['context_decoded'] = $decoded;
				}
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Hook: auth intake success.
	 *
	 * @param array<string, mixed> $claims Verified claims.
	 * @param int                  $user_id User ID.
	 * @param string               $dest Destination.
	 *
	 * @return void
	 */
	public function log_auth_success( $claims, $user_id, $dest ) {
		$this->log(
			'auth_success',
			'info',
			'Bridge auth succeeded.',
			array(
				'user_id'             => absint( $user_id ),
				'destination'         => (string) $dest,
				'shopify_customer_id' => PIB_Customer_Mapping::customer_id_from_claims( $claims ),
				'company_id'          => PIB_Customer_Mapping::company_id_from_claims( $claims ),
			)
		);
	}

	/**
	 * Hook: auth intake failure.
	 *
	 * @param string               $code Failure code.
	 * @param string               $message Failure message.
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $claims Claim context.
	 *
	 * @return void
	 */
	public function log_auth_failure( $code, $message, $status, $claims ) {
		$this->log(
			'auth_failure',
			'warning',
			'Bridge auth failed.',
			array(
				'error_code'          => (string) $code,
				'error_message'       => (string) $message,
				'status'              => absint( $status ),
				'shopify_customer_id' => PIB_Customer_Mapping::customer_id_from_claims( is_array( $claims ) ? $claims : array() ),
				'company_id'          => PIB_Customer_Mapping::company_id_from_claims( is_array( $claims ) ? $claims : array() ),
			)
		);
	}

	/**
	 * Hook: mapping update.
	 *
	 * @param array<string, mixed> $mapping Mapping context.
	 * @param string               $action Action label.
	 *
	 * @return void
	 */
	public function log_mapping_upsert( $mapping, $action ) {
		if ( ! is_array( $mapping ) ) {
			return;
		}

		$this->log(
			'mapping_' . sanitize_key( (string) $action ),
			'info',
			'Customer mapping updated.',
			array(
				'user_id'             => isset( $mapping['wp_user_id'] ) ? absint( $mapping['wp_user_id'] ) : 0,
				'mapping_id'          => isset( $mapping['id'] ) ? absint( $mapping['id'] ) : 0,
				'shopify_customer_id' => isset( $mapping['shopify_customer_id'] ) ? (string) $mapping['shopify_customer_id'] : '',
				'company_id'          => isset( $mapping['company_id'] ) ? (string) $mapping['company_id'] : '',
			)
		);
	}

	/**
	 * Hook: mapping resolution denied.
	 *
	 * @param string               $reason  Denial reason.
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return void
	 */
	public function log_mapping_resolution_denied( $reason, $context ) {
		$payload = is_array( $context ) ? $context : array();
		$payload['reason'] = sanitize_key( (string) $reason );

		$this->log(
			'mapping_resolution_denied',
			'warning',
			'Customer mapping resolution denied.',
			$payload
		);
	}

	/**
	 * Hook: explicit logout requested.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $context Logout context.
	 *
	 * @return void
	 */
	public function log_logout_requested( $user_id, $context ) {
		$payload = is_array( $context ) ? $context : array();
		$payload['user_id'] = absint( $user_id );

		$this->log(
			'logout_requested',
			'info',
			'Portal logout requested.',
			$payload
		);
	}

	/**
	 * Hook: explicit logout completed.
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $mode    Logout mode (local/coordinated).
	 * @param array<string, mixed> $context Logout context.
	 *
	 * @return void
	 */
	public function log_logout_completed( $user_id, $mode, $context ) {
		$payload = is_array( $context ) ? $context : array();
		$payload['user_id'] = absint( $user_id );
		$mode               = sanitize_key( (string) $mode );
		if ( '' === $mode ) {
			$mode = 'local';
		}
		$payload['mode'] = $mode;

		$this->log(
			'logout_completed',
			'info',
			'Portal logout completed.',
			$payload
		);
	}

	/**
	 * Hook: backorders viewed.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $identity Identity context.
	 * @param int                  $count Row count.
	 * @param array<string, mixed> $filters Active filters.
	 *
	 * @return void
	 */
	public function log_backorders_view( $user_id, $identity, $count, $filters ) {
		$this->log(
			'backorders_view',
			'info',
			'Backorders viewed.',
			array(
				'user_id'             => absint( $user_id ),
				'count'               => absint( $count ),
				'shopify_customer_id' => isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
				'company_id'          => isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
				'status_filter'       => isset( $filters['status'] ) ? (string) $filters['status'] : '',
				'limit'               => isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0,
			)
		);
	}

	/**
	 * Hook: backorders export.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $identity Identity context.
	 * @param int                  $count Row count.
	 * @param array<string, mixed> $filters Active filters.
	 *
	 * @return void
	 */
	public function log_backorders_export( $user_id, $identity, $count, $filters ) {
		$this->log(
			'backorders_export',
			'info',
			'Backorders exported.',
			array(
				'user_id'             => absint( $user_id ),
				'count'               => absint( $count ),
				'shopify_customer_id' => isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
				'company_id'          => isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
				'status_filter'       => isset( $filters['status'] ) ? (string) $filters['status'] : '',
				'limit'               => isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0,
			)
		);
	}

	/**
	 * Hook: backorders error.
	 *
	 * @param string               $code Error code.
	 * @param string               $message Error message.
	 * @param array<string, mixed> $identity Identity context.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return void
	 */
	public function log_backorders_error( $code, $message, $identity, $filters ) {
		$this->log(
			'backorders_error',
			'error',
			'Backorders load/export failed.',
			array(
				'error_code'          => (string) $code,
				'error_message'       => (string) $message,
				'shopify_customer_id' => isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
				'company_id'          => isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
				'status_filter'       => isset( $filters['status'] ) ? (string) $filters['status'] : '',
				'limit'               => isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0,
			)
		);
	}

	/**
	 * Hook: material download.
	 *
	 * @param int                  $user_id User ID.
	 * @param int                  $material_id Material ID.
	 * @param array<string, mixed> $identity Identity context.
	 *
	 * @return void
	 */
	public function log_material_download( $user_id, $material_id, $identity ) {
		$this->log(
			'material_download',
			'info',
			'Partner material downloaded.',
			array(
				'user_id'             => absint( $user_id ),
				'material_id'         => absint( $material_id ),
				'shopify_customer_id' => isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
				'company_id'          => isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
			)
		);
	}

	/**
	 * Hook: protected route denied.
	 *
	 * @param string $reason Reason code.
	 * @param string $destination Requested destination.
	 * @param int    $user_id User ID.
	 *
	 * @return void
	 */
	public function log_protected_access_denied( $reason, $destination, $user_id ) {
		$this->log(
			'protected_access_denied',
			'warning',
			'Protected route access denied.',
			array(
				'user_id'     => absint( $user_id ),
				'reason'      => (string) $reason,
				'destination' => (string) $destination,
			)
		);
	}

	/**
	 * Hook: session expiration event.
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $reason Reason code.
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return void
	 */
	public function log_session_expired( $user_id, $reason, $context ) {
		$payload = is_array( $context ) ? $context : array();
		$payload['user_id'] = absint( $user_id );
		$payload['reason']  = (string) $reason;

		$this->log(
			'session_expired',
			'warning',
			'Managed session expired or invalidated.',
			$payload
		);
	}
}
