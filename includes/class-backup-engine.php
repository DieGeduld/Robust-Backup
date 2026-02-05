<?php
/**
 * Backup Engine
 * 
 * Orchestrates the full backup process: DB export → File archive → Storage distribution.
 * Manages backup state and phases.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Backup_Engine {

    const PHASE_INIT      = 'init';
    const PHASE_DB        = 'database';
    const PHASE_FILES     = 'files';
    const PHASE_COMPRESS  = 'compress';
    const PHASE_UPLOAD    = 'upload';
    const PHASE_CLEANUP   = 'cleanup';
    const PHASE_DONE      = 'done';

    private $db_exporter;
    private $file_archiver;
    private $storage;

    public function __construct() {
        $this->db_exporter   = new WPRB_DB_Exporter();
        $this->file_archiver = new WPRB_File_Archiver();
        $this->storage       = new WPRB_Storage_Manager();
    }

    /**
     * Get backup state key.
     */
    private function state_key() {
        return 'wprb_backup_state';
    }

    /**
     * Start a new backup.
     * 
     * @param string $type 'full', 'db_only', 'files_only'
     * @return array Initial state.
     */
    public function start( $type = 'full' ) {
        // Check if another backup is running
        $existing = get_option( $this->state_key() );
        if ( $existing && $existing['phase'] !== self::PHASE_DONE ) {
            return [
                'error'   => true,
                'message' => 'Es läuft bereits ein Backup. Bitte warten oder das laufende Backup abbrechen.',
            ];
        }

        $backup_id = 'backup-' . date( 'Y-m-d-His' );
        wp_mkdir_p( WPRB_BACKUP_DIR . $backup_id );

        $state = [
            'backup_id'  => $backup_id,
            'type'       => $type,
            'phase'      => self::PHASE_INIT,
            'progress'   => 0,
            'message'    => 'Backup wird vorbereitet...',
            'started_at' => time(),
            'errors'     => [],
            'all_files'  => [],
        ];

        update_option( $this->state_key(), $state, false );
        $this->log( 'Backup gestartet: ' . $backup_id . ' (Typ: ' . $type . ')' );

        return $state;
    }

    /**
     * Process the next step of the backup.
     * Called repeatedly via AJAX until done.
     */
    public function process_next() {
        $state = get_option( $this->state_key() );

        if ( ! $state ) {
            return [ 'error' => true, 'message' => 'Kein aktives Backup gefunden.' ];
        }

        $backup_id = $state['backup_id'];
        $type      = $state['type'];

        switch ( $state['phase'] ) {

            case self::PHASE_INIT:
                // Initialize the appropriate exporters
                if ( $type === 'full' || $type === 'db_only' ) {
                    $this->db_exporter->init_export( $backup_id );
                    $state['phase'] = self::PHASE_DB;
                    $state['message'] = 'Datenbank-Export wird gestartet...';
                } elseif ( $type === 'files_only' ) {
                    $this->file_archiver->init_archive( $backup_id );
                    $state['phase'] = self::PHASE_FILES;
                    $state['message'] = 'Datei-Archivierung wird gestartet...';
                }
                break;

            case self::PHASE_DB:
                $result = $this->db_exporter->process_chunk();

                if ( isset( $result['error'] ) ) {
                    $state['errors'][] = $result['error'];
                    $state['phase'] = self::PHASE_DONE;
                    break;
                }

                // Weight: DB export = 0-40% of total, files = 40-90%, upload = 90-100%
                if ( $type === 'db_only' ) {
                    $state['progress'] = $result['progress'] * 0.9;
                } else {
                    $state['progress'] = $result['progress'] * 0.4;
                }
                $state['message'] = $result['message'];

                if ( $result['done'] ) {
                    $sql_file = WPRB_BACKUP_DIR . $backup_id . '/database.sql';
                    if ( file_exists( $sql_file ) ) {
                        $state['all_files'][] = $sql_file;
                    }

                    if ( $type === 'full' ) {
                        $this->file_archiver->init_archive( $backup_id );
                        $state['phase'] = self::PHASE_FILES;
                        $state['message'] = 'Datenbank fertig. Starte Datei-Archivierung...';
                    } else {
                        $state['phase'] = self::PHASE_UPLOAD;
                        $state['message'] = 'Datenbank fertig. Starte Upload...';
                    }

                    $this->log( 'DB-Export abgeschlossen für ' . $backup_id );
                }
                break;

            case self::PHASE_FILES:
                $result = $this->file_archiver->process_batch();

                if ( isset( $result['error'] ) ) {
                    $state['errors'][] = $result['error'];
                    $state['phase'] = self::PHASE_DONE;
                    break;
                }

                if ( $type === 'files_only' ) {
                    $state['progress'] = $result['progress'] * 0.9;
                } else {
                    $state['progress'] = 40 + ( $result['progress'] * 0.5 );
                }
                $state['message'] = $result['message'];

                if ( $result['done'] ) {
                    if ( ! empty( $result['archive_files'] ) ) {
                        $state['all_files'] = array_merge( $state['all_files'], $result['archive_files'] );
                    }

                    $state['phase']   = self::PHASE_COMPRESS;
                    $state['message'] = 'Dateien fertig. Komprimiere Archive...';

                    $this->log( 'Datei-Archivierung abgeschlossen für ' . $backup_id );
                }
                break;

            case self::PHASE_COMPRESS:
                // Compress any tar files (tar → tar.gz)
                $this->file_archiver->compress_tar_files( $backup_id );

                // Update file list with compressed versions
                $backup_dir = WPRB_BACKUP_DIR . $backup_id . '/';
                $all_files  = [];

                foreach ( glob( $backup_dir . '*' ) as $file ) {
                    $name = basename( $file );
                    if ( is_file( $file ) && $name !== 'file_list.txt' && $name !== 'tar_batch.txt' ) {
                        $all_files[] = $file;
                    }
                }

                $state['all_files'] = $all_files;
                $state['phase']     = self::PHASE_UPLOAD;
                $state['progress']  = 90;
                $state['message']   = 'Komprimierung fertig. Starte Upload...';
                break;

            case self::PHASE_UPLOAD:
                // Save backup metadata
                $meta = [
                    'date'      => date( 'Y-m-d H:i:s' ),
                    'type'      => $type,
                    'wp_version' => get_bloginfo( 'version' ),
                    'site_url'  => get_site_url(),
                    'files'     => array_map( 'basename', $state['all_files'] ),
                ];
                file_put_contents(
                    WPRB_BACKUP_DIR . $backup_id . '/backup-meta.json',
                    wp_json_encode( $meta, JSON_PRETTY_PRINT )
                );

                // Distribute to configured storage
                $results = $this->storage->distribute( $backup_id, $state['all_files'] );
                $upload_errors = false;

                foreach ( $results as $storage => $result ) {
                    if ( ! $result['success'] ) {
                        $state['errors'][] = $storage . ': ' . $result['message'];
                        $upload_errors = true;
                    }
                    $this->log( $storage . ': ' . $result['message'] );
                }

                // Clean up local files if not selected as storage (and no upload errors occurred)
                $active_storage = (array) get_option( 'wprb_storage', [ 'local' ] );
                if ( ! in_array( 'local', $active_storage ) && ! $upload_errors ) {
                    // Only delete files if we have at least one successful remote storage
                    $remote_success = false;
                    foreach ( $results as $res ) {
                        if ( $res['success'] ) {
                            $remote_success = true;
                            break;
                        }
                    }

                    if ( $remote_success ) {
                        $this->storage->delete_local_files_only( $backup_id );
                        $this->log( 'Lokale Dateien bereinigt (Nur Cloud-Speicher gewählt).' );
                    }
                }

                // Enforce retention
                $this->storage->enforce_retention();

                $state['phase']    = self::PHASE_DONE;
                $state['progress'] = 100;
                $state['message']  = 'Backup abgeschlossen!';
                $state['storage_results'] = $results;

                $this->log( 'Backup abgeschlossen: ' . $backup_id );
                break;

            case self::PHASE_DONE:
                // Already done
                break;
        }

        update_option( $this->state_key(), $state, false );

        return $state;
    }

    /**
     * Cancel the running backup.
     */
    public function cancel() {
        $state = get_option( $this->state_key() );

        if ( $state ) {
            $this->db_exporter->cancel();
            $this->file_archiver->cancel();

            // Delete backup directory
            if ( isset( $state['backup_id'] ) ) {
                $this->storage->delete_backup( $state['backup_id'] );
            }

            $this->log( 'Backup abgebrochen: ' . ( $state['backup_id'] ?? 'unknown' ) );
        }

        delete_option( $this->state_key() );

        return [ 'success' => true, 'message' => 'Backup abgebrochen.' ];
    }

    /**
     * Get current backup status.
     */
    public function get_status() {
        $state = get_option( $this->state_key() );

        if ( ! $state ) {
            return [
                'running'  => false,
                'phase'    => 'idle',
                'progress' => 0,
                'message'  => 'Kein Backup aktiv.',
            ];
        }

        return [
            'running'  => $state['phase'] !== self::PHASE_DONE,
            'phase'    => $state['phase'],
            'progress' => round( $state['progress'], 1 ),
            'message'  => $state['message'],
            'errors'   => $state['errors'] ?? [],
            'backup_id' => $state['backup_id'],
            'type'     => $state['type'],
            'elapsed'  => time() - ( $state['started_at'] ?? time() ),
            'storage_results' => $state['storage_results'] ?? null,
        ];
    }

    /**
     * Run a full backup (used by cron / CLI).
     */
    public function run_full_backup() {
        $this->start( 'full' );

        $max_iterations = 10000; // Safety limit
        $i = 0;

        while ( $i < $max_iterations ) {
            $state = $this->process_next();

            if ( isset( $state['error'] ) && $state['error'] === true ) {
                break;
            }

            if ( isset( $state['phase'] ) && $state['phase'] === self::PHASE_DONE ) {
                break;
            }

            $i++;

            // Give the server a tiny break
            usleep( 50000 ); // 50ms
        }

        return $this->get_status();
    }

    /**
     * Simple log writer.
     */
    private function log( $message ) {
        $timestamp = date( 'Y-m-d H:i:s' );
        $line      = "[{$timestamp}] {$message}\n";

        if ( ! file_exists( WPRB_BACKUP_DIR ) ) {
            wp_mkdir_p( WPRB_BACKUP_DIR );
        }

        file_put_contents( WPRB_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }
}
