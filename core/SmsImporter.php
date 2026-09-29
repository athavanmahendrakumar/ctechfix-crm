<?php
// ============================================================
// SMS Importer
// Polls VoIP.ms for inbound SMS as a backup to the webhook.
// Webhook handles real-time; this catches anything missed.
// ============================================================
class SmsImporter {

    public static function importRecent(int $hours = 2): array {
        $results = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $dateFrom = date('Y-m-d', strtotime("-{$hours} hours"));
        $dateTo   = date('Y-m-d');

        $locations = DB::query('SELECT * FROM locations WHERE is_active = 1', []);

        foreach ($locations as $location) {
            $did      = preg_replace('/\D/', '', $location['did']);
            $messages = VoipMS::getSMSMessages($did, $dateFrom, $dateTo);

            foreach ($messages as $sms) {
                $result = self::importSms($sms, $location);
                if ($result === 'imported') $results['imported']++;
                elseif ($result === 'skipped') $results['skipped']++;
                else $results['errors'][] = $result;
            }
        }

        return $results;
    }

    private static function importSms(array $sms, array $location): string {
        // VoIP.ms getSMS fields: id, date, type, did, contact, message
        $voipmsId = $sms['id'] ?? null;

        // Skip if already saved (webhook may have already stored it)
        if ($voipmsId && DB::queryOne('SELECT id FROM sms_messages WHERE voipms_id = ? LIMIT 1', [$voipmsId])) {
            return 'skipped';
        }

        $from = preg_replace('/\D/', '', $sms['contact'] ?? '');
        if (strlen($from) === 11 && $from[0] === '1') $from = substr($from, 1);

        $message = trim($sms['message'] ?? '');
        $smsDate = !empty($sms['date']) ? date('Y-m-d H:i:s', strtotime($sms['date'])) : date('Y-m-d H:i:s');

        if (!$from || $message === '') return 'skipped';

        // Match to customer
        $customer   = DB::queryOne('SELECT * FROM customers WHERE phone_normalized = ? LIMIT 1', [$from]);
        $customerId = $customer['id'] ?? null;

        // Needs reply?
        $simpleAcks = ['ok','okay','thanks','thank you','thx','k','got it','sure','yes','no','approved','approve','declined','decline'];
        $needsReply = !in_array(strtolower(trim($message)), $simpleAcks, true) ? 1 : 0;

        try {
            $smsDbId = DB::insert(
                'INSERT INTO sms_messages
                 (voipms_id, location_id, customer_id, direction, did, contact_number,
                  message, status, needs_reply, sent_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $voipmsId,
                    $location['id'],
                    $customerId,
                    'inbound',
                    $location['did'],
                    $from,
                    $message,
                    'received',
                    $needsReply,
                    $smsDate,
                ]
            );

            // Create follow-up if reply needed
            if ($needsReply && $smsDbId) {
                $customerName = $customer
                    ? ($customer['first_name'] . ' ' . $customer['last_name'])
                    : $from;

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
                        date('Y-m-d H:i:s', time() + 3600),
                    ]
                );
            }

            // ── Booking YES/NO reply detection ────────────────
            self::checkBookingReply($from, $message, $smsDate);

            return 'imported';
        } catch (Exception $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * If the inbound message is YES or NO, and the sender has a booking
     * with a confirmation SMS sent and no reply yet — record it.
     */
    private static function checkBookingReply(string $fromPhone, string $message, string $smsDate): void {
        $clean = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $message)));
        if (!in_array($clean, ['yes', 'y', 'no', 'n'], true)) return;

        $reply = in_array($clean, ['yes', 'y'], true) ? 'yes' : 'no';

        // Match phone to a booking that has confirmation sent but no reply yet
        $booking = DB::queryOne(
            "SELECT id FROM bookings
             WHERE customer_reply IS NULL
               AND status IN ('pending','confirmed')
               AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
               AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone,' ',''),'-',''),'(',''),')',''),'+1','') LIKE ?
             ORDER BY booking_date ASC
             LIMIT 1",
            ['%' . $fromPhone . '%']
        );

        if ($booking) {
            DB::execute(
                "UPDATE bookings SET customer_reply=?, customer_replied_at=? WHERE id=?",
                [$reply, $smsDate, $booking['id']]
            );
        }
    }
}
