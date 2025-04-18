# PB Attend

A WordPress plugin for managing attendance records with notes and approval workflow.

## Description

PB Attend creates a custom post type for storing attendance records, with fields managed by Advanced Custom Fields (ACF). Each attendance record contains information about the student, course, and attendance details.

## Requirements

- WordPress 5.0 or higher
- Advanced Custom Fields Pro (ACF Pro) plugin
- PHP 7.0 or higher

## Installation

1. Upload the `pbattend` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure Advanced Custom Fields Pro is installed and activated

## Features

- Custom post type for attendance records
- Student information fields
- Course information fields
- Attendance details (status, time, notes)
- Meta information tracking

## Usage

1. Navigate to the "Attendance" menu in the WordPress admin
2. Click "Add New" to create a new attendance record
3. Fill in the required fields:
   - Student ID
   - First Name
   - Last Name
   - Course Information
   - Attendance Details
4. Save the record

## Future Features

- JSON import functionality
- Approval workflow states
- Custom capabilities for different user roles
- Custom taxonomies for better organization 