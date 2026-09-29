<?php
// ============================================================
// Repair Detail / View
// ============================================================
$rootPath = dirname(__DIR__, 4);
require_once $rootPath . '/config/config.php';
require_once $rootPath . '/core/DB.php';
require_once $rootPath . '/core/Auth.php';
require_once $rootPath . '/core/helpers.php';
require_once $rootPath . '/core/Audit.php';
require_once $rootPath . '/core/VoipMS.php';

Auth::boot();
Auth::require();

$user     = Auth::user();
$training = Auth::isTraining();
$repairId = intval($_GET['id'] ?? 0);

if (!$repairId) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

$repair = DB::queryOne(
    "SELECT r.*,
            c.first_name, c.last_name, c.phone_primary AS phone, c.phone_normalized, c.email,
            l.name AS location_name, l.code AS location_code, l.did AS location_did,
            u.first_name AS tech_name
     FROM repairs r
     JOIN customers c  ON r.customer_id = c.id
     JOIN locations l  ON r.location_id = l.id
     LEFT JOIN users u ON r.assigned_to = u.id
     WHERE r.id = ? LIMIT 1",
    [$repairId]
);

if (!$repair) { header('Location: ' . APP_URL . '/modules/repairs/'); exit; }

// Access control: staff only see their location
if (Auth::isStaff() && $repair['location_id'] != $user['location_id']) {
    header('Location: ' . APP_URL . '/modules/repairs/'); exit;
}

// ── AJAX: Inventory lookup ───────────────────────────────────
if (isset($_GET['lookup_inventory'])) {
    header('Content-Type: application/json');
    $term = '%' . trim($_GET['lookup_inventory']) . '%';
    $rows = DB::query(
        "SELECT i.id, i.name, i.sku, i.cost_price, i.sell_price, i.compatible_with,
                COALESCE(s.quantity, 0) AS stock
         FROM inventory_items i
         LEFT JOIN inventory_stock s ON s.item_id = i.id AND s.location_id = ?
         WHERE i.is_active = 1 AND i.item_type = 'part'
           AND (i.name LIKE ? OR i.sku LIKE ? OR i.compatible_with LIKE ?)
         ORDER BY i.name
         LIMIT 20",
        [$repair['location_id'], $term, $term, $term]
    );
    echo json_encode($rows);
    exit;
}

