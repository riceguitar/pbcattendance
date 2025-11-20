<?php
/**
 * Handles importing attendance records and users from Populi on a per-user basis.
 */
class PBAttend_Populi_Importer {
    // Base API URL
    private $api_base = 'https://pbc.populiweb.com/api2';
    
    // API Endpoints
    private $endpoints = array(
        'attendance' => '/attendance/detail',
        'person'     => '/people',
        'student'    => '/people/%d/student',
        'email'      => '/people/%d/emailaddresses'
    );
    
    private $import_log_key = 'pbattend_import_log';
    private $user_last_sync_key = 'pbattend_last_sync_timestamp';

    public function __construct() {
        // Add login hook for user sync
        add_action('wp_login', array($this, 'sync_on_login'), 10, 2);
        
        // Add manual sync action for admin profile page
        add_action('admin_post_pbattend_manual_sync', array($this, 'handle_manual_sync'));
        
        // Add button to user profile page
        add_action('show_user_profile', array($this, 'add_sync_button_to_profile'));
        add_action('edit_user_profile', array($this, 'add_sync_button_to_profile'));

        // Add admin notices
        add_action('admin_notices', array($this, 'display_import_notices'));

        // Add daily cron for student cache refresh
        add_filter('cron_schedules', array($this, 'add_daily_cron_schedule'));
        if (!wp_next_scheduled('pbattend_refresh_student_cache_cron')) {
            wp_schedule_event(time(), 'daily', 'pbattend_refresh_student_cache_cron');
        }
        add_action('pbattend_refresh_student_cache_cron', array($this, 'refresh_student_cache'));
    }

    /**
     * Adds a 'daily' schedule to the WordPress cron schedules.
     */
    public function add_daily_cron_schedule($schedules) {
        $schedules['daily'] = array(
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Once Daily', 'pbattend')
        );
        return $schedules;
    }

    /**
     * Get the Populi API credentials
     */
    private function get_api_credentials() {
        return array(
            'api_key' => get_option('pbattend_populi_api_key'),
            'api_base' => get_option('pbattend_populi_api_base', $this->api_base)
        );
    }

    /**
     * Get API endpoint URL
     */
    private function get_endpoint_url($endpoint_key) {
        $credentials = $this->get_api_credentials();
        return trailingslashit($credentials['api_base']) . ltrim($this->endpoints[$endpoint_key], '/');
    }

    /**
     * Display admin notices
     */
    public function display_import_notices() {
        $notice = get_transient('pbattend_admin_notice');
        if ($notice) {
            ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
            <?php
            delete_transient('pbattend_admin_notice');
        }
    }

    /**
     * Log import activity
     */
    public function log_import($message, $type = 'info') {
        $log = get_option($this->import_log_key, array());
        $log[] = array(
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'message' => $message
        );
        
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option($this->import_log_key, $log);
    }

    /**
     * Hook for wp_login to trigger sync.
     * This should be made asynchronous in the future.
     */
    public function sync_on_login($user_login, $user) {
        $this->log_import(sprintf('User %s (ID: %d) logged in. Triggering attendance sync.', $user_login, $user->ID));
        $this->sync_student_attendance($user->ID);
    }

