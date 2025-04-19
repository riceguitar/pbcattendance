<?php
/**
 * Handles frontend functionality for user attendance management
 */
class PBAttend_Frontend_Controller {
    public function __construct() {
        // Redirect subscribers from wp-admin
        add_action('admin_init', array($this, 'redirect_subscribers'));
        
        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_pbattend_update_notes', array($this, 'handle_note_update'));
    }

    /**
     * Redirect subscribers from wp-admin
     */
    public function redirect_subscribers() {
        if (is_admin() && current_user_can('subscriber') && !wp_doing_ajax()) {
            // Find the attendance dashboard page
            $dashboard_page = get_posts(array(
                'post_type' => 'page',
                'meta_key' => '_wp_page_template',
                'meta_value' => 'page-templates/attendance-dashboard.php',
                'posts_per_page' => 1
            ));

            if (!empty($dashboard_page)) {
                wp_redirect(get_permalink($dashboard_page[0]->ID));
                exit;
            }
        }
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        if (is_page_template('page-templates/attendance-dashboard.php')) {
            wp_enqueue_style(
                'pbattend-frontend',
                PBATTEND_PLUGIN_URL . 'assets/css/user-attendance.css',
                array(),
                PBATTEND_VERSION
            );
        }
    }

    /**
     * Handle note update form submission
     */
    public function handle_note_update() {
        if (!isset($_POST['pbattend_nonce']) || !wp_verify_nonce($_POST['pbattend_nonce'], 'pbattend_update_notes')) {
            wp_die('Invalid nonce');
        }

        if (!is_user_logged_in()) {
            wp_die('You must be logged in');
        }

        $record_id = isset($_POST['record_id']) ? intval($_POST['record_id']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (!$this->can_edit_record($record_id)) {
            wp_die('You do not have permission to edit this record');
        }

        update_field('attendance_details_attendance_note', $notes, $record_id);
        update_field('review_status', 'waiting', $record_id);

        wp_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }

    /**
     * Get user's attendance records
     */
    public function get_user_records($status = 'all', $page = 1, $per_page = 10) {
        $user_id = get_current_user_id();
        $user_student_id = get_field('student_id', 'user_' . $user_id);

        $args = array(
            'post_type' => 'pbattend_record',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'meta_query' => array(
                array(
                    'key' => 'student_id',
                    'value' => $user_student_id
                )
            )
        );

        if ($status !== 'all') {
            $args['meta_query'][] = array(
                'key' => 'review_status',
                'value' => $status
            );
        }

        return new WP_Query($args);
    }

    /**
     * Check if user can edit a record
     */
    public function can_edit_record($record_id) {
        $user_id = get_current_user_id();
        $student_id = get_field('student_id', $record_id);
        $review_status = get_field('review_status', $record_id);
        $notes = get_field('attendance_details_attendance_note', $record_id);

        return $user_id == $student_id && 
               in_array($review_status, array('pending', '')) && 
               empty($notes);
    }
} 