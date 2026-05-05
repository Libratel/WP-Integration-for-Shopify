<?php
/**
 * ERP Adapter Interface
 *
 * This interface defines the contract that all ERP adapters must implement. It
 * enables the plugin to communicate with multiple backends (e.g. Spire,
 * Dynamics, Sage 300, Odoo) in a uniform manner. Each adapter is responsible
 * for handling authentication, pagination, and any protocol details unique to
 * its provider. The sync orchestrator will call into these methods to
 * retrieve data from the ERP and write back orders as needed. New adapters
 * should implement this interface and register themselves via the
 * ERP_Provider_Registry.
 *
 * Methods return raw provider records; downstream normalization and mapping
 * happens via the ERP_Mapping_Engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface ERP_Adapter_Interface {

    /**
     * Return a unique key identifying this provider (e.g. "spire", "dynamics").
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Advertise capabilities supported by this provider. Keys include
     * 'warehouses', 'price_tiers', 'attributes', 'webhooks', 'pagination',
     * 'delta_sync'. Values should be boolean. Unknown keys should be
     * considered false.
     *
     * @return array<string,bool>
     */
    public function get_capabilities(): array;

    /**
     * Test the connection using the provided context. Should return an
     * associative array with keys 'ok' (bool) and 'message' (string) describing
     * the connection state. Adapters should honour any SSL settings and
     * timeouts configured via the context.
     *
     * @param ERP_Context $ctx
     * @return array{ok:bool,message:string}
     */
    public function test_connection(ERP_Context $ctx): array;

    /**
     * List all warehouses. Should return an array of associative arrays
     * containing at minimum 'code' and 'description' keys. If the provider
     * does not support warehouses, return an empty array.
     *
     * @param ERP_Context $ctx
     * @return array<int,array<string,string>>
     */
    public function list_warehouses(ERP_Context $ctx): array;

    /**
     * Fetch a page of items/products from the ERP. The adapter should respect
     * pagination and any provider-specific query parameters. Returned data
     * should mirror the raw format of the provider so that the mapping engine
     * can operate on it. The return value must include a list of records and
     * a boolean flag indicating whether there are more records to fetch. If
     * more records are available, the caller will increment $start accordingly.
     *
     * @param ERP_Context $ctx
     * @param int $start Pagination offset or token (provider-specific)
     * @param int $limit Maximum number of records to return
     * @param array $filters Optional key/value filters to apply (e.g. warehouse)
     * @return array{records:array,has_more:bool,next_start:int}
     */
    public function fetch_items(ERP_Context $ctx, int $start, int $limit, array $filters = []): array;

    /**
     * Fetch a customer record by ID or criteria. This is optional; adapters
     * may return null if unsupported.
     *
     * @param ERP_Context $ctx
     * @param array $criteria
     * @return array|null
     */
    public function fetch_customer(ERP_Context $ctx, array $criteria);

    /**
     * Create or update an order in the ERP. The payload should already be
     * mapped to the provider's expected format. Return an array describing
     * success and any external identifiers.
     *
     * @param ERP_Context $ctx
     * @param array $payload
     * @return array{ok:bool,external_id?:string,message?:string}
     */
    public function post_order(ERP_Context $ctx, array $payload): array;

    /**
     * Return provider-specific rate limit information if available. Keys may
     * include 'remaining', 'reset', 'limit'. If unsupported, return an empty
     * array.
     *
     * @return array
     */
    public function rate_limit_info(): array;
}