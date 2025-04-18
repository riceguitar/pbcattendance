<?php
class PBAttend_ACF_Fields {
    public function __construct() {
        add_action('acf/init', array($this, 'register_attendance_fields'));
    }

    public function register_attendance_fields() {
        if (function_exists('acf_add_local_field_group')):

        acf_add_local_field_group(array(
            'key' => 'group_pbattend_record',
            'title' => 'Attendance Record Details',
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
                        array(
                            'key' => 'field_attendance_note',
                            'label' => 'Notes',
                            'name' => 'attendance_note',
                            'type' => 'textarea',
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

        endif;
    }
} 