<?php
/**
 * Handles custom post type registration
 */
class PBAttend_Post_Types {
    public function __construct() {
        add_action('init', array($this, 'register_attendance_post_type'));
        
        // Add custom columns
        add_filter('manage_pbattend_record_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_pbattend_record_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_filter('manage_edit-pbattend_record_sortable_columns', array($this, 'make_columns_sortable'));
        add_action('pre_get_posts', array($this, 'custom_column_orderby'));
        add_action('admin_head', array($this, 'add_admin_styles'));

        // Add review actions metabox
        add_action('add_meta_boxes', array($this, 'add_review_metabox'));
        // Use priority 20 to run after ACF processes its fields (ACF uses priority 10)
        add_action('save_post_pbattend_record', array($this, 'save_review_data'), 20);
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
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-calendar-alt',
            'hierarchical'        => false,
            'supports'            => array('title', 'author'),
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
        );

        register_post_type('pbattend_record', $args);
    }

    /**
     * Add review actions metabox
     */
    public function add_review_metabox() {
        add_meta_box(
            'pbattend_review_actions',
            __('Attendance Review', 'pbattend'),
            array($this, 'render_review_metabox'),
            'pbattend_record',
            'side',
            'high'
        );
    }

    /**
     * Render the review metabox
     */
    public function render_review_metabox($post) {
        wp_nonce_field('pbattend_review_action', 'pbattend_review_nonce');

        $current_status = get_field('review_status', $post->ID) ?: 'pending';
        $rejection_reason_raw = get_field('rejection_reason', $post->ID);
        // Strip any HTML that might have been added during display/formatting
        $rejection_reason = $rejection_reason_raw ? wp_strip_all_tags($rejection_reason_raw) : '';
        ?>
        <div id="pbattend-review-control">
            <p><strong><?php _e('Current Status:', 'pbattend'); ?></strong> <span id="current-review-status"><?php echo esc_html($current_status === 'approved' ? 'Excused' : ucfirst($current_status)); ?></span></p>
            
            <div class="review-actions" style="margin: 15px 0;">
                <label for="pbattend_review_action"><strong><?php _e('Review Action:', 'pbattend'); ?></strong></label>
                <select name="pbattend_review_action" id="pbattend_review_action" style="width: 100%; margin-top: 5px;">
                    <option value=""><?php _e('— No Change —', 'pbattend'); ?></option>
                    <option value="approved"><?php _e('Excused', 'pbattend'); ?></option>
                    <option value="rejected"><?php _e('Reject', 'pbattend'); ?></option>
                </select>
            </div>

            <div id="rejection-reason-wrapper" style="display: none; margin-top: 15px;">
                <label for="rejection_reason_metabox"><strong><?php _e('Rejection Reason:', 'pbattend'); ?></strong></label>
                <textarea name="rejection_reason_metabox" id="rejection_reason_metabox" style="width: 100%; margin-top: 5px;" rows="4" placeholder="<?php esc_attr_e('Enter reason for rejection...', 'pbattend'); ?>"><?php echo esc_textarea($rejection_reason); ?></textarea>
                <p class="description" style="margin-top: 5px;">
                    <?php _e('This reason will be saved with the rejection.', 'pbattend'); ?>
                </p>
            </div>

            <p class="description" style="margin-top: 15px;">
                <?php _e('Select an action and click "Update" to save.', 'pbattend'); ?>
            </p>
        </div>
        <script>
            (function() {
                const reviewAction = document.getElementById('pbattend_review_action');
                const rejectionWrapper = document.getElementById('rejection-reason-wrapper');
                const currentStatus = '<?php echo esc_js($current_status); ?>';
                
                if (reviewAction && rejectionWrapper) {
                    // Show/hide rejection reason based on selection
                    function toggleRejectionReason() {
                        if (reviewAction.value === 'rejected') {
                            rejectionWrapper.style.display = 'block';
                        } else {
                            rejectionWrapper.style.display = 'none';
                        }
                    }
                    
                    // Show rejection reason on page load if status is already rejected
                    if (currentStatus === 'rejected' || reviewAction.value === 'rejected') {
                        rejectionWrapper.style.display = 'block';
                    }
                    
                    // Listen for changes
                    reviewAction.addEventListener('change', toggleRejectionReason);
                }
            })();
        </script>
        <?php
    }

