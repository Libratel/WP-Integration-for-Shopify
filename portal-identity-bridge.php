<?php
/**
 * Plugin Name: Portal Identity Bridge
 * Description: Shopify/SparkLayer to WordPress auth bridge for the partner portal.
 * Version: 0.5.0
 * Author: Portal Team
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIB_VERSION', '0.5.0' );
define( 'PIB_PLUGIN_FILE', __FILE__ );
define( 'PIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PIB_PLUGIN_DIR . 'includes/mapping/class-pib-customer-mapping.php';
require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-installer.php';
require_once PIB_PLUGIN_DIR . 'includes/core/class-pib-plugin.php';

register_activation_hook( PIB_PLUGIN_FILE, array( 'PIB_Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'PIB_Installer', 'maybe_upgrade' ) );

PIB_Plugin::instance();
