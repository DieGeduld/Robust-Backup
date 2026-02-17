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
     * Process one step of the upload distribution.
     * Prevents timeouts by uploading incrementally.
     * 
     * @param string $backup_id
     * @param array $files
     * @param array $state Current upload state
     * @return array New state and result
     */
    public function process_upload_step( $backup_id, $files, $state, $storage_override = null ) {
        $storages = ! empty( $storage_override ) ? $storage_override : (array) get_option( 'wprb_storage', [ 'local' ] );
        
        // Initialize state if empty
        if ( empty( $state ) ) {
            $state = [
                'current_storage_index' => 0,
                'current_file_index'    => 0,
                'storage_states'        => [],
                'results'               => [],
                'phase_start_time'      => time(),
                'upload_start_time'     => microtime( true ), // Precise start time
            ];
            // Initialize results
            foreach ( $storages as $s ) {
                $state['results'][ $s ] = [ 'success' => true, 'message' => 'Start...' ];
            }
        }

        // Execution time limit for THIS request step
        $execution_start_time = time();
        $time_limit = 20; // 20 seconds per request

        while ( $state['current_storage_index'] < count( $storages ) ) {
            
            // Check if we exceeded the time limit for this request
            if ( time() - $execution_start_time > $time_limit ) {
                $stats = $this->calculate_upload_stats( $state, count( $storages ), $files );
                $speed_msg = $stats['speed_formatted'] ? " (" . $stats['speed_formatted'] . ")" : "";
                
                return [
                    'done'     => false,
                    'state'    => $state,
                    'message'  => 'Upload läuft...' . $speed_msg, 
                    'progress' => $stats['progress'],
                    'stats'    => $stats,
                ];
            }

            $current_storage = $storages[ $state['current_storage_index'] ];
            $result = null;

            switch ( $current_storage ) {
                case 'local':
                    // Local is fast, do it in one go (but check if already done)
                    if ( empty( $state['storage_states']['local_done'] ) ) {
                        $res = $this->store_local( $backup_id, $files );
                        $state['results']['local'] = $res;
                        $state['storage_states']['local_done'] = true;
                    }
                    $result = [ 'done' => true ]; 
                    break;

                case 'dropbox':
                    // Pass execution start time so dropbox step also knows about time limit
                    $result = $this->store_dropbox_step( $backup_id, $files, $state, $execution_start_time, $time_limit );
                    break;
                
                case 'gdrive':
                    $result = $this->store_gdrive_step( $backup_id, $files, $state, $execution_start_time, $time_limit );
                    break;

                case 'sftp':
                    $result = $this->store_sftp_step( $backup_id, $files, $state, $execution_start_time, $time_limit );
                    break;
            }

            if ( $result && $result['done'] ) {
                // Storage finished
                if ( isset( $result['result'] ) ) {
                    $state['results'][ $current_storage ] = $result['result'];
                }
                $state['current_storage_index']++;
                // Reset file index/state for next storage
                $state['current_file_index'] = 0;
                unset( $state['current_upload_session'] );
                unset( $state['dropbox_session'] );
                unset( $state['gdrive_session'] );
            } else {
                $stats = $this->calculate_upload_stats( $state, count( $storages ), $files );
                $speed_msg = $stats['speed_formatted'] ? " (" . $stats['speed_formatted'] . ")" : "";

                return [
                    'done'     => false,
                    'state'    => $state,
                    'message'  => ($result['message'] ?? 'Upload...') . $speed_msg,
                    'progress' => $stats['progress'],
                    'stats'    => $stats,
                ];
            }
        }

        return [
            'done'    => true,
            'state'   => $state,
            'results' => $state['results'],
            'message' => 'Upload abgeschlossen.',
            'progress' => 100,
        ];
    }

    private function calculate_upload_stats( $state, $total_storages, $files ) {
        if ( $total_storages === 0 ) return [ 'progress' => 100, 'speed' => 0, 'speed_formatted' => '' ];
        
        $total_files = count( $files );
        $total_bytes_all_files = 0;
        foreach ($files as $f) {
            if (file_exists($f)) $total_bytes_all_files += filesize($f);
        }
        
        $total_bytes_expected = $total_bytes_all_files * $total_storages;
        
        // Calculate uploaded bytes
        $uploaded_bytes = 0;
        
        // 1. Completed storages
        $uploaded_bytes += $state['current_storage_index'] * $total_bytes_all_files;
        
        // 2. Completed files in current storage
        for ($i = 0; $i < $state['current_file_index']; $i++) {
            if (isset($files[$i]) && file_exists($files[$i])) {
                $uploaded_bytes += filesize($files[$i]);
            }
        }
        
        // 3. Current file progress (chunk)
        if ( isset( $files[ $state['current_file_index'] ] ) ) {
            // Check known session providers for offset
            $offset = 0;
            if ( isset( $state['dropbox_session']['offset'] ) ) {
                $offset = $state['dropbox_session']['offset'];
            } elseif ( isset( $state['gdrive_session']['offset'] ) ) {
                $offset = $state['gdrive_session']['offset'];
            }
            $uploaded_bytes += $offset;
        }

        // Percentage
        $progress = ($total_bytes_expected > 0) ? ($uploaded_bytes / $total_bytes_expected) * 100 : 0;
        
        // Speed
        $start_time = $state['upload_start_time'] ?? microtime(true);
        $elapsed = microtime(true) - $start_time;
        if ($elapsed < 1) $elapsed = 1; // Avoid div by zero
        
        $speed = $uploaded_bytes / $elapsed; // bytes per second
        
        return [
            'progress'        => round( $progress, 1 ),
            'uploaded_bytes'  => $uploaded_bytes,
            'total_bytes'     => $total_bytes_expected,
            'speed'           => $speed,
            'speed_formatted' => size_format( $speed ) . '/s',
        ];
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

    // ... (Keep existing GDrive/Local methods) ...

    /**
     * Resumable Dropbox Storage Step
     */
    private function store_dropbox_step( $backup_id, $files, &$state, $execution_start_time = 0, $time_limit = 15 ) {
        $token = get_option( 'wprb_dropbox_token', '' );
        if ( empty( $token ) ) {
            return [ 'done' => true, 'result' => [ 'success' => false, 'message' => 'Dropbox nicht konfiguriert.' ] ];
        }

        // Setup token state if not present
        if ( ! isset( $state['dropbox_token_refreshed'] ) ) {
             $token_data   = json_decode( $token, true );
             $access_token = $this->dropbox_refresh_token( $token_data );
             if ( ! $access_token ) {
                 return [ 'done' => true, 'result' => [ 'success' => false, 'message' => 'Token konnte nicht erneuert werden.' ] ];
             }
             $state['dropbox_access_token'] = $access_token;
             $state['dropbox_token_refreshed'] = true;
             $this->storage_log( 'Dropbox: Access Token erhalten.' );
        }

        $access_token = $state['dropbox_access_token'];
        $folder_name  = $this->get_storage_folder_name();
        
        // Initialize error list if not present
        if ( ! isset( $state['dropbox_errors'] ) ) {
            $state['dropbox_errors'] = [];
            $state['dropbox_uploaded_count'] = 0;
        }

        if ( $execution_start_time === 0 ) $execution_start_time = time();

        while ( $state['current_file_index'] < count( $files ) ) {
            // Check global time limit via parent loop (we return control regularly)
            if ( time() - $execution_start_time > $time_limit ) {
                $current_file_name = basename( $files[ $state['current_file_index'] ] ?? '' );
                return [ 'done' => false, 'message' => 'Dropbox Upload: ' . $current_file_name . ' läuft...' ];
            }

            $current_file = $files[ $state['current_file_index'] ];

            if ( ! file_exists( $current_file ) ) {
                $state['current_file_index']++;
                continue;
            }

            $dest = '/' . $folder_name . '/' . $backup_id . '/' . basename( $current_file );

            // Upload Chunk Step
            $res = $this->dropbox_upload_file_chunked_step( $access_token, $current_file, $dest, $state );

            if ( $res['done'] ) {
                if ( $res['success'] ) {
                    $state['dropbox_uploaded_count']++;
                    $this->storage_log( 'Dropbox: Upload OK: ' . basename( $current_file ) );
                } else {
                    $state['dropbox_errors'][] = basename( $current_file ) . ' (' . $res['message'] . ')';
                    $this->storage_log( 'Dropbox: Upload FEHLER: ' . basename( $current_file ) . ' - ' . $res['message'] );
                }
                
                // Move to next file
                $state['current_file_index']++;
                unset( $state['dropbox_session'] ); // Clear session
            } else {
                return [ 'done' => false, 'message' => 'Dropbox: ' . basename( $current_file ) . ' (' . $res['progress'] . ')' ];
            }
        }

        // All files done
        return [
            'done' => true,
            'result' => [
                'success' => empty( $state['dropbox_errors'] ),
                'message' => sprintf(
                    'Dropbox: %d/%d Dateien hochgeladen.%s',
                    $state['dropbox_uploaded_count'],
                    count( $files ),
                    ! empty( $state['dropbox_errors'] ) ? ' Fehler bei: ' . implode( ', ', $state['dropbox_errors'] ) : ''
                ),
            ],
        ];
    }
    
    /**
     * Upload a single chunk or start session.
     */
    private function dropbox_upload_file_chunked_step( $access_token, $filepath, $dest_path, &$state ) {
        $file_size  = filesize( $filepath );
        $chunk_size = 4 * 1024 * 1024; // Reduce to 4 MB per request to stay safe
        
        // Check if session exists in state
        if ( ! isset( $state['dropbox_session'] ) ) {
            $state['dropbox_session'] = [
                'id' => null,
                'offset' => 0,
            ];
            
            // If file is small, just do simple upload in one go? 
            // Better to standardize on chunked for reliability in this step-mode.
            // But simple is safer for tiny files.
            if ( $file_size < $chunk_size ) {
                $res = $this->dropbox_simple_upload( $access_token, $filepath, $dest_path, $file_size );
                return [ 'done' => true, 'success' => ( $res === true ), 'message' => ( $res === true ? 'OK' : $res ) ];
            }
        }
        
        $session = &$state['dropbox_session'];
        
        $fh = fopen( $filepath, 'rb' );
        fseek( $fh, $session['offset'] );
        $chunk = fread( $fh, $chunk_size );
        fclose( $fh );
        
        $current_chunk_size = strlen( $chunk );
        
        // 1. Start Session
        if ( $session['offset'] === 0 && $session['id'] === null ) {
            $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/start', [
                'timeout' => 45,
                'headers' => [
                    'Authorization'   => 'Bearer ' . $access_token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( [ 'close' => false ] ),
                ],
                'body' => $chunk,
            ] );

            if ( is_wp_error( $response ) ) return [ 'done' => true, 'success' => false, 'message' => $response->get_error_message() ];
            
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! isset( $body['session_id'] ) ) return [ 'done' => true, 'success' => false, 'message' => 'Start Failed' ];
            
            $session['id'] = $body['session_id'];
            $session['offset'] += $current_chunk_size;
            
            return [ 'done' => false, 'progress' => size_format($session['offset']) . ' / ' . size_format($file_size) ];
        }
        
        // 2. Append or Finish
        $is_last_chunk = ( $session['offset'] + $current_chunk_size >= $file_size );
        
        if ( ! $is_last_chunk ) {
            // Append
             $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/append_v2', [
                'timeout' => 45,
                'headers' => [
                    'Authorization'   => 'Bearer ' . $access_token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( [
                        'cursor' => [ 'session_id' => $session['id'], 'offset' => $session['offset'] ],
                        'close' => false,
                    ] ),
                ],
                'body' => $chunk,
            ] );
            
            if ( is_wp_error( $response ) ) return [ 'done' => true, 'success' => false, 'message' => 'Append Failed' ];
            
            if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return [ 'done' => true, 'success' => false, 'message' => 'Append HTTP Error' ];
            
            $session['offset'] += $current_chunk_size;
            return [ 'done' => false, 'progress' => size_format($session['offset']) . ' / ' . size_format($file_size) ];
            
        } else {
            // Finish
            $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/upload_session/finish', [
                'timeout' => 120,
                'headers' => [
                    'Authorization'   => 'Bearer ' . $access_token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( [
                        'cursor' => [ 'session_id' => $session['id'], 'offset' => $session['offset'] ],
                        'commit' => [ 'path' => $dest_path, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true ],
                    ] ),
                ],
                'body' => $chunk,
            ] );
            
             if ( is_wp_error( $response ) ) return [ 'done' => true, 'success' => false, 'message' => 'Finish Failed: ' . $response->get_error_message() ];
             if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
                 // return [ 'done' => true, 'success' => false, 'message' => 'Finish HTTP Error: ' . wp_remote_retrieve_body($response) ];
                 // Be verbose for debug
                 return [ 'done' => true, 'success' => false, 'message' => 'Finish Error' ];
             }
             
             return [ 'done' => true, 'success' => true ];
        }
    }

    /**
     * Get download URL for a backup file from a specific storage.
     */
    public function get_download_url( $backup_id, $filename, $storage = 'local' ) {
        return add_query_arg( [
            'action'    => 'wprb_download',
            'backup_id' => sanitize_file_name( $backup_id ),
            'file'      => basename( $filename ), // match disk name exactly
            'storage'   => sanitize_text_field( $storage ),
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
        // Use basename to preserve characters like spaces, but prevent traversal
        $filename  = basename( $_GET['file'] ?? '' );
        $storage   = sanitize_text_field( $_GET['storage'] ?? 'local' );
        $nonce     = $_GET['_wpnonce'] ?? '';

        if ( ! wp_verify_nonce( $nonce, 'wprb_download_' . $backup_id ) ) {
            wp_die( 'Invalid nonce' );
        }

        // 1. Dropbox
        if ( $storage === 'dropbox' ) {
            $link = $this->get_dropbox_temp_link( $backup_id, $filename );
            if ( $link ) {
                wp_redirect( $link );
                exit;
            }
            wp_die( 'Die Datei wurde in Dropbox nicht gefunden. Möglicherweise wurde der Ordner verschoben oder gelöscht.' );
        }

        // 2. Google Drive
        if ( $storage === 'gdrive' ) {
             $link = $this->get_gdrive_temp_link( $backup_id, $filename );
             if ( $link ) {
                 wp_redirect( $link );
                 exit;
             }
             wp_die( 'Konnte Google Drive Link nicht abrufen.' );
        }

        // 3. SFTP
        if ( $storage === 'sftp' ) {
             $this->stream_sftp_file( $backup_id, $filename );
             exit;
        }

        // 4. Local (Default)
        $filepath = WPRB_BACKUP_DIR . $backup_id . '/' . $filename;

        if ( ! file_exists( $filepath ) ) {
            wp_die( 'Datei lokal nicht gefunden.' );
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

    private function get_dropbox_temp_link( $backup_id, $filename ) {
        $token = get_option( 'wprb_dropbox_token', '' );
        if ( empty( $token ) ) return false;
        
        $token_data = json_decode( $token, true );
        $access_token = $this->dropbox_refresh_token( $token_data );
        if ( ! $access_token ) return false;

        $folder_name = $this->get_storage_folder_name();
        
        // Potential paths to try
        $paths_to_try = [
            '/' . $folder_name . '/' . $backup_id . '/' . $filename, // Standard
            '/WP-Backups/' . $backup_id . '/' . $filename,           // Legacy
            '/' . $backup_id . '/' . $filename,                      // Root backup
        ];

        foreach ( $paths_to_try as $path ) {
            $res = $this->dropbox_get_link_for_path( $access_token, $path );
            if ( $res['success'] ) {
                return $res['link'];
            }
        }

        // Deep Search: If direct paths fail, search for the file inside the backup folder (if we can guess the folder)
        // Let's try to list files in the expected backup folder and find a match
        $search_folders = [ '/' . $folder_name . '/' . $backup_id, '/WP-Backups/' . $backup_id ];
        
        foreach ( $search_folders as $folder ) {
            $found_path = $this->dropbox_search_file_in_folder( $access_token, $folder, $filename );
            if ( $found_path ) {
                 $res = $this->dropbox_get_link_for_path( $access_token, $found_path );
                 if ( $res['success'] ) return $res['link'];
            }
        }

        return false;
    }

    private function dropbox_get_link_for_path( $access_token, $path ) {
        $response = wp_remote_post( 'https://api.dropboxapi.com/2/files/get_temporary_link', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 'path' => $path ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'HTTP Error: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $code === 200 && isset( $body['link'] ) ) {
            return [ 'success' => true, 'link' => $body['link'] ];
        }
        
        $error_summary = $body['error_summary'] ?? 'Unknown API Error';
        return [ 'success' => false, 'error' => $error_summary ];
    }

    private function dropbox_list_folders( $access_token, $path = '' ) {
        $response = wp_remote_post( 'https://api.dropboxapi.com/2/files/list_folder', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 
                'path' => $path, // Empty string for root
                'recursive' => false,
                'include_media_info' => false
            ] ),
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
             return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $entries = $body['entries'] ?? [];
        $folders = [];
        foreach ( $entries as $entry ) {
            // Collect folders and maybe files if useful, but folders are main interest
            if ( $entry['.tag'] === 'folder' ) {
                $folders[] = $entry['path_display']; // Use path_display for better readability
            }
        }
        return $folders;
    }



    private function dropbox_search_file_in_folder( $access_token, $folder, $filename ) {
        $response = wp_remote_post( 'https://api.dropboxapi.com/2/files/list_folder', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 
                'path' => $folder,
                'recursive' => false,
                'include_media_info' => false
            ] ),
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
             return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $entries = $body['entries'] ?? [];
        
        foreach ( $entries as $entry ) {
            if ( $entry['.tag'] === 'file' && $entry['name'] === $filename ) {
                return $entry['path_lower']; // or path_display
            }
        }
        
        return false;
    }

    private function get_gdrive_temp_link( $backup_id, $filename ) {
        $token = get_option( 'wprb_gdrive_token', '' );
        if ( empty( $token ) ) return false;
        
        $token_data = json_decode( $token, true );
        $access_token = $this->gdrive_refresh_token( $token_data );
        if ( ! $access_token ) return false;

        // 1. Find file ID
        // We need to traverse: Root -> Backup Folder -> File
        $folder_name = 'WP-Backup-' . $this->get_storage_folder_name();
        $root_id     = $this->gdrive_get_folder_id( $access_token, $folder_name );
        
        if ( ! $root_id ) return false;
        
        $backup_folder_id = $this->gdrive_get_folder_id( $access_token, $backup_id, $root_id );
        if ( ! $backup_folder_id ) return false;
        
        // Find file
        $query = "name = '" . str_replace( "'", "\'", $filename ) . "' and '" . $backup_folder_id . "' in parents and trashed = false";
        $url   = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode( $query ) . '&fields=files(id,webContentLink)';
        
        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );
        
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return false;
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['files'] ) ) return false;
        
        // Return webContentLink (direct download) if available, or fallback
        $file_id = $body['files'][0]['id'];
        
        // Ensure the file is downloadable
        return $body['files'][0]['webContentLink'] ?? 'https://drive.google.com/uc?id=' . $file_id . '&export=download';
    }

    private function stream_sftp_file( $backup_id, $filename ) {
        $host  = get_option( 'wprb_sftp_host' );
        $user  = get_option( 'wprb_sftp_user' );
        $port  = (int) get_option( 'wprb_sftp_port', 22 );
        $pass  = get_option( 'wprb_sftp_pass' );
        $path  = get_option( 'wprb_sftp_path', '/' );
        $proto = get_option( 'wprb_sftp_proto', 'sftp' );

        $remote_path = rtrim( $path, '/' ) . '/' . $this->get_storage_folder_name() . '/' . $backup_id . '/' . $filename;

        // Handle FTP
        if ( $proto === 'ftp' ) {
            if ( ! function_exists( 'ftp_connect' ) ) wp_die( 'FTP Extension missing' );
            
            $conn_id = ftp_connect( $host, $port );
            if ( ! $conn_id ) wp_die( 'FTP Connection failed' );
            if ( ! @ftp_login( $conn_id, $user, $pass ) ) wp_die( 'FTP Auth failed' );
            
            ftp_pasv( $conn_id, true );
            
            $size = ftp_size( $conn_id, $remote_path );
            
            header( 'Content-Description: File Transfer' );
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Transfer-Encoding: binary' );
            if ( $size != -1 ) header( 'Content-Length: ' . $size );
            header( 'Cache-Control: must-revalidate' );
            header( 'Pragma: public' );
            
            if ( ob_get_level() ) ob_end_clean();
            
            $out = fopen( 'php://output', 'w' );
            ftp_fget( $conn_id, $out, $remote_path, FTP_BINARY );
            fclose( $out );
            ftp_close( $conn_id );
            exit;
        }
        
        // Handle SFTP (Default)
        if ( ! function_exists( 'ssh2_connect' ) ) wp_die( 'SFTP Extension missing' );
        
        $connection = ssh2_connect( $host, $port );
        if ( ! $connection ) wp_die( 'SFTP Connection failed' );
        if ( ! ssh2_auth_password( $connection, $user, $pass ) ) wp_die( 'SFTP Auth failed' );
        
        $sftp = ssh2_sftp( $connection );
        
        $stream_path = "ssh2.sftp://$sftp" . $remote_path;
        
        if ( ! file_exists( $stream_path ) ) wp_die( 'File not found on SFTP' );
        
        $size = filesize( $stream_path );
        
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        if ( $size ) header( 'Content-Length: ' . $size );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        
        if ( ob_get_level() ) ob_end_clean();
        
        $fh = fopen( $stream_path, 'rb' );
        fpassthru( $fh );
        fclose( $fh );
        exit;
    }

    // ─────────────────────────────────────────────
    // Google Drive
    // ─────────────────────────────────────────────

    /**
     * Upload to Google Drive (Step-based).
     */
    private function store_gdrive_step( $backup_id, $files, &$state, $execution_start_time, $time_limit ) {
        // Init State
        if ( ! isset( $state['gdrive_file_index'] ) ) $state['gdrive_file_index'] = 0;

        $token = get_option( 'wprb_gdrive_token', '' );
        if ( empty( $token ) ) {
            $state['results']['gdrive'] = [ 'success' => false, 'message' => 'Google Drive nicht konfiguriert.' ];
            return [ 'done' => true ];
        }

        $token_data = json_decode( $token, true );
        $access_token = $this->gdrive_refresh_token( $token_data );
        if ( ! $access_token ) {
            $state['results']['gdrive'] = [ 'success' => false, 'message' => 'Google Drive Token Error.' ];
            return [ 'done' => true ];
        }

        // Folder Creation (Only once)
        if ( ! isset( $state['gdrive_folder_id'] ) ) {
            $root_name = 'WP-Backup-' . $this->get_storage_folder_name();
            $root_id   = $this->gdrive_get_folder_id( $access_token, $root_name );
            
            if ( ! $root_id ) {
                $root_id = $this->gdrive_create_folder( $access_token, $root_name );
            }
            
            if ( $root_id ) {
                $folder_id = $this->gdrive_create_folder( $access_token, $backup_id, $root_id );
                $state['gdrive_folder_id'] = $folder_id;
            } else {
                $state['results']['gdrive'] = [ 'success' => false, 'message' => 'Google Drive Root Folder Error.' ];
                return [ 'done' => true ];
            }
        }

        // Loop Files
        while ( $state['gdrive_file_index'] < count( $files ) ) {
            // Time Check
            if ( time() - $execution_start_time > $time_limit ) {
                return [ 'done' => false, 'message' => 'Google Drive Upload...' ];
            }

            $file = $files[ $state['gdrive_file_index'] ];
            if ( ! file_exists( $file ) ) {
                $state['gdrive_file_index']++;
                continue;
            }

            // Upload Chunk
            $res = $this->gdrive_upload_file_chunked_step( $access_token, $file, $state['gdrive_folder_id'], $state );

            if ( $res['done'] ) {
                $state['gdrive_file_index']++;
                unset( $state['gdrive_session'] ); // Clear session for next file
                
                if ( $res['error'] ) {
                    // Log error but continue? For now we just count it as done (failed).
                    $this->storage_log( 'GDrive Upload Failed: ' . basename( $file ) );
                }
            } else {
                 // Chunk uploaded, need more steps
                 return [ 'done' => false, 'message' => 'Uploading to GDrive: ' . basename( $file ) . ' (' . round( $res['progress'] ) . '%)' ];
            }
        }

        $state['results']['gdrive'] = [ 'success' => true, 'message' => 'Upload zu Google Drive abgeschlossen.' ];
        return [ 'done' => true ];
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
     * Check if a folder exists and return its ID.
     */
    private function gdrive_get_folder_id( $access_token, $name, $parent_id = null ) {
        $query = "mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace( "'", "\'", $name ) . "' and trashed = false";
        if ( $parent_id ) {
            $query .= " and '" . $parent_id . "' in parents";
        }

        $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode( $query );
        
        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['files'] ) ) {
            return $body['files'][0]['id'];
        }
        return null;
    }

    /**
     * Create a folder on Google Drive.
     */
    private function gdrive_create_folder( $access_token, $name, $parent_id = null ) {
        $body = [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        if ( $parent_id ) {
            $body['parents'] = [ $parent_id ];
        }

        $response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['id'] ?? null;
    }

    /**
     * Upload a file chunk to Google Drive.
     */
    private function gdrive_upload_file_chunked_step( $access_token, $filepath, $folder_id, &$state ) {
        $file_size = filesize( $filepath );
        $filename  = basename( $filepath );

        // Initialize Session
        if ( ! isset( $state['gdrive_session'] ) || $state['gdrive_session']['file'] !== $filepath ) {
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
                return [ 'done' => true, 'error' => true ];
            }

            $upload_url = wp_remote_retrieve_header( $response, 'location' );
            if ( empty( $upload_url ) ) {
                return [ 'done' => true, 'error' => true ];
            }

            $state['gdrive_session'] = [
                'file'       => $filepath,
                'upload_url' => $upload_url,
                'offset'     => 0,
            ];
        }

        // Process Chunk
        $upload_url = $state['gdrive_session']['upload_url'];
        $offset     = $state['gdrive_session']['offset'];
        $chunk_size = 2 * 1024 * 1024; // 2 MB Chunks (kleiner für mehr Responsiveness)

        // Read chunk
        $fh = fopen( $filepath, 'rb' );
        if ( ! $fh ) return [ 'done' => true, 'error' => true ]; // Should not happen
        fseek( $fh, $offset );
        $chunk = fread( $fh, $chunk_size );
        fclose( $fh );

        $length = strlen( $chunk );
        $end    = $offset + $length - 1;

        // Send Chunk
        $response = wp_remote_request( $upload_url, [
            'method'  => 'PUT',
            'timeout' => 60,
            'headers' => [
                'Content-Length' => $length,
                'Content-Range'  => "bytes {$offset}-{$end}/{$file_size}",
            ],
            'body' => $chunk,
        ] );

        $status = wp_remote_retrieve_response_code( $response );

        if ( $status === 308 ) {
            // Resume Incomplete
            $state['gdrive_session']['offset'] += $length;
            $percent = ($state['gdrive_session']['offset'] / $file_size) * 100;
            return [ 'done' => false, 'error' => false, 'progress' => $percent ];
        } elseif ( $status === 200 || $status === 201 ) {
            // Done
            return [ 'done' => true, 'error' => false ];
        } else {
            // Error
            $this->storage_log( 'GDrive Chunk Error: ' . $status . ' - ' . wp_remote_retrieve_body($response) );
            return [ 'done' => true, 'error' => true ];
        }
    }

    // ─────────────────────────────────────────────
    // Dropbox
    // ─────────────────────────────────────────────

    /**
     * Get the storage folder name based on domain.
     */
    private function get_storage_folder_name() {
        $url = parse_url( get_site_url(), PHP_URL_HOST );
        // Remove www. and sanitize
        $name = str_replace( 'www.', '', $url );
        return preg_replace( '/[^a-zA-Z0-9\._-]/', '', $name );
    }

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
        
        $folder_name = $this->get_storage_folder_name();

        $uploaded = 0;
        $errors   = [];

        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) ) {
                $this->storage_log( 'Dropbox: Datei nicht gefunden: ' . $file );
                continue;
            }

            $dest   = '/' . $folder_name . '/' . $backup_id . '/' . basename( $file );
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

        $folder_name = $this->get_storage_folder_name();
        $source_path = '/' . $folder_name . '/' . $backup_id . '/' . $filename;
        
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
            // Fallback: Check if it exists in the old default folder (WP-Backups)
            if ( $folder_name !== 'WP-Backups' && ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 409 ) {
                 // 409 usually means path not found
                 $this->storage_log( 'Dropbox-Download: Nicht in Domain-Ordner gefunden, versuche Legacy-Ordner...' );
                 
                 $source_path_legacy = '/WP-Backups/' . $backup_id . '/' . $filename;
                 $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/download', [
                    'timeout' => 300,
                    'stream'  => true,
                    'filename' => $dest,
                    'headers' => [
                        'Authorization'   => 'Bearer ' . $access_token,
                        'Dropbox-API-Arg' => wp_json_encode( [ 'path' => $source_path_legacy ] ),
                    ],
                ] );
            }
        }

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
        $line = '[' . current_time( 'mysql' ) . '] ' . $message . "\n";
        file_put_contents( WPRB_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }

    // ─────────────────────────────────────────────
    // OAuth Helper (shared redirect handler)
    // ─────────────────────────────────────────────

    /**
     * Get the OAuth callback URL.
     */
    public static function get_oauth_redirect_url() {
        return admin_url( 'admin.php?page=wprb-storage&oauth_callback=1' );
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
        $success = false;

        if ( $state === 'gdrive' ) {
            $success = $this->gdrive_exchange_code( $code );
        } elseif ( $state === 'dropbox' ) {
            $success = $this->dropbox_exchange_code( $code );
        }

        if ( $success ) {
            wp_redirect( admin_url( 'admin.php?page=wprb-storage' ) );
            exit;
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
                return true;
            }
        }
        return false;
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
                return true;
            }
        }
        return false;
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
            if ( $is_local_deleted && ! empty( $meta['file_details'] ) ) {
                foreach ( $meta['file_details'] as $cur_file ) {
                    if ( basename( $cur_file['name'] ) === 'file_manifest.json.gz' ) continue;
                    $file_info[] = [
                        'name' => $cur_file['name'],
                        'size' => size_format( $cur_file['size'] ),
                        'missing' => true,
                    ];
                }
                $size = $meta['total_size'] ?? 0;
            } elseif ( $is_local_deleted && ! empty( $meta['files'] ) ) {
                 // Fallback for old meta format
                 foreach ( $meta['files'] as $filename ) {
                    $file_info[] = [
                        'name' => $filename,
                        'size' => 'Cloud',
                        'missing' => true,
                    ];
                }
            } else {
                foreach ( $files as $file ) {
                    if ( is_file( $file ) && basename( $file ) !== 'file_list.txt' && basename( $file ) !== 'backup-meta.json' && basename( $file ) !== 'restore.log' && basename( $file ) !== 'file_manifest.json.gz' ) {
                        $fsize = filesize( $file );
                        $size += $fsize;
                        $file_info[] = [
                            'name' => basename( $file ),
                            'size' => size_format( $fsize ),
                            // 'url' will be populated later based on storages
                        ];
                    }
                }
            }

            $storages = $meta['storages'] ?? [];
            if ( empty( $storages ) ) {
                 // Fallback for old backups
                 $storages = $is_local_deleted ? ['cloud'] : ['local'];
            }

            // Enrich file info with download links per storage
            foreach ( $file_info as &$f_item ) {
                $links = [];
                // 1. Local
                if ( ! $is_local_deleted ) {
                     $links['local'] = $this->get_download_url( $backup_id, $f_item['name'], 'local' );
                }
                // 2. Others
                foreach ( $storages as $st ) {
                    if ( $st !== 'local' && $st !== 'cloud' ) {
                         $links[ $st ] = $this->get_download_url( $backup_id, $f_item['name'], $st );
                    }
                    if ( $st === 'cloud' && $is_local_deleted ) {
                         // guess cloud means dropbox in old version
                         $links['dropbox'] = $this->get_download_url( $backup_id, $f_item['name'], 'dropbox' );
                    }
                }
                $f_item['downloads'] = $links;
            }
            unset($f_item); // break ref

            $backups[] = [
                'id'            => $backup_id,
                'date'          => $meta['date'] ?? wp_date( 'Y-m-d H:i:s', filemtime( $dir ) ),
                'duration'      => $meta['duration'] ?? null,
                'size'          => size_format( $size ),
                'size_raw'      => $size,
                'type'          => $meta['type'] ?? 'full',
                'files'         => $file_info,
                'file_count'    => count( $file_info ),
                'local_deleted' => $is_local_deleted,
                'location'      => $is_local_deleted ? 'Cloud' : 'Lokal', // Deprecated but kept for compat
                'storages'      => $storages,
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
            
            // Keep metadata, manifest and log
            if ( $file === 'backup-meta.json' || $file === 'file_manifest.json.gz' || $file === 'restore.log' ) continue;

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

    /**
     * Store files to SFTP / FTP.
     */
    private function store_sftp_step( $backup_id, $files, &$state, $execution_start_time, $time_limit ) {
        // Init State
        if ( ! isset( $state['sftp_file_index'] ) ) $state['sftp_file_index'] = 0;

        $host  = get_option( 'wprb_sftp_host' );
        $user  = get_option( 'wprb_sftp_user' );
        $port  = (int) get_option( 'wprb_sftp_port', 22 );
        $pass  = get_option( 'wprb_sftp_pass' );
        $path  = get_option( 'wprb_sftp_path', '/' );
        $proto = get_option( 'wprb_sftp_proto', 'sftp' ); // sftp or ftp

        if ( empty( $host ) || empty( $user ) ) {
            $state['results']['sftp'] = [ 'success' => false, 'message' => 'SFTP nicht konfiguriert.' ];
            return [ 'done' => true ];
        }

        try {
            if ( ! class_exists( 'WPRB_SFTP_Helper' ) ) {
                require_once dirname( __FILE__ ) . '/class-sftp-helper.php';
            }
            
            // Connect
             $sftp = new WPRB_SFTP_Helper( $host, $port, $user, $pass, $proto );

            // Create target folder structure (Remote)
            $target_base = rtrim( $path, '/' ) . '/' . $this->get_storage_folder_name() . '/' . $backup_id;
            // First time: Create directory
            if ( $state['sftp_file_index'] === 0 ) {
                 if ( ! $sftp->mkdir( $target_base ) ) {
                     // Try to ignore error if exists? Helper mkdir handles it gracefully?
                 }
            }

            $current_index = $state['sftp_file_index'];
            
            while ( $current_index < count( $files ) ) {
                if ( time() - $execution_start_time > $time_limit ) {
                    $sftp->close();
                    $state['sftp_file_index'] = $current_index;
                    $current_file = basename( $files[ $current_index ] );
                    return [ 'done' => false, 'message' => "SFTP Upload: $current_file läuft..." ];
                }
                
                $file = $files[ $current_index ];
                if ( file_exists( $file ) ) {
                    $remote_file = $target_base . '/' . basename( $file );
                    if ( ! $sftp->upload( $file, $remote_file ) ) {
                         $this->storage_log( "SFTP Error uploading: " . basename( $file ) );
                         // Fail or continue?
                    } else {
                        $this->storage_log( "SFTP Upload OK: " . basename( $file ) );
                    }
                }
                
                $current_index++;
            }
            
            $sftp->close();
            
            $state['results']['sftp'] = [ 'success' => true, 'message' => 'Upload zu SFTP abgeschlossen.' ];
            return [ 'done' => true ];

        } catch ( Exception $e ) {
            $state['results']['sftp'] = [ 'success' => false, 'message' => 'SFTP Fehler: ' . $e->getMessage() ];
            return [ 'done' => true ];
        }
    }
}
