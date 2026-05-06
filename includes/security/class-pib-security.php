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
	 * Cookie name used to bind handoff state to browser context.
	 */
	const HANDOFF_STATE_COOKIE = 'pib_handoff_state';

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

	/**
	 * Issue a one-time handoff state and bind it to the browser via cookie.
	 *
	 * @param int $ttl_seconds State lifetime.
	 *
	 * @return string
	 */
	public static function issue_handoff_state( $ttl_seconds = 300 ) {
		$ttl_seconds = max( 60, min( 1800, absint( $ttl_seconds ) ) );

		$existing_state = self::handoff_state_cookie_value();
		if ( '' !== $existing_state && is_array( get_transient( self::handoff_state_key( $existing_state ) ) ) ) {
			return $existing_state;
		}
		if ( headers_sent() ) {
			return '';
		}

		$state       = wp_generate_password( 48, false, false );
		if ( '' === $state ) {
			return '';
		}

		$ua = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$payload = array(
			'issued_at' => time(),
			'ua_hash'   => '' !== $ua ? md5( $ua ) : '',
		);

		set_transient( self::handoff_state_key( $state ), $payload, $ttl_seconds );
		self::set_handoff_state_cookie( $state, $ttl_seconds );

		return $state;
	}

	/**
	 * Validate and consume a previously issued handoff state.
	 *
	 * @param string $state State value from request/token.
	 *
	 * @return true|WP_Error
	 */
	public static function consume_handoff_state( $state ) {
		$state = sanitize_text_field( (string) $state );
		if ( '' === $state ) {
			return new WP_Error( 'pib_missing_state', 'Missing handoff state.' );
		}

		$cookie_state = self::handoff_state_cookie_value();
		if ( '' === $cookie_state || ! hash_equals( $cookie_state, $state ) ) {
			return new WP_Error( 'pib_invalid_state_binding', 'Handoff state did not match browser session.' );
		}

		$key     = self::handoff_state_key( $state );
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'pib_state_replay', 'Handoff state is invalid or already used.' );
		}

		$ua = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		if ( '' !== $ua && ! empty( $payload['ua_hash'] ) && md5( $ua ) !== (string) $payload['ua_hash'] ) {
			return new WP_Error( 'pib_invalid_state_binding', 'Handoff state source does not match originating browser.' );
		}

		delete_transient( $key );
		self::clear_handoff_state_cookie();

		return true;
	}

	/**
	 * Build transient key for handoff state.
	 *
	 * @param string $state State value.
	 *
	 * @return string
	 */
	private static function handoff_state_key( $state ) {
		return 'pib_state_' . md5( (string) $state );
	}

	/**
	 * Set browser cookie for the handoff state value.
	 *
	 * @param string $state       State value.
	 * @param int    $ttl_seconds Cookie lifetime.
	 *
	 * @return void
	 */
	private static function set_handoff_state_cookie( $state, $ttl_seconds ) {
		$expires  = time() + max( 60, absint( $ttl_seconds ) );
		$path     = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain   = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		$secure   = is_ssl();
		$http_only = true;

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::HANDOFF_STATE_COOKIE,
				$state,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => $http_only,
					// Cross-site POST handoff from Shopify worker back to WordPress intake requires cookie availability.
					'samesite' => 'None',
				)
			);
			return;
		}

		setcookie( self::HANDOFF_STATE_COOKIE, $state, $expires, $path, $domain, $secure, $http_only );
	}

	/**
	 * Clear browser cookie for handoff state.
	 *
	 * @return void
	 */
	private static function clear_handoff_state_cookie() {
		$path      = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain    = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		$secure    = is_ssl();
		$http_only = true;
		$expires   = time() - HOUR_IN_SECONDS;

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::HANDOFF_STATE_COOKIE,
				'',
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => $http_only,
					'samesite' => 'None',
				)
			);
			return;
		}

		setcookie( self::HANDOFF_STATE_COOKIE, '', $expires, $path, $domain, $secure, $http_only );
	}

	/**
	 * Read current handoff state cookie value.
	 *
	 * @return string
	 */
	private static function handoff_state_cookie_value() {
		if ( ! isset( $_COOKIE[ self::HANDOFF_STATE_COOKIE ] ) || ! is_scalar( $_COOKIE[ self::HANDOFF_STATE_COOKIE ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) wp_unslash( $_COOKIE[ self::HANDOFF_STATE_COOKIE ] ) );
	}
}
