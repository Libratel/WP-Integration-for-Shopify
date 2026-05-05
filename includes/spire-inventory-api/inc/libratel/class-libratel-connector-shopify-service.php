<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shopify API operations used by Libratel connector sync.
 */
class Libratel_Connector_Shopify_Service {
    private const API_VERSION = '2025-10';
    private const RETRIES = 3;
    private const TOKEN_CACHE_PREFIX = 'libratel_shopify_cc_token_';
    private const TOKEN_TTL_BUFFER_SECONDS = 120;
    private const VARIANT_SEARCH_CHUNK_SIZE = 25;
    private const VARIANT_SEARCH_PAGE_SIZE = 100;

    /**
     * Probe Shopify shop endpoint using current auth mode.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test_connection(): array {
        $resp = self::request('GET', '/shop.json');
        if (!$resp['ok']) {
            return [
                'ok' => false,
                'message' => $resp['message'],
            ];
        }

        $data = is_array($resp['data']) ? $resp['data'] : [];
        $shop_name = '';
        if (isset($data['shop']) && is_array($data['shop']) && isset($data['shop']['name'])) {
            $shop_name = (string) $data['shop']['name'];
        }

        if ($shop_name !== '') {
            return [
                'ok' => true,
                'message' => 'Shopify connection successful: ' . $shop_name,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Shopify connection successful.',
        ];
    }

    /**
     * @return array{ok:bool,message:string,data?:array<int,array<string,mixed>>}
     */
    public static function find_variants_by_sku(string $sku): array {
        if ($sku === '') {
            return ['ok' => true, 'message' => 'empty sku', 'data' => []];
        }

        $resp = self::request(
            'GET',
            '/variants.json',
            null,
            [
                'limit' => 250,
                'sku'   => $sku,
            ]
        );

        if (!$resp['ok']) {
            return [
                'ok' => false,
                'message' => $resp['message'],
            ];
        }

        $data = is_array($resp['data']) ? $resp['data'] : [];
        $variants = isset($data['variants']) && is_array($data['variants']) ? $data['variants'] : [];

        // Defensive guard in case API ignores sku filter.
        $exact = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (!isset($variant['sku'])) {
                continue;
            }
            if ((string) $variant['sku'] !== $sku) {
                continue;
            }
            $exact[] = $variant;
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'data' => $exact,
        ];
    }

    /**
     * Build a reusable Shopify variant index keyed by exact SKU.
     *
     * @param array<int|string,mixed> $skus
     * @return array{ok:bool,message:string,data?:array<string,array<int,array<string,mixed>>>}
     */
    public static function build_variant_index(array $skus): array {
        $normalized = [];
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $normalized[$sku] = true;
        }

        $target_skus = array_keys($normalized);
        if (empty($target_skus)) {
            return [
                'ok' => true,
                'message' => 'No SKUs supplied for Shopify indexing.',
                'data' => [],
            ];
        }

        $index = [];
        foreach (array_chunk($target_skus, self::VARIANT_SEARCH_CHUNK_SIZE) as $chunk) {
            $chunk_result = self::fetch_variants_for_sku_chunk($chunk);
            if (!$chunk_result['ok']) {
                return $chunk_result;
            }

            $chunk_index = isset($chunk_result['data']) && is_array($chunk_result['data'])
                ? $chunk_result['data']
                : [];

            foreach ($chunk_index as $sku => $variants) {
                if (!isset($index[$sku])) {
                    $index[$sku] = [];
                }
                foreach ($variants as $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    $index[$sku][] = $variant;
                }
            }
        }

        return [
            'ok' => true,
            'message' => 'Shopify variant index built.',
            'data' => $index,
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{ok:bool,message:string}
     */
    public static function update_variant(int $variant_id, array $fields): array {
        if ($variant_id <= 0) {
            return ['ok' => false, 'message' => 'Invalid variant id'];
        }

        if (empty($fields)) {
            return ['ok' => true, 'message' => 'No fields to update'];
        }

        $payload = [
            'variant' => array_merge(['id' => $variant_id], $fields),
        ];

        $resp = self::request(
            'PUT',
            '/variants/' . $variant_id . '.json',
            $payload
        );

        if (!$resp['ok']) {
            return ['ok' => false, 'message' => $resp['message']];
        }

        return ['ok' => true, 'message' => 'ok'];
    }

    /**
     * @return array{ok:bool,message:string,data?:array<string,mixed>,code?:int}
     */
    private static function request(string $method, string $path, ?array $body = null, array $query = []): array {
        $creds = self::credentials();
        if (!$creds['ok']) {
            return ['ok' => false, 'message' => $creds['message']];
        }

        $url = self::build_url($creds['domain'], $path, $query);
        $args = [
            'method'      => $method,
            'timeout'     => 30,
            'redirection' => 2,
            'headers'     => [
                'X-Shopify-Access-Token' => $creds['token'],
                'Accept'                 => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $attempt = 0;
        do {
            $attempt++;
            $resp = wp_remote_request($url, $args);
            if (is_wp_error($resp)) {
                if ($attempt <= self::RETRIES) {
                    self::sleep_with_backoff($attempt);
                    continue;
                }
                return ['ok' => false, 'message' => 'Shopify request failed: ' . $resp->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $raw = (string) wp_remote_retrieve_body($resp);
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];

            if (($code === 429 || $code >= 500) && $attempt <= self::RETRIES) {
                $retry_after = wp_remote_retrieve_header($resp, 'retry-after');
                if ($retry_after !== '' && is_numeric($retry_after)) {
                    sleep(max(1, (int) $retry_after));
                } else {
                    self::sleep_with_backoff($attempt);
                }
                continue;
            }

            if ($code < 200 || $code >= 300) {
                $msg = 'Shopify HTTP ' . $code;
                if (isset($data['errors'])) {
                    if (is_string($data['errors'])) {
                        $msg .= ': ' . $data['errors'];
                    } elseif (is_array($data['errors'])) {
                        $msg .= ': ' . wp_json_encode($data['errors'], JSON_UNESCAPED_SLASHES);
                    }
                }
                return ['ok' => false, 'message' => $msg, 'code' => $code];
            }

            return [
                'ok' => true,
                'message' => 'ok',
                'data' => $data,
                'code' => $code,
            ];
        } while ($attempt <= self::RETRIES);

        return ['ok' => false, 'message' => 'Shopify request failed after retries'];
    }

    /**
     * @param array<int,string> $skus
     * @return array{ok:bool,message:string,data?:array<string,array<int,array<string,mixed>>>}
     */
    private static function fetch_variants_for_sku_chunk(array $skus): array {
        if (empty($skus)) {
            return [
                'ok' => true,
                'message' => 'empty chunk',
                'data' => [],
            ];
        }

        $query = <<<'GQL'
query LibratelVariantIndex($first: Int!, $after: String, $query: String!) {
  productVariants(first: $first, after: $after, query: $query) {
    nodes {
      id
      sku
      barcode
      price
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GQL;

        $wanted = array_fill_keys($skus, true);
        $search = self::build_sku_search_query($skus);
        $cursor = null;
        $index = [];

        do {
            $resp = self::graphql_request($query, [
                'first' => self::VARIANT_SEARCH_PAGE_SIZE,
                'after' => $cursor,
                'query' => $search,
            ]);

            if (!$resp['ok']) {
                return [
                    'ok' => false,
                    'message' => $resp['message'],
                ];
            }

            $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
            $product_variants = isset($data['productVariants']) && is_array($data['productVariants'])
                ? $data['productVariants']
                : [];
            $nodes = isset($product_variants['nodes']) && is_array($product_variants['nodes'])
                ? $product_variants['nodes']
                : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $sku = isset($node['sku']) ? trim((string) $node['sku']) : '';
                if ($sku === '' || !isset($wanted[$sku])) {
                    continue;
                }

                $variant_id = self::variant_gid_to_legacy_id(isset($node['id']) ? (string) $node['id'] : '');
                if ($variant_id <= 0) {
                    continue;
                }

                if (!isset($index[$sku])) {
                    $index[$sku] = [];
                }

                $index[$sku][] = [
                    'id' => $variant_id,
                    'sku' => $sku,
                    'barcode' => isset($node['barcode']) ? (string) $node['barcode'] : '',
                    'price' => isset($node['price']) ? (string) $node['price'] : '',
                ];
            }

            $page_info = isset($product_variants['pageInfo']) && is_array($product_variants['pageInfo'])
                ? $product_variants['pageInfo']
                : [];
            $has_next_page = !empty($page_info['hasNextPage']);
            $cursor = isset($page_info['endCursor']) ? (string) $page_info['endCursor'] : null;
        } while ($has_next_page && $cursor !== null && $cursor !== '');

        return [
            'ok' => true,
            'message' => 'ok',
            'data' => $index,
        ];
    }

    /**
     * @param array<string,mixed> $variables
     * @return array{ok:bool,message:string,data?:array<string,mixed>,code?:int}
     */
    private static function graphql_request(string $query, array $variables = []): array {
        $creds = self::credentials();
        if (!$creds['ok']) {
            return ['ok' => false, 'message' => $creds['message']];
        }

        $url = sprintf('https://%s/admin/api/%s/graphql.json', $creds['domain'], self::API_VERSION);
        $args = [
            'method'      => 'POST',
            'timeout'     => 30,
            'redirection' => 2,
            'headers'     => [
                'X-Shopify-Access-Token' => $creds['token'],
                'Accept'                 => 'application/json',
                'Content-Type'           => 'application/json',
            ],
            'body'        => wp_json_encode([
                'query' => $query,
                'variables' => $variables,
            ], JSON_UNESCAPED_SLASHES),
        ];

        $attempt = 0;
        do {
            $attempt++;
            $resp = wp_remote_request($url, $args);
            if (is_wp_error($resp)) {
                if ($attempt <= self::RETRIES) {
                    self::sleep_with_backoff($attempt);
                    continue;
                }
                return ['ok' => false, 'message' => 'Shopify GraphQL request failed: ' . $resp->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $raw = (string) wp_remote_retrieve_body($resp);
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];

            if (($code === 429 || $code >= 500) && $attempt <= self::RETRIES) {
                $retry_after = wp_remote_retrieve_header($resp, 'retry-after');
                if ($retry_after !== '' && is_numeric($retry_after)) {
                    sleep(max(1, (int) $retry_after));
                } else {
                    self::sleep_with_backoff($attempt);
                }
                continue;
            }

            if ($code < 200 || $code >= 300) {
                return [
                    'ok' => false,
                    'message' => 'Shopify GraphQL HTTP ' . $code,
                    'code' => $code,
                ];
            }

            if (isset($data['errors']) && is_array($data['errors']) && !empty($data['errors'])) {
                return [
                    'ok' => false,
                    'message' => 'Shopify GraphQL errors: ' . wp_json_encode($data['errors'], JSON_UNESCAPED_SLASHES),
                    'code' => $code,
                ];
            }

            return [
                'ok' => true,
                'message' => 'ok',
                'data' => isset($data['data']) && is_array($data['data']) ? $data['data'] : [],
                'code' => $code,
            ];
        } while ($attempt <= self::RETRIES);

        return ['ok' => false, 'message' => 'Shopify GraphQL request failed after retries'];
    }

    /**
     * Auth priority:
     * 1) Client ID + Client Secret (auto token generation + caching)
     * 2) Static access token fallback
     *
     * @return array{ok:bool,message:string,domain?:string,token?:string}
     */
    private static function credentials(): array {
        $domain = trim((string) get_option(Libratel_Connector_Options::OPTION_SHOPIFY_DOMAIN, ''));
        $fallback_token = trim((string) get_option(Libratel_Connector_Options::OPTION_SHOPIFY_TOKEN, ''));
        $client_id = trim((string) get_option(Libratel_Connector_Options::OPTION_SHOPIFY_CLIENT_ID, ''));
        $client_secret = trim((string) get_option(Libratel_Connector_Options::OPTION_SHOPIFY_CLIENT_SECRET, ''));

        if ($domain === '') {
            return [
                'ok' => false,
                'message' => 'Shopify domain is missing. Save connector settings first.',
            ];
        }

        // Preferred mode: client credentials grant.
        if ($client_id !== '' && $client_secret !== '') {
            $token_result = self::get_or_refresh_client_credentials_token($domain, $client_id, $client_secret);
            if ($token_result['ok']) {
                return [
                    'ok' => true,
                    'message' => 'ok',
                    'domain' => $domain,
                    'token' => (string) $token_result['token'],
                ];
            }

            // Soft fallback: if client credentials fail but static token exists, continue with static token.
            if ($fallback_token !== '') {
                return [
                    'ok' => true,
                    'message' => 'ok',
                    'domain' => $domain,
                    'token' => $fallback_token,
                ];
            }

            return [
                'ok' => false,
                'message' => $token_result['message'],
            ];
        }

        if ($fallback_token === '') {
            return [
                'ok' => false,
                'message' => 'Shopify credentials missing. Provide Client ID + Secret or an access token.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'domain' => $domain,
            'token' => $fallback_token,
        ];
    }

    /**
     * @return array{ok:bool,message:string,token?:string}
     */
    private static function get_or_refresh_client_credentials_token(string $domain, string $client_id, string $client_secret): array {
        $cache_key = self::token_cache_key($domain, $client_id);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return [
                'ok' => true,
                'message' => 'ok',
                'token' => $cached,
            ];
        }

        $token_url = sprintf('https://%s/admin/oauth/access_token', $domain);
        $resp = wp_remote_post($token_url, [
            'timeout' => 30,
            'redirection' => 2,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
            ],
        ]);

        if (is_wp_error($resp)) {
            return [
                'ok' => false,
                'message' => 'Shopify token request failed: ' . $resp->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($code < 200 || $code >= 300) {
            $message = 'Shopify token request failed: HTTP ' . $code . '.';
            if (isset($data['error_description']) && is_string($data['error_description'])) {
                $message .= ' ' . $data['error_description'];
            } elseif (isset($data['error']) && is_string($data['error'])) {
                $message .= ' ' . $data['error'];
            }
            return [
                'ok' => false,
                'message' => $message,
            ];
        }

        $token = isset($data['access_token']) ? trim((string) $data['access_token']) : '';
        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'Shopify token request succeeded but no access_token was returned.',
            ];
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 86399;
        $ttl = max(60, $expires_in - self::TOKEN_TTL_BUFFER_SECONDS);
        set_transient($cache_key, $token, $ttl);

        return [
            'ok' => true,
            'message' => 'ok',
            'token' => $token,
        ];
    }

    private static function build_url(string $domain, string $path, array $query = []): string {
        $base = sprintf(
            'https://%s/admin/api/%s/%s',
            $domain,
            self::API_VERSION,
            ltrim($path, '/')
        );

        if (!empty($query)) {
            return add_query_arg($query, $base);
        }
        return $base;
    }

    private static function sleep_with_backoff(int $attempt): void {
        $seconds = min(2 ** $attempt, 8);
        $seconds += mt_rand(0, 300) / 1000;
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * @param array<int,string> $skus
     */
    private static function build_sku_search_query(array $skus): string {
        $terms = [];
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if ($sku === '') {
                continue;
            }
            $escaped = addcslashes($sku, "\\\"");
            $terms[] = 'sku:"' . $escaped . '"';
        }

        return implode(' OR ', $terms);
    }

    private static function variant_gid_to_legacy_id(string $gid): int {
        if (preg_match('#/ProductVariant/(\d+)$#', $gid, $matches)) {
            return (int) $matches[1];
        }

        if (ctype_digit($gid)) {
            return (int) $gid;
        }

        return 0;
    }

    private static function token_cache_key(string $domain, string $client_id): string {
        return self::TOKEN_CACHE_PREFIX . md5(strtolower($domain) . '|' . $client_id);
    }

    public static function clear_cached_client_credentials_token(string $domain, string $client_id): void {
        if ($domain === '' || $client_id === '') {
            return;
        }
        delete_transient(self::token_cache_key($domain, $client_id));
    }
}
