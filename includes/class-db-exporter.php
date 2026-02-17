<?php
/**
 * Chunked Database Exporter
 * 
 * Exports the WordPress database table by table, chunk by chunk.
 * Designed to handle databases of any size without hitting PHP memory or timeout limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_DB_Exporter {

    private $chunk_size;
    private $output_file;

    public function __construct() {
        $this->chunk_size = (int) get_option( 'wprb_db_chunk_size', 1000 );
    }

    /**
     * Get the state key for tracking export progress.
     */
    private function state_key() {
        return 'wprb_db_export_state';
    }

    /**
     * Initialize a new export session.
     * Returns the total number of tables.
     */
    public function init_export( $backup_id ) {
        global $wpdb;

        $this->output_file = WPRB_BACKUP_DIR . $backup_id . '/database.sql';

        // Ensure directory exists
        wp_mkdir_p( dirname( $this->output_file ) );

        // Get all tables
        $tables = $wpdb->get_col( "SHOW TABLES" );

        // Build table info with row counts and primary keys
        $table_info = [];
        foreach ( $tables as $table ) {
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            
            // Get Primary Key
            // Check for composite keys!
            $keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'" );
            $primary_key = false;
            
            // Only use optimization if PK is a single column.
            // Composite keys would require complex tuple comparison (a,b) > (x,y).
            // ALSO: Only use for INTEGER keys. Binary/String keys cause issues with comparisons and wpdb->prepare (e.g. wp_wffilemods).
            if ( count( $keys ) === 1 ) {
                $pk_col = $keys[0]->Column_name;
                $col_info = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE '{$pk_col}'" );
                
                if ( $col_info && preg_match( '/int/i', $col_info->Type ) ) {
                    $primary_key = $pk_col;
                }
            }

            $table_info[] = [
                'name'        => $table,
                'total_rows'  => $count,
                'exported'    => 0,
                'done'        => false,
                'primary_key' => $primary_key,
                'last_id'     => 0
            ];
        }

        // Try mysqldump first (much faster)
        $dump_error = '';
        $dump_success = $this->try_mysqldump( $backup_id, $this->output_file, $dump_error );

        $state = [
            'backup_id'       => $backup_id,
            'output_file'     => $this->output_file,
            'tables'          => $table_info,
            'current_idx'     => $dump_success ? count( $table_info ) : 0, // If done, skip to end
            'total_tables'    => count( $table_info ),
            'started_at'      => time(),
            'header_done'     => $dump_success, // Already done by dump
            'mysqldump'       => $dump_success,
            'mysqldump_error' => $dump_error, // Store reason for failure if any
        ];

        update_option( $this->state_key(), $state, false );

        return $state;
    }

    /**
     * Process the next chunk of the export.
     * Returns progress info or false if done.
     */
    public function process_chunk() {
        global $wpdb;

        $state = get_option( $this->state_key() );
        if ( ! $state ) {
            return [ 'error' => 'No export state found.' ];
        }

        $this->output_file = $state['output_file'];
        $fh = fopen( $this->output_file, 'a' );

        if ( ! $fh ) {
            return [ 'error' => 'Cannot open output file for writing.' ];
        }

        // Write header on first chunk
        if ( ! $state['header_done'] ) {
            $header = $this->get_sql_header();
            fwrite( $fh, $header );
            $state['header_done'] = true;
        }

        // If mysqldump was used and successful, we are done immediately
        if ( ! empty( $state['mysqldump'] ) ) {
            fclose( $fh );
            delete_option( $this->state_key() );
            return [
                'done'     => true,
                'progress' => 100,
                'message'  => 'Datenbank-Export abgeschlossen (via mysqldump).',
            ];
        }

        $idx = $state['current_idx'];

        // All tables done?
        if ( $idx >= $state['total_tables'] ) {
            fwrite( $fh, $this->get_sql_footer() );
            fclose( $fh );
            delete_option( $this->state_key() );
            return [
                'done'     => true,
                'progress' => 100,
                'message'  => 'Datenbank-Export abgeschlossen.',
            ];
        }

        $table = &$state['tables'][ $idx ];
        $table_name = $table['name'];

        // Write CREATE TABLE statement if this is the first chunk for this table
        if ( $table['exported'] === 0 ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N );
            if ( $create ) {
                fwrite( $fh, "\n-- --------------------------------------------------------\n" );
                fwrite( $fh, "-- Table: `{$table_name}`\n" );
                fwrite( $fh, "-- --------------------------------------------------------\n\n" );
                fwrite( $fh, "DROP TABLE IF EXISTS `{$table_name}`;\n" );
                fwrite( $fh, $create[1] . ";\n\n" );
            }
        }

        // Export rows in chunks
        $primary_key = $table['primary_key'] ?? false;
        $rows = [];

        if ( $primary_key ) {
            // Cursor-based pagination (Much faster for large tables)
            $last_id = $table['last_id'] ?? 0;
            
            // Handle non-numeric PKs if necessary (statistically rare in WP core but possible in plugins)
            // For simplicity and speed, we assume numeric or string sortable PKs.
            // Using prepared statement for safety.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table_name}` WHERE `{$primary_key}` > %s ORDER BY `{$primary_key}` ASC LIMIT %d",
                    $last_id,
                    $this->chunk_size
                ),
                ARRAY_A
            );

            if ( ! empty( $rows ) ) {
                // Update last_id for next chunk
                $last_row = end( $rows );
                $table['last_id'] = $last_row[ $primary_key ];
            }

        } else {
            // Fallback to OFFSET (Slow for deep pagination)
            $offset = $table['exported'];
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d",
                    $this->chunk_size,
                    $offset
                ),
                ARRAY_A
            );
        }

        if ( ! empty( $rows ) ) {
            // Get column names from first row
            $columns = array_keys( $rows[0] );
            $col_list = '`' . implode( '`, `', $columns ) . '`';

            // Build INSERT statements in batches of 50 rows
            $batch = [];
            $batch_count = 0;

            foreach ( $rows as $row ) {
                $values = [];
                foreach ( $row as $value ) {
                    if ( is_null( $value ) ) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape( $value ) . "'";
                    }
                }
                $batch[] = '(' . implode( ', ', $values ) . ')';
                $batch_count++;

                // Write every 50 rows to keep memory low
                if ( $batch_count >= 50 ) {
                    fwrite( $fh, "INSERT INTO `{$table_name}` ({$col_list}) VALUES\n" );
                    fwrite( $fh, implode( ",\n", $batch ) . ";\n" );
                    $batch = [];
                    $batch_count = 0;
                }
            }

            // Write remaining rows
            if ( ! empty( $batch ) ) {
                fwrite( $fh, "INSERT INTO `{$table_name}` ({$col_list}) VALUES\n" );
                fwrite( $fh, implode( ",\n", $batch ) . ";\n" );
            }

            $table['exported'] += count( $rows );
        }

        // Check if table is done
        // If we got NO rows, or fewer than requested, we are done
        // OR if using cursor and we hit the end (empty rows handled above, but explicit check here)
        if ( empty( $rows ) || count( $rows ) < $this->chunk_size ) {
            $table['done'] = true;
            $state['current_idx']++;
            fwrite( $fh, "\n" );
        }

        fclose( $fh );

        // Calculate overall progress
        $total_rows = 0;
        $done_rows = 0;
        foreach ( $state['tables'] as $t ) {
            $total_rows += max( $t['total_rows'], 1 );
            $done_rows += $t['exported'];
        }
        $progress = $total_rows > 0 ? round( ( $done_rows / $total_rows ) * 100, 1 ) : 0;

        update_option( $this->state_key(), $state, false );

        return [
            'done'          => false,
            'progress'      => $progress,
            'current_table' => $table_name,
            'table_index'   => $idx + 1,
            'total_tables'  => $state['total_tables'],
            'rows_exported' => $table['exported'],
            'rows_total'    => $table['total_rows'],
            'message'       => sprintf(
                'Tabelle %d/%d: %s (%s/%s Zeilen)',
                $idx + 1,
                $state['total_tables'],
                $table_name,
                number_format_i18n( $table['exported'] ),
                number_format_i18n( $table['total_rows'] )
            ),
        ];
    }

    /**
     * Cancel an ongoing export.
     */
    public function cancel() {
        $state = get_option( $this->state_key() );
        if ( $state && isset( $state['output_file'] ) && file_exists( $state['output_file'] ) ) {
            @unlink( $state['output_file'] );
        }
        delete_option( $this->state_key() );
    }

    /**
     * SQL file header
     */
    private function get_sql_header() {
        global $wpdb;

        $header = "-- WP Robust Backup - Database Export\n";
        $header .= "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n";
        $header .= "-- WordPress: " . get_bloginfo( 'version' ) . "\n";
        $header .= "-- Site: " . get_bloginfo( 'url' ) . "\n";
        $header .= "-- MySQL: " . $wpdb->db_version() . "\n";
        $header .= "-- --------------------------------------------------------\n\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET AUTOCOMMIT = 0;\n";
        $header .= "START TRANSACTION;\n";
        $header .= "SET time_zone = \"+00:00\";\n";
        $header .= "SET NAMES utf8mb4;\n\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

        return $header;
    }

    /**
     * Run a benchmark of the PHP export method.
     * returns file size or false on failure.
     */
    public function benchmark_php_export( $backup_id, $limit_rows = 0 ) {
        $output_file = WPRB_BACKUP_DIR . $backup_id . '/benchmark.sql';
        wp_mkdir_p( dirname( $output_file ) );
        
        $fh = fopen( $output_file, 'w' );
        if ( ! $fh ) return false;

        fwrite( $fh, $this->get_sql_header() );

        global $wpdb;
        $tables = $wpdb->get_col( "SHOW TABLES" );

        foreach ( $tables as $table ) {
            // Table structure
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );

            // Table data
            $limit = $limit_rows > 0 ? " LIMIT $limit_rows" : "";
            $rows = $wpdb->get_results( "SELECT * FROM `{$table}`$limit", ARRAY_N );
            
            if ( $rows ) {
                fwrite( $fh, "INSERT INTO `{$table}` VALUES\n" );
                $lines = [];
                foreach ( $rows as $row ) {
                    $values = [];
                    foreach ( $row as $val ) {
                        if ( is_null( $val ) ) $values[] = 'NULL';
                        elseif ( is_numeric( $val ) ) $values[] = $val;
                        else $values[] = "'" . esc_sql( $val ) . "'";
                    }
                    $lines[] = "(" . implode( ',', $values ) . ")";
                }
                fwrite( $fh, implode( ",\n", $lines ) . ";\n\n" );
            }
        }

        fwrite( $fh, $this->get_sql_footer() );
        fclose( $fh );

        if ( file_exists( $output_file ) ) {
            return filesize( $output_file );
        }
        return false;
    }

    /**
     * SQL file footer
     */
    private function get_sql_footer() {
        $footer = "\nCOMMIT;\n\n";
        $footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
        $footer .= "-- End of backup\n";

        return $footer;
    }

    /**
     * Try to use system mysqldump.
     * Returns true if successful.
     */
    public function try_mysqldump( $backup_id, $output_file, &$error_log = '' ) {
        if ( ! function_exists( 'exec' ) ) {
            $error_log = 'exec() is disabled';
            return false;
        }

        // Check availability
        $bin = null;
        $paths = [ '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump', 'mysqldump' ];
        foreach ( $paths as $p ) {
            exec( "which $p 2>/dev/null", $out, $ret );
            if ( $ret === 0 && ! empty( $out ) ) {
                $bin = $out[0];
                break;
            }
        }

        if ( ! $bin ) {
            $error_log = 'mysqldump binary not found';
            return false;
        }

        // Build command
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;
        
        // Handle port in host (localhost:3306)
        $msg_host = $host;
        $port_arg = '';
        if ( strpos( $host, ':' ) !== false ) {
            list( $h, $p ) = explode( ':', $host );
            $msg_host = $h;
            $port_arg = " --port=" . escapeshellarg( $p ) . " --host=" . escapeshellarg( $h );
        } else {
            $port_arg = " --host=" . escapeshellarg( $host );
        }

        // Safer to use defaults-extra-file for password to avoid process list exposure, 
        // but for simplicity (and since we are likely alone on the VPS/Hosting), we use standard args.
        // Warning: Password visible in ps aux. Use config file if possible.
        // Let's use a temporary config file for security.
        $cnf_file = WPRB_BACKUP_DIR . $backup_id . '/.my.cnf';
        $cnf_content = "[client]\nuser=\"$user\"\npassword=\"$pass\"\nhost=\"$msg_host\"\n";
        file_put_contents( $cnf_file, $cnf_content );
        chmod( $cnf_file, 0600 );

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --no-tablespaces --single-transaction --quick %s > %s 2>&1',
            escapeshellarg( $bin ),
            escapeshellarg( $cnf_file ),
            escapeshellarg( $name ),
            escapeshellarg( $output_file )
        );

        exec( $cmd, $output, $return_code );

        @unlink( $cnf_file );

        if ( $return_code === 0 && file_exists( $output_file ) && filesize( $output_file ) > 0 ) {
            return true;
        }
        
        // If failed, delete partial file so PHP fallback can start clean
        $error_log = 'Return Code: ' . $return_code . ', Output: ' . implode( "\n", $output );
        @unlink( $output_file );
        return false;
    }
}
