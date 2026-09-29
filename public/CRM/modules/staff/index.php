<?php
// ============================================================
// Staff Management
// Owner: full access
// Manager: add/edit/reset password for staff only
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();
if (!Auth::isOwner() && !Auth::isManager()) {
    header('Location: ' . APP_URL . '/modules/dashboard/'); exit;
}

$user      = Auth::user();
$isOwner   = Auth::isOwner();
$isManager = Auth::isManager();
$errors    = [];

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);
$roles     = DB::query('SELECT * FROM roles ORDER BY id', []);

// Role hierarchy — managers can only assign 'staff' role
$managerAllowedRoles = ['staff'];
$ownerAllowedRoles   = ['owner', 'manager', 'staff'];

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add staff member ─────────────────────────────────────
    if ($action === 'add_user') {
        $firstName  = trim($_POST['first_name']  ?? '');
        $lastName   = trim($_POST['last_name']   ?? '');
        $username   = trim($_POST['username']    ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $roleName   = trim($_POST['role']        ?? 'staff');
        $locationId = intval($_POST['location_id'] ?? 0) ?: null;
        $password   = trim($_POST['password']    ?? '');
        $isTraining = isset($_POST['is_training']) ? 1 : 0;

        // Permission check
        $allowedRoles = $isOwner ? $ownerAllowedRoles : $managerAllowedRoles;
        if (!in_array($roleName, $allowedRoles)) {
            $errors[] = 'You do not have permission to create that role.';
        }

        if (!$firstName || !$username || !$password) {
            $errors[] = 'First name, username, and password are required.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }

        // Check username unique
        $exists = DB::queryOne('SELECT id FROM users WHERE username=?', [$username]);
        if ($exists) $errors[] = 'Username already taken.';

        if (empty($errors)) {
            $roleRow = DB::queryOne('SELECT id FROM roles WHERE name=?', [$roleName]);
            DB::execute(
                'INSERT INTO users (username, password_hash, first_name, last_name, email, phone, role_id, primary_location_id, is_training, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,1,?)',
                [
                    $username,
                    password_hash($password, PASSWORD_BCRYPT),
                    $firstName, $lastName,
                    $email ?: null, $phone ?: null,
                    $roleRow['id'],
                    $locationId,
                    $isTraining,
                    $user['id']
                ]
            );
            header('Location: ' . APP_URL . '/modules/staff/?msg=added'); exit;
        }
    }

    // ── Reset password ───────────────────────────────────────
    if ($action === 'reset_password') {
        $targetId  = intval($_POST['user_id'] ?? 0);
        $newPass   = trim($_POST['new_password'] ?? '');
        $targetUser = DB::queryOne(
            'SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',
            [$targetId]
        );

        if (!$targetUser) { $errors[] = 'User not found.'; }
        elseif (!$isOwner && $targetUser['role'] !== 'staff') {
            $errors[] = 'You can only reset passwords for staff accounts.';
        } elseif (strlen($newPass) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }

        if (empty($errors)) {
            DB::execute(
                'UPDATE users SET password_hash=? WHERE id=?',
                [password_hash($newPass, PASSWORD_BCRYPT), $targetId]
            );
            header('Location: ' . APP_URL . '/modules/staff/?msg=pw_reset'); exit;
        }
    }

    // ── Toggle active/inactive ───────────────────────────────
    if ($action === 'toggle_active') {
        $targetId   = intval($_POST['user_id'] ?? 0);
        $targetUser = DB::queryOne(
            'SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',
            [$targetId]
        );

        if ($targetUser && $targetId !== intval($user['id'])) {
            if (!$isOwner && $targetUser['role'] !== 'staff') {
                $errors[] = 'You can only deactivate staff accounts.';
            } else {
                $newStatus = $targetUser['is_active'] ? 0 : 1;
                DB::execute('UPDATE users SET is_active=? WHERE id=?', [$newStatus, $targetId]);
                header('Location: ' . APP_URL . '/modules/staff/?msg=' . ($newStatus ? 'activated' : 'deactivated')); exit;
            }
        }
    }

    // ── Toggle training mode ─────────────────────────────────
    if ($action === 'toggle_training') {
        $targetId   = intval($_POST['user_id'] ?? 0);
        $targetUser = DB::queryOne('SELECT * FROM users WHERE id=?', [$targetId]);
        if ($targetUser) {
            DB::execute('UPDATE users SET is_training=? WHERE id=?',
                [$targetUser['is_training'] ? 0 : 1, $targetId]);
            header('Location: ' . APP_URL . '/modules/staff/?msg=training_updated'); exit;
        }
    }

    // ── Edit user ────────────────────────────────────────────
    if ($action === 'edit_user') {
        $targetId   = intval($_POST['user_id'] ?? 0);
        $firstName  = trim($_POST['first_name']  ?? '');
        $lastName   = trim($_POST['last_name']   ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $locationId = intval($_POST['location_id'] ?? 0) ?: null;
        $roleName   = trim($_POST['role']        ?? '');

        $targetUser = DB::queryOne(
            'SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',
            [$targetId]
        );

        if (!$targetUser) { $errors[] = 'User not found.'; }
        elseif (!$isOwner && $targetUser['role'] !== 'staff') {
            $errors[] = 'You can only edit staff accounts.';
        } elseif (!$firstName) {
            $errors[] = 'First name is required.';
        }

        if (empty($errors)) {
            if ($isOwner && $roleName) {
                $roleRow = DB::queryOne('SELECT id FROM roles WHERE name=?', [$roleName]);
                DB::execute(
                    'UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role_id=?, primary_location_id=? WHERE id=?',
                    [$firstName, $lastName, $email ?: null, $phone ?: null, $roleRow['id'], $locationId, $targetId]
                );
            } else {
                DB::execute(
                    'UPDATE users SET first_name=?, last_name=?, email=?, phone=?, primary_location_id=? WHERE id=?',
                    [$firstName, $lastName, $email ?: null, $phone ?: null, $locationId, $targetId]
                );
            }
            header('Location: ' . APP_URL . '/modules/staff/?msg=updated'); exit;
        }
    }
}

// ── Load users ───────────────────────────────────────────────
$staffList = DB::query(
    "SELECT u.*, r.name AS role, r.label AS role_label,
            l.name AS location_name, l.code AS location_code
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN locations l ON l.id = u.primary_location_id
     ORDER BY r.id ASC, u.first_name ASC",
    []
);

$msgMap = [
    'added'            => '✓ Staff member added.',
    'updated'          => '✓ Staff member updated.',
    'pw_reset'         => '✓ Password reset.',
    'deactivated'      => '✓ Account deactivated.',
    'activated'        => '✓ Account reactivated.',
    'training_updated' => '✓ Training mode updated.',
];

$activeTab = $_GET['tab'] ?? 'list';
$editUser  = null;
if (isset($_GET['edit'])) {
    $editUser = DB::queryOne(
        'SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',
        [intval($_GET['edit'])]
    );
}

$pageTitle = 'Staff';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Staff Management</h1>
        <p class="page-sub"><?= count($staffList) ?> team members</p>
    </div>
    <button onclick="document.getElementById('add-panel').style.display=document.getElementById('add-panel').style.display==='none'?'block':'none'"
            class="btn btn-primary">+ Add Staff</button>
</div>

<?php if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): ?>
<div class="alert alert-success"><?= $msgMap[$_GET['msg']] ?></div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Add Staff Panel -->
<div id="add-panel" style="display:<?= $errors && ($_POST['action']??'')==='add_user' ? 'block' : 'none' ?>;">
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2 class="card-title">Add Staff Member</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" class="form-control" required
                           value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control"
                           value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" required autocomplete="off"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Password <span class="required">*</span></label>
                    <input type="password" name="password" class="form-control" required autocomplete="new-password" minlength="6">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Role <span class="required">*</span></label>
                    <select name="role" class="form-control" required>
                        <?php foreach ($roles as $r):
                            if (!$isOwner && !in_array($r['name'], $managerAllowedRoles)) continue; ?>
                        <option value="<?= $r['name'] ?>" <?= ($_POST['role']??'')===$r['name']?'selected':'' ?>>
                            <?= htmlspecialchars($r['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem;">
                    <input type="checkbox" name="is_training" id="is_training" value="1">
                    <label for="is_training" style="margin:0;font-weight:400;">Start in Training Mode</label>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">Add Staff Member</button>
                </div>
            </div>
        </form>
    </div>
</div>
</div>

<!-- Edit Panel -->
<?php if ($editUser): ?>
<div class="card" style="margin-bottom:1.5rem;border:2px solid var(--blue);">
    <div class="card-header" style="background:var(--blue-light,#eff6ff);">
        <h2 class="card-title">Edit — <?= htmlspecialchars($editUser['first_name'] . ' ' . $editUser['last_name']) ?></h2>
        <a href="?" class="btn btn-sm btn-ghost">Cancel</a>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action"  value="edit_user">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" class="form-control" required
                           value="<?= htmlspecialchars($editUser['first_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control"
                           value="<?= htmlspecialchars($editUser['last_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <?php if ($isOwner): ?>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-control">
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['name'] ?>" <?= $editUser['role']===$r['name']?'selected':'' ?>>
                            <?= htmlspecialchars($r['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= $editUser['primary_location_id']==$loc['id']?'selected':'' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Staff List -->
<div class="card">
    <div class="card-header"><h2 class="card-title">Team Members</h2></div>
    <div class="card-body" style="padding:0;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Location</th>
                <th>Status</th>
                <th>Training</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staffList as $s):
            $canEdit     = $isOwner || ($isManager && $s['role'] === 'staff');
            $isSelf      = $s['id'] == $user['id'];
        ?>
        <tr style="<?= !$s['is_active'] ? 'opacity:.5;' : '' ?>">
            <td>
                <strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong>
                <?php if ($isSelf): ?><span class="badge badge-info" style="margin-left:4px;">You</span><?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($s['username']) ?></td>
            <td>
                <?php
                $roleColors = ['owner'=>'badge-danger','manager'=>'badge-warning','staff'=>'badge-info'];
                $rc = $roleColors[$s['role']] ?? 'badge-secondary';
                ?>
                <span class="badge <?= $rc ?>"><?= htmlspecialchars($s['role_label']) ?></span>
            </td>
            <td>
                <?php if ($s['location_code']): ?>
                <span class="badge badge-loc"><?= htmlspecialchars($s['location_code']) ?></span>
                <?php else: ?>
                <span class="text-muted small">All</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($s['is_active']): ?>
                <span style="color:var(--green);font-size:13px;">● Active</span>
                <?php else: ?>
                <span style="color:var(--red);font-size:13px;">● Inactive</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($s['is_training']): ?>
                <span class="badge badge-warning">Training</span>
                <?php else: ?>
                <span class="text-muted small">Live</span>
                <?php endif; ?>
            </td>
            <td class="text-muted small">
                <?= $s['last_login_at'] ? timeAgo($s['last_login_at']) : 'Never' ?>
            </td>
            <td>
                <div style="display:flex;gap:.25rem;flex-wrap:wrap;">
                <?php if ($canEdit && !$isSelf): ?>

                    <!-- Edit -->
                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>

                    <!-- Reset Password -->
                    <button class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('pw-<?= $s['id'] ?>').style.display='block'">
                        🔑 Reset PW
                    </button>

                    <!-- Toggle Active -->
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?> this account?')">
                        <input type="hidden" name="action"  value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                            <?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                        </button>
                    </form>

                    <!-- Toggle Training -->
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action"  value="toggle_training">
                        <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost"
                                title="<?= $s['is_training'] ? 'Switch to Live' : 'Switch to Training' ?>">
                            <?= $s['is_training'] ? '🔴 → Live' : '🟡 → Train' ?>
                        </button>
                    </form>

                <?php elseif ($isSelf): ?>
                    <span class="text-muted small">—</span>
                <?php else: ?>
                    <span class="text-muted small">No access</span>
                <?php endif; ?>
                </div>

                <!-- Inline Reset Password Form -->
                <?php if ($canEdit && !$isSelf): ?>
                <div id="pw-<?= $s['id'] ?>" style="display:none;margin-top:.5rem;">
                    <form method="POST" style="display:flex;gap:.25rem;align-items:center;">
                        <input type="hidden" name="action"  value="reset_password">
                        <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                        <input type="password" name="new_password" class="form-control"
                               placeholder="New password" minlength="6" required
                               style="width:160px;font-size:13px;padding:.3rem .5rem;">
                        <button type="submit" class="btn btn-sm btn-primary">Set</button>
                        <button type="button" class="btn btn-sm btn-ghost"
                                onclick="document.getElementById('pw-<?= $s['id'] ?>').style.display='none'">✕</button>
                    </form>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
