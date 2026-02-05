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
    const PHASE_SNAPSHOT   = 'snapshot';
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

        $has_db    = file_exists( $backup_dir . 'database.sql' );
        $db_size   = $has_db ? filesize( $backup_dir . 'database.sql' ) : 0;

        // Find archive files
        $archives    = [];
        $total_asize = 0;
        foreach ( glob( $backup_dir . 'files-part*' ) as $archive ) {
            $size = filesize( $archive );
            $archives[] = [
                'name' => basename( $archive ),
                'size' => size_format( $size ),
            ];
            $total_asize += $size;
        }

        return [
            'backup_id'    => $backup_id,
            'date'         => $meta['date'] ?? 'Unbekannt',
            'type'         => $meta['type'] ?? 'full',
            'site_url'     => $meta['site_url'] ?? 'Unbekannt',
            'wp_version'   => $meta['wp_version'] ?? 'Unbekannt',
            'has_db'       => $has_db,
            'db_size'      => size_format( $db_size ),
            'archives'     => $archives,
            'archive_size' => size_format( $total_asize ),
            'has_files'    => ! empty( $archives ),
        ];
    }

    /**
     * Start restore process.
     *
     * @param string $backup_id    The backup to restore.
     * @param bool   $restore_db   Whether to restore database.
     * @param bool   $restore_files Whether to restore files.
     * @param bool   $create_snapshot Whether to create a safety backup first.
     * @return array
     */
    public function start( $backup_id, $restore_db = true, $restore_files = true, $create_snapshot = true ) {
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

        // Validate what we can restore
        $has_db    = file_exists( $backup_dir . 'database.sql' );
        $archives  = glob( $backup_dir . 'files-part*' );
        $has_files = ! empty( $archives );

        if ( $restore_db && ! $has_db ) {
            return [ 'error' => true, 'message' => 'Kein Datenbank-Dump in diesem Backup gefunden.' ];
        }

        if ( $restore_files && ! $has_files ) {
            return [ 'error' => true, 'message' => 'Keine Datei-Archive in diesem Backup gefunden.' ];
        }

        $state = [
            'backup_id'       => $backup_id,
            'backup_dir'      => $backup_dir,
            'phase'           => $create_snapshot ? self::PHASE_SNAPSHOT : self::PHASE_INIT,
            'restore_db'      => $restore_db,
            'restore_files'   => $restore_files,
            'create_snapshot' => $create_snapshot,
            'progress'        => 0,
            'message'         => $create_snapshot
                ? 'Erstelle Sicherheitskopie vor der Wiederherstellung...'
                : 'Wiederherstellung wird vorbereitet...',
            'started_at'      => time(),
            'errors'          => [],
            // DB restore state
            'db_file'         => $backup_dir . 'database.sql',
            'db_offset'       => 0,
            'db_size'         => $has_db ? filesize( $backup_dir . 'database.sql' ) : 0,
            'db_statements'   => 0,
            // File restore state
            'archives'        => $has_files ? array_map( 'basename', $archives ) : [],
            'current_archive' => 0,
            'files_extracted' => 0,
            // Snapshot
            'snapshot_id'     => null,
            'snapshot_phase'  => 'pending',
        ];

        update_option( $this->state_key(), $state, false );
        $this->log( 'Wiederherstellung gestartet: ' . $backup_id );

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

            case self::PHASE_SNAPSHOT:
                $result = $this->process_snapshot( $state );
                break;

            case self::PHASE_INIT:
                // Move to the first restore phase
                if ( $state['restore_db'] ) {
                    $state['phase']   = self::PHASE_DB;
                    $state['message'] = 'Starte Datenbank-Wiederherstellung...';
                } elseif ( $state['restore_files'] ) {
                    $state['phase']   = self::PHASE_FILES;
                    $state['message'] = 'Starte Datei-Wiederherstellung...';
                } else {
                    $state['phase'] = self::PHASE_DONE;
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

        if ( $ext === 'gz' || $this->is_tar_gz( $archive_file ) ) {
            $result = $this->extract_tar_gz( $archive_file, ABSPATH );
        } elseif ( $ext === 'zip' ) {
            $result = $this->extract_zip( $archive_file, ABSPATH );
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

        $state['message'] = sprintf(
            'Dateien: Archiv %d/%d (%s) – %s Dateien extrahiert',
            $state['current_archive'],
            count( $archives ),
            $archive_name,
            number_format_i18n( $state['files_extracted'] )
        );

        // Check if all archives are done
        if ( $state['current_archive'] >= count( $archives ) ) {
            $state['phase']    = self::PHASE_DONE;
            $state['progress'] = 100;
            $state['message']  = 'Wiederherstellung abgeschlossen!';

            $this->log( 'Datei-Wiederherstellung abgeschlossen: ' . $state['files_extracted'] . ' Dateien' );
        }

        return $state;
    }

    /**
     * Extract a .tar.gz file.
     */
    private function extract_tar_gz( $archive, $destination ) {
        // Prefer system tar
        if ( function_exists( 'exec' ) ) {
            $cmd = sprintf(
                'tar -xzf %s -C %s 2>&1',
                escapeshellarg( $archive ),
                escapeshellarg( $destination )
            );

            exec( $cmd, $output, $return_code );

            if ( $return_code === 0 ) {
                // Count extracted files (approximate)
                $cmd2 = sprintf( 'tar -tzf %s 2>/dev/null | wc -l', escapeshellarg( $archive ) );
                $count = (int) trim( shell_exec( $cmd2 ) );
                return [ 'success' => true, 'count' => $count ];
            }

            return [ 'error' => 'tar fehlgeschlagen: ' . implode( "\n", $output ) ];
        }

        // Fallback: PharData
        if ( class_exists( 'PharData' ) ) {
            try {
                $phar = new PharData( $archive );
                $phar->extractTo( $destination, null, true );
                return [ 'success' => true, 'count' => $phar->count() ];
            } catch ( Exception $e ) {
                return [ 'error' => 'PharData: ' . $e->getMessage() ];
            }
        }

        return [ 'error' => 'Weder tar noch PharData verfügbar.' ];
    }

    /**
     * Extract a .zip file.
     */
    private function extract_zip( $archive, $destination ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'error' => 'ZipArchive nicht verfügbar.' ];
        }

        $zip = new ZipArchive();
        $result = $zip->open( $archive );

        if ( $result !== true ) {
            return [ 'error' => 'ZIP konnte nicht geöffnet werden: Code ' . $result ];
        }

        $count = $zip->numFiles;
        $zip->extractTo( $destination );
        $zip->close();

        return [ 'success' => true, 'count' => $count ];
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
        $timestamp = date( 'Y-m-d H:i:s' );
        $line      = "[{$timestamp}] [Restore] {$message}\n";

        file_put_contents( WPRB_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }
}
