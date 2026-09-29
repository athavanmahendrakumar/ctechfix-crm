<?php
// ============================================================
// Create New Customer
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';

Auth::boot();
Auth::require();

$user   = Auth::user();
$errors = [];

// Pre-fill phone from URL (coming from call classify or repair intake)
$prefillPhone    = trim($_GET['phone']    ?? '');
$prefillLocation = intval($_GET['location_id'] ?? $user['location_id']);
$returnUrl       = trim($_GET['return']   ?? '');

$locations = DB::query('SELECT * FROM locations WHERE is_active=1 ORDER BY name', []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName  = trim($_POST['first_name']  ?? '');
    $lastName   = trim($_POST['last_name']   ?? '');
    $phone      = trim($_POST['phone']       ?? '');
    $email      = trim($_POST['email']       ?? '');
    $locationId = intval($_POST['location_id'] ?? $user['location_id']);
    $notes      = trim($_POST['notes']       ?? '');
    $returnUrl  = trim($_POST['return_url']  ?? '');

    if (!$firstName) $errors[] = 'First name is required.';
    if (!$phone)     $errors[] = 'Phone number is required.';

    // Normalize phone — strip to digits only
    $phoneNormalized = preg_replace('/\D/', '', $phone);
    if (strlen($phoneNormalized) === 11 && $phoneNormalized[0] === '1') {
        $phoneNormalized = substr($phoneNormalized, 1);
    }

    // Check for duplicate phone
    if ($phoneNormalized) {
        $exists = DB::queryOne(
            'SELECT id, first_name, last_name FROM customers WHERE phone_normalized=? LIMIT 1',
            [$phoneNormalized]
        );
        if ($exists) {
            $viewUrl = APP_URL . '/modules/customers/view.php?id=' . $exists['id'];
            $errors[] = 'A customer with this phone number already exists: <a href="' . $viewUrl . '" style="font-weight:700;">'
                . htmlspecialchars($exists['first_name'] . ' ' . $exists['last_name'])
                . ' — View Customer →</a>';
        }
    }

    if (empty($errors)) {
        $customerId = DB::insert(
            'INSERT INTO customers (first_name, last_name, phone_primary, phone_normalized, email, location_id, notes, is_training, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $firstName, $lastName,
                $phone, $phoneNormalized,
                $email ?: null,
                $locationId,
                $notes ?: null,
                Auth::isTraining() ? 1 : 0,
                $user['id']
            ]
        );

        // Redirect back to where we came from, or to customer view
        if ($returnUrl) {
            header('Location: ' . $returnUrl); exit;
        }
        header('Location: ' . APP_URL . '/modules/customers/view.php?id=' . $customerId . '&msg=created'); exit;
    }
}

$pageTitle = 'New Customer';
require_once APP_ROOT . '/modules/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">New Customer</h1>
    </div>
    <a href="index.php" class="btn btn-secondary">← Customers</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="max-width:600px;">
<div class="card">
    <div class="card-header"><h2 class="card-title">Customer Info</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrl) ?>">

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
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <input type="text" name="phone" class="form-control" required
                           value="<?= htmlspecialchars($_POST['phone'] ?? $prefillPhone) ?>"
                           placeholder="e.g. 905-233-2596">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Location</label>
                <select name="location_id" class="form-control">
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"
                        <?= (($_POST['location_id'] ?? $prefillLocation) == $loc['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="Any notes about this customer"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <div class="form-actions" style="padding-top:0;border:0;margin-top:1rem;">
                <button type="submit" class="btn btn-primary">Create Customer</button>
                <a href="<?= $returnUrl ?: APP_URL . '/modules/customers/' ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