    /**
     * Save review data when the post is updated
     */
    public function save_review_data($post_id) {
        // Skip if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Skip if user doesn't have permission
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['pbattend_review_nonce']) || !wp_verify_nonce($_POST['pbattend_review_nonce'], 'pbattend_review_action')) {
            return;
        }
        
        // Process review action if set
        if (isset($_POST['pbattend_review_action']) && !empty($_POST['pbattend_review_action'])) {
            $action = sanitize_text_field($_POST['pbattend_review_action']);
            
            if ($action === 'approved') {
                update_field('review_status', 'approved', $post_id);
                update_field('rejection_reason', '', $post_id); // Clear rejection reason when approved

                // Sync attendance status to Populi (set to "excused")
                if (class_exists('PBAttend_Populi_Importer')) {
                    $importer = new PBAttend_Populi_Importer();
                    $importer->sync_attendance_to_populi($post_id);
                }
            } elseif ($action === 'rejected') {
                update_field('review_status', 'rejected', $post_id);
                // Save rejection reason from metabox if provided
                if (isset($_POST['rejection_reason_metabox'])) {
                    $reason = sanitize_textarea_field($_POST['rejection_reason_metabox']);
                    update_field('rejection_reason', $reason, $post_id);
                    
                    // Send email notification to student if reason is provided
                    if (!empty($reason)) {
                        $this->send_rejection_email($post_id, $reason);
                    }
                }
                // Sync rejection note to Populi (note only; attendance status unchanged)
                if (class_exists('PBAttend_Populi_Importer')) {
                    $importer = new PBAttend_Populi_Importer();
                    $importer->sync_rejection_note_to_populi($post_id);
                }
            }
        } else {
            // No action selected, but check if we need to update rejection reason
            // This handles the case where status is already rejected and user is updating the reason
            $current_status = get_field('review_status', $post_id);
            if ($current_status === 'rejected' && isset($_POST['rejection_reason_metabox'])) {
                $reason = sanitize_textarea_field($_POST['rejection_reason_metabox']);
                $existing_reason = get_field('rejection_reason', $post_id);
                
                // Only update if the reason has changed to avoid unnecessary email sends
                if ($reason !== $existing_reason) {
                    update_field('rejection_reason', $reason, $post_id);
                }
            }
        }
    }

    /**
     * Send email notification to student when attendance is rejected
     */
    private function send_rejection_email($post_id, $rejection_reason) {
        // Get the attendance record data
        $populi_id = get_field('populi_id', $post_id);
        $first_name = get_field('first_name', $post_id);
        $last_name = get_field('last_name', $post_id);
        $course_name = get_field('course_info_course_name', $post_id);
        $meeting_time = get_field('attendance_details_meeting_start_time', $post_id);
        
        if (!$populi_id) {
            return; // Can't find student without Populi ID
        }
        
        // Find WordPress user by Populi ID
        $users = get_users(array(
            'meta_key' => 'populi_id',
            'meta_value' => $populi_id,
            'number' => 1,
        ));
        
        if (empty($users)) {
            return; // Student not found in WordPress
        }
        
        $student = $users[0];
        $student_email = $student->user_email;
        $student_name = $student->display_name ?: ($first_name . ' ' . $last_name);
        
        // Format meeting time
        $meeting_date = $meeting_time ? date('F j, Y \a\t g:i A', strtotime($meeting_time)) : 'N/A';
        
        // Email subject
        $subject = sprintf(
            __('Attendance Record Rejected - %s', 'pbattend'),
            $course_name ?: 'Your Course'
        );
        
        // Email body
        $message = sprintf(
            __(
                "Dear %s,\n\n"
                . "Your attendance record for %s on %s has been reviewed and rejected.\n\n"
                . "Rejection Reason:\n"
                . "%s\n\n"
                . "If you have questions about this decision, please contact your instructor.\n\n"
                . "Best regards,\n"
                . "Portland Bible College",
                'pbattend'
            ),
            $student_name,
            $course_name ?: 'your course',
            $meeting_date,
            wp_strip_all_tags($rejection_reason)
        );
        
        // Send email
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $sent = wp_mail($student_email, $subject, $message, $headers);
        
        // Log the email attempt
        if (!$sent) {
            error_log('PBAttend: Failed to send rejection email to ' . $student_email . ' for post ' . $post_id);
        }
    }

    /**
     * Set custom columns for attendance records
     */
    public function add_custom_columns($columns) {
        unset($columns['author']);
        unset($columns['date']); // We will re-add this in our desired order

        $new_columns = array(
            'cb' => $columns['cb'],
            'title' => __('Title', 'pbattend'),
            'student' => __('Student', 'pbattend'),
            'date' => __('Date'), // WordPress default date column
            'status' => __('Status', 'pbattend'),
            'start_time' => __('Start Time', 'pbattend'),
            'review_status' => __('Review Status', 'pbattend'),
            'rejection_reason' => __('Rejection Reason', 'pbattend'),
            'course_meeting_id' => __('Meeting ID', 'pbattend'),
            'notes' => __('Notes', 'pbattend'),
        );
        
        // Debug: Log column registration (remove after testing)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PBAttend: Registered columns: ' . implode(', ', array_keys($new_columns)));
        }
        
        return $new_columns;
    }

    /**
     * Render custom columns
     */
    public function render_custom_columns($column, $post_id) {
        switch ($column) {
            case 'student':
                $first_name = get_field('first_name', $post_id);
                $last_name = get_field('last_name', $post_id);
                echo esc_html($first_name . ' ' . $last_name);
                
                $populi_id = get_field('populi_id', $post_id);
                if ($populi_id) {
                    echo '<br><small>ID: ' . esc_html($populi_id) . '</small>';
                }
                break;
            
            case 'status':
                echo esc_html(get_field('attendance_details_attendance_status', $post_id));
                break;
            
            case 'start_time':
                $meeting_time = get_field('attendance_details_meeting_start_time', $post_id);
                if ($meeting_time) {
                    echo esc_html(date('g:iA, M j, Y', strtotime($meeting_time)));
                }
                break;
            
            case 'review_status':
                $status = get_field('review_status', $post_id);
                $status_class = 'status-' . ($status ?: 'pending');
                $status_label = ($status === 'approved') ? 'Excused' : (ucfirst($status) ?: 'Pending');
                echo '<span class="review-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
                break;

            case 'rejection_reason':
                $reason = get_field('rejection_reason', $post_id);
                if ($reason) {
                    // Strip HTML tags and display plain text
                    $reason_plain = wp_strip_all_tags($reason);
                    // Truncate if too long for column display
                    if (strlen($reason_plain) > 100) {
                        echo esc_html(substr($reason_plain, 0, 100)) . '...';
                    } else {
                        echo esc_html($reason_plain);
                    }
                } else {
                    echo '—';
                }
                break;

            case 'course_meeting_id':
                $meeting_id = get_field('course_info_course_meeting_id', $post_id);
                echo $meeting_id ? esc_html($meeting_id) : '—';
                break;

            case 'notes':
                $notes = get_field('attendance_note', $post_id);
                if (strlen($notes) > 100) {
                    echo esc_html(substr($notes, 0, 100)) . '...';
                } else {
                    echo esc_html($notes) ?: '—';
                }
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function make_columns_sortable($columns) {
        $columns['start_time'] = 'start_time';
        $columns['review_status'] = 'review_status';
        return $columns;
    }

    public function custom_column_orderby($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ('start_time' === $orderby) {
            $query->set('meta_key', 'attendance_details_meeting_start_time');
            $query->set('orderby', 'meta_value');
        }

        if ('review_status' === $orderby) {
            $query->set('meta_key', 'review_status');
            $query->set('orderby', 'meta_value');
        }
    }

    public function add_admin_styles() {
        ?>
        <style>
            .review-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                line-height: 1.4;
                font-weight: 600;
            }
            .status-pending, .status- {
                background-color: #f0f0f1;
                color: #1d2327;
            }
            .status-review {
                background-color: #f0f8ff;
                color: #1a4624;
            }
            .status-approved {
                background-color: #d1f7cb;
                color: #1a4624;
            }
            .status-rejected {
                background-color: #f7d7da;
                color: #8a2424;
            }
        </style>
        <?php
    }
} 