// ── Handle POST actions ──────────────────────────────────────
$msgMap = [
    'saved'             => '✓ Repair ticket updated.',
    'status_updated'    => '✓ Status updated successfully.',
    'note_added'        => '✓ Note added.',
    'part_added'        => '✓ Part added.',
    'payment_recorded'  => '✓ Payment recorded — repair marked as completed.',
    'reopened'          => '✓ Repair reopened — collect new payment below.',
    'cost_updated'      => '✓ Final cost updated.',
    'sms_sent'          => '✓ SMS sent to customer.',
    'sms_failed'        => '✗ SMS failed — check VoipMS credentials.',
    'sms_optout'        => '⚠ Customer has SMS opted out.',
    'approval_sent'     => '✓ Quote approval request sent to customer.',
    'approval_failed'   => '✗ Could not send approval SMS — check VoipMS credentials.',
    'approval_reset'    => '✓ Approval status reset.',
];
$actionMsg  = $msgMap[$_GET['msg'] ?? ''] ?? null;
$actionType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Change Status ───────────────────────────────────────
    if ($action === 'change_status') {
        $newStatus  = $_POST['new_status']    ?? '';
        $statusNote = trim($_POST['status_note'] ?? '');
        $sendSms    = isset($_POST['send_sms']) && !$repair['sms_opt_out'] && !$training;

        $allowed = ['received','diagnosed','in_repair','waiting_parts','ready_pickup','completed','cancelled','non_repairable'];
        if (in_array($newStatus, $allowed, true) && $newStatus !== $repair['status']) {

            DB::execute('UPDATE repairs SET status=?, updated_by=? WHERE id=?',
                [$newStatus, $user['id'], $repairId]);

            // If completed, set completed_at
            if ($newStatus === 'completed') {
                DB::execute('UPDATE repairs SET completed_at=NOW() WHERE id=? AND completed_at IS NULL', [$repairId]);
            }
            // If marked ready_pickup, set final_cost from estimated if not already set
            if ($newStatus === 'ready_pickup') {
                DB::execute('UPDATE repairs SET final_cost=COALESCE(final_cost, estimated_cost) WHERE id=?', [$repairId]);
            }
            // If non_repairable, store reason in diagnosis_notes for visibility on ticket
            if ($newStatus === 'non_repairable' && $statusNote) {
                DB::execute(
                    "UPDATE repairs SET diagnosis_notes=CONCAT(COALESCE(NULLIF(diagnosis_notes,''),''), IF(diagnosis_notes IS NOT NULL AND diagnosis_notes != '', '\n\n', ''), 'NON-REPAIRABLE: ', ?) WHERE id=?",
                    [$statusNote, $repairId]
                );
            }

            $smsId = null;
            // Send SMS notification
            if ($sendSms) {
                $smsTemplates = [
                    'diagnosed'       => "Hi {name}, your {device} has been diagnosed. We'll be in touch shortly with a quote. - C Tech Fix",
                    'in_repair'       => "Hi {name}, great news! We've started repairing your {device}. We'll notify you when it's ready. - C Tech Fix",
                    'waiting_parts'   => "Hi {name}, we're waiting on a part for your {device}. We'll update you as soon as it arrives. - C Tech Fix",
                    'ready_pickup'    => "Hi {name}, your {device} is ready for pickup! Visit us during store hours. Ticket: {ticket}. - C Tech Fix",
                    'completed'       => "Hi {name}, thank you for choosing C Tech Fix! Your repair is complete. - C Tech Fix",
                    'non_repairable'  => "Hi {name}, unfortunately your {device} is non-repairable. Please come in to pick it up at your convenience. Sorry we couldn't do more. - C Tech Fix",
                ];
                if (isset($smsTemplates[$newStatus])) {
                    $msg = strtr($smsTemplates[$newStatus], [
                        '{name}'   => $repair['first_name'],
                        '{device}' => $repair['device_brand'] . ' ' . $repair['device_model'],
                        '{ticket}' => $repair['record_number'],
                    ]);
                    $did    = VoipMS::didForLocation($repair['location_code']);
                    $result = VoipMS::sendSMS($did, $repair['phone_normalized'], $msg);
                    if ($result === true) {
                        $smsId = DB::insert(
                            'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                             VALUES (?,?,?,?,?,?,?,NOW())',
                            [$repair['location_id'], $repair['customer_id'], 'outbound',
                             $repair['location_did'], $repair['phone_normalized'], $msg, 'sent']
                        );
                    }
                }
            }

            DB::execute(
                'INSERT INTO repair_status_history (repair_id, old_status, new_status, notes, sms_sent, sms_message_id, changed_by)
                 VALUES (?,?,?,?,?,?,?)',
                [$repairId, $repair['status'], $newStatus, $statusNote ?: null, $smsId ? 1 : 0, $smsId, $user['id']]
            );

            Audit::log('status_change', 'repairs', $repairId,
                $repair['status'], $newStatus, "Status changed to $newStatus");

            // Redirect back with success message
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=status_updated');
            exit;
        }
    }

    // ── Add Note ────────────────────────────────────────────
    elseif ($action === 'add_note') {
        $note       = trim($_POST['note']        ?? '');
        $isInternal = isset($_POST['is_internal']) ? 1 : 0;
        if ($note) {
            DB::execute(
                'INSERT INTO repair_notes (repair_id, note, is_internal, created_by) VALUES (?,?,?,?)',
                [$repairId, $note, $isInternal, $user['id']]
            );
        }
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=note_added');
        exit;
    }

    // ── Update part cost (owner/manager only) ───────────────
    elseif ($action === 'update_part_cost' && (Auth::isOwner() || Auth::isManager())) {
        $partId    = intval($_POST['part_id']    ?? 0);
        $costPrice = trim($_POST['cost_price']   ?? '');
        if ($partId && $costPrice !== '') {
            DB::execute('UPDATE repair_parts SET cost_price=? WHERE id=? AND repair_id=?',
                [floatval($costPrice), $partId, $repairId]);
        }
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '#parts'); exit;
    }

    // ── Add Part ────────────────────────────────────────────
    elseif ($action === 'add_part') {
        $invItemId = intval($_POST['inventory_item_id'] ?? 0) ?: null;
        $partName  = trim($_POST['part_name']  ?? '');
        $partSku   = trim($_POST['part_sku']   ?? '');
        $qty       = intval($_POST['quantity'] ?? 1);
        // Staff cannot submit cost — owner/manager only
        $costPrice = (Auth::isOwner() || Auth::isManager()) ? trim($_POST['cost_price'] ?? '') : '';
        $sellPrice = trim($_POST['sell_price'] ?? '0');

        // If inventory item selected, pull details from it
        if ($invItemId) {
            $invItem = DB::queryOne('SELECT * FROM inventory_items WHERE id=?', [$invItemId]);
            if ($invItem) {
                $partName  = $partName  ?: $invItem['name'];
                $partSku   = $partSku   ?: ($invItem['sku'] ?? '');
                $costPrice = $costPrice ?: $invItem['cost_price'];
                $sellPrice = $sellPrice && $sellPrice != '0' ? $sellPrice : $invItem['sell_price'];
            }
        }

        if ($partName) {
            DB::execute(
                'INSERT INTO repair_parts (repair_id, inventory_item_id, part_name, part_sku, quantity, cost_price, sell_price, added_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$repairId, $invItemId, $partName, $partSku ?: null, $qty,
                 $costPrice !== '' ? $costPrice : null, $sellPrice, $user['id']]
            );

            // Deduct from inventory stock at this repair's location
            if ($invItemId) {
                DB::execute(
                    'UPDATE inventory_stock SET quantity = GREATEST(0, quantity - ?)
                     WHERE item_id = ? AND location_id = ?',
                    [$qty, $invItemId, $repair['location_id']]
                );
                DB::execute(
                    'INSERT INTO inventory_movements (item_id, location_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$invItemId, $repair['location_id'], 'repair_use', -$qty,
                     'repair', $repairId, 'Used in repair ' . $repair['record_number'], $user['id']]
                );
            }

            DB::execute(
                'UPDATE repairs SET estimated_cost = (SELECT SUM(sell_price * quantity) FROM repair_parts WHERE repair_id = ?)
                 WHERE id = ?',
                [$repairId, $repairId]
            );
        }
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=part_added');
        exit;
    }

    // ── Remove Part ─────────────────────────────────────────
    elseif ($action === 'remove_part') {
        $partId = intval($_POST['part_id'] ?? 0);
        if ($partId) {
            $part = DB::queryOne('SELECT * FROM repair_parts WHERE id=? AND repair_id=?', [$partId, $repairId]);
            if ($part) {
                // Restore inventory stock if linked to an inventory item
                if ($part['inventory_item_id']) {
                    DB::execute(
                        'UPDATE inventory_stock SET quantity = quantity + ? WHERE item_id = ? AND location_id = ?',
                        [$part['quantity'], $part['inventory_item_id'], $repair['location_id']]
                    );
                    DB::execute(
                        'INSERT INTO inventory_movements (item_id, location_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$part['inventory_item_id'], $repair['location_id'], 'return', $part['quantity'],
                         'repair', $repairId, 'Part removed from repair ' . $repair['record_number'], $user['id']]
                    );
                }
                DB::execute('DELETE FROM repair_parts WHERE id=?', [$partId]);
                // Recalculate estimated cost
                DB::execute(
                    'UPDATE repairs SET estimated_cost = (
                        SELECT COALESCE(SUM(sell_price * quantity), NULL) FROM repair_parts WHERE repair_id = ?
                     ) WHERE id = ?',
                    [$repairId, $repairId]
                );
            }
        }
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '#parts');
        exit;
    }

    // ── Record Payment ──────────────────────────────────────
    elseif ($action === 'record_payment') {
        require_once $rootPath . '/core/RecordNumber.php';

        $finalCost     = floatval($_POST['final_cost']      ?? 0);
        $discount      = floatval($_POST['discount_amount'] ?? 0);
        $paymentMethod = $_POST['payment_method']           ?? '';
        $paymentNotes  = trim($_POST['payment_notes']       ?? '');

        $depositOnFile = (float)($repair['deposit_amount'] ?? 0);
        $balanceDue    = max(0, $finalCost - $discount - $depositOnFile);

        DB::execute(
            'UPDATE repairs SET final_cost=?, discount_amount=?, payment_method=?, payment_notes=?, paid_at=NOW(),
             status=\'completed\', completed_at=COALESCE(completed_at,NOW()), updated_by=? WHERE id=?',
            [$finalCost, $discount, $paymentMethod, $paymentNotes ?: null, $user['id'], $repairId]
        );
        DB::execute(
            'INSERT INTO repair_status_history (repair_id, old_status, new_status, notes, changed_by)
             VALUES (?,?,?,?,?)',
            [$repairId, $repair['status'], 'completed', 'Payment recorded. Method: ' . $paymentMethod . ($depositOnFile > 0 ? '. Deposit applied: $' . number_format($depositOnFile, 2) : ''), $user['id']]
        );

        // Log the balance payment as a sale (only if there's a balance to collect)
        if ($balanceDue > 0 && !$training) {
            $locCode       = $repair['location_code'];
            $saleRecordNum = RecordNumber::next('SALE', $locCode);
            $saleId = DB::insert(
                'INSERT INTO sales (record_number, location_id, customer_id, repair_id, sale_type,
                 subtotal, tax_amount, discount_amount, total_amount, payment_method, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $saleRecordNum, $repair['location_id'], $repair['customer_id'], $repairId, 'repair_final',
                    $balanceDue, 0, $discount, $balanceDue,
                    $paymentMethod,
                    'Final payment for repair ' . $repair['record_number'] . ($depositOnFile > 0 ? ' (deposit $' . number_format($depositOnFile, 2) . ' already collected)' : ''),
                    $user['id'],
                ]
            );
            DB::insert(
                'INSERT INTO sale_items (sale_id, item_name, quantity, unit_price, line_total)
                 VALUES (?,?,?,?,?)',
                [$saleId, 'Repair — ' . $repair['device_brand'] . ' ' . $repair['device_model'], 1, $balanceDue, $balanceDue]
            );
        }

        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=payment_recorded');
        exit;
    }

    // ── Request quote approval via SMS ─────────────────────
    elseif ($action === 'request_approval') {
        if ($repair['sms_opt_out']) {
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=sms_optout'); exit;
        }
        $estCost   = $repair['estimated_cost'] !== null ? '$' . number_format((float)$repair['estimated_cost'], 2) : 'TBD';
        $statusUrl = APP_URL . '/repair-status.php?t=' . $repair['status_token'];
        // Build message — URL must never be cut, so we fall back to a shorter prefix if needed.
        // Full form:  "C Tech Fix: Quote ready - $120.00. Approve or decline your repair: https://..."  (~148 chars)
        // Short form: "C Tech Fix: Quote $120.00: https://..."  (~103 chars, used only if full exceeds 160)
        $msgFull  = "C Tech Fix: Quote ready - {$estCost}. Approve or decline your repair: {$statusUrl}";
        $msgShort = "C Tech Fix: Quote {$estCost}: {$statusUrl}";
        $msg = strlen($msgFull) <= 160 ? $msgFull : $msgShort;

        // Use the repair's own is_training flag — not the user's session toggle
        // so that real repairs always send real SMS regardless of training mode
        if (!$repair['is_training']) {
            $did    = VoipMS::didForLocation($repair['location_code']);
            $result = VoipMS::sendSMS($did, $repair['phone_normalized'], $msg);
            if ($result === true) {
                DB::execute("UPDATE repairs SET quote_approval='pending', quote_approved_at=NULL, quote_decline_reason=NULL WHERE id=?", [$repairId]);
                DB::insert(
                    'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                     VALUES (?,?,?,?,?,?,?,NOW())',
                    [$repair['location_id'], $repair['customer_id'], 'outbound',
                     $did, $repair['phone_normalized'], $msg, 'sent']
                );
                header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=approval_sent'); exit;
            } else {
                $err = is_string($result) ? $result : 'SMS failed';
                session_start();
                $_SESSION['sms_error'] = $err;
                header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=approval_failed'); exit;
            }
        } else {
            // Training repair — mark pending but never send real SMS
            DB::execute("UPDATE repairs SET quote_approval='pending' WHERE id=?", [$repairId]);
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=approval_sent'); exit;
        }
    }

    // ── Reset approval status ───────────────────────────────
    elseif ($action === 'reset_approval') {
        DB::execute("UPDATE repairs SET quote_approval='none', quote_approved_at=NULL, quote_decline_reason=NULL WHERE id=?", [$repairId]);
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=approval_reset'); exit;
    }

    // ── Reopen completed/cancelled repair (owner/manager) ──
    elseif ($action === 'reopen_repair' && (Auth::isOwner() || Auth::isManager())) {
        DB::execute(
            "UPDATE repairs SET status='ready_pickup', paid_at=NULL, completed_at=NULL, updated_by=? WHERE id=?",
            [$user['id'], $repairId]
        );
        DB::execute(
            "INSERT INTO repair_status_history (repair_id, old_status, new_status, notes, changed_by)
             VALUES (?,?,?,?,?)",
            [$repairId, $repair['status'], 'ready_pickup',
             'Repair reopened by manager. Previous payment record may need to be voided in Sales.', $user['id']]
        );
        Audit::log('repair_reopened', 'repairs', $repairId, $repair['status'], 'ready_pickup', 'Reopened for payment correction');
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=reopened');
        exit;
    }

    // ── Update final cost only (no payment yet) ─────────────
    elseif ($action === 'update_final_cost') {
        $newFinalCost = floatval($_POST['final_cost'] ?? 0);
        DB::execute("UPDATE repairs SET final_cost=?, updated_by=? WHERE id=?",
            [$newFinalCost, $user['id'], $repairId]);
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=cost_updated');
        exit;
    }

    // ── Delete repair (owner only) ──────────────────────────
    elseif ($action === 'delete_repair') {
        if (!Auth::isOwner()) {
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId); exit;
        }
        // Detach any sales records (deposit/final payments) — keep the payment, just unlink
        DB::execute('UPDATE sales SET repair_id=NULL WHERE repair_id=?', [$repairId]);
        // Delete child rows
        DB::execute('DELETE FROM repair_parts         WHERE repair_id=?', [$repairId]);
        DB::execute('DELETE FROM repair_notes         WHERE repair_id=?', [$repairId]);
        DB::execute('DELETE FROM repair_status_history WHERE repair_id=?', [$repairId]);
        // Delete the repair
        DB::execute('DELETE FROM repairs WHERE id=?', [$repairId]);
        header('Location: ' . APP_URL . '/modules/repairs/?deleted=1'); exit;
    }

    // ── Send custom SMS ─────────────────────────────────────
    elseif ($action === 'send_custom_sms') {
        $msgBody = trim($_POST['sms_body'] ?? '');
        if ($repair['sms_opt_out']) {
            header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=sms_optout'); exit;
        }
        if ($msgBody && !$training) {
            $did    = VoipMS::didForLocation($repair['location_code']);
            $result = VoipMS::sendSMS($did, $repair['phone_normalized'], $msgBody);
            if ($result === true) {
                DB::insert(
                    'INSERT INTO sms_messages (location_id, customer_id, direction, did, contact_number, message, status, sent_at)
                     VALUES (?,?,?,?,?,?,?,NOW())',
                    [$repair['location_id'], $repair['customer_id'], 'outbound',
                     $did, $repair['phone_normalized'], $msgBody, 'sent']
                );
                header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=sms_sent'); exit;
            } else {
                header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId . '&msg=sms_failed'); exit;
            }
        }
        header('Location: ' . APP_URL . '/modules/repairs/view.php?id=' . $repairId); exit;
    }
}

