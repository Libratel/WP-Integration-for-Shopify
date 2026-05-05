<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queued, batched Libratel sync (Spire -> Shopify).
 */
class Libratel_Connector_Sync_Service {
    private const START_HOOK = 'libratel_connector_background_sync_start';
    private const BATCH_HOOK = 'libratel_connector_background_sync_batch';
    private const PAYLOAD_TRANSIENT_PREFIX = 'libratel_connector_job_payload_';
    private const INDEX_TRANSIENT_PREFIX = 'libratel_connector_job_index_';
    private const JOB_LOCK_PREFIX = 'libratel_connector_job_lock_';
    private const JOB_PAYLOAD_TTL = 12 * HOUR_IN_SECONDS;
    private const JOB_STALE_SECONDS = 2 * HOUR_IN_SECONDS;
    private const BATCH_SIZE = 50;
    private const MAX_DETAIL_ITEMS = 200;
    private const MAX_MISSING_SKUS = 5000;

    public static function init(): void {
        add_action(self::START_HOOK, [__CLASS__, 'background_start_handler'], 10, 1);
        add_action(self::BATCH_HOOK, [__CLASS__, 'background_batch_handler'], 10, 1);
    }

    /**
     * Queue a manual sync and return immediately.
     *
     * @return array<string,mixed>
     */
    public static function run_manual_sync(): array {
        return self::enqueue_sync('manual');
    }

    /**
     * Queue a scheduled sync and return immediately.
     *
     * @return array<string,mixed>
     */
    public static function run_scheduled_sync(): array {
        return self::enqueue_sync('scheduled');
    }

    /**
     * Start-stage worker: fetch Spire data once, persist payload, queue first batch.
     */
    public static function background_start_handler(string $job_id = ''): void {
        $state = self::get_matching_job_state($job_id);
        if (empty($state)) {
            return;
        }

        if (!self::acquire_job_lock($job_id)) {
            return;
        }

        try {
            $started_at = current_time('mysql');
            $state['status'] = 'starting';
            $state['started_at'] = $started_at;
            $state['updated_at'] = $started_at;
            $state['updated_at_ts'] = current_time('timestamp');
            Libratel_Connector_Options::set_job_state($state);

            $summary = self::base_summary((string) ($state['run_source'] ?? 'manual'), $started_at);

            $spire = Libratel_Connector_Spire_Service::fetch_merged_sku_data();
            if (!$spire['ok']) {
                self::fail_job($state, $summary, (string) $spire['message']);
                return;
            }

            $rows = isset($spire['data']) && is_array($spire['data']) ? $spire['data'] : [];
            $meta = isset($spire['meta']) && is_array($spire['meta']) ? $spire['meta'] : [];

            $summary['spire_upc_records'] = (int) ($meta['upc_records'] ?? 0);
            $summary['spire_price_records'] = (int) ($meta['price_records'] ?? 0);
            $summary['spire_upc_duplicates_resolved'] = (int) ($meta['upc_duplicates_resolved'] ?? 0);
            $summary['spire_price_duplicates_resolved'] = (int) ($meta['price_duplicates_resolved'] ?? 0);
            $summary['total_skus'] = count($rows);

            $shopify_index = [];
            if (!empty($rows)) {
                $index_result = Libratel_Connector_Shopify_Service::build_variant_index(array_keys($rows));
                if (!$index_result['ok']) {
                    self::fail_job($state, $summary, (string) $index_result['message']);
                    return;
                }

                $shopify_index = isset($index_result['data']) && is_array($index_result['data'])
                    ? $index_result['data']
                    : [];
            }

            set_transient(self::payload_transient_key($job_id), $rows, self::JOB_PAYLOAD_TTL);
            set_transient(self::index_transient_key($job_id), $shopify_index, self::JOB_PAYLOAD_TTL);

            $state['status'] = 'running';
            $state['processed_skus'] = 0;
            $state['total_skus'] = count($rows);
            $state['batch_size'] = self::BATCH_SIZE;
            $state['updated_at'] = current_time('mysql');
            $state['updated_at_ts'] = current_time('timestamp');
            $state['summary'] = $summary;
            Libratel_Connector_Options::set_job_state($state);

            if ($state['total_skus'] === 0) {
                self::finish_job($state, $summary);
                return;
            }

            self::queue_async(self::BATCH_HOOK, [$job_id]);
        } finally {
            self::release_job_lock($job_id);
        }
    }

