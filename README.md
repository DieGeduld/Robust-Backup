# WP Robust Backup – Setup-Anleitung

## Installation

1. Lade die Datei `wp-robust-backup.zip` herunter
2. WordPress Admin → **Plugins → Installieren → Plugin hochladen**
3. ZIP auswählen und installieren
4. Plugin **aktivieren**
5. Das Plugin findest du unter **Werkzeuge → Robust Backup**

---

## Grundkonfiguration

Nach der Aktivierung findest du das Plugin unter **Werkzeuge → Robust Backup**. Der Tab **Einstellungen** enthält alles Wichtige.

### Zeitplan

| Einstellung | Empfehlung | Beschreibung |
|---|---|---|
| Automatisches Backup | Täglich | Für produktive Sites. Wöchentlich reicht für kleine Blogs. |
| Uhrzeit | 03:00 | Nachts, wenn wenig Traffic ist. |
| Aufbewahrung | 5 | Anzahl der Backups die behalten werden. Bei großen Sites weniger (Speicherplatz!). |

### Performance-Tuning (für große Sites >10GB)

| Einstellung | Standard | Empfehlung bei Problemen |
|---|---|---|
| DB-Chunk-Größe | 1000 | Auf 500 reduzieren bei Timeout-Fehlern |
| Datei-Batch-Größe | 200 | Auf 100 reduzieren bei Memory-Fehlern |
| Max. Archiv-Größe | 500 MB | Auf 250 MB reduzieren wenn Upload zu Cloud fehlschlägt |

### Verzeichnis-Ausschlüsse

Standardmäßig werden diese Verzeichnisse ausgeschlossen:

```
wp-content/wprb-backups
wp-content/cache
wp-content/upgrade
```

Weitere sinnvolle Ausschlüsse je nach Setup:

```
wp-content/updraft
wp-content/ai1wm-backups
wp-content/debug.log
node_modules
.git
```

---

## Speicherorte einrichten

### Lokal auf dem Server

Funktioniert sofort – keine Konfiguration nötig. Backups werden in `wp-content/wprb-backups/` gespeichert und per `.htaccess` vor direktem Zugriff geschützt.

### Download via Browser

Ist immer verfügbar. Im Tab **Backups** gibt es Download-Links für jede einzelne Datei. Auch große Dateien werden gestreamt, ohne PHP-Memory zu sprengen.

---

## Google Drive einrichten

### Schritt 1: Google Cloud Projekt erstellen

