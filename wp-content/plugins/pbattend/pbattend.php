<?php
/**
 * Plugin Name: PB Attend
 * Plugin URI: 
 * Description: A plugin for adding notes and approval workflow to attendance records.
 * Version: 1.0.0
 * Author: 
 * Text Domain: pbattend
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('PBATTEND_VERSION', '1.0.0');
define('PBATTEND_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PBATTEND_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if ACF is active
if (!class_exists('ACF')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('PB Attend requires Advanced Custom Fields to be installed and activated.', 'pbattend'); ?></p>
        </div>
        <?php
    });
    return;
}

// Include required files
require_once PBATTEND_PLUGIN_DIR . 'includes/class-post-types.php';
require_once PBATTEND_PLUGIN_DIR . 'includes/class-acf-fields.php';
require_once PBATTEND_PLUGIN_DIR . 'includes/class-populi-importer.php';
require_once PBATTEND_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once PBATTEND_PLUGIN_DIR . 'includes/class-notifications.php';
require_once PBATTEND_PLUGIN_DIR . 'includes/class-frontend-controller.php';

// Initialize the plugin
function pbattend_init() {
    // Initialize post types
    new PBAttend_Post_Types();
    
    // Initialize ACF fields
    if (function_exists('acf_add_local_field_group')) {
        new PBAttend_ACF_Fields();
    }

    // Initialize Populi importer
    new PBAttend_Populi_Importer();

    // Initialize admin settings
    if (is_admin()) {
        new PBAttend_Admin_Settings();
    }

    // Initialize notifications
    new PBAttend_Notifications();

    // Initialize frontend controller
    new \PBAttend\Frontend_Controller();
}
add_action('plugins_loaded', 'pbattend_init');

// Register plugin page templates
function pbattend_register_page_templates($templates) {
    $templates['page-templates/attendance-dashboard.php'] = 'My Attendance Dashboard';
    $templates['page-templates/attendance-editor.php'] = 'Attendance Editor';
    return $templates;
}
add_filter('theme_page_templates', 'pbattend_register_page_templates');

// Load plugin page templates
function pbattend_load_page_template($template) {
    global $post;
    
    if (!$post) {
        return $template;
    }
    
    $page_template = get_post_meta($post->ID, '_wp_page_template', true);
    
    if ($page_template === 'page-templates/attendance-dashboard.php' || 
        $page_template === 'page-templates/attendance-editor.php') {
        $new_template = PBATTEND_PLUGIN_DIR . $page_template;
        
        // Debug information for administrators
        if (current_user_can('administrator')) {
            error_log('PB Attend: Attempting to load template from: ' . $new_template);
            error_log('PB Attend: Template exists: ' . (file_exists($new_template) ? 'Yes' : 'No'));
        }
        
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    
    return $template;
}
add_filter('page_template', 'pbattend_load_page_template');

// Flush rewrite rules on activation
function pbattend_activate() {
    pbattend_init();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'pbattend_activate');

// Deactivation hook
register_deactivation_hook(__FILE__, 'pbattend_deactivate');
function pbattend_deactivate() {
    // Clean up if necessary
    flush_rewrite_rules();
    
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('pbattend_import_cron');
} 