    /**
     * Batch worker: process a fixed number of SKUs and queue the next batch if needed.
     */
    public static function background_batch_handler(string $job_id = ''): void {
        $state = self::get_matching_job_state($job_id);
        if (empty($state)) {
            return;
        }

        if (!self::acquire_job_lock($job_id)) {
            return;
        }

        try {
            $rows = get_transient(self::payload_transient_key($job_id));
            if (!is_array($rows)) {
                $summary = isset($state['summary']) && is_array($state['summary'])
                    ? $state['summary']
                    : self::base_summary((string) ($state['run_source'] ?? 'manual'), (string) ($state['started_at'] ?? current_time('mysql')));
                self::fail_job($state, $summary, 'Background payload expired before processing finished.');
                return;
            }

            $shopify_index = get_transient(self::index_transient_key($job_id));
            if (!is_array($shopify_index)) {
                $summary = isset($state['summary']) && is_array($state['summary'])
                    ? $state['summary']
                    : self::base_summary((string) ($state['run_source'] ?? 'manual'), (string) ($state['started_at'] ?? current_time('mysql')));
                self::fail_job($state, $summary, 'Shopify variant index expired before processing finished.');
                return;
            }

            $summary = isset($state['summary']) && is_array($state['summary'])
                ? $state['summary']
                : self::base_summary((string) ($state['run_source'] ?? 'manual'), (string) ($state['started_at'] ?? current_time('mysql')));

            $offset = isset($state['processed_skus']) ? (int) $state['processed_skus'] : 0;
            $total = count($rows);

            if ($offset >= $total) {
                self::finish_job($state, $summary);
                return;
            }

            $batch_rows = array_slice($rows, $offset, self::BATCH_SIZE, true);
            self::process_rows($batch_rows, $shopify_index, $summary);

            $offset += count($batch_rows);
            $state['processed_skus'] = $offset;
            $state['total_skus'] = $total;
            $state['updated_at'] = current_time('mysql');
            $state['updated_at_ts'] = current_time('timestamp');
            $state['summary'] = $summary;
            Libratel_Connector_Options::set_job_state($state);

            if ($offset < $total) {
                self::queue_async(self::BATCH_HOOK, [$job_id]);
                return;
            }

            self::finish_job($state, $summary);
        } finally {
            self::release_job_lock($job_id);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function enqueue_sync(string $run_source): array {
        $state = Libratel_Connector_Options::get_job_state();
        if (self::job_is_stale($state)) {
            self::clear_job_resources($state);
            $state = [];
        }

        if (self::job_is_active($state)) {
            return [
                'ok' => false,
                'message' => 'A Libratel sync is already queued or running.',
                'run_source' => $run_source,
            ];
        }

        $job_id = uniqid('libratel_', true);
        $queued_at = current_time('mysql');
        Libratel_Connector_Options::set_job_state([
            'job_id'          => $job_id,
            'status'          => 'queued',
            'run_source'      => $run_source,
            'queued_at'       => $queued_at,
            'started_at'      => '',
            'updated_at'      => $queued_at,
            'updated_at_ts'   => current_time('timestamp'),
            'processed_skus'  => 0,
            'total_skus'      => 0,
            'batch_size'      => self::BATCH_SIZE,
        ]);

        self::queue_async(self::START_HOOK, [$job_id]);

        return [
            'ok' => true,
            'message' => $run_source === 'scheduled'
                ? 'Scheduled Libratel sync queued.'
                : 'Libratel sync queued and will continue in the background.',
            'run_source' => $run_source,
        ];
    }

    /**
     * @param array<string,mixed> $rows
     * @param array<string,array<int,array<string,mixed>>> $shopify_index
     * @param array<string,mixed> $summary
     */
    private static function process_rows(array $rows, array $shopify_index, array &$summary): void {
        foreach ($rows as $sku => $row) {
            $sku = (string) $sku;
            if ($sku === '' || !is_array($row)) {
                continue;
            }

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            $price = isset($row['price']) ? trim((string) $row['price']) : '';

            $has_upc = ($upc !== '');
            $has_price = ($price !== '' && is_numeric($price));

            if ($has_upc) {
                $summary['skus_with_upc']++;
            }
            if ($has_price) {
                $summary['skus_with_price']++;
            }
            if ($has_upc && $has_price) {
                $summary['skus_with_both']++;
            }

            if (!$has_upc && !$has_price) {
                self::add_detail($summary, 'skip_empty_spire', $sku, 'No UPC or price found in Spire for this SKU.');
                continue;
            }

            $variants = isset($shopify_index[$sku]) && is_array($shopify_index[$sku]) ? $shopify_index[$sku] : [];
            if (empty($variants)) {
                $summary['shopify_skus_not_found']++;
                self::add_missing_sku($summary, $sku);
                self::add_detail($summary, 'shopify_sku_not_found', $sku, 'No Shopify variant found for SKU.');
                continue;
            }

            $summary['shopify_variants_matched'] += count($variants);

            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $variant_id = isset($variant['id']) ? (int) $variant['id'] : 0;
                if ($variant_id <= 0) {
                    $summary['shopify_update_errors']++;
                    self::add_detail($summary, 'shopify_variant_invalid', $sku, 'Variant without valid ID.');
                    continue;
                }

                $payload = [];
                if ($has_upc) {
                    $current_barcode = isset($variant['barcode']) ? trim((string) $variant['barcode']) : '';
                    if ($current_barcode !== $upc) {
                        $payload['barcode'] = $upc;
                    }
                }

                if ($has_price) {
                    $current_price = isset($variant['price']) ? trim((string) $variant['price']) : '';
                    $normalized_target_price = self::normalize_price_string($price);
                    if (self::prices_differ($current_price, $normalized_target_price)) {
                        $payload['price'] = $normalized_target_price;
                    }
                }

                if (empty($payload)) {
                    $summary['shopify_variants_no_change']++;
                    continue;
                }

                $update = Libratel_Connector_Shopify_Service::update_variant($variant_id, $payload);
                if (!$update['ok']) {
                    $summary['shopify_update_errors']++;
                    self::add_detail($summary, 'shopify_update_error', $sku, 'Variant ' . $variant_id . ': ' . (string) $update['message']);
                    continue;
                }

                $summary['shopify_variants_updated']++;
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     */
    private static function fail_job(array $state, array $summary, string $message): void {
        $summary['ok'] = false;
        $summary['message'] = $message;
        self::add_detail($summary, 'job_error', '', $message);
        self::persist_finished_summary($state, $summary);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     */
    private static function finish_job(array $state, array $summary): void {
        if ((int) ($summary['shopify_update_errors'] ?? 0) > 0) {
            $summary['ok'] = false;
            $summary['message'] = ((string) ($state['run_source'] ?? 'manual') === 'scheduled')
                ? 'Libratel scheduled sync finished with errors.'
                : 'Libratel sync finished with errors.';
        } elseif ((int) ($summary['total_skus'] ?? 0) === 0) {
            $summary['ok'] = true;
            $summary['message'] = ((string) ($state['run_source'] ?? 'manual') === 'scheduled')
                ? 'Libratel scheduled sync finished. No Spire SKUs were returned.'
                : 'Libratel sync finished. No Spire SKUs were returned.';
        }

        self::persist_finished_summary($state, $summary);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     */
    private static function persist_finished_summary(array $state, array $summary): void {
        $summary['finished_at'] = current_time('mysql');
        Libratel_Connector_Options::set_last_run($summary);
        Libratel_Connector_Options::append_sync_log($summary);
        self::clear_job_resources($state);
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function clear_job_resources(array $state): void {
        $job_id = isset($state['job_id']) ? (string) $state['job_id'] : '';
        if ($job_id !== '') {
            delete_transient(self::payload_transient_key($job_id));
            delete_transient(self::index_transient_key($job_id));
            self::release_job_lock($job_id);
        }
        Libratel_Connector_Options::clear_job_state();
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function job_is_active(array $state): bool {
        if (empty($state)) {
            return false;
        }
        $status = isset($state['status']) ? (string) $state['status'] : '';
        return in_array($status, ['queued', 'starting', 'running'], true);
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function job_is_stale(array $state): bool {
        if (!self::job_is_active($state)) {
            return false;
        }
        $updated_at_ts = isset($state['updated_at_ts']) ? (int) $state['updated_at_ts'] : 0;
        if ($updated_at_ts <= 0) {
            return false;
        }
        return (current_time('timestamp') - $updated_at_ts) > self::JOB_STALE_SECONDS;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_matching_job_state(string $job_id): array {
        $state = Libratel_Connector_Options::get_job_state();
        $state_job_id = isset($state['job_id']) ? (string) $state['job_id'] : '';
        if ($job_id === '' || $state_job_id === '' || $state_job_id !== $job_id) {
            return [];
        }
        return $state;
    }

    private static function acquire_job_lock(string $job_id): bool {
        $lock_key = self::job_lock_key($job_id);
        if (get_transient($lock_key)) {
            return false;
        }
        return (bool) set_transient($lock_key, 1, 15 * MINUTE_IN_SECONDS);
    }

    private static function release_job_lock(string $job_id): void {
        if ($job_id === '') {
            return;
        }
        delete_transient(self::job_lock_key($job_id));
    }

    /**
     * @return array<string,mixed>
     */
    private static function base_summary(string $run_source, string $started_at): array {
        return [
            'ok' => true,
            'message' => $run_source === 'scheduled' ? 'Libratel scheduled sync completed.' : 'Libratel sync completed.',
            'run_source' => $run_source,
            'started_at' => $started_at,
            'finished_at' => '',
            'total_skus' => 0,
            'skus_with_upc' => 0,
            'skus_with_price' => 0,
            'skus_with_both' => 0,
            'shopify_variants_matched' => 0,
            'shopify_variants_updated' => 0,
            'shopify_variants_no_change' => 0,
            'shopify_skus_not_found' => 0,
            'shopify_update_errors' => 0,
            'spire_upc_records' => 0,
            'spire_price_records' => 0,
            'spire_upc_duplicates_resolved' => 0,
            'spire_price_duplicates_resolved' => 0,
            'missing_skus' => [],
            'details' => [],
        ];
    }

    /**
     * @param array<int|string,mixed> $args
     */
    private static function queue_async(string $hook, array $args): void {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args);
            return;
        }

        wp_schedule_single_event(time(), $hook, $args);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function add_detail(array &$summary, string $type, string $sku, string $message): void {
        if (!isset($summary['details']) || !is_array($summary['details'])) {
            $summary['details'] = [];
        }
        if (count($summary['details']) >= self::MAX_DETAIL_ITEMS) {
            return;
        }
        $summary['details'][] = [
            'type' => $type,
            'sku' => $sku,
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function add_missing_sku(array &$summary, string $sku): void {
        if ($sku === '') {
            return;
        }

        if (!isset($summary['missing_skus']) || !is_array($summary['missing_skus'])) {
            $summary['missing_skus'] = [];
        }

        if (in_array($sku, $summary['missing_skus'], true)) {
            return;
        }

        if (count($summary['missing_skus']) >= self::MAX_MISSING_SKUS) {
            return;
        }

        $summary['missing_skus'][] = $sku;
    }

    private static function payload_transient_key(string $job_id): string {
        return self::PAYLOAD_TRANSIENT_PREFIX . md5($job_id);
    }

    private static function index_transient_key(string $job_id): string {
        return self::INDEX_TRANSIENT_PREFIX . md5($job_id);
    }

    private static function job_lock_key(string $job_id): string {
        return self::JOB_LOCK_PREFIX . md5($job_id);
    }

    private static function normalize_price_string(string $price): string {
        return number_format((float) $price, 2, '.', '');
    }

    private static function prices_differ(string $current, string $target): bool {
        if (!is_numeric($current)) {
            return true;
        }
        return abs((float) $current - (float) $target) > 0.00001;
    }
}