// ── Load related data ────────────────────────────────────────
$statusHistory = DB::query(
    "SELECT rsh.*, u.first_name AS changed_by_name
     FROM repair_status_history rsh
     LEFT JOIN users u ON rsh.changed_by = u.id
     WHERE rsh.repair_id = ?
     ORDER BY rsh.created_at ASC",
    [$repairId]
);

$notes = DB::query(
    "SELECT rn.*, u.first_name AS author
     FROM repair_notes rn
     LEFT JOIN users u ON rn.created_by = u.id
     WHERE rn.repair_id = ?
     ORDER BY rn.created_at DESC",
    [$repairId]
);

$parts = DB::query(
    'SELECT rp.*, u.first_name AS added_by_name
     FROM repair_parts rp LEFT JOIN users u ON rp.added_by = u.id
     WHERE rp.repair_id = ? ORDER BY rp.created_at ASC',
    [$repairId]
);

$smsMsgs = DB::query(
    'SELECT * FROM sms_messages
     WHERE customer_id = ? AND location_id = ?
     ORDER BY sent_at DESC LIMIT 20',
    [$repair['customer_id'], $repair['location_id']]
);

$statusLabels = [
    'received'        => 'Received',
    'diagnosed'       => 'Diagnosed',
    'in_repair'       => 'In Repair',
    'waiting_parts'   => 'Waiting Parts',
    'ready_pickup'    => 'Ready for Pickup',
    'completed'       => 'Completed',
    'cancelled'       => 'Cancelled',
    'non_repairable'  => 'Non-Repairable',
];
$statusColors = [
    'received'        => 'badge-secondary',
    'diagnosed'       => 'badge-info',
    'in_repair'       => 'badge-primary',
    'waiting_parts'   => 'badge-warning',
    'ready_pickup'    => 'badge-success',
    'completed'       => 'badge-muted',
    'cancelled'       => 'badge-danger',
    'non_repairable'  => 'badge-danger',
];
// All open statuses — staff can jump to any of these from any open stage
$_openStatuses = [
    'received'       => 'Received',
    'diagnosed'      => 'Diagnosed',
    'in_repair'      => 'In Repair',
    'waiting_parts'  => 'Waiting for Parts',
    'ready_pickup'   => 'Ready for Pickup',
    'non_repairable' => 'Non-Repairable',
    'cancelled'      => 'Cancel Repair',
];
$nextStatuses = [];
foreach (array_keys($_openStatuses) as $_s) {
    $nextStatuses[$_s] = array_filter($_openStatuses, fn($k) => $k !== $_s, ARRAY_FILTER_USE_KEY);
    // Ready for pickup also gets the Completed option (triggers payment)
    if ($_s === 'ready_pickup') {
        $nextStatuses[$_s]['completed'] = 'Mark Completed';
    }
}
$nextStatuses['completed']      = [];
$nextStatuses['cancelled']       = ['received' => 'Re-open as Received'];
// Non-repairable: can mark ready for pickup (customer comes to collect device)
$nextStatuses['non_repairable'] = ['ready_pickup' => 'Ready for Pickup (Customer Collecting Device)', 'cancelled' => 'Cancel'];

