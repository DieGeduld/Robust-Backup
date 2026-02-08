<?php
/**
 * Plugin Name: WP Robust Backup
 * Plugin URI: https://example.com/wp-robust-backup
 * Description: Zuverlässiges Backup-Plugin für große WordPress-Seiten. Sichert Datenbank und Dateien in Chunks – ohne Timeout-Probleme. Unterstützt lokale Speicherung, Download, Google Drive und Dropbox.
 * Version: 1.0.0
 * Author: Custom Development
 * License: GPL-2.0+
 * Text Domain: wp-robust-backup
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPRB_VERSION', '1.0.0' );
define( 'WPRB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPRB_BACKUP_DIR', WP_CONTENT_DIR . '/wprb-backups/' );
define( 'WPRB_LOG_FILE', WPRB_BACKUP_DIR . 'backup.log' );

// Autoload classes
require_once WPRB_PLUGIN_DIR . 'includes/class-logger.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-db-exporter.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-file-archiver.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-storage-manager.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-backup-engine.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-restore-engine.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once WPRB_PLUGIN_DIR . 'includes/class-backup-scheduler.php';
require_once WPRB_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Main plugin class
 */
final class WP_Robust_Backup {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
    }

    public function activate() {
        // Create backup directory with .htaccess protection
        if ( ! file_exists( WPRB_BACKUP_DIR ) ) {
            wp_mkdir_p( WPRB_BACKUP_DIR );
        }

        // Protect backup directory from direct access
        $htaccess = WPRB_BACKUP_DIR . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
        }

        $index = WPRB_BACKUP_DIR . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }

        // Set default options
        $defaults = [
            'wprb_schedule'          => 'daily',
            'wprb_schedule_time'     => '03:00',
            'wprb_retention'         => 5,
            'wprb_storage'           => [ 'local' ],
            'wprb_db_chunk_size'     => 1000,
            'wprb_file_batch_size'   => 200,
            'wprb_exclude_dirs'      => "wp-content/wprb-backups\nwp-content/cache\nwp-content/upgrade",
            'wprb_gdrive_client_id'  => '',
            'wprb_gdrive_secret'     => '',
            'wprb_gdrive_token'      => '',
            'wprb_dropbox_app_key'   => '',
            'wprb_dropbox_secret'    => '',
            'wprb_dropbox_token'     => '',
            'wprb_max_archive_size'  => 500, // MB per archive part
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }

        // Schedule cron
        WPRB_Backup_Scheduler::schedule();
    }

    public function deactivate() {
        WPRB_Backup_Scheduler::unschedule();
    }

    public function init() {
        // Initialize components
        new WPRB_Ajax_Handler();
        new WPRB_Admin_Page();
        WPRB_Backup_Scheduler::init();
    }
}

// Boot the plugin
WP_Robust_Backup::instance();
