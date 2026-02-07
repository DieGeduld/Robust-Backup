<?php
/**
 * Admin Page
 * 
 * Renders the backup admin interface with tabs for:
 * - Dashboard (run backup, view progress)
 * - Backups (list, download, delete)
 * - Settings (schedule, storage, cloud config)
 * - Log
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPRB_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
    }

    public function add_menu() {
        add_menu_page(
            'WP Robust Backup',
            'Robust Backup',
            'manage_options',
            'wp-robust-backup',
            [ $this, 'render_page' ],
            'dashicons-cloud',
            98
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wp-robust-backup' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wprb-admin',
            WPRB_PLUGIN_URL . 'admin/css/backup-admin.css',
            [],
            WPRB_VERSION
        );

        wp_enqueue_script(
            'wprb-admin',
            WPRB_PLUGIN_URL . 'admin/js/backup-admin.js',
            [ 'jquery' ],
            WPRB_VERSION,
            true
        );

        wp_localize_script( 'wprb-admin', 'wprbData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wprb_nonce' ),
            'strings' => [
                'confirmDelete'  => 'Dieses Backup wirklich löschen?',
                'confirmCancel'  => 'Laufendes Backup wirklich abbrechen?',
                'backupStarted'  => 'Backup gestartet...',
                'backupDone'     => 'Backup abgeschlossen!',
                'backupCanceled' => 'Backup abgebrochen.',
                'error'          => 'Ein Fehler ist aufgetreten.',
                'saving'         => 'Speichere...',
                'saved'          => 'Gespeichert!',
            ],
        ] );
    }

    public function handle_oauth_callback() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wp-robust-backup' && isset( $_GET['oauth_callback'] ) ) {
            $storage = new WPRB_Storage_Manager();
            $storage->handle_oauth_callback();
        }
    }

    public function render_page() {
        $tab = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );
        ?>
        <div class="wrap wprb-wrap">
            <h1>
                <span class="dashicons dashicons-backup" style="font-size: 28px; margin-right: 8px;"></span>
                WP Robust Backup
            </h1>

            <nav class="nav-tab-wrapper wprb-tabs">
                <a href="?page=wp-robust-backup&tab=dashboard"
                   class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span> Dashboard
                </a>
                <a href="?page=wp-robust-backup&tab=backups"
                   class="nav-tab <?php echo $tab === 'backups' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-archive"></span> Backups
                </a>
                <a href="?page=wp-robust-backup&tab=restore"
                   class="nav-tab <?php echo $tab === 'restore' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-undo"></span> Wiederherstellen
                </a>
                <a href="?page=wp-robust-backup&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings"></span> Einstellungen
                </a>
                <a href="?page=wp-robust-backup&tab=schedules"
                   class="nav-tab <?php echo $tab === 'schedules' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar-alt"></span> Zeitpläne
                </a>
                <a href="?page=wp-robust-backup&tab=storage"
                   class="nav-tab <?php echo $tab === 'storage' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-cloud"></span> Speicher
                </a>
                <a href="?page=wp-robust-backup&tab=log"
                   class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-editor-alignleft"></span> Log
                </a>
            </nav>

            <div class="wprb-content">
                <?php
                switch ( $tab ) {
                    case 'dashboard':
                        $this->render_dashboard();
                        break;
                    case 'backups':
                        $this->render_backups();
                        break;
                    case 'restore':
                        $this->render_restore();
                        break;
                    case 'settings':
                        $this->render_settings();
                        break;
                    case 'schedules':
                        $this->render_schedules();
                        break;
                    case 'storage':
                        $this->render_storage();
                        break;
                    case 'log':
                        $this->render_log();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Dashboard Tab
    // ─────────────────────────────────────────────

    private function render_dashboard() {
        $scheduler = WPRB_Backup_Scheduler::get_next_scheduled();
        $storage   = new WPRB_Storage_Manager();
        $backups   = $storage->list_backups();
        $last      = ! empty( $backups ) ? $backups[0] : null;
        ?>

        <div class="wprb-dashboard-grid">

            <!-- Quick Backup Panel -->
            <div class="wprb-card wprb-card-primary">
                <h2>Backup starten</h2>
                <p>Wähle den Backup-Typ und starte den Vorgang. Das Backup läuft im Hintergrund in kleinen Schritten – ohne Timeout-Probleme.</p>

                <div class="wprb-backup-actions">
                    <button class="button button-primary button-hero wprb-start-backup" data-type="full">
                        <span class="dashicons dashicons-database-export"></span>
                        Vollständiges Backup
                    </button>
                    <button class="button button-secondary wprb-start-backup" data-type="db_only">
                        <span class="dashicons dashicons-database"></span>
                        Nur Datenbank
                    </button>
                    <button class="button button-secondary wprb-start-backup" data-type="files_only">
                        <span class="dashicons dashicons-media-archive"></span>
                        Nur Dateien
                    </button>
                </div>

                <!-- Progress Section (hidden by default) -->
                <div id="wprb-progress-section" style="display: none;">
                    
                    <!-- Stepper -->
                    <div class="wprb-stepper">
                        <div class="wprb-step" id="step-database">
                            <span class="step-icon dashicons dashicons-database"></span>
                            <span class="step-label">Datenbank</span>
                        </div>
                        <div class="wprb-step-line"></div>
                        <div class="wprb-step" id="step-files">
                            <span class="step-icon dashicons dashicons-media-archive"></span>
                            <span class="step-label">Dateien</span>
                        </div>
                        <div class="wprb-step-line"></div>
                        <div class="wprb-step" id="step-upload">
                            <span class="step-icon dashicons dashicons-cloud-upload"></span>
                            <span class="step-label">Upload</span>
                        </div>
                        <div class="wprb-step-line"></div>
                        <div class="wprb-step" id="step-done">
                            <span class="step-icon dashicons dashicons-yes"></span>
                            <span class="step-label">Fertig</span>
                        </div>
                    </div>

                    <div class="wprb-progress-bar-wrapper">
                        <div class="wprb-progress-bar">
                            <div class="wprb-progress-fill" id="wprb-progress-fill" style="width: 0%;">
                                <span id="wprb-progress-percent">0%</span>
                            </div>
                        </div>
                    </div>
                    <div class="wprb-progress-info">
                        <span id="wprb-progress-message">Wird vorbereitet...</span>
                        <span id="wprb-progress-time" class="wprb-muted"></span>
                    </div>
                    <button class="button wprb-cancel-backup" id="wprb-cancel-btn">
                        <span class="dashicons dashicons-no"></span> Abbrechen
                    </button>
                </div>

                <!-- Done Section (hidden by default) -->
                <div id="wprb-done-section" style="display: none;">
                    <div class="wprb-notice wprb-notice-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span id="wprb-done-message">Backup abgeschlossen!</span>
                    </div>
                </div>
            </div>

            <!-- Info Cards -->
            <div class="wprb-info-cards">
                <div class="wprb-card wprb-card-small">
                    <h3>Letztes Backup</h3>
                    <?php if ( $last ) : ?>
                        <p class="wprb-big-text"><?php echo esc_html( $last['date'] ); ?></p>
                        <p class="wprb-muted"><?php echo esc_html( $last['size'] ); ?> – <?php echo esc_html( $last['file_count'] ); ?> Datei(en)</p>
                    <?php else : ?>
                        <p class="wprb-muted">Noch kein Backup erstellt.</p>
                    <?php endif; ?>
                </div>

                <div class="wprb-card wprb-card-small">
                    <h3>Nächstes geplantes Backup</h3>
                    <?php if ( $scheduler['scheduled'] ) : ?>
                        <p class="wprb-big-text"><?php echo esc_html( $scheduler['date'] ); ?></p>
                        <?php 
                        $is_overdue = isset( $scheduler['timestamp'] ) && $scheduler['timestamp'] < time();
                        $label = $is_overdue ? 'Überfällig seit ' . $scheduler['in'] : 'In ' . $scheduler['in'];
                        $style = $is_overdue ? 'color: #d63638;' : '';
                        ?>
                        <p class="wprb-muted" style="<?php echo $style; ?>"><?php echo esc_html( $label ); ?></p>
                    <?php else : ?>
                        <p class="wprb-muted">Kein automatisches Backup geplant.</p>
                    <?php endif; ?>
                </div>

                <div class="wprb-card wprb-card-small">
                    <h3>Gespeicherte Backups</h3>
                    <p class="wprb-big-text"><?php echo count( $backups ); ?></p>
                    <?php
                    $total_size = array_sum( array_column( $backups, 'size_raw' ) );
                    ?>
                    <p class="wprb-muted">Gesamt: <?php echo size_format( $total_size ); ?></p>
                </div>

                <div class="wprb-card wprb-card-small">
                    <h3>System-Info</h3>
                    <p><strong>PHP:</strong> <?php echo PHP_VERSION; ?></p>
                    <p><strong>Memory:</strong> <?php echo ini_get( 'memory_limit' ); ?></p>
                    <p><strong>Timeout:</strong> <?php echo ini_get( 'max_execution_time' ); ?>s</p>
                    <p><strong>tar:</strong> <?php echo $this->check_tar() ? '✅' : '❌'; ?></p>
                    <p><strong>WP Zeit:</strong> <?php echo current_time( 'd.m.Y H:i' ); ?></p>
                    <p><strong>Zeitzone:</strong> <?php echo wp_timezone_string(); ?></p>

                </div>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Backups Tab
    // ─────────────────────────────────────────────

    private function render_backups() {
        $storage = new WPRB_Storage_Manager();
        $backups = $storage->list_backups();
        ?>

        <div class="wprb-card">
            <h2>Gespeicherte Backups</h2>

            <?php if ( empty( $backups ) ) : ?>
                <p class="wprb-muted">Noch keine Backups vorhanden.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped wprb-backup-table">
                    <thead>
                        <tr>
                            <th>Backup-ID</th>
                            <th>Datum</th>
                            <th>Typ</th>
                            <th>Größe</th>
                            <th>Ort</th>
                            <th>Dateien</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="wprb-backup-list">
                        <?php foreach ( $backups as $backup ) : ?>
                            <tr data-id="<?php echo esc_attr( $backup['id'] ); ?>">
                                <td><code><?php echo esc_html( $backup['id'] ); ?></code></td>
                                <td><?php echo esc_html( $backup['date'] ); ?></td>
                                <td>
                                    <?php
                                    $type_labels = [
                                        'full'       => 'Vollständig',
                                        'db_only'    => 'Nur DB',
                                        'files_only' => 'Nur Dateien',
                                    ];
                                    echo esc_html( $type_labels[ $backup['type'] ] ?? $backup['type'] );
                                    ?>
                                </td>
                                <td><?php echo esc_html( $backup['size'] ); ?></td>
                                <td>
                                    <?php if ( $backup['location'] === 'Cloud' ) : ?>
                                        <span class="dashicons dashicons-cloud" title="Nur in der Cloud"></span> Cloud
                                    <?php else : ?>
                                        <span class="dashicons dashicons-desktop" title="Lokal vorhanden"></span> Lokal
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ( $backup['files'] as $file ) : ?>
                                        <a href="<?php echo esc_url( $file['url'] ); ?>" class="wprb-file-link" title="Download: <?php echo esc_attr( $file['name'] ); ?>">
                                            <span class="dashicons dashicons-download"></span>
                                            <?php echo esc_html( $file['name'] ); ?>
                                            <small>(<?php echo esc_html( $file['size'] ); ?>)</small>
                                        </a><br>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <button class="button button-small wprb-delete-backup"
                                            data-id="<?php echo esc_attr( $backup['id'] ); ?>">
                                        <span class="dashicons dashicons-trash"></span> Löschen
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Restore Tab
    // ─────────────────────────────────────────────

    private function render_restore() {
        $storage = new WPRB_Storage_Manager();
        $backups = $storage->list_backups();
        ?>

        <div class="wprb-card wprb-card-primary">
            <h2>Backup wiederherstellen</h2>
            <p>Wähle ein Backup aus und entscheide, was wiederhergestellt werden soll. Vor der Wiederherstellung wird automatisch eine Sicherheitskopie der aktuellen Datenbank erstellt.</p>

            <div class="wprb-restore-warning">
                <span class="dashicons dashicons-warning"></span>
                <strong>Achtung:</strong> Die Wiederherstellung überschreibt die aktuelle Datenbank und/oder Dateien. Stelle sicher, dass du das richtige Backup ausgewählt hast.
            </div>

            <?php if ( empty( $backups ) ) : ?>
                <p class="wprb-muted">Keine Backups vorhanden. Erstelle zuerst ein Backup im Dashboard.</p>
            <?php else : ?>

                <!-- Step 1: Select Backup -->
                <div id="wprb-restore-step1">
                    <h3>1. Backup auswählen</h3>
                    <select id="wprb-restore-select" class="wprb-select-large">
                        <option value="">— Backup wählen —</option>
                        <?php foreach ( $backups as $backup ) : ?>
                            <option value="<?php echo esc_attr( $backup['id'] ); ?>">
                                <?php echo esc_html( $backup['date'] . ' – ' . $backup['size'] . ' (' . $backup['type'] . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="button button-secondary" id="wprb-analyze-btn" disabled>
                        <span class="dashicons dashicons-search"></span> Backup analysieren
                    </button>
                </div>

                <!-- Step 2: Analysis Result & Options (hidden) -->
                <div id="wprb-restore-step2" style="display: none;">
                    <h3>2. Backup-Details</h3>

                    <table class="wprb-restore-info-table">
                        <tr>
                            <th>Datum:</th>
                            <td id="wprb-ri-date"></td>
                        </tr>
                        <tr>
                            <th>Typ:</th>
                            <td id="wprb-ri-type"></td>
                        </tr>
                        <tr>
                            <th>WordPress:</th>
                            <td id="wprb-ri-wp"></td>
                        </tr>
                        <tr>
                            <th>Seite:</th>
                            <td id="wprb-ri-url"></td>
                        </tr>
                        <tr>
                            <th>Datenbank:</th>
                            <td id="wprb-ri-db"></td>
                        </tr>
                        <tr>
                            <th>Dateien:</th>
                            <td id="wprb-ri-files"></td>
                        </tr>
                    </table>

                    <h3>3. Was wiederherstellen?</h3>

                    <div class="wprb-restore-options">
                        <label class="wprb-checkbox-card" id="wprb-opt-db-card">
                            <input type="checkbox" id="wprb-restore-db" checked>
                            <span class="dashicons dashicons-database"></span>
                            <span>Datenbank</span>
                        </label>
                        <label class="wprb-checkbox-card" id="wprb-opt-files-card">
                            <input type="checkbox" id="wprb-restore-files" checked>
                            <span class="dashicons dashicons-media-archive"></span>
                            <span>Dateien</span>
                        </label>
                        <label class="wprb-checkbox-card">
                            <input type="checkbox" id="wprb-restore-snapshot" checked>
                            <span class="dashicons dashicons-shield"></span>
                            <span>Sicherheitskopie vorher erstellen</span>
                        </label>
                    </div>

                    <div style="margin-top: 20px;">
                        <button class="button button-primary button-hero" id="wprb-start-restore-btn">
                            <span class="dashicons dashicons-undo"></span>
                            Wiederherstellung starten
                        </button>
                        <button class="button" id="wprb-restore-back-btn" style="margin-left: 8px;">
                            Zurück
                        </button>
                    </div>
                </div>

                <!-- Step 3: Progress (hidden) -->
                <div id="wprb-restore-progress" style="display: none;">
                    <h3>Wiederherstellung läuft...</h3>

                    <div class="wprb-progress-bar-wrapper">
                        <div class="wprb-progress-bar">
                            <div class="wprb-progress-fill wprb-progress-fill-restore" id="wprb-restore-fill" style="width: 0%;">
                                <span id="wprb-restore-percent">0%</span>
                            </div>
                        </div>
                    </div>
                    <div class="wprb-progress-info">
                        <span id="wprb-restore-message">Wird vorbereitet...</span>
                        <span id="wprb-restore-time" class="wprb-muted"></span>
                    </div>
                    <button class="button wprb-cancel-backup" id="wprb-cancel-restore-btn">
                        <span class="dashicons dashicons-no"></span> Abbrechen
                    </button>
                </div>

                <!-- Step 4: Done (hidden) -->
                <div id="wprb-restore-done" style="display: none;">
                    <div class="wprb-notice wprb-notice-success" id="wprb-restore-done-notice">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>
                            <strong id="wprb-restore-done-title">Wiederherstellung abgeschlossen!</strong>
                            <p id="wprb-restore-done-details" class="wprb-muted" style="margin: 4px 0 0;"></p>
                        </div>
                    </div>
                    <button class="button" id="wprb-restore-reset-btn" style="margin-top: 8px;">
                        Weitere Wiederherstellung
                    </button>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Schedules Tab
    // ─────────────────────────────────────────────

    private function render_schedules() {
        $schedules = get_option( 'wprb_schedules', [] );
        $storage_mgr = new WPRB_Storage_Manager();
        $connected_storages = $this->get_connected_storages();
        ?>
        <div class="wprb-card">
            <h2>Aktive Zeitpläne</h2>
            
            <?php if ( empty( $schedules ) ) : ?>
                <p class="wprb-muted">Keine Zeitpläne eingerichtet.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Intervall</th>
                            <th>Zeit</th>
                            <th>Typ</th>
                            <th>Ziele</th>
                            <th>Nächster Lauf</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $schedules as $id => $sched ) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $labels = [ 'hourly' => 'Stündlich', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich' ];
                                    echo esc_html( $labels[ $sched['interval'] ] ?? $sched['interval'] );
                                    ?>
                                </td>
                                <td><?php echo esc_html( $sched['time'] ); ?></td>
                                <td>
                                    <?php
                                    $types = [ 'full' => 'Vollständig', 'db_only' => 'Nur DB', 'files_only' => 'Nur Dateien' ];
                                    echo esc_html( $types[ $sched['type'] ] ?? $sched['type'] );
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    foreach ( $sched['destinations'] as $dest ) {
                                        echo '<span class="wprb-badge">' . esc_html( ucfirst( $dest ) ) . '</span> ';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $next = wp_next_scheduled( 'wprb_scheduled_backup', [ $id ] );
                                    echo $next ? date_i18n( 'd.m.Y H:i', $next ) : 'Inaktiv';
                                    ?>
                                </td>
                                <td>
                                    <button class="button button-small wprb-delete-schedule" data-id="<?php echo esc_attr( $id ); ?>">
                                        <span class="dashicons dashicons-trash"></span> Löschen
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="wprb-card wprb-card-primary">
            <h2>Neuen Zeitplan erstellen</h2>
            <form id="wprb-add-schedule-form">
                <input type="hidden" name="action" value="wprb_add_schedule">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wprb_nonce'); ?>">

                <table class="form-table">
                    <tr>
                        <th>Intervall</th>
                        <td>
                            <select name="interval">
                                <option value="hourly">Stündlich</option>
                                <option value="daily" selected>Täglich</option>
                                <option value="weekly">Wöchentlich</option>
                                <option value="monthly">Monatlich</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Uhrzeit</th>
                        <td>
                            <input type="time" name="time" value="03:00">
                            <p class="description">Gilt für tägliche/wöchentliche/monatliche Backups.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Backup-Typ</th>
                        <td>
                            <select name="type">
                                <option value="full">Vollständig (DB + Dateien)</option>
                                <option value="db_only">Nur Datenbank</option>
                                <option value="files_only">Nur Dateien</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Speicherorte</th>
                        <td>
                            <?php if ( empty( $connected_storages ) ) : ?>
                                <p class="wprb-notice-warning">Keine externen Speicherorte verbunden. Backup wird lokal gespeichert.</p>
                                <input type="hidden" name="destinations[]" value="local">
                            <?php else : ?>
                                <label><input type="checkbox" name="destinations[]" value="local" checked> Lokal</label><br>
                                <?php foreach ( $connected_storages as $storage ) : ?>
                                    <label>
                                        <input type="checkbox" name="destinations[]" value="<?php echo esc_attr( $storage ); ?>"> 
                                        <?php echo esc_html( ucfirst( $storage ) ); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-plus"></span> Zeitplan hinzufügen
                    </button>
                </p>
            </form>
        </div>
        <?php
    }



    /**
     * Helper to get list of actually connected storages (with tokens)
     */
    private function get_connected_storages() {
        $connected = [];
        // Check Dropbox
        if ( get_option( 'wprb_dropbox_token' ) ) {
            $connected[] = 'dropbox';
        }
        // Check GDrive
        if ( get_option( 'wprb_gdrive_token' ) ) {
            $connected[] = 'gdrive';
        }
        return $connected;
    }

    // ─────────────────────────────────────────────
    // Settings Tab
    // ─────────────────────────────────────────────

    private function render_settings() {
        ?>
        <div class="wprb-card">
            <h2>Allgemeine Einstellungen</h2>

            <form id="wprb-settings-form">
                <input type="hidden" name="tab" value="settings">

                <!-- Performance -->
                <h3>Performance</h3>
                <table class="form-table">
                    <tr>
                        <th>DB-Chunk-Größe</th>
                        <td>
                            <input type="number" name="db_chunk_size" min="100" max="10000"
                                   value="<?php echo esc_attr( get_option( 'wprb_db_chunk_size', 1000 ) ); ?>">
                            <p class="description">Zeilen pro DB-Chunk. Kleiner = sicherer bei knappem Memory/Timeout. Standard: 1000</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Datei-Batch-Größe</th>
                        <td>
                            <input type="number" name="file_batch_size" min="50" max="2000"
                                   value="<?php echo esc_attr( get_option( 'wprb_file_batch_size', 200 ) ); ?>">
                            <p class="description">Dateien pro Batch. Standard: 200</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Max. Archiv-Größe (MB)</th>
                        <td>
                            <input type="number" name="max_archive_size" min="50" max="5000"
                                   value="<?php echo esc_attr( get_option( 'wprb_max_archive_size', 500 ) ); ?>">
                            <p class="description">Archive werden bei dieser Größe aufgeteilt. Standard: 500 MB</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Ausgeschlossene Verzeichnisse</th>
                        <td>
                            <textarea name="exclude_dirs" rows="5" class="large-text code"
                            ><?php echo esc_textarea( get_option( 'wprb_exclude_dirs', '' ) ); ?></textarea>
                            <p class="description">Ein Verzeichnis pro Zeile, relativ zu WordPress-Root. Z.B.: wp-content/cache</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="wprb-save-settings">
                        <span class="dashicons dashicons-saved"></span> Einstellungen speichern
                    </button>
                    <span id="wprb-settings-status" class="wprb-muted" style="margin-left: 10px;"></span>
                </p>

            </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Storage Tab
    // ─────────────────────────────────────────────

    private function render_storage() {
        $storage_mgr = new WPRB_Storage_Manager();
        $active_storage = (array) get_option( 'wprb_storage', [ 'local' ] );
        ?>
        <div class="wprb-card">
            <h2>Speicherorte verwalten</h2>

            <form id="wprb-storage-settings-form">
                <input type="hidden" name="tab" value="storage">

                <!-- Storage Selection -->
                <table class="form-table">
                    <tr>
                        <th>Aktive Speicherorte</th>
                        <td>
                            <?php
                            $storage_opts = [
                                'local'   => 'Lokal auf dem Server',
                                'dropbox' => 'Dropbox',
                                'gdrive'  => 'Google Drive',
                            ];
                            foreach ( $storage_opts as $key => $label ) {
                                printf(
                                    '<label><input type="checkbox" name="storage[]" value="%s" %s class="wprb-storage-checkbox" data-target="#wprb-%s-settings"> %s</label><br>',
                                    esc_attr( $key ),
                                    checked( in_array( $key, $active_storage ), true, false ),
                                    esc_attr( $key ),
                                    esc_html( $label )
                                );
                            }
                            ?>
                            <p class="description">Wähle, wohin Backups gespeichert werden sollen. Mehrfachauswahl möglich.</p>
                        </td>
                    </tr>
                </table>

                <hr style="margin: 20px 0; border: none; border-bottom: 1px solid #f0f0f1;">

                <!-- Dropbox Settings -->
                <div id="wprb-dropbox-settings" style="display: <?php echo in_array('dropbox', $active_storage) ? 'block' : 'none'; ?>;">
                    <h3><span class="dashicons dashicons-dropbox"></span> Dropbox Konfiguration</h3>
                    <p class="description" style="margin-bottom: 15px;">
                        Erstelle eine App in der <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox App Console</a>,<br>
                        wähle "Scoped Access", "App Folder" und füge folgende Redirect URI hinzu:<br>
                        <code><?php echo esc_html( WPRB_Storage_Manager::get_oauth_redirect_url() ); ?></code>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th>App Key</th>
                            <td><input type="text" name="dropbox_app_key" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_dropbox_app_key', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>App Secret</th>
                            <td><input type="password" name="dropbox_secret" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_dropbox_secret', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Verbindung</th>
                            <td>
                                <?php
                                $dropbox_token = get_option( 'wprb_dropbox_token', '' );
                                if ( ! empty( $dropbox_token ) ) {
                                    echo '<span class="wprb-status-ok">✅ Verbunden</span> ';
                                    echo '<a href="#" class="wprb-disconnect-storage button button-link-delete" data-service="dropbox" style="margin-left: 10px;">Trennen</a>';
                                } else {
                                    $auth_url = $storage_mgr->get_dropbox_auth_url();
                                    if ( $auth_url ) {
                                        echo '<a href="' . esc_url( $auth_url ) . '" class="button">Mit Dropbox verbinden</a>';
                                    } else {
                                        echo '<span class="wprb-muted">Erst Key & Secret speichern.</span>';
                                    }
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                    <hr style="margin: 20px 0; border: none; border-bottom: 1px solid #f0f0f1;">
                </div>

                <!-- Google Drive Settings -->
                <div id="wprb-gdrive-settings" style="display: <?php echo in_array('gdrive', $active_storage) ? 'block' : 'none'; ?>;">
                    <h3><span class="dashicons dashicons-google"></span> Google Drive Konfiguration</h3>
                    <p class="description" style="margin-bottom: 15px;">
                        Erstelle Credentials in der <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.<br>
                        Redirect URI: <code><?php echo esc_html( WPRB_Storage_Manager::get_oauth_redirect_url() ); ?></code>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th>Client ID</th>
                            <td><input type="text" name="gdrive_client_id" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_gdrive_client_id', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Client Secret</th>
                            <td><input type="password" name="gdrive_secret" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_gdrive_secret', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Verbindung</th>
                            <td>
                                <?php
                                $gdrive_token = get_option( 'wprb_gdrive_token', '' );
                                if ( ! empty( $gdrive_token ) ) {
                                    echo '<span class="wprb-status-ok">✅ Verbunden</span> ';
                                    echo '<a href="#" class="wprb-disconnect-storage button button-link-delete" data-service="gdrive" style="margin-left: 10px;">Trennen</a>';
                                } else {
                                    $auth_url = $storage_mgr->get_gdrive_auth_url();
                                    if ( $auth_url ) {
                                        echo '<a href="' . esc_url( $auth_url ) . '" class="button">Mit Google Drive verbinden</a>';
                                    } else {
                                        echo '<span class="wprb-muted">Erst ID & Secret speichern.</span>';
                                    }
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                    <hr style="margin: 20px 0; border: none; border-bottom: 1px solid #f0f0f1;">
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="wprb-save-storage">
                        <span class="dashicons dashicons-saved"></span> Speicher-Einstellungen sichern
                    </button>
                    <span id="wprb-storage-status" class="wprb-muted" style="margin-left: 10px;"></span>
                </p>
            </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Log Tab
    // ─────────────────────────────────────────────

    private function render_log() {
        // Handle Clear Log
        if ( isset( $_POST['wprb_clear_log'] ) && check_admin_referer( 'wprb_clear_log_nonce' ) ) {
            if ( file_exists( WPRB_LOG_FILE ) ) {
                file_put_contents( WPRB_LOG_FILE, '' );
                echo '<div class="notice notice-success is-dismissible"><p>Log wurde erfolgreich geleert.</p></div>';
            }
        }

        $mode = isset( $_GET['log_mode'] ) && $_GET['log_mode'] === 'detailed' ? 'detailed' : 'normal';
        ?>
        <div class="wprb-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Backup-Log</h2>
                
                <div class="wprb-log-actions">
                    <!-- Mode Switch -->
                    <div class="button-group">
                        <a href="?page=wp-robust-backup&tab=log&log_mode=normal" 
                           class="button <?php echo $mode === 'normal' ? 'button-primary' : 'button-secondary'; ?>">
                           Normal
                        </a>
                        <a href="?page=wp-robust-backup&tab=log&log_mode=detailed" 
                           class="button <?php echo $mode === 'detailed' ? 'button-primary' : 'button-secondary'; ?>">
                           Detailliert
                        </a>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
                 <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field( 'wprb_clear_log_nonce' ); ?>
                    <button type="submit" name="wprb_clear_log" class="button button-link-delete" 
                            onclick="return confirm('Möchtest du das Log wirklich leeren?');">
                        <span class="dashicons dashicons-trash"></span> Log leeren
                    </button>
                </form>
            </div>

            <?php if ( file_exists( WPRB_LOG_FILE ) && filesize( WPRB_LOG_FILE ) > 0 ) : ?>
                <?php
                $lines = file( WPRB_LOG_FILE ); 
                $content = '';
                $found = false;

                if ( $lines ) {
                    // Reverse to show newest first? Usually logs are appended.
                    // Let's keep chronological order as per file.
                    foreach ( $lines as $line ) {
                        // Filter Logic
                        if ( $mode === 'normal' ) {
                            // If explicit DEBUG, skip
                            if ( strpos( $line, '[DEBUG]' ) !== false ) {
                                continue;
                            }
                        }
                        $content .= htmlspecialchars( $line );
                        $found = true;
                    }
                }
                ?>
                
                <?php if ( $found && ! empty( $content ) ) : ?>
                    <textarea class="wprb-log-viewer" readonly style="width: 100%; height: 500px; font-family: monospace; white-space: pre;"><?php echo $content; ?></textarea>
                <?php else : ?>
                    <p class="wprb-muted">Keine passenden Log-Einträge für diesen Modus gefunden.</p>
                <?php endif; ?>

            <?php else : ?>
                <p class="wprb-muted">Noch keine Log-Einträge vorhanden.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    private function check_tar() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        exec( 'which tar 2>&1', $output, $ret );
        return $ret === 0;
    }
}
