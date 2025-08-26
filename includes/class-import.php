<?php

/**
 * Import functionality for Database Sync plugin
 */

// No external dependencies needed - using WordPress native methods

class DatabaseSync_Import {

    /**
     * Handle import AJAX request
     */
    public function handle_import() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $filename = sanitize_text_field($_POST['filename']);

        // Get file path
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/db-sync/' . $filename;

        if (!file_exists($file_path)) {
            wp_send_json_error('SQL file not found');
        }

        $sql_content = file_get_contents($file_path);

        if (!$sql_content) {
            wp_send_json_error('Could not read SQL file');
        }

        // Create backup before import
        $backup_result = $this->create_backup($filename);
        if (is_wp_error($backup_result)) {
            wp_send_json_error('Backup failed: ' . $backup_result->get_error_message());
        }

        // Process and import
        $result = $this->import_sql($sql_content);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Add backup info to result
        $result['backup_file'] = $backup_result;

        wp_send_json_success($result);
    }

    /**
     * Handle delete AJAX request
     */
    public function handle_delete() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $filename = sanitize_text_field($_POST['filename']);

        // Get file path
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/db-sync/' . $filename;

        if (!file_exists($file_path)) {
            wp_send_json_error('File not found');
        }

        // Delete the file
        $result = unlink($file_path);

        if ($result === false) {
            wp_send_json_error('Failed to delete file');
        }

        wp_send_json_success(array(
            'filename' => $filename,
            'message' => 'File deleted successfully'
        ));
    }

    /**
     * Handle restore AJAX request
     */
    public function handle_restore() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $backup_filename = sanitize_text_field($_POST['backup_filename']);

        // Get backup file path
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/db-sync/' . $backup_filename;

        if (!file_exists($backup_path)) {
            wp_send_json_error('Backup file not found');
        }

        $sql_content = file_get_contents($backup_path);

        if (!$sql_content) {
            wp_send_json_error('Could not read backup file');
        }

        // Process and restore
        $result = $this->import_sql($sql_content);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Handle preview AJAX request
     */
    public function handle_preview() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $filename = sanitize_text_field($_POST['filename']);

        // Get file path
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/db-sync/' . $filename;

        if (!file_exists($file_path)) {
            wp_send_json_error('SQL file not found');
        }

        $sql_content = file_get_contents($file_path);

        if (!$sql_content) {
            wp_send_json_error('Could not read SQL file');
        }

        // Generate preview
        $preview = $this->generate_preview($sql_content);

        wp_send_json_success($preview);
    }

    /**
     * Get available SQL files
     */
    public static function get_available_files() {
        $upload_dir = wp_upload_dir();
        $db_sync_dir = $upload_dir['basedir'] . '/db-sync/';

        if (!is_dir($db_sync_dir)) {
            return array();
        }

        $files = glob($db_sync_dir . '*.sql');
        $file_list = array();

        foreach ($files as $file) {
            $filename = basename($file);
            $file_info = self::parse_filename($filename);

            $file_list[] = array(
                'filename' => $filename,
                'file_path' => $file,
                'file_size' => size_format(filesize($file)),
                'modified' => filemtime($file),
                'preset' => $file_info['preset'],
                'environment' => $file_info['environment'],
                'date' => $file_info['date'],
                'is_backup' => $file_info['is_backup']
            );

            error_log('*** DB Sync: Added file to list: ' . $filename . ' (is_backup: ' . ($file_info['is_backup'] ? 'true' : 'false') . ')');
        }

        // Sort by modification time (newest first)
        usort($file_list, function ($a, $b) {
            return $b['modified'] - $a['modified'];
        });

        return $file_list;
    }

    /**
     * Parse filename to extract information
     */
    public static function parse_filename($filename) {
        // Format: 250825-143022-development-local.sql or 250825-143022-development-local-BAK.sql
        if (preg_match('/^(\d{6})-(\d{6})-([^-]+)-([^-]+)(-BAK)?\.sql$/', $filename, $matches)) {
            $is_backup = !empty($matches[5]);
            $date = $matches[1];
            $time = $matches[2];
            $timestamp = $date . '-' . $time;
            error_log('*** DB Sync: Parsed filename "' . $filename . '" - is_backup: ' . ($is_backup ? 'true' : 'false') . ', timestamp: ' . $timestamp);
            return array(
                'date' => $date,
                'time' => $time,
                'timestamp' => $timestamp,
                'preset' => ucfirst(str_replace('-', ' ', $matches[3])),
                'environment' => ucfirst($matches[4]),
                'is_backup' => $is_backup
            );
        }

        // Fallback for old format: 250825-development-local.sql or 250825-development-local-BAK.sql
        if (preg_match('/^(\d{6})-([^-]+)-([^-]+)(-BAK)?\.sql$/', $filename, $matches)) {
            $is_backup = !empty($matches[4]);
            error_log('*** DB Sync: Parsed filename (old format) "' . $filename . '" - is_backup: ' . ($is_backup ? 'true' : 'false'));
            return array(
                'date' => $matches[1],
                'time' => '000000',
                'timestamp' => $matches[1] . '-000000',
                'preset' => ucfirst(str_replace('-', ' ', $matches[2])),
                'environment' => ucfirst($matches[3]),
                'is_backup' => $is_backup
            );
        }

        error_log('*** DB Sync: Filename "' . $filename . '" did not match any expected pattern');
        return array(
            'date' => 'Unknown',
            'time' => 'Unknown',
            'timestamp' => 'Unknown',
            'preset' => 'Unknown',
            'environment' => 'Unknown',
            'is_backup' => false
        );
    }

    /**
     * Split SQL content into individual statements
     * Uses a more robust approach for WordPress content with serialized data
     */
    private function split_sql($sql_content) {
        // Remove single-line comments
        $sql_content = preg_replace('/--.*$/m', '', $sql_content);

        // Remove multi-line comments
        $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);

        // Use a more sophisticated approach to handle serialized data
        $statements = array();
        $current_statement = '';
        $in_string = false;
        $string_char = '';
        $escaped = false;
        $paren_depth = 0;

        for ($i = 0; $i < strlen($sql_content); $i++) {
            $char = $sql_content[$i];

            // Handle escaped characters
            if ($escaped) {
                $current_statement .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $current_statement .= $char;
                continue;
            }

            // Track parentheses depth (for function calls, etc.)
            if (!$in_string) {
                if ($char === '(') {
                    $paren_depth++;
                } elseif ($char === ')') {
                    $paren_depth--;
                }
            }

            // Handle string literals
            if (!$in_string && ($char === "'" || $char === '"')) {
                $in_string = true;
                $string_char = $char;
                $current_statement .= $char;
            } elseif ($in_string && $char === $string_char) {
                $in_string = false;
                $string_char = '';
                $current_statement .= $char;
            } else {
                $current_statement .= $char;
            }

            // End of statement (only if not in a string and parentheses are balanced)
            if (!$in_string && $paren_depth === 0 && $char === ';') {
                $statement = trim($current_statement);
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
                $current_statement = '';
            }
        }

        // Add any remaining statement
        $remaining = trim($current_statement);
        if (!empty($remaining)) {
            $statements[] = $remaining;
        }

        return array_filter($statements, function ($stmt) {
            return !empty(trim($stmt)) && !preg_match('/^\s*$/', $stmt);
        });
    }

    /**
     * Import SQL content into the database - execute all statements in order
     */
    private function import_sql($sql_content) {
        global $wpdb;

        $results = array(
            'tables_processed' => 0,
            'rows_imported' => 0,
            'errors' => array()
        );

        // Remove comments
        $sql_content = preg_replace('/--.*$/m', '', $sql_content);
        $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);

        // Split by semicolon and execute each statement in order
        $queries = explode(';', $sql_content);
        $queries = array_filter(array_map('trim', $queries));

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($queries as $query) {
                if (empty($query)) continue;

                $result = $wpdb->query($query);
                if ($result === false) {
                    throw new Exception('SQL Error: ' . $wpdb->last_error);
                }

                // Count for reporting
                if (preg_match('/^CREATE TABLE/i', $query)) {
                    $results['tables_processed']++;
                } elseif (preg_match('/^INSERT INTO/i', $query)) {
                    $results['rows_imported']++;
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');
            error_log("*** DB Sync: Import completed - Tables: " . $results['tables_processed'] . ", Rows: " . $results['rows_imported']);
        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            error_log("*** DB Sync: Import failed - " . $e->getMessage());
            return new WP_Error('import_failed', $e->getMessage());
        }

        return $results;
    }

    /**
     * Generate preview of import
     */
    private function generate_preview($sql_content) {
        // Get target URL
        $target_url = get_option('siteurl');

        // Use split_sql to get accurate counts
        $statements = $this->split_sql($sql_content);
        $tables = array();
        $total_rows = 0;

        foreach ($statements as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches)) {
                $table_name = $matches[1];
                $tables[$table_name] = 0;
            } elseif (preg_match('/^INSERT\s+INTO\s+`([^`]+)`/i', $statement, $matches)) {
                $table_name = $matches[1];
                if (isset($tables[$table_name])) {
                    $tables[$table_name]++;
                } else {
                    $tables[$table_name] = 1;
                }
                $total_rows++;
            }
        }

        return array(
            'target_url' => $target_url,
            'tables' => $tables,
            'total_rows' => $total_rows
        );
    }





    /**
     * Create backup of existing tables before import
     */
    private function create_backup($import_filename) {
        global $wpdb;

        // Parse import filename to get info
        $file_info = self::parse_filename($import_filename);

        // Create backup filename - simple -BAK format
        $backup_filename = str_replace('.sql', '-BAK.sql', $import_filename);
        error_log('*** DB Sync: Creating backup filename: ' . $backup_filename);

        // Get upload directory
        $upload_dir = wp_upload_dir();
        $db_sync_dir = $upload_dir['basedir'] . '/db-sync/';
        $backup_path = $db_sync_dir . $backup_filename;

        // Get tables that will be imported (from SQL content)
        $sql_content = file_get_contents($db_sync_dir . $import_filename);
        $tables_to_backup = $this->extract_tables_from_sql($sql_content);

        if (empty($tables_to_backup)) {
            return new WP_Error('no_tables', 'No tables found in import file');
        }

        // Generate backup SQL using WordPress native methods
        error_log('*** DB Sync: Creating backup using WordPress native methods for tables: ' . implode(', ', $tables_to_backup));

        $backup_sql = "-- WordPress Database Backup\n";
        $backup_sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $backup_sql .= "-- Backup before import: " . $import_filename . "\n";
        $backup_sql .= "-- Target URL: " . get_option('siteurl') . "\n\n";

        foreach ($tables_to_backup as $table_name) {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            if (!$table_exists) {
                continue;
            }

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
            if ($create_table) {
                $backup_sql .= "\n-- Table structure for $table_name\n";
                $backup_sql .= "DROP TABLE IF EXISTS `$table_name`;\n";
                $backup_sql .= $create_table[1] . ";\n\n";

                // Export table data using WordPress methods
                $backup_sql .= $this->export_table_data_for_backup($table_name);
            }
        }

        $result = file_put_contents($backup_path, $backup_sql);

        if ($result === false) {
            return new WP_Error('backup_failed', 'Failed to save backup file');
        }

        error_log('*** DB Sync: Backup file created successfully: ' . $backup_filename);
        return array(
            'filename' => $backup_filename,
            'file_size' => size_format(filesize($backup_path)),
            'tables_backed_up' => count($tables_to_backup)
        );
    }

    /**
     * Export table data for backup using WordPress native methods
     */
    private function export_table_data_for_backup($table_name) {
        global $wpdb;

        $sql_content = "-- Data for $table_name\n";

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
            // Skip problematic data
            if ($this->is_problematic_row_for_backup($row)) {
                continue;
            }

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
     * Check if a row contains problematic data that should be skipped during backup
     */
    private function is_problematic_row_for_backup($row) {
        global $wpdb;

        // Skip problematic options in options table
        if (isset($row['option_name']) && $wpdb->options === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->options}'")) {
            $problematic_options = array(
                '_transient_',
                '_site_transient_',
                'upload_url_path',
                'upload_path',
                'template',
                'stylesheet',
                'current_theme'
            );

            foreach ($problematic_options as $option) {
                if (strpos($row['option_name'], $option) === 0) {
                    return true;
                }
            }

            // Preserve plugin's own options
            if (strpos($row['option_name'], 'db_sync_') === 0) {
                return false; // Keep plugin options
            }
        }

        // Skip rows with malformed content
        foreach ($row as $value) {
            if (is_string($value) && strpos($value, '<!') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract table names from SQL content
     */
    private function extract_tables_from_sql($sql_content) {
        $tables = array();
        $statements = $this->split_sql($sql_content);

        foreach ($statements as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches)) {
                $tables[] = $matches[1];
            }
        }

        return array_unique($tables);
    }

    /**
     * Handle file check AJAX request for polling
     */
    public function handle_check_files() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Debug: Log when file check is called
        error_log('*** DB Sync: handle_check_files() called');

        // Get current files
        $current_files = self::get_available_files();
        error_log('*** DB Sync: Found ' . count($current_files) . ' current files');

        // Get stored file list from option
        $stored_files = get_option('db_sync_stored_files', array());
        error_log('*** DB Sync: Found ' . count($stored_files) . ' stored file hashes');

        // Special case: If this is the first check (no stored files), mark as changed
        $is_first_check = empty($stored_files) && !empty($current_files);
        if ($is_first_check) {
            error_log('*** DB Sync: First check detected (no stored files but current files exist), marking as changed');
        }

        // Compare current files with stored files
        $changed = false;
        $file_hashes = array();

        foreach ($current_files as $file) {
            $file_hash = $file['filename'] . '|' . $file['modified'] . '|' . $file['file_size'];
            $file_hashes[] = $file_hash;
            error_log('*** DB Sync: Current file hash: ' . $file_hash);

            if (!in_array($file_hash, $stored_files)) {
                error_log('*** DB Sync: File hash not found in stored files, marking as changed: ' . $file_hash);
                $changed = true;
            }
        }

        // Debug stored files
        foreach ($stored_files as $stored_hash) {
            error_log('*** DB Sync: Stored file hash: ' . $stored_hash);
        }

        // Check if any stored files are missing (file count changed)
        if (count($stored_files) !== count($file_hashes)) {
            error_log('*** DB Sync: File count changed from ' . count($stored_files) . ' to ' . count($file_hashes) . ', marking as changed');
            $changed = true;
        }

        // Check for deleted files (hashes that exist in stored but not in current)
        foreach ($stored_files as $stored_hash) {
            if (!in_array($stored_hash, $file_hashes)) {
                error_log('*** DB Sync: Stored file hash no longer exists, marking as changed: ' . $stored_hash);
                $changed = true;
            }
        }

        // Apply first check logic
        if ($is_first_check) {
            $changed = true;
        }

        error_log('*** DB Sync: Final changed status: ' . ($changed ? 'true' : 'false'));

        // Update stored file list
        update_option('db_sync_stored_files', $file_hashes);

        wp_send_json_success(array(
            'changed' => $changed,
            'files' => $current_files
        ));
    }
}