$pageTitle = $repair['record_number'] . ' — ' . $repair['first_name'] . ' ' . $repair['last_name'];
require_once APP_ROOT . '/modules/layout/header.php';

$justCreated = isset($_GET['created']);
?>

<?php if ($justCreated): ?>
<div class="alert alert-success" style="margin-bottom:1rem;">
    ✅ Repair ticket <strong><?= htmlspecialchars($repair['record_number']) ?></strong> opened successfully!
    <a href="receipt.php?id=<?= $repairId ?>" target="_blank" class="btn btn-sm btn-primary"   style="margin-left:1rem;">🧾 Print Receipt</a>
    <a href="label.php?id=<?= $repairId ?>"   target="_blank" class="btn btn-sm btn-secondary" style="margin-left:.5rem;">🖨 Print Label</a>
</div>
<?php endif; ?>

<?php if ($actionMsg): ?>
<div class="alert alert-<?= $actionType ?>">✓ <?= $actionMsg ?></div>
<?php endif; ?>

<?php if ($repair['status'] === 'non_repairable'): ?>
<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.75rem;">
    <span style="font-size:1.5rem;flex-shrink:0;">🚫</span>
    <div>
        <div style="font-weight:700;color:#991b1b;font-size:15px;">Non-Repairable Device</div>
        <?php
        // Pull the non-repairable reason from status history
        $nrEntry = DB::queryOne(
            "SELECT notes FROM repair_status_history WHERE repair_id=? AND new_status='non_repairable' ORDER BY created_at DESC LIMIT 1",
            [$repairId]
        );
        if ($nrEntry && $nrEntry['notes']):
        ?>
        <div style="color:#7f1d1d;font-size:13px;margin-top:.25rem;">Reason: <?= htmlspecialchars($nrEntry['notes']) ?></div>
        <?php endif; ?>
        <div style="color:#991b1b;font-size:12px;margin-top:.35rem;">Customer needs to collect this device. Use <strong>Ready for Pickup</strong> to notify them.</div>
    </div>
</div>
<?php endif; ?>

