<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dedicated scheduler for Libratel connector sync.
 */
class Libratel_Connector_Scheduler {
    public const CRON_HOOK = 'libratel_connector_sync_event';
    private const SCHEDULE_KEY_PREFIX = 'libratel_connector_every_';

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_sync']);

        // Keep state consistent if cron entries are missing/disabled.
        self::ensure_schedule_state();
    }

    /**
     * Register dynamic interval schedule from connector option.
     *
     * @param array<string,mixed> $schedules
     * @return array<string,mixed>
     */
    public static function register_cron_schedules(array $schedules): array {
        $minutes = self::interval_minutes();
        $key = self::schedule_key($minutes);

        if (!isset($schedules[$key])) {
            $schedules[$key] = [
                'interval' => $minutes * MINUTE_IN_SECONDS,
                'display'  => sprintf('Libratel Connector Every %d Minutes', $minutes),
            ];
        }

        return $schedules;
    }

    /**
     * Runs on the cron hook.
     */
    public static function run_scheduled_sync(): void {
        if (!self::enabled()) {
            return;
        }
        Libratel_Connector_Sync_Service::run_scheduled_sync();
    }

    /**
     * Refresh schedule after settings save or activation.
     */
    public static function refresh_schedule_from_settings(): void {
        self::unschedule_all();

        if (!self::enabled()) {
            return;
        }

        $minutes = self::interval_minutes();
        $key = self::schedule_key($minutes);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $key, self::CRON_HOOK);
        }
    }

    /**
     * Ensure expected schedule exists or is removed based on current settings.
     */
    public static function ensure_schedule_state(): void {
        if (self::enabled()) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                self::refresh_schedule_from_settings();
            }
            return;
        }

        if (wp_next_scheduled(self::CRON_HOOK)) {
            self::unschedule_all();
        }
    }

    public static function activate(): void {
        if (!has_filter('cron_schedules', [__CLASS__, 'register_cron_schedules'])) {
            add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
        }
        self::refresh_schedule_from_settings();
    }

    public static function deactivate(): void {
        self::unschedule_all();
    }

    /**
     * Timestamp of next scheduled run, or false if not scheduled.
     *
     * @return int|false
     */
    public static function next_run_timestamp() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    private static function enabled(): bool {
        return (bool) get_option(Libratel_Connector_Options::OPTION_SCHEDULE_ENABLED, false);
    }

    private static function interval_minutes(): int {
        $minutes = (int) get_option(Libratel_Connector_Options::OPTION_SCHEDULE_INTERVAL, 60);
        return max(5, min(1440, $minutes));
    }

    private static function schedule_key(int $minutes): string {
        return self::SCHEDULE_KEY_PREFIX . $minutes . '_minutes';
    }

    private static function unschedule_all(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }
}
