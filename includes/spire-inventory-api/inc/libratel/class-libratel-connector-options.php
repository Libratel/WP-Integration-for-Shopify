<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Libratel connector option access + sanitization.
 */
class Libratel_Connector_Options {
    public const OPTION_SHOPIFY_DOMAIN = 'libratel_shopify_domain';
    public const OPTION_SHOPIFY_TOKEN  = 'libratel_shopify_access_token';
    public const OPTION_SHOPIFY_CLIENT_ID = 'libratel_shopify_client_id';
    public const OPTION_SHOPIFY_CLIENT_SECRET = 'libratel_shopify_client_secret';
    public const OPTION_SCHEDULE_ENABLED = 'libratel_connector_schedule_enabled';
    public const OPTION_SCHEDULE_INTERVAL = 'libratel_connector_interval_minutes';
    public const OPTION_LAST_RUN = 'libratel_connector_last_run';
    public const OPTION_SYNC_LOG = 'libratel_connector_sync_log';
    public const OPTION_JOB_STATE = 'libratel_connector_job_state';
    private const MAX_LOG_ENTRIES = 50;

    /**
     * Return all values needed by the admin page.
     *
     * @return array<string,mixed>
     */
    public static function get_all(): array {
        $interval = (int) get_option(self::OPTION_SCHEDULE_INTERVAL, 60);
        if ($interval < 5) {
            $interval = 60;
        }

        return [
            'spire_base_url'    => (string) get_option('spire_base_url', ''),
            'spire_username'    => (string) get_option('spire_username', ''),
            'spire_password'    => (string) get_option('spire_password', ''),
            'shopify_domain'    => (string) get_option(self::OPTION_SHOPIFY_DOMAIN, ''),
            'shopify_token'     => (string) get_option(self::OPTION_SHOPIFY_TOKEN, ''),
            'shopify_client_id' => (string) get_option(self::OPTION_SHOPIFY_CLIENT_ID, ''),
            'shopify_client_secret' => (string) get_option(self::OPTION_SHOPIFY_CLIENT_SECRET, ''),
            'schedule_enabled'  => (bool) get_option(self::OPTION_SCHEDULE_ENABLED, false),
            'schedule_interval' => $interval,
            'schedule_next_run' => self::next_run_timestamp(),
            'schedule_next_run_local' => self::next_run_local_string(),
            'job_state'         => self::get_job_state(),
            'last_run'          => self::get_last_run(),
            'sync_log'          => self::get_sync_log(),
        ];
    }

