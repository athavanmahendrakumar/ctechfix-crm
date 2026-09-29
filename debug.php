<?php
// ============================================================
// C Tech Fix — Full Diagnostic Tool
// DROP THIS BLOCK at the top of any file to debug a 500 error
// REMOVE IT when done — never leave on production
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Catch fatal errors that display_errors misses
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div style="background:#1e1e1e;color:#f55;font-family:monospace;padding:1rem;margin:1rem;border-radius:8px;border:2px solid #f55;">';
        echo '<strong>💀 FATAL ERROR</strong><br>';
        echo '<strong>Type:</strong> '    . $err['type']    . '<br>';
        echo '<strong>Message:</strong> ' . htmlspecialchars($err['message']) . '<br>';
        echo '<strong>File:</strong> '    . htmlspecialchars($err['file'])    . '<br>';
        echo '<strong>Line:</strong> '    . $err['line']    . '<br>';
        echo '</div>';
    }
});

// Catch exceptions that bubble up uncaught
set_exception_handler(function (Throwable $e) {
    echo '<div style="background:#1e1e1e;color:#fb0;font-family:monospace;padding:1rem;margin:1rem;border-radius:8px;border:2px solid #fb0;">';
    echo '<strong>🔥 UNCAUGHT EXCEPTION: ' . htmlspecialchars(get_class($e)) . '</strong><br>';
    echo '<strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '<br>';
    echo '<strong>File:</strong> '    . htmlspecialchars($e->getFile())    . '<br>';
    echo '<strong>Line:</strong> '    . $e->getLine() . '<br>';
    echo '<strong>Trace:</strong><pre style="color:#ccc;font-size:12px;margin-top:.5rem;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
});

// ── Environment snapshot ─────────────────────────────────────
$diagnostics = function () {
    echo '<div style="background:#0d1117;color:#c9d1d9;font-family:monospace;font-size:13px;padding:1rem;margin:1rem;border-radius:8px;border:1px solid #30363d;">';
    echo '<strong style="color:#58a6ff;font-size:15px;">🩺 C Tech Fix Diagnostics</strong><br><br>';

    // PHP
    echo '<strong style="color:#79c0ff;">PHP</strong><br>';
    echo '  Version: '      . PHP_VERSION . '<br>';
    echo '  SAPI: '         . php_sapi_name() . '<br>';
    echo '  Max memory: '   . ini_get('memory_limit') . '<br>';
    echo '  Max exec time: '. ini_get('max_execution_time') . 's<br>';
    echo '  Upload max: '   . ini_get('upload_max_filesize') . '<br>';
    echo '  Post max: '     . ini_get('post_max_size') . '<br><br>';

    // Request
    echo '<strong style="color:#79c0ff;">Request</strong><br>';
    echo '  Method: '  . ($_SERVER['REQUEST_METHOD'] ?? '?') . '<br>';
    echo '  URI: '     . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '?') . '<br>';
    echo '  Script: '  . htmlspecialchars($_SERVER['SCRIPT_FILENAME'] ?? '?') . '<br>';
    echo '  REMOTE_ADDR: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . '<br><br>';

    // GET / POST
    if (!empty($_GET)) {
        echo '<strong style="color:#79c0ff;">$_GET</strong><br>';
        foreach ($_GET as $k => $v) echo '  ' . htmlspecialchars($k) . ' = ' . htmlspecialchars((string)$v) . '<br>';
        echo '<br>';
    }
    if (!empty($_POST)) {
        echo '<strong style="color:#79c0ff;">$_POST</strong><br>';
        $skip = ['password','token','secret'];
        foreach ($_POST as $k => $v) {
            $display = in_array(strtolower($k), $skip) ? '***hidden***' : (string)$v;
            echo '  ' . htmlspecialchars($k) . ' = ' . htmlspecialchars($display) . '<br>';
        }
        echo '<br>';
    }

    // Session
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {
        echo '<strong style="color:#79c0ff;">$_SESSION</strong><br>';
        foreach ($_SESSION as $k => $v) {
            if (in_array(strtolower($k), ['password','token','secret'])) continue;
            echo '  ' . htmlspecialchars($k) . ' = ' . htmlspecialchars(json_encode($v)) . '<br>';
        }
        echo '<br>';
    }

    // File paths
    echo '<strong style="color:#79c0ff;">Paths</strong><br>';
    echo '  __FILE__: '     . htmlspecialchars(__FILE__)    . '<br>';
    echo '  __DIR__: '      . htmlspecialchars(__DIR__)     . '<br>';
    echo '  DOCUMENT_ROOT: '. htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '?') . '<br><br>';

    // DB test (if DB class is available)
    if (class_exists('DB')) {
        echo '<strong style="color:#79c0ff;">Database</strong><br>';
        try {
            $row = DB::queryOne('SELECT VERSION() AS v, NOW() AS t', []);
            echo '  ✅ Connected — MySQL ' . htmlspecialchars($row['v']) . ' · Server time: ' . $row['t'] . '<br>';
        } catch (\Throwable $e) {
            echo '  ❌ Connection failed: ' . htmlspecialchars($e->getMessage()) . '<br>';
        }
        echo '<br>';
    }

    // Loaded extensions
    $want = ['pdo','pdo_mysql','mbstring','json','curl','openssl','gd','zip'];
    echo '<strong style="color:#79c0ff;">Extensions</strong><br>';
    foreach ($want as $ext) {
        $ok = extension_loaded($ext);
        echo '  ' . ($ok ? '✅' : '❌') . ' ' . $ext . '<br>';
    }

    echo '<br><span style="color:#6e7681;font-size:11px;">Remove diagnostic block before deploying to production.</span>';
    echo '</div>';
};

$diagnostics();
// ── END DIAGNOSTIC BLOCK — remove from here up when done ────
