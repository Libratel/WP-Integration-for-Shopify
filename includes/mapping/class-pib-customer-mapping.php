<?php
/**
 * Shopify customer to WordPress user mapping.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapping store and identity-resolution logic.
 */
final class PIB_Customer_Mapping {
	/**
	 * Active mapping ID user meta key.
	 */
	const META_ACTIVE_MAPPING_ID = 'pib_active_mapping_id';

	/**
	 * Singleton.
	 *
	 * @var PIB_Customer_Mapping|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Request-local identity context cache keyed by user ID.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $identity_context_cache = array();

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Customer_Mapping|null
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
	 * Get mapping table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pib_customer_map';
	}

	/**
	 * Build object and register hooks.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'pib_resolve_user_from_claims', array( $this, 'resolve_user_from_claims' ), 10, 2 );
	}

	/**
	 * Normalize customer ID from claims payload.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 *
	 * @return string
	 */
	public static function customer_id_from_claims( $claims ) {
		$raw = '';
		if ( isset( $claims['shopify_customer_id'] ) ) {
			$raw = (string) $claims['shopify_customer_id'];
		} elseif ( isset( $claims['customer_id'] ) ) {
			$raw = (string) $claims['customer_id'];
		}

		return trim( sanitize_text_field( wp_unslash( $raw ) ) );
	}

	/**
	 * Normalize company ID from claims payload.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 *
	 * @return string
	 */
	public static function company_id_from_claims( $claims ) {
		$raw = isset( $claims['company_id'] ) ? (string) $claims['company_id'] : '';
		return trim( sanitize_text_field( wp_unslash( $raw ) ) );
	}

