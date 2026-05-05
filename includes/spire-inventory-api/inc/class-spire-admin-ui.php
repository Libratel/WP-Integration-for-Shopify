<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Spire_Admin_UI
 * Renders the plugin's admin interface in Settings → Spire Inventory API.
 */
class Spire_Admin_UI {

    /**
     * Cache for option values (per-request).
     *
     * @var array
     */
    private static $option_cache = [];

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_form_submissions' ] );
        add_action('admin_enqueue_scripts', function($hook){
            if ($hook !== 'settings_page_spire-inventory-api-settings') return;

            // Ensure jQuery is printed so our inline JS actually runs
            wp_enqueue_script('jquery');

            // Our card/grid styles (create a virtual style handle and add inline CSS to it)
            if (!wp_style_is('spire-admin', 'enqueued')) {
                wp_register_style('spire-admin', false, [], null);
                wp_enqueue_style('spire-admin');
                wp_add_inline_style('spire-admin', '
                    .spire-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
                    @media (max-width:1200px){ .spire-grid { grid-template-columns:1fr; } }
                    .spire-card.postbox { border-radius:10px; overflow:hidden; }
                    .spire-card .hndle { font-size:14px; font-weight:600; padding:12px 16px; }
                    .spire-card .inside { padding:16px; }
                    .spire-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
                    .spire-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; line-height:18px; background:#eef2ff; color:#1e40af; }
                    .spire-badge.ok { background:#ecfdf5; color:#065f46; }
                    .spire-badge.warn { background:#fff7ed; color:#9a3412; }
                    .spire-note { color:#555; margin:6px 0 0; }
                    .spire-actions .button:not(:last-child){ margin-right:6px; }
                    .spire-danger { border-left:4px solid #b91c1c; }
                    .spire-muted { color:#6b7280; }
                ');
            }

            // Submit-preserving + double-submit guard (attach to the printed 'jquery' handle)
            wp_add_inline_script('jquery', '
            jQuery(function($){
                $(document).on("click", "form input[type=submit], form button[type=submit]", function(){
                const f = this.form; if (f) f._lastClickedSubmit = this;
                });
                $(document).on("submit", "form", function(e){
                const f = this;
                if (f.dataset.submitting === "1") { e.preventDefault(); return false; }
                f.dataset.submitting = "1";
                let s = (e.originalEvent && e.originalEvent.submitter) ? e.originalEvent.submitter : f._lastClickedSubmit;
                if (!s) { s = $(f).find("input[type=submit], button[type=submit]").get(0) || null; }
                if (s) {
                    const name = s.getAttribute("name");
                    if (name && !f.querySelector(\'input[type="hidden"][name="\'+name+\'"]\')) {
                    const val = s.getAttribute("value") || "1";
                    const h = document.createElement("input");
                    h.type="hidden"; h.name=name; h.value=val; f.appendChild(h);
                    }
                }
                setTimeout(function(){
                    $(f).find("input[type=submit], button[type=submit]").prop("disabled", true).addClass("disabled");
                }, 0);
                });
            });
            ');
        });

        add_action('admin_notices', function(){
            if (isset($_GET['page']) && $_GET['page'] === 'spire-inventory-api-settings') {
                if (function_exists('spire_inventory_api_print_notices')) {
                spire_inventory_api_print_notices();
                } else {
                settings_errors();
                }
            }
        });
    }

    /**
     * Helper to get options with simple per-request caching.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private static function get_cached_option( $key, $default = [] ) {
        if ( ! isset( self::$option_cache[ $key ] ) ) {
            self::$option_cache[ $key ] = get_option( $key, $default );
        }
        return self::$option_cache[ $key ];
    }

    /**
     * Add Settings Page to WordPress Admin.
     */
    public static function add_settings_page() {
        add_options_page(
            'Spire Inventory API Settings',
            'Spire Inventory API',
            'manage_options',
            'spire-inventory-api-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /**
     * Render the entire settings page with tabs.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';

        $base_url  = admin_url( 'options-general.php' );
        $page_slug = 'spire-inventory-api-settings';

        echo '<div class="wrap">';
        echo '<h1>Spire Inventory API Settings</h1>';

        // Tab navigation (build URLs safely)
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = [
            'general'       => 'General',
            'orders'        => 'Order Settings',
            'sync_log'      => 'Sync Log',
            'schedule_sync' => 'Schedule Sync',
            'erp_settings'  => 'ERP Settings',
        ];
        foreach ( $tabs as $slug => $label ) {
            $url = esc_url( add_query_arg( [ 'page' => $page_slug, 'tab' => $slug ], $base_url ) );
            $cls = ( $active_tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . $url . '" class="' . $cls . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        // Render the active tab
        switch ( $active_tab ) {
            case 'orders':
                self::render_order_settings_tab();
                break;
            case 'sync_log':
                self::render_sync_log_tab();
                break;
            case 'schedule_sync':
                self::render_schedule_sync_tab();
                break;
            case 'erp_settings':
                self::render_erp_settings_tab();
                break;
            case 'general':
            default:
                self::render_general_tab();
                break;
        }

        echo '</div>';
    }

    /**
     * Render the ERP Settings tab. Allows administrators to select the ERP
     * provider, edit the mapping JSON, and toggle feature flags such as
     * adapter mode and universal mapping. Settings are saved via a POST
     * submission handled in handle_form_submissions().
     */
    private static function render_erp_settings_tab() {
        $provider      = get_option('erp_provider', 'spire');
        $mapping_json  = get_option('erp_mappings', '');
        $feature_flags = get_option('erp_feature_flags', []);
        $adapter_mode      = isset($feature_flags['adapter_mode']) ? (bool) $feature_flags['adapter_mode'] : false;
        $universal_mapping = isset($feature_flags['universal_mapping']) ? (bool) $feature_flags['universal_mapping'] : false;

        // ALWAYS post back to this screen, so handlers see the POST
        $action_url = esc_url( add_query_arg(
            ['page' => 'spire-inventory-api-settings', 'tab' => 'erp_settings'],
            admin_url('options-general.php')
        ) );
        ?>
        <h2>ERP Settings</h2>
        <p>Configure universal ERP integration. By default the plugin runs in
        classic Spire mode; enable Adapter Mode to use the universal engine.</p>
        <form method="post" action="<?php echo $action_url; ?>">
            <?php wp_nonce_field('spire_save_erp_settings_nonce', 'spire_save_erp_settings_nonce_field'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="erp_provider">ERP Provider</label></th>
                    <td>
                        <select name="erp_provider" id="erp_provider">
                            <?php
                            $providers = [
                                'spire'    => 'Spire (classic)',
                                'dynamics' => 'Microsoft Dynamics',
                                'sage300'  => 'Sage 300',
                                'odoo'     => 'Odoo',
                                'custom'   => 'Custom',
                            ];
                            foreach ($providers as $key => $label) {
                                $selected = ($provider === $key) ? 'selected' : '';
                                echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description">Select which ERP provider to use for universal sync.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="erp_feature_flags_adapter_mode">Adapter Mode</label></th>
                    <td>
                        <input type="checkbox" name="erp_feature_flags[adapter_mode]" id="erp_feature_flags_adapter_mode" value="1" <?php checked($adapter_mode); ?> />
                        <p class="description">Enable universal adapter mode. When enabled, the plugin will use the selected ERP provider and mapping to sync products.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="erp_feature_flags_universal_mapping">Universal Mapping</label></th>
                    <td>
                        <input type="checkbox" name="erp_feature_flags[universal_mapping]" id="erp_feature_flags_universal_mapping" value="1" <?php checked($universal_mapping); ?> />
                        <p class="description">Enable the mapping engine for the default provider. Currently has no effect.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="erp_mappings">Mapping JSON</label></th>
                    <td>
                        <textarea name="erp_mappings" id="erp_mappings" class="large-text code" rows="10"><?php echo esc_textarea($mapping_json); ?></textarea>
                        <p class="description">JSON mapping definition. The default mapping for Spire is provided on activation. Ensure valid JSON.</p>
                    </td>
                </tr>

                <?php if ($provider && $provider !== 'spire') : ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($provider); ?>_base_url">Provider Base URL</label></th>
                    <td>
                        <?php
                        $provider_base_url = get_option($provider . '_base_url', '');
                        ?>
                        <input type="url" name="<?php echo esc_attr($provider); ?>_base_url" id="<?php echo esc_attr($provider); ?>_base_url" value="<?php echo esc_attr($provider_base_url); ?>" class="regular-text" />
                        <p class="description">Enter the base URL for the selected ERP provider.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($provider); ?>_username">Provider Username</label></th>
                    <td>
                        <?php
                        $provider_username = get_option($provider . '_username', '');
                        ?>
                        <input type="text" name="<?php echo esc_attr($provider); ?>_username" id="<?php echo esc_attr($provider); ?>_username" value="<?php echo esc_attr($provider_username); ?>" class="regular-text" />
                        <p class="description">Username for the selected ERP provider.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($provider); ?>_password">Provider Password</label></th>
                    <td>
                        <?php
                        $provider_password = get_option($provider . '_password', '');
                        ?>
                        <input type="password" name="<?php echo esc_attr($provider); ?>_password" id="<?php echo esc_attr($provider); ?>_password" value="<?php echo esc_attr($provider_password); ?>" class="regular-text" />
                        <p class="description">Password for the selected ERP provider.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <p class="submit">
                <input type="submit" name="spire_save_erp_settings" class="button-primary" value="Save ERP Settings" />
            </p>
        </form>
        <?php
    }

    /**
     * Render the General tab.
     */
    private static function render_general_tab() {
        // status heuristics
        $has_creds = (bool) (get_option('spire_username') && get_option('spire_password') && get_option('spire_base_url'));
        $ssl_no_verify = (bool) get_option('spire_ssl_no_verify', false);
        $default_cust = get_option('spire_default_customer_no', '');
        $warehouses_loaded = (bool) self::get_cached_option('spire_warehouses_loaded', false);
        $warehouses = self::get_cached_option('spire_warehouses_list', []);
        $wh_count = is_array($warehouses) ? count($warehouses) : 0;
        $selected_wh = self::get_cached_option('spire_selected_warehouses', []);
        $selected_wh_count = is_array($selected_wh) ? count($selected_wh) : 0;

        // ALWAYS post back to this screen, so handlers see the POST
        $action_url = esc_url( add_query_arg(
            ['page' => 'spire-inventory-api-settings', 'tab' => 'general'],
            admin_url('options-general.php')
        ) );
        ?>
        <div class="spire-grid">

            <!-- Overview / Status -->
            <div class="postbox spire-card">
                <h2 class="hndle"><span>Overview</span></h2>
                <div class="inside">
                    <div class="spire-row" style="margin-bottom:8px;">
                        <span class="spire-badge <?php echo $has_creds ? 'ok' : 'warn'; ?>">
                            <?php echo $has_creds ? 'Credentials: Configured' : 'Credentials: Missing'; ?>
                        </span>
                        <span class="spire-badge <?php echo $ssl_no_verify ? 'warn' : 'ok'; ?>">
                            SSL Verify: <?php echo $ssl_no_verify ? 'Disabled (DEV)' : 'Enabled'; ?>
                        </span>
                        <span class="spire-badge <?php echo $default_cust ? 'ok' : 'warn'; ?>">
                            Default Customer: <?php echo $default_cust ? esc_html($default_cust) : 'Not set'; ?>
                        </span>
                        <span class="spire-badge <?php echo $warehouses_loaded ? 'ok' : 'warn'; ?>">
                            Warehouses: <?php echo $warehouses_loaded ? "{$selected_wh_count}/{$wh_count} selected" : 'Not loaded'; ?>
                        </span>
                    </div>
                    <p class="spire-note">Quick summary of your Spire integration configuration.</p>
                    <div class="spire-actions" style="margin-top:10px;">
                        <form method="post" action="<?php echo $action_url; ?>" style="display:inline-block;margin-right:6px;">
                            <?php wp_nonce_field('spire_test_connection_nonce', 'spire_test_connection_nonce_field'); ?>
                            <input type="hidden" name="page" value="spire-inventory-api-settings">
                            <input type="hidden" name="tab" value="general">
                            <input type="submit" name="spire_test_connection_submit" class="button button-primary" value="Test Connection">
                        </form>

                        <form method="post" action="<?php echo $action_url; ?>" style="display:inline-block;margin-right:6px;">
                            <?php wp_nonce_field('spire_load_warehouses_nonce', 'spire_load_warehouses_nonce_field'); ?>
                            <input type="hidden" name="page" value="spire-inventory-api-settings">
                            <input type="hidden" name="tab" value="general">
                            <input type="submit" name="spire_load_warehouses_submit" class="button" value="Load Warehouses">
                        </form>

                        <form method="post" action="<?php echo $action_url; ?>" style="display:inline-block;">
                            <?php wp_nonce_field('spire_sync_nonce', 'spire_sync_nonce_field'); ?>
                            <input type="hidden" name="page" value="spire-inventory-api-settings">
                            <input type="hidden" name="tab" value="general">
                            <input type="submit" name="spire_sync_submit" class="button" value="Sync Products Now">
                        </form>
                    </div>
                </div>
            </div>

            <!-- API Credentials (Settings API posts to options.php by design) -->
            <div class="postbox spire-card">
                <h2 class="hndle"><span>API Credentials</span></h2>
                <div class="inside spire-form">
                    <form method="post" action="options.php">
                        <?php
                            settings_fields('spire_inventory_api_credentials_group');
                            do_settings_sections('spire-inventory-api-settings');
                            submit_button('Save Credentials');
                        ?>
                    </form>
                    <p class="spire-note spire-muted">Set base URL, company code, and auth. Then run “Test Connection”.</p>
                </div>
            </div>

            <!-- Security -->
            <div class="postbox spire-card <?php echo $ssl_no_verify ? 'spire-danger' : ''; ?>">
                <h2 class="hndle"><span>Security</span></h2>
                <div class="inside">
                    <p><strong>Not recommended in production.</strong> Disable SSL verification only for IP/self-signed endpoints.</p>
                    <form method="post" action="<?php echo $action_url; ?>">
                        <?php wp_nonce_field('spire_sslverify_nonce', 'spire_sslverify_nonce_field'); ?>
                        <input type="hidden" name="page" value="spire-inventory-api-settings">
                        <input type="hidden" name="tab" value="general">
                        <label>
                            <input type="checkbox" name="spire_ssl_no_verify" value="1" <?php checked($ssl_no_verify); ?>>
                            Allow requests with <code>sslverify = false</code> (DEV only)
                        </label>
                        <br><br>
                        <input type="submit" name="spire_save_sslverify" class="button button-secondary" value="Save Security Setting">
                    </form>
                </div>
            </div>

            <!-- Default Customer Number -->
            <div class="postbox spire-card">
                <h2 class="hndle"><span>Default Customer Number (Fallback)</span></h2>
                <div class="inside">
                    <p>Used when a WooCommerce user/order has no mapped <code>customerNo</code>.</p>
                    <form method="post" action="<?php echo $action_url; ?>" class="spire-row" style="align-items:flex-end;">
                        <?php wp_nonce_field('spire_default_customer_no_nonce', 'spire_default_customer_no_nonce_field'); ?>
                        <input type="hidden" name="page" value="spire-inventory-api-settings">
                        <input type="hidden" name="tab" value="general">
                        <?php $val = esc_attr(get_option('spire_default_customer_no', '')); ?>
                        <label style="display:block;">
                            <span class="spire-muted" style="display:block;margin-bottom:4px;">Customer No</span>
                            <input type="text" name="spire_default_customer_no" value="<?php echo $val; ?>" class="regular-text" />
                        </label>
                        <input type="submit" name="spire_save_default_customer_no" class="button button-primary" value="Save">
                    </form>
                    <p class="spire-note">Allowed: letters, numbers, dot, dash, underscore, slash.</p>
                </div>
            </div>

            <!-- Warehouses -->
            <div class="postbox spire-card" style="<?php echo $warehouses_loaded ? '' : 'opacity:.9;'; ?>">
                <h2 class="hndle"><span>Warehouses</span></h2>
                <div class="inside">
                    <p>After a successful connection test, load available warehouses from Spire and select which to sync.</p>

                    <form method="post" action="<?php echo $action_url; ?>" style="margin-bottom:10px;">
                        <?php wp_nonce_field('spire_load_warehouses_nonce', 'spire_load_warehouses_nonce_field'); ?>
                        <input type="hidden" name="page" value="spire-inventory-api-settings">
                        <input type="hidden" name="tab" value="general">
                        <input type="submit" name="spire_load_warehouses_submit" class="button" value="Reload Warehouses">
                    </form>

                    <?php if ($warehouses_loaded && ! empty($warehouses)) : ?>
                        <form method="post" action="<?php echo $action_url; ?>">
                            <?php wp_nonce_field('spire_save_warehouses_nonce', 'spire_save_warehouses_nonce_field'); ?>
                            <input type="hidden" name="page" value="spire-inventory-api-settings">
                            <input type="hidden" name="tab" value="general">
                            <div style="max-height:240px; overflow:auto; border:1px solid #e5e7eb; padding:10px; border-radius:8px;">
                                <?php
                                $selected_warehouses = self::get_cached_option('spire_selected_warehouses', []);
                                foreach ($warehouses as $wh) {
                                    $code = isset($wh['code']) ? $wh['code'] : '';
                                    $desc = isset($wh['description']) ? $wh['description'] : '';
                                    $code_attr  = esc_attr($code);
                                    $desc_label = esc_html($desc);
                                    $checked = in_array($code, (array) $selected_warehouses, true) ? 'checked' : '';
                                    echo "<label style='display:block;margin-bottom:6px;'>
                                            <input type='checkbox' name='warehouses[]' value='{$code_attr}' {$checked}>
                                            <strong>{$code_attr}</strong> – <span class='spire-muted'>{$desc_label}</span>
                                        </label>";
                                }
                                ?>
                            </div>
                            <br>
                            <input type="submit" name="spire_save_warehouses_submit" class="button button-primary" value="Save Selected Warehouses">
                        </form>
                    <?php else: ?>
                        <p class="spire-note spire-muted">No warehouses loaded yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Manual Ops -->
            <div class="postbox spire-card">
                <h2 class="hndle"><span>Manual Operations</span></h2>
                <div class="inside">
                    <p>Created ⇒ Pending, existing ⇒ Updated. Respects incremental vs. full-sync schedule.</p>
                    <form method="post" action="<?php echo $action_url; ?>" class="spire-actions">
                        <?php wp_nonce_field('spire_sync_nonce', 'spire_sync_nonce_field'); ?>
                        <input type="hidden" name="page" value="spire-inventory-api-settings">
                        <input type="hidden" name="tab" value="general">
                        <input type="submit" name="spire_sync_submit" class="button button-primary" value="Sync Products Now">
                    </form>
                </div>
            </div>

            <!-- Payment Terms -->
            <div class="postbox spire-card">
                <h2 class="hndle"><span>Sync Payment Terms</span></h2>
                <div class="inside">
                    <p>Fetches <code>paymentTerms.description</code> for users with <code>spire_customer_no</code> and updates local <code>spire_payment_term</code>.</p>
                    <form method="post" action="<?php echo $action_url; ?>" class="spire-actions">
                        <?php wp_nonce_field('spire_sync_terms_nonce', 'spire_sync_terms_nonce_field'); ?>
                        <input type="hidden" name="page" value="spire-inventory-api-settings">
                        <input type="hidden" name="tab" value="general">

                        <label style="display:inline-flex;align-items:center;gap:8px;margin-right:12px;">
                            <input type="checkbox" name="force_overwrite_terms" value="1">
                            <span class="spire-muted">Rewrite all existing terms (force overwrite) ⚠️</span>
                        </label>

                        <input type="submit" name="spire_sync_terms_submit" class="button" value="Sync Payment Terms Now">
                    </form>
                    <p class="spire-note">Tip: Use force overwrite when you previously populated a wrong mapping and need to reset all terms from ERP.</p>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Render the Order Settings tab.
     */
    private static function render_order_settings_tab() {
        echo '<h2>Order Settings</h2>';
        echo '<p>Configure how orders are posted to Spire and review any failed attempts.</p>';

        echo '<hr><h3>Users Missing Customer No</h3>';
        self::render_missing_spire_ids();

        echo '<hr><h3>Unsuccessful POST Actions</h3>';
        self::render_failed_orders();
    }

    /**
     * Render the Sync Log tab.
     */
    private static function render_sync_log_tab() {
        echo '<h2>Sync Log</h2>';
        echo '<p>View detailed info of sync operations, including skipped items and reasons.</p>';

        // Handle clearing the log
        if ( isset( $_POST['spire_clear_sync_log'] ) ) {
            check_admin_referer( 'spire_clear_sync_log_nonce', 'spire_clear_sync_log_nonce_field' );
            update_option( 'spire_sync_log', [] );
            echo '<div class="notice notice-success"><p>Sync log cleared.</p></div>';
        }

        $sync_log = get_option( 'spire_sync_log', [] );
        if ( ! is_array( $sync_log ) ) {
            $sync_log = [];
        }

        // Show form to clear log
        echo '<form method="post" style="margin-bottom:15px;">';
        wp_nonce_field( 'spire_clear_sync_log_nonce', 'spire_clear_sync_log_nonce_field' );
        echo '<input type="submit" name="spire_clear_sync_log" class="button button-secondary" value="Clear Log">';
        echo '</form>';

        if ( empty( $sync_log ) ) {
            echo '<p>No sync log entries found.</p>';
            return;
        }

        // Build log output using implode() for better performance.
        $log_entries = [];
        foreach ( array_reverse( $sync_log ) as $entry ) {
            $date_time         = isset( $entry['date_time'] ) ? $entry['date_time'] : 'Unknown Time';
            $warehouse         = isset( $entry['warehouse'] ) ? $entry['warehouse'] : 'N/A';
            $total             = isset( $entry['total'] ) ? $entry['total'] : 0;
            $created           = isset( $entry['created'] ) ? $entry['created'] : 0;
            $updated           = isset( $entry['updated'] ) ? $entry['updated'] : 0;
            $skipped           = isset( $entry['skipped'] ) ? $entry['skipped'] : 0;
            $title             = isset( $entry['title'] ) ? $entry['title'] : 'Sync Complete';
            $triggered_by_cron = isset( $entry['triggered_by_cron'] ) ? $entry['triggered_by_cron'] : false;
            $reasons           = isset( $entry['skipped_details'] ) ? $entry['skipped_details'] : [];

            $log_text  = "===== [{$date_time}] =====\n";
            $log_text .= "Warehouse: {$warehouse}\n";
            $log_text .= "{$title}\n";
            $log_text .= "Total Items: {$total}\nCreated: {$created}\nUpdated: {$updated}\nSkipped: {$skipped}\n";
            if ( ! empty( $reasons ) && is_array( $reasons ) ) {
                $log_text .= "Skipped Details:\n";
                foreach ( $reasons as $skipinfo ) {
                    $sku     = isset( $skipinfo['sku'] ) ? $skipinfo['sku'] : 'Unknown SKU';
                    $message = isset( $skipinfo['message'] ) ? $skipinfo['message'] : 'No reason given';
                    $log_text .= "  - {$sku}: {$message}\n";
                }
            }
            $log_text .= $triggered_by_cron ? "Triggered by: Scheduled Sync\n" : "Triggered by: Manual Sync\n";
            $log_entries[] = $log_text;
        }

        echo '<textarea rows="20" style="width:100%;">' . esc_textarea( implode( "\n", $log_entries ) ) . '</textarea>';
    }

    /**
     * Render the Schedule Sync tab.
     */
    private static function render_schedule_sync_tab() {
        echo '<h2>Schedule Sync</h2>';
        echo '<p>Configure automatic product sync on specific days, times, and intervals.</p>';

        // Handle schedule save
        if ( isset( $_POST['spire_save_schedule_sync'] ) ) {
            check_admin_referer( 'spire_schedule_sync_nonce', 'spire_schedule_sync_nonce_field' );

            $days      = isset( $_POST['days'] ) ? array_map( 'sanitize_text_field', (array) $_POST['days'] ) : [];
            $from_time = isset( $_POST['from_time'] ) ? sanitize_text_field( $_POST['from_time'] ) : '09:00';
            $to_time   = isset( $_POST['to_time'] ) ? sanitize_text_field( $_POST['to_time'] ) : '17:00';
            $every     = isset( $_POST['every'] ) ? max( 1, intval( $_POST['every'] ) ) : 60;
            $full_sync = ! empty( $_POST['full_sync'] );

            $schedule_data = [
                'days'      => $days,
                'from_time' => $from_time,
                'to_time'   => $to_time,
                'every'     => $every,
                'full_sync' => $full_sync,
            ];
            update_option( 'spire_schedule_settings', $schedule_data );

            // Reschedule the sync event.
            if ( method_exists( 'Spire_Sync_Manager', 'schedule_sync_event' ) ) {
                Spire_Sync_Manager::schedule_sync_event();
            }

            echo '<div class="notice notice-success"><p>Schedule sync settings saved.</p></div>';
        }

        // Load existing schedule settings
        $schedule_data = get_option( 'spire_schedule_settings', [
            'days'      => [],
            'from_time' => '09:00',
            'to_time'   => '17:00',
            'every'     => 60,
            'full_sync' => false,
        ] );

        $days      = isset( $schedule_data['days'] ) ? (array) $schedule_data['days'] : [];
        $from_time = isset( $schedule_data['from_time'] ) ? $schedule_data['from_time'] : '09:00';
        $to_time   = isset( $schedule_data['to_time'] ) ? $schedule_data['to_time'] : '17:00';
        $every     = isset( $schedule_data['every'] ) ? (int) $schedule_data['every'] : 60;
        $full_sync = ! empty( $schedule_data['full_sync'] );

        // Handle clearing schedule log
        $schedule_log = get_option( 'spire_schedule_sync_log', [] );
        if ( isset( $_POST['spire_clear_schedule_log'] ) ) {
            check_admin_referer( 'spire_schedule_sync_log_nonce', 'spire_schedule_sync_log_nonce_field' );
            update_option( 'spire_schedule_sync_log', [] );
            echo '<div class="notice notice-success"><p>Schedule sync log cleared.</p></div>';
            $schedule_log = [];
        }

        $days_of_week = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
        ?>
        <form method="post">
            <?php wp_nonce_field( 'spire_schedule_sync_nonce', 'spire_schedule_sync_nonce_field' ); ?>
            <table class="form-table">
                <tr>
                    <th>Days of Week</th>
                    <td>
                        <?php 
                        $days_output = [];
                        foreach ( $days_of_week as $d ) {
                            $checked     = in_array( $d, $days, true ) ? 'checked' : '';
                            $days_output[] = "<label style=\"margin-right:10px;\"><input type=\"checkbox\" name=\"days[]\" value=\"" . esc_attr( $d ) . "\" {$checked}> " . esc_html( $d ) . "</label>";
                        }
                        echo implode( "\n", $days_output );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Time Range</th>
                    <td>
                        <label>From: <input type="time" name="from_time" value="<?php echo esc_attr( $from_time ); ?>" required></label>
                        &nbsp;–&nbsp;
                        <label>To: <input type="time" name="to_time" value="<?php echo esc_attr( $to_time ); ?>" required></label>
                    </td>
                </tr>
                <tr>
                    <th>Every (minutes)</th>
                    <td>
                        <input type="number" name="every" value="<?php echo esc_attr( $every ); ?>" min="1" style="width:100px;" required>
                        <p class="description">Sync will run at this interval (in minutes) when within the time range and days specified.</p>
                    </td>
                </tr>
                <tr>
                    <th>Full Sync?</th>
                    <td>
                        <input type="checkbox" name="full_sync" value="1" <?php checked( $full_sync ); ?>>
                        <p class="description">
                            If checked, the sync process fetches and processes <strong>all items</strong>.  
                            Otherwise, only <strong>modified items</strong> are updated.
                        </p>
                    </td>
                </tr>
            </table>
            <input type="submit" name="spire_save_schedule_sync" class="button button-primary" value="Save Schedule Settings">
        </form>

        <hr>
        <h3>Schedule Sync Log</h3>
        <p>Below is the log of automatic sync operations.</p>
        <form method="post" style="margin-bottom:10px;">
            <?php wp_nonce_field( 'spire_schedule_sync_log_nonce', 'spire_schedule_sync_log_nonce_field' ); ?>
            <input type="submit" name="spire_clear_schedule_log" class="button button-secondary" value="Clear Schedule Log">
        </form>
        <?php
        if ( empty( $schedule_log ) ) {
            echo '<p>No automatic sync log entries found.</p>';
        } else {
            $log_output = [];
            foreach ( array_reverse( $schedule_log ) as $entry ) {
                $date_time = isset( $entry['date_time'] ) ? $entry['date_time'] : 'Unknown';
                $warehouse = isset( $entry['warehouse'] ) ? $entry['warehouse'] : 'N/A';
                $total     = isset( $entry['total'] ) ? $entry['total'] : 0;
                $created   = isset( $entry['created'] ) ? $entry['created'] : 0;
                $updated   = isset( $entry['updated'] ) ? $entry['updated'] : 0;
                $skipped   = isset( $entry['skipped'] ) ? $entry['skipped'] : 0;
                $title     = isset( $entry['title'] ) ? $entry['title'] : 'Sync Complete';

                $log_entry  = "===== [{$date_time}] =====\n";
                $log_entry .= "Warehouse: {$warehouse}\n";
                $log_entry .= "{$title}\n";
                $log_entry .= "Total Items: {$total}\nCreated: {$created}\nUpdated: {$updated}\nSkipped: {$skipped}\n\n";
                $log_output[] = $log_entry;
            }
            echo '<textarea rows="15" style="width:100%;">' . esc_textarea( implode( "\n", $log_output ) ) . '</textarea>';
        }
    }

    /**
     * Handle form submissions across all tabs.
     */
    public static function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Test Connection
        if ( isset( $_POST['spire_test_connection_submit'] ) ) {
            check_admin_referer( 'spire_test_connection_nonce', 'spire_test_connection_nonce_field' );
            // Determine if adapter mode is enabled and which provider is selected
            $feature_flags = get_option( 'erp_feature_flags', [] );
            $adapter_mode = is_array( $feature_flags ) && ! empty( $feature_flags['adapter_mode'] );
            $provider_key = get_option( 'erp_provider', 'spire' );
            if ( $adapter_mode && $provider_key !== 'spire' ) {
                // Use the selected adapter to test connection
                $adapter = null;
                if ( class_exists( 'ERP_Provider_Registry' ) ) {
                    $adapter = ERP_Provider_Registry::get_provider( $provider_key );
                }
                if ( $adapter ) {
                    // Build context from generic options
                    $ctx = new ERP_Context();
                    $ctx->base_url = trim( get_option( $provider_key . '_base_url', '' ) );
                    $ctx->username = trim( get_option( $provider_key . '_username', '' ) );
                    $ctx->password = trim( get_option( $provider_key . '_password', '' ) );
                    $result = $adapter->test_connection( $ctx );
                    if ( isset( $result['ok'] ) && $result['ok'] ) {
                        spire_inventory_api_set_notice( 'Connection successful.', 'success' );
                    } else {
                        $msg = isset( $result['message'] ) ? $result['message'] : 'Connection failed.';
                        spire_inventory_api_set_notice( $msg, 'error' );
                    }
                } else {
                    spire_inventory_api_set_notice( 'Adapter for provider ' . esc_html( $provider_key ) . ' is not available.', 'error' );
                }
            } else {
                // Classic Spire test connection
                if ( method_exists( 'Spire_Sync_Manager', 'test_connection_process' ) ) {
                    Spire_Sync_Manager::test_connection_process();
                }
            }
        }

        if ( isset( $_POST['spire_save_default_customer_no'] ) ) {
            check_admin_referer( 'spire_default_customer_no_nonce', 'spire_default_customer_no_nonce_field' );

            $raw = isset($_POST['spire_default_customer_no']) ? (string) $_POST['spire_default_customer_no'] : '';
            $raw = trim( $raw );
            // Allow alnum, dot, dash, underscore, slash (adjust if ERP allows more)
            $san = preg_replace('/[^A-Za-z0-9._\-\/ ]/', '', $raw);

            update_option( 'spire_default_customer_no', $san );
            // keep per-request cache consistent if you later use get_cached_option for this key
            self::$option_cache['spire_default_customer_no'] = $san;

            spire_inventory_api_set_notice( 'Default Customer Number saved.', 'success' );
        }

        // Load Warehouses
        if ( isset( $_POST['spire_load_warehouses_submit'] ) ) {
            check_admin_referer( 'spire_load_warehouses_nonce', 'spire_load_warehouses_nonce_field' );
            if ( method_exists( 'Spire_Sync_Manager', 'load_warehouses_process' ) ) {
                Spire_Sync_Manager::load_warehouses_process();
            }
            // Invalidate cache so UI reflects new data on same request.
            self::$option_cache = [];
        }

        // Save Selected Warehouses
        if ( isset( $_POST['spire_save_warehouses_submit'] ) ) {
            check_admin_referer( 'spire_save_warehouses_nonce', 'spire_save_warehouses_nonce_field' );
            $selected = isset( $_POST['warehouses'] ) ? array_map( 'sanitize_text_field', (array) $_POST['warehouses'] ) : [];
            update_option( 'spire_selected_warehouses', $selected );
            // Keep per-request cache consistent.
            self::$option_cache['spire_selected_warehouses'] = $selected;
            spire_inventory_api_set_notice( 'Selected warehouses saved.', 'success' );
        }

        // Manual Sync (Schedule Background Sync) — de-dupe same-args job
        if ( isset( $_POST['spire_sync_submit'] ) ) {
            check_admin_referer( 'spire_sync_nonce', 'spire_sync_nonce_field' );
            $schedule_data = get_option( 'spire_schedule_settings', [] );
            $full_sync     = ! empty( $schedule_data['full_sync'] );

            $args = [
                'is_cron'        => false,
                'manual_trigger' => true,
                'full_sync'      => $full_sync,
            ];

            $ts = wp_next_scheduled( 'spire_background_sync', $args );
            if ( ! $ts ) {
                wp_schedule_single_event( time(), 'spire_background_sync', $args );
            }
            spire_inventory_api_set_notice( 'Sync scheduled in the background. Refresh the page in a few minutes.', 'success' );
        }

        // Save Schedule Sync is handled inside render_schedule_sync_tab() (nonce already checked there).

        // Purge Failed Orders (if submitted)
        if ( isset( $_POST['spire_purge_failed_submit'] ) ) {
            check_admin_referer( 'spire_purge_failed_nonce', 'spire_purge_failed_nonce_field' );
            update_option( 'spire_failed_orders_log', [] );
            spire_inventory_api_set_notice( 'All unsuccessful actions purged.', 'success' );
        }

        // Sync Payment Terms
        if ( isset( $_POST['spire_sync_terms_submit'] ) ) {
            check_admin_referer( 'spire_sync_terms_nonce', 'spire_sync_terms_nonce_field' );

            $force = ! empty( $_POST['force_overwrite_terms'] );

            try {
                if ( class_exists( 'Spire_Payment_Terms_Sync' ) && method_exists( 'Spire_Payment_Terms_Sync', 'run' ) ) {
                    $result  = Spire_Payment_Terms_Sync::run( [ 'force' => $force ] );
                    $updated = (int) ( $result['updated'] ?? 0 );
                    $missing = (int) ( $result['missing'] ?? 0 );
                    $errors  = (int) ( $result['errors']  ?? 0 );
                    $msg     = isset( $result['message'] ) ? (string) $result['message'] : '';

                    // Severity rules:
                    //   - success: updates and no errors
                    //   - warning: some updates but also errors OR no updates but also no errors (nothing to do)
                    //   - error: no updates and had errors (typical connectivity/field mismatch)
                    $severity = 'success';
                    if ( $errors > 0 && $updated === 0 ) {
                        $severity = 'error';
                    } elseif ( $errors > 0 || ($updated === 0 && $missing >= 0) ) {
                        $severity = 'warning';
                    }

                    $text = $msg !== ''
                        ? $msg
                        : sprintf(
                            'Terms sync%s: updated %d, missing %d, errors %d',
                            $force ? ' (force overwrite)' : '',
                            $updated, $missing, $errors
                        );

                    spire_inventory_api_set_notice( $text, $severity );
                } else {
                    spire_inventory_api_set_notice(
                        'Payment terms sync is unavailable: missing Spire_Payment_Terms_Sync::run().',
                        'error'
                    );
                }
            } catch ( \Throwable $e ) {
                spire_inventory_api_set_notice( 'Error syncing payment terms: ' . $e->getMessage(), 'error' );
            }
        }

        if ( isset($_POST['spire_save_sslverify']) ) {
            check_admin_referer('spire_sslverify_nonce', 'spire_sslverify_nonce_field');
            $flag = ! empty($_POST['spire_ssl_no_verify']);
            update_option('spire_ssl_no_verify', $flag ? 1 : 0);
            spire_inventory_api_set_notice(
                $flag
                    ? 'Security setting saved: SSL verification DISABLED (dev only).'
                    : 'Security setting saved: SSL verification ENABLED.',
                $flag ? 'warning' : 'success'
            );
        }

        // ERP Settings Save
        if ( isset( $_POST['spire_save_erp_settings'] ) ) {
            // Verify nonce and save ERP settings (provider, mapping, flags)
            check_admin_referer( 'spire_save_erp_settings_nonce', 'spire_save_erp_settings_nonce_field' );

            // Provider selection
            $provider = isset($_POST['erp_provider']) ? sanitize_text_field( wp_unslash($_POST['erp_provider']) ) : 'spire';

            // Feature flags (checkboxes)
            $flags_input = isset($_POST['erp_feature_flags']) && is_array($_POST['erp_feature_flags']) ? $_POST['erp_feature_flags'] : [];
            $feature_flags = [];
            // Adapter mode flag
            $feature_flags['adapter_mode'] = ! empty( $flags_input['adapter_mode'] );
            $feature_flags['universal_mapping'] = ! empty( $flags_input['universal_mapping'] );

            // Mapping JSON (raw textarea); ensure valid JSON before saving
            $mapping_json = isset($_POST['erp_mappings']) ? wp_unslash( (string) $_POST['erp_mappings'] ) : '';
            $mapping_decoded = json_decode( $mapping_json, true );
            if ( $mapping_json !== '' && ( is_null( $mapping_decoded ) || json_last_error() !== JSON_ERROR_NONE ) ) {
                // Invalid JSON; do not save, set error notice
                spire_inventory_api_set_notice( 'Invalid mapping JSON. Settings were not saved.', 'error' );
            } else {
                // Save provider, mapping, and flags
                update_option( 'erp_provider', $provider );
                update_option( 'erp_mappings', $mapping_json );
                update_option( 'erp_feature_flags', $feature_flags );

                // Save provider-specific credentials if not Spire
                if ( $provider !== 'spire' ) {
                    // Base URL
                    $base_opt_name = $provider . '_base_url';
                    $user_opt_name = $provider . '_username';
                    $pass_opt_name = $provider . '_password';
                    $base_raw = isset($_POST[$provider . '_base_url']) ? wp_unslash( (string) $_POST[$provider . '_base_url'] ) : '';
                    $user_raw = isset($_POST[$provider . '_username']) ? wp_unslash( (string) $_POST[$provider . '_username'] ) : '';
                    $pass_raw = isset($_POST[$provider . '_password']) ? wp_unslash( (string) $_POST[$provider . '_password'] ) : '';
                    // Sanitize similar to register_settings
                    $base_clean = esc_url_raw( trim( (string) $base_raw ) );
                    if ( $base_clean !== '' ) {
                        $base_clean = rtrim( $base_clean, '/' );
                    }
                    $user_clean = sanitize_text_field( $user_raw );
                    $pass_clean = trim( (string) $pass_raw );
                    update_option( $base_opt_name, $base_clean );
                    update_option( $user_opt_name, $user_clean );
                    update_option( $pass_opt_name, $pass_clean );
                }

                // Clear option cache so new values show in UI
                self::$option_cache['erp_provider'] = $provider;
                self::$option_cache['erp_mappings'] = $mapping_json;
                self::$option_cache['erp_feature_flags'] = $feature_flags;
                // Also update provider credentials in cache
                if ( $provider !== 'spire' ) {
                    self::$option_cache[$provider . '_base_url'] = $base_clean;
                    self::$option_cache[$provider . '_username'] = $user_clean;
                    self::$option_cache[$provider . '_password'] = $pass_clean;
                }

                spire_inventory_api_set_notice( 'ERP settings saved.', 'success' );
            }
        }
    }

    /**
     * Render Users Missing Customer No
     */
    private static function render_missing_spire_ids() {
        $paged  = isset( $_GET['paged_miss'] ) ? max( 1, intval( $_GET['paged_miss'] ) ) : 1;
        $search = isset( $_GET['search_miss'] ) ? sanitize_text_field( $_GET['search_miss'] ) : '';
        $limit  = 20;
        $offset = ( $paged - 1 ) * $limit;

        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="spire-inventory-api-settings">';
        echo '<input type="hidden" name="tab" value="orders">';
        echo '<input type="hidden" name="paged_miss" value="1">'; // fixed hidden name
        echo '<input type="text" name="search_miss" placeholder="Search users (name/email/login)..." value="' . esc_attr( $search ) . '">';
        echo '<input type="submit" class="button" value="Search">';
        echo '</form>';

        // Users with missing or empty spire_customer_no
        $meta_missing = [
            'relation' => 'OR',
            [
                'key'     => 'spire_customer_no',
                'compare' => 'NOT EXISTS'
            ],
            [
                'key'     => 'spire_customer_no',
                'value'   => '',
                'compare' => '='
            ],
        ];

        $args = [
            'number'       => $limit,
            'offset'       => $offset,
            'fields'       => [ 'ID', 'display_name', 'user_email' ],
            'meta_query'   => $meta_missing,
            'count_total'  => true,
        ];

        if ( $search !== '' ) {
            $args['search']         = '*' . esc_attr( $search ) . '*';
            $args['search_columns'] = [ 'user_email', 'user_login', 'display_name' ];
        }

        $uq           = new WP_User_Query( $args );
        $users        = $uq->get_results();
        $total_users  = (int) $uq->get_total();
        $total_pages  = ( $total_users > 0 ) ? (int) ceil( $total_users / $limit ) : 1;

        if ( empty( $users ) ) {
            echo '<p>No users found missing <code>spire_customer_no</code> under these filters.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>User</th><th>Email</th><th>Current Customer No</th><th>Action</th></tr></thead><tbody>';
        foreach ( $users as $u ) {
            $cust_no = get_user_meta( $u->ID, 'spire_customer_no', true );
            echo '<tr>';
            echo '<td>' . esc_html( $u->display_name ) . '</td>';
            echo '<td>' . esc_html( $u->user_email ) . '</td>';
            echo '<td>' . esc_html( $cust_no ?: '(none)' ) . '</td>';
            echo '<td><a class="button" href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">Edit User</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $base_url = add_query_arg( [
                'page'        => 'spire-inventory-api-settings',
                'tab'         => 'orders',
                'search_miss' => $search,
                'paged_miss'  => '%#%',
            ], admin_url( 'options-general.php' ) );

            echo '<div class="tablenav">';
            echo paginate_links( [
                'base'      => esc_url( $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '« Prev',
                'next_text' => 'Next »',
            ] );
            echo '</div>';
        }
    }

    /**
     * Renders the failed orders list (unsuccessful POST actions)
     */
    private static function render_failed_orders() {
        // Purge form
        echo '<form method="post" style="margin-bottom:15px;">';
        wp_nonce_field( 'spire_purge_failed_nonce', 'spire_purge_failed_nonce_field' );
        echo '<input type="submit" name="spire_purge_failed_submit" class="button button-secondary" value="Purge All Unsuccessful Actions">';
        echo '</form>';

        // Pagination + search
        $paged  = isset( $_GET['paged_fail'] ) ? max( 1, intval( $_GET['paged_fail'] ) ) : 1;
        $search = isset( $_GET['search_fail'] ) ? sanitize_text_field( $_GET['search_fail'] ) : '';
        $limit  = 20;
        $offset = ( $paged - 1 ) * $limit;

        $all_failed = get_option( 'spire_failed_orders_log', [] );
        if ( ! is_array( $all_failed ) ) {
            $all_failed = [];
        }

        if ( $search !== '' ) {
            $all_failed = array_filter( $all_failed, function( $entry ) use ( $search ) {
                $oid    = isset( $entry['order_id'] ) ? (string) $entry['order_id'] : '';
                $reason = isset( $entry['reason'] ) ? (string) $entry['reason'] : '';
                return ( stripos( $oid, $search ) !== false ) || ( stripos( $reason, $search ) !== false );
            } );
        }

        // Sort by time descending (fallback to current_time if missing)
        usort( $all_failed, function( $a, $b ) {
            $ta = isset( $a['time'] ) ? strtotime( $a['time'] ) : 0;
            $tb = isset( $b['time'] ) ? strtotime( $b['time'] ) : 0;
            return $tb <=> $ta;
        } );

        $total_failed = count( $all_failed );

        // Quick search form
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="spire-inventory-api-settings">';
        echo '<input type="hidden" name="tab" value="orders">';
        echo '<input type="hidden" name="paged_fail" value="1">';
        echo '<input type="text" name="search_fail" placeholder="Search by Order ID or reason..." value="' . esc_attr( $search ) . '">';
        echo '<input type="submit" class="button" value="Search Failed Actions">';
        echo '</form>';

        if ( $total_failed === 0 ) {
            echo '<p>No unsuccessful POST actions found.</p>';
            return;
        }

        $total_pages  = (int) ceil( $total_failed / $limit );
        $paged_failed = array_slice( $all_failed, $offset, $limit );

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Order ID</th><th>Reason</th><th>Time</th></tr></thead><tbody>';
        foreach ( $paged_failed as $fail ) {
            $order_id = isset( $fail['order_id'] ) ? $fail['order_id'] : '';
            $reason   = isset( $fail['reason'] ) ? $fail['reason'] : '';
            $time     = isset( $fail['time'] ) ? $fail['time'] : '';
            echo '<tr>';
            echo '<td>' . esc_html( $order_id ) . '</td>';
            echo '<td>' . esc_html( $reason ) . '</td>';
            echo '<td>' . esc_html( $time ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $base_url = add_query_arg( [
                'page'        => 'spire-inventory-api-settings',
                'tab'         => 'orders',
                'search_fail' => $search,
                'paged_fail'  => '%#%',
            ], admin_url( 'options-general.php' ) );

            echo '<div class="tablenav">';
            echo paginate_links( [
                'base'      => esc_url( $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '« Prev',
                'next_text' => 'Next »',
            ] );
            echo '</div>';
        }
    }
}
