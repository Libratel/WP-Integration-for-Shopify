<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BR_Admin_New' ) ) :

class BR_Admin_New {

    const CREDS_OPTION = 'br_creds';
    const CPT          = 'br_report';

    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_global_settings' ] );
        add_action( 'wp_ajax_br_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_br_run_now', [ $this, 'ajax_run_now' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
    }

    public function register_post_type() {
        $labels = [
            'name'          => 'Back-Order Records',
            'singular_name' => 'Back-Order Record',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'supports'           => [ 'title' ],
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'publicly_queryable' => false,
            'rewrite'            => false,
        ];

        register_post_type( self::CPT, $args );
    }

    public function add_menu() {
        add_options_page(
            'Back-Order Report',
            'Back-Order Report',
            'manage_options',
            'br-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_global_settings() {
        register_setting( 'br_creds_group', self::CREDS_OPTION, [ $this, 'sanitize_creds' ] );
        add_settings_section( 'br_creds_sec', 'Header', '__return_false', 'br-settings' );

        $fields = [
            'spire_user' => [
                'label'       => 'Spire Username',
                'type'        => 'text',
                'placeholder' => '',
            ],
            'spire_pass' => [
                'label'       => 'Spire Password',
                'type'        => 'password',
                'placeholder' => '',
            ],
            'base_url'   => [
                'label'       => 'Spire Base URL',
                'type'        => 'text',
                'placeholder' => 'https://example.com:10880/api/v2',
            ],
            'sheet_id'   => [
                'label'       => 'Google-Sheet ID',
                'type'        => 'text',
                'placeholder' => '1Abc...',
            ],
            'sheet_gid'  => [
                'label'       => 'Sheet GID (tab)',
                'type'        => 'text',
                'placeholder' => '0',
            ],
        ];

        foreach ( $fields as $id => $field ) {
            add_settings_field(
                $id,
                $field['label'],
                function() use ( $id, $field ) {
                    $opt         = get_option( self::CREDS_OPTION, [] );
                    $value       = esc_attr( $opt[ $id ] ?? '' );
                    $type        = $field['type'] ?? 'text';
                    $placeholder = esc_attr( $field['placeholder'] ?? '' );

                    printf(
                        '<input type="%1$s" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" style="width:350px;">',
                        esc_attr( $type ),
                        esc_attr( self::CREDS_OPTION ),
                        esc_attr( $id ),
                        $value,
                        $placeholder
                    );
                },
                'br-settings',
                'br_creds_sec'
            );
        }
    }

    public function sanitize_creds( $in ) {
        return [
            'spire_user' => sanitize_text_field( $in['spire_user'] ?? '' ),
            'spire_pass' => sanitize_text_field( $in['spire_pass'] ?? '' ),
            'base_url'   => esc_url_raw( $in['base_url'] ?? '' ),
            'sheet_id'   => sanitize_text_field( $in['sheet_id'] ?? '' ),
            'sheet_gid'  => sanitize_text_field( $in['sheet_gid'] ?? '' ),
        ];
    }

    public function render_settings_page() {
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'delete' && ! empty( $_POST['post'] ) ) {
            foreach ( (array) $_POST['post'] as $pid ) {
                if ( current_user_can( 'delete_post', $pid ) ) {
                    wp_delete_post( $pid, true );
                }
            }
            echo '<div class="updated notice"><p>Selected records deleted.</p></div>';
        }

        $table = new BR_Records_Table();
        $table->prepare_items();

        echo '<div class="wrap"><h1>Back-Order Report</h1>';

        echo '<h2>Header</h2><form method="post" action="options.php">';
        settings_fields( 'br_creds_group' );
        do_settings_sections( 'br-settings' );
        submit_button( 'Save Header' );
        echo ' <button type="button" class="button" id="br-global-test">Test Connection</button> <span id="br-global-msg"></span>';
        echo '</form>';

        ?>
        <script>
        jQuery(function($){
            $('#br-global-test').on('click',function(){
                let b=$(this), m=$('#br-global-msg');
                b.prop('disabled',true); m.text('Testing...');
                $.post(ajaxurl,{action:'br_test_connection'},function(resp){
                    m.text(resp);
                    b.prop('disabled',false);
                });
            });
        });
        </script>
        <?php

        echo '<hr><h2>Report Records</h2>';
        echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ) . '" class="button button-primary">Add New Record</a><br><br>';
        echo '<form method="post">';
        $table->search_box( 'Search Customer No', 'record' );
        $table->display();
        echo '</form></div>';
    }

