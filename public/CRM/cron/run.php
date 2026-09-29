<?php
// ============================================================
// URL-based Cron Runner — Namecheap shared hosting safe
// Called by cPanel cron via wget:
//   */10 * * * * /usr/bin/wget -q -O /dev/null "https://ctrepair.ca/CRM/cron/run.php?job=calls&token=cTf-cr0n-7x9Qm2pL4nR8wK1"
//   */5  * * * * /usr/bin/wget -q -O /dev/null "https://ctrepair.ca/CRM/cron/run.php?job=sms&token=cTf-cr0n-7x9Qm2pL4nR8wK1"
//   0    9 * * * /usr/bin/wget -q -O /dev/null "https://ctrepair.ca/CRM/cron/run.php?job=maintenance&token=cTf-cr0n-7x9Qm2pL4nR8wK1"
// ============================================================

$rootPath = dirname(__DIR__, 3);
require_once $rootPath . '/config/config.php';

// Authenticate — secret token required
$token = $_GET['token'] ?? '';
if ($token !== CRON_SECRET) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

$job = $_GET['job'] ?? '';
if (!in_array($job, ['calls', 'sms', 'maintenance', 'booking_reminder'])) {
    exit('Unknown job.');
}

// ── Helper: save sync time (handles NULL unique key properly) ─────────────
function saveSyncTime(string $key, string $now): void {
    $existing = DB::queryOne("SELECT id FROM settings WHERE setting_key=? AND location_id IS NULL", [$key]);
    if ($existing) {
        DB::execute("UPDATE settings SET value=? WHERE setting_key=? AND location_id IS NULL", [$now, $key]);
    } else {
        DB::execute("INSERT INTO settings (setting_key, location_id, value) VALUES (?, NULL, ?)", [$key, $now]);
    }
}

$logFile = $rootPath . '/logs/cron_' . $job . '.log';
$now     = date('Y-m-d H:i:s');

