<?php
/**
 * ERP Sync Manager
 *
 * This orchestrator coordinates synchronizing products from an arbitrary ERP
 * provider into WooCommerce. It delegates provider-specific operations to
 * adapters implementing ERP_Adapter_Interface and uses ERP_Mapping_Engine
 * to normalize records. Only simple products are handled at this time.
 * Variations and other advanced features continue to be managed by the
 * legacy Spire-specific sync logic until universal mapping stabilizes.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ERP_Sync_Manager {

    /**
     * Perform a full or incremental sync using the configured adapter and
     * mapping. This method mirrors the behaviour of Spire_Sync_Manager but
     * delegates data retrieval to the adapter and record transformation to
     * the mapping engine. It respects selected warehouses from the existing
     * settings. Logs are stored in the same sync log options used by the
     * legacy system.
     *
     * @param bool $is_cron        Whether invoked by the cron scheduler
     * @param bool $manual_trigger Whether triggered manually by an admin
     * @param bool $full_sync      Full vs incremental mode (ignored for now)
     */
    public static function run_sync(bool $is_cron, bool $manual_trigger, bool $full_sync): void {
        // Fetch feature flags to decide if adapter mode is enabled
        $feature_flags = get_option('erp_feature_flags', []);
        if (!is_array($feature_flags)) {
            $feature_flags = [];
        }
        // Do nothing if adapter mode is not enabled
        if (empty($feature_flags['adapter_mode'])) {
            return;
        }

        // Determine provider key and load adapter
        $provider_key = get_option('erp_provider', 'spire');
        /** @var ERP_Adapter_Interface|null $adapter */
        $adapter = ERP_Provider_Registry::get_provider($provider_key);
        if (!$adapter) {
            spire_inventory_api_set_notice('Unknown ERP provider: ' . esc_html($provider_key), 'error');
            return;
        }

        // Build context from provider-specific options
        $ctx = new ERP_Context();
        if ($provider_key === 'spire') {
            $ctx->base_url = trim(Spire_Settings::get_option('base_url', ''));
            $ctx->username = trim(Spire_Settings::get_option('username', ''));
            $ctx->password = trim(Spire_Settings::get_option('password', ''));
        } else {
            // Look for generic options: {provider}_base_url, {provider}_username, {provider}_password
            $ctx->base_url = trim(get_option($provider_key . '_base_url', ''));
            $ctx->username = trim(get_option($provider_key . '_username', ''));
            $ctx->password = trim(get_option($provider_key . '_password', ''));
        }
        // Load mapping spec from option and decode
        $mapping_json = get_option('erp_mappings', '');
        $mapping_spec = json_decode($mapping_json, true);
        if (!is_array($mapping_spec)) {
            $mapping_spec = [];
        }
        $ctx->mapping = $mapping_spec;

        // Selected warehouses (re-use Spire setting for now)
        $selected_wh = get_option('spire_selected_warehouses', []);
        if (empty($selected_wh) || !is_array($selected_wh)) {
            spire_inventory_api_set_notice('No warehouses selected for ERP sync.', 'error');
            return;
        }

        // Defer expensive counters during bulk writes (same as Spire)
        $prev_term  = wp_defer_term_counting(true);
        $prev_comm  = wp_defer_comment_counting(true);
        $prev_inval = wp_suspend_cache_invalidation(true);
        if (function_exists('wc_defer_transactional_cache_updates')) {
            wc_defer_transactional_cache_updates(true);
        }

        $touched_ids = [];
        $total_items = 0;
        $created_count = 0;
        $updated_count = 0;
        $skipped_count = 0;
        $skipped_details = [];

        try {
            foreach ($selected_wh as $warehouse_code) {
                $start = 0;
                $limit = 200;
                do {
                    $page = $adapter->fetch_items($ctx, $start, $limit, ['warehouse' => $warehouse_code]);
                    $records = $page['records'] ?? [];
                    foreach ($records as $raw) {
                        // Apply mapping; expect product mapping under 'product'
                        $product_map = $mapping_spec['product'] ?? [];
                        if (empty($product_map)) {
                            // If no product mapping defined, skip record
                            $skipped_count++;
                            $skipped_details[] = [
                                'sku' => '',
                                'message' => 'No product mapping defined',
                            ];
                            continue;
                        }
                        $data = ERP_Mapping_Engine::apply_mapping($raw, $product_map);
                        if (empty($data['sku'])) {
                            $skipped_count++;
                            $skipped_details[] = [
                                'sku' => '',
                                'message' => 'Mapped SKU missing',
                            ];
                            continue;
                        }
                        $upsert_res = self::upsert_product($data, $full_sync);
                        switch ($upsert_res['status']) {
                            case 'created':
                                $created_count++;
                                if (!empty($upsert_res['product_id'])) {
                                    $touched_ids[] = (int) $upsert_res['product_id'];
                                }
                                break;
                            case 'updated':
                                $updated_count++;
                                if (!empty($upsert_res['product_id'])) {
                                    $touched_ids[] = (int) $upsert_res['product_id'];
                                }
                                break;
                            case 'skipped':
                            default:
                                $skipped_count++;
                                $skipped_details[] = [
                                    'sku' => $upsert_res['sku'] ?? '',
                                    'message' => $upsert_res['reason'] ?? 'Skipped',
                                ];
                                break;
                        }
                        $total_items++;
                    }
                    $start = $page['next_start'] ?? 0;
                } while (!empty($page['has_more']));

                // Log per warehouse
                $sync_log = get_option('spire_sync_log', []);
                $sync_log[] = [
                    'date_time'        => current_time('mysql'),
                    'warehouse'        => $warehouse_code,
                    'total'            => $total_items,
                    'created'          => $created_count,
                    'updated'          => $updated_count,
                    'skipped'          => $skipped_count,
                    'skipped_details'  => $skipped_details,
                    'title'            => 'ERP Sync Complete',
                    'triggered_by_cron'=> $is_cron,
                ];
                update_option('spire_sync_log', $sync_log);
            }
        } finally {
            if (function_exists('wc_defer_transactional_cache_updates')) {
                wc_defer_transactional_cache_updates(false);
            }
            wp_suspend_cache_invalidation($prev_inval);
            wp_defer_comment_counting($prev_comm);
            wp_defer_term_counting($prev_term);
            // Invalidate caches for touched products
            if (!empty($touched_ids)) {
                $touched_ids = array_unique(array_map('intval', $touched_ids));
                foreach ($touched_ids as $pid) {
                    wc_delete_product_transients($pid);
                }
            }
        }

        // Emit notice summarising the sync
        $msg  = 'ERP Sync Complete.<br />';
        $msg .= 'Total Items: ' . $total_items . '<br />';
        $msg .= 'Created: ' . $created_count . '<br />';
        $msg .= 'Updated: ' . $updated_count . '<br />';
        $msg .= 'Skipped: ' . $skipped_count;
        spire_inventory_api_set_notice($msg, 'success');
    }

    /**
     * Upsert a simple product into WooCommerce using the mapped data. Only
     * handles simple products; attribute support is not yet implemented.
     *
     * @param array $data Mapped product data (expects keys: sku, name, price, qty)
     * @param bool $full_sync Whether to force update all fields (ignored)
     * @return array Status array with keys 'status', 'sku', 'product_id', 'reason'
     */
    private static function upsert_product(array $data, bool $full_sync): array {
        $sku   = isset($data['sku']) ? (string) $data['sku'] : '';
        $name  = isset($data['name']) ? (string) $data['name'] : $sku;
        $price = isset($data['price']) ? (float) $data['price'] : 0.0;
        $qty   = isset($data['qty']) ? (float) $data['qty'] : 0.0;

        if ($sku === '') {
            return ['status' => 'skipped', 'reason' => 'Empty SKU'];
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                return ['status' => 'skipped', 'reason' => 'Product not found', 'sku' => $sku];
            }
            // Only update stock; do not overwrite name or price unless full_sync
            if ((float) $product->get_stock_quantity() !== $qty || !$product->get_manage_stock()) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($qty);
                $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
                if ($full_sync) {
                    $product->set_name($name);
                    $product->set_regular_price($price);
                }
                $product->save();
            }
            return ['status' => 'updated', 'sku' => $sku, 'product_id' => $product_id];
        }
        // Create new product
        $post_id = wp_insert_post([
            'post_title'   => $name,
            'post_content' => '',
            'post_type'    => 'product',
            'post_status'  => 'pending',
        ]);
        if (is_wp_error($post_id)) {
            return ['status' => 'skipped', 'reason' => 'Failed to create product: ' . $post_id->get_error_message(), 'sku' => $sku];
        }
        $product = wc_get_product($post_id);
        if (!$product) {
            return ['status' => 'skipped', 'reason' => 'Could not load new product', 'sku' => $sku];
        }
        $product->set_sku($sku);
        $product->set_name($name);
        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $product->save();
        return ['status' => 'created', 'sku' => $sku, 'product_id' => $post_id];
    }
}