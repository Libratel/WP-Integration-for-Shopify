<?php
/**
 * Authorization helpers for mapped customer scope.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static access helpers for protected resources.
 */
final class PIB_Authorization {
	/**
	 * Get current signed-in identity context.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current_identity() {
		$mapping = PIB_Customer_Mapping::instance();
		if ( ! $mapping instanceof PIB_Customer_Mapping ) {
			return null;
		}

		return $mapping->get_active_identity_context( get_current_user_id() );
	}

	/**
	 * Check whether current user matches given scope.
	 *
	 * @param string      $shopify_customer_id Required customer ID.
	 * @param string|null $company_id          Optional company/account ID.
	 *
	 * @return bool
	 */
	public static function current_user_matches_scope( $shopify_customer_id, $company_id = null ) {
		$mapping = PIB_Customer_Mapping::instance();
		if ( ! $mapping instanceof PIB_Customer_Mapping ) {
			return false;
		}

		return $mapping->current_user_matches_scope( $shopify_customer_id, $company_id );
	}

	/**
	 * Validate scoped resource authorization.
	 *
	 * @param string      $shopify_customer_id Required customer ID.
	 * @param string|null $company_id          Optional company/account ID.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_scope( $shopify_customer_id, $company_id = null ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'pib_not_logged_in', 'User is not logged in.' );
		}

		if ( ! self::current_user_matches_scope( $shopify_customer_id, $company_id ) ) {
			return new WP_Error( 'pib_scope_mismatch', 'Signed-in identity does not match required scope.' );
		}

		return true;
	}
}