    /**
     * Save request payload into WP options.
     *
     * @param array<string,mixed> $request
     * @return void
     */
    public static function save_from_request(array $request): void {
        $previous_shopify_domain = (string) get_option(self::OPTION_SHOPIFY_DOMAIN, '');
        $previous_shopify_client_id = (string) get_option(self::OPTION_SHOPIFY_CLIENT_ID, '');

        $spire_base_url = isset($request['spire_base_url']) ? wp_unslash((string) $request['spire_base_url']) : '';
        $spire_username = isset($request['spire_username']) ? wp_unslash((string) $request['spire_username']) : '';
        $spire_password = isset($request['spire_password']) ? wp_unslash((string) $request['spire_password']) : '';

        $shopify_domain = isset($request['libratel_shopify_domain']) ? wp_unslash((string) $request['libratel_shopify_domain']) : '';
        $shopify_token  = isset($request['libratel_shopify_access_token']) ? wp_unslash((string) $request['libratel_shopify_access_token']) : '';
        $shopify_client_id = isset($request['libratel_shopify_client_id']) ? wp_unslash((string) $request['libratel_shopify_client_id']) : '';
        $shopify_client_secret = isset($request['libratel_shopify_client_secret']) ? wp_unslash((string) $request['libratel_shopify_client_secret']) : '';

        $schedule_enabled = !empty($request['libratel_connector_schedule_enabled']) ? 1 : 0;
        $schedule_interval = isset($request['libratel_connector_interval_minutes'])
            ? (int) $request['libratel_connector_interval_minutes']
            : 60;
        $schedule_interval = max(5, min(1440, $schedule_interval));

        $spire_base_url = esc_url_raw(trim($spire_base_url));
        if ($spire_base_url !== '') {
            $spire_base_url = rtrim($spire_base_url, '/');
        }

        $spire_username = sanitize_text_field($spire_username);
        $spire_password = trim($spire_password); // preserve special chars

        $shopify_domain = self::normalize_shopify_domain($shopify_domain);
        $shopify_token  = trim($shopify_token); // preserve special chars
        $shopify_client_id = trim($shopify_client_id);
        $shopify_client_secret = trim($shopify_client_secret);

        // Refresh cached client-credential token on credential/domain updates.
        if (class_exists('Libratel_Connector_Shopify_Service')) {
            Libratel_Connector_Shopify_Service::clear_cached_client_credentials_token(
                (string) $previous_shopify_domain,
                (string) $previous_shopify_client_id
            );
            Libratel_Connector_Shopify_Service::clear_cached_client_credentials_token(
                $shopify_domain,
                $shopify_client_id
            );
        }

        // Reuse existing Spire credential keys.
        update_option('spire_base_url', $spire_base_url);
        update_option('spire_username', $spire_username);
        update_option('spire_password', $spire_password);

        // Connector-specific settings.
        update_option(self::OPTION_SHOPIFY_DOMAIN, $shopify_domain);
        update_option(self::OPTION_SHOPIFY_TOKEN, $shopify_token);
        update_option(self::OPTION_SHOPIFY_CLIENT_ID, $shopify_client_id);
        update_option(self::OPTION_SHOPIFY_CLIENT_SECRET, $shopify_client_secret);
        update_option(self::OPTION_SCHEDULE_ENABLED, $schedule_enabled);
        update_option(self::OPTION_SCHEDULE_INTERVAL, $schedule_interval);
    }

    /**
     * Normalize Shopify domain into host-only format.
     */
    private static function normalize_shopify_domain(string $domain): string {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = trim($domain, "/ \t\n\r\0\x0B");

        // Remove accidental path/query.
        $slash_pos = strpos($domain, '/');
        if ($slash_pos !== false) {
            $domain = substr($domain, 0, $slash_pos);
        }
        $query_pos = strpos($domain, '?');
        if ($query_pos !== false) {
            $domain = substr($domain, 0, $query_pos);
        }

        return sanitize_text_field($domain);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_last_run(): array {
        $data = get_option(self::OPTION_LAST_RUN, []);
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_sync_log(): array {
        $data = get_option(self::OPTION_SYNC_LOG, []);
        if (!is_array($data)) {
            return [];
        }
        // Ensure newest first
        return array_values($data);
    }

    /**
     * @param array<string,mixed> $summary
     */
    public static function set_last_run(array $summary): void {
        update_option(self::OPTION_LAST_RUN, $summary);
    }

    /**
     * @param array<string,mixed> $summary
     */
    public static function append_sync_log(array $summary): void {
        $log = get_option(self::OPTION_SYNC_LOG, []);
        if (!is_array($log)) {
            $log = [];
        }

        array_unshift($log, $summary);
        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, 0, self::MAX_LOG_ENTRIES);
        }

        update_option(self::OPTION_SYNC_LOG, $log);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_job_state(): array {
        $state = get_option(self::OPTION_JOB_STATE, []);
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function set_job_state(array $state): void {
        update_option(self::OPTION_JOB_STATE, $state);
    }

    public static function clear_job_state(): void {
        delete_option(self::OPTION_JOB_STATE);
    }

    /**
     * @return int|false
     */
    public static function next_run_timestamp() {
        if (!class_exists('Libratel_Connector_Scheduler')) {
            return false;
        }
        return Libratel_Connector_Scheduler::next_run_timestamp();
    }

    public static function next_run_local_string(): string {
        $ts = self::next_run_timestamp();
        if (!$ts) {
            return '';
        }
        // WordPress local timezone formatting.
        if (function_exists('wp_date')) {
            return (string) wp_date('Y-m-d H:i:s T', (int) $ts);
        }
        return (string) date('Y-m-d H:i:s', (int) $ts);
    }
}
