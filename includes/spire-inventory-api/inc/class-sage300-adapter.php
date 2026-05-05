<?php
/**
 * Sage 300 ERP Adapter (skeleton)
 *
 * This adapter is a placeholder for integrating Sage 300 (formerly
 * Accpac). It implements the ERP_Adapter_Interface but does not
 * communicate with a live Sage 300 API. Replace the stubbed
 * implementations with actual API calls and mapping logic when
 * building a real integration. The capabilities array should be
 * updated to reflect supported features as they are implemented.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Sage300_Adapter implements ERP_Adapter_Interface {
    public function get_key(): string {
        return 'sage300';
    }

    public function get_capabilities(): array {
        return [
            'warehouses'  => false,
            'pagination'  => false,
            'price_tiers' => false,
            'attributes'  => false,
            'webhooks'    => false,
            'delta_sync'  => false,
        ];
    }

    public function test_connection(ERP_Context $ctx): array {
        return [
            'ok' => false,
            'message' => 'Sage 300 adapter not implemented',
        ];
    }

    public function list_warehouses(ERP_Context $ctx): array {
        return [];
    }

    public function fetch_items(ERP_Context $ctx, int $start, int $limit, array $filters = []): array {
        return [
            'records'   => [],
            'has_more'  => false,
            'next_start'=> 0,
        ];
    }

    public function fetch_customer(ERP_Context $ctx, array $criteria) {
        return null;
    }

    public function post_order(ERP_Context $ctx, array $payload): array {
        return [
            'ok' => false,
            'message' => 'Sage 300 order post not implemented',
        ];
    }

    public function rate_limit_info(): array {
        return [];
    }
}