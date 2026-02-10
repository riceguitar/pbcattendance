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
        'email'      => '/people/%d/emailaddresses',
        'course_offering_students' => '/courseofferings/%d/students',
        'update_attendance' => '/courseofferings/%d/students/%d/attendance/update'
    );
    
    private $import_log_key = 'pbattend_import_log';
    private $user_last_sync_key = 'pbattend_last_sync_timestamp';

    public function __construct() {
        // Add manual sync action for admin profile page
        add_action('admin_post_pbattend_manual_sync', array($this, 'handle_manual_sync'));

        // Student-facing "Sync now" from dashboard (must be logged in, have populi_id)
        add_action('admin_post_pbattend_student_sync', array($this, 'handle_student_sync'));
        add_action('admin_post_nopriv_pbattend_student_sync', array($this, 'handle_student_sync_no_priv'));
        
        // Add button to user profile page
        add_action('show_user_profile', array($this, 'add_sync_button_to_profile'));
        add_action('edit_user_profile', array($this, 'add_sync_button_to_profile'));

        // Add admin notices
        add_action('admin_notices', array($this, 'display_import_notices'));
        
        // Hook into the MiniOrange SSO plugin to capture attributes and trigger sync
        add_action('mo_saml_sso_user_authenticated', array($this, 'handle_sso_login'), 10, 2);

        // Fallback: sync on any WordPress login (wp_login). SSO uses wp_set_auth_cookie() directly
        // so wp_login does NOT fire for SSO—only for username/password form. This ensures we
        // sync when users log in via the WP form; if SSO hook doesn't fire (e.g. IdP cached session),
        // visiting the dashboard will still trigger sync via maybe_sync_on_dashboard.
        add_action('wp_login', array($this, 'maybe_sync_on_wp_login'), 10, 2);

        // Sync on any page load when a student (with populi_id) is logged in. Debounced so we
        // don't run every request. Priority 20 so current user is set; reliable when SSO/wp_login don't fire.
        add_action('template_redirect', array($this, 'maybe_sync_on_page_load'), 20);

        // Sync to Populi when Attendance Status field is set to Excused (admin edit screen)
        add_action('acf/save_post', array($this, 'maybe_sync_on_attendance_status_excused'), 20);
    }

    /**
     * Trigger sync when user logs in via the WordPress login form (username/password).
     * Does not run when user logs in via SSO (MiniOrange sets cookie directly, wp_login not fired).
     */
    public function maybe_sync_on_wp_login($user_login, $user) {
        if (!isset($user->ID)) {
            return;
        }
        if (!get_user_meta($user->ID, 'populi_id', true)) {
            return;
        }
        $this->log_import("wp_login: triggering attendance sync for user ID: {$user->ID} ({$user_login})", 'info');
        $this->sync_student_attendance($user->ID);
    }

    /**
     * Trigger attendance sync on any page load when a logged-in user has populi_id.
     * Debounced to once per 10 minutes per user. Does not depend on dashboard or login hooks.
     */
    public function maybe_sync_on_page_load() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        if (!get_user_meta($user_id, 'populi_id', true)) {
            return;
        }
        $transient_key = 'pbattend_sync_debounce_' . $user_id;
        if (get_transient($transient_key)) {
            return;
        }
        $this->log_import("Page load: triggering attendance sync for user ID: {$user_id}", 'info');
        $this->sync_student_attendance($user_id);
        set_transient($transient_key, 1, 10 * 60); // 10 minutes
    }

    /**
     * Sync to Populi when Attendance Status field is set to Excused (after ACF save).
     */
    public function maybe_sync_on_attendance_status_excused($post_id) {
        if (get_post_type($post_id) !== 'pbattend_record') {
            return;
        }
        if (!is_admin()) {
            return;
        }
        if (get_field('attendance_details_attendance_status', $post_id) !== 'EXCUSED') {
            return;
        }
        $this->log_import("Attendance status set to Excused for post ID: {$post_id}, syncing to Populi", 'info');
        $this->sync_attendance_to_populi($post_id);
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

        // Use the date of their most recent record as the cutoff (sync everything since then).
        $newest_record_date = $this->get_most_recent_record_date_for_student($populi_id);
        $since_date = null;
        if ($newest_record_date) {
            // 1-day lookback from newest record to avoid missing boundary/timezone edge cases
            $since_date = date('Y-m-d H:i:s', strtotime($newest_record_date) - 86400);
        }
        $new_sync_time = current_time('mysql');

        $this->log_import("Starting attendance sync for user {$user->user_login} (Populi ID: {$populi_id}). Newest existing record: " . ($newest_record_date ?: 'none (full sync)'));

        try {
            $current_page = 1;
            $total_new_records = 0;

            while (true) {
                $request_body = $this->build_student_attendance_request_body($user, $since_date, $current_page);
                
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
     * Get the meeting date of the most recent attendance record we have for this student.
     * Used as the "sync since" cutoff so we pull all Populi records after that date.
     *
     * @param string $populi_id Populi person/student ID
     * @return string|null MySQL date of newest record's meeting_start_time, or null if none
     */
    private function get_most_recent_record_date_for_student($populi_id) {
        $posts = get_posts(array(
            'post_type'      => 'pbattend_record',
            'posts_per_page' => 1,
            'orderby'        => 'meta_value',
            'meta_key'       => 'attendance_details_meeting_start_time',
            'order'          => 'DESC',
            'meta_query'     => array(
                array('key' => 'populi_id', 'value' => $populi_id, 'compare' => '=')
            ),
            'fields'         => 'ids'
        ));
        if (empty($posts)) {
            return null;
        }
        $date = get_field('attendance_details_meeting_start_time', $posts[0]);
        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Build the request body for a single student's attendance records.
     */
    private function build_student_attendance_request_body($user, $last_import_time = null, $page = 1) {
        $academic_term = get_option('pbattend_populi_academic_term'); // Assumes term ID is stored in options
        if (empty($academic_term)) {
            $this->log_import('Cannot build request: Academic Term is not set in plugin settings.', 'error');
            // Use a fallback or throw an exception
            $academic_term = '302988'; // Fallback to avoid fatal errors
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

        // $last_import_time is now "since_date" (newest record date minus 1 day, or null for full sync)
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
     * Handles "Sync now" from the student dashboard. Logged-in user with populi_id only.
     */
    public function handle_student_sync() {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pbattend_student_sync')) {
            file_put_contents($dl, json_encode(array('timestamp' => round(microtime(true) * 1000), 'location' => 'class-populi-importer.php:handle_student_sync', 'message' => 'nonce check failed, wp_die', 'data' => array(), 'hypothesisId' => 'H2')) . "\n", FILE_APPEND | LOCK_EX);
            wp_die(__('Security check failed.'));
        }
        file_put_contents($dl, json_encode(array('timestamp' => round(microtime(true) * 1000), 'location' => 'class-populi-importer.php:handle_student_sync', 'message' => 'nonce passed', 'data' => array(), 'hypothesisId' => 'H2')) . "\n", FILE_APPEND | LOCK_EX);
        $user_id = get_current_user_id();
        $populi_id_val = $user_id ? get_user_meta($user_id, 'populi_id', true) : '';
        if (!$user_id || !$populi_id_val) {
            file_put_contents($dl, json_encode(array('timestamp' => round(microtime(true) * 1000), 'location' => 'class-populi-importer.php:handle_student_sync', 'message' => 'permission check failed', 'data' => array('user_id' => $user_id, 'has_populi_id' => !empty($populi_id_val)), 'hypothesisId' => 'H3')) . "\n", FILE_APPEND | LOCK_EX);
            wp_die(__('You do not have permission to sync.'));
        }
        file_put_contents($dl, json_encode(array('timestamp' => round(microtime(true) * 1000), 'location' => 'class-populi-importer.php:handle_student_sync', 'message' => 'permission ok, calling sync_student_attendance', 'data' => array('user_id' => $user_id), 'hypothesisId' => 'H3')) . "\n", FILE_APPEND | LOCK_EX);
        $this->log_import("Student sync now: user ID {$user_id}", 'info');
        delete_transient('pbattend_sync_debounce_' . $user_id);
        $result = $this->sync_student_attendance($user_id);
        $redirect = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : wp_get_referer();
        if (!$redirect) {
            $redirect = home_url('/');
        }
        if ($result['success']) {
            $redirect = add_query_arg('pbattend_synced', '1', $redirect);
        } else {
            $redirect = add_query_arg('pbattend_sync_error', '1', $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Not logged in: send to login then back.
     */
    public function handle_student_sync_no_priv() {
        wp_safe_redirect(wp_login_url(add_query_arg('action', 'pbattend_student_sync', admin_url('admin-post.php'))));
        exit;
    }

    /**
     * Get the URL for the student "Sync now" action (for use in dashboard template).
     *
     * @return string URL with nonce and redirect_to to current page
     */
    public static function get_student_sync_url() {
        $url = add_query_arg(array(
            'action'    => 'pbattend_student_sync',
            '_wpnonce'  => wp_create_nonce('pbattend_student_sync'),
            'redirect_to' => (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''),
        ), admin_url('admin-post.php'));
        return $url;
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

    /**
     * Get students enrolled in a specific course offering from Populi
     * @param int $course_offering_id The course offering ID
     * @return array|false Array of student enrollments or false on error
     */
    private function get_course_offering_students($course_offering_id) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('Cannot fetch course offering students: API credentials not configured', 'error');
            return false;
        }

        $students_url = sprintf($this->get_endpoint_url('course_offering_students'), $course_offering_id);
        $response = wp_remote_get($students_url, array(
            'headers' => array('Authorization' => 'Bearer ' . $credentials['api_key']),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            $this->log_import('Failed to get course offering students: ' . $response->get_error_message(), 'error');
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (empty($data)) {
            $this->log_import('Empty response when fetching course offering students', 'warning');
            return false;
        }

        $top_keys = is_array($data) ? implode(', ', array_keys($data)) : 'not-array';
        $this->log_import("Course offering students response top-level keys: [{$top_keys}]", 'info');

        // Unwrap if Populi returns a paginated list (standard format: { "object": "list", "data": [...] })
        if (isset($data['data']) && is_array($data['data'])) {
            $this->log_import("Unwrapped 'data' key, found " . count($data['data']) . " enrollment(s)", 'info');
            return $data['data'];
        }
        if (isset($data['enrollments']) && is_array($data['enrollments'])) {
            return $data['enrollments'];
        }
        if (isset($data['students']) && is_array($data['students'])) {
            return $data['students'];
        }
        return $data;
    }

    /**
     * Find enrollment_id by matching student_id in the students array
     * @param array $students Array of student enrollments from Populi API
     * @param int $student_id The student ID to match
     * @return int|false The enrollment_id or false if not found
     */
    private function find_enrollment_id_by_student_id($students, $student_id) {
        if (!is_array($students)) {
            $this->log_import('Invalid students array provided to find_enrollment_id_by_student_id', 'error');
            return false;
        }

        $student_id_str = (string) $student_id;

        foreach ($students as $student) {
            if (!is_array($student)) {
                continue;
            }
            // Populi often uses person_id for the person; try that first, then student_id, then id
            $student_id_fields = array('person_id', 'student_id', 'id');

            foreach ($student_id_fields as $field) {
                if (!isset($student[$field])) {
                    continue;
                }
                if ((string) $student[$field] !== $student_id_str) {
                    continue;
                }
                // Enrollment ID: prefer enrollment_id; else use id (when each item is an enrollment, id is enrollment_id)
                $enrollment_id = isset($student['enrollment_id']) ? $student['enrollment_id'] : null;
                if ($enrollment_id === null && $field !== 'id' && isset($student['id'])) {
                    $enrollment_id = $student['id'];
                }
                if ($enrollment_id !== null && $enrollment_id !== '') {
                    $this->log_import("Found enrollment_id {$enrollment_id} for student_id {$student_id}", 'info');
                    return (int) $enrollment_id;
                }
            }
        }

        $first = reset($students);
        $sample = (is_array($first) && !empty($first)) ? implode(', ', array_keys($first)) : 'empty';
        $this->log_import("Enrollment ID not found for student_id {$student_id}. First item keys: [{$sample}]", 'warning');
        return false;
    }

    /**
     * Update attendance status in Populi for a specific enrollment
     * @param int $course_offering_id The course offering ID
     * @param int $enrollment_id The enrollment ID
     * @param string $status The attendance status (e.g., 'excused')
     * @param array $extra Optional extra params: course_meeting_id, start_time
     * @return bool True on success, false on failure
     */
    private function update_populi_attendance($course_offering_id, $enrollment_id, $status, $extra = array()) {
        $credentials = $this->get_api_credentials();
        if (empty($credentials['api_key'])) {
            $this->log_import('Cannot update attendance: API credentials not configured', 'error');
            return false;
        }

        $update_url = sprintf($this->get_endpoint_url('update_attendance'), $course_offering_id, $enrollment_id);
        $request_body = array('status' => $status);

        // Include course_meeting_id or start_time so Populi knows which meeting to update
        if (!empty($extra['course_meeting_id'])) {
            $request_body['course_meeting_id'] = (int) $extra['course_meeting_id'];
        }
        if (!empty($extra['start_time'])) {
            $request_body['start_time'] = $extra['start_time'];
        }
        if (!empty($extra['note'])) {
            $request_body['note'] = $extra['note'];
        }

        $this->log_import("Sending update_attendance PUT to {$update_url} with body: " . json_encode($request_body), 'info');

        $response = wp_remote_request($update_url, array(
            'method' => 'PUT',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $credentials['api_key']
            ),
            'body' => json_encode($request_body),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            $this->log_import('Failed to update attendance: ' . $response->get_error_message(), 'error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code >= 200 && $response_code < 300) {
            $this->log_import("Successfully updated attendance to '{$status}' for enrollment {$enrollment_id}", 'info');
            return true;
        } else {
            $this->log_import("Failed to update attendance. HTTP {$response_code}: {$response_body}", 'error');
            return false;
        }
    }

    /**
     * Resolve course_offering_id, enrollment_id, and meeting identifiers for a given attendance record.
     * Used by both sync_attendance_to_populi and sync_rejection_note_to_populi.
     *
     * @param int $post_id The attendance record post ID
     * @return array|false Associative array with keys course_offering_id, enrollment_id, extra; or false on failure
     */
    private function resolve_populi_sync_context($post_id) {
        $course_offering_id = get_field('course_info_course_id', $post_id);
        $populi_id = get_field('populi_id', $post_id);

        if (empty($course_offering_id)) {
            $this->log_import("Cannot resolve sync context: course_offering_id not found for post {$post_id}", 'error');
            return false;
        }
        if (empty($populi_id)) {
            $this->log_import("Cannot resolve sync context: populi_id not found for post {$post_id}", 'error');
            return false;
        }

        $this->log_import("Resolving sync context for course_offering_id: {$course_offering_id}, populi_id (person_id): {$populi_id}", 'info');

        $student_data = $this->get_student_data($populi_id);
        $student_id = null;
        if (!empty($student_data['id'])) {
            $student_id = $student_data['id'];
            $this->log_import("Resolved person_id {$populi_id} to student_id {$student_id}", 'info');
        } else {
            $this->log_import("Could not resolve person_id {$populi_id} to student_id via Student API. Response keys: " . (is_array($student_data) ? implode(', ', array_keys($student_data)) : 'false'), 'warning');
            $student_id = $populi_id;
        }

        $students = $this->get_course_offering_students($course_offering_id);
        if (!$students) {
            $this->log_import("Failed to fetch students for course offering {$course_offering_id}", 'error');
            return false;
        }

        $enrollment_id = $this->find_enrollment_id_by_student_id($students, $student_id);
        if (!$enrollment_id) {
            $this->log_import("Could not find enrollment_id for student_id {$student_id} (person_id {$populi_id}) in course offering {$course_offering_id}", 'warning');
            return false;
        }

        $extra = array();
        $course_meeting_id = get_field('course_info_course_meeting_id', $post_id);
        if (!empty($course_meeting_id)) {
            $extra['course_meeting_id'] = $course_meeting_id;
        }
        $start_time = get_field('attendance_details_meeting_start_time', $post_id);
        if (!empty($start_time)) {
            $extra['start_time'] = $start_time;
        }

        if (empty($extra['course_meeting_id']) && empty($extra['start_time'])) {
            $this->log_import("Cannot sync for post {$post_id}: neither course_meeting_id nor start_time is available. Populi needs at least one to identify the meeting.", 'error');
            return false;
        }

        return array(
            'course_offering_id' => $course_offering_id,
            'enrollment_id'      => $enrollment_id,
            'extra'              => $extra,
        );
    }

    /**
     * Sync attendance status back to Populi when review action is "approved"
     * Sets attendance status to "excused" in Populi
     * @param int $post_id The attendance record post ID
     * @return bool True on success, false on failure
     */
    public function sync_attendance_to_populi($post_id) {
        $this->log_import("Starting attendance sync to Populi for post ID: {$post_id}", 'info');

        $context = $this->resolve_populi_sync_context($post_id);
        if (!$context) {
            return false;
        }

        $success = $this->update_populi_attendance($context['course_offering_id'], $context['enrollment_id'], 'excused', $context['extra']);
        if ($success) {
            $this->log_import("Successfully synced attendance status to 'excused' for post {$post_id}", 'info');
            return true;
        } else {
            $this->log_import("Failed to update attendance status for post {$post_id}", 'error');
            return false;
        }
    }

    /**
     * Sync rejection reason to Populi as a note without changing the attendance status.
     * Sends the rejection_reason with current datetime appended. Populi API requires status,
     * so we pass the record's existing attendance status (TARDY/ABSENT) unchanged.
     *
     * @param int $post_id The attendance record post ID
     * @return bool True on success, false on failure
     */
    public function sync_rejection_note_to_populi($post_id) {
        $this->log_import("Starting rejection note sync to Populi for post ID: {$post_id}", 'info');

        $reason = get_field('rejection_reason', $post_id);
        $reason = $reason ? wp_strip_all_tags($reason) : '';
        $note = $reason . ' [' . current_time('Y-m-d H:i:s') . ']';

        $status_raw = get_field('attendance_details_attendance_status', $post_id);
        $status = $status_raw ? strtolower($status_raw) : 'absent';

        $context = $this->resolve_populi_sync_context($post_id);
        if (!$context) {
            return false;
        }

        $context['extra']['note'] = $note;

        $success = $this->update_populi_attendance($context['course_offering_id'], $context['enrollment_id'], $status, $context['extra']);
        if ($success) {
            $this->log_import("Successfully synced rejection note to Populi for post {$post_id}", 'info');
            return true;
        } else {
            $this->log_import("Failed to sync rejection note for post {$post_id}", 'error');
            return false;
        }
    }
} 