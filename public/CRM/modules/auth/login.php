<?php
require_once dirname(__DIR__, 4) . '/core/Auth.php';
Auth::boot();

// Already logged in? Go to dashboard
if (Auth::check()) {
    redirect(APP_URL . '/modules/dashboard/' . $_SESSION['role'] . '.php');
}

$error  = '';
$reason = $_GET['reason'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $result = Auth::attempt($username, $password);
        if ($result === true) {
            // Managers get their location auto-set from their profile — skip check-in screen
            if (Auth::isManager()) {
                $locId = intval($_SESSION['location_id'] ?? 0);
                $_SESSION['working_location_id'] = $locId;
                $_SESSION['checkin_date']        = date('Y-m-d');
                redirect(APP_URL . '/modules/dashboard/manager.php');
            }
            redirect(APP_URL . '/modules/auth/checkin.php');
        } else {
            $error = $result;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — C Tech Fix CRM</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body class="login-page">

<div class="login-container">
  <div class="login-card">

    <div class="login-logo">
      <span class="logo-icon">🔧</span>
      <h1>C Tech Fix</h1>
      <p>CRM &amp; Operations</p>
    </div>

    <?php if ($reason === 'session'): ?>
      <div class="alert alert-warning">Your session expired. Please log in again.</div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="login-form" autocomplete="off">
      <div class="form-group">
        <label for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          class="form-input"
          value="<?= e($_POST['username'] ?? '') ?>"
          placeholder="Enter your username"
          autocomplete="username"
          autofocus
          required
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-with-toggle">
          <input
            type="password"
            id="password"
            name="password"
            class="form-input"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
          <button type="button" class="toggle-password" onclick="togglePassword()">👁</button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-full">Sign In</button>
    </form>

    <p class="login-footer">C Tech Fix — Oshawa &amp; Pickering</p>
  </div>
</div>

<script>
function togglePassword() {
  const p = document.getElementById('password');
  p.type = p.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
