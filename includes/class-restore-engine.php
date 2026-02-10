<?php
/**
 * Restore Engine
 * 
 * Restores backups in chunks:
 * - SQL file: executed statement by statement
 * - Archive files: extracted in batches
 * 
 * Creates a safety snapshot before restoring.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Restore_Engine {

    const PHASE_INIT       = 'init';
    const PHASE_DOWNLOAD   = 'download';
    const PHASE_SNAPSHOT   = 'snapshot';
    const PHASE_DECRYPT    = 'decrypt';
    const PHASE_DB         = 'database';
    const PHASE_FILES      = 'files';
    const PHASE_DONE       = 'done';

    private function state_key() {
        return 'wprb_restore_state';
    }

    /**
     * Analyze a backup before restoring.
     * Returns info about what will be restored.
     */
    public function analyze( $backup_id ) {
        $backup_dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id ) . '/';

        if ( ! is_dir( $backup_dir ) ) {
            return [ 'error' => true, 'message' => 'Backup nicht gefunden.' ];
        }

        $meta_file = $backup_dir . 'backup-meta.json';
        $meta      = file_exists( $meta_file )
            ? json_decode( file_get_contents( $meta_file ), true )
            : [];

        $is_local_deleted = ! empty( $meta['local_deleted'] );
        
        $has_db    = file_exists( $backup_dir . 'database.sql' ) || file_exists( $backup_dir . 'database.sql.enc' );
        // Also check if cloud file list has it
        if ( ! $has_db && $is_local_deleted && in_array( 'database.sql', $meta['files'] ?? [] ) ) {
             $has_db = true;
        } elseif ( ! $has_db && $is_local_deleted && in_array( 'database.sql.enc', $meta['files'] ?? [] ) ) {
             $has_db = true;
        }

        $db_size = 0;
        if ( file_exists( $backup_dir . 'database.sql' ) ) {
            $db_size = filesize( $backup_dir . 'database.sql' );
        } elseif ( file_exists( $backup_dir . 'database.sql.enc' ) ) {
            $db_size = filesize( $backup_dir . 'database.sql.enc' );
        }

        // Find archive files
        $archives    = [];
        $total_asize = 0;
        
        if ( $is_local_deleted && ! empty( $meta['files'] ) ) {
            foreach ( $meta['files'] as $f ) {
                if ( strpos( $f, 'files-part' ) === 0 ) {
                    $archives[] = [ 'name' => $f, 'size' => 'Cloud' ];
                }
            }
        } else {
            foreach ( glob( $backup_dir . 'files-part*' ) as $archive ) {
                $ext = pathinfo( $archive, PATHINFO_EXTENSION );
                $size = filesize( $archive );
                $archives[] = [
                    'name' => basename( $archive ),
                    'size' => size_format( $size ) . ($ext === 'enc' ? ' 🔒' : ''),
                ];
                $total_asize += $size;
            }
        }

        return [
            'backup_id'    => $backup_id,
            'date'         => $meta['date'] ?? 'Unbekannt',
            'type'         => $meta['type'] ?? 'full',
            'site_url'     => $meta['site_url'] ?? 'Unbekannt',
            'wp_version'   => $meta['wp_version'] ?? 'Unbekannt',
            'has_db'       => $has_db,
            'db_size'      => $db_size ? size_format( $db_size ) : 'Cloud',
            'archives'     => $archives,
            'archive_size' => $total_asize ? size_format( $total_asize ) : 'Cloud',
            'has_files'    => ! empty( $archives ),
            'local_deleted' => $is_local_deleted,
        ];
    }

    /**
     * Get list of files in the backup archives.
     */
    public function get_file_list( $backup_id ) {
        $backup_dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id ) . '/';
        
        // Check for pre-generated manifest (ZIP format - New)
        $manifest_zip = $backup_dir . 'file_manifest.zip';
        if ( file_exists( $manifest_zip ) && class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $manifest_zip ) === true ) {
                $content = $zip->getFromName( 'file_manifest.json' );
                $zip->close();
                if ( $content ) {
                    $data = json_decode( $content, true );
                    if ( is_array( $data ) ) {
                        return array_map( function($f) { return ltrim( $f, '/' ); }, $data );
                    }
                }
            }
        }

        // Check for pre-generated manifest (GZ format - Old)
        $manifest_gz = $backup_dir . 'file_manifest.json.gz';
        if ( file_exists( $manifest_gz ) ) {
             $content = gzdecode( file_get_contents( $manifest_gz ) );
             $data    = json_decode( $content, true );
             if ( is_array( $data ) ) {
                 return array_map( function($f) { return ltrim( $f, '/' ); }, $data );
             }
        }

        $archives   = glob( $backup_dir . 'files-part*' );
        $files      = [];

        foreach ( $archives as $archive ) {
            $ext = pathinfo( $archive, PATHINFO_EXTENSION );
            if ( $ext === 'zip' && class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( $zip->open( $archive ) === true ) {
                    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                         $name = $zip->getNameIndex( $i );
                         // Skip directories if empty, but useful for tree
                         $files[] = $name;
                    }
                    $zip->close();
                }
            } elseif ( ( $ext === 'gz' || $this->is_tar_gz( $archive ) ) && function_exists( 'shell_exec' ) ) {
                $output = shell_exec( 'tar -tf ' . escapeshellarg( $archive ) );
                if ( $output ) {
                    $lines = explode( "\n", trim( $output ) );
                    foreach ( $lines as $line ) {
                        if ( ! empty( $line ) ) $files[] = $line;
                    }
                }
            }
        }
        return array_values( array_unique( $files ) );
    }

    /**
     * Start restore process.
     */
    public function start( $backup_id, $restore_db = true, $restore_files = true, $create_snapshot = true, $selected_files = [] ) {
        // Check if restore is already running
        $existing = get_option( $this->state_key() );
        if ( $existing && $existing['phase'] !== self::PHASE_DONE ) {
            return [
                'error'   => true,
                'message' => 'Es läuft bereits eine Wiederherstellung.',
            ];
        }

        $backup_dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id ) . '/';
        if ( ! is_dir( $backup_dir ) ) {
            return [ 'error' => true, 'message' => 'Backup nicht gefunden.' ];
        }

        $meta_file = $backup_dir . 'backup-meta.json';
        $meta      = file_exists( $meta_file ) ? json_decode( file_get_contents( $meta_file ), true ) : [];
        $is_local_deleted = ! empty( $meta['local_deleted'] );

        // Initial check, skipped if cloud backup
        $local_archives = [];
        $local_db_size  = 0;

        if ( ! $is_local_deleted ) {
            $has_db    = file_exists( $backup_dir . 'database.sql' );
            $local_db_size = $has_db ? filesize( $backup_dir . 'database.sql' ) : 0;

            $raw_archives  = glob( $backup_dir . 'files-part*' );
            $local_archives = ! empty( $raw_archives ) ? array_map( 'basename', $raw_archives ) : [];
            $has_files = ! empty( $local_archives );

            if ( $restore_db && ! $has_db ) {
                return [ 'error' => true, 'message' => 'Kein Datenbank-Dump in diesem Backup gefunden.' ];
            }
            if ( $restore_files && ! $has_files ) {
                return [ 'error' => true, 'message' => 'Keine Datei-Archive in diesem Backup gefunden.' ];
            }
        }

        // Determine initial phase
        $start_phase = $create_snapshot ? self::PHASE_SNAPSHOT : self::PHASE_INIT;
        $message     = $create_snapshot 
            ? 'Erstelle Sicherheitskopie vor der Wiederherstellung...' 
            : 'Wiederherstellung wird vorbereitet...';

        if ( $is_local_deleted ) {
            $start_phase = self::PHASE_DOWNLOAD;
            $message     = 'Lade Backup aus der Cloud (Dropbox)...';
        }

        $state = [
            'backup_id'       => $backup_id,
            'backup_dir'      => $backup_dir,
            'phase'           => $start_phase,
            'restore_db'      => $restore_db,
            'restore_files'   => $restore_files,
            'create_snapshot' => $create_snapshot,
            'selected_files'  => $selected_files, // Selective Restore
            'progress'        => 0,
            'message'         => $message,
            'started_at'      => time(),
            'errors'          => [],
            // DB restore state
            'db_file'         => $backup_dir . 'database.sql',
            'db_offset'       => 0,
            'db_size'         => $local_db_size, // Updated
            'db_statements'   => 0,
            // File restore state
            'archives'        => $local_archives, // Updated
            'current_archive' => 0,
            'files_extracted' => 0,
            // Snapshot
            'snapshot_id'     => null,
            'snapshot_phase'  => 'pending',
            // Cloud
            'download_attempts' => 0,
        ];

        update_option( $this->state_key(), $state, false );
        $this->log( 'Wiederherstellung gestartet: ' . $backup_id . ( ! empty($selected_files) ? ' (Selektiv)' : '' ) );

        return $state;
    }

    /**
     * Process next restore step.
     */
    public function process_next() {
        $state = get_option( $this->state_key() );

        if ( ! $state ) {
            return [ 'error' => true, 'message' => 'Keine aktive Wiederherstellung gefunden.' ];
        }

        switch ( $state['phase'] ) {

            case self::PHASE_DOWNLOAD:
                $storage = new WPRB_Storage_Manager();
                $result  = $storage->download_backup_from_cloud( $state['backup_id'] );

                if ( $result['success'] ) {
                    // Update state with newly downloaded file info
                    $backup_dir = $state['backup_dir'];
                    $has_db     = file_exists( $backup_dir . 'database.sql' );
                    $archives   = glob( $backup_dir . 'files-part*' );

                    $state['db_size']  = $has_db ? filesize( $backup_dir . 'database.sql' ) : 0;
                    $state['archives'] = ! empty( $archives ) ? array_map( 'basename', $archives ) : [];
                    
                    // Proceed to next phase
                    $state['message'] = 'Download erfolgreich. Starte Wiederherstellung...';
                    $state['phase']   = $state['create_snapshot'] ? self::PHASE_SNAPSHOT : self::PHASE_INIT;
                } else {
                    $state['errors'][] = 'Download fehlgeschlagen: ' . $result['message'];
                    $state['phase']    = self::PHASE_DONE;
                }
                
                // For download, we assume it's one atomic step for now (might time out on huge files, but retry logic is complex)
                $result = $state;
                break;

            case self::PHASE_SNAPSHOT:
                $result = $this->process_snapshot( $state );
                break;

            case self::PHASE_INIT:
                // Check if decryption is needed first
                $backup_dir = $state['backup_dir'];
                $has_enc_files = ! empty( glob( $backup_dir . '*.enc' ) );
                
                if ( $has_enc_files && empty( $state['decrypted'] ) ) {
                    $state['phase'] = self::PHASE_DECRYPT;
                    $state['message'] = 'Verschlüsselte Dateien gefunden. Starte Entschlüsselung...';
                    // Identify files to decrypt
                    $to_decrypt = glob( $backup_dir . '*.enc' );
                    $state['files_to_decrypt'] = $to_decrypt;
                    $state['decrypt_idx'] = 0;
                } elseif ( $state['restore_db'] ) {
                    // Update DB file location just in case it was decrypted
                    if ( file_exists( $backup_dir . 'database.sql' ) ) {
                        $state['db_file'] = $backup_dir . 'database.sql';
                        $state['db_size'] = filesize( $state['db_file'] );
                    }
                    
                    $state['phase']   = self::PHASE_DB;
                    $state['message'] = 'Starte Datenbank-Wiederherstellung...';
                } elseif ( $state['restore_files'] ) {
                     // Update archives list
                    $raw_archives  = glob( $backup_dir . 'files-part*' );
                    // Filter out .enc if .tar.gz exists? 
                    // Actually we should only use non-enc files for restore
                    $ready_archives = [];
                    foreach ( $raw_archives as $arc ) {
                        if ( substr( $arc, -4 ) !== '.enc' ) {
                            $ready_archives[] = basename( $arc );
                        }
                    }
                    $state['archives'] = $ready_archives;

                    $state['phase']   = self::PHASE_FILES;
                    $state['message'] = 'Starte Datei-Wiederherstellung...';
                } else {
                    $state['phase'] = self::PHASE_DONE;
                }
                $result = $state;
                break;

            case self::PHASE_DECRYPT:
                $files = $state['files_to_decrypt'] ?? [];
                $idx   = $state['decrypt_idx'] ?? 0;
                
                if ( $idx >= count( $files ) ) {
                    $state['decrypted'] = true;
                    $state['phase'] = self::PHASE_INIT; // Go back to Init to route to DB/Files
                    $state['progress'] = 0;
                    $state['message'] = 'Entschlüsselung abgeschlossen.';
                    unset( $state['files_to_decrypt'], $state['decrypt_idx'] );
                } else {
                    $enc_file = $files[ $idx ];
                    $base_name = basename( $enc_file, '.enc' );
                    $dest_file = dirname( $enc_file ) . '/' . $base_name;
                    
                    // Only decrypt if dest doesn't exist (resume support)
                    if ( ! file_exists( $dest_file ) ) {
                        $pass = get_option( 'wprb_encryption_key' );
                        if ( empty( $pass ) ) {
                            $state['errors'][] = 'Passwort fehlt für Entschlüsselung von ' . basename($enc_file);
                            $state['phase'] = self::PHASE_DONE;
                            break;
                        }
                        
                        $res = WPRB_Crypto::decrypt_file( $enc_file, $dest_file, $pass );
                        if ( isset( $res['error'] ) ) {
                             $state['errors'][] = 'Entschlüsselung fehlgeschlagen (' . basename($enc_file) . '): ' . $res['error'];
                             // Critical error, stop?
                             $state['phase'] = self::PHASE_DONE;
                             break;
                        } else {
                            $this->log( 'Datei entschlüsselt: ' . basename( $dest_file ) );
                            $temp_files = $state['temp_decrypted_files'] ?? [];
                            $temp_files[] = $dest_file;
                            $state['temp_decrypted_files'] = $temp_files; // Track for cleanup
                        }
                    }
                    
                    $state['decrypt_idx']++;
                    $state['progress'] = round( ( $state['decrypt_idx'] / count( $files ) ) * 100 );
                    $state['message'] = 'Entschlüssle ' . basename( $enc_file ) . '...';
                }
                $result = $state;
                break;

            case self::PHASE_DB:
                $result = $this->process_db_restore( $state );
                break;

            case self::PHASE_FILES:
                $result = $this->process_file_restore( $state );
                break;

            case self::PHASE_DONE:
                // Cleanup temp decrypted files
                if ( ! empty( $state['temp_decrypted_files'] ) ) {
                    foreach ( $state['temp_decrypted_files'] as $f ) {
                        if ( file_exists( $f ) ) {
                            unlink( $f );
                        }
                    }
                    unset( $state['temp_decrypted_files'] );
                }
                $result = $state;
                break;

            default:
                $result = $state;
        }

        update_option( $this->state_key(), $result, false );
        return $result;
    }

    /**
     * Create a safety snapshot (quick DB dump) before restore.
     */
    private function process_snapshot( &$state ) {
        if ( $state['snapshot_phase'] === 'pending' ) {
            // Start a quick DB-only backup as snapshot
            $snapshot_id = 'pre-restore-' . date( 'Y-m-d-His' );
            $state['snapshot_id']    = $snapshot_id;
            $state['snapshot_phase'] = 'running';

            $exporter = new WPRB_DB_Exporter();
            $exporter->init_export( $snapshot_id );

            $state['message']  = 'Erstelle Sicherheitskopie der Datenbank...';
            $state['progress'] = 2;

            return $state;
        }

        if ( $state['snapshot_phase'] === 'running' ) {
            $exporter = new WPRB_DB_Exporter();
            $result   = $exporter->process_chunk();

            if ( isset( $result['error'] ) ) {
                // Snapshot failed - warn but continue
                $state['errors'][]      = 'Sicherheitskopie fehlgeschlagen: ' . $result['error'];
                $state['snapshot_phase'] = 'done';
                $state['phase']          = self::PHASE_INIT;
                $state['message']        = 'Sicherheitskopie fehlgeschlagen, fahre fort...';
                return $state;
            }

            $state['progress'] = $result['progress'] * 0.05; // Snapshot = 0-5% of total
            $state['message']  = 'Sicherheitskopie: ' . ( $result['message'] ?? '' );

            if ( $result['done'] ) {
                // Save snapshot meta
                $snapshot_dir = WPRB_BACKUP_DIR . $state['snapshot_id'] . '/';
                $meta = [
                    'date'      => date( 'Y-m-d H:i:s' ),
                    'type'      => 'pre_restore_snapshot',
                    'site_url'  => get_site_url(),
                    'wp_version' => get_bloginfo( 'version' ),
                    'note'      => 'Automatische Sicherheitskopie vor Wiederherstellung von ' . $state['backup_id'],
                ];
                file_put_contents(
                    $snapshot_dir . 'backup-meta.json',
                    wp_json_encode( $meta, JSON_PRETTY_PRINT )
                );

                $state['snapshot_phase'] = 'done';
                $state['phase']          = self::PHASE_INIT;
                $state['progress']       = 5;
                $state['message']        = 'Sicherheitskopie erstellt. Starte Wiederherstellung...';

                $this->log( 'Sicherheitskopie erstellt: ' . $state['snapshot_id'] );
            }
        }

        return $state;
    }

    /**
     * Restore database from SQL dump in chunks.
     * Reads a batch of SQL statements per call.
     */
    private function process_db_restore( &$state ) {
        global $wpdb;

        $sql_file = $state['db_file'];
        $offset   = $state['db_offset'];
        $filesize = $state['db_size'];

        if ( ! file_exists( $sql_file ) ) {
            $state['errors'][] = 'SQL-Datei nicht gefunden.';
            $state['phase']    = $state['restore_files'] ? self::PHASE_FILES : self::PHASE_DONE;
            return $state;
        }

        $fh = fopen( $sql_file, 'r' );
        if ( ! $fh ) {
            $state['errors'][] = 'SQL-Datei konnte nicht geöffnet werden.';
            $state['phase']    = $state['restore_files'] ? self::PHASE_FILES : self::PHASE_DONE;
            return $state;
        }

        // Seek to last position
        fseek( $fh, $offset );

        $batch_limit  = 50; // Statements per batch
        $executed     = 0;
        $statement    = '';
        $in_comment   = false;

        // Disable foreign key checks during restore
        if ( $offset === 0 ) {
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
            $wpdb->query( 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"' );
            $wpdb->query( 'SET NAMES utf8mb4' );
        }

        while ( ! feof( $fh ) && $executed < $batch_limit ) {
            $line = fgets( $fh );

            if ( $line === false ) {
                break;
            }

            $trimmed = trim( $line );

            // Skip comments and empty lines
            if ( empty( $trimmed ) || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '/*!' ) === 0 ) {
                // Handle /*!...*/ style comments that are actually MySQL commands
                if ( strpos( $trimmed, '/*!' ) === 0 && substr( $trimmed, -2 ) === '*/' ) {
                    // This is a conditional comment / MySQL command - skip
                }
                continue;
            }

            $statement .= $line;

            // Check if statement is complete (ends with semicolon)
            if ( preg_match( '/;\s*$/', $trimmed ) ) {
                $statement = trim( $statement );

                // Skip transaction control statements (we handle them ourselves)
                $upper = strtoupper( substr( $statement, 0, 20 ) );
                if ( strpos( $upper, 'START TRANSACTION' ) !== false ||
                     strpos( $upper, 'COMMIT' ) !== false ||
                     strpos( $upper, 'SET AUTOCOMMIT' ) !== false ) {
                    $statement = '';
                    continue;
                }

                // Execute the statement
                if ( ! empty( $statement ) && $statement !== ';' ) {
                    $result = $wpdb->query( $statement );

                    if ( $result === false && ! empty( $wpdb->last_error ) ) {
                        // Log error but continue (some errors are expected, e.g. IF EXISTS)
                        $error_msg = substr( $wpdb->last_error, 0, 200 );
                        // Only log serious errors, skip routine ones
                        if ( strpos( $error_msg, 'already exists' ) === false &&
                             strpos( $error_msg, "doesn't exist" ) === false ) {
                            $state['errors'][] = 'SQL: ' . $error_msg;
                        }
                    }

                    $executed++;
                    $state['db_statements']++;
                }

                $statement = '';
            }
        }

        $new_offset = ftell( $fh );
        $is_eof     = feof( $fh );
        fclose( $fh );

        $state['db_offset'] = $new_offset;

        // Calculate progress
        // DB restore: 5-55% (or 5-95% if no files)
        $db_progress = $filesize > 0 ? ( $new_offset / $filesize ) : 1;

        if ( $state['restore_files'] ) {
            $state['progress'] = 5 + ( $db_progress * 50 );
        } else {
            $state['progress'] = 5 + ( $db_progress * 90 );
        }

        $state['message'] = sprintf(
            'Datenbank: %s / %s (%s Anweisungen ausgeführt)',
            size_format( $new_offset ),
            size_format( $filesize ),
            number_format_i18n( $state['db_statements'] )
        );

        if ( $is_eof || $new_offset >= $filesize ) {
            // Re-enable foreign key checks
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

            $this->log( sprintf(
                'DB-Wiederherstellung abgeschlossen: %s Anweisungen',
                number_format_i18n( $state['db_statements'] )
            ) );

            if ( $state['restore_files'] ) {
                $state['phase']   = self::PHASE_FILES;
                $state['message'] = 'Datenbank wiederhergestellt. Starte Datei-Wiederherstellung...';
            } else {
                $state['phase']    = self::PHASE_DONE;
                $state['progress'] = 100;
                $state['message']  = 'Wiederherstellung abgeschlossen!';
            }
        }

        return $state;
    }

    /**
     * Restore files from archives.
     */

    private function process_file_restore( &$state ) {
        $backup_dir      = $state['backup_dir'];
        $archives        = $state['archives'];
        $current_idx     = $state['current_archive'];
        $selected_files  = $state['selected_files'] ?? [];

        if ( $current_idx >= count( $archives ) ) {
            $state['phase']    = self::PHASE_DONE;
            $state['progress'] = 100;
            $state['message']  = 'Wiederherstellung abgeschlossen!';

            $this->log( 'Datei-Wiederherstellung abgeschlossen.' );
            return $state;
        }

        $archive_file = $backup_dir . $archives[ $current_idx ];
        $archive_name = $archives[ $current_idx ];

        if ( ! file_exists( $archive_file ) ) {
            $state['errors'][] = 'Archiv nicht gefunden: ' . $archive_name;
            $state['current_archive']++;
            return $state;
        }

        // Determine archive type and extract
        $ext = pathinfo( $archive_file, PATHINFO_EXTENSION );

        $restore_root = ABSPATH;

        if ( $ext === 'gz' || $this->is_tar_gz( $archive_file ) ) {
            $result = $this->extract_tar_gz( $archive_file, $restore_root, $selected_files );
        } elseif ( $ext === 'zip' ) {
            $result = $this->extract_zip( $archive_file, $restore_root, $selected_files );
        } else {
            $result = [ 'error' => 'Unbekanntes Archiv-Format: ' . $ext ];
        }

        if ( isset( $result['error'] ) ) {
            $state['errors'][] = $archive_name . ': ' . $result['error'];
        } else {
            $state['files_extracted'] += $result['count'] ?? 0;
        }

        $state['current_archive']++;

        // Progress: files = 55-95% (or 5-95% if no DB)
        $file_progress = count( $archives ) > 0
            ? ( $state['current_archive'] / count( $archives ) )
            : 1;

        if ( $state['restore_db'] ) {
            $state['progress'] = 55 + ( $file_progress * 40 );
        } else {
            $state['progress'] = 5 + ( $file_progress * 90 );
        }

        $msg_extra = ! empty( $selected_files ) ? ' (Selektiv)' : '';
        $state['message'] = sprintf(
            'Dateien%s: Archiv %d/%d (%s)',
            $msg_extra,
            $state['current_archive'],
            count( $archives ),
            $archive_name
        );

        // Check if all archives are done
        if ( $state['current_archive'] >= count( $archives ) ) {
            $state['phase']    = self::PHASE_DONE;
            $state['progress'] = 100;
            $state['message']  = 'Wiederherstellung abgeschlossen!';

            $this->log( 'Datei-Wiederherstellung abgeschlossen.' );
        }

        return $state;
    }

    /**
     * Extract a .tar.gz file.
     */
    private function extract_tar_gz( $archive, $destination, $selected_files = [] ) {
        // Prefer system tar
        if ( function_exists( 'exec' ) ) {
            $use_dest = $destination;
            $test_path = '';

            if ( ! empty( $selected_files ) ) {
                $test_path = reset( $selected_files );
            } else {
                // For full restore, peek into the archive to guess path structure
                $peek = shell_exec( 'tar -tf ' . escapeshellarg( $archive ) . ' | head -n 1' );
                if ( $peek ) {
                    $test_path = trim( $peek );
                }
            }

            if ( $test_path ) {
                 // Clean up absolute path (remove leading slash if present, though tar list usually doesn't have it)
                 $abs_no_slash = ltrim( ABSPATH, '/' );
                 
                 // If the file path starts with the structure of ABSPATH, assume it's an absolute path archive
                 // e.g. Archive: "Users/fabian/wp/index.php", ABSPATH: "/Users/fabian/wp/"
                 if ( ! empty( $abs_no_slash ) && strpos( $test_path, $abs_no_slash ) === 0 ) {
                     // DEBUG: Do not force root for testing subdir restore
                     // $use_dest = '/';
                     // Using relative extraction to test dir
                 }
            }

            $cmd_base = sprintf(
                'tar -xzf %s -C %s',
                escapeshellarg( $archive ),
                escapeshellarg( $use_dest )
            );

            $tmp_list = null;
            if ( ! empty( $selected_files ) ) {
                $tmp_list = tempnam( sys_get_temp_dir(), 'wprb_restore_' );
                file_put_contents( $tmp_list, implode( "\n", $selected_files ) );
                $cmd_base .= ' -T ' . escapeshellarg( $tmp_list );
            }

            $cmd = $cmd_base . ' 2>&1';
            
            // DEBUG: Log used command
            $this->log( 'TAR Command: ' . $cmd );
            
            exec( $cmd, $output, $return_code );
            
            // DEBUG: Log command output
            $this->log( 'TAR Output (' . $return_code . '): ' . implode( ' | ', $output ) );

            if ( $tmp_list && file_exists( $tmp_list ) ) unlink( $tmp_list );

            if ( $return_code === 0 ) {
                // Count (approx)
                return [ 'success' => true, 'count' => 1 ];
            }

            return [ 'error' => 'tar fehlgeschlagen (' . $return_code . '): ' . implode( "\n", $output ) ];
        }

        // Fallback: PharData
        if ( class_exists( 'PharData' ) ) {
            try {
                $phar = new PharData( $archive );
                $files_to_extract = ! empty( $selected_files ) ? $selected_files : null;
                $phar->extractTo( $destination, $files_to_extract, true );
                return [ 'success' => true, 'count' => 1 ];
            } catch ( Exception $e ) {
                return [ 'error' => 'PharData: ' . $e->getMessage() ];
            }
        }

        return [ 'error' => 'Weder tar noch PharData verfügbar.' ];
    }

    /**
     * Extract a .zip file.
     */
    private function extract_zip( $archive, $destination, $selected_files = [] ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'error' => 'ZipArchive nicht verfügbar.' ];
        }

        $zip = new ZipArchive();
        $result = $zip->open( $archive );

        if ( $result !== true ) {
            return [ 'error' => 'ZIP konnte nicht geöffnet werden: Code ' . $result ];
        }

        $count = $zip->numFiles;
        $entries = ! empty( $selected_files ) ? $selected_files : null;
        
        $success = $zip->extractTo( $destination, $entries );
        $zip->close();

        if ( $success ) {
            return [ 'success' => true, 'count' => $entries ? count($entries) : $count ];
        } else {
            return [ 'error' => 'ZIP ExtractTo schlug fehl' ];
        }
    }

    /**
     * Check if a file is a tar.gz regardless of extension.
     */
    private function is_tar_gz( $file ) {
        $fh = fopen( $file, 'rb' );
        if ( ! $fh ) return false;
        $bytes = fread( $fh, 2 );
        fclose( $fh );
        // Gzip magic number: 1f 8b
        return $bytes === "\x1f\x8b";
    }

    /**
     * Cancel running restore.
     */
    public function cancel() {
        $state = get_option( $this->state_key() );

        if ( $state ) {
            $this->log( 'Wiederherstellung abgebrochen.' );
            
            // Cleanup temp decrypted files
            if ( ! empty( $state['temp_decrypted_files'] ) ) {
                foreach ( $state['temp_decrypted_files'] as $f ) {
                    if ( file_exists( $f ) ) {
                        unlink( $f );
                    }
                }
            }
        }
        
        delete_option( $this->state_key() );

        return [ 'success' => true, 'message' => 'Wiederherstellung abgebrochen.' ];
    }

    /**
     * Get current restore status.
     */
    public function get_status() {
        $state = get_option( $this->state_key() );

        if ( ! $state ) {
            return [
                'running'  => false,
                'phase'    => 'idle',
                'progress' => 0,
                'message'  => 'Keine Wiederherstellung aktiv.',
            ];
        }

        return [
            'running'     => $state['phase'] !== self::PHASE_DONE,
            'phase'       => $state['phase'],
            'progress'    => round( $state['progress'] ?? 0, 1 ),
            'message'     => $state['message'] ?? '',
            'errors'      => $state['errors'] ?? [],
            'backup_id'   => $state['backup_id'],
            'snapshot_id' => $state['snapshot_id'] ?? null,
            'elapsed'     => time() - ( $state['started_at'] ?? time() ),
        ];
    }

    /**
     * Simple log writer.
     */

    private function log( $message ) {
        WPRB_Logger::log( $message, 'RESTORE' );
    }
}
