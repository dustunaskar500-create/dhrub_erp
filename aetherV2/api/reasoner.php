<?php
/**
 * Aether v2 — Reasoner / Action Planner
 *
 * Converts the (intent, entities, knowledge-graph hits) tuple from the NLP
 * engine into either:
 *   • A natural-language answer (read intents)
 *   • A structured *action plan* with preview + confirmation step (write intents)
 *
 * Write actions are NEVER executed silently — they're stored as proposed plans
 * in `aether_action_plans` and require explicit approval through the API.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/nlp-engine.php';
require_once __DIR__ . '/audit-log.php';

class AetherReasoner
{
    private PDO $db;
    private array $user;

    public function __construct(array $user, ?PDO $db = null) {
        $this->db = $db ?: aether_db();
        $this->user = $user;
    }

    public function reason(string $message): array {
        $nlp = new AetherNLP($this->db);
        $analysis = $nlp->analyze($message);
        $intent = $analysis['intent'];
        $confidence = $analysis['confidence'];
        $entities = $analysis['entities'];

        // Dispatch
        $reply = match ($intent) {
            'greeting'         => $this->reGreeting(),
            'help'             => $this->reHelp(),
            'dashboard'        => $this->reDashboard(),
            'health_status'    => $this->reHealth(),
            'list_donations'   => $this->reListDonations($entities),
            'list_donors'      => $this->reListDonors($entities),
            'list_expenses'    => $this->reListExpenses($entities),
            'list_employees'   => $this->reListEmployees($entities),
            'list_inventory'   => $this->reListInventory($entities),
            'low_stock'        => $this->reLowStock(),
            'top_donors'       => $this->reTopDonors($entities),
            'forecast'         => $this->reForecast($entities),
            'schema_info'      => $this->reSchemaInfo($message, $analysis),
            'audit_recent'     => $this->reAuditRecent(),
            'record_donation'  => $this->planDonation($entities, $message),
            'create_expense'   => $this->planExpense($entities, $message),
            'update_salary'    => $this->planSalaryUpdate($entities, $message),
            default            => $this->fallback($message, $analysis),
        };

        // Track the conversation outcome for the learning engine
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO aether_learning (user_id, phrase, intent, confidence, outcome)
                 VALUES (?, ?, ?, ?, 'unknown')"
            );
            $stmt->execute([$this->user['id'] ?? 0, mb_substr($message, 0, 500), $intent, $confidence]);
        } catch (\Throwable $e) {}

        return [
            'intent'      => $intent,
            'confidence'  => $confidence,
            'entities'    => $entities,
            'reply'       => $reply['text'],
            'cards'       => $reply['cards'] ?? [],
            'plan'        => $reply['plan'] ?? null,
            'mode'        => $reply['mode'] ?? 'answer', // answer|plan|action
            'kg_matches'  => $analysis['kg_matches'] ?? [],
        ];
    }

    /* =====================================================================
     *  READ HANDLERS
     * ===================================================================== */

    private function reGreeting(): array {
        $hour = (int)date('H');
        $part = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        $name = $this->user['full_name'] ?? $this->user['username'] ?? 'there';
        return ['text' => "Good $part, **$name**. I'm Aether — your ERP's autonomous brain. Ask me about donations, expenses, employees, inventory, schema, health checks, or just say *help*."];
    }

    private function reHelp(): array {
        $lines = [
            "**Things I can do right now:**",
            "• Show dashboards, top donors, expense summaries, low-stock items",
            "• Forecast donation trends from your historical data",
            "• Describe any ERP table or column (auto-synced with the live schema)",
            "• Run health checks, surface issues, auto-heal minor data inconsistencies",
            "• Plan write-actions (record donation, update salary, log expense) with a preview step",
            "• Show audit trail of every decision and change I've made",
            "",
            "_All reasoning runs locally inside your ERP — zero external calls._",
        ];
        return ['text' => implode("\n", $lines)];
    }

    private function reDashboard(): array {
        $db = $this->db;
        $stats = [
            'donations_total' => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM donations WHERE status IN ('completed','received','approved') OR status IS NULL")->fetchColumn(),
            'donations_count' => (int)$db->query("SELECT COUNT(*) FROM donations")->fetchColumn(),
            'donors'          => (int)$db->query("SELECT COUNT(*) FROM donors")->fetchColumn(),
            'expenses_total'  => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn(),
            'employees'       => (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn(),
            'volunteers'      => (int)$db->query("SELECT COUNT(*) FROM volunteers")->fetchColumn(),
            'inventory_items' => (int)$db->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn(),
            'projects'        => (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
        ];
        $cards = [
            ['label' => 'Total Donations',   'value' => '₹' . number_format($stats['donations_total'], 2), 'icon' => 'hand-holding-heart'],
            ['label' => 'Donations Recorded','value' => $stats['donations_count'],                            'icon' => 'receipt'],
            ['label' => 'Donors',            'value' => $stats['donors'],                                    'icon' => 'users'],
            ['label' => 'Expenses',          'value' => '₹' . number_format($stats['expenses_total'], 2),  'icon' => 'wallet'],
            ['label' => 'Employees',         'value' => $stats['employees'],                                 'icon' => 'user-tie'],
            ['label' => 'Volunteers',        'value' => $stats['volunteers'],                                'icon' => 'people-group'],
            ['label' => 'Inventory Items',   'value' => $stats['inventory_items'],                           'icon' => 'box'],
            ['label' => 'Projects',          'value' => $stats['projects'],                                  'icon' => 'diagram-project'],
        ];
        $text = "Here's the live ERP snapshot — pulled directly from your database.";
        return ['text' => $text, 'cards' => $cards];
    }

    private function reHealth(): array {
        require_once __DIR__ . '/error-monitor.php';
        $monitor = new AetherErrorMonitor($this->db);
        $report = $monitor->runAll(false);
        $lines = ["**System health: " . strtoupper($report['overall']) . "**"];
        foreach ($report['checks'] as $c) {
            $emoji = $c['status'] === 'ok' ? '✓' : ($c['status'] === 'warn' ? '!' : '✗');
            $lines[] = "$emoji  *{$c['title']}* — {$c['detail']}";
        }
        if (!empty($report['issues'])) {
            $lines[] = "\n_{$report['issue_count']} open issue(s) tracked. Ask me to *self-heal* to attempt fixes._";
        } else {
            $lines[] = "\nAll systems nominal. No open issues.";
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reListDonations(array $entities): array {
        $stmt = $this->db->query(
            "SELECT d.donation_code, d.amount, d.donation_date, COALESCE(dn.name,'-') donor
             FROM donations d LEFT JOIN donors dn ON dn.id = d.donor_id
             ORDER BY d.id DESC LIMIT 10"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No donations recorded yet.'];
        $lines = ["**Last 10 donations:**"];
        foreach ($rows as $r) {
            $lines[] = "• `{$r['donation_code']}` — ₹" . number_format((float)$r['amount'], 2) . " from *{$r['donor']}* ({$r['donation_date']})";
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reListDonors(array $entities): array {
        $rows = $this->db->query("SELECT id, name, email, phone FROM donors ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No donors found.'];
        $lines = ['**Recent donors:**'];
        foreach ($rows as $r) $lines[] = "• {$r['name']} — {$r['email']} / {$r['phone']}";
        return ['text' => implode("\n", $lines)];
    }

    private function reListExpenses(array $entities): array {
        $rows = $this->db->query("SELECT expense_category, amount, expense_date, description FROM expenses ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No expenses recorded.'];
        $lines = ['**Recent expenses:**'];
        foreach ($rows as $r) {
            $lines[] = "• [{$r['expense_category']}] ₹" . number_format((float)$r['amount'], 2) . " on {$r['expense_date']}" .
                ($r['description'] ? " — {$r['description']}" : '');
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reListEmployees(array $entities): array {
        $rows = $this->db->query("SELECT name, designation, department, net_salary FROM employees WHERE status='active' OR status IS NULL ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No active employees found.'];
        $lines = ['**Active employees:**'];
        foreach ($rows as $r) {
            $sal = (float)($r['net_salary'] ?? 0);
            $lines[] = "• {$r['name']} — {$r['designation']} ({$r['department']}) · ₹" . number_format($sal, 2);
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reListInventory(array $entities): array {
        $rows = $this->db->query("SELECT item_name, quantity, unit, min_stock FROM inventory_items ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No inventory items.'];
        $lines = ['**Inventory:**'];
        foreach ($rows as $r) {
            $low = ($r['min_stock'] && $r['quantity'] !== null && (int)$r['quantity'] < (int)$r['min_stock']) ? '  ⚠ low' : '';
            $lines[] = "• {$r['item_name']}: {$r['quantity']} {$r['unit']}$low";
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reLowStock(): array {
        $rows = $this->db->query("SELECT item_name, quantity, unit, min_stock FROM inventory_items WHERE min_stock > 0 AND quantity < min_stock ORDER BY (min_stock - quantity) DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => '✓ No low-stock items. Inventory is healthy.'];
        $lines = ["**" . count($rows) . " item(s) below minimum stock:**"];
        foreach ($rows as $r) {
            $lines[] = "• {$r['item_name']} — {$r['quantity']} / {$r['min_stock']} {$r['unit']}";
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reTopDonors(array $entities): array {
        $rows = $this->db->query(
            "SELECT dn.name, COUNT(d.id) AS donations, SUM(d.amount) AS total
             FROM donations d JOIN donors dn ON dn.id = d.donor_id
             GROUP BY dn.id ORDER BY total DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['text' => 'No donations to rank yet.'];
        $lines = ['**Top 10 donors by total contribution:**'];
        $rank = 1;
        foreach ($rows as $r) {
            $lines[] = "{$rank}. **{$r['name']}** — ₹" . number_format((float)$r['total'], 2) . " across {$r['donations']} donation(s)";
            $rank++;
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reForecast(array $entities): array {
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(donation_date, '%Y-%m') ym, SUM(amount) total
             FROM donations
             WHERE donation_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
             GROUP BY ym ORDER BY ym ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < 2) return ['text' => 'Not enough history to forecast yet (need ≥ 2 months of donation data).'];
        // simple linear regression on month index
        $n = count($rows);
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        foreach ($rows as $i => $r) {
            $x = $i; $y = (float)$r['total'];
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / max(1, ($n * $sumX2 - $sumX * $sumX));
        $intercept = ($sumY - $slope * $sumX) / $n;
        $next = $intercept + $slope * $n;
        $next = max(0, $next);

        $hist = [];
        foreach ($rows as $r) $hist[] = "  • {$r['ym']}: ₹" . number_format((float)$r['total'], 2);
        $forecastMonth = date('Y-m', strtotime('first day of next month'));
        $text = "**Donation forecast** (linear regression on the last $n months)\n\n" .
                "Historical:\n" . implode("\n", $hist) .
                "\n\n_Projected for {$forecastMonth}_: **₹" . number_format($next, 2) . "**" .
                ($slope > 0 ? "  ↑ trending up" : ($slope < 0 ? "  ↓ trending down" : "  → flat trend"));
        return ['text' => $text];
    }

    private function reSchemaInfo(string $msg, array $analysis): array {
        // pick the most specific table mentioned
        $kg = $analysis['kg_matches'] ?? [];
        $table = null;
        foreach ($kg as $m) {
            if ($m['entity_type'] === 'table') { $table = $m['entity_name']; break; }
        }
        if (!$table) {
            return ['text' => 'Tell me which table — e.g. "describe donations" or "fields in employees".'];
        }
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([AETHER_DB_NAME, $table]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lines = ["**Schema of `$table`:**"];
        foreach ($cols as $c) {
            $key = $c['COLUMN_KEY'] === 'PRI' ? '🔑 ' : ($c['COLUMN_KEY'] === 'MUL' ? '🔗 ' : '');
            $null = $c['IS_NULLABLE'] === 'NO' ? ' (required)' : '';
            $lines[] = "• {$key}`{$c['COLUMN_NAME']}` — `{$c['COLUMN_TYPE']}`$null";
        }
        return ['text' => implode("\n", $lines)];
    }

    private function reAuditRecent(): array {
        $rows = AetherAudit::recent(15);
        if (!$rows) return ['text' => 'Audit log is empty so far.'];
        $lines = ['**Recent Aether activity:**'];
        foreach ($rows as $r) {
            $sev = strtoupper($r['severity']);
            $lines[] = "• [{$r['created_at']}] [{$sev}] {$r['event_type']}: {$r['summary']}";
        }
        return ['text' => implode("\n", $lines)];
    }

    /* =====================================================================
     *  WRITE PLANNERS — return a plan, never execute directly
     * ===================================================================== */

    private function planDonation(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','accountant','editor'])) {
            return ['text' => "Your role (`{$this->user['role']}`) cannot record donations. Ask an admin or accountant."];
        }
        $amount = $entities['amount'][0] ?? null;
        if (!$amount) {
            return ['text' => "I can record a donation. Please tell me the amount and donor — e.g. *record ₹5000 donation from Ravi Kumar*."];
        }
        $donorName = $entities['quoted'][0] ?? $this->guessDonorName($message);
        $plan = [
            'kind'   => 'insert',
            'table'  => 'donations',
            'fields' => [
                'amount'        => (float)$amount,
                'donation_date' => date('Y-m-d'),
                'status'        => 'completed',
                'donor_name'    => $donorName,
            ],
            'requires' => ['donor_id (auto-created if needed)'],
        ];
        $plan = $this->savePlan('record_donation', $plan,
            "Plan: insert donation ₹" . number_format((float)$amount, 2) .
            ($donorName ? " from `$donorName`" : '') . " (today's date)."
        );
        return [
            'text' => "I've drafted an action plan to record this donation. **Approve** it from the floating panel or reply *approve*.",
            'plan' => $plan,
            'mode' => 'plan',
        ];
    }

    private function planExpense(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','accountant'])) {
            return ['text' => "Your role (`{$this->user['role']}`) cannot record expenses."];
        }
        $amount = $entities['amount'][0] ?? null;
        if (!$amount) return ['text' => 'Please include an amount — e.g. *log expense ₹2500 for stationery*.'];
        $cat = $entities['quoted'][0] ?? $this->guessCategory($message) ?? 'misc';
        $plan = [
            'kind'   => 'insert',
            'table'  => 'expenses',
            'fields' => [
                'amount'           => (float)$amount,
                'expense_date'     => date('Y-m-d'),
                'expense_category' => $cat,
                'description'      => trim(preg_replace('/[\d,]+|rs|rupees|inr|₹/i', '', $message)),
            ],
        ];
        $plan = $this->savePlan('create_expense', $plan,
            "Plan: log expense ₹" . number_format((float)$amount, 2) . " under category `$cat`."
        );
        return [
            'text' => "Drafted plan to log this expense. Approve it from the panel or reply *approve*.",
            'plan' => $plan,
            'mode' => 'plan',
        ];
    }

    private function planSalaryUpdate(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && ($this->user['role'] ?? '') !== 'hr') {
            return ['text' => "Salary updates are restricted to HR / admins."];
        }
        $amount = $entities['amount'][0] ?? null;
        if (!$amount) return ['text' => 'Tell me the new salary amount and the employee name.'];
        $name = $entities['quoted'][0] ?? null;
        if (!$name) return ['text' => 'Wrap the employee name in quotes — e.g. *update salary of "Anita Sharma" to ₹45000*.'];
        $emp = $this->db->prepare("SELECT id, name, basic_salary FROM employees WHERE name LIKE ? LIMIT 1");
        $emp->execute(['%' . $name . '%']);
        $row = $emp->fetch();
        if (!$row) return ['text' => "Couldn't find an employee matching `$name`."];

        $plan = [
            'kind'   => 'update',
            'table'  => 'employees',
            'where'  => ['id' => (int)$row['id']],
            'fields' => ['basic_salary' => (float)$amount],
        ];
        $plan = $this->savePlan('update_salary', $plan,
            "Plan: update **{$row['name']}**'s basic_salary from ₹" . number_format((float)$row['basic_salary'], 2) .
            " → ₹" . number_format((float)$amount, 2) . "."
        );
        return [
            'text' => "Drafted salary update for **{$row['name']}**. Review and approve from the panel.",
            'plan' => $plan,
            'mode' => 'plan',
        ];
    }

    /* =====================================================================
     *  Plan persistence + execution
     * ===================================================================== */

    private function savePlan(string $intent, array $plan, string $preview): array {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO aether_action_plans (user_id, intent, plan_json, preview, status)
                 VALUES (?, ?, ?, ?, 'proposed')"
            );
            $stmt->execute([
                $this->user['id'] ?? 0, $intent,
                json_encode($plan, JSON_UNESCAPED_UNICODE), $preview,
            ]);
            $id = (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            $id = 0;
        }
        AetherAudit::log('plan_proposed', $preview, $plan, 'low', $this->user['id'] ?? null, $intent);
        return ['id' => $id, 'intent' => $intent, 'preview' => $preview, 'plan' => $plan, 'status' => 'proposed'];
    }

    public function executePlan(int $planId): array {
        $stmt = $this->db->prepare("SELECT * FROM aether_action_plans WHERE id = ?");
        $stmt->execute([$planId]);
        $row = $stmt->fetch();
        if (!$row) return ['ok' => false, 'error' => 'Plan not found'];
        if ($row['status'] !== 'proposed') return ['ok' => false, 'error' => "Plan already $row[status]"];
        $plan = json_decode($row['plan_json'], true);
        if (!$plan) return ['ok' => false, 'error' => 'Corrupt plan'];

        try {
            $this->db->beginTransaction();
            $result = $this->applyPlan($plan, $row['intent']);
            $this->db->commit();
            $this->db->prepare("UPDATE aether_action_plans SET status='executed', executed_at=NOW() WHERE id = ?")->execute([$planId]);
            AetherAudit::log('plan_executed', "Executed: {$row['preview']}", ['plan_id' => $planId, 'result' => $result],
                'medium', $this->user['id'] ?? null, $row['intent']);
            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->prepare("UPDATE aether_action_plans SET status='failed' WHERE id = ?")->execute([$planId]);
            AetherAudit::log('plan_failed', "Failed: {$row['preview']}", ['error' => $e->getMessage()],
                'high', $this->user['id'] ?? null, $row['intent']);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function applyPlan(array $plan, string $intent): array {
        if ($plan['kind'] === 'insert') {
            $table = $plan['table'];
            $fields = $plan['fields'];

            // Special case: donations — auto-create donor if needed
            if ($table === 'donations' && !empty($fields['donor_name'])) {
                $donorName = $fields['donor_name'];
                unset($fields['donor_name']);
                $stmt = $this->db->prepare("SELECT id FROM donors WHERE name = ? LIMIT 1");
                $stmt->execute([$donorName]);
                $donorId = (int)$stmt->fetchColumn();
                if (!$donorId) {
                    $this->db->prepare("INSERT INTO donors (name, donor_type) VALUES (?, 'individual')")->execute([$donorName]);
                    $donorId = (int)$this->db->lastInsertId();
                }
                $fields['donor_id'] = $donorId;
                if (empty($fields['donation_code'])) {
                    $fields['donation_code'] = 'DON-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
                }
            }
            $cols = array_keys($fields);
            $place = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($place)";
            $this->db->prepare($sql)->execute(array_values($fields));
            return ['inserted_id' => (int)$this->db->lastInsertId(), 'table' => $table];
        }
        if ($plan['kind'] === 'update') {
            $table = $plan['table'];
            $set = [];
            $vals = [];
            foreach ($plan['fields'] as $k => $v) { $set[] = "`$k`=?"; $vals[] = $v; }
            $whereCol = array_key_first($plan['where']);
            $whereVal = $plan['where'][$whereCol];
            $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE `$whereCol` = ?";
            $vals[] = $whereVal;
            $this->db->prepare($sql)->execute($vals);
            return ['updated_table' => $table, 'where' => $plan['where']];
        }
        throw new \RuntimeException('Unknown plan kind: ' . ($plan['kind'] ?? '?'));
    }

    public function rejectPlan(int $planId): array {
        $this->db->prepare("UPDATE aether_action_plans SET status='rejected' WHERE id = ? AND status='proposed'")->execute([$planId]);
        AetherAudit::log('plan_rejected', "Plan #$planId rejected", [], 'low', $this->user['id'] ?? null);
        return ['ok' => true];
    }

    private function fallback(string $msg, array $analysis): array {
        $kg = $analysis['kg_matches'] ?? [];
        if (!empty($kg)) {
            $first = $kg[0];
            $hint = "Did you mean *{$first['business_label']}* ({$first['entity_name']})? Try `describe {$first['entity_name']}`.";
        } else {
            $hint = "I couldn't confidently map that to an action. Try *help* to see what I can do.";
        }
        return ['text' => $hint];
    }

    private function guessDonorName(string $msg): ?string {
        if (preg_match('/from\s+([A-Z][\w\.\- ]{1,40})/u', $msg, $m)) return trim($m[1]);
        if (preg_match('/by\s+([A-Z][\w\.\- ]{1,40})/u', $msg, $m)) return trim($m[1]);
        return null;
    }

    private function guessCategory(string $msg): ?string {
        $cats = ['stationery','travel','food','rent','utility','salary','printing','events','medical','transport','training'];
        $low = strtolower($msg);
        foreach ($cats as $c) if (str_contains($low, $c)) return $c;
        return null;
    }
}
