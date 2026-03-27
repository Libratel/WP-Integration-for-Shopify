<?php
/**
 * Security utilities and auth hardening.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared security helpers.
 */
final class PIB_Security {
	/**
	 * Build auth-failure transient key for client IP.
	 *
	 * @param string $ip Client IP.
	 *
	 * @return string
	 */
	private static function failure_key( $ip ) {
		return 'pib_fail_' . md5( (string) $ip );
	}

	/**
	 * Determine client IP for logging/rate-limiting.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw = (string) wp_unslash( $_SERVER[ $key ] );
			if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
				$parts = explode( ',', $raw );
				$raw   = trim( (string) $parts[0] );
			}

			$ip = filter_var( trim( $raw ), FILTER_VALIDATE_IP );
			if ( false !== $ip ) {
				return (string) $ip;
			}
		}

		return '';
	}

	/**
	 * Check if current IP has exceeded auth failure threshold.
	 *
	 * @param int $limit Maximum failures in window.
	 *
	 * @return bool
	 */
	public static function is_auth_rate_limited( $limit ) {
		$limit = max( 1, absint( $limit ) );
		$ip    = self::client_ip();
		if ( '' === $ip ) {
			return false;
		}

		$key   = self::failure_key( $ip );
		$count = absint( get_transient( $key ) );

		return $count >= $limit;
	}

	/**
	 * Increment auth failure counter for current IP.
	 *
	 * @param int $window_minutes Counter retention window.
	 *
	 * @return void
	 */
	public static function register_auth_failure( $window_minutes ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}

		$window_minutes = max( 1, min( 1440, absint( $window_minutes ) ) );
		$key            = self::failure_key( $ip );
		$count          = absint( get_transient( $key ) );
		$count++;

		set_transient( $key, $count, $window_minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Clear auth failure counter for current IP.
	 *
	 * @return void
	 */
	public static function clear_auth_failures() {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}

		delete_transient( self::failure_key( $ip ) );
	}
}

