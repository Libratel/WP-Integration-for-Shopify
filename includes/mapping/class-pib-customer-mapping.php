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
		$email               = isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';

		if ( '' === $shopify_customer_id ) {
			return $resolved_user;
		}

		$mapping = $this->find_by_customer_company( $shopify_customer_id, $company_id );
		if ( $mapping ) {
			$user = get_user_by( 'id', absint( $mapping['wp_user_id'] ) );
			if ( $user instanceof WP_User ) {
				$this->touch_mapping( (int) $mapping['id'], $email );
				$this->set_active_mapping_for_user( $user->ID, (int) $mapping['id'] );
				do_action( 'pib_mapping_upsert', $mapping, 'touch' );
				return $user;
			}
		}

		$user = $resolved_user;

		$auto_link_by_email = ! empty( $settings['auto_link_existing_users_by_email'] );
		if ( ! $user && $auto_link_by_email && '' !== $email ) {
			$user = get_user_by( 'email', $email );
		}

		$auto_create = ! empty( $settings['auto_create_users'] );
		if ( ! $user && $auto_create ) {
			$user = $this->create_wordpress_user_from_claims( $claims, (string) $settings['new_user_role'] );
		}

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$mapping_id = $this->upsert_mapping(
			$shopify_customer_id,
			$company_id,
			$user->ID,
			$email
		);

		if ( $mapping_id > 0 ) {
			$this->set_active_mapping_for_user( $user->ID, $mapping_id );
			$mapping = $this->find_by_id( $mapping_id );
			if ( $mapping ) {
				do_action( 'pib_mapping_upsert', $mapping, 'upsert' );
			}
		}

		return $user;
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

		$mapping_id = absint( get_user_meta( $user_id, self::META_ACTIVE_MAPPING_ID, true ) );
		if ( $mapping_id <= 0 ) {
			return null;
		}

		$mapping = $this->find_by_id( $mapping_id );
		if ( ! $mapping ) {
			return null;
		}

		if ( absint( $mapping['wp_user_id'] ) !== $user_id ) {
			return null;
		}

		if ( 'active' !== (string) $mapping['status'] ) {
			return null;
		}

		return array(
			'mapping_id'           => absint( $mapping['id'] ),
			'wp_user_id'           => absint( $mapping['wp_user_id'] ),
			'shopify_customer_id'  => (string) $mapping['shopify_customer_id'],
			'company_id'           => (string) $mapping['company_id'],
			'email'                => (string) $mapping['email'],
			'status'               => (string) $mapping['status'],
		);
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
			if ( $wp_user_id > 0 ) {
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
				status = 'active',
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
		$email = isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			return $existing;
		}

		$base_username = '';
		if ( isset( $claims['email'] ) ) {
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
			$role = 'subscriber';
		}

		$user->set_role( $role );

		return $user;
	}
}
