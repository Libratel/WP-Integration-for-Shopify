<?php
/**
 * Admin-only bridge simulator for testing handoff flow.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds short-lived test JWT tokens and launches intake redirect.
 */
final class PIB_Bridge_Simulator {
	/**
	 * Singleton.
	 *
	 * @var PIB_Bridge_Simulator|null
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
	 * @return PIB_Bridge_Simulator|null
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

		add_action( 'admin_post_pib_bridge_simulate', array( $this, 'handle_launch' ) );
	}

	/**
	 * Build and launch admin test handoff redirect.
	 *
	 * @return void
	 */
	public function handle_launch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.', 403 );
		}

		check_admin_referer( 'pib_bridge_simulate' );

		$settings = $this->plugin->get_settings();

		$token_secret = isset( $settings['token_secret'] ) ? trim( (string) $settings['token_secret'] ) : '';
		$issuer       = isset( $settings['expected_issuer'] ) ? trim( (string) $settings['expected_issuer'] ) : '';
		$audience     = $this->expected_audience( $settings );

		if ( '' === $token_secret ) {
			$this->fail_with_code( 'missing_secret' );
		}
		if ( '' === $issuer ) {
			$this->fail_with_code( 'missing_issuer' );
		}
		if ( '' === $audience ) {
			$this->fail_with_code( 'missing_client_id' );
		}

		$customer_id = $this->request_text( 'pib_bridge_customer_id' );
		$company_id  = $this->request_text( 'pib_bridge_company_id' );
		$email       = sanitize_email( $this->request_text( 'pib_bridge_email' ) );
		$dest_raw    = $this->request_text( 'pib_bridge_dest' );
		$defaults    = $this->default_bridge_identity();

		if ( '' === $customer_id && isset( $defaults['shopify_customer_id'] ) ) {
			$customer_id = trim( (string) $defaults['shopify_customer_id'] );
		}
		if ( '' === $company_id && isset( $defaults['company_id'] ) ) {
			$company_id = trim( (string) $defaults['company_id'] );
		}
		if ( '' === $email && isset( $defaults['email'] ) ) {
			$email = sanitize_email( (string) $defaults['email'] );
		}

		if ( '' === $customer_id ) {
			$this->fail_with_code( 'missing_customer' );
		}
		if ( ! empty( $settings['require_company_id'] ) && '' === $company_id ) {
			$this->fail_with_code( 'missing_company' );
		}

		$default_dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		$dest         = PIB_Destination::sanitize_destination( $dest_raw, $default_dest );
		$allowed      = isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array();
		if ( ! PIB_Destination::is_allowed( $dest, $allowed ) ) {
			$dest = $default_dest;
		}

		$state_ttl = isset( $settings['handoff_state_ttl_seconds'] ) ? absint( $settings['handoff_state_ttl_seconds'] ) : 300;
		$state_ttl = max( 60, min( 1800, $state_ttl ) );
		$state     = PIB_Security::issue_handoff_state( $state_ttl );
		if ( '' === $state ) {
			$this->fail_with_code( 'missing_state' );
		}

		$now    = time();
		$claims = array(
			'iss'                 => $issuer,
			'aud'                 => $audience,
			'exp'                 => $now + 120,
			'iat'                 => $now,
			'nbf'                 => $now,
			'jti'                 => wp_generate_uuid4(),
			'shopify_customer_id' => $customer_id,
			'company_id'          => $company_id,
			'email'               => $email,
			'dest'                => $dest,
			'state'               => $state,
		);

		$token = $this->sign_hs256( $claims, $token_secret );
		if ( is_wp_error( $token ) ) {
			$this->fail_with_code( 'token_signing_failed' );
		}

		$this->render_intake_post_form( (string) $token, $state );
		exit;
	}

	/**
	 * Resolve expected audience from settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return string
	 */
	private function expected_audience( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$audience = isset( $settings['client_id'] ) ? trim( (string) $settings['client_id'] ) : '';
		if ( '' === $audience && isset( $settings['expected_audience'] ) ) {
			$audience = trim( (string) $settings['expected_audience'] );
		}

		return $audience;
	}

	/**
	 * Sign JWT claims with HS256.
	 *
	 * @param array<string, mixed> $claims Claims payload.
	 * @param string               $secret Signing secret.
	 * @return string|WP_Error
	 */
	private function sign_hs256( $claims, $secret ) {
		$claims = is_array( $claims ) ? $claims : array();
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return new WP_Error( 'pib_sign_missing_secret', 'Signing secret is missing.' );
		}

		$header_json = wp_json_encode(
			array(
				'alg' => 'HS256',
				'typ' => 'JWT',
			)
		);
		$claims_json = wp_json_encode( $claims );

		if ( ! is_string( $header_json ) || ! is_string( $claims_json ) ) {
			return new WP_Error( 'pib_sign_json_failure', 'Unable to encode token JSON payload.' );
		}

		$segments = array(
			$this->base64url_encode( $header_json ),
			$this->base64url_encode( $claims_json ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $secret, true );
		$segments[]    = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Encode string as base64url (no padding).
	 *
	 * @param string $value Value to encode.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Redirect back to Tools > Advanced Bridge Simulator with a notice code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function fail_with_code( $code ) {
		$redirect = add_query_arg(
			array(
				'page'                => 'pib-tools',
				'tab'                 => 'advanced',
				'panel'               => 'bridge_simulator',
				'pib_bridge_notice'   => sanitize_key( (string) $code ),
				'pib_bridge_notice_type' => 'error',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render auto-submit POST form to intake endpoint.
	 *
	 * @param string $token JWT token.
	 * @param string $state Handoff state.
	 *
	 * @return void
	 */
	private function render_intake_post_form( $token, $state ) {
		nocache_headers();
		$intake_url = PIB_Plugin::intake_url();

		echo '<!doctype html><html><head><meta charset="utf-8" /><meta name="robots" content="noindex,nofollow" /><title>Portal Bridge Simulator</title></head><body>';
		echo '<form id="pib-sim-post" method="post" action="' . esc_url( $intake_url ) . '">';
		echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		echo '<input type="hidden" name="state" value="' . esc_attr( $state ) . '" />';
		echo '<noscript><p>Click continue to submit simulator handoff.</p><button type="submit">Continue</button></noscript>';
		echo '</form>';
		echo '<script>document.getElementById("pib-sim-post").submit();</script>';
		echo '</body></html>';
	}

	/**
	 * Read scalar request value as text.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_text( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) wp_unslash( $_REQUEST[ $key ] ) );
	}

	/**
	 * Get latest active mapping defaults for quick simulator launches.
	 *
	 * @return array<string, string>
	 */
	private function default_bridge_identity() {
		global $wpdb;

		$table = PIB_Customer_Mapping::table_name();
		$row   = $wpdb->get_row(
			"SELECT shopify_customer_id, company_id, email
			FROM {$table}
			WHERE status = 'active'
			ORDER BY last_seen_at DESC, updated_at DESC, id DESC
			LIMIT 1",
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'shopify_customer_id' => '',
				'company_id'          => '',
				'email'               => '',
			);
		}

		return array(
			'shopify_customer_id' => isset( $row['shopify_customer_id'] ) ? sanitize_text_field( (string) $row['shopify_customer_id'] ) : '',
			'company_id'          => isset( $row['company_id'] ) ? sanitize_text_field( (string) $row['company_id'] ) : '',
			'email'               => isset( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '',
		);
	}
}