	/**
	 * Normalize customer email from claims payload.
	 *
	 * Accepts `email` and common bridge aliases.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 *
	 * @return string
	 */
	public static function email_from_claims( $claims ) {
		$claims = is_array( $claims ) ? $claims : array();
		$keys   = array( 'email', 'customer_email', 'shopify_email' );

		foreach ( $keys as $key ) {
			if ( ! isset( $claims[ $key ] ) ) {
				continue;
			}

			$email = sanitize_email( (string) $claims[ $key ] );
			if ( '' !== $email && is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Resolve/auto-link WP user from stable customer claims.
	 *
	 * @param WP_User|false         $resolved_user Existing resolved user from prior logic.
	 * @param array<string, mixed>  $claims        Verified token claims.
	 *
	 * @return WP_User|false
	 */
	public function resolve_user_from_claims( $resolved_user, $claims ) {
		$settings            = $this->plugin->get_settings();
		$shopify_customer_id = self::customer_id_from_claims( $claims );
		$company_id          = self::company_id_from_claims( $claims );
		$email               = self::email_from_claims( $claims );
		$resolution_action   = 'upsert';

		if ( '' === $shopify_customer_id ) {
			return $resolved_user;
		}

		$mapping = $this->find_by_customer_company( $shopify_customer_id, $company_id );
		if ( $mapping ) {
			$status = isset( $mapping['status'] ) ? sanitize_key( (string) $mapping['status'] ) : '';
			if ( 'active' !== $status ) {
				$this->emit_mapping_resolution_denied(
					'mapping_not_active',
					array(
						'mapping_id'          => isset( $mapping['id'] ) ? absint( $mapping['id'] ) : 0,
						'shopify_customer_id' => $shopify_customer_id,
						'company_id'          => $company_id,
						'status'              => $status,
					)
				);
				return false;
			}

			$mapped_user_id = isset( $mapping['wp_user_id'] ) ? absint( $mapping['wp_user_id'] ) : 0;
			if ( $mapped_user_id > 0 ) {
				$user = get_user_by( 'id', $mapped_user_id );
				if ( $user instanceof WP_User ) {
					if ( ! $this->sync_user_email_to_claims( $user, $email, $shopify_customer_id, $company_id ) ) {
						return false;
					}

					$this->touch_mapping( (int) $mapping['id'], $email );
					$this->set_active_mapping_for_user( $user->ID, (int) $mapping['id'] );
					do_action( 'pib_mapping_upsert', $mapping, 'touch' );
					return $user;
				}
			}

			$resolution_action = 'recover_mapping';
		}

		$user = false;
		if ( $resolved_user instanceof WP_User ) {
			if ( $this->user_has_conflicting_customer_mappings( $resolved_user->ID, $shopify_customer_id ) ) {
				$this->emit_mapping_resolution_denied(
					'hint_user_conflict',
					array(
						'user_id'             => $resolved_user->ID,
						'shopify_customer_id' => $shopify_customer_id,
						'company_id'          => $company_id,
					)
				);
				return false;
			}

			$user              = $resolved_user;
			$resolution_action = 'resolved_hint';
		}

		$auto_link_by_email = ! empty( $settings['auto_link_existing_users_by_email'] );
		$auto_create        = ! empty( $settings['auto_create_users'] );

		if ( ! $user && $auto_link_by_email && '' !== $email ) {
			$email_link_user = $this->resolve_guarded_email_link_user( $email, $shopify_customer_id );
			if ( is_wp_error( $email_link_user ) ) {
				$this->emit_mapping_resolution_denied(
					$email_link_user->get_error_code(),
					array(
						'email'               => $email,
						'shopify_customer_id' => $shopify_customer_id,
						'company_id'          => $company_id,
					)
				);
				return false;
			}
			if ( $email_link_user instanceof WP_User ) {
				$user              = $email_link_user;
				$resolution_action = 'email_link';
			}
		}

		if ( ! $user && '' === $email && ( $auto_link_by_email || $auto_create ) ) {
			$this->emit_mapping_resolution_denied(
				'missing_email_claim',
				array(
					'shopify_customer_id' => $shopify_customer_id,
					'company_id'          => $company_id,
				)
			);
			return false;
		}

		if ( ! $user && $auto_create ) {
			$user = $this->create_wordpress_user_from_claims( $claims, (string) $settings['new_user_role'] );
			if ( $user instanceof WP_User ) {
				$resolution_action = 'auto_create';
			}
		}

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( $this->user_has_conflicting_customer_mappings( $user->ID, $shopify_customer_id ) ) {
			$this->emit_mapping_resolution_denied(
				'user_conflicting_customer_mapping',
				array(
					'user_id'             => $user->ID,
					'shopify_customer_id' => $shopify_customer_id,
					'company_id'          => $company_id,
				)
			);
			return false;
		}

		if ( ! $this->sync_user_email_to_claims( $user, $email, $shopify_customer_id, $company_id ) ) {
			return false;
		}

		$mapping_id = $this->upsert_mapping(
			$shopify_customer_id,
			$company_id,
			$user->ID,
			$email
		);

		if ( $mapping_id <= 0 ) {
			$this->emit_mapping_resolution_denied(
				'mapping_upsert_failed',
				array(
					'user_id'             => $user->ID,
					'shopify_customer_id' => $shopify_customer_id,
					'company_id'          => $company_id,
				)
			);
			return false;
		}

		$this->set_active_mapping_for_user( $user->ID, $mapping_id );
		$mapping = $this->find_by_id( $mapping_id );
		if ( $mapping ) {
			do_action( 'pib_mapping_upsert', $mapping, $resolution_action );
		}

		return $user;
	}

	/**
	 * Resolve email-based link target with conflict guards.
	 *
	 * @param string $email               Customer email claim.
	 * @param string $shopify_customer_id Shopify customer ID.
	 *
	 * @return WP_User|WP_Error|false
	 */
	private function resolve_guarded_email_link_user( $email, $shopify_customer_id ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( $this->user_has_conflicting_customer_mappings( $user->ID, $shopify_customer_id ) ) {
			return new WP_Error(
				'pib_email_link_conflict',
				'Existing user email belongs to a different active Shopify customer mapping.'
			);
		}

		return $user;
	}

	/**
	 * Check if user has active mappings for a different Shopify customer.
	 *
	 * @param int    $wp_user_id          WordPress user ID.
	 * @param string $shopify_customer_id Shopify customer ID.
	 *
	 * @return bool
	 */
	private function user_has_conflicting_customer_mappings( $wp_user_id, $shopify_customer_id ) {
		global $wpdb;

		$wp_user_id          = absint( $wp_user_id );
		$shopify_customer_id = trim( (string) $shopify_customer_id );

		if ( $wp_user_id <= 0 || '' === $shopify_customer_id ) {
			return false;
		}

		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE wp_user_id = %d AND status = %s AND shopify_customer_id <> %s",
				$wp_user_id,
				'active',
				$shopify_customer_id
			)
		);

		return absint( $count ) > 0;
	}

	/**
	 * Emit guarded mapping resolution failure event for observability.
	 *
	 * @param string               $reason  Failure reason key.
	 * @param array<string, mixed> $context Additional context.
	 *
	 * @return void
	 */
	private function emit_mapping_resolution_denied( $reason, $context = array() ) {
		$payload            = is_array( $context ) ? $context : array();
		$payload['reason']  = sanitize_key( (string) $reason );
		$payload['user_id'] = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0;

		do_action( 'pib_mapping_resolution_denied', $payload['reason'], $payload );
	}

	/**
	 * Get active identity context for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_active_identity_context( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $user_id, $this->identity_context_cache ) ) {
			return $this->identity_context_cache[ $user_id ];
		}

		$mapping_id = absint( get_user_meta( $user_id, self::META_ACTIVE_MAPPING_ID, true ) );
		if ( $mapping_id <= 0 ) {
			$this->identity_context_cache[ $user_id ] = null;
			return null;
		}

		$mapping = $this->find_by_id( $mapping_id );
		if ( ! $mapping ) {
			$this->identity_context_cache[ $user_id ] = null;
			return null;
		}

		if ( absint( $mapping['wp_user_id'] ) !== $user_id ) {
			$this->identity_context_cache[ $user_id ] = null;
			return null;
		}

		if ( 'active' !== (string) $mapping['status'] ) {
			$this->identity_context_cache[ $user_id ] = null;
			return null;
		}

		$context = array(
			'mapping_id'           => absint( $mapping['id'] ),
			'wp_user_id'           => absint( $mapping['wp_user_id'] ),
			'shopify_customer_id'  => (string) $mapping['shopify_customer_id'],
			'company_id'           => (string) $mapping['company_id'],
			'email'                => (string) $mapping['email'],
			'status'               => (string) $mapping['status'],
		);

		$this->identity_context_cache[ $user_id ] = $context;

		return $context;
	}

	/**
	 * Determine if current user context matches required scope.
	 *
	 * @param string      $shopify_customer_id Required customer ID.
	 * @param string|null $company_id          Optional required company ID.
	 *
	 * @return bool
	 */
	public function current_user_matches_scope( $shopify_customer_id, $company_id = null ) {
		$user_id  = get_current_user_id();
		$context  = $this->get_active_identity_context( $user_id );
		$customer = trim( (string) $shopify_customer_id );

		if ( ! $context || '' === $customer ) {
			return false;
		}

		if ( $customer !== (string) $context['shopify_customer_id'] ) {
			return false;
		}

		if ( null !== $company_id && '' !== (string) $company_id ) {
			return (string) $company_id === (string) $context['company_id'];
		}

		return true;
	}

	/**
	 * Query mappings for admin tools.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function query_mappings( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'status' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$search = sanitize_text_field( (string) $args['search'] );
		$status = sanitize_key( (string) $args['status'] );
		$limit  = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset = max( 0, absint( $args['offset'] ) );

		$where  = '1=1';
		$params = array();

		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where   .= ' AND (shopify_customer_id LIKE %s OR company_id LIKE %s OR email LIKE %s OR CAST(wp_user_id AS CHAR) LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$query = $wpdb->prepare( $sql, $params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count mappings for admin tools.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return int
	 */
	public function count_mappings( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'search' => '',
				'status' => '',
			)
		);

