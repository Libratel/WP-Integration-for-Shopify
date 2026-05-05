<?php
if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Class Spire_Sync_Manager
 * Handles scheduling + background synchronization of products from Spire ERP to WooCommerce,
 * including both manual and scheduled syncs, concurrency control, and basic error/logging support.
 */
class Spire_Sync_Manager {

    /**
     * Initialize the Sync Manager.
     * Called from plugins_loaded in spire-inventory-api.php
     */
    public static function init() {
        // Hook for scheduled sync events (our existing WP-Cron schedule)
        add_action('spire_sync_event', [__CLASS__, 'scheduled_sync']);

        // Our new background sync hook
        add_action('spire_background_sync', [__CLASS__, 'background_sync_handler'], 10, 3);

        // Register custom cron schedules
        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
    }

    /**
     * Plugin activation hook.
     * Schedules the sync event based on user-defined settings.
     */
    public static function activate_plugin() {
        // Ensure plugin options are created and autoload flags are set via the
        // plugin initializer. This will create any missing options (e.g. ERP
        // provider settings) and mark them autoload=no. Call this here
        // because the activation hook is currently bound to this class.
        if ( class_exists( 'Spire_Plugin_Init' ) && method_exists( 'Spire_Plugin_Init', 'activate' ) ) {
            Spire_Plugin_Init::activate();
        }
        self::schedule_sync_event();
    }

    /**
     * Plugin deactivation hook.
     * Unschedules any existing sync events to prevent orphaned cron jobs.
     */
    public static function deactivate_plugin() {
        self::unschedule_sync_event();
    }

