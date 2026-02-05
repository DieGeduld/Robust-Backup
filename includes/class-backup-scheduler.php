<?php
/**
 * Backup Scheduler
 * 
 * Manages WP-Cron based backup scheduling.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Backup_Scheduler {

    const CRON_HOOK = 'wprb_scheduled_backup';

    /**
     * Initialize scheduler hooks.
     */
    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_backup' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );
    }

    /**
     * Add custom cron intervals.
     */
    public static function add_cron_intervals( $schedules ) {
        $schedules['wprb_weekly'] = [
            'interval' => 604800,
            'display'  => 'Wöchentlich (WP Robust Backup)',
        ];
        $schedules['wprb_monthly'] = [
            'interval' => 2592000,
            'display'  => 'Monatlich (WP Robust Backup)',
        ];
        return $schedules;
    }

    /**
     * Schedule the backup cron event.
     */
    public static function schedule() {
        $schedule = get_option( 'wprb_schedule', 'daily' );
        $time     = get_option( 'wprb_schedule_time', '03:00' );

        if ( $schedule === 'disabled' ) {
            self::unschedule();
            return;
        }

        // Calculate next run time
        $parts    = explode( ':', $time );
        $hour     = (int) ( $parts[0] ?? 3 );
        $minute   = (int) ( $parts[1] ?? 0 );

        $next_run = strtotime( "today {$hour}:{$minute}" );
        if ( $next_run < time() ) {
            $next_run = strtotime( "tomorrow {$hour}:{$minute}" );
        }

        // Map schedule to WP cron recurrence
        $recurrence_map = [
            'hourly'  => 'hourly',
            'daily'   => 'daily',
            'weekly'  => 'wprb_weekly',
            'monthly' => 'wprb_monthly',
        ];

        $recurrence = $recurrence_map[ $schedule ] ?? 'daily';

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( $next_run, $recurrence, self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the backup cron event.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Run the scheduled backup.
     */
    public static function run_scheduled_backup() {
        // Increase limits for cron execution
        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $engine = new WPRB_Backup_Engine();
        $result = $engine->run_full_backup();

        // Log result
        $status  = empty( $result['errors'] ) ? 'OK' : 'mit Fehlern';
        $message = sprintf(
            '[Cron] Geplantes Backup %s: %s',
            $status,
            $result['message'] ?? ''
        );

        if ( ! empty( $result['errors'] ) ) {
            $message .= ' Fehler: ' . implode( ', ', $result['errors'] );
        }

        file_put_contents(
            WPRB_LOG_FILE,
            '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Get info about the next scheduled backup.
     */
    public static function get_next_scheduled() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $timestamp ) {
            return [
                'scheduled' => false,
                'message'   => 'Kein Backup geplant.',
            ];
        }

        return [
            'scheduled' => true,
            'timestamp' => $timestamp,
            'date'      => date_i18n( 'd.m.Y H:i', $timestamp ),
            'in'        => human_time_diff( time(), $timestamp ),
        ];
    }
}
