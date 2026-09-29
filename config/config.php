<?php
// ============================================================
// C Tech Fix CRM — Configuration File
// IMPORTANT: This file lives OUTSIDE public_html. Never upload
// it to the public folder.
// ============================================================

// DATABASE
define('DB_HOST',    'localhost');
define('DB_NAME',    'ctrecfkn_ctechfix_crm');
define('DB_USER',    'ctrecfkn_ctechfix_user');
define('DB_PASS',    'g)E+Y4J[£44}2:_NubiQ');
define('DB_CHARSET', 'utf8mb4');

// APPLICATION
define('APP_NAME',     'C Tech Fix CRM');
define('APP_URL',      'https://ctrepair.ca/CRM');
define('APP_TIMEZONE', 'America/Toronto');
define('APP_ENV',      'production');

// SECURITY
define('SESSION_TIMEOUT_HOURS', 8);
define('APP_SECRET_KEY', 'Xk9mP2qL7nT4vR8wJ3hY6uA1cF5eBdZ0sG2iN4oK7pM9rW3tQ6yU8xL1vH5nJ2');

// VOIP.MS
// API endpoint — unlikely to change, kept here
define('VOIPMS_API_URL', 'https://voip.ms/api/v1/rest.php');

// Credentials and DIDs are now stored in the database (settings + locations tables).
// These constants act as a fallback ONLY if the DB rows don't exist yet.
// Manage them via Settings → Integrations in the ERP UI.
define('VOIPMS_API_USERNAME', 'athavan.mahen@hotmail.com');
define('VOIPMS_API_PASSWORD', '-kH.a98d1azfScY4]umd1{%YR&hN7wiQq_2TLZ81FQb');
define('VOIPMS_DID_OS', '9052332596');   // Oshawa  — fallback only
define('VOIPMS_DID_PF', '9057520343');   // Pickering — fallback only

// PATHS
define('ROOT_PATH',   dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('CORE_PATH',   ROOT_PATH . '/core');
define('PUBLIC_PATH', ROOT_PATH . '/public_html/CRM');
define('APP_ROOT',    PUBLIC_PATH);   // Used by layout includes
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// CRON
define('CRON_SECRET', 'cTf-cr0n-7x9Qm2pL4nR8wK1');  // Used to authenticate URL-based cron calls

// SYSTEM
date_default_timezone_set(APP_TIMEZONE);
error_reporting(APP_ENV === 'development' ? E_ALL : 0);
ini_set('display_errors', APP_ENV === 'development' ? 1 : 0);
