<?php

/**
 * Export functionality for Database Sync plugin
 */

// No external dependencies needed - using WordPress native methods

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

        // Create descriptive filename with timestamp
        $timestamp = date('ymd-His'); // YYMMDD-HHMMSS format
        $preset_name = $this->get_preset_display_name($preset);
        $environment = $this->get_environment_name();
        $filename = $timestamp . '-' . strtolower(str_replace(' ', '-', $preset_name)) . '-' . strtolower($environment) . '.sql';

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
     * Generate SQL content using WordPress native methods
     */
    private function generate_sql($tables) {
        global $wpdb;

        // Add our custom header
        $sql_content = "-- WordPress Database Export\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- Source URL: " . get_option('siteurl') . "\n";
        $sql_content .= "-- Exported tables: " . implode(', ', $tables) . "\n\n";

        // Get the actual table names for the selected tables
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

            // Export table data
            $sql_content .= $this->export_table_data($table_name, $table_display);
        }

        return $sql_content;
    }

    /**
     * Export table data using WordPress native methods
     */
    private function export_table_data($table_name, $table_display) {
        global $wpdb;

        $sql_content = "-- Data for $table_display\n";

        // Get all rows from the table
        $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);

        if (empty($rows)) {
            $sql_content .= "-- No data found\n\n";
            return $sql_content;
        }

        // Get column names
        $columns = array_keys($rows[0]);
        $column_list = '`' . implode('`, `', $columns) . '`';

        // Build INSERT statements
        foreach ($rows as $row) {
            $values = array();
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    // Properly escape the value
                    $escaped_value = $wpdb->_real_escape($value);
                    $values[] = "'$escaped_value'";
                }
            }

            $sql_content .= "INSERT INTO `$table_name` ($column_list) VALUES (" . implode(', ', $values) . ");\n";
        }

        $sql_content .= "\n";
        return $sql_content;
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
