<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_admin() ) {
    return;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if ( ! class_exists( 'BR_Records_Table' ) ) :

class BR_Records_Table extends WP_List_Table {

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox" />',
            'title'    => 'Customer Numbers',
            'schedule' => 'Schedule (WP Time)',
            'reports'  => 'Generated Reports',
        ];
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="post[]" value="%d" />', $item->ID );
    }

    protected function column_title( $item ) {
        $customers = get_post_meta( $item->ID, 'customers', true );
        $label     = $customers ? $customers : '(no customers)';
        $edit_link = get_edit_post_link( $item->ID );

        $actions = [
            'edit'   => sprintf( '<a href="%s">Edit</a>', esc_url( $edit_link ) ),
            'delete' => sprintf( '<a href="%s" onclick="return confirm(\'Delete this record?\');">Delete</a>', esc_url( get_delete_post_link( $item->ID, '', true ) ) ),
        ];

        return sprintf(
            '<a href="%1$s">%2$s</a>%3$s',
            esc_url( $edit_link ),
            esc_html( $label ),
            $this->row_actions( $actions )
        );
    }

    protected function column_schedule( $item ) {
        $days = get_post_meta( $item->ID, 'days', true );
        $hour = (int) get_post_meta( $item->ID, 'hour', true );
        $hour = max( 0, min( 23, $hour ) );
        $hour_text = sprintf( '%02d:00', $hour );

        $labels = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
        $day_names = [];
        if ( is_array( $days ) ) {
            foreach ( $days as $day ) {
                $day = intval( $day );
                if ( isset( $labels[ $day ] ) ) {
                    $day_names[] = $labels[ $day ];
                }
            }
        }

        if ( ! $day_names ) {
            return '—';
        }

        return esc_html( implode( ', ', $day_names ) . ' @ ' . $hour_text );
    }

    protected function column_reports( $item ) {
        $files = get_post_meta( $item->ID, BR_Core::REPORT_META_KEY, true );
        $count = is_array( $files ) ? count( $files ) : 0;

        if ( ! $count ) {
            return 'None';
        }

        $text = sprintf( '%d file%s', $count, $count === 1 ? '' : 's' );
        $link = get_edit_post_link( $item->ID );
        if ( $link ) {
            $link .= '#br_reports';
            return sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $text ) );
        }

        return esc_html( $text );
    }

    public function get_bulk_actions() {
        return [ 'delete' => 'Delete' ];
    }

    public function no_items() {
        echo 'No back-order records found.';
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = [];
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page = 20;
        $paged    = $this->get_pagenum();
        $search   = sanitize_text_field( $_REQUEST['s'] ?? '' );

        $query = new WP_Query(
            [
                'post_type'      => BR_Admin_New::CPT,
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                's'              => $search,
            ]
        );

        $this->items = $query->posts;

        $this->set_pagination_args(
            [
                'total_items' => $query->found_posts,
                'per_page'    => $per_page,
                'total_pages' => $query->max_num_pages,
            ]
        );
    }
}

endif;
