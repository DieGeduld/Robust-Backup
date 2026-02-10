<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Crypto {

    const METHOD = 'aes-256-cbc';
    const CHUNK_SIZE = 1048576; // 1MB chunks (must be multiple of 16 for efficiency)

    /**
     * Encrypt a file using AES-256-CBC with manual chunking.
     * 
     * @param string $source_path      Input file path
     * @param string $dest_path        Output file path
     * @param string $passphrase       User password
     * @return array|bool Result array or false on failure
     */
    public static function encrypt_file( $source_path, $dest_path, $passphrase ) {
        if ( ! file_exists( $source_path ) ) {
            return [ 'error' => 'Source file not found' ];
        }

        if ( empty( $passphrase ) ) {
            return [ 'error' => 'No passphrase provided for encryption.' ];
        }

        $salt = openssl_random_pseudo_bytes( 16 );
        $iv   = openssl_random_pseudo_bytes( 16 );
        
        // Derive key: PBKDF2 with 10k iterations
        $key = hash_pbkdf2( 'sha256', $passphrase, $salt, 10000, 32, true );

        $fp_in  = fopen( $source_path, 'rb' );
        $fp_out = fopen( $dest_path, 'wb' );

        if ( ! $fp_in || ! $fp_out ) {
            return [ 'error' => 'File IO error' ];
        }

        // Write Header: Magic(4) + Version(1) + Salt(16) + IV(16)
        // Magic = WPRB
        fwrite( $fp_out, 'WPRB' . pack('C', 1) . $salt . $iv );

        $current_iv = $iv;
        
        // Get file size to handle padding on last chunk
        $fsize = filesize( $source_path );
        $processed = 0;

        while ( ! feof( $fp_in ) && $processed < $fsize ) {
            // Read up to CHUNK_SIZE
            $chunk = fread( $fp_in, self::CHUNK_SIZE );
            
            if ( $chunk === false ) break;
            
            $chunk_len = strlen( $chunk );
            if ( $chunk_len === 0 ) break;

            $processed += $chunk_len;
            $is_last = ( $processed >= $fsize );

            if ( $is_last ) {
                // PKCS7 Padding for the last block
                $pad = 16 - ( $chunk_len % 16 );
                $chunk .= str_repeat( chr( $pad ), $pad );
            } elseif ( $chunk_len % 16 !== 0 ) {
                // If not last chunk but length is not multiple of 16, something is wrong with read or file alignment?
                // Actually fread should return requested size unless EOF.
                // If we get partial read and it's NOT EOF (rare but possible with network/stream wrappers, unlikely for local file), 
                // we should theoretically loop to fill buffer. But for local files, short read usually means EOF.
                // We'll treat short read as end of stream logic above via $processed checks.
            }

            // Encrypt using OPENSSL_RAW_DATA and ZERO_PADDING (we manage padding manually)
            $ciphertext = openssl_encrypt( $chunk, self::METHOD, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv );

            if ( $ciphertext === false ) {
                fclose( $fp_in );
                fclose( $fp_out );
                return [ 'error' => 'Encryption failed: ' . openssl_error_string() ];
            }

            fwrite( $fp_out, $ciphertext );

            // Update IV for next chunk (last block of ciphertext)
            $current_iv = substr( $ciphertext, -16 );
        }

        // If file was empty, we still need to write padded block?
        // Yes, empty file -> pad 16 bytes of \x10.
        if ( $fsize === 0 ) {
            $pad = 16;
            $chunk = str_repeat( chr($pad), $pad );
            $ciphertext = openssl_encrypt( $chunk, self::METHOD, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv );
            fwrite( $fp_out, $ciphertext );
        }

        fclose( $fp_in );
        fclose( $fp_out );

        return [ 'success' => true ];
    }

    /**
     * Decrypt a file.
     */
    public static function decrypt_file( $source_path, $dest_path, $passphrase ) {
        if ( ! file_exists( $source_path ) ) {
            return [ 'error' => 'Source file not found' ];
        }
        
        if ( empty( $passphrase ) ) {
            // Try to get from options if empty
            $passphrase = get_option( 'wprb_encryption_key' );
        }
        
        if ( empty( $passphrase ) ) { 
             return [ 'error' => 'Passphrase missing' ];
        }

        $fp_in  = fopen( $source_path, 'rb' );
        $fp_out = fopen( $dest_path, 'wb' );

        if ( ! $fp_in || ! $fp_out ) {
            return [ 'error' => 'File IO error' ];
        }

        // Read Header
        $magic = fread( $fp_in, 4 );
        if ( $magic !== 'WPRB' ) {
            fclose( $fp_in );
            fclose( $fp_out );
            return [ 'error' => 'Invalid file format (Not WPRB encrypted)' ];
        }
        
        $ver   = unpack('C', fread( $fp_in, 1 ))[1]; // Version (unused for now)
        $salt  = fread( $fp_in, 16 );
        $iv    = fread( $fp_in, 16 );

        $key = hash_pbkdf2( 'sha256', $passphrase, $salt, 10000, 32, true );
        $current_iv = $iv;
        
        // Calculate Ciphertext Size
        $header_size = 4 + 1 + 16 + 16; // 37
        $fsize = filesize( $source_path );
        $ct_size = $fsize - $header_size;
        $processed = 0;

        while ( ! feof( $fp_in ) && $processed < $ct_size ) {
            // Read ciphertext
            $chunk = fread( $fp_in, self::CHUNK_SIZE );
            
            if ( $chunk === false ) break;
            
            $chunk_len = strlen( $chunk );
            if ( $chunk_len === 0 ) break;
            
            $processed += $chunk_len;
            $is_last = ( $processed >= $ct_size );

            // Save next IV before decrypting
            $next_iv = substr( $chunk, -16 );

            $plaintext = openssl_decrypt( $chunk, self::METHOD, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv );

            if ( $plaintext === false ) {
                fclose( $fp_in );
                fclose( $fp_out );
                return [ 'error' => 'Decryption failed (Wrong password?)' ];
            }

            // If Last Chunk, remove padding
            if ( $is_last ) {
                $pad = ord( substr( $plaintext, -1 ) );
                if ( $pad > 0 && $pad <= 16 ) {
                    // Start of padding
                    $valid_len = strlen( $plaintext ) - $pad;
                    if ( $valid_len >= 0 ) {
                        $plaintext = substr( $plaintext, 0, $valid_len );
                    }
                }
            }

            fwrite( $fp_out, $plaintext );
            $current_iv = $next_iv;
        }

        fclose( $fp_in );
        fclose( $fp_out );

        return [ 'success' => true ];
    }
    
    /**
     * Check if file is encrypted (checks Magic Header).
     */
    public static function is_encrypted( $file ) {
        if ( ! file_exists( $file ) ) return false;
        
        $fh = fopen( $file, 'rb' );
        if ( ! $fh ) return false;
        
        $magic = fread( $fh, 4 );
        fclose( $fh );
        
        return $magic === 'WPRB';
    }
}
