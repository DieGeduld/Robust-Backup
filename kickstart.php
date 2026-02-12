<?php
/**
 * WP Robust Backup - Kickstart Installer
 * 
 * A standalone script to restore backups on a new server.
 * Upload this file along with your backup files (database.sql, files-part-*, backup-meta.json).
 * 
 * Usage: Access verify via browser (e.g. http://your-site.com/kickstart.php)
 */

// Define constants
define( 'KICKSTART_VERSION', '1.0.0' );
define( 'KICKSTART_DIR', __DIR__ );
define( 'KICKSTART_SCRIPT', basename( __FILE__ ) );

// Increase limits
@ini_set( 'memory_limit', '512M' );
@set_time_limit( 0 );

// ─────────────────────────────────────────────────────────────────────────────
// CLASSES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Simplified Crypto Class
 */
class WRB_Crypto {
    const METHOD = 'aes-256-cbc';
    const CHUNK_SIZE = 1048576; // 1MB

    public static function decrypt_file( $source, $dest, $pass ) {
        if ( ! file_exists( $source ) ) return [ 'error' => 'File not found' ];
        
        $fp_in  = fopen( $source, 'rb' );
        $fp_out = fopen( $dest, 'wb' );
        
        if ( ! $fp_in || ! $fp_out ) return [ 'error' => 'IO Error' ];

        $magic = fread( $fp_in, 4 );
        if ( $magic !== 'WPRB' ) {
            fclose($fp_in); fclose($fp_out);
            return [ 'error' => 'Invalid format' ];
        }
        
        $ver  = fread( $fp_in, 1 );
        $salt = fread( $fp_in, 16 );
        $iv   = fread( $fp_in, 16 );

        $key = hash_pbkdf2( 'sha256', $pass, $salt, 10000, 32, true );
        $current_iv = $iv;
        
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
            $pt = openssl_decrypt( $chunk, self::METHOD, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv );
            
            if ( $pt === false ) {
                fclose($fp_in); fclose($fp_out);
                return [ 'error' => 'Decryption failed (Wrong password?)' ];
            }

            // Remove padding on last chunk
            if ( $processed >= $ct_size ) {
                $pad = ord( substr( $pt, -1 ) );
                if ( $pad > 0 && $pad <= 16 ) {
                    $pt = substr( $pt, 0, strlen($pt) - $pad );
                }
            }

            fwrite( $fp_out, $pt );
            $current_iv = $next_iv;
        }

        fclose( $fp_in );
        fclose( $fp_out );
        return [ 'success' => true ];
    }
}

/**
 * Installer Logic
 */
class WRB_Installer {

    private $files = [];
    private $meta  = [];

    public function __construct() {
        $this->scan_files();
    }

    private function scan_files() {
        $files = glob( '*.{sql,sql.enc,zip,gz,tar.gz,enc,json}', GLOB_BRACE );
        $this->files = array_filter( $files, function( $f ) {
            return $f !== KICKSTART_SCRIPT;
        });

        if ( file_exists( 'backup-meta.json' ) ) {
            $this->meta = json_decode( file_get_contents( 'backup-meta.json' ), true );
        }
    }

    public function handle_ajax() {
        $action = $_POST['action'] ?? '';

        switch ( $action ) {
            case 'decrypt':
                $file = $_POST['file'] ?? '';
                $pass = $_POST['pass'] ?? '';
                $dest = basename( $file, '.enc' );
                
                $res = WRB_Crypto::decrypt_file( $file, $dest, $pass );
                if ( isset( $res['error'] ) ) wp_send_json_error( $res['error'] );
                
                // Add decrypted file to list check
                wp_send_json_success( [ 'file' => $dest ] );
                break;

            case 'test_db':
                $host = $_POST['host'];
                $user = $_POST['user'];
                $pass = $_POST['pass'];
                $name = $_POST['name'];

                $mysqli = @new mysqli( $host, $user, $pass, $name );
                if ( $mysqli->connect_error ) {
                    wp_send_json_error( 'Connection failed: ' . $mysqli->connect_error );
                }
                wp_send_json_success( 'Connected successfully!' );
                break;

            case 'restore_db':
                $this->restore_db();
                break;

            case 'extract_files':
                $this->extract_files();
                break;

            case 'update_config':
                $this->update_config();
                break;
                
            case 'cleanup':
                $this->cleanup();
                break;
        }
    }

