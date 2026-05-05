<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core: fetch Spire, merge sheet, build CSV, e-mail, cron.
 */
class BR_Core {

	const CRON_HOOK        = 'br_generate_reports';
	const GLOBAL_OPTION    = 'br_creds';
	const RECORD_POST_TYPE = 'br_report';
	const REPORT_META_KEY  = 'br_report_files';

	private $global = [];
	private $mapping_cache = null;

	public function __construct() {
		$this->global = $this->load_global_options();
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	private function load_global_options(): array {
		$opt = get_option( self::GLOBAL_OPTION, [] );
		if ( ! is_array( $opt ) ) {
			return [];
		}
		if ( ! empty( $opt['base_url'] ) ) {
			$opt['base_url'] = untrailingslashit( trim( $opt['base_url'] ) );
		}
		return $opt;
	}

	/* ---------- Activation ---------- */
	public static function activation_bootstrap() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Re-schedule according to settings */
	public static function reschedule( $opt = null ) {
		self::clear_schedule();
		wp_schedule_event( time() + MINUTE_IN_SECONDS * 5, 'hourly', self::CRON_HOOK );
	}

	/* ---------- Connection test ---------- */
	public static function test_connection() {
		$opt = get_option( self::GLOBAL_OPTION, [] );
		if ( empty( $opt['spire_user'] ) || empty( $opt['spire_pass'] ) || empty( $opt['base_url'] ) ) {
			return false;
		}

		$endpoint = untrailingslashit( $opt['base_url'] ) . '/companies/LIBRATEL';
		$auth     = base64_encode( $opt['spire_user'] . ':' . $opt['spire_pass'] );

		$resp = wp_remote_get(
			$endpoint,
			[
				'timeout'   => 60,
				'headers'   => [
					'Authorization' => 'Basic ' . $auth,
					'Accept'        => 'application/json',
				],
				'sslverify' => false,
			]
		);

		return ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
	}

	/* ---------- Main runner ---------- */
	public function run() {
		$records = $this->get_records();
		if ( ! $records ) {
			return;
		}

		$cred_error = $this->validate_credentials();
		if ( $cred_error instanceof WP_Error ) {
			error_log( 'BR_Core: Spire credentials missing, aborting scheduled run.' );
			return;
		}

		$timestamp = current_time( 'timestamp' );
		$mapping   = $this->get_eta_mapping();

		foreach ( $records as $record_id ) {
			$settings = $this->get_record_settings( $record_id );
			if ( is_wp_error( $settings ) ) {
				error_log( sprintf( 'BR_Core: %s (record #%d)', $settings->get_error_message(), $record_id ) );
				continue;
			}

			if ( ! $this->should_run_record( $settings, $timestamp ) ) {
				continue;
			}

			$this->process_record( $record_id, $settings, $mapping );
		}
	}

	/**
	 * Generate reports immediately.
	 *
	 * @param int $record_id Optional record ID.
	 * @return array|WP_Error
	 */
	public function run_now( int $record_id = 0 ) {
		$cred_error = $this->validate_credentials();
		if ( $cred_error instanceof WP_Error ) {
			return $cred_error;
		}

		$mapping = $this->get_eta_mapping();

		if ( $record_id ) {
			$settings = $this->get_record_settings( $record_id );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}
			return $this->process_record( $record_id, $settings, $mapping );
		}

		$results = [];
		foreach ( $this->get_records() as $record_id ) {
			$settings = $this->get_record_settings( $record_id );
			if ( is_wp_error( $settings ) ) {
				$results[ $record_id ] = $settings;
				continue;
			}
			$results[ $record_id ] = $this->process_record( $record_id, $settings, $mapping );
		}

		return $results;
	}

	private function validate_credentials(): ?WP_Error {
		if ( empty( $this->global['spire_user'] ) || empty( $this->global['spire_pass'] ) ) {
			return new WP_Error( 'br_creds_missing', 'Spire credentials are not configured.' );
		}
		if ( empty( $this->global['base_url'] ) ) {
			return new WP_Error( 'br_base_missing', 'Spire Base URL is not configured.' );
		}
		return null;
	}

