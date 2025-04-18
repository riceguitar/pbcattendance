<?php
/**
 * Handles admin settings for the plugin
 */
class PBAttend_Admin_Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=pbattend_record',
            __('PB Attend Settings', 'pbattend'),
            __('Settings', 'pbattend'),
            'manage_options',
            'pbattend-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('pbattend_settings', 'pbattend_populi_api_key');
        register_setting('pbattend_settings', 'pbattend_populi_api_base');

        add_settings_section(
            'pbattend_populi_settings',
            __('Populi API Settings', 'pbattend'),
            array($this, 'render_section_info'),
            'pbattend-settings'
        );

        add_settings_field(
            'pbattend_populi_api_key',
            __('API Key', 'pbattend'),
            array($this, 'render_api_key_field'),
            'pbattend-settings',
            'pbattend_populi_settings'
        );

        add_settings_field(
            'pbattend_populi_api_base',
            __('API Base URL', 'pbattend'),
            array($this, 'render_api_base_field'),
            'pbattend-settings',
            'pbattend_populi_settings'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('pbattend_settings');
                do_settings_sections('pbattend-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php _e('Manual Import', 'pbattend'); ?></h2>
            <p><?php _e('Click the button below to manually import attendance records from Populi.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_manual_import', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_manual_import">
                <?php submit_button(__('Import Now', 'pbattend'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('Reset Importer', 'pbattend'); ?></h2>
            <p><?php _e('Use this button to reset the importer state. This will clear all tracking of previously imported records, allowing you to reimport everything.', 'pbattend'); ?></p>
            <p class="description"><?php _e('Warning: This will not delete any existing records, but will allow them to be imported again.', 'pbattend'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('pbattend_manual_import', 'pbattend_nonce'); ?>
                <input type="hidden" name="action" value="pbattend_manual_import">
                <input type="hidden" name="reset" value="1">
                <?php submit_button(__('Reset Importer', 'pbattend'), 'secondary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('Import Log', 'pbattend'); ?></h2>
            <?php $this->render_import_log(); ?>
        </div>
        <?php
    }

    /**
     * Render section info
     */
    public function render_section_info() {
        echo '<p>' . __('Configure your Populi API settings below.', 'pbattend') . '</p>';
    }

    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $value = get_option('pbattend_populi_api_key');
        echo '<input type="password" id="pbattend_populi_api_key" name="pbattend_populi_api_key" value="' . esc_attr($value) . '" class="regular-text">';
    }

    /**
     * Render API base URL field
     */
    public function render_api_base_field() {
        $value = get_option('pbattend_populi_api_base', 'https://pbc.populiweb.com/api2');
        echo '<input type="url" id="pbattend_populi_api_base" name="pbattend_populi_api_base" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('Base URL for the Populi API (e.g., https://pbc.populiweb.com/api2)', 'pbattend') . '</p>';
    }

    /**
     * Render import log
     */
    private function render_import_log() {
        $log = get_option('pbattend_import_log', array());
        if (empty($log)) {
            echo '<p>' . __('No import activity logged yet.', 'pbattend') . '</p>';
            return;
        }

        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'pbattend') . '</th>';
        echo '<th>' . __('Type', 'pbattend') . '</th>';
        echo '<th>' . __('Message', 'pbattend') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach (array_reverse($log) as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html($entry['timestamp']) . '</td>';
            echo '<td>' . esc_html($entry['type']) . '</td>';
            echo '<td>' . esc_html($entry['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
} 