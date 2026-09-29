<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
$user = Auth::user();

echo "<p>User: "; print_r($user); echo "</p>";

// Test query 1 — techs
try {
    $techs = DB::query(
        "SELECT u.id, u.first_name, u.last_name, u.location_id
         FROM users u
         JOIN roles r ON u.role_id = r.id
         WHERE u.is_active = 1
           AND r.name IN ('staff','manager','owner')
         ORDER BY u.first_name",
        []
    );
    echo "<p style='color:green'>✓ Techs query OK — " . count($techs) . " rows</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Techs query: " . $e->getMessage() . "</p>";
}

// Test query 2 — locations
try {
    $locations = DB::query('SELECT * FROM locations WHERE is_active = 1 ORDER BY name', []);
    echo "<p style='color:green'>✓ Locations query OK — " . count($locations) . " rows</p>";
    echo "<p>Location data: "; print_r($locations); echo "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Locations query: " . $e->getMessage() . "</p>";
}

// Test header include
echo "<p>Attempting to load header...</p>";
try {
    $pageTitle = 'Debug Test';
    require_once APP_ROOT . '/modules/layout/header.php';
    echo "<p style='color:green'>✓ Header loaded OK</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Header: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "</p>";
}
