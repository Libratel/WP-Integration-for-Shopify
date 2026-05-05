<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_Settings
 * Manages retrieval of user-saved credentials from wp_options.
 */
class Spire_Settings {
    public static function init() {
        // keep your existing init wiring
    }

    public static function get_option($key, $default = '') {
        $map = [
            'username'      => 'spire_username',
            'password'      => 'spire_password',
            'base_url'      => 'spire_base_url',
            'company_code'  => 'spire_company_code',
            'default_customer_no'  => 'spire_default_customer_no',
            // if you have ssl toggle, keep its key here too e.g. 'ssl_no_verify' => 'spire_ssl_no_verify'
        ];
        if (!isset($map[$key])) return $default;
        return get_option($map[$key], $default);
    }

    // Small helper to compose API endpoints consistently
    public static function endpoint(string $path): string {
        $base   = rtrim(self::get_option('base_url', ''), '/');
        $path   = ltrim($path, '/');
        return $base ? "{$base}/{$path}" : "/{$path}";
    }

    // Convenience for common Spire paths with company
    public static function company_path(string $subpath): string {
        $company = trim(self::get_option('company_code', ''), '/');
        if ($company === '') {
            throw new \RuntimeException('Spire company_code is not configured.');
        }
        $subpath = ltrim($subpath, '/');
        return "companies/{$company}/{$subpath}";
    }

    public static function default_customer_no(): string {
        return trim(self::get_option('default_customer_no', ''));
    }
}
