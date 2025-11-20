<?php
/**
 * Handles frontend functionality for user attendance management
 */

namespace PBAttend;

if (!class_exists('PBAttend\Frontend_Controller')) {
    class Frontend_Controller {
        public function __construct() {
            $this->register_hooks();
        }

        private function register_hooks() {
            // Redirect subscribers from wp-admin
            add_action('admin_init', array($this, 'redirect_subscribers'));
            
            // Register scripts and styles
            add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
            
            // Add capabilities for subscribers
            add_action('init', array($this, 'add_subscriber_capabilities'));

            // Hide admin bar for subscribers
            add_action('after_setup_theme', array($this, 'hide_admin_bar_for_subscribers'));

            // Function to update review status after form submission
            add_action('acf/save_post', array($this, 'update_review_status'), 20);
        }

        /**
         * Hides the admin bar for users with the 'subscriber' role.
         */
        public function hide_admin_bar_for_subscribers() {
            if (current_user_can('subscriber')) {
                show_admin_bar(false);
            }
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
            if (is_page_template('page-templates/attendance-dashboard.php') || is_page_template('page-templates/attendance-editor.php')) {
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
        public function get_user_records($status = 'all', $page = 1, $per_page = 1000) {
            $user_id = get_current_user_id();
            $user = get_userdata($user_id);
            
            // Try to get student_id from user meta, which should be set by SSO.
            $user_populi_id = get_user_meta($user_id, 'populi_id', true);
            
            // If still no student_id, the user is not linked. Return empty query.
            if (!$user_populi_id) {
                // We don't try to sync here anymore, just return an empty result.
                // The on-login hook handles the sync.
                return new \WP_Query(array('post_type' => 'pbattend_record', 'post__in' => array(0)));
            }

            $args = array(
                'post_type' => 'pbattend_record',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'orderby' => 'meta_value',
                'meta_key' => 'attendance_details_meeting_start_time',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => 'populi_id',
                        'value' => $user_populi_id
                    )
                )
            );

            if ($status !== 'all') {
                $args['meta_query'][] = array(
                    'key' => 'review_status',
                    'value' => $status
                );
            }

            return new \WP_Query($args);
        }

        /**
         * Check if user can edit a record
         */
        public function can_edit_record($record_id) {
            $user_id = get_current_user_id();
            $user_populi_id = get_field('populi_id', 'user_' . $user_id);
            $record_populi_id = get_field('populi_id', $record_id);
            $review_status = get_post_meta($record_id, 'review_status', true);

            error_log('PBAttend Debug - User Populi ID: ' . $user_populi_id);
            error_log('PBAttend Debug - Record Populi ID: ' . $record_populi_id);
            error_log('PBAttend Debug - Review Status: ' . $review_status);

            return $user_populi_id == $record_populi_id && in_array($review_status, array('pending', ''));
        }

        /**
         * Update review status when notes are submitted from frontend editor
         */
        public function update_review_status($post_id) {
            // Only update if this is an attendance record
            if (get_post_type($post_id) !== 'pbattend_record' || is_admin()) {
                return;
            }

            // We only want to trigger this after our specific form on the front-end is submitted.
            // The acf_form function includes a hidden field '_acf_post_id' that we can check.
            if (!isset($_POST['_acf_post_id']) || intval($_POST['_acf_post_id']) !== $post_id) {
                return;
            }

            // Get current status
            $current_status = get_field('review_status', $post_id);

            // Only update if current status is 'pending' or empty
            if ($current_status === 'pending' || $current_status === '') {
                update_field('review_status', 'review', $post_id);
            }
        }
    }
} 