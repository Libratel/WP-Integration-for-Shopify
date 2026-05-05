<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_Plugin_Init
 * Handles the initialization of plugin settings and other dependencies.
 */
class Spire_Plugin_Init {

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        if (is_admin()) {
            add_action('admin_init', [__CLASS__, 'register_settings']);
        }
    }

    /**
     * Call this from your main plugin file:
     * register_activation_hook(__FILE__, ['Spire_Plugin_Init', 'activate']);
     */
    public static function activate() {
        // Precreate sensitive options with autoload = no
        $keys = [
            'spire_username',
            'spire_password',
            'spire_base_url',
            'spire_items_endpoint',
            'spire_orders_endpoint',
            'spire_warehouses_endpoint',
            'spire_company_code',
        ];

        foreach ($keys as $k) {
            if (false === get_option($k, false)) {
                // Value empty, autoload=no
                add_option($k, '', '', 'no');
            } else {
                // Ensure autoload=no even if it already exists
                self::set_option_autoload($k, 'no');
            }
        }

        // Create universal ERP options on activation if absent. Do not alter
        // existing values. Set autoload=no to avoid loading large data on
        // every request.
        $erp_defaults = [
            'erp_provider'    => 'spire',
            'erp_mappings'    => json_encode([
                'product' => [
                    'sku'   => 'partNo',
                    'name'  => 'description',
                    'price' => 'pricing.sellPrice[0]',
                    'qty'   => 'onHandQty',
                    'attributes' => [
                        'Color' => 'attributes.color',
                        'Size'  => 'attributes.size',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
            'erp_feature_flags' => ['adapter_mode' => false, 'universal_mapping' => false],
        ];
        foreach ($erp_defaults as $opt_key => $default_value) {
            if (false === get_option($opt_key, false)) {
                add_option($opt_key, $default_value, '', 'no');
            } else {
                // Ensure autoload=no
                self::set_option_autoload($opt_key, 'no');
            }
        }

        // Create placeholder options for other ERP providers (Dynamics, Sage 300, Odoo, Custom)
        $erp_providers = ['dynamics', 'sage300', 'odoo', 'custom'];
        foreach ($erp_providers as $pkey) {
            $base_key = $pkey . '_base_url';
            $user_key = $pkey . '_username';
            $pass_key = $pkey . '_password';
            foreach ([$base_key, $user_key, $pass_key] as $opt) {
                if (false === get_option($opt, false)) {
                    add_option($opt, '', '', 'no');
                } else {
                    self::set_option_autoload($opt, 'no');
                }
            }
        }
    }

    /**
     * Low-level helper to toggle autoload flag for an existing option.
     */
    private static function set_option_autoload($option_name, $autoload = 'no') {
        global $wpdb;
        $autoload = ($autoload === 'yes') ? 'yes' : 'no';
        $wpdb->update(
            $wpdb->options,
            ['autoload' => $autoload],
            ['option_name' => $option_name],
            ['%s'],
            ['%s']
        );
        // Clear notoptions cache for safety
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Register plugin settings in the WordPress settings API.
     */
    public static function register_settings() {
        $credentials_group = 'spire_inventory_api_credentials_group';
        $group = 'spire_inventory_api_settings_group';
        $page  = 'spire-inventory-api-settings';
        $section = 'spire_inventory_api_main_section';

        // ---- Sanitizers / normalizers ----

        // Normalize a path segment used after base URL:
        // - trim whitespace
        // - remove leading slashes
        // - ensure single trailing slash when non-empty
        $normalize_path = function ($v) {
            if (!is_string($v)) return '';
            $v = trim($v);
            if ($v === '') return '';
            $v = ltrim($v, '/');
            return rtrim($v, '/') . '/';
        };

        // Endpoint override sanitizer:
        // - allows full URL overrides
        // - auto-prefixes relative paths with companies/{company_code}/
        // - preserves legacy values that already start with companies/
        $normalize_endpoint_override = function ($v) use ($normalize_path) {
            if (!is_string($v)) {
                return '';
            }

            $v = trim($v);
            if ($v === '') {
                return '';
            }

            if (filter_var($v, FILTER_VALIDATE_URL)) {
                return rtrim($v, '/');
            }

            $path = $normalize_path($v);
            if ($path === '') {
                return '';
            }

            if (stripos($path, 'companies/') === 0) {
                return $path;
            }

            $posted_company = isset($_POST['spire_company_code'])
                ? sanitize_text_field((string) wp_unslash($_POST['spire_company_code']))
                : '';
            $stored_company = sanitize_text_field((string) get_option('spire_company_code', ''));
            $company        = trim($posted_company !== '' ? $posted_company : $stored_company, '/');
            if ($company === '') {
                return $path;
            }

            return 'companies/' . $company . '/' . ltrim($path, '/');
        };

        // Build a base URL sanitizer with required/optional behavior.
        $make_base_url_sanitizer = function ($option_name, $required = true) {
            return function ($v) use ($option_name, $required) {
                $u = esc_url_raw(trim((string) $v));
                if ($u === '') {
                    if ($required) {
                        add_settings_error($option_name, $option_name . '_required', __('Base URL is required.', 'spire'), 'error');
                        // keep previous to avoid blanking it out accidentally
                        return get_option($option_name, '');
                    }
                    return '';
                }

                // Normalize trailing slash
                $u = rtrim($u, '/');

                // Soft warning on non-HTTPS
                if (stripos($u, 'https://') !== 0) {
                    add_settings_error($option_name, $option_name . '_insecure', __('Warning: Using a non-HTTPS Base URL is insecure.', 'spire'), 'warning');
                }
                return $u;
            };
        };

        // Required text sanitizer for username/password with gentle trimming.
        $sanitize_required_text = function ($option_name) {
            return function ($v) use ($option_name) {
                $v = is_string($v) ? trim($v) : '';
                if ($v === '') {
                    add_settings_error($option_name, $option_name . '_required', sprintf(__('%s is required.', 'spire'), ucfirst(str_replace('spire_', '', $option_name))), 'error');
                    return get_option($option_name, '');
                }
                // Allow special chars for passwords; for username we still accept what users input.
                return $v;
            };
        };

        // ---- Register options with sanitizers ----
        register_setting($credentials_group, 'spire_username', [
            'type'              => 'string',
            'sanitize_callback' => $sanitize_required_text('spire_username'),
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_password', [
            'type'              => 'string',
            'sanitize_callback' => $sanitize_required_text('spire_password'),
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_base_url', [
            'type'              => 'string',
            'sanitize_callback' => $make_base_url_sanitizer('spire_base_url', true),
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_items_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => $normalize_endpoint_override,
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_orders_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => $normalize_endpoint_override,
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_warehouses_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => $normalize_endpoint_override,
            'default'           => '',
        ]);

        register_setting($credentials_group, 'spire_company_code', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
            'show_in_rest'      => false,
        ]);

        // Register universal ERP settings. These are disabled by default and
        // loaded only when the user opts into adapter mode. We use simple
        // sanitizers because validation happens elsewhere.
        register_setting($group, 'erp_provider', [
            'type'              => 'string',
            'sanitize_callback' => function ($v) { return sanitize_text_field($v); },
            'default'           => 'spire',
        ]);

        register_setting($group, 'erp_mappings', [
            'type'              => 'string',
            'sanitize_callback' => function ($v) { return is_string($v) ? $v : ''; },
            'default'           => json_encode([
                'product' => [
                    'sku'   => 'partNo',
                    'name'  => 'description',
                    'price' => 'pricing.sellPrice[0]',
                    'qty'   => 'onHandQty',
                    'attributes' => [
                        'Color' => 'attributes.color',
                        'Size'  => 'attributes.size',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        register_setting($group, 'erp_feature_flags', [
            'type'              => 'array',
            'sanitize_callback' => function ($v) {
                return is_array($v) ? $v : [];
            },
            'default'           => ['adapter_mode' => false, 'universal_mapping' => false],
        ]);

        // Register settings for other ERP provider credentials.
        // Base URL is optional for non-Spire providers.
        $other_providers = ['dynamics', 'sage300', 'odoo', 'custom'];
        foreach ($other_providers as $pkey) {
            // Base URL
            register_setting($group, $pkey . '_base_url', [
                'type'              => 'string',
                'sanitize_callback' => $make_base_url_sanitizer($pkey . '_base_url', false),
                'default'           => '',
            ]);
            // Username
            register_setting($group, $pkey . '_username', [
                'type'              => 'string',
                'sanitize_callback' => function ($v) { return is_string($v) ? sanitize_text_field($v) : ''; },
                'default'           => '',
            ]);
            // Password: allow raw input (trimmed), do not strip special chars
            register_setting($group, $pkey . '_password', [
                'type'              => 'string',
                'sanitize_callback' => function ($v) { return is_string($v) ? trim($v) : ''; },
                'default'           => '',
            ]);
        }

        // ---- Section ----
        add_settings_section(
            $section,
            __('API Credentials', 'spire'),
            [__CLASS__, 'render_main_section'],
            $page
        );

        // ---- Fields (single page/section only) ----
        add_settings_field(
            'spire_company_code',
            __('Company Code', 'spire'),
            function () {
                $val = esc_attr(get_option('spire_company_code', ''));
                echo '<input type="text" name="spire_company_code" value="' . $val . '" class="regular-text" />';
                echo '<p class="description">' . esc_html__('Spire company identifier used in API paths.', 'spire') . '</p>';
            },
            $page,
            $section
        );

        add_settings_field(
            'spire_username',
            __('Username', 'spire'),
            [__CLASS__, 'render_username_field'],
            $page,
            $section
        );

        add_settings_field(
            'spire_password',
            __('Password', 'spire'),
            [__CLASS__, 'render_password_field'],
            $page,
            $section
        );

        add_settings_field(
            'spire_base_url',
            __('Base URL', 'spire'),
            [__CLASS__, 'render_base_url_field'],
            $page,
            $section
        );

        add_settings_field(
            'spire_items_endpoint',
            __('Items Endpoint (override)', 'spire'),
            [__CLASS__, 'render_items_endpoint_field'],
            $page,
            $section
        );

        add_settings_field(
            'spire_orders_endpoint',
            __('Orders Endpoint (override)', 'spire'),
            [__CLASS__, 'render_orders_endpoint_field'],
            $page,
            $section
        );

        add_settings_field(
            'spire_warehouses_endpoint',
            __('Warehouses Endpoint (override)', 'spire'),
            [__CLASS__, 'render_warehouses_endpoint_field'],
            $page,
            $section
        );
    }

    public static function render_main_section() {
        echo '<p>' . wp_kses_post(__('Enter your Spire ERP API credentials below. Example: <code>https://142.127.76.182:10880</code>', 'spire')) . '</p>';
    }

    public static function render_username_field() {
        $username = get_option('spire_username', '');
        echo '<input type="text" name="spire_username" value="' . esc_attr($username) . '" class="regular-text" required />';
    }

    public static function render_password_field() {
        $password = get_option('spire_password', '');
        echo '<input type="password" name="spire_password" value="' . esc_attr($password) . '" class="regular-text" required />';
    }

    public static function render_base_url_field() {
        $base_url = get_option('spire_base_url', '');
        echo '<input type="url" name="spire_base_url" value="' . esc_attr($base_url) . '" class="regular-text" required />';
        echo '<p class="description">' . wp_kses_post(__('Example: <code>https://142.127.76.182:10880</code>', 'spire')) . '</p>';
    }

    private static function endpoint_prefix_for_display() {
        $company = trim(sanitize_text_field((string) get_option('spire_company_code', '')), '/');
        if ($company === '') {
            $company = '{COMPANY_CODE}';
        }
        return 'companies/' . $company . '/';
    }

    private static function endpoint_value_for_display($option_name, $prefix) {
        $saved = trim((string) get_option($option_name, ''));
        if ($saved === '') {
            return '';
        }

        if (filter_var($saved, FILTER_VALIDATE_URL)) {
            return $saved;
        }

        $saved = ltrim($saved, '/');
        if (strpos($saved, $prefix) === 0) {
            return substr($saved, strlen($prefix));
        }

        return $saved;
    }

    private static function render_company_scoped_endpoint_field($option_name, $default_suffix) {
        $prefix = self::endpoint_prefix_for_display();
        $value  = self::endpoint_value_for_display($option_name, $prefix);

        echo '<div class="spire-endpoint-override-wrap" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        echo '<code>' . esc_html($prefix) . '</code>';
        echo '<input type="text" name="' . esc_attr($option_name) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr($default_suffix) . '" />';
        echo '</div>';

        $example = $prefix . $default_suffix;
        echo '<p class="description">' . wp_kses_post(sprintf(__('Example: <code>%s</code> (Leave blank to use the default built from Company Code. Full URL is also accepted.)', 'spire'), esc_html($example))) . '</p>';
    }

    public static function render_items_endpoint_field() {
        self::render_company_scoped_endpoint_field('spire_items_endpoint', 'inventory/items/');
    }

    public static function render_orders_endpoint_field() {
        self::render_company_scoped_endpoint_field('spire_orders_endpoint', 'sales/orders/');
    }

    public static function render_warehouses_endpoint_field() {
        self::render_company_scoped_endpoint_field('spire_warehouses_endpoint', 'inventory/warehouses/');
    }
}
