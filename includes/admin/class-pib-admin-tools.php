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

		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Register tools page under Tools menu.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			'Portal Bridge Tools',
			'Portal Bridge Tools',
			'manage_options',
			'pib-tools',
			array( $this, 'render_page' )
		);
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
			$tab = 'mappings';
		}
		if ( ! in_array( $tab, array( 'mappings', 'logs' ), true ) ) {
			$tab = 'mappings';
		}

		echo '<div class="wrap">';
		echo '<h1>Portal Bridge Tools</h1>';
		echo '<p>Operational controls for customer mappings, audit events, and diagnostics.</p>';
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab ' . ( 'mappings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'mappings' ) ) . '">Mappings</a>';
		echo '<a class="nav-tab ' . ( 'logs' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'logs' ) ) . '">Audit Logs</a>';
		echo '</h2>';

		if ( 'logs' === $tab ) {
			$this->render_logs_tab();
		} else {
			$this->render_mappings_tab();
		}

		echo '</div>';
	}

	/**
	 * Render mapping inspector tab.
	 *
	 * @return void
	 */
	private function render_mappings_tab() {
		$mapping_module = PIB_Customer_Mapping::instance();
		if ( ! $mapping_module instanceof PIB_Customer_Mapping ) {
			echo '<p>Mapping module is unavailable.</p>';
			return;
		}

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

				$action_nonce = wp_create_nonce( 'pib_mapping_action_' . $mapping_id );
				$toggle_to    = 'active' === (string) $row['status'] ? 'blocked' : 'active';

				$toggle_url = add_query_arg(
					array(
						'page'       => 'pib-tools',
						'tab'        => 'mappings',
						'pib_action' => 'toggle_mapping_status',
						'mapping_id' => $mapping_id,
						'to_status'  => $toggle_to,
						'_wpnonce'   => $action_nonce,
					),
					admin_url( 'tools.php' )
				);

				echo '<tr>';
				echo '<td>' . esc_html( (string) $mapping_id ) . '</td>';
				echo '<td>' . esc_html( (string) $row['shopify_customer_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['company_id'] ) . '</td>';
				echo '<td>' . esc_html( $user_label ) . '</td>';
				echo '<td>' . esc_html( (string) $row['email'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['updated_at'] ) . '</td>';
				echo '<td><a href="' . esc_url( $toggle_url ) . '">' . esc_html( 'active' === (string) $row['status'] ? 'Block' : 'Activate' ) . '</a></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo $this->pagination( $total, $page, $limit, 'mappings', $search, $status, '' );
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
				admin_url( 'tools.php' )
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
		echo $this->pagination( $total, $page, $limit, 'logs', $search, '', $event );
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

		if ( 'clear_logs' === $action ) {
			check_admin_referer( 'pib_clear_logs' );
			$logger = PIB_Audit_Log::instance();
			if ( $logger instanceof PIB_Audit_Log ) {
				$logger->clear_logs();
			}
			wp_safe_redirect( $this->tab_url( 'logs', array( 'pib_notice' => 'logs_cleared' ) ) );
			exit;
		}

		if ( 'toggle_mapping_status' === $action ) {
			$mapping_id = absint( $this->request_text( 'mapping_id' ) );
			$to_status  = sanitize_key( $this->request_text( 'to_status' ) );
			if ( ! in_array( $to_status, array( 'active', 'blocked', 'archived' ), true ) ) {
				$to_status = 'active';
			}

			check_admin_referer( 'pib_mapping_action_' . $mapping_id );

			$mapping_module = PIB_Customer_Mapping::instance();
			if ( $mapping_module instanceof PIB_Customer_Mapping && $mapping_id > 0 ) {
				$mapping_module->update_mapping(
					$mapping_id,
					array(
						'status' => $to_status,
					)
				);
			}

			wp_safe_redirect( $this->tab_url( 'mappings', array( 'pib_notice' => 'mapping_updated' ) ) );
			exit;
		}
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

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Render simple pagination links.
	 *
	 * @param int    $total  Total rows.
	 * @param int    $page   Current page.
	 * @param int    $limit  Rows per page.
	 * @param string $tab    Tab slug.
	 * @param string $search Search query.
	 * @param string $status Status filter.
	 * @param string $event  Event filter.
	 *
	 * @return string
	 */
	private function pagination( $total, $page, $limit, $tab, $search = '', $status = '', $event = '' ) {
		$total_pages = (int) ceil( max( 1, absint( $total ) ) / max( 1, absint( $limit ) ) );
		if ( $total_pages <= 1 ) {
			return '';
		}

		$out = '<p style="margin-top:12px;">';
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url = $this->tab_url(
				$tab,
				array_filter(
					array(
						'paged' => $i,
						'q'     => $search,
						'status'=> $status,
						'event' => $event,
					),
					function( $value ) {
						return '' !== (string) $value;
					}
				)
			);

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
