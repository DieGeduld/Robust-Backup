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
        add_management_page(
            'WP Robust Backup',
            'Robust Backup',
            'manage_options',
            'wp-robust-backup',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'tools_page_wp-robust-backup' ) {
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
                <a href="?page=wp-robust-backup&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings"></span> Einstellungen
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
                    case 'settings':
                        $this->render_settings();
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
                        <p class="wprb-muted">In <?php echo esc_html( $scheduler['in'] ); ?></p>
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
    // Settings Tab
    // ─────────────────────────────────────────────

    private function render_settings() {
        $storage_mgr = new WPRB_Storage_Manager();
        ?>
        <div class="wprb-card">
            <h2>Einstellungen</h2>

            <form id="wprb-settings-form">

                <!-- Schedule -->
                <h3>Zeitplan</h3>
                <table class="form-table">
                    <tr>
                        <th>Automatisches Backup</th>
                        <td>
                            <select name="schedule" id="wprb-schedule">
                                <?php
                                $schedule = get_option( 'wprb_schedule', 'daily' );
                                $options  = [
                                    'disabled' => 'Deaktiviert',
                                    'hourly'   => 'Stündlich',
                                    'daily'    => 'Täglich',
                                    'weekly'   => 'Wöchentlich',
                                    'monthly'  => 'Monatlich',
                                ];
                                foreach ( $options as $val => $label ) {
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr( $val ),
                                        selected( $schedule, $val, false ),
                                        esc_html( $label )
                                    );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Uhrzeit</th>
                        <td>
                            <input type="time" name="schedule_time" value="<?php echo esc_attr( get_option( 'wprb_schedule_time', '03:00' ) ); ?>">
                            <p class="description">Empfohlen: Nachts, wenn wenig Traffic ist.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Aufbewahrung</th>
                        <td>
                            <input type="number" name="retention" min="1" max="100"
                                   value="<?php echo esc_attr( get_option( 'wprb_retention', 5 ) ); ?>">
                            <p class="description">Anzahl der Backups, die behalten werden. Ältere werden automatisch gelöscht.</p>
                        </td>
                    </tr>
                </table>

                <!-- Storage -->
                <h3>Speicherorte</h3>
                <table class="form-table">
                    <tr>
                        <th>Aktive Speicherorte</th>
                        <td>
                            <?php
                            $active_storage = (array) get_option( 'wprb_storage', [ 'local' ] );
                            $storage_opts   = [
                                'local'   => 'Lokal auf dem Server',
                                'gdrive'  => 'Google Drive',
                                'dropbox' => 'Dropbox',
                            ];
                            foreach ( $storage_opts as $key => $label ) {
                                printf(
                                    '<label><input type="checkbox" name="storage[]" value="%s" %s> %s</label><br>',
                                    esc_attr( $key ),
                                    checked( in_array( $key, $active_storage ), true, false ),
                                    esc_html( $label )
                                );
                            }
                            ?>
                            <p class="description">Download via Browser ist immer verfügbar.</p>
                        </td>
                    </tr>
                </table>

                <!-- Google Drive -->
                <h3>Google Drive</h3>
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
                        <th>Status</th>
                        <td>
                            <?php
                            $gdrive_token = get_option( 'wprb_gdrive_token', '' );
                            if ( ! empty( $gdrive_token ) ) {
                                echo '<span class="wprb-status-ok">✅ Verbunden</span>';
                            } else {
                                $auth_url = $storage_mgr->get_gdrive_auth_url();
                                if ( $auth_url ) {
                                    echo '<a href="' . esc_url( $auth_url ) . '" class="button">Mit Google Drive verbinden</a>';
                                } else {
                                    echo '<span class="wprb-muted">Client ID eingeben und speichern, dann verbinden.</span>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                </table>

                <!-- Dropbox -->
                <h3>Dropbox</h3>
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
                        <th>Status</th>
                        <td>
                            <?php
                            $dropbox_token = get_option( 'wprb_dropbox_token', '' );
                            if ( ! empty( $dropbox_token ) ) {
                                echo '<span class="wprb-status-ok">✅ Verbunden</span>';
                            } else {
                                $auth_url = $storage_mgr->get_dropbox_auth_url();
                                if ( $auth_url ) {
                                    echo '<a href="' . esc_url( $auth_url ) . '" class="button">Mit Dropbox verbinden</a>';
                                } else {
                                    echo '<span class="wprb-muted">App Key eingeben und speichern, dann verbinden.</span>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                </table>

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

        <div class="wprb-card" style="margin-top: 20px;">
            <h3>OAuth Redirect URI</h3>
            <p>Verwende folgende URL als Redirect URI in deiner Google Cloud Console bzw. Dropbox App:</p>
            <code><?php echo esc_html( WPRB_Storage_Manager::get_oauth_redirect_url() ); ?></code>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // Log Tab
    // ─────────────────────────────────────────────

    private function render_log() {
        ?>
        <div class="wprb-card">
            <h2>Backup-Log</h2>
            <?php if ( file_exists( WPRB_LOG_FILE ) ) : ?>
                <pre class="wprb-log-viewer"><?php echo esc_html( file_get_contents( WPRB_LOG_FILE ) ); ?></pre>
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
