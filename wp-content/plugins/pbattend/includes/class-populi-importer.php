<?php
/**
 * Handles importing attendance records from Populi
 */
class PBAttend_Populi_Importer {
    // Base API URL
    private $api_base = 'https://pbc.populiweb.com/api2';
    
    // API Endpoints
    private $endpoints = array(
        'attendance' => '/attendance/detail',
        'person' => '/people',
        'student' => '/people/%d/student',
        'email' => '/people/%d/emailaddresses'
    );
    
    private $last_import_key = 'pbattend_last_import_time';
    private $processed_records_key = 'pbattend_processed_records';
    private $student_queue_key = 'pbattend_student_import_queue';
    private $import_log_key = 'pbattend_import_log';
    private $import_state_key = 'pbattend_import_state';
    private $batch_size = 50; // Number of records to process per batch

    public function __construct() {
        // Register the cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Register the cron event
        if (!wp_next_scheduled('pbattend_import_cron')) {
            wp_schedule_event(time(), 'hourly', 'pbattend_import_cron');
        }

        // Hook the import function to the cron event
        add_action('pbattend_import_cron', array($this, 'import_attendance_records'));
        
        // Add manual import action
        add_action('admin_post_pbattend_manual_import', array($this, 'handle_manual_import'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'display_import_notices'));
        
        // Add user import action
        add_action('admin_post_pbattend_user_import', array($this, 'handle_user_import'));
        
        // Add login hook for user sync
        add_action('wp_login', array($this, 'sync_user_on_login'), 10, 2);
        
        // Add bulk sync action
        add_action('admin_post_pbattend_bulk_sync', array($this, 'handle_bulk_sync'));
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['hourly'] = array(
            'interval' => 3600, // 60 minutes in seconds
            'display'  => __('Every Hour', 'pbattend')
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
     * Reset the importer state and clear processed records
     */
    public function reset_importer() {
        // Clear all tracking options
        delete_option($this->last_import_key);
        delete_option($this->processed_records_key);
        delete_option($this->import_state_key);
        delete_option($this->student_queue_key);
        
        $this->log_import('Importer state has been reset', 'info');
        
        return array(
            'success' => true,
            'message' => 'Importer state has been reset successfully'
        );
    }

    /**
     * Handle manual import request
     */
    public function handle_manual_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_manual_import', 'pbattend_nonce');

        // Check if this is a reset request
        if (isset($_GET['reset']) && $_GET['reset'] === '1') {
            $result = $this->reset_importer();
            set_transient('pbattend_admin_notice', array(
                'type' => 'success',
                'message' => $result['message']
            ), 45);
            
            // Redirect back to settings page
            wp_redirect(add_query_arg(
                'page',
                'pbattend-settings',
                admin_url('edit.php?post_type=pbattend_record')
            ));
            exit;
        }

        // Increase execution time for import
        set_time_limit(300); // 5 minutes

        // Run the import
        $result = $this->import_attendance_records();

        // Set notice based on result
        if ($result['success']) {
            set_transient('pbattend_admin_notice', array(
                'type' => 'success',
                'message' => sprintf(
                    __('Import completed. Processed %d records, %d new records imported.', 'pbattend'),
                    $result['total_processed'],
                    $result['new_records']
                )
            ), 45);
        } else {
            set_transient('pbattend_admin_notice', array(
                'type' => 'error',
                'message' => $result['message']
            ), 45);
        }

        // Redirect back to settings page
        wp_redirect(add_query_arg(
            'page',
            'pbattend-settings',
            admin_url('edit.php?post_type=pbattend_record')
        ));
        exit;
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
        
        // Keep only the last 100 log entries
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option($this->import_log_key, $log);
    }

    /**
     * Import attendance records from Populi with pagination and batching
     */
    public function import_attendance_records() {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('Import failed: API credentials not configured', 'error');
            return array(
                'success' => false,
                'message' => 'Populi API credentials not configured'
            );
        }

        // Get or initialize import state
        $import_state = get_option($this->import_state_key, array(
            'last_import_time' => get_option($this->last_import_key, ''),
            'processed_records' => get_option($this->processed_records_key, array()),
            'current_page' => 1,
            'total_processed' => 0,
            'new_records' => 0,
            'in_progress' => false
        ));

        // If no import in progress, start a new one
        if (!$import_state['in_progress']) {
            $import_state = array(
                'last_import_time' => current_time('mysql'),
                'processed_records' => array(),
                'current_page' => 1,
                'total_processed' => 0,
                'new_records' => 0,
                'in_progress' => true
            );
            update_option($this->import_state_key, $import_state);
        }

        $this->log_import('Starting/resuming attendance import' . ($import_state['last_import_time'] ? ' since ' . $import_state['last_import_time'] : ''));

        try {
            while (true) {
                // Build the request
                $request_body = $this->build_request_body($import_state['last_import_time'], $import_state['current_page']);
                
                // Make the API request
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

                $records = $response_data['data'];
                $batch_count = 0;

                foreach ($records as $record) {
                    if ($batch_count >= $this->batch_size) {
                        // Save progress and continue in next batch
                        update_option($this->import_state_key, $import_state);
                        return array(
                            'success' => true,
                            'total_processed' => $import_state['total_processed'],
                            'new_records' => $import_state['new_records'],
                            'message' => sprintf(
                                'Batch processed. Total: %d, New: %d. More records to process.',
                                $import_state['total_processed'],
                                $import_state['new_records']
                            )
                        );
                    }

                    $import_state['total_processed']++;
                    
                    if (!isset($record['report_data']['row_id'])) {
                        continue;
                    }

                    $record_id = $record['report_data']['row_id'];
                    if (in_array($record_id, $import_state['processed_records'])) {
                        continue;
                    }

                    $result = $this->create_attendance_record($record);
                    if ($result) {
                        $import_state['new_records']++;
                        if (isset($record['id'])) {
                            $this->queue_student_import($record['id']);
                        }
                    }

                    $import_state['processed_records'][] = $record_id;
                    $batch_count++;
                }

                // Check if there are more pages
                if (!isset($response_data['has_more']) || !$response_data['has_more']) {
                    break;
                }

                $import_state['current_page']++;
            }

            // Import completed successfully
            update_option($this->last_import_key, $import_state['last_import_time']);
            update_option($this->processed_records_key, $import_state['processed_records']);
            update_option($this->import_state_key, array('in_progress' => false));

            return array(
                'success' => true,
                'total_processed' => $import_state['total_processed'],
                'new_records' => $import_state['new_records'],
                'message' => sprintf(
                    'Import completed. Processed %d records, %d new records imported.',
                    $import_state['total_processed'],
                    $import_state['new_records']
                )
            );

        } catch (Exception $e) {
            // Save progress before throwing error
            update_option($this->import_state_key, $import_state);
            
            $this->log_import($e->getMessage(), 'error');
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Build the request body for the API call with pagination
     */
    private function build_request_body($last_import_time, $page = 1) {
        $request = array(
            'filter' => array(
                '0' => array(
                    'logic' => 'ALL',
                    'fields' => array(
                        array(
                            'name' => 'has_active_student_role',
                            'value' => 'YES',
                            'positive' => '1'
                        ),
                        array(
                            'name' => 'academic_year',
                            'value' => '72405',
                            'positive' => '1'
                        )
                    )
                ),
                '1' => array(
                    'logic' => 'ANY',
                    'fields' => array(
                        array(
                            'name' => 'status',
                            'value' => 'TARDY',
                            'positive' => '1'
                        ),
                        array(
                            'name' => 'status',
                            'value' => 'ABSENT',
                            'positive' => '1'
                        )
                    )
                )
            ),
            'page' => $page,
            'results_per_page' => 200
        );

        // Add time filter if we have a last import time
        if (!empty($last_import_time)) {
            $request['filter']['2'] = array(
                'logic' => 'ALL',
                'fields' => array(
                    array(
                        'name' => 'attendance_added_at',
                        'value' => $last_import_time,
                        'positive' => '1',
                        'operator' => '>'
                    )
                )
            );
        }

        return $request;
    }

    /**
     * Create or update an attendance record
     */
    private function create_attendance_record($record) {
        if (!isset($record['id']) || !isset($record['report_data'])) {
            $this->log_import('Invalid record format: Missing required fields', 'error');
            return false;
        }

        $populi_row_id = $record['report_data']['row_id'] ?? '';
        
        // WordPress-level duplicate check: Check if a post with this Populi row_id already exists
        if (!empty($populi_row_id)) {
            $existing_posts = get_posts(array(
                'post_type' => 'pbattend_record',
                'meta_query' => array(
                    array(
                        'key' => 'populi_row_id',
                        'value' => $populi_row_id,
                        'compare' => '='
                    )
                ),
                'posts_per_page' => 1,
                'fields' => 'ids'
            ));
            
            if (!empty($existing_posts)) {
                $existing_post_id = $existing_posts[0];
                $this->log_import(sprintf('Duplicate record found (Populi row_id: %s), skipping. Existing post ID: %d', $populi_row_id, $existing_post_id), 'info');
                return false;
            }
        }

        $post_data = array(
            'post_type' => 'pbattend_record',
            'post_status' => 'publish',
            'post_title' => sprintf(
                '%s - %s (%s)',
                $record['display_name'] ?? 'Unknown Student',
                $record['report_data']['course_name'] ?? 'Unknown Course',
                $record['report_data']['meeting_start_time'] ?? 'Unknown Time'
            )
        );

        // Create new post
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            $this->log_import('Failed to create post: ' . $post_id->get_error_message(), 'error');
            return false;
        }

        if ($post_id) {
            $this->log_import('Created post with ID: ' . $post_id);
            
            // Update ACF fields
            $fields_updated = array();
            
            // Store Populi row_id for duplicate checking
            $fields_updated[] = update_field('populi_row_id', $populi_row_id, $post_id);
            
            // Student info
            $fields_updated[] = update_field('student_id', $record['id'], $post_id);
            $fields_updated[] = update_field('first_name', $record['first_name'] ?? '', $post_id);
            $fields_updated[] = update_field('last_name', $record['last_name'] ?? '', $post_id);

            // Course info
            $fields_updated[] = update_field('course_info_course_id', $record['report_data']['course_offering_id'] ?? '', $post_id);
            $fields_updated[] = update_field('course_info_course_name', $record['report_data']['course_name'] ?? '', $post_id);
            $fields_updated[] = update_field('course_info_term_name', $record['report_data']['term_name'] ?? '', $post_id);

            // Attendance details
            $fields_updated[] = update_field('attendance_details_meeting_start_time', $record['report_data']['meeting_start_time'] ?? '', $post_id);
            $fields_updated[] = update_field('attendance_details_meeting_end_time', $record['report_data']['meeting_end_time'] ?? '', $post_id);
            $fields_updated[] = update_field('attendance_details_attendance_status', $record['report_data']['attendance_status'] ?? '', $post_id);
            $fields_updated[] = update_field('attendance_note', $record['report_data']['attendance_note'] ?? '', $post_id);

            // Meta info
            $fields_updated[] = update_field('meta_info_attendance_added_at', $record['report_data']['attendance_added_at'] ?? '', $post_id);
            $fields_updated[] = update_field('meta_info_attendance_added_by', $record['report_data']['attendance_added_by'] ?? '', $post_id);

            // Log any field update failures
            if (in_array(false, $fields_updated, true)) {
                $this->log_import('Some ACF fields failed to update for post ID: ' . $post_id, 'warning');
            }

            return true;
        }

        return false;
    }

    /**
     * Import a student/user from Populi
     */
    private function import_student($student_id) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            return false;
        }