    private function restore_db() {
        $host = $_POST['host'];
        $user = $_POST['user'];
        $pass = $_POST['pass'];
        $name = $_POST['name'];
        $file = $_POST['file'] ?? 'database.sql'; // Decrypted file

        if ( ! file_exists( $file ) ) wp_send_json_error( 'SQL File not found: ' . $file );

        $mysqli = @new mysqli( $host, $user, $pass, $name );
        if ( $mysqli->connect_error ) wp_send_json_error( 'DB Connection failed' );

        $mysqli->set_charset( 'utf8mb4' );
        $mysqli->query( "SET FOREIGN_KEY_CHECKS = 0" );

        // Stream file
        $fh = fopen( $file, 'r' );
        $query = '';
        $count = 0;
        
        while ( $line = fgets( $fh ) ) {
            $trim = trim( $line );
            if ( empty( $trim ) || strpos( $trim, '--' ) === 0 || strpos( $trim, '/*' ) === 0 ) continue;
            
            $query .= $line;
            if ( substr( trim($query), -1 ) === ';' ) {
                if ( ! $mysqli->query( $query ) ) {
                    // Ignore "table exists" etc?
                    // wp_send_json_error( 'SQL Error: ' . $mysqli->error );
                }
                $query = '';
                $count++;
            }
        }
        
        $mysqli->query( "SET FOREIGN_KEY_CHECKS = 1" );
        fclose( $fh );
        
        wp_send_json_success( [ 'count' => $count ] );
    }

    private function extract_files() {
        $archive = $_POST['archive'];
        
        if ( ! file_exists( $archive ) ) wp_send_json_error( 'Archive not found' );

        $ext = pathinfo( $archive, PATHINFO_EXTENSION );
        $res = false;

        if ( $ext === 'zip' ) {
            $zip = new ZipArchive;
            if ( $zip->open( $archive ) === true ) {
                $zip->extractTo( __DIR__ );
                $zip->close();
                $res = true;
            }
        } elseif ( $ext === 'gz' || strpos( $archive, '.tar.gz' ) !== false ) {
            // Try shell Exec
            // TODO: PharData fallback
            $cmd = "tar -xzf " . escapeshellarg( $archive ) . " -C " . escapeshellarg( __DIR__ );
            exec( $cmd, $out, $ret );
            $res = ( $ret === 0 );
        }

        if ( $res ) wp_send_json_success();
        else wp_send_json_error( 'Extraction failed' );
    }

