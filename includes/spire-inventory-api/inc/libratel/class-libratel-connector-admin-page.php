<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Libratel connector admin UI + form handlers.
 */
class Libratel_Connector_Admin_Page {
    private const PAGE_SLUG = 'libratel-website-connector-settings';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'handle_form_submissions']);
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            'options-general.php',
            'Libratel Website Connector',
            'Libratel Connector',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action_url = esc_url(add_query_arg(['page' => self::PAGE_SLUG], admin_url('options-general.php')));
        $values = Libratel_Connector_Options::get_all();

        ?>
        <div class="wrap">
            <h1>Libratel Website Connector</h1>
            <p>Syncs Shopify variant barcode + price using Spire LIBRATEL data.</p>

            <h2>Connector Settings</h2>
            <form method="post" action="<?php echo $action_url; ?>">
                <?php wp_nonce_field('libratel_save_connector_settings_nonce', 'libratel_save_connector_settings_nonce_field'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="spire_base_url">Spire Base URL</label></th>
                        <td>
                            <input type="url" name="spire_base_url" id="spire_base_url" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['spire_base_url']); ?>" />
                            <p class="description">Used for Libratel endpoints under <code>/api/v2/companies/LIBRATEL/</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spire_username">Spire Username</label></th>
                        <td>
                            <input type="text" name="spire_username" id="spire_username" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['spire_username']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spire_password">Spire Password</label></th>
                        <td>
                            <input type="password" name="spire_password" id="spire_password" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['spire_password']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="libratel_shopify_domain">Shopify Store Domain</label></th>
                        <td>
                            <input type="text" name="libratel_shopify_domain" id="libratel_shopify_domain" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['shopify_domain']); ?>" placeholder="your-store.myshopify.com" />
                            <p class="description">Domain only (no protocol/path).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="libratel_shopify_client_id">Shopify Client ID</label></th>
                        <td>
                            <input type="text" name="libratel_shopify_client_id" id="libratel_shopify_client_id" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['shopify_client_id']); ?>" />
                            <p class="description">From your Shopify Dev Dashboard app settings.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="libratel_shopify_client_secret">Shopify Client Secret</label></th>
                        <td>
                            <input type="password" name="libratel_shopify_client_secret" id="libratel_shopify_client_secret" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['shopify_client_secret']); ?>" autocomplete="new-password" />
                            <p class="description">Used to generate access tokens automatically (24h refresh handled by the plugin).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="libratel_shopify_access_token">Shopify Admin API Access Token</label></th>
                        <td>
                            <input type="password" name="libratel_shopify_access_token" id="libratel_shopify_access_token" class="regular-text"
                                   value="<?php echo esc_attr((string) $values['shopify_token']); ?>" autocomplete="new-password" />
                            <p class="description">Optional fallback. If Client ID + Secret are filled, they are used first.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Connector Schedule</th>
                        <td>
                            <label for="libratel_connector_schedule_enabled">
                                <input type="checkbox"
                                       name="libratel_connector_schedule_enabled"
                                       id="libratel_connector_schedule_enabled"
                                       value="1" <?php checked((bool) $values['schedule_enabled']); ?> />
                                Enable automatic connector schedule
                            </label>
                            <p style="margin-top:8px;">
                                <label for="libratel_connector_interval_minutes">
                                    Run every
                                    <input type="number"
                                           name="libratel_connector_interval_minutes"
                                           id="libratel_connector_interval_minutes"
                                           min="5"
                                           max="1440"
                                           step="1"
                                           style="width:100px;"
                                           value="<?php echo esc_attr((string) $values['schedule_interval']); ?>" />
                                    minutes
                                </label>
                            </p>
                            <?php
                            $is_enabled = !empty($values['schedule_enabled']);
                            $next_run_local = isset($values['schedule_next_run_local']) ? (string) $values['schedule_next_run_local'] : '';
                            if ($is_enabled) {
                                if ($next_run_local !== '') {
                                    echo '<p class="description">Next scheduled run: <strong>' . esc_html($next_run_local) . '</strong></p>';
                                } else {
                                    echo '<p class="description">Schedule enabled. Next run will be set after saving settings.</p>';
                                }
                            } else {
                                echo '<p class="description">Schedule is currently disabled.</p>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="libratel_save_connector_settings" class="button button-primary" value="Save Connector Settings" />
                </p>
            </form>

            <hr />
            <h2>Connectivity Checks</h2>
            <p>Use saved settings to validate access before running sync in later phases.</p>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <form method="post" action="<?php echo $action_url; ?>">
                    <?php wp_nonce_field('libratel_test_spire_connection_nonce', 'libratel_test_spire_connection_nonce_field'); ?>
                    <input type="submit" name="libratel_test_spire_connection" class="button" value="Test Spire Connection" />
                </form>

                <form method="post" action="<?php echo $action_url; ?>">
                    <?php wp_nonce_field('libratel_test_shopify_connection_nonce', 'libratel_test_shopify_connection_nonce_field'); ?>
                    <input type="submit" name="libratel_test_shopify_connection" class="button" value="Test Shopify Connection" />
                </form>
            </div>

            <hr />
            <h2>Manual Sync</h2>
            <p>Maps Spire <code>partNo</code> to Shopify <code>SKU</code>, then processes updates in background batches so the browser request does not time out.</p>
            <form method="post" action="<?php echo $action_url; ?>">
                <?php wp_nonce_field('libratel_run_sync_nonce', 'libratel_run_sync_nonce_field'); ?>
                <input type="submit" name="libratel_run_sync_now" class="button button-primary" value="Queue Sync Now" />
            </form>

            <?php self::render_job_status($values); ?>
            <?php self::render_last_run($values); ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function render_job_status(array $values): void {
        $job_state = isset($values['job_state']) && is_array($values['job_state']) ? $values['job_state'] : [];

        echo '<hr />';
        echo '<h2>Current Job</h2>';
        if (empty($job_state)) {
            echo '<p>No background sync job is currently queued or running.</p>';
            return;
        }

        $status = isset($job_state['status']) ? (string) $job_state['status'] : 'unknown';
        $run_source = isset($job_state['run_source']) ? (string) $job_state['run_source'] : '';
        $queued_at = isset($job_state['queued_at']) ? (string) $job_state['queued_at'] : '';
        $started_at = isset($job_state['started_at']) ? (string) $job_state['started_at'] : '';
        $processed = isset($job_state['processed_skus']) ? (int) $job_state['processed_skus'] : 0;
        $total = isset($job_state['total_skus']) ? (int) $job_state['total_skus'] : 0;
        $batch_size = isset($job_state['batch_size']) ? (int) $job_state['batch_size'] : 0;

        $progress = 'Preparing Spire data...';
        if ($total > 0) {
            $percent = min(100, max(0, (int) floor(($processed / max(1, $total)) * 100)));
            $progress = sprintf('%d / %d SKUs processed (%d%%), batch size %d', $processed, $total, $percent, $batch_size);
        } elseif ($status === 'queued') {
            $progress = 'Queued and waiting for the background worker to start.';
        }

        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<tbody>';
        self::summary_row('Status', ucfirst($status));
        self::summary_row('Source', $run_source);
        self::summary_row('Queued', $queued_at);
        self::summary_row('Started', $started_at);
        self::summary_row('Progress', $progress);
        echo '</tbody>';
        echo '</table>';
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function render_last_run(array $values): void {
        $last_run = isset($values['last_run']) && is_array($values['last_run']) ? $values['last_run'] : [];
        $sync_log = isset($values['sync_log']) && is_array($values['sync_log']) ? $values['sync_log'] : [];

        echo '<hr />';
        echo '<h2>Last Run Summary</h2>';
        if (empty($last_run)) {
            echo '<p>No sync has been run yet.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:900px;">';
            echo '<tbody>';
            self::summary_row('Status', !empty($last_run['ok']) ? 'Success' : 'Error');
            self::summary_row('Message', isset($last_run['message']) ? (string) $last_run['message'] : '');
            self::summary_row('Started', isset($last_run['started_at']) ? (string) $last_run['started_at'] : '');
            self::summary_row('Finished', isset($last_run['finished_at']) ? (string) $last_run['finished_at'] : '');
            self::summary_row('Total SKUs', (string) (int) ($last_run['total_skus'] ?? 0));
            self::summary_row('SKUs Found in Shopify', (string) ((int) ($last_run['total_skus'] ?? 0) - (int) ($last_run['shopify_skus_not_found'] ?? 0)));
            self::summary_row('SKUs Not Found in Shopify', (string) (int) ($last_run['shopify_skus_not_found'] ?? 0));
            self::summary_row('Variants Matched', (string) (int) ($last_run['shopify_variants_matched'] ?? 0));
            self::summary_row('Variants Updated', (string) (int) ($last_run['shopify_variants_updated'] ?? 0));
            self::summary_row('Variants No Change', (string) (int) ($last_run['shopify_variants_no_change'] ?? 0));
            self::summary_row('Update Errors', (string) (int) ($last_run['shopify_update_errors'] ?? 0));
            self::summary_row('Spire UPC Duplicates Resolved', (string) (int) ($last_run['spire_upc_duplicates_resolved'] ?? 0));
            self::summary_row('Spire Price Duplicates Resolved', (string) (int) ($last_run['spire_price_duplicates_resolved'] ?? 0));
            echo '</tbody>';
            echo '</table>';

            $details = isset($last_run['details']) && is_array($last_run['details']) ? $last_run['details'] : [];
            $missing_skus = isset($last_run['missing_skus']) && is_array($last_run['missing_skus']) ? $last_run['missing_skus'] : [];
            if (!empty($missing_skus)) {
                echo '<h3 style="margin-top:16px;">Missing PartNos / Shopify SKUs</h3>';
                echo '<p class="description">Exact Spire <code>partNo</code> values that did not find a Shopify variant SKU match.</p>';
                echo '<textarea rows="10" style="width:100%;max-width:1100px;" readonly>';
                echo esc_textarea(implode("\n", $missing_skus));
                echo '</textarea>';
            }

            if (!empty($details)) {
                echo '<h3 style="margin-top:16px;">Last Run Details (first 200)</h3>';
                echo '<textarea rows="12" style="width:100%;max-width:1100px;" readonly>';
                $lines = [];
                foreach ($details as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    $type = isset($detail['type']) ? (string) $detail['type'] : 'detail';
                    $sku = isset($detail['sku']) ? (string) $detail['sku'] : '';
                    $message = isset($detail['message']) ? (string) $detail['message'] : '';
                    $lines[] = '[' . $type . '] SKU=' . $sku . ' :: ' . $message;
                }
                echo esc_textarea(implode("\n", $lines));
                echo '</textarea>';
            }
        }

        echo '<h2 style="margin-top:20px;">Recent Runs</h2>';
        if (empty($sync_log)) {
            echo '<p>No run history yet.</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr><th>Finished</th><th>Status</th><th>Total SKUs</th><th>Updated</th><th>Not Found</th><th>Errors</th><th>Message</th></tr></thead>';
        echo '<tbody>';
        foreach (array_slice($sync_log, 0, 10) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $finished = isset($entry['finished_at']) ? (string) $entry['finished_at'] : '';
            $status   = !empty($entry['ok']) ? 'Success' : 'Error';
            $total    = (int) ($entry['total_skus'] ?? 0);
            $updated  = (int) ($entry['shopify_variants_updated'] ?? 0);
            $notfound = (int) ($entry['shopify_skus_not_found'] ?? 0);
            $errors   = (int) ($entry['shopify_update_errors'] ?? 0);
            $message  = isset($entry['message']) ? (string) $entry['message'] : '';

            echo '<tr>';
            echo '<td>' . esc_html($finished) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html((string) $total) . '</td>';
            echo '<td>' . esc_html((string) $updated) . '</td>';
            echo '<td>' . esc_html((string) $notfound) . '</td>';
            echo '<td>' . esc_html((string) $errors) . '</td>';
            echo '<td>' . esc_html($message) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }

    private static function summary_row(string $label, string $value): void {
        echo '<tr><th style="width:280px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    public static function handle_form_submissions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['libratel_save_connector_settings'])) {
            check_admin_referer('libratel_save_connector_settings_nonce', 'libratel_save_connector_settings_nonce_field');
            Libratel_Connector_Options::save_from_request($_POST);
            if (class_exists('Libratel_Connector_Scheduler')) {
                Libratel_Connector_Scheduler::refresh_schedule_from_settings();
            }

            $next_local = Libratel_Connector_Options::next_run_local_string();
            $enabled = (bool) get_option(Libratel_Connector_Options::OPTION_SCHEDULE_ENABLED, false);
            if ($enabled && $next_local !== '') {
                self::set_notice('Libratel connector settings saved. Next scheduled run: ' . $next_local . '.', 'success');
            } elseif ($enabled) {
                self::set_notice('Libratel connector settings saved. Schedule enabled.', 'success');
            } else {
                self::set_notice('Libratel connector settings saved. Schedule disabled.', 'success');
            }
        }

        if (isset($_POST['libratel_test_spire_connection'])) {
            check_admin_referer('libratel_test_spire_connection_nonce', 'libratel_test_spire_connection_nonce_field');
            $result = Libratel_Connector_Connectivity::test_spire_connection();
            self::set_notice($result['message'], $result['ok'] ? 'success' : 'error');
        }

        if (isset($_POST['libratel_test_shopify_connection'])) {
            check_admin_referer('libratel_test_shopify_connection_nonce', 'libratel_test_shopify_connection_nonce_field');
            $result = Libratel_Connector_Connectivity::test_shopify_connection();
            self::set_notice($result['message'], $result['ok'] ? 'success' : 'error');
        }

        if (isset($_POST['libratel_run_sync_now'])) {
            check_admin_referer('libratel_run_sync_nonce', 'libratel_run_sync_nonce_field');
            $summary = Libratel_Connector_Sync_Service::run_manual_sync();
            $msg = self::format_sync_notice($summary);
            self::set_notice($msg, !empty($summary['ok']) ? 'success' : 'warning');
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function format_sync_notice(array $summary): string {
        $message = isset($summary['message']) ? (string) $summary['message'] : 'Sync finished.';
        if (strpos(strtolower($message), 'queued') !== false) {
            return $message;
        }
        $updated = (int) ($summary['shopify_variants_updated'] ?? 0);
        $notfound = (int) ($summary['shopify_skus_not_found'] ?? 0);
        $errors = (int) ($summary['shopify_update_errors'] ?? 0);
        $total = (int) ($summary['total_skus'] ?? 0);

        return sprintf(
            '%s Total SKUs: %d. Variants updated: %d. SKUs not found: %d. Errors: %d.',
            $message,
            $total,
            $updated,
            $notfound,
            $errors
        );
    }

    private static function set_notice(string $message, string $type): void {
        if (function_exists('spire_inventory_api_set_notice')) {
            spire_inventory_api_set_notice($message, $type);
            return;
        }
        add_settings_error('libratel_connector', 'libratel_connector_notice', $message, $type);
    }
}
