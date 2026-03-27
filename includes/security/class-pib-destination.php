<?php
/**
 * Destination helper utilities.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared sanitization and validation for redirects/destination paths.
 */
final class PIB_Destination {
	/**
	 * Get current request path/query as destination string.
	 *
	 * @return string
	 */
	public static function current_request_destination() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		return self::sanitize_destination( $request_uri, '/' );
	}

	/**
	 * Sanitize and normalize a destination to internal path(+query) format.
	 *
	 * @param mixed  $destination Raw destination.
	 * @param string $fallback    Fallback internal path.
	 *
	 * @return string
	 */
	public static function sanitize_destination( $destination, $fallback = '/' ) {
		$fallback = self::normalize_path_query( (string) $fallback, '/' );

		if ( ! is_string( $destination ) ) {
			return $fallback;
		}

		$destination = trim( wp_unslash( $destination ) );

		if ( '' === $destination ) {
			return $fallback;
		}

		if ( false !== strpos( $destination, "\n" ) || false !== strpos( $destination, "\r" ) ) {
			return $fallback;
		}

		if ( preg_match( '#^(https?:)?//#i', $destination ) ) {
			return $fallback;
		}

		if ( 0 !== strpos( $destination, '/' ) ) {
			$destination = '/' . ltrim( $destination, '/' );
		}

		return self::normalize_path_query( $destination, $fallback );
	}

	/**
	 * Sanitize allowed path prefixes.
	 *
	 * @param mixed $raw Raw prefixes input.
	 *
	 * @return array<int, string>
	 */
	public static function sanitize_prefixes( $raw ) {
		$items = array();

		if ( is_string( $raw ) ) {
			$items = preg_split( '/[\r\n,]+/', $raw );
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}

		$prefixes = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$prefix = trim( wp_unslash( $item ) );
			if ( '' === $prefix ) {
				continue;
			}

			if ( preg_match( '#^(https?:)?//#i', $prefix ) ) {
				continue;
			}

			if ( 0 !== strpos( $prefix, '/' ) ) {
				$prefix = '/' . ltrim( $prefix, '/' );
			}

			$prefix = (string) wp_parse_url( $prefix, PHP_URL_PATH );
			if ( '' === $prefix ) {
				continue;
			}

			$prefix = preg_replace( '#/{2,}#', '/', $prefix );
			if ( ! is_string( $prefix ) || '' === $prefix ) {
				continue;
			}

			if ( '/' !== $prefix ) {
				$prefix = rtrim( $prefix, '/' );
			}

			$prefixes[] = $prefix;
		}

		$prefixes = array_values( array_unique( $prefixes ) );

		if ( empty( $prefixes ) ) {
			$prefixes = array( '/dashboard' );
		}

		return $prefixes;
	}

	/**
	 * Determine whether a destination is allowed by prefix policy.
	 *
	 * @param string               $destination Internal destination.
	 * @param array<int, string>   $prefixes    Allowed path prefixes.
	 *
	 * @return bool
	 */
	public static function is_allowed( $destination, $prefixes ) {
		$destination = self::sanitize_destination( $destination, '/' );
		$path        = (string) wp_parse_url( $destination, PHP_URL_PATH );
		$prefixes    = self::sanitize_prefixes( $prefixes );

		foreach ( $prefixes as $prefix ) {
			if ( '/' === $prefix ) {
				return true;
			}

			if ( 0 === strpos( $path, $prefix ) ) {
				$prefix_length = strlen( $prefix );
				$path_length   = strlen( $path );

				if ( $path_length === $prefix_length ) {
					return true;
				}

				if ( $path_length > $prefix_length && '/' === $path[ $prefix_length ] ) {
					return true;
				}
			}

			// Keep exact root-prefix behavior when saved prefix ends with slash.
			if ( '/' !== $prefix && 0 === strpos( $path, rtrim( $prefix, '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert an internal destination into an absolute portal URL.
	 *
	 * @param string $destination Internal path(+query).
	 *
	 * @return string
	 */
	public static function to_absolute_url( $destination ) {
		$destination = self::sanitize_destination( $destination, '/' );
		return home_url( $destination );
	}

	/**
	 * Normalize path+query values.
	 *
	 * @param string $value    Candidate path+query.
	 * @param string $fallback Fallback value.
	 *
	 * @return string
	 */
	private static function normalize_path_query( $value, $fallback ) {
		$parts = wp_parse_url( $value );
		if ( false === $parts ) {
			return $fallback;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}

		if ( 0 !== strpos( $path, '/' ) ) {
			$path = '/' . ltrim( $path, '/' );
		}

		$path = preg_replace( '#/{2,}#', '/', $path );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		return $query ? $path . '?' . $query : $path;
	}
}
