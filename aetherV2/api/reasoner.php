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
            'module_report'    => $this->reModuleReport($message, $analysis, $entities),
            'suggest_caption'  => $this->reSuggestCaption($message, $entities),
            'suggest_blog'     => $this->reSuggestBlog($message, $entities),
            'record_donation'  => $this->planDonation($entities, $message),
            'create_expense'   => $this->planExpense($entities, $message),
            'update_salary'    => $this->planSalaryUpdate($entities, $message),
            'create_donor'     => $this->planCreateDonor($entities, $message),
            'create_volunteer' => $this->planCreateVolunteer($entities, $message),
            'approve_expense'  => $this->planApproveExpense($entities, $message),
            'adjust_inventory' => $this->planAdjustInventory($entities, $message),
            'add_inventory_item' => $this->planAddInventoryItem($entities, $message),
            'create_program'   => $this->planCreateProgram($entities, $message),
            'create_blog_post' => $this->planCreateBlogPost($entities, $message),
            'send_message'     => $this->planSendMessage($entities, $message),
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
            return ['text' => "I can record a donation. Please tell me the amount and donor — e.g. *record ₹5000 donation from \"Ravi Kumar\"*."];
        }
        $donorName = $entities['quoted'][0] ?? $this->guessDonorName($message);
        $email = $entities['email'][0] ?? null;
        $phone = $entities['phone'][0] ?? null;
        $plan = [
            'kind'   => 'insert',
            'table'  => 'donations',
            'fields' => [
                'amount'        => (float)$amount,
                'donation_date' => date('Y-m-d'),
                'status'        => 'completed',
                'donor_name'    => $donorName,
                'donor_email'   => $email,
                'donor_phone'   => $phone,
            ],
            'auto_receipt' => true,   // ← Aether will email PDF receipt + Fast2SMS thank-you on approval
        ];
        $previewLines = [
            'Plan: insert donation **₹' . number_format((float)$amount, 2) . '**' .
            ($donorName ? " from `$donorName`" : '') . " (today's date).",
        ];
        if ($email || $phone) {
            $contactBits = array_filter([$email ? "email `$email`" : null, $phone ? "phone `$phone`" : null]);
            $previewLines[] = '+ Auto-send PDF receipt + thank-you SMS to ' . implode(' / ', $contactBits) . '.';
        } else {
            $previewLines[] = '+ Will auto-send PDF receipt & SMS if donor record has email / phone.';
        }

        $plan = $this->savePlan('record_donation', $plan, implode("\n", $previewLines));
        return [
            'text' => "I've drafted a plan to record this donation **and** auto-send a thank-you receipt. **Approve** to fire both.",
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

    /* ─── New write planners (manpower-replacing actions) ─────────────── */

    private function planCreateDonor(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','accountant','editor'])) {
            return ['text' => "Your role can't create donors."];
        }
        $name = $entities['quoted'][0] ?? null;
        if (!$name) return ['text' => 'Wrap the donor name in quotes — e.g. *create donor "Jane Doe" jane@x.com 9876543210*.'];
        $email = $entities['email'][0] ?? null;
        $phone = $entities['phone'][0] ?? null;
        $fields = ['name' => $name, 'donor_type' => 'individual'];
        if ($email) $fields['email'] = $email;
        if ($phone) $fields['phone'] = $phone;
        $plan = ['kind'=>'insert','table'=>'donors','fields'=>$fields];
        $plan = $this->savePlan('create_donor', $plan,
            "Plan: create donor **$name**" . ($email?" · `$email`":'') . ($phone?" · `$phone`":'') . '.'
        );
        return ['text' => "Drafted donor creation. Approve to add.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planCreateVolunteer(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','editor','hr'])) {
            return ['text' => "Your role can't onboard volunteers."];
        }
        $name = $entities['quoted'][0] ?? null;
        if (!$name) return ['text' => 'Wrap the volunteer name in quotes.'];
        $code = 'VOL-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
        $fields = [
            'volunteer_code' => $code, 'name' => $name, 'designation' => 'Volunteer', 'department' => 'General',
        ];
        if (!empty($entities['email']))  $fields['email'] = $entities['email'][0];
        if (!empty($entities['phone']))  $fields['phone'] = $entities['phone'][0];
        $plan = ['kind'=>'insert','table'=>'volunteers','fields'=>$fields];
        $plan = $this->savePlan('create_volunteer', $plan, "Plan: register volunteer **$name** (`$code`).");
        return ['text' => "Drafted volunteer onboarding.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planApproveExpense(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','accountant'])) {
            return ['text' => "Your role can't approve expenses."];
        }
        $id = $entities['number'][0] ?? null;
        if (!$id) return ['text' => 'Tell me which expense — e.g. *approve expense #42*.'];
        $row = $this->db->prepare("SELECT id, amount, expense_category, description FROM expenses WHERE id = ?");
        $row->execute([$id]);
        $r = $row->fetch();
        if (!$r) return ['text' => "No expense with id #$id."];
        $plan = ['kind'=>'update','table'=>'expenses','where'=>['id'=>(int)$id],
                 'fields'=>['status'=>'approved','approved_at'=>date('Y-m-d H:i:s')]];
        $plan = $this->savePlan('approve_expense', $plan,
            "Plan: approve expense #{$r['id']} (₹" . number_format((float)$r['amount'], 2) . " / {$r['expense_category']})."
        );
        return ['text' => "Drafted approval.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planAdjustInventory(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','editor'])) {
            return ['text' => "Your role can't adjust inventory."];
        }
        $name = $entities['quoted'][0] ?? null;
        $qty  = $entities['number'][0] ?? null;
        if (!$name || !$qty) return ['text' => 'Try *adjust inventory of "Notebooks" by +50* (use + or - and quote the item).'];
        $sign = (preg_match('/-\s*\d/', $message) || preg_match('/\b(decrease|consume|remove|reduce)\b/i', $message)) ? -1 : 1;
        $delta = $sign * (int)$qty;
        $row = $this->db->prepare("SELECT id, item_name, quantity FROM inventory_items WHERE item_name LIKE ? LIMIT 1");
        $row->execute(['%' . $name . '%']);
        $r = $row->fetch();
        if (!$r) return ['text' => "Couldn't find inventory item matching `$name`."];
        $plan = ['kind'=>'increment','table'=>'inventory_items','column'=>'quantity',
                 'delta'=>$delta,'where'=>['id'=>(int)$r['id']]];
        $plan = $this->savePlan('adjust_inventory', $plan,
            "Plan: adjust **{$r['item_name']}** quantity by **" . ($delta>=0?'+':'') . "$delta** (current: {$r['quantity']})."
        );
        return ['text' => "Drafted inventory adjustment.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planAddInventoryItem(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','editor'])) {
            return ['text' => "Your role can't add inventory items."];
        }
        $name = $entities['quoted'][0] ?? null;
        if (!$name) return ['text' => 'Wrap the item name in quotes — e.g. *add inventory item "Notebooks" qty 100 unit pcs*.'];
        $qty = $entities['number'][0] ?? 0;
        $unit = 'pcs';
        if (preg_match('/\b(unit|in)\s+([a-z]+)/i', $message, $m)) $unit = $m[2];
        $plan = ['kind'=>'insert','table'=>'inventory_items',
                 'fields'=>['item_name'=>$name,'quantity'=>(int)$qty,'unit'=>$unit,'category'=>'other']];
        $plan = $this->savePlan('add_inventory_item', $plan,
            "Plan: add new item **$name** (qty $qty $unit) to inventory."
        );
        return ['text' => "Drafted inventory addition.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planCreateProgram(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','editor'])) {
            return ['text' => "Your role can't create programs."];
        }
        $name = $entities['quoted'][0] ?? null;
        if (!$name) return ['text' => 'Wrap the program name in quotes.'];
        $budget = $entities['amount'][0] ?? 0;
        $plan = ['kind'=>'insert','table'=>'programs',
                 'fields'=>['program_name'=>$name,'description'=>$name,
                            'start_date'=>date('Y-m-d'),'budget'=>(float)$budget,'status'=>'planning']];
        $plan = $this->savePlan('create_program', $plan,
            "Plan: launch program **$name**" . ($budget ? " with budget ₹" . number_format((float)$budget, 2) : '') . '.'
        );
        return ['text' => "Drafted program creation.", 'plan' => $plan, 'mode' => 'plan'];
    }

    private function planCreateBlogPost(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['editor'])) {
            return ['text' => "Only editors / admins can publish blog posts."];
        }
        $title = $entities['quoted'][0] ?? null;
        if (!$title) return ['text' => 'Wrap the blog title in quotes — I\'ll generate the body and excerpt for you.'];
        $body = $this->scaffoldBlogContent($title, $message);
        $excerpt = mb_substr(strip_tags($body), 0, 200);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($title));
        $plan = ['kind'=>'insert','table'=>'blog_posts',
                 'fields'=>['title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$body,
                            'category'=>'story','author_name'=>$this->user['full_name'] ?? 'Aether',
                            'is_published'=>0,'is_featured'=>0]];
        $plan = $this->savePlan('create_blog_post', $plan,
            "Plan: draft blog post **\"$title\"** (~" . str_word_count($body) . " words, saved as draft)."
        );
        return [
            'text' => "Drafted a blog scaffold for **\"$title\"**. Approve to save as a draft you can polish in the CMS.",
            'plan' => $plan, 'mode' => 'plan',
        ];
    }

    private function planSendMessage(array $entities, string $message): array {
        if (!aether_is_admin($this->user) && !in_array($this->user['role'] ?? '', ['manager','editor','accountant'])) {
            return ['text' => "Your role can't send custom messages."];
        }
        $email = $entities['email'][0] ?? null;
        $phone = $entities['phone'][0] ?? null;
        if (!$email && !$phone) return ['text' => 'Include an email or phone number — e.g. *send email to jane@x.com saying "thank you"*.'];
        $body = '';
        if (!empty($entities['quoted'])) $body = implode("\n\n", $entities['quoted']);
        if (!$body) {
            // strip recipient mentions and use the rest as body
            $clean = preg_replace('/(send|email|sms|message|to)\b/i', ' ', $message);
            $clean = preg_replace('/\S+@\S+\.\S+|\b\d{10}\b/', '', $clean);
            $body = trim($clean) ?: 'Hello from Dhrub Foundation.';
        }
        $subject = mb_substr(explode("\n", $body)[0], 0, 120) ?: 'Message from Dhrub Foundation';
        $plan = ['kind'=>'send_message','email'=>$email,'phone'=>$phone,'subject'=>$subject,'body'=>$body];
        $preview = "Plan: send message" . ($email?" · email `$email`":'') . ($phone?" · sms `$phone`":'') . "\n> " . mb_substr($body, 0, 200);
        $plan = $this->savePlan('send_message', $plan, $preview);
        return ['text' => "Drafted the outgoing message. Approve to send.", 'plan' => $plan, 'mode' => 'plan'];
    }

    /* ─── AI helpers (caption / blog scaffolding) ─────────────────────── */

    private function reSuggestCaption(string $message, array $entities): array {
        $subject = $entities['quoted'][0] ?? null;
        $name = $subject ?: trim(preg_replace('/(suggest|caption|for|image|description|alt text|photo)/i', '', $message));
        $name = $name ?: 'this image';
        $words = preg_split('/[\s_\-\.]+/', strtolower($name));
        $keywords = array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, ['png','jpg','jpeg','image','photo','file','dsc','img']));
        $keywordList = $keywords ? implode(', ', array_slice($keywords, 0, 4)) : 'community impact';

        $captions = [
            "Capturing a moment of $keywordList — every face here represents a story Dhrub Foundation is honoured to be part of.",
            "Frame by frame, our work on $keywordList comes to life. Thank you to everyone who made this possible.",
            "On the ground with our team for $keywordList. Real impact. Real people. Real stories.",
            "Behind every photograph is a chapter of change — today's was about $keywordList.",
        ];
        $altSuggestions = [
            "$name showing community members during a Dhrub Foundation initiative",
            "Volunteers and beneficiaries at a $keywordList moment",
            "Dhrub Foundation field activity — $keywordList",
        ];
        $lines = ["**Caption suggestions for `$name`** (pick whichever fits — all original, no AI fluff):", ''];
        foreach ($captions as $i => $c) $lines[] = ($i+1) . ". $c";
        $lines[] = "\n**Suggested alt text** (for accessibility):";
        foreach ($altSuggestions as $a) $lines[] = "- $a";
        $lines[] = "\n_Want a different tone? Tell me \"more formal\" / \"more casual\" / \"shorter\" and I'll re-draft._";
        return ['text' => implode("\n", $lines)];
    }

    private function reSuggestBlog(string $message, array $entities): array {
        $title = $entities['quoted'][0] ?? null;
        if (!$title) {
            return ['text' => "Give me a topic in quotes — e.g. *suggest blog about \"our annual scholarship drive\"*. I'll scaffold a complete draft you can save."];
        }
        $body = $this->scaffoldBlogContent($title, $message);
        $excerpt = mb_substr(strip_tags($body), 0, 180);
        return [
            'text' => "**Draft for `$title`**\n\n$body\n\n_Want me to **save this as a draft post**? Reply *create blog post \"$title\"* and I'll plan it._\n\n**Excerpt:** $excerpt",
        ];
    }

    /** Scaffold blog content from a title — pure heuristic, no external calls. */
    private function scaffoldBlogContent(string $title, string $context = ''): string {
        $t = trim($title);
        $opening = "There are stories that don't make headlines but quietly change lives. **" . htmlspecialchars($t) . "** is one of them.";
        $body = [
            "## Why this matters",
            "At Dhrub Foundation, every initiative starts with a simple question: *who is being left out, and what would it take to bring them in?* Our work on " . htmlspecialchars($t) . " grew out of long conversations with the communities we serve.",
            "",
            "## What we did",
            "- Listened first — to families, to volunteers, to local partners",
            "- Mapped the gaps in resources, access, and opportunity",
            "- Built a small, focused programme around the most pressing needs",
            "- Measured progress not in numbers alone, but in the moments people felt seen",
            "",
            "## What changed",
            "By the end of the cycle, we saw outcomes we hadn't dared promise — but they came because the people closest to the problem were trusted to design the solution.",
            "",
            "## What's next",
            "We're now scaling " . htmlspecialchars($t) . " thoughtfully — with the same humility that started it. If our story moved you, consider supporting us, volunteering, or simply sharing this post.",
            "",
            "_— Dhrub Foundation team_",
        ];
        return $opening . "\n\n" . implode("\n", $body);
    }

    /* ─── Module reports ─────────────────────────────────────────────── */

    private function reModuleReport(string $message, array $analysis, array $entities): array {
        require_once __DIR__ . '/module-reports.php';
        $module = AetherModuleReports::detectModule($message, $analysis['kg_matches'] ?? []);
        $period = AetherModuleReports::detectPeriod($message);
        $report = AetherModuleReports::build($this->db, $module, $period);
        return [
            'text'  => $report['text'],
            'cards' => $report['cards'] ?? [],
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

            // Special case: donations — auto-create donor if needed, optionally update donor contact info
            if ($table === 'donations') {
                $donorName  = $fields['donor_name']  ?? null;
                $donorEmail = $fields['donor_email'] ?? null;
                $donorPhone = $fields['donor_phone'] ?? null;
                unset($fields['donor_name'], $fields['donor_email'], $fields['donor_phone']);

                if ($donorName) {
                    $stmt = $this->db->prepare("SELECT id, email, phone FROM donors WHERE name = ? LIMIT 1");
                    $stmt->execute([$donorName]);
                    $existing = $stmt->fetch();
                    if (!$existing) {
                        $cols = ['name','donor_type'];
                        $vals = [$donorName, 'individual'];
                        if ($donorEmail) { $cols[] = 'email'; $vals[] = $donorEmail; }
                        if ($donorPhone) { $cols[] = 'phone'; $vals[] = $donorPhone; }
                        $place = implode(',', array_fill(0, count($cols), '?'));
                        $this->db->prepare("INSERT INTO donors (" . implode(',', $cols) . ") VALUES ($place)")->execute($vals);
                        $fields['donor_id'] = (int)$this->db->lastInsertId();
                    } else {
                        $fields['donor_id'] = (int)$existing['id'];
                        $patch = [];
                        if ($donorEmail && empty($existing['email'])) $patch['email'] = $donorEmail;
                        if ($donorPhone && empty($existing['phone'])) $patch['phone'] = $donorPhone;
                        if ($patch) {
                            $sets = []; $pv = [];
                            foreach ($patch as $k=>$v) { $sets[] = "`$k`=?"; $pv[] = $v; }
                            $pv[] = $existing['id'];
                            $this->db->prepare("UPDATE donors SET " . implode(',', $sets) . " WHERE id = ?")->execute($pv);
                        }
                    }
                }
                if (empty($fields['donation_code'])) {
                    $fields['donation_code'] = 'DON-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
                }
            }

            // Gallery upload: save binary first
            if ($table === 'gallery' && !empty($fields['__upload'])) {
                $upload = $fields['__upload'];
                unset($fields['__upload']);
                $fields['image_url'] = $this->saveUpload($upload['filename'], $upload['data']);
            }

            $cols = array_keys($fields);
            $place = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($place)";
            $this->db->prepare($sql)->execute(array_values($fields));
            $insertedId = (int)$this->db->lastInsertId();

            // Auto-receipt dispatch on donation insert
            if ($table === 'donations' && !empty($plan['auto_receipt'])) {
                @require_once __DIR__ . '/notifier.php';
                $receipt = AetherNotifier::sendDonationReceipt($insertedId);
                AetherAudit::log(
                    'receipt_dispatched',
                    "Donation #$insertedId thank-you sent (email=" . ($receipt['email']?'yes':'no') . ", sms=" . ($receipt['sms']?'yes':'no') . ")",
                    $receipt, 'info', $this->user['id'] ?? null, "donations:$insertedId"
                );
                return ['inserted_id' => $insertedId, 'table' => $table, 'receipt' => $receipt];
            }

            return ['inserted_id' => $insertedId, 'table' => $table];
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
        if ($plan['kind'] === 'increment') {
            $table = $plan['table']; $col = $plan['column']; $delta = (float)$plan['delta'];
            $whereCol = array_key_first($plan['where']);
            $whereVal = $plan['where'][$whereCol];
            $this->db->prepare("UPDATE `$table` SET `$col` = `$col` + ? WHERE `$whereCol` = ?")->execute([$delta, $whereVal]);
            return ['table' => $table, 'column' => $col, 'delta' => $delta];
        }
        if ($plan['kind'] === 'send_message') {
            @require_once __DIR__ . '/notifier.php';
            $sent = AetherNotifier::sendCustom(
                $plan['email'] ?? null,
                $plan['phone'] ?? null,
                $plan['subject'] ?? '(no subject)',
                $plan['body']    ?? ''
            );
            return ['email' => $sent['email'] ?? false, 'sms' => $sent['sms'] ?? false];
        }
        throw new \RuntimeException('Unknown plan kind: ' . ($plan['kind'] ?? '?'));
    }

    /** Save a base64 upload into /uploads/aether/<filename>. Returns relative URL. */
    private function saveUpload(string $filename, string $base64): string {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $clean = uniqid() . '_' . $clean;
        $dir = '/app/uploads/aether';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $bin = base64_decode($base64) ?: '';
        file_put_contents("$dir/$clean", $bin);
        return "/uploads/aether/$clean";
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