    private function update_config() {
        $config_file = 'wp-config.php';
        if ( ! file_exists( $config_file ) ) wp_send_json_error( 'wp-config.php not found' );

        $content = file_get_contents( $config_file );
        
        $host = $_POST['host'];
        $user = $_POST['user'];
        $pass = $_POST['pass'];
        $name = $_POST['name'];

        // Simple Regex Replacements
        $content = preg_replace( "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"].*?['\"]\s*\);/", "define( 'DB_NAME', '$name' );", $content );
        $content = preg_replace( "/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"].*?['\"]\s*\);/", "define( 'DB_USER', '$user' );", $content );
        $content = preg_replace( "/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"].*?['\"]\s*\);/", "define( 'DB_PASSWORD', '$pass' );", $content );
        $content = preg_replace( "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"].*?['\"]\s*\);/", "define( 'DB_HOST', '$host' );", $content );

        file_put_contents( $config_file, $content );
        wp_send_json_success();
    }
    
    private function cleanup() {
        // Delete restored archives and self
         $files = glob( '*.{sql,sql.enc,zip,gz,tar.gz,enc,json,part,part*}', GLOB_BRACE );
         foreach ( $files as $f ) {
             if ( $f !== KICKSTART_SCRIPT ) {
                @unlink( $f );
             }
         }
         
         // Self destruct?
         @unlink( __FILE__ );
         
         wp_send_json_success();
    }

    public function render() {
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WP Robust Backup - Kickstart</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: #f0f0f1; color: #3c434a; line-height: 1.5; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: #2271b1; color: #fff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .step { display: none; }
        .step.active { display: block; }
        .btn { display: inline-block; background: #2271b1; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 16px; transition: background .2s; }
        .btn:hover { background: #135e96; }
        .btn:disabled { background: #a7aaad; cursor: not-allowed; }
        .btn-secondary { background: #f6f7f7; color: #2c3338; border: 1px solid #c3c4c7; }
        .btn-secondary:hover { background: #f0f0f1; border-color: #8c8f94; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #dcdcde; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
        .file-list { border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; background: #f9f9f9; margin-bottom: 20px; max-height: 200px; overflow-y: auto; }
        .file-item { padding: 5px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }
        .file-item:last-child { border-bottom: none; }
        .badge { background: #dcdcde; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .badge.enc { background: #fce9e2; color: #d63638; }
        .progress-bar { height: 20px; background: #f0f0f1; border-radius: 10px; overflow: hidden; margin: 20px 0; }
        .progress-fill { height: 100%; background: #2271b1; width: 0%; transition: width 0.3s; }
        .log { background: #2c3338; color: #fff; padding: 15px; border-radius: 4px; height: 150px; overflow-y: auto; font-family: monospace; font-size: 13px; margin-top: 20px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>WP Robust Backup - Kickstart</h1>
    </div>
    
    <div class="content">
        
        <!-- Step 1: Welcome & Checks -->
        <div id="step-1" class="step active">
            <h2>1. Backup Dateien prüfen</h2>
            <p>Willkommen! Dieses Script hilft dir, deine WordPress-Seite wiederherzustellen. Folgende Dateien wurden gefunden:</p>
            
            <div class="file-list">
                <?php if ( empty( $this->files ) ): ?>
                    <p>Keine Backup-Dateien gefunden. Bitte lade .sql, .zip oder .enc Dateien hoch.</p>
                <?php else: ?>
                    <?php foreach ( $this->files as $f ): ?>
                        <div class="file-item">
                            <span><?php echo htmlspecialchars( $f ); ?></span>
                            <?php if ( substr( $f, -4 ) === '.enc' ): ?>
                                <span class="badge enc">Verschlüsselt</span>
                            <?php else: ?>
                                <span class="badge">Bereit</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="enc-section" style="display: none;">
                <div class="form-group">
                    <label>Entschlüsselungs-Passwort</label>
                    <input type="password" id="enc-pass" placeholder="Passwort eingeben...">
                    <p style="font-size: 13px; color: #666;">Einige Dateien sind verschlüsselt. Bitte Passwort eingeben.</p>
                </div>
            </div>

            <button class="btn" onclick="nextStep(1)">Weiter</button>
        </div>

        <!-- Step 2: Decrypt (Progress) -->
        <div id="step-decrypt" class="step">
            <h2>2. Entschlüsselung</h2>
            <p>Dateien werden entschlüsselt...</p>
            <div class="progress-bar"><div class="progress-fill" id="dec-bar"></div></div>
            <div id="dec-log" class="log"></div>
        </div>

        <!-- Step 3: Database -->
        <div id="step-db" class="step">
            <h2>3. Datenbank Verbindung</h2>
            <p>Bitte gib die Zugangsdaten für die neue Datenbank ein:</p>
            
            <div class="form-group">
                <label>Datenbank Host</label>
                <input type="text" id="db-host" value="localhost">
            </div>
            <div class="form-group">
                <label>Datenbank Name</label>
                <input type="text" id="db-name" placeholder="wp_database">
            </div>
            <div class="form-group">
                <label>Datenbank User</label>
                <input type="text" id="db-user" placeholder="root">
            </div>
            <div class="form-group">
                <label>Datenbank Passwort</label>
                <input type="password" id="db-pass">
            </div>

            <button class="btn btn-secondary" onclick="testDB()">Verbindung testen</button>
            <button class="btn" id="db-next-btn" onclick="startRestore()" disabled style="margin-left: 10px;">Wiederherstellung starten</button>
            <p id="db-status" style="margin-top: 10px; font-weight: bold;"></p>
        </div>

        <!-- Step 4: Restore Progress -->
        <div id="step-restore" class="step">
            <h2>4. Wiederherstellung läuft</h2>
            <p>Bitte warten, Dateien und Datenbank werden wiederhergestellt...</p>
            <div class="progress-bar"><div class="progress-fill" id="res-bar"></div></div>
            <div id="res-log" class="log"></div>
        </div>
        
        <!-- Step 5: Done -->
        <div id="step-done" class="step">
            <h2>5. Fertig!</h2>
            <p style="color: green; font-weight: bold; font-size: 18px;">Deine Seite wurde erfolgreich wiederhergestellt.</p>
            <p>Es wird empfohlen, die Installationsdateien (Kickstart + Backups) zu löschen.</p>
            
            <button class="btn" onclick="cleanup()">Dateien löschen & zur Seite</button>
            <a href="/" class="btn btn-secondary" style="margin-left: 10px;">Ohne Löschen zur Seite</a>
        </div>

    </div>
</div>

<script>
let files = <?php echo json_encode( array_values( $this->files ) ); ?>;
let hasEncrypted = files.some( f => f.endsWith('.enc') );
let decryptedFiles = [];
let dbConfig = {};

document.addEventListener('DOMContentLoaded', () => {
    if ( hasEncrypted ) {
        document.getElementById('enc-section').style.display = 'block';
    }
});

function log( msg, id = 'res-log' ) {
    let el = document.getElementById(id);
    el.innerHTML += msg + '<br>';
    el.scrollTop = el.scrollHeight;
}

function ajax( action, data ) {
    let fd = new FormData();
    fd.append('action', action);
    for ( let k in data ) fd.append(k, data[k]);
    
    return fetch('<?php echo basename(__FILE__); ?>', {
        method: 'POST',
        body: fd
    }).then( r => r.json() );
}

function nextStep( current ) {
    if ( current === 1 ) {
        if ( hasEncrypted ) {
            // Check pass
            let pass = document.getElementById('enc-pass').value;
            if ( ! pass ) {
                alert('Bitte Passwort eingeben.');
                return;
            }
            // Go to decrypt step
            showStep('step-decrypt');
            runDecryption( pass );
        } else {
            showStep('step-db');
        }
    }
}

function showStep( id ) {
    document.querySelectorAll('.step').forEach( s => s.classList.remove('active') );
    document.getElementById(id).classList.add('active');
}

async function runDecryption( pass ) {
    let encFiles = files.filter( f => f.endsWith('.enc') );
    let total = encFiles.length;
    let done = 0;
    
    for ( let f of encFiles ) {
        log( 'Entschlüssle: ' + f, 'dec-log' );
        try {
            let res = await ajax('decrypt', { file: f, pass: pass });
            if ( res.success ) {
                log( 'OK: ' + f, 'dec-log' );
                decryptedFiles.push( res.data.file );
                done++;
                document.getElementById('dec-bar').style.width = (done/total*100) + '%';
            } else {
                log( 'FEHLER: ' + res.data, 'dec-log' );
                alert('Entschlüsselung fehlgeschlagen!');
                return;
            }
        } catch(e) {
            log( 'Netzwerkfehler', 'dec-log' );
            return;
        }
    }
    
    setTimeout( () => showStep('step-db'), 1000 );
}

async function testDB() {
    dbConfig = {
        host: document.getElementById('db-host').value,
        name: document.getElementById('db-name').value,
        user: document.getElementById('db-user').value,
        pass: document.getElementById('db-pass').value
    };
    
    let res = await ajax( 'test_db', dbConfig );
    if ( res.success ) {
        document.getElementById('db-status').style.color = 'green';
        document.getElementById('db-status').innerText = 'Verbindung erfolgreich!';
        document.getElementById('db-next-btn').disabled = false;
    } else {
        document.getElementById('db-status').style.color = 'red';
        document.getElementById('db-status').innerText = res.data;
    }
}

async function startRestore() {
    showStep('step-restore');
    
    // 1. Import DB
    // Use decrypted sql if available, else look for database.sql
    let sqlFile = 'database.sql';
    if ( decryptedFiles.includes('database.sql') ) sqlFile = 'database.sql';
    
    // Check if we have an SQL file
    let hasSQL = files.includes('database.sql') || decryptedFiles.includes('database.sql');
    
    if ( hasSQL ) {
        log( 'Importiere Datenbank...' );
        let res = await ajax( 'restore_db', { ...dbConfig, file: sqlFile } );
        if ( res.success ) {
            log( 'Datenbank importiert!' );
        } else {
            log( 'DB Fehler: ' + res.data );
        }
    }
    
    document.getElementById('res-bar').style.width = '33%';
    
    // 2. Extract Files
    let archives = files.filter( f => f.includes('files-part') );
    // Map .enc archives to decrypted versions
    let readyArchives = [];
    archives.forEach( f => {
        if ( f.endsWith('.enc') ) {
             let base = f.replace('.enc', '');
             if ( decryptedFiles.includes(base) || files.includes(base) ) readyArchives.push(base);
        } else {
            readyArchives.push(f);
        }
    });

    // Unique
    readyArchives = [...new Set(readyArchives)];
    
    let i = 0;
    for ( let arc of readyArchives ) {
        log( 'Entpacke: ' + arc );
        let res = await ajax( 'extract_files', { archive: arc } );
        if ( res.success ) {
            log( 'OK' );
        } else {
            log( 'Fehler: ' + res.data );
        }
        i++;
        document.getElementById('res-bar').style.width = (33 + (i/readyArchives.length*33)) + '%';
    }
    
    // 3. Update Config
    log( 'Updates wp-config.php...' );
    await ajax( 'update_config', dbConfig );
    
    document.getElementById('res-bar').style.width = '100%';
    setTimeout( () => showStep('step-done'), 1000 );
}

async function cleanup() {
    if ( confirm('Wirklich alle Backup-Dateien und diesen Installer löschen?') ) {
        await ajax('cleanup', {});
        window.location.href = '/';
    }
}

</script>
</body>
</html>
        <?php
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────────────────────────────────────

// Helpers
function wp_send_json_success( $data = null ) {
    echo json_encode( [ 'success' => true, 'data' => $data ] );
    exit;
}
function wp_send_json_error( $data = null ) {
    echo json_encode( [ 'success' => false, 'data' => $data ] );
    exit;
}

$installer = new WRB_Installer();

if ( isset( $_POST['action'] ) ) {
    $installer->handle_ajax();
} else {
    $installer->render();
}
