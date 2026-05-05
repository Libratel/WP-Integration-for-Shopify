<?php
/*
Plugin Name: Spire Inventory API
Description: Synchronizes product data from Spire ERP to WooCommerce; handles orders, scheduling, and incremental vs. full sync. Includes advanced UI with warehouse fields, customer mapping, and unsuccessful order logs.
Version:     1.0
Author:      Nojan
*/

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// 1. Require all class files
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-plugin-init.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-settings.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-admin-ui.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-customer-mapping.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-product-ui.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-api-client.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-sync-manager.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-orders-manager.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-payment-terms-sync.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-options.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-connectivity.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-spire-service.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-shopify-service.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-sync-service.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector-admin-page.php';
require_once plugin_dir_path(__FILE__) . 'inc/libratel/class-libratel-connector.php';

// Universal ERP support classes
require_once plugin_dir_path(__FILE__) . 'inc/class-erp-adapter-interface.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-erp-context.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-erp-provider-registry.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-erp-mapping-engine.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-spire-adapter.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-erp-sync-manager.php';

// Register additional adapter classes for other ERP systems. These classes
// provide skeleton implementations that developers can extend. They are
// included here so that they are available to the provider registry when
// plugins_loaded fires. Actual integrations should replace the stubbed
// methods in these classes with real API calls.
require_once plugin_dir_path(__FILE__) . 'inc/class-dynamics-adapter.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-sage300-adapter.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-odoo-adapter.php';
require_once plugin_dir_path(__FILE__) . 'inc/class-custom-adapter.php';

// 2. Add global notice functions if not present
if (!function_exists('spire_inventory_api_set_notice')) {
    function spire_inventory_api_set_notice($message, $type = 'success') {
        $class = "notice notice-{$type} is-dismissible";
        $html  = "<div class='{$class}'><p>{$message}</p></div>";
        set_transient('spire_inventory_api_notice', $html, 45);
    }
}

if (!function_exists('spire_inventory_api_admin_notices')) {
    function spire_inventory_api_admin_notices() {
        $notice = get_transient('spire_inventory_api_notice');
        if ($notice) {
            echo $notice;
            delete_transient('spire_inventory_api_notice');
        }
    }
}
add_action('admin_notices', 'spire_inventory_api_admin_notices');

// 3. Initialize plugin (runs after all plugins loaded)
add_action('plugins_loaded', function() {
    // Initialize base/admin-safe classes regardless of WooCommerce state.
    Spire_Plugin_Init::init();
    Spire_Settings::init();
    Libratel_Connector::init();

    // Woo-dependent features remain behind this guard.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p><strong>Spire Inventory API:</strong> WooCommerce is not active. Woo sync/order features are disabled, but Libratel Connector settings remain available.</p></div>';
        });
        return;
    }

    // Initialize Woo-enabled classes
    Spire_Admin_UI::init();
    Spire_Customer_Mapping::init();
    Spire_Product_UI::init();
    Spire_Sync_Manager::init();
    Spire_Orders_Manager::init();

    // Register the default Spire adapter in the provider registry. Other
    // adapters can register themselves when included. Register skeleton
    // adapters for Dynamics, Sage 300, Odoo and Custom so that they appear
    // as selectable providers in the ERP settings. Developers can override
    // these classes with concrete implementations in separate plugins.
    if (class_exists('ERP_Provider_Registry')) {
        if (class_exists('Spire_Adapter')) {
            ERP_Provider_Registry::register_provider(new Spire_Adapter());
        }
        if (class_exists('Dynamics_Adapter')) {
            ERP_Provider_Registry::register_provider(new Dynamics_Adapter());
        }
        if (class_exists('Sage300_Adapter')) {
            ERP_Provider_Registry::register_provider(new Sage300_Adapter());
        }
        if (class_exists('Odoo_Adapter')) {
            ERP_Provider_Registry::register_provider(new Odoo_Adapter());
        }
        if (class_exists('Custom_Adapter')) {
            ERP_Provider_Registry::register_provider(new Custom_Adapter());
        }
    }
});

// 4. Activation and Deactivation Hooks
register_activation_hook(__FILE__, ['Spire_Sync_Manager', 'activate_plugin']);
register_deactivation_hook(__FILE__, ['Spire_Sync_Manager', 'deactivate_plugin']);
register_activation_hook(__FILE__, ['Libratel_Connector_Scheduler', 'activate']);
register_deactivation_hook(__FILE__, ['Libratel_Connector_Scheduler', 'deactivate']);
