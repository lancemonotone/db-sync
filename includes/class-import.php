<?php

/**
 * Import functionality for Database Sync plugin
 */

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

        // Delete file after successful import
        unlink($file_path);

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

        // Delete backup file after successful restore
        unlink($backup_path);

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
                'date' => $file_info['date']
            );
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
        // Format: 250825-development-local.sql or 250825-development-local-BAK.sql
        if (preg_match('/^(\d{6})-([^-]+)-([^-]+)(-BAK)?\.sql$/', $filename, $matches)) {
            return array(
                'date' => $matches[1],
                'preset' => ucfirst(str_replace('-', ' ', $matches[2])),
                'environment' => ucfirst($matches[3]),
                'is_backup' => !empty($matches[4])
            );
        }

        return array(
            'date' => 'Unknown',
            'preset' => 'Unknown',
            'environment' => 'Unknown',
            'is_backup' => false
        );
    }

    /**
     * Import SQL content
     */
    private function import_sql($sql_content) {
        global $wpdb;

        // Extract source URL from SQL comments
        $source_url = $this->extract_source_url($sql_content);
        $target_url = get_option('siteurl');

        // Replace URLs
        $sql_content = $this->replace_urls($sql_content, $source_url, $target_url);

        // Split SQL into individual statements
        $statements = $this->split_sql($sql_content);

        $results = array(
            'tables_processed' => 0,
            'rows_imported' => 0,
            'errors' => array()
        );

        // Begin transaction
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                if (empty($statement) || strpos($statement, '--') === 0) {
                    continue;
                }

                // Debug: Log problematic statements
                if (strlen($statement) > 200) {
                    error_log("*** DB Sync: Processing statement " . ($index + 1) . " (length: " . strlen($statement) . ")");
                }

                $result = $wpdb->query($statement);

                if ($result === false) {
                    $error_msg = 'SQL Error: ' . $wpdb->last_error . ' in statement ' . ($index + 1) . ': ' . substr($statement, 0, 200);
                    error_log("*** DB Sync: " . $error_msg);
                    throw new Exception($error_msg);
                }

                if (strpos($statement, 'INSERT INTO') === 0) {
                    $results['rows_imported']++;
                } elseif (strpos($statement, 'CREATE TABLE') === 0) {
                    $results['tables_processed']++;
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            return new WP_Error('import_failed', $e->getMessage());
        }

        return $results;
    }

    /**
     * Generate preview of import
     */
    private function generate_preview($sql_content) {
        // Extract source URL from SQL comments
        $source_url = $this->extract_source_url($sql_content);
        $target_url = get_option('siteurl');

        // Count tables and rows
        $tables = array();
        $statements = $this->split_sql($sql_content);

        foreach ($statements as $statement) {
            if (preg_match('/CREATE TABLE `([^`]+)`/', $statement, $matches)) {
                $table_name = $matches[1];
                $tables[$table_name] = 0;
            } elseif (preg_match('/INSERT INTO `([^`]+)`/', $statement, $matches)) {
                $table_name = $matches[1];
                if (isset($tables[$table_name])) {
                    $tables[$table_name]++;
                }
            }
        }

        return array(
            'source_url' => $source_url,
            'target_url' => $target_url,
            'tables' => $tables,
            'total_rows' => array_sum($tables)
        );
    }

    /**
     * Extract source URL from SQL comments
     */
    private function extract_source_url($sql_content) {
        if (preg_match('/-- Source URL: (.+)/', $sql_content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * Replace URLs in SQL content
     */
    private function replace_urls($sql_content, $source_url, $target_url) {
        if (empty($source_url) || empty($target_url) || $source_url === $target_url) {
            return $sql_content;
        }

        // More robust URL replacement - only replace in string literals
        $pattern = "/(['\"])([^'\"]*)" . preg_quote($source_url, '/') . "([^'\"]*)(['\"])/";
        $replacement = '$1$2' . $target_url . '$3$4';

        return preg_replace($pattern, $replacement, $sql_content);
    }

    /**
     * Split SQL content into individual statements
     */
    private function split_sql($sql_content) {
        // Remove comments
        $sql_content = preg_replace('/--.*$/m', '', $sql_content);

        // Split by semicolon, but be more careful about semicolons in strings
        $statements = array();
        $current_statement = '';
        $in_string = false;
        $string_char = '';

        for ($i = 0; $i < strlen($sql_content); $i++) {
            $char = $sql_content[$i];

            if (!$in_string && ($char === "'" || $char === '"')) {
                $in_string = true;
                $string_char = $char;
            } elseif ($in_string && $char === $string_char) {
                // Check for escaped quote
                if ($i > 0 && $sql_content[$i - 1] !== '\\') {
                    $in_string = false;
                    $string_char = '';
                }
            }

            if (!$in_string && $char === ';') {
                $statements[] = trim($current_statement);
                $current_statement = '';
            } else {
                $current_statement .= $char;
            }
        }

        // Add the last statement if it's not empty
        if (!empty(trim($current_statement))) {
            $statements[] = trim($current_statement);
        }

        return array_filter($statements);
    }

    /**
     * Create backup of existing tables before import
     */
    private function create_backup($import_filename) {
        global $wpdb;

        // Parse import filename to get info
        $file_info = self::parse_filename($import_filename);

        // Create backup filename
        $backup_filename = str_replace('.sql', '-BAK.sql', $import_filename);

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

        // Generate backup SQL
        $backup_sql = "-- WordPress Database Backup\n";
        $backup_sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $backup_sql .= "-- Backup before import: " . $import_filename . "\n";
        $backup_sql .= "-- Target URL: " . get_option('siteurl') . "\n\n";

        foreach ($tables_to_backup as $table_name) {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            if (!$table_exists) {
                continue; // Skip if table doesn't exist
            }

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
            if (!$create_table) {
                continue;
            }

            $backup_sql .= "\n-- Table structure for $table_name\n";
            $backup_sql .= "DROP TABLE IF EXISTS `$table_name`;\n";
            $backup_sql .= $create_table[1] . ";\n\n";

            // Get table data
            $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
            if (!empty($rows)) {
                $backup_sql .= "-- Data for $table_name\n";

                foreach ($rows as $row) {
                    $escaped_values = array();
                    foreach ($row as $value) {
                        if ($value === null) {
                            $escaped_values[] = 'NULL';
                        } else {
                            $escaped_values[] = "'" . $wpdb->_real_escape($value) . "'";
                        }
                    }
                    $backup_sql .= "INSERT INTO `$table_name` VALUES (" . implode(",", $escaped_values) . ");\n";
                }
            }
        }

        // Save backup file
        $result = file_put_contents($backup_path, $backup_sql);

        if ($result === false) {
            return new WP_Error('backup_failed', 'Failed to save backup file');
        }

        return array(
            'filename' => $backup_filename,
            'file_size' => size_format(strlen($backup_sql)),
            'tables_backed_up' => count($tables_to_backup)
        );
    }

    /**
     * Extract table names from SQL content
     */
    private function extract_tables_from_sql($sql_content) {
        $tables = array();
        $statements = $this->split_sql($sql_content);

        foreach ($statements as $statement) {
            if (preg_match('/CREATE TABLE `([^`]+)`/', $statement, $matches)) {
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

        // Add is_backup field to each file
        foreach ($current_files as &$file) {
            $file_info = self::parse_filename($file['filename']);
            $file['is_backup'] = $file_info['is_backup'];
        }

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
