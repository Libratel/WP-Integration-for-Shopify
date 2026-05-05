<?php
/**
 * Custom ERP Adapter (skeleton)
 *
 * This adapter serves as a template for building integrations
 * to arbitrary/custom ERP systems. It implements the
 * ERP_Adapter_Interface but returns static responses by default.
 * Developers can clone this adapter and modify the methods
 * accordingly to communicate with their own ERP. Update the
 * capabilities array to reflect supported features.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Custom_Adapter implements ERP_Adapter_Interface {
    public function get_key(): string {
        return 'custom';
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
            'message' => 'Custom adapter not implemented',
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
            'message' => 'Custom order post not implemented',
        ];
    }

    public function rate_limit_info(): array {
        return [];
    }
}