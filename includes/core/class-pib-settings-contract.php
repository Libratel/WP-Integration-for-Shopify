<?php
/**
 * Central settings contract for schema, normalization, validation, and diagnostics.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared settings schema and validation helpers.
 */
final class PIB_Settings_Contract {
	/**
	 * Get settings sections used by the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function sections() {
		return array(
			'identity_auth_bridge' => array(
				'title'       => 'Identity/Auth Bridge',
				'description' => 'Configure Shopify handoff identity and token verification.',
			),
			'routing_access'       => array(
				'title'       => 'Routing & Access',
				'description' => 'Define allowed destinations and protected portal routing behavior.',
			),
			'user_mapping'         => array(
				'title'       => 'User Mapping',
				'description' => 'Control WordPress user linking and creation behavior.',
			),
			'session_logout'       => array(
				'title'       => 'Session & Logout',
				'description' => 'Configure managed session lifetime, revalidation, and logout policy.',
			),
			'backorders'           => array(
				'title'       => 'Backorders',
				'description' => 'Configure external backorders integration and dashboard behavior.',
			),
			'operations_audit'     => array(
				'title'       => 'Operations & Audit',
				'description' => 'Configure security controls and logging retention.',
			),
		);
	}

	/**
	 * Get schema for known settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema() {
		$schema = array(
			'shopify_login_url' => array(
				'type'        => 'url',
				'default'     => '',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Shopify Portal URL',
				'description' => 'Where users are sent to start the Shopify bridge login step.',
				'placeholder' => 'https://shop.example.com/apps/portal-bridge',
				'critical'    => true,
				'ui'          => true,
			),
			'client_id' => array(
				'type'        => 'text',
				'default'     => '',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Client ID',
				'description' => 'Client ID from your Shopify bridge app. Used as the expected token audience.',
				'placeholder' => 'partner-portal',
				'critical'    => true,
				'ui'          => true,
			),
			'token_secret' => array(
				'type'        => 'password',
				'default'     => '',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Shared Secret',
				'description' => 'Shared secret used by Shopify and WordPress to sign and verify tokens.',
				'placeholder' => '',
				'critical'    => true,
				'sensitive'   => true,
				'ui'          => true,
			),
			'expected_issuer' => array(
				'type'        => 'text',
				'default'     => '',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Issuer (iss)',
				'description' => 'Issuer claim that tokens must include (for example, your Shopify domain).',
				'placeholder' => 'https://shop.example.com',
				'critical'    => true,
				'ui'          => true,
			),
			'expected_audience' => array(
				'type'        => 'text',
				'default'     => '',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Expected Token Audience (aud)',
				'placeholder' => 'partner-portal',
				'critical'    => true,
				'ui'          => false,
			),
			'leeway_seconds' => array(
				'type'        => 'int_range',
				'default'     => 30,
				'section'     => 'identity_auth_bridge',
				'label'       => 'Allowed Clock Skew (seconds)',
				'description' => 'Extra seconds allowed to account for small time differences between servers.',
				'placeholder' => '30',
				'min'         => 0,
				'max'         => 300,
				'ui'          => true,
			),
			'require_company_id' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'identity_auth_bridge',
				'label'       => 'Require Company ID Claim',
				'description' => 'Reject handoffs that do not include a company/account identifier claim.',
				'ui'          => true,
			),
			'sign_in_label' => array(
				'type'        => 'text',
				'default'     => 'Sign in to Portal',
				'section'     => 'identity_auth_bridge',
				'label'       => 'Default Sign-In Link Label',
				'placeholder' => 'Sign in to Portal',
				'ui'          => true,
			),
			'default_destination' => array(
				'type'        => 'destination',
				'default'     => '/dashboard',
				'section'     => 'routing_access',
				'label'       => 'Default Destination',
				'placeholder' => '/dashboard',
				'critical'    => true,
				'ui'          => true,
			),
			'allowed_destination_prefixes' => array(
				'type'        => 'prefixes',
				'default'     => array(
					'/dashboard',
					'/materials',
					'/downloads',
				),
				'section'     => 'routing_access',
				'label'       => 'Allowed Destination Prefixes',
				'critical'    => true,
				'ui'          => true,
			),
			'failure_destination' => array(
				'type'        => 'destination',
				'default'     => '/portal-login',
				'section'     => 'routing_access',
				'label'       => 'Failure Destination',
				'placeholder' => '/portal-login',
				'critical'    => true,
				'ui'          => true,
			),
			'protected_route_prefixes' => array(
				'type'        => 'prefixes',
				'default'     => array(
					'/dashboard',
					'/materials',
					'/downloads',
				),
				'section'     => 'routing_access',
				'label'       => 'Protected Route Prefixes',
				'description' => 'Internal path prefixes that require authentication and mapping policy checks.',
				'ui'          => true,
			),
			'unprotected_route_prefixes' => array(
				'type'        => 'prefixes_strict',
				'default'     => array(
					'/portal-login',
				),
				'section'     => 'routing_access',
				'label'       => 'Unprotected Route Prefixes',
				'description' => 'Optional internal prefixes that bypass protection even when they overlap protected prefixes.',
				'ui'          => true,
			),
			'require_active_mapping' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'routing_access',
				'label'       => 'Require Active Mapping on Protected Routes',
				'description' => 'Block protected pages when signed-in user has no active customer mapping context.',
				'ui'          => true,
			),
			'require_managed_session_for_protected_routes' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'routing_access',
				'label'       => 'Require Managed Session on Protected Routes',
				'description' => 'Require bridge-managed session state for protected pages (blocks direct WordPress logins).',
				'ui'          => true,
			),
			'spark_launch_enabled' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'routing_access',
				'label'       => 'Enable SparkLayer Launch Entry',
				'description' => 'Allow external launch links using ?pib_launch=1&dest=/path.',
				'ui'          => true,
			),
			'spark_launch_allowed_prefixes' => array(
				'type'        => 'prefixes',
				'default'     => array(
					'/dashboard',
					'/materials',
					'/downloads',
				),
				'section'     => 'routing_access',
				'label'       => 'Spark Launch Allowed Prefixes',
				'ui'          => true,
			),
			'auto_create_users' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'user_mapping',
				'label'       => 'Auto-create WordPress Users',
				'description' => 'Create WordPress users when a mapped customer has no existing linked user.',
				'ui'          => true,
			),
			'auto_link_existing_users_by_email' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'user_mapping',
				'label'       => 'Auto-link Existing Users by Email',
				'description' => 'Allow first-time customer mapping to link by email only when no conflicting active Shopify customer mapping exists.',
				'ui'          => true,
			),
			'new_user_role' => array(
				'type'        => 'role',
				'default'     => 'shopify_customer',
				'section'     => 'user_mapping',
				'label'       => 'New User Role',
				'ui'          => true,
			),
			'managed_session_ttl_hours' => array(
				'type'        => 'int_range',
				'default'     => 4,
				'section'     => 'session_logout',
				'label'       => 'Managed Session TTL (hours)',
				'placeholder' => '4',
				'min'         => 1,
				'max'         => 720,
				'ui'          => true,
			),
			'periodic_revalidation_enabled' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'session_logout',
				'label'       => 'Enable Periodic Revalidation',
				'description' => 'Periodically verify active mapping context for managed sessions.',
				'ui'          => true,
			),
			'revalidate_interval_minutes' => array(
				'type'        => 'int_range',
				'default'     => 30,
				'section'     => 'session_logout',
				'label'       => 'Revalidation Interval (minutes)',
				'placeholder' => '30',
				'min'         => 1,
				'max'         => 1440,
				'ui'          => true,
			),
			'coordinated_logout_enabled' => array(
				'type'        => 'checkbox',
				'default'     => 0,
				'section'     => 'session_logout',
				'label'       => 'Enable Coordinated Logout',
				'description' => 'Redirect users to upstream logout endpoint after local logout.',
				'ui'          => true,
			),
			'upstream_logout_url' => array(
				'type'        => 'url',
				'default'     => '',
				'section'     => 'session_logout',
				'label'       => 'Upstream Logout URL',
				'placeholder' => 'https://shop.example.com/account/logout',
				'ui'          => true,
			),
			'upstream_logout_return_param' => array(
				'type'        => 'return_param',
				'default'     => 'return_to',
				'section'     => 'session_logout',
				'label'       => 'Upstream Logout Return Param',
				'placeholder' => 'return_to',
				'ui'          => true,
			),
			'backorders_enabled' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'backorders',
				'label'       => 'Enable Backorders Dashboard',
				'description' => 'Enable backorders views and CSV export features.',
				'ui'          => true,
			),
			'backorders_api_endpoint' => array(
				'type'        => 'url',
				'default'     => '',
				'section'     => 'backorders',
				'label'       => 'Backorders API Endpoint',
				'placeholder' => 'https://api.example.com/backorders',
				'ui'          => true,
			),
			'backorders_api_token' => array(
				'type'        => 'password',
				'default'     => '',
				'section'     => 'backorders',
				'label'       => 'Backorders API Token',
				'placeholder' => '',
				'sensitive'   => true,
				'ui'          => true,
			),
			'backorders_api_timeout' => array(
				'type'        => 'int_range',
				'default'     => 15,
				'section'     => 'backorders',
				'label'       => 'Backorders API Timeout (seconds)',
				'placeholder' => '15',
				'min'         => 3,
				'max'         => 60,
				'ui'          => true,
			),
			'backorders_default_limit' => array(
				'type'        => 'int_range',
				'default'     => 200,
				'section'     => 'backorders',
				'label'       => 'Backorders Default Limit',
				'placeholder' => '200',
				'min'         => 1,
				'max'         => 5000,
				'ui'          => true,
			),
			'auth_failure_limit' => array(
				'type'        => 'int_range',
				'default'     => 10,
				'section'     => 'operations_audit',
				'label'       => 'Auth Failure Limit',
				'placeholder' => '10',
				'min'         => 1,
				'max'         => 500,
				'ui'          => true,
			),
			'auth_failure_window_minutes' => array(
				'type'        => 'int_range',
				'default'     => 15,
				'section'     => 'operations_audit',
				'label'       => 'Auth Failure Window (minutes)',
				'placeholder' => '15',
				'min'         => 1,
				'max'         => 1440,
				'ui'          => true,
			),
			'audit_logging_enabled' => array(
				'type'        => 'checkbox',
				'default'     => 1,
				'section'     => 'operations_audit',
				'label'       => 'Enable Audit Logging',
				'description' => 'Persist auth/access events in audit log table.',
				'ui'          => true,
			),
			'audit_log_retention_days' => array(
				'type'        => 'int_range',
				'default'     => 90,
				'section'     => 'operations_audit',
				'label'       => 'Audit Log Retention (days)',
				'placeholder' => '90',
				'min'         => 1,
				'max'         => 3650,
				'ui'          => true,
			),
			'legacy_handoff_enabled' => array(
				'type'      => 'checkbox',
				'default'   => 0,
				'section'   => 'identity_auth_bridge',
				'label'     => 'Legacy Handoff',
				'ui'        => false,
			),
		);

		/**
		 * Filter schema for settings extensions.
		 *
		 * @param array<string, array<string,mixed>> $schema Settings schema.
		 */
		$schema = apply_filters( 'pib_settings_schema', $schema );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Get default settings from schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$schema   = self::schema();
		$defaults = array();

