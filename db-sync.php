<?php

/**
 * Plugin Name: Database Sync
 * Description: Export and import WordPress database tables with URL replacement
 * Version: 1.0.0
 * Author: Rus Miller
 * Author URI: https://rusmiller.com
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DB_SYNC_VERSION', '1.0.0');
define('DB_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DB_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once DB_SYNC_PLUGIN_DIR . 'includes/class-admin.php';
require_once DB_SYNC_PLUGIN_DIR . 'includes/class-export.php';
require_once DB_SYNC_PLUGIN_DIR . 'includes/class-import.php';

/**
 * Main plugin class
 */
class DatabaseSync {

    private $admin;
    private $export;
    private $import;

    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        // Initialize components
        $this->admin = new DatabaseSync_Admin();
        $this->export = new DatabaseSync_Export();
        $this->import = new DatabaseSync_Import();

        // Add admin menu
        add_action('admin_menu', array($this->admin, 'add_admin_menu'));

        // Handle AJAX requests
        add_action('wp_ajax_db_sync_export', array($this->export, 'handle_export'));
        add_action('wp_ajax_db_sync_import', array($this->import, 'handle_import'));
        add_action('wp_ajax_db_sync_preview', array($this->import, 'handle_preview'));
        add_action('wp_ajax_db_sync_restore', array($this->import, 'handle_restore'));
        add_action('wp_ajax_db_sync_delete', array($this->import, 'handle_delete'));
        add_action('wp_ajax_db_sync_check_files', array($this->import, 'handle_check_files'));
        add_action('wp_ajax_db_sync_refresh_nonce', array($this, 'handle_refresh_nonce'));
    }

    /**
     * Get available tables
     */
    public static function get_available_tables() {
        global $wpdb;

        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $available_tables = array();

        foreach ($tables as $table) {
            $table_name = $table[0];
            // Remove prefix for display
            $display_name = str_replace($wpdb->prefix, '', $table_name);
            $available_tables[$display_name] = $table_name;
        }

        return $available_tables;
    }

    /**
     * Get table row counts
     */
    public static function get_table_counts() {
        global $wpdb;

        $tables = self::get_available_tables();
        $counts = array();

        foreach ($tables as $display_name => $table_name) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
            $counts[$display_name] = $count;
        }

        return $counts;
    }

    /**
     * Get preset configurations
     */
    public static function get_presets() {
        return array(
            'development' => array(
                'name' => 'Development',
                'description' => 'Full development environment sync',
                'tables' => array('posts', 'postmeta', 'terms', 'term_relationships', 'term_taxonomy', 'termmeta', 'options', 'widgets', 'widget_areas', 'users', 'usermeta')
            ),
            'content' => array(
                'name' => 'Content Only',
                'description' => 'Content and structure only',
                'tables' => array('posts', 'postmeta', 'terms', 'term_relationships', 'termmeta')
            )
        );
    }

    /**
     * Handle nonce refresh AJAX request
     */
    public function handle_refresh_nonce() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        wp_send_json_success(array(
            'nonce' => wp_create_nonce('db_sync_nonce')
        ));
    }
}

// Initialize the plugin
new DatabaseSync();
