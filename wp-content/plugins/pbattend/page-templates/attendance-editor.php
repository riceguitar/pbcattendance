<?php
/**
 * Template Name: Attendance Editor
 * 
 * This template is used for editing a single attendance record.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get record ID from URL and validate it.
$record_id = isset($_GET['record_id']) ? intval($_GET['record_id']) : 0;
if (!$record_id || get_post_type($record_id) !== 'pbattend_record') {
    wp_die('Invalid or missing record ID.');
}

$controller = new PBAttend\Frontend_Controller();

// Security check: Ensure the current user is allowed to edit this record
if (!$controller->can_edit_record($record_id)) {
    wp_die('You do not have permission to view or edit this record.');
}

// Get field values
$populi_id = get_field('populi_id', $record_id);
$first_name = get_field('first_name', $record_id);
$last_name = get_field('last_name', $record_id);
$course_name = get_field('course_info_course_name', $record_id);
$meeting_date = get_field('attendance_details_meeting_start_time', $record_id);
$review_status = get_field('review_status', $record_id);

acf_form_head();

get_header();
?>

<div class="wrap pbattend-editor-container">
    <h1>Edit Attendance Note</h1>

    <?php
    // Show a message if the record is not in 'pending' status
    if ($review_status !== 'pending' && $review_status !== '') {
        echo '<div class="notice notice-warning"><p>This record cannot be edited at this time. Only records with a "Pending" status can be edited.</p></div>';
    } else {
        // Display the ACF form for editing
        ?>
        <div class="pbattend-record-details">
            <h2>Record Details</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th>Student Name</th>
                        <td><?php echo esc_html($first_name . ' ' . $last_name); ?></td>
                    </tr>
                    <tr>
                        <th>Populi ID</th>
                        <td><?php echo esc_html($populi_id); ?></td>
                    </tr>
                    <tr>
                        <th>Course Name</th>
                        <td><?php echo esc_html($course_name); ?></td>
                    </tr>
                    <tr>
                        <th>Meeting Date</th>
                        <td><?php echo esc_html($meeting_date); ?></td>
                    </tr>
                    <tr>
                        <th>Review Status</th>
                        <td><?php echo esc_html(($review_status === 'approved') ? 'Excused' : (ucfirst($review_status) ?: 'Pending')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pbattend-acf-form">
            <?php
            acf_form(array(
                'post_id' => $record_id,
                'fields' => array('attendance_note'),
                'submit_value' => 'Submit Notes for Review',
                'updated_message' => 'Your notes have been submitted successfully.',
                'html_updated_message'  => '<div id="message" class="updated"><p>%s</p></div>',
                'return' => home_url('/attendance/'),
            ));
            ?>
        </div>
    <?php } ?>
</div>

<?php get_footer(); ?> 