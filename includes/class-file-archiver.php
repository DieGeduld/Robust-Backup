<?php
/**
 * Chunked File Archiver
 * 
 * Archives WordPress files in batches to avoid timeout and memory issues.
 * Supports splitting into multiple archive parts for very large sites.
 * Uses system tar/gzip when available, falls back to ZipArchive.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_File_Archiver {

    private $batch_size;
    private $max_archive_mb;
    private $exclude_dirs;

    public function __construct() {
        $this->batch_size     = (int) get_option( 'wprb_file_batch_size', 200 );
        $this->max_archive_mb = (int) get_option( 'wprb_max_archive_size', 500 );

        $exclude_raw = get_option( 'wprb_exclude_dirs', '' );
        $this->exclude_dirs = array_filter( array_map( 'trim', explode( "\n", $exclude_raw ) ) );
    }

    private function state_key() {
        return 'wprb_file_archive_state';
    }

    /**
     * Initialize file archiving session.
     * Scans the WordPress directory and builds a file list.
     */
    public function init_archive( $backup_id ) {
        $backup_dir  = WPRB_BACKUP_DIR . $backup_id . '/';
        $file_list   = $backup_dir . 'file_list.txt';

        wp_mkdir_p( $backup_dir );

        // Build file list (stream to file to avoid memory issues)
        $count = $this->build_file_list( ABSPATH, $file_list );

        $state = [
            'backup_id'     => $backup_id,
            'backup_dir'    => $backup_dir,
            'file_list'     => $file_list,
            'total_files'   => $count,
            'processed'     => 0,
            'current_part'  => 1,
            'current_size'  => 0,
            'archive_files' => [],
            'started_at'    => time(),
            'use_tar'       => $this->can_use_tar(),
        ];

        update_option( $this->state_key(), $state, false );

        return $state;
    }

    /**
     * Process the next batch of files.
     */
    public function process_batch() {
        $state = get_option( $this->state_key() );
        if ( ! $state ) {
            return [ 'error' => 'No archive state found.' ];
        }

        $file_list  = $state['file_list'];
        $backup_dir = $state['backup_dir'];
        $processed  = $state['processed'];
        $part       = $state['current_part'];
        $use_tar    = $state['use_tar'];

        // Read the next batch of files from the list
        $fh = fopen( $file_list, 'r' );
        if ( ! $fh ) {
            return [ 'error' => 'Cannot read file list.' ];
        }

        // Skip already processed lines
        $skipped = 0;
        while ( $skipped < $processed && ! feof( $fh ) ) {
            fgets( $fh );
            $skipped++;
        }

        // Read next batch
        $batch = [];
        $batch_count = 0;
        while ( $batch_count < $this->batch_size && ! feof( $fh ) ) {
            $line = trim( fgets( $fh ) );
            if ( ! empty( $line ) && file_exists( $line ) ) {
                $batch[] = $line;
                $batch_count++;
            }
        }

        $is_eof = feof( $fh );
        fclose( $fh );

        // Nothing left to process?
        if ( empty( $batch ) && $is_eof ) {
            // Finalize
            delete_option( $this->state_key() );
            @unlink( $file_list );

            return [
                'done'          => true,
                'progress'      => 100,
                'archive_files' => $state['archive_files'],
                'message'       => sprintf(
                    'Datei-Archivierung abgeschlossen. %s Dateien in %d Teil(en).',
                    number_format_i18n( $state['processed'] ),
                    $state['current_part']
                ),
            ];
        }

        // Archive this batch
        $archive_name = sprintf( 'files-part%03d.zip', $part );
        $archive_path = $backup_dir . $archive_name;

        if ( $use_tar ) {
            $result = $this->archive_batch_tar( $batch, $archive_path, $backup_dir, $part );
        } else {
            $result = $this->archive_batch_zip( $batch, $archive_path );
        }

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        $state['processed'] += count( $batch );

        // Check if we need to start a new archive part
        $current_size = file_exists( $archive_path ) ? filesize( $archive_path ) : 0;
        $state['current_size'] = $current_size;

        if ( $current_size >= $this->max_archive_mb * 1024 * 1024 ) {
            // Archive part is full, register it and start new part
            $state['archive_files'][] = $archive_path;
            $state['current_part']++;
            $state['current_size'] = 0;
        } elseif ( $is_eof && empty( $batch ) === false ) {
            // Last batch, register current archive
            $state['archive_files'][] = $archive_path;
        }

        // Calculate progress
        $progress = $state['total_files'] > 0
            ? round( ( $state['processed'] / $state['total_files'] ) * 100, 1 )
            : 0;

        update_option( $this->state_key(), $state, false );

        return [
            'done'           => false,
            'progress'       => $progress,
            'files_done'     => $state['processed'],
            'files_total'    => $state['total_files'],
            'current_part'   => $state['current_part'],
            'current_size'   => size_format( $current_size ),
            'message'        => sprintf(
                'Dateien: %s / %s (Teil %d, %s)',
                number_format_i18n( $state['processed'] ),
                number_format_i18n( $state['total_files'] ),
                $state['current_part'],
                size_format( $current_size )
            ),
        ];
    }

    /**
     * Cancel an ongoing archive.
     */
    public function cancel() {
        $state = get_option( $this->state_key() );
        if ( $state ) {
            // Clean up archive files
            if ( ! empty( $state['archive_files'] ) ) {
                foreach ( $state['archive_files'] as $file ) {
                    @unlink( $file );
                }
            }
            @unlink( $state['file_list'] );
        }
        delete_option( $this->state_key() );
    }

    /**
     * Build a flat file list and write it to a text file.
     * Uses iterative approach (not recursive) to avoid stack overflow.
     */
    private function build_file_list( $root_dir, $output_file ) {
        $fh = fopen( $output_file, 'w' );
        if ( ! $fh ) {
            return 0;
        }

        $count = 0;
        $stack = [ rtrim( $root_dir, '/' ) ];

        while ( ! empty( $stack ) ) {
            $dir = array_pop( $stack );
            $handle = @opendir( $dir );

            if ( ! $handle ) {
                continue;
            }

            while ( false !== ( $entry = readdir( $handle ) ) ) {
                if ( $entry === '.' || $entry === '..' ) {
                    continue;
                }

                $full_path = $dir . '/' . $entry;
                $rel_path  = ltrim( str_replace( ABSPATH, '', $full_path ), '/' );

                // Check excludes
                if ( $this->is_excluded( $rel_path ) ) {
                    continue;
                }

                if ( is_dir( $full_path ) ) {
                    $stack[] = $full_path;
                } elseif ( is_file( $full_path ) && is_readable( $full_path ) ) {
                    fwrite( $fh, $full_path . "\n" );
                    $count++;
                }
            }

            closedir( $handle );
        }

        fclose( $fh );
        return $count;
    }

    /**
     * Check if a relative path should be excluded.
     */
    private function is_excluded( $rel_path ) {
        foreach ( $this->exclude_dirs as $exclude ) {
            if ( strpos( $rel_path, $exclude ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Archive a batch of files using ZipArchive.
     */
    private function archive_batch_zip( $files, $archive_path ) {
        $zip = new ZipArchive();
        $mode = file_exists( $archive_path ) ? ZipArchive::CHECKCONS : ZipArchive::CREATE;

        // If existing file, open it; otherwise create new
        if ( file_exists( $archive_path ) ) {
            $result = $zip->open( $archive_path );
        } else {
            $result = $zip->open( $archive_path, ZipArchive::CREATE );
        }

        if ( $result !== true ) {
            return [ 'error' => 'Cannot open zip archive: ' . $result ];
        }

        foreach ( $files as $file ) {
            $rel_path = ltrim( str_replace( ABSPATH, '', $file ), '/' );
            $zip->addFile( $file, $rel_path );
        }

        $zip->close();

        return [ 'success' => true ];
    }

    /**
     * Archive a batch of files using system tar command.
     * More efficient for large files and avoids PHP memory issues.
     */
    private function archive_batch_tar( $files, $archive_path, $backup_dir, $part ) {
        $tar_name = sprintf( 'files-part%03d.tar.gz', $part );
        $tar_path = $backup_dir . $tar_name;

        // Write file list for tar
        $list_file = $backup_dir . 'tar_batch.txt';
        file_put_contents( $list_file, implode( "\n", $files ) );

        // Append to existing tar or create new
        if ( file_exists( $tar_path ) ) {
            // We need to decompress, append, recompress (tar -rf doesn't work with .gz)
            // Instead, let's use an uncompressed tar and compress at the end
            $uncompressed = $backup_dir . sprintf( 'files-part%03d.tar', $part );

            if ( ! file_exists( $uncompressed ) && file_exists( $tar_path ) ) {
                exec( sprintf( 'gunzip -k %s 2>&1', escapeshellarg( $tar_path ) ), $output, $ret );
            }

            $cmd = sprintf(
                'tar -rf %s -C %s -T %s 2>&1',
                escapeshellarg( $uncompressed ),
                escapeshellarg( ABSPATH ),
                escapeshellarg( $list_file )
            );
        } else {
            $cmd = sprintf(
                'tar -cf %s -C %s -T %s 2>&1',
                escapeshellarg( str_replace( '.tar.gz', '.tar', $tar_path ) ),
                escapeshellarg( ABSPATH ),
                escapeshellarg( $list_file )
            );
        }

        exec( $cmd, $output, $return_code );
        @unlink( $list_file );

        if ( $return_code !== 0 ) {
            // Fallback to zip
            return $this->archive_batch_zip( $files, $archive_path );
        }

        return [ 'success' => true, 'archive' => $tar_path ];
    }

    /**
     * Compress tar files after archiving is complete.
     */
    public function compress_tar_files( $backup_id ) {
        $backup_dir = WPRB_BACKUP_DIR . $backup_id . '/';
        $tar_files  = glob( $backup_dir . 'files-part*.tar' );

        foreach ( $tar_files as $tar ) {
            if ( ! file_exists( $tar . '.gz' ) ) {
                exec( sprintf( 'gzip %s 2>&1', escapeshellarg( $tar ) ) );
            }
        }
    }

    /**
     * Check if system tar is available.
     */
    private function can_use_tar() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        exec( 'which tar 2>&1', $output, $return_code );
        return $return_code === 0;
    }
}
