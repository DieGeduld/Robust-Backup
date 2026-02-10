<?php
/**
 * Email Notification Template
 *
 * Variables:
 * - $status_text (string)
 * - $has_error (bool)
 * - $site_name (string)
 * - $site_url (string)
 * - $date (string)
 * - $schedule_id (string)
 * - $message (string)
 * - $errors (array)
 * - $report_url (string)
 */

$status_color = $has_error ? '#d63638' : '#00a32a';
$bg_color     = '#f6f7f7';
$body_bg      = '#ffffff';
$text_color   = '#3c434a'; // WordPress dark gray
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Backup Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.5; color: <?php echo $text_color; ?>; background-color: <?php echo $bg_color; ?>; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: <?php echo $body_bg; ?>; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .header { background: <?php echo $status_color; ?>; color: #fff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 500; }
        .content { padding: 30px; }
        .meta { margin-bottom: 20px; font-size: 14px; color: #646970; border-bottom: 1px solid #f0f0f1; padding-bottom: 20px; }
        .meta p { margin: 5px 0; }
        .message { font-size: 16px; margin-bottom: 20px; }
        .errors { background: #fcf0f1; border-left: 4px solid #d63638; padding: 15px; margin-top: 20px; }
        .errors h3 { margin-top: 0; color: #d63638; font-size: 16px; }
        .errors ul { margin: 0; padding-left: 20px; }
        .errors li { margin-bottom: 5px; }
        .footer { background: #f6f7f7; padding: 20px; text-align: center; font-size: 12px; color: #8c8f94; border-top: 1px solid #f0f0f1; }
        .button { display: inline-block; background: #2271b1; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 3px; font-weight: bold; margin-top: 20px; }
        .button:hover { background: #135e96; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Backup <?php echo $status_text; ?></h1>
        </div>
        
        <div class="content">
            <div class="meta">
                <p><strong>Website:</strong> <a href="<?php echo esc_url( $site_url ); ?>" style="color: #2271b1; text-decoration: none;"><?php echo esc_html( $site_name ); ?></a></p>
                <p><strong>Datum:</strong> <?php echo esc_html( $date ); ?></p>
                <p><strong>Typ:</strong> <?php echo esc_html( ucfirst( $schedule_id ) ); ?></p>
            </div>

            <div class="message">
                <?php if ( $has_error ) : ?>
                    <p>Das Backup wurde mit Fehlern abgeschlossen.</p>
                <?php else : ?>
                    <p>Das Backup wurde erfolgreich erstellt und gespeichert.</p>
                <?php endif; ?>
                
                <p><strong>Details:</strong> <?php echo esc_html( $message ); ?></p>
            </div>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="errors">
                    <h3>Aufgetretene Fehler:</h3>
                    <ul>
                        <?php foreach ( $errors as $error ) : ?>
                            <li><?php echo esc_html( $error ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $report_url ) ) : ?>
                <div style="text-align: center;">
                    <a href="<?php echo esc_url( $report_url ); ?>" class="button">Zum Backup-Dashboard</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>Dies ist eine automatisch generierte Nachricht von WP Robust Backup.</p>
            <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html( $site_name ); ?></p>
        </div>
    </div>
</body>
</html>
