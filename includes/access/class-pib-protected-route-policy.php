<?php
/**
 * Protected route policy helpers.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes protected-route matching and diagnostics helpers.
 */
final class PIB_Protected_Route_Policy {
	/**
	 * Resolve effective route policy rules.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function rules( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$protected_prefixes = isset( $settings['protected_route_prefixes'] ) ? $settings['protected_route_prefixes'] : array();
		$unprotected_prefixes = isset( $settings['unprotected_route_prefixes'] ) ? $settings['unprotected_route_prefixes'] : array();

		$protected_prefixes = PIB_Destination::sanitize_prefixes( $protected_prefixes, true );
		$unprotected_prefixes = PIB_Destination::sanitize_prefixes( $unprotected_prefixes, false );

		/**
		 * Filter effective protected route prefixes.
		 *
		 * @param array<int, string>      $protected_prefixes Effective protected prefixes.
		 * @param array<string, mixed>    $settings           Normalized settings.
		 */
		$protected_prefixes = apply_filters( 'pib_protected_route_prefixes', $protected_prefixes, $settings );
		$protected_prefixes = PIB_Destination::sanitize_prefixes( $protected_prefixes, true );

		/**
		 * Filter effective unprotected route prefixes.
		 *
		 * Unprotected prefixes are evaluated first and can explicitly bypass protection.
		 *
		 * @param array<int, string>      $unprotected_prefixes Effective unprotected prefixes.
		 * @param array<string, mixed>    $settings             Normalized settings.
		 */
		$unprotected_prefixes = apply_filters( 'pib_unprotected_route_prefixes', $unprotected_prefixes, $settings );
		$unprotected_prefixes = PIB_Destination::sanitize_prefixes( $unprotected_prefixes, false );

		return array(
			'protected_prefixes'   => $protected_prefixes,
			'unprotected_prefixes' => $unprotected_prefixes,
		);
	}

	/**
	 * Evaluate route protection for a destination.
	 *
	 * @param string               $destination Requested internal destination.
	 * @param array<string, mixed> $settings    Normalized settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function evaluate_destination( $destination, $settings ) {
		$settings    = is_array( $settings ) ? $settings : array();
		$destination = PIB_Destination::sanitize_destination( (string) $destination, '/' );
		$path        = (string) wp_parse_url( $destination, PHP_URL_PATH );
		$rules       = self::rules( $settings );

		$matched_unprotected = PIB_Destination::first_matching_prefix( $destination, $rules['unprotected_prefixes'], false );
		$matched_protected   = PIB_Destination::first_matching_prefix( $destination, $rules['protected_prefixes'], true );
		$is_protected        = '' !== $matched_protected && '' === $matched_unprotected;

		$result = array(
			'destination'            => $destination,
			'path'                   => $path,
			'is_protected'           => $is_protected,
			'matched_protected'      => $matched_protected,
			'matched_unprotected'    => $matched_unprotected,
			'protected_prefixes'     => $rules['protected_prefixes'],
			'unprotected_prefixes'   => $rules['unprotected_prefixes'],
		);

		/**
		 * Filter full protected-route evaluation payload.
		 *
		 * @param array<string, mixed> $result   Evaluation result.
		 * @param array<string, mixed> $settings Normalized settings.
		 */
		$result = apply_filters( 'pib_protected_route_evaluation', $result, $settings );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		$override = isset( $result['is_protected'] ) ? (bool) $result['is_protected'] : $is_protected;

		/**
		 * Filter final protected-route decision.
		 *
		 * @param bool                $override    Final protected decision.
		 * @param string              $destination Normalized destination.
		 * @param array<string,mixed> $result      Evaluation payload.
		 * @param array<string,mixed> $settings    Normalized settings.
		 */
		$result['is_protected'] = (bool) apply_filters( 'pib_is_destination_protected', $override, $destination, $result, $settings );
		$result['destination']  = isset( $result['destination'] ) ? PIB_Destination::sanitize_destination( (string) $result['destination'], '/' ) : $destination;
		$result['path']         = isset( $result['path'] ) ? (string) $result['path'] : $path;
		$result['matched_protected'] = isset( $result['matched_protected'] ) ? sanitize_text_field( (string) $result['matched_protected'] ) : $matched_protected;
		$result['matched_unprotected'] = isset( $result['matched_unprotected'] ) ? sanitize_text_field( (string) $result['matched_unprotected'] ) : $matched_unprotected;
		$result['protected_prefixes'] = isset( $result['protected_prefixes'] ) ? PIB_Destination::sanitize_prefixes( $result['protected_prefixes'], true ) : $rules['protected_prefixes'];
		$result['unprotected_prefixes'] = isset( $result['unprotected_prefixes'] ) ? PIB_Destination::sanitize_prefixes( $result['unprotected_prefixes'], false ) : $rules['unprotected_prefixes'];

		return $result;
	}

	/**
	 * Build default sample destinations for admin route diagnostics.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 *
	 * @return array<int, string>
	 */
	public static function sample_destinations( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$rules    = self::rules( $settings );
		$samples  = array();

		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '';
		$failure_dest = isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '';

		if ( '' !== $default_dest ) {
			$samples[] = $default_dest;
		}
		if ( '' !== $failure_dest ) {
			$samples[] = $failure_dest;
		}

		$samples[] = '/portal-login';
		$samples[] = '/wp-login.php';
		$samples[] = '/';

		foreach ( $rules['protected_prefixes'] as $prefix ) {
			$samples[] = $prefix;
		}
		foreach ( $rules['unprotected_prefixes'] as $prefix ) {
			$samples[] = $prefix;
		}

		/**
		 * Filter admin route diagnostics sample destinations.
		 *
		 * @param array<int, string>      $samples  Candidate destinations.
		 * @param array<string, mixed>    $settings Normalized settings.
		 */
		$samples = apply_filters( 'pib_protected_route_samples', $samples, $settings );

		if ( ! is_array( $samples ) ) {
			$samples = array();
		}

		$normalized = array();
		foreach ( $samples as $sample ) {
			if ( ! is_scalar( $sample ) ) {
				continue;
			}
			$normalized[] = PIB_Destination::sanitize_destination( (string) $sample, '/' );
		}

		return array_values( array_unique( $normalized ) );
	}
}
