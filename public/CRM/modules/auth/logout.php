<?php
require_once dirname(__DIR__, 4) . '/core/Auth.php';
require_once dirname(__DIR__, 4) . '/core/helpers.php';
Auth::boot();
// Redirect to checkout (till close) before fully logging out
redirect(APP_URL . '/modules/auth/checkout.php');
