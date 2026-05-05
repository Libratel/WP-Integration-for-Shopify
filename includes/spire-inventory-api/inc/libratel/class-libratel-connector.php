<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Libratel connector entrypoint.
 */
class Libratel_Connector {
    public static function init(): void {
        Libratel_Connector_Scheduler::init();
        Libratel_Connector_Sync_Service::init();

        if (is_admin()) {
            Libratel_Connector_Admin_Page::init();
        }
    }
}