	private function get_records(): array {
		return get_posts(
			[
				'post_type'        => self::RECORD_POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private function get_record_settings( int $record_id ) {
		$customers_raw = get_post_meta( $record_id, 'customers', true );
		$customers     = array_filter( array_map( 'trim', explode( ',', (string) $customers_raw ) ) );

		if ( ! $customers ) {
			return new WP_Error( 'br_no_customers', 'No customer numbers configured for this record.' );
		}

		$emails_raw = get_post_meta( $record_id, 'emails', true );
		$emails     = array_filter(
			array_map(
				'sanitize_email',
				array_map( 'trim', explode( ',', (string) $emails_raw ) )
			)
		);

		$days = get_post_meta( $record_id, 'days', true );
		$days = is_array( $days ) ? array_map( 'intval', $days ) : [];

		$hour = (int) get_post_meta( $record_id, 'hour', true );
		if ( $hour < 0 || $hour > 23 ) {
			$hour = 3;
		}

		$use_po_number = (bool) get_post_meta( $record_id, 'use_po_number', true );

		return [
			'customers' => $customers,
			'emails'    => $emails,
			'days'      => $days,
			'hour'      => $hour,
			'use_po'    => $use_po_number,
		];
	}

	private function should_run_record( array $settings, int $timestamp ): bool {
		if ( empty( $settings['days'] ) ) {
			return false;
		}

		$current_day  = (int) wp_date( 'w', $timestamp );
		$current_hour = (int) wp_date( 'G', $timestamp );

		return in_array( $current_day, $settings['days'], true ) && $current_hour === (int) $settings['hour'];
	}

	private function process_record( int $record_id, array $settings, array $mapping ): array {
		$generated = [];
		$no_backorders = [];

		foreach ( $settings['customers'] as $customer ) {
			$result = $this->build_csv( $record_id, $customer, $mapping, ! empty( $settings['use_po'] ) );
			if ( ! $result ) {
				continue;
			}

			if ( empty( $result['has_backorders'] ) ) {
				$no_backorders[] = $customer;
			}

			$file = $result['file'] ?? '';
			if ( ! $file ) {
				continue;
			}

			$this->log_report_file( $record_id, $file );
			$generated[] = $file;
		}

		if ( ! empty( $settings['emails'] ) && ! empty( $generated ) ) {
			$this->email_report( $generated, $settings['emails'], $no_backorders );
		}

		return $generated;
	}

	private function build_csv( int $record_id, string $customer, array $mapping, bool $use_po_number ) {
		$base_url = $this->global['base_url'] ?? '';
		$auth     = base64_encode( $this->global['spire_user'] . ':' . $this->global['spire_pass'] );

		$filter_json = wp_json_encode( [ 'order.customer.customerNo' => $customer ] );
		$api_url     = add_query_arg(
			[
				'filter' => $filter_json,
				'limit'  => 2000,
			],
			$base_url . '/companies/LIBRATEL/sales/items'
		);

		$resp = wp_remote_get(
			$api_url,
			[
				'timeout' => 45,
				'headers' => [
					'Authorization' => 'Basic ' . $auth,
					'Accept'        => 'application/json',
					'User-Agent'    => 'WP BackOrder',
				],
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $resp ) ) {
			error_log( 'BR_Core API error: ' . $resp->get_error_message() );
			return false;
		}

		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );
		$records = $decoded['records'] ?? [];

		$rows = [];
		foreach ( $records as $rec ) {
			$backorder = (float) ( $rec['backorderQty'] ?? 0 );
			if ( $backorder <= 0 ) {
				continue;
			}
			$part = trim( (string) ( $rec['partNo'] ?? '' ) );

			$row = [
				$rec['order']['orderDate'] ?? '',
				$rec['orderNo'] ?? '',
				$rec['order']['customerPO'] ?? '',
				$rec['order']['shippingAddress']['shipId'] ?? '',
				$rec['order']['shippingAddress']['name'] ?? '',
				$part,
				$rec['description'] ?? '',
				(float) ( $rec['orderQty'] ?? 0 ),
				$backorder,
			];
			if ( ! $use_po_number ) {
				$row[] = $mapping[ $part ] ?? '';
			}

			$rows[] = $row;
		}

		if ( ! $rows ) {
			return [
				'file'           => '',
				'has_backorders' => false,
			];
		}

		list( $dir ) = self::report_locations();
		if ( ! wp_mkdir_p( $dir ) ) {
			error_log( 'BR_Core: unable to create reports directory.' );
			return false;
		}

		$timestamp = wp_date( 'Ymd_His', current_time( 'timestamp' ) );
		$filename  = sprintf(
			'%s_record-%d_backorders_%s.csv',
			sanitize_key( $customer ),
			$record_id,
			$timestamp
		);
		$file_path = trailingslashit( $dir ) . $filename;

		$fp = fopen( $file_path, 'w' );
		if ( ! $fp ) {
			return false;
		}

		$header = [ 'Order Date', 'Order Number', 'customer PO', 'Ship Id', 'Ship Name', 'Part Number', 'Description', 'Order Qty', 'Backorder Qty' ];
		if ( ! $use_po_number ) {
			$header[] = 'ETA YYZ';
		}
		fputcsv( $fp, $header );

		foreach ( $rows as $row ) {
			fputcsv( $fp, $row );
		}

		fclose( $fp );

		return [
			'file'           => $file_path,
			'has_backorders' => true,
		];
	}

	private function get_eta_mapping(): array {
		if ( is_array( $this->mapping_cache ) ) {
			return $this->mapping_cache;
		}

		$sheet_id = trim( $this->global['sheet_id'] ?? '' );
		if ( ! $sheet_id ) {
			$this->mapping_cache = [];
			return [];
		}

		$gid  = trim( $this->global['sheet_gid'] ?? '0' );
		$url  = sprintf( 'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s', rawurlencode( $sheet_id ), rawurlencode( $gid ) );
		$resp = wp_remote_get(
			$url,
			[
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $resp ) ) {
			error_log( 'BR_Core sheet error: ' . $resp->get_error_message() );
			$this->mapping_cache = [];
			return [];
		}

		$csv = wp_remote_retrieve_body( $resp );
		if ( ! $csv ) {
			$this->mapping_cache = [];
			return [];
		}

		$this->mapping_cache = $this->sheet_mapping( $csv );
		return $this->mapping_cache;
	}

	private function sheet_mapping( string $csv ): array {
		$rows = array_map( 'str_getcsv', explode( "\n", trim( $csv ) ) );
		if ( empty( $rows[0] ) || count( $rows[0] ) < 7 ) {
			return [];
		}

		$map = [];
		foreach ( $rows as $row ) {
			$part = trim( $row[1] ?? '' );
			$eta  = trim( $row[6] ?? '' );
			$eta_ts = strtotime( $eta );

			if ( ! $part || false === $eta_ts ) {
				continue;
			}

			if ( ! isset( $map[ $part ] ) || $eta_ts > strtotime( $map[ $part ] ) ) {
				$map[ $part ] = $eta;
			}
		}

		return $map;
	}

	private function email_report( array $files, array $recipients, array $no_backorders = [] ): bool {
		$to = array_filter( $recipients );
		$files = array_values( array_filter( $files ) );
		if ( ! $to || ! $files ) {
			return false;
		}

		$no_backorders = array_values( array_unique( array_filter( $no_backorders ) ) );
		$subject = 'Back-Order Report ' . wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$body    = 'Please find attached the latest back-order report.';
		if ( $no_backorders ) {
			$body .= "\n\nThere is no backorder for the following customers: ";
			$body .= implode( ', ', array_map( 'sanitize_text_field', $no_backorders ) );
		}

		return (bool) wp_mail( $to, $subject, $body, [], $files );
	}

	private function log_report_file( int $record_id, string $file ): void {
		$basename = basename( $file );
		$stored   = get_post_meta( $record_id, self::REPORT_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$stored = array_values(
			array_filter(
				$stored,
				static function ( $existing ) use ( $basename ) {
					return $existing !== $basename;
				}
			)
		);

		$stored[] = $basename;

		if ( count( $stored ) > 200 ) {
			$stored = array_slice( $stored, -200 );
		}

		update_post_meta( $record_id, self::REPORT_META_KEY, $stored );
	}

	private static function report_locations(): array {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'backorder_reports';
		$url     = trailingslashit( $uploads['baseurl'] ) . 'backorder_reports';

		return [ $dir, $url ];
	}

	/* ---------- Admin helpers ---------- */
	public static function render_file_table( int $record_id ) {
		$files = get_post_meta( $record_id, self::REPORT_META_KEY, true );
		if ( ! is_array( $files ) || ! $files ) {
			echo '<p>No reports yet.</p>';
			return;
		}

		list( $dir, $url ) = self::report_locations();
		$rows              = [];
		$changed           = false;

		foreach ( array_reverse( $files ) as $basename ) {
			$path = trailingslashit( $dir ) . $basename;
			if ( ! file_exists( $path ) ) {
				$changed = true;
				continue;
			}

			$rows[] = [
				'name' => $basename,
				'path' => $path,
				'url'  => trailingslashit( $url ) . $basename,
			];
		}

		if ( $changed ) {
			$valid = array_reverse(
				array_map(
					static function ( $row ) {
						return $row['name'];
					},
					$rows
				)
			);
			update_post_meta( $record_id, self::REPORT_META_KEY, $valid );
		}

		if ( ! $rows ) {
			echo '<p>No reports yet.</p>';
			return;
		}

		$per_page    = 20;
		$page        = isset( $_REQUEST['br_reports_page'] ) ? max( 1, (int) $_REQUEST['br_reports_page'] ) : 1;
		$total_rows  = count( $rows );
		$total_pages = (int) ceil( $total_rows / $per_page );
		if ( $total_pages > 0 && $page > $total_pages ) {
			$page = $total_pages;
		}
		$offset     = ( $page - 1 ) * $per_page;
		$page_rows  = array_slice( $rows, $offset, $per_page );

		if ( $total_pages > 1 ) {
			$base_url = remove_query_arg( 'br_reports_page' );
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo '<span class="displaying-num">' . esc_html( $total_rows ) . ' items</span> ';
			if ( $page > 1 ) {
				$prev_url = add_query_arg( 'br_reports_page', $page - 1, $base_url );
				echo '<a class="button" href="' . esc_url( $prev_url ) . '">Previous</a> ';
			} else {
				echo '<span class="button disabled">Previous</span> ';
			}
			echo '<span class="paging-input">Page ' . esc_html( $page ) . ' of <span class="total-pages">' . esc_html( $total_pages ) . '</span></span> ';
			if ( $page < $total_pages ) {
				$next_url = add_query_arg( 'br_reports_page', $page + 1, $base_url );
				echo '<a class="button" href="' . esc_url( $next_url ) . '">Next</a>';
			} else {
				echo '<span class="button disabled">Next</span>';
			}
			echo '</div></div>';
		}

		echo '<table class="widefat striped"><thead><tr><th>File</th><th>Size</th><th>Created (WP Time)</th></tr></thead><tbody>';
		foreach ( $page_rows as $row ) {
			printf(
				'<tr><td><label><input type="checkbox" name="br_files[]" value="%1$s"> <a href="%2$s" target="_blank" rel="noopener">%1$s</a></label></td><td>%3$s KB</td><td>%4$s</td></tr>',
				esc_attr( $row['name'] ),
				esc_url( $row['url'] ),
				esc_html( round( filesize( $row['path'] ) / 1024, 1 ) ),
				esc_html( wp_date( 'Y-m-d H:i', filemtime( $row['path'] ) ) )
			);
		}
		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			$base_url = remove_query_arg( 'br_reports_page' );
			echo '<div class="tablenav"><div class="tablenav-pages">';
			if ( $page > 1 ) {
				$prev_url = add_query_arg( 'br_reports_page', $page - 1, $base_url );
				echo '<a class="button" href="' . esc_url( $prev_url ) . '">Previous</a> ';
			} else {
				echo '<span class="button disabled">Previous</span> ';
			}
			echo '<span class="paging-input">Page ' . esc_html( $page ) . ' of <span class="total-pages">' . esc_html( $total_pages ) . '</span></span> ';
			if ( $page < $total_pages ) {
				$next_url = add_query_arg( 'br_reports_page', $page + 1, $base_url );
				echo '<a class="button" href="' . esc_url( $next_url ) . '">Next</a>';
			} else {
				echo '<span class="button disabled">Next</span>';
			}
			echo '</div></div>';
		}
	}

	public static function delete_report_file( int $record_id, string $filename ): bool {
		$filename = sanitize_file_name( wp_basename( $filename ) );
		if ( ! $filename ) {
			return false;
		}

		list( $dir ) = self::report_locations();
		$path        = trailingslashit( $dir ) . $filename;

		$deleted = true;
		if ( file_exists( $path ) ) {
			$deleted = wp_delete_file( $path );
			if ( ! $deleted ) {
				$deleted = @unlink( $path );
			}
		}

		$stored = get_post_meta( $record_id, self::REPORT_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$new = array_values(
			array_filter(
				$stored,
				static function ( $existing ) use ( $filename ) {
					return $existing !== $filename;
				}
			)
		);

		if ( $new !== $stored ) {
			update_post_meta( $record_id, self::REPORT_META_KEY, $new );
		}

		return (bool) $deleted || $new !== $stored;
	}
}
