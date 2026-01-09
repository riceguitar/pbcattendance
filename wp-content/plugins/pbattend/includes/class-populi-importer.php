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
        // Add manual sync action for admin profile page
        add_action('admin_post_pbattend_manual_sync', array($this, 'handle_manual_sync'));
        
        // Add button to user profile page
        add_action('show_user_profile', array($this, 'add_sync_button_to_profile'));
        add_action('edit_user_profile', array($this, 'add_sync_button_to_profile'));

        // Add admin notices
        add_action('admin_notices', array($this, 'display_import_notices'));
        
        // Hook into the MiniOrange SSO plugin to capture attributes and trigger sync
        add_action('mo_saml_sso_user_authenticated', array($this, 'handle_sso_login'), 10, 2);
    }

    /**
     * Captures attributes and triggers sync after a successful SSO login.
     *
     * @param int   $user_id The ID of the authenticated user.
     * @param array $attrs   The SAML attributes from the IdP.
     */
    public function handle_sso_login($user_id, $attrs) {
        $this->log_import("handle_sso_login: SSO login detected for user ID: {$user_id}", 'info');
        
        // First, capture and save all attributes.
        $this->capture_sso_attributes($user_id, $attrs);

        // Now, trigger the attendance sync for this user.
        $this->sync_student_attendance($user_id);
    }

    /**
     * Captures attributes from the SAML response and saves them to the user profile.
     *
     * @param int   $user_id The ID of the authenticated user.
     * @param array $attrs   The SAML attributes from the IdP.
     */
    public function capture_sso_attributes($user_id, $attrs) {
        $this->log_import("Capturing SSO attributes for user ID: {$user_id}", 'info');

        // --- Update Core User Fields ---
        $user_data_to_update = [];
        $user = get_userdata($user_id);

        if (isset($attrs['FirstName']) && !empty($attrs['FirstName'][0])) {
            $first_name = sanitize_text_field($attrs['FirstName'][0]);
            if ($user->first_name !== $first_name) {
                $user_data_to_update['first_name'] = $first_name;
            }
        }

        if (isset($attrs['LastName']) && !empty($attrs['LastName'][0])) {
            $last_name = sanitize_text_field($attrs['LastName'][0]);
            if ($user->last_name !== $last_name) {
                $user_data_to_update['last_name'] = $last_name;
            }
        }

        if (!empty($user_data_to_update)) {
            $user_data_to_update['ID'] = $user_id;
            wp_update_user($user_data_to_update);
            $this->log_import("Updated core user data (first/last name) for user ID: {$user_id}", 'info');
        }

        // --- Update User Meta Fields ---
        $attribute_map = [
            'PopuliID'                               => 'populi_id', // Standardize to lowercase with underscore
            'NameID'                                 => 'sso_name_id',
            'Email'                                  => 'sso_email',
            'urn:oid:0.9.2342.19200300.100.1.3'       => 'sso_mail',
            'urn:oid:0.9.2342.19200300.100.1'         => 'sso_uid',
        ];

        foreach ($attribute_map as $saml_attr => $meta_key) {
            if (isset($attrs[$saml_attr]) && !empty($attrs[$saml_attr][0])) {
                $value = sanitize_text_field($attrs[$saml_attr][0]);
                $existing_value = get_user_meta($user_id, $meta_key, true);

                if ($value !== $existing_value) {
                    update_user_meta($user_id, $meta_key, $value);
                    $this->log_import("Saved meta key '{$meta_key}' for user ID: {$user_id}", 'info');
                }
            } else {
                $this->log_import("'{$saml_attr}' attribute not found in SAML response for user ID: {$user_id}", 'info');
            }
        }
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

        // Populi ID from SSO is required to sync.
        $populi_id = get_user_meta($user_id, 'populi_id', true);

        if (empty($populi_id)) {
            $message = "Sync failed for user {$user->user_login}: No 'populi_id' found in user meta. Please ensure the SSO plugin is configured to provide the 'PopuliID' attribute.";
            $this->log_import($message, 'error');
            return array('success' => false, 'message' => $message);
        }

        $last_sync_time = get_user_meta($user_id, $this->user_last_sync_key, true);
        $new_sync_time = current_time('mysql');

        $this->log_import("Starting attendance sync for user {$user->user_login} (Populi ID: {$populi_id}). Last sync: " . ($last_sync_time ?: 'Never'));

        try {
            $current_page = 1;
            $total_new_records = 0;

            while (true) {
                $request_body = $this->build_student_attendance_request_body($user, $last_sync_time, $current_page);
                
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
    private function build_student_attendance_request_body($user, $last_import_time = null, $page = 1) {
        $academic_term = get_option('pbattend_populi_academic_term'); // Assumes term ID is stored in options
        if (empty($academic_term)) {
            $this->log_import('Cannot build request: Academic Term is not set in plugin settings.', 'error');
            // Use a fallback or throw an exception
            $academic_term = '302974'; // Fallback to avoid fatal errors
        }

        $populi_id = get_user_meta($user->ID, 'populi_id', true);

        $filter = array(
            '0' => array(
                'logic' => 'ALL',
                'fields' => array(
                    array('name' => 'has_active_student_role', 'value' => 'YES', 'positive' => '1'),
                    array('name' => 'academic_term', 'value' => $academic_term, 'positive' => '1'),
                    array(
                        'name' => 'student',
                        'value' => array(
                            'id' => $populi_id,
                            'display_text' => $user->display_name
                        ),
                        'positive' => '1'
                    )
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
        
        // Extract course_meeting_id from row_id (format: person_id_meeting_id)
        $course_meeting_id = '';
        if (!empty($populi_row_id) && strpos($populi_row_id, '_') !== false) {
            $parts = explode('_', $populi_row_id);
            $course_meeting_id = isset($parts[1]) ? $parts[1] : '';
            $this->log_import("Extracted course_meeting_id: {$course_meeting_id} from row_id: {$populi_row_id}", 'info');
        } else {
            $this->log_import("Warning: Could not extract course_meeting_id from row_id: {$populi_row_id}", 'warning');
        }
        
        if (!empty($populi_row_id)) {
            $existing_posts = get_posts(array(
                'post_type' => 'pbattend_record',
                'meta_query' => array(
                    array('key' => 'populi_row_id', 'value' => $populi_row_id, 'compare' => '=')
                ),
                'posts_per_page' => 1, 'fields' => 'ids'
            ));
            
            if (!empty($existing_posts)) {
                $this->log_import("Skipping duplicate record (Populi Row ID: {$populi_row_id}).", 'info');
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
        update_field('populi_id', $record['id'], $post_id);
        update_field('review_status', 'pending', $post_id); // Set default status
        update_field('first_name', $record['first_name'] ?? '', $post_id);
        update_field('last_name', $record['last_name'] ?? '', $post_id);
        update_field('course_info_course_id', $record['report_data']['course_offering_id'] ?? '', $post_id);
        update_field('course_info_course_name', $record['report_data']['course_name'] ?? '', $post_id);
        update_field('course_info_term_name', $record['report_data']['term_name'] ?? '', $post_id);
        update_field('course_info_course_meeting_id', $course_meeting_id, $post_id);
        $this->log_import("Saved course_meeting_id: {$course_meeting_id} to post {$post_id}", 'info');
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

        $sync_url = add_query_arg(array(
            'action' => 'pbattend_manual_sync',
            'user_id' => $user->ID,
            '_wpnonce' => wp_create_nonce('pbattend_manual_sync_nonce')
        ), admin_url('admin-post.php'));

        $reset_sync_url = add_query_arg('reset', '1', $sync_url);
        ?>
        <h2><?php _e('Populi Attendance Sync', 'pbattend'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="populi-sync"><?php _e('Manual Sync', 'pbattend'); ?></label></th>
                <td>
                    <a href="<?php echo esc_url($sync_url); ?>" class="button button-secondary"><?php _e('Import New Records'); ?></a>
                    <a href="<?php echo esc_url($reset_sync_url); ?>" class="button button-secondary" style="margin-left: 10px;"><?php _e('Reset and Re-import All'); ?></a>
                    <p class="description"><?php _e('Use "Import New" for fast, incremental updates. Use "Reset and Re-import" to fetch the student\'s complete attendance history for the term.', 'pbattend'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Handles the manual sync request from the admin profile page.
     */
    public function handle_manual_sync() {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pbattend_manual_sync_nonce')) {
            wp_die(__('Security check failed.'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }

        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (empty($user_id)) {
            wp_die(__('Invalid user ID.'));
        }

        // Check if this is a reset request
        if (isset($_GET['reset']) && $_GET['reset'] === '1') {
            delete_user_meta($user_id, $this->user_last_sync_key);
            $this->log_import("Sync timestamp reset for user ID: {$user_id}. A full re-import will be performed.", 'info');
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