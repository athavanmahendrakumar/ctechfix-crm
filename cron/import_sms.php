<?php
// ============================================================
// Cron: Import Inbound SMS from VoIP.ms
// Recommended schedule: */5 * * * *  (every 5 minutes)
// cPanel command: php /home/ctrecfkn/cron/import_sms.php
//
// Note: The webhook (webhook/sms.php) handles real-time delivery.
// This cron is a safety net to catch any messages the webhook missed.
// ============================================================

// Only allow CLI execution — never run via browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';
require_once $rootPath . '/core/SmsImporter.php';

// Lock file — prevents overlapping runs
$lockFile = $rootPath . '/logs/import_sms.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    exit("Already running.\n");
}
touch($lockFile);

$logFile = $rootPath . '/logs/import_sms.log';

try {
    $result = SmsImporter::importRecent(2);

    $now  = date('Y-m-d H:i:s');
    $line = $now
        . ' | imported=' . $result['imported']
        . ' skipped='   . $result['skipped']
        . (count($result['errors']) ? ' errors=' . implode('; ', $result['errors']) : '')
        . "\n";

    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;

    // Record last sync time in settings table
    DB::execute(
        "INSERT INTO settings (setting_key, location_id, value) VALUES ('last_sms_sync', NULL, ?)
         ON DUPLICATE KEY UPDATE value=?",
        [$now, $now]
    );

} catch (Exception $e) {
    $line = date('Y-m-d H:i:s') . ' | EXCEPTION: ' . $e->getMessage() . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

@unlink($lockFile);
