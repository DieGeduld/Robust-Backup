<?php
/**
 * Logger Class
 * 
 * Handles logging with automatic rotation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Logger {

    const MAX_LOG_SIZE = 10485760; // 10 MB

    /**
     * Write a log message.
     * 
     * @param string $message
     * @param string $level
     */
    public static function log( $message, $level = 'INFO' ) {
        if ( ! defined( 'WPRB_LOG_FILE' ) ) {
            return;
        }

        self::rotate_if_needed();

        $timestamp = wp_date( 'Y-m-d H:i:s' );
        $line      = "[{$timestamp}] [{$level}] {$message}\n";

        $dir = dirname( WPRB_LOG_FILE );
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        file_put_contents( WPRB_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Rotate log if it exceeds max size.
     */
    private static function rotate_if_needed() {
        if ( ! file_exists( WPRB_LOG_FILE ) ) {
            return;
        }

        $size = filesize( WPRB_LOG_FILE );
        if ( $size > self::MAX_LOG_SIZE ) {
            $rotated = WPRB_LOG_FILE . '.bak';
            if ( file_exists( $rotated ) ) {
                unlink( $rotated );
            }
            rename( WPRB_LOG_FILE, $rotated );
        }
    }
}