    public function ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }
        echo BR_Core::test_connection() ? 'Success' : 'Failed';
        wp_die();
    }

    public function ajax_run_now() {
        check_ajax_referer( 'br_run_now' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'No permission' );
        }

        $rid   = intval( $_POST['record_id'] ?? 0 );
        $core  = new BR_Core();
        $files = $core->run_now( $rid );

        if ( is_wp_error( $files ) ) {
            wp_send_json_error( $files->get_error_message() );
        }
        wp_send_json_success(
            [
                'when'  => wp_date( 'Y-m-d H:i', current_time( 'timestamp' ) ),
                'files' => $files,
            ]
        );
    }

    public function add_meta_boxes() {
        add_meta_box( 'br_meta', 'Report Configuration', [ $this, 'render_meta_box' ], self::CPT, 'normal', 'high' );
        add_meta_box( 'br_reports', 'Generated Reports', [ $this, 'render_reports_box' ], self::CPT, 'normal', 'default' );
    }

    private function render_text_field( WP_Post $post, string $key, string $label, string $placeholder = '' ) {
        $value = esc_attr( get_post_meta( $post->ID, $key, true ) );
        printf(
            '<p><label>%1$s<br><input type="text" name="br_%2$s" value="%3$s" placeholder="%4$s" style="width:350px;"></label></p>',
            esc_html( $label ),
            esc_attr( $key ),
            $value,
            esc_attr( $placeholder )
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'br_save_meta', 'br_meta_nonce' );

        $this->render_text_field( $post, 'customers', 'Customer Numbers (comma-separated)' );

        $use_po_number = (bool) get_post_meta( $post->ID, 'use_po_number', true );
        printf(
            '<p><label><input type="checkbox" name="br_use_po_number" value="1" %1$s> Remove ETA YYZ column from CSV</label></p>',
            checked( $use_po_number, true, false )
        );

        $days = (array) get_post_meta( $post->ID, 'days', true );
        $hour = get_post_meta( $post->ID, 'hour', true );
        $hour = $hour === '' ? 3 : (int) $hour;

        echo '<p><strong>Schedule</strong><br>';
        $labels = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
        foreach ( $labels as $i => $label ) {
            $checked = in_array( $i, $days, true ) ? 'checked' : '';
            printf(
                '<label style="margin-right:8px"><input type="checkbox" name="br_days[]" value="%1$d" %2$s> %3$s</label>',
                $i,
                $checked,
                esc_html( $label )
            );
        }
        printf(
            ' Hour: <input type="number" name="br_hour" value="%1$d" min="0" max="23" style="width:60px">',
            $hour
        );
        echo '<br><span class="description">Runs using the WordPress timezone.</span></p>';

        $this->render_text_field( $post, 'emails', 'Recipient Emails', 'a@example.com,b@example.com' );

        $rid   = $post->ID;
        $nonce = wp_create_nonce( 'br_run_now' );

        echo '<button type="button" class="button" id="br-rec-test">Test Connection</button> ';
        echo '<button type="button" class="button button-primary" id="br-rec-run">Generate Now</button> ';
        echo '<span id="br-rec-msg"></span>';

        ?>
        <script>
        jQuery(function($){
            const rid   = <?php echo (int) $rid; ?>;
            const nonce = '<?php echo esc_js( $nonce ); ?>';
            $('#br-rec-test').on('click',function(){
                let b=$(this), m=$('#br-rec-msg');
                b.prop('disabled',true); m.text('Testing...');
                $.post(ajaxurl,{action:'br_test_connection'},function(resp){
                    m.text(resp);
                    b.prop('disabled',false);
                });
            });
            $('#br-rec-run').on('click',function(){
                let b=$(this), m=$('#br-rec-msg');
                b.prop('disabled',true); m.text('Generating...');
                $.post(ajaxurl,{action:'br_run_now',record_id:rid,_ajax_nonce:nonce},function(resp){
                    if(resp.success){
                        m.text('Done');
                        location.reload();
                    }else{
                        m.text(resp.data || 'Failed');
                    }
                    b.prop('disabled',false);
                },'json');
            });
        });
        </script>
        <?php
    }

    public function render_reports_box( $post ) {
        $deleted_count = get_transient( 'br_deleted_notice_' . $post->ID );
        if ( false !== $deleted_count ) {
            delete_transient( 'br_deleted_notice_' . $post->ID );
            echo '<div class="updated notice"><p>Deleted ' . intval( $deleted_count ) . ' report(s).</p></div>';
        }

        echo '<p class="description">Select files to remove them from this record.</p>';
        echo '<p><button type="button" class="button" id="br-select-all">Select All</button></p>';
        BR_Core::render_file_table( $post->ID );
        wp_nonce_field( 'br_delete_reports', 'br_delete_nonce' );
        submit_button( 'Delete Selected Files', 'secondary', 'br_delete', false );
        submit_button(
            'Delete All Files',
            'delete',
            'br_delete_all',
            false,
            [
                'onclick' => "return confirm('Delete all report files?');",
            ]
        );

        ?>
        <script>
        jQuery(function($){
            $('#br-select-all').on('click',function(){
                const box = $('#br_reports').find('input[type="checkbox"][name="br_files[]"]');
                const allChecked = box.length && box.filter(':checked').length === box.length;
                box.prop('checked', !allChecked);
            });
        });
        </script>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['br_meta_nonce'] ) || ! wp_verify_nonce( $_POST['br_meta_nonce'], 'br_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['br_delete'], $_POST['br_delete_nonce'] ) && wp_verify_nonce( $_POST['br_delete_nonce'], 'br_delete_reports' ) ) {
            $count = 0;
            $files = isset( $_POST['br_files'] ) ? (array) wp_unslash( $_POST['br_files'] ) : [];
            foreach ( $files as $file ) {
                if ( BR_Core::delete_report_file( $post_id, $file ) ) {
                    $count++;
                }
            }
            set_transient( 'br_deleted_notice_' . $post_id, $count, MINUTE_IN_SECONDS * 5 );
        }
        if ( isset( $_POST['br_delete_all'], $_POST['br_delete_nonce'] ) && wp_verify_nonce( $_POST['br_delete_nonce'], 'br_delete_reports' ) ) {
            $count = 0;
            $files = get_post_meta( $post_id, BR_Core::REPORT_META_KEY, true );
            $files = is_array( $files ) ? $files : [];
            foreach ( $files as $file ) {
                if ( BR_Core::delete_report_file( $post_id, $file ) ) {
                    $count++;
                }
            }
            set_transient( 'br_deleted_notice_' . $post_id, $count, MINUTE_IN_SECONDS * 5 );
        }

        $customers_input = isset( $_POST['br_customers'] ) ? sanitize_text_field( wp_unslash( $_POST['br_customers'] ) ) : '';
        update_post_meta( $post_id, 'customers', $customers_input );

        $use_po_number = isset( $_POST['br_use_po_number'] ) ? 1 : 0;
        update_post_meta( $post_id, 'use_po_number', $use_po_number );

        $parts = array_filter( array_map( 'trim', explode( ',', $customers_input ) ) );
        if ( $parts ) {
            $title = implode( ', ', array_slice( $parts, 0, 3 ) );
            if ( count( $parts ) > 3 ) {
                $title .= ', ...';
            }
        } else {
            $title = 'Back-Order Record ' . $post_id;
        }

        $current_title = get_post_field( 'post_title', $post_id );
        if ( $title !== $current_title ) {
            remove_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
            wp_update_post(
                [
                    'ID'         => $post_id,
                    'post_title' => $title,
                ]
            );
            add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
        }

        if ( isset( $_POST['br_emails'] ) ) {
            $emails_input = sanitize_text_field( wp_unslash( $_POST['br_emails'] ) );
            update_post_meta( $post_id, 'emails', $emails_input );
        }

        $hour = isset( $_POST['br_hour'] ) ? (int) $_POST['br_hour'] : 3;
        $hour = max( 0, min( 23, $hour ) );
        update_post_meta( $post_id, 'hour', $hour );

        $days = isset( $_POST['br_days'] ) ? (array) $_POST['br_days'] : [];
        $days = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $days
                    ),
                    function ( $day ) {
                        return $day >= 0 && $day <= 6;
                    }
                )
            )
        );
        update_post_meta( $post_id, 'days', $days );
    }
}

endif;
