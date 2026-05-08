<?php
/**
 * First-time registration email verification flow.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and validates one-time email verification links for first-time mappings.
 */
final class PIB_Registration_Verifier {
	/**
	 * Singleton.
	 *
	 * @var PIB_Registration_Verifier|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Token status: pending send/issued.
	 */
	const STATUS_ISSUED = 'issued';

	/**
	 * Token status: successfully sent by email.
	 */
	const STATUS_SENT = 'sent';

	/**
	 * Token status: consumed by verification click.
	 */
	const STATUS_CONSUMED = 'consumed';

	/**
	 * Token status: email dispatch failed.
	 */
	const STATUS_SEND_FAILED = 'send_failed';

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Registration_Verifier|null
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
	 * Get verification table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pib_verification_tokens';
	}

	/**
	 * Build object and register hooks.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_post_nopriv_pib_verify_email', array( $this, 'handle_verify_email' ) );
		add_action( 'admin_post_pib_verify_email', array( $this, 'handle_verify_email' ) );
	}

	/**
	 * Ensure first-time verification challenge exists and email is dispatched.
	 *
	 * @param array<string, mixed> $mapping Mapping row.
	 * @param array<string, mixed> $claims  Verified claims.
	 *
	 * @return true|WP_Error
	 */
	public function ensure_verification_challenge( $mapping, $claims ) {
		$mapping = is_array( $mapping ) ? $mapping : array();
		$claims  = is_array( $claims ) ? $claims : array();

		$mapping_id = isset( $mapping['id'] ) ? absint( $mapping['id'] ) : 0;
		$user_id    = isset( $mapping['wp_user_id'] ) ? absint( $mapping['wp_user_id'] ) : 0;
		$email      = PIB_Customer_Mapping::email_from_claims( $claims );
		if ( '' === $email ) {
			$email = isset( $mapping['email'] ) ? sanitize_email( (string) $mapping['email'] ) : '';
		}

		if ( $mapping_id <= 0 || $user_id <= 0 || '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'pib_email_verification_send_failed',
				'Unable to start email verification for this account.'
			);
		}

		$settings      = $this->plugin->get_settings();
		$now           = time();
		$cooldown      = max( 30, min( 1800, absint( $settings['verification_resend_cooldown_seconds'] ) ) );
		$max_per_hour  = max( 1, min( 60, absint( $settings['verification_max_sends_per_hour'] ) ) );
		$ttl_seconds   = max( 300, min( 3600, absint( $settings['verification_token_ttl_seconds'] ) ) );
		$dest          = isset( $claims['dest'] ) ? (string) $claims['dest'] : '';
		$dest          = PIB_Destination::sanitize_destination(
			$dest,
			isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard'
		);
		$dest_allowed = PIB_Destination::is_allowed(
			$dest,
			isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array()
		);
		if ( ! $dest_allowed ) {
			$dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		}

		$recent_token = $this->latest_token_for_mapping( $mapping_id );
		if ( is_array( $recent_token ) ) {
			$recent_created = isset( $recent_token['created_at'] ) ? strtotime( (string) $recent_token['created_at'] . ' UTC' ) : false;
			$recent_status  = isset( $recent_token['status'] ) ? sanitize_key( (string) $recent_token['status'] ) : '';
			if ( false !== $recent_created ) {
				$seconds_since = max( 0, $now - (int) $recent_created );
				if ( $seconds_since < $cooldown && in_array( $recent_status, array( self::STATUS_ISSUED, self::STATUS_SENT ), true ) ) {
					return true;
				}
			}
		}

		$sends_last_hour = $this->count_recent_dispatches( $mapping_id, HOUR_IN_SECONDS );
		if ( $sends_last_hour >= $max_per_hour ) {
			return new WP_Error(
				'pib_email_verification_rate_limited',
				'Verification email limit reached. Please try again in a little while.'
			);
		}

		$token = wp_generate_password( 64, false, false );
		if ( '' === $token ) {
			return new WP_Error(
				'pib_email_verification_send_failed',
				'Unable to generate verification token.'
			);
		}

		$token_hash = $this->hash_token( $token );
		$expires_at = gmdate( 'Y-m-d H:i:s', $now + $ttl_seconds );

		$inserted = $this->insert_token_row(
			array(
				'mapping_id'          => $mapping_id,
				'wp_user_id'          => $user_id,
				'email'               => $email,
				'token_hash'          => $token_hash,
				'status'              => self::STATUS_ISSUED,
				'expires_at'          => $expires_at,
				'dest'                => $dest,
				'shopify_customer_id' => isset( $mapping['shopify_customer_id'] ) ? (string) $mapping['shopify_customer_id'] : '',
				'company_id'          => isset( $mapping['company_id'] ) ? (string) $mapping['company_id'] : '',
				'request_ip'          => PIB_Security::client_ip(),
				'request_ua_hash'     => $this->user_agent_hash(),
			)
		);

		if ( $inserted <= 0 ) {
			return new WP_Error(
				'pib_email_verification_send_failed',
				'Unable to save verification token.'
			);
		}

		$verify_url = add_query_arg(
			array(
				'action' => 'pib_verify_email',
				'token'  => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);

		$email_sent = $this->dispatch_verification_email( $email, $verify_url, $ttl_seconds );
		if ( ! $email_sent ) {
			$this->mark_token_status( $inserted, self::STATUS_SEND_FAILED );
			return new WP_Error(
				'pib_email_verification_send_failed',
				'Unable to send verification email right now. Please try again shortly.'
			);
		}

		$this->mark_token_status( $inserted, self::STATUS_SENT );

		do_action(
			'pib_email_verification_required',
			array(
				'mapping_id'          => $mapping_id,
				'user_id'             => $user_id,
				'email'               => $email,
				'shopify_customer_id' => isset( $mapping['shopify_customer_id'] ) ? (string) $mapping['shopify_customer_id'] : '',
				'company_id'          => isset( $mapping['company_id'] ) ? (string) $mapping['company_id'] : '',
				'dest'                => $dest,
			)
		);

		return true;
	}

	/**
	 * Handle verification link callback from email.
	 *
	 * @return void
	 */
	public function handle_verify_email() {
		nocache_headers();

		$settings = $this->plugin->get_settings();
		$token    = $this->request_token();
		if ( '' === $token ) {
			$this->redirect_to_failure( 'pib_verification_invalid_token', $settings );
		}

		$token_hash = $this->hash_token( $token );
		$row        = $this->find_token_row( $token_hash );
		if ( ! is_array( $row ) ) {
			$this->redirect_to_failure( 'pib_verification_invalid_token', $settings );
		}

		$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
		if ( self::STATUS_CONSUMED === $status ) {
			$this->redirect_to_failure( 'pib_verification_already_used', $settings );
		}

		$expires_at = isset( $row['expires_at'] ) ? strtotime( (string) $row['expires_at'] . ' UTC' ) : false;
		if ( false === $expires_at || time() > (int) $expires_at ) {
			$this->redirect_to_failure( 'pib_verification_expired_token', $settings );
		}

		$mapping_id = isset( $row['mapping_id'] ) ? absint( $row['mapping_id'] ) : 0;
		$user_id    = isset( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : 0;
		$dest       = isset( $row['dest'] ) ? (string) $row['dest'] : '';
		$dest       = PIB_Destination::sanitize_destination(
			$dest,
			isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard'
		);
		if ( ! PIB_Destination::is_allowed( $dest, isset( $settings['allowed_destination_prefixes'] ) ? $settings['allowed_destination_prefixes'] : array() ) ) {
			$dest = isset( $settings['default_destination'] ) ? (string) $settings['default_destination'] : '/dashboard';
		}

		$mapping_module = PIB_Customer_Mapping::instance( $this->plugin );
		if ( ! $mapping_module instanceof PIB_Customer_Mapping || $mapping_id <= 0 || $user_id <= 0 ) {
			$this->redirect_to_failure( 'pib_verification_mapping_missing', $settings );
		}

		$mapping = $mapping_module->get_mapping( $mapping_id );
		if ( ! is_array( $mapping ) ) {
			$this->redirect_to_failure( 'pib_verification_mapping_missing', $settings );
		}

		$current_status = isset( $mapping['status'] ) ? sanitize_key( (string) $mapping['status'] ) : '';
		if ( PIB_Customer_Mapping::STATUS_PENDING_VERIFICATION !== $current_status ) {
			$this->redirect_to_failure( 'pib_verification_mapping_inactive', $settings );
		}

		$email = isset( $mapping['email'] ) ? sanitize_email( (string) $mapping['email'] ) : '';
		if ( '' === $email ) {
			$email = isset( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '';
		}

		$updated = $mapping_module->update_mapping(
			$mapping_id,
			array(
				'status'     => 'active',
				'wp_user_id' => $user_id,
				'email'      => $email,
			)
		);
		if ( ! $updated ) {
			$this->redirect_to_failure( 'pib_verification_mapping_update_failed', $settings );
		}

		$this->mark_token_status( absint( $row['id'] ), self::STATUS_CONSUMED );

		update_user_meta( $user_id, PIB_Customer_Mapping::META_ACTIVE_MAPPING_ID, $mapping_id );

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			$this->redirect_to_failure( 'pib_verification_user_missing', $settings );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		$claims = array(
			'shopify_customer_id' => isset( $mapping['shopify_customer_id'] ) ? (string) $mapping['shopify_customer_id'] : '',
			'company_id'          => isset( $mapping['company_id'] ) ? (string) $mapping['company_id'] : '',
			'email'               => $email,
			'dest'                => $dest,
			'verification'        => 'email_link',
		);

		do_action( 'pib_email_verification_completed', $claims, $user->ID, $mapping_id );
		do_action( 'pib_auth_intake_success', $claims, $user->ID, $dest );

		$redirect_to = add_query_arg(
			array(
				'pib_verified' => '1',
			),
			PIB_Destination::to_absolute_url( $dest )
		);

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Find most recent token for mapping.
	 *
	 * @param int $mapping_id Mapping ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latest_token_for_mapping( $mapping_id ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_id = %d ORDER BY id DESC LIMIT 1",
				$mapping_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count recent send/issue attempts for mapping.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @param int $window     Time window in seconds.
	 *
	 * @return int
	 */
	private function count_recent_dispatches( $mapping_id, $window ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		$window     = max( 60, absint( $window ) );
		if ( $mapping_id <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );
		$table  = self::table_name();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE mapping_id = %d AND created_at >= %s",
				$mapping_id,
				$cutoff
			)
		);

		return absint( $count );
	}

	/**
	 * Insert verification token row.
	 *
	 * @param array<string, mixed> $data Row payload.
	 *
	 * @return int Insert ID.
	 */
	private function insert_token_row( $data ) {
		global $wpdb;

		$data = is_array( $data ) ? $data : array();
		$now  = current_time( 'mysql', 1 );

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'mapping_id'          => isset( $data['mapping_id'] ) ? absint( $data['mapping_id'] ) : 0,
				'wp_user_id'          => isset( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : 0,
				'email'               => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
				'token_hash'          => isset( $data['token_hash'] ) ? sanitize_text_field( (string) $data['token_hash'] ) : '',
				'status'              => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : self::STATUS_ISSUED,
				'expires_at'          => isset( $data['expires_at'] ) ? sanitize_text_field( (string) $data['expires_at'] ) : $now,
				'dest'                => isset( $data['dest'] ) ? sanitize_text_field( (string) $data['dest'] ) : '/dashboard',
				'shopify_customer_id' => isset( $data['shopify_customer_id'] ) ? sanitize_text_field( (string) $data['shopify_customer_id'] ) : '',
				'company_id'          => isset( $data['company_id'] ) ? sanitize_text_field( (string) $data['company_id'] ) : '',
				'request_ip'          => isset( $data['request_ip'] ) ? sanitize_text_field( (string) $data['request_ip'] ) : '',
				'request_ua_hash'     => isset( $data['request_ua_hash'] ) ? sanitize_text_field( (string) $data['request_ua_hash'] ) : '',
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		return absint( $wpdb->insert_id );
	}

	/**
	 * Update token status and lifecycle timestamps.
	 *
	 * @param int    $row_id  Row ID.
	 * @param string $status  New status.
	 *
	 * @return void
	 */
	private function mark_token_status( $row_id, $status ) {
		global $wpdb;

		$row_id = absint( $row_id );
		$status = sanitize_key( (string) $status );
		if ( $row_id <= 0 || '' === $status ) {
			return;
		}

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', 1 ),
		);

		if ( self::STATUS_SENT === $status ) {
			$data['sent_at'] = current_time( 'mysql', 1 );
		}
		if ( self::STATUS_CONSUMED === $status ) {
			$data['consumed_at'] = current_time( 'mysql', 1 );
		}

		$format = array_fill( 0, count( $data ), '%s' );

		$wpdb->update(
			self::table_name(),
			$data,
			array( 'id' => $row_id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Find token row by token hash.
	 *
	 * @param string $token_hash Token hash.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_token_row( $token_hash ) {
		global $wpdb;

		$token_hash = sanitize_text_field( (string) $token_hash );
		if ( '' === $token_hash ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s ORDER BY id DESC LIMIT 1",
				$token_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Build salted token hash.
	 *
	 * @param string $token Raw token.
	 *
	 * @return string
	 */
	private function hash_token( $token ) {
		$token = (string) $token;
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Dispatch verification email.
	 *
	 * @param string $email       Recipient email.
	 * @param string $verify_url  Verification URL.
	 * @param int    $ttl_seconds Token TTL in seconds.
	 *
	 * @return bool
	 */
	private function dispatch_verification_email( $email, $verify_url, $ttl_seconds ) {
		$email       = sanitize_email( (string) $email );
		$verify_url  = esc_url_raw( (string) $verify_url );
		$ttl_seconds = max( 60, absint( $ttl_seconds ) );

		if ( '' === $email || ! is_email( $email ) || '' === $verify_url ) {
			return false;
		}

		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$ttl_minutes = max( 1, (int) ceil( $ttl_seconds / 60 ) );
		$subject     = sprintf( '[%s] Verify your portal sign-in', $site_name );
		$body_lines  = array(
			'You requested first-time access to the partner portal.',
			'',
			'Verify your email to finish sign-in:',
			$verify_url,
			'',
			sprintf( 'This link expires in %d minute(s) and can only be used once.', $ttl_minutes ),
			'If you did not request this, you can ignore this email.',
		);

		$body = implode( "\n", $body_lines );

		/**
		 * Filter verification email payload before dispatch.
		 *
		 * @param array<string, string> $payload Email payload.
		 * @param PIB_Registration_Verifier $module Verifier module.
		 */
		$payload = apply_filters(
			'pib_verification_email_payload',
			array(
				'to'      => $email,
				'subject' => $subject,
				'body'    => $body,
			),
			$this
		);

		if ( ! is_array( $payload ) ) {
			return false;
		}

		$to      = isset( $payload['to'] ) ? sanitize_email( (string) $payload['to'] ) : '';
		$subject = isset( $payload['subject'] ) ? wp_strip_all_tags( (string) $payload['subject'] ) : '';
		$body    = isset( $payload['body'] ) ? (string) $payload['body'] : '';

		if ( '' === $to || '' === $subject || '' === trim( $body ) ) {
			return false;
		}

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Request token from query string.
	 *
	 * @return string
	 */
	private function request_token() {
		if ( ! isset( $_GET['token'] ) || ! is_scalar( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$raw = sanitize_text_field( (string) wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return trim( $raw );
	}

	/**
	 * Get stable hash of request user agent.
	 *
	 * @return string
	 */
	private function user_agent_hash() {
		$ua = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		if ( '' === $ua ) {
			return '';
		}

		return hash( 'sha256', $ua );
	}

	/**
	 * Redirect to configured failure destination with an error code.
	 *
	 * @param string               $error_code Error code.
	 * @param array<string, mixed> $settings   Plugin settings.
	 *
	 * @return void
	 */
	private function redirect_to_failure( $error_code, $settings ) {
		$settings = is_array( $settings ) ? $settings : $this->plugin->get_settings();
		$error_code = sanitize_key( (string) $error_code );

		do_action(
			'pib_auth_intake_failure',
			$error_code,
			'Email verification flow failed.',
			403,
			array()
		);

		$failure  = PIB_Destination::safe_failure_destination(
			isset( $settings['failure_destination'] ) ? (string) $settings['failure_destination'] : '',
			'/portal-login'
		);

		$url = add_query_arg(
			array(
				'pib_auth_error' => $error_code,
			),
			PIB_Destination::to_absolute_url( $failure )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
