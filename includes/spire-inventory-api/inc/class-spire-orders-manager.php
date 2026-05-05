<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_Orders_Manager
 * Hooks into final WooCommerce order statuses (processing) and posts the finalized order
 * to the Spire API. Avoids cart-based totals and uses reliable wc_get_order($order_id) data.
 */
class Spire_Orders_Manager {

    public static function init() {
        // Hook into final order statuses at priority 20
        add_action('woocommerce_order_status_processing', [__CLASS__, 'handle_order_completed'], 20, 1);
    }

    /**
     * handle_order_completed
     * Called when an order transitions to 'processing'.
     * Fetches reliable final order data via wc_get_order, builds a Spire payload, and sends it.
     */
    public static function handle_order_completed($order_id) {
        // Idempotency guard: don't resend if already synced
        if (get_post_meta($order_id, '_spire_synced', true)) {
            return;
        }

        // Circuit breaker: if open, skip posting for now
        $cb_key = 'spire_post_cb';
        $cb = get_transient($cb_key);
        if (!empty($cb) && $cb === 'open') {
            error_log("Spire circuit open; skipping order #{$order_id}.");
            return;
        }

        // Micro-lock: avoid concurrent double posts
        $lock_key = "spire_post_lock_{$order_id}";
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 60);

        try {
            // 1) Get the final WooCommerce order object
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception("Order not found: #{$order_id}");
            }

            $woo_order_no = 'W' . $order->get_order_number();

            // 2) Resolve customerNo with fallback to default setting
            $user_id = $order->get_user_id();
            $customer_no = '';