		foreach ( $schema as $key => $definition ) {
			$defaults[ $key ] = isset( $definition['default'] ) ? $definition['default'] : '';
		}

		$defaults['legacy_handoff_enabled'] = 0;
		return $defaults;
	}

	/**
	 * Normalize incoming settings against schema.
	 *
	 * Unknown keys from $base are preserved for forward compatibility.
	 *
	 * @param array<string, mixed> $raw  Incoming values.
	 * @param array<string, mixed> $base Existing values to preserve unknown keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function normalize( $raw, $base = array() ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$base   = is_array( $base ) ? $base : array();
		$schema = self::schema();

		$settings = $base;

		foreach ( $schema as $key => $definition ) {
			$default = isset( $definition['default'] ) ? $definition['default'] : '';
			$value   = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;

			$settings[ $key ] = self::sanitize_value( $key, $value, $definition );
		}

		$expected_audience = isset( $settings['expected_audience'] ) ? sanitize_text_field( (string) $settings['expected_audience'] ) : '';
		$client_id         = isset( $settings['client_id'] ) ? sanitize_text_field( (string) $settings['client_id'] ) : '';

		if ( '' === $client_id && '' !== $expected_audience ) {
			$client_id = $expected_audience;
		}

		if ( '' === $expected_audience && '' !== $client_id ) {
			$expected_audience = $client_id;
		}

		$settings['client_id']         = $client_id;
		$settings['expected_audience'] = $expected_audience;

		$settings['legacy_handoff_enabled'] = 0;
		return $settings;
	}

	/**
	 * Build merged candidate settings for save operations.
	 *
	 * @param array<string, mixed> $input    Submitted values.
	 * @param array<string, mixed> $existing Existing stored values.
	 *
	 * @return array<string, mixed>
	 */
	public static function merged_candidate_for_save( $input, $existing ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = is_array( $existing ) ? $existing : array();
		$schema   = self::schema();

		$candidate = wp_parse_args( $input, $existing );

		foreach ( $schema as $key => $definition ) {
			$type = isset( $definition['type'] ) ? (string) $definition['type'] : 'text';
			$is_ui_field = ! isset( $definition['ui'] ) || ! empty( $definition['ui'] );
			if ( 'checkbox' === $type && $is_ui_field && ! array_key_exists( $key, $input ) ) {
				$candidate[ $key ] = 0;
			}
		}

		return $candidate;
	}

	/**
	 * Validate normalized settings.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 * @param array<string, mixed> $source   Raw candidate values (optional).
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public static function validate( $settings, $source = array() ) {
		$settings = is_array( $settings ) ? $settings : array();
		$source   = is_array( $source ) ? $source : array();

		$results = array(
			'critical_errors' => array(),
			'warnings'        => array(),
			'checks'          => array(),
		);

		self::validate_required_url(
			$results,
			'shopify_login_url',
			isset( $settings['shopify_login_url'] ) ? (string) $settings['shopify_login_url'] : '',
			'Shopify Portal URL is required and must be a valid URL.'
		);
		self::validate_required_text(
			$results,
			'token_secret',
			isset( $settings['token_secret'] ) ? (string) $settings['token_secret'] : '',
			'Shared Secret is required.'
		);
		self::validate_required_text(
			$results,
			'expected_issuer',
			isset( $settings['expected_issuer'] ) ? (string) $settings['expected_issuer'] : '',
			'Issuer (iss) is required.'
		);
		self::validate_required_text(
			$results,
			'client_id',
			isset( $settings['client_id'] ) ? (string) $settings['client_id'] : '',
			'Client ID is required.'
		);

		$allowed_prefixes = isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array();
		$allowed_prefixes_valid = is_array( $allowed_prefixes ) && ! empty( $allowed_prefixes );
		if ( $allowed_prefixes_valid && array_key_exists( 'allowed_destination_prefixes', $source ) ) {
			$allowed_prefixes_valid = ! empty( self::sanitize_prefixes_without_default( $source['allowed_destination_prefixes'] ) );
		}

		if ( ! $allowed_prefixes_valid ) {
			self::add_critical(
				$results,
				'allowed_destination_prefixes',
				'Allowed Destination Prefixes must include at least one internal path (for example, /dashboard).'
			);
		} else {
			self::add_ok(
				$results,
				'allowed_destination_prefixes',
				'Allowed Destination Prefixes are configured.'
			);
		}

		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '';
		$failure_dest = isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '';

		self::validate_destination_input(
			$results,
			'default_destination',
			$default_dest,
			array_key_exists( 'default_destination', $source ) ? $source['default_destination'] : null,
			'Default Destination must be an internal portal path.'
		);
		self::validate_destination_input(
			$results,
			'failure_destination',
			$failure_dest,
			array_key_exists( 'failure_destination', $source ) ? $source['failure_destination'] : null,
			'Failure Destination must be an internal portal path.'
		);

		if ( ! PIB_Destination::is_allowed( $default_dest, $allowed_prefixes ) ) {
			self::add_critical(
				$results,
				'default_destination_allowlist',
				'Default Destination must start with one of the Allowed Destination Prefixes.'
			);
		} else {
			self::add_ok(
				$results,
				'default_destination_allowlist',
				'Default Destination matches Allowed Destination Prefixes.'
			);
		}

		$unprotected_prefixes = isset( $settings['unprotected_route_prefixes'] ) && is_array( $settings['unprotected_route_prefixes'] )
			? $settings['unprotected_route_prefixes']
			: array();

		if ( in_array( '/', $unprotected_prefixes, true ) ) {
			self::add_critical(
				$results,
				'unprotected_root_prefix',
				'Unprotected Route Prefixes cannot include "/" because it disables route protection site-wide.'
			);
		} else {
			self::add_ok(
				$results,
				'unprotected_root_prefix',
				'Unprotected Route Prefixes do not disable global protection.'
			);
		}

		if ( class_exists( 'PIB_Protected_Route_Policy' ) ) {
			$default_eval = PIB_Protected_Route_Policy::evaluate_destination( $default_dest, $settings );
			$failure_eval = PIB_Protected_Route_Policy::evaluate_destination( $failure_dest, $settings );

			if ( empty( $default_eval['is_protected'] ) ) {
				self::add_warning(
					$results,
					'default_destination_not_protected',
					'Default Destination is not protected by current route policy.'
				);
			} else {
				self::add_ok(
					$results,
					'default_destination_protected',
					'Default Destination is protected by route policy.'
				);
			}

			if ( ! empty( $failure_eval['is_protected'] ) ) {
				self::add_critical(
					$results,
					'failure_destination_protected',
					'Failure Destination is currently protected and may cause sign-in redirect loops.'
				);
			} else {
				self::add_ok(
					$results,
					'failure_destination_public',
					'Failure Destination is reachable without protected-route gating.'
				);
			}
		}

		$coordinated_logout = ! empty( $settings['coordinated_logout_enabled'] );
		$upstream_logout    = isset( $settings['upstream_logout_url'] ) ? (string) $settings['upstream_logout_url'] : '';
		$return_param       = isset( $settings['upstream_logout_return_param'] ) ? (string) $settings['upstream_logout_return_param'] : '';

		if ( $coordinated_logout ) {
			self::validate_required_url(
				$results,
				'upstream_logout_url',
				$upstream_logout,
				'Upstream Logout URL is required when coordinated logout is enabled.'
			);

			$param_is_valid = '' !== $return_param;
			if ( array_key_exists( 'upstream_logout_return_param', $source ) ) {
				$param_is_valid = '' !== sanitize_key( (string) $source['upstream_logout_return_param'] );
			}
			if ( ! $param_is_valid ) {
				self::add_critical(
					$results,
					'upstream_logout_return_param',
					'Upstream Logout Return Param must be a non-empty key when coordinated logout is enabled.'
				);
			} else {
				self::add_ok(
					$results,
					'upstream_logout_return_param',
					'Upstream Logout Return Param is configured.'
				);
			}
		} else {
			self::add_ok(
				$results,
				'upstream_logout_gated',
				'Coordinated logout is disabled; upstream logout fields are optional.'
			);
		}

		$spark_launch_enabled = ! empty( $settings['spark_launch_enabled'] );
		$spark_prefixes       = isset( $settings['spark_launch_allowed_prefixes'] ) ? $settings['spark_launch_allowed_prefixes'] : array();
		if ( $spark_launch_enabled ) {
			$has_valid_prefixes = is_array( $spark_prefixes ) && ! empty( $spark_prefixes );
			if ( $has_valid_prefixes && array_key_exists( 'spark_launch_allowed_prefixes', $source ) ) {
				$has_valid_prefixes = ! empty( self::sanitize_prefixes_without_default( $source['spark_launch_allowed_prefixes'] ) );
			}
			if ( ! $has_valid_prefixes ) {
				self::add_critical(
					$results,
					'spark_launch_allowed_prefixes',
					'Spark Launch Allowed Prefixes must be non-empty when SparkLayer launch is enabled.'
				);
			} else {
				self::add_ok(
					$results,
					'spark_launch_allowed_prefixes',
					'Spark Launch Allowed Prefixes are configured.'
				);
			}
		} else {
			self::add_ok(
				$results,
				'spark_launch_gated',
				'SparkLayer launch is disabled; launch prefixes are optional.'
			);
		}

		$backorders_enabled = ! empty( $settings['backorders_enabled'] );
		if ( $backorders_enabled ) {
			self::validate_required_url(
				$results,
				'backorders_api_endpoint',
				isset( $settings['backorders_api_endpoint'] ) ? (string) $settings['backorders_api_endpoint'] : '',
				'Backorders API Endpoint is required when backorders are enabled.'
			);
		} else {
			self::add_ok(
				$results,
				'backorders_endpoint_gated',
				'Backorders are disabled; API endpoint is optional.'
			);
		}

		$token_secret = isset( $settings['token_secret'] ) ? (string) $settings['token_secret'] : '';
		if ( '' !== $token_secret && strlen( $token_secret ) < 32 ) {
			self::add_warning(
				$results,
				'weak_token_secret',
				'Shared Secret is configured but shorter than 32 characters.'
			);
		} else {
			self::add_ok(
				$results,
				'strong_token_secret',
				'Shared Secret length is acceptable.'
			);
		}

		$ttl_minutes = max( 1, absint( isset( $settings['managed_session_ttl_hours'] ) ? $settings['managed_session_ttl_hours'] : 1 ) ) * 60;
		$revalidate  = max( 1, absint( isset( $settings['revalidate_interval_minutes'] ) ? $settings['revalidate_interval_minutes'] : 1 ) );
		if ( ! empty( $settings['periodic_revalidation_enabled'] ) && $revalidate > $ttl_minutes ) {
			self::add_warning(
				$results,
				'revalidation_gt_ttl',
				'Revalidation Interval is greater than managed session TTL window.'
			);
		} else {
			self::add_ok(
				$results,
				'revalidation_window',
				'Revalidation window is aligned with managed session TTL.'
			);
		}

		$backorders_token = isset( $settings['backorders_api_token'] ) ? (string) $settings['backorders_api_token'] : '';
		if ( $backorders_enabled && '' === $backorders_token ) {
			self::add_warning(
				$results,
				'backorders_token_missing',
				'Backorders are enabled but Backorders API Token is empty (optional, but recommended).'
			);
		} else {
			self::add_ok(
				$results,
				'backorders_token_present',
				'Backorders API token configuration is acceptable for current mode.'
			);
		}

		/**
		 * Filter validation/diagnostic result payload.
		 *
		 * @param array<string, array<int, array<string, string>>> $results  Validation results.
		 * @param array<string, mixed>                              $settings Normalized settings.
		 * @param array<string, mixed>                              $source   Raw candidate source.
		 */
		$results = apply_filters( 'pib_settings_validation_results', $results, $settings, $source );
		return self::normalize_results_shape( $results );
	}

	/**
	 * Build diagnostics payload for read-only settings panel.
	 *
	 * @param array<string, mixed> $settings Normalized runtime settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics( $settings ) {
		$settings   = is_array( $settings ) ? $settings : array();
		$validation = self::validate( $settings );
		$checks     = isset( $validation['checks'] ) && is_array( $validation['checks'] ) ? $validation['checks'] : array();

		/**
		 * Filter diagnostics checklist entries.
		 *
		 * @param array<int, array<string, string>> $checks     Checklist entries.
		 * @param array<string, mixed>              $settings   Normalized settings.
		 * @param array<string, mixed>              $validation Validation result payload.
		 */
		$checks = apply_filters( 'pib_settings_diagnostics_checks', $checks, $settings, $validation );
		if ( ! is_array( $checks ) ) {
			$checks = array();
		}

		$has_critical = ! empty( $validation['critical_errors'] );
		$has_warning  = ! empty( $validation['warnings'] );

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['level'] ) ) {
				continue;
			}
			$level = sanitize_key( (string) $check['level'] );
			if ( 'critical' === $level ) {
				$has_critical = true;
			} elseif ( 'warning' === $level ) {
				$has_warning = true;
			}
		}

		$status = 'healthy';
		if ( $has_critical ) {
			$status = 'action_needed';
		} elseif ( $has_warning ) {
			$status = 'warnings';
		}

		$effective_values = self::redacted_effective_values( $settings );

		return array(
			'status'           => $status,
			'checks'           => $checks,
			'critical_errors'  => isset( $validation['critical_errors'] ) ? $validation['critical_errors'] : array(),
			'warnings'         => isset( $validation['warnings'] ) ? $validation['warnings'] : array(),
			'effective_values' => $effective_values,
			'references'       => array(
				'intake_url'     => PIB_Plugin::intake_url(),
				'client_id'      => isset( $settings['client_id'] ) ? (string) $settings['client_id'] : '',
				'session_guard'  => ! empty( $settings['require_managed_session_for_protected_routes'] ) ? 'enabled' : 'disabled',
				'spark_launch'   => ! empty( $settings['spark_launch_enabled'] ) ? 'enabled' : 'disabled',
				'backorders'     => ! empty( $settings['backorders_enabled'] ) ? 'enabled' : 'disabled',
				'coord_logout'   => ! empty( $settings['coordinated_logout_enabled'] ) ? 'enabled' : 'disabled',
				'revalidation'   => ! empty( $settings['periodic_revalidation_enabled'] ) ? 'enabled' : 'disabled',
				'protected_prefixes' => (string) count( PIB_Destination::sanitize_prefixes( isset( $settings['protected_route_prefixes'] ) ? $settings['protected_route_prefixes'] : array(), true ) ),
				'unprotected_prefixes' => (string) count( PIB_Destination::sanitize_prefixes( isset( $settings['unprotected_route_prefixes'] ) ? $settings['unprotected_route_prefixes'] : array(), false ) ),
			),
		);
	}

	/**
	 * Normalize validation result shape after filters.
	 *
	 * @param mixed $results Candidate result payload.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	private static function normalize_results_shape( $results ) {
		$normalized = array(
			'critical_errors' => array(),
			'warnings'        => array(),
			'checks'          => array(),
		);

		if ( ! is_array( $results ) ) {
			return $normalized;
		}

		foreach ( array_keys( $normalized ) as $bucket ) {
			if ( isset( $results[ $bucket ] ) && is_array( $results[ $bucket ] ) ) {
				foreach ( $results[ $bucket ] as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$normalized_entry = array();
					foreach ( $entry as $key => $value ) {
						$normalized_entry[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
					}
					$normalized[ $bucket ][] = $normalized_entry;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Build redacted effective values map for diagnostics display.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function redacted_effective_values( $settings ) {
		$schema = self::schema();
		$values = array();

		foreach ( $schema as $key => $definition ) {
			if ( isset( $definition['ui'] ) && ! $definition['ui'] ) {
				continue;
			}

			$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : ( isset( $definition['default'] ) ? $definition['default'] : '' );
			if ( ! empty( $definition['sensitive'] ) ) {
				$values[ $key ] = '' === (string) $value ? '(not set)' : '(configured)';
				continue;
			}
			$values[ $key ] = $value;
		}

		return $values;
	}

	/**
	 * Sanitize a value by schema definition.
	 *
	 * @param string               $key        Setting key.
	 * @param mixed                $value      Raw value.
	 * @param array<string, mixed> $definition Schema definition.
	 *
	 * @return mixed
	 */
	private static function sanitize_value( $key, $value, $definition ) {
		$type    = isset( $definition['type'] ) ? (string) $definition['type'] : 'text';
		$default = isset( $definition['default'] ) ? $definition['default'] : '';

		switch ( $type ) {
			case 'url':
				return esc_url_raw( trim( self::to_scalar_string( $value ) ) );

			case 'password':
			case 'text':
				$text = sanitize_text_field( self::to_scalar_string( $value ) );
				if ( 'sign_in_label' === $key && '' === $text ) {
					return 'Sign in to Portal';
				}
				return $text;

			case 'destination':
				return PIB_Destination::sanitize_destination( self::to_scalar_string( $value ), (string) $default );

			case 'prefixes':
				return PIB_Destination::sanitize_prefixes( $value );

			case 'prefixes_strict':
				return PIB_Destination::sanitize_prefixes( $value, false );

			case 'checkbox':
				return empty( $value ) ? 0 : 1;

			case 'int_range':
				$min = isset( $definition['min'] ) ? absint( $definition['min'] ) : 0;
				$max = isset( $definition['max'] ) ? absint( $definition['max'] ) : PHP_INT_MAX;
				return max( $min, min( $max, absint( $value ) ) );

			case 'role':
				$role      = sanitize_key( self::to_scalar_string( $value ) );
				$roles_obj = wp_roles();
				$roles     = $roles_obj instanceof WP_Roles ? $roles_obj->get_names() : array();
				if ( ! isset( $roles[ $role ] ) ) {
					$role = isset( $roles['shopify_customer'] ) ? 'shopify_customer' : 'subscriber';
				}
				return $role;

			case 'return_param':
				$param = sanitize_key( self::to_scalar_string( $value ) );
				if ( '' === $param ) {
					$param = 'return_to';
				}
				return $param;
		}

		return sanitize_text_field( self::to_scalar_string( $value ) );
	}

	/**
	 * Validate required text key.
	 *
	 * @param array<string, array<int, array<string, string>>> $results Results payload.
	 * @param string                                            $key     Check key.
	 * @param string                                            $value   Value.
	 * @param string                                            $message Failure message.
	 *
	 * @return void
	 */
	private static function validate_required_text( &$results, $key, $value, $message ) {
		if ( '' === trim( $value ) ) {
			self::add_critical( $results, $key, $message );
			return;
		}

		self::add_ok( $results, $key, 'Configured.' );
	}

	/**
	 * Validate required URL key.
	 *
	 * @param array<string, array<int, array<string, string>>> $results Results payload.
	 * @param string                                            $key     Check key.
	 * @param string                                            $value   Value.
	 * @param string                                            $message Failure message.
	 *
	 * @return void
	 */
	private static function validate_required_url( &$results, $key, $value, $message ) {
		if ( ! self::is_valid_http_url( $value ) ) {
			self::add_critical( $results, $key, $message );
			return;
		}

		self::add_ok( $results, $key, 'Configured.' );
	}

	/**
	 * Validate destination input requirements.
	 *
	 * @param array<string, array<int, array<string, string>>> $results     Results payload.
	 * @param string                                            $key         Check key.
	 * @param string                                            $normalized  Normalized value.
	 * @param mixed                                             $source_raw  Raw source input when available.
	 * @param string                                            $error_msg   Error message.
	 *
	 * @return void
	 */
	private static function validate_destination_input( &$results, $key, $normalized, $source_raw, $error_msg ) {
		$valid = self::is_valid_internal_destination_string( $normalized );
		if ( null !== $source_raw ) {
			$valid = $valid && self::is_valid_destination_input( $source_raw );
		}

		if ( ! $valid ) {
			self::add_critical( $results, $key, $error_msg );
			return;
		}

		self::add_ok( $results, $key, 'Configured.' );
	}

	/**
	 * Determine whether URL is valid absolute HTTP(S) URL.
	 *
	 * @param string $url URL.
	 *
	 * @return bool
	 */
	private static function is_valid_http_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'] ) ) {
			return false;
		}

		return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Validate destination based on raw input form.
	 *
	 * @param mixed $value Raw destination value.
	 *
	 * @return bool
	 */
	private static function is_valid_destination_input( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = trim( wp_unslash( (string) $value ) );
		if ( '' === $value ) {
			return false;
		}
		if ( false !== strpos( $value, "\n" ) || false !== strpos( $value, "\r" ) ) {
			return false;
		}
		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return false;
		}

		if ( 0 !== strpos( $value, '/' ) ) {
			$value = '/' . ltrim( $value, '/' );
		}

		return self::is_valid_internal_destination_string( $value );
	}

	/**
	 * Validate normalized destination string.
	 *
	 * @param string $value Destination value.
	 *
	 * @return bool
	 */
	private static function is_valid_internal_destination_string( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 0 !== strpos( $value, '/' ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		return false !== $parts;
	}

	/**
	 * Sanitize prefixes without auto-fallback.
	 *
	 * @param mixed $raw Raw prefixes.
	 *
	 * @return array<int, string>
	 */
	private static function sanitize_prefixes_without_default( $raw ) {
		$items = array();
		if ( is_string( $raw ) ) {
			$items = preg_split( '/[\r\n,]+/', $raw );
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}

		$prefixes = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$prefix = trim( wp_unslash( $item ) );
			if ( '' === $prefix || preg_match( '#^(https?:)?//#i', $prefix ) ) {
				continue;
			}
			if ( 0 !== strpos( $prefix, '/' ) ) {
				$prefix = '/' . ltrim( $prefix, '/' );
			}
			$prefix = (string) wp_parse_url( $prefix, PHP_URL_PATH );
			if ( '' === $prefix ) {
				continue;
			}
			$prefix = preg_replace( '#/{2,}#', '/', $prefix );
			if ( ! is_string( $prefix ) || '' === $prefix ) {
				continue;
			}
			if ( '/' !== $prefix ) {
				$prefix = rtrim( $prefix, '/' );
			}

			$prefixes[] = $prefix;
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Add critical error/check entry.
	 *
	 * @param array<string, array<int, array<string, string>>> $results Results payload.
	 * @param string                                            $code    Error code.
	 * @param string                                            $message Message.
	 *
	 * @return void
	 */
	private static function add_critical( &$results, $code, $message ) {
		$entry                      = array(
			'code'    => sanitize_key( (string) $code ),
			'message' => sanitize_text_field( (string) $message ),
		);
		$results['critical_errors'][] = $entry;
		$results['checks'][]          = array(
			'id'      => $entry['code'],
			'level'   => 'critical',
			'message' => $entry['message'],
		);
	}

	/**
	 * Add warning/check entry.
	 *
	 * @param array<string, array<int, array<string, string>>> $results Results payload.
	 * @param string                                            $code    Warning code.
	 * @param string                                            $message Message.
	 *
	 * @return void
	 */
	private static function add_warning( &$results, $code, $message ) {
		$entry                 = array(
			'code'    => sanitize_key( (string) $code ),
			'message' => sanitize_text_field( (string) $message ),
		);
		$results['warnings'][] = $entry;
		$results['checks'][]   = array(
			'id'      => $entry['code'],
			'level'   => 'warning',
			'message' => $entry['message'],
		);
	}

	/**
	 * Add OK checklist entry.
	 *
	 * @param array<string, array<int, array<string, string>>> $results Results payload.
	 * @param string                                            $id      Check ID.
	 * @param string                                            $message Message.
	 *
	 * @return void
	 */
	private static function add_ok( &$results, $id, $message ) {
		$results['checks'][] = array(
			'id'      => sanitize_key( (string) $id ),
			'level'   => 'ok',
			'message' => sanitize_text_field( (string) $message ),
		);
	}

	/**
	 * Convert scalar-like values to strings safely.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private static function to_scalar_string( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) wp_unslash( $value );
		}

		return '';
	}
}
