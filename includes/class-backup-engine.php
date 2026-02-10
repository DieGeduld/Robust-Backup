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
    public function start( $type = 'full', $storage_override = [] ) {
        // Check if another backup is running
        $existing = get_option( $this->state_key() );
        if ( $existing && $existing['phase'] !== self::PHASE_DONE ) {
            return [
                'error'   => true,
                'message' => 'Es läuft bereits ein Backup. Bitte warten oder das laufende Backup abbrechen.',
            ];
        }

        $backup_id = 'backup-' . wp_date( 'Y-m-d-His' );
        wp_mkdir_p( WPRB_BACKUP_DIR . $backup_id );

        $state = [
            'backup_id'        => $backup_id,
            'type'             => $type,
            'phase'            => self::PHASE_INIT,
            'progress'         => 0,
            'message'          => 'Backup wird vorbereitet...',
            'started_at'       => time(),
            'errors'           => [],
            'all_files'        => [],
            'storage_override' => $storage_override,
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
                    $this->log( 'DB-Export Fehler: ' . $result['error'], 'ERROR' );
                    $state['phase'] = self::PHASE_DONE;
                    break;
                }

                $this->log( 'DB-Chunk verarbeitet. Fortschritt: ' . $result['progress'] . '%', 'DEBUG' );

                // Progress 0-100% for this phase
                $state['progress'] = $result['progress'];
                $state['message'] = $result['message'];

                if ( $result['done'] ) {
                    $sql_file = WPRB_BACKUP_DIR . $backup_id . '/database.sql';
                    if ( file_exists( $sql_file ) ) {
                        $state['all_files'][] = $sql_file;
                    }

                    if ( $type === 'full' ) {
                        $this->file_archiver->init_archive( $backup_id );
                        $state['phase'] = self::PHASE_FILES;
                        $state['progress'] = 0; // Reset for next phase
                        $state['message'] = 'Datenbank fertig. Starte Datei-Archivierung...';
                    } else {
                        $state['phase'] = self::PHASE_UPLOAD;
                        $state['progress'] = 0; // Reset for next phase
                        $state['message'] = 'Datenbank fertig. Starte Upload...';
                    }

                    $this->log( 'DB-Export abgeschlossen für ' . $backup_id );
                }
                break;

            case self::PHASE_FILES:
                $result = $this->file_archiver->process_batch();

                if ( isset( $result['error'] ) ) {
                    $state['errors'][] = $result['error'];
                    $this->log( 'Datei-Archivierung Fehler: ' . $result['error'], 'ERROR' );
                    $state['phase'] = self::PHASE_DONE;
                    break;
                }

                $this->log( 'Datei-Batch verarbeitet. Fortschritt: ' . $result['progress'] . '%', 'DEBUG' );

                // Progress 0-100% for this phase
                $state['progress'] = $result['progress'];
                $state['message'] = $result['message'];

                if ( $result['done'] ) {
                    if ( ! empty( $result['archive_files'] ) ) {
                        $state['all_files'] = array_merge( $state['all_files'], $result['archive_files'] );
                    }

                    $state['phase']   = self::PHASE_COMPRESS;
                    $state['progress'] = 0; 
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
                $state['progress']  = 0;
                $state['message']   = 'Komprimierung fertig. Starte Upload...';
                break;

            case self::PHASE_UPLOAD:
                // ... (Metadata logic remains same) ...
                if ( ! file_exists( WPRB_BACKUP_DIR . $backup_id . '/backup-meta.json' ) ) {
                     // ... (omitted for brevity in replacement, but kept in logic) ...
                     // Actually I need to include it or it will be cut. Let's keep existing meta logic block below intact by targeting surrounding lines or re-adding it.
                     // Since I'm replacing the whole block, I need to include the meta generation or ensure the start/end lines don't cover it if I don't want to change it.
                     // The requirement is to change progress calculation.
                }

                // Create metadata if not exists
                if ( ! file_exists( WPRB_BACKUP_DIR . $backup_id . '/backup-meta.json' ) ) {
                    $files_with_info = [];
                    $total_size = 0;
                    foreach ( $state['all_files'] as $f ) {
                        $s = filesize( $f );
                        $files_with_info[] = [
                            'name' => basename( $f ),
                            'size' => $s,
                        ];
                        $total_size += $s;
                    }

                    $active_storage = ! empty( $state['storage_override'] ) ? $state['storage_override'] : (array) get_option( 'wprb_storage', [ 'local' ] );

                    $meta = [
                        'date'         => wp_date( 'Y-m-d H:i:s' ),
                        'type'         => $type,
                        'wp_version'   => get_bloginfo( 'version' ),
                        'site_url'     => get_site_url(),
                        'files'        => array_column( $files_with_info, 'name' ),
                        'file_details' => $files_with_info,
                        'total_size'   => $total_size,
                        'cloud_only'   => false,
                        'storages'     => $active_storage,
                    ];
                    file_put_contents(
                        WPRB_BACKUP_DIR . $backup_id . '/backup-meta.json',
                        wp_json_encode( $meta, JSON_PRETTY_PRINT )
                    );
                }

                $storage_override = ! empty( $state['storage_override'] ) ? $state['storage_override'] : null;
                $dist_result = $this->storage->process_upload_step( $backup_id, $state['all_files'], $state['upload_state'] ?? [], $storage_override );
                
                $state['upload_state'] = $dist_result['state'];
                
                if ( isset( $dist_result['progress'] ) ) {
                    // Progress 0-100% for upload phase
                    $state['progress'] = $dist_result['progress'];
                }

                if ( isset( $dist_result['stats'] ) ) {
                    $state['upload_stats'] = $dist_result['stats'];
                }
                
                $state['message'] = $dist_result['message'];

                if ( $dist_result['done'] ) {
                    $results = $dist_result['results'];
                    $upload_errors = false;
                    
                    // Clear stats on done
                    unset( $state['upload_stats'] );
                    // ... (rest of done logic)


                    foreach ( $results as $storage => $res ) {
                        if ( ! $res['success'] ) {
                            $state['errors'][] = $storage . ': ' . $res['message'];
                            $upload_errors = true;
                            $this->log( $storage . ': ' . $res['message'], 'ERROR' );
                        } else {
                            $this->log( $storage . ': ' . $res['message'], 'INFO' );
                        }
                    }

                    // cleanup
                    $active_storage = ! empty( $state['storage_override'] ) ? $state['storage_override'] : (array) get_option( 'wprb_storage', [ 'local' ] );
                    if ( ! in_array( 'local', $active_storage ) && ! $upload_errors ) {
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

                    $this->storage->enforce_retention();

                    $state['phase']           = self::PHASE_DONE;
                    $state['progress']        = 100;
                    $state['message']         = 'Backup abgeschlossen!';
                    $state['storage_results'] = $results;
                    $this->log( 'Backup abgeschlossen: ' . $backup_id );
                }
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
            'type'            => $state['type'],
            'elapsed'         => time() - ( $state['started_at'] ?? time() ),
            'storage_results' => $state['storage_results'] ?? null,
            'upload_stats'    => $state['upload_stats'] ?? null,
        ];
    }

    /**
     * Run a full backup (used by cron / CLI).
     * 
     * @param string $type Optional. Backup type ('full', 'db_only', 'files_only'). Default 'full'.
     */
    public function run_full_backup( $type = 'full' ) {
        $this->start( $type );

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
     * Simple log writer with levels.
     * 
     * @param string $message
     * @param string $level 'INFO', 'ERROR', 'DEBUG'
     */
    private function log( $message, $level = 'INFO' ) {
        WPRB_Logger::log( $message, $level );
    }
}