            if ($user_id) {
                // Normalize from user meta first
                $customer_no_raw = (string) get_user_meta($user_id, 'spire_customer_no', true);
                $customer_no = preg_replace('/[\p{C}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $customer_no_raw);
                $customer_no = strtoupper(trim($customer_no));
            }

            // If missing or guest/no user, use the configured default
            if ($customer_no === '') {
                // Prefer helper if you added it; otherwise pull via get_option map
                $fallback_default = method_exists('Spire_Settings', 'default_customer_no')
                    ? Spire_Settings::default_customer_no()
                    : trim(Spire_Settings::get_option('default_customer_no', ''));

                // Normalize fallback too
                $fallback_default = strtoupper(preg_replace('/[\p{C}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $fallback_default));

                if ($fallback_default !== '') {
                    $customer_no = $fallback_default;
                }
            }

            // Still empty? Fail with a clear message
            if ($customer_no === '') {
                $why = $user_id
                    ? "User #{$user_id} has no spire_customer_no and no default is configured."
                    : "Guest order has no mapped customer and no default is configured.";
                throw new Exception("Cannot post order #{$order_id}: {$why}");
            }

            // 3) Build items array from reliable order line items
            $items_array = [];
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue; // product removed or invalid
                }

                $sku = $product->get_sku();
                if (!$sku) {
                    throw new Exception("Missing SKU for item #{$item_id} in order #{$order_id}.");
                }

                $qty = $item->get_quantity();
                if ($qty <= 0) {
                    throw new Exception("Invalid qty ($qty) for SKU $sku in order #{$order_id}.");
                }

                // Use the final order item total from WooCommerce
                $line_total = floatval($item->get_total());

                // If line_total is zero, fallback to product price * qty
                if ($line_total <= 0) {
                    $fallback_price = floatval($product->get_price()) * $qty;
                    error_log("⚠️ line_total was 0 for SKU {$sku} in order #{$order_id}, fallback => {$fallback_price}");
                    $line_total = $fallback_price;
                }

                // Compute price per unit
                $price = ($qty > 0) ? ($line_total / $qty) : 0.0;
                if ($price <= 0.0) {
                    // If still invalid, skip item
                    error_log("Invalid price (0.0) after fallback for SKU: {$sku} in order #{$order_id}, item skipped.");
                    continue;
                }
                error_log("✅ Final price for SKU {$sku} in order #{$order_id} => {$price}");

                // Determine warehouse code
                $whse = get_post_meta($product->get_id(), 'warehouse_code', true);
                if (empty($whse)) {
                    $whse = '00';
                }

                $items_array[] = [
                    'whse'        => $whse,
                    'partNo'      => $sku,
                    'orderQty'    => $qty,
                    'retailPrice' => $price,
                ];
            }

            if (empty($items_array)) {
                $order->add_order_note('Spire: not posted (no valid line items).');
                throw new Exception("No valid line items in order #{$order_id}.");
            }

            // 4) Build final JSON payload
            $order_date = $order->get_date_created()
                ? $order->get_date_created()->date('Y-m-d')
                : date('Y-m-d');

            // --- Address helpers (normalize + pack + map to Spire keys) ---
            $normalize = static function($s) {
                $s = is_string($s) ? $s : '';
                $s = preg_replace('/[\p{C}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $s);
                return trim($s);
            };
            $pack_address = static function(array $raw) use ($normalize) {
                return [
                    'line1'      => $normalize($raw['address_1'] ?? ''),
                    'line2'      => $normalize($raw['address_2'] ?? ''),
                    'city'       => $normalize($raw['city'] ?? ''),
                    'provState'  => $normalize($raw['state'] ?? ''),
                    'postalCode' => $normalize($raw['postcode'] ?? ''),
                    'country'    => $normalize($raw['country'] ?? ''),
                ];
            };
            $to_spire_addr = static function(array $a) {
                return [
                    'line1'   => $a['line1'] ?? '',
                    'line2'   => $a['line2'] ?? '',
                    'city'       => $a['city'] ?? '',
                    'province'   => $a['provState'] ?? '',
                    'postalCode' => $a['postalCode'] ?? '',
                    'country'    => $a['country'] ?? '',
                ];
            };
            $address_has_values = static function(array $address) {
                foreach ($address as $value) {
                    if (is_string($value) && $value !== '') {
                        return true;
                    }
                }
                return false;
            };

            // Pull raw Woo addresses
            $billing_raw  = $order->get_address('billing');
            $shipping_raw = $order->get_address('shipping');

            // Build normalized billing
            $billing_address = $pack_address($billing_raw);
            $has_billing_address = $address_has_values($billing_address);

            // Decide if shipping is truly present (any non-empty field, not just address_1)
            $has_shipping = is_array($shipping_raw) && (bool) array_filter($shipping_raw, function($v){
                return !empty($v) && trim((string)$v) !== '';
            });

            // Build normalized shipping (if present)
            $shipping_address = $has_shipping ? $pack_address($shipping_raw) : [];
            $has_shipping_address = $address_has_values($shipping_address);
            $ship_to_source = 'default';
            $ship_to_payload = null;
            if ($has_shipping_address) {
                $ship_to_payload = $to_spire_addr($shipping_address);
                $ship_to_source = 'shipping';
            }

            // Trace what we actually send for easier debugging
            error_log(sprintf(
                'Spire: order %d address selection => billing=%s shipTo=%s source=%s',
                $order_id,
                $has_billing_address
                    ? json_encode($billing_address, JSON_UNESCAPED_UNICODE)
                    : 'default',
                $ship_to_payload
                    ? json_encode($ship_to_payload, JSON_UNESCAPED_UNICODE)
                    : 'default',
                $ship_to_source
            ));

            // 5) Combine into final data structure (Spire: bill-to 'address', ship-to 'shipTo')
            $post_data = [
                'orderNo'   => $woo_order_no,
                'customer'  => ['customerNo' => $customer_no], // already normalized
                'orderDate' => $order_date,
                'items'     => $items_array,
            ];

            if ($has_billing_address) {
                $post_data['address'] = $to_spire_addr($billing_address);
            }

            if ($ship_to_payload) {
                $ship_to_name = $normalize(($shipping_raw['first_name'] ?? '') . ' ' . ($shipping_raw['last_name'] ?? ''));
                if ($ship_to_name !== '') {
                    $ship_to_payload['name'] = $ship_to_name;
                }
                $post_data['shippingAddress'] = $ship_to_payload;
            }

            $customer_note = $normalize($order->get_customer_note());
            if ($customer_note !== '') {
                $post_data['notes'] = $customer_note;
            }

            // 6) POST to Spire
            error_log("🧾 Final Spire Payload: " . json_encode($post_data));
            $resp = self::post_to_spire($post_data);
            if (is_wp_error($resp)) {
                throw new Exception("Spire API error: " . $resp->getMessage());
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = (string) wp_remote_retrieve_body($resp);

            // Success
            if ($code >= 200 && $code <= 299) {
                error_log("Order #{$order_id} posted to Spire successfully. Body => {$body}");
                $order->add_order_note('Spire: order posted successfully.');
                $json = json_decode($body, true);
                $spire_id = is_array($json) ? ($json['id'] ?? null) : null;
                update_post_meta($order_id, '_spire_synced', 1);
                update_post_meta($order_id, '_spire_order_no', $woo_order_no);
                if ($spire_id) update_post_meta($order_id, '_spire_order_id', $spire_id);
                delete_transient('spire_post_fail_count');
                return;
            }

            // 422: inactive inventory lines
            if ($code === 422 && stripos($body, 'Inventory is inactive') !== false) {
                $inactive_skus = self::extract_inactive_skus_from_body($body);

                // Remove those items and retry once
                list($new_items, $removed) = self::filter_items_by_inactive_skus($items_array, $inactive_skus);

                if (empty($removed)) {
                    throw new Exception("Spire 422 (inactive inventory), SKU unknown. Response: {$body}");
                }

                if (empty($new_items)) {
                    $reason = "All items inactive (removed: " . implode(', ', $removed) . ")";
                    $order->add_order_note("Spire: not posted. {$reason}");
                    self::log_failed_order($order_id, $reason);
                    return; // terminal
                }

                // Rebuild payload with filtered items + header note
                $note_msg = "Removed inactive SKUs: " . implode(', ', $removed) . ".";
                $order->add_order_note("Spire: {$note_msg}");
                $post_data['items'] = $new_items;
                $post_data['notes'] = (isset($post_data['notes']) && $post_data['notes'])
                    ? $post_data['notes'] . ' ' . $note_msg
                    : $note_msg;

                // Retry once
                $resp = self::post_to_spire($post_data);
                if (is_wp_error($resp)) {
                    throw new Exception("Retry after removing inactive SKUs failed: " . $resp->getMessage());
                }

                $code = (int) wp_remote_retrieve_response_code($resp);
                $body = (string) wp_remote_retrieve_body($resp);

                if ($code >= 200 && $code <= 299) {
                    error_log("Order #{$order_id} posted to Spire (after removing inactive SKUs) successfully.");
                    $order->add_order_note('Spire: order posted successfully after removing inactive SKUs.');
                    update_post_meta($order_id, '_spire_synced', 1);
                    update_post_meta($order_id, '_spire_order_no', $woo_order_no);
                    delete_transient('spire_post_fail_count');
                    return;
                }

                // Still not good — mark as failed once, don’t loop
                $reason = "Retry after removing inactive SKUs failed: {$code} {$body}";
                $order->add_order_note("Spire: {$reason}");
                self::log_failed_order($order_id, $reason);
                return;
            }

            // 422: order number already exists => treat as synced
            if ($code === 422 && stripos($body, 'Order number already exists') !== false) {
                $order->add_order_note('Spire: orderNo already exists; marking as synced.');
                update_post_meta($order_id, '_spire_synced', 1);
                update_post_meta($order_id, '_spire_order_no', $woo_order_no);
                delete_transient('spire_post_fail_count');
                return;
            }

            // Default error
            throw new Exception("Spire API returned status code {$code}. Response: {$body}");

        } catch (Exception $e) {
            // Log + fail gracefully (correct method name)
            error_log("Order #{$order_id} => Spire POST fail: " . $e->getMessage());
            if (function_exists('spire_inventory_api_set_notice')) {
                spire_inventory_api_set_notice("Failed to send order #{$order_id} to Spire: " . $e->getMessage(), 'error');
            }

            // Failure counter -> open circuit after 3 fails in 10 minutes
            $fail_key   = 'spire_post_fail_count';
            $fail_count = (int) get_transient($fail_key);
            $fail_count++;
            set_transient($fail_key, $fail_count, 10 * MINUTE_IN_SECONDS);
            if ($fail_count >= 3) {
                set_transient($cb_key, 'open', 15 * MINUTE_IN_SECONDS); // cool off 15 minutes
                delete_transient($fail_key);
            }

            self::log_failed_order($order_id, $e->getMessage());

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * post_to_spire
     * Actually sends the JSON payload to Spire.
     */
    private static function post_to_spire(array $data) {
        $username = trim(Spire_Settings::get_option('username', ''));
        $password = trim(Spire_Settings::get_option('password', ''));
        $base_url = trim(Spire_Settings::get_option('base_url', ''));
        $override = trim(get_option('spire_orders_endpoint', ''));

        if (!$username || !$password || !$base_url) {
            return new WP_Error('missing_creds', 'Spire credentials are missing.');
        }

        // Build the endpoint once. If full URL override is set, use it; else compose from base.
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_URL)) {
            $orders_endpoint = rtrim($override, '/');
        } else {
            $orders_path     = $override !== '' ? ltrim($override, '/') : Spire_Settings::company_path('sales/orders');
            $orders_endpoint = Spire_Sync_Manager::build_api_url($base_url, $orders_path);
        }

        // Optional idempotency
        if (empty($data['externalRef']) && !empty($data['orderNo'])) {
            $data['externalRef'] = 'WOO-' . (string) $data['orderNo'];
        }

        // Log the URL used
        error_log("[Spire Orders] POST URL: {$orders_endpoint}");

        // Correct call signature: payload separate from request args
        return Spire_API_Client::post(
            $orders_endpoint,
            $username,
            $password,
            $data,
            [
                'timeout' => 25,
                'retries' => 3,
            ]
        );
    }

    /**
     * Extract inactive SKUs from a Spire 422 body (e.g., "Inventory is inactive: AMAZE03").
     */
    private static function extract_inactive_skus_from_body($body) {
        $skus = [];
        // Body may be JSON or plain; handle both
        $msg = $body;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !empty($decoded['message'])) {
            $msg = $decoded['message'];
        }
        // Pattern: "Inventory is inactive: SKU" (possibly multiple separated by commas/spaces)
        if (preg_match('/Inventory is inactive:\s*([A-Za-z0-9\-_.,\s]+)/i', $msg, $m)) {
            // split on comma or whitespace
            $candidates = preg_split('/[,\s]+/', trim($m[1]));
            foreach ($candidates as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $skus[] = $c;
                }
            }
        }
        return array_values(array_unique($skus));
    }

    /**
     * Remove items from items array by partNo. Returns [new_items, removed_skus].
     */
    private static function filter_items_by_inactive_skus(array $items, array $inactive_skus) {
        if (empty($inactive_skus)) return [$items, []];
        $removed = [];
        $filtered = [];
        $inactive_map = array_fill_keys($inactive_skus, true);
        foreach ($items as $it) {
            $sku = $it['partNo'] ?? null;
            if ($sku && isset($inactive_map[$sku])) {
                $removed[] = $sku;
                continue; // drop line
            }
            $filtered[] = $it;
        }
        return [$filtered, array_values(array_unique($removed))];
    }

    /**
     * log_failed_order
     * Keeps track of any orders that failed to post to Spire for debugging/resend.
     */
    private static function log_failed_order($order_id, $reason) {
        $key = 'spire_failed_orders_log';

        // Ensure autoload = no on first create
        if (false === get_option($key, false)) {
            add_option($key, [], '', 'no');
        }

        $failed_orders   = get_option($key, []);
        $failed_orders[] = [
            'order_id' => (int) $order_id,
            'reason'   => (string) $reason,
            'time'     => current_time('mysql'),
        ];

        // Cap to last N entries
        $max = 200;
        if (count($failed_orders) > $max) {
            $failed_orders = array_slice($failed_orders, -$max);
        }

        // Explicit autoload = no
        update_option($key, $failed_orders, false);
    }
}
