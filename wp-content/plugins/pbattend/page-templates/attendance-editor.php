<?php
/**
 * Template Name: Attendance Editor
 * 
 * This template is used for editing attendance record notes
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if user is logged in
if (!is_user_logged_in()) {
    auth_redirect();
}

// Get record ID from URL
$record_id = isset($_GET['record_id']) ? intval($_GET['record_id']) : 0;

// Verify record exists
if (!$record_id || !get_post($record_id)) {
    wp_die('Invalid record ID');
}

// Get record details
$record = get_post($record_id);
$review_status = get_field('field_review_status', $record_id);
$course_name = get_field('course_info_course_name', $record_id);
$meeting_date = get_field('attendance_details_meeting_start_time', $record_id);
$student_id = get_field('student_id', $record_id);
$current_notes = get_field('attendance_note', $record_id);

// Check if record can be edited
if ($review_status !== 'pending') {
    wp_die('This record cannot be edited at this time. Only records with "Pending" status can be edited.');
}

// Only add ACF form head if we're going to show the form
acf_form_head();

get_header();
?>

<div class="wrap">
    <div class="pbattend-editor">

            <h3>Edit Attendance Record</h3>
            <a href="/attendance">
                &larr; Back to Dashboard
            </a>
        
        
        <div class="pbattend-record-details">
            <h2>Record Details</h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th>Course</th>
                        <td><?php echo esc_html($course_name); ?></td>
                    </tr>
                    <tr>
                        <th>Date</th>
                        <td><?php echo esc_html($meeting_date); ?></td>
                    </tr>
                    <tr>
                        <th>Student ID</th>
                        <td><?php echo esc_html($student_id); ?></td>
                    </tr>
                    <tr>
                        <th>Review Status</th>
                        <td><?php echo esc_html($review_status ?: 'Pending'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pbattend-notes-form">
            <h2>Attendance Notes</h2>


            
            <?php
            // Set up ACF form
            acf_form(array(
                'post_id' => $record_id,
                'field_groups' => array('group_attendance_note'),
                'form' => true,
                'return' => home_url('/attendance/'),  // Redirect to dashboard
                'submit_value' => 'Update Notes',
                'html_before_fields' => '',
                'html_after_fields' => '',
                'updated_message' => 'Notes updated successfully.',
                'instruction_placement' => 'field',  // Show instructions with the field
                'field_el' => 'div',  // Use div instead of table for cleaner layout
                'uploader' => 'wp',  // Use WordPress media uploader
                'honeypot' => true,  // Add spam protection
            ));
            ?>



        </div>
    </div>
</div>

<?php get_footer(); ?> 