<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Spire_Payment_Terms_Sync
 *
 * One-shot sync:
 *  - Iterate WP users with meta 'spire_customer_no'
 *  - Fetch customer from Spire by customerNo (string; leading zeros preserved)
 *  - Write payment terms into user meta
 *
 * Returns a structured summary array usable in admin notices or cron logs.
 */
class Spire_Payment_Terms_Sync {

	/** Option used to surface a one-time debug breadcrumb into the admin notice. */
	private const DEBUG_OPTION = 'spire_terms_last_debug';

	/**
	 * Entry point (admin UI / cron).
	 * @param array $args ['force' => bool, 'width' => int|null] width is optional padding width for numeric customerNo (e.g., 7)
	 * @return array [updated => int, missing => int, errors => int, message? => string]
	 * @throws Exception when credentials or preconditions are missing
	 */
	public static function run( $args = [] ) {
		$force = ! empty( $args['force'] );
		$width = isset( $args['width'] ) && is_numeric( $args['width'] ) ? (int) $args['width'] : null;

		$ctx = self::build_context(); // throws on missing creds

		$user_ids = self::get_user_ids_with_customer_no();
		if ( empty( $user_ids ) ) {
			throw new Exception( 'No users found with a Spire Customer No.' );
		}

		$base_no_slash   = self::customers_base( false );
		$base_with_slash = self::customers_base( true );

		$updated = 0;
		$missing = 0;
		$errors  = 0;

		// Clear any leftover breadcrumb before we start
		delete_option( self::DEBUG_OPTION );

		foreach ( $user_ids as $user_id ) {
			$raw_no = (string) get_user_meta( $user_id, 'spire_customer_no', true );
			if ( $raw_no === '' ) {
				continue;
			}

			$customer_no = self::normalize_customer_no( $raw_no, $width );

			// Fetch single customer (robust to trailing slash differences)
			$fetch = self::fetch_customer_by_customer_no( $customer_no, $ctx['username'], $ctx['password'], $base_no_slash, $base_with_slash );
			if ( ! $fetch['ok'] ) {
				// Distinguish “API reachable but no record” vs transport/API failure
				if ( $fetch['saw200'] ) {
					$missing++;
				} else {
					$errors++;
				}
				// One-time breadcrumb
				if ( ! get_option( self::DEBUG_OPTION ) && $fetch['debug'] !== '' ) {
					update_option( self::DEBUG_OPTION, $fetch['debug'], false );
				}
				continue;
			}

			$rec = $fetch['record'];
			$terms = self::extract_payment_terms( $rec );
			if ( $terms['desc'] === '' && $terms['code'] === '' ) {
				$missing++;
				continue;
			}

			// Apply updates (idempotent; respects "force")
			$did_update = self::update_user_terms( $user_id, $terms['desc'], $terms['code'], $force );
			if ( $did_update ) {
				$updated++;
			}
		}

		// Report any breadcrumb once
		$debug_msg = get_option( self::DEBUG_OPTION, '' );
		if ( $debug_msg !== '' ) {
			delete_option( self::DEBUG_OPTION );
			return [
				'updated' => $updated,
				'missing' => $missing,
				'errors'  => $errors,
				'message' => $debug_msg,
			];
		}

		// Soft failure summary (no fatals in UI)
		if ( $updated === 0 && $errors > 0 ) {
			return [
				'updated' => 0,
				'missing' => $missing,
				'errors'  => $errors,
				'message' => 'All lookups failed.',
			];
		}

		return [
			'updated' => $updated,
			'missing' => $missing,
			'errors'  => $errors,
		];
	}

