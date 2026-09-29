<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
echo "=== C Tech Fix CRM Diagnostics ===\n\n";

// Current file location
echo "This file is at:\n  " . __FILE__ . "\n\n";

// Where PHP thinks home is
echo "dirname(__DIR__, 1) = " . dirname(__DIR__, 1) . "\n";
echo "dirname(__DIR__, 2) = " . dirname(__DIR__, 2) . "\n";
echo "dirname(__DIR__, 3) = " . dirname(__DIR__, 3) . "\n\n";

// Check if config exists at expected paths
$paths = [
    dirname(__DIR__, 1) . '/config/config.php',
    dirname(__DIR__, 2) . '/config/config.php',
    dirname(__DIR__, 3) . '/config/config.php',
    '/home/ctrecfkn/config/config.php',
];

echo "Looking for config.php:\n";
foreach ($paths as $path) {
    echo "  " . $path . " → " . (file_exists($path) ? "✅ FOUND" : "❌ not found") . "\n";
}

echo "\nLooking for core/DB.php:\n";
$corePaths = [
    dirname(__DIR__, 1) . '/core/DB.php',
    dirname(__DIR__, 2) . '/core/DB.php',
    dirname(__DIR__, 3) . '/core/DB.php',
    '/home/ctrecfkn/core/DB.php',
];
foreach ($corePaths as $path) {
    echo "  " . $path . " → " . (file_exists($path) ? "✅ FOUND" : "❌ not found") . "\n";
}

echo "\nPHP Version: " . phpversion() . "\n";
echo "</pre>";
