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

$controller = new PBAttend_Frontend_Controller();
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$per_page = 10;

$user_id = get_current_user_id();
$user_student_id = get_field('student_id', 'user_' . $user_id);

// Debug information
if (current_user_can('administrator')) {
    echo '<div class="notice notice-info">';
    echo '<p>Debug Information:</p>';
    echo '<ul>';
    echo '<li>User ID: ' . $user_id . '</li>';
    echo '<li>Student ID: ' . ($user_student_id ? $user_student_id : 'Not set') . '</li>';
    echo '</ul>';
    echo '</div>';
}

$query = $controller->get_user_records($status, $page, $per_page);

get_header();
?>

<div class="wrap">
    <h1><?php the_title(); ?></h1>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success">
            <p>Notes updated successfully!</p>
        </div>
    <?php endif; ?>

    <?php if (!$user_student_id) : ?>
        <div class="notice notice-warning">
            <p>Your student ID is not set. Please contact the administrator.</p>
        </div>
    <?php else : ?>
        <div class="pbattend-filters">
            <form method="get">
                <select name="status">
                    <option value="all" <?php selected($status, 'all'); ?>>All Records</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                    <option value="waiting" <?php selected($status, 'waiting'); ?>>Waiting for Review</option>
                    <option value="approved" <?php selected($status, 'approved'); ?>>Approved</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>Rejected</option>
                </select>
                <input type="submit" value="Filter">
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Course</th>
                    <th>Status</th>
                    <th>Review Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($query->have_posts()) : ?>
                    <?php while ($query->have_posts()) : $query->the_post(); ?>
                        <?php
                        $record_id = get_the_ID();
                        $can_edit = $controller->can_edit_record($record_id);
                        ?>
                        <tr>
                            <td><?php echo get_field('attendance_details_meeting_start_time'); ?></td>
                            <td><?php echo get_field('course_info_course_name'); ?></td>
                            <td><?php echo get_field('attendance_details_attendance_status'); ?></td>
                            <td><?php echo get_field('review_status'); ?></td>
                            <td>
                                <?php if ($can_edit) : ?>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                        <?php wp_nonce_field('pbattend_update_notes', 'pbattend_nonce'); ?>
                                        <input type="hidden" name="action" value="pbattend_update_notes">
                                        <input type="hidden" name="record_id" value="<?php echo $record_id; ?>">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(get_permalink()); ?>">
                                        <textarea name="notes" rows="2" cols="30"><?php echo get_field('attendance_details_attendance_note'); ?></textarea>
                                        <input type="submit" value="Update Notes">
                                    </form>
                                <?php else : ?>
                                    <?php echo get_field('attendance_details_attendance_note'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can_edit) : ?>
                                    <a href="<?php echo get_edit_post_link($record_id); ?>" class="button">Edit</a>
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

        <?php
        echo paginate_links(array(
            'total' => $query->max_num_pages,
            'current' => $page,
            'add_args' => array('status' => $status)
        ));
        ?>
    <?php endif; ?>
</div>

<?php get_footer(); ?> 