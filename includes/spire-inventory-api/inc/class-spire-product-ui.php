<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Spire_Product_UI
 * Adds a "Warehouse" read-only field in product edit screen
 * + a "Warehouse" column in the Products list table
 */
class Spire_Product_UI {

    public static function init() {
        // Show warehouse read-only field in product data
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'add_warehouse_display_field']);
        
        // Add "Warehouse" column to the Products list
        add_filter('manage_edit-product_columns', [__CLASS__, 'add_warehouse_column'], 15);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'render_warehouse_column'], 10, 2);

        // Hide "Warehouse" column by default
        add_filter('default_hidden_columns', [__CLASS__, 'default_hidden_columns'], 10, 2);
    }

    /**
     * Display the read-only "Warehouse" field in product data
     */
    public static function add_warehouse_display_field() {
        global $post;

        $product = wc_get_product($post->ID);
        if (!$product) {
            return;
        }

        $whse = get_post_meta($post->ID, 'warehouse_code', true);
        if (empty($whse)) {
            $whse = 'N/A';
        }
        
        echo '<div class="options_group">';
        echo '<p class="form-field">';
        echo '<label>Warehouse</label>';
        echo '<input type="text" readonly value="' . esc_attr($whse) . '" />';
        echo '<span class="description">Synced from Spire.</span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Add a "Warehouse" column to the Products list table
     */
    public static function add_warehouse_column($columns) {
        $new = [];
        foreach ($columns as $key => $title) {
            $new[$key] = $title;
            if ($key === 'sku') {
                $new['spire_warehouse'] = 'Warehouse';
            }
        }
        if (!isset($new['spire_warehouse'])) {
            $new['spire_warehouse'] = 'Warehouse';
        }
        return $new;
    }

    /**
     * Render the warehouse code in the custom column
     */
    public static function render_warehouse_column($column, $post_id) {
        if ($column === 'spire_warehouse') {
            $whse = get_post_meta($post_id, 'warehouse_code', true);
            if (!$whse) {
                $whse = '—';
            }
            echo esc_html($whse);
        }
    }

    /**
     * Hide the "Warehouse" column by default; user can enable via Screen Options
     */
    public static function default_hidden_columns($hidden, $screen) {
        if (isset($screen) && $screen->id === 'edit-product') {
            if (!in_array('spire_warehouse', $hidden, true)) {
                $hidden[] = 'spire_warehouse';
            }
        }
        return $hidden;
    }
}

