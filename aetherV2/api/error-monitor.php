<?php
/**
 * Aether v2 — Error Monitor + Self-Healer
 * Continuously scans the ERP for data inconsistencies, calculation errors,
 * workflow failures, and broken dependencies. Records issues and (optionally)
 * applies safe auto-fixes. Every detection and every fix is audited.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherErrorMonitor
{
    private PDO $db;

    /** Registered checks: each returns ['status'=>ok|warn|fail, 'detail'=>'…', 'issues'=>[…]] */
    private array $checks;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: aether_db();
        $this->checks = $this->buildRegistry();
        $this->ensureRegistered();
    }

    private function buildRegistry(): array {
        return [
            // ── Referential integrity ───────────────────────────────────
            'orphan_donations' => [
                'category' => 'integrity', 'severity' => 'high', 'auto_heal' => 0,
                'title'    => 'Donations without a valid donor',
                'run'      => fn() => $this->checkOrphans('donations', 'donor_id', 'donors', 'id'),
            ],
            'orphan_payroll' => [
                'category' => 'integrity', 'severity' => 'high', 'auto_heal' => 0,
                'title'    => 'Payroll rows without a valid employee',
                'run'      => fn() => $this->checkOrphans('payroll', 'employee_id', 'employees', 'id'),
            ],

            // ── Negative / impossible values ────────────────────────────
            'negative_donations' => [
                'category' => 'data', 'severity' => 'high', 'auto_heal' => 0,
                'title'    => 'Negative donation amounts',
                'run'      => fn() => $this->checkScalar('donations', 'amount < 0', 'amount'),
            ],
            'negative_expenses' => [
                'category' => 'data', 'severity' => 'high', 'auto_heal' => 0,
                'title'    => 'Negative expense amounts',
                'run'      => fn() => $this->checkScalar('expenses', 'amount < 0', 'amount'),
            ],
            'negative_inventory' => [
                'category' => 'data', 'severity' => 'medium', 'auto_heal' => 1,
                'title'    => 'Inventory with negative quantity',
                'run'      => fn() => $this->checkScalar('inventory_items', 'quantity < 0', 'quantity'),
                'heal'     => fn() => $this->healNegativeInventory(),
            ],

            // ── Calculation errors ──────────────────────────────────────
            'employee_salary_mismatch' => [
                'category' => 'calc', 'severity' => 'medium', 'auto_heal' => 1,
                'title'    => 'Employees with stale net_salary calculation',
                'run'      => fn() => $this->checkSalaryMismatch(),
                'heal'     => fn() => $this->healSalaryMismatch(),
            ],

            // ── Inventory thresholds ────────────────────────────────────
            'low_stock_alerts' => [
                'category' => 'workflow', 'severity' => 'low', 'auto_heal' => 0,
                'title'    => 'Inventory items below minimum stock',
                'run'      => fn() => $this->checkLowStock(),
            ],

            // ── User accounts ───────────────────────────────────────────
            'inactive_admins' => [
                'category' => 'security', 'severity' => 'high', 'auto_heal' => 0,
                'title'    => 'No active admin user',
                'run'      => fn() => $this->checkAdminPresence(),
            ],
            'duplicate_user_emails' => [
                'category' => 'data', 'severity' => 'medium', 'auto_heal' => 0,
                'title'    => 'Duplicate email addresses across users',
                'run'      => fn() => $this->checkDuplicates('users', 'email'),
            ],

            // ── Workflow ────────────────────────────────────────────────
            'pending_actions' => [
                'category' => 'workflow', 'severity' => 'low', 'auto_heal' => 0,
                'title'    => 'Long-pending action plans',
                'run'      => fn() => $this->checkStalePlans(),
            ],
        ];
    }

    private function ensureRegistered(): void {
        $stmt = $this->db->prepare(
            "INSERT INTO aether_health_checks
             (check_code, category, severity, title, description, auto_heal, enabled)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                category=VALUES(category), severity=VALUES(severity),
                title=VALUES(title), auto_heal=VALUES(auto_heal)"
        );
        foreach ($this->checks as $code => $def) {
            $stmt->execute([
                $code, $def['category'], $def['severity'],
                $def['title'], '', $def['auto_heal'],
            ]);
        }
    }

    public function runAll(bool $autoHeal = false): array {
        $results = [];
        $totalIssues = 0;
        $totalHealed = 0;
        $worst = 'ok';

        foreach ($this->checks as $code => $def) {
            try {
                $r = ($def['run'])();
            } catch (\Throwable $e) {
                $r = ['status' => 'unknown', 'detail' => 'Check error: ' . $e->getMessage(), 'issues' => []];
            }
            $r['code'] = $code;
            $r['title'] = $def['title'];
            $r['severity'] = $def['severity'];
            $r['category'] = $def['category'];
            $r['auto_heal'] = (bool)$def['auto_heal'];

            // Persist issues
            $this->persistIssues($code, $def['severity'], $def['title'], $r['issues'] ?? []);

            if (($r['status'] ?? 'ok') !== 'ok' && $autoHeal && !empty($def['heal'])) {
                try {
                    $healResult = ($def['heal'])();
                    $r['healed'] = $healResult;
                    $totalHealed += (int)($healResult['fixed'] ?? 0);
                    AetherAudit::log(
                        'self_heal',
                        "Healed {$healResult['fixed']} issue(s) in {$def['title']}",
                        $healResult, 'medium'
                    );
                    $r = array_merge($r, ($def['run'])());
                } catch (\Throwable $e) {
                    $r['heal_error'] = $e->getMessage();
                }
            }

            // Update registry row
            $this->db->prepare(
                "UPDATE aether_health_checks
                 SET last_status=?, last_findings=?, last_detail=?, last_run_at=NOW()
                 WHERE check_code=?"
            )->execute([
                $r['status'] ?? 'unknown',
                count($r['issues'] ?? []),
                mb_substr($r['detail'] ?? '', 0, 500),
                $code,
            ]);

            $totalIssues += count($r['issues'] ?? []);
            if ($r['status'] === 'fail') $worst = 'fail';
            elseif ($r['status'] === 'warn' && $worst !== 'fail') $worst = 'warn';

            $results[] = $r;
        }

        AetherAudit::log(
            'health_run',
            "Ran " . count($results) . " checks: $totalIssues issue(s), $totalHealed healed",
            ['overall' => $worst, 'issues' => $totalIssues, 'healed' => $totalHealed],
            $worst === 'fail' ? 'high' : ($worst === 'warn' ? 'medium' : 'info')
        );

        $openIssues = (int)$this->db->query("SELECT COUNT(*) FROM aether_issues WHERE status='open'")->fetchColumn();

        return [
            'overall'      => $worst,
            'checks'       => $results,
            'issue_count'  => $openIssues,
            'healed_count' => $totalHealed,
        ];
    }

    public function listIssues(string $status = 'open', int $limit = 100): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM aether_issues WHERE status = ?
             ORDER BY FIELD(severity,'critical','high','medium','low','info') ASC, id DESC
             LIMIT " . max(1, min($limit, 500))
        );
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =====================================================================
     *  Check implementations
     * ===================================================================== */

    private function tableExists(string $t): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $stmt->execute([AETHER_DB_NAME, $t]);
        return (bool)$stmt->fetchColumn();
    }
    private function columnExists(string $t, string $c): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute([AETHER_DB_NAME, $t, $c]);
        return (bool)$stmt->fetchColumn();
    }

    private function checkOrphans(string $table, string $col, string $refTable, string $refCol): array {
        if (!$this->tableExists($table) || !$this->tableExists($refTable) || !$this->columnExists($table, $col)) {
            return ['status' => 'ok', 'detail' => "Table $table or reference missing — skipped.", 'issues' => []];
        }
        $sql = "SELECT t.id FROM `$table` t LEFT JOIN `$refTable` r ON r.`$refCol` = t.`$col`
                WHERE t.`$col` IS NOT NULL AND r.`$refCol` IS NULL LIMIT 50";
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $issues = array_map(fn($r) => [
            'entity_type' => $table, 'entity_id' => (string)$r['id'],
            'detail' => "Row id={$r['id']} references missing $refTable.$refCol",
        ], $rows);
        return [
            'status' => empty($rows) ? 'ok' : 'fail',
            'detail' => empty($rows) ? "All $table.$col references valid." : count($rows) . " orphan row(s).",
            'issues' => $issues,
        ];
    }

    private function checkScalar(string $table, string $where, string $field): array {
        if (!$this->tableExists($table)) return ['status' => 'ok', 'detail' => 'Table missing — skipped.', 'issues' => []];
        $rows = $this->db->query("SELECT id, `$field` FROM `$table` WHERE $where LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $issues = array_map(fn($r) => [
            'entity_type' => $table, 'entity_id' => (string)$r['id'],
            'detail' => "Row id={$r['id']} has invalid $field=" . $r[$field],
        ], $rows);
        return [
            'status' => empty($rows) ? 'ok' : 'warn',
            'detail' => empty($rows) ? "No rows match `$where`." : count($rows) . " row(s) match `$where`.",
            'issues' => $issues,
        ];
    }

    private function checkSalaryMismatch(): array {
        if (!$this->tableExists('employees')) return ['status' => 'ok', 'detail' => 'No employees table.', 'issues' => []];
        $rows = $this->db->query(
            "SELECT id, name, basic_salary, hra, da, travel_allowance, medical_allowance, special_allowance, other_allowances,
                    pf_deduction, esi_deduction, tds_deduction, professional_tax, other_deductions, net_salary
             FROM employees WHERE basic_salary IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC);
        $issues = [];
        foreach ($rows as $r) {
            $earn = (float)$r['basic_salary'] + (float)$r['hra'] + (float)$r['da']
                  + (float)$r['travel_allowance'] + (float)$r['medical_allowance']
                  + (float)$r['special_allowance'] + (float)$r['other_allowances'];
            $ded  = (float)$r['pf_deduction'] + (float)$r['esi_deduction']
                  + (float)$r['tds_deduction'] + (float)$r['professional_tax']
                  + (float)$r['other_deductions'];
            $expected = round($earn - $ded, 2);
            $actual = round((float)$r['net_salary'], 2);
            if (abs($expected - $actual) > 0.01) {
                $issues[] = [
                    'entity_type' => 'employees', 'entity_id' => (string)$r['id'],
                    'detail' => "{$r['name']}: stored net_salary={$actual}, computed={$expected}",
                ];
            }
        }
        return [
            'status' => empty($issues) ? 'ok' : 'warn',
            'detail' => empty($issues) ? 'All net_salary values consistent.' : count($issues) . " mismatch(es).",
            'issues' => $issues,
        ];
    }

    private function healSalaryMismatch(): array {
        if (!$this->tableExists('employees')) return ['fixed' => 0];
        $rows = $this->db->query("SELECT id, basic_salary, hra, da, travel_allowance, medical_allowance, special_allowance, other_allowances,
                                  pf_deduction, esi_deduction, tds_deduction, professional_tax, other_deductions, net_salary
                                  FROM employees WHERE basic_salary IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $this->db->prepare("UPDATE employees SET net_salary = ? WHERE id = ?");
        $fixed = 0;
        foreach ($rows as $r) {
            $expected = round(
                (float)$r['basic_salary'] + (float)$r['hra'] + (float)$r['da']
                + (float)$r['travel_allowance'] + (float)$r['medical_allowance']
                + (float)$r['special_allowance'] + (float)$r['other_allowances']
                - (float)$r['pf_deduction'] - (float)$r['esi_deduction']
                - (float)$r['tds_deduction'] - (float)$r['professional_tax']
                - (float)$r['other_deductions'], 2
            );
            if (abs($expected - (float)$r['net_salary']) > 0.01) {
                $upd->execute([$expected, $r['id']]);
                $fixed++;
            }
        }
        // mark related issues healed
        $this->db->prepare("UPDATE aether_issues SET status='healed', healed_at=NOW(), healed_by='auto' WHERE check_code='employee_salary_mismatch' AND status='open'")->execute();
        return ['fixed' => $fixed];
    }

    private function healNegativeInventory(): array {
        $upd = $this->db->prepare("UPDATE inventory_items SET quantity = 0 WHERE quantity < 0");
        $upd->execute();
        $fixed = $upd->rowCount();
        $this->db->prepare("UPDATE aether_issues SET status='healed', healed_at=NOW(), healed_by='auto' WHERE check_code='negative_inventory' AND status='open'")->execute();
        return ['fixed' => $fixed];
    }

    private function checkLowStock(): array {
        if (!$this->tableExists('inventory_items')) return ['status' => 'ok', 'detail' => 'No inventory.', 'issues' => []];
        $rows = $this->db->query("SELECT id, item_name, quantity, min_stock FROM inventory_items WHERE min_stock > 0 AND quantity < min_stock LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $issues = array_map(fn($r) => [
            'entity_type' => 'inventory_items', 'entity_id' => (string)$r['id'],
            'detail' => "{$r['item_name']}: {$r['quantity']} below minimum {$r['min_stock']}",
        ], $rows);
        return [
            'status' => empty($rows) ? 'ok' : 'warn',
            'detail' => empty($rows) ? 'Inventory levels healthy.' : count($rows) . ' item(s) low.',
            'issues' => $issues,
        ];
    }

    private function checkAdminPresence(): array {
        $cnt = (int)$this->db->query(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name IN ('super_admin','admin') AND u.is_active=1"
        )->fetchColumn();
        return [
            'status' => $cnt > 0 ? 'ok' : 'fail',
            'detail' => $cnt > 0 ? "$cnt active admin user(s)." : 'No active admin user!',
            'issues' => $cnt > 0 ? [] : [['entity_type' => 'users', 'entity_id' => '0', 'detail' => 'No active admin']],
        ];
    }

    private function checkDuplicates(string $table, string $col): array {
        if (!$this->tableExists($table) || !$this->columnExists($table, $col)) return ['status' => 'ok', 'detail' => 'Skipped.', 'issues' => []];
        $rows = $this->db->query("SELECT `$col` v, COUNT(*) c FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> '' GROUP BY `$col` HAVING c > 1 LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $issues = array_map(fn($r) => [
            'entity_type' => $table, 'entity_id' => $r['v'],
            'detail' => "Duplicate $col `{$r['v']}` appears {$r['c']} times",
        ], $rows);
        return [
            'status' => empty($rows) ? 'ok' : 'warn',
            'detail' => empty($rows) ? "No duplicate $col." : count($rows) . " duplicate(s).",
            'issues' => $issues,
        ];
    }

    private function checkStalePlans(): array {
        $rows = $this->db->query("SELECT id, intent, preview, created_at FROM aether_action_plans WHERE status='proposed' AND created_at < NOW() - INTERVAL 24 HOUR LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $issues = array_map(fn($r) => [
            'entity_type' => 'aether_action_plans', 'entity_id' => (string)$r['id'],
            'detail' => "Plan #{$r['id']} ({$r['intent']}) pending since {$r['created_at']}",
        ], $rows);
        return [
            'status' => empty($rows) ? 'ok' : 'warn',
            'detail' => empty($rows) ? 'No stale plans.' : count($rows) . ' plan(s) >24h old.',
            'issues' => $issues,
        ];
    }

    private function persistIssues(string $code, string $sev, string $title, array $issues): void {
        if (empty($issues)) {
            // close any previously-open issues for this check
            $this->db->prepare("UPDATE aether_issues SET status='closed', updated_at=NOW() WHERE check_code=? AND status='open'")->execute([$code]);
            return;
        }
        // Wipe stale opens, then insert fresh
        $this->db->prepare("UPDATE aether_issues SET status='closed', updated_at=NOW() WHERE check_code=? AND status='open'")->execute([$code]);
        $ins = $this->db->prepare(
            "INSERT INTO aether_issues (check_code, entity_type, entity_id, severity, title, detail, status)
             VALUES (?, ?, ?, ?, ?, ?, 'open')"
        );
        foreach ($issues as $i) {
            $ins->execute([
                $code,
                $i['entity_type'] ?? null,
                $i['entity_id']   ?? null,
                $sev,
                $title,
                mb_substr($i['detail'] ?? '', 0, 65000),
            ]);
        }
    }
}
