<?php
/**
 * Spire ERP Adapter
 *
 * This adapter implements the ERP_Adapter_Interface using the existing
 * Spire_API_Client to communicate with Spire ERP. It surfaces only the
 * methods needed by the generic sync orchestrator. When adapter mode is
 * disabled (default) the plugin will continue to use its bespoke logic in
 * Spire_Sync_Manager. When enabled, this adapter allows the universal
 * mapping engine to retrieve items and warehouses in a provider-agnostic
 * format.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Spire_Adapter implements ERP_Adapter_Interface {
    public function get_key(): string {
        return 'spire';
    }

    public function get_capabilities(): array {
        return [
            'warehouses' => true,
            'pagination' => true,
            'price_tiers' => false,
            'attributes' => true,
            'webhooks' => false,
            'delta_sync' => false,
        ];
    }

    public function test_connection(ERP_Context $ctx): array {
        // Build the items endpoint and query 1 record to test connectivity
        $companyPath = Spire_Settings::company_path('inventory/items');
        // Use Spire_Sync_Manager::build_api_url to assemble full endpoint
        $endpoint    = Spire_Sync_Manager::build_api_url($ctx->base_url, $companyPath);
        $url         = add_query_arg([
            'filter' => wp_json_encode(['whse' => '00']),
            'start'  => 0,
            'limit'  => 1,
        ], $endpoint);
        $resp   = Spire_API_Client::get($url, $ctx->username, $ctx->password);
        $parsed = Spire_API_Client::parse_json_response($resp);
        if (is_wp_error($parsed)) {
            return [
                'ok' => false,
                'message' => 'Transport error: ' . $parsed->get_error_message(),
            ];
        }
        $code = (int) ($parsed['code'] ?? 0);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'message' => 'Connection successful'];
        }
        return ['ok' => false, 'message' => 'HTTP ' . $code];
    }

    public function list_warehouses(ERP_Context $ctx): array {
        $path = Spire_Settings::company_path('inventory/warehouses');
        $endpoint = Spire_Sync_Manager::build_api_url($ctx->base_url, $path);
        $resp = Spire_API_Client::get($endpoint, $ctx->username, $ctx->password);
        $parsed = Spire_API_Client::parse_json_response($resp);
        if (is_wp_error($parsed) || (int) ($parsed['code'] ?? 0) >= 300) {
            return [];
        }
        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        if (empty($data['records']) || !is_array($data['records'])) {
            return [];
        }
        $result = [];
        foreach ($data['records'] as $rec) {
            if (isset($rec['code']) && isset($rec['description'])) {
                $result[] = [
                    'code' => sanitize_text_field($rec['code']),
                    'description' => sanitize_text_field($rec['description']),
                ];
            }
        }
        return $result;
    }

    public function fetch_items(ERP_Context $ctx, int $start, int $limit, array $filters = []): array {
        // Determine warehouse filter (if provided)
        $whse = $filters['warehouse'] ?? '';
        $filter = [];
        if ($whse !== '') {
            $filter['whse'] = $whse;
        }
        $filter_json = $filter ? wp_json_encode($filter) : '';
        $companyPath = Spire_Settings::company_path('inventory/items');
        $endpoint    = Spire_Sync_Manager::build_api_url($ctx->base_url, $companyPath);
        $args = [
            'start' => $start,
            'limit' => $limit,
        ];
        if ($filter_json !== '') {
            $args['filter'] = $filter_json;
        }
        $url = add_query_arg($args, $endpoint);
        $resp   = Spire_API_Client::get($url, $ctx->username, $ctx->password);
        $parsed = Spire_API_Client::parse_json_response($resp);
        if (is_wp_error($parsed) || (int) ($parsed['code'] ?? 0) >= 300) {
            return ['records' => [], 'has_more' => false, 'next_start' => 0];
        }
        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : [];
        $count = count($records);
        $has_more = $count === $limit;
        $next_start = $start + $limit;
        return ['records' => $records, 'has_more' => $has_more, 'next_start' => $next_start];
    }

    public function fetch_customer(ERP_Context $ctx, array $criteria) {
        // Use Spire_Customer_Mapping to fetch by customerNo if provided
        if (isset($criteria['customerNo'])) {
            $customerNo = $criteria['customerNo'];
            return Spire_Customer_Mapping::get_customer_by_no($customerNo);
        }
        return null;
    }

    public function post_order(ERP_Context $ctx, array $payload): array {
        // Spire Orders endpoint: companies/{company}/sales/orders
        $path = Spire_Settings::company_path('sales/orders');
        $endpoint = Spire_Sync_Manager::build_api_url($ctx->base_url, $path);
        $resp = Spire_API_Client::post($endpoint, $ctx->username, $ctx->password, $payload);
        $parsed = Spire_API_Client::parse_json_response($resp);
        if (is_wp_error($parsed)) {
            return ['ok' => false, 'message' => $parsed->get_error_message()];
        }
        $code = (int) ($parsed['code'] ?? 0);
        if ($code >= 200 && $code < 300) {
            // Orders API returns id in payload? Keep it generic
            return ['ok' => true];
        }
        return ['ok' => false, 'message' => 'HTTP ' . $code];
    }

    public function rate_limit_info(): array {
        // Spire does not expose standard rate-limit headers; return empty
        return [];
    }
}