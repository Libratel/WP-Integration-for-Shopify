<?php
/*
Plugin Name: Back-Order Report
Description: Fetches Spire back-orders, merges ETA sheet, e-mails CSVs on a schedule.
Version: 1.0.0
Author: TwinOrbit
*/

defined( 'ABSPATH' ) || exit;

// Autoload classes.
require_once __DIR__ . '/class-br-core.php';
require_once __DIR__ . '/class-br-records-table.php';
require_once __DIR__ . '/class-br-admin-new.php';

// Kick off admin UI.
add_action( 'plugins_loaded', function () {
    new BR_Admin_New();
    new BR_Core();
} );

// On activation schedule a first run an hour from now.
register_activation_hook( __FILE__, [ 'BR_Core', 'activation_bootstrap' ] );  // :contentReference[oaicite:3]{index=3}
register_deactivation_hook( __FILE__, [ 'BR_Core', 'clear_schedule' ] );
