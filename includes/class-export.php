<?php

/**
 * Export functionality for Database Sync plugin
 */

class DatabaseSync_Export {

    /**
     * Handle export AJAX request
     */
    public function handle_export() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $preset = sanitize_text_field($_POST['preset']);
        $tables = isset($_POST['tables']) ? array_map('sanitize_text_field', $_POST['tables']) : array();

        // Get tables based on preset
        $tables_to_export = $this->get_tables_for_preset($preset, $tables);

        // Save settings
        update_option('db_sync_preset', $preset);
        update_option('db_sync_tables', $tables_to_export);

        // Generate SQL file
        $sql_content = $this->generate_sql($tables_to_export);

        // Create descriptive filename
        $date = date('ymd'); // YYMMDD format
        $preset_name = $this->get_preset_display_name($preset);
        $environment = $this->get_environment_name();
        $filename = $date . '-' . strtolower(str_replace(' ', '-', $preset_name)) . '-' . strtolower($environment) . '.sql';

        // Ensure uploads directory exists
        $upload_dir = wp_upload_dir();
        $db_sync_dir = $upload_dir['basedir'] . '/db-sync/';
        if (!is_dir($db_sync_dir)) {
            wp_mkdir_p($db_sync_dir);
        }

        // Save file
        $file_path = $db_sync_dir . $filename;
        $result = file_put_contents($file_path, $sql_content);

        if ($result === false) {
            wp_send_json_error('Failed to save SQL file');
        }

        // Return success with file info
        wp_send_json_success(array(
            'filename' => $filename,
            'file_path' => $file_path,
            'file_size' => size_format(strlen($sql_content)),
            'preset' => $preset_name,
            'environment' => $environment
        ));
    }

    /**
     * Get tables for preset
     */
    private function get_tables_for_preset($preset, $custom_tables = array()) {
        $presets = DatabaseSync::get_presets();

        if ($preset === 'custom') {
            return $custom_tables;
        }

        if (isset($presets[$preset])) {
            return $presets[$preset]['tables'];
        }

        return $presets['development']['tables'];
    }

    /**
     * Generate SQL content
     */
    private function generate_sql($tables) {
        global $wpdb;

        $sql_content = "-- WordPress Database Export\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- Source URL: " . get_option('siteurl') . "\n\n";

        $available_tables = DatabaseSync::get_available_tables();

        foreach ($tables as $table_display) {
            if (!isset($available_tables[$table_display])) {
                continue;
            }

            $table_name = $available_tables[$table_display];

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
            if (!$create_table) {
                continue;
            }

            $sql_content .= "\n-- Table structure for $table_display\n";
            $sql_content .= "DROP TABLE IF EXISTS `$table_name`;\n";
            $sql_content .= $create_table[1] . ";\n\n";

            // Get table data
            $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
            if (!empty($rows)) {
                $sql_content .= "-- Data for $table_display\n";

                // Handle options table specially
                if ($table_display === 'options') {
                    $rows = $this->filter_options_table($rows);
                }

                foreach ($rows as $row) {
                    $values = array_map(array($wpdb, '_real_escape'), $row);
                    $sql_content .= "INSERT INTO `$table_name` VALUES ('" . implode("','", $values) . "');\n";
                }
            }
        }

        return $sql_content;
    }

    /**
     * Filter options table to exclude siteurl, home, and transients
     */
    private function filter_options_table($rows) {
        $filtered_rows = array();

        foreach ($rows as $row) {
            $option_name = $row['option_name'];

            // Skip siteurl and home
            if (in_array($option_name, array('siteurl', 'home'))) {
                continue;
            }

            // Skip transients
            if (strpos($option_name, '_transient_') === 0 || strpos($option_name, '_site_transient_') === 0) {
                continue;
            }

            $filtered_rows[] = $row;
        }

        return $filtered_rows;
    }

    /**
     * Get preset display name
     */
    private function get_preset_display_name($preset) {
        $presets = DatabaseSync::get_presets();

        if (isset($presets[$preset])) {
            return $presets[$preset]['name'];
        }

        return 'Custom';
    }

    /**
     * Get environment name
     */
    private function get_environment_name() {
        $site_url = get_option('siteurl');

        if (strpos($site_url, 'localhost') !== false || strpos($site_url, '.local') !== false) {
            return 'Local';
        }

        return 'Remote';
    }
}
