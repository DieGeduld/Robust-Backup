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
        add_action( 'admin_post_wprb_download_log', [ $this, 'download_log' ] );
    }

    public function download_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $log_file = defined('WPRB_LOG_FILE') ? WPRB_LOG_FILE : '';
        if ( ! $log_file || ! file_exists( $log_file ) ) {
            wp_die( 'Kein Log gefunden.' );
        }

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="wprb-backup.log.txt"' );
        header( 'Content-Length: ' . filesize( $log_file ) );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        
        readfile( $log_file );
        exit;
    }

    public function add_menu() {
        // Main Menu (Dashboard)
        add_menu_page(
            'WP Robust Backup',
            'Robust Backup',
            'manage_options',
            'wp-robust-backup',
            [ $this, 'render_dashboard_page' ],
            'dashicons-cloud',
            98
        );

        // Rename first submenu to "Dashboard"
        add_submenu_page(
            'wp-robust-backup',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wp-robust-backup',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Backups',
            'Backups',
            'manage_options',
            'wprb-backups',
            [ $this, 'render_backups_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Wiederherstellen',
            'Wiederherstellen',
            'manage_options',
            'wprb-restore',
            [ $this, 'render_restore_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Zeitpläne',
            'Zeitpläne',
            'manage_options',
            'wprb-schedules',
            [ $this, 'render_schedules_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Speicher',
            'Speicher',
            'manage_options',
            'wprb-storage',
            [ $this, 'render_storage_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'wprb-settings',
            [ $this, 'render_settings_page' ]
        );

        add_submenu_page(
            'wp-robust-backup',
            'Log',
            'Log',
            'manage_options',
            'wprb-log',
            [ $this, 'render_log_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        // Check if we are on any of our plugin pages
        if ( strpos( $hook, 'wp-robust-backup' ) === false && strpos( $hook, 'wprb-' ) === false ) {
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
        // Allow callback on dashboard or storage page
        if ( isset( $_GET['page'] ) && ( $_GET['page'] === 'wp-robust-backup' || $_GET['page'] === 'wprb-storage' ) && isset( $_GET['oauth_callback'] ) ) {
            $storage = new WPRB_Storage_Manager();
            $storage->handle_oauth_callback();
        }
    }

    // ─────────────────────────────────────────────
    // Page Wrappers
    // ─────────────────────────────────────────────

    public function render_dashboard_page() {
        $this->render_page_wrapper( 'dashboard' );
    }

    public function render_backups_page() {
        $this->render_page_wrapper( 'backups' );
    }

    public function render_restore_page() {
        $this->render_page_wrapper( 'restore' );
    }

    public function render_schedules_page() {
        $this->render_page_wrapper( 'schedules' );
    }

    public function render_storage_page() {
        $this->render_page_wrapper( 'storage' );
    }

    public function render_settings_page() {
        $this->render_page_wrapper( 'settings' );
    }

    public function render_log_page() {
        $this->render_page_wrapper( 'log' );
    }

    private function render_page_wrapper( $subpage ) {
        ?>
        <div class="wrap wprb-wrap">
            <h1>
                <span class="dashicons dashicons-backup" style="font-size: 28px; margin-right: 8px;"></span>
                WP Robust Backup
            </h1>

            <div class="wprb-content">
                <?php
                switch ( $subpage ) {
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

                <div class="wprb-backup-options" style="margin-bottom: 20px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <strong style="display: block; margin-bottom: 10px;">Zielort:</strong>
                    <label style="margin-right: 15px;">
                        <input type="checkbox" name="backup_dest[]" value="local" checked> 
                        <span class="dashicons dashicons-desktop" style="color: #646970; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span> Lokal
                    </label>
                    <?php
                    $connected = $this->get_connected_storages();
                    if ( ! empty( $connected ) ) :
                        $dashboard_icons = [
                            'dropbox' => 'dashicons-dropbox',
                            'gdrive'  => 'dashicons-google',
                            'sftp'    => 'dashicons-admin-network',
                            's3'      => 'dashicons-amazon',
                        ];
                        foreach ( $connected as $storage ) :
                            $d_icon = $dashboard_icons[ $storage ] ?? 'dashicons-cloud';
                            ?>
                            <label style="margin-right: 15px;">
                                <input type="checkbox" name="backup_dest[]" value="<?php echo esc_attr( $storage ); ?>"> 
                                <span class="dashicons <?php echo esc_attr( $d_icon ); ?>" style="color: #646970; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span> <?php echo esc_html( ucfirst( $storage ) ); ?>
                            </label>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </div>

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
                                    <?php 
                                    $storages = $backup['storages'] ?? [];
                                    if ( empty( $storages ) ) { 
                                         $storages = ( $backup['location'] === 'Cloud' ) ? ['cloud'] : ['local'];
                                    }
                                    
                                    $labels = [
                                        'local'   => 'Lokal',
                                        'dropbox' => 'Dropbox',
                                        'gdrive'  => 'Google Drive',
                                        'sftp'    => 'SFTP',
                                        's3'      => 'S3',
                                        'cloud'   => 'Cloud',
                                    ];
                                    
                                    $icons = [
                                        'local'   => 'dashicons-desktop',
                                        'dropbox' => 'dashicons-dropbox', 
                                        'gdrive'  => 'dashicons-google',
                                        'sftp'    => 'dashicons-admin-network',
                                        's3'      => 'dashicons-amazon',
                                        'cloud'   => 'dashicons-cloud',
                                    ];

                                    foreach ( $storages as $st ) {
                                        // Skip local if deleted
                                        if ( $st === 'local' && ! empty( $backup['local_deleted'] ) ) {
                                           continue; 
                                        }

                                        $icon = $icons[ $st ] ?? 'dashicons-cloud';
                                        $label = $labels[ $st ] ?? ucfirst( $st );
                                        
                                        printf( 
                                            '<span class="dashicons %s" title="%s" style="margin-right: 6px; color: #50575e;"></span>', 
                                            esc_attr( $icon ), 
                                            esc_attr( $label ) 
                                        );
                                    }
                                    ?>
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
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wprb-restore&backup_id=' . $backup['id'] ) ); ?>" 
                                       class="button button-small wprb-restore-backup-link"
                                       style="margin-right: 4px;">
                                        <span class="dashicons dashicons-undo"></span> Wiederherstellen
                                    </a>
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
                            <button type="button" class="button button-small" id="wprb-restore-files-select-btn" style="margin-left: 10px; display: none;">Auswählen</button>
                            <span id="wprb-restore-files-note" class="wprb-muted" style="margin-left: 10px; display: none; font-size: 12px;"></span>
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

                <!-- File Selection Modal -->
                <div id="wprb-file-selection-modal" class="wprb-modal" style="display: none;">
                    <div class="wprb-modal-content" style="display: flex; flex-direction: column; height: 100%;">
                        <div class="wprb-modal-header">
                            <h3>Dateien auswählen</h3>
                            <span class="wprb-modal-close" style="cursor: pointer; font-size: 24px;">&times;</span>
                        </div>
                        <div class="wprb-modal-body">
                            <p>Wähle die Dateien oder Ordner aus, die wiederhergestellt werden sollen. Wenn nichts ausgewählt ist, werden alle Dateien wiederhergestellt.</p>
                            <input type="text" id="wprb-file-search" placeholder="Dateien suchen..." style="width: 100%; margin-bottom: 10px;">
                            <div id="wprb-file-tree" class="wprb-file-tree">
                                <p class="wprb-muted">Lade Dateiliste...</p>
                            </div>
                        </div>
                        <div class="wprb-modal-footer">
                            <span id="wprb-file-selection-count" class="wprb-muted" style="float: left; line-height: 30px;">Alle Dateien (Standard)</span>
                            <button type="button" class="button" id="wprb-file-modal-cancel">Abbrechen</button>
                            <button type="button" class="button button-primary" id="wprb-file-modal-save">Auswahl bestätigen</button>
                        </div>
                    </div>
                </div>
                <div id="wprb-modal-backdrop" class="wprb-modal-backdrop" style="display: none;"></div>

                <style>
                    .wprb-modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; }
                    .wprb-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; max-width: 90%; background: #fff; z-index: 10001; box-shadow: 0 5px 15px rgba(0,0,0,0.3); border-radius: 4px; height: 80vh; max-height: 80vh; }
                    .wprb-modal-header { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; height: 50px; flex-shrink: 0; box-sizing: border-box; }
                    .wprb-modal-header h3 { margin: 0; }
                    .wprb-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
                    .wprb-modal-footer { padding: 15px 20px; border-top: 1px solid #eee; text-align: right; height: 60px; flex-shrink: 0; box-sizing: border-box; background: #fff; border-bottom-left-radius: 4px; border-bottom-right-radius: 4px;}
                    .wprb-file-tree { border: 1px solid #ddd; padding: 10px; height: calc(100% - 80px); overflow-y: auto; background: #fafafa; }
                    .wprb-file-item { margin: 2px 0; display: block; }
                </style>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Schedules Tab
    // ─────────────────────────────────────────────

    private function render_schedules() {
        $schedules = get_option( 'wprb_schedules', [] );
        $connected_storages = $this->get_connected_storages();
        ?>
        <div class="wprb-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; border: none; padding: 0;">Aktive Zeitpläne</h2>
                <button class="button button-primary" id="wprb-show-add-schedule" style="height: auto; padding: 6px 14px; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-plus" style="font-size: 18px; width: 18px; height: 18px;"></span>
                    Zeitplan hinzufügen
                </button>
            </div>
            
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
                            <tr id="wprb-schedule-row-<?php echo esc_attr( $id ); ?>" 
                                data-id="<?php echo esc_attr( $id ); ?>"
                                data-interval="<?php echo esc_attr( $sched['interval'] ); ?>"
                                data-time="<?php echo esc_attr( $sched['time'] ); ?>"
                                data-day-of-week="<?php echo esc_attr( $sched['day_of_week'] ?? 1 ); ?>"
                                data-day-of-month="<?php echo esc_attr( $sched['day_of_month'] ?? 1 ); ?>"
                                data-type="<?php echo esc_attr( $sched['type'] ); ?>"
                                data-destinations='<?php echo json_encode( $sched['destinations'] ?? [] ); ?>'>
                                <td>
                                    <?php
                                    $labels = [ 'hourly' => 'Stündlich', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich' ];
                                    echo esc_html( $labels[ $sched['interval'] ] ?? $sched['interval'] );
                                    
                                    if ( $sched['interval'] === 'weekly' ) {
                                        $days = [ 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So' ];
                                        echo ' (' . ( $days[ $sched['day_of_week'] ?? 1 ] ?? 'Mo' ) . ')';
                                    } elseif ( $sched['interval'] === 'monthly' ) {
                                        echo ' (' . ( $sched['day_of_month'] ?? 1 ) . '.)';
                                    }
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
                                    <button class="button button-small wprb-edit-schedule" data-id="<?php echo esc_attr( $id ); ?>" style="margin-right: 4px;">
                                        <span class="dashicons dashicons-edit"></span> Bearbeiten
                                    </button>
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

        <div id="wprb-add-schedule-wrapper" style="display: none;">
            <div class="wprb-card wprb-card-primary">
                <h2>Neuen Zeitplan erstellen</h2>
                <form id="wprb-add-schedule-form">
                    <input type="hidden" name="action" value="wprb_add_schedule">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wprb_nonce'); ?>">
                    <input type="hidden" name="schedule_id" id="wprb-schedule-id" value="">

                    <table class="form-table">
                        <tr>
                            <th>Intervall</th>
                            <td>
                                <select name="interval" id="wprb-schedule-interval">
                                    <option value="hourly">Stündlich</option>
                                    <option value="daily" selected>Täglich</option>
                                    <option value="weekly">Wöchentlich</option>
                                    <option value="monthly">Monatlich</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="wprb-weekly-options" style="display:none;">
                            <th>Wochentag</th>
                            <td>
                                <select name="day_of_week">
                                    <option value="1">Montag</option>
                                    <option value="2">Dienstag</option>
                                    <option value="3">Mittwoch</option>
                                    <option value="4">Donnerstag</option>
                                    <option value="5">Freitag</option>
                                    <option value="6">Samstag</option>
                                    <option value="7">Sonntag</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="wprb-monthly-options" style="display:none;">
                            <th>Tag des Monats</th>
                            <td>
                                <select name="day_of_month">
                                    <?php for($i=1; $i<=28; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?>.</option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">Hinweis: Nur Tage 1-28 verfügbar, um Probleme in kurzen Monaten zu vermeiden.</p>
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
                        <button type="submit" class="button button-primary" id="wprb-save-schedule-btn">
                            <span class="dashicons dashicons-plus"></span> <span class="btn-text">Zeitplan hinzufügen</span>
                        </button>
                        <button type="button" class="button button-secondary" id="wprb-cancel-add-schedule" style="margin-left: 10px;">
                            Abbrechen
                        </button>
                    </p>
                </form>
            </div>
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
        // Check SFTP
        if ( get_option( 'wprb_sftp_host' ) && get_option( 'wprb_sftp_user' ) ) {
            $connected[] = 'sftp';
        }
        // Check S3
        if ( get_option( 'wprb_s3_key' ) && get_option( 'wprb_s3_bucket' ) ) {
            $connected[] = 's3';
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

                <!-- Benachrichtigungen -->
                <h3>Benachrichtigungen</h3>
                <table class="form-table">
                    <tr>
                        <th>Benachrichtigungen</th>
                        <td>
                            <select name="email_notification_type">
                                <option value="none" <?php selected( get_option( 'wprb_email_notification_type', 'none' ), 'none' ); ?>>Deaktiviert</option>
                                <option value="always" <?php selected( get_option( 'wprb_email_notification_type' ), 'always' ); ?>>Bei jedem Backup (Erfolg & Fehler)</option>
                                <option value="error" <?php selected( get_option( 'wprb_email_notification_type' ), 'error' ); ?>>Nur bei Fehlern</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Empfänger-Adresse</th>
                        <td>
                            <input type="email" name="notification_email" class="regular-text"
                                   value="<?php echo esc_attr( get_option( 'wprb_notification_email', get_option( 'admin_email' ) ) ); ?>">
                            <p class="description">Standardmäßig wird die WordPress-Admin-E-Mail verwendet.</p>
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

                <p>Konfiguriere hier deine externen Speicherorte. Sobald sie verbunden sind, kannst du sie beim Backup auswählen.</p>
                <hr style="margin: 20px 0; border: none; border-bottom: 1px solid #f0f0f1;">

                <!-- Dropbox Settings -->
                <div id="wprb-dropbox-settings">
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
                <div id="wprb-gdrive-settings">
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

                <!-- SFTP Settings -->
                <div id="wprb-sftp-settings">
                    <h3><span class="dashicons dashicons-admin-network"></span> SFTP / FTP Konfiguration</h3>
                    <table class="form-table">
                        <tr>
                            <th>Host</th>
                            <td><input type="text" name="sftp_host" class="regular-text" placeholder="ftp.example.com"
                                       value="<?php echo esc_attr( get_option( 'wprb_sftp_host', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Port</th>
                            <td><input type="number" name="sftp_port" class="small-text" placeholder="22"
                                       value="<?php echo esc_attr( get_option( 'wprb_sftp_port', '22' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Benutzer</th>
                            <td><input type="text" name="sftp_user" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_sftp_user', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Passwort</th>
                            <td><input type="password" name="sftp_pass" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_sftp_pass', '' ) ); ?>"></td>
                        </tr>
                         <tr>
                            <th>Pfad</th>
                            <td><input type="text" name="sftp_path" class="regular-text" placeholder="/backup/"
                                       value="<?php echo esc_attr( get_option( 'wprb_sftp_path', '/' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Protokoll</th>
                            <td>
                                <select name="sftp_proto">
                                    <option value="sftp" <?php selected( get_option('wprb_sftp_proto'), 'sftp' ); ?>>SFTP (SSH)</option>
                                    <option value="ftp" <?php selected( get_option('wprb_sftp_proto'), 'ftp' ); ?>>FTP</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <hr style="margin: 20px 0; border: none; border-bottom: 1px solid #f0f0f1;">
                </div>

                <!-- S3 Settings -->
                <div id="wprb-s3-settings">
                    <h3><span class="dashicons dashicons-cloud-upload"></span> S3 Compatible Storage</h3>
                    <table class="form-table">
                        <tr>
                            <th>Endpoint</th>
                            <td>
                                <input type="text" name="s3_endpoint" class="regular-text" placeholder="s3.amazonaws.com"
                                       value="<?php echo esc_attr( get_option( 'wprb_s3_endpoint', '' ) ); ?>">
                                <p class="description">Leer lassen für AWS Standard. Benötigt für MinIO, Wasabi, DO Spaces etc.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Region</th>
                            <td><input type="text" name="s3_region" class="small-text" placeholder="us-east-1"
                                       value="<?php echo esc_attr( get_option( 'wprb_s3_region', 'us-east-1' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Access Key</th>
                            <td><input type="text" name="s3_key" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_s3_key', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Secret Key</th>
                            <td><input type="password" name="s3_secret" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_s3_secret', '' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th>Bucket</th>
                            <td><input type="text" name="s3_bucket" class="regular-text"
                                       value="<?php echo esc_attr( get_option( 'wprb_s3_bucket', '' ) ); ?>"></td>
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
                        <a href="?page=wprb-log&log_mode=normal" 
                           class="button <?php echo $mode === 'normal' ? 'button-primary' : 'button-secondary'; ?>">
                           Normal
                        </a>
                        <a href="?page=wprb-log&log_mode=detailed" 
                           class="button <?php echo $mode === 'detailed' ? 'button-primary' : 'button-secondary'; ?>">
                           Detailliert
                        </a>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 20px; display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                <?php if ( file_exists( WPRB_LOG_FILE ) && filesize( WPRB_LOG_FILE ) > 0 ) : ?>
                <a href="<?php echo admin_url( 'admin-post.php?action=wprb_download_log' ); ?>" class="button">
                    <span class="dashicons dashicons-download"></span> Log herunterladen (.txt)
                </a>
                <?php endif; ?>

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
