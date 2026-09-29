<?php
// ============================================================
// customers/search.php — AJAX customer name/phone lookup
// Used by: maintenance/add.php, bookings, etc.
// Returns JSON array of matching customers
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';

Auth::boot();
Auth::require();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$like = '%' . $q . '%';

$results = DB::query(
    "SELECT id,
            CONCAT(first_name, ' ', COALESCE(last_name,'')) AS full_name,
            first_name, last_name,
            phone_primary AS phone,
            email
     FROM customers
     WHERE is_active = 1
       AND (
           CONCAT(first_name, ' ', COALESCE(last_name,'')) LIKE ?
           OR phone_primary LIKE ?
           OR phone_normalized LIKE ?
           OR email LIKE ?
       )
     ORDER BY first_name, last_name
     LIMIT 10",
    [$like, $like, $like, $like]
);

echo json_encode($results ?: []);
exit;
