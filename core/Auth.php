<?php
// ============================================================
// Auth — Login, Sessions, Permissions
// ============================================================
class Auth {

    // ----------------------------------------------------------
    // Boot: load config and core, start session
    // ----------------------------------------------------------
    public static function boot(): void {
        require_once dirname(__DIR__) . '/config/config.php';
        require_once CORE_PATH . '/DB.php';
        require_once CORE_PATH . '/Audit.php';
        require_once CORE_PATH . '/helpers.php';

        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure',   1);
            ini_set('session.use_strict_mode', 1);
            session_start();
        }
    }

    // ----------------------------------------------------------
    // Attempt login — returns true on success, error string on fail
    // ----------------------------------------------------------
    public static function attempt(string $username, string $password): bool|string {
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $user = DB::queryOne(
            'SELECT u.*, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.username = ? AND u.is_active = 1
              LIMIT 1',
            [$username]
        );

        $success = $user && password_verify($password, $user['password_hash']);

        // Log every attempt regardless of outcome
        DB::execute(
            'INSERT INTO login_attempts (username, ip_address, user_agent, success) VALUES (?,?,?,?)',
            [$username, $ip, $userAgent, $success ? 1 : 0]
        );

        if (!$success) {
            return 'Incorrect username or password.';
        }

        // Update last login
        DB::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

        // Store in PHP session
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['first_name']    = $user['first_name'];
        $_SESSION['role']          = $user['role_name'];
        $_SESSION['is_training']   = (bool) $user['is_training'];
        $_SESSION['location_id']   = $user['primary_location_id'];
        $_SESSION['last_active']   = time();

        // Persist session token in DB for audit
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (SESSION_TIMEOUT_HOURS * 3600));
        DB::execute(
            'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, is_training, expires_at)
             VALUES (?,?,?,?,?,?)',
            [$user['id'], $token, $ip, $userAgent, $user['is_training'], $expires]
        );
        $_SESSION['session_token'] = $token;

        Audit::log('user.login', 'auth', $user['id']);
        return true;
    }

    // ----------------------------------------------------------
    // Check if user is logged in and session not expired
    // ----------------------------------------------------------
    public static function check(): bool {
        if (empty($_SESSION['user_id']) || empty($_SESSION['last_active'])) {
            return false;
        }

        $timeout = SESSION_TIMEOUT_HOURS * 3600;
        if ((time() - $_SESSION['last_active']) > $timeout) {
            self::logout();
            return false;
        }

        $_SESSION['last_active'] = time();
        return true;
    }

    // ----------------------------------------------------------
    // Require login — redirect to login page if not authenticated
    // ----------------------------------------------------------
    public static function require(): void {
        if (!self::check()) {
            header('Location: ' . APP_URL . '/modules/auth/login.php?reason=session');
            exit;
        }
    }

    // ----------------------------------------------------------
    // Require daily check-in — redirect to checkin if not done today
    // Call after Auth::require() in any module that needs location context
    // ----------------------------------------------------------
    public static function requireCheckin(): void {
        self::require();
        // Managers don't do manual check-in — auto-set from their primary location
        if (self::isManager()) {
            if (empty($_SESSION['working_location_id'])) {
                $_SESSION['working_location_id'] = intval($_SESSION['location_id'] ?? 0);
                $_SESSION['checkin_date']        = date('Y-m-d');
            }
            return;
        }
        $today = date('Y-m-d');
        if (
            empty($_SESSION['working_location_id']) ||
            ($_SESSION['checkin_date'] ?? '') !== $today
        ) {
            header('Location: ' . APP_URL . '/modules/auth/checkin.php');
            exit;
        }
    }

    // ----------------------------------------------------------
    // Get the active working location for the session
    // Falls back to user's primary_location_id if no checkin
    // ----------------------------------------------------------
    public static function workingLocationId(): int {
        return intval($_SESSION['working_location_id'] ?? self::user()['location_id'] ?? 0);
    }

    // ----------------------------------------------------------
    // Require a specific role — redirect with error if insufficient
    // ----------------------------------------------------------
    public static function requireRole(string ...$roles): void {
        self::require();
        if (!in_array($_SESSION['role'], $roles, true)) {
            http_response_code(403);
            include PUBLIC_PATH . '/modules/auth/403.php';
            exit;
        }
    }

    // ----------------------------------------------------------
    // Logout
    // ----------------------------------------------------------
    public static function logout(): void {
        if (!empty($_SESSION['session_token'])) {
            DB::execute(
                'UPDATE user_sessions SET is_revoked = 1 WHERE session_token = ?',
                [$_SESSION['session_token']]
            );
        }
        if (!empty($_SESSION['user_id'])) {
            Audit::log('user.logout', 'auth', $_SESSION['user_id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ----------------------------------------------------------
    // Get current user info
    // ----------------------------------------------------------
    public static function user(): array {
        return [
            'id'          => $_SESSION['user_id']     ?? null,
            'username'    => $_SESSION['username']    ?? '',
            'first_name'  => $_SESSION['first_name']  ?? '',
            'role'        => $_SESSION['role']         ?? '',
            'location_id' => $_SESSION['location_id'] ?? null,
            'is_training' => $_SESSION['is_training'] ?? false,
        ];
    }

    // ----------------------------------------------------------
    // Permission helpers
    // ----------------------------------------------------------
    public static function isOwner():   bool { return ($_SESSION['role'] ?? '') === 'owner'; }
    public static function isManager(): bool { return ($_SESSION['role'] ?? '') === 'manager'; }
    public static function isStaff():   bool { return ($_SESSION['role'] ?? '') === 'staff'; }
    public static function isTraining():bool { return !empty($_SESSION['is_training']); }

    // Can the current user see ALL locations? (owner only — managers locked to their location)
    public static function isMultiLocation(): bool {
        return self::isOwner();
    }

    // Can the current user access a given location?
    public static function canAccessLocation(int $locationId): bool {
        if (self::isOwner()) return true;
        return ((int)($_SESSION['location_id'] ?? 0)) === $locationId;
    }

    // Can current user do a specific action?
    public static function can(string $action): bool {
        $role = $_SESSION['role'] ?? '';
        $permissions = [
            'owner'   => ['*'],   // owns everything
            'manager' => [
                'manage_staff', 'approve_discounts', 'approve_inventory',
                'approve_transfers', 'approve_complaints', 'approve_cash',
                'view_audit_operational',
                'create_coupons', 'manage_repairs', 'manage_sales',
                'manage_customers', 'manage_calls', 'manage_activations',
            ],
            'staff' => [
                'view_calls', 'create_calls', 'manage_customers', 'create_leads',
                'manage_repairs', 'view_repairs', 'create_sales', 'view_sales',
                'clock_in', 'view_activations', 'create_activations',
            ],
        ];

        if ($role === 'owner') return true;
        return in_array($action, $permissions[$role] ?? [], true);
    }
}
