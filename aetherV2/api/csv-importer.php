<?php
/**
 * Aether v2 — CSV Importer
 * Generates per-module sample CSVs, parses uploads, validates rows, builds
 * preview, and (on approval) bulk-inserts into the appropriate ERP table.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherCsvImporter
{
    /** Per-module CSV schema — columns, examples, and target table mapping. */
    private const SCHEMAS = [
        'donors' => [
            'table'   => 'donors',
            'columns' => [
                'name'        => ['required'=>true,  'example'=>'Jane Doe'],
                'email'       => ['required'=>false, 'example'=>'jane@example.com'],
                'phone'       => ['required'=>false, 'example'=>'9876543210'],
                'donor_type'  => ['required'=>false, 'example'=>'individual', 'allowed'=>['individual','corporate','trust']],
                'address'     => ['required'=>false, 'example'=>'12 Park St, Kolkata'],
                'pan'         => ['required'=>false, 'example'=>'ABCDE1234F'],
            ],
        ],
        'donations' => [
            'table'   => 'donations',
            'columns' => [
                'donor_name'    => ['required'=>true,  'example'=>'Jane Doe', 'note'=>'Donor will be auto-created if not present'],
                'amount'        => ['required'=>true,  'example'=>'5000', 'validate'=>'amount'],
                'donation_date' => ['required'=>true,  'example'=>'2026-01-15', 'validate'=>'date'],
                'payment_method'=> ['required'=>false, 'example'=>'upi', 'allowed'=>['upi','cash','cheque','online','bank']],
                'transaction_id'=> ['required'=>false, 'example'=>'TXN12345'],
            ],
        ],
        'expenses' => [
            'table'   => 'expenses',
            'columns' => [
                'expense_category' => ['required'=>true, 'example'=>'stationery'],
                'amount'           => ['required'=>true, 'example'=>'2500', 'validate'=>'amount'],
                'expense_date'     => ['required'=>true, 'example'=>'2026-01-15', 'validate'=>'date'],
                'description'      => ['required'=>false,'example'=>'Notebooks for outreach event'],
                'payment_method'   => ['required'=>false,'example'=>'upi'],
            ],
        ],
        'employees' => [
            'table'   => 'employees',
            'columns' => [
                'name'           => ['required'=>true,  'example'=>'Anita Sharma'],
                'employee_code'  => ['required'=>false, 'example'=>'EMP001'],
                'designation'    => ['required'=>false, 'example'=>'Program Officer'],
                'department'     => ['required'=>false, 'example'=>'Operations'],
                'email'          => ['required'=>false, 'example'=>'anita@dhrubfoundation.org'],
                'phone'          => ['required'=>false, 'example'=>'9876543210'],
                'basic_salary'   => ['required'=>false, 'example'=>'30000', 'validate'=>'amount'],
                'joining_date'   => ['required'=>false, 'example'=>'2025-04-01', 'validate'=>'date'],
            ],
        ],
        'volunteers' => [
            'table'   => 'volunteers',
            'columns' => [
                'name'         => ['required'=>true,  'example'=>'Ravi Kumar'],
                'email'        => ['required'=>false, 'example'=>'ravi@example.com'],
                'phone'        => ['required'=>false, 'example'=>'9876543210'],
                'designation'  => ['required'=>false, 'example'=>'Volunteer'],
                'department'   => ['required'=>false, 'example'=>'Outreach'],
            ],
        ],
        'inventory' => [
            'table'   => 'inventory_items',
            'columns' => [
                'item_name' => ['required'=>true,  'example'=>'A4 Notebooks'],
                'quantity'  => ['required'=>true,  'example'=>'100', 'validate'=>'number'],
                'unit'      => ['required'=>false, 'example'=>'pcs'],
                'category'  => ['required'=>false, 'example'=>'stationery'],
                'min_stock' => ['required'=>false, 'example'=>'20', 'validate'=>'number'],
            ],
        ],
        'programs' => [
            'table'   => 'programs',
            'columns' => [
                'program_name' => ['required'=>true,  'example'=>'Winter Outreach 2026'],
                'description'  => ['required'=>false, 'example'=>'Distribution of warm clothes'],
                'start_date'   => ['required'=>false, 'example'=>'2026-01-01', 'validate'=>'date'],
                'end_date'     => ['required'=>false, 'example'=>'2026-02-28', 'validate'=>'date'],
                'budget'       => ['required'=>false, 'example'=>'250000', 'validate'=>'amount'],
                'status'       => ['required'=>false, 'example'=>'planning', 'allowed'=>['planning','active','completed','on_hold']],
            ],
        ],
    ];

    public static function modules(): array { return array_keys(self::SCHEMAS); }
    public static function headers(string $module): array {
        return array_keys(self::SCHEMAS[$module]['columns'] ?? []);
    }

    /** Stream a sample CSV to the user (called by the API router). */
    public static function streamTemplate(string $module): void {
        if (!isset(self::SCHEMAS[$module])) aether_error('Unknown module', 400);
        $cols = self::SCHEMAS[$module]['columns'];

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="aether-' . $module . '-template.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array_keys($cols));
        // example row
        $row = []; foreach ($cols as $c => $meta) $row[] = $meta['example'] ?? '';
        fputcsv($fp, $row);
        // hint row (commented)
        $hint = []; foreach ($cols as $c => $meta) {
            $bits = [];
            if (!empty($meta['required'])) $bits[] = 'required';
            if (!empty($meta['validate']))  $bits[] = 'type:' . $meta['validate'];
            if (!empty($meta['allowed']))   $bits[] = 'oneof:' . implode('|', $meta['allowed']);
            if (!empty($meta['note']))      $bits[] = $meta['note'];
            $hint[] = $bits ? '# ' . implode(', ', $bits) : '';
        }
        fputcsv($fp, $hint);
        fclose($fp);
        exit;
    }

    /** First-stage chat handler: tell the user how to proceed for a module. */
    public static function onboard(array $user, string $module): array {
        if (!self::canImport($user, $module)) {
            return ['text' => "Your role can't bulk-import `$module`."];
        }
        if (!isset(self::SCHEMAS[$module])) {
            $opts = implode(' / ', self::modules());
            return ['text' => "Pick a module to import: $opts. Try *import csv for donors*."];
        }
        $cols = self::headers($module);
        $url = '/aetherV2/api/aether.php?action=csv_template&module=' . urlencode($module);
        $lines = [
            "**Bulk import for `$module`** — here's how it works:",
            "",
            "1. Download the template:  👉 [`download $module-template.csv`]($url)",
            "2. Fill in your rows (the second row is an example you can replace).",
            "3. Drop the file into the chat (📎 paperclip icon) and I'll preview, validate, and ask for your approval before inserting anything.",
            "",
            "**Required columns**",
            "`" . implode('` · `', $cols) . "`",
            "",
            "_All imports go through the plan-approve workflow — nothing is written without your green light._",
        ];
        return ['text' => implode("\n", $lines), 'cards' => [
            ['label'=>'Module','value'=>$module,'icon'=>'table'],
            ['label'=>'Columns','value'=>count($cols),'icon'=>'list'],
            ['label'=>'Mode','value'=>'plan-approve','icon'=>'shield-halved'],
        ]];
    }

    /** Parse + validate an uploaded CSV (base64 string). */
    public static function preview(array $user, string $module, string $base64, string $filename): array {
        if (!isset(self::SCHEMAS[$module])) return ['ok'=>false, 'error'=>'Unknown module'];
        if (!self::canImport($user, $module)) return ['ok'=>false, 'error'=>'Forbidden'];

        $bin = base64_decode($base64) ?: '';
        if (!$bin) return ['ok'=>false, 'error'=>'Empty file'];

        $tmp = tempnam(sys_get_temp_dir(), 'aev_csv_');
        file_put_contents($tmp, $bin);
        $fp = fopen($tmp, 'r');
        if (!$fp) { unlink($tmp); return ['ok'=>false, 'error'=>'Could not open file']; }

        $headerRow = fgetcsv($fp);
        if (!$headerRow) { fclose($fp); unlink($tmp); return ['ok'=>false,'error'=>'No headers']; }
        $headerRow = array_map(fn($h) => trim($h), $headerRow);

        $schema = self::SCHEMAS[$module];
        $expected = array_keys($schema['columns']);
        $unknown = array_diff($headerRow, $expected);
        $missingReq = [];
        foreach ($schema['columns'] as $col => $meta) {
            if (!empty($meta['required']) && !in_array($col, $headerRow, true)) $missingReq[] = $col;
        }

        $rowsAccepted = []; $rowsRejected = []; $rowNum = 1;
        while (($r = fgetcsv($fp)) !== false) {
            $rowNum++;
            // skip empty + hint rows
            $rowText = implode('', $r);
            if (trim($rowText) === '' || (isset($r[0]) && str_starts_with(trim($r[0]), '#'))) continue;

            $assoc = [];
            foreach ($headerRow as $i => $h) $assoc[$h] = $r[$i] ?? '';

            // validate
            $errs = [];
            foreach ($schema['columns'] as $col => $meta) {
                $v = trim($assoc[$col] ?? '');
                if (!empty($meta['required']) && $v === '') { $errs[] = "$col is required"; continue; }
                if ($v === '') continue;
                if (!empty($meta['validate'])) {
                    if ($meta['validate'] === 'amount' && !is_numeric(str_replace(',', '', $v))) $errs[] = "$col must be a number";
                    if ($meta['validate'] === 'number' && !ctype_digit(preg_replace('/[+-]/','',$v))) $errs[] = "$col must be an integer";
                    if ($meta['validate'] === 'date' && !strtotime($v)) $errs[] = "$col must be a valid date";
                }
                if (!empty($meta['allowed']) && !in_array(strtolower($v), array_map('strtolower', $meta['allowed']), true)) {
                    $errs[] = "$col must be one of " . implode('|', $meta['allowed']);
                }
            }
            if ($errs) $rowsRejected[] = ['row' => $rowNum, 'data' => $assoc, 'errors' => $errs];
            else       $rowsAccepted[] = $assoc;
        }
        fclose($fp); unlink($tmp);

        // Persist the import preview record
        $payload = ['accepted' => $rowsAccepted, 'rejected' => $rowsRejected, 'unknown_columns' => $unknown, 'missing_required' => $missingReq];
        $stmt = aether_db()->prepare(
            "INSERT INTO aether_csv_imports (user_id, module, filename, rows_total, rows_ok, rows_failed, payload_json, preview_json, errors_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'preview')"
        );
        $stmt->execute([
            $user['id'], $module, $filename,
            count($rowsAccepted) + count($rowsRejected),
            count($rowsAccepted),
            count($rowsRejected),
            json_encode($payload),
            json_encode(array_slice($rowsAccepted, 0, 5)),
            json_encode(array_slice($rowsRejected, 0, 5)),
        ]);
        $importId = (int)aether_db()->lastInsertId();
        AetherAudit::log('csv_preview', "CSV preview for $module ($filename)", ['ok'=>count($rowsAccepted),'failed'=>count($rowsRejected)], 'low', $user['id']);

        return [
            'ok' => true,
            'import_id'        => $importId,
            'module'           => $module,
            'rows_total'       => count($rowsAccepted) + count($rowsRejected),
            'rows_ok'          => count($rowsAccepted),
            'rows_failed'      => count($rowsRejected),
            'unknown_columns'  => array_values($unknown),
            'missing_required' => $missingReq,
            'sample_ok'        => array_slice($rowsAccepted, 0, 5),
            'sample_failed'    => array_slice($rowsRejected, 0, 5),
        ];
    }

    /** Execute a previously-previewed import. */
    public static function execute(array $user, int $importId): array {
        $db = aether_db();
        $row = $db->prepare("SELECT * FROM aether_csv_imports WHERE id = ? AND user_id = ?");
        $row->execute([$importId, $user['id']]);
        $r = $row->fetch();
        if (!$r) return ['ok'=>false,'error'=>'Import not found'];
        if ($r['status'] !== 'preview') return ['ok'=>false,'error'=>"Import already $r[status]"];

        $payload = json_decode($r['payload_json'], true) ?: [];
        $accepted = $payload['accepted'] ?? [];
        if (!$accepted) return ['ok'=>false,'error'=>'No valid rows to import'];

        $module = $r['module'];
        $schema = self::SCHEMAS[$module] ?? null;
        if (!$schema) return ['ok'=>false,'error'=>'Schema missing'];

        $inserted = 0; $errors = [];
        try {
            $db->beginTransaction();
            if ($module === 'donations') {
                $inserted = self::insertDonations($db, $accepted, $errors);
            } else {
                $inserted = self::insertGeneric($db, $schema['table'], $accepted, $errors);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok'=>false,'error'=>$e->getMessage()];
        }

        $db->prepare("UPDATE aether_csv_imports SET status='executed', executed_at=NOW() WHERE id = ?")->execute([$importId]);
        AetherAudit::log(
            'csv_executed',
            "Bulk-imported $inserted row(s) into $module",
            ['import_id'=>$importId, 'inserted'=>$inserted, 'errors'=>$errors],
            'medium', $user['id']
        );
        return ['ok'=>true, 'inserted'=>$inserted, 'errors'=>$errors];
    }

    private static function insertGeneric(PDO $db, string $table, array $rows, array &$errors): int {
        $count = 0;
        foreach ($rows as $i => $row) {
            try {
                $cols = array_keys($row);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($place)";
                $db->prepare($sql)->execute(array_values($row));
                $count++;
            } catch (\Throwable $e) {
                $errors[] = ['row_index'=>$i, 'error'=>$e->getMessage()];
            }
        }
        return $count;
    }

    private static function insertDonations(PDO $db, array $rows, array &$errors): int {
        $count = 0;
        $findDonor = $db->prepare("SELECT id FROM donors WHERE name = ? LIMIT 1");
        $createDonor = $db->prepare("INSERT INTO donors (name, donor_type) VALUES (?, 'individual')");
        $insert = $db->prepare("INSERT INTO donations (donation_code, donor_id, amount, donation_date, status, payment_method, transaction_id) VALUES (?, ?, ?, ?, 'completed', ?, ?)");
        foreach ($rows as $i => $row) {
            try {
                $name = trim($row['donor_name']);
                $findDonor->execute([$name]);
                $donorId = (int)$findDonor->fetchColumn();
                if (!$donorId) {
                    $createDonor->execute([$name]);
                    $donorId = (int)$db->lastInsertId();
                }
                $code = 'DON-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $insert->execute([
                    $code, $donorId,
                    (float)str_replace(',', '', $row['amount']),
                    date('Y-m-d', strtotime($row['donation_date'])),
                    $row['payment_method'] ?? null,
                    $row['transaction_id'] ?? null,
                ]);
                $count++;
            } catch (\Throwable $e) {
                $errors[] = ['row_index'=>$i, 'error'=>$e->getMessage()];
            }
        }
        return $count;
    }

    private static function canImport(array $user, string $module): bool {
        $role = $user['role'] ?? '';
        if (in_array($role, ['super_admin','admin'], true)) return true;
        $roleMap = [
            'donors'      => ['manager','accountant','editor'],
            'donations'   => ['manager','accountant','editor'],
            'expenses'    => ['manager','accountant'],
            'employees'   => ['hr'],
            'volunteers'  => ['manager','editor','hr'],
            'inventory'   => ['manager','editor'],
            'programs'    => ['manager','editor'],
        ];
        return in_array($role, $roleMap[$module] ?? [], true);
    }
}
