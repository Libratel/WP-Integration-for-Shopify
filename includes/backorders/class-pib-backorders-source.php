<?php
/**
 * Backorders data-source adapter.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and normalizes backorders from external API or custom hooks.
 */
final class PIB_Backorders_Source {
	/**
	 * Fetch normalized backorder records for current identity.
	 *
	 * @param array<string, mixed> $identity Identity context.
	 * @param array<string, mixed> $filters  Request filters.
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function fetch_records( $identity, $filters, $settings ) {
		/**
		 * Optional short-circuit for custom adapters.
		 *
		 * Return array to provide records directly, WP_Error on failure, or null to continue default API flow.
		 *
		 * @param array<int, array<string, mixed>>|WP_Error|null $records  Pre-fetched records.
		 * @param array<string, mixed>                            $identity Identity context.
		 * @param array<string, mixed>                            $filters  Filters.
		 * @param array<string, mixed>                            $settings Settings.
		 */
		$preloaded = apply_filters( 'pib_backorders_source_records', null, $identity, $filters, $settings );
		if ( is_wp_error( $preloaded ) ) {
			return $preloaded;
		}
		if ( is_array( $preloaded ) ) {
			return self::normalize_records( $preloaded, $identity, $filters );
		}

		$endpoint = trim( (string) $settings['backorders_api_endpoint'] );
		if ( '' === $endpoint ) {
			return new WP_Error( 'pib_backorders_endpoint_missing', 'Backorders API endpoint is not configured.' );
		}

		$query = array(
			'customer_id' => isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '',
			'company_id'  => isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '',
			'limit'       => isset( $filters['limit'] ) ? absint( $filters['limit'] ) : absint( $settings['backorders_default_limit'] ),
		);

		if ( isset( $filters['status'] ) && '' !== (string) $filters['status'] ) {
			$query['status'] = (string) $filters['status'];
		}

		$url = add_query_arg( $query, $endpoint );

		$timeout = max( 3, min( 60, absint( $settings['backorders_api_timeout'] ) ) );
		$args    = array(
			'timeout' => $timeout,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		$token = trim( (string) $settings['backorders_api_token'] );
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		/**
		 * Filter remote request args for backorders API integration.
		 *
		 * @param array<string, mixed> $args     Request args.
		 * @param string               $url      Final request URL.
		 * @param array<string, mixed> $identity Identity context.
		 * @param array<string, mixed> $filters  Filters.
		 */
		$args = apply_filters( 'pib_backorders_request_args', $args, $url, $identity, $filters );

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pib_backorders_request_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'pib_backorders_bad_response', 'Backorders API returned an unexpected response code.' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pib_backorders_invalid_json', 'Backorders API response was not valid JSON.' );
		}

		$records = self::extract_record_array( $data );

		return self::normalize_records( $records, $identity, $filters );
	}

	/**
	 * Extract record array from common API response shapes.
	 *
	 * @param array<string, mixed>|array<int, mixed> $payload API payload.
	 *
	 * @return array<int, mixed>
	 */
	private static function extract_record_array( $payload ) {
		if ( self::is_list_array( $payload ) ) {
			return $payload;
		}

		if ( isset( $payload['records'] ) && is_array( $payload['records'] ) ) {
			return $payload['records'];
		}

		if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
			return $payload['items'];
		}

		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			if ( self::is_list_array( $payload['data'] ) ) {
				return $payload['data'];
			}
			if ( isset( $payload['data']['records'] ) && is_array( $payload['data']['records'] ) ) {
				return $payload['data']['records'];
			}
			if ( isset( $payload['data']['items'] ) && is_array( $payload['data']['items'] ) ) {
				return $payload['data']['items'];
			}
		}

		return array();
	}

	/**
	 * Normalize and filter records for display/export.
	 *
	 * @param array<int, mixed>    $records  Raw records.
	 * @param array<string, mixed> $identity Identity context.
	 * @param array<string, mixed> $filters  Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_records( $records, $identity, $filters ) {
		$normalized = array();
		$customer   = isset( $identity['shopify_customer_id'] ) ? (string) $identity['shopify_customer_id'] : '';
		$company    = isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '';
		$status     = isset( $filters['status'] ) ? strtolower( trim( (string) $filters['status'] ) ) : '';

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$item_customer = self::pick( $record, array( 'shopify_customer_id', 'customer_id', 'customer' ) );
			$item_company  = self::pick( $record, array( 'company_id', 'account_id', 'company' ) );

			// If upstream includes scope fields, enforce strict match.
			if ( '' !== $customer && '' !== $item_customer && $customer !== $item_customer ) {
				continue;
			}
			if ( '' !== $company && '' !== $item_company && $company !== $item_company ) {
				continue;
			}

			$item_status = self::pick( $record, array( 'status', 'backorder_status' ) );
			if ( '' !== $status && '' !== $item_status && strtolower( $item_status ) !== $status ) {
				continue;
			}

			$normalized[] = array(
				'order_number'        => self::pick( $record, array( 'order_number', 'order_no', 'order', 'sales_order', 'order_id' ) ),
				'sku'                 => self::pick( $record, array( 'sku', 'item_sku', 'product_sku' ) ),
				'product_name'        => self::pick( $record, array( 'product_name', 'name', 'title', 'product' ) ),
				'quantity_backordered'=> self::pick_int( $record, array( 'qty_backordered', 'backordered_qty', 'quantity', 'qty' ) ),
				'status'              => $item_status,
				'backorder_date'      => self::pick( $record, array( 'backorder_date', 'created_at', 'date' ) ),
				'expected_date'       => self::pick( $record, array( 'expected_date', 'eta', 'due_date' ) ),
				'reference'           => self::pick( $record, array( 'reference', 'po_number', 'po', 'external_reference' ) ),
				'shopify_customer_id' => $item_customer,
				'company_id'          => $item_company,
				'raw'                 => $record,
			);
		}

		return $normalized;
	}

	/**
	 * Pick first available scalar value from record keys.
	 *
	 * @param array<string, mixed> $record Record payload.
	 * @param array<int, string>   $keys   Candidate keys.
	 *
	 * @return string
	 */
	private static function pick( $record, $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $record[ $key ] ) ) {
				continue;
			}

			$value = $record[ $key ];
			if ( is_scalar( $value ) ) {
				return trim( sanitize_text_field( (string) $value ) );
			}
		}

		return '';
	}

	/**
	 * Pick integer value from record keys.
	 *
	 * @param array<string, mixed> $record Record payload.
	 * @param array<int, string>   $keys   Candidate keys.
	 *
	 * @return int
	 */
	private static function pick_int( $record, $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $record[ $key ] ) ) {
				continue;
			}

			$value = $record[ $key ];
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * PHP 7.4-compatible list array detection.
	 *
	 * @param array<int|string, mixed> $array Candidate array.
	 *
	 * @return bool
	 */
	private static function is_list_array( $array ) {
		if ( array() === $array ) {
			return true;
		}

		$index = 0;
		foreach ( array_keys( $array ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}
			$index++;
		}

		return true;
	}
}

