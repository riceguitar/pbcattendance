---
name: Update Populi Attendance on Excused Status
overview: Add functionality to automatically update attendance status in Populi to "EXCUSED" when a WordPress attendance record's review_status is changed to "excused". This requires fetching the enrollment_id from Populi's courseofferings/{id}/students endpoint and then calling update_attendance.
todos:
  - id: add-api-endpoints
    content: Add course_offering_students and update_attendance endpoints to $endpoints array in class-populi-importer.php
    status: completed
  - id: add-helper-methods
    content: "Create helper methods: get_course_offering_students() using GET, find_enrollment_id_by_student_id(), and update_populi_attendance()"
    status: completed
    dependencies:
      - add-api-endpoints
  - id: add-sync-method
    content: Create main sync_attendance_to_populi() method that orchestrates the API calls
    status: completed
    dependencies:
      - add-helper-methods
  - id: hook-post-types
    content: Hook sync into save_review_data() method in class-post-types.php when status is 'excused'
    status: completed
    dependencies:
      - add-sync-method
  - id: hook-notifications
    content: Hook sync into check_review_status_change() and handle_acp_save() methods in class-notifications.php when status changes to 'excused'
    status: completed
    dependencies:
      - add-sync-method
isProject: false
---

# Update Populi Attendance When Review Status Changes to Excused

## Overview

When an attendance record's `review_status` is changed to "excused", automatically update the corresponding attendance record in Populi by:

1. Getting `course_offering_id` from the attendance record (stored in `course_info_course_id` field)
2. Getting `Student_ID` (populi_id) from the attendance record
3. Calling Populi's `GET /courseofferings/{courseoffering}/students` API to get enrollments
4. Matching the Student_ID to find the `enrollment_id`
5. Calling Populi's `PUT /courseoffering/{courseoffering}/student/{enrollment}/attendance/update` API with `course_offering_id`, `enrollment_id`, and status "excused"

## Implementation Details

### 1. Add API Endpoints to `class-populi-importer.php`

- Add `'course_offering_students' => '/courseofferings/%d/students'` and `'update_attendance' => '/courseoffering/%d/student/%d/attendance/update'` to the `$endpoints` array
- The update_attendance endpoint uses PUT method with course_offering_id and enrollment_id as path parameters
- These endpoints will be used for fetching enrollments and updating attendance
- Reference: [https://populi.co/api/#update_attendance](https://populi.co/api/#update_attendance)

### 2. Create Helper Methods in `class-populi-importer.php`

- `**get_course_offering_students($course_offering_id)**`: Calls `GET /courseofferings/{courseoffering}/students` API endpoint using `wp_remote_get()`, returns array of student enrollments
- `**find_enrollment_id_by_student_id($students, $student_id)**`: Loops through students array, matches `student_id` or `person_id` field to the provided `student_id`, returns `enrollment_id`
- `**update_populi_attendance($course_offering_id, $enrollment_id, $status)**`: Calls `PUT /courseoffering/{courseoffering}/student/{enrollment}/attendance/update` API endpoint using `wp_remote_request()` with PUT method. The endpoint uses course_offering_id and enrollment_id as path parameters (via sprintf with endpoint template). Request body contains JSON with `status` (set to "excused")

### 3. Create Main Update Method

- `**sync_attendance_to_populi($post_id)**`: Main method that:
- Gets `course_offering_id` from `course_info_course_id` field
- Gets `populi_id` (Student_ID) from the attendance record
- Validates both IDs are present, logs error if missing
- Calls `get_course_offering_students()` to fetch enrollments
- Calls `find_enrollment_id_by_student_id()` to get enrollment_id
- If enrollment_id found, calls `update_populi_attendance()` with course_offering_id, enrollment_id, and status "excused" (lowercase)
- Handles errors and logs results using existing `log_import()` method

### 4. Hook into Review Status Changes

- Modify `class-post-types.php` in `save_review_data()` method (around line 159)
- When `review_status` is set to "excused", call `sync_attendance_to_populi($post_id)`
- Also hook into `class-notifications.php` `check_review_status_change()` method (around line 58) to handle ACF form saves
- Also hook into `handle_acp_save()` method (around line 39) to handle Admin Columns Pro inline edits

### 5. Error Handling & Logging

- Add comprehensive error handling for API failures
- Log all API calls and responses using existing `log_import()` method
- Handle cases where enrollment_id is not found (log warning)
- Handle cases where course_offering_id or populi_id is missing (log error)
- Handle HTTP errors from API calls

## Files to Modify

1. **[wp-content/plugins/pbattend/includes/class-populi-importer.php](wp-content/plugins/pbattend/includes/class-populi-importer.php)**

- Add new endpoints to `$endpoints` array (line ~10-15)
- Add helper methods for API calls (after line ~464)
- Add main `sync_attendance_to_populi()` method (after helper methods)

1. **[wp-content/plugins/pbattend/includes/class-post-types.php](wp-content/plugins/pbattend/includes/class-post-types.php)**

- Modify `save_review_data()` to trigger sync when status is "excused" (around line 159)

1. **[wp-content/plugins/pbattend/includes/class-notifications.php](wp-content/plugins/pbattend/includes/class-notifications.php)**

- Modify `check_review_status_change()` to trigger sync when status changes to "excused" (around line 58)
- Modify `handle_acp_save()` to trigger sync when status changes to "excused" (around line 39)

## API Flow

```javascript
Review Status → "excused"
    ↓
Get course_offering_id from course_info_course_id field
    ↓
Get populi_id (Student_ID) from record
    ↓
Call GET /courseofferings/{courseoffering}/students API
    ↓
Loop students → Match student_id/person_id → Get enrollment_id
    ↓
Call PUT /courseoffering/{courseoffering}/student/{enrollment}/attendance/update API
- Path params: course_offering_id, enrollment_id
- Body: status="excused"
    ↓
Log result
```

## Notes

- The existing code uses `wp_remote_post()` and `wp_remote_get()` for API calls; for PUT requests use `wp_remote_request()` with 'method' => 'PUT'
- API authentication uses Bearer token in Authorization header (already implemented)
- Use `GET` method for `/courseofferings/{id}/students` endpoint

