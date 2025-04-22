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
        
        // Add capabilities for subscribers
        add_action('init', array($this, 'add_subscriber_capabilities'));

        // Function to update review status after form submission
        add_action('acf/save_post', array($this, 'update_review_status'), 20);
    }

    /**
     * Add necessary capabilities for subscribers
     */
    public function add_subscriber_capabilities() {
        $role = get_role('subscriber');
        if ($role) {
            $role->add_cap('edit_posts');
            $role->add_cap('edit_published_posts');
        }
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
        $user_student_id = get_field('student_id', 'user_' . $user_id);
        $record_student_id = get_field('student_id', $record_id);
        $review_status = get_post_meta($record_id, 'review_status', true);

        error_log('PBAttend Debug - User Student ID: ' . $user_student_id);
        error_log('PBAttend Debug - Record Student ID: ' . $record_student_id);
        error_log('PBAttend Debug - Review Status: ' . $review_status);

        return $user_student_id == $record_student_id && in_array($review_status, array('pending', ''));
    }

    /**
     * Update review status when notes are submitted from frontend editor
     */
    public function update_review_status($post_id) {
        // Only update if this is an attendance record
        if (get_post_type($post_id) !== 'pbattend_record') {
            return;
        }

        // Don't update if we're in the admin area
        if (is_admin()) {
            return;
        }

        // Check if this is coming from our frontend editor
        // We can check the referer URL to see if it's from our editor page
        $referer = wp_get_referer();
        if (!$referer || strpos($referer, '/attendance-editor') === false) {
            return;
        }
        
        update_field('field_review_status', 'review', $post_id);
    }
} 