        // Get student details
        $student_url = sprintf($this->get_endpoint_url('student'), $student_id);
        $student_response = wp_remote_get($student_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['api_key']
            )
        ));

        if (is_wp_error($student_response)) {
            $this->log_import('Failed to get student details: ' . $student_response->get_error_message(), 'error');
            return false;
        }

        $student_data = json_decode(wp_remote_retrieve_body($student_response), true);
        if (empty($student_data)) {
            $this->log_import('Invalid student data received', 'error');
            return false;
        }

        // Get person details
        $person_url = $this->get_endpoint_url('person') . '/' . $student_id;
        $person_response = wp_remote_get($person_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['api_key']
            )
        ));

        if (is_wp_error($person_response)) {
            $this->log_import('Failed to get person details: ' . $person_response->get_error_message(), 'error');
            return false;
        }

        $person_data = json_decode(wp_remote_retrieve_body($person_response), true);
        if (empty($person_data)) {
            $this->log_import('Invalid person data received', 'error');
            return false;
        }

        // Get email addresses
        $email_url = sprintf($this->get_endpoint_url('email'), $student_id);
        $email_response = wp_remote_get($email_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['api_key']
            )
        ));

        if (is_wp_error($email_response)) {
            $this->log_import('Failed to get email addresses: ' . $email_response->get_error_message(), 'error');
            return false;
        }

        $email_data = json_decode(wp_remote_retrieve_body($email_response), true);
        $email = '';
        if (!empty($email_data['data'])) {
            foreach ($email_data['data'] as $email_record) {
                if ($email_record['primary'] || empty($email)) {
                    $email = $email_record['email'];
                }
            }
        }

        if (empty($email)) {
            $this->log_import('No email address found for student: ' . $student_id, 'error');
            return false;
        }

        // Check if user already exists
        $user = get_user_by('email', $email);
        if (!$user) {
            // Create new user
            $username = sanitize_user($person_data['first_name'] . '.' . $person_data['last_name'], true);
            $username = $this->generate_unique_username($username);
            
            $user_id = wp_create_user(
                $username,
                wp_generate_password(),
                $email
            );

            if (is_wp_error($user_id)) {
                $this->log_import('Failed to create user: ' . $user_id->get_error_message(), 'error');
                return false;
            }

            $user = get_user_by('id', $user_id);
        }

        // Update user details
        wp_update_user(array(
            'ID' => $user->ID,
            'first_name' => $person_data['first_name'],
            'last_name' => $person_data['last_name'],
            'display_name' => $person_data['display_name'],
            'role' => 'subscriber'
        ));

        // Store Populi IDs as user meta and ACF fields
        update_user_meta($user->ID, 'populi_id', $student_id);
        update_user_meta($user->ID, 'populi_student_id', $student_data['visible_student_id']);
        
        // Update ACF fields
        update_field('student_id', $student_id, 'user_' . $user->ID);
        update_field('student_visible_id', $student_data['visible_student_id'], 'user_' . $user->ID);

        $this->log_import(sprintf(
            'Successfully imported/updated user: %s (ID: %d)',
            $person_data['display_name'],
            $user->ID
        ));

        return $user->ID;
    }

    /**
     * Generate a unique username by appending a number if needed
     */
    private function generate_unique_username($username) {
        $original_username = $username;
        $counter = 1;

        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Queue a student for import
     */
    private function queue_student_import($student_id) {
        $queue = get_option($this->student_queue_key, array());
        if (!in_array($student_id, $queue)) {
            $queue[] = $student_id;
            update_option($this->student_queue_key, $queue);
            
            // Try to import the student immediately
            $this->import_student($student_id);
        }
    }

    /**
     * Import users from Populi
     */
    public function import_users() {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('User import failed: API credentials not configured', 'error');
            return array(
                'success' => false,
                'message' => 'Populi API credentials not configured'
            );
        }

        // Get all attendance records to find unique student IDs
        $args = array(
            'post_type' => 'pbattend_record',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $attendance_posts = get_posts($args);
        $student_ids = array();
        
        foreach ($attendance_posts as $post_id) {
            $student_id = get_field('student_id', $post_id);
            if ($student_id && !in_array($student_id, $student_ids)) {
                $student_ids[] = $student_id;
            }
        }

        if (empty($student_ids)) {
            return array(
                'success' => false,
                'message' => 'No student IDs found in attendance records'
            );
        }

        $total_processed = 0;
        $new_users = 0;
        $updated_users = 0;

        $this->log_import(sprintf('Starting user import for %d students', count($student_ids)));

        foreach ($student_ids as $student_id) {
            $result = $this->import_student($student_id);
            if ($result) {
                $total_processed++;
                if (is_numeric($result)) {
                    $new_users++;
                } else {
                    $updated_users++;
                }
            }
        }

        $message = sprintf(
            'User import completed. Processed %d students: %d new users, %d updated users.',
            $total_processed,
            $new_users,
            $updated_users
        );

        $this->log_import($message);
        return array(
            'success' => true,
            'total_processed' => $total_processed,
            'new_users' => $new_users,
            'updated_users' => $updated_users,
            'message' => $message
        );
    }

    /**
     * Handle manual user import request
     */
    public function handle_user_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_user_import', 'pbattend_nonce');

        // Increase execution time for import
        set_time_limit(300); // 5 minutes

        // Run the import
        $result = $this->import_users();

        // Set notice based on result
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

        // Redirect back to settings page
        wp_redirect(add_query_arg(
            'page',
            'pbattend-settings',
            admin_url('edit.php?post_type=pbattend_record')
        ));
        exit;
    }

    /**
     * Sync user with POPULI data on login (if not already synced)
     */
    public function sync_user_on_login($user_login, $user) {
        // Skip if user already has student_id
        $existing_student_id = get_field('student_id', 'user_' . $user->ID);
        if ($existing_student_id) {
            return;
        }
        
        // Skip if synced recently (avoid API spam)
        $last_sync = get_user_meta($user->ID, 'populi_last_sync', true);
        if ($last_sync && (time() - $last_sync) < DAY_IN_SECONDS) {
            return;
        }
        
        // Lookup user in POPULI by email
        $this->sync_user_by_email($user);
        
        $this->log_import(sprintf(
            'Login sync attempted for user: %s (ID: %d)',
            $user->display_name,
            $user->ID
        ));
    }

    /**
     * Lookup and sync user data from POPULI using email
     * Since POPULI doesn't have email search, we'll use name matching from attendance records
     */
    public function sync_user_by_email($user) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('User sync failed: API credentials not configured', 'error');
            return false;
        }
        
        $this->log_import(sprintf('Starting sync for user: %s (email: %s)', $user->display_name, $user->user_email), 'info');
        
        // Strategy 1: Try to find student ID from attendance records by matching name and email
        $student_id = $this->find_student_id_from_attendance($user);
        
        if ($student_id) {
            $this->log_import(sprintf('Found student ID %d from attendance records for user %s', $student_id, $user->user_email), 'info');
            
            // Get student data from POPULI
            $student_data = $this->get_student_data($student_id);
            
            if ($student_data) {
                // Store POPULI data in WordPress
                update_field('student_id', $student_id, 'user_' . $user->ID);
                update_field('student_visible_id', $student_data['visible_student_id'], 'user_' . $user->ID);
                update_field('populi_sync_status', 'synced', 'user_' . $user->ID);
                update_field('populi_last_sync_date', current_time('Y-m-d H:i:s'), 'user_' . $user->ID);
                
                update_user_meta($user->ID, 'populi_id', $student_id);
                update_user_meta($user->ID, 'populi_student_id', $student_data['visible_student_id']);
                update_user_meta($user->ID, 'populi_last_sync', time());
                
                $this->log_import(sprintf(
                    'Successfully synced user via attendance matching: %s (ID: %d, POPULI ID: %d)',
                    $user->display_name,
                    $user->ID,
                    $student_id
                ));
                
                return true;
            } else {
                $this->log_import(sprintf('Failed to get student data for ID %d', $student_id), 'error');
            }
        }
        
        // Strategy 2: If no match found, try to get a small list of people and match by email
        // This is more API intensive, so we'll skip it for bulk sync to prevent timeouts
        // Only do email scanning for individual login attempts
        $is_bulk_sync = defined('DOING_BULK_SYNC') && DOING_BULK_SYNC;
        $person_id = false;
        
        if (!$is_bulk_sync) {
            $person_id = $this->find_person_by_email_scan($user->user_email);
        } else {
            $this->log_import('Skipping email scan for bulk sync to prevent timeout: ' . $user->user_email, 'info');
        }
        
        if ($person_id) {
            $student_data = $this->get_student_data($person_id);
            
            if ($student_data) {
                // Store POPULI data in WordPress
                update_field('student_id', $person_id, 'user_' . $user->ID);
                update_field('student_visible_id', $student_data['visible_student_id'], 'user_' . $user->ID);
                update_field('populi_sync_status', 'synced', 'user_' . $user->ID);
                update_field('populi_last_sync_date', current_time('Y-m-d H:i:s'), 'user_' . $user->ID);
                
                update_user_meta($user->ID, 'populi_id', $person_id);
                update_user_meta($user->ID, 'populi_student_id', $student_data['visible_student_id']);
                update_user_meta($user->ID, 'populi_last_sync', time());
                
                $this->log_import(sprintf(
                    'Successfully synced user via email scan: %s (ID: %d, POPULI ID: %d)',
                    $user->display_name,
                    $user->ID,
                    $person_id
                ));
                
                return true;
            }
        }
        
        // No match found
        $this->log_import('No POPULI user found for email: ' . $user->user_email, 'info');
        update_field('populi_sync_status', 'not_found', 'user_' . $user->ID);
        update_field('populi_last_sync_date', current_time('Y-m-d H:i:s'), 'user_' . $user->ID);
        update_user_meta($user->ID, 'populi_last_sync', time());
        
        return false;
    }

    /**
     * Find student ID from existing attendance records by matching name
     */
    private function find_student_id_from_attendance($user) {
        // Get user's name components
        $user_first = strtolower(trim($user->first_name));
        $user_last = strtolower(trim($user->last_name));
        $user_display = strtolower(trim($user->display_name));
        
        if (empty($user_first) && empty($user_last) && empty($user_display)) {
            return false;
        }
        
        // Search attendance records for matching names
        $args = array(
            'post_type' => 'pbattend_record',
            'posts_per_page' => 50, // Reduced to speed up bulk sync
            'fields' => 'ids'
        );
        
        $attendance_posts = get_posts($args);
        
        foreach ($attendance_posts as $post_id) {
            $record_first = strtolower(trim(get_field('first_name', $post_id)));
            $record_last = strtolower(trim(get_field('last_name', $post_id)));
            $student_id = get_field('student_id', $post_id);
            
            if (!$student_id) {
                continue;
            }
            
            // Try exact name match
            if (($user_first && $record_first && $user_first === $record_first) &&
                ($user_last && $record_last && $user_last === $record_last)) {
                $this->log_import(sprintf('Found name match: %s %s -> Student ID %d', $record_first, $record_last, $student_id), 'info');
                return $student_id;
            }
            
            // Try partial matches for display name
            if ($user_display && (strpos($user_display, $record_first) !== false || strpos($user_display, $record_last) !== false)) {
                // Additional verification - check if this makes sense
                if (strlen($record_first) > 2 && strlen($record_last) > 2) {
                    $this->log_import(sprintf('Found partial name match: %s -> Student ID %d', $user_display, $student_id), 'info');
                    return $student_id;
                }
            }
        }
        
        return false;
    }

    /**
     * Find person by scanning POPULI people and matching email
     * This is API intensive, so use sparingly
     */
    private function find_person_by_email_scan($email) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            return false;
        }
        
        // Get a limited list of people from POPULI
        $people_url = $this->get_endpoint_url('person');
        $response = wp_remote_get($people_url . '?limit=50', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['api_key']
            ),
            'timeout' => 20
        ));
        
        if (is_wp_error($response)) {
            $this->log_import('Failed to get people list: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log_import('People list request failed with code: ' . $response_code, 'error');
            return false;
        }
        
        $people_data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($people_data['data'])) {
            return false;
        }
        
        // Check each person's email addresses
        foreach ($people_data['data'] as $person) {
            $person_id = $person['id'];
            
            // Get email addresses for this person
            $email_url = sprintf($this->get_endpoint_url('email'), $person_id);
            $email_response = wp_remote_get($email_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $credentials['api_key']
                ),
                'timeout' => 10
            ));
            
            if (!is_wp_error($email_response) && wp_remote_retrieve_response_code($email_response) === 200) {
                $email_data = json_decode(wp_remote_retrieve_body($email_response), true);
                
                if (!empty($email_data['data'])) {
                    foreach ($email_data['data'] as $email_record) {
                        if (strtolower($email_record['email']) === strtolower($email)) {
                            $this->log_import(sprintf('Found email match: %s -> Person ID %d', $email, $person_id), 'info');
                            return $person_id;
                        }
                    }
                }
            }
            
            // Small delay to be nice to the API
            usleep(100000); // 0.1 seconds
        }
        
        return false;
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
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['api_key']
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $this->log_import('Failed to get student data: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $student_data = json_decode(wp_remote_retrieve_body($response), true);
        return $student_data ?: false;
    }

    /**
     * Handle bulk sync request
     */
    public function handle_bulk_sync() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_bulk_sync', 'pbattend_nonce');

        // Increase execution time for bulk sync
        set_time_limit(300); // 5 minutes

        // Run the bulk sync
        $result = $this->bulk_sync_users();

        // Set notice based on result
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

        // Redirect back to settings page
        wp_redirect(add_query_arg(
            'page',
            'pbattend-settings',
            admin_url('edit.php?post_type=pbattend_record')
        ));
        exit;
    }

    /**
     * Bulk sync all users without student_id
     */
    public function bulk_sync_users() {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            return array(
                'success' => false,
                'message' => 'POPULI API credentials not configured'
            );
        }

        // Get all users without student_id or with failed/pending sync status
        $all_users = get_users(array('number' => 100)); // Reduced from 200
        $users = array();
        
        foreach ($all_users as $user) {
            $student_id = get_field('student_id', 'user_' . $user->ID);
            $sync_status = get_field('populi_sync_status', 'user_' . $user->ID);
            
            // Include users who:
            // 1. Don't have a student_id, OR
            // 2. Have failed sync status, OR  
            // 3. Have pending sync status, OR
            // 4. Haven't been synced yet (no sync status)
            if (!$student_id || in_array($sync_status, array('failed', 'pending', ''))) {
                $users[] = $user;
                
                // Much smaller batch to prevent timeouts
                if (count($users) >= 10) {
                    break;
                }
            }
        }

        if (empty($users)) {
            return array(
                'success' => true,
                'message' => 'No users found that need syncing'
            );
        }

        $total_processed = 0;
        $successful_syncs = 0;
        $failed_syncs = 0;

        $this->log_import(sprintf('Starting bulk sync for %d users', count($users)));

        // Set flag to indicate bulk sync mode
        define('DOING_BULK_SYNC', true);

        foreach ($users as $user) {
            $total_processed++;
            
            if ($this->sync_user_by_email($user)) {
                $successful_syncs++;
            } else {
                $failed_syncs++;
            }
            
            // Longer delay to prevent API overload and timeouts
            usleep(500000); // 0.5 seconds between users
        }

        $message = sprintf(
            'Bulk sync completed. Processed %d users: %d successful, %d failed.',
            $total_processed,
            $successful_syncs,
            $failed_syncs
        );

        $this->log_import($message);
        
        return array(
            'success' => true,
            'message' => $message,
            'total_processed' => $total_processed,
            'successful_syncs' => $successful_syncs,
            'failed_syncs' => $failed_syncs
        );
    }
} 