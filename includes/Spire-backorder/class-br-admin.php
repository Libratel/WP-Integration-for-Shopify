<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI + Settings page.
 *
 * Uses Settings API for safety.  :contentReference[oaicite:4]{index=4}
 */
class BR_Admin {

    const OPTION = 'br_options';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_br_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_br_run_now', [ $this, 'ajax_run_now' ] );
    }

    /* ---------- Admin menu ---------- */
    public function add_menu() {
        add_options_page(
            'Back-Order Report',
            'Back-Order Report',
            'manage_options',
            'br-settings',
            [ $this, 'render_page' ]
        );
    }

    /* ---------- Settings registration ---------- */
    public function register_settings() {

        register_setting(
            self::OPTION,
            self::OPTION,
            [ 'sanitize_callback' => [ $this, 'sanitize' ] ]
        );

        add_settings_section( 'br_main', 'Configuration', '__return_false', 'br-settings' );

        add_settings_field(
            'customers',
            'Customer Numbers',
            [ $this, 'field_customers' ],
            'br-settings', 'br_main'
        );
        add_settings_field(
            'spire_user',
            'Spire Username',
            [ $this, 'field_text' ], 'br-settings', 'br_main',
            [ 'id' => 'spire_user' ]
        );
        add_settings_field(
            'spire_pass',
            'Spire Password',
            [ $this, 'field_password' ], 'br-settings', 'br_main',
            [ 'id' => 'spire_pass' ]
        );
        add_settings_field(
            'schedule',
            'Schedule',
            [ $this, 'field_schedule' ],
            'br-settings', 'br_main'
        );
        add_settings_field(
            'emails',
            'Recipient Emails',
            [ $this, 'field_text' ], 'br-settings', 'br_main',
            [ 'id' => 'emails', 'placeholder' => 'a@example.com,b@example.com' ]
        );
        add_settings_field(
            'base_url',
            'Spire Base URL',
            [ $this, 'field_text' ],
            'br-settings', 'br_main',
            [ 'id' => 'base_url', 'placeholder' => 'https://example.com:10880/api/v2/' ]
            );

        // Google-Sheet
        add_settings_field(
            'sheet_id',
            'Google-Sheet ID',
            [ $this, 'field_text' ],          // reuse the generic text handler
            'br-settings',                    // page
            'br_main',                        // section
            [ 'id' => 'sheet_id', 'placeholder' => '1Kcz…' ]
        );

        add_settings_field(
            'sheet_gid',
            'Sheet GID (tab)',
            [ $this, 'field_text' ],
            'br-settings',
            'br_main',
            [ 'id' => 'sheet_gid', 'placeholder' => '0' ]
        );
    }

    /* ---------- Field callbacks ---------- */

    public function field_customers() {
        $opt       = get_option( self::OPTION, [] );
        $cust_str  = $opt['customers'] ?? '';
        printf(
            '<input type="text" name="%1$s[customers]" value="%2$s" style="width:400px;" placeholder="EASYHOME,BESTBUY,ANOTHER_CUST">',
            esc_attr( self::OPTION ),
            esc_attr( $cust_str )
        );
        echo '<p class="description">Comma-separate one or more customer numbers.</p>';
    }

    public function field_text( $args ) {
        $opt = get_option( self::OPTION, [] );
        $id  = $args['id'];
        printf(
            '<input type="text" name="%1$s[%2$s]" value="%3$s" style="width:300px" placeholder="%4$s">',
            esc_attr( self::OPTION ),
            esc_attr( $id ),
            esc_attr( $opt[ $id ] ?? '' ),
            esc_attr( $args['placeholder'] ?? '' )
        );
    }

    public function field_password( $args ) {
        $opt = get_option( self::OPTION, [] );
        $id  = $args['id'];
        printf(
            '<input type="password" name="%1$s[%2$s]" value="%3$s" autocomplete="off" style="width:300px">',
            esc_attr( self::OPTION ),
            esc_attr( $id ),
            esc_attr( $opt[ $id ] ?? '' )
        );
    }

    public function field_schedule() {
        $opt   = get_option( self::OPTION, [] );
        $days  = $opt['days']  ?? [];
        $hour  = $opt['hour']  ?? 3; // UTC by default
        $dow   = [ 'Sun','Mon','Tue','Wed','Thu','Fri','Sat' ];
        foreach ( $dow as $i => $label ) {
            printf(
                '<label style="margin-right:8px"><input type="checkbox" name="%1$s[days][]" value="%2$d" %3$s> %4$s</label>',
                esc_attr( self::OPTION ),
                $i,
                checked( in_array( $i, $days ), true, false ),
                esc_html( $label )
            );
        }
        echo '<br>Hour (0-23): ';
        printf(
            '<input type="number" min="0" max="23" name="%1$s[hour]" value="%2$d" style="width:60px">',
            esc_attr( self::OPTION ),
            esc_attr( $hour )
        );
    }

    /* ---------- Sanitizer ---------- */
    public function sanitize( $in ) {
        $clean                         = [];
        $clean['customers']            = sanitize_text_field( $in['customers'] ?? '' );
        $clean['spire_user']           = sanitize_text_field( $in['spire_user'] ?? '' );
        $clean['spire_pass']           = sanitize_text_field( $in['spire_pass'] ?? '' );
        $clean['emails']               = sanitize_text_field( $in['emails'] ?? '' );
        $clean['hour']                 = (int) ( $in['hour'] ?? 3 );
        $clean['days']                 = array_map( 'intval', $in['days'] ?? [] );
        $clean['base_url']             = esc_url_raw( $in['base_url'] ?? '' );

        $clean['sheet_id']  = sanitize_text_field( $in['sheet_id'] ?? '' );
        $clean['sheet_gid'] = sanitize_text_field( $in['sheet_gid'] ?? '0' );


        // Reschedule cron on save.
        BR_Core::reschedule( $clean );
        return $clean;
    }

    /* ---------- Page renderer ---------- */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Back-Order Report Settings</h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION );  // nonce + option group :contentReference[oaicite:5]{index=5}
                do_settings_sections( 'br-settings' );
                submit_button();
                ?>
                <button type="button" class="button secondary" id="br-test-conn">Test Connection</button>
                <span id="br-test-result"></span>
            <?php wp_nonce_field( 'br_run_now', 'br_run_now_nonce' ); ?>
            <button type="button" class="button button-primary" id="br-run-now">
                ⏱ Generate Now
            </button>
            <span id="br-run-result"></span>
            </form>
            <hr>
            <h2>Generated Reports</h2>
            <?php BR_Core::render_file_table(); ?>
        </div>
        <script>
        document.getElementById('br-test-conn').addEventListener('click', () => {
            const btn = event.target, span = document.getElementById('br-test-result');
            btn.disabled = true; span.textContent = 'Testing…';
            fetch(ajaxurl, {method:'POST', body:new URLSearchParams({action:'br_test_connection'})})
                .then(r=>r.text()).then(txt=>{span.textContent=txt; btn.disabled=false;});
        });
        </script>
        <script>
            document.getElementById('br-run-now').addEventListener('click', () => {
                const btn  = event.target,
                    span = document.getElementById('br-run-result'),
                    nonce = document.querySelector('[name="br_run_now_nonce"]').value;
                btn.disabled = true; span.textContent = 'Generating…';
                fetch(ajaxurl, {
                    method: 'POST',
                    body: new URLSearchParams({action:'br_run_now', _ajax_nonce:nonce})
                })
                .then(r=>r.json())
                .then(data=>{
                    if (data.success){
                        span.textContent = '✅ Done ('+data.data.when+')';
                        // Reload the table so the new file appears
                        location.reload();
                    } else {
                        span.textContent = '❌ '+data.data;
                    }
                    btn.disabled = false;
                });
            });
        </script>
        <?php
    }

    /* ---------- AJAX connection test ---------- */
    public function ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $ok = BR_Core::test_connection();
        echo $ok ? '✅ Success' : '❌ Failed';
        wp_die();
    }

    public function ajax_run_now() {
        check_ajax_referer( 'br_run_now' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

        $core   = new BR_Core();
        $files  = $core->run_now();         // returns array|WP_Error
        if ( is_wp_error( $files ) ) {
            wp_send_json_error( $files->get_error_message() );
        }
        wp_send_json_success( [ 
            'when'  => wp_date( 'Y-m-d H:i', current_time( 'timestamp' ) ),
            'files' => $files,
        ] );
    }
}
