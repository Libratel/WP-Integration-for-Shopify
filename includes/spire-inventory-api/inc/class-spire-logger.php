<?php
if (!defined('ABSPATH')) {
    exit;
}

class Spire_Logger {

    /**
     * Log a message to WordPress debug.log or a custom location
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Spire API [$level] - " . $message);
        }
    }
}
