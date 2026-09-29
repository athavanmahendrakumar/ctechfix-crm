<?php
// ============================================================
// VoIP.ms API Client
// Handles all communication with the VoIP.ms REST API
// Credentials and DIDs are loaded from the database (settings
// and locations tables) with a fallback to config constants.
// ============================================================
class VoipMS {

    /** Request-scoped credential cache */
    private static ?array $_creds = null;

    // ----------------------------------------------------------
    // Load API credentials from settings table (with fallback)
    // ----------------------------------------------------------
    private static function getCredentials(): array {
        if (self::$_creds !== null) return self::$_creds;

        $rows = DB::query(
            "SELECT setting_key, value FROM settings
             WHERE setting_key IN ('voipms_api_username','voipms_api_password')
               AND location_id IS NULL",
            []
        );
        $s = [];
        foreach ($rows as $r) $s[$r['setting_key']] = $r['value'];

        self::$_creds = [
            'username' => $s['voipms_api_username']
                          ?? (defined('VOIPMS_API_USERNAME') ? VOIPMS_API_USERNAME : ''),
            'password' => $s['voipms_api_password']
                          ?? (defined('VOIPMS_API_PASSWORD') ? VOIPMS_API_PASSWORD : ''),
        ];
        return self::$_creds;
    }

    // ----------------------------------------------------------
    // Make a call to the VoIP.ms API
    // ----------------------------------------------------------
    private static function call(string $method, array $params = []): array {
        $creds = self::getCredentials();
        $params['api_username'] = $creds['username'];
        $params['api_password'] = $creds['password'];
        $params['method']       = $method;

        $url = VOIPMS_API_URL . '?' . http_build_query($params);

        $ctx = stream_context_create(['http' => [
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $ctx);

        if ($response === false) {
            return ['status' => 'error', 'message' => 'Could not reach VoIP.ms API'];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return ['status' => 'error', 'message' => 'Invalid response from VoIP.ms'];
        }

        return $data;
    }

    // ----------------------------------------------------------
    // Test API credentials
    // ----------------------------------------------------------
    public static function testConnection(): bool|string {
        $result = self::call('getServersInfo');
        if (($result['status'] ?? '') === 'success') return true;
        return $result['status'] ?? 'unknown error';
    }

    // ----------------------------------------------------------
    // Get call logs for a DID between two dates
    // ----------------------------------------------------------
    public static function getCallLogs(string $did, string $dateFrom, string $dateTo): array {
        $result = self::call('getCDR', [
            'date_from' => $dateFrom,   // YYYY-MM-DD
            'date_to'   => $dateTo,
            'timezone'  => -5,          // EST (UTC-5); adjust for DST as needed
            'answered'  => 1,
            'noanswer'  => 1,
            'busy'      => 1,
            'failed'    => 1,
        ]);

        if (($result['status'] ?? '') !== 'success') {
            return [];
        }

        // Filter to only calls involving this DID
        $calls = $result['cdr'] ?? [];
        return array_filter($calls, function($call) use ($did) {
            $normalizedDid = preg_replace('/\D/', '', $did);
            return str_contains($call['destination'] ?? '', $normalizedDid)
                || str_contains($call['account']     ?? '', $normalizedDid)
                || str_contains($call['callerid']    ?? '', $normalizedDid);
        });
    }

    // ----------------------------------------------------------
    // Send an outbound SMS
    // Returns true on success, error string on failure
    // ----------------------------------------------------------
    public static function sendSMS(string $fromDid, string $toNumber, string $message): bool|string {
        // Normalize numbers
        $toNumber = preg_replace('/\D/', '', $toNumber);
        if (strlen($toNumber) === 11 && $toNumber[0] === '1') {
            $toNumber = substr($toNumber, 1);
        }
        $fromDid = preg_replace('/\D/', '', $fromDid);

        $result = self::call('sendSMS', [
            'did'     => $fromDid,
            'dst'     => $toNumber,
            'message' => $message,
        ]);

        if (($result['status'] ?? '') === 'success') {
            return true;
        }

        // Return detailed error for diagnosis
        $status = $result['status'] ?? 'unknown';
        unset($result['status']);
        $extra = array_filter($result);   // any other fields VoIP.ms returned
        return $status . (count($extra) ? ' | ' . json_encode($extra) : '')
             . " | did={$fromDid} dst={$toNumber} msglen=" . mb_strlen($message);
    }

    // ----------------------------------------------------------
    // Get inbound SMS messages (polling fallback)
    // ----------------------------------------------------------
    public static function getSMSMessages(string $did, string $dateFrom, string $dateTo): array {
        $did = preg_replace('/\D/', '', $did);

        $result = self::call('getSMS', [
            'did'       => $did,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'type'      => 1,   // 1 = inbound
            'limit'     => 100,
        ]);

        if (($result['status'] ?? '') !== 'success') {
            return [];
        }

        return $result['sms'] ?? [];
    }

    // ----------------------------------------------------------
    // Get the DID for a given location code
    // Queries locations.did; falls back to config constants
    // ----------------------------------------------------------
    public static function didForLocation(string $locationCode): string {
        $loc = DB::queryOne(
            'SELECT did FROM locations WHERE code = ? AND is_active = 1 LIMIT 1',
            [strtoupper($locationCode)]
        );
        if ($loc && !empty($loc['did'])) {
            return preg_replace('/\D/', '', $loc['did']);
        }
        // Fallback to config constants (backward compat)
        return match(strtoupper($locationCode)) {
            'OS'    => defined('VOIPMS_DID_OS') ? VOIPMS_DID_OS : '',
            'PF'    => defined('VOIPMS_DID_PF') ? VOIPMS_DID_PF : '',
            default => '',
        };
    }

    // ----------------------------------------------------------
    // Detect location code from an inbound DID number
    // Queries locations table; falls back to config constants
    // ----------------------------------------------------------
    public static function locationFromDid(string $did): ?string {
        $did = preg_replace('/\D/', '', $did);

        $locs = DB::query('SELECT code, did FROM locations WHERE is_active = 1', []);
        foreach ($locs as $loc) {
            $locDid = preg_replace('/\D/', '', $loc['did'] ?? '');
            if ($locDid && ($locDid === $did || str_ends_with($locDid, $did) || str_ends_with($did, $locDid))) {
                return $loc['code'];
            }
        }

        // Fallback to config constants
        if (defined('VOIPMS_DID_OS') && ($did === VOIPMS_DID_OS || str_ends_with(VOIPMS_DID_OS, $did))) return 'OS';
        if (defined('VOIPMS_DID_PF') && ($did === VOIPMS_DID_PF || str_ends_with(VOIPMS_DID_PF, $did))) return 'PF';
        return null;
    }
}
