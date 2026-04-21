<?php
/**
 * Portal module extensibility and dashboard rendering.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for pluggable portal feature modules.
 */
final class PIB_Portal_Modules {
	/**
	 * Singleton.
	 *
	 * @var PIB_Portal_Modules|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Registered module definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $definitions = array();

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Portal_Modules|null
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

		add_shortcode( 'portal_dashboard', array( $this, 'render_dashboard_shortcode' ) );
		add_filter( 'pib_settings_diagnostics_checks', array( $this, 'append_settings_diagnostics_checks' ), 10, 3 );

		/**
		 * Register portal modules with extensibility registry.
		 *
		 * @param PIB_Portal_Modules $registry Module registry.
		 * @param PIB_Plugin         $plugin   Plugin container.
		 */
		do_action( 'pib_register_portal_modules', $this, $this->plugin );

		/**
		 * Fires after all listeners have had a chance to register portal modules.
		 *
		 * @param array<string, array<string,mixed>> $definitions Registered definitions.
		 * @param PIB_Portal_Modules                 $registry    Module registry.
		 * @param PIB_Plugin                         $plugin      Plugin container.
		 */
		do_action( 'pib_portal_modules_registered', $this->definitions, $this, $this->plugin );
	}

	/**
	 * Register or replace a portal module definition.
	 *
	 * @param string               $module_id   Module ID.
	 * @param array<string, mixed> $definition  Module definition.
	 *
	 * @return bool
	 */
	public function register_module( $module_id, $definition = array() ) {
		$module_id  = sanitize_key( (string) $module_id );
		$definition = is_array( $definition ) ? $definition : array();

		if ( '' === $module_id ) {
			return false;
		}

		$definition['id'] = $module_id;
		if ( ! isset( $definition['source'] ) ) {
			$definition['source'] = 'custom';
		}

		$normalized = $this->normalize_definition( $definition );
		if ( empty( $normalized ) ) {
			return false;
		}

		$this->definitions[ $module_id ] = $normalized;
		return true;
	}

	/**
	 * Return registered raw definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_registered_modules() {
		return $this->definitions;
	}

	/**
	 * Resolve evaluated module entries for current settings/user context.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_modules( $args = array() ) {
		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'context'                  => 'portal_dashboard',
				'include_hidden'           => false,
				'user_id'                  => get_current_user_id(),
				'settings'                 => null,
				'ignore_user_requirements' => false,
			)
		);

		$context    = sanitize_key( (string) $args['context'] );
		$user_id    = absint( $args['user_id'] );
		$settings   = is_array( $args['settings'] ) ? $args['settings'] : $this->plugin->get_settings();
		$include_hidden = ! empty( $args['include_hidden'] );
		$ignore_user_requirements = ! empty( $args['ignore_user_requirements'] );

		$identity = null;
		if ( $user_id > 0 ) {
			$mapping = PIB_Customer_Mapping::instance();
			if ( $mapping instanceof PIB_Customer_Mapping ) {
				$identity = $mapping->get_active_identity_context( $user_id );
			}
		}

		$modules = array();
		foreach ( $this->definitions as $module_id => $definition ) {
			$module = $this->normalize_definition( $definition );
			if ( empty( $module ) ) {
				continue;
			}

			$destination = PIB_Destination::sanitize_destination(
				isset( $module['destination'] ) ? (string) $module['destination'] : '',
				isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/'
			);
			$module['destination'] = $destination;
			$module['url']         = PIB_Destination::to_absolute_url( $destination );

			$allowed = PIB_Destination::is_allowed(
				$destination,
				isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array()
			);

			$route_policy = null;
			if ( class_exists( 'PIB_Protected_Route_Policy' ) ) {
				$route_policy = PIB_Protected_Route_Policy::evaluate_destination( $destination, $settings );
			}

			$enabled = ! empty( $module['enabled'] );
			if ( ! empty( $module['enabled_callback'] ) && is_callable( $module['enabled_callback'] ) ) {
				$enabled = (bool) call_user_func( $module['enabled_callback'], $module, $settings, $user_id, $identity );
			}

			$hidden_reason = '';
			if ( ! $enabled ) {
				$hidden_reason = 'disabled';
			} elseif ( ! $allowed ) {
				$hidden_reason = 'destination_not_allowed';
			} elseif ( ! $ignore_user_requirements ) {
				if ( ! empty( $module['requires_auth'] ) && $user_id <= 0 ) {
					$hidden_reason = 'login_required';
				} elseif ( ! empty( $module['requires_identity'] ) && ! is_array( $identity ) ) {
					$hidden_reason = 'identity_required';
				}
			}

			$module['is_available']           = '' === $hidden_reason;
			$module['hidden_reason']          = $hidden_reason;
			$module['destination_allowed']    = $allowed;
			$module['route_is_protected']     = is_array( $route_policy ) ? ! empty( $route_policy['is_protected'] ) : false;
			$module['route_policy']           = is_array( $route_policy ) ? $route_policy : array();
			$module['context']                = $context;

			/**
			 * Filter evaluated portal module entry.
			 *
			 * @param array<string, mixed> $module   Evaluated entry.
			 * @param array<string, mixed> $settings Settings.
			 * @param int                  $user_id  User ID.
			 * @param array<string,mixed>|null $identity Identity context.
			 */
			$module = apply_filters( 'pib_portal_module', $module, $settings, $user_id, $identity );
			if ( ! is_array( $module ) ) {
				continue;
			}

			if ( empty( $module['is_available'] ) && ! $include_hidden ) {
				continue;
			}

			$modules[] = $module;
		}

		usort(
			$modules,
			function( $a, $b ) {
				$a_priority = isset( $a['priority'] ) ? absint( $a['priority'] ) : 100;
				$b_priority = isset( $b['priority'] ) ? absint( $b['priority'] ) : 100;
				if ( $a_priority !== $b_priority ) {
					return $a_priority <=> $b_priority;
				}

				$a_label = isset( $a['label'] ) ? (string) $a['label'] : '';
				$b_label = isset( $b['label'] ) ? (string) $b['label'] : '';
				return strcasecmp( $a_label, $b_label );
			}
		);

		/**
		 * Filter portal module list for current context.
		 *
		 * @param array<int, array<string,mixed>> $modules  Module entries.
		 * @param string                          $context  Consumer context.
		 * @param array<string,mixed>             $settings Settings.
		 * @param int                             $user_id  User ID.
		 * @param array<string,mixed>|null        $identity Identity context.
		 * @param PIB_Portal_Modules              $registry Registry.
		 */
		$modules = apply_filters( 'pib_portal_modules', $modules, $context, $settings, $user_id, $identity, $this );

		return is_array( $modules ) ? $modules : array();
	}

	/**
	 * Render portal dashboard cards from registered modules.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_dashboard_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'         => 'Partner Portal',
				'empty_message' => 'No portal modules are currently available.',
			),
			$atts,
			'portal_dashboard'
		);

		$settings = $this->plugin->get_settings();
		$modules  = $this->get_modules(
			array(
				'context'        => 'portal_dashboard',
				'include_hidden' => false,
				'settings'       => $settings,
			)
		);

		$out = '<div class="pib-portal-dashboard">';
		if ( '' !== trim( (string) $atts['title'] ) ) {
			$out .= '<h2>' . esc_html( (string) $atts['title'] ) . '</h2>';
		}

		if ( empty( $modules ) ) {
			if ( ! is_user_logged_in() ) {
				$signin = PIB_Signin_Link::instance( $this->plugin );
				if ( $signin instanceof PIB_Signin_Link ) {
					$signin_url = $signin->build_signin_url( isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/' );
					if ( '' !== $signin_url ) {
						$out .= '<p>Please <a href="' . esc_url( $signin_url ) . '">sign in</a> to access your portal modules.</p>';
						$out .= '</div>';
						return $out;
					}
				}
			}

			$out .= '<p>' . esc_html( (string) $atts['empty_message'] ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		$out .= '<div class="pib-portal-dashboard-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;">';
		foreach ( $modules as $module ) {
			$module_id = isset( $module['id'] ) ? sanitize_key( (string) $module['id'] ) : '';
			$label     = isset( $module['label'] ) ? (string) $module['label'] : $module_id;
			$desc      = isset( $module['description'] ) ? (string) $module['description'] : '';
			$url       = isset( $module['url'] ) ? (string) $module['url'] : '';
			$cta       = isset( $module['cta_label'] ) ? (string) $module['cta_label'] : 'Open';
			$icon      = isset( $module['icon'] ) ? sanitize_html_class( (string) $module['icon'] ) : 'dashicons-admin-links';

			$card_markup = '';
			if ( isset( $module['render_callback'] ) && is_callable( $module['render_callback'] ) ) {
				$card_markup = (string) call_user_func( $module['render_callback'], $module, $settings );
			}

			if ( '' === trim( $card_markup ) ) {
				$card_markup = '<article class="pib-portal-card" style="border:1px solid #ddd;border-radius:8px;padding:16px;background:#fff;">';
				$card_markup .= '<p style="margin:0 0 8px 0;"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span></p>';
				$card_markup .= '<h3 style="margin:0 0 8px 0;">' . esc_html( $label ) . '</h3>';
				if ( '' !== trim( $desc ) ) {
					$card_markup .= '<p style="margin:0 0 12px 0;">' . esc_html( $desc ) . '</p>';
				}
				$card_markup .= '<p style="margin:0;"><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( $cta ) . '</a></p>';
				$card_markup .= '</article>';
			}

			/**
			 * Filter final dashboard card markup.
			 *
			 * @param string               $card_markup Card markup.
			 * @param array<string, mixed> $module      Module entry.
			 * @param array<string, mixed> $settings    Settings.
			 */
			$card_markup = (string) apply_filters( 'pib_portal_module_card_markup', $card_markup, $module, $settings );
			if ( '' !== trim( $card_markup ) ) {
				$out .= $card_markup;
			}
		}
		$out .= '</div></div>';

		/**
		 * Filter dashboard shortcode markup.
		 *
		 * @param string                          $out     Rendered markup.
		 * @param array<int, array<string,mixed>> $modules Modules.
		 * @param array<string, string>           $atts    Shortcode atts.
		 */
		return (string) apply_filters( 'pib_portal_dashboard_markup', $out, $modules, $atts );
	}

	/**
	 * Extend settings diagnostics with module health checks.
	 *
	 * @param array<int, array<string,string>> $checks     Existing checks.
	 * @param array<string,mixed>              $settings   Settings.
	 * @param array<string,mixed>              $validation Validation payload.
	 *
	 * @return array<int, array<string,string>>
	 */
	public function append_settings_diagnostics_checks( $checks, $settings, $validation ) {
		$checks   = is_array( $checks ) ? $checks : array();
		$settings = is_array( $settings ) ? $settings : array();

		$modules = $this->get_modules(
			array(
				'context'                  => 'settings_diagnostics',
				'include_hidden'           => true,
				'settings'                 => $settings,
				'user_id'                  => 0,
				'ignore_user_requirements' => true,
			)
		);

		$total = count( $modules );
		if ( $total <= 0 ) {
			$checks[] = array(
				'code'    => 'portal_modules_missing',
				'level'   => 'warning',
				'message' => 'No portal modules are registered. Add modules via pib_register_portal_modules.',
			);
			return $checks;
		}

		$invalid_dest = 0;
		$available    = 0;
		foreach ( $modules as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}
			if ( ! empty( $module['is_available'] ) ) {
				++$available;
			}
			if ( isset( $module['hidden_reason'] ) && 'destination_not_allowed' === (string) $module['hidden_reason'] ) {
				++$invalid_dest;
			}
		}

		if ( $invalid_dest > 0 ) {
			$checks[] = array(
				'code'    => 'portal_modules_destination_invalid',
				'level'   => 'warning',
				'message' => 'One or more portal module destinations are outside Allowed Destination Prefixes.',
			);
		} else {
			$checks[] = array(
				'code'    => 'portal_modules_destinations_ok',
				'level'   => 'ok',
				'message' => 'Portal module destinations are aligned with destination allowlist.',
			);
		}

		$checks[] = array(
			'code'    => 'portal_modules_count',
			'level'   => 'ok',
			'message' => sprintf( 'Portal modules registered: %d (%d available in current config).', $total, $available ),
		);

		return $checks;
	}

	/**
	 * Normalize module definition.
	 *
	 * @param array<string, mixed> $definition Raw definition.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_definition( $definition ) {
		$definition = wp_parse_args(
			is_array( $definition ) ? $definition : array(),
			array(
				'id'                => '',
				'label'             => '',
				'description'       => '',
				'destination'       => '/',
				'shortcode'         => '',
				'cta_label'         => 'Open',
				'icon'              => 'dashicons-admin-links',
				'source'            => 'custom',
				'priority'          => 100,
				'enabled'           => true,
				'enabled_callback'  => null,
				'requires_auth'     => true,
				'requires_identity' => false,
				'render_callback'   => null,
			)
		);

		$definition['id']          = sanitize_key( (string) $definition['id'] );
		$definition['label']       = sanitize_text_field( (string) $definition['label'] );
		$definition['description'] = sanitize_text_field( (string) $definition['description'] );
		$definition['destination'] = PIB_Destination::sanitize_destination( (string) $definition['destination'], '/' );
		$definition['shortcode']   = sanitize_key( (string) $definition['shortcode'] );
		$definition['cta_label']   = sanitize_text_field( (string) $definition['cta_label'] );
		$definition['icon']        = sanitize_html_class( (string) $definition['icon'] );
		$definition['source']      = sanitize_key( (string) $definition['source'] );
		$definition['priority']    = max( 1, absint( $definition['priority'] ) );
		$definition['enabled']     = ! empty( $definition['enabled'] );
		$definition['requires_auth'] = ! empty( $definition['requires_auth'] );
		$definition['requires_identity'] = ! empty( $definition['requires_identity'] );

		if ( '' === $definition['id'] ) {
			return array();
		}
		if ( '' === $definition['label'] ) {
			$definition['label'] = ucwords( str_replace( '_', ' ', $definition['id'] ) );
		}
		if ( '' === $definition['source'] ) {
			$definition['source'] = 'custom';
		}
		if ( '' === $definition['cta_label'] ) {
			$definition['cta_label'] = 'Open';
		}

		return $definition;
	}
}

