<?php
// ============================================================
// Call Log Importer
// Pulls calls from VoIP.ms and saves to DB without duplicates
// ============================================================
class CallImporter {

    // ----------------------------------------------------------
    // Import calls for both DIDs for the last N days
    // ----------------------------------------------------------
    public static function importRecent(int $days = 7): array {
        $results = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
        $dateTo   = date('Y-m-d');

        $locations = DB::query('SELECT * FROM locations WHERE is_active = 1');

        foreach ($locations as $location) {
            $did  = VoipMS::didForLocation($location['code']);
            $calls = VoipMS::getCallLogs($did, $dateFrom, $dateTo);

            foreach ($calls as $call) {
                $result = self::importCall($call, $location);
                if ($result === 'imported') $results['imported']++;
                elseif ($result === 'skipped') $results['skipped']++;
                else $results['errors'][] = $result;
            }
        }

        return $results;
    }

    // ----------------------------------------------------------
    // Import a single call record
    // ----------------------------------------------------------
    private static function importCall(array $call, array $location): string {
        // VoIP.ms CDR fields: uniqueid, date, callerid, destination,
        // description, account, duration, disposition, rate, total

        $voipmsId = $call['uniqueid'] ?? null;

        // Skip if already imported
        if ($voipmsId && DB::queryOne('SELECT id FROM call_logs WHERE voipms_id = ? LIMIT 1', [$voipmsId])) {
            return 'skipped';
        }

        // Parse caller number
        $callerRaw    = $call['callerid'] ?? '';
        $callerNumber = normalizePhone(preg_replace('/[^0-9+]/', '', $callerRaw));
        $callerName   = '';

        // Extract name from callerid like "John Smith <9051234567>"
        if (preg_match('/^"?([^"<]+)"?\s*</', $callerRaw, $m)) {
            $callerName = trim($m[1]);
        }

        // Determine direction and status
        $disposition = strtolower($call['disposition'] ?? 'answered');
        $status = match($disposition) {
            'answered'  => 'answered',
            'no answer' => 'missed',
            'busy'      => 'busy',
            'failed'    => 'failed',
            default     => 'answered',
        };

        $direction = str_contains($call['description'] ?? '', 'Outbound') ? 'outbound' : 'inbound';

        // Duration in seconds
        $duration = 0;
        if (!empty($call['duration'])) {
            $parts    = explode(':', $call['duration']);
            $duration = ((int)($parts[0] ?? 0) * 3600)
                      + ((int)($parts[1] ?? 0) * 60)
                      + (int)($parts[2] ?? 0);
        }

        // Parse call datetime
        $callAt = $call['date'] ?? date('Y-m-d H:i:s');

        // Try to match existing customer by phone
        $customerId = null;
        if ($callerNumber) {
            $customer = DB::queryOne(
                'SELECT id FROM customers WHERE phone_normalized = ? LIMIT 1',
                [$callerNumber]
            );
            $customerId = $customer['id'] ?? null;
        }

        // Needs callback if missed inbound
        $needsCallback = ($status === 'missed' && $direction === 'inbound') ? 1 : 0;
        $callbackDueAt = null;
        if ($needsCallback) {
            // Set callback due 30 minutes from call time
            $callbackDueAt = date('Y-m-d H:i:s', strtotime($callAt) + 1800);
        }

        try {
            DB::insert(
                'INSERT INTO call_logs
                 (voipms_id, location_id, customer_id, direction, did, caller_number,
                  caller_name, duration_seconds, status, call_at, needs_callback,
                  callback_status, callback_due_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $voipmsId,
                    $location['id'],
                    $customerId,
                    $direction,
                    $location['did'],
                    $callerNumber,
                    $callerName,
                    $duration,
                    $status,
                    $callAt,
                    $needsCallback,
                    $needsCallback ? 'pending' : null,
                    $callbackDueAt,
                ]
            );
            return 'imported';
        } catch (Exception $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
