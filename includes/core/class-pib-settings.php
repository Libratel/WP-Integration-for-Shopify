<?php
/**
 * Settings UI module.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin configuration page and settings sanitization.
 */
final class PIB_Settings {
	/**
	 * Singleton.
	 *
	 * @var PIB_Settings|null
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
	 * @return PIB_Settings|null
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

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
	}

	/**
	 * Register settings page.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		add_options_page(
			'Portal Identity Bridge',
			'Portal Bridge',
			'manage_options',
			'pib-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'pib_settings_group',
			PIB_Plugin::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'pib_auth_bridge_section',
			'Auth Bridge',
			function() {
				echo '<p>Configure secure Shopify/SparkLayer to WordPress handoff settings.</p>';
			},
			'pib-settings'
		);

		$this->add_text_field( 'shopify_login_url', 'Shopify Login/Issuer URL', 'url', 'https://shop.example.com/apps/portal-login' );
		$this->add_text_field( 'token_secret', 'Shared Token Secret', 'password', '' );
		$this->add_text_field( 'expected_issuer', 'Expected Token Issuer (iss)', 'text', 'shopify-bridge' );
		$this->add_text_field( 'leeway_seconds', 'Token Leeway (seconds)', 'number', '30' );
		$this->add_text_field( 'default_destination', 'Default Destination', 'text', '/dashboard' );
		$this->add_textarea_field( 'allowed_destination_prefixes', 'Allowed Destination Prefixes' );
		$this->add_textarea_field( 'protected_route_prefixes', 'Protected Route Prefixes' );
		$this->add_checkbox_field( 'spark_launch_enabled', 'Enable SparkLayer Launch Entry', 'Allow external launch links using ?pib_launch=1&dest=/path.' );
		$this->add_textarea_field( 'spark_launch_allowed_prefixes', 'Spark Launch Allowed Prefixes' );
		$this->add_checkbox_field( 'backorders_enabled', 'Enable Backorders Dashboard', 'Enable backorders views and CSV export features.' );
		$this->add_text_field( 'backorders_api_endpoint', 'Backorders API Endpoint', 'url', 'https://api.example.com/backorders' );
		$this->add_text_field( 'backorders_api_token', 'Backorders API Token', 'password', '' );
		$this->add_text_field( 'backorders_api_timeout', 'Backorders API Timeout (seconds)', 'number', '15' );
		$this->add_text_field( 'backorders_default_limit', 'Backorders Default Limit', 'number', '200' );
		$this->add_text_field( 'managed_session_ttl_hours', 'Managed Session TTL (hours)', 'number', '8' );
		$this->add_checkbox_field( 'periodic_revalidation_enabled', 'Enable Periodic Revalidation', 'Periodically verify active mapping context for managed sessions.' );
		$this->add_text_field( 'revalidate_interval_minutes', 'Revalidation Interval (minutes)', 'number', '30' );
		$this->add_checkbox_field( 'coordinated_logout_enabled', 'Enable Coordinated Logout', 'Redirect users to upstream logout endpoint after local logout.' );
		$this->add_text_field( 'upstream_logout_url', 'Upstream Logout URL', 'url', 'https://shop.example.com/account/logout' );
		$this->add_text_field( 'upstream_logout_return_param', 'Upstream Logout Return Param', 'text', 'return_to' );
		$this->add_text_field( 'auth_failure_limit', 'Auth Failure Limit', 'number', '10' );
		$this->add_text_field( 'auth_failure_window_minutes', 'Auth Failure Window (minutes)', 'number', '15' );
		$this->add_checkbox_field( 'audit_logging_enabled', 'Enable Audit Logging', 'Persist auth/access events in audit log table.' );
		$this->add_text_field( 'audit_log_retention_days', 'Audit Log Retention (days)', 'number', '90' );
		$this->add_text_field( 'failure_destination', 'Failure Destination', 'text', '/portal-login' );
		$this->add_text_field( 'sign_in_label', 'Default Sign-In Link Label', 'text', 'Sign in to Portal' );
		$this->add_checkbox_field( 'auto_create_users', 'Auto-create WordPress Users', 'Create WordPress users when a mapped customer has no existing linked user.' );
		$this->add_checkbox_field( 'auto_link_existing_users_by_email', 'Auto-link Existing Users by Email', 'Allow first-time customer mapping to link to an existing WP account with matching email.' );
		$this->add_role_select_field( 'new_user_role', 'New User Role' );
		$this->add_checkbox_field( 'require_active_mapping', 'Require Active Mapping on Protected Routes', 'Block protected pages when signed-in user has no active customer mapping context.' );
	}

	/**
	 * Add text input field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $type        Input type.
	 * @param string $placeholder Placeholder value.
	 *
	 * @return void
	 */
	private function add_text_field( $key, $label, $type, $placeholder ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_text_field' ),
			'pib-settings',
			'pib_auth_bridge_section',
			array(
				'key'         => $key,
				'type'        => $type,
				'placeholder' => $placeholder,
			)
		);
	}

	/**
	 * Add textarea field.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 *
	 * @return void
	 */
	private function add_textarea_field( $key, $label ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_textarea_field' ),
			'pib-settings',
			'pib_auth_bridge_section',
			array(
				'key' => $key,
			)
		);
	}

	/**
	 * Add checkbox field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $description Short description.
	 *
	 * @return void
	 */
	private function add_checkbox_field( $key, $label, $description ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_checkbox_field' ),
			'pib-settings',
			'pib_auth_bridge_section',
			array(
				'key'         => $key,
				'description' => $description,
			)
		);
	}

	/**
	 * Add role select field.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 *
	 * @return void
	 */
	private function add_role_select_field( $key, $label ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_role_select_field' ),
			'pib-settings',
			'pib_auth_bridge_section',
			array(
				'key' => $key,
			)
		);
	}

	/**
	 * Render text field.
	 *
	 * @param array<string, string> $args Field args.
	 *
	 * @return void
	 */
	public function render_text_field( $args ) {
		$settings = $this->plugin->get_settings();
		$key      = (string) $args['key'];
		$type     = isset( $args['type'] ) ? (string) $args['type'] : 'text';
		$value    = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

		printf(
			'<input type="%1$s" class="regular-text" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" autocomplete="off" />',
			esc_attr( $type ),
			esc_attr( PIB_Plugin::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '' )
		);
	}

	/**
	 * Render textarea field.
	 *
	 * @param array<string, string> $args Field args.
	 *
	 * @return void
	 */
	public function render_textarea_field( $args ) {
		$settings = $this->plugin->get_settings();
		$key      = (string) $args['key'];
		$value    = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? implode( "\n", $settings[ $key ] ) : '';

		printf(
			'<textarea class="large-text code" rows="6" name="%1$s[%2$s]" placeholder="/dashboard%3$s/materials">%4$s</textarea>',
			esc_attr( PIB_Plugin::OPTION_KEY ),
			esc_attr( $key ),
			esc_html( "\n" ),
			esc_textarea( $value )
		);
		echo '<p class="description">One internal path prefix per line. Example: /dashboard</p>';
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array<string, string> $args Field args.
	 *
	 * @return void
	 */
	public function render_checkbox_field( $args ) {
		$settings    = $this->plugin->get_settings();
		$key         = (string) $args['key'];
		$is_checked  = ! empty( $settings[ $key ] );
		$description = isset( $args['description'] ) ? (string) $args['description'] : '';

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( PIB_Plugin::OPTION_KEY ),
			esc_attr( $key ),
			checked( true, $is_checked, false ),
			esc_html( $description )
		);
	}

	/**
	 * Render role select field.
	 *
	 * @param array<string, string> $args Field args.
	 *
	 * @return void
	 */
	public function render_role_select_field( $args ) {
		$settings = $this->plugin->get_settings();
		$key      = (string) $args['key'];
		$current  = isset( $settings[ $key ] ) ? sanitize_key( (string) $settings[ $key ] ) : 'subscriber';
		$roles_obj = wp_roles();
		$roles     = $roles_obj instanceof WP_Roles ? $roles_obj->get_names() : array();

		printf(
			'<select name="%1$s[%2$s]">',
			esc_attr( PIB_Plugin::OPTION_KEY ),
			esc_attr( $key )
		);

		foreach ( $roles as $role_key => $role_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $role_key ),
				selected( $current, $role_key, false ),
				esc_html( $role_label )
			);
		}

		echo '</select>';
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param mixed $input Raw settings input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$defaults = PIB_Plugin::default_settings();
		$existing = get_option( PIB_Plugin::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$input    = is_array( $input ) ? $input : array();

		$settings = wp_parse_args( $input, $existing );
		$settings = wp_parse_args( $settings, $defaults );

		$settings['shopify_login_url']            = esc_url_raw( trim( (string) $settings['shopify_login_url'] ) );
		$settings['token_secret']                 = sanitize_text_field( (string) $settings['token_secret'] );
		$settings['expected_issuer']              = sanitize_text_field( (string) $settings['expected_issuer'] );
		$settings['leeway_seconds']               = max( 0, min( 300, absint( $settings['leeway_seconds'] ) ) );
		$settings['backorders_api_endpoint']      = esc_url_raw( trim( (string) $settings['backorders_api_endpoint'] ) );
		$settings['backorders_api_token']         = sanitize_text_field( (string) $settings['backorders_api_token'] );
		$settings['backorders_api_timeout']       = max( 3, min( 60, absint( $settings['backorders_api_timeout'] ) ) );
		$settings['backorders_default_limit']     = max( 1, min( 5000, absint( $settings['backorders_default_limit'] ) ) );
		$settings['managed_session_ttl_hours']    = max( 1, min( 720, absint( $settings['managed_session_ttl_hours'] ) ) );
		$settings['revalidate_interval_minutes']  = max( 1, min( 1440, absint( $settings['revalidate_interval_minutes'] ) ) );
		$settings['upstream_logout_url']          = esc_url_raw( trim( (string) $settings['upstream_logout_url'] ) );
		$settings['upstream_logout_return_param'] = sanitize_key( (string) $settings['upstream_logout_return_param'] );
		if ( '' === $settings['upstream_logout_return_param'] ) {
			$settings['upstream_logout_return_param'] = 'return_to';
		}
		$settings['auth_failure_limit']          = max( 1, min( 500, absint( $settings['auth_failure_limit'] ) ) );
		$settings['auth_failure_window_minutes'] = max( 1, min( 1440, absint( $settings['auth_failure_window_minutes'] ) ) );
		$settings['audit_log_retention_days']    = max( 1, min( 3650, absint( $settings['audit_log_retention_days'] ) ) );
		$settings['default_destination']          = PIB_Destination::sanitize_destination( $settings['default_destination'], '/dashboard' );
		$settings['allowed_destination_prefixes'] = PIB_Destination::sanitize_prefixes( $settings['allowed_destination_prefixes'] );
		$settings['protected_route_prefixes']     = PIB_Destination::sanitize_prefixes( $settings['protected_route_prefixes'] );
		$settings['spark_launch_allowed_prefixes'] = PIB_Destination::sanitize_prefixes( $settings['spark_launch_allowed_prefixes'] );
		$settings['failure_destination']          = PIB_Destination::sanitize_destination( $settings['failure_destination'], '/portal-login' );
		$settings['sign_in_label']                = sanitize_text_field( (string) $settings['sign_in_label'] );
		$settings['auto_create_users']            = empty( $input['auto_create_users'] ) ? 0 : 1;
		$settings['auto_link_existing_users_by_email'] = empty( $input['auto_link_existing_users_by_email'] ) ? 0 : 1;
		$settings['require_active_mapping']       = empty( $input['require_active_mapping'] ) ? 0 : 1;
		$settings['spark_launch_enabled']         = empty( $input['spark_launch_enabled'] ) ? 0 : 1;
		$settings['backorders_enabled']           = empty( $input['backorders_enabled'] ) ? 0 : 1;
		$settings['periodic_revalidation_enabled'] = empty( $input['periodic_revalidation_enabled'] ) ? 0 : 1;
		$settings['coordinated_logout_enabled']   = empty( $input['coordinated_logout_enabled'] ) ? 0 : 1;
		$settings['audit_logging_enabled']        = empty( $input['audit_logging_enabled'] ) ? 0 : 1;

		$roles_obj                 = wp_roles();
		$role_names                = $roles_obj instanceof WP_Roles ? $roles_obj->get_names() : array();
		$settings['new_user_role'] = sanitize_key( (string) $settings['new_user_role'] );
		if ( ! isset( $role_names[ $settings['new_user_role'] ] ) ) {
			$settings['new_user_role'] = 'subscriber';
		}

		if ( '' === $settings['sign_in_label'] ) {
			$settings['sign_in_label'] = 'Sign in to Portal';
		}

		$this->plugin->refresh_settings_cache();

		return $settings;
	}

	/**
	 * Render full settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Portal Identity Bridge</h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'pib_settings_group' );
				do_settings_sections( 'pib-settings' );
				submit_button();
				?>
			</form>
			<p><strong>Intake URL:</strong> <code><?php echo esc_html( PIB_Plugin::intake_url() ); ?></code></p>
			<p class="description">Shopify/SparkLayer handoff should redirect users to this endpoint with <code>token=...</code>.</p>
		</div>
		<?php
	}
}
