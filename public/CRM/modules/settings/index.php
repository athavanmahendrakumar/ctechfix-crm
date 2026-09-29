<?php
// ============================================================
// Settings — Global + Per-Location + Integrations
// Owner only
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$user      = Auth::user();
$errors    = [];
$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

// Helper: load settings for a given location_id (NULL = global)
function loadSettings(?int $locId): array {
    $rows = DB::query(
        'SELECT setting_key, value FROM settings WHERE location_id ' . ($locId === null ? 'IS NULL' : '= ?'),
        $locId === null ? [] : [$locId]
    );
    $s = [];
    foreach ($rows as $r) $s[$r['setting_key']] = $r['value'];
    return $s;
}

// Helper: upsert a setting
function saveSetting(string $key, string $value, ?int $locId, int $userId): void {
    DB::execute(
        'INSERT INTO settings (setting_key, location_id, value, updated_by) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE value=?, updated_by=?',
        [$key, $locId, $value, $userId, $value, $userId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $locId  = isset($_POST['location_id']) && $_POST['location_id'] !== ''
              ? intval($_POST['location_id'])
              : null;

    // ── Save global info ────────────────────────────────────
    if ($action === 'save_global') {
        foreach (['business_name','business_email','tax_rate','hst_number'] as $key) {
            saveSetting($key, trim($_POST[$key] ?? ''), null, $user['id']);
        }
        header('Location: ' . APP_URL . '/modules/settings/?msg=saved&tab=global'); exit;
    }

    // ── Save per-location info ──────────────────────────────
    if ($action === 'save_location' && $locId) {
        foreach (['location_phone','location_address'] as $key) {
            saveSetting($key, trim($_POST[$key] ?? ''), $locId, $user['id']);
        }
        // Booking hours
        $openTime  = trim($_POST['store_open_time']       ?? '11:00');
        $closeTime = trim($_POST['store_close_time']      ?? '21:30');
        $buffer    = intval($_POST['store_booking_buffer'] ?? 30);
        $openDays  = isset($_POST['store_open_days'])
                     ? implode(',', array_map('intval', (array)$_POST['store_open_days']))
                     : '';
        saveSetting('store_open_time',      $openTime,        $locId, $user['id']);
        saveSetting('store_close_time',     $closeTime,       $locId, $user['id']);
        saveSetting('store_booking_buffer', (string)$buffer,  $locId, $user['id']);
        saveSetting('store_open_days',      $openDays,        $locId, $user['id']);
        header('Location: ' . APP_URL . '/modules/settings/?msg=saved&tab=loc_' . $locId); exit;
    }

    // ── Upload logo ─────────────────────────────────────────
    if ($action === 'upload_logo') {
        if (!empty($_FILES['logo']['tmp_name'])) {
            $file    = $_FILES['logo'];
            $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
            $maxBytes = 2 * 1024 * 1024;

            if (!in_array($file['type'], $allowed)) {
                $errors[] = 'Logo must be JPG, PNG, GIF, WEBP, or SVG.';
            } elseif ($file['size'] > $maxBytes) {
                $errors[] = 'Logo must be under 2 MB.';
            } else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $prefix   = $locId ? 'logo_loc' . $locId . '_' : 'logo_global_';
                $filename = $prefix . time() . '.' . $ext;
                $dest     = APP_ROOT . '/uploads/logos/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $existing = loadSettings($locId);
                    $oldPath  = $existing['logo_path'] ?? '';
                    if ($oldPath && file_exists(APP_ROOT . '/uploads/logos/' . basename($oldPath))) {
                        @unlink(APP_ROOT . '/uploads/logos/' . basename($oldPath));
                    }
                    saveSetting('logo_path', $filename, $locId, $user['id']);
                    $tab = $locId ? 'loc_' . $locId : 'global';
                    header('Location: ' . APP_URL . '/modules/settings/?msg=logo_saved&tab=' . $tab); exit;
                } else {
                    $errors[] = 'Upload failed — check folder permissions on /uploads/logos/';
                }
            }
        } else {
            $errors[] = 'No file selected.';
        }
    }

    // ── Remove logo ─────────────────────────────────────────
    if ($action === 'remove_logo') {
        $existing = loadSettings($locId);
        $oldPath  = $existing['logo_path'] ?? '';
        if ($oldPath) @unlink(APP_ROOT . '/uploads/logos/' . basename($oldPath));
        saveSetting('logo_path', '', $locId, $user['id']);
        $tab = $locId ? 'loc_' . $locId : 'global';
        header('Location: ' . APP_URL . '/modules/settings/?msg=logo_removed&tab=' . $tab); exit;
    }

    // ── Save VoipMS credentials ─────────────────────────────
    if ($action === 'save_voipms') {
        $uname = trim($_POST['voipms_username'] ?? '');
        $upass = trim($_POST['voipms_password'] ?? '');
        if ($uname !== '') saveSetting('voipms_api_username', $uname, null, $user['id']);
        if ($upass !== '') saveSetting('voipms_api_password', $upass, null, $user['id']);
        header('Location: ' . APP_URL . '/modules/settings/?msg=voipms_saved&tab=integrations'); exit;
    }

    // ── Save per-location DIDs ──────────────────────────────
    if ($action === 'save_dids') {
        foreach ($locations as $loc) {
            $did = trim($_POST['did_' . $loc['id']] ?? '');
            if ($did !== '') {
                DB::execute('UPDATE locations SET did = ? WHERE id = ?', [$did, $loc['id']]);
            }
        }
        header('Location: ' . APP_URL . '/modules/settings/?msg=dids_saved&tab=integrations'); exit;
    }

    // ── Test VoipMS connection ──────────────────────────────
    if ($action === 'test_voipms') {
        $result = VoipMS::testConnection();
        $msg    = $result === true ? 'voipms_ok' : 'voipms_fail';
        header('Location: ' . APP_URL . '/modules/settings/?msg=' . $msg . '&tab=integrations'); exit;
    }

    // ── Cash account actions ────────────────────────────────────
    if ($action === 'add_cash_account') {
    $name   = trim($_POST['name'] ?? '');
    $type   = in_array($_POST['type'] ?? '', ['hub','card','bank','other']) ? $_POST['type'] : 'other';
    $locId  = intval($_POST['location_id'] ?? 0) ?: null;
    $openBal = floatval($_POST['opening_balance'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if ($name) {
        $maxOrder = DB::queryOne('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM cash_accounts', [])['n'] ?? 1;
        $acctId = DB::insert(
            'INSERT INTO cash_accounts (name, type, location_id, opening_balance, notes, sort_order, created_by) VALUES (?,?,?,?,?,?,?)',
            [$name, $type, $locId, $openBal, $notes ?: null, $maxOrder, $user['id']]
        );
        // Record opening balance movement if > 0
        if ($openBal > 0) {
            DB::execute(
                'INSERT INTO cash_movements (to_account_id, amount, movement_type, notes, moved_at, created_by)
                 VALUES (?,?,?,?,NOW(),?)',
                [$acctId, $openBal, 'opening', 'Opening balance set on launch', $user['id']]
            );
        }
    }
    header('Location: ' . APP_URL . '/modules/settings/?msg=acct_saved&tab=cash'); exit;
}

if ($action === 'toggle_cash_account') {
    $acctId = intval($_POST['account_id'] ?? 0);
    DB::execute('UPDATE cash_accounts SET is_active = NOT is_active WHERE id=?', [$acctId]);
    header('Location: ' . APP_URL . '/modules/settings/?msg=acct_saved&tab=cash'); exit;
}

if ($action === 'set_opening_balance') {
    $acctId  = intval($_POST['account_id'] ?? 0);
    $openBal = floatval($_POST['opening_balance'] ?? 0);
    DB::execute('UPDATE cash_accounts SET opening_balance=? WHERE id=?', [$openBal, $acctId]);
    // Remove old opening movement and re-insert
    DB::execute("DELETE FROM cash_movements WHERE to_account_id=? AND movement_type='opening'", [$acctId]);
    if ($openBal > 0) {
        DB::execute(
            'INSERT INTO cash_movements (to_account_id, amount, movement_type, notes, moved_at, created_by)
             VALUES (?,?,?,?,NOW(),?)',
            [$acctId, $openBal, 'opening', 'Opening balance', $user['id']]
        );
    }
    header('Location: ' . APP_URL . '/modules/settings/?msg=acct_saved&tab=cash'); exit;
}

} // end if POST

$cashAccounts = [];
try {
    $cashAccounts = DB::query(
        'SELECT ca.*, l.name AS loc_name
         FROM cash_accounts ca
         LEFT JOIN locations l ON l.id = ca.location_id
         ORDER BY ca.sort_order, ca.name', []
    );
} catch (\Throwable $e) { /* table may not exist yet */ }

$msgMap = [
    'saved'        => '✓ Settings saved.',
    'logo_saved'   => '✓ Logo uploaded.',
    'logo_removed' => '✓ Logo removed.',
    'voipms_saved' => '✓ VoIP.ms credentials saved.',
    'dids_saved'   => '✓ Location DIDs updated.',
    'voipms_ok'    => '✓ VoIP.ms connection is working!',
    'voipms_fail'  => '✗ VoIP.ms connection failed — check your credentials.',
    'acct_saved'   => '✓ Cash account updated.',
];

// Active tab
$activeTab = $_GET['tab'] ?? 'global';

// Load global settings
$global = loadSettings(null);

// Load per-location settings
$locSettings = [];
foreach ($locations as $loc) {
    $locSettings[$loc['id']] = loadSettings((int)$loc['id']);
}

$pageTitle = 'Settings';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">Global business info, per-location details, and integrations</p>
    </div>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-<?= $_GET['msg'] === 'voipms_fail' ? 'danger' : 'success' ?>">
    <?= $msgMap[$_GET['msg']] ?>
</div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:.25rem;border-bottom:2px solid var(--border);margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="?tab=global"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:<?= $activeTab==='global' ? 'var(--surface)' : 'var(--surface-2)' ?>;
              color:<?= $activeTab==='global' ? 'var(--blue)' : 'var(--text-2)' ?>;">
        🌐 Global
    </a>
    <?php foreach ($locations as $loc): ?>
    <a href="?tab=loc_<?= $loc['id'] ?>"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:<?= $activeTab==='loc_'.$loc['id'] ? 'var(--surface)' : 'var(--surface-2)' ?>;
              color:<?= $activeTab==='loc_'.$loc['id'] ? 'var(--blue)' : 'var(--text-2)' ?>;">
        📍 <?= htmlspecialchars($loc['name']) ?>
    </a>
    <?php endforeach; ?>
    <a href="?tab=integrations"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:<?= $activeTab==='integrations' ? 'var(--surface)' : 'var(--surface-2)' ?>;
              color:<?= $activeTab==='integrations' ? 'var(--blue)' : 'var(--text-2)' ?>;">
        🔌 Integrations
    </a>
    <a href="?tab=cash"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:<?= $activeTab==='cash' ? 'var(--surface)' : 'var(--surface-2)' ?>;
              color:<?= $activeTab==='cash' ? 'var(--blue)' : 'var(--text-2)' ?>;">
        💵 Cash Accounts
    </a>
    <a href="ai_access.php"
       style="padding:.5rem 1.25rem;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;text-decoration:none;
              border:1px solid var(--border);border-bottom:none;
              background:var(--surface-2);color:var(--text-2);">
        🤖 Claude AI Access
    </a>
</div>

<!-- ── GLOBAL TAB ──────────────────────────────────────────── -->
<?php if ($activeTab === 'global'): ?>
<div class="repair-grid">
    <div class="repair-col-main">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Global Business Info</h2></div>
            <div class="card-body">
                <p class="text-muted small" style="margin-bottom:1rem;">Applies across all locations — company name, email, and tax rate.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_global">
                    <div class="form-group">
                        <label class="form-label">Business Name</label>
                        <input type="text" name="business_name" class="form-control"
                               value="<?= htmlspecialchars($global['business_name'] ?? '') ?>"
                               placeholder="C Tech Fix">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="business_email" class="form-control"
                               value="<?= htmlspecialchars($global['business_email'] ?? '') ?>"
                               placeholder="info@ctrepair.ca">
                    </div>
                    <div class="form-group" style="max-width:200px;">
                        <label class="form-label">Tax Rate (%)</label>
                        <input type="number" name="tax_rate" class="form-control" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars($global['tax_rate'] ?? '13') ?>">
                        <div class="form-hint">HST in Ontario = 13%</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">HST Registration #</label>
                        <input type="text" name="hst_number" class="form-control"
                               value="<?= htmlspecialchars($global['hst_number'] ?? '') ?>"
                               placeholder="e.g. 123456789 RT0001">
                        <div class="form-hint">Printed on receipts when HST is charged.</div>
                    </div>
                    <div class="form-actions" style="margin-top:1rem;padding-top:0;border:0;">
                        <button type="submit" class="btn btn-primary">Save Global Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Global Logo -->
    <div class="repair-col-side">
        <?php
        $logoFile = $global['logo_path'] ?? '';
        $logoUrl  = $logoFile ? APP_URL . '/uploads/logos/' . htmlspecialchars($logoFile) : '';
        ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Default Logo</h2></div>
            <div class="card-body">
                <p class="text-muted small" style="margin-bottom:1rem;">Used when no location-specific logo is set.</p>
                <?php if ($logoUrl): ?>
                <div style="text-align:center;margin-bottom:1rem;padding:1rem;background:var(--surface-2);border-radius:8px;border:1px solid var(--border);">
                    <img src="<?= $logoUrl ?>" alt="Logo" style="max-width:100%;max-height:120px;object-fit:contain;">
                </div>
                <form method="POST" style="margin-bottom:1rem;">
                    <input type="hidden" name="action" value="remove_logo">
                    <input type="hidden" name="location_id" value="">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove logo?')">Remove</button>
                </form>
                <?php else: ?>
                <div style="text-align:center;padding:1.5rem;background:var(--surface-2);border-radius:8px;border:2px dashed var(--border);margin-bottom:1rem;color:var(--text-3);">
                    <div style="font-size:2rem;">🖼</div>
                    <div>No logo uploaded</div>
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_logo">
                    <input type="hidden" name="location_id" value="">
                    <div class="form-group">
                        <label class="form-label"><?= $logoUrl ? 'Replace Logo' : 'Upload Logo' ?></label>
                        <input type="file" name="logo" class="form-control" accept="image/*" required>
                        <div class="form-hint">JPG, PNG, SVG — max 2 MB</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── INTEGRATIONS TAB ────────────────────────────────────── -->
<?php elseif ($activeTab === 'integrations'): ?>
<div class="repair-grid">
    <div class="repair-col-main">

        <!-- VoIP.ms Credentials -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">VoIP.ms API Credentials</h2>
            </div>
            <div class="card-body">
                <p class="text-muted small" style="margin-bottom:1rem;">
                    Used to send SMS notifications for repairs and maintenance reminders.
                    Leave the password field blank to keep the current password unchanged.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_voipms">
                    <div class="form-group">
                        <label class="form-label">API Username (email)</label>
                        <input type="email" name="voipms_username" class="form-control"
                               value="<?= htmlspecialchars($global['voipms_api_username'] ?? (defined('VOIPMS_API_USERNAME') ? VOIPMS_API_USERNAME : '')) ?>"
                               placeholder="your@email.com">
                        <div class="form-hint">The email address you log into VoIP.ms with.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">API Password</label>
                        <input type="password" name="voipms_password" class="form-control"
                               placeholder="Leave blank to keep current password"
                               autocomplete="new-password">
                        <div class="form-hint">
                            This is your VoIP.ms <strong>API password</strong> (set separately from your login password
                            at Main Menu → API in the VoIP.ms portal).
                        </div>
                    </div>
                    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:1rem;">
                        <button type="submit" class="btn btn-primary">Save Credentials</button>
                    </div>
                </form>

                <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

                <!-- Test connection -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="test_voipms">
                    <button type="submit" class="btn btn-secondary">🔍 Test Connection</button>
                </form>
                <span class="text-muted small" style="margin-left:.75rem;">
                    Sends a live request to VoIP.ms to verify the credentials work.
                </span>
            </div>
        </div>

        <!-- Per-Location DIDs -->
        <div class="card" style="margin-top:1.25rem;">
            <div class="card-header">
                <h2 class="card-title">Per-Location DID Numbers</h2>
            </div>
            <div class="card-body">
                <p class="text-muted small" style="margin-bottom:1rem;">
                    The VoIP.ms DID (outbound phone number) used when sending SMS for each location.
                    Digits only or with dashes — both are accepted.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_dids">
                    <?php foreach ($locations as $loc): ?>
                    <div class="form-group">
                        <label class="form-label">
                            <?= htmlspecialchars($loc['name']) ?>
                            <span style="font-weight:400;color:var(--text-3);font-size:12px;margin-left:.4rem;">
                                (<?= htmlspecialchars($loc['code']) ?>)
                            </span>
                        </label>
                        <input type="text" name="did_<?= $loc['id'] ?>" class="form-control"
                               style="max-width:240px;font-family:monospace;"
                               value="<?= htmlspecialchars($loc['did'] ?? '') ?>"
                               placeholder="e.g. 9051234567">
                    </div>
                    <?php endforeach; ?>
                    <div class="form-actions" style="margin-top:1rem;padding-top:0;border:0;">
                        <button type="submit" class="btn btn-primary">Save DIDs</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- Side info panel -->
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">How to set up VoIP.ms API</h2></div>
            <div class="card-body">
                <ol style="font-size:13px;line-height:1.9;padding-left:1.1rem;color:var(--text-2);">
                    <li>Log into <strong>voip.ms</strong></li>
                    <li>Go to <strong>Main Menu → API</strong></li>
                    <li>Enable the API and set an <strong>API Password</strong></li>
                    <li>Whitelist your server's IP if prompted</li>
                    <li>Enter the credentials above and click <strong>Save</strong></li>
                    <li>Click <strong>Test Connection</strong> to confirm</li>
                </ol>
                <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
                <p style="font-size:12px;color:var(--text-3);">
                    Credentials are stored in the database and never written to log files.
                    The API password is <em>separate</em> from your VoIP.ms login password.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ── LOCATION TABS ───────────────────────────────────────── -->
<?php else:
    $activeLoc = null;
    foreach ($locations as $loc) {
        if ('loc_' . $loc['id'] === $activeTab) { $activeLoc = $loc; break; }
    }
    if ($activeLoc):
        $ls = $locSettings[$activeLoc['id']];
        $logoFile = $ls['logo_path'] ?? '';
        $logoUrl  = $logoFile ? APP_URL . '/uploads/logos/' . htmlspecialchars($logoFile) : '';
?>
<div class="repair-grid">
    <div class="repair-col-main">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= htmlspecialchars($activeLoc['name']) ?> — Location Info</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_location">
                    <input type="hidden" name="location_id" value="<?= $activeLoc['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="location_phone" class="form-control"
                               value="<?= htmlspecialchars($ls['location_phone'] ?? '') ?>"
                               placeholder="e.g. 905-233-2596">
                        <div class="form-hint">Display phone for receipts and customer-facing pages.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="location_address" class="form-control" rows="3"
                                  placeholder="Full address for this location"><?= htmlspecialchars($ls['location_address'] ?? '') ?></textarea>
                    </div>
                    <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">
                    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;">🕐 Booking Hours</h3>
                    <p class="text-muted small" style="margin-bottom:1rem;">Controls which dates and times appear on the customer booking page.</p>
                    <?php
                    $openDaysSaved = array_map('intval', explode(',', $ls['store_open_days'] ?? '1,2,3,4,5,6'));
                    $dayMap = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
                    ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Opening Time</label>
                            <input type="time" name="store_open_time" class="form-control"
                                   value="<?= htmlspecialchars($ls['store_open_time'] ?? '11:00') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Closing Time</label>
                            <input type="time" name="store_close_time" class="form-control"
                                   value="<?= htmlspecialchars($ls['store_close_time'] ?? '21:30') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Booking Buffer (minutes before close)</label>
                        <input type="number" name="store_booking_buffer" class="form-control" min="0" max="120" style="max-width:120px;"
                               value="<?= intval($ls['store_booking_buffer'] ?? 30) ?>">
                        <div class="form-hint">Last booking slot = closing time minus this buffer.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Open Days</label>
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.3rem;">
                            <?php foreach ($dayMap as $num => $label): ?>
                            <label style="display:flex;align-items:center;gap:.3rem;font-size:13px;cursor:pointer;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:.3rem .6rem;">
                                <input type="checkbox" name="store_open_days[]" value="<?= $num ?>"
                                       <?= in_array($num, $openDaysSaved) ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:1rem;padding-top:0;border:0;">
                        <button type="submit" class="btn btn-primary">Save Location Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Location system info -->
        <div class="card" style="margin-top:1.25rem;">
            <div class="card-header"><h2 class="card-title">VoIP / System Info</h2></div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Location Code</span>
                        <span class="detail-value"><strong><?= htmlspecialchars($activeLoc['code']) ?></strong></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">DID (VoIP.ms)</span>
                        <span class="detail-value" style="font-family:monospace;"><?= htmlspecialchars($activeLoc['did'] ?? '—') ?></span>
                    </div>
                </div>
                <p class="text-muted small" style="margin-top:.75rem;">
                    To update the DID, go to the <a href="?tab=integrations">🔌 Integrations</a> tab.
                </p>
            </div>
        </div>
    </div>

    <!-- Per-location logo -->
    <div class="repair-col-side">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Location Logo</h2></div>
            <div class="card-body">
                <p class="text-muted small" style="margin-bottom:1rem;">Overrides the global logo for this location's repair labels.</p>
                <?php if ($logoUrl): ?>
                <div style="text-align:center;margin-bottom:1rem;padding:1rem;background:var(--surface-2);border-radius:8px;border:1px solid var(--border);">
                    <img src="<?= $logoUrl ?>" alt="Logo" style="max-width:100%;max-height:120px;object-fit:contain;">
                </div>
                <form method="POST" style="margin-bottom:1rem;">
                    <input type="hidden" name="action" value="remove_logo">
                    <input type="hidden" name="location_id" value="<?= $activeLoc['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove logo?')">Remove</button>
                </form>
                <?php else: ?>
                <div style="text-align:center;padding:1.5rem;background:var(--surface-2);border-radius:8px;border:2px dashed var(--border);margin-bottom:1rem;color:var(--text-3);">
                    <div style="font-size:2rem;">🖼</div>
                    <div>No logo for this location</div>
                    <div style="font-size:12px;margin-top:.25rem;">Falls back to global logo</div>
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_logo">
                    <input type="hidden" name="location_id" value="<?= $activeLoc['id'] ?>">
                    <div class="form-group">
                        <label class="form-label"><?= $logoUrl ? 'Replace Logo' : 'Upload Logo' ?></label>
                        <input type="file" name="logo" class="form-control" accept="image/*" required>
                        <div class="form-hint">JPG, PNG, SVG — max 2 MB</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; endif; ?>

<!-- ── CASH ACCOUNTS TAB ───────────────────────────────────── -->
<?php if ($activeTab === 'cash'): ?>
<div class="repair-grid">
<div class="repair-col-main">

    <!-- Existing accounts -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">💵 Cash Accounts</h2>
            <span class="text-muted small">Hub, cards, bank accounts — tills are managed per location</span>
        </div>
        <?php if (empty($cashAccounts)): ?>
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-icon">💵</div>
                <p>No cash accounts yet. Add your Hub, cards, and bank accounts below.</p>
                <p class="text-muted small" style="margin-top:.5rem;">
                    ⚠️ Run the <code>migrations/cash_accounts.sql</code> file first if you haven't already.
                </p>
            </div>
        </div>
        <?php else: ?>
        <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Opening Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $typeLabels = ['till'=>'🏪 Till','hub'=>'🏠 Hub','card'=>'💳 Card','bank'=>'🏦 Bank','other'=>'📋 Other'];
            foreach ($cashAccounts as $acct):
            ?>
            <tr style="<?= !$acct['is_active'] ? 'opacity:.5;' : '' ?>">
                <td style="font-weight:600;">
                    <?= htmlspecialchars($acct['name']) ?>
                    <?php if ($acct['loc_name']): ?>
                    <span class="text-muted small"> · <?= htmlspecialchars($acct['loc_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-secondary"><?= $typeLabels[$acct['type']] ?? $acct['type'] ?></span></td>
                <td>
                    <!-- Inline opening balance edit -->
                    <form method="POST" style="display:flex;gap:.4rem;align-items:center;">
                        <input type="hidden" name="action"     value="set_opening_balance">
                        <input type="hidden" name="account_id" value="<?= $acct['id'] ?>">
                        <div style="position:relative;width:120px;">
                            <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--text-3);">$</span>
                            <input type="number" name="opening_balance" step="0.01" min="0"
                                   class="form-control" style="padding-left:20px;height:32px;font-size:13px;"
                                   value="<?= number_format((float)$acct['opening_balance'], 2) ?>">
                        </div>
                        <button type="submit" class="btn btn-sm btn-secondary" style="height:32px;">Set</button>
                    </form>
                </td>
                <td>
                    <span style="color:<?= $acct['is_active'] ? 'var(--green)' : 'var(--text-3)' ?>;font-weight:600;">
                        <?= $acct['is_active'] ? '● Active' : '○ Inactive' ?>
                    </span>
                </td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action"     value="toggle_cash_account">
                        <input type="hidden" name="account_id" value="<?= $acct['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost">
                            <?= $acct['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add new account -->
    <div class="card" style="margin-top:1.25rem;">
        <div class="card-header"><h2 class="card-title">+ Add Account</h2></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_cash_account">
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Account Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               placeholder="e.g. Hub, BMO Debit Card, Square Cash Card">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type <span class="required">*</span></label>
                        <select name="type" class="form-control">
                            <option value="hub">🏠 Hub</option>
                            <option value="card">💳 Card</option>
                            <option value="bank">🏦 Bank</option>
                            <option value="other">📋 Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Opening Balance ($)</label>
                        <input type="number" name="opening_balance" step="0.01" min="0"
                               class="form-control" placeholder="0.00" value="0.00">
                        <div class="form-hint">Set this to the actual amount on launch day.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location <span class="text-muted small">(tills only)</span></label>
                        <select name="location_id" class="form-control">
                            <option value="">— None —</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes <span class="text-muted small">(optional)</span></label>
                    <input type="text" name="notes" class="form-control"
                           placeholder="e.g. Last 4 digits: 4521">
                </div>
                <button type="submit" class="btn btn-primary">Add Account</button>
            </form>
        </div>
    </div>

</div>

<!-- Side info -->
<div class="repair-col-side">
    <div class="card">
        <div class="card-header"><h2 class="card-title">How accounts work</h2></div>
        <div class="card-body" style="font-size:13px;color:var(--text-2);line-height:1.8;">
            <p style="margin-bottom:.75rem;"><strong>Tills</strong> are tracked daily via the Cash Drawer module — each location has its own till.</p>
            <p style="margin-bottom:.75rem;"><strong>Hub</strong> is your home cash storage. Cash flows in from tills and out to purchases or the bank.</p>
            <p style="margin-bottom:.75rem;"><strong>Cards</strong> (BMO, Square) are logged as payment methods on expenses — no physical count needed.</p>
            <p style="margin-bottom:.75rem;"><strong>Opening balance</strong> should be set once on your launch day to match what's actually in each account.</p>
            <hr style="border:none;border-top:1px solid var(--border);margin:.75rem 0;">
            <p style="font-size:12px;color:var(--text-3);">Add future accounts anytime — new card, new location till, etc.</p>
        </div>
    </div>

    <?php if (!empty($cashAccounts)): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h2 class="card-title">Account Summary</h2></div>
        <div class="card-body" style="padding:0;">
        <table class="data-table" style="font-size:13px;">
            <thead><tr><th>Account</th><th style="text-align:right;">Opening</th></tr></thead>
            <tbody>
            <?php foreach ($cashAccounts as $acct): if (!$acct['is_active']) continue; ?>
            <tr>
                <td><?= htmlspecialchars($acct['name']) ?></td>
                <td style="text-align:right;font-weight:700;">$<?= number_format((float)$acct['opening_balance'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
