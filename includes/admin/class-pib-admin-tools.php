<?php
/**
 * Admin diagnostics and mapping tools.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides operational admin pages for mappings and logs.
 */
final class PIB_Admin_Tools {
	/**
	 * Singleton.
	 *
	 * @var PIB_Admin_Tools|null
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
	 * @return PIB_Admin_Tools|null
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

		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_tools_page' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
	}

	/**
	 * Register tools page under Tools menu.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'pib-settings',
			'Portal Bridge Tools',
			'Tools',
			'manage_options',
			'pib-tools',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Redirect legacy tools URL to the Portal Bridge top-level route.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_tools_page() {
		if ( ! is_admin() ) {
			return;
		}

		global $pagenow;
		if ( 'tools.php' !== $pagenow ) {
			return;
		}

		$page = sanitize_key( $this->request_text( 'page' ) );
		if ( 'pib-tools' !== $page ) {
			return;
		}

		$args = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$args[ $key ] = sanitize_text_field( (string) wp_unslash( $value ) );
		}
		$args['page'] = 'pib-tools';

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render tools page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->handle_admin_actions();

		$tab = $this->request_text( 'tab' );
		if ( '' === $tab ) {
			$tab = 'overview';
		}
		if ( ! in_array( $tab, array( 'overview', 'mappings', 'failures', 'health', 'logs', 'advanced' ), true ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap">';
		echo '<h1>Portal Bridge Tools</h1>';
		echo '<p>Operational controls for mappings, failures, health checks, audit logs, and advanced troubleshooting panels.</p>';
		$this->render_notice_from_query();
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab ' . ( 'overview' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'overview' ) ) . '">Overview</a>';
		echo '<a class="nav-tab ' . ( 'mappings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'mappings' ) ) . '">Mappings</a>';
		echo '<a class="nav-tab ' . ( 'failures' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'failures' ) ) . '">Failures</a>';
		echo '<a class="nav-tab ' . ( 'health' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'health' ) ) . '">Health</a>';
		echo '<a class="nav-tab ' . ( 'logs' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'logs' ) ) . '">Audit Logs</a>';
		echo '<a class="nav-tab ' . ( 'advanced' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'advanced' ) ) . '">Advanced</a>';
		echo '</h2>';

		if ( 'advanced' === $tab ) {
			$this->render_advanced_tab();
		} elseif ( 'logs' === $tab ) {
			$this->render_logs_tab();
		} elseif ( 'health' === $tab ) {
			$this->render_health_tab();
		} elseif ( 'failures' === $tab ) {
			$this->render_failures_tab();
		} elseif ( 'mappings' === $tab ) {
			$this->render_mappings_tab();
		} else {
			$this->render_overview_tab();
		}

		echo '</div>';
	}

	/**
	 * Render operational overview tab with direct entry points.
	 *
	 * @return void
	 */
	private function render_overview_tab() {
		$cards = array(
			array(
				'title'       => 'Mappings',
				'description' => 'Create, search, and update customer-to-user mappings.',
				'url'         => $this->tab_url( 'mappings' ),
				'action'      => 'Open Mappings',
			),
			array(
				'title'       => 'Failures',
				'description' => 'Review auth and mapping-denial records with guided actions.',
				'url'         => $this->tab_url( 'failures' ),
				'action'      => 'Open Failures',
			),
			array(
				'title'       => 'Health',
				'description' => 'Check runtime health and download support snapshots.',
				'url'         => $this->tab_url( 'health' ),
				'action'      => 'Open Health',
			),
			array(
				'title'       => 'Audit Logs',
				'description' => 'Filter and inspect recent plugin activity and events.',
				'url'         => $this->tab_url( 'logs' ),
				'action'      => 'Open Audit Logs',
			),
			array(
				'title'       => 'Route Access (Advanced)',
				'description' => 'Diagnose destination protection and redirect-loop risk.',
				'url'         => $this->advanced_panel_url( 'routes' ),
				'action'      => 'Open Route Access',
			),
			array(
				'title'       => 'Extensions (Advanced)',
				'description' => 'Inspect module registry and portal extension visibility.',
				'url'         => $this->advanced_panel_url( 'extensions' ),
				'action'      => 'Open Extensions',
			),
			array(
				'title'       => 'Token Check (Advanced)',
				'description' => 'Jump to advanced troubleshooting tools in the new panel workspace.',
				'url'         => $this->advanced_panel_url( 'token' ),
				'action'      => 'Open Advanced',
			),
		);

		echo '<h2 style="margin-top:18px;">Operations Overview</h2>';
		echo '<p>Choose a workspace below to continue with day-to-day operations or advanced troubleshooting.</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;max-width:1180px;">';
		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$title       = isset( $card['title'] ) ? sanitize_text_field( (string) $card['title'] ) : '';
			$description = isset( $card['description'] ) ? sanitize_text_field( (string) $card['description'] ) : '';
			$url         = isset( $card['url'] ) ? esc_url_raw( (string) $card['url'] ) : '';
			$action      = isset( $card['action'] ) ? sanitize_text_field( (string) $card['action'] ) : 'Open';
			if ( '' === $title || '' === $url ) {
				continue;
			}

			echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;">';
			echo '<h3 style="margin:0 0 8px 0;">' . esc_html( $title ) . '</h3>';
			if ( '' !== $description ) {
				echo '<p style="margin:0 0 12px 0;color:#50575e;">' . esc_html( $description ) . '</p>';
			}
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a>';
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Render advanced operations workspace with panel-level routing.
	 *
	 * @return void
	 */
	private function render_advanced_tab() {
		$panels = array(
			'routes'           => 'Route Access',
			'extensions'       => 'Extensions',
			'token'            => 'Token Check',
			'setup_assistant'  => 'Setup Assistant',
			'bridge_contract'  => 'Bridge Contract',
			'bridge_simulator' => 'Bridge Simulator',
			'diagnostics'      => 'Diagnostics',
		);

		$panel = sanitize_key( $this->request_text( 'panel' ) );
		if ( '' === $panel || ! isset( $panels[ $panel ] ) ) {
			$panel = 'routes';
		}

		echo '<h2 style="margin-top:18px;">Advanced Workspace</h2>';
		echo '<p>Expert tools are grouped here while setup remains focused on configuration.</p>';
		echo '<h3 class="nav-tab-wrapper" style="margin-top:14px;">';
		foreach ( $panels as $panel_key => $panel_label ) {
			$is_active = $panel_key === $panel ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $is_active ) . '" href="' . esc_url( $this->advanced_panel_url( $panel_key ) ) . '">' . esc_html( $panel_label ) . '</a>';
		}
		echo '</h3>';

		if ( 'routes' === $panel ) {
			$this->render_routes_tab();
			return;
		}

		if ( 'extensions' === $panel ) {
			$this->render_extensions_tab();
			return;
		}

		$settings_page = class_exists( 'PIB_Settings' ) ? PIB_Settings::instance( $this->plugin ) : null;
		if ( $settings_page instanceof PIB_Settings && method_exists( $settings_page, 'render_advanced_tools_panel' ) ) {
			$rendered = $settings_page->render_advanced_tools_panel( $panel, $this->advanced_panel_url( $panel ) );
			if ( $rendered ) {
				return;
			}
		}

		echo '<div class="notice notice-error" style="margin:16px 0 12px 0;">';
		echo '<p><strong>Advanced panel unavailable:</strong> the selected panel could not be rendered.</p>';
		echo '</div>';
	}