	/**
	 * Sync payment terms for a single user (self-heal path).
	 *
	 * @param int   $user_id
	 * @param array $args ['force' => bool, 'width' => int|null, 'customer_no' => string]
	 * @return array{
	 *   ok:bool,
	 *   updated:bool,
	 *   status:string,
	 *   message:string
	 * }
	 */
	public static function sync_user( int $user_id, array $args = [] ): array {
		if ( $user_id <= 0 ) {
			return [
				'ok'      => false,
				'updated' => false,
				'status'  => 'invalid_user',
				'message' => 'Invalid user ID.',
			];
		}

		$force = ! empty( $args['force'] );
		$width = isset( $args['width'] ) && is_numeric( $args['width'] ) ? (int) $args['width'] : null;
		$raw_no = isset( $args['customer_no'] ) ? (string) $args['customer_no'] : (string) get_user_meta( $user_id, 'spire_customer_no', true );
		$customer_no = self::normalize_customer_no( $raw_no, $width );

		if ( $customer_no === '' ) {
			delete_user_meta( $user_id, 'spire_payment_term' );
			delete_user_meta( $user_id, 'spire_payment_term_code' );
			delete_user_meta( $user_id, 'spire_terms_last_synced' );
			return [
				'ok'      => true,
				'updated' => true,
				'status'  => 'cleared',
				'message' => 'Customer number is empty; cleared payment terms.',
			];
		}

		try {
			$ctx = self::build_context();
		} catch ( Exception $e ) {
			return [
				'ok'      => false,
				'updated' => false,
				'status'  => 'missing_context',
				'message' => $e->getMessage(),
			];
		}

		$base_no_slash   = self::customers_base( false );
		$base_with_slash = self::customers_base( true );

		$fetch = self::fetch_customer_by_customer_no(
			$customer_no,
			$ctx['username'],
			$ctx['password'],
			$base_no_slash,
			$base_with_slash
		);

		if ( ! $fetch['ok'] ) {
			return [
				'ok'      => false,
				'updated' => false,
				'status'  => $fetch['saw200'] ? 'not_found' : 'lookup_error',
				'message' => $fetch['debug'] !== '' ? $fetch['debug'] : 'Failed to fetch customer record.',
			];
		}

		$terms = self::extract_payment_terms( (array) $fetch['record'] );
		if ( $terms['desc'] === '' && $terms['code'] === '' ) {
			return [
				'ok'      => false,
				'updated' => false,
				'status'  => 'missing_terms',
				'message' => 'Customer record has no payment terms.',
			];
		}

		$did_update = self::update_user_terms( $user_id, $terms['desc'], $terms['code'], $force );
		return [
			'ok'      => true,
			'updated' => (bool) $did_update,
			'status'  => $did_update ? 'updated' : 'unchanged',
			'message' => $did_update ? 'Payment terms updated.' : 'Payment terms already up to date.',
		];
	}

	/* ---------- Context & Inputs ---------- */

	/**
	 * Build API context from settings; throws on missing creds.
	 * @return array{username:string,password:string,base_url:string}
	 * @throws Exception
	 */
	private static function build_context(): array {
		$username = trim( Spire_Settings::get_option( 'username', '' ) );
		$password = trim( Spire_Settings::get_option( 'password', '' ) );
		$base_url = trim( Spire_Settings::get_option( 'base_url', '' ) );

		if ( ! $username || ! $password || ! $base_url ) {
			throw new Exception( 'Spire credentials missing.' );
		}
		return compact( 'username', 'password', 'base_url' );
	}

	/**
	 * Return user IDs that have a non-empty spire_customer_no.
	 * @return int[]
	 */
	private static function get_user_ids_with_customer_no(): array {
		$q = new WP_User_Query( [
			'meta_query' => [
				[ 'key' => 'spire_customer_no', 'value' => '', 'compare' => '!=' ],
			],
			'fields' => [ 'ID' ],
		] );
		return array_map( static function( $u ) { return (int) $u->ID; }, (array) $q->get_results() );
	}

	/**
	 * Customers collection base URL, with or without trailing slash.
	 * @param bool $with_slash
	 * @return string
	 */
	private static function customers_base( bool $with_slash ): string {
		$base = Spire_Settings::endpoint( Spire_Settings::company_path( 'customers' ) );
		if ( $with_slash ) {
			return function_exists( 'trailingslashit' ) ? trailingslashit( $base ) : rtrim( $base, '/' ) . '/';
		}
		return function_exists( 'untrailingslashit' ) ? untrailingslashit( $base ) : rtrim( $base, '/' );
	}

	/**
	 * Normalize customer number while preserving semantics:
	 *  - Trim whitespace/control chars
	 *  - If purely digits and width is provided, left-pad to that width (preserves leading zeros)
	 *  - Otherwise return as-is (string IDs remain untouched)
	 */
	private static function normalize_customer_no( $no, ?int $width ): string {
		$no = trim( preg_replace( '/[\p{C}]+/u', '', (string) $no ) );
		if ( $no === '' ) {
			return $no;
		}
		if ( $width && ctype_digit( $no ) ) {
			$no = str_pad( ltrim( $no, '0' ), max( 1, $width ), '0', STR_PAD_LEFT );
		}
		return $no;
	}

