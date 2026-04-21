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
	 * Register plugin settings and grouped schema fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'pib_settings_group',
			PIB_Plugin::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		$sections = PIB_Settings_Contract::sections();
		foreach ( $sections as $section_key => $section ) {
			$section_id   = 'pib_section_' . sanitize_key( (string) $section_key );
			$section_desc = isset( $section['description'] ) ? (string) $section['description'] : '';
			add_settings_section(
				$section_id,
				isset( $section['title'] ) ? (string) $section['title'] : '',
				function() use ( $section_desc ) {
					if ( '' === $section_desc ) {
						return;
					}
					echo '<p>' . esc_html( $section_desc ) . '</p>';
				},
				'pib-settings'
			);
		}

		$schema = PIB_Settings_Contract::schema();
		foreach ( $schema as $key => $definition ) {
			if ( empty( $definition['ui'] ) ) {
				continue;
			}

			$section = isset( $definition['section'] ) ? sanitize_key( (string) $definition['section'] ) : '';
			if ( '' === $section || ! isset( $sections[ $section ] ) ) {
				$section = 'identity_auth_bridge';
			}

			add_settings_field(
				$key,
				isset( $definition['label'] ) ? (string) $definition['label'] : $key,
				array( $this, 'render_schema_field' ),
				'pib-settings',
				'pib_section_' . $section,
				array(
					'key'        => (string) $key,
					'definition' => is_array( $definition ) ? $definition : array(),
				)
			);
		}
	}

	/**
	 * Render a schema-backed settings field.
	 *
	 * @param array<string, mixed> $args Field args.
	 *
	 * @return void
	 */
	public function render_schema_field( $args ) {
		$settings   = $this->plugin->get_settings();
		$key        = isset( $args['key'] ) ? (string) $args['key'] : '';
		$definition = isset( $args['definition'] ) && is_array( $args['definition'] ) ? $args['definition'] : array();
		$type       = isset( $definition['type'] ) ? (string) $definition['type'] : 'text';
		$value      = array_key_exists( $key, $settings ) ? $settings[ $key ] : ( isset( $definition['default'] ) ? $definition['default'] : '' );
		$desc       = isset( $definition['description'] ) ? (string) $definition['description'] : '';

		if ( 'checkbox' === $type ) {
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( PIB_Plugin::OPTION_KEY ),
				esc_attr( $key ),
				checked( true, ! empty( $value ), false ),
				esc_html( $desc )
			);
			return;
		}

		if ( 'prefixes' === $type || 'prefixes_strict' === $type ) {
			$value_text = is_array( $value ) ? implode( "\n", $value ) : '';
			printf(
				'<textarea class="large-text code" rows="6" name="%1$s[%2$s]" placeholder="/dashboard%3$s/materials">%4$s</textarea>',
				esc_attr( PIB_Plugin::OPTION_KEY ),
				esc_attr( $key ),
				esc_html( "\n" ),
				esc_textarea( $value_text )
			);
			if ( '' !== $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			} else {
				echo '<p class="description">One internal path prefix per line. Example: /dashboard</p>';
			}
			return;
		}

		if ( 'role' === $type ) {
			$current   = sanitize_key( (string) $value );
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
			if ( '' !== $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			return;
		}

		$input_type = 'text';
		if ( 'password' === $type ) {
			$input_type = 'password';
		} elseif ( 'url' === $type ) {
			$input_type = 'url';
		} elseif ( 'int_range' === $type ) {
			$input_type = 'number';
		}

		$attributes = '';
		if ( 'int_range' === $type ) {
			$min = isset( $definition['min'] ) ? absint( $definition['min'] ) : 0;
			$max = isset( $definition['max'] ) ? absint( $definition['max'] ) : 0;
			$attributes .= ' step="1"';
			$attributes .= ' min="' . esc_attr( (string) $min ) . '"';
			if ( $max > 0 ) {
				$attributes .= ' max="' . esc_attr( (string) $max ) . '"';
			}
		}

		printf(
			'<input type="%1$s" class="regular-text" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" autocomplete="off"%6$s />',
			esc_attr( $input_type ),
			esc_attr( PIB_Plugin::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			esc_attr( isset( $definition['placeholder'] ) ? (string) $definition['placeholder'] : '' ),
			$attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Sanitize and validate settings on save.
	 *
	 * Settings are persisted even when validation reports critical issues so
	 * administrators can save incremental setup progress.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( PIB_Plugin::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$input    = is_array( $input ) ? $input : array();

		$candidate           = PIB_Settings_Contract::merged_candidate_for_save( $input, $existing );
		$normalized          = PIB_Settings_Contract::normalize( $candidate, $existing );
		$validation          = PIB_Settings_Contract::validate( $normalized, $candidate );

		$this->push_settings_messages( $validation );
		$this->plugin->refresh_settings_cache();

		if ( ! empty( $validation['critical_errors'] ) ) {
			add_settings_error(
				'pib_settings_group',
				'pib_saved_with_issues',
				'Settings were saved, but some required fields still need attention before login flow will work.',
				'warning'
			);
		}

		return $normalized;
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

		$settings    = $this->plugin->get_settings();
		$token_test  = $this->maybe_validate_token( $settings );
		$diagnostics = PIB_Settings_Contract::diagnostics( $settings );
		$requested_tool = sanitize_key( $this->request_scalar_text( 'pib_tool' ) );
		$open_troubleshooting = '';
		$open_token_tool      = '';
		$open_diagnostics_tool = '';
		if ( is_array( $token_test ) ) {
			$open_troubleshooting = ' open';
			$open_token_tool      = ' open';
		}
		if ( 'token' === $requested_tool ) {
			$open_troubleshooting = ' open';
			$open_token_tool      = ' open';
		}
		if ( 'diagnostics' === $requested_tool ) {
			$open_troubleshooting = ' open';
			$open_diagnostics_tool = ' open';
		}
		$core_keys = $this->core_setup_keys();
		$this->render_settings_page_assets();
		?>
		<div class="wrap pib-settings-wrap">
			<h1>Portal Login Setup</h1>
			<?php $this->render_bridge_simulator_notice(); ?>
			<?php settings_errors( 'pib_settings_group' ); ?>
			<form action="options.php" method="post" class="pib-settings-form">
				<?php
				settings_fields( 'pib_settings_group' );
				$this->render_core_setup_panel( $settings );
				?>
				<details class="pib-settings-section">
					<summary>Advanced Settings (Optional)</summary>
					<div class="pib-settings-section-body">
						<p class="pib-quiet-note">Leave these unchanged unless you need custom behavior.</p>
						<?php $this->render_settings_sections_accordion( $settings, $core_keys ); ?>
					</div>
				</details>
				<?php
				submit_button( 'Save Settings' );
				?>
			</form>
			<details class="pib-tool-card"<?php echo $open_troubleshooting; ?>>
				<summary>Troubleshooting (Optional)</summary>
				<div class="pib-tool-body">
					<details class="pib-tool-nested"<?php echo $open_token_tool; ?>>
						<summary>Token Check</summary>
						<div class="pib-tool-body">
							<?php $this->render_token_test_panel( $token_test, true ); ?>
						</div>
					</details>
					<details class="pib-tool-nested"<?php echo $open_diagnostics_tool; ?>>
						<summary>Diagnostics</summary>
						<div class="pib-tool-body">
							<?php $this->render_diagnostics_panel( $diagnostics, true ); ?>
						</div>
					</details>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render lightweight page styles for better settings UX.
	 *
	 * @return void
	 */
	private function render_settings_page_assets() {
		?>
		<style>
			.pib-settings-wrap .pib-core-card,
			.pib-settings-wrap .pib-settings-section,
			.pib-settings-wrap .pib-tool-card,
			.pib-settings-wrap .pib-tool-nested {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				margin: 0 0 12px 0;
			}
			.pib-settings-wrap .pib-settings-section > summary,
			.pib-settings-wrap .pib-tool-card > summary,
			.pib-settings-wrap .pib-tool-nested > summary {
				cursor: pointer;
				padding: 12px 16px;
				font-weight: 600;
			}
			.pib-settings-wrap .pib-settings-section[open] > summary,
			.pib-settings-wrap .pib-tool-card[open] > summary,
			.pib-settings-wrap .pib-tool-nested[open] > summary {
				border-bottom: 1px solid #dcdcde;
			}
			.pib-settings-wrap .pib-core-head {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 10px;
				padding: 12px 16px 0 16px;
			}
			.pib-settings-wrap .pib-core-head h2 {
				margin: 0;
				font-size: 18px;
				line-height: 1.2;
			}
			.pib-settings-wrap .pib-pill {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 999px;
				font-size: 12px;
				line-height: 1.7;
				border: 1px solid #c5d9ed;
				white-space: nowrap;
			}
			.pib-settings-wrap .pib-pill--ok {
				background: #edfaef;
				border-color: #b8d7be;
				color: #185f2d;
			}
			.pib-settings-wrap .pib-pill--warn {
				background: #fff8e5;
				border-color: #e7c47d;
				color: #7a4b00;
			}
			.pib-settings-wrap .pib-settings-section-body,
			.pib-settings-wrap .pib-tool-body {
				padding: 8px 16px 16px 16px;
			}
			.pib-settings-wrap .pib-quiet-note {
				margin: 0 0 10px 0;
				color: #646970;
			}
			.pib-settings-wrap .form-table th {
				width: 260px;
			}
			.pib-settings-wrap .form-table td,
			.pib-settings-wrap .form-table th {
				padding-top: 14px;
				padding-bottom: 14px;
			}
			.pib-settings-wrap .regular-text,
			.pib-settings-wrap .large-text.code {
				max-width: 640px;
				width: 100%;
			}
			.pib-settings-wrap .pib-settings-form {
				margin-bottom: 14px;
			}
			.pib-settings-wrap .notice {
				margin-left: 0;
			}
			.pib-settings-wrap .pib-tool-nested {
				margin-top: 10px;
			}
			.pib-settings-wrap .pib-tool-nested .pib-tool-body {
				padding-top: 12px;
			}
			.pib-settings-wrap .pib-test-actions,
			.pib-settings-wrap .pib-section-test-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 10px;
			}
			@media (max-width: 900px) {
				.pib-settings-wrap .pib-core-head {
					display: block;
				}
				.pib-settings-wrap .form-table th,
				.pib-settings-wrap .form-table td {
					display: block;
					width: 100%;
					padding-right: 0;
				}
			}
		</style>
		<?php
	}

	/**
	 * Get required setup field keys shown in simple mode.
	 *
	 * @return array<int, string>
	 */
	private function core_setup_keys() {
		return array(
			'shopify_login_url',
			'client_id',
			'token_secret',
			'expected_issuer',
		);
	}

	/**
	 * Render simple required setup panel.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 *
	 * @return void
	 */
	private function render_core_setup_panel( $settings ) {
		$settings  = is_array( $settings ) ? $settings : array();
		$schema    = PIB_Settings_Contract::schema();
		$core_keys = $this->core_setup_keys();
		$complete  = 0;

		foreach ( $core_keys as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
			if ( $this->is_setting_filled( $key, $settings, $schema[ $key ] ) ) {
				$complete++;
			}
		}

		$total      = count( $core_keys );
		$pill_class = $complete === $total ? 'pib-pill pib-pill--ok' : 'pib-pill pib-pill--warn';
		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$shopify_test_url = $this->build_shopify_test_url( $settings, $default_dest );
		$bridge_test_url  = $this->build_bridge_simulator_quick_url( $settings, $default_dest );
		$token_tool_url   = $this->settings_tool_url( 'token' );
		$can_bridge_test  = $this->can_run_bridge_simulator_quick( $settings );
		?>
		<section class="pib-core-card">
			<div class="pib-core-head">
				<h2>Required Setup</h2>
				<span class="<?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( (string) $complete ); ?>/<?php echo esc_html( (string) $total ); ?> complete</span>
			</div>
			<div class="pib-settings-section-body">
				<p class="pib-quiet-note">Only these 4 fields are required. Fill them, save, and test login.</p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $core_keys as $key ) :
							if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
								continue;
							}
							?>
							<tr>
								<th scope="row"><?php echo esc_html( isset( $schema[ $key ]['label'] ) ? (string) $schema[ $key ]['label'] : $key ); ?></th>
								<td><?php $this->render_schema_field( array( 'key' => $key, 'definition' => $schema[ $key ] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><strong>WordPress Intake URL:</strong> <code><?php echo esc_html( PIB_Plugin::intake_url() ); ?></code></p>
				<div class="pib-test-actions">
					<?php if ( '' !== $shopify_test_url ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $shopify_test_url ); ?>" target="_blank" rel="noopener noreferrer">Test Shopify Login</a>
					<?php else : ?>
						<button class="button button-primary" type="button" disabled>Test Shopify Login</button>
					<?php endif; ?>
					<?php if ( '' !== $bridge_test_url && $can_bridge_test ) : ?>
						<a class="button" href="<?php echo esc_url( $bridge_test_url ); ?>">Run Internal Token Test</a>
					<?php else : ?>
						<button class="button" type="button" disabled>Run Internal Token Test</button>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( $token_tool_url ); ?>">Open Token Check</a>
				</div>
				<?php if ( ! $can_bridge_test ) : ?>
					<p class="description">Internal token test needs at least one active customer mapping in this WordPress site.</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render settings sections as accordions for advanced configuration.
	 *
	 * @param array<string, mixed> $settings      Current settings.
	 * @param array<int, string>   $excluded_keys Keys not to render.
	 *
	 * @return void
	 */
	private function render_settings_sections_accordion( $settings, $excluded_keys = array() ) {
		$sections      = PIB_Settings_Contract::sections();
		$schema        = PIB_Settings_Contract::schema();
		$settings      = is_array( $settings ) ? $settings : array();
		$excluded_keys = is_array( $excluded_keys ) ? array_map( 'strval', $excluded_keys ) : array();

		foreach ( $sections as $section_key => $section ) {
			$section_key = sanitize_key( (string) $section_key );
			$title       = isset( $section['title'] ) ? (string) $section['title'] : $section_key;
			$desc        = isset( $section['description'] ) ? (string) $section['description'] : '';
			$fields      = array();

			foreach ( $schema as $field_key => $definition ) {
				if ( ! is_array( $definition ) || empty( $definition['ui'] ) ) {
					continue;
				}
				if ( in_array( (string) $field_key, $excluded_keys, true ) ) {
					continue;
				}

				$field_section = isset( $definition['section'] ) ? sanitize_key( (string) $definition['section'] ) : 'identity_auth_bridge';
				if ( $field_section !== $section_key ) {
					continue;
				}

				$fields[ (string) $field_key ] = $definition;
			}

			if ( empty( $fields ) ) {
				continue;
			}
			?>
			<details class="pib-settings-section">
				<summary><?php echo esc_html( $title ); ?></summary>
				<div class="pib-settings-section-body">
					<?php if ( '' !== $desc ) : ?>
						<p class="pib-quiet-note"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<tbody>
							<?php foreach ( $fields as $field_key => $definition ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( isset( $definition['label'] ) ? (string) $definition['label'] : (string) $field_key ); ?></th>
									<td><?php $this->render_schema_field( array( 'key' => $field_key, 'definition' => $definition ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php $this->render_section_test_actions( $section_key, $settings ); ?>
				</div>
			</details>
			<?php
		}
	}

	/**
	 * Render section-specific quick test actions.
	 *
	 * @param string               $section_key Section key.
	 * @param array<string, mixed> $settings    Current settings.
	 *
	 * @return void
	 */
	private function render_section_test_actions( $section_key, $settings ) {
		$section_key    = sanitize_key( (string) $section_key );
		$settings       = is_array( $settings ) ? $settings : array();
		$default_dest   = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$shopify_test   = $this->build_shopify_test_url( $settings, $default_dest );
		$bridge_test    = $this->build_bridge_simulator_quick_url( $settings, $default_dest );
		$token_tool_url = $this->settings_tool_url( 'token' );
		$diag_tool_url  = $this->settings_tool_url( 'diagnostics' );
		$can_bridge_test = $this->can_run_bridge_simulator_quick( $settings );

		$actions = array();

		if ( 'identity_auth_bridge' === $section_key ) {
			if ( '' !== $shopify_test ) {
				$actions[] = '<a class="button button-secondary" href="' . esc_url( $shopify_test ) . '" target="_blank" rel="noopener noreferrer">Test Shopify Login</a>';
			}
			$actions[] = '<a class="button" href="' . esc_url( $token_tool_url ) . '">Open Token Check</a>';
		} elseif ( 'routing_access' === $section_key ) {
			if ( '' !== $shopify_test ) {
				$actions[] = '<a class="button button-secondary" href="' . esc_url( $shopify_test ) . '" target="_blank" rel="noopener noreferrer">Test Default Destination</a>';
			}
		} elseif ( 'user_mapping' === $section_key ) {
			if ( '' !== $bridge_test && $can_bridge_test ) {
				$actions[] = '<a class="button button-secondary" href="' . esc_url( $bridge_test ) . '">Run Mapping Test Token</a>';
			}
			$actions[] = '<a class="button" href="' . esc_url( $token_tool_url ) . '">Review Mapping in Token Check</a>';
		} elseif ( 'session_logout' === $section_key ) {
			if ( class_exists( 'PIB_Session_Policy' ) ) {
				$policy = PIB_Session_Policy::instance( $this->plugin );
				if ( $policy instanceof PIB_Session_Policy && is_user_logged_in() ) {
					$actions[] = '<a class="button button-secondary" href="' . esc_url( $policy->logout_url() ) . '">Test Logout</a>';
				}
			}
		} elseif ( 'backorders' === $section_key ) {
			$backorders_dest = '/dashboard';
			$allowed         = isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array();
			if ( ! PIB_Destination::is_allowed( $backorders_dest, $allowed ) ) {
				$backorders_dest = $default_dest;
			}
			$backorders_test = $this->build_shopify_test_url( $settings, $backorders_dest );
			if ( '' !== $backorders_test ) {
				$actions[] = '<a class="button button-secondary" href="' . esc_url( $backorders_test ) . '" target="_blank" rel="noopener noreferrer">Test Backorders Access</a>';
			}
		} elseif ( 'operations_audit' === $section_key ) {
			$actions[] = '<a class="button button-secondary" href="' . esc_url( $diag_tool_url ) . '">Open Diagnostics</a>';
		}

		if ( empty( $actions ) ) {
			return;
		}

		echo '<div class="pib-section-test-actions">' . implode( '', $actions ) . '</div>';
	}

	/**
	 * Build Shopify test URL for one-click login validation.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param string               $dest     Optional destination.
	 *
	 * @return string
	 */
	private function build_shopify_test_url( $settings, $dest = '' ) {
		$settings = is_array( $settings ) ? $settings : array();

		if ( class_exists( 'PIB_Signin_Link' ) ) {
			$signin = PIB_Signin_Link::instance( $this->plugin );
			if ( $signin instanceof PIB_Signin_Link ) {
				$built = $signin->build_signin_url( (string) $dest );
				if ( is_string( $built ) && '' !== trim( $built ) ) {
					return $built;
				}
			}
		}

		$shopify_url = isset( $settings['shopify_login_url'] ) ? trim( (string) $settings['shopify_login_url'] ) : '';
		if ( '' === $shopify_url ) {
			return '';
		}

		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$dest         = PIB_Destination::sanitize_destination( (string) $dest, $default_dest );
		$allowed      = isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array();
		if ( ! PIB_Destination::is_allowed( $dest, $allowed ) ) {
			$dest = $default_dest;
		}

		$query_args = array(
			'return_url' => PIB_Plugin::intake_url(),
			'intake'     => PIB_Plugin::intake_url(),
			'dest'       => $dest,
			'source'     => 'partner_portal',
		);

		$audience = $this->expected_audience( $settings );
		if ( '' !== $audience ) {
			$query_args['client_id'] = $audience;
		}

		return add_query_arg( $query_args, $shopify_url );
	}

	/**
	 * Build quick internal bridge simulator URL.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param string               $dest     Optional destination.
	 *
	 * @return string
	 */
	private function build_bridge_simulator_quick_url( $settings, $dest = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$settings     = is_array( $settings ) ? $settings : array();
		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$dest         = PIB_Destination::sanitize_destination( (string) $dest, $default_dest );
		$allowed      = isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array();
		if ( ! PIB_Destination::is_allowed( $dest, $allowed ) ) {
			$dest = $default_dest;
		}

		$url = add_query_arg(
			array(
				'action'          => 'pib_bridge_simulate',
				'pib_bridge_dest' => $dest,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'pib_bridge_simulate' );
	}

	/**
	 * Determine whether quick bridge simulator test can run.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 *
	 * @return bool
	 */
	private function can_run_bridge_simulator_quick( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		if ( ! $this->is_core_setup_complete( $settings ) ) {
			return false;
		}

		$defaults = $this->default_bridge_identity();
		$customer = isset( $defaults['shopify_customer_id'] ) ? trim( (string) $defaults['shopify_customer_id'] ) : '';
		$company  = isset( $defaults['company_id'] ) ? trim( (string) $defaults['company_id'] ) : '';

		if ( '' === $customer ) {
			return false;
		}
		if ( ! empty( $settings['require_company_id'] ) && '' === $company ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether all required setup fields are configured.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 *
	 * @return bool
	 */
	private function is_core_setup_complete( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$schema   = PIB_Settings_Contract::schema();

		foreach ( $this->core_setup_keys() as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				return false;
			}
			if ( ! $this->is_setting_filled( $key, $settings, $schema[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build settings page URL with optional troubleshooting tool selector.
	 *
	 * @param string $tool Optional tool key.
	 *
	 * @return string
	 */
	private function settings_tool_url( $tool = '' ) {
		$url = add_query_arg(
			array(
				'page' => 'pib-settings',
			),
			admin_url( 'options-general.php' )
		);

		$tool = sanitize_key( (string) $tool );
		if ( '' !== $tool ) {
			$url = add_query_arg(
				array(
					'pib_tool' => $tool,
				),
				$url
			);
		}

		return $url;
	}

	/**
	 * Determine whether a settings value should be treated as configured.
	 *
	 * @param string               $key       Setting key.
	 * @param array<string, mixed> $settings  Current settings.
	 * @param array<string, mixed> $definition Field definition.
	 *
	 * @return bool
	 */
	private function is_setting_filled( $key, $settings, $definition ) {
		$settings   = is_array( $settings ) ? $settings : array();
		$definition = is_array( $definition ) ? $definition : array();
		$value      = array_key_exists( $key, $settings )
			? $settings[ $key ]
			: ( isset( $definition['default'] ) ? $definition['default'] : '' );

		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return '' !== (string) $value;
		}

		if ( is_scalar( $value ) ) {
			return '' !== trim( (string) $value );
		}

		return ! empty( $value );
	}

	/**
	 * Render top-level setup status summary.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 *
	 * @return void
	 */
	private function render_setup_status_panel( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$client_id = isset( $settings['client_id'] ) ? trim( (string) $settings['client_id'] ) : '';
		if ( '' === $client_id && isset( $settings['expected_audience'] ) ) {
			$client_id = trim( (string) $settings['expected_audience'] );
		}

		$checks = array(
			'Shopify Portal URL' => ! empty( $settings['shopify_login_url'] ),
			'Client ID'          => '' !== $client_id,
			'Shared Secret'      => ! empty( $settings['token_secret'] ),
			'Issuer (iss)'       => ! empty( $settings['expected_issuer'] ),
		);

		$complete_count = 0;
		foreach ( $checks as $is_ready ) {
			if ( $is_ready ) {
				$complete_count++;
			}
		}

		$total_count   = count( $checks );
		$is_connected  = $complete_count === $total_count;
		$status_label  = $is_connected ? 'Connected' : 'Setup Needed';
		$status_notice = $is_connected
			? 'Required bridge credentials are configured.'
			: 'Add the required fields below to enable token login from Shopify.';

		echo '<div class="notice notice-info" style="margin:16px 0 18px 0;padding:12px 14px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Setup Status:</strong> ' . esc_html( $status_label ) . ' (' . esc_html( (string) $complete_count ) . '/' . esc_html( (string) $total_count ) . ')</p>';
		echo '<p style="margin:0 0 10px 0;">' . esc_html( $status_notice ) . '</p>';
		echo '<p style="margin:0 0 8px 0;"><strong>WordPress Login Endpoint:</strong> <code>' . esc_html( PIB_Plugin::intake_url() ) . '</code></p>';
		echo '<ul style="margin:0 0 0 16px;list-style:disc;">';
		foreach ( $checks as $label => $is_ready ) {
			$prefix = $is_ready ? 'Ready' : 'Missing';
			echo '<li><strong>' . esc_html( $prefix ) . ':</strong> ' . esc_html( (string) $label ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Handle optional token-validation form submit.
	 *
	 * @param array<string, mixed> $settings Current plugin settings.
	 *
	 * @return array<string, mixed>|null
	 */
	private function maybe_validate_token( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return null;
		}

		$action = $this->request_scalar_text( 'pib_action' );
		if ( 'validate_token' !== $action ) {
			return null;
		}

		$nonce = $this->request_scalar_text( 'pib_validate_token_nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'pib_validate_token' ) ) {
			return array(
				'token'    => '',
				'overall'  => array(
					'status'  => 'error',
					'message' => 'Security check failed. Reload the page and try token validation again.',
				),
				'checks'   => array(),
				'claims'   => array(),
				'mapping'  => array(
					'status'  => 'error',
					'message' => 'Token validation did not run.',
				),
			);
		}

		$token = '';
		if ( isset( $_POST['pib_test_token'] ) && is_scalar( $_POST['pib_test_token'] ) ) {
			$token = (string) wp_unslash( $_POST['pib_test_token'] );
		}
		$token = preg_replace( '/\s+/', '', $token );
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return array(
				'token'    => '',
				'overall'  => array(
					'status'  => 'error',
					'message' => 'Paste a JWT token to validate.',
				),
				'checks'   => array(),
				'claims'   => array(),
				'mapping'  => array(
					'status'  => 'error',
					'message' => 'No token was provided.',
				),
			);
		}

		return $this->build_token_test_result( $token, $settings );
	}

	/**
	 * Build token validation diagnostics payload.
	 *
	 * @param string               $token    JWT token.
	 * @param array<string, mixed> $settings Current plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function build_token_test_result( $token, $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$token    = trim( (string) $token );

		$result = array(
			'token'   => $token,
			'overall' => array(
				'status'  => 'error',
				'message' => 'Token validation failed.',
			),
			'checks'  => array(),
			'claims'  => array(),
			'mapping' => array(
				'status'  => 'error',
				'message' => 'User mapping was not evaluated.',
			),
		);

		$parsed = $this->parse_jwt_for_test( $token );
		if ( is_wp_error( $parsed ) ) {
			$result['checks'][] = array(
				'label'   => 'Token format',
				'status'  => 'fail',
				'message' => $parsed->get_error_message(),
			);
			$result['overall']['message'] = 'Token is not in JWT format (`header.payload.signature`).';
			return $result;
		}

		$claims  = isset( $parsed['claims'] ) && is_array( $parsed['claims'] ) ? $parsed['claims'] : array();
		$header  = isset( $parsed['header'] ) && is_array( $parsed['header'] ) ? $parsed['header'] : array();
		$now     = time();
		$leeway  = isset( $settings['leeway_seconds'] ) ? absint( $settings['leeway_seconds'] ) : 30;
		$secret  = isset( $settings['token_secret'] ) ? trim( (string) $settings['token_secret'] ) : '';
		$issuer  = isset( $settings['expected_issuer'] ) ? trim( (string) $settings['expected_issuer'] ) : '';
		$aud_exp = $this->expected_audience( $settings );

		$result['claims'] = array(
			'iss'                 => isset( $claims['iss'] ) ? (string) $claims['iss'] : '',
			'aud'                 => $this->display_audience_claim( isset( $claims['aud'] ) ? $claims['aud'] : '' ),
			'exp'                 => isset( $claims['exp'] ) ? (string) $claims['exp'] : '',
			'jti'                 => isset( $claims['jti'] ) ? (string) $claims['jti'] : '',
			'shopify_customer_id' => PIB_Customer_Mapping::customer_id_from_claims( $claims ),
			'company_id'          => PIB_Customer_Mapping::company_id_from_claims( $claims ),
			'email'               => isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '',
		);

		$alg = isset( $header['alg'] ) ? (string) $header['alg'] : '';
		if ( 'HS256' !== $alg ) {
			$result['checks'][] = array(
				'label'   => 'Signature',
				'status'  => 'fail',
				'message' => 'JWT header `alg` must be HS256.',
			);
			$signature_ok = false;
		} elseif ( '' === $secret ) {
			$result['checks'][] = array(
				'label'   => 'Signature',
				'status'  => 'warn',
				'message' => 'Shared Secret is not configured in settings.',
			);
			$signature_ok = false;
		} else {
			$expected_binary = hash_hmac( 'sha256', (string) $parsed['signing_input'], $secret, true );
			$actual_binary   = isset( $parsed['signature_binary'] ) ? (string) $parsed['signature_binary'] : '';
			$signature_ok    = '' !== $actual_binary && hash_equals( $expected_binary, $actual_binary );
			$result['checks'][] = array(
				'label'   => 'Signature',
				'status'  => $signature_ok ? 'pass' : 'fail',
				'message' => $signature_ok ? 'Signature matches Shared Secret.' : 'Signature does not match Shared Secret.',
			);
		}

		$token_issuer = isset( $claims['iss'] ) ? trim( (string) $claims['iss'] ) : '';
		$issuer_ok    = false;
		if ( '' === $issuer ) {
			$result['checks'][] = array(
				'label'   => 'Issuer (iss)',
				'status'  => 'warn',
				'message' => 'Issuer is not configured in settings.',
			);
		} else {
			$issuer_ok = hash_equals( $issuer, $token_issuer );
			$result['checks'][] = array(
				'label'   => 'Issuer (iss)',
				'status'  => $issuer_ok ? 'pass' : 'fail',
				'message' => $issuer_ok ? 'Issuer matches settings.' : 'Issuer does not match settings.',
			);
		}

		$aud_ok = false;
		if ( '' === $aud_exp ) {
			$result['checks'][] = array(
				'label'   => 'Audience (aud)',
				'status'  => 'warn',
				'message' => 'Client ID / expected audience is not configured.',
			);
		} else {
			$aud_ok = $this->audience_matches_expected( isset( $claims['aud'] ) ? $claims['aud'] : '', $aud_exp );
			$result['checks'][] = array(
				'label'   => 'Audience (aud)',
				'status'  => $aud_ok ? 'pass' : 'fail',
				'message' => $aud_ok ? 'Audience includes configured Client ID.' : 'Audience does not include configured Client ID.',
			);
		}

		$exp_ok      = false;
		$exp_message = 'Token is missing a numeric `exp` claim.';
		if ( isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ) {
			$exp_time = (int) $claims['exp'];
			$exp_ok   = ( $exp_time + $leeway ) >= $now;
			$exp_utc  = gmdate( 'Y-m-d H:i:s', $exp_time ) . ' UTC';
			$exp_message = $exp_ok
				? 'Token is within expiry window (exp: ' . $exp_utc . ').'
				: 'Token is expired (exp: ' . $exp_utc . ').';
		}
		$result['checks'][] = array(
			'label'   => 'Expiry (exp)',
			'status'  => $exp_ok ? 'pass' : 'fail',
			'message' => $exp_message,
		);

		$jti_ok = isset( $claims['jti'] ) && is_string( $claims['jti'] ) && '' !== trim( $claims['jti'] );
		$result['checks'][] = array(
			'label'   => 'Replay ID (jti)',
			'status'  => $jti_ok ? 'pass' : 'fail',
			'message' => $jti_ok ? 'Token contains `jti` claim.' : 'Token is missing `jti` claim.',
		);

		$nbf_ok = true;
		if ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) ) {
			$nbf_time = (int) $claims['nbf'];
			$nbf_ok   = ( $nbf_time - $leeway ) <= $now;
			$nbf_utc  = gmdate( 'Y-m-d H:i:s', $nbf_time ) . ' UTC';
			$result['checks'][] = array(
				'label'   => 'Not Before (nbf)',
				'status'  => $nbf_ok ? 'pass' : 'fail',
				'message' => $nbf_ok ? 'Token is active for nbf window.' : 'Token is not active yet (nbf: ' . $nbf_utc . ').',
			);
		}

		$iat_ok = true;
		if ( isset( $claims['iat'] ) && is_numeric( $claims['iat'] ) ) {
			$iat_time = (int) $claims['iat'];
			$iat_ok   = ( $iat_time - $leeway ) <= $now;
			$iat_utc  = gmdate( 'Y-m-d H:i:s', $iat_time ) . ' UTC';
			$result['checks'][] = array(
				'label'   => 'Issued At (iat)',
				'status'  => $iat_ok ? 'pass' : 'fail',
				'message' => $iat_ok ? 'Token iat is valid.' : 'Token iat is in the future (iat: ' . $iat_utc . ').',
			);
		}

		$core_valid = $signature_ok && $issuer_ok && $aud_ok && $exp_ok && $jti_ok && $nbf_ok && $iat_ok;
		$result['mapping'] = $this->mapping_preview_result( $claims, $settings );

		$mapping_status = isset( $result['mapping']['status'] ) ? (string) $result['mapping']['status'] : 'error';
		if ( $core_valid && 'pass' === $mapping_status ) {
			$result['overall'] = array(
				'status'  => 'success',
				'message' => 'Token checks passed and an active WordPress mapping was found.',
			);
		} elseif ( $core_valid && 'warn' === $mapping_status ) {
			$result['overall'] = array(
				'status'  => 'warning',
				'message' => 'Token checks passed. Login may still work through auto-link or auto-create behavior.',
			);
		} else {
			$result['overall'] = array(
				'status'  => 'error',
				'message' => 'Token is not ready for intake with current settings.',
			);
		}

		return $result;
	}

	/**
	 * Parse JWT into decoded header/claims and signature binary.
	 *
	 * @param string $token JWT token.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse_jwt_for_test( $token ) {
		$token = trim( (string) $token );
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'pib_invalid_token_parts', 'Token must have exactly 3 JWT segments.' );
		}

		$header_raw = $this->base64url_decode( (string) $parts[0] );
		$claims_raw = $this->base64url_decode( (string) $parts[1] );
		$sig_raw    = $this->base64url_decode( (string) $parts[2] );

		if ( null === $header_raw || null === $claims_raw || null === $sig_raw ) {
			return new WP_Error( 'pib_invalid_token_encoding', 'Token contains invalid base64url encoding.' );
		}

		$header = json_decode( $header_raw, true );
		$claims = json_decode( $claims_raw, true );
		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new WP_Error( 'pib_invalid_token_json', 'Token header or payload is not valid JSON.' );
		}

		return array(
			'header'           => $header,
			'claims'           => $claims,
			'signing_input'    => $parts[0] . '.' . $parts[1],
			'signature_binary' => $sig_raw,
		);
	}

	/**
	 * Decode base64url string.
	 *
	 * @param string $value Base64url value.
	 *
	 * @return string|null
	 */
	private function base64url_decode( $value ) {
		$value = str_replace( array( '-', '_' ), array( '+', '/' ), (string) $value );
		$pad   = strlen( $value ) % 4;
		if ( $pad > 0 ) {
			$value .= str_repeat( '=', 4 - $pad );
		}

		$decoded = base64_decode( $value, true );
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Resolve expected audience string from settings.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 *
	 * @return string
	 */
	private function expected_audience( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$audience = isset( $settings['client_id'] ) ? trim( (string) $settings['client_id'] ) : '';
		if ( '' === $audience && isset( $settings['expected_audience'] ) ) {
			$audience = trim( (string) $settings['expected_audience'] );
		}

		return $audience;
	}

	/**
	 * Determine whether aud claim matches expected value.
	 *
	 * @param mixed  $audience_claim    Token aud claim.
	 * @param string $expected_audience Expected audience.
	 *
	 * @return bool
	 */
	private function audience_matches_expected( $audience_claim, $expected_audience ) {
		$expected_audience = trim( (string) $expected_audience );
		if ( '' === $expected_audience ) {
			return false;
		}

		$audiences = array();
		if ( is_string( $audience_claim ) ) {
			$audiences[] = trim( $audience_claim );
		} elseif ( is_array( $audience_claim ) ) {
			foreach ( $audience_claim as $aud_item ) {
				if ( is_string( $aud_item ) ) {
					$audiences[] = trim( $aud_item );
				}
			}
		}

		return in_array( $expected_audience, $audiences, true );
	}

	/**
	 * Convert audience claim to display text.
	 *
	 * @param mixed $audience_claim Token audience claim.
	 *
	 * @return string
	 */
	private function display_audience_claim( $audience_claim ) {
		if ( is_string( $audience_claim ) ) {
			return trim( $audience_claim );
		}

		if ( is_array( $audience_claim ) ) {
			$items = array();
			foreach ( $audience_claim as $aud_item ) {
				if ( is_string( $aud_item ) ) {
					$items[] = trim( $aud_item );
				}
			}

			return implode( ', ', $items );
		}

		return '';
	}

	/**
	 * Build mapping preview result for decoded claims.
	 *
	 * @param array<string, mixed> $claims   Token claims.
	 * @param array<string, mixed> $settings Current plugin settings.
	 *
	 * @return array<string, string>
	 */
	private function mapping_preview_result( $claims, $settings ) {
		$claims   = is_array( $claims ) ? $claims : array();
		$settings = is_array( $settings ) ? $settings : array();

		$customer_id = PIB_Customer_Mapping::customer_id_from_claims( $claims );
		$company_id  = PIB_Customer_Mapping::company_id_from_claims( $claims );
		$email       = isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';

		if ( '' === $customer_id ) {
			return array(
				'status'  => 'error',
				'message' => 'Missing `shopify_customer_id`/`customer_id` claim.',
			);
		}

		if ( ! empty( $settings['require_company_id'] ) && '' === $company_id ) {
			return array(
				'status'  => 'error',
				'message' => 'Company ID is required but missing from token.',
			);
		}

		global $wpdb;
		$table = PIB_Customer_Mapping::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, wp_user_id, status FROM {$table} WHERE shopify_customer_id = %s AND company_id = %s LIMIT 1",
				$customer_id,
				$company_id
			),
			ARRAY_A
		);

		if ( is_array( $row ) ) {
			$status  = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$user_id = isset( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : 0;
			$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

			if ( 'active' !== $status ) {
				return array(
					'status'  => 'error',
					'message' => 'Mapping exists but is not active (' . $status . ').',
				);
			}

			if ( $user instanceof WP_User ) {
				return array(
					'status'  => 'pass',
					'message' => 'Active mapping found for user `' . $user->user_login . '` (#' . $user->ID . ').',
				);
			}

			return array(
				'status'  => 'error',
				'message' => 'Mapping exists but linked WordPress user was not found.',
			);
		}

		$auto_link   = ! empty( $settings['auto_link_existing_users_by_email'] );
		$auto_create = ! empty( $settings['auto_create_users'] );

		if ( $auto_link && '' !== $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof WP_User ) {
				return array(
					'status'  => 'warn',
					'message' => 'No mapping record yet. Auto-link may connect to `' . $user->user_login . '` by email.',
				);
			}
		}

		if ( $auto_create && '' !== $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( ! $user instanceof WP_User ) {
				return array(
					'status'  => 'warn',
					'message' => 'No mapping record yet. Auto-create may create a new WordPress user for this email.',
				);
			}
		}

		return array(
			'status'  => 'error',
			'message' => 'No active mapping found for this customer/company.',
		);
	}

	/**
	 * Render token validation panel and output latest result.
	 *
	 * @param array<string, mixed>|null $token_test Token diagnostics payload.
	 * @param bool                      $embedded   True when rendered inside tools accordion card.
	 *
	 * @return void
	 */
	private function render_token_test_panel( $token_test, $embedded = false ) {
		$token_test = is_array( $token_test ) ? $token_test : null;
		$token      = is_array( $token_test ) && isset( $token_test['token'] ) ? (string) $token_test['token'] : '';

		if ( ! $embedded ) {
			echo '<hr />';
			echo '<h2>Token Validation Tool</h2>';
		}
		echo '<p>Paste a Shopify bridge JWT to test signature, issuer, audience, expiry, and current WordPress mapping behavior. This check is read-only and does not create users or mappings.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options-general.php?page=pib-settings' ) ) . '">';
		wp_nonce_field( 'pib_validate_token', 'pib_validate_token_nonce' );
		echo '<input type="hidden" name="pib_action" value="validate_token" />';
		echo '<textarea class="large-text code" rows="5" name="pib_test_token" placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">' . esc_textarea( $token ) . '</textarea>';
		echo '<p style="margin-top:8px;">';
		submit_button( 'Validate Token', 'secondary', 'submit', false );
		echo '</p>';
		echo '</form>';

		if ( ! is_array( $token_test ) ) {
			return;
		}

		$overall        = isset( $token_test['overall'] ) && is_array( $token_test['overall'] ) ? $token_test['overall'] : array();
		$overall_status = isset( $overall['status'] ) ? sanitize_key( (string) $overall['status'] ) : 'error';
		$overall_msg    = isset( $overall['message'] ) ? (string) $overall['message'] : '';

		$overall_class = 'notice notice-error';
		if ( 'success' === $overall_status ) {
			$overall_class = 'notice notice-success';
		} elseif ( 'warning' === $overall_status ) {
			$overall_class = 'notice notice-warning';
		}
		echo '<div class="' . esc_attr( $overall_class ) . '" style="margin:12px 0 10px 0;"><p><strong>Validation Result:</strong> ' . esc_html( $overall_msg ) . '</p></div>';

		$checks = isset( $token_test['checks'] ) && is_array( $token_test['checks'] ) ? $token_test['checks'] : array();
		if ( ! empty( $checks ) ) {
			echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr><th style="width:220px;">Check</th><th style="width:120px;">Status</th><th>Details</th></tr></thead><tbody>';
			foreach ( $checks as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}

				$label   = isset( $check['label'] ) ? (string) $check['label'] : '';
				$status  = isset( $check['status'] ) ? sanitize_key( (string) $check['status'] ) : 'fail';
				$message = isset( $check['message'] ) ? (string) $check['message'] : '';
				$status_label = 'Fail';
				if ( 'pass' === $status ) {
					$status_label = 'Pass';
				} elseif ( 'warn' === $status ) {
					$status_label = 'Warn';
				}

				echo '<tr>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td><strong>' . esc_html( $status_label ) . '</strong></td>';
				echo '<td>' . esc_html( $message ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$mapping = isset( $token_test['mapping'] ) && is_array( $token_test['mapping'] ) ? $token_test['mapping'] : array();
		$map_msg = isset( $mapping['message'] ) ? (string) $mapping['message'] : '';
		$map_status = isset( $mapping['status'] ) ? sanitize_key( (string) $mapping['status'] ) : 'error';
		if ( '' !== $map_msg ) {
			$map_label = 'Fail';
			if ( 'pass' === $map_status ) {
				$map_label = 'Pass';
			} elseif ( 'warn' === $map_status ) {
				$map_label = 'Warn';
			}
			echo '<p style="margin-top:10px;"><strong>Mapped WP User Result:</strong> [' . esc_html( $map_label ) . '] ' . esc_html( $map_msg ) . '</p>';
		}

		$claims = isset( $token_test['claims'] ) && is_array( $token_test['claims'] ) ? $token_test['claims'] : array();
		if ( ! empty( $claims ) ) {
			echo '<h3>Decoded Claims (Preview)</h3>';
			echo '<table class="widefat striped" style="max-width:1000px;"><tbody>';
			foreach ( $claims as $claim_key => $claim_value ) {
				echo '<tr>';
				echo '<th style="width:260px;">' . esc_html( (string) $claim_key ) . '</th>';
				echo '<td><code>' . esc_html( is_scalar( $claim_value ) ? (string) $claim_value : '' ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Render bridge simulator notice from query args.
	 *
	 * @return void
	 */
	private function render_bridge_simulator_notice() {
		$notice = sanitize_key( $this->request_scalar_text( 'pib_bridge_notice' ) );
		if ( '' === $notice ) {
			return;
		}

		$type = sanitize_key( $this->request_scalar_text( 'pib_bridge_notice_type' ) );
		if ( '' === $type ) {
			$type = 'error';
		}

		$messages = array(
			'missing_secret'      => 'Bridge simulator requires Shared Secret in settings.',
			'missing_issuer'      => 'Bridge simulator requires Issuer (iss) in settings.',
			'missing_client_id'   => 'Bridge simulator requires Client ID in settings.',
			'missing_customer'    => 'Bridge simulator requires Shopify Customer ID.',
			'missing_company'     => 'Company ID is required by your current settings.',
			'token_signing_failed'=> 'Bridge simulator could not sign token payload.',
		);

		$message = isset( $messages[ $notice ] ) ? $messages[ $notice ] : 'Bridge simulator request failed.';
		$class   = 'notice notice-error is-dismissible';

		if ( 'warning' === $type ) {
			$class = 'notice notice-warning is-dismissible';
		} elseif ( 'success' === $type ) {
			$class = 'notice notice-success is-dismissible';
		}

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render test handoff launcher panel.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param bool                 $embedded True when rendered inside tools accordion card.
	 *
	 * @return void
	 */
	private function render_bridge_simulator_panel( $settings, $embedded = false ) {
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = $this->default_bridge_identity();

		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$customer     = isset( $defaults['shopify_customer_id'] ) ? (string) $defaults['shopify_customer_id'] : '';
		$company      = isset( $defaults['company_id'] ) ? (string) $defaults['company_id'] : '';
		$email        = isset( $defaults['email'] ) ? (string) $defaults['email'] : '';
		if ( ! $embedded ) {
			echo '<hr />';
			echo '<h2>Bridge Simulator</h2>';
		}
		?>
		<p>Launch a short-lived test handoff token into WordPress intake. This is admin-only and does not validate live Libratel session cookies.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pib_bridge_simulate' ); ?>
			<input type="hidden" name="action" value="pib_bridge_simulate" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="pib_bridge_customer_id">Shopify Customer ID</label></th>
						<td><input id="pib_bridge_customer_id" class="regular-text" name="pib_bridge_customer_id" value="<?php echo esc_attr( $customer ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pib_bridge_company_id">Company ID</label></th>
						<td><input id="pib_bridge_company_id" class="regular-text" name="pib_bridge_company_id" value="<?php echo esc_attr( $company ); ?>" <?php echo ! empty( $settings['require_company_id'] ) ? 'required' : ''; ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pib_bridge_email">Email (optional)</label></th>
						<td><input id="pib_bridge_email" class="regular-text" name="pib_bridge_email" value="<?php echo esc_attr( $email ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pib_bridge_dest">Destination</label></th>
						<td><input id="pib_bridge_dest" class="regular-text code" name="pib_bridge_dest" value="<?php echo esc_attr( $default_dest ); ?>" /><p class="description">Must match Allowed Destination Prefixes.</p></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( 'Launch Test Handoff', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render production bridge contract details for external app wiring.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param bool                 $embedded True when rendered inside tools accordion card.
	 *
	 * @return void
	 */
	private function render_bridge_contract_panel( $settings, $embedded = false ) {
		$settings      = is_array( $settings ) ? $settings : array();
		$shopify_url   = isset( $settings['shopify_login_url'] ) ? trim( (string) $settings['shopify_login_url'] ) : '';
		$intake_url    = PIB_Plugin::intake_url();
		$default_dest  = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$client_id     = $this->expected_audience( $settings );
		$issuer        = isset( $settings['expected_issuer'] ) ? trim( (string) $settings['expected_issuer'] ) : '';
		$route_hint    = $this->bridge_route_hint( $shopify_url );
		$launch_url    = '';
		$redirect_demo = add_query_arg(
			array(
				'token' => '<jwt>',
				'dest'  => $default_dest,
			),
			$intake_url
		);

		if ( '' !== $shopify_url ) {
			$launch_url = add_query_arg(
				array(
					'return_url' => $intake_url,
					'intake'     => $intake_url,
					'dest'       => $default_dest,
					'client_id'  => $client_id,
					'source'     => 'partner_portal',
				),
				$shopify_url
			);
		}
		if ( ! $embedded ) {
			echo '<hr />';
			echo '<h2>Bridge Contract</h2>';
		}
		?>
		<p>Use this exact contract when wiring the real Shopify app route (for example, <code>/apps/portal-bridge</code>).</p>
		<?php if ( '' !== $route_hint ) : ?>
			<div class="notice notice-warning" style="margin:8px 0 10px 0;"><p><?php echo esc_html( $route_hint ); ?></p></div>
		<?php endif; ?>
		<h3>Launch URL From WordPress</h3>
		<?php if ( '' === $launch_url ) : ?>
			<p><em>Configure Shopify Portal URL to generate the production launch URL.</em></p>
		<?php else : ?>
			<textarea class="large-text code" rows="3" readonly><?php echo esc_textarea( $launch_url ); ?></textarea>
		<?php endif; ?>
		<h3>Expected Shopify Redirect Output</h3>
		<p><code><?php echo esc_html( $redirect_demo ); ?></code></p>
		<h3>Required JWT Claims</h3>
		<table class="widefat striped" style="max-width:1000px;">
			<thead>
				<tr>
					<th style="width:220px;">Claim</th>
					<th style="width:260px;">Expected Value</th>
					<th>Notes</th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>iss</code></td><td><code><?php echo esc_html( $issuer ); ?></code></td><td>Must match Issuer setting exactly.</td></tr>
				<tr><td><code>aud</code></td><td><code><?php echo esc_html( $client_id ); ?></code></td><td>Must include Client ID.</td></tr>
				<tr><td><code>exp</code></td><td><code>now + 120s</code></td><td>Short-lived token recommended.</td></tr>
				<tr><td><code>iat</code>/<code>nbf</code></td><td><code>now</code></td><td>Keep clocks aligned (30s skew allowed).</td></tr>
				<tr><td><code>jti</code></td><td><code>uuid</code></td><td>Must be unique (replay protection).</td></tr>
				<tr><td><code>shopify_customer_id</code></td><td><code>customer id</code></td><td>Required stable identity claim.</td></tr>
				<tr><td><code>company_id</code></td><td><code>company/account id</code></td><td><?php echo esc_html( ! empty( $settings['require_company_id'] ) ? 'Required by current settings.' : 'Optional in current settings.' ); ?></td></tr>
				<tr><td><code>dest</code></td><td><code><?php echo esc_html( $default_dest ); ?></code></td><td>Must match Allowed Destination Prefixes.</td></tr>
				<tr><td><code>email</code></td><td><code>customer email</code></td><td>Recommended for auto-link/auto-create behavior.</td></tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Build a quick route hint for configured Shopify portal URL.
	 *
	 * @param string $shopify_url Configured Shopify portal URL.
	 *
	 * @return string
	 */
	private function bridge_route_hint( $shopify_url ) {
		$shopify_url = trim( (string) $shopify_url );
		if ( '' === $shopify_url ) {
			return '';
		}

		$path = (string) wp_parse_url( $shopify_url, PHP_URL_PATH );
		if ( false === strpos( $path, '/apps/' ) ) {
			return 'Current Shopify Portal URL does not look like an app bridge route. Use your token-minting app endpoint (for example, /apps/portal-bridge).';
		}

		return '';
	}

	/**
	 * Get latest active mapping defaults for simulator form.
	 *
	 * @return array<string, string>
	 */
	private function default_bridge_identity() {
		global $wpdb;

		$table = PIB_Customer_Mapping::table_name();
		$row   = $wpdb->get_row(
			"SELECT shopify_customer_id, company_id, email
			FROM {$table}
			WHERE status = 'active'
			ORDER BY last_seen_at DESC, updated_at DESC, id DESC
			LIMIT 1",
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'shopify_customer_id' => '',
				'company_id'          => '',
				'email'               => '',
			);
		}

		return array(
			'shopify_customer_id' => isset( $row['shopify_customer_id'] ) ? sanitize_text_field( (string) $row['shopify_customer_id'] ) : '',
			'company_id'          => isset( $row['company_id'] ) ? sanitize_text_field( (string) $row['company_id'] ) : '',
			'email'               => isset( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '',
		);
	}

	/**
	 * Read scalar request value as text.
	 *
	 * @param string $key Request key.
	 *
	 * @return string
	 */
	private function request_scalar_text( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) wp_unslash( $_REQUEST[ $key ] ) );
	}

	/**
	 * Render effective configuration diagnostics panel.
	 *
	 * @param array<string, mixed> $diagnostics Diagnostics payload.
	 * @param bool                 $embedded    True when rendered inside tools accordion card.
	 *
	 * @return void
	 */
	private function render_diagnostics_panel( $diagnostics, $embedded = false ) {
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$status      = isset( $diagnostics['status'] ) ? sanitize_key( (string) $diagnostics['status'] ) : 'healthy';
		$checks      = isset( $diagnostics['checks'] ) && is_array( $diagnostics['checks'] ) ? $diagnostics['checks'] : array();
		$effective   = isset( $diagnostics['effective_values'] ) && is_array( $diagnostics['effective_values'] ) ? $diagnostics['effective_values'] : array();
		$references  = isset( $diagnostics['references'] ) && is_array( $diagnostics['references'] ) ? $diagnostics['references'] : array();

		$status_label = 'Healthy';
		if ( 'action_needed' === $status ) {
			$status_label = 'Action Needed';
		} elseif ( 'warnings' === $status ) {
			$status_label = 'Warnings';
		}

		if ( ! $embedded ) {
			echo '<hr />';
			echo '<h2>Effective Configuration Diagnostics</h2>';
		}
		echo '<p><strong>Status:</strong> ' . esc_html( $status_label ) . '</p>';

		echo '<h3>Checklist</h3>';
		if ( empty( $checks ) ) {
			echo '<p>No diagnostics checks were generated.</p>';
		} else {
			echo '<ul>';
			foreach ( $checks as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}
				$level   = isset( $check['level'] ) ? sanitize_key( (string) $check['level'] ) : 'ok';
				$message = isset( $check['message'] ) ? (string) $check['message'] : '';
				if ( '' === $message ) {
					continue;
				}

				$prefix = 'OK';
				if ( 'critical' === $level ) {
					$prefix = 'Action Needed';
				} elseif ( 'warning' === $level ) {
					$prefix = 'Warning';
				}

				echo '<li><strong>' . esc_html( $prefix ) . ':</strong> ' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<h3>Computed References</h3>';
		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
		foreach ( $references as $key => $value ) {
			echo '<tr>';
			echo '<th style="width:260px;">' . esc_html( (string) $key ) . '</th>';
			echo '<td>';
			if ( 'intake_url' === (string) $key ) {
				echo '<code>' . esc_html( (string) $value ) . '</code>';
			} else {
				echo esc_html( is_scalar( $value ) ? (string) $value : '' );
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3>Effective Values</h3>';
		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
		foreach ( $effective as $key => $value ) {
			echo '<tr>';
			echo '<th style="width:260px;">' . esc_html( (string) $key ) . '</th>';
			echo '<td>';
			if ( is_array( $value ) ) {
				echo '<code>' . esc_html( implode( ', ', array_map( 'strval', $value ) ) ) . '</code>';
			} else {
				echo '<code>' . esc_html( is_scalar( $value ) ? (string) $value : '' ) . '</code>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Add validation messages to WordPress settings errors.
	 *
	 * @param array<string, mixed> $validation Validation payload.
	 *
	 * @return void
	 */
	private function push_settings_messages( $validation ) {
		$validation = is_array( $validation ) ? $validation : array();
		$critical   = isset( $validation['critical_errors'] ) && is_array( $validation['critical_errors'] ) ? $validation['critical_errors'] : array();
		$warnings   = isset( $validation['warnings'] ) && is_array( $validation['warnings'] ) ? $validation['warnings'] : array();

		foreach ( $critical as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$code    = isset( $error['code'] ) ? sanitize_key( (string) $error['code'] ) : 'pib_settings_error';
			$message = isset( $error['message'] ) ? (string) $error['message'] : 'Invalid settings value.';

			add_settings_error(
				'pib_settings_group',
				'pib_critical_' . $code,
				esc_html( $message ),
				'error'
			);
		}

		foreach ( $warnings as $warning ) {
			if ( ! is_array( $warning ) ) {
				continue;
			}
			$code    = isset( $warning['code'] ) ? sanitize_key( (string) $warning['code'] ) : 'pib_settings_warning';
			$message = isset( $warning['message'] ) ? (string) $warning['message'] : '';
			if ( '' === $message ) {
				continue;
			}

			add_settings_error(
				'pib_settings_group',
				'pib_warning_' . $code,
				esc_html( $message ),
				'warning'
			);
		}
	}
}
