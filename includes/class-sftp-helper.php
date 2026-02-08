<?php
/**
 * SFTP Helper Class for WP Robust Backup
 * Handles SFTP and FTP connections and uploads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_SFTP_Helper {

    private $connection;
    private $proto;
    private $login_success = false;

    public function __construct( $host, $port, $user, $pass, $proto = 'sftp' ) {
        $this->proto = $proto;
        $this->connect( $host, $port, $user, $pass );
    }

    private function connect( $host, $port, $user, $pass ) {
        if ( $this->proto === 'sftp' ) {
            if ( ! function_exists( 'ssh2_connect' ) ) {
                throw new Exception( 'SFTP (ssh2) extension not available.' );
            }
            $this->connection = ssh2_connect( $host, $port );
            if ( ! $this->connection ) {
                throw new Exception( 'SFTP Connection failed.' );
            }
            if ( ! ssh2_auth_password( $this->connection, $user, $pass ) ) {
                 throw new Exception( 'SFTP Authentication failed.' );
            }
            $this->login_success = true;
        } else {
            // FTP
            if ( ! function_exists( 'ftp_connect' ) ) {
                 throw new Exception( 'FTP extension not available.' );
            }
            $this->connection = ftp_connect( $host, $port );
            if ( ! $this->connection ) {
                 throw new Exception( 'FTP Connection failed.' );
            }
            if ( ! ftp_login( $this->connection, $user, $pass ) ) {
                 throw new Exception( 'FTP Authentication failed.' );
            }
            ftp_pasv( $this->connection, true ); // Connect passive
            $this->login_success = true;
        }
    }

    public function mkdir( $path ) {
        if ( ! $this->login_success ) return false;
        
        if ( $this->proto === 'sftp' ) {
            $sftp = ssh2_sftp( $this->connection );
            // Helper to recursively create dir
            $parts = explode( '/', trim( $path, '/' ) );
            $current = (strpos($path, '/') === 0 ? '/' : '');
            foreach ( $parts as $part ) {
                $current .= $part;
                if ( ! file_exists( "ssh2.sftp://$sftp" . $current ) ) {
                    ssh2_sftp_mkdir( $sftp, $current );
                }
                $current .= '/';
            }
            return true;
        } else {
            // FTP
            $parts = explode( '/', trim( $path, '/' ) );
            foreach ( $parts as $part ) {
                if ( ! @ftp_chdir( $this->connection, $part ) ) {
                    ftp_mkdir( $this->connection, $part );
                    ftp_chdir( $this->connection, $part );
                }
            }
            // Go back to root ??? No, stay here or reset?
            // To be safe, we usually navigate to target.
            // But mkdir logic implies creating it.
            // Let's assume absolute path usage for uploads.
            return true;
        }
    }

    public function upload( $local_file, $remote_path ) {
       if ( ! $this->login_success ) return false;

       if ( $this->proto === 'sftp' ) {
           if ( ! function_exists('ssh2_sftp') ) return false;
           $sftp = ssh2_sftp( $this->connection );
           if ( ! $sftp ) return false;
           
           $remote_stream = @fopen( "ssh2.sftp://$sftp$remote_path", 'w' );
           if ( ! $remote_stream ) return false;
           
           $local_stream = fopen( $local_file, 'r' );
           if ( ! $local_stream ) { fclose( $remote_stream ); return false; }
           
           while ( ! feof( $local_stream ) ) {
               $buffer = fread( $local_stream, 8192 );
               if ( fwrite( $remote_stream, $buffer ) === false ) {
                   fclose( $local_stream );
                   fclose( $remote_stream );
                   return false;
               }
           }
           
           fclose( $local_stream );
           fclose( $remote_stream );
           return true; 
       } else {
           return ftp_put( $this->connection, $remote_path, $local_file, FTP_BINARY );
       }
    }

    public function close() {
        if ( $this->proto === 'ftp' && $this->connection ) {
            ftp_close( $this->connection );
        }
        // SSH2 resource auto-closes
    }
}
