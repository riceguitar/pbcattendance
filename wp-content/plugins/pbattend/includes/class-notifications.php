<?php
/**
 * Handles email notifications for the plugin
 */
class PBAttend_Notifications {
    public function __construct() {
        // Hook for ACF saves
        add_action('acf/save_post', array($this, 'check_review_status_change'), 20);
        
        // Hook for Admin Columns Pro inline edits
        add_action('acp/editing/saved', array($this, 'handle_acp_save'), 10, 2);
    }

    /**
     * Handle Admin Columns Pro inline edit saves
     */
    public function handle_acp_save($id, $column) {
        // Only proceed if this is the review status column
        if ($column->get_name() !== 'review_status') {
            return;
        }

        // Get the post type
        $post_type = get_post_type($id);
        if ($post_type !== 'pbattend_record') {
            return;
        }

        // Get the new status from the request
        $new_status = $_POST['value'] ?? '';
        if (empty($new_status)) {
            return;
        }

        // Get the old status
        $old_status = get_field('review_status', $id, false);

        // If status has changed and is either approved or rejected
        if ($old_status !== $new_status && in_array($new_status, array('approved', 'rejected'))) {
            $this->send_status_notification($id, $new_status);
        }
    }

    /**
     * Check if review status has changed and send notification if needed
     */
    public function check_review_status_change($post_id) {
        // Only proceed if this is an attendance record
        if (get_post_type($post_id) !== 'pbattend_record') {
            return;
        }

        // Get the old and new review status
        $old_status = get_field('review_status', $post_id, false); // Get the value before update
        $new_status = $_POST['acf']['field_review_status'] ?? ''; // Get the new value being saved

        // If status has changed and is either approved or rejected
        if ($old_status !== $new_status && in_array($new_status, array('approved', 'rejected'))) {
            $this->send_status_notification($post_id, $new_status);
        }
    }

    /**
     * Send email notification about status change
     */
    private function send_status_notification($post_id, $new_status) {
        $to = 'david@26am.com';
        $subject = 'PBC Attendance Record Status Update';
        $message = sprintf(
            'Your PBC attendance record has been updated to %s',
            strtoupper($new_status)
        );

        // Add record details to email
        $student_name = get_field('first_name', $post_id) . ' ' . get_field('last_name', $post_id);
        $course_name = get_field('course_info_course_name', $post_id);
        $attendance_date = get_field('attendance_details_meeting_start_time', $post_id);
        
        $message .= "\n\nRecord Details:";
        $message .= "\nStudent: " . $student_name;
        $message .= "\nCourse: " . $course_name;
        $message .= "\nDate: " . $attendance_date;
        
        wp_mail($to, $subject, $message);
    }
} 