    /**
     * Main function to sync a single student's attendance records.
     * @param int $user_id The WordPress User ID.
     * @return array Status of the sync operation.
     */
    public function sync_student_attendance($user_id) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('Sync failed: API credentials not configured', 'error');
            return array('success' => false, 'message' => 'Populi API credentials not configured');
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->log_import("Sync failed: User ID {$user_id} not found.", 'error');
            return array('success' => false, 'message' => 'User not found.');
        }

        // Ensure the user is linked to a Populi ID.
        $populi_id = get_user_meta($user_id, 'populi_id', true);
        if (empty($populi_id)) {
            $this->log_import("User {$user->user_login} has no Populi ID. Attempting to sync user data first.", 'info');
            // `sync_user_by_email` will find the populi ID and update the user meta.
            $this->sync_user_by_email($user); 
            $populi_id = get_user_meta($user_id, 'populi_id', true);

            if (empty($populi_id)) {
                 $this->log_import("Could not find a matching Populi record for user {$user->user_login}.", 'warning');
                return array('success' => false, 'message' => 'Could not link user to a Populi record.');
            }
        }

        $last_sync_time = get_user_meta($user_id, $this->user_last_sync_key, true);
        $new_sync_time = current_time('mysql');

        $this->log_import("Starting attendance sync for user {$user->user_login} (Populi ID: {$populi_id}). Last sync: " . ($last_sync_time ?: 'Never'));

        try {
            $current_page = 1;
            $total_new_records = 0;

            while (true) {
                $request_body = $this->build_student_attendance_request_body($populi_id, $last_sync_time, $current_page);
                
                $response = wp_remote_post($this->get_endpoint_url('attendance'), array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $credentials['api_key']
                    ),
                    'body' => json_encode($request_body),
                    'timeout' => 30
                ));

                if (is_wp_error($response)) {
                    throw new Exception('API request failed: ' . $response->get_error_message());
                }

                $response_data = json_decode(wp_remote_retrieve_body($response), true);

                if (empty($response_data) || !isset($response_data['data']) || !is_array($response_data['data'])) {
                    break;
                }

                foreach ($response_data['data'] as $record) {
                    if ($this->create_attendance_record($record)) {
                        $total_new_records++;
                    }
                }

                if (!isset($response_data['has_more']) || !$response_data['has_more']) {
                    break;
                }
                $current_page++;
            }

            update_user_meta($user_id, $this->user_last_sync_key, $new_sync_time);

            $message = sprintf('Sync completed for %s. Imported %d new records.', $user->display_name, $total_new_records);
            $this->log_import($message, 'info');
            return array('success' => true, 'message' => $message, 'new_records' => $total_new_records);

        } catch (Exception $e) {
            $this->log_import('An error occurred during sync for user ' . $user->user_login . ': ' . $e->getMessage(), 'error');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Build the request body for a single student's attendance records.
     */
    private function build_student_attendance_request_body($student_populi_id, $last_import_time = null, $page = 1) {
        $academic_term = get_option('pbattend_populi_academic_term'); // Assumes term ID is stored in options
        if (empty($academic_term)) {
            $this->log_import('Cannot build request: Academic Term is not set in plugin settings.', 'error');
            // Use a fallback or throw an exception
            $academic_term = '302974'; // Fallback to avoid fatal errors
        }

        $filter = array(
            '0' => array(
                'logic' => 'ALL',
                'fields' => array(
                    array('name' => 'has_active_student_role', 'value' => 'YES', 'positive' => '1'),
                    array('name' => 'academic_term', 'value' => $academic_term, 'positive' => '1'),
                    array('name' => 'student', 'value' => array('id' => $student_populi_id), 'positive' => '1')
                )
            ),
            '1' => array(
                'logic' => 'ANY',
                'fields' => array(
                    array('name' => 'status', 'value' => 'TARDY', 'positive' => '1'),
                    array('name' => 'status', 'value' => 'ABSENT', 'positive' => '1')
                )
            )
        );

        if ($last_import_time) {
            $filter[0]['fields'][] = array(
                'name' => 'event_start_time',
                'value' => array(
                    'type' => 'GREATER',
                    'start' => $last_import_time
                ),
                'positive' => '1'
            );
        }

        return array(
            'filter' => $filter,
            'page' => $page,
            'results_per_page' => 100
        );
    }

    /**
     * Create or update an attendance record post.
     * Returns true if a new post was created, false otherwise.
     */
    private function create_attendance_record($record) {
        if (!isset($record['id']) || !isset($record['report_data'])) {
            $this->log_import('Invalid record format: Missing required fields', 'error');
            return false;
        }

        $populi_row_id = $record['report_data']['row_id'] ?? '';
        
        if (!empty($populi_row_id)) {
            $existing_posts = get_posts(array(
                'post_type' => 'pbattend_record',
                'meta_query' => array(
                    array('key' => 'populi_row_id', 'value' => $populi_row_id, 'compare' => '=')
                ),
                'posts_per_page' => 1, 'fields' => 'ids'
            ));
            
            if (!empty($existing_posts)) {
                return false; // Record already exists, not an error.
            }
        }

        $post_id = wp_insert_post(array(
            'post_type' => 'pbattend_record',
            'post_status' => 'publish',
            'post_title' => sprintf(
                '%s - %s (%s)',
                $record['display_name'] ?? 'Unknown Student',
                $record['report_data']['course_name'] ?? 'Unknown Course',
                $record['report_data']['meeting_start_time'] ?? 'Unknown Time'
            )
        ));

        if (is_wp_error($post_id)) {
            $this->log_import('Failed to create post: ' . $post_id->get_error_message(), 'error');
            return false;
        }

        // Update ACF fields
        update_field('populi_row_id', $populi_row_id, $post_id);
        update_field('student_id', $record['id'], $post_id);
        update_field('first_name', $record['first_name'] ?? '', $post_id);
        update_field('last_name', $record['last_name'] ?? '', $post_id);
        update_field('course_info_course_id', $record['report_data']['course_offering_id'] ?? '', $post_id);
        update_field('course_info_course_name', $record['report_data']['course_name'] ?? '', $post_id);
        update_field('course_info_term_name', $record['report_data']['term_name'] ?? '', $post_id);
        update_field('attendance_details_meeting_start_time', $record['report_data']['meeting_start_time'] ?? '', $post_id);
        update_field('attendance_details_meeting_end_time', $record['report_data']['meeting_end_time'] ?? '', $post_id);
        update_field('attendance_details_attendance_status', $record['report_data']['attendance_status'] ?? '', $post_id);
        update_field('attendance_note', $record['report_data']['attendance_note'] ?? '', $post_id);
        update_field('meta_info_attendance_added_at', $record['report_data']['attendance_added_at'] ?? '', $post_id);
        update_field('meta_info_attendance_added_by', $record['report_data']['attendance_added_by'] ?? '', $post_id);

        $this->log_import('Created post with ID: ' . $post_id, 'info');
        return true;
    }

    /**
     * Adds the "Sync with Populi" button to the user profile page.
     */
    public function add_sync_button_to_profile($user) {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <h2><?php _e('Populi Attendance Sync', 'pbattend'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="populi-sync"><?php _e('Manual Sync', 'pbattend'); ?></label></th>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="pbattend_manual_sync">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                        <?php wp_nonce_field('pbattend_manual_sync_nonce', 'pbattend_nonce'); ?>
                        <?php submit_button(__('Import/Sync Attendance Records'), 'secondary', 'submit', false); ?>
                    </form>
                    <p class="description"><?php _e('Click to import this student\'s latest attendance records from Populi.', 'pbattend'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Handles the manual sync request from the admin profile page.
     */
    public function handle_manual_sync() {
        if (!isset($_POST['pbattend_nonce']) || !wp_verify_nonce($_POST['pbattend_nonce'], 'pbattend_manual_sync_nonce')) {
            wp_die(__('Security check failed.'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (empty($user_id)) {
            wp_die(__('Invalid user ID.'));
        }

        $result = $this->sync_student_attendance($user_id);

        if ($result['success']) {
            set_transient('pbattend_admin_notice', array(
                'type' => 'success',
                'message' => $result['message']
            ), 45);
        } else {
            set_transient('pbattend_admin_notice', array(
                'type' => 'error',
                'message' => $result['message']
            ), 45);
        }

        wp_redirect(get_edit_user_link($user_id));
        exit;
    }

    /**
     * Sync user with POPULI data (if not already synced)
     * This is a simplified version for finding a user's Populi ID.
     */
    public function sync_user_by_email($user) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('User sync failed: API credentials not configured', 'error');
            return false;
        }
        
        $this->log_import(sprintf('Starting sync for user: %s (email: %s)', $user->display_name, $user->user_email), 'info');
        
        // This function should find the Populi person ID by matching email.
        // The original implementation was complex. A better way would be a direct API lookup if possible.
        // Assuming no direct email lookup, we must find a creative way.
        // For now, let's keep the logic simple: if we can't find a user, we can't sync.
        // The original `find_person_by_email_scan` is too slow and unreliable.
        // A better approach is needed, maybe getting all students and caching them.
        // For this refactor, we will assume a more direct method can be found or that admins link users manually.
        
        // Placeholder for a more robust email->person_id lookup.
        $person_id = $this->find_person_id_by_email($user->user_email);

        if ($person_id) {
            $student_data = $this->get_student_data($person_id);
            if ($student_data) {
                update_user_meta($user->ID, 'populi_id', $person_id);
                update_user_meta($user->ID, 'populi_student_id', $student_data['visible_student_id']);
                update_user_meta($user->ID, 'populi_last_sync', time());
                
                $this->log_import(sprintf('Successfully synced user: %s, Populi ID: %d', $user->display_name, $person_id));
                return true;
            }
        }
        
        $this->log_import('No POPULI user found for email: ' . $user->user_email, 'info');
        return false;
    }

    /**
     * Placeholder: Finds a person ID in Populi by their email.
     * This needs a proper implementation.
     */
    private function find_person_id_by_email($email) {
        $student_cache = get_transient('pbattend_student_cache');

        // If the cache is empty, try to build it on-demand.
        if (empty($student_cache)) {
            $this->log_import('Student cache is empty. Attempting to build it now.', 'info');
            $this->refresh_student_cache();
            $student_cache = get_transient('pbattend_student_cache');
        }

        if (empty($student_cache)) {
            $this->log_import('Failed to build student cache. Cannot look up user by email.', 'error');
            return false;
        }

        // Case-insensitive search in the cache.
        $email_lower = strtolower($email);
        if (isset($student_cache[$email_lower])) {
            $this->log_import("Found email {$email} in cache. Populi ID: {$student_cache[$email_lower]}", 'info');
            return $student_cache[$email_lower];
        }

        $this->log_import("Email {$email} not found in student cache.", 'info');
        return false;
    }

    /**
     * Fetches all students from Populi and stores their email/ID in a transient cache.
     */
    public function refresh_student_cache() {
        $this->log_import('Starting daily student cache refresh.', 'info');
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('Cannot refresh student cache: API key not set.', 'error');
            return false;
        }

        try {
            // NOTE: The Populi API v2 `getPeople` endpoint does not seem to support filtering by role.
            // This implementation will have to fetch all people and filter locally.
            // This is inefficient and may cause performance issues on sites with many people.
            // A better solution would be to find an API endpoint that allows filtering.
            
            $all_people = array();
            $page = 1;
            while (true) {
                $response = wp_remote_get($this->get_endpoint_url('person') . '?limit=200&page=' . $page, array(
                    'headers' => array('Authorization' => 'Bearer ' . $credentials['api_key']),
                    'timeout' => 45
                ));

                if (is_wp_error($response)) {
                    throw new Exception('API error while fetching people: ' . $response->get_error_message());
                }
                
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (empty($body['data'])) {
                    break;
                }

                $all_people = array_merge($all_people, $body['data']);

                if (!isset($body['has_more']) || !$body['has_more']) {
                    break;
                }
                $page++;
            }

            if (empty($all_people)) {
                throw new Exception('No people returned from the Populi API.');
            }

            $student_cache = array();
            foreach ($all_people as $person) {
                // We only want to cache students.
                if (isset($person['roles']) && is_array($person['roles'])) {
                    $is_student = false;
                    foreach ($person['roles'] as $role) {
                        if ($role['name'] === 'Student') {
                            $is_student = true;
                            break;
                        }
                    }

                    if ($is_student && isset($person['primary_email'])) {
                        $student_cache[strtolower($person['primary_email'])] = $person['id'];
                    }
                }
            }
            
            set_transient('pbattend_student_cache', $student_cache, DAY_IN_SECONDS);
            $this->log_import(sprintf('Student cache refreshed successfully. Found %d students.', count($student_cache)), 'info');
            return true;

        } catch (Exception $e) {
            $this->log_import('Error refreshing student cache: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get student data from POPULI for a person ID
     */
    private function get_student_data($person_id) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            return false;
        }
        
        $student_url = sprintf($this->get_endpoint_url('student'), $person_id);
        $response = wp_remote_get($student_url, array(
            'headers' => array('Authorization' => 'Bearer ' . $credentials['api_key']),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $this->log_import('Failed to get student data: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true) ?: false;
    }
} 