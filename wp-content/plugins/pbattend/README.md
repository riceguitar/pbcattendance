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
- Student information fields with POPULI integration
- Course information fields
- Attendance details (status, time, notes)
- Meta information tracking
- **NEW: Automatic user sync with POPULI via email lookup**
- **NEW: SSO login integration for seamless user matching**
- **NEW: Bulk sync tool for existing users**

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

## POPULI Integration

This plugin now includes seamless integration with POPULI for user management:

### Automatic User Sync
- Users who log in via SSO are automatically synced with POPULI
- Email addresses are used to match WordPress users with POPULI records
- Student IDs and other data are populated automatically

### Admin Tools
- **Bulk Sync**: Sync all existing users with POPULI data
- **Sync Status Dashboard**: Monitor sync status for all users
- **Import Log**: Track all sync and import activities

### How It Works
1. Student logs in via miniOrange SAML SSO
2. Plugin checks if user has POPULI student_id
3. If not, queries POPULI API using email address
4. Populates WordPress user with POPULI data
5. Attendance records are now properly matched

## Future Features

- Enhanced error handling and retry mechanisms
- Scheduled sync for data updates
- Custom taxonomies for better organization 