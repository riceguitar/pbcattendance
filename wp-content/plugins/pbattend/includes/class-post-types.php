<?php
class PBAttend_Post_Types {
    public function __construct() {
        add_action('init', array($this, 'register_attendance_post_type'));
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
} 