<?php
/**
 * Handles custom post type registration
 */
class PBAttend_Post_Types {
    public function __construct() {
        add_action('init', array($this, 'register_attendance_post_type'));
        
        // Add custom columns
        add_filter('manage_pbattend_record_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_pbattend_record_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_filter('manage_edit-pbattend_record_sortable_columns', array($this, 'make_columns_sortable'));
        add_action('pre_get_posts', array($this, 'custom_column_orderby'));
        add_action('admin_head', array($this, 'add_admin_styles'));
    }

    public function register_attendance_post_type() {
        $labels = array(
            'name'               => _x('Attendance Records', 'post type general name', 'pbattend'),
            'singular_name'      => _x('Attendance Record', 'post type singular name', 'pbattend'),
            'menu_name'          => _x('Attendance', 'admin menu', 'pbattend'),
            'add_new'            => _x('Add New', 'attendance', 'pbattend'),
            'add_new_item'       => __('Add New Attendance Record', 'pbattend'),
            'edit_item'          => __('Edit Attendance Record', 'pbattend'),
            'new_item'           => __('New Attendance Record', 'pbattend'),
            'view_item'          => __('View Attendance Record', 'pbattend'),
            'search_items'       => __('Search Attendance Records', 'pbattend'),
            'not_found'          => __('No attendance records found', 'pbattend'),
            'not_found_in_trash' => __('No attendance records found in Trash', 'pbattend'),
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-calendar-alt',
            'hierarchical'        => false,
            'supports'            => array('title', 'author'),
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
        );

        register_post_type('pbattend_record', $args);
    }

    /**
     * Set custom columns for attendance records
     */
    public function add_custom_columns($columns) {
        unset($columns['author']);
        unset($columns['date']); // We will re-add this in our desired order

        $new_columns = array(
            'cb' => $columns['cb'],
            'title' => __('Title', 'pbattend'),
            'student' => __('Student', 'pbattend'),
            'date' => __('Date'), // WordPress default date column
            'status' => __('Status', 'pbattend'),
            'start_time' => __('Start Time', 'pbattend'),
            'review_status' => __('Review Status', 'pbattend'),
            'notes' => __('Notes', 'pbattend'),
        );
        return $new_columns;
    }

    /**
     * Render custom columns
     */
    public function render_custom_columns($column, $post_id) {
        switch ($column) {
            case 'student':
                $first_name = get_field('first_name', $post_id);
                $last_name = get_field('last_name', $post_id);
                echo esc_html($first_name . ' ' . $last_name);
                
                $populi_id = get_field('populi_id', $post_id);
                if ($populi_id) {
                    echo '<br><small>ID: ' . esc_html($populi_id) . '</small>';
                }
                break;
            
            case 'status':
                echo esc_html(get_field('attendance_details_attendance_status', $post_id));
                break;
            
            case 'start_time':
                $meeting_time = get_field('attendance_details_meeting_start_time', $post_id);
                if ($meeting_time) {
                    echo esc_html(date('g:iA, M j, Y', strtotime($meeting_time)));
                }
                break;
            
            case 'review_status':
                $status = get_field('review_status', $post_id);
                $status_class = 'status-' . ($status ?: 'pending');
                echo '<span class="review-status ' . esc_attr($status_class) . '">' . esc_html(ucfirst($status) ?: 'Pending') . '</span>';
                break;

            case 'notes':
                $notes = get_field('attendance_note', $post_id);
                if (strlen($notes) > 100) {
                    echo esc_html(substr($notes, 0, 100)) . '...';
                } else {
                    echo esc_html($notes) ?: '—';
                }
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function make_columns_sortable($columns) {
        $columns['start_time'] = 'start_time';
        $columns['review_status'] = 'review_status';
        return $columns;
    }

    public function custom_column_orderby($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ('start_time' === $orderby) {
            $query->set('meta_key', 'attendance_details_meeting_start_time');
            $query->set('orderby', 'meta_value');
        }

        if ('review_status' === $orderby) {
            $query->set('meta_key', 'review_status');
            $query->set('orderby', 'meta_value');
        }
    }

    public function add_admin_styles() {
        ?>
        <style>
            .review-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                line-height: 1.4;
                font-weight: 600;
            }
            .status-pending, .status- {
                background-color: #f0f0f1;
                color: #1d2327;
            }
            .status-review {
                background-color: #f0f8ff;
                color: #1a4624;
            }
            .status-approved {
                background-color: #d1f7cb;
                color: #1a4624;
            }
            .status-rejected {
                background-color: #f7d7da;
                color: #8a2424;
            }
        </style>
        <?php
    }
} 