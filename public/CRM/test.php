<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';

$steps  = [];
$errors = [];

// Check all tables
$tables = ['locations','roles','users','user_sessions','login_attempts',
           'audit_log','record_sequences','settings','store_hours'];

foreach ($tables as $table) {
    $found = DB::queryOne("SHOW TABLES LIKE ?", [$table]);
    if ($found) {
        $steps[] = "✅ Table '{$table}' exists";
    } else {
        $errors[] = "❌ Table '{$table}' MISSING";
    }
}

// Set owner password
try {
    $hash = password_hash('324973130Aa@', PASSWORD_BCRYPT, ['cost' => 10]);
    $rows = DB::execute('UPDATE users SET password_hash = ? WHERE username = ?', [$hash, 'athavan']);
    if ($rows > 0) {
        $steps[] = "✅ Owner password set successfully";
    } else {
        $errors[] = "❌ Owner user 'athavan' not found in users table";
    }
} catch (Exception $e) {
    $errors[] = "❌ Password error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>C Tech Fix — Setup</title>
  <style>
    body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #0d1117; color: #eee; }
    h1 { color: #4fc3f7; }
    .step { padding: 6px 0; font-size: 15px; }
    .box { padding: 16px 20px; border-radius: 8px; margin-top: 20px; }
    .success { background: rgba(16,185,129,0.1); border: 1px solid #10b981; }
    .error   { background: rgba(239,68,68,0.1);  border: 1px solid #ef4444; }
    a { color: #4fc3f7; }
    ol { margin: 10px 0 0 20px; line-height: 2.2; }
  </style>
</head>
<body>
  <h1>🔧 C Tech Fix CRM — Setup</h1>

  <?php foreach ($steps  as $s): ?><div class="step"><?= $s ?></div><?php endforeach; ?>
  <?php foreach ($errors as $e): ?><div class="step"><?= $e ?></div><?php endforeach; ?>

  <?php if (empty($errors)): ?>
    <div class="box success">
      <strong>✅ Setup complete!</strong>
      <ol>
        <li>Delete <strong>test.php</strong> and <strong>diag.php</strong> from your server</li>
        <li>Go to <a href="https://ctrepair.ca/CRM">https://ctrepair.ca/CRM</a></li>
        <li>Username: <strong>athavan</strong></li>
        <li>Password: <strong>324973130Aa@</strong></li>
      </ol>
    </div>
  <?php else: ?>
    <div class="box error">
      <strong>❌ Errors found.</strong> See above.
    </div>
  <?php endif; ?>
</body>
</html>
