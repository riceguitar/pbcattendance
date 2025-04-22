<?php
/**
 * Handles ACF field registration
 */
class PBAttend_ACF_Fields {
    public function __construct() {
        add_action('acf/init', array($this, 'register_fields'));
        add_action('admin_init', array($this, 'migrate_notes_data'));
    }

    public function register_fields() {
        // Register attendance record fields
        acf_add_local_field_group(array(
            'key' => 'group_attendance_record',
            'title' => 'Attendance Record',
            'fields' => array(
                array(
                    'key' => 'field_student_id',
                    'label' => 'Student ID',
                    'name' => 'student_id',
                    'type' => 'text',
                    'required' => 1,
                ),
                array(
                    'key' => 'field_first_name',
                    'label' => 'First Name',
                    'name' => 'first_name',
                    'type' => 'text',
                    'required' => 1,
                ),
                array(
                    'key' => 'field_last_name',
                    'label' => 'Last Name',
                    'name' => 'last_name',
                    'type' => 'text',
                    'required' => 1,
                ),
                array(
                    'key' => 'field_review_status',
                    'label' => 'Review Status',
                    'name' => 'review_status',
                    'type' => 'select',
                    'choices' => array(
                        'pending' => 'Pending',
                        'review' => 'In Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected'
                    ),
                    'default_value' => 'pending',
                    'required' => 1,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => ''
                    ),
                ),
                array(
                    'key' => 'field_course_info',
                    'label' => 'Course Information',
                    'name' => 'course_info',
                    'type' => 'group',
                    'layout' => 'block',
                    'sub_fields' => array(
                        array(
                            'key' => 'field_course_id',
                            'label' => 'Course ID',
                            'name' => 'course_id',
                            'type' => 'text',
                        ),
                        array(
                            'key' => 'field_course_name',
                            'label' => 'Course Name',
                            'name' => 'course_name',
                            'type' => 'text',
                        ),
                        array(
                            'key' => 'field_term_name',
                            'label' => 'Term',
                            'name' => 'term_name',
                            'type' => 'text',
                        ),
                    ),
                ),
                array(
                    'key' => 'field_attendance_details',
                    'label' => 'Attendance Details',
                    'name' => 'attendance_details',
                    'type' => 'group',
                    'layout' => 'block',
                    'sub_fields' => array(
                        array(
                            'key' => 'field_meeting_start_time',
                            'label' => 'Start Time',
                            'name' => 'meeting_start_time',
                            'type' => 'date_time_picker',
                            'required' => 1,
                        ),
                        array(
                            'key' => 'field_meeting_end_time',
                            'label' => 'End Time',
                            'name' => 'meeting_end_time',
                            'type' => 'date_time_picker',
                            'required' => 1,
                        ),
                        array(
                            'key' => 'field_attendance_status',
                            'label' => 'Status',
                            'name' => 'attendance_status',
                            'type' => 'select',
                            'choices' => array(
                                'PRESENT' => 'Present',
                                'ABSENT' => 'Absent',
                                'TARDY' => 'Tardy',
                                'EXCUSED' => 'Excused',
                            ),
                            'required' => 1,
                        ),
                    ),
                ),
                array(
                    'key' => 'field_meta_info',
                    'label' => 'Meta Information',
                    'name' => 'meta_info',
                    'type' => 'group',
                    'layout' => 'block',
                    'sub_fields' => array(
                        array(
                            'key' => 'field_attendance_added_at',
                            'label' => 'Added At',
                            'name' => 'attendance_added_at',
                            'type' => 'date_time_picker',
                            'readonly' => 1,
                        ),
                        array(
                            'key' => 'field_attendance_added_by',
                            'label' => 'Added By',
                            'name' => 'attendance_added_by',
                            'type' => 'number',
                            'readonly' => 1,
                        ),
                    ),
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'pbattend_record',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
        ));

        // Register standalone attendance note field
        acf_add_local_field_group(array(
            'key' => 'group_attendance_note',
            'title' => 'Attendance Note',
            'fields' => array(
                array(
                    'key' => 'field_attendance_note',
                    'label' => 'Notes',
                    'name' => 'attendance_note',
                    'type' => 'textarea',
                    'required' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'pbattend_record',
                    ),
                ),
            ),
            'menu_order' => 1,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
        ));

        // Register user fields
        acf_add_local_field_group(array(
            'key' => 'group_student_info',
            'title' => 'Student Information',
            'fields' => array(
                array(
                    'key' => 'field_student_visible_id',
                    'label' => 'Visible Student ID',
                    'name' => 'student_visible_id',
                    'type' => 'text',
                    'instructions' => 'The student ID visible in Populi',
                    'required' => 1,
                    'wrapper' => array(
                        'width' => '50',
                    ),
                ),
                array(
                    'key' => 'field_student_id',
                    'label' => 'Student ID',
                    'name' => 'student_id',
                    'type' => 'text',
                    'instructions' => 'The internal student ID from Populi',
                    'required' => 1,
                    'wrapper' => array(
                        'width' => '50',
                    ),
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'user_form',
                        'operator' => '==',
                        'value' => 'all',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
        ));
    }

    /**
     * Migrate existing notes data to the new field structure
     */
    public function migrate_notes_data() {
        // Only run once
        if (get_option('pbattend_notes_migrated')) {
            return;
        }

        // Get all attendance records
        $args = array(
            'post_type' => 'pbattend_record',
            'posts_per_page' => -1,
            'post_status' => 'any'
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // Get old notes data
                $old_notes = get_field('attendance_details_attendance_note', $post_id);
                
                if ($old_notes) {
                    // Update with new field
                    update_field('attendance_note', $old_notes, $post_id);
                }
            }
            wp_reset_postdata();
        }

        // Mark migration as complete
        update_option('pbattend_notes_migrated', true);
    }
} 