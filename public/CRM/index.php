<?php
// Entry point — redirects to login or correct dashboard
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::boot();

if (Auth::check()) {
    $role = $_SESSION['role'] ?? 'staff';
    redirect(APP_URL . '/modules/dashboard/' . $role . '.php');
} else {
    redirect(APP_URL . '/modules/auth/login.php');
}