		$search = sanitize_text_field( (string) $args['search'] );
		$status = sanitize_key( (string) $args['status'] );

		$where  = '1=1';
		$params = array();

		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where   .= ' AND (shopify_customer_id LIKE %s OR company_id LIKE %s OR email LIKE %s OR CAST(wp_user_id AS CHAR) LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql   = 'SELECT COUNT(*) FROM ' . self::table_name() . " WHERE {$where}";
		$query = $wpdb->prepare( $sql, $params );
		$count = $wpdb->get_var( $query );

		return absint( $count );
	}

	/**
	 * Fetch mapping by ID for admin operations.
	 *
	 * @param int $mapping_id Mapping ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_mapping( $mapping_id ) {
		return $this->find_by_id( $mapping_id );
	}

	/**
	 * Create or update a mapping from admin tools.
	 *
	 * @param string $shopify_customer_id Shopify customer ID.
	 * @param string $company_id          Company/account ID.
	 * @param int    $wp_user_id          WordPress user ID.
	 * @param string $email               Optional email.
	 * @param string $status              Desired status.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function admin_upsert_mapping( $shopify_customer_id, $company_id, $wp_user_id, $email = '', $status = 'active' ) {
		$shopify_customer_id = trim( sanitize_text_field( (string) $shopify_customer_id ) );
		$company_id          = trim( sanitize_text_field( (string) $company_id ) );
		$wp_user_id          = absint( $wp_user_id );
		$email               = sanitize_email( (string) $email );
		$status              = sanitize_key( (string) $status );

		if ( '' === $shopify_customer_id ) {
			return new WP_Error( 'pib_admin_missing_customer_id', 'Shopify customer ID is required.' );
		}
		if ( $wp_user_id <= 0 ) {
			return new WP_Error( 'pib_admin_invalid_user', 'A valid WordPress user is required.' );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'pib_admin_user_not_found', 'WordPress user could not be found.' );
		}

		if ( $this->user_has_conflicting_customer_mappings( $wp_user_id, $shopify_customer_id ) ) {
			return new WP_Error(
				'pib_admin_user_conflict',
				'Selected WordPress user is actively mapped to a different Shopify customer.'
			);
		}

		$mapping_id = $this->upsert_mapping(
			$shopify_customer_id,
			$company_id,
			$wp_user_id,
			$email
		);

		if ( $mapping_id <= 0 ) {
			return new WP_Error( 'pib_admin_mapping_upsert_failed', 'Unable to create or update mapping record.' );
		}

		if ( ! in_array( $status, array( 'active', 'blocked', 'archived' ), true ) ) {
			$status = 'active';
		}
		if ( 'active' !== $status ) {
			$this->update_mapping(
				$mapping_id,
				array(
					'status' => $status,
				)
			);
		}

		$mapping = $this->find_by_id( $mapping_id );
		if ( ! $mapping ) {
			return new WP_Error( 'pib_admin_mapping_missing', 'Mapping record could not be loaded after update.' );
		}

		if ( 'active' === $status ) {
			do_action( 'pib_mapping_upsert', $mapping, 'admin_upsert' );
		}

		return $mapping;
	}

	/**
	 * Update mapping record from admin tool actions.
	 *
	 * @param int                  $mapping_id Mapping ID.
	 * @param array<string, mixed> $fields     Updatable fields.
	 *
	 * @return bool
	 */
	public function update_mapping( $mapping_id, $fields ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 || ! is_array( $fields ) ) {
			return false;
		}

		$data   = array();
		$format = array();

		if ( isset( $fields['status'] ) ) {
			$status = sanitize_key( (string) $fields['status'] );
			if ( in_array( $status, array( 'active', 'blocked', 'archived' ), true ) ) {
				$data['status'] = $status;
				$format[]       = '%s';
			}
		}

		if ( isset( $fields['wp_user_id'] ) ) {
			$wp_user_id = absint( $fields['wp_user_id'] );
			if ( $wp_user_id > 0 && get_user_by( 'id', $wp_user_id ) instanceof WP_User ) {
				$data['wp_user_id'] = $wp_user_id;
				$format[]           = '%d';
			}
		}

		if ( isset( $fields['email'] ) ) {
			$email = sanitize_email( (string) $fields['email'] );
			$data['email'] = $email;
			$format[]      = '%s';
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', 1 );
		$format[]           = '%s';

		$result = $wpdb->update(
			self::table_name(),
			$data,
			array( 'id' => $mapping_id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$mapping = $this->find_by_id( $mapping_id );
		if ( $mapping ) {
			do_action( 'pib_mapping_upsert', $mapping, 'admin_update' );
		}

		return true;
	}

	/**
	 * Find mapping record by mapping ID.
	 *
	 * @param int $mapping_id Mapping ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_by_id( $mapping_id ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$mapping_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find mapping by customer and company IDs.
	 *
	 * @param string $shopify_customer_id Shopify customer ID.
	 * @param string $company_id          Company/account ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_by_customer_company( $shopify_customer_id, $company_id ) {
		global $wpdb;

		$shopify_customer_id = trim( (string) $shopify_customer_id );
		$company_id          = trim( (string) $company_id );

		if ( '' === $shopify_customer_id ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE shopify_customer_id = %s AND company_id = %s LIMIT 1",
				$shopify_customer_id,
				$company_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or update mapping row and return mapping ID.
	 *
	 * @param string $shopify_customer_id Shopify customer ID.
	 * @param string $company_id          Company/account ID.
	 * @param int    $wp_user_id          WordPress user ID.
	 * @param string $email               Email.
	 *
	 * @return int
	 */
	private function upsert_mapping( $shopify_customer_id, $company_id, $wp_user_id, $email ) {
		global $wpdb;

		$shopify_customer_id = trim( (string) $shopify_customer_id );
		$company_id          = trim( (string) $company_id );
		$wp_user_id          = absint( $wp_user_id );
		$email               = sanitize_email( (string) $email );

		if ( '' === $shopify_customer_id || $wp_user_id <= 0 ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', 1 );

		$sql = "
			INSERT INTO {$table} (
				shopify_customer_id,
				company_id,
				wp_user_id,
				email,
				status,
				created_at,
				updated_at,
				last_seen_at
			)
			VALUES (%s, %s, %d, %s, 'active', %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				wp_user_id = VALUES(wp_user_id),
				email = VALUES(email),
				updated_at = VALUES(updated_at),
				last_seen_at = VALUES(last_seen_at)
		";

		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$shopify_customer_id,
				$company_id,
				$wp_user_id,
				$email,
				$now,
				$now,
				$now
			)
		);

		$mapping = $this->find_by_customer_company( $shopify_customer_id, $company_id );
		if ( ! $mapping ) {
			return 0;
		}

		return absint( $mapping['id'] );
	}

	/**
	 * Mark mapping as recently seen.
	 *
	 * @param int    $mapping_id Mapping ID.
	 * @param string $email      Optional email update.
	 *
	 * @return void
	 */
	private function touch_mapping( $mapping_id, $email = '' ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 ) {
			return;
		}

		$data = array(
			'updated_at'  => current_time( 'mysql', 1 ),
			'last_seen_at' => current_time( 'mysql', 1 ),
		);

		$format = array( '%s', '%s' );

		$email = sanitize_email( (string) $email );
		if ( '' !== $email ) {
			$data['email'] = $email;
			$format[]      = '%s';
		}

		$wpdb->update(
			self::table_name(),
			$data,
			array( 'id' => $mapping_id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Persist active mapping for session-bound authorization checks.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @param int $mapping_id Mapping ID.
	 *
	 * @return void
	 */
	private function set_active_mapping_for_user( $wp_user_id, $mapping_id ) {
		$wp_user_id = absint( $wp_user_id );
		$mapping_id = absint( $mapping_id );

		if ( $wp_user_id <= 0 || $mapping_id <= 0 ) {
			return;
		}

		update_user_meta( $wp_user_id, self::META_ACTIVE_MAPPING_ID, $mapping_id );
		unset( $this->identity_context_cache[ $wp_user_id ] );
	}

	/**
	 * Sync WordPress user email to verified Shopify claim when needed.
	 *
	 * @param WP_User $user                Target user.
	 * @param string  $email               Verified email claim.
	 * @param string  $shopify_customer_id Shopify customer ID.
	 * @param string  $company_id          Shopify company/account ID.
	 *
	 * @return bool
	 */
	private function sync_user_email_to_claims( WP_User $user, $email, $shopify_customer_id, $company_id ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return true;
		}

		$current_email = sanitize_email( (string) $user->user_email );
		if ( '' !== $current_email && 0 === strcasecmp( $current_email, $email ) ) {
			return true;
		}

		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user instanceof WP_User && $existing_user->ID !== $user->ID ) {
			$this->emit_mapping_resolution_denied(
				'email_sync_conflict',
				array(
					'user_id'             => $user->ID,
					'conflict_user_id'    => $existing_user->ID,
					'shopify_customer_id' => $shopify_customer_id,
					'company_id'          => $company_id,
					'email'               => $email,
				)
			);
			return false;
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $updated ) ) {
			$this->emit_mapping_resolution_denied(
				'email_sync_failed',
				array(
					'user_id'             => $user->ID,
					'shopify_customer_id' => $shopify_customer_id,
					'company_id'          => $company_id,
					'email'               => $email,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Get preferred role for auto-created Shopify identities.
	 *
	 * @return string
	 */
	private function default_customer_role() {
		$roles_obj = wp_roles();
		$roles     = $roles_obj instanceof WP_Roles ? $roles_obj->get_names() : array();

		if ( isset( $roles['shopify_customer'] ) ) {
			return 'shopify_customer';
		}

		return 'subscriber';
	}

	/**
	 * Create a WordPress user from claim payload.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 * @param string               $role   Role to assign.
	 *
	 * @return WP_User|false
	 */
	private function create_wordpress_user_from_claims( $claims, $role ) {
		$email = self::email_from_claims( $claims );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			return false;
		}

		$base_username = '';
		if ( '' !== $email ) {
			$email_parts   = explode( '@', $email );
			$base_username = sanitize_user( (string) $email_parts[0], true );
		}
		if ( '' === $base_username ) {
			$base_username = 'portal_user';
		}

		$username = $base_username;
		$counter  = 1;
		while ( username_exists( $username ) ) {
			$counter++;
			$username = $base_username . '_' . $counter;
		}

		$user_id = wp_create_user( $username, wp_generate_password( 32, true, true ), $email );
		if ( is_wp_error( $user_id ) ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$roles_obj     = wp_roles();
		$allowed_roles = $roles_obj instanceof WP_Roles ? $roles_obj->get_names() : array();
		$role          = sanitize_key( (string) $role );
		if ( ! isset( $allowed_roles[ $role ] ) ) {
			$role = $this->default_customer_role();
		}

		$user->set_role( $role );

		return $user;
	}
}
