<?php
// ============================================================
// Audit Log — records every critical action
// ============================================================
class Audit {

    public static function log(
        string $action,
        string $module,
        ?int   $recordId  = null,
        mixed  $oldValue  = null,
        mixed  $newValue  = null,
        ?string $notes    = null
    ): void {
        $userId     = $_SESSION['user_id']     ?? null;
        $locationId = $_SESSION['location_id'] ?? null;
        $isTraining = $_SESSION['is_training'] ?? 0;
        $ip         = $_SERVER['REMOTE_ADDR']  ?? 'unknown';

        DB::execute(
            'INSERT INTO audit_log
             (user_id, location_id, action, module, record_id, old_value, new_value, notes, ip_address, is_training)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $userId,
                $locationId,
                $action,
                $module,
                $recordId,
                $oldValue !== null ? json_encode($oldValue) : null,
                $newValue !== null ? json_encode($newValue) : null,
                $notes,
                $ip,
                $isTraining ? 1 : 0,
            ]
        );
    }
}
