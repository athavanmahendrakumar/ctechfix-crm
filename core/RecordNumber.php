<?php
// ============================================================
// Record Number Generator
// Produces: CTF-OS-R-2026-000001, CTF-PO-2026-000001, etc.
// ============================================================
class RecordNumber {

    private static array $typeMap = [
        'REPAIR'   => 'R',
        'SALE'     => 'S',
        'PO'       => 'PO',
        'TRANSFER' => 'TR',
        'ACT'      => 'ACT',
        'INC'      => 'INC',
        'CMP'      => 'CMP',
        'LOAN'     => 'LOAN',
    ];

    // Company-wide types (no location code)
    private static array $companyWide = ['PO', 'TRANSFER', 'ACT', 'INC', 'CMP', 'LOAN'];

    public static function next(string $type, ?string $locationCode = null): string {
        $type = strtoupper($type);
        $year = (int) date('Y');
        $isCompanyWide = in_array($type, self::$companyWide, true);
        $locCode = $isCompanyWide ? null : strtoupper($locationCode ?? '');

        $db = DB::get();
        $db->beginTransaction();

        try {
            // Lock the row for this type/location/year
            $row = $db->prepare(
                'SELECT id, last_seq FROM record_sequences
                  WHERE type = ? AND (location_code = ? OR (location_code IS NULL AND ? IS NULL)) AND year = ?
                  FOR UPDATE'
            );
            $row->execute([$type, $locCode, $locCode, $year]);
            $seq = $row->fetch(PDO::FETCH_ASSOC);

            if (!$seq) {
                // First record of this type/year — create the sequence
                $db->prepare(
                    'INSERT INTO record_sequences (type, location_code, year, last_seq) VALUES (?,?,?,1)'
                )->execute([$type, $locCode, $year]);
                $nextSeq = 1;
            } else {
                $nextSeq = $seq['last_seq'] + 1;
                $db->prepare(
                    'UPDATE record_sequences SET last_seq = ? WHERE id = ?'
                )->execute([$nextSeq, $seq['id']]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        // Build the record number string
        $abbr    = self::$typeMap[$type] ?? $type;
        $seqPad  = str_pad($nextSeq, 6, '0', STR_PAD_LEFT);

        if ($isCompanyWide) {
            // CTF-PO-2026-000001
            return "CTF-{$abbr}-{$year}-{$seqPad}";
        } else {
            // CTF-OS-R-2026-000001
            return "CTF-{$locCode}-{$abbr}-{$year}-{$seqPad}";
        }
    }
}
