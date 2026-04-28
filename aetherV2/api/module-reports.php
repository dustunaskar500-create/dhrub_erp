<?php
/**
 * Aether v2 — Module Reports
 * On-demand analytical reports for each ERP module. Pure SQL aggregations,
 * formatted as Markdown reply + KPI cards for the floating panel.
 *
 * Modules supported:
 *   donations · expenses · hr · inventory · programs · volunteers · cms · audit
 */

require_once __DIR__ . '/bootstrap.php';

class AetherModuleReports
{
    /** Detect which module the user wants from message + KG hits. */
    public static function detectModule(string $msg, array $kgMatches = []): string {
        $low = strtolower($msg);
        $map = [
            'donations'  => ['donation', 'donor', 'contribution', 'fundrais', 'gift'],
            'expenses'   => ['expense', 'spending', 'spend', 'cost', 'outflow'],
            'hr'         => ['hr', 'employee', 'staff', 'payroll', 'salary', 'team'],
            'inventory'  => ['inventory', 'stock', 'supply', 'item'],
            'programs'   => ['program', 'project', 'initiative', 'campaign'],
            'volunteers' => ['volunteer'],
            'cms'        => ['blog', 'cms', 'gallery', 'post', 'article', 'content'],
            'audit'      => ['audit', 'log'],
        ];
        foreach ($map as $module => $needles) {
            foreach ($needles as $n) if (str_contains($low, $n)) return $module;
        }
        // KG fallback
        foreach ($kgMatches as $m) {
            if ($m['entity_type'] === 'table') {
                foreach ($map as $module => $needles) {
                    foreach ($needles as $n) if (str_contains(strtolower($m['entity_name']), $n)) return $module;
                }
            }
        }
        return 'donations'; // sensible default
    }

    public static function detectPeriod(string $msg): array {
        $low = strtolower($msg);
        if (str_contains($low, 'today'))     return ['days' =>  1,   'label' => 'today'];
        if (str_contains($low, 'this week')) return ['days' =>  7,   'label' => 'last 7 days'];
        if (str_contains($low, 'this month'))return ['days' => 30,   'label' => 'this month'];
        if (str_contains($low, 'last month'))return ['days' => 60,   'label' => 'last 30 days'];
        if (str_contains($low, 'quarter'))   return ['days' => 90,   'label' => 'this quarter'];
        if (str_contains($low, 'half year')) return ['days' => 180,  'label' => 'last 6 months'];
        if (str_contains($low, 'year'))      return ['days' => 365,  'label' => 'last 12 months'];
        return ['days' => 90, 'label' => 'last 90 days'];
    }

    public static function build(PDO $db, string $module, array $period): array {
        return match ($module) {
            'donations'  => self::donations($db, $period),
            'expenses'   => self::expenses($db, $period),
            'hr'         => self::hr($db, $period),
            'inventory'  => self::inventory($db, $period),
            'programs'   => self::programs($db, $period),
            'volunteers' => self::volunteers($db, $period),
            'cms'        => self::cms($db, $period),
            'audit'      => self::auditReport($db, $period),
            default      => self::donations($db, $period),
        };
    }

    /* ────────────────────────────────────────────── modules ─────────── */

