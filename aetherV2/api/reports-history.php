<?php
/**
 * Aether v2 — Reports History
 *
 * Surfaces every report Aether has produced (module reports, impact reports,
 * audit summaries) and supports exporting them as CSV.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherReportsHistory
{
    public const MODULES = ['donations','expenses','hr','inventory','programs','volunteers','cms','audit'];
    public const PERIODS = ['7 days','30 days','90 days','12 months'];

    public static function list(array $user): array {
        $db = aether_db();
        $items = [];

        // Impact-report plans (proposed + executed)
        try {
            $stmt = $db->query(
                "SELECT p.id, p.intent, p.preview, p.status, p.created_at, p.executed_at,
                        u.full_name, r.role_name AS role
                 FROM aether_action_plans p
                 LEFT JOIN users u ON u.id = p.user_id
                 LEFT JOIN roles r ON r.id = u.role_id
                 WHERE p.intent IN ('impact_report','donation_reminders')
                 ORDER BY p.id DESC LIMIT 50"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'kind'    => $r['intent'],
                    'id'      => 'plan:' . $r['id'],
                    'title'   => $r['intent'] === 'impact_report'
                        ? 'Year-end Impact Report'
                        : 'Donation Reminder Sweep',
                    'status'  => $r['status'],
                    'preview' => mb_substr(strip_tags($r['preview'] ?? ''), 0, 300),
                    'author'  => trim(($r['full_name'] ?? '') . ($r['role'] ? " ({$r['role']})" : '')),
                    'created_at'  => $r['created_at'],
                    'executed_at' => $r['executed_at'],
                    'export_url'  => '/aetherV2/api/aether.php?action=report_export&kind=' . urlencode($r['intent']) . '&plan_id=' . $r['id'],
                ];
            }
        } catch (\Throwable $e) {}

        // Module-report invocations from audit log
        try {
            $stmt = $db->prepare(
                "SELECT id, summary, payload_json, created_at, user_id
                 FROM aether_audit_log
                 WHERE event_type IN ('module_report','plan_executed','receipt_dispatched')
                 ORDER BY id DESC LIMIT 30"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'kind'    => 'audit:' . $r['summary'],
                    'id'      => 'audit:' . $r['id'],
                    'title'   => mb_substr($r['summary'] ?? '', 0, 120),
                    'status'  => 'logged',
                    'preview' => mb_substr($r['payload_json'] ?? '', 0, 200),
                    'author'  => '—',
                    'created_at' => $r['created_at'],
                    'executed_at' => null,
                ];
            }
        } catch (\Throwable $e) {}

        usort($items, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return ['ok'=>true, 'items'=>array_slice($items, 0, 100)];
    }

    /**
     * Build + stream a CSV export for a module report.
     * @param string $module donations|expenses|hr|inventory|programs|volunteers|cms|audit
     * @param string $period e.g. "30 days"
     */
    public static function exportModuleCsv(string $module, string $period): void {
        require_once __DIR__ . '/module-reports.php';
        $db = aether_db();
        $periodInfo = AetherModuleReports::detectPeriod($period);
        $label = $periodInfo['label'] ?? $period;
        $days  = (int)($periodInfo['days'] ?? 90);

        [$headers, $rows, $title] = self::moduleRows($db, $module, $days);

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="aether-' . $module . '-' . date('Ymd-His') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ["# Aether Report: $title"]);
        fputcsv($fp, ["# Period: $label"]);
        fputcsv($fp, ["# Generated: " . date('Y-m-d H:i:s')]);
        fputcsv($fp, []);
        fputcsv($fp, $headers);
        foreach ($rows as $r) fputcsv($fp, $r);
        fclose($fp);
        exit;
    }

    /** Build raw header+rows for a given module's CSV export. */
    private static function moduleRows(PDO $db, string $module, int $days): array {
        $cutoff = "DATE_SUB(CURRENT_DATE, INTERVAL " . $days . " DAY)";

        switch ($module) {
            case 'donations':
                $rows = $db->query(
                    "SELECT d.donation_code, dn.name AS donor, d.amount, d.donation_date, d.payment_method, d.status
                     FROM donations d LEFT JOIN donors dn ON dn.id = d.donor_id
                     WHERE d.donation_date >= $cutoff ORDER BY d.donation_date DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['Donation Code','Donor','Amount (₹)','Date','Method','Status'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Donations'];

            case 'expenses':
                $rows = $db->query(
                    "SELECT e.id, e.expense_category, e.amount, e.expense_date, e.description,
                            COALESCE(p.program_name,'') AS program
                     FROM expenses e LEFT JOIN programs p ON p.id = e.program_id
                     WHERE e.expense_date >= $cutoff ORDER BY e.expense_date DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Category','Amount (₹)','Date','Description','Program'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Expenses'];

            case 'hr':
                $rows = $db->query(
                    "SELECT id, name, designation, department, email, phone, basic_salary, status
                     FROM employees ORDER BY id DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Name','Designation','Department','Email','Phone','Basic Salary (₹)','Status'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Employees'];

            case 'inventory':
                $rows = $db->query(
                    "SELECT id, item_name, quantity, unit, category, min_stock FROM inventory_items ORDER BY id DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Item','Quantity','Unit','Category','Min Stock'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Inventory'];

            case 'programs':
                $rows = $db->query(
                    "SELECT id, program_name, description, start_date, end_date, budget, status FROM programs ORDER BY id DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Program','Description','Start','End','Budget (₹)','Status'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Programs'];

            case 'volunteers':
                $rows = $db->query(
                    "SELECT id, name, designation, department, email, phone FROM volunteers ORDER BY id DESC"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Name','Designation','Department','Email','Phone'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Volunteers'];

            case 'cms':
                $rows = $db->query(
                    "SELECT id, title, slug, category, author_name, is_published, created_at FROM blog_posts ORDER BY id DESC LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Title','Slug','Category','Author','Published','Created At'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Blog Posts'];

            case 'audit':
                $rows = $db->query(
                    "SELECT id, event_type, summary, severity, user_id, created_at FROM aether_audit_log ORDER BY id DESC LIMIT 500"
                )->fetchAll(PDO::FETCH_ASSOC);
                return [['ID','Event Type','Summary','Severity','User ID','Timestamp'],
                        array_map(fn($r) => array_values($r), $rows),
                        'Audit Log'];

            default:
                return [['Info'], [['Unknown module']], 'Unknown'];
        }
    }
}
