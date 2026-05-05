<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_API_Client
 * Handles API requests to Spire ERP.
 */
class Spire_API_Client {

    private static function auth_headers(string $username, string $password): array {
        return [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            'Accept'        => 'application/json',
            // We’ll add Content-Type only when sending a JSON body
        ];
    }

    // ✅ Single source of truth: read the DEV checkbox consistently
    private static function insecure_requests_enabled(): bool {
        // Prefer settings class; fall back to options. Support legacy key too.
        $val = null;

        if (class_exists('Spire_Settings') && method_exists('Spire_Settings', 'get_option')) {
            $val = Spire_Settings::get_option('allow_insecure', null);
        }
        if ($val === null) {
            $val = get_option('spire_allow_insecure', null); // new key
        }
        if ($val === null) {
            $val = get_option('spire_ssl_no_verify', false); // legacy
        }
        return (bool) $val;
    }

    /**
     * Small helper for backoff with jitter (fractional seconds).
     */
    private static function sleep_with_jitter(float $seconds): void {
        if ($seconds <= 0) {
            return;
        }
        // Add up to ~300ms jitter
        $seconds += mt_rand(0, 300) / 1000;
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * Core requester: returns RAW WP HTTP response array or WP_Error.
     * Retries on 5xx and 429 with exponential backoff + Retry-After.
     * Honors the DEV checkbox to disable SSL verification.
     */
    private static function request(string $method, string $url, string $username, string $password, array $args = []) {
        $default_timeout = ($method === 'GET') ? 60 : 25;
        $retries         = isset($args['retries']) ? (int) $args['retries'] : 3;

        // Honor the checkbox directly
        $sslverify = ! self::insecure_requests_enabled();

        // Base request args
        $req = [
            'method'      => $method,
            'headers'     => self::auth_headers($username, $password),
            'timeout'     => isset($args['timeout']) ? (int) $args['timeout'] : $default_timeout,
            'sslverify'   => $sslverify,
            'redirection' => 3,
            // Let WP defaults handle 'decompress', 'blocking', etc.
        ];

        // Merge caller-provided headers/args (caller wins)
        if (!empty($args['headers']) && is_array($args['headers'])) {
            $req['headers'] = array_merge($req['headers'], $args['headers']);
        }
        foreach (['timeout','redirection','sslverify'] as $k) {
            if (array_key_exists($k, $args)) {
                $req[$k] = $args[$k];
            }
        }

        // Encode JSON body if provided (for POST/PUT/PATCH, etc.)
        if (array_key_exists('body', $args) && $args['body'] !== null) {
            $req['body'] = is_string($args['body']) ? $args['body'] : wp_json_encode($args['body'], JSON_UNESCAPED_SLASHES);
            // Ensure content-type for JSON bodies
            $req['headers']['Content-Type'] = $req['headers']['Content-Type'] ?? 'application/json';
        }

        $attempt = 0;
        do {
            $attempt++;

            $resp = wp_remote_request($url, $req);
            if (is_wp_error($resp)) {
                if ($attempt <= $retries) {
                    self::sleep_with_jitter(min(2 ** $attempt, 8));
                    continue;
                }
                return $resp; // WP_Error out
            }

            $code = (int) wp_remote_retrieve_response_code($resp);

            // Handle 429 with Retry-After header when present
            if ($code === 429 && $attempt <= $retries) {
                // Use the helper to be header-format agnostic
                $ra = wp_remote_retrieve_header($resp, 'retry-after');
                $delay = 0;
                if ($ra !== null && $ra !== '') {
                    if (is_numeric($ra)) {
                        $delay = (int) $ra;
                    } else {
                        // HTTP-date format
                        $ts = strtotime($ra);
                        if ($ts !== false) {
                            $delay = max(0, $ts - time());
                        }
                    }
                }
                self::sleep_with_jitter($delay > 0 ? $delay : min(2 ** $attempt, 10));
                continue;
            }

            // Retry on 5xx
            if ($code >= 500 && $attempt <= $retries) {
                self::sleep_with_jitter(min(2 ** $attempt, 8));
                continue;
            }

            // Done — return RAW for caller to inspect/parse
            return $resp;

        } while ($attempt <= $retries);

        // Shouldn't get here, but return the last response
        return $resp;
    }

    // ✅ Thin wrappers always go through request()
    public static function get(string $url, string $username, string $password, array $args = []) {
        // Harden EVERY GET: ensure any 'filter' with customerNo is normalized to uppercase.
        $url = self::normalize_filter_customer_no_url($url);
        return self::request('GET', $url, $username, $password, $args);
    }

    public static function post(string $url, string $username, string $password, array $payload = [], array $args = []) {
        // Allow caller to pass custom args; we inject JSON body
        $args['body'] = $payload;
        return self::request('POST', $url, $username, $password, $args);
    }

    /**
     * Helper: decode JSON body from a RAW response.
     * Returns ['code'=>int,'data'=>mixed,'raw'=>string] or WP_Error on transport error.
     */
    public static function parse_json_response($resp) {
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $data = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $body;
        }

        return [
            'code' => $code,
            'data' => $data,
            'raw'  => $body,
        ];
    }

