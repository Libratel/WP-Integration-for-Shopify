<?php
/**
 * Backorders dashboard module.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders customer-scoped backorders and CSV export.
 */
final class PIB_Backorders {
	/**
	 * Singleton.
	 *
	 * @var PIB_Backorders|null
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
	 * @return PIB_Backorders|null
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

		add_shortcode( 'portal_backorders', array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_nopriv_pib_backorders_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_pib_backorders_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Render backorders dashboard.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$settings = $this->plugin->get_settings();

		if ( empty( $settings['backorders_enabled'] ) ) {
			return '<p>Backorders are currently unavailable.</p>';
		}

		if ( ! is_user_logged_in() ) {
			$signin = PIB_Signin_Link::instance( $this->plugin );
			if ( $signin instanceof PIB_Signin_Link ) {
				$url = $signin->build_signin_url( '/dashboard/backorders' );
				if ( '' !== $url ) {
					return '<p>Please <a href="' . esc_url( $url ) . '">sign in</a> to view your backorders.</p>';
				}
			}
			return '<p>Please sign in to view your backorders.</p>';
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			return '<p>Your portal account is not linked to a customer record yet.</p>';
		}

		$filters = $this->parse_filters( $settings );
		$records = PIB_Backorders_Source::fetch_records( $identity, $filters, $settings );

		if ( is_wp_error( $records ) ) {
			do_action( 'pib_backorders_error', $records->get_error_code(), $records->get_error_message(), $identity, $filters );
			return '<p>We could not load backorders right now. Please try again shortly.</p>';
		}

		do_action( 'pib_backorders_view', get_current_user_id(), $identity, count( $records ), $filters );

		$export_url = add_query_arg(
			array(
				'action' => 'pib_backorders_export',
				'status' => $filters['status'],
				'limit'  => $filters['limit'],
			),
			admin_url( 'admin-post.php' )
		);
		$export_url = wp_nonce_url( $export_url, 'pib_backorders_export', 'pib_nonce' );

		$output = '<div class="pib-backorders">';
		$output .= '<p><a class="button" href="' . esc_url( $export_url ) . '">Export CSV</a></p>';

		if ( empty( $records ) ) {
			$output .= '<p>No backorders found for your account.</p></div>';
			return $output;
		}

		$output .= '<table class="pib-backorders-table" style="width:100%;border-collapse:collapse;">';
		$output .= '<thead><tr>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Order</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">SKU</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Product</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Qty</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Status</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Backorder Date</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Expected Date</th>';
		$output .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Reference</th>';
		$output .= '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$output .= '<tr>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['order_number'] ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['sku'] ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['product_name'] ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['quantity_backordered'] ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['status'] ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $this->format_date( (string) $record['backorder_date'] ) ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $this->format_date( (string) $record['expected_date'] ) ) . '</td>';
			$output .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( (string) $record['reference'] ) . '</td>';
			$output .= '</tr>';
		}

		$output .= '</tbody></table></div>';

		return $output;
	}

	/**
	 * Export backorders as CSV for current user scope.
	 *
	 * @return void
	 */
	public function handle_export() {
		$settings = $this->plugin->get_settings();

		if ( empty( $settings['backorders_enabled'] ) ) {
			$this->fail( 'pib_backorders_disabled' );
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_to_signin();
		}

		$nonce = $this->request_text( 'pib_nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'pib_backorders_export' ) ) {
			$this->fail( 'pib_invalid_export_nonce' );
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			$this->fail( 'pib_missing_identity' );
		}

		$filters = $this->parse_filters( $settings );
		$records = PIB_Backorders_Source::fetch_records( $identity, $filters, $settings );
		if ( is_wp_error( $records ) ) {
			do_action( 'pib_backorders_error', $records->get_error_code(), $records->get_error_message(), $identity, $filters );
			$this->fail( 'pib_backorders_fetch_failed' );
		}

		do_action( 'pib_backorders_export', get_current_user_id(), $identity, count( $records ), $filters );

		nocache_headers();
		$filename = 'backorders-' . gmdate( 'Ymd-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$handle = fopen( 'php://output', 'w' );
		if ( false === $handle ) {
			exit;
		}

		fputcsv(
			$handle,
			array(
				'order_number',
				'sku',
				'product_name',
				'quantity_backordered',
				'status',
				'backorder_date',
				'expected_date',
				'reference',
			)
		);

		foreach ( $records as $record ) {
			fputcsv(
				$handle,
				array(
					(string) $record['order_number'],
					(string) $record['sku'],
					(string) $record['product_name'],
					(string) $record['quantity_backordered'],
					(string) $record['status'],
					(string) $record['backorder_date'],
					(string) $record['expected_date'],
					(string) $record['reference'],
				)
			);
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Parse accepted dashboard/export filters.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_filters( $settings ) {
		$status    = $this->request_text( 'status' );
		$limit_raw = $this->request_text( 'limit' );
		$limit     = '' !== $limit_raw ? absint( $limit_raw ) : absint( $settings['backorders_default_limit'] );

		$limit = max( 1, min( 5000, $limit ) );

		return array(
			'status' => $status,
			'limit'  => $limit,
		);
	}

	/**
	 * Safely read scalar request value as text.
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

	/**
	 * Redirect to sign-in when export is requested unauthenticated.
	 *
	 * @return void
	 */
	private function redirect_to_signin() {
		$signin = PIB_Signin_Link::instance( $this->plugin );
		if ( $signin instanceof PIB_Signin_Link ) {
			$url = $signin->build_signin_url( '/dashboard/backorders' );
			if ( '' !== $url ) {
				wp_safe_redirect( $url );
				exit;
			}
		}

		$this->fail( 'pib_signin_unavailable' );
	}

	/**
	 * Fail backorders operation with safe redirect.
	 *
	 * @param string $error_code Error code.
	 *
	 * @return void
	 */
	private function fail( $error_code ) {
		$url = PIB_Destination::to_absolute_url( '/dashboard/backorders' );
		$url = add_query_arg(
			array(
				'pib_auth_error' => (string) $error_code,
			),
			$url
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Format possible date values for UI.
	 *
	 * @param string $value Date value.
	 *
	 * @return string
	 */
	private function format_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( 'Y-m-d', $timestamp );
	}
}
