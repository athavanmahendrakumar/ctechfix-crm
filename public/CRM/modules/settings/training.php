<?php
// Toggle training mode for own account (Owner/Manager only can also set for staff)
require_once dirname(__DIR__, 4) . '/core/Auth.php';
Auth::boot();
Auth::requireRole('owner', 'manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enable = isset($_POST['enable']) ? 1 : 0;

    // Toggle current user's session training flag
    $_SESSION['is_training'] = (bool) $enable;

    // Persist to DB
    DB::execute(
        'UPDATE users SET is_training = ? WHERE id = ?',
        [$enable, $_SESSION['user_id']]
    );

    Audit::log(
        $enable ? 'training.enabled' : 'training.disabled',
        'settings',
        $_SESSION['user_id']
    );

    $msg = $enable ? 'Training mode ON — no real SMS or live data.' : 'Training mode OFF — now in live mode.';
    redirect(APP_URL . '/modules/settings/index.php?msg=' . urlencode($msg));
}

redirect(APP_URL . '/modules/settings/index.php');