    public static function url_with_json_filter(string $endpoint, array $filter): string {
        // Normalize both flat and nested customerNo before encoding
        $norm = static function ($s): string {
            $s = preg_replace('/[\p{C}]+/u', '', (string) $s);
            return strtoupper(trim($s));
        };

        if (isset($filter['customerNo']) && is_string($filter['customerNo'])) {
            $filter['customerNo'] = $norm($filter['customerNo']);
        }
        if (isset($filter['customer']['customerNo']) && is_string($filter['customer']['customerNo'])) {
            $filter['customer']['customerNo'] = $norm($filter['customer']['customerNo']);
        }

        $json = wp_json_encode($filter, JSON_UNESCAPED_SLASHES);
        $sep  = (strpos($endpoint, '?') === false) ? '?' : '&';
        return $endpoint . $sep . 'filter=' . rawurlencode($json);
    }

    private static function normalize_filter_customer_no_url(string $url): string {
        // Use WP's parser when available
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!$parts || empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);
        if (!isset($params['filter'])) {
            return $url;
        }

        $filterRaw = $params['filter'];

        // try JSON decode (already decoded by parse_str), then try urldecode fall-back
        $f = json_decode(is_string($filterRaw) ? $filterRaw : '', true);
        if (!is_array($f)) {
            $f = json_decode(urldecode((string) $filterRaw), true);
        }
        if (!is_array($f)) {
            return $url; // don't touch non-JSON filters
        }

        $changed = false;
        $norm = static function ($s): string {
            $s = preg_replace('/[\p{C}]+/u', '', (string) $s);
            return strtoupper(trim($s));
        };

        // Top-level: {"customerNo": "..."}
        if (isset($f['customerNo']) && is_string($f['customerNo'])) {
            $new = $norm($f['customerNo']);
            if ($new !== $f['customerNo']) {
                $f['customerNo'] = $new;
                $changed = true;
            }
        }

        // Nested: {"customer": {"customerNo": "..."}}
        if (isset($f['customer']['customerNo']) && is_string($f['customer']['customerNo'])) {
            $new = $norm($f['customer']['customerNo']);
            if ($new !== $f['customer']['customerNo']) {
                $f['customer']['customerNo'] = $new;
                $changed = true;
            }
        }

        if (!$changed) {
            return $url;
        }

        $json = wp_json_encode($f, JSON_UNESCAPED_SLASHES);

        // Rebuild query: keep other params, but control encoding of "filter"
        unset($params['filter']);
        $rest = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $newQuery = 'filter=' . rawurlencode($json) . ($rest !== '' ? '&' . $rest : '');

        // Rebuild URL
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user   = $parts['user'] ?? null;
        $pass   = $parts['pass'] ?? null;
        $auth   = $user ? $user . ($pass ? ':' . $pass : '') . '@' : '';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';
        $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . '?' . $newQuery . $frag;
    }
}
