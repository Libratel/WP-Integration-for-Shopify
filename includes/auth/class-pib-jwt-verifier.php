<?php
/**
 * JWT verifier utilities.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small HS256 JWT verifier for bridge handoff tokens.
 */
final class PIB_JWT_Verifier {
	/**
	 * Verify a JWT signed with HS256.
	 *
	 * @param string $token           JWT token.
	 * @param string $secret          Shared secret.
	 * @param string $expected_issuer Expected issuer claim.
	 * @param int    $leeway_seconds  Time leeway for claim checks.
	 * @param string $expected_audience Expected audience claim.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function verify_hs256( $token, $secret, $expected_issuer = '', $leeway_seconds = 30, $expected_audience = '' ) {
		$token           = trim( (string) $token );
		$secret          = (string) $secret;
		$expected_issuer = trim( (string) $expected_issuer );
		$leeway_seconds  = max( 0, absint( $leeway_seconds ) );
		$expected_audience = trim( (string) $expected_audience );

		if ( '' === $token ) {
			return new WP_Error( 'pib_missing_token', 'Missing handoff token.' );
		}

		if ( '' === $secret ) {
			return new WP_Error( 'pib_missing_secret', 'Plugin signing secret is not configured.' );
		}

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'pib_invalid_token_format', 'Invalid token format.' );
		}

		$header_json = self::base64url_decode( $parts[0] );
		$claims_json = self::base64url_decode( $parts[1] );

		if ( null === $header_json || null === $claims_json ) {
			return new WP_Error( 'pib_invalid_token_encoding', 'Invalid token encoding.' );
		}

		$header = json_decode( $header_json, true );
		$claims = json_decode( $claims_json, true );

		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new WP_Error( 'pib_invalid_token_payload', 'Invalid token payload.' );
		}

		if ( ! isset( $header['alg'] ) || 'HS256' !== (string) $header['alg'] ) {
			return new WP_Error( 'pib_invalid_alg', 'Unsupported token algorithm.' );
		}

		$signing_input   = $parts[0] . '.' . $parts[1];
		$expected_binary = hash_hmac( 'sha256', $signing_input, $secret, true );
		$actual_binary   = self::base64url_decode( $parts[2] );

		if ( null === $actual_binary || ! hash_equals( $expected_binary, $actual_binary ) ) {
			return new WP_Error( 'pib_invalid_signature', 'Token signature verification failed.' );
		}

		$now = time();

		if ( empty( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) ) {
			return new WP_Error( 'pib_missing_exp', 'Token is missing a valid exp claim.' );
		}

		$exp = (int) $claims['exp'];
		if ( ( $exp + $leeway_seconds ) < $now ) {
			return new WP_Error( 'pib_token_expired', 'Token has expired.' );
		}

		if ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) ) {
			$nbf = (int) $claims['nbf'];
			if ( ( $nbf - $leeway_seconds ) > $now ) {
				return new WP_Error( 'pib_token_not_yet_valid', 'Token is not yet valid.' );
			}
		}

		if ( isset( $claims['iat'] ) && is_numeric( $claims['iat'] ) ) {
			$iat = (int) $claims['iat'];
			if ( ( $iat - $leeway_seconds ) > $now ) {
				return new WP_Error( 'pib_token_future_iat', 'Token iat is in the future.' );
			}
		}

		if ( '' !== $expected_issuer ) {
			$issuer = isset( $claims['iss'] ) ? (string) $claims['iss'] : '';
			if ( $expected_issuer !== $issuer ) {
				return new WP_Error( 'pib_invalid_issuer', 'Token issuer is not trusted.' );
			}
		}

		if ( '' !== $expected_audience ) {
			$aud = isset( $claims['aud'] ) ? $claims['aud'] : '';
			$audiences = array();

			if ( is_string( $aud ) ) {
				$audiences[] = $aud;
			} elseif ( is_array( $aud ) ) {
				foreach ( $aud as $aud_value ) {
					if ( is_string( $aud_value ) ) {
						$audiences[] = $aud_value;
					}
				}
			}

			$audiences = array_map( 'trim', $audiences );
			if ( ! in_array( $expected_audience, $audiences, true ) ) {
				return new WP_Error( 'pib_invalid_audience', 'Token audience is not trusted.' );
			}
		}

		if ( empty( $claims['jti'] ) || ! is_string( $claims['jti'] ) ) {
			return new WP_Error( 'pib_missing_jti', 'Token is missing a jti claim.' );
		}

		return $claims;
	}

	/**
	 * Decode base64url string to binary.
	 *
	 * @param string $value Base64url encoded string.
	 *
	 * @return string|null
	 */
	private static function base64url_decode( $value ) {
		$value = str_replace( array( '-', '_' ), array( '+', '/' ), (string) $value );
		$pad   = strlen( $value ) % 4;

		if ( $pad > 0 ) {
			$value .= str_repeat( '=', 4 - $pad );
		}

		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return null;
		}

		return $decoded;
	}
}
