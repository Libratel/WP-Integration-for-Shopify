<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Spire fetch/mapping logic for Libratel connector sync.
 */
class Libratel_Connector_Spire_Service {
    private const SPIRE_COMPANY = 'LIBRATEL';
    private const WHSE_CODE = '00';
    private const PRICE_LEVEL_ID = 15;
    private const PAGE_SIZE = 2000;
    private const MAX_PAGES = 500;

    /**
     * Fetch UPC + price data and merge by partNo (SKU).
     *
     * @return array{ok:bool,message:string,data?:array<string,array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch_merged_sku_data(): array {
        $creds = self::credentials();
        if (!$creds['ok']) {
            return $creds;
        }

        $upc_result = self::fetch_upc_map($creds['base_url'], $creds['username'], $creds['password']);
        if (!$upc_result['ok']) {
            return $upc_result;
        }

        $price_result = self::fetch_price_map($creds['base_url'], $creds['username'], $creds['password']);
        if (!$price_result['ok']) {
            return $price_result;
        }

        $upc_map = $upc_result['data'];
        $price_map = $price_result['data'];

        $all_skus = array_values(array_unique(array_merge(array_keys($upc_map), array_keys($price_map))));
        $merged = [];
        foreach ($all_skus as $sku) {
            $merged[$sku] = [
                'sku'   => $sku,
                'upc'   => isset($upc_map[$sku]['upc']) ? (string) $upc_map[$sku]['upc'] : '',
                'price' => isset($price_map[$sku]['price']) ? (string) $price_map[$sku]['price'] : '',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Spire data fetched.',
            'data' => $merged,
            'meta' => [
                'upc_records' => (int) ($upc_result['meta']['records'] ?? 0),
                'price_records' => (int) ($price_result['meta']['records'] ?? 0),
                'upc_duplicates_resolved' => (int) ($upc_result['meta']['duplicates_resolved'] ?? 0),
                'price_duplicates_resolved' => (int) ($price_result['meta']['duplicates_resolved'] ?? 0),
                'total_skus' => count($merged),
            ],
        ];
    }

    /**
     * @return array{ok:bool,message:string,base_url?:string,username?:string,password?:string}
     */
    private static function credentials(): array {
        $username = trim((string) get_option('spire_username', ''));
        $password = trim((string) get_option('spire_password', ''));
        $base_url = trim((string) get_option('spire_base_url', ''));

        if ($username === '' || $password === '' || $base_url === '') {
            return [
                'ok' => false,
                'message' => 'Spire credentials are missing. Save base URL, username, and password first.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'base_url' => $base_url,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * @return array{ok:bool,message:string,data?:array<string,array<string,mixed>>,meta?:array<string,mixed>}
     */
    private static function fetch_upc_map(string $base_url, string $username, string $password): array {
        $endpoint = self::spire_endpoint($base_url, 'inventory/upcs');
        $records_result = self::fetch_records_paginated(
            $endpoint,
            $username,
            $password,
            ['whse' => self::WHSE_CODE]
        );
        if (!$records_result['ok']) {
            return $records_result;
        }

        $map = [];
        $duplicates = 0;

        foreach ($records_result['records'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sku = isset($row['partNo']) ? sanitize_text_field((string) $row['partNo']) : '';
            if ($sku === '') {
                continue;
            }

            $whse = isset($row['whse']) ? (string) $row['whse'] : self::WHSE_CODE;
            if ($whse !== self::WHSE_CODE) {
                continue;
            }

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '') {
                continue;
            }

            $candidate = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'uom' => isset($row['uomCode']) ? (string) $row['uomCode'] : '',
                'upc' => $upc,
            ];

            if (!isset($map[$sku])) {
                $map[$sku] = $candidate;
                continue;
            }

            $duplicates++;
            if (self::is_candidate_better($candidate, $map[$sku])) {
                $map[$sku] = $candidate;
            }
        }

        return [
            'ok' => true,
            'message' => 'Spire UPC map built.',
            'data' => $map,
            'meta' => [
                'records' => count($records_result['records']),
                'duplicates_resolved' => $duplicates,
            ],
        ];
    }

    /**
     * @return array{ok:bool,message:string,data?:array<string,array<string,mixed>>,meta?:array<string,mixed>}
     */
    private static function fetch_price_map(string $base_url, string $username, string $password): array {
        $endpoint = self::spire_endpoint($base_url, 'inventory/sell_prices');
        $records_result = self::fetch_records_paginated(
            $endpoint,
            $username,
            $password,
            [
                'inventory.whse' => self::WHSE_CODE,
                'priceLevel.id' => self::PRICE_LEVEL_ID,
            ]
        );
        if (!$records_result['ok']) {
            return $records_result;
        }

        $map = [];
        $duplicates = 0;

        foreach ($records_result['records'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $inventory = isset($row['inventory']) && is_array($row['inventory']) ? $row['inventory'] : [];
            $sku = isset($inventory['partNo']) ? sanitize_text_field((string) $inventory['partNo']) : '';
            if ($sku === '') {
                continue;
            }

            $whse = isset($inventory['whse']) ? (string) $inventory['whse'] : self::WHSE_CODE;
            if ($whse !== self::WHSE_CODE) {
                continue;
            }

            $price_level = isset($row['priceLevel']) && is_array($row['priceLevel']) ? $row['priceLevel'] : [];
            $price_level_id = isset($price_level['id']) ? (int) $price_level['id'] : 0;
            if ($price_level_id !== self::PRICE_LEVEL_ID) {
                continue;
            }

            $price = isset($row['price']) ? trim((string) $row['price']) : '';
            if ($price === '' || !is_numeric($price)) {
                continue;
            }

            $uom = '';
            if (isset($row['unitOfMeasure']) && is_array($row['unitOfMeasure']) && isset($row['unitOfMeasure']['code'])) {
                $uom = (string) $row['unitOfMeasure']['code'];
            }

            $candidate = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'uom' => $uom,
                'price' => self::normalize_price_string($price),
            ];

            if (!isset($map[$sku])) {
                $map[$sku] = $candidate;
                continue;
            }

            $duplicates++;
            if (self::is_candidate_better($candidate, $map[$sku])) {
                $map[$sku] = $candidate;
            }
        }

        return [
            'ok' => true,
            'message' => 'Spire price map built.',
            'data' => $map,
            'meta' => [
                'records' => count($records_result['records']),
                'duplicates_resolved' => $duplicates,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filter
     * @return array{ok:bool,message:string,records?:array<int,mixed>}
     */
    private static function fetch_records_paginated(string $endpoint, string $username, string $password, array $filter): array {
        $start = 0;
        $page = 0;
        $all = [];
        $filter_json = wp_json_encode($filter);
        if (!is_string($filter_json)) {
            $filter_json = '{}';
        }

        do {
            $page++;
            if ($page > self::MAX_PAGES) {
                return [
                    'ok' => false,
                    'message' => 'Aborted Spire fetch: page safety limit reached.',
                ];
            }

            $url = add_query_arg(
                [
                    'start' => $start,
                    'limit' => self::PAGE_SIZE,
                    'filter' => $filter_json,
                ],
                $endpoint
            );

            $resp = Spire_API_Client::get($url, $username, $password, ['timeout' => 60, 'retries' => 3]);
            $parsed = Spire_API_Client::parse_json_response($resp);
            if (is_wp_error($parsed)) {
                return [
                    'ok' => false,
                    'message' => 'Spire fetch failed: ' . $parsed->get_error_message(),
                ];
            }

            $code = (int) ($parsed['code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                return [
                    'ok' => false,
                    'message' => 'Spire fetch failed: HTTP ' . $code . '.',
                ];
            }

            $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : [];
            $count = count($records);
            if ($count > 0) {
                foreach ($records as $row) {
                    $all[] = $row;
                }
            }

            $start += self::PAGE_SIZE;
        } while ($count === self::PAGE_SIZE);

        return [
            'ok' => true,
            'message' => 'ok',
            'records' => $all,
        ];
    }

    /**
     * "Better" means:
     * 1) Prefer EA uom
     * 2) If tie, prefer higher numeric id
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $existing
     */
    private static function is_candidate_better(array $candidate, array $existing): bool {
        $candidate_ea = strtoupper(trim((string) ($candidate['uom'] ?? ''))) === 'EA' ? 1 : 0;
        $existing_ea = strtoupper(trim((string) ($existing['uom'] ?? ''))) === 'EA' ? 1 : 0;

        if ($candidate_ea !== $existing_ea) {
            return $candidate_ea > $existing_ea;
        }

        $candidate_id = (int) ($candidate['id'] ?? 0);
        $existing_id = (int) ($existing['id'] ?? 0);
        return $candidate_id > $existing_id;
    }

    private static function normalize_price_string(string $price): string {
        return number_format((float) $price, 2, '.', '');
    }

    private static function spire_endpoint(string $base_url, string $suffix): string {
        $path = 'companies/' . self::SPIRE_COMPANY . '/' . ltrim($suffix, '/');
        return Spire_Sync_Manager::build_api_url($base_url, $path);
    }
}