<!-- ── Header ─────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <h1 class="page-title" style="margin:0;"><?= htmlspecialchars($repair['record_number']) ?></h1>
            <span class="badge <?= $statusColors[$repair['status']] ?>">
                <?= $statusLabels[$repair['status']] ?? $repair['status'] ?>
            </span>
            <span class="badge badge-loc"><?= htmlspecialchars($repair['location_code']) ?></span>
            <?php if ($repair['is_training']): ?>
            <span class="badge badge-warning">TRAINING</span>
            <?php endif; ?>
        </div>
        <p class="page-sub" style="margin-top:.25rem;">
            <?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?>
            — <?= htmlspecialchars($repair['first_name'] . ' ' . $repair['last_name']) ?>
            — Received <?= timeAgo($repair['received_at']) ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="receipt.php?id=<?= $repairId ?>" target="_blank" class="btn btn-secondary">🧾 Receipt</a>
        <a href="label.php?id=<?= $repairId ?>"   target="_blank" class="btn btn-secondary">🖨 Label</a>
        <a href="edit.php?id=<?= $repairId ?>"    class="btn btn-secondary">✏ Edit</a>
        <a href="<?= APP_URL ?>/modules/repairs/"  class="btn btn-secondary">← Queue</a>
        <?php if (in_array($repair['status'], ['completed','cancelled']) && (Auth::isOwner() || Auth::isManager())): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="reopen_repair">
            <button type="submit" class="btn btn-warning"
                    onclick="return confirm('Reopen this repair? The previous payment record will NOT be automatically voided — check Sales if needed.')">
                🔄 Reopen
            </button>
        </form>
        <?php endif; ?>
        <?php if (Auth::isOwner()): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_repair">
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Permanently delete repair <?= htmlspecialchars($repair['record_number']) ?>? This cannot be undone.')">
                🗑 Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="repair-grid">

    <!-- ── LEFT COLUMN ───────────────────────────────────── -->
    <div class="repair-col-main">

        <!-- Customer & Device Info -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Customer & Device</h2></div>
            <div class="card-body detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Customer</span>
                    <span class="detail-value"><?= htmlspecialchars($repair['first_name'] . ' ' . $repair['last_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value"><a href="tel:<?= $repair['phone_normalized'] ?>"><?= htmlspecialchars($repair['phone']) ?></a></span>
                </div>
                <?php if ($repair['email']): ?>
                <div class="detail-item">
                    <span class="detail-label">Email</span>
                    <span class="detail-value"><?= htmlspecialchars($repair['email']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <span class="detail-label">Device</span>
                    <span class="detail-value"><?= htmlspecialchars($repair['device_brand'] . ' ' . $repair['device_model']) ?>
                        <?= $repair['device_color'] ? '(' . htmlspecialchars($repair['device_color']) . ')' : '' ?>
                    </span>
                </div>
                <?php if ($repair['device_serial']): ?>
                <div class="detail-item">
                    <span class="detail-label">Serial</span>
                    <span class="detail-value detail-mono"><?= htmlspecialchars($repair['device_serial']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($repair['device_imei']): ?>
                <div class="detail-item">
                    <span class="detail-label">IMEI</span>
                    <span class="detail-value detail-mono"><?= htmlspecialchars($repair['device_imei']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($repair['device_passcode']): ?>
                <div class="detail-item">
                    <span class="detail-label">Passcode</span>
                    <span class="detail-value detail-mono passcode-blur" onclick="this.classList.toggle('passcode-blur')" title="Click to reveal">
                        <?= htmlspecialchars($repair['device_passcode']) ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($repair['accessories']): ?>
                <div class="detail-item">
                    <span class="detail-label">Accessories</span>
                    <span class="detail-value"><?= htmlspecialchars($repair['accessories']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($repair['service_type'])): ?>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Services</span>
                    <span class="detail-value">
                    <?php foreach (array_map('trim', explode(',', $repair['service_type'])) as $svc): ?>
                        <span style="display:inline-block;padding:.15rem .55rem;border-radius:12px;background:var(--primary);color:#fff;font-size:12px;font-weight:600;margin:.1rem .15rem 0 0;"><?= htmlspecialchars($svc) ?></span>
                    <?php endforeach; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Issue Reported</span>
                    <span class="detail-value"><?= nl2br(htmlspecialchars($repair['issue_description'])) ?></span>
                </div>
                <?php if ($repair['diagnosis_notes']): ?>
                <div class="detail-item" style="grid-column:1/-1;">
                    <span class="detail-label">Diagnosis</span>
                    <span class="detail-value"><?= nl2br(htmlspecialchars($repair['diagnosis_notes'])) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <span class="detail-label">Technician</span>
                    <span class="detail-value"><?= $repair['tech_name'] ? htmlspecialchars($repair['tech_name']) : '—' ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Est. Ready</span>
                    <span class="detail-value"><?= $repair['estimated_ready_at'] ? date('M j, Y g:ia', strtotime($repair['estimated_ready_at'])) : '—' ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Est. Cost</span>
                    <span class="detail-value"><?= $repair['estimated_cost'] !== null ? '$' . number_format($repair['estimated_cost'], 2) : '—' ?></span>
                </div>
                <?php
                $depositAmt = (float)($repair['deposit_amount'] ?? 0);
                $finalCostAmt = $repair['final_cost'] !== null ? (float)$repair['final_cost'] : null;
                $baseForBalance = $finalCostAmt ?? (float)($repair['estimated_cost'] ?? 0);
                $balanceOwing = max(0, $baseForBalance - $depositAmt);
                ?>
                <?php if ($depositAmt > 0): ?>
                <div class="detail-item" style="background:#f0fdf4;border-radius:6px;padding:.5rem .75rem;">
                    <span class="detail-label">Deposit Paid</span>
                    <span class="detail-value" style="color:var(--green);font-weight:700;">
                        $<?= number_format($depositAmt, 2) ?>
                        <?php if ($repair['deposit_method']): ?>
                        <small style="font-weight:400;color:var(--text-3);">(<?= ucwords(str_replace('_',' ',$repair['deposit_method'])) ?>)</small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item" style="background:<?= $balanceOwing > 0 ? '#fffbeb' : '#f0fdf4' ?>;border-radius:6px;padding:.5rem .75rem;">
                    <span class="detail-label">Balance at Pickup</span>
                    <span class="detail-value" style="color:<?= $balanceOwing > 0 ? 'var(--amber)' : 'var(--green)' ?>;font-weight:700;">
                        $<?= number_format($balanceOwing, 2) ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($finalCostAmt !== null): ?>
                <div class="detail-item">
                    <span class="detail-label">Final Cost</span>
                    <span class="detail-value" style="color:var(--success);font-weight:600;">$<?= number_format($finalCostAmt, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($repair['payment_method']): ?>
                <div class="detail-item">
                    <span class="detail-label">Payment</span>
                    <span class="detail-value"><?= ucwords(str_replace('_', ' ', $repair['payment_method'])) ?></span>
                </div>
                <?php endif; ?>
                <?php
                $wDays = intval($repair['warranty_days'] ?? 90);
                if ($wDays === 365)     $wLabel = '1 Year';
                elseif ($wDays === 180) $wLabel = '6 Months';
                elseif ($wDays > 0)    $wLabel = $wDays . ' Days';
                else                   $wLabel = 'No Warranty';
                ?>
                <div class="detail-item">
                    <span class="detail-label">Warranty</span>
                    <span class="detail-value" style="<?= $wDays === 0 ? 'color:var(--text-3)' : '' ?>">
                        <?= $wDays === 0 ? '—' : '🛡 ' . $wLabel ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Quote Approval -->
        <?php
        $qa = $repair['quote_approval'] ?? 'none';
        $canRequest = !in_array($repair['status'], ['completed','cancelled']) && $repair['estimated_cost'] !== null;
        ?>
        <div class="card" style="border-color:<?= $qa==='approved' ? 'var(--success)' : ($qa==='declined' ? 'var(--danger)' : ($qa==='pending' ? 'var(--amber)' : 'var(--border)')) ?>;">
            <div class="card-header">
                <h2 class="card-title">Customer Quote Approval</h2>
                <?php if ($qa === 'approved'): ?>
                <span style="background:#dcfce7;color:#166534;border-radius:20px;padding:.2rem .75rem;font-size:12px;font-weight:700;">✓ Approved</span>
                <?php elseif ($qa === 'declined'): ?>
                <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:.2rem .75rem;font-size:12px;font-weight:700;">✗ Declined</span>
                <?php elseif ($qa === 'pending'): ?>
                <span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:.2rem .75rem;font-size:12px;font-weight:700;">⏳ Awaiting Response</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($qa === 'approved'): ?>
                <p style="color:var(--success);font-weight:600;margin-bottom:.5rem;">
                    ✓ Customer approved the quote on <?= $repair['quote_approved_at'] ? date('M j, Y g:ia', strtotime($repair['quote_approved_at'])) : '—' ?>
                </p>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reset_approval">
                    <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Reset approval status?')">Reset</button>
                </form>

                <?php elseif ($qa === 'declined'): ?>
                <p style="color:var(--danger);font-weight:600;margin-bottom:.25rem;">✗ Customer declined the repair.</p>
                <?php if ($repair['quote_decline_reason']): ?>
                <p class="text-muted small" style="margin-bottom:.75rem;">Reason: <?= htmlspecialchars($repair['quote_decline_reason']) ?></p>
                <?php endif; ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reset_approval">
                    <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Reset approval status?')">Reset &amp; Re-send</button>
                </form>

                <?php elseif ($qa === 'pending'): ?>
                <p class="text-muted small" style="margin-bottom:.75rem;">
                    Approval request was sent. Waiting for the customer to tap the link and respond.
                    The customer's status page shows the quote and Approve / Decline buttons.
                </p>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="request_approval">
                    <button type="submit" class="btn btn-sm btn-secondary">Resend SMS</button>
                </form>
                <form method="POST" style="display:inline;margin-left:.5rem;">
                    <input type="hidden" name="action" value="reset_approval">
                    <button type="submit" class="btn btn-sm btn-secondary" style="color:var(--danger);" onclick="return confirm('Reset approval?')">Cancel Request</button>
                </form>

                <?php else: /* none */ ?>
                <?php if ($canRequest): ?>
                <p class="text-muted small" style="margin-bottom:.75rem;">
                    Send the customer an SMS with a link to review and approve the <strong>$<?= number_format((float)$repair['estimated_cost'], 2) ?></strong> quote directly from their phone.
                    They click Approve → repair moves to <em>In Repair</em> automatically.
                </p>
                <?php if (!$repair['sms_opt_out']): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="request_approval">
                    <button type="submit" class="btn btn-primary">📲 Send Quote for Approval</button>
                </form>
                <?php else: ?>
                <p class="text-muted small">SMS opt-out is enabled for this customer.</p>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted small">Set an estimated cost first, then you can send a quote approval request to the customer.</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Parts Used -->
        <div class="card" id="parts">
            <div class="card-header">
                <h2 class="card-title">Parts Used</h2>
                <?php if (!in_array($repair['status'], ['completed','cancelled'])): ?>
                <button class="btn btn-sm btn-secondary" onclick="togglePanel('add-part-panel')">+ Add Part</button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($parts)): ?>
                <p class="text-muted">No parts added yet.</p>
                <?php else: ?>
                <?php $canRemove = !in_array($repair['status'], ['completed','cancelled']); ?>
                <table class="data-table">
                    <?php $canSeeCost = Auth::isOwner() || Auth::isManager(); ?>
                    <thead>
                        <tr>
                            <th>Part</th><th>SKU</th><th>Qty</th>
                            <?php if ($canSeeCost): ?><th>Cost</th><?php endif; ?>
                            <th>Sell Price</th><th>Subtotal</th>
                            <?php if ($canRemove): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($parts as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['part_name']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($p['part_sku'] ?? '—') ?></td>
                        <td><?= $p['quantity'] ?></td>
                        <?php if ($canSeeCost): ?>
                        <td>
                            <?php if ($p['cost_price'] !== null): ?>
                                <!-- Cost set — show value + edit icon for owner/manager -->
                                <span style="display:flex;align-items:center;gap:.3rem;">
                                    <span style="color:var(--text-2);font-size:13px;" id="cost-val-<?= $p['id'] ?>">
                                        $<?= number_format($p['cost_price'], 2) ?>
                                    </span>
                                    <button type="button"
                                            onclick="toggleCostEdit(<?= $p['id'] ?>)"
                                            title="Edit cost"
                                            style="background:none;border:none;cursor:pointer;font-size:11px;color:var(--primary);padding:0;">✏️</button>
                                </span>
                                <form method="POST" id="cost-form-<?= $p['id'] ?>"
                                      style="display:none;gap:.3rem;align-items:center;margin-top:.2rem;">
                                    <input type="hidden" name="action"   value="update_part_cost">
                                    <input type="hidden" name="part_id"  value="<?= $p['id'] ?>">
                                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0"
                                           value="<?= number_format($p['cost_price'], 2) ?>"
                                           style="width:80px;height:28px;padding:.2rem .4rem;font-size:12px;" required>
                                    <button type="submit" class="btn btn-sm btn-primary"
                                            style="padding:.2rem .5rem;font-size:12px;">✓</button>
                                    <button type="button" class="btn btn-sm btn-ghost"
                                            onclick="toggleCostEdit(<?= $p['id'] ?>)"
                                            style="padding:.2rem .4rem;font-size:12px;">✕</button>
                                </form>
                            <?php else: ?>
                                <!-- No cost — always show input form -->
                                <form method="POST" style="display:flex;gap:.3rem;align-items:center;">
                                    <input type="hidden" name="action"   value="update_part_cost">
                                    <input type="hidden" name="part_id"  value="<?= $p['id'] ?>">
                                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0"
                                           style="width:80px;height:28px;padding:.2rem .4rem;font-size:12px;"
                                           placeholder="0.00" required>
                                    <button type="submit" class="btn btn-sm btn-secondary"
                                            style="padding:.2rem .5rem;font-size:12px;">✓</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>$<?= number_format($p['sell_price'], 2) ?></td>
                        <td>$<?= number_format($p['sell_price'] * $p['quantity'], 2) ?></td>
                        <?php if ($canRemove): ?>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($p['part_name'])) ?>?')">
                                <input type="hidden" name="action" value="remove_part">
                                <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:.2rem .6rem;font-size:12px;">✕</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:600;">
                        <td colspan="<?= ($canSeeCost ? 1 : 0) + ($canRemove ? 5 : 4) ?>" style="text-align:right;">Total Parts:</td>
                        <td>$<?= number_format(array_sum(array_map(fn($p) => $p['sell_price'] * $p['quantity'], $parts)), 2) ?></td>
                    </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- Add Part Panel -->
                <div id="add-part-panel" style="display:none;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_part">
                        <input type="hidden" name="inventory_item_id" id="inv_item_id" value="">

                        <!-- Inventory search -->
                        <div class="form-group" style="position:relative;">
                            <label class="form-label">Search Inventory</label>
                            <input type="text" id="inv_search" class="form-control"
                                   placeholder="Type to search inventory (or leave blank for custom part)..."
                                   autocomplete="off">
                            <div id="inv_results" style="display:none;position:absolute;z-index:200;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow);left:0;right:0;max-height:220px;overflow-y:auto;"></div>
                        </div>

                        <div class="form-row">
                            <div class="form-group" style="flex:2;">
                                <label class="form-label">Part Name <span class="required">*</span></label>
                                <input type="text" name="part_name" id="part_name" class="form-control" required placeholder="e.g. iPhone 14 Screen">
                            </div>
                            <div class="form-group">
                                <label class="form-label">SKU</label>
                                <input type="text" name="part_sku" id="part_sku" class="form-control" placeholder="Optional">
                            </div>
                            <div class="form-group" style="flex:0 0 80px;">
                                <label class="form-label">Qty</label>
                                <input type="number" name="quantity" class="form-control" value="1" min="1">
                            </div>
                        </div>
                        <div class="form-row">
                            <?php if (Auth::isOwner() || Auth::isManager()): ?>
                            <div class="form-group">
                                <label class="form-label">Cost Price ($)</label>
                                <input type="number" name="cost_price" id="part_cost" class="form-control" step="0.01" placeholder="What you paid">
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="cost_price" id="part_cost" value="">
                            <?php endif; ?>
                            <div class="form-group">
                                <label class="form-label">Sell Price ($) <span class="required">*</span></label>
                                <input type="number" name="sell_price" id="part_sell" class="form-control" step="0.01" value="0" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Add Part</button>
                        </div>
                        <div id="inv_stock_info" style="display:none;" class="alert alert-info" style="font-size:13px;"></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Notes</h2>
                <button class="btn btn-sm btn-secondary" onclick="togglePanel('add-note-panel')">+ Add Note</button>
            </div>
            <div class="card-body">
                <div id="add-note-panel" style="display:none;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border);">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_note">
                        <textarea name="note" class="form-control" rows="3" required placeholder="Add a note..."></textarea>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.5rem;">
                            <label class="form-check">
                                <input type="checkbox" name="is_internal" value="1" checked>
                                <span>Internal only (not shown on public status page)</span>
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm">Save Note</button>
                        </div>
                    </form>
                </div>

                <?php if (empty($notes)): ?>
                <p class="text-muted">No notes yet.</p>
                <?php else: ?>
                <?php foreach ($notes as $n): ?>
                <div class="note-item <?= $n['is_internal'] ? 'note-internal' : 'note-public' ?>">
                    <div class="note-meta">
                        <strong><?= htmlspecialchars($n['author']) ?></strong>
                        <span class="text-muted"><?= timeAgo($n['created_at']) ?></span>
                        <?php if ($n['is_internal']): ?>
                        <span class="badge badge-secondary" style="font-size:.65rem;">Internal</span>
                        <?php else: ?>
                        <span class="badge badge-info" style="font-size:.65rem;">Public</span>
                        <?php endif; ?>
                    </div>
                    <div class="note-body"><?= nl2br(htmlspecialchars($n['note'])) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.repair-col-main -->

    <!-- ── RIGHT COLUMN ──────────────────────────────────── -->
    <div class="repair-col-side">

        <!-- Change Status -->
        <?php if (!in_array($repair['status'], ['completed', 'cancelled']) || $repair['status'] === 'cancelled'): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Update Status</h2></div>
            <div class="card-body">
                <form method="POST" data-once="true">
                    <input type="hidden" name="action" value="change_status">
                    <div class="form-group">
                        <label class="form-label">New Status</label>
                        <select name="new_status" id="status_select" class="form-control" required onchange="onStatusChange(this.value)">
                            <option value="">— Select —</option>
                            <?php foreach ($nextStatuses[$repair['status']] ?? [] as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="nr_reason_group" style="display:none;">
                        <label class="form-label" style="color:var(--red);font-weight:700;">
                            ⚠ Non-Repairable Reason <span class="required">*</span>
                        </label>
                        <textarea name="status_note" id="status_note_nr" class="form-control" rows="2"
                                  placeholder="e.g. Board-level damage beyond repair, liquid damage, broken flex cable unrepairable..."></textarea>
                        <div class="form-hint">This reason will be saved on the ticket and sent to the customer via SMS.</div>
                    </div>
                    <div class="form-group" id="note_group">
                        <label class="form-label">Note (optional)</label>
                        <textarea name="status_note" id="status_note_normal" class="form-control" rows="2"
                                  placeholder="e.g. Waiting on screen from supplier..."></textarea>
                    </div>
                    <?php if (!$repair['sms_opt_out'] && !$training): ?>
                    <label class="form-check" style="margin-bottom:.75rem;">
                        <input type="checkbox" name="send_sms" value="1" checked>
                        <span>Send SMS update to customer</span>
                    </label>
                    <?php else: ?>
                    <p class="text-muted small" style="margin-bottom:.75rem;">
                        <?= $repair['sms_opt_out'] ? 'SMS updates disabled for this customer.' : 'SMS blocked in training mode.' ?>
                    </p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" style="width:100%;" data-sending="Updating…">Update Status</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Set Final Cost (any open ticket, before payment) -->
        <?php if (!in_array($repair['status'], ['completed', 'cancelled'])): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">💲 Set Final Cost</h2></div>
            <div class="card-body">
                <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="update_final_cost">
                    <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                        <label class="form-label">Final Cost ($)</label>
                        <input type="number" name="final_cost" class="form-control" step="0.01" min="0"
                               value="<?= htmlspecialchars($repair['final_cost'] ?? $repair['estimated_cost'] ?? '') ?>"
                               placeholder="0.00" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Save Cost</button>
                </form>
                <div class="form-hint" style="margin-top:.4rem;">Sets the final cost on the ticket. Collect payment separately when customer picks up.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Record Payment (when ready for pickup) -->
        <?php if (in_array($repair['status'], ['ready_pickup'])): ?>
        <?php
        $depositPaid   = (float)($repair['deposit_amount'] ?? 0);
        $estForPayment = (float)($repair['final_cost'] ?? $repair['estimated_cost'] ?? 0);
        $balanceDue    = max(0, $estForPayment - $depositPaid);
        ?>
        <div class="card" style="border-color:var(--success);">
            <div class="card-header" style="background:rgba(34,197,94,.08);">
                <h2 class="card-title" style="color:var(--success);">💰 Collect Payment & Close</h2>
            </div>
            <div class="card-body">
                <?php if ($depositPaid > 0): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:.6rem .9rem;margin-bottom:1rem;font-size:13px;color:#166534;">
                    Deposit on file: <strong>$<?= number_format($depositPaid, 2) ?></strong>
                    &nbsp;·&nbsp; Balance owing: <strong>$<?= number_format($balanceDue, 2) ?></strong>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    <div class="form-group">
                        <label class="form-label">Final Repair Cost ($)</label>
                        <input type="number" name="final_cost" class="form-control" step="0.01"
                               value="<?= $estForPayment ?>" required>
                        <div class="form-hint">Total job cost including any parts. Deposit will be deducted automatically.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Discount ($)</label>
                        <input type="number" name="discount_amount" class="form-control" step="0.01"
                               value="<?= $repair['discount_amount'] ?? '0' ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="">— Select —</option>
                            <option value="cash">Cash</option>
                            <option value="debit">Debit</option>
                            <option value="credit">Credit Card</option>
                            <option value="e_transfer">E-Transfer</option>
                            <option value="warranty">Warranty</option>
                            <option value="insurance">Insurance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <input type="text" name="payment_notes" class="form-control" placeholder="Optional">
                    </div>
                    <button type="submit" class="btn btn-success" style="width:100%;">✓ Mark Paid & Complete</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status Timeline -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Status Timeline</h2></div>
            <div class="card-body">
                <div class="timeline">
                <?php foreach ($statusHistory as $h): ?>
                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <div style="font-weight:600;">
                            <?= $statusLabels[$h['new_status']] ?? ucwords(str_replace('_',' ',$h['new_status'])) ?>
                        </div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($h['changed_by_name'] ?? 'System') ?> · <?= timeAgo($h['created_at']) ?>
                        </div>
                        <?php if ($h['notes']): ?>
                        <div class="text-muted small" style="margin-top:.2rem;"><?= htmlspecialchars($h['notes']) ?></div>
                        <?php endif; ?>
                        <?php if ($h['sms_sent']): ?>
                        <div class="small" style="color:var(--success);">📱 SMS sent</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- SMS Thread -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">SMS — <?= htmlspecialchars($repair['first_name']) ?></h2>
                <a href="<?= APP_URL ?>/modules/calls/sms.php?phone=<?= urlencode($repair['phone_normalized']) ?>&location=<?= $repair['location_id'] ?>"
                   class="btn btn-sm btn-secondary">Full Thread →</a>
            </div>
            <div class="card-body" style="padding-bottom:.5rem;">
                <!-- Message history -->
                <?php if (empty($smsMsgs)): ?>
                <p class="text-muted small" style="margin-bottom:.75rem;">No messages yet.</p>
                <?php else: ?>
                <div class="sms-mini-thread" style="max-height:180px;overflow-y:auto;margin-bottom:.75rem;">
                <?php foreach (array_reverse($smsMsgs) as $sms): ?>
                <div class="sms-bubble <?= $sms['direction'] === 'outbound' ? 'sms-out' : 'sms-in' ?>">
                    <div class="sms-text"><?= htmlspecialchars($sms['message']) ?></div>
                    <div class="sms-time"><?= timeAgo($sms['sent_at']) ?></div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Quick send -->
                <?php if (!$repair['sms_opt_out'] && !$training): ?>
                <form method="POST" id="quick-sms-form">
                    <input type="hidden" name="action" value="send_custom_sms">
                    <div style="display:flex;flex-direction:column;gap:.5rem;">
                        <!-- Quick-pick templates -->
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                            <?php
                            $name   = $repair['first_name'];
                            $device = trim(($repair['device_brand'] ?? '') . ' ' . ($repair['device_model'] ?? ''));
                            $ticket = $repair['record_number'];
                            $quickMsgs = [
                                'Ready ✓'   => "Hi {$name}, great news! Your {$device} is ready for pickup. Please come in at your earliest convenience. - C Tech Fix",
                                'Update'    => "Hi {$name}, just a quick update on your {$device} repair (#{$ticket}). We'll follow up shortly with more details. - C Tech Fix",
                                'Quote'     => "Hi {$name}, we've completed the diagnosis on your {$device}. Please call us to discuss the repair quote. - C Tech Fix",
                                'Parts ETA' => "Hi {$name}, we're waiting on a part for your {$device}. We'll contact you as soon as it arrives. - C Tech Fix",
                            ];
                            foreach ($quickMsgs as $label => $text): ?>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="document.getElementById('sms_body').value = <?= json_encode($text) ?>; updateCount();">
                                <?= htmlspecialchars($label) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <textarea id="sms_body" name="sms_body" class="form-control" rows="3"
                                  placeholder="Type a message to <?= htmlspecialchars($repair['first_name']) ?>…"
                                  maxlength="320" oninput="updateCount()"
                                  style="resize:vertical;font-size:13px;"></textarea>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span id="sms_count" style="font-size:12px;color:var(--text-3);">0 / 160</span>
                            <button type="submit" class="btn btn-primary btn-sm">📱 Send SMS</button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                <p class="text-muted small">
                    <?= $repair['sms_opt_out'] ? 'SMS disabled for this customer.' : 'SMS blocked in training mode.' ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Public Status Link -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Public Status Link</h2></div>
            <div class="card-body">
                <p class="text-muted small">Share this with the customer so they can check status anytime.</p>
                <div class="copy-box">
                    <input type="text" id="statusUrl" class="form-control"
                           value="<?= APP_URL ?>/repair-status.php?t=<?= $repair['status_token'] ?>"
                           readonly>
                    <button class="btn btn-sm btn-secondary" onclick="copyStatus()">Copy</button>
                </div>
            </div>
        </div>

    </div><!-- /.repair-col-side -->

</div><!-- /.repair-grid -->

<script>
function onStatusChange(val) {
    const nrGroup     = document.getElementById('nr_reason_group');
    const noteGroup   = document.getElementById('note_group');
    const nrTextarea  = document.getElementById('status_note_nr');
    const normTextarea= document.getElementById('status_note_normal');
    if (val === 'non_repairable') {
        nrGroup.style.display   = 'block';
        noteGroup.style.display = 'none';
        nrTextarea.required     = true;
        normTextarea.required   = false;
        normTextarea.name       = ''; // disable so only nr textarea submits
        nrTextarea.name         = 'status_note';
    } else {
        nrGroup.style.display   = 'none';
        noteGroup.style.display = 'block';
        nrTextarea.required     = false;
        normTextarea.required   = false;
        normTextarea.name       = 'status_note';
        nrTextarea.name         = '';
    }
}

function togglePanel(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleCostEdit(partId) {
    const form = document.getElementById('cost-form-' + partId);
    const val  = document.getElementById('cost-val-'  + partId);
    const open = form.style.display !== 'none' && form.style.display !== '';
    if (open) {
        form.style.display = 'none';
        val.closest('span').style.display = '';
    } else {
        form.style.display = 'flex';
        val.closest('span').style.display = 'none';
        form.querySelector('input[type=number]').focus();
    }
}
function copyStatus() {
    const el = document.getElementById('statusUrl');
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        const btn = el.nextElementSibling;
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}

function updateCount() {
    const ta  = document.getElementById('sms_body');
    const cnt = document.getElementById('sms_count');
    if (!ta || !cnt) return;
    const len = ta.value.length;
    const msgs = len <= 160 ? 1 : Math.ceil(len / 153);
    cnt.textContent = len + ' / 160' + (msgs > 1 ? ' (' + msgs + ' parts)' : '');
    cnt.style.color = len > 300 ? 'var(--red)' : len > 160 ? 'var(--amber)' : 'var(--text-3)';
}

// ── Inventory search for Add Part panel ─────────────────────
(function () {
    const searchInput = document.getElementById('inv_search');
    const resultsBox  = document.getElementById('inv_results');
    const hiddenId    = document.getElementById('inv_item_id');
    const nameField   = document.getElementById('part_name');
    const skuField    = document.getElementById('part_sku');
    const costField   = document.getElementById('part_cost');
    const sellField   = document.getElementById('part_sell');
    const stockInfo   = document.getElementById('inv_stock_info');

    if (!searchInput) return;

    let timer = null;

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 2) { resultsBox.style.display = 'none'; return; }
        timer = setTimeout(() => fetchInventory(q), 300);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { resultsBox.style.display = 'none'; }
    });

    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
            resultsBox.style.display = 'none';
        }
    });

    function fetchInventory(q) {
        const url = '?id=<?= $repairId ?>&lookup_inventory=' + encodeURIComponent(q);
        fetch(url)
            .then(r => r.json())
            .then(items => renderResults(items))
            .catch(() => {});
    }

    function renderResults(items) {
        resultsBox.innerHTML = '';
        if (!items.length) {
            resultsBox.innerHTML = '<div style="padding:.75rem 1rem;color:var(--text-3);font-size:13px;">No inventory parts found — you can still enter manually below.</div>';
            resultsBox.style.display = 'block';
            return;
        }
        items.forEach(item => {
            const div = document.createElement('div');
            div.style.cssText = 'padding:.6rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px;';
            div.innerHTML =
                '<strong>' + esc(item.name) + '</strong>' +
                (item.compatible_with ? ' <span style="color:var(--text-3);">(' + esc(item.compatible_with) + ')</span>' : '') +
                '<br><span style="color:var(--text-3);">' +
                (item.sku ? 'SKU: ' + esc(item.sku) + ' · ' : '') +
                'Cost $' + parseFloat(item.cost_price).toFixed(2) +
                ' · Sell $' + parseFloat(item.sell_price).toFixed(2) +
                ' · <span style="color:' + (item.stock > 0 ? 'var(--green)' : 'var(--red)') + ';">' +
                item.stock + ' in stock</span></span>';
            div.addEventListener('mouseenter', () => div.style.background = 'var(--surface-2)');
            div.addEventListener('mouseleave', () => div.style.background = '');
            div.addEventListener('click', () => selectItem(item));
            resultsBox.appendChild(div);
        });
        resultsBox.style.display = 'block';
    }

    function selectItem(item) {
        hiddenId.value  = item.id;
        nameField.value = item.name;
        skuField.value  = item.sku || '';
        costField.value = parseFloat(item.cost_price).toFixed(2);
        sellField.value = parseFloat(item.sell_price).toFixed(2);
        searchInput.value = item.name;
        resultsBox.style.display = 'none';
        if (item.stock <= 0) {
            stockInfo.textContent = '⚠ This item is currently out of stock at this location. Adding it will record a negative adjustment.';
            stockInfo.style.display = 'block';
        } else {
            stockInfo.textContent = '✓ ' + item.stock + ' in stock at this location.';
            stockInfo.style.display = 'block';
        }
    }

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
</script>

<?php require_once APP_ROOT . '/modules/layout/footer.php'; ?>
