<?php
/**
 * Dynamics ERP Adapter (skeleton)
 *
 * This adapter provides a placeholder implementation for Microsoft
 * Dynamics or Dynamics 365. It implements the ERP_Adapter_Interface
 * but currently returns static responses. When developing a real
 * integration, replace the stubbed methods with appropriate API
 * requests and mapping logic. The capabilities array should reflect
 * supported features once implemented.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Dynamics_Adapter implements ERP_Adapter_Interface {
    public function get_key(): string {
        return 'dynamics';
    }

    public function get_capabilities(): array {
        // Assume no advanced capabilities until implemented
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
        // Always return not implemented. A real implementation should
        // attempt an authenticated request to the Dynamics API.
        return [
            'ok' => false,
            'message' => 'Dynamics adapter not implemented',
        ];
    }

    public function list_warehouses(ERP_Context $ctx): array {
        // Dynamics warehouse listing is not implemented yet
        return [];
    }

    public function fetch_items(ERP_Context $ctx, int $start, int $limit, array $filters = []): array {
        // Return an empty page; no items fetched
        return [
            'records'   => [],
            'has_more'  => false,
            'next_start'=> 0,
        ];
    }

    public function fetch_customer(ERP_Context $ctx, array $criteria) {
        // Not implemented
        return null;
    }

    public function post_order(ERP_Context $ctx, array $payload): array {
        // Not implemented
        return [
            'ok' => false,
            'message' => 'Dynamics order post not implemented',
        ];
    }

    public function rate_limit_info(): array {
        return [];
    }
}