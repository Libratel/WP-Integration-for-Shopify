<?php
/**
 * ERP Mapping Engine
 *
 * This class converts raw ERP records into a canonical format using a
 * user-defined mapping specification. The mapping is an associative array
 * where keys correspond to WooCommerce fields and values describe how to
 * retrieve and optionally transform the source data. Values may contain a
 * dot-path (e.g. "pricing.sellPrice[0]") and optional transforms separated
 * by the pipe character (e.g. "partNo|uppercase|trim").
 */
if (!defined('ABSPATH')) {
    exit;
}

class ERP_Mapping_Engine {

    /**
     * Apply a mapping definition to a single record. Nested arrays in the
     * mapping spec (e.g. for attributes) will be processed recursively.
     *
     * @param array $record The raw ERP record
     * @param array $mapping The mapping definition
     * @return array The normalized record
     */
    public static function apply_mapping(array $record, array $mapping): array {
        $output = [];
        foreach ($mapping as $targetField => $spec) {
            if (is_array($spec)) {
                $nested = [];
                foreach ($spec as $nestedKey => $nestedSpec) {
                    $nested[$nestedKey] = self::resolve_value($record, (string) $nestedSpec);
                }
                $output[$targetField] = $nested;
            } else {
                $output[$targetField] = self::resolve_value($record, (string) $spec);
            }
        }
        return $output;
    }

    /**
     * Resolve a mapping specification against a record. Supports a dot-path
     * followed by optional transforms separated by the pipe character.
     *
     * @param array $record
     * @param string $spec
     * @return mixed
     */
    private static function resolve_value(array $record, string $spec) {
        $parts = array_map('trim', explode('|', $spec));
        $path = array_shift($parts);
        $value = self::get_path_value($record, $path);
        foreach ($parts as $transform) {
            $value = self::apply_transform($value, $transform);
        }
        return $value;
    }

    /**
     * Retrieve a nested value from an array using dot and bracket notation.
     * Returns null if any part of the path is missing.
     *
     * @param array $record
     * @param string $path
     * @return mixed
     */
    private static function get_path_value(array $record, string $path) {
        // Convert bracket notation to dot notation (e.g. foo[0] -> foo.0)
        $path = preg_replace('/\[(\d+)\]/', '.$1', $path);
        $segments = explode('.', $path);
        $value = $record;
        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }
            if (is_array($value) && array_key_exists($seg, $value)) {
                $value = $value[$seg];
            } else {
                return null;
            }
        }
        return $value;
    }

    /**
     * Apply a simple transform to a value. Supported transforms:
     * - uppercase: uppercase strings
     * - lowercase: lowercase strings
     * - trim: trim whitespace from strings
     * - to_int: cast numeric values to int
 * - round(n): round numeric values to n decimals (default 0)
     * - fallback(value): use value if original is empty/null/zero-length
     *
     * Unknown transforms will be ignored.
     *
     * @param mixed $value
     * @param string $transform
     * @return mixed
     */
    private static function apply_transform($value, string $transform) {
        $transform = trim($transform);
        if ($transform === '') {
            return $value;
        }
        if (preg_match('/^([a-zA-Z_]+)(?:\((.*)\))?$/', $transform, $m)) {
            $name = strtolower($m[1]);
            $args = isset($m[2]) ? $m[2] : '';
        } else {
            return $value;
        }
        switch ($name) {
            case 'uppercase':
                return is_string($value) ? mb_strtoupper($value) : $value;
            case 'lowercase':
                return is_string($value) ? mb_strtolower($value) : $value;
            case 'trim':
                return is_string($value) ? trim($value) : $value;
            case 'to_int':
                return is_numeric($value) ? (int) $value : $value;
            case 'round':
                $precision = is_numeric($args) ? (int) $args : 0;
                return is_numeric($value) ? round((float) $value, $precision) : $value;
            case 'fallback':
                $fallback = $args;
                return ($value === null || $value === '' || $value === []) ? $fallback : $value;

            case 'concat':
                // Concatenate one or more strings to the current value. Accepts a comma‑separated list of arguments.
                // Each argument is appended directly to the original value. Surrounding quotes are stripped.
                $parts = [];
                if ($args !== '') {
                    $rawArgs = preg_split('/,(?=(?:[^\\"]*\\"[^\\"]*\\")*[^\\"]*$)/', $args);
                    if ($rawArgs) {
                        foreach ($rawArgs as $arg) {
                            $arg = trim($arg);
                            if ((substr($arg, 0, 1) === '"' && substr($arg, -1) === '"') || (substr($arg, 0, 1) === "'" && substr($arg, -1) === "'")) {
                                $arg = substr($arg, 1, -1);
                            }
                            $parts[] = $arg;
                        }
                    }
                }
                if (!$parts) {
                    return $value;
                }
                $valStr = (is_array($value) || is_object($value)) ? '' : (string) $value;
                return implode('', $parts) . $valStr;

            case 'map':
                // Map a value to another based on provided key:value pairs. Accepts a JSON-like object {A:"Alpha",B:"Beta"}
                // or a comma‑separated list A:Alpha,B:Beta. If current value matches a key, it is replaced with the mapped value.
                $mapStr = trim($args);
                if ($mapStr === '') {
                    return $value;
                }
                $mappings = [];
                $jsonCandidate = $mapStr;
                if (substr($jsonCandidate, 0, 1) === '{' && substr($jsonCandidate, -1) === '}') {
                    $jsonCandidateFixed = str_replace("'", '"', $jsonCandidate);
                    $decoded = json_decode($jsonCandidateFixed, true);
                    if (is_array($decoded)) {
                        $mappings = $decoded;
                    }
                }
                if (!$mappings) {
                    $pairs = explode(',', $mapStr);
                    foreach ($pairs as $pair) {
                        if (strpos($pair, ':') !== false) {
                            list($k, $v) = explode(':', $pair, 2);
                            $k = trim($k, " \"'{} ");
                            $v = trim($v, " \"'{} ");
                            if ($k !== '') {
                                $mappings[$k] = $v;
                            }
                        }
                    }
                }
                if (is_scalar($value) && isset($mappings[(string) $value])) {
                    return $mappings[(string) $value];
                }
                return $value;
            default:
                return $value;
        }
    }
}