	/**
	 * Render mapping inspector and operations tab.
	 *
	 * @return void
	 */
	private function render_mappings_tab() {
		$mapping_module = PIB_Customer_Mapping::instance();
		if ( ! $mapping_module instanceof PIB_Customer_Mapping ) {
			echo '<p>Mapping module is unavailable.</p>';
			return;
		}

		$prefill = array(
			'customer' => $this->request_text( 'prefill_customer' ),
			'company'  => $this->request_text( 'prefill_company' ),
			'email'    => $this->request_text( 'prefill_email' ),
			'user'     => $this->request_text( 'prefill_user' ),
		);

		$this->render_mapping_upsert_form( $prefill );

		$search = $this->request_text( 'q' );
		$status = $this->request_text( 'status' );
		$page   = max( 1, absint( $this->request_text( 'paged' ) ) );
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$rows = $mapping_module->query_mappings(
			array(
				'search' => $search,
				'status' => $status,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
		$total = $mapping_module->count_mappings(
			array(
				'search' => $search,
				'status' => $status,
			)
		);

		echo '<form method="get" style="margin-top:16px;">';
		echo '<input type="hidden" name="page" value="pib-tools" />';
		echo '<input type="hidden" name="tab" value="mappings" />';
		echo '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search customer/company/email/user..." />';
		echo '<select name="status">';
		echo '<option value="" ' . selected( $status, '', false ) . '>All statuses</option>';
		echo '<option value="active" ' . selected( $status, 'active', false ) . '>Active</option>';
		echo '<option value="blocked" ' . selected( $status, 'blocked', false ) . '>Blocked</option>';
		echo '<option value="archived" ' . selected( $status, 'archived', false ) . '>Archived</option>';
		echo '</select> ';
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped" style="margin-top:12px;">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Customer</th><th>Company</th><th>WP User</th><th>Email</th><th>Status</th><th>Updated</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="8">No mappings found.</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$mapping_id = absint( $row['id'] );
				$user_id    = absint( $row['wp_user_id'] );
				$user_label = $user_id > 0 ? '#' . $user_id : '';
				if ( $user_id > 0 ) {
					$user_obj = get_user_by( 'id', $user_id );
					if ( $user_obj instanceof WP_User ) {
						$user_label = $user_obj->user_login . ' (#' . $user_id . ')';
					}
				}

				$status = sanitize_key( (string) $row['status'] );
				$actions = array();
				foreach ( array( 'active' => 'Activate', 'blocked' => 'Block', 'archived' => 'Archive' ) as $to_status => $label ) {
					if ( $to_status === $status ) {
						$actions[] = '<span style="opacity:.65;">' . esc_html( $label ) . '</span>';
						continue;
					}
					$actions[] = '<a href="' . esc_url( $this->mapping_status_url( $mapping_id, $to_status ) ) . '">' . esc_html( $label ) . '</a>';
				}

				echo '<tr>';
				echo '<td>' . esc_html( (string) $mapping_id ) . '</td>';
				echo '<td>' . esc_html( (string) $row['shopify_customer_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['company_id'] ) . '</td>';
				echo '<td>' . esc_html( $user_label ) . '</td>';
				echo '<td>' . esc_html( (string) $row['email'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['updated_at'] ) . '</td>';
				echo '<td>' . implode( ' | ', $actions ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo $this->pagination(
			$total,
			$page,
			$limit,
			'mappings',
			array(
				'q'      => $search,
				'status' => $status,
			)
		);
	}

	/**
	 * Render mapping failures tab.
	 *
	 * @return void
	 */
	private function render_failures_tab() {
		$logger = PIB_Audit_Log::instance();
		if ( ! $logger instanceof PIB_Audit_Log ) {
			echo '<p>Audit log module is unavailable.</p>';
			return;
		}

		$token_check_url    = $this->advanced_panel_url( 'token' );
		$bridge_setup_url   = $this->settings_page_url();
		$contract_tool_url  = $this->advanced_panel_url( 'bridge_contract' );
		echo '<div class="notice notice-info" style="margin:12px 0 14px 0;"><p><strong>Bridge troubleshooting note:</strong> this table tracks JWT handoff and mapping denials. A normal Shopify customer login without JWT redirect to intake commonly appears as <code>pib_missing_token</code>.</p><p><a href="' . esc_url( $token_check_url ) . '">Open Token Check</a> | <a href="' . esc_url( $contract_tool_url ) . '">Open Bridge Contract</a> | <a href="' . esc_url( $bridge_setup_url ) . '">Open Bridge Setup</a></p></div>';

		$search = $this->request_text( 'q' );
		$source = $this->request_text( 'source' );
		$reason = $this->request_text( 'reason' );
		$page   = max( 1, absint( $this->request_text( 'paged' ) ) );
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$rows = $logger->get_mapping_failures(
			array(
				'search' => $search,
				'source' => $source,
				'reason' => $reason,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
		$total = $logger->count_mapping_failures(
			array(
				'search' => $search,
				'source' => $source,
				'reason' => $reason,
			)
		);

		echo '<form method="get" style="margin-top:16px;">';
		echo '<input type="hidden" name="page" value="pib-tools" />';
		echo '<input type="hidden" name="tab" value="failures" />';
		echo '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search customer/company/message..." />';
		echo '<select name="source">';
		echo '<option value="" ' . selected( $source, '', false ) . '>All sources</option>';
		echo '<option value="mapping_resolution_denied" ' . selected( $source, 'mapping_resolution_denied', false ) . '>Mapping Resolution Denied</option>';
		echo '<option value="auth_failure" ' . selected( $source, 'auth_failure', false ) . '>Auth Failure</option>';
		echo '</select> ';
		echo '<input type="text" name="reason" value="' . esc_attr( $reason ) . '" placeholder="reason/error_code" /> ';
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped" style="margin-top:12px;">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Time</th><th>Source</th><th>Reason</th><th>Customer</th><th>Company</th><th>Email</th><th>User</th><th>Message</th><th>Action</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="10">No unmatched/failed mapping records found.</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$context   = isset( $row['context_decoded'] ) && is_array( $row['context_decoded'] ) ? $row['context_decoded'] : array();
				$source_id = isset( $row['event_type'] ) ? sanitize_key( (string) $row['event_type'] ) : '';
				$reason_id = '';
				if ( isset( $context['reason'] ) ) {
					$reason_id = sanitize_key( (string) $context['reason'] );
				} elseif ( isset( $context['error_code'] ) ) {
					$reason_id = sanitize_key( (string) $context['error_code'] );
				}

				$customer = isset( $row['shopify_customer_id'] ) ? (string) $row['shopify_customer_id'] : '';
				$company  = isset( $row['company_id'] ) ? (string) $row['company_id'] : '';
				$email    = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
				$user_id  = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : absint( $row['user_id'] );
				$guidance = $this->failure_reason_guidance( $source_id, $reason_id );

				$action = '';
				if ( '' !== $customer ) {
					$action = '<a href="' . esc_url(
						$this->tab_url(
							'mappings',
							array(
								'prefill_customer' => $customer,
								'prefill_company'  => $company,
								'prefill_email'    => $email,
								'prefill_user'     => $user_id > 0 ? (string) $user_id : '',
							)
						)
					) . '">Create/Link Mapping</a>';
				}
				$action_links = array();
				if ( '' !== $action ) {
					$action_links[] = $action;
				}
				if ( isset( $guidance['links'] ) && is_array( $guidance['links'] ) ) {
					foreach ( $guidance['links'] as $label => $url ) {
						$label = sanitize_text_field( (string) $label );
						$url   = esc_url_raw( (string) $url );
						if ( '' === $label || '' === $url ) {
							continue;
						}
						$action_links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
					}
				}
				$action = implode( ' | ', $action_links );

				$message_cell = esc_html( isset( $row['message'] ) ? (string) $row['message'] : '' );
				if ( isset( $guidance['text'] ) && '' !== (string) $guidance['text'] ) {
					$message_cell .= '<br /><span class="description">' . esc_html( (string) $guidance['text'] ) . '</span>';
				}

				echo '<tr>';
				echo '<td>' . esc_html( (string) $row['id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
				echo '<td>' . esc_html( $source_id ) . '</td>';
				echo '<td>' . esc_html( $reason_id ) . '</td>';
				echo '<td>' . esc_html( $customer ) . '</td>';
				echo '<td>' . esc_html( $company ) . '</td>';
				echo '<td>' . esc_html( $email ) . '</td>';
				echo '<td>' . esc_html( $user_id > 0 ? (string) $user_id : '' ) . '</td>';
				echo '<td>' . $message_cell . '</td>';
				echo '<td>' . $action . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo $this->pagination(
			$total,
			$page,
			$limit,
			'failures',
			array(
				'q'      => $search,
				'source' => $source,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Build reason-specific guidance for failure records.
	 *
	 * @param string $source_id Failure source event key.
	 * @param string $reason_id Reason/error code.
	 *
	 * @return array<string, mixed>
	 */
	private function failure_reason_guidance( $source_id, $reason_id ) {
		$source_id = sanitize_key( (string) $source_id );
		$reason_id = sanitize_key( (string) $reason_id );

		$token_check_url     = $this->advanced_panel_url( 'token' );
		$bridge_setup_url    = $this->settings_page_url();
		$bridge_contract_url = $this->advanced_panel_url( 'bridge_contract' );
		$mappings_url       = $this->tab_url( 'mappings' );

		$guidance = array(
			'text'  => '',
			'links' => array(),
		);

		$map = array(
			'pib_missing_token' => array(
				'text'  => 'No JWT handoff token reached intake. Ensure Shopify bridge route mints a token and submits it to WordPress intake (POST recommended; legacy query transport is optional).',
				'links' => array(
					'Token Check'  => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_missing_shared_secret' => array(
				'text'  => 'WordPress Shared Secret is missing; intake cannot verify JWT signatures.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_missing_secret' => array(
				'text'  => 'Shared secret is missing on verifier path. Configure matching secret values in Shopify app and WordPress.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_invalid_signature' => array(
				'text'  => 'JWT signature did not match Shared Secret. Verify HS256 signing key parity between Shopify app and WordPress.',
				'links' => array(
					'Token Check'  => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_invalid_token_format' => array(
				'text'  => 'Token is not valid JWT format (`header.payload.signature`).',
				'links' => array(
					'Token Check' => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_invalid_token_encoding' => array(
				'text'  => 'Token failed base64url decoding. Ensure Shopify sends an intact JWT without transport corruption.',
				'links' => array(
					'Token Check' => $token_check_url,
				),
			),
			'pib_invalid_token_payload' => array(
				'text'  => 'Token payload is invalid JSON or missing claim structure.',
				'links' => array(
					'Token Check' => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_invalid_alg' => array(
				'text'  => 'Token algorithm is unsupported; WordPress expects HS256.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Token Check' => $token_check_url,
				),
			),
			'pib_invalid_issuer' => array(
				'text'  => 'Token `iss` does not match WordPress Issuer exactly (including protocol and trailing slash behavior).',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
					'Token Check'  => $token_check_url,
				),
			),
			'pib_invalid_audience' => array(
				'text'  => 'Token `aud` does not include configured Client ID.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
					'Token Check'  => $token_check_url,
				),
			),
			'pib_missing_exp' => array(
				'text'  => 'Token is missing `exp`; short-lived expiry claim is required.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Token Check' => $token_check_url,
				),
			),
			'pib_token_expired' => array(
				'text'  => 'Token expired before intake verification. Shorten redirect latency and check server clock skew.',
				'links' => array(
					'Token Check' => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_token_not_yet_valid' => array(
				'text'  => 'Token `nbf` is in the future relative to WordPress clock.',
				'links' => array(
					'Token Check' => $token_check_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_token_future_iat' => array(
				'text'  => 'Token `iat` is in the future relative to WordPress clock.',
				'links' => array(
					'Token Check' => $token_check_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_missing_customer_id' => array(
				'text'  => 'Token is missing `shopify_customer_id`/`customer_id` claim required for mapping resolution.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Token Check' => $token_check_url,
				),
			),
			'pib_missing_company_id' => array(
				'text'  => 'Company claim is required by current settings; add `company_id` in the Shopify-minted JWT or disable requirement.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
					'Token Check'  => $token_check_url,
				),
			),
			'pib_missing_dest' => array(
				'text'  => 'Token is missing destination claim (`dest`).',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_invalid_dest' => array(
				'text'  => 'Destination claim is not allowed by current destination prefix policy.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_missing_jti' => array(
				'text'  => 'Token is missing `jti`; replay-protection identifier is required.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Token Check' => $token_check_url,
				),
			),
			'pib_replay_detected' => array(
				'text'  => 'The same token `jti` was already consumed. Ensure each bridge handoff token is single-use and unique.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_invalid_state' => array(
				'text'  => 'State verification hook rejected this handoff token.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
					'Token Check' => $token_check_url,
				),
			),
			'pib_missing_state' => array(
				'text'  => 'Handoff state is missing. Ensure sign-in launches include a state value and the token-minting route returns it to intake.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_invalid_state_binding' => array(
				'text'  => 'Handoff state did not match the originating browser context. Restart sign-in and avoid cross-browser handoff links.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_state_replay' => array(
				'text'  => 'Handoff state is invalid or already consumed. Ensure each launch uses a fresh state and token round-trip.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'pib_auth_rate_limited' => array(
				'text'  => 'Auth failure rate limit is active. Wait for window expiry or reduce repeated invalid attempts.',
				'links' => array(
					'Bridge Setup' => $bridge_setup_url,
				),
			),
			'pib_user_not_found' => array(
				'text'  => 'JWT verified, but no eligible WordPress user mapping was resolved for this customer/company.',
				'links' => array(
					'Mappings'    => $mappings_url,
					'Token Check' => $token_check_url,
					'Bridge Contract' => $bridge_contract_url,
				),
			),
			'missing_email_claim' => array(
				'text'  => 'No email claim was available for first-time mapping. Include `email` (or `customer_email`) in JWT so WordPress can auto-link or auto-create user records.',
				'links' => array(
					'Bridge Contract' => $bridge_contract_url,
					'Token Check' => $token_check_url,
				),
			),
			'mapping_not_active' => array(
				'text'  => 'A mapping exists but is not active. Activate it or update mapping policy.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'hint_user_conflict' => array(
				'text'  => 'Hinted WordPress user conflicts with an active mapping for a different Shopify customer.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'pib_email_link_conflict' => array(
				'text'  => 'Email auto-link is blocked because the target WordPress user is already mapped to another active customer.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'email_sync_conflict' => array(
				'text'  => 'Shopify email claim belongs to a different WordPress user. Resolve duplicate users before continuing so customer/email identity stays aligned.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'email_sync_failed' => array(
				'text'  => 'WordPress user email update failed while syncing to Shopify claim.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'user_conflicting_customer_mapping' => array(
				'text'  => 'Resolved user has conflicting active mappings. Consolidate or correct mappings.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
			'mapping_upsert_failed' => array(
				'text'  => 'JWT verified but mapping upsert failed; inspect mapping table state and retry.',
				'links' => array(
					'Mappings' => $mappings_url,
				),
			),
		);

		if ( isset( $map[ $reason_id ] ) && is_array( $map[ $reason_id ] ) ) {
			return $map[ $reason_id ];
		}

		if ( 0 === strpos( $reason_id, 'pib_' ) ) {
			$guidance['text']  = 'This bridge error code indicates JWT handoff or policy verification failed before login completion.';
			$guidance['links'] = array(
				'Token Check' => $token_check_url,
				'Bridge Contract' => $bridge_contract_url,
				'Bridge Setup' => $bridge_setup_url,
			);
			return $guidance;
		}

		if ( 'auth_failure' === $source_id ) {
			$guidance['text']  = 'Review token claims/signature and verify Shopify route is returning to intake with a signed JWT.';
			$guidance['links'] = array(
				'Token Check'  => $token_check_url,
				'Bridge Contract' => $bridge_contract_url,
				'Bridge Setup' => $bridge_setup_url,
			);
		} elseif ( 'mapping_resolution_denied' === $source_id ) {
			$guidance['text']  = 'JWT may be valid, but mapping policy denied resolution for current customer/company/user context.';
			$guidance['links'] = array(
				'Mappings'    => $mappings_url,
				'Token Check' => $token_check_url,
			);
		}

		return $guidance;
	}

	/**
	 * Render route access management tab.
	 *
	 * @return void
	 */
	private function render_routes_tab() {
		if ( ! class_exists( 'PIB_Protected_Route_Policy' ) ) {
			echo '<p>Route policy module is unavailable.</p>';
			return;
		}

		$settings = $this->plugin->get_settings();
		$rules    = PIB_Protected_Route_Policy::rules( $settings );
		$setup_url = $this->settings_page_url();
		$failures_url = $this->tab_url( 'failures' );
		$token_url = $this->advanced_panel_url( 'token' );

		$default_dest = isset( $settings['default_destination'] ) ? PIB_Destination::sanitize_destination( (string) $settings['default_destination'], '/' ) : '/';
		$failure_dest = isset( $settings['failure_destination'] ) ? PIB_Destination::sanitize_destination( (string) $settings['failure_destination'], '/' ) : '/';
		$default_eval = PIB_Protected_Route_Policy::evaluate_destination( $default_dest, $settings );
		$failure_eval = PIB_Protected_Route_Policy::evaluate_destination( $failure_dest, $settings );
		$test_raw     = $this->request_text( 'dest' );
		$test_eval    = null;

		if ( '' !== $test_raw ) {
			$test_eval = PIB_Protected_Route_Policy::evaluate_destination( $test_raw, $settings );
		}

		$checks = array();
		if ( empty( $rules['protected_prefixes'] ) ) {
			$checks[] = array(
				'level'   => 'error',
				'message' => 'Protected Route Prefixes are empty; no protected destinations can be enforced.',
				'action'  => 'Configure Route Prefixes',
				'url'     => $setup_url,
			);
		}
		if ( in_array( '/', $rules['unprotected_prefixes'], true ) ) {
			$checks[] = array(
				'level'   => 'error',
				'message' => 'Unprotected Route Prefixes include "/" and disable protection globally.',
				'action'  => 'Fix Unprotected Prefixes',
				'url'     => $setup_url,
			);
		}
		if ( ! empty( $failure_eval['is_protected'] ) ) {
			$checks[] = array(
				'level'   => 'error',
				'message' => 'Failure Destination is protected and may cause sign-in loops.',
				'action'  => 'Fix Failure Destination',
				'url'     => $setup_url,
			);
		} else {
			$checks[] = array(
				'level'   => 'ok',
				'message' => 'Failure Destination is publicly reachable.',
			);
		}
		if ( empty( $default_eval['is_protected'] ) ) {
			$checks[] = array(
				'level'   => 'warning',
				'message' => 'Default Destination is not currently protected.',
				'action'  => 'Review Default Destination',
				'url'     => $setup_url,
			);
		} else {
			$checks[] = array(
				'level'   => 'ok',
				'message' => 'Default Destination is protected.',
			);
		}

		echo '<h2 style="margin-top:18px;">Route Access Assistant</h2>';
		echo '<p>Use this page to diagnose redirect loops and understand exactly how destination protection is resolved.</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( $setup_url ) . '">Open Bridge Setup</a> <a class="button" href="' . esc_url( $failures_url ) . '">Open Mapping Failures</a> <a class="button" href="' . esc_url( $token_url ) . '">Open Token Check</a></p>';

		$managed_required = ! empty( $settings['require_managed_session_for_protected_routes'] );
		$mapping_required = ! empty( $settings['require_active_mapping'] );

		echo '<h3 style="margin-top:18px;">Current Policy Inputs</h3>';
		echo '<table class="widefat striped" style="margin-top:10px;max-width:1000px;"><tbody>';
		echo '<tr><th style="width:240px;">Protected Prefixes</th><td><code>' . esc_html( implode( ', ', $rules['protected_prefixes'] ) ) . '</code></td><td>' . esc_html( 'Routes matching these prefixes are protection candidates.' ) . '</td></tr>';
		echo '<tr><th>Unprotected Prefixes</th><td><code>' . esc_html( implode( ', ', $rules['unprotected_prefixes'] ) ) . '</code></td><td>' . esc_html( 'These override protected matches and stay public.' ) . '</td></tr>';
		echo '<tr><th>Default Destination</th><td><code>' . esc_html( $default_dest ) . '</code></td><td>' . esc_html( ! empty( $default_eval['is_protected'] ) ? 'Currently protected.' : 'Currently public.' ) . '</td></tr>';
		echo '<tr><th>Failure Destination</th><td><code>' . esc_html( $failure_dest ) . '</code></td><td>' . esc_html( ! empty( $failure_eval['is_protected'] ) ? 'Currently protected (loop risk).' : 'Currently public (recommended).' ) . '</td></tr>';
		echo '<tr><th>Require Managed Session</th><td><code>' . esc_html( $managed_required ? 'enabled' : 'disabled' ) . '</code></td><td>' . esc_html( $managed_required ? 'Protected routes require bridge-managed session context.' : 'Any valid authenticated WP session can pass protected-route gate.' ) . '</td></tr>';
		echo '<tr><th>Require Active Mapping</th><td><code>' . esc_html( $mapping_required ? 'enabled' : 'disabled' ) . '</code></td><td>' . esc_html( $mapping_required ? 'Protected routes also require active customer mapping context.' : 'Mapping context is not required for protected-route pass.' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h3 style="margin-top:18px;">Risk Checks</h3>';
		echo '<ul>';
		foreach ( $checks as $check ) {
			$level = isset( $check['level'] ) ? (string) $check['level'] : 'ok';
			$label = 'OK';
			if ( 'error' === $level ) {
				$label = 'Action Needed';
			} elseif ( 'warning' === $level ) {
				$label = 'Warning';
			}
			$message = isset( $check['message'] ) ? (string) $check['message'] : '';
			$action  = isset( $check['action'] ) ? sanitize_text_field( (string) $check['action'] ) : '';
			$url     = isset( $check['url'] ) ? esc_url_raw( (string) $check['url'] ) : '';
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $message );
			if ( '' !== $action && '' !== $url ) {
				echo ' <a href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<h3 style="margin-top:18px;">Route Tester</h3>';
		echo '<form method="get" action="' . esc_url( $this->advanced_panel_url( 'routes' ) ) . '">';
		echo '<input type="hidden" name="page" value="pib-tools" />';
		echo '<input type="hidden" name="tab" value="advanced" />';
		echo '<input type="hidden" name="panel" value="routes" />';
		echo '<input type="text" class="regular-text code" name="dest" value="' . esc_attr( $test_raw ) . '" placeholder="/dashboard/backorders?status=open" /> ';
		submit_button( 'Evaluate', 'secondary', '', false );
		echo '</form>';

		if ( is_array( $test_eval ) ) {
			$is_protected = ! empty( $test_eval['is_protected'] );
			echo '<table class="widefat striped" style="max-width:1000px;margin-top:12px;"><tbody>';
			echo '<tr><th style="width:240px;">Result</th><td>' . esc_html( $is_protected ? 'Protected' : 'Public' ) . '</td></tr>';
			echo '<tr><th>Normalized Destination</th><td><code>' . esc_html( isset( $test_eval['destination'] ) ? (string) $test_eval['destination'] : '' ) . '</code></td></tr>';
			echo '<tr><th>Matched Protected Prefix</th><td><code>' . esc_html( isset( $test_eval['matched_protected'] ) ? (string) $test_eval['matched_protected'] : '' ) . '</code></td></tr>';
			echo '<tr><th>Matched Unprotected Prefix</th><td><code>' . esc_html( isset( $test_eval['matched_unprotected'] ) ? (string) $test_eval['matched_unprotected'] : '' ) . '</code></td></tr>';
			echo '<tr><th>Expected Visitor Behavior</th><td>' . esc_html( $this->route_behavior_summary( $test_eval, $settings ) ) . '</td></tr>';
			echo '</tbody></table>';
		}

		$samples = PIB_Protected_Route_Policy::sample_destinations( $settings );
		echo '<h3 style="margin-top:18px;">Sample Routes</h3>';
		echo '<table class="widefat striped" style="max-width:1000px;">';
		echo '<thead><tr><th>Destination</th><th>Protected?</th><th>Matched Protected</th><th>Matched Unprotected</th><th>Expected Behavior</th><th>Action</th></tr></thead><tbody>';
		foreach ( $samples as $sample ) {
			$evaluation = PIB_Protected_Route_Policy::evaluate_destination( $sample, $settings );
			$is_protected = ! empty( $evaluation['is_protected'] );
			$eval_url = $this->advanced_panel_url(
				'routes',
				array(
					'dest' => (string) $sample,
				)
			);
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $sample ) . '</code></td>';
			echo '<td>' . esc_html( $is_protected ? 'Yes' : 'No' ) . '</td>';
			echo '<td><code>' . esc_html( isset( $evaluation['matched_protected'] ) ? (string) $evaluation['matched_protected'] : '' ) . '</code></td>';
			echo '<td><code>' . esc_html( isset( $evaluation['matched_unprotected'] ) ? (string) $evaluation['matched_unprotected'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( $this->route_behavior_summary( $evaluation, $settings ) ) . '</td>';
			echo '<td><a href="' . esc_url( $eval_url ) . '">Evaluate</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Build visitor behavior summary for route policy evaluation result.
	 *
	 * @param array<string, mixed> $evaluation Route evaluation payload.
	 * @param array<string, mixed> $settings   Current settings.
	 *
	 * @return string
	 */
	private function route_behavior_summary( $evaluation, $settings ) {
		$evaluation = is_array( $evaluation ) ? $evaluation : array();
		$settings   = is_array( $settings ) ? $settings : array();
		$is_protected = ! empty( $evaluation['is_protected'] );
		if ( ! $is_protected ) {
			return 'Public route; protection redirect does not trigger.';
		}

		$managed_required = ! empty( $settings['require_managed_session_for_protected_routes'] );
		$mapping_required = ! empty( $settings['require_active_mapping'] );
		$parts            = array();

		if ( $managed_required ) {
			$parts[] = 'requires bridge-managed session';
		} else {
			$parts[] = 'requires authenticated WordPress session';
		}
		if ( $mapping_required ) {
			$parts[] = 'requires active customer mapping';
		}

		return 'Protected route; ' . implode( ' and ', $parts ) . '.';
	}

	/**
	 * Render extension modules diagnostics tab.
	 *
	 * @return void
	 */
	private function render_extensions_tab() {
		$registry = $this->plugin->get_module_registry();
		$module_ids = array();
		if ( $registry instanceof PIB_Module_Registry ) {
			$module_ids = $registry->all_module_ids();
		}

		echo '<h2 style="margin-top:18px;">Plugin Module Lifecycle</h2>';
		if ( empty( $module_ids ) ) {
			echo '<p>No boot modules were reported.</p>';
		} else {
			echo '<p>Boot order for registered plugin modules:</p>';
			echo '<ol style="margin-left:18px;">';
			foreach ( $module_ids as $module_id ) {
				echo '<li><code>' . esc_html( (string) $module_id ) . '</code></li>';
			}
			echo '</ol>';
		}

		$portal_registry = PIB_Portal_Modules::instance();
		if ( ! $portal_registry instanceof PIB_Portal_Modules ) {
			echo '<h2 style="margin-top:24px;">Portal Extensions</h2>';
			echo '<p>Portal module registry is unavailable.</p>';
			return;
		}

		$settings = $this->plugin->get_settings();
		$modules  = $portal_registry->get_modules(
			array(
				'context'                  => 'admin_tools',
				'include_hidden'           => true,
				'settings'                 => $settings,
				'user_id'                  => 0,
				'ignore_user_requirements' => true,
			)
		);

		echo '<h2 style="margin-top:24px;">Portal Extensions</h2>';
		echo '<p>Registered portal modules that can be rendered via <code>[portal_dashboard]</code>.</p>';

		if ( empty( $modules ) ) {
			echo '<p>No portal modules are registered. Use <code>pib_register_portal_modules</code> to register extensions.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px;">';
			echo '<thead><tr>';
			echo '<th>ID</th><th>Label</th><th>Source</th><th>Destination</th><th>Shortcode</th><th>Protected</th><th>Status</th><th>Reason</th>';
			echo '</tr></thead><tbody>';

			foreach ( $modules as $module ) {
				if ( ! is_array( $module ) ) {
					continue;
				}

				$status = ! empty( $module['is_available'] ) ? 'Available' : 'Hidden';
				$reason = isset( $module['hidden_reason'] ) ? (string) $module['hidden_reason'] : '';
				$protected = ! empty( $module['route_is_protected'] ) ? 'Yes' : 'No';

				echo '<tr>';
				echo '<td><code>' . esc_html( isset( $module['id'] ) ? (string) $module['id'] : '' ) . '</code></td>';
				echo '<td>' . esc_html( isset( $module['label'] ) ? (string) $module['label'] : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $module['source'] ) ? (string) $module['source'] : '' ) . '</td>';
				echo '<td><code>' . esc_html( isset( $module['destination'] ) ? (string) $module['destination'] : '' ) . '</code></td>';
				$shortcode = isset( $module['shortcode'] ) ? sanitize_key( (string) $module['shortcode'] ) : '';
				echo '<td><code>' . esc_html( '' !== $shortcode ? '[' . $shortcode . ']' : '' ) . '</code></td>';
				echo '<td>' . esc_html( $protected ) . '</td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td><code>' . esc_html( $reason ) . '</code></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3 style="margin-top:20px;">Extension Hook Example</h3>';
		echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;overflow:auto;"><code>';
		echo esc_html( "add_action( 'pib_register_portal_modules', function( \$registry ) {\n\tif ( ! \$registry instanceof PIB_Portal_Modules ) {\n\t\treturn;\n\t}\n\n\t\$registry->register_module( 'invoices', array(\n\t\t'label'             => 'Invoices',\n\t\t'description'       => 'View billing documents.',\n\t\t'destination'       => '/dashboard/invoices',\n\t\t'shortcode'         => 'portal_invoices',\n\t\t'requires_auth'     => true,\n\t\t'requires_identity' => true,\n\t\t'source'            => 'custom',\n\t\t'priority'          => 30,\n\t) );\n}, 20 );" );
		echo '</code></pre>';
	}

	/**
	 * Render operational health diagnostics tab.
	 *
	 * @return void
	 */
	private function render_health_tab() {
		$health_module = PIB_Operations_Health::instance( $this->plugin );
		if ( ! $health_module instanceof PIB_Operations_Health ) {
			echo '<p>Operations health module is unavailable.</p>';
			return;
		}

		$snapshot = $health_module->build_snapshot();
		$health   = isset( $snapshot['health'] ) && is_array( $snapshot['health'] ) ? $snapshot['health'] : array();
		$status   = isset( $health['status'] ) ? sanitize_key( (string) $health['status'] ) : 'healthy';
		$summary  = isset( $health['summary'] ) && is_array( $health['summary'] ) ? $health['summary'] : array();
		$checks   = isset( $health['checks'] ) && is_array( $health['checks'] ) ? $health['checks'] : array();

		$status_label = 'Healthy';
		if ( 'action_needed' === $status ) {
			$status_label = 'Action Needed';
		} elseif ( 'warnings' === $status ) {
			$status_label = 'Warnings';
		}

		$download_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'pib-tools',
					'tab'        => 'health',
					'pib_action' => 'download_health_snapshot',
				),
				admin_url( 'admin.php' )
			),
			'pib_download_health_snapshot'
		);

		echo '<h2 style="margin-top:18px;">Operational Health</h2>';
		echo '<p>Runtime checks that validate configuration integrity, module state, data tables, and recent auth/mapping signals.</p>';
		echo '<p><strong>Overall Status:</strong> ' . esc_html( $status_label ) . '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( $this->tab_url( 'health' ) ) . '">Refresh Checks</a> ';
		echo '<a class="button button-primary" href="' . esc_url( $download_url ) . '">Download JSON Snapshot</a></p>';

		echo '<table class="widefat striped" style="max-width:780px;"><tbody>';
		echo '<tr><th style="width:220px;">Critical Checks</th><td>' . esc_html( (string) absint( isset( $summary['critical'] ) ? $summary['critical'] : 0 ) ) . '</td></tr>';
		echo '<tr><th>Warning Checks</th><td>' . esc_html( (string) absint( isset( $summary['warning'] ) ? $summary['warning'] : 0 ) ) . '</td></tr>';
		echo '<tr><th>Passing Checks</th><td>' . esc_html( (string) absint( isset( $summary['ok'] ) ? $summary['ok'] : 0 ) ) . '</td></tr>';
		echo '<tr><th>Total Checks</th><td>' . esc_html( (string) absint( isset( $summary['total'] ) ? $summary['total'] : 0 ) ) . '</td></tr>';
		echo '<tr><th>Generated (UTC)</th><td><code>' . esc_html( isset( $snapshot['generated_at_utc'] ) ? (string) $snapshot['generated_at_utc'] : '' ) . '</code></td></tr>';
		echo '<tr><th>Next Trim Schedule (UTC)</th><td><code>' . esc_html( isset( $snapshot['trim_schedule_next_utc'] ) ? (string) $snapshot['trim_schedule_next_utc'] : '' ) . '</code></td></tr>';
		echo '</tbody></table>';

		echo '<h3 style="margin-top:18px;">Checks</h3>';
		echo '<table class="widefat striped" style="max-width:1080px;">';
		echo '<thead><tr><th style="width:180px;">ID</th><th style="width:160px;">Level</th><th>Message</th></tr></thead><tbody>';
		if ( empty( $checks ) ) {
			echo '<tr><td colspan="3">No checks were generated.</td></tr>';
		} else {
			foreach ( $checks as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}

				$check_id    = isset( $check['id'] ) ? sanitize_key( (string) $check['id'] ) : '';
				$check_level = isset( $check['level'] ) ? sanitize_key( (string) $check['level'] ) : 'ok';
				$message     = isset( $check['message'] ) ? (string) $check['message'] : '';

				$level_label = 'OK';
				if ( 'critical' === $check_level ) {
					$level_label = 'Action Needed';
				} elseif ( 'warning' === $check_level ) {
					$level_label = 'Warning';
				}

				echo '<tr>';
				echo '<td><code>' . esc_html( $check_id ) . '</code></td>';
				echo '<td>' . esc_html( $level_label ) . '</td>';
				echo '<td>' . esc_html( $message ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		$json = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		echo '<h3 style="margin-top:18px;">Snapshot Preview</h3>';
		echo '<p class="description">Snapshot payload is redacted for sensitive values and can be shared with support engineers.</p>';
		echo '<textarea class="large-text code" rows="18" readonly>' . esc_textarea( $json ) . '</textarea>';
	}

	/**
	 * Render audit logs tab.
	 *
	 * @return void
	 */
	private function render_logs_tab() {
		$logger = PIB_Audit_Log::instance();
		if ( ! $logger instanceof PIB_Audit_Log ) {
			echo '<p>Audit log module is unavailable.</p>';
			return;
		}

		$search = $this->request_text( 'q' );
		$level  = $this->request_text( 'level' );
		$event  = $this->request_text( 'event' );
		$page   = max( 1, absint( $this->request_text( 'paged' ) ) );
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$rows = $logger->get_logs(
			array(
				'search' => $search,
				'level'  => $level,
				'event'  => $event,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
		$total = $logger->count_logs(
			array(
				'search' => $search,
				'level'  => $level,
				'event'  => $event,
			)
		);

		echo '<form method="get" style="margin-top:16px;">';
		echo '<input type="hidden" name="page" value="pib-tools" />';
		echo '<input type="hidden" name="tab" value="logs" />';
		echo '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search logs..." />';
		echo '<select name="level">';
		echo '<option value="" ' . selected( $level, '', false ) . '>All levels</option>';
		echo '<option value="info" ' . selected( $level, 'info', false ) . '>Info</option>';
		echo '<option value="warning" ' . selected( $level, 'warning', false ) . '>Warning</option>';
		echo '<option value="error" ' . selected( $level, 'error', false ) . '>Error</option>';
		echo '</select> ';
		echo '<input type="text" name="event" value="' . esc_attr( $event ) . '" placeholder="event_type" /> ';
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		$clear_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'pib-tools',
					'tab'        => 'logs',
					'pib_action' => 'clear_logs',
				),
				admin_url( 'admin.php' )
			),
			'pib_clear_logs'
		);
		echo '<p><a class="button button-secondary" href="' . esc_url( $clear_url ) . '" onclick="return confirm(\'Clear all audit logs?\');">Clear Logs</a></p>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Time</th><th>Level</th><th>Event</th><th>Message</th><th>User</th><th>Customer</th><th>Company</th><th>IP</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="9">No logs found.</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) $row['id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['level'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['event_type'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['message'] ) . '</td>';
				echo '<td>' . esc_html( (string) absint( $row['user_id'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['shopify_customer_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['company_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['ip_address'] ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo $this->pagination(
			$total,
			$page,
			$limit,
			'logs',
			array(
				'q'     => $search,
				'level' => $level,
				'event' => $event,
			)
		);
	}

	/**
	 * Handle page-level admin actions.
	 *
	 * @return void
	 */
	private function handle_admin_actions() {
		$action = $this->request_text( 'pib_action' );
		if ( '' === $action ) {
			return;
		}

		if ( 'download_health_snapshot' === $action ) {
			check_admin_referer( 'pib_download_health_snapshot' );

			$health_module = PIB_Operations_Health::instance( $this->plugin );
			if ( ! $health_module instanceof PIB_Operations_Health ) {
				wp_safe_redirect(
					$this->tab_url(
						'health',
						array(
							'pib_notice'      => 'health_snapshot_unavailable',
							'pib_notice_type' => 'error',
						)
					)
				);
				exit;
			}

			$snapshot = $health_module->build_snapshot();
			$json     = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				$json = '{}';
			}

			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="pib-health-snapshot-' . gmdate( 'Ymd-His' ) . '.json"' );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( 'clear_logs' === $action ) {
			check_admin_referer( 'pib_clear_logs' );
			$logger = PIB_Audit_Log::instance();
			if ( $logger instanceof PIB_Audit_Log ) {
				$logger->clear_logs();
			}
			wp_safe_redirect( $this->tab_url( 'logs', array( 'pib_notice' => 'logs_cleared', 'pib_notice_type' => 'success' ) ) );
			exit;
		}

		if ( 'toggle_mapping_status' === $action || 'set_mapping_status' === $action ) {
			$mapping_id = absint( $this->request_text( 'mapping_id' ) );
			$to_status  = sanitize_key( $this->request_text( 'to_status' ) );
			if ( 'toggle_mapping_status' === $action ) {
				$to_status = 'active' === $to_status ? 'blocked' : 'active';
			}
			if ( ! in_array( $to_status, array( 'active', 'blocked', 'archived' ), true ) ) {
				$to_status = 'active';
			}

			check_admin_referer( 'pib_mapping_action_' . $mapping_id );

			$updated = false;
			$mapping_module = PIB_Customer_Mapping::instance();
			if ( $mapping_module instanceof PIB_Customer_Mapping && $mapping_id > 0 ) {
				$updated = $mapping_module->update_mapping(
					$mapping_id,
					array(
						'status' => $to_status,
					)
				);
			}

			wp_safe_redirect(
				$this->tab_url(
					'mappings',
					array(
						'pib_notice'      => $updated ? 'mapping_updated' : 'mapping_update_failed',
						'pib_notice_type' => $updated ? 'success' : 'error',
					)
				)
			);
			exit;
		}

		if ( 'upsert_mapping' === $action ) {
			check_admin_referer( 'pib_upsert_mapping' );

			$customer_id = $this->request_text( 'shopify_customer_id' );
			$company_id  = $this->request_text( 'company_id' );
			$email       = $this->request_text( 'email' );
			$status      = $this->request_text( 'status' );
			$user_lookup = $this->request_text( 'user_lookup' );

			$user = $this->resolve_user_lookup( $user_lookup );
			if ( ! $user instanceof WP_User ) {
				wp_safe_redirect(
					$this->tab_url(
						'mappings',
						array(
							'pib_notice'      => 'mapping_upsert_user_not_found',
							'pib_notice_type' => 'error',
							'prefill_customer'=> $customer_id,
							'prefill_company' => $company_id,
							'prefill_email'   => $email,
							'prefill_user'    => $user_lookup,
						)
					)
				);
				exit;
			}

			$mapping_module = PIB_Customer_Mapping::instance();
			if ( ! $mapping_module instanceof PIB_Customer_Mapping ) {
				wp_safe_redirect(
					$this->tab_url(
						'mappings',
						array(
							'pib_notice'      => 'mapping_upsert_unavailable',
							'pib_notice_type' => 'error',
						)
					)
				);
				exit;
			}

			$result = $mapping_module->admin_upsert_mapping(
				$customer_id,
				$company_id,
				$user->ID,
				$email,
				$status
			);

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					$this->tab_url(
						'mappings',
						array(
							'pib_notice'      => sanitize_key( (string) $result->get_error_code() ),
							'pib_notice_type' => 'error',
							'prefill_customer'=> $customer_id,
							'prefill_company' => $company_id,
							'prefill_email'   => $email,
							'prefill_user'    => (string) $user->ID,
						)
					)
				);
				exit;
			}

			wp_safe_redirect(
				$this->tab_url(
					'mappings',
					array(
						'pib_notice'      => 'mapping_upserted',
						'pib_notice_type' => 'success',
					)
				)
			);
			exit;
		}
	}

	/**
	 * Render create/link mapping operation form.
	 *
	 * @param array<string, string> $prefill Prefill values.
	 *
	 * @return void
	 */
	private function render_mapping_upsert_form( $prefill ) {
		$prefill = wp_parse_args(
			is_array( $prefill ) ? $prefill : array(),
			array(
				'customer' => '',
				'company'  => '',
				'email'    => '',
				'user'     => '',
			)
		);

		echo '<h2 style="margin-top:18px;">Create or Link Mapping</h2>';
		echo '<p>Create or update Shopify customer/company to WordPress user mapping records.</p>';
		echo '<form method="post" action="' . esc_url( $this->tab_url( 'mappings' ) ) . '" style="margin-top:10px;">';
		wp_nonce_field( 'pib_upsert_mapping' );
		echo '<input type="hidden" name="page" value="pib-tools" />';
		echo '<input type="hidden" name="tab" value="mappings" />';
		echo '<input type="hidden" name="pib_action" value="upsert_mapping" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pib_map_customer">Shopify Customer ID</label></th><td><input id="pib_map_customer" class="regular-text" name="shopify_customer_id" value="' . esc_attr( (string) $prefill['customer'] ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="pib_map_company">Company ID</label></th><td><input id="pib_map_company" class="regular-text" name="company_id" value="' . esc_attr( (string) $prefill['company'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="pib_map_user">WordPress User</label></th><td><input id="pib_map_user" class="regular-text" name="user_lookup" value="' . esc_attr( (string) $prefill['user'] ) . '" placeholder="ID, login, or email" required /><p class="description">Lookup accepts WordPress user ID, login, or email.</p></td></tr>';
		echo '<tr><th scope="row"><label for="pib_map_email">Email</label></th><td><input id="pib_map_email" class="regular-text" name="email" value="' . esc_attr( (string) $prefill['email'] ) . '" placeholder="customer@example.com" /></td></tr>';
		echo '<tr><th scope="row"><label for="pib_map_status">Status</label></th><td><select id="pib_map_status" name="status"><option value="active">Active</option><option value="blocked">Blocked</option><option value="archived">Archived</option></select></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Create/Update Mapping' );
		echo '</form>';
	}

	/**
	 * Build mapping status action URL.
	 *
	 * @param int    $mapping_id Mapping ID.
	 * @param string $to_status  Destination status.
	 *
	 * @return string
	 */
	private function mapping_status_url( $mapping_id, $to_status ) {
		return add_query_arg(
			array(
				'page'       => 'pib-tools',
				'tab'        => 'mappings',
				'pib_action' => 'set_mapping_status',
				'mapping_id' => absint( $mapping_id ),
				'to_status'  => sanitize_key( (string) $to_status ),
				'_wpnonce'   => wp_create_nonce( 'pib_mapping_action_' . absint( $mapping_id ) ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Resolve user from admin lookup field (ID, login, or email).
	 *
	 * @param string $lookup Lookup value.
	 *
	 * @return WP_User|false
	 */
	private function resolve_user_lookup( $lookup ) {
		$lookup = trim( (string) $lookup );
		if ( '' === $lookup ) {
			return false;
		}

		if ( ctype_digit( $lookup ) ) {
			$user = get_user_by( 'id', absint( $lookup ) );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}

		$email = sanitize_email( $lookup );
		if ( '' !== $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}

		$login = sanitize_user( $lookup );
		if ( '' !== $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}

		return false;
	}

	/**
	 * Render query-string notice banner.
	 *
	 * @return void
	 */
	private function render_notice_from_query() {
		$notice = sanitize_key( $this->request_text( 'pib_notice' ) );
		if ( '' === $notice ) {
			return;
		}

		$type = sanitize_key( $this->request_text( 'pib_notice_type' ) );
		if ( '' === $type ) {
			$type = 'success';
		}

		$message_map = array(
			'logs_cleared'                    => 'Audit logs were cleared.',
			'health_snapshot_unavailable'     => 'Operations health snapshot module is unavailable.',
			'mapping_updated'                 => 'Mapping was updated.',
			'mapping_update_failed'           => 'Mapping update failed.',
			'mapping_upserted'                => 'Mapping was created/updated.',
			'mapping_upsert_user_not_found'   => 'WordPress user lookup failed. Use a valid ID, login, or email.',
			'mapping_upsert_unavailable'      => 'Mapping module is unavailable.',
			'pib_admin_missing_customer_id'   => 'Shopify customer ID is required.',
			'pib_admin_invalid_user'          => 'A valid WordPress user is required.',
			'pib_admin_user_not_found'        => 'WordPress user could not be found.',
			'pib_admin_user_conflict'         => 'Selected WordPress user is actively mapped to a different Shopify customer.',
			'pib_admin_mapping_upsert_failed' => 'Unable to create or update mapping record.',
			'pib_admin_mapping_missing'       => 'Mapping record could not be loaded after update.',
		);

		$message = isset( $message_map[ $notice ] ) ? $message_map[ $notice ] : 'Operation completed.';

		$class = 'notice notice-success is-dismissible';
		if ( 'error' === $type ) {
			$class = 'notice notice-error is-dismissible';
		} elseif ( 'warning' === $type ) {
			$class = 'notice notice-warning is-dismissible';
		} elseif ( 'info' === $type ) {
			$class = 'notice notice-info is-dismissible';
		}

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Build tools tab URL.
	 *
	 * @param string               $tab   Tab slug.
	 * @param array<string, mixed> $extra Extra query args.
	 *
	 * @return string
	 */
	private function tab_url( $tab, $extra = array() ) {
		$args = array_merge(
			array(
				'page' => 'pib-tools',
				'tab'  => $tab,
			),
			is_array( $extra ) ? $extra : array()
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build advanced panel URL.
	 *
	 * @param string               $panel Panel slug.
	 * @param array<string, mixed> $extra Extra query args.
	 *
	 * @return string
	 */
	private function advanced_panel_url( $panel, $extra = array() ) {
		$panel = sanitize_key( (string) $panel );
		if ( '' === $panel ) {
			$panel = 'routes';
		}

		$extra = is_array( $extra ) ? $extra : array();
		$args  = array_merge(
			array(
				'panel' => $panel,
			),
			$extra
		);

		return $this->tab_url( 'advanced', $args );
	}

	/**
	 * Build Setup page URL.
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
	 * Render simple pagination links.
	 *
	 * @param int                  $total Total rows.
	 * @param int                  $page Current page.
	 * @param int                  $limit Rows per page.
	 * @param string               $tab Tab slug.
	 * @param array<string, mixed> $extra Extra query args.
	 *
	 * @return string
	 */
	private function pagination( $total, $page, $limit, $tab, $extra = array() ) {
		$total_pages = (int) ceil( max( 1, absint( $total ) ) / max( 1, absint( $limit ) ) );
		if ( $total_pages <= 1 ) {
			return '';
		}

		$extra = is_array( $extra ) ? $extra : array();
		$out   = '<p style="margin-top:12px;">';
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url_args = array_merge( $extra, array( 'paged' => $i ) );
			$url_args = array_filter(
				$url_args,
				function( $value ) {
					return null !== $value && '' !== (string) $value;
				}
			);

			$url = $this->tab_url( $tab, $url_args );
			if ( $i === $page ) {
				$out .= '<strong>' . esc_html( (string) $i ) . '</strong> ';
			} else {
				$out .= '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
		}
		$out .= '</p>';

		return $out;
	}

	/**
	 * Read scalar request value as text.
	 *
	 * @param string $key Request key.
	 *
	 * @return string
	 */
	private function request_text( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) wp_unslash( $_REQUEST[ $key ] ) );
	}
}
