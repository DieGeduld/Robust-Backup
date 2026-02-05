<?php
/**
 * Storage Manager
 * 
 * Handles uploading backup files to various storage destinations:
 * - Local (already on server)
 * - Browser download
 * - Google Drive
 * - Dropbox
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Storage_Manager {

    /**
     * Upload backup files to configured storage destinations.
     *
     * @param string $backup_id   The backup identifier.
     * @param array  $files       Array of absolute file paths.
     * @return array Results per storage type.
     */
    public function distribute( $backup_id, $files ) {
        $storages = (array) get_option( 'wprb_storage', [ 'local' ] );
        $results  = [];

        foreach ( $storages as $storage ) {
            switch ( $storage ) {
                case 'local':
                    $results['local'] = $this->store_local( $backup_id, $files );
                    break;
                case 'gdrive':
                    $results['gdrive'] = $this->store_gdrive( $backup_id, $files );
                    break;
                case 'dropbox':
                    $results['dropbox'] = $this->store_dropbox( $backup_id, $files );
                    break;
            }
        }

        return $results;
    }

    /**
     * Local storage: files are already in place, just log it.
     */
    private function store_local( $backup_id, $files ) {
        $total_size = 0;
        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                $total_size += filesize( $file );
            }
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Lokal gespeichert: %d Datei(en), %s',
                count( $files ),
                size_format( $total_size )
            ),
            'path'    => WPRB_BACKUP_DIR . $backup_id . '/',
        ];
    }

    /**
     * Get download URL for a backup file.
     */
    public function get_download_url( $backup_id, $filename ) {
        return add_query_arg( [
            'action'    => 'wprb_download',
            'backup_id' => sanitize_file_name( $backup_id ),
            'file'      => sanitize_file_name( $filename ),
            '_wpnonce'  => wp_create_nonce( 'wprb_download_' . $backup_id ),
        ], admin_url( 'admin-ajax.php' ) );
    }

    /**
     * Handle file download streaming.
     */
    public function stream_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $backup_id = sanitize_file_name( $_GET['backup_id'] ?? '' );
        $filename  = sanitize_file_name( $_GET['file'] ?? '' );
        $nonce     = $_GET['_wpnonce'] ?? '';

        if ( ! wp_verify_nonce( $nonce, 'wprb_download_' . $backup_id ) ) {
            wp_die( 'Invalid nonce' );
        }

        $filepath = WPRB_BACKUP_DIR . $backup_id . '/' . $filename;

        if ( ! file_exists( $filepath ) ) {
            wp_die( 'File not found' );
        }

        // Stream the file
        $size = filesize( $filepath );

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . $size );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );

        // Flush output buffer
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        // Stream in 8KB chunks
        $fh = fopen( $filepath, 'rb' );
        while ( ! feof( $fh ) ) {
            echo fread( $fh, 8192 );
            flush();
        }
        fclose( $fh );

        exit;
    }

    // ─────────────────────────────────────────────
    // Google Drive
    // ─────────────────────────────────────────────

    /**
     * Upload to Google Drive using chunked/resumable upload.
     */
    private function store_gdrive( $backup_id, $files ) {
        $token = get_option( 'wprb_gdrive_token', '' );
        if ( empty( $token ) ) {
            return [
                'success' => false,
                'message' => 'Google Drive nicht konfiguriert. Bitte Token in den Einstellungen hinterlegen.',
            ];
        }

        $token_data = json_decode( $token, true );
        $access_token = $this->gdrive_refresh_token( $token_data );

        if ( ! $access_token ) {
            return [
                'success' => false,
                'message' => 'Google Drive Token konnte nicht erneuert werden.',
            ];
        }

        // Create a folder for this backup
        $folder_id = $this->gdrive_create_folder( $access_token, 'WP-Backup-' . $backup_id );

        $uploaded = 0;
        $errors   = [];

        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) ) {
                continue;
            }

            $result = $this->gdrive_upload_file( $access_token, $file, $folder_id );

            if ( $result ) {
                $uploaded++;
            } else {
                $errors[] = basename( $file );
            }
        }

        return [
            'success' => empty( $errors ),
            'message' => sprintf(
                'Google Drive: %d/%d Datei(en) hochgeladen.%s',
                $uploaded,
                count( $files ),
                ! empty( $errors ) ? ' Fehler bei: ' . implode( ', ', $errors ) : ''
            ),
        ];
    }

    /**
     * Refresh Google Drive access token.
     */
    private function gdrive_refresh_token( $token_data ) {
        if ( ! isset( $token_data['refresh_token'] ) ) {
            return $token_data['access_token'] ?? false;
        }

        $client_id     = get_option( 'wprb_gdrive_client_id', '' );
        $client_secret = get_option( 'wprb_gdrive_secret', '' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $token_data['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            $token_data['access_token'] = $body['access_token'];
            update_option( 'wprb_gdrive_token', wp_json_encode( $token_data ) );
            return $body['access_token'];
        }

        return false;
    }

    /**
     * Create a folder on Google Drive.
     */
    private function gdrive_create_folder( $access_token, $name ) {
        $response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'name'     => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['id'] ?? null;
    }

    /**
     * Upload a file to Google Drive using resumable upload (for large files).
     */
    private function gdrive_upload_file( $access_token, $filepath, $folder_id ) {
        $filename  = basename( $filepath );
        $file_size = filesize( $filepath );

        // Step 1: Initiate resumable upload session
        $metadata = wp_json_encode( [
            'name'    => $filename,
            'parents' => $folder_id ? [ $folder_id ] : [],
        ] );

        $response = wp_remote_post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization'           => 'Bearer ' . $access_token,
                    'Content-Type'            => 'application/json; charset=UTF-8',
                    'X-Upload-Content-Length'  => $file_size,
                ],
                'body' => $metadata,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $upload_url = wp_remote_retrieve_header( $response, 'location' );
        if ( empty( $upload_url ) ) {
            return false;
        }

        // Step 2: Upload in 5MB chunks
        $chunk_size = 5 * 1024 * 1024; // 5 MB
        $fh         = fopen( $filepath, 'rb' );
        $offset     = 0;

        while ( $offset < $file_size ) {
            $chunk  = fread( $fh, $chunk_size );
            $length = strlen( $chunk );
            $end    = $offset + $length - 1;

            $response = wp_remote_request( $upload_url, [
                'method'  => 'PUT',
                'timeout' => 120,
                'headers' => [
                    'Content-Length' => $length,
                    'Content-Range'  => "bytes {$offset}-{$end}/{$file_size}",
                ],
                'body' => $chunk,
            ] );

            $status = wp_remote_retrieve_response_code( $response );

            if ( $status === 200 || $status === 201 ) {
                // Upload complete
                fclose( $fh );
                return true;
            } elseif ( $status === 308 ) {
                // Chunk accepted, continue
                $offset += $length;
            } else {
                // Error
                fclose( $fh );
                return false;
            }
        }

        fclose( $fh );
        return true;
    }

    // ─────────────────────────────────────────────
    // Dropbox
    // ─────────────────────────────────────────────

    /**
     * Upload to Dropbox using chunked upload sessions.
     */
    private function store_dropbox( $backup_id, $files ) {
        $token = get_option( 'wprb_dropbox_token', '' );
        if ( empty( $token ) ) {
            return [
                'success' => false,
                'message' => 'Dropbox nicht konfiguriert. Bitte Token in den Einstellungen hinterlegen.',
            ];
        }

        $token_data   = json_decode( $token, true );
        $access_token = $this->dropbox_refresh_token( $token_data );

        if ( ! $access_token ) {
            return [
                'success' => false,
                'message' => 'Dropbox Token konnte nicht erneuert werden. Bitte erneut verbinden.',
            ];
        }

        $this->storage_log( 'Dropbox: Access Token erhalten, starte Upload...' );

        $uploaded = 0;
        $errors   = [];

        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) ) {
                $this->storage_log( 'Dropbox: Datei nicht gefunden: ' . $file );
                continue;
            }

            $dest   = '/WP-Backups/' . $backup_id . '/' . basename( $file );
            $result = $this->dropbox_upload_file( $access_token, $file, $dest );

            if ( $result === true ) {
                $uploaded++;
                $this->storage_log( 'Dropbox: Upload OK: ' . basename( $file ) );
            } else {
                $errors[] = basename( $file ) . ' (' . $result . ')';
                $this->storage_log( 'Dropbox: Upload FEHLER: ' . basename( $file ) . ' - ' . $result );
            }
        }

        return [
            'success' => empty( $errors ),
            'message' => sprintf(
                'Dropbox: %d/%d Datei(en) hochgeladen.%s',
                $uploaded,
                count( $files ),
                ! empty( $errors ) ? ' Fehler bei: ' . implode( ', ', $errors ) : ''
            ),
        ];
    }

    /**
     * Refresh Dropbox access token.
     */
    private function dropbox_refresh_token( $token_data ) {
        if ( ! isset( $token_data['refresh_token'] ) ) {
            $this->storage_log( 'Dropbox: Kein refresh_token vorhanden, verwende access_token direkt.' );
            return $token_data['access_token'] ?? false;
        }

        $app_key    = get_option( 'wprb_dropbox_app_key', '' );
        $app_secret = get_option( 'wprb_dropbox_secret', '' );

        $this->storage_log( 'Dropbox: Erneuere Access Token via Refresh Token...' );

        $response = wp_remote_post( 'https://api.dropbox.com/oauth2/token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $app_key . ':' . $app_secret ),
            ],
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token_data['refresh_token'],
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $this->storage_log( 'Dropbox: Token-Refresh WP-Error: ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 ) {
            $error_msg = $body['error_description'] ?? $body['error'] ?? 'HTTP ' . $status;
            $this->storage_log( 'Dropbox: Token-Refresh fehlgeschlagen: ' . $error_msg );
            return false;
        }

        if ( isset( $body['access_token'] ) ) {
            $token_data['access_token'] = $body['access_token'];
            update_option( 'wprb_dropbox_token', wp_json_encode( $token_data ) );
            $this->storage_log( 'Dropbox: Neuer Access Token gespeichert.' );
            return $body['access_token'];
        }

        $this->storage_log( 'Dropbox: Token-Refresh: Kein access_token in Antwort.' );
        return false;
    }

    /**
     * Upload a file to Dropbox.
     * Returns true on success, or an error string on failure.
     */
    private function dropbox_upload_file( $access_token, $filepath, $dest_path ) {
        $file_size  = filesize( $filepath );
        $chunk_size = 8 * 1024 * 1024; // 8 MB chunks

        $this->storage_log( sprintf(
            'Dropbox: Upload starten: %s (%s) → %s',
            basename( $filepath ),
            size_format( $file_size ),
            $dest_path
        ) );

        // Small files (≤8MB): simple upload
        if ( $file_size <= $chunk_size ) {
            return $this->dropbox_simple_upload( $access_token, $filepath, $dest_path, $file_size );
        }

        // Large files: chunked upload session
        return $this->dropbox_chunked_upload( $access_token, $filepath, $dest_path, $file_size, $chunk_size );
    }

    /**
     * Simple single-request upload for small files.
     */
    private function dropbox_simple_upload( $access_token, $filepath, $dest_path, $file_size ) {
        $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload', [
            'timeout' => 120,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'Content-Type'    => 'application/octet-stream',
                'Dropbox-API-Arg' => wp_json_encode( [
                    'path'       => $dest_path,
                    'mode'       => 'overwrite',
                    'autorename' => false,
                    'mute'       => true,
                ] ),
            ],
            'body' => file_get_contents( $filepath ),
        ] );

        if ( is_wp_error( $response ) ) {
            return 'WP-Error: ' . $response->get_error_message();
        }

        $status = wp_remote_retrieve_response_code( $response );

        if ( $status === 200 ) {
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error = $body['error_summary'] ?? $body['error'] ?? wp_remote_retrieve_body( $response );
        return 'HTTP ' . $status . ': ' . ( is_string( $error ) ? $error : wp_json_encode( $error ) );
    }

    /**
     * Chunked upload session for large files.
     */
    private function dropbox_chunked_upload( $access_token, $filepath, $dest_path, $file_size, $chunk_size ) {
        $fh     = fopen( $filepath, 'rb' );
        $offset = 0;

        // Step 1: Start session with first chunk
        $chunk = fread( $fh, $chunk_size );
        $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/start', [
            'timeout' => 120,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'Content-Type'    => 'application/octet-stream',
                'Dropbox-API-Arg' => wp_json_encode( [ 'close' => false ] ),
            ],
            'body' => $chunk,
        ] );

        if ( is_wp_error( $response ) ) {
            fclose( $fh );
            return 'Session start WP-Error: ' . $response->get_error_message();
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            fclose( $fh );
            return 'Session start HTTP ' . $status . ': ' . wp_remote_retrieve_body( $response );
        }

        $body       = json_decode( wp_remote_retrieve_body( $response ), true );
        $session_id = $body['session_id'] ?? null;

        if ( ! $session_id ) {
            fclose( $fh );
            return 'Keine Session-ID erhalten';
        }

        $offset += strlen( $chunk );
        $this->storage_log( 'Dropbox: Upload-Session gestartet: ' . $session_id );

        // Step 2: Append chunks
        while ( ! feof( $fh ) ) {
            $chunk       = fread( $fh, $chunk_size );
            $chunk_len   = strlen( $chunk );
            $is_last     = feof( $fh );

            if ( $is_last ) {
                // Step 3: Finish session with last chunk
                break;
            }

            $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/append_v2', [
                'timeout' => 120,
                'headers' => [
                    'Authorization'   => 'Bearer ' . $access_token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( [
                        'cursor' => [
                            'session_id' => $session_id,
                            'offset'     => $offset,
                        ],
                        'close' => false,
                    ] ),
                ],
                'body' => $chunk,
            ] );

            if ( is_wp_error( $response ) ) {
                fclose( $fh );
                return 'Append WP-Error bei Offset ' . $offset . ': ' . $response->get_error_message();
            }

            $status = wp_remote_retrieve_response_code( $response );
            if ( $status !== 200 ) {
                fclose( $fh );
                return 'Append HTTP ' . $status . ' bei Offset ' . $offset;
            }

            $offset += $chunk_len;
            $this->storage_log( sprintf( 'Dropbox: Chunk hochgeladen: %s / %s', size_format( $offset ), size_format( $file_size ) ) );
        }

        fclose( $fh );

        // Step 3: Finish session
        $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/finish', [
            'timeout' => 120,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'Content-Type'    => 'application/octet-stream',
                'Dropbox-API-Arg' => wp_json_encode( [
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset'     => $offset,
                    ],
                    'commit' => [
                        'path'       => $dest_path,
                        'mode'       => 'overwrite',
                        'autorename' => false,
                        'mute'       => true,
                    ],
                ] ),
            ],
            'body' => isset( $chunk ) ? $chunk : '',
        ] );

        if ( is_wp_error( $response ) ) {
            return 'Finish WP-Error: ' . $response->get_error_message();
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status === 200 ) {
            return true;
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $error = $body['error_summary'] ?? wp_remote_retrieve_body( $response );
        return 'Finish HTTP ' . $status . ': ' . $error;
    }

    // ─────────────────────────────────────────────
    // Cloud Download / Restore
    // ─────────────────────────────────────────────

    /**
     * Attempt to download missing files from cloud for a backup.
     * Returns true if all files are now present locally.
     */
    public function download_backup_from_cloud( $backup_id ) {
        $dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id );
        
        $meta_file = $dir . '/backup-meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return [ 'success' => false, 'message' => 'Metadaten fehlen.' ];
        }

        $meta = json_decode( file_get_contents( $meta_file ), true );
        $files = $meta['files'] ?? [];
        $failed = [];

        // Check if files are already there
        foreach ( $files as $file ) {
            if ( ! file_exists( $dir . '/' . $file ) ) {
                // Try Dropbox first
                $success = $this->dropbox_download_file( $backup_id, $file, $dir . '/' . $file );
                if ( ! $success ) {
                    // Try Google Drive
                    $success = $this->gdrive_download_file( $backup_id, $file, $dir . '/' . $file );
                }

                if ( ! $success ) {
                    $failed[] = $file;
                }
            }
        }

        if ( empty( $failed ) ) {
            // Restore complete, remove deleted flag
            unset( $meta['local_deleted'] );
            file_put_contents( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT ) );
            return [ 'success' => true, 'message' => 'Alle Dateien geladen.' ];
        }

        return [ 
            'success' => false, 
            'message' => 'Konnte nicht laden: ' . implode( ', ', $failed ) 
        ];
    }

    private function dropbox_download_file( $backup_id, $filename, $dest ) {
        $token = get_option( 'wprb_dropbox_token', '' );
        if ( empty( $token ) ) return false;
        
        $token_data = json_decode( $token, true );
        $access_token = $this->dropbox_refresh_token( $token_data );
        if ( ! $access_token ) return false;

        $source_path = '/WP-Backups/' . $backup_id . '/' . $filename;
        
        $this->storage_log( 'Dropbox-Download: ' . $source_path );

        $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/download', [
            'timeout' => 300,
            'stream'  => true, // Stream to file
            'filename' => $dest,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'Dropbox-API-Arg' => wp_json_encode( [ 'path' => $source_path ] ),
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $this->storage_log( 'Dropbox-Download Fehler: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
            return false;
        }

        return true;
    }

    private function gdrive_download_file( $backup_id, $filename, $dest ) {
        // Implementing GDrive download is complex because we need to find the file ID by name first.
        // For this iteration, we focus on Dropbox as requested.
        return false;
    }

    /**
     * Log helper for storage operations.
     */
    private function storage_log( $message ) {
        $line = '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
        file_put_contents( WPRB_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }

    // ─────────────────────────────────────────────
    // OAuth Helper (shared redirect handler)
    // ─────────────────────────────────────────────

    /**
     * Get the OAuth callback URL.
     */
    public static function get_oauth_redirect_url() {
        return admin_url( 'admin.php?page=wp-robust-backup&tab=settings&oauth_callback=1' );
    }

    /**
     * Get Google Drive authorization URL.
     */
    public function get_gdrive_auth_url() {
        $client_id = get_option( 'wprb_gdrive_client_id', '' );
        if ( empty( $client_id ) ) {
            return '';
        }

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => self::get_oauth_redirect_url(),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => 'gdrive',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params, '', '&' );
    }

    /**
     * Get Dropbox authorization URL.
     */
    public function get_dropbox_auth_url() {
        $app_key = get_option( 'wprb_dropbox_app_key', '' );
        if ( empty( $app_key ) ) {
            return '';
        }

        $params = [
            'client_id'         => $app_key,
            'redirect_uri'      => self::get_oauth_redirect_url(),
            'response_type'     => 'code',
            'token_access_type' => 'offline',
            'state'             => 'dropbox',
            'scope'             => 'files.content.write files.content.read',
        ];

        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query( $params, '', '&' );
    }

    /**
     * Exchange OAuth code for tokens.
     */
    public function handle_oauth_callback() {
        if ( ! isset( $_GET['oauth_callback'] ) || ! isset( $_GET['code'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $code  = sanitize_text_field( $_GET['code'] );
        $state = sanitize_text_field( $_GET['state'] ?? '' );

        if ( $state === 'gdrive' ) {
            $this->gdrive_exchange_code( $code );
        } elseif ( $state === 'dropbox' ) {
            $this->dropbox_exchange_code( $code );
        }
    }

    private function gdrive_exchange_code( $code ) {
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'wprb_gdrive_client_id' ),
                'client_secret' => get_option( 'wprb_gdrive_secret' ),
                'redirect_uri'  => self::get_oauth_redirect_url(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['access_token'] ) ) {
                update_option( 'wprb_gdrive_token', wp_json_encode( $body ) );
            }
        }
    }

    private function dropbox_exchange_code( $code ) {
        $response = wp_remote_post( 'https://api.dropbox.com/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    get_option( 'wprb_dropbox_app_key' ) . ':' . get_option( 'wprb_dropbox_secret' )
                ),
            ],
            'body' => [
                'code'         => $code,
                'grant_type'   => 'authorization_code',
                'redirect_uri' => self::get_oauth_redirect_url(),
            ],
        ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['access_token'] ) ) {
                update_option( 'wprb_dropbox_token', wp_json_encode( $body ) );
            }
        }
    }

    // ─────────────────────────────────────────────
    // Backup Management
    // ─────────────────────────────────────────────

    /**
     * List all local backups.
     */
    public function list_backups() {
        $backups = [];

        if ( ! is_dir( WPRB_BACKUP_DIR ) ) {
            return $backups;
        }

        $dirs = glob( WPRB_BACKUP_DIR . 'backup-*', GLOB_ONLYDIR );

        foreach ( $dirs as $dir ) {
            $backup_id = basename( $dir );
            $files     = glob( $dir . '/*' );
            $meta_file = $dir . '/backup-meta.json';
            $meta      = file_exists( $meta_file ) ? json_decode( file_get_contents( $meta_file ), true ) : [];
            
            $is_local_deleted = ! empty( $meta['local_deleted'] );
            $size = 0;
            $file_info = [];

            // If local files are deleted, rely on metadata for file list
            if ( $is_local_deleted && ! empty( $meta['files'] ) ) {
                foreach ( $meta['files'] as $filename ) {
                    // We don't know the exact size of each file if deleted, 
                    // unless we stored it in meta. For now, we list them without size or handle gracefully.
                    // TODO: Improve meta to store file sizes.
                    $file_info[] = [
                        'name' => $filename,
                        'size' => 'Cloud', // Indicator
                        'url'  => '#', // No local download
                        'missing' => true,
                    ];
                }
            } else {
                foreach ( $files as $file ) {
                    if ( is_file( $file ) && basename( $file ) !== 'file_list.txt' && basename( $file ) !== 'backup-meta.json' && basename( $file ) !== 'restore.log' ) {
                        $fsize = filesize( $file );
                        $size += $fsize;
                        $file_info[] = [
                            'name' => basename( $file ),
                            'size' => size_format( $fsize ),
                            'url'  => $this->get_download_url( $backup_id, basename( $file ) ),
                        ];
                    }
                }
            }

            $backups[] = [
                'id'            => $backup_id,
                'date'          => $meta['date'] ?? date( 'Y-m-d H:i:s', filemtime( $dir ) ),
                'size'          => $is_local_deleted ? 'Cloud' : size_format( $size ),
                'size_raw'      => $size,
                'type'          => $meta['type'] ?? 'full',
                'files'         => $file_info,
                'file_count'    => count( $file_info ),
                'local_deleted' => $is_local_deleted,
            ];
        }

        // Sort newest first
        usort( $backups, function( $a, $b ) {
            return strcmp( $b['date'], $a['date'] );
        } );

        return $backups;
    }

    /**
     * Delete a backup.
     */
    public function delete_backup( $backup_id ) {
        $dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id );

        if ( ! is_dir( $dir ) ) {
            return false;
        }

        return $this->recursive_rmdir( $dir );
    }

    /**
     * Remove heavy local files but keep backup-meta.json.
     */
    public function delete_local_files_only( $backup_id ) {
        $dir = WPRB_BACKUP_DIR . sanitize_file_name( $backup_id );
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        $files = scandir( $dir );
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) continue;
            
            // Keep metadata and log
            if ( $file === 'backup-meta.json' || $file === 'restore.log' ) continue;

            $path = $dir . '/' . $file;
            if ( is_file( $path ) ) {
                unlink( $path );
            }
        }
        
        // Update meta to reflect local deletion
        $meta_file = $dir . '/backup-meta.json';
        if ( file_exists( $meta_file ) ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            $meta['local_deleted'] = true;
            file_put_contents( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT ) );
        }
        
        return true;
    }

    private function recursive_rmdir( $dir ) {
        if ( ! is_dir( $dir ) ) return false;

        $files = array_diff( scandir( $dir ), [ '.', '..' ] );
        foreach ( $files as $file ) {
            ( is_dir( "$dir/$file" ) ) ? $this->recursive_rmdir( "$dir/$file" ) : unlink( "$dir/$file" );
        }
        return rmdir( $dir );
    }

    /**
     * Enforce retention policy.
     */
    public function enforce_retention() {
        $retention = (int) get_option( 'wprb_retention', 5 );
        if ( $retention < 1 ) return;

        $backups = $this->list_backups();

        if ( count( $backups ) <= $retention ) {
            return;
        }

        $to_delete = array_slice( $backups, $retention );

        foreach ( $to_delete as $backup ) {
            $this->delete_backup( $backup['id'] );
            $this->storage_log( 'Retention: Altes Backup gelöscht: ' . $backup['id'] );
        }
    }
}
