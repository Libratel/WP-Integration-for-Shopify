<?php
/**
 * Odoo ERP Adapter (skeleton)
 *
 * This adapter is a placeholder for integrating with Odoo. It
 * implements ERP_Adapter_Interface but does not communicate with
 * Odoo out of the box. When implementing a real integration,
 * replace the stubbed methods with JSON‑RPC or REST calls to
 * Odoo's API and adjust the capabilities accordingly.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Odoo_Adapter implements ERP_Adapter_Interface {
    public function get_key(): string {
        return 'odoo';
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
            'message' => 'Odoo adapter not implemented',
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
            'message' => 'Odoo order post not implemented',
        ];
    }

    public function rate_limit_info(): array {
        return [];
    }
}