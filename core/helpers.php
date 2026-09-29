<?php
// ============================================================
// Shared Helper Functions
// ============================================================

// Safely output text to HTML (prevents XSS attacks)
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Redirect to a URL
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// Format a phone number for display: 9052332596 → (905) 233-2596
function formatPhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);

    // Strip leading 1 if 11 digits
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    // If still too long, try to extract last 10 digits
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    if (strlen($digits) === 10) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }

    // Fallback — return as-is if we can't parse it
    return $phone;
}

// Normalize phone to 10 digits for DB storage
function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);

    // Strip leading 1 if 11 digits
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    // If still too long, take last 10 digits
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

// Format a dollar amount
function formatMoney(float $amount): string {
    return '$' . number_format($amount, 2);
}

// Is the store currently open? (checks store_hours table)
function isStoreOpen(int $locationId): bool {
    $now     = new DateTime('now', new DateTimeZone(APP_TIMEZONE));
    $dayOfWeek = (int) $now->format('w');  // 0=Sun, 6=Sat
    $currentTime = $now->format('H:i:s');

    $hours = DB::queryOne(
        'SELECT * FROM store_hours WHERE location_id = ? AND day_of_week = ? LIMIT 1',
        [$locationId, $dayOfWeek]
    );

    if (!$hours || $hours['is_closed']) return false;
    return ($currentTime >= $hours['open_time'] && $currentTime <= $hours['close_time']);
}

// Get setting value from DB
function getSetting(string $key, string $default = ''): string {
    $row = DB::queryOne('SELECT value FROM settings WHERE `key` = ? LIMIT 1', [$key]);
    return $row ? ($row['value'] ?? $default) : $default;
}

// Training mode badge for UI
function trainingBadge(): string {
    if (!empty($_SESSION['is_training'])) {
        return '<span class="training-badge">TRAINING MODE</span>';
    }
    return '';
}

// Time ago (e.g. "2 hours ago")
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