    private static function donations(PDO $db, array $period): array {
        $d = (int)$period['days'];
        $stmt = $db->query(
            "SELECT COUNT(*) c, COALESCE(SUM(amount),0) sum, COALESCE(AVG(amount),0) avg, COALESCE(MAX(amount),0) max
             FROM donations WHERE donation_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY)"
        )->fetch();
        $top = $db->query(
            "SELECT dn.name, SUM(d.amount) total, COUNT(d.id) cnt
             FROM donations d JOIN donors dn ON dn.id = d.donor_id
             WHERE d.donation_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY)
             GROUP BY dn.id ORDER BY total DESC LIMIT 5"
        )->fetchAll();
        $byMethod = $db->query(
            "SELECT COALESCE(payment_method,'unknown') pm, COUNT(*) c, SUM(amount) s
             FROM donations WHERE donation_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY)
             GROUP BY pm ORDER BY s DESC"
        )->fetchAll();

        $cards = [
            ['label' => 'Total Received',  'value' => '₹' . number_format((float)$d['sum'] ?? 0, 0), 'icon' => 'hand-holding-heart'],
            ['label' => 'Donations',       'value' => $stmt['c'] ?? 0, 'icon' => 'receipt'],
            ['label' => 'Average Gift',    'value' => '₹' . number_format((float)($stmt['avg'] ?? 0), 0), 'icon' => 'chart-line'],
            ['label' => 'Largest Gift',    'value' => '₹' . number_format((float)($stmt['max'] ?? 0), 0), 'icon' => 'trophy'],
        ];

        $lines = ["**Donations report — {$period['label']}**", ''];
        $lines[] = "Aether processed **" . ($stmt['c']??0) . "** donations totalling **₹" . number_format((float)($stmt['sum']??0), 2) . "**.";
        $lines[] = "Average gift: **₹" . number_format((float)($stmt['avg']??0), 2) . "**, largest single gift: **₹" . number_format((float)($stmt['max']??0), 2) . "**.";

        if ($top) {
            $lines[] = "\n**Top contributors**";
            foreach ($top as $i => $r) {
                $lines[] = ($i+1) . ". *{$r['name']}* — ₹" . number_format((float)$r['total'], 0) . " across {$r['cnt']} donation(s)";
            }
        }
        if ($byMethod) {
            $lines[] = "\n**By payment method**";
            foreach ($byMethod as $r) {
                $lines[] = "- {$r['pm']}: " . $r['c'] . ' donations · ₹' . number_format((float)$r['s'], 0);
            }
        }
        $lines[] = "\n_Want me to dig deeper? Try `report on donations this quarter` or `forecast donations`._";

        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function expenses(PDO $db, array $period): array {
        $d = (int)$period['days'];
        $sum = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY)")->fetch();
        $byCat = $db->query(
            "SELECT COALESCE(expense_category,'misc') cat, COUNT(*) c, SUM(amount) s
             FROM expenses WHERE expense_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY)
             GROUP BY cat ORDER BY s DESC LIMIT 8"
        )->fetchAll();
        $largest = $db->query(
            "SELECT id, expense_category, amount, expense_date, description FROM expenses
             WHERE expense_date >= DATE_SUB(CURRENT_DATE, INTERVAL $d DAY) ORDER BY amount DESC LIMIT 5"
        )->fetchAll();

        $cards = [
            ['label' => 'Total Spend',   'value' => '₹' . number_format((float)($sum['s'] ?? 0), 0), 'icon' => 'wallet'],
            ['label' => 'Transactions', 'value' => $sum['c'] ?? 0, 'icon' => 'list'],
            ['label' => 'Categories',   'value' => count($byCat), 'icon' => 'shapes'],
            ['label' => 'Period',       'value' => $period['label'], 'icon' => 'calendar'],
        ];

        $lines = ["**Expense report — {$period['label']}**", ''];
        $lines[] = "Total spend: **₹" . number_format((float)($sum['s']??0), 2) . "** across **" . ($sum['c']??0) . "** transactions.";
        if ($byCat) {
            $lines[] = "\n**Top categories**";
            foreach ($byCat as $r) $lines[] = "- {$r['cat']}: ₹" . number_format((float)$r['s'], 0) . " ({$r['c']} txn)";
        }
        if ($largest) {
            $lines[] = "\n**Five largest expenses**";
            foreach ($largest as $r) $lines[] = "- ₹" . number_format((float)$r['amount'], 0) . " · {$r['expense_category']} · {$r['expense_date']} — " . mb_substr($r['description']??'', 0, 60);
        }
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function hr(PDO $db, array $period): array {
        $tot = $db->query("SELECT COUNT(*) c, COALESCE(SUM(net_salary),0) tot, COALESCE(AVG(net_salary),0) avg FROM employees WHERE status='active' OR status IS NULL")->fetch();
        $byDept = $db->query("SELECT COALESCE(department,'unassigned') d, COUNT(*) c, SUM(net_salary) s FROM employees WHERE status='active' OR status IS NULL GROUP BY d ORDER BY c DESC")->fetchAll();
        $cards = [
            ['label' => 'Active Employees', 'value' => $tot['c'] ?? 0, 'icon' => 'user-tie'],
            ['label' => 'Monthly Payroll',  'value' => '₹' . number_format((float)($tot['tot'] ?? 0), 0), 'icon' => 'sack-dollar'],
            ['label' => 'Average Net Pay',  'value' => '₹' . number_format((float)($tot['avg'] ?? 0), 0), 'icon' => 'chart-bar'],
            ['label' => 'Departments',      'value' => count($byDept), 'icon' => 'building'],
        ];
        $lines = ["**HR report — current state**", ''];
        $lines[] = "**" . ($tot['c']??0) . "** active employees · monthly payroll **₹" . number_format((float)($tot['tot']??0), 2) . "** · average net **₹" . number_format((float)($tot['avg']??0), 2) . "**.";
        if ($byDept) {
            $lines[] = "\n**By department**";
            foreach ($byDept as $r) $lines[] = "- {$r['d']}: {$r['c']} people · ₹" . number_format((float)$r['s'], 0);
        }
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function inventory(PDO $db, array $period): array {
        $tot = $db->query("SELECT COUNT(*) c, COALESCE(SUM(quantity),0) qty FROM inventory_items")->fetch();
        $low = $db->query("SELECT COUNT(*) FROM inventory_items WHERE min_stock > 0 AND quantity < min_stock")->fetchColumn();
        $byCat = $db->query("SELECT COALESCE(category,'misc') c, COUNT(*) n FROM inventory_items GROUP BY c ORDER BY n DESC LIMIT 8")->fetchAll();
        $cards = [
            ['label' => 'Total Items',    'value' => $tot['c'] ?? 0, 'icon' => 'box'],
            ['label' => 'Total Quantity', 'value' => $tot['qty'] ?? 0, 'icon' => 'cubes'],
            ['label' => 'Low Stock',      'value' => $low, 'icon' => 'triangle-exclamation'],
            ['label' => 'Categories',     'value' => count($byCat), 'icon' => 'shapes'],
        ];
        $lines = ["**Inventory report**", ''];
        $lines[] = "Tracking **" . ($tot['c']??0) . "** items, total quantity **" . ($tot['qty']??0) . "**. ";
        $lines[] = ($low > 0)
            ? "**$low item(s) below minimum stock — restock recommended.**"
            : "All items at or above minimum stock levels. ";
        if ($byCat) {
            $lines[] = "\n**By category**";
            foreach ($byCat as $r) $lines[] = "- {$r['c']}: {$r['n']} item(s)";
        }
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function programs(PDO $db, array $period): array {
        $rows = $db->query("SELECT status, COUNT(*) c, COALESCE(SUM(budget),0) b FROM programs GROUP BY status")->fetchAll();
        $tot = $db->query("SELECT COUNT(*) c, COALESCE(SUM(budget),0) b FROM programs")->fetch();
        $cards = [
            ['label' => 'Programs',     'value' => $tot['c'] ?? 0, 'icon' => 'diagram-project'],
            ['label' => 'Total Budget', 'value' => '₹' . number_format((float)($tot['b'] ?? 0), 0), 'icon' => 'sack-dollar'],
            ['label' => 'Statuses',     'value' => count($rows), 'icon' => 'flag'],
            ['label' => 'Period',       'value' => $period['label'], 'icon' => 'calendar'],
        ];
        $lines = ["**Programs report**", ''];
        $lines[] = "**" . ($tot['c']??0) . "** programs registered, total committed budget **₹" . number_format((float)($tot['b']??0), 2) . "**.";
        foreach ($rows as $r) $lines[] = "- {$r['status']}: {$r['c']} programs · ₹" . number_format((float)$r['b'], 0) . " budget";
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function volunteers(PDO $db, array $period): array {
        $tot = $db->query("SELECT COUNT(*) c FROM volunteers")->fetchColumn();
        $byDept = $db->query("SELECT COALESCE(department,'general') d, COUNT(*) c FROM volunteers GROUP BY d ORDER BY c DESC LIMIT 6")->fetchAll();
        $cards = [['label'=>'Volunteers','value'=>$tot,'icon'=>'people-group']];
        $lines = ["**Volunteers report**", "**$tot** volunteers on the roster."];
        foreach ($byDept as $r) $lines[] = "- {$r['d']}: {$r['c']}";
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function cms(PDO $db, array $period): array {
        $blog = $db->query("SELECT COUNT(*) c, SUM(is_published=1) p FROM blog_posts")->fetch();
        $gal  = (int)$db->query("SELECT COUNT(*) FROM gallery")->fetchColumn();
        $cards = [
            ['label' => 'Blog Posts',   'value' => $blog['c'] ?? 0, 'icon' => 'newspaper'],
            ['label' => 'Published',    'value' => $blog['p'] ?? 0, 'icon' => 'circle-check'],
            ['label' => 'Gallery',      'value' => $gal, 'icon' => 'images'],
        ];
        $lines = ["**Content report**", '',
            "**" . ($blog['c']??0) . "** blog posts (" . ($blog['p']??0) . " published, " . (($blog['c']??0) - ($blog['p']??0)) . " drafts).",
            "**$gal** images in the gallery."];
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }

    private static function auditReport(PDO $db, array $period): array {
        $d = (int)$period['days'];
        $sev = $db->query("SELECT severity, COUNT(*) c FROM aether_audit_log WHERE created_at >= NOW() - INTERVAL $d DAY GROUP BY severity")->fetchAll();
        $by  = $db->query("SELECT event_type, COUNT(*) c FROM aether_audit_log WHERE created_at >= NOW() - INTERVAL $d DAY GROUP BY event_type ORDER BY c DESC LIMIT 10")->fetchAll();
        $cards = [];
        foreach ($sev as $r) $cards[] = ['label' => ucfirst($r['severity']), 'value' => $r['c'], 'icon' => 'circle'];
        $lines = ["**Audit report — {$period['label']}**", ''];
        foreach ($by as $r) $lines[] = "- `{$r['event_type']}` — {$r['c']} event(s)";
        return ['text' => implode("\n", $lines), 'cards' => $cards];
    }
}