1. Gehe zu [Google Cloud Console](https://console.cloud.google.com/)
2. Erstelle ein neues Projekt (z.B. "WP Backup")
3. Klicke oben auf **Projekt auswählen** → dein neues Projekt

### Schritt 2: Google Drive API aktivieren

1. Gehe zu **APIs & Dienste → Bibliothek**
2. Suche nach "Google Drive API"
3. Klicke auf **Aktivieren**

### Schritt 3: OAuth-Zustimmungsbildschirm

1. Gehe zu **APIs & Dienste → OAuth-Zustimmungsbildschirm**
2. Wähle **Extern** (oder Intern, falls du Google Workspace hast)
3. Fülle aus:
   - App-Name: z.B. "WP Backup"
   - Support-E-Mail: deine E-Mail
   - Autorisierte Domains: deine Domain (z.B. `deinedomain.de`)
4. Unter **Bereiche** (Scopes): Füge `https://www.googleapis.com/auth/drive.file` hinzu
5. Unter **Testnutzer**: Füge deine Google-E-Mail hinzu (solange die App nicht verifiziert ist)

### Schritt 4: OAuth-Anmeldedaten erstellen

1. Gehe zu **APIs & Dienste → Anmeldedaten**
2. Klicke **+ Anmeldedaten erstellen → OAuth-Client-ID**
3. Anwendungstyp: **Webanwendung**
4. Name: z.B. "WP Backup Client"
5. **Autorisierte Weiterleitungs-URIs**: Kopiere die URI aus dem Plugin!
   - Findest du im Plugin unter **Einstellungen** ganz unten: "OAuth Redirect URI"
   - Sieht so aus: `https://deinedomain.de/wp-admin/admin.php?page=wp-robust-backup&tab=settings&oauth_callback=1`
6. Klicke **Erstellen**
7. Notiere **Client-ID** und **Client-Secret**

### Schritt 5: Im Plugin verbinden

1. Gehe zu **Werkzeuge → Robust Backup → Einstellungen**
2. Unter **Google Drive**:
   - Trage **Client ID** ein
   - Trage **Client Secret** ein
3. Klicke **Einstellungen speichern**
4. Es erscheint ein Button **Mit Google Drive verbinden** – klicke darauf
5. Melde dich bei Google an und erlaube den Zugriff
6. Du wirst zurück zum Plugin geleitet, Status zeigt: ✅ Verbunden
7. Aktiviere **Google Drive** unter **Aktive Speicherorte** und speichere erneut

### Wo landen die Backups?

Die Backups werden in deinem Google Drive unter einem Ordner pro Backup gespeichert, z.B. `WP-Backup-backup-2025-02-05-030000`. Dateien werden per Resumable Upload in 5MB-Chunks hochgeladen – auch bei instabiler Verbindung.

---

## Dropbox einrichten

### Schritt 1: Dropbox App erstellen

1. Gehe zu [Dropbox App Console](https://www.dropbox.com/developers/apps)
2. Klicke **Create app**
3. Wähle:
   - **Scoped access**
   - **Full Dropbox** (oder App Folder, wenn du den Zugriff einschränken willst)
   - Name: z.B. "WP Backup"
4. Klicke **Create app**

### Schritt 2: Berechtigungen setzen

1. In der App-Konsole → Tab **Permissions**
2. Aktiviere diese Scopes:
   - `files.metadata.write`
   - `files.metadata.read`
   - `files.content.write`
   - `files.content.read`
3. Klicke **Submit**

### Schritt 3: Redirect URI konfigurieren

1. Tab **Settings** in der Dropbox App Console
2. Unter **OAuth 2 → Redirect URIs**:
   - Füge die Redirect URI aus dem Plugin hinzu (gleiche wie bei Google Drive)
   - Klicke **Add**
3. Notiere den **App Key** und **App Secret** (sichtbar oben auf der Settings-Seite)

### Schritt 4: Im Plugin verbinden

1. Gehe zu **Werkzeuge → Robust Backup → Einstellungen**
2. Unter **Dropbox**:
   - Trage **App Key** ein
   - Trage **App Secret** ein
3. Klicke **Einstellungen speichern**
4. Es erscheint ein Button **Mit Dropbox verbinden** – klicke darauf
5. Erlaube den Zugriff bei Dropbox
6. Du wirst zurück zum Plugin geleitet, Status zeigt: ✅ Verbunden
7. Aktiviere **Dropbox** unter **Aktive Speicherorte** und speichere erneut

### Wo landen die Backups?

Die Backups werden in deiner Dropbox unter `/WP-Backups/backup-YYYY-MM-DD-HHMMSS/` gespeichert. Große Dateien werden per Upload Session in 8MB-Chunks hochgeladen.

---

## Backup erstellen

### Manuell (Dashboard)

1. **Werkzeuge → Robust Backup → Dashboard**
2. Wähle den Backup-Typ:
   - **Vollständiges Backup**: Datenbank + alle Dateien
   - **Nur Datenbank**: Schneller, sichert nur die DB
   - **Nur Dateien**: Sichert nur die WordPress-Dateien
3. Fortschrittsbalken zeigt den aktuellen Status
4. Bei Problemen: **Abbrechen** und unter Einstellungen die Chunk-Größen reduzieren

### Automatisch (Cronjob)

Das Plugin nutzt WP-Cron. Für zuverlässige Ausführung empfiehlt es sich, WP-Cron per System-Cronjob auszulösen:

1. In `wp-config.php` einfügen:
   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. System-Cronjob einrichten (z.B. alle 5 Minuten):
   ```
   */5 * * * * wget -q -O /dev/null https://deinedomain.de/wp-cron.php
   ```
   oder:
   ```
   */5 * * * * curl -s https://deinedomain.de/wp-cron.php > /dev/null 2>&1
   ```

---

## Backup wiederherstellen (Restore)

### Schritt für Schritt

1. Gehe zu **Werkzeuge → Robust Backup → Wiederherstellen**
2. Wähle das gewünschte Backup aus der Liste
3. Klicke **Backup analysieren** – das Plugin zeigt dir:
   - Datum und Typ des Backups
   - Ob Datenbank und/oder Dateien vorhanden sind
   - WordPress-Version zum Zeitpunkt des Backups
4. Wähle, was wiederhergestellt werden soll:
   - **Datenbank**: Stellt alle Tabellen wieder her
   - **Dateien**: Extrahiert alle archivierten Dateien zurück nach WordPress
   - **Sicherheitskopie vorher erstellen**: Empfohlen! Erstellt einen DB-Dump bevor irgendwas überschrieben wird
5. Klicke **Wiederherstellung starten** und bestätige

### Was passiert bei der Wiederherstellung?

1. **Sicherheitskopie** (optional): Die aktuelle Datenbank wird als `pre-restore-*` Backup gespeichert
2. **Datenbank**: SQL-Statements werden Batch für Batch ausgeführt. Bestehende Tabellen werden per `DROP TABLE IF EXISTS` entfernt und neu erstellt.
3. **Dateien**: Archive werden nacheinander extrahiert und bestehende Dateien überschrieben.

### Nach der Wiederherstellung

- Permalink-Struktur neu speichern: **Einstellungen → Permalinks → Speichern**
- Cache leeren (falls Cache-Plugin aktiv)
- Prüfen, ob die Site korrekt funktioniert
- Bei Problemen: Das `pre-restore-*` Backup nutzen, um zurückzugehen

### Wichtige Hinweise

- Die Wiederherstellung **überschreibt** bestehende Daten
- Stelle sicher, dass das Backup von der gleichen Domain stammt, oder passe die URLs nachträglich in der DB an
- Bei einem Wechsel der Domain nach der Wiederherstellung: Nutze ein Tool wie WP-CLI oder ein Search-Replace-Plugin:
  ```
  wp search-replace 'alte-domain.de' 'neue-domain.de'
  ```

---

## Troubleshooting

### Backup bricht ab / Timeout

- Reduziere **DB-Chunk-Größe** auf 500 oder 250
- Reduziere **Datei-Batch-Größe** auf 100
- Überprüfe PHP `max_execution_time` (mindestens 60s empfohlen)
- Überprüfe PHP `memory_limit` (mindestens 256M empfohlen)

### Upload zu Google Drive / Dropbox schlägt fehl

- Prüfe ob der Token noch gültig ist (Status in Einstellungen)
- Reduziere **Max. Archiv-Größe** auf 250 MB
- Prüfe die Internetverbindung des Servers
- Bei Google Drive: Prüfe ob genügend Speicherplatz vorhanden ist

### "Es läuft bereits ein Backup"

Falls ein Backup abgebrochen wurde ohne korrekt beendet zu werden:
1. Lösche den Transient manuell (z.B. mit WP-CLI):
   ```
   wp option delete wprb_backup_state
   wp option delete wprb_db_export_state
   wp option delete wprb_file_archive_state
   ```
2. Oder warte – nach einem Page-Reload sollte es sich zurücksetzen

### Backup-Verzeichnis nicht beschreibbar

- Prüfe die Berechtigungen von `wp-content/wprb-backups/`
- Der Webserver-User (www-data, apache, nginx) braucht Schreibrechte
- `chmod 755 wp-content/wprb-backups/` oder `chown www-data:www-data wp-content/wprb-backups/`

### Log prüfen

Unter **Werkzeuge → Robust Backup → Log** findest du alle Backup- und Restore-Aktivitäten mit Zeitstempel.
