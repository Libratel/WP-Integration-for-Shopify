<?php
/**
 * ERP Provider Registry
 *
 * This registry holds instances of ERP adapters keyed by their provider
 * identifier. Adapters should register themselves on load (typically via
 * `plugins_loaded` or the adapter's constructor). The registry provides
 * lookup methods so that the sync manager can resolve a provider at
 * runtime based on the current settings. If a provider is not registered,
 * attempts to fetch it will return null.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ERP_Provider_Registry {
    /** @var array<string,ERP_Adapter_Interface> */
    private static $providers = [];

    /**
     * Register an ERP adapter. Subsequent calls with the same key will
     * overwrite the existing adapter.
     *
     * @param ERP_Adapter_Interface $provider
     * @return void
     */
    public static function register_provider(ERP_Adapter_Interface $provider): void {
        self::$providers[$provider->get_key()] = $provider;
    }

    /**
     * Retrieve a registered provider by key.
     *
     * @param string $key
     * @return ERP_Adapter_Interface|null
     */
    public static function get_provider(string $key) {
        return self::$providers[$key] ?? null;
    }

    /**
     * Return all registered providers.
     *
     * @return array<string,ERP_Adapter_Interface>
     */
    public static function all_providers(): array {
        return self::$providers;
    }
}