    /**
     * Register a custom cron schedule using "every" minutes
     * from user settings (spire_schedule_settings).
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public static function register_cron_schedules($schedules) {
        $settings = get_option('spire_schedule_settings', []);
        $every    = !empty($settings['every']) ? (int) $settings['every'] : 60;

        // Avoid duplicating schedules if it already exists
        $key = "spire_every_{$every}_minutes";
        if (!isset($schedules[$key])) {
            $schedules[$key] = [
                'interval' => $every * 60, // minutes -> seconds
                'display'  => sprintf(__('Every %d Minutes', 'spire-inventory-api'), $every),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule the recurring sync event (the master cron trigger).
     * We use gmdate('U') to ensure consistent server-based scheduling.
     */
    public static function schedule_sync_event() {
        // Ensure our custom schedule exists (activation context may not have init run yet)
        if ( ! has_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']) ) {
            add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
        }

        $settings = get_option('spire_schedule_settings', []);
        $days     = !empty($settings['days']) ? $settings['days'] : [];
        $every    = !empty($settings['every']) ? (int) $settings['every'] : 60;

        // If no days are selected, do not schedule anything
        if (empty($days)) {
            // Ensure any existing events are removed if the user cleared days
            self::unschedule_sync_event();
            return;
        }

        $hook         = 'spire_sync_event';
        $frequencyKey = "spire_every_{$every}_minutes";

        // Always clear any prior schedules before adding a new one (so "every" changes take effect)
        self::unschedule_sync_event();

        // Use gmdate('U') for consistent scheduling
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(gmdate('U'), $frequencyKey, $hook);
        }
    }

    /**
     * Unschedule existing sync events (on plugin deactivation).
     */
    public static function unschedule_sync_event() {
        $hook      = 'spire_sync_event';
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }

    /**
     * Runs automatically via WP-Cron if the day/time matches user settings.
     * Instead of doing a full sync directly, it schedules a one-time background event.
     */
    public static function scheduled_sync() {
        $settings    = get_option('spire_schedule_settings', []);
        $days        = !empty($settings['days']) ? $settings['days'] : [];
        $from_time   = !empty($settings['from_time']) ? $settings['from_time'] : '09:00';
        $to_time     = !empty($settings['to_time'])   ? $settings['to_time']   : '17:00';
        $full_sync   = !empty($settings['full_sync']) ? boolval($settings['full_sync']) : false;

        // Current day/time in local WP time
        $current_day = current_time('l');  // "Monday", "Tuesday", ...
        $current_hm  = current_time('H:i'); // "14:30"

        // Only sync if day/time is within user-defined range
        if (!in_array($current_day, $days, true)) {
            // Not a chosen day
            return;
        }
        if ($current_hm < $from_time || $current_hm > $to_time) {
            // Outside the time range
            return;
        }

        // Schedule a single background job now
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'spire_background_sync',
                [
                    'is_cron'        => true,
                    'manual_trigger' => false,
                    'full_sync'      => $full_sync,
                ]
            );
        } else {
            wp_schedule_single_event(
                time(),
                'spire_background_sync',
                [
                    'is_cron'        => true,
                    'manual_trigger' => false,
                    'full_sync'      => $full_sync,
                ]
            );
        }
    }

    /**
     * Public method for manual sync (e.g., user clicks "Sync Now" in the admin).
     * Instead of running immediately, we schedule a single event to do it in the background.
     *
     * @param bool $is_cron        - Unused here (just pass false)
     * @param bool $manual_trigger - True if triggered by the user
     * @param bool $full_sync      - True => full sync, false => incremental sync
     */
    public static function run_sync_process($is_cron = false, $manual_trigger = false, $full_sync = false) {
        // We simply schedule a background job to handle the heavy lifting
        wp_schedule_single_event(
            time(),
            'spire_background_sync',
            [
                'is_cron'        => $is_cron,
                'manual_trigger' => $manual_trigger,
                'full_sync'      => $full_sync,
            ]
        );
    }

    /**
     * The background worker that actually runs the sync logic.
     * Prevents multiple overlapping syncs by checking a transient lock.
     *
     * @param bool $is_cron
     * @param bool $manual_trigger
     * @param bool $full_sync
     */
    public static function background_sync_handler($is_cron, $manual_trigger, $full_sync) {
        // Concurrency lock transient to prevent overlapping syncs
        $lock_key = 'spire_sync_in_progress';

        // If a lock exists, bail (TTL may be stored outside options with an object cache)
        if (get_transient($lock_key)) {
            error_log("Spire Sync: Another sync is already running.");
            return;
        }

        // Set transient lock with explicit check
        if (!set_transient($lock_key, true, 15 * 60)) { // 15-minute lock
            error_log("Spire Sync: Failed to set lock transient.");
            return;
        }

        try {
            // Decide between universal ERP sync and classic Spire sync based on adapter mode flag
            $feature_flags = get_option('erp_feature_flags', []);
            $use_adapter = is_array($feature_flags) && !empty($feature_flags['adapter_mode']);
            if ( $use_adapter && class_exists('ERP_Sync_Manager') ) {
                // Use the universal ERP sync manager. This call will early-return
                // if adapter mode is disabled or misconfigured.
                try {
                    ERP_Sync_Manager::run_sync( (bool) $is_cron, (bool) $manual_trigger, (bool) $full_sync );
                } catch ( \Throwable $e2 ) {
                    // Catch exceptions from ERP sync separately to avoid masking them
                    error_log( "ERP Sync Exception (Background Job): " . $e2->getMessage() );
                    if ( function_exists( 'spire_inventory_api_set_notice' ) ) {
                        spire_inventory_api_set_notice( "ERP sync failed: " . $e2->getMessage(), 'error' );
                    }
                }
                // When using adapter mode, skip classic Spire sync entirely
            } else {
                // Perform legacy Spire sync
                self::do_actual_sync( $is_cron, $manual_trigger, $full_sync );
            }
        } catch ( \Throwable $e ) { // catch both Exception and Error from legacy sync
            error_log( "Spire Sync Exception (Background Job): " . $e->getMessage() );
            if ( function_exists( 'spire_inventory_api_set_notice' ) ) {
                spire_inventory_api_set_notice( "Sync failed: " . $e->getMessage(), 'error' );
            }

        } finally {
            // Release concurrency lock
            delete_transient($lock_key);
        }
    }

    /**
     * The "real" sync code (previously inside run_sync_process).
     * Runs through all selected warehouses, calls Spire APIs, upserts products, etc.
     * 
     * @param bool $is_cron
     * @param bool $manual_trigger
     * @param bool $full_sync
     */
    // Spire_Sync_Manager::do_actual_sync  — bulk defers, no global SKU scan
    private static function do_actual_sync($is_cron, $manual_trigger, $full_sync) {
        @set_time_limit(0);

        $selected_wh = get_option('spire_selected_warehouses', []);
        if (empty($selected_wh)) {
            spire_inventory_api_set_notice("No warehouses selected. Please select at least one warehouse.", 'error');
            return;
        }

        // Defer expensive counters during bulk writes
        $prev_term  = wp_defer_term_counting(true);
        $prev_comm  = wp_defer_comment_counting(true);
        $prev_inval = wp_suspend_cache_invalidation(true);
        if (function_exists('wc_defer_transactional_cache_updates')) {
            wc_defer_transactional_cache_updates(true);
        }

        // Track products we actually touched to invalidate caches precisely at the end
        $touched_ids = [];

        // Lock heartbeat setup: refresh every ~60s so lock doesn't expire on long runs
        $lock_key   = 'spire_sync_in_progress';
        $last_touch = time();
        $touch_lock = static function() use (&$last_touch, $lock_key) {
            if (time() - $last_touch > 60) {
                set_transient($lock_key, true, 15 * 60);
                $last_touch = time();
            }
        };

        // Ensure log options exist with autoload = 'no' once per request
        self::ensure_option_no_autoload('spire_sync_log');
        self::ensure_option_no_autoload('spire_schedule_sync_log');

        try {
            $username = trim(Spire_Settings::get_option('username', ''));
            $password = trim(Spire_Settings::get_option('password', ''));
            $base_url = trim(Spire_Settings::get_option('base_url', ''));

            if (!$username || !$password) {
                spire_inventory_api_set_notice("Credentials missing. Please save them first.", 'error');
                return;
            }
            if (empty($base_url)) {
                spire_inventory_api_set_notice("Base URL is missing. Please save it first.", 'error');
                return;
            }

            foreach ($selected_wh as $warehouse_code) {
                $filter_json = wp_json_encode(['whse' => $warehouse_code]);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    spire_inventory_api_set_notice(
                        "JSON encode error for warehouse {$warehouse_code}: " . json_last_error_msg(),
                        'error'
                    );
                    continue;
                }

                // placeholder to keep signature compatibility
                $sku_dict = [];

                $result = self::run_sync_actual_with_utilities(
                    $username, $password, $base_url, $filter_json, $sku_dict, $warehouse_code, $full_sync,
                    $touched_ids, $touch_lock // extras
                );

                if (is_string($result)) {
                    spire_inventory_api_set_notice("Warehouse {$warehouse_code} => Error: {$result}", 'error');
                } else {
                    $msg  = "Warehouse {$warehouse_code} => Sync Complete.<br>";
                    $msg .= "Total Items: {$result['total']}<br>";
                    $msg .= "Created: {$result['created']}<br>";
                    $msg .= "Updated: {$result['updated']}<br>";
                    $msg .= "Skipped: {$result['skipped']}<br>";
                    spire_inventory_api_set_notice($msg, 'success');

                    // Append to logs but bound size
                    $sync_log   = get_option('spire_sync_log', []);
                    $sync_log[] = [
                        'date_time'        => current_time('mysql'),
                        'warehouse'        => $warehouse_code,
                        'total'            => $result['total'],
                        'created'          => $result['created'],
                        'updated'          => $result['updated'],
                        'skipped'          => $result['skipped'],
                        'skipped_details'  => $result['skipped_details'] ?? [],
                        'title'            => "Sync Complete",
                        'triggered_by_cron'=> $is_cron,
                    ];
                    update_option('spire_sync_log', $sync_log);

                    if ($is_cron) {
                        $schedule_log   = get_option('spire_schedule_sync_log', []);
                        $schedule_log[] = [
                            'date_time' => current_time('mysql'),
                            'warehouse' => $warehouse_code,
                            'total'     => $result['total'],
                            'created'   => $result['created'],
                            'updated'   => $result['updated'],
                            'skipped'   => $result['skipped'],
                            'title'     => 'Sync Complete via Scheduled Sync',
                        ];
                        update_option('spire_schedule_sync_log', $schedule_log);
                    }
                }
            }
        } finally {
            if (function_exists('wc_defer_transactional_cache_updates')) {
                wc_defer_transactional_cache_updates(false);
            }
            wp_suspend_cache_invalidation($prev_inval);
            wp_defer_comment_counting($prev_comm);
            wp_defer_term_counting($prev_term);

            // Targeted cache invalidation: only for products we touched
            if (!empty($touched_ids)) {
                $touched_ids = array_unique(array_map('intval', $touched_ids));
                foreach ($touched_ids as $pid) {
                    wc_delete_product_transients($pid);
                }
            }
        }
    }

    private static function ensure_option_no_autoload(string $key): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $key));
        if (!$row) {
            // create with autoload no
            add_option($key, [], '', 'no');
        } elseif ($row->autoload !== 'no') {
            // switch to no (direct update to avoid overwriting value)
            $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $key]);
        }
    }

    private static function run_sync_actual_with_utilities(
        $username, $password, $base_url, $filter_json, &$sku_dict, $warehouse_code, $full_sync,
        array &$touched_ids, callable $touch_lock
    ) {
        $custom_items_endpoint = get_option('spire_items_endpoint', '');
        if (!empty($custom_items_endpoint)) {
            $items_endpoint = filter_var($custom_items_endpoint, FILTER_VALIDATE_URL)
                ? rtrim($custom_items_endpoint, '/')
                : self::build_api_url($base_url, $custom_items_endpoint);
        } else {
            $items_endpoint = self::build_api_url($base_url, Spire_Settings::company_path('inventory/items'));
        }

        $batch_size      = 200;
        $start           = 0;
        $count           = 0;
        $total_count     = 0;
        $created         = 0;
        $updated         = 0;
        $skipped         = 0;
        $skipped_details = [];

        do {
            $batch_url = add_query_arg([
                'filter' => $filter_json,
                'start'  => $start,
                'limit'  => $batch_size,
            ], $items_endpoint);

            $resp   = Spire_API_Client::get($batch_url, $username, $password);
            $parsed = Spire_API_Client::parse_json_response($resp);

            if (is_wp_error($parsed)) {
                $msg = $parsed->get_error_message();
                error_log("Sync Error (Warehouse {$warehouse_code}, Start {$start}): " . $msg);
                return "Error fetching data: " . $msg;
            }

            $code = (int) ($parsed['code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                $raw = isset($parsed['raw']) ? substr((string)$parsed['raw'], 0, 500) : '';
                error_log("Sync HTTP {$code} (Warehouse {$warehouse_code}, Start {$start}) Body: {$raw}");
                return "HTTP {$code} response from Spire.";
            }

            $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            if (!isset($data['records']) || !is_array($data['records'])) {
                error_log("Invalid response structure (Warehouse {$warehouse_code}, Start {$start}): " . print_r($data, true));
                return "Invalid response structure.";
            }

            $records = $data['records'];
            $count   = count($records);
            $total_count += $count;

            if ($count > 0) {
                // Heartbeat lock once per batch start
                $touch_lock();

                // Build a SKU map for THIS batch only
                $batch_skus = [];
                foreach ($records as $r) {
                    if (!empty($r['partNo'])) {
                        $batch_skus[] = sanitize_text_field($r['partNo']);
                    }
                }
                $sku_dict = self::map_existing_products_by_sku($batch_skus); // [sku => post_id]

                // ---- NEW: Pre-ensure attributes/terms for this batch (single pass) ----
                $attr_registry = self::ensure_attributes_terms_for_batch($records);

                foreach ($records as $item) {
                    $item['warehouse_code'] = $warehouse_code;
                    $res = self::upsert_item($item, $sku_dict, $full_sync, $touched_ids, $attr_registry);

                    switch ($res['status']) {
                        case 'created': $created++; break;
                        case 'updated': $updated++; break;
                        case 'skipped':
                            $skipped++;
                            $skipped_details[] = [
                                'sku'     => $res['sku'] ?? 'UnknownSKU',
                                'message' => $res['reason'] ?? 'No reason provided',
                            ];
                            break;
                    }

                    // Heartbeat during long loops
                    $touch_lock();
                }
            }

            $start += $batch_size;
        } while ($count === $batch_size);

        return [
            'total'           => $total_count,
            'created'         => $created,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'skipped_details' => $skipped_details,
        ];
    }

    /**
     * Build a SKU => product_id dictionary for existing WooCommerce products.
     *
     * @return array
     */
    private static function build_sku_dictionary() {
        $sku_dict = [];
    
        // Fetch products and variations
        $args = [
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $products = get_posts($args);
    
        foreach ($products as $pid) {
            $sku = get_post_meta($pid, '_sku', true);
            if (!empty($sku)) {
                $sku_dict[$sku] = $pid;
            }
        }
        return $sku_dict;
    }

    private static function ensure_attributes_terms_for_batch(array $records): array {
        // taxonomy => slug => ['label' => string, 'term_id' => int|null]
        $attr_registry = [];

        // Collect all attribute taxonomies/values from batch
        foreach ($records as $r) {
            if (!empty($r['attributes']) && is_array($r['attributes'])) {
                foreach ($r['attributes'] as $name => $value) {
                    $taxonomy = 'pa_' . sanitize_title($name);
                    $slug     = sanitize_title($value);
                    $label    = (string) $value; // keep human-readable label

                    if (!isset($attr_registry[$taxonomy])) {
                        $attr_registry[$taxonomy] = [];
                    }
                    if (!isset($attr_registry[$taxonomy][$slug])) {
                        $attr_registry[$taxonomy][$slug] = ['label' => $label, 'term_id' => null];
                    }
                }
            }
        }

        if (empty($attr_registry)) return [];

        // Ensure taxonomies exist
        foreach (array_keys($attr_registry) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                wc_create_attribute([
                    'name'         => ucwords(str_replace(['pa-','_','-'], ['',' ',' '], $taxonomy)),
                    'slug'         => substr($taxonomy, 3),
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]);
                register_taxonomy($taxonomy, ['product'], ['hierarchical' => true]);
            }
        }

        // Ensure terms exist and cache IDs
        foreach ($attr_registry as $taxonomy => $terms) {
            foreach ($terms as $slug => $info) {
                if ($info['term_id'] !== null) continue;

                $term = term_exists($slug, $taxonomy);
                if (!$term) {
                    $created = wp_insert_term(
                        $info['label'],     // ← human-readable name (behavior match)
                        $taxonomy,
                        ['slug' => $slug]
                    );
                    $attr_registry[$taxonomy][$slug]['term_id'] = is_wp_error($created) ? 0 : (int)$created['term_id'];
                } else {
                    $attr_registry[$taxonomy][$slug]['term_id'] = (int)(is_array($term) ? $term['term_id'] : $term);
                }
            }
        }

        // Return a simplified map: taxonomy => slug => term_id
        $out = [];
        foreach ($attr_registry as $taxonomy => $terms) {
            foreach ($terms as $slug => $info) {
                $out[$taxonomy][$slug] = $info['term_id'] ?? 0;
            }
        }
        return $out;
    }
    

    /**
     * Actually sync items from Spire for one warehouse, returning stats or an error string.
     *
     * @param string $username       Spire API username.
     * @param string $password       Spire API password.
     * @param string $base_url       Spire API base URL.
     * @param string $filter_json    JSON-encoded filter object (e.g. {'whse':'00'}).
     * @param array  &$sku_dict      Reference to SKU dictionary for quick lookups.
     * @param string $warehouse_code Current warehouse code being processed.
     * @param bool   $full_sync      True => full sync, false => incremental.
     * @return array|string          Array of stats or error message string.
     */
    private static function run_sync_actual($username, $password, $base_url, $filter_json, &$sku_dict, $warehouse_code, $full_sync) {
        // Resolve endpoint (keeps your base/custom logic)
        $custom_items_endpoint = get_option('spire_items_endpoint', '');
        if (!empty($custom_items_endpoint)) {
            $items_endpoint = filter_var($custom_items_endpoint, FILTER_VALIDATE_URL)
                ? rtrim($custom_items_endpoint, '/')
                : self::build_api_url($base_url, $custom_items_endpoint);
        } else {
            $items_endpoint = self::build_api_url($base_url, Spire_Settings::company_path('inventory/items'));
        }

        $batch_size      = 200;
        $start           = 0;
        $count           = 0;
        $total_count     = 0;
        $created         = 0;
        $updated         = 0;
        $skipped         = 0;
        $skipped_details = [];

        do {
            $batch_url = add_query_arg([
                'filter' => $filter_json,
                'start'  => $start,
                'limit'  => $batch_size,
            ], $items_endpoint);

            $resp   = Spire_API_Client::get($batch_url, $username, $password);
            $parsed = Spire_API_Client::parse_json_response($resp);

            if (is_wp_error($parsed)) {
                $msg = $parsed->get_error_message();
                error_log("Sync Error (Warehouse {$warehouse_code}, Start {$start}): " . $msg);
                return "Error fetching data: " . $msg;
            }

            $code = (int) ($parsed['code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                $raw = isset($parsed['raw']) ? substr((string)$parsed['raw'], 0, 500) : '';
                error_log("Sync HTTP {$code} (Warehouse {$warehouse_code}, Start {$start}) Body: {$raw}");
                return "HTTP {$code} response from Spire.";
            }

            $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            if (!isset($data['records']) || !is_array($data['records'])) {
                error_log("Invalid response structure (Warehouse {$warehouse_code}, Start {$start}): " . print_r($data, true));
                return "Invalid response structure.";
            }

            $records = $data['records'];
            $count   = count($records);
            $total_count += $count;

            if ($count > 0) {
                // Build a SKU map for THIS batch only
                $batch_skus = [];
                foreach ($records as $r) {
                    if (!empty($r['partNo'])) {
                        $batch_skus[] = sanitize_text_field($r['partNo']);
                    }
                }
                $sku_dict = self::map_existing_products_by_sku($batch_skus); // [sku => post_id]

                foreach ($records as $item) {
                    $item['warehouse_code'] = $warehouse_code;
                    $res = self::upsert_item($item, $sku_dict, $full_sync);

                    switch ($res['status']) {
                        case 'created': $created++; break;
                        case 'updated': $updated++; break;
                        case 'skipped':
                            $skipped++;
                            $skipped_details[] = [
                                'sku'     => $res['sku'] ?? 'UnknownSKU',
                                'message' => $res['reason'] ?? 'No reason provided',
                            ];
                            break;
                    }
                }
            }

            $start += $batch_size;
        } while ($count === $batch_size);

        return [
            'total'           => $total_count,
            'created'         => $created,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'skipped_details' => $skipped_details,
        ];
    }

    /**
     * Create or update a product based on SKU. Full Sync => always upsert.
     * Incremental => skip if onHandQty/availableQty haven't changed.
     *
     * @param array $item      Spire item data.
     * @param array &$sku_dict Reference to SKU dictionary.
     * @param bool  $full_sync True => full sync, false => incremental.
     * @return array status => created|updated|skipped, reason => skip reason, sku => ...
     */
    // Spire_Sync_Manager::upsert_item — skips no-change writes; minimal work
    private static function upsert_item($item, &$sku_dict, $full_sync, array &$touched_ids = null, array $attr_registry = null) {
        if (!isset($item['id'])) {
            return ['status' => 'skipped', 'reason' => 'No item ID', 'sku' => 'N/A'];
        }

        $spire_id    = (int) $item['id'];
        $sku         = !empty($item['partNo']) ? sanitize_text_field($item['partNo']) : ('spire-' . $spire_id);
        $desc        = isset($item['description']) ? sanitize_text_field($item['description']) : 'No Name';
        $price       = isset($item['pricing']['sellPrice'][0]) ? (float) $item['pricing']['sellPrice'][0] : 0.0;
        $on_hand_qty = isset($item['onHandQty']) ? (float) $item['onHandQty'] : 0.0;
        $attributes  = isset($item['attributes']) ? $item['attributes'] : [];
        $whse_code   = isset($item['warehouse_code']) ? sanitize_text_field($item['warehouse_code']) : '';

        $existing_id = isset($sku_dict[$sku]) ? (int) $sku_dict[$sku] : 0;

        if ($existing_id) {
            $product = wc_get_product($existing_id);
            if (!$product) {
                return ['status'=>'skipped','reason'=>"Product ID {$existing_id} not found",'sku'=>$sku];
            }

            if (!$full_sync) {
                $prev_on_hand = (float) get_post_meta($existing_id, '_spire_item_onHandQty', true);
                if ($prev_on_hand === $on_hand_qty) {
                    return ['status'=>'skipped','reason'=>'No qty change','sku'=>$sku];
                }
            }

            if ($product->is_type('variation')) {
                // pass attr registry; DO NOT change price behavior in create_or_update_variation()
                $vres = self::create_or_update_variation($desc, $price, $on_hand_qty, '', [], 0, $existing_id, $attr_registry ?? []);
                if ($touched_ids !== null && !empty($vres['product_id'])) $touched_ids[] = (int)$vres['product_id'];
                return $vres;
            } else {
                $ures = self::update_product($product, $existing_id, $sku, $desc, $price, $on_hand_qty, $on_hand_qty, $spire_id, $whse_code);
                if ($touched_ids !== null && !empty($ures['product_id'])) $touched_ids[] = (int)$ures['product_id'];
                return $ures;
            }
        }

        // Create path
        if (!empty($attributes)) {
            $parent_id = self::find_parent_product_for_variation($item);
            if (!$parent_id) {
                return ['status' => 'skipped', 'reason' => 'Parent product not found', 'sku' => $sku];
            }
            $vres = self::create_or_update_variation($desc, $price, $on_hand_qty, $sku, $attributes, $parent_id, 0, $attr_registry ?? []);
            if ($vres['status'] === 'created' && !empty($vres['product_id'])) {
                $sku_dict[$sku] = $vres['product_id'];
                if ($touched_ids !== null) $touched_ids[] = (int)$vres['product_id'];
            }
            return $vres;
        }

        $res = self::create_product($sku, $desc, $price, $on_hand_qty, $on_hand_qty, $spire_id, $whse_code);
        if ($res['status'] === 'created' && !empty($res['product_id'])) {
            $sku_dict[$sku] = $res['product_id'];
            if ($touched_ids !== null) $touched_ids[] = (int)$res['product_id'];
        }
        return $res;
    }
    
    private static function find_parent_product_for_variation($item, array $attr_registry = []) {
        static $prepared_parents = []; // parent_id => true

        if (empty($item['basePartNo'])) {
            return 0;
        }

        $base_sku = sanitize_text_field($item['basePartNo']);
        $parent_id = wc_get_product_id_by_sku($base_sku);

        // If parent doesn't exist, create one
        if (!$parent_id) {
            $parent_id = wp_insert_post([
                'post_title'   => sanitize_text_field($item['baseDescription'] ?? $base_sku),
                'post_type'    => 'product',
                'post_status'  => 'publish',
            ]);
            $parent_product = wc_get_product($parent_id);
            $parent_product->set_sku($base_sku);
            $parent_product->set_type('variable');
            $parent_product->save();
            $prepared_parents[$parent_id] = false; // not prepared yet
        }

        // Register attributes on parent only once in this run
        if (empty($prepared_parents[$parent_id])) {
            $parent_product = wc_get_product($parent_id);
            if ($parent_product) {
                $attributes_data = [];

                if (!empty($item['attributes']) && is_array($item['attributes'])) {
                    foreach ($item['attributes'] as $attr_name => $_) {
                        $taxonomy = 'pa_' . sanitize_title($attr_name);
                        if (taxonomy_exists($taxonomy)) {
                            $attributes_data[$taxonomy] = [
                                'name'         => $taxonomy,
                                'value'        => '',
                                'is_visible'   => 1,
                                'is_variation' => 1,
                                'is_taxonomy'  => 1,
                            ];
                        }
                    }
                }

                if (!empty($attributes_data)) {
                    $parent_product->set_attributes($attributes_data);
                    $parent_product->save();
                }
            }
            $prepared_parents[$parent_id] = true;
        }

        return $parent_id;
    }
    
    private static function create_or_update_variation($desc, $price, $qty, $sku = '', $attributes = [], $parent_id = 0, $variation_id = 0, array $attr_registry = []) {
        $created = false;

        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                return ['status' => 'skipped', 'reason' => 'Variation not found', 'sku' => $sku ?: '(unknown)'];
            }
        } else {
            $variation_id = wp_insert_post([
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
            ]);
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                return ['status' => 'skipped', 'reason' => 'Failed to create variation', 'sku' => $sku ?: '(unknown)'];
            }
            $created = true;
        }

        if ($sku) {
            if ($created) {
                $existing = wc_get_product_id_by_sku($sku);
                if ($existing && (int)$existing !== (int)$variation_id) {
                    return ['status' => 'skipped', 'reason' => "SKU {$sku} already in use", 'sku' => $sku];
                }
            }
            $variation->set_sku($sku);
        }

        // ✅ Reverted behavior: set price only if empty (no updates on subsequent syncs)
        if ($variation->get_regular_price() === '') {
            $variation->set_regular_price($price);
        }

        // Stock update (unchanged)
        if ((float)$variation->get_stock_quantity() !== (float)$qty || !$variation->get_manage_stock()) {
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($qty);
            $variation->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        }

        // Use prebuilt registry (pure efficiency, no behavior change)
        if (!empty($attributes)) {
            $var_attributes = [];
            foreach ($attributes as $name => $value) {
                $taxonomy = 'pa_' . sanitize_title($name);
                $slug     = sanitize_title($value);
                if (isset($attr_registry[$taxonomy][$slug])) {
                    $var_attributes[$taxonomy] = $slug;
                }
            }
            if (!empty($var_attributes)) {
                $variation->set_attributes($var_attributes);
            }
        }

        $variation->save();
        update_post_meta($variation_id, '_spire_item_onHandQty', $qty);

        return [
            'status'     => $created ? 'created' : 'updated',
            'sku'        => $variation->get_sku(),
            'product_id' => $variation_id,
        ];
    }

    /**
     * Update an existing WooCommerce product.
     */
    private static function update_product($product, $product_id, $sku, $desc, $price, $on_hand_qty, $available_qty, $spire_id, $whse_code) {
        if (!$product) {
            return ['status'=>'skipped','reason'=>"Product ID {$product_id} not found",'sku'=>$sku];
        }

        // Only write what changes (name/price often stable; keep commented if you want price/name updates)
        // if ($product->get_name() !== $desc) { $product->set_name($desc); }
        // if ((float)$product->get_regular_price() !== (float)$price) { $product->set_regular_price($price); }

        if ((float)$product->get_stock_quantity() !== (float)$on_hand_qty || !$product->get_manage_stock()) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($on_hand_qty);
            $product->set_stock_status($on_hand_qty > 0 ? 'instock' : 'outofstock');
        }

        $product->save();

        // Update metadata
        update_post_meta($product_id, '_spire_item_id', $spire_id);
        update_post_meta($product_id, 'warehouse_code', $whse_code);
        update_post_meta($product_id, '_spire_item_onHandQty', $on_hand_qty);
        update_post_meta($product_id, '_spire_item_availableQty', $available_qty);

        return ['status'=>'updated','sku'=>$sku,'product_id'=>$product_id];
    }

    /**
     * Create a new WooCommerce product.
     */
    private static function create_product($sku, $desc, $price, $on_hand_qty, $available_qty, $spire_id, $whse_code) {
        // Check if SKU already in use
        $dup_id = wc_get_product_id_by_sku($sku);
        if ($dup_id) {
            $suffix = '-spiredup' . rand(1000,9999);
            $tmp_sku = $sku . $suffix;

            $dup_id2 = wc_get_product_id_by_sku($tmp_sku);
            if ($dup_id2) {
                // Even with suffix, it's used => skip
                return [
                    'status'=>'skipped',
                    'reason'=>"SKU {$tmp_sku} also in use",
                    'sku'=>$tmp_sku
                ];
            }
            // Reassign new SKU with random suffix
            $sku = $tmp_sku;
        }

        $post_id = wp_insert_post([
            'post_title'   => $desc,
            'post_content' => '',
            'post_type'    => 'product',
            'post_status'  => 'pending'
        ]);
        if (is_wp_error($post_id)) {
            return [
                'status'=>'skipped',
                'reason'=>"Failed to create: ".$post_id->get_error_message(),
                'sku'=>$sku
            ];
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return [
                'status'=>'skipped',
                'reason'=>"Cannot retrieve new product #{$post_id}",
                'sku'=>$sku
            ];
        }

        $product->set_sku($sku);
        $product->set_name($desc);
        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($on_hand_qty);
        $product->set_stock_status($on_hand_qty > 0 ? 'instock' : 'outofstock');
        $product->save();

        // Update metadata
        update_post_meta($post_id, '_spire_item_id', $spire_id);
        update_post_meta($post_id, 'warehouse_code', $whse_code);
        update_post_meta($post_id, '_spire_item_onHandQty', $on_hand_qty);
        update_post_meta($post_id, '_spire_item_availableQty', $available_qty);

        return ['status'=>'created','sku'=>$sku,'product_id'=>$post_id];
    }

    /**
     * Build a final Spire API URL for older PHP versions (no str_contains).
     */
    public static function build_api_url($base_url, $path='') {
        $base_url = rtrim($base_url, '/');
        if (strpos($base_url, '/api/v2') === false) {
            $base_url .= '/api/v2';
        }
        $base_url = rtrim($base_url, '/');
        $path     = ltrim($path, '/');
        return $base_url.'/'.$path;
    }

    /**
     * Test the API connection by fetching a single record from whse "00".
     */
    public static function test_connection_process() {
        $username = trim(Spire_Settings::get_option('username', ''));
        $password = trim(Spire_Settings::get_option('password', ''));
        $base_url = trim(Spire_Settings::get_option('base_url', ''));

        $insecure = class_exists('Spire_Settings') && method_exists('Spire_Settings', 'get_option')
            ? (bool) Spire_Settings::get_option('allow_insecure', get_option('spire_allow_insecure', false))
            : (bool) get_option('spire_allow_insecure', false);

        if ($insecure) {
            spire_inventory_api_set_notice(
                'DEV mode: SSL verification is disabled. Use a proper hostname + certificate in production.',
                'warning'
            );
        }

        if (!$username || !$password) {
            spire_inventory_api_set_notice('Credentials missing. Please save them first.', 'error');
            return;
        }
        if (empty($base_url)) {
            spire_inventory_api_set_notice('Base URL is missing. Please save it first.', 'error');
            return;
        }

        // Same endpoint resolution as sync (uses $base_url and respects custom override)
        $custom_items_endpoint = get_option('spire_items_endpoint', '');
        if (!empty($custom_items_endpoint)) {
            if (filter_var($custom_items_endpoint, FILTER_VALIDATE_URL)) {
                $endpoint = rtrim($custom_items_endpoint, '/');
            } else {
                $endpoint = self::build_api_url($base_url, $custom_items_endpoint);
            }
        } else {
            $endpoint = self::build_api_url($base_url, Spire_Settings::company_path('inventory/items'));
        }

        // Probe a real warehouse: first selected if present, else "00"
        $probe_wh = '00';
        $selected_wh = get_option('spire_selected_warehouses', []);
        if (is_array($selected_wh) && !empty($selected_wh)) {
            $first = reset($selected_wh);
            if (is_array($first) && isset($first['code'])) {
                $probe_wh = (string) $first['code'];
            } elseif (is_string($first) && $first !== '') {
                $probe_wh = $first;
            }
        }

        $test_url = add_query_arg([
            'filter' => wp_json_encode(['whse' => $probe_wh]),
            'start'  => 0,
            'limit'  => 1,
        ], $endpoint);

        $resp   = Spire_API_Client::get($test_url, $username, $password);
        $parsed = Spire_API_Client::parse_json_response($resp);

        if (is_wp_error($parsed)) {
            spire_inventory_api_set_notice('Connection test failed: '.$parsed->get_error_message(), 'error');
            return;
        }

        $code = (int) ($parsed['code'] ?? 0);
        if ($code !== 200) {
            $path_info = wp_parse_url($test_url, PHP_URL_PATH);
            spire_inventory_api_set_notice('Connection test failed: HTTP '.$code.' (path: '.$path_info.')', 'error');
            return;
        }

        spire_inventory_api_set_notice('Connection test successful (200 OK).', 'success');
    }

    /**
     * Load warehouses from Spire.
     */
    public static function load_warehouses_process() {
        $username = trim(Spire_Settings::get_option('username', ''));
        $password = trim(Spire_Settings::get_option('password', ''));
        $base_url = trim(Spire_Settings::get_option('base_url', ''));

        if (!$username || !$password) {
            spire_inventory_api_set_notice('Credentials missing. Please save them first.', 'error');
            return;
        }
        if (empty($base_url)) {
            spire_inventory_api_set_notice('Base URL is missing. Please save it first.', 'error');
            return;
        }

        $custom_warehouses_endpoint = get_option('spire_warehouses_endpoint', '');
        if (!empty($custom_warehouses_endpoint)) {
            if (filter_var($custom_warehouses_endpoint, FILTER_VALIDATE_URL)) {
                $endpoint = rtrim($custom_warehouses_endpoint, '/');
            } else {
                $endpoint = self::build_api_url($base_url, $custom_warehouses_endpoint);
            }
        } else {
            $endpoint = self::build_api_url($base_url, Spire_Settings::company_path('inventory/warehouses'));
        }

        $resp   = Spire_API_Client::get($endpoint, $username, $password);
        $parsed = Spire_API_Client::parse_json_response($resp);

        if (is_wp_error($parsed)) {
            spire_inventory_api_set_notice('Failed to load warehouses: '.$parsed->get_error_message(), 'error');
            return;
        }

        $code = (int) ($parsed['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $path_info = wp_parse_url($endpoint, PHP_URL_PATH);
            spire_inventory_api_set_notice('Failed to load warehouses: HTTP '.$code.' (path: '.$path_info.')', 'error');
            return;
        }

        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        if (!isset($data['records']) || !is_array($data['records'])) {
            spire_inventory_api_set_notice('Invalid warehouse JSON format.', 'error');
            return;
        }

        $warehouses = [];
        foreach ($data['records'] as $wh) {
            if (isset($wh['code']) && isset($wh['description'])) {
                $warehouses[] = [
                    'code'        => sanitize_text_field($wh['code']),
                    'description' => sanitize_text_field($wh['description']),
                ];
            }
        }

        if (empty($warehouses)) {
            spire_inventory_api_set_notice('No valid warehouses found.', 'warning');
            return;
        }

        update_option('spire_warehouses_list', $warehouses);
        update_option('spire_warehouses_loaded', true);

        spire_inventory_api_set_notice('Warehouses loaded successfully.', 'success');
    }

    // NEW — Spire_Sync_Manager::map_existing_products_by_sku
    private static function map_existing_products_by_sku(array $skus): array {
        global $wpdb;
        $skus = array_values(array_unique(array_filter($skus)));
        if (empty($skus)) return [];

        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $sql = "
            SELECT pm.post_id, pm.meta_value AS sku
            FROM {$wpdb->postmeta} pm
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value IN ($placeholders)
        ";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $skus), ARRAY_A);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['sku']] = (int) $row['post_id'];
        }
        return $map;
    }

}
