<?php
/**
 * Handles custom post type registration
 */
class PBAttend_Post_Types {
    public function __construct() {
        add_action('init', array($this, 'register_attendance_post_type'));
        add_action('init', array($this, 'register_taxonomies'));
        
        // Add custom columns
        add_filter('manage_pbattend_record_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_pbattend_record_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_filter('manage_edit-pbattend_record_sortable_columns', array($this, 'make_columns_sortable'));
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
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => true,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-calendar-alt',
            'hierarchical'        => false,
            'supports'            => array('title', 'author'),
            'has_archive'         => false,
            'rewrite'            => array('slug' => 'attendance'),
            'show_in_rest'        => true,
        );

        register_post_type('pbattend_record', $args);
    }

    public function register_taxonomies() {
        // ... existing taxonomy registration code ...
    }

    /**
     * Set custom columns for attendance records
     */
    public function add_custom_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'title') {
                $new_columns[$key] = $value;
                $new_columns['student_info'] = 'Student';
                $new_columns['review_status'] = 'Review Status';
            } else {
                $new_columns[$key] = $value;
            }
        }
        return $new_columns;
    }

    /**
     * Render custom columns
     */
    public function render_custom_columns($column, $post_id) {
        switch ($column) {
            case 'student_info':
                $first_name = get_field('first_name', $post_id);
                $last_name = get_field('last_name', $post_id);
                $student_id = get_field('student_id', $post_id);
                echo esc_html($first_name . ' ' . $last_name);
                if ($student_id) {
                    echo '<br><small>ID: ' . esc_html($student_id) . '</small>';
                }
                break;
            case 'review_status':
                $status = get_field('field_review_status', $post_id);
                $status_class = 'status-' . $status;
                echo '<span class="review-status ' . esc_attr($status_class) . '">' . esc_html(ucfirst($status)) . '</span>';
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function make_columns_sortable($columns) {
        $columns['review_status'] = 'review_status';
        return $columns;
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
            .status-pending {
                background-color: #f0f0f1;
                color: #1d2327;
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