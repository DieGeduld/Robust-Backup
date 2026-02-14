<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Crypto {

    // V2 Constants (GCM)
    const CIPHER = 'aes-256-gcm';
    const KDF_ALGO = 'sha256';
    const KDF_ITERATIONS = 600000;
    const CHUNK_SIZE = 1048576; // 1MB
    const TAG_LENGTH = 16;
    const IV_LENGTH = 12;
    const SALT_LENGTH = 32;

    // V1 Constants (Legacy)
    const V1_METHOD = 'aes-256-cbc';

    /**
     * Encrypt a file using AES-256-GCM (V2 Secure Standard).
     */
    public static function encrypt_file( $source_path, $dest_path, $passphrase ) {
        if ( ! file_exists( $source_path ) ) return [ 'error' => 'Source file not found' ];
        if ( empty( $passphrase ) ) return [ 'error' => 'No passphrase provided.' ];

        $fp_in  = fopen( $source_path, 'rb' );
        $fp_out = fopen( $dest_path, 'wb' );

        if ( ! $fp_in || ! $fp_out ) return [ 'error' => 'File IO error' ];

        // Header: Magic(4) + Version(1)
        fwrite( $fp_out, 'WPRB' . chr(2) );

        // Salt (32 bytes)
        $salt = random_bytes( self::SALT_LENGTH );
        fwrite( $fp_out, $salt );

        // Derive Key
        $key = hash_pbkdf2( self::KDF_ALGO, $passphrase, $salt, self::KDF_ITERATIONS, 32, true );

        $chunk_idx = 0;
        
        while ( ! feof( $fp_in ) ) {
            $chunk = fread( $fp_in, self::CHUNK_SIZE );
            if ( $chunk === false || $chunk === '' ) break;

            // Per-Chunk IV (12 bytes for GCM)
            $iv = random_bytes( self::IV_LENGTH );
            $tag = '';
            
            // Encrypt
            $ciphertext = openssl_encrypt( $chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
            
            if ( $ciphertext === false ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => 'Encryption failed at chunk ' . $chunk_idx ];
            }

            // Write: IV + Tag + Ciphertext
            fwrite( $fp_out, $iv . $tag . $ciphertext );
            $chunk_idx++;
        }

        fclose( $fp_in );
        fclose( $fp_out );

        return [ 'success' => true ];
    }

    /**
     * Decrypt a file (Supports V1 and V2).
     */
    public static function decrypt_file( $source_path, $dest_path, $passphrase ) {
        if ( ! file_exists( $source_path ) ) return [ 'error' => 'Source file not found' ];
        
        if ( empty( $passphrase ) ) {
            $passphrase = get_option( 'wprb_encryption_key' );
        }
        if ( empty( $passphrase ) ) return [ 'error' => 'Passphrase missing' ];

        $fp_in = fopen( $source_path, 'rb' );
        if ( ! $fp_in ) return [ 'error' => 'Could not open source file' ];

        // 1. Magic Check
        $magic = fread( $fp_in, 4 );
        if ( $magic !== 'WPRB' ) {
            fclose( $fp_in );
            return [ 'error' => 'Invalid file format (Not WPRB encrypted)' ];
        }

        // 2. Version Check
        $ver_char = fread( $fp_in, 1 );
        $ver = ord( $ver_char );

        // Rewind to allow specific methods to handle stream cleanly if needed (though we mostly just need to pass the stream or offset)
        // Actually, simplest is to pass the resource handle.
        // BUT: our methods assume they start reading AFTER header/version.
        // So we leave the pointer here.

        $fp_out = fopen( $dest_path, 'wb' );
        if ( ! $fp_out ) {
            fclose($fp_in);
            return [ 'error' => 'Could not create destination file' ];
        }

        if ( $ver === 2 ) {
            return self::decrypt_v2( $fp_in, $fp_out, $passphrase );
        } elseif ( $ver === 1 ) {
            return self::decrypt_v1( $fp_in, $fp_out, $passphrase );
        } else {
            fclose($fp_in); fclose($fp_out);
            return [ 'error' => "Unsupported version: $ver" ];
        }
    }

    private static function decrypt_v2( $fp_in, $fp_out, $pass ) {
        // Read Salt
        $salt = fread( $fp_in, self::SALT_LENGTH );
        if ( strlen($salt) !== self::SALT_LENGTH ) {
            fclose($fp_in); fclose($fp_out);
            return [ 'error' => 'File truncated (Salt)' ];
        }

        $key = hash_pbkdf2( self::KDF_ALGO, $pass, $salt, self::KDF_ITERATIONS, 32, true );
        $chunk_idx = 0;

        while ( ! feof( $fp_in ) ) {
            // Read IV
            $iv = fread( $fp_in, self::IV_LENGTH );
            if ( $iv === '' || $iv === false ) break; // EOF
            if ( strlen($iv) !== self::IV_LENGTH ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => "Corrupt chunk $chunk_idx (IV)" ];
            }

            // Read Tag
            $tag = fread( $fp_in, self::TAG_LENGTH );
            if ( strlen($tag) !== self::TAG_LENGTH ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => "Corrupt chunk $chunk_idx (Tag)" ];
            }

            // Read Ciphertext (Size = CHUNK_SIZE)
            // Note: Since GCM produces ciphertext of same length as plaintext, 
            // and we write in CHUNK_SIZE blocks, valid blocks are CHUNK_SIZE.
            // Last block is smaller.
            // We just read up to CHUNK_SIZE.
            $ciphertext = fread( $fp_in, self::CHUNK_SIZE );
            if ( $ciphertext === false ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => "Read error at chunk $chunk_idx" ];
            }
            if ( $ciphertext === '' ) break; // Should have been caught by IV read, but for safety

            // Decrypt
            $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
            if ( $plaintext === false ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => "Decryption failed at chunk $chunk_idx (Wrong password or corrupted data)" ];
            }

            fwrite( $fp_out, $plaintext );
            $chunk_idx++;
        }

        fclose( $fp_in );
        fclose( $fp_out );
        return [ 'success' => true ];
    }

    private static function decrypt_v1( $fp_in, $fp_out, $pass ) {
        // V1 Header: Magic(4)[Read] + Ver(1)[Read] + Salt(16) + IV(16)
        $salt = fread( $fp_in, 16 );
        $iv   = fread( $fp_in, 16 );

        // Weak KDF parameters from V1
        $key = hash_pbkdf2( 'sha256', $pass, $salt, 10000, 32, true );
        $current_iv = $iv;
        
        // We need total file size for V1 padding logic
        $stat = fstat( $fp_in );
        $fsize = $stat['size'];
        $header_size = 4 + 1 + 16 + 16;
        $ct_size = $fsize - $header_size;
        $processed = 0;

        while ( ! feof( $fp_in ) && $processed < $ct_size ) {
            $chunk = fread( $fp_in, self::CHUNK_SIZE );
            if ( ! $chunk ) break;
            
            $chunk_len = strlen( $chunk );
            $processed += $chunk_len;
            
            $next_iv = substr( $chunk, -16 );
            $pt = openssl_decrypt( $chunk, self::V1_METHOD, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv );
            
            if ( $pt === false ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => 'Decryption failed (Wrong password?)' ];
            }

            // Remove V1 Padding
            if ( $processed >= $ct_size ) {
                $pad = ord( substr( $pt, -1 ) );
                if ( $pad > 0 && $pad <= 16 ) {
                    // Check if padding is valid
                     $padding_valid = true;
                     $padding_content = substr( $pt, -$pad );
                     for($i=0; $i<$pad; $i++) {
                         if ( ord($padding_content[$i]) !== $pad ) $padding_valid = false;
                     }

                     if ( $padding_valid ) {
                         $pt = substr( $pt, 0, strlen($pt) - $pad );
                     }
                }
            }

            fwrite( $fp_out, $pt );
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
