<?php
/**
 * Template Name: My Attendance Dashboard
 * 
 * This template is used for displaying the user's attendance records
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if user is logged in
if (!is_user_logged_in()) {
    auth_redirect();
}

$controller = new \PBAttend\Frontend_Controller();
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$page = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
$per_page = 1000;

$user_id = get_current_user_id();
$user = get_userdata($user_id);
$user_populi_id = get_field('populi_id', 'user_' . $user_id);
$student_visible_id = get_field('student_visible_id', 'user_' . $user_id);

// Debug information
if (current_user_can('administrator')) {
    echo '<div class="notice notice-info">';
    echo '<p>Debug Information:</p>';
    echo '<ul>';
    echo '<li>User ID: ' . $user_id . '</li>';
    echo '<li>Student ID: ' . ($user_populi_id ? $user_populi_id : 'Not set') . '</li>';
    echo '</ul>';
    echo '</div>';
}

$query = $controller->get_user_records($status, $page, $per_page);

get_header();
?>

<div class="wrap">
    <h3><?php the_title(); ?></h3>
    <p>You can find your attendance records below. Any records with the status of "pending" can be edited for you to attach a reason for the absence or tardy.</p>


    <?php if (!$user_populi_id) : ?>
        <div class="notice notice-warning">
            <p>Your account is not yet linked with a Populi ID. Attendance records cannot be displayed. Please contact an administrator.</p>
        </div>
    <?php else : ?>
        <div class="pbattend-user-info">
            <h2>Welcome, <?php echo esc_html($user->display_name); ?></h2>
            <p><strong>Email Address:</strong> <?php echo esc_html($user->user_email); ?></p>
            <p><strong>Visible Student ID:</strong> <?php echo esc_html($student_visible_id); ?></p>
            <p><strong>Student ID:</strong> <?php echo esc_html($user_populi_id); ?></p>
            <a href="<?php echo esc_url(PBAttend_Populi_Importer::get_student_sync_url()); ?>" class="button">Sync attendance from Populi</a>
        </div>
        <?php
        if (isset($_GET['pbattend_synced']) && $_GET['pbattend_synced'] === '1') {
            echo '<div class="notice notice-success"><p>Attendance synced from Populi.</p></div>';
        }
        if (isset($_GET['pbattend_sync_error']) && $_GET['pbattend_sync_error'] === '1') {
            echo '<div class="notice notice-error"><p>Sync failed. Please try again or contact support.</p></div>';
        }
        ?>

        <div class="pbattend-filters">
            <form method="get">
                <select name="status" class="status">
                    <option value="all" <?php selected($status, 'all'); ?>>All Records</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                    <option value="waiting" <?php selected($status, 'waiting'); ?>>Waiting for Review</option>
                    <option value="approved" <?php selected($status, 'approved'); ?>>Excused</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>Rejected</option>
                </select>
                <input type="submit" value="Filter">
            </form>
        </div>

        <div class="pbattend-table-wrap">
        <table class="wp-list-table widefat fixed striped pbattend-dashboard-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Course</th>
                    <th>Status</th>
                    <th>Review Status</th>
                    <th width="40%">Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($query->have_posts()) : ?>
                    <?php while ($query->have_posts()) : $query->the_post(); ?>
                        <?php
                        $record_id = get_the_ID();


                        $field = get_field_object('field_review_status');
                        $value = get_field('field_review_status');
                        $label = $field['choices'][$value];

                        $review_status = $label;
                        $can_edit = in_array($review_status, array('pending', ''));
                        $current_notes = get_field('attendance_note', $record_id);
                        $rejection_reason = get_field('rejection_reason', $record_id);
                        ?>
                        <tr>
                            <td data-label="Date"><?php
                                $meeting_time = get_field('attendance_details_meeting_start_time');
                                if ($meeting_time) {
                                    echo date('M d Y, g:iA', strtotime($meeting_time));
                                }
                            ?></td>
                            <td data-label="Course"><?php echo get_field('course_info_course_name'); ?></td>
                            <td data-label="Status"><?php echo get_field('attendance_details_attendance_status'); ?></td>
                            <td data-label="Review Status"><?php echo $review_status ?: 'Pending'; ?></td>
                            <td data-label="Notes">
                                <div class="pbattend-notes-display">
                                    <?php echo esc_html($current_notes); ?>
                                </div>
                                <?php if (!empty($rejection_reason)) : ?>
                                    <div class="pbattend-rejection-reason">
                                        <strong>Rejection Reason:</strong> <?php echo esc_html(wp_strip_all_tags($rejection_reason)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <?php if ($review_status == 'Pending' && empty($current_notes)) : ?>
                                    <a href="<?php echo esc_url(add_query_arg('record_id', $record_id, get_permalink(get_page_by_path('attendance-editor')))); ?>">
                                        Edit Notes
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6">No attendance records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="tablenav bottom">
        <?php
        echo paginate_links(array(
            'base' => add_query_arg('page_num', '%#%'),
            'format' => '',
            'current' => $page,
            'total' => $query->max_num_pages,
            'add_args' => array(
                'status' => $status
            ),
            'prev_next' => true,
            'prev_text' => '&laquo; Previous',
            'next_text' => 'Next &raquo;',
            'show_all' => false,
            'end_size' => 1,
            'mid_size' => 2
        ));
        ?>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?> 