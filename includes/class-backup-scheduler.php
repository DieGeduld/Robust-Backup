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
     * Add a new schedule.
     */
    public static function add_schedule( $data ) {
        $schedules = get_option( 'wprb_schedules', [] );
        $id = uniqid( 'sched_' );
        
        $data['id'] = $id;
        $data['created_at'] = time();
        
        $schedules[ $id ] = $data;
        update_option( 'wprb_schedules', $schedules );

        // Schedule the cron event
        self::schedule_event( $id, $data );

        return $id;
    }

    /**
     * Update an existing schedule.
     */
    public static function update_schedule( $id, $data ) {
        $schedules = get_option( 'wprb_schedules', [] );
        
        if ( ! isset( $schedules[ $id ] ) ) {
            return false;
        }

        // Preserve creation time and ID
        $data['id'] = $id;
        $data['created_at'] = $schedules[ $id ]['created_at'] ?? time();
        $schedules[ $id ] = $data;

        update_option( 'wprb_schedules', $schedules );

        // Reschedule
        $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $id ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $id ] );
        }
        self::schedule_event( $id, $data );

        return true;
    }

    /**
     * Delete a schedule.
     */
    public static function delete_schedule( $id ) {
        $schedules = get_option( 'wprb_schedules', [] );
        
        if ( isset( $schedules[ $id ] ) ) {
            // Unschedule cron
            $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $id ] );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $id ] );
            }

            unset( $schedules[ $id ] );
            return update_option( 'wprb_schedules', $schedules );
        }

        return false;
    }

    /**
     * Schedule a specific event.
     */
    private static function schedule_event( $id, $data ) {
        $time = $data['time'] ?? '03:00';
        $interval = $data['interval'] ?? 'daily';

        // Calculate first run
        // Calculate first run using WP Timezone
        $parts    = explode( ':', $time );
        $hour     = (int) ( $parts[0] ?? 3 );
        $minute   = (int) ( $parts[1] ?? 0 );

        $tz = wp_timezone();
        $now = new DateTime( 'now', $tz );
        $scheduled = new DateTime( 'now', $tz );
        $scheduled->setTime( $hour, $minute, 0 );

        if ( $scheduled <= $now ) {
            $scheduled->modify( '+1 day' );
        }

        $next_run = $scheduled->getTimestamp();

        // Map interval
        $recurrence_map = [
            'hourly'  => 'hourly',
            'daily'   => 'daily',
            'weekly'  => 'wprb_weekly',
            'monthly' => 'wprb_monthly',
        ];

        $recurrence = $recurrence_map[ $interval ] ?? 'daily';

        // Schedule only if not already there
        if ( ! wp_next_scheduled( self::CRON_HOOK, [ $id ] ) ) {
            wp_schedule_event( $next_run, $recurrence, self::CRON_HOOK, [ $id ] );
        }
    }

    /**
     * Re-schedule all active schedules (e.g. on plugin init).
     */
    public static function schedule() {
        $schedules = get_option( 'wprb_schedules', [] );
        foreach ( $schedules as $id => $data ) {
            if ( ! wp_next_scheduled( self::CRON_HOOK, [ $id ] ) ) {
                self::schedule_event( $id, $data );
            }
        }
    }

    /**
     * Unschedule all events (e.g. on deactivation).
     */
    public static function unschedule() {
        $schedules = get_option( 'wprb_schedules', [] );
        foreach ( $schedules as $id => $data ) {
            $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $id ] );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $id ] );
            }
        }
    }

    /**
     * Run the scheduled backup.
     * 
     * @param string $schedule_id Optional schedule ID.
     */
    public static function run_scheduled_backup( $schedule_id = null ) {
        // Increase limits for cron execution
        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $schedules = get_option( 'wprb_schedules', [] );
        
        // Defaults if no specific schedule found (legacy fallback or error)
        $type = 'full';
        $destinations = ['local'];

        if ( $schedule_id && isset( $schedules[ $schedule_id ] ) ) {
            $sched = $schedules[ $schedule_id ];
            $type = $sched['type'] ?? 'full';
            $destinations = $sched['destinations'] ?? ['local'];
        }

        // Configure Engine for this run
        // Note: Engine currently pulls from global options. 
        // We need to inject these specific settings or update options temporarily.
        // Better: Pass config to run_full_backup() if supported, or update the engine to support overrides.
        // For now, let's assume valid default behavior but we need to pass destinations to the storage manager later?
        // Actually, Backup Engine reads 'wprb_storage' option. We might need to filter it.
        
        // Filter storage option for this run
        add_filter( 'option_wprb_storage', function() use ($destinations) {
            return $destinations;
        });

        $engine = new WPRB_Backup_Engine();
        $result = $engine->run_full_backup( $type ); // Engine needs to support type argument!

        // Log result
        $status  = empty( $result['errors'] ) ? 'OK' : 'mit Fehlern';
        $message = sprintf(
            '[Cron] Geplantes Backup (%s) %s: %s',
            $schedule_id ? "ID: $schedule_id" : 'Global',
            $status,
            $result['message'] ?? ''
        );

        if ( ! empty( $result['errors'] ) ) {
            $message .= ' Fehler: ' . implode( ', ', $result['errors'] );
        }

        file_put_contents(
            WPRB_LOG_FILE,
            '[' . wp_date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Get info about the next scheduled backup (nearest one).
     */
    public static function get_next_scheduled() {
        $schedules = get_option( 'wprb_schedules', [] );
        $next_timestamp = false;
        $next_schedule_id = null;

        foreach ( $schedules as $id => $data ) {
            $ts = wp_next_scheduled( self::CRON_HOOK, [ $id ] );
            if ( $ts ) {
                if ( ! $next_timestamp || $ts < $next_timestamp ) {
                    $next_timestamp = $ts;
                    $next_schedule_id = $id;
                }
            }
        }

        if ( ! $next_timestamp ) {
            return [
                'scheduled' => false,
                'message'   => 'Kein Backup geplant.',
            ];
        }

        return [
            'scheduled' => true,
            'timestamp' => $next_timestamp,
            'date'      => date_i18n( 'd.m.Y H:i', $next_timestamp ),
            'in'        => human_time_diff( time(), $next_timestamp ),
            'id'        => $next_schedule_id // Optional: which schedule is next
        ];
    }
}
