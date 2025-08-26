<?php

/**
 * Admin interface for Database Sync plugin
 */

class DatabaseSync_Admin {

    public function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'Database Sync',
            'Database Sync',
            'manage_options',
            'db-sync',
            array($this, 'admin_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_db-sync') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'db-sync-admin',
            DB_SYNC_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            DB_SYNC_VERSION,
            true
        );

        wp_enqueue_style(
            'db-sync-admin',
            DB_SYNC_PLUGIN_URL . 'assets/admin.css',
            array(),
            DB_SYNC_VERSION
        );

        // Localize script
        wp_localize_script('db-sync-admin', 'dbSyncAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('db_sync_nonce'),
            'strings' => array(
                'exporting' => 'Exporting...',
                'importing' => 'Importing...',
                'previewing' => 'Generating preview...',
                'error' => 'An error occurred',
                'success' => 'Operation completed successfully'
            )
        ));
    }

    /**
     * Admin page content
     */
    public function admin_page() {
        $available_tables = DatabaseSync::get_available_tables();
        $table_counts = DatabaseSync::get_table_counts();
        $presets = DatabaseSync::get_presets();
        $saved_preset = get_option('db_sync_preset', 'development');
        $saved_tables = get_option('db_sync_tables', $presets['development']['tables']);

?>
        <div class="wrap">
            <h1>Database Sync</h1>

            <div class="db-sync-container">
                <!-- Export Section -->
                <div class="db-sync-section">
                    <h2>Export Database</h2>
                    <form id="db-sync-export-form" action="javascript:void(0);" method="post" onsubmit="return false;">
                        <div class="preset-buttons">
                            <?php foreach ($presets as $key => $preset): ?>
                                <button type="button" class="preset-btn <?php echo ($saved_preset === $key) ? 'active' : ''; ?>" data-preset="<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($preset['name']); ?>
                                </button>
                            <?php endforeach; ?>
                            <button type="button" class="preset-btn <?php echo ($saved_preset === 'custom') ? 'active' : ''; ?>" data-preset="custom">Custom</button>
                        </div>

                        <div id="preset-description" class="preset-description">
                            <?php
                            $current_preset = $saved_preset;
                            if (isset($presets[$current_preset])) {
                                echo esc_html($presets[$current_preset]['description']);
                            } else {
                                echo 'Select individual tables to sync';
                            }
                            ?>
                        </div>

                        <div id="table-selection-container">
                            <h3>Select Tables</h3>
                            <div class="table-selection">
                                <?php foreach ($available_tables as $display_name => $table_name): ?>
                                    <label class="table-checkbox">
                                        <input type="checkbox" name="tables[]" value="<?php echo esc_attr($display_name); ?>"
                                            <?php checked(in_array($display_name, $saved_tables)); ?>>
                                        <?php echo esc_html($display_name); ?>
                                        <span class="row-count">(<?php echo number_format($table_counts[$display_name]); ?> rows)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <p class="submit">
                            <button type="button" id="export-database" class="button button-primary">Export Database</button>
                        </p>
                    </form>
                </div>

                <!-- Import Section -->
                <div class="db-sync-section">
                    <h2>Import Database</h2>
                    <div id="import-file-selection">
                        <div class="file-selection">
                            <h3>Available SQL Files</h3>
                            <div class="no-files-message" <?php if (!empty(DatabaseSync_Import::get_available_files())) echo ' style="display: none;"'; ?>>
                                <p>No SQL files found. Export a database first to see available files here.</p>
                            </div>
                            <div class="file-list">
                                <?php
                                $available_files = DatabaseSync_Import::get_available_files();
                                if (!empty($available_files)):
                                    $import_files = array();
                                    $backup_files = array();

                                    foreach ($available_files as $file) {
                                        // Add is_backup field if not present
                                        if (!isset($file['is_backup'])) {
                                            $file_info = DatabaseSync_Import::parse_filename($file['filename']);
                                            $file['is_backup'] = $file_info['is_backup'];
                                        }

                                        if ($file['is_backup']) {
                                            $backup_files[] = $file;
                                        } else {
                                            $import_files[] = $file;
                                        }
                                    }
                                ?>

                                    <!-- Import Files -->
                                    <?php if (!empty($import_files)): ?>
                                        <?php foreach ($import_files as $index => $file): ?>
                                            <div class="file-item <?php echo ($index === 0) ? 'selected' : ''; ?>" data-filename="<?php echo esc_attr($file['filename']); ?>">
                                                <div class="file-info">
                                                    <div class="file-name"><?php echo esc_html($file['filename']); ?></div>
                                                    <div class="file-details">
                                                        <span class="file-preset"><?php echo esc_html($file['preset']); ?></span>
                                                        <span class="file-environment"><?php echo esc_html($file['environment']); ?></span>
                                                        <span class="file-size"><?php echo esc_html($file['file_size']); ?></span>
                                                        <span class="file-date"><?php echo date('M j, Y g:i A', $file['modified']); ?></span>
                                                    </div>
                                                </div>
                                                <button type="button" class="delete-file-btn" data-filename="<?php echo esc_attr($file['filename']); ?>" title="Delete file">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <!-- Backup Files -->
                                    <?php if (!empty($backup_files)): ?>
                                        <?php foreach ($backup_files as $file): ?>
                                            <div class="file-item backup-file" data-filename="<?php echo esc_attr($file['filename']); ?>">
                                                <div class="file-info">
                                                    <div class="file-name"><?php echo esc_html($file['filename']); ?></div>
                                                    <div class="file-details">
                                                        <span class="file-preset"><?php echo esc_html($file['preset']); ?></span>
                                                        <span class="file-environment"><?php echo esc_html($file['environment']); ?></span>
                                                        <span class="file-size"><?php echo esc_html($file['file_size']); ?></span>
                                                        <span class="file-date"><?php echo date('M j, Y g:i A', $file['modified']); ?></span>
                                                        <span class="file-type">Backup</span>
                                                    </div>
                                                </div>
                                                <button type="button" class="delete-file-btn" data-filename="<?php echo esc_attr($file['filename']); ?>" title="Delete backup file">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <p class="submit" <?php if (empty($available_files)) echo ' style="display: none;"'; ?>>
                                <button type="button" id="preview-import" class="button">Preview Changes</button>
                                <button type="button" id="import-database" class="button button-primary">Import Database</button>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Results Section -->
                <div id="db-sync-results" style="display: none;">
                    <h2>Results</h2>
                    <div id="results-content"></div>
                </div>
            </div>
        </div>
<?php
    }
}
