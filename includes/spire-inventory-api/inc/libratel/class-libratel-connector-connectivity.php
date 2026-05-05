<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connectivity checks for Libratel connector dependencies.
 */
class Libratel_Connector_Connectivity {
    private const SPIRE_COMPANY = 'LIBRATEL';
    private const SPIRE_WHSE    = '00';

    /**
     * Probe Spire UPC endpoint used by this connector.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test_spire_connection(): array {
        $username = trim((string) get_option('spire_username', ''));
        $password = trim((string) get_option('spire_password', ''));
        $base_url = trim((string) get_option('spire_base_url', ''));

        if ($username === '' || $password === '' || $base_url === '') {
            return [
                'ok' => false,
                'message' => 'Spire credentials are missing. Save base URL, username, and password first.',
            ];
        }

        $endpoint = self::spire_endpoint($base_url, 'inventory/upcs');
        $url = add_query_arg(
            [
                'start'  => 0,
                'limit'  => 1,
                'filter' => wp_json_encode(['whse' => self::SPIRE_WHSE]),
            ],
            $endpoint
        );

        $resp = Spire_API_Client::get($url, $username, $password, ['timeout' => 20, 'retries' => 1]);
        $parsed = Spire_API_Client::parse_json_response($resp);

        if (is_wp_error($parsed)) {
            return [
                'ok' => false,
                'message' => 'Spire connection failed: ' . $parsed->get_error_message(),
            ];
        }

        $code = (int) ($parsed['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'message' => 'Spire connection failed: HTTP ' . $code . '.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Spire connection successful. Libratel UPC endpoint is reachable.',
        ];
    }

    /**
     * Probe Shopify Admin API using:
     * - Client ID + Client Secret (preferred), or
     * - static token fallback.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test_shopify_connection(): array {
        return Libratel_Connector_Shopify_Service::test_connection();
    }

    /**
     * Build a Spire endpoint rooted in /api/v2/companies/LIBRATEL/.
     */
    private static function spire_endpoint(string $base_url, string $suffix): string {
        $path = 'companies/' . self::SPIRE_COMPANY . '/' . ltrim($suffix, '/');
        return Spire_Sync_Manager::build_api_url($base_url, $path);
    }
}
