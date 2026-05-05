<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_Customer_Mapping
 * Adds a "Spire Customer No" field to user profiles + Users table.
 */
class Spire_Customer_Mapping {

    public static function init() {
        // Add "Spire Customer No" field to user profiles
        add_action('show_user_profile', [__CLASS__, 'add_customer_no_field']);
        add_action('edit_user_profile', [__CLASS__, 'add_customer_no_field']);
        add_action('personal_options_update', [__CLASS__, 'save_customer_no_field']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_customer_no_field']);

        // Add column to Users table
        add_filter('manage_users_columns', [__CLASS__, 'add_customer_no_column']);
        add_filter('manage_users_custom_column', [__CLASS__, 'render_customer_no_column'], 10, 3);
        add_filter('manage_users_sortable_columns', [__CLASS__, 'sortable_customer_no_column']);
        add_action('pre_get_users', [__CLASS__, 'sort_users_meta']);
    }

    /**
     * Add "Spire Customer No" field to user profiles
     */
    public static function add_customer_no_field($user) {
        $cust_no = get_user_meta($user->ID, 'spire_customer_no', true);
        $payment_term = get_user_meta($user->ID, 'spire_payment_term', true);
        if (!$payment_term) {
            $payment_term = 'ON RECEIPT'; // Default payment term if not set
        }
        ?>
        <h3>Spire ERP Mapping</h3>
        <table class="form-table">
            <tr>
                <th><label for="spire_customer_no">Customer No</label></th>
                <td>
                    <input type="text" name="spire_customer_no" id="spire_customer_no"
                           value="<?php echo esc_attr($cust_no); ?>" class="regular-text" />
                    <p class="description">Assign this user a Spire customerNo.</p>
                </td>
            </tr>
            <tr>
                <th><label for="spire_payment_term">Payment Term</label></th>
                <td>
                    <input type="text" name="spire_payment_term" id="spire_payment_term" readonly
                           value="<?php echo esc_attr($payment_term); ?>" class="regular-text" />
                    <p class="description">Payment Term used.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save "Spire Customer No" and self-heal Payment Term from Spire.
     */
    public static function save_customer_no_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        // Normalize to uppercase on save to avoid 10k/10K issues later.
        $raw = isset($_POST['spire_customer_no']) ? (string) wp_unslash($_POST['spire_customer_no']) : '';
        $val = self::normalize_customer_no($raw);
        update_user_meta($user_id, 'spire_customer_no', $val);

        // Self-heal: always refresh payment terms from Spire after customerNo save.
        if (class_exists('Spire_Payment_Terms_Sync') && method_exists('Spire_Payment_Terms_Sync', 'sync_user')) {
            $result = Spire_Payment_Terms_Sync::sync_user((int) $user_id, [
                'customer_no' => $val,
                'force'       => true,
            ]);

            if (is_array($result) && empty($result['ok'])) {
                error_log('Spire payment-term self-heal failed for user #' . (int) $user_id . ': ' . (string) ($result['message'] ?? 'Unknown error'));
            }
        } elseif ($val === '') {
            // Fallback cleanup when sync class is unavailable.
            delete_user_meta($user_id, 'spire_payment_term');
            delete_user_meta($user_id, 'spire_payment_term_code');
            delete_user_meta($user_id, 'spire_terms_last_synced');
        }
    }

    /**
     * Add columns
     */
    public static function add_customer_no_column($columns) {
        $columns['spire_customer_no']  = 'Customer No';
        $columns['spire_payment_term'] = 'Payment Term';
        return $columns;
    }

    /**
     * Render columns
     */
    public static function render_customer_no_column($value, $column_name, $user_id) {
        if ($column_name === 'spire_customer_no') {
            $cust_no = get_user_meta($user_id, 'spire_customer_no', true);
            return esc_html($cust_no ?: '');
        }
        if ($column_name === 'spire_payment_term') {
            $payment_term = get_user_meta($user_id, 'spire_payment_term', true);
            return esc_html($payment_term ?: '');
        }
        return $value;
    }

    /**
     * Make columns sortable
     */
    public static function sortable_customer_no_column($columns) {
        $columns['spire_customer_no']  = 'spire_customer_no';
        $columns['spire_payment_term'] = 'spire_payment_term';
        return $columns;
    }

    /**
     * Apply sorting when those columns are used
     */
    public static function sort_users_meta($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        $orderby = $query->get('orderby');
        if ($orderby === 'spire_customer_no') {
            $query->set('meta_key', 'spire_customer_no');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'spire_payment_term') {
            $query->set('meta_key', 'spire_payment_term');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Normalize customer number: strip control/invisible chars, trim, uppercase.
     */
    private static function normalize_customer_no(string $value): string {
        // Remove control & zero-width chars, then trim and uppercase.
        $value = preg_replace('/[\p{C}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);
        $value = trim($value);
        return strtoupper($value);
    }

    /**
     * Build the customers endpoint path or honor a full URL override.
     */
    private static function customers_endpoint(string $base_url): string {
        $custom = trim(get_option('spire_customers_endpoint', '')); // optional override

        if ($custom !== '') {
            if (filter_var($custom, FILTER_VALIDATE_URL)) {
                return rtrim($custom, '/');
            }
            // Relative override -> base + /api/v2 + path
            return Spire_Sync_Manager::build_api_url($base_url, ltrim($custom, '/'));
        }

        // Default company customers path
        $path = Spire_Settings::company_path('customers');
        return Spire_Sync_Manager::build_api_url($base_url, $path);
    }

    /**
     * Fetch a customer record by customerNo from Spire.
     * Returns array (record) or false on error/not found.
     */
    public static function get_customer_by_no(string $customerNo) {
        $username = trim(Spire_Settings::get_option('username', ''));
        $password = trim(Spire_Settings::get_option('password', ''));
        $base_url = trim(Spire_Settings::get_option('base_url', ''));

        if (!$username || !$password || !$base_url) {
            return false; // let caller surface admin notices
        }

        // Normalize before building filter to avoid 10k -> 0 records issue.
        $customerNo = self::normalize_customer_no($customerNo);

        $endpoint = self::customers_endpoint($base_url);
        // Build URL with a URL-encoded JSON filter
        $url = Spire_API_Client::url_with_json_filter($endpoint, ['customerNo' => $customerNo]);

        $resp   = Spire_API_Client::get($url, $username, $password);
        $parsed = Spire_API_Client::parse_json_response($resp);

        if (is_wp_error($parsed)) {
            error_log('Spire customers lookup transport error: ' . $parsed->get_error_message());
            return false;
        }
        if ((int) ($parsed['code'] ?? 0) !== 200) {
            $body = isset($parsed['raw']) ? substr((string) $parsed['raw'], 0, 300) : '';
            error_log('Spire customers lookup HTTP ' . (int)$parsed['code'] . ' Body: ' . $body);
            return false;
        }

        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        if (empty($data['records']) || !is_array($data['records'])) {
            return false;
        }

        // Return the first match
        return $data['records'][0];
    }
}
