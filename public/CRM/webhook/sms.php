<?php
// ============================================================
// VoIP.ms Inbound SMS Webhook
// Supports both:
//   - SMS/MMS URL Callback (GET):  ctrepair.ca/CRM/webhook/sms.php
//   - SMS/MMS Webhook URL (POST JSON): same URL
// ============================================================

// Load core — no Auth needed for webhooks
$rootPath = dirname(__DIR__, 3);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/helpers.php';

// Log all webhook hits for debugging
$logFile = $rootPath . '/logs/sms_webhook.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

// ── Read parameters from POST JSON body ────────────────────
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);

// Log raw payload for debugging
@file_put_contents($logFile, date('Y-m-d H:i:s') . ' | RAW: ' . $rawBody . "\n", FILE_APPEND);

// VoIP.ms Webhook URL sends a nested JSON structure:
// data.payload.from.phone_number  = sender
// data.payload.to[0].phone_number = DID that received it
// data.payload.text               = SMS body
// data.payload.id                 = message ID
// data.payload.received_at        = timestamp

$payload = $body['data']['payload'] ?? [];

$toDid   = preg_replace('/\D/', '', $payload['to'][0]['phone_number']   ?? '');
$from    = preg_replace('/\D/', '', $payload['from']['phone_number']     ?? '');

// Strip leading country code 1 from DID and sender
if (strlen($toDid) === 11 && $toDid[0] === '1') $toDid = substr($toDid, 1);
if (strlen($from)  === 11 && $from[0]  === '1') $from  = substr($from, 1);
$message = trim($payload['text']                                          ?? '');
$smsId   = $payload['id']                                                 ?? null;
$rawDate = $payload['received_at']                                        ?? null;
$smsDate = $rawDate ? date('Y-m-d H:i:s', strtotime($rawDate)) : date('Y-m-d H:i:s');

// Validate we have the minimum required fields
if (!$toDid || !$from || $message === '') {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " | REJECTED: missing fields\n", FILE_APPEND);
    echo 'ok'; // Always return ok so VoIP.ms doesn't retry bad requests
    exit;
}

// Skip duplicate (same VoIP.ms message ID already stored)
if ($smsId && DB::queryOne('SELECT id FROM sms_messages WHERE voipms_id = ? LIMIT 1', [$smsId])) {
    echo 'ok';
    exit;
}

// Identify location from the DID
$location = DB::queryOne(
    'SELECT * FROM locations WHERE REPLACE(did, \'-\', \'\') = ? LIMIT 1',
    [$toDid]
);

if (!$location) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " | UNKNOWN DID: {$toDid}\n", FILE_APPEND);
    echo 'ok';
    exit;
}

// Sender already normalized above
$fromNormalized = $from;

// Try to match to existing customer
$customer = DB::queryOne(
    'SELECT * FROM customers WHERE phone_normalized = ? LIMIT 1',
    [$fromNormalized]
);
$customerId = $customer['id'] ?? null;

// Determine if this SMS needs a reply
// Simple acknowledgements don't need follow-up
$simpleAcks = ['ok', 'okay', 'thanks', 'thank you', 'thx', 'k', 'got it', 'sure', 'yes', 'no', 'approved', 'approve', 'declined', 'decline'];
$needsReply = !in_array(strtolower(trim($message)), $simpleAcks, true) ? 1 : 0;

// Save the inbound SMS
try {
    $smsDbId = DB::insert(
        'INSERT INTO sms_messages
         (voipms_id, location_id, customer_id, direction, did, contact_number,
          message, status, needs_reply, sent_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [
            $smsId,
            $location['id'],
            $customerId,
            'inbound',
            $location['did'],
            $fromNormalized,
            $message,
            'received',
            $needsReply,
            $smsDate,
        ]
    );

    // Create a follow-up task if reply is needed
    if ($needsReply && $smsDbId) {
        $customerName = $customer
            ? ($customer['first_name'] . ' ' . $customer['last_name'])
            : formatPhone($fromNormalized);

        DB::execute(
            'INSERT INTO follow_ups
             (location_id, customer_id, sms_id, type, priority, title, due_at)
             VALUES (?,?,?,?,?,?,?)',
            [
                $location['id'],
                $customerId,
                $smsDbId,
                'sms_reply',
                'normal',
                'Reply to SMS from ' . $customerName,
                date('Y-m-d H:i:s', time() + 3600), // Due in 1 hour
            ]
        );
    }

    @file_put_contents($logFile, date('Y-m-d H:i:s') . " | SAVED sms_id={$smsDbId} from={$fromNormalized} loc={$location['code']}\n", FILE_APPEND);

} catch (Exception $e) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " | ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

// MUST return "ok" — VoIP.ms retries every 30 min until it sees this
echo 'ok';
