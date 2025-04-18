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
        'student' => '/people', // Future endpoint for student data
        'course' => '/courses', // Future endpoint for course data
    );
    
    private $last_import_key = 'pbattend_last_import_time';
    private $processed_records_key = 'pbattend_processed_records';
    private $student_queue_key = 'pbattend_student_import_queue';
    private $import_log_key = 'pbattend_import_log';

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
     * Handle manual import request
     */
    public function handle_manual_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_manual_import', 'pbattend_nonce');

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
    private function log_import($message, $type = 'info') {
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
     * Import attendance records from Populi
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

        $last_import_time = get_option($this->last_import_key, '');
        $processed_records = get_option($this->processed_records_key, array());
        $new_records_count = 0;
        $total_processed = 0;

        $this->log_import('Starting attendance import' . ($last_import_time ? ' since ' . $last_import_time : ''));

        // Build the request
        $request_body = $this->build_request_body($last_import_time);
        
        // Log the request details
        $this->log_import('Making API request to: ' . $this->get_endpoint_url('attendance'));
        $this->log_import('Request body: ' . json_encode($request_body));
        
        // Make the API request
        $response = wp_remote_post($this->get_endpoint_url('attendance'), array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $credentials['api_key']
            ),
            'body' => json_encode($request_body)
        ));

        if (is_wp_error($response)) {
            $error_message = 'API request failed: ' . $response->get_error_message();
            $this->log_import($error_message, 'error');
            return array(
                'success' => false,
                'message' => $error_message
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log_import('API Response Code: ' . $response_code);
        $this->log_import('API Response Body: ' . $response_body);

        $response_data = json_decode($response_body, true);

        if (empty($response_data) || !isset($response_data['data']) || !is_array($response_data['data'])) {
            $this->log_import('No valid records found in response: ' . $response_body, 'error');
            return array(
                'success' => false,
                'message' => 'Invalid response from API'
            );
        }

        $records = $response_data['data'];
        $this->log_import(sprintf('Found %d records to process', count($records)));

        // Process each record
        foreach ($records as $record) {
            $total_processed++;
            
            // Skip if we've already processed this record
            if (!isset($record['report_data']['row_id'])) {
                $this->log_import('Skipping record: Missing row_id', 'warning');
                continue;
            }

            $record_id = $record['report_data']['row_id'];
            if (in_array($record_id, $processed_records)) {
                $this->log_import('Skipping already processed record: ' . $record_id);
                continue;
            }

            // Create or update the attendance record
            $result = $this->create_attendance_record($record);
            if ($result) {
                $this->log_import(sprintf(
                    'Successfully created attendance record for %s in %s (ID: %s)',
                    $record['display_name'] ?? 'Unknown Student',
                    $record['report_data']['course_name'] ?? 'Unknown Course',
                    $record_id
                ));
                $new_records_count++;
            } else {
                $this->log_import('Failed to create attendance record for record ID: ' . $record_id, 'error');
            }

            // Add student to import queue if needed
            if (isset($record['id'])) {
                $this->queue_student_import($record['id']);
            }

            // Mark record as processed
            $processed_records[] = $record_id;
        }

        // Update the last import time and processed records
        update_option($this->last_import_key, current_time('mysql'));
        update_option($this->processed_records_key, $processed_records);

        $this->log_import(sprintf(
            'Import completed. Processed %d records, %d new records imported.',
            $total_processed,
            $new_records_count
        ));

        return array(
            'success' => true,
            'total_processed' => $total_processed,
            'new_records' => $new_records_count,
            'message' => sprintf(
                'Import completed. Processed %d records, %d new records imported.',
                $total_processed,
                $new_records_count
            )
        );
    }

    /**
     * Build the request body for the API call
     */
    private function build_request_body($last_import_time) {
        $request = array(
            'filter' => array(
                '0' => array(
                    'logic' => 'ALL',
                    'fields' => array(
                        array(
                            'name' => 'has_active_student_role',
                            'value' => 'YES',
                            'positive' => '1'
                        )
                    )
                )
            )
        );

        // Add time filter if we have a last import time
        if (!empty($last_import_time)) {
            $request['filter']['1'] = array(
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

        // Create or update post
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            $this->log_import('Failed to create post: ' . $post_id->get_error_message(), 'error');
            return false;
        }

        if ($post_id) {
            $this->log_import('Created post with ID: ' . $post_id);
            
            // Update ACF fields
            $fields_updated = array();
            
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
            $fields_updated[] = update_field('attendance_details_attendance_note', $record['report_data']['attendance_note'] ?? '', $post_id);

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
     * Queue a student for import
     */
    private function queue_student_import($student_id) {
        $queue = get_option($this->student_queue_key, array());
        if (!in_array($student_id, $queue)) {
            $queue[] = $student_id;
            update_option($this->student_queue_key, $queue);
        }
    }
} 