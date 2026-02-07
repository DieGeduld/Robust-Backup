<?php
/**
 * AJAX Handler
 * 
 * Handles all AJAX requests from the admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Ajax_Handler {

    public function __construct() {
        // Backup operations
        add_action( 'wp_ajax_wprb_start_backup', [ $this, 'start_backup' ] );
        add_action( 'wp_ajax_wprb_process_backup', [ $this, 'process_backup' ] );
        add_action( 'wp_ajax_wprb_cancel_backup', [ $this, 'cancel_backup' ] );
        add_action( 'wp_ajax_wprb_get_status', [ $this, 'get_status' ] );
        add_action( 'wp_ajax_wprb_delete_backup', [ $this, 'delete_backup' ] );
        add_action( 'wp_ajax_wprb_list_backups', [ $this, 'list_backups' ] );
        add_action( 'wp_ajax_wprb_save_settings', [ $this, 'save_settings' ] );
        add_action( 'wp_ajax_wprb_disconnect_storage', [ $this, 'disconnect_storage' ] );

        // Schedule operations
        add_action( 'wp_ajax_wprb_add_schedule', [ $this, 'add_schedule' ] );
        add_action( 'wp_ajax_wprb_update_schedule', [ $this, 'update_schedule' ] );
        add_action( 'wp_ajax_wprb_delete_schedule', [ $this, 'delete_schedule' ] );

        // Restore operations
        add_action( 'wp_ajax_wprb_analyze_backup', [ $this, 'analyze_backup' ] );
        add_action( 'wp_ajax_wprb_start_restore', [ $this, 'start_restore' ] );
        add_action( 'wp_ajax_wprb_process_restore', [ $this, 'process_restore' ] );
        add_action( 'wp_ajax_wprb_cancel_restore', [ $this, 'cancel_restore' ] );
        add_action( 'wp_ajax_wprb_get_restore_status', [ $this, 'get_restore_status' ] );

        // Download handler
        add_action( 'wp_ajax_wprb_download', [ $this, 'handle_download' ] );
    }

    /**
     * Verify request.
     */
    private function verify( $action = 'wprb_nonce' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }

        if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Sicherheitstoken.' ] );
        }
    }

    /**
     * Start a new backup.
     */
    public function start_backup() {
        $this->verify();

        $type   = sanitize_text_field( $_POST['type'] ?? 'full' );
        $engine = new WPRB_Backup_Engine();
        $result = $engine->start( $type );

        if ( isset( $result['error'] ) && $result['error'] === true ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    /**
     * Process next backup step.
     */
    public function process_backup() {
        $this->verify();

        // Increase limits for this request
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '512M' );

        $engine = new WPRB_Backup_Engine();
        $result = $engine->process_next();

        if ( isset( $result['error'] ) && $result['error'] === true ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    /**
     * Cancel running backup.
     */
    public function cancel_backup() {
        $this->verify();

        $engine = new WPRB_Backup_Engine();
        $result = $engine->cancel();

        wp_send_json_success( $result );
    }

    /**
     * Get current status.
     */
    public function get_status() {
        $this->verify();

        $engine = new WPRB_Backup_Engine();
        wp_send_json_success( $engine->get_status() );
    }

    /**
     * Delete a backup.
     */
    public function delete_backup() {
        $this->verify();

        $backup_id = sanitize_file_name( $_POST['backup_id'] ?? '' );
        if ( empty( $backup_id ) ) {
            wp_send_json_error( [ 'message' => 'Keine Backup-ID angegeben.' ] );
        }

        $storage = new WPRB_Storage_Manager();
        $result  = $storage->delete_backup( $backup_id );

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Backup gelöscht.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Backup konnte nicht gelöscht werden.' ] );
        }
    }

    /**
     * List all backups.
     */
    public function list_backups() {
        $this->verify();

        $storage = new WPRB_Storage_Manager();
        wp_send_json_success( [ 'backups' => $storage->list_backups() ] );
    }

    /**
     * Save settings.
     */
    public function save_settings() {
        $this->verify();

        $tab = sanitize_text_field( $_POST['tab'] ?? '' );

        if ( $tab === 'settings' ) {
            // Performance
            update_option( 'wprb_db_chunk_size', max( 100, intval( $_POST['db_chunk_size'] ?? 1000 ) ) );
            update_option( 'wprb_file_batch_size', max( 50, intval( $_POST['file_batch_size'] ?? 200 ) ) );
            update_option( 'wprb_max_archive_size', max( 50, intval( $_POST['max_archive_size'] ?? 500 ) ) );
            update_option( 'wprb_exclude_dirs', sanitize_textarea_field( $_POST['exclude_dirs'] ?? '' ) );
            
            // Notifications
            update_option( 'wprb_email_notification_type', sanitize_text_field( $_POST['email_notification_type'] ?? 'none' ) );
            update_option( 'wprb_notification_email', sanitize_email( $_POST['notification_email'] ?? '' ) );

            wp_send_json_success( [ 'message' => 'Einstellungen gespeichert.' ] );
        } elseif ( $tab === 'storage' ) {
            // Storage
            update_option( 'wprb_storage', array_map( 'sanitize_text_field', (array) ( $_POST['storage'] ?? [ 'local' ] ) ) );

            // Google Drive
            if ( isset( $_POST['gdrive_client_id'] ) ) {
                update_option( 'wprb_gdrive_client_id', sanitize_text_field( $_POST['gdrive_client_id'] ) );
                update_option( 'wprb_gdrive_secret', sanitize_text_field( $_POST['gdrive_secret'] ) );
            }

            // Dropbox
            if ( isset( $_POST['dropbox_app_key'] ) ) {
                update_option( 'wprb_dropbox_app_key', sanitize_text_field( $_POST['dropbox_app_key'] ) );
                update_option( 'wprb_dropbox_secret', sanitize_text_field( $_POST['dropbox_secret'] ) );
            }
            
            // SFTP
            if ( isset( $_POST['sftp_host'] ) ) {
                update_option( 'wprb_sftp_host', sanitize_text_field( $_POST['sftp_host'] ) );
                update_option( 'wprb_sftp_port', intval( $_POST['sftp_port'] ) );
                update_option( 'wprb_sftp_user', sanitize_text_field( $_POST['sftp_user'] ) );
                if ( ! empty( $_POST['sftp_pass'] ) ) {
                    update_option( 'wprb_sftp_pass', sanitize_text_field( $_POST['sftp_pass'] ) );
                }
                update_option( 'wprb_sftp_path', sanitize_text_field( $_POST['sftp_path'] ) );
                update_option( 'wprb_sftp_proto', sanitize_text_field( $_POST['sftp_proto'] ) );
            }

            // S3
            if ( isset( $_POST['s3_key'] ) ) {
                update_option( 'wprb_s3_endpoint', sanitize_text_field( $_POST['s3_endpoint'] ) );
                update_option( 'wprb_s3_region', sanitize_text_field( $_POST['s3_region'] ) );
                update_option( 'wprb_s3_key', sanitize_text_field( $_POST['s3_key'] ) );
                if ( ! empty( $_POST['s3_secret'] ) ) {
                     update_option( 'wprb_s3_secret', sanitize_text_field( $_POST['s3_secret'] ) );
                }
                update_option( 'wprb_s3_bucket', sanitize_text_field( $_POST['s3_bucket'] ) );
            }
            
            wp_send_json_success( [ 'message' => 'Speicher-Einstellungen gespeichert.' ] );
        }

        wp_send_json_error( [ 'message' => 'Unbekannte Anfrage.' ] );
    }

    /**
     * Disconnect storage service.
     */
    public function disconnect_storage() {
        $this->verify();

        $service = sanitize_text_field( $_POST['service'] ?? '' );

        if ( $service === 'gdrive' ) {
            delete_option( 'wprb_gdrive_token' );
            wp_send_json_success( [ 'message' => 'Google Drive getrennt.' ] );
        } elseif ( $service === 'dropbox' ) {
            delete_option( 'wprb_dropbox_token' );
            wp_send_json_success( [ 'message' => 'Dropbox getrennt.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Unbekannter Dienst.' ] );
        }
    }

    /**
     * Handle file download.
     */
    public function handle_download() {
        $storage = new WPRB_Storage_Manager();
        $storage->stream_download();
    }

    // ─────────────────────────────────────────────
    // Schedule Operations
    // ─────────────────────────────────────────────

    /**
     * Add a new schedule.
     */
    public function add_schedule() {
        $this->verify();

        $data = [
            'interval'     => sanitize_text_field( $_POST['interval'] ?? 'daily' ),
            'time'         => sanitize_text_field( $_POST['time'] ?? '03:00' ),
            'day_of_week'  => max( 1, min( 7, intval( $_POST['day_of_week'] ?? 1 ) ) ),
            'day_of_month' => max( 1, min( 28, intval( $_POST['day_of_month'] ?? 1 ) ) ),
            'type'         => sanitize_text_field( $_POST['type'] ?? 'full' ),
            'destinations' => array_map( 'sanitize_text_field', (array) ( $_POST['destinations'] ?? [ 'local' ] ) ),
        ];

        // Basic validation
        if ( ! in_array( $data['interval'], [ 'hourly', 'daily', 'weekly', 'monthly' ] ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Intervall.' ] );
        }

        $result = WPRB_Backup_Scheduler::add_schedule( $data );

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Zeitplan erstellt.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Fehler beim Erstellen des Zeitplans.' ] );
        }
    }

    /**
     * Update an existing schedule.
     */
    public function update_schedule() {
        $this->verify();

        $id = sanitize_text_field( $_POST['schedule_id'] ?? '' );
        if ( empty( $id ) ) {
            wp_send_json_error( [ 'message' => 'Keine ID angegeben.' ] );
        }

        $data = [
            'interval'     => sanitize_text_field( $_POST['interval'] ?? 'daily' ),
            'time'         => sanitize_text_field( $_POST['time'] ?? '03:00' ),
            'day_of_week'  => max( 1, min( 7, intval( $_POST['day_of_week'] ?? 1 ) ) ),
            'day_of_month' => max( 1, min( 28, intval( $_POST['day_of_month'] ?? 1 ) ) ),
            'type'         => sanitize_text_field( $_POST['type'] ?? 'full' ),
            'destinations' => array_map( 'sanitize_text_field', (array) ( $_POST['destinations'] ?? [ 'local' ] ) ),
        ];

        // Basic validation
        if ( ! in_array( $data['interval'], [ 'hourly', 'daily', 'weekly', 'monthly' ] ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Intervall.' ] );
        }

        $result = WPRB_Backup_Scheduler::update_schedule( $id, $data );

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Zeitplan aktualisiert.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Fehler beim Aktualisieren.' ] );
        }
    }

    /**
     * Delete a schedule.
     */
    public function delete_schedule() {
        $this->verify();

        $id = sanitize_text_field( $_POST['schedule_id'] ?? '' );
        if ( empty( $id ) ) {
            wp_send_json_error( [ 'message' => 'Keine ID angegeben.' ] );
        }

        $result = WPRB_Backup_Scheduler::delete_schedule( $id );

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Zeitplan gelöscht.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Fehler beim Löschen.' ] );
        }
    }

    // ─────────────────────────────────────────────
    // Restore Operations
    // ─────────────────────────────────────────────

    /**
     * Analyze a backup for restore.
     */
    public function analyze_backup() {
        $this->verify();

        $backup_id = sanitize_file_name( $_POST['backup_id'] ?? '' );
        if ( empty( $backup_id ) ) {
            wp_send_json_error( [ 'message' => 'Keine Backup-ID angegeben.' ] );
        }

        $engine = new WPRB_Restore_Engine();
        $result = $engine->analyze( $backup_id );

        if ( isset( $result['error'] ) && $result['error'] ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    /**
     * Start restore process.
     */
    public function start_restore() {
        $this->verify();

        $backup_id       = sanitize_file_name( $_POST['backup_id'] ?? '' );
        $restore_db      = ! empty( $_POST['restore_db'] );
        $restore_files   = ! empty( $_POST['restore_files'] );
        $create_snapshot = ! empty( $_POST['create_snapshot'] );

        if ( empty( $backup_id ) ) {
            wp_send_json_error( [ 'message' => 'Keine Backup-ID angegeben.' ] );
        }

        if ( ! $restore_db && ! $restore_files ) {
            wp_send_json_error( [ 'message' => 'Bitte wähle mindestens DB oder Dateien zur Wiederherstellung.' ] );
        }

        $engine = new WPRB_Restore_Engine();
        $result = $engine->start( $backup_id, $restore_db, $restore_files, $create_snapshot );

        if ( isset( $result['error'] ) && $result['error'] ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    /**
     * Process next restore step.
     */
    public function process_restore() {
        $this->verify();

        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '512M' );

        $engine = new WPRB_Restore_Engine();
        $result = $engine->process_next();

        if ( isset( $result['error'] ) && $result['error'] === true ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    /**
     * Cancel running restore.
     */
    public function cancel_restore() {
        $this->verify();

        $engine = new WPRB_Restore_Engine();
        wp_send_json_success( $engine->cancel() );
    }

    /**
     * Get restore status.
     */
    public function get_restore_status() {
        $this->verify();

        $engine = new WPRB_Restore_Engine();
        wp_send_json_success( $engine->get_status() );
    }
}