if ($job === 'calls') {
    require_once $rootPath . '/core/CallImporter.php';

    // Lock check
    $lock = $rootPath . '/logs/import_calls.lock';
    if (file_exists($lock) && (time() - filemtime($lock)) < 480) {
        exit("Calls: already running.\n");
    }
    touch($lock);

    try {
        $result = CallImporter::importRecent(3);
        $line   = $now . ' | imported=' . $result['imported'] . ' skipped=' . $result['skipped']
                . (count($result['errors']) ? ' errors=' . implode('; ', $result['errors']) : '') . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
        saveSyncTime('last_call_sync', $now);
        echo $line;
    } catch (\Throwable $e) {
        $line = $now . ' | EXCEPTION: ' . $e->getMessage() . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
        echo $line;
    }
    @unlink($lock);

} elseif ($job === 'sms') {
    require_once $rootPath . '/core/SmsImporter.php';

    // Lock check
    $lock = $rootPath . '/logs/import_sms.lock';
    if (file_exists($lock) && (time() - filemtime($lock)) < 240) {
        exit("SMS: already running.\n");
    }
    touch($lock);

    try {
        $result = SmsImporter::importRecent(2);
        $line   = $now . ' | imported=' . $result['imported'] . ' skipped=' . $result['skipped']
                . (count($result['errors']) ? ' errors=' . implode('; ', $result['errors']) : '') . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
        saveSyncTime('last_sms_sync', $now);
        echo $line;
    } catch (\Throwable $e) {
        $line = $now . ' | EXCEPTION: ' . $e->getMessage() . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
        echo $line;
    }

    // ── Booking reply check (runs every time, catches already-imported messages) ──
    try {
        $recentInbound = DB::query(
            "SELECT contact_number, message, sent_at
             FROM sms_messages
             WHERE direction = 'inbound'
               AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY sent_at DESC",
            []
        );
        foreach ($recentInbound as $sms) {
            $clean = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $sms['message'])));
            if (!in_array($clean, ['yes','y','no','n'], true)) continue;
            $reply = in_array($clean, ['yes','y'], true) ? 'yes' : 'no';
            $from  = preg_replace('/\D/', '', $sms['contact_number']);
            if (strlen($from) === 11 && $from[0] === '1') $from = substr($from, 1);
            if (!$from) continue;
            DB::execute(
                "UPDATE bookings
                 SET customer_reply=?, customer_replied_at=?
                 WHERE customer_reply IS NULL
                   AND status IN ('pending','confirmed')
                   AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                   AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone,' ',''),'-',''),'(',''),')',''),'+1','') LIKE ?",
                [$reply, $sms['sent_at'], '%' . $from . '%']
            );
        }
    } catch (\Throwable $e) {}

    @unlink($lock);

} elseif ($job === 'maintenance') {
    // Send follow-up SMS for due maintenance records
    $today = date('Y-m-d');
    $due = DB::query(
        "SELECT m.*, l.code AS loc_code
         FROM device_maintenance m
         LEFT JOIN locations l ON l.id=m.location_id
         WHERE m.follow_up_sms_sent=0
           AND m.follow_up_date <= ?
           AND m.customer_phone IS NOT NULL
           AND m.customer_phone != ''",
        [$today]
    );

    $sent = 0; $errs = [];
    foreach ($due as $m) {
        $firstName  = explode(' ', $m['customer_name'])[0];
        $type       = $m['maintenance_type'];
        $device     = trim(($m['device_brand'] ?? '') . ' ' . ($m['device_model'] ?? ''));
        $devicePart = $device ? " for your {$device}" : '';
        $locName    = DB::queryOne("SELECT name FROM locations WHERE id=?", [$m['location_id']])['name'] ?? 'C Tech Fix';
        $message    = "Hi {$firstName}, C Tech Fix {$locName}: Your {$type}{$devicePart} is due. Call or text us to book!";
        $message    = mb_substr($message, 0, 159);

        $fromDid = VoipMS::didForLocation($m['loc_code'] ?? '');
        $result  = VoipMS::sendSMS($fromDid, $m['customer_phone'], $message);

        if ($result === true) {
            DB::execute(
                "UPDATE device_maintenance SET follow_up_sms_sent=1, follow_up_sms_sent_at=NOW() WHERE id=?",
                [$m['id']]
            );
            $sent++;
        } else {
            $errs[] = "ID {$m['id']}: " . $result;
        }
    }

    $line = $now . ' | maintenance sent=' . $sent . ' due=' . count($due)
          . (count($errs) ? ' errors=' . implode('; ', $errs) : '') . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;

} elseif ($job === 'booking_reminder') {
    // Send day-before reminder SMS for bookings with a confirmed appointment time
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $due = DB::query(
        "SELECT b.*, l.code AS loc_code, l.name AS loc_name
         FROM bookings b
         JOIN locations l ON l.id = b.location_id
         WHERE b.booking_date = ?
           AND b.appointment_time IS NOT NULL
           AND b.confirmation_sms_sent = 1
           AND b.reminder_sms_sent = 0
           AND b.status IN ('pending','confirmed')
           AND b.customer_reply != 'no'",
        [$tomorrow]
    );

    $sent = 0; $errs = [];
    foreach ($due as $b) {
        $firstName     = explode(' ', trim($b['customer_name']))[0];
        $dateFormatted = date('l, F j', strtotime($b['booking_date']));
        $timeFormatted = date('g:i A',  strtotime($b['appointment_time']));

        $message = "Hi {$firstName}, reminder: C Tech Fix {$b['loc_name']} tomorrow, {$dateFormatted} at {$timeFormatted}. See you then!";

        $fromDid = VoipMS::didForLocation($b['loc_code']);
        $result  = VoipMS::sendSMS($fromDid, $b['customer_phone'], $message);

        if ($result === true) {
            DB::execute(
                "UPDATE bookings SET reminder_sms_sent=1, reminder_sms_sent_at=NOW() WHERE id=?",
                [$b['id']]
            );
            $sent++;
        } else {
            $errs[] = "Booking ID {$b['id']}: " . $result;
        }
    }

    $line = $now . ' | booking_reminder sent=' . $sent . ' due=' . count($due)
          . (count($errs) ? ' errors=' . implode('; ', $errs) : '') . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}
