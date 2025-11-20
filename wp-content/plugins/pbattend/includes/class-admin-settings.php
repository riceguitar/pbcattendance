<?php
/**
 * Handles admin settings for the plugin
 */
class PBAttend_Admin_Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add API test action
        add_action('admin_post_pbattend_test_api', array($this, 'handle_api_test'));
        
        // Add single user sync test
        add_action('admin_post_pbattend_test_single_sync', array($this, 'handle_single_sync_test'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=pbattend_record',
            __('PB Attend Settings', 'pbattend'),
            __('Settings', 'pbattend'),
            'manage_options',
            'pbattend-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('pbattend_settings', 'pbattend_populi_api_key');
        register_setting('pbattend_settings', 'pbattend_populi_api_base');

        add_settings_section(
            'pbattend_populi_settings',
            __('Populi API Settings', 'pbattend'),
            array($this, 'render_section_info'),
            'pbattend-settings'
        );

        add_settings_field(
            'pbattend_populi_api_key',
            __('API Key', 'pbattend'),
            array($this, 'render_api_key_field'),
            'pbattend-settings',
            'pbattend_populi_settings'
        );

        add_settings_field(
            'pbattend_populi_api_base',
            __('API Base URL', 'pbattend'),
            array($this, 'render_api_base_field'),
            'pbattend-settings',
            'pbattend_populi_settings'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('pbattend_settings');
                do_settings_sections('pbattend-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php _e('Manual Import', 'pbattend'); ?></h2>
            <p><?php _e('Click the button below to manually import attendance records from Populi.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_manual_import', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_manual_import">
                <?php submit_button(__('Import Attendance Records', 'pbattend'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('User Import', 'pbattend'); ?></h2>
            <p><?php _e('Click the button below to import WordPress users for all students in your attendance records.', 'pbattend'); ?></p>
            <p class="description"><?php _e('This will create or update WordPress users with the Subscriber role for all students found in your attendance records.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_user_import', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_user_import">
                <?php submit_button(__('Import Users', 'pbattend'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('Reset Importer', 'pbattend'); ?></h2>
            <p><?php _e('Use this button to reset the importer state. This will clear all tracking of previously imported records, allowing you to reimport everything.', 'pbattend'); ?></p>
            <p class="description"><?php _e('Warning: This will not delete any existing records, but will allow them to be imported again.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_manual_import', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_manual_import">
                <input type="hidden" name="reset" value="1">
                <?php submit_button(__('Reset Importer', 'pbattend'), 'secondary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('API Connection Test', 'pbattend'); ?></h2>
            <p><?php _e('Test your POPULI API connection to diagnose sync issues.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_test_api', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_test_api">
                <?php submit_button(__('Test API Connection', 'pbattend'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('User Sync', 'pbattend'); ?></h2>
            <p><?php _e('Sync existing users with POPULI data using their email addresses. This will populate missing student IDs for users who logged in via SSO but don\'t have their POPULI data linked yet.', 'pbattend'); ?></p>
            <p class="description"><?php _e('<strong>Note:</strong> Processes 10 users at a time to prevent timeouts. Run multiple times if needed. Each batch takes 1-2 minutes.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_bulk_sync', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_bulk_sync">
                <?php submit_button(__('Sync All Users', 'pbattend'), 'secondary', 'submit', false); ?>
            </form>
            
            <p><strong>Debug:</strong> Test sync with a single user first:</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_test_single_sync', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_test_single_sync">
                <?php submit_button(__('Test Single User Sync', 'pbattend'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('User Sync Status', 'pbattend'); ?></h2>
            <?php $this->render_user_sync_status(); ?>

            <hr>

            <h2><?php _e('Import Log', 'pbattend'); ?></h2>
            <?php $this->render_import_log(); ?>
        </div>
        <?php
    }

    /**
     * Render section info
     */
    public function render_section_info() {
        echo '<p>' . __('Configure your Populi API settings below.', 'pbattend') . '</p>';
    }

    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $value = get_option('pbattend_populi_api_key');
        echo '<input type="password" id="pbattend_populi_api_key" name="pbattend_populi_api_key" value="' . esc_attr($value) . '" class="regular-text">';
    }

    /**
     * Render API base URL field
     */
    public function render_api_base_field() {
        $value = get_option('pbattend_populi_api_base', 'https://pbc.populiweb.com/api2');
        echo '<input type="url" id="pbattend_populi_api_base" name="pbattend_populi_api_base" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('Base URL for the Populi API (e.g., https://pbc.populiweb.com/api2)', 'pbattend') . '</p>';
    }

    /**
     * Render user sync status
     */
    private function render_user_sync_status() {
        // Get sync status counts
        $users = get_users(array('number' => 100));
        $status_counts = array(
            'synced' => 0,
            'pending' => 0,
            'failed' => 0,
            'not_found' => 0,
            'no_status' => 0
        );
        
        foreach ($users as $user) {
            $sync_status = get_field('populi_sync_status', 'user_' . $user->ID);
            if (empty($sync_status)) {
                $status_counts['no_status']++;
            } else {
                $status_counts[$sync_status]++;
            }
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Sync Status</th><th>Count</th><th>Description</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td><strong>Successfully Synced</strong></td><td>' . $status_counts['synced'] . '</td><td>Users with POPULI data linked</td></tr>';
        echo '<tr><td><strong>Pending Sync</strong></td><td>' . $status_counts['pending'] . '</td><td>Users waiting to be synced</td></tr>';
        echo '<tr><td><strong>Sync Failed</strong></td><td>' . $status_counts['failed'] . '</td><td>Users where sync encountered errors</td></tr>';
        echo '<tr><td><strong>Not Found</strong></td><td>' . $status_counts['not_found'] . '</td><td>Users not found in POPULI</td></tr>';
        echo '<tr><td><strong>No Status</strong></td><td>' . $status_counts['no_status'] . '</td><td>Users never attempted sync</td></tr>';
        echo '</tbody>';
        echo '</table>';
        
        $total_need_sync = $status_counts['pending'] + $status_counts['failed'] + $status_counts['no_status'];
        if ($total_need_sync > 0) {
            echo '<p><strong>' . $total_need_sync . ' users need syncing.</strong></p>';
        }
    }

    /**
     * Render import log
     */
    private function render_import_log() {
        $log = get_option('pbattend_import_log', array());
        if (empty($log)) {
            echo '<p>' . __('No import activity logged yet.', 'pbattend') . '</p>';
            return;
        }

        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'pbattend') . '</th>';
        echo '<th>' . __('Type', 'pbattend') . '</th>';
        echo '<th>' . __('Message', 'pbattend') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach (array_reverse($log) as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html($entry['timestamp']) . '</td>';
            echo '<td>' . esc_html($entry['type']) . '</td>';
            echo '<td>' . esc_html($entry['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Handle API test request
     */
    public function handle_api_test() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_test_api', 'pbattend_nonce');

        // Get API credentials
        $api_key = get_option('pbattend_populi_api_key');
        $api_base = get_option('pbattend_populi_api_base', 'https://pbc.populiweb.com/api2');

        $test_results = array();

        // Test 1: Check if credentials are configured
        if (empty($api_key)) {
            $test_results[] = array(
                'test' => 'API Key Configuration',
                'status' => 'FAIL',
                'message' => 'API Key is not configured'
            );
        } else {
            $test_results[] = array(
                'test' => 'API Key Configuration',
                'status' => 'PASS',
                'message' => 'API Key is configured (length: ' . strlen($api_key) . ')'
            );
        }

        // Test 2: Test basic API connectivity
        if (!empty($api_key)) {
            $test_url = trailingslashit($api_base) . 'people?limit=1';
            
            $response = wp_remote_get($test_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'timeout' => 10
            ));

            if (is_wp_error($response)) {
                $test_results[] = array(
                    'test' => 'API Connectivity',
                    'status' => 'FAIL',
                    'message' => 'Connection error: ' . $response->get_error_message()
                );
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                
                if ($response_code === 200) {
                    $test_results[] = array(
                        'test' => 'API Connectivity',
                        'status' => 'PASS',
                        'message' => 'Successfully connected to POPULI API'
                    );
                } elseif ($response_code === 401) {
                    $test_results[] = array(
                        'test' => 'API Connectivity',
                        'status' => 'FAIL',
                        'message' => 'Authentication failed - check your API key'
                    );
                } elseif ($response_code === 404) {
                    $test_results[] = array(
                        'test' => 'API Connectivity',
                        'status' => 'FAIL',
                        'message' => 'API endpoint not found - check your API base URL'
                    );
                } else {
                    $test_results[] = array(
                        'test' => 'API Connectivity',
                        'status' => 'FAIL',
                        'message' => 'HTTP ' . $response_code . ': ' . substr($response_body, 0, 200)
                    );
                }
            }
        }

        // Test 3: Test getting people list (this is what we actually use)
        if (!empty($api_key)) {
            $test_url = trailingslashit($api_base) . 'people?limit=5';
            
            $response = wp_remote_get($test_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'timeout' => 10
            ));

            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if ($response_code === 200) {
                    if (!empty($data['data'])) {
                        $count = count($data['data']);
                        $test_results[] = array(
                            'test' => 'People List Access',
                            'status' => 'PASS',
                            'message' => 'Successfully retrieved ' . $count . ' people from POPULI'
                        );
                    } else {
                        $test_results[] = array(
                            'test' => 'People List Access',
                            'status' => 'INFO',
                            'message' => 'POPULI returned empty people list (this may be normal)'
                        );
                    }
                }
            }
        }

        // Create summary message
        $pass_count = count(array_filter($test_results, function($r) { return $r['status'] === 'PASS'; }));
        $fail_count = count(array_filter($test_results, function($r) { return $r['status'] === 'FAIL'; }));
        
        $summary = sprintf('API Test Results: %d passed, %d failed', $pass_count, $fail_count);
        
        // Build detailed message
        $detailed_message = $summary . "\n\n";
        foreach ($test_results as $result) {
            $detailed_message .= sprintf("[%s] %s: %s\n", $result['status'], $result['test'], $result['message']);
        }

        // Set notice
        set_transient('pbattend_admin_notice', array(
            'type' => $fail_count > 0 ? 'error' : 'success',
            'message' => $detailed_message
        ), 45);

        // Redirect back to settings page
        wp_redirect(add_query_arg(
            'page',
            'pbattend-settings',
            admin_url('edit.php?post_type=pbattend_record')
        ));
        exit;
    }

    /**
     * Handle single user sync test
     */
    public function handle_single_sync_test() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        check_admin_referer('pbattend_test_single_sync', 'pbattend_nonce');

        // Get a single user to test with
        $users = get_users(array('number' => 1));
        if (empty($users)) {
            set_transient('pbattend_admin_notice', array(
                'type' => 'error',
                'message' => 'No users found to test with'
            ), 45);
        } else {
            $user = $users[0];
            
            // Create importer instance and test sync
            $importer = new PBAttend_Populi_Importer();
            
            // Add detailed logging
            $importer->log_import('=== SINGLE USER SYNC TEST START ===', 'info');
            $importer->log_import('Testing with user: ' . $user->display_name . ' (email: ' . $user->user_email . ')', 'info');
            
            // Check if user has existing data
            $existing_student_id = get_field('student_id', 'user_' . $user->ID);
            $importer->log_import('Existing student_id: ' . ($existing_student_id ?: 'None'), 'info');
            
            // Test the sync
            $result = $importer->sync_user_by_email($user);
            
            $importer->log_import('=== SINGLE USER SYNC TEST END ===', 'info');
            $importer->log_import('Result: ' . ($result ? 'SUCCESS' : 'FAILED'), $result ? 'info' : 'error');
            
            set_transient('pbattend_admin_notice', array(
                'type' => $result ? 'success' : 'error',
                'message' => sprintf(
                    'Single user sync test completed for %s. Result: %s. Check Import Log for details.',
                    $user->display_name,
                    $result ? 'SUCCESS' : 'FAILED'
                )
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
} 