	/* ---------- API ---------- */

	/**
	 * Fetch a single customer record by customerNo; tries with and without trailing slash.
	 * Uses Spire_API_Client::url_with_json_filter to avoid double-encoding bugs.
	 *
	 * @return array{ok:bool, saw200:bool, record?:array, debug:string}
	 */
	private static function fetch_customer_by_customer_no(
		string $customer_no,
		string $username,
		string $password,
		string $base_no_slash,
		string $base_with_slash
	): array {
		$urls = [
			Spire_API_Client::url_with_json_filter($base_no_slash,  [ 'customerNo' => $customer_no ]),
			Spire_API_Client::url_with_json_filter($base_with_slash, [ 'customerNo' => $customer_no ]),
		];

		$saw200 = false;
		$last_url = '';

		foreach ($urls as $url) {
			$last_url = $url; // Track the last attempted URL

			$resp   = Spire_API_Client::get($url, $username, $password);
			$parsed = Spire_API_Client::parse_json_response($resp);

			if (is_wp_error($parsed)) {
				continue; // Skip to next URL if request failed
			}

			$code = (int) ($parsed['code'] ?? 0);
			if ($code !== 200) {
				continue; // Skip to next if non-200 status
			}

			$saw200 = true;

			$data    = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
			$records = (!empty($data['records']) && is_array($data['records'])) ? $data['records'] : [];
			if (!empty($records)) {
				return [
					'ok'     => true,
					'saw200' => true,
					'record' => $records[0],
					'debug'  => '',
				];
			}
		}

		// Return failure with the last attempted URL included
		return [
			'ok'     => false,
			'saw200' => $saw200,
			'debug'  => 'No customer matched at URL: ' . $last_url,
		];
	}

	/* ---------- Domain parsing ---------- */

	/**
	 * Extract payment terms code/description from a customer record.
	 * @param array $rec
	 * @return array{code:string, desc:string}
	 */
	private static function extract_payment_terms( array $rec ): array {
		$code = '';
		$desc = '';

		if ( isset( $rec['paymentTerms'] ) ) {
			if ( is_array( $rec['paymentTerms'] ) ) {
				$code = (string) ( $rec['paymentTerms']['code'] ?? '' );
				$desc = (string) ( $rec['paymentTerms']['description'] ?? '' );
			} elseif ( is_string( $rec['paymentTerms'] ) ) {
				$code = (string) $rec['paymentTerms'];
			}
		}

		if ( $desc === '' && isset( $rec['paymentTermsDescription'] ) ) {
			$desc = (string) $rec['paymentTermsDescription'];
		}
		if ( $desc === '' && $code !== '' ) {
			$desc = $code; // safe fallback label
		}

		return [
			'code' => sanitize_text_field( $code ),
			'desc' => sanitize_text_field( $desc ),
		];
	}

	/* ---------- Persistence ---------- */

	/**
	 * Update user meta if changed (or force), return true if we wrote anything.
	 */
	private static function update_user_terms( int $user_id, string $term_desc, string $term_code, bool $force ): bool {
		if ( $force ) {
			delete_user_meta( $user_id, 'spire_payment_term' );
			delete_user_meta( $user_id, 'spire_payment_term_code' );
		}

		$cur_desc = (string) get_user_meta( $user_id, 'spire_payment_term', true );
		$cur_code = (string) get_user_meta( $user_id, 'spire_payment_term_code', true );

		if ( ! $force && $cur_desc === $term_desc && $cur_code === $term_code ) {
			return false;
		}

		update_user_meta( $user_id, 'spire_payment_term',      $term_desc );
		update_user_meta( $user_id, 'spire_payment_term_code', $term_code );
		update_user_meta( $user_id, 'spire_terms_last_synced', gmdate( 'c' ) );

		if ( function_exists( 'clean_user_cache' ) ) {
			clean_user_cache( $user_id );
		}
		wp_cache_delete( $user_id, 'user_meta' );

		return true;
	}
}
