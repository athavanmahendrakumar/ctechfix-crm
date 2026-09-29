<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Security token check
$allowedToken = 'CTECHFIX_INSTALL_2026';
if (($_GET['token'] ?? '') !== $allowedToken) {
    http_response_code(403);
    die('Access denied. Add ?token=CTECHFIX_INSTALL_2026 to the URL.');
}

$steps  = [];
$errors = [];

// Find config — 2 levels up from public_html/CRM/
$rootPath = dirname(__DIR__, 2);  // = /home/ctrecfkn
$configFile = $rootPath . '/config/config.php';
$coreDB     = $rootPath . '/core/DB.php';

// Step 1: Check files exist
if (!file_exists($configFile)) {
    $errors[] = "❌ Cannot find config.php at: {$configFile}";
} else {
    $steps[] = "✅ Found config.php at: {$configFile}";
}

if (!file_exists($coreDB)) {
    $errors[] = "❌ Cannot find DB.php at: {$coreDB}";
} else {
    $steps[] = "✅ Found DB.php at: {$coreDB}";
}

if (!empty($errors)) {
    showPage($steps, $errors);
    exit;
}

// Step 2: Load config and DB
require_once $configFile;
require_once $coreDB;

// Step 3: Test DB connection
try {
    DB::get();
    $steps[] = '✅ Database connection successful.';
} catch (Exception $e) {
    $errors[] = '❌ Database connection failed: ' . $e->getMessage();
    showPage($steps, $errors);
    exit;
}

// Step 4: Check tables exist
$requiredTables = [
    'locations', 'roles', 'users', 'user_sessions',
    'login_attempts', 'audit_log', 'record_sequences',
    'settings', 'store_hours'
];

$allTablesFound = true;
foreach ($requiredTables as $table) {
    $result = DB::queryOne("SHOW TABLES LIKE ?", [$table]);
    if ($result) {
        $steps[] = "✅ Table '{$table}' found.";
    } else {
        $errors[] = "❌ Table '{$table}' missing — did the SQL import succeed?";
        $allTablesFound = false;
    }
}

// Step 5: Set owner password
if ($allTablesFound) {
    try {
        $hash = password_hash('324973130Aa@', PASSWORD_BCRYPT, ['cost' => 12]);
        $affected = DB::execute(
            'UPDATE users SET password_hash = ? WHERE username = ?',
            [$hash, 'athavan']
        );
        if ($affected > 0) {
            $steps[] = '✅ Owner password set successfully.';
        } else {
            $errors[] = '❌ Owner user not found — check the users table has the athavan account.';
        }
    } catch (Exception $e) {
        $errors[] = '❌ Failed to set password: ' . $e->getMessage();
    }
}

showPage($steps, $errors);

function showPage(array $steps, array $errors): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Install — C Tech Fix CRM</title>
  <style>
    body { font-family: sans-serif; max-width: 650px; margin: 40px auto; padding: 20px; background: #0d1117; color: #eee; }
    h1   { color: #4fc3f7; margin-bottom: 24px; }
    .step { padding: 7px 0; font-size: 15px; }
    .box { padding: 16px 20px; border-radius: 8px; margin-top: 24px; font-size: 15px; }
    .box.error { background: rgba(239,68,68,0.1); border: 1px solid #ef4444; }
    .box.success { background: rgba(16,185,129,0.1); border: 1px solid #10b981; }
    ol { margin: 10px 0 0 20px; line-height: 2; }
    a  { color: #4fc3f7; }
    code { background: #1f2937; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  </style>
</head>
<body>
  <h1>🔧 C Tech Fix CRM — Install</h1>

  <?php foreach ($steps as $step): ?>
    <div class="step"><?= $step ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $err): ?>
    <div class="step"><?= $err ?></div>
  <?php endforeach; ?>

  <?php if (empty($errors)): ?>
    <div class="box success">
      <strong>✅ Installation complete!</strong>
      <ol>
        <li>Delete <code>install.php</code> and <code>diag.php</code> from your server now.</li>
        <li>Go to <a href="https://ctrepair.ca/CRM">https://ctrepair.ca/CRM</a></li>
        <li>Username: <strong>athavan</strong></li>
        <li>Password: your set password</li>
        <li>Change your password after first login.</li>
      </ol>
    </div>
  <?php else: ?>
    <div class="box error">
      <strong>❌ Installation has errors.</strong> Fix the issues above and refresh.
    </div>
  <?php endif; ?>
</body>
</html>
<?php
}
