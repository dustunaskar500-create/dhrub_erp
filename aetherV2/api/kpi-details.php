<?php
/**
 * Aether v2 — KPI drill-down details
 * For every dashboard KPI (Total Donations, Donors, Expenses, …), return the
 * underlying records — RBAC-scoped — so the dashboard can show a detail panel
 * when the user clicks the card.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rbac.php';

class AetherKPIDetails
{
    public static function build(array $user, string $kpi, ?string $from = null, ?string $to = null): array {
        if (!self::canSee($kpi, $user)) {
            return ['ok'=>false, 'error'=>'Your role cannot drill into this KPI.'];
        }
        $db = aether_db();
        $whereDate = '';
        $params = [];
        if ($from && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $whereDate = 'BETWEEN ? AND ?';
            $params = [$from, $to];
        }

        switch ($kpi) {
            case 'total_donations':
            case 'donations_total':
            case 'donations_recorded':
                return self::donations($db, $whereDate, $params, $user);
            case 'donors':
                return self::donors($db, $whereDate, $params, $user);
            case 'expenses':
            case 'expenses_total':
                return self::expenses($db, $whereDate, $params, $user);
            case 'employees':
                return self::employees($db, $user);
            case 'volunteers':
                return self::volunteers($db, $user);
            case 'inventory_items':
            case 'inventory':
                return self::inventory($db, $user);
            case 'projects':
            case 'programs':
                return self::programs($db, $user);
            default:
                return ['ok'=>false, 'error'=>'Unknown KPI'];
        }
    }

    private static function canSee(string $kpi, array $user): bool {
        $module = match ($kpi) {
            'total_donations','donations_total','donations_recorded','donors' => 'donations',
            'expenses','expenses_total' => 'expenses',
            'employees' => 'hr',
            'volunteers' => 'volunteers',
            'inventory_items','inventory' => 'inventory',
            'projects','programs' => 'programs',
            default => 'audit_overview',
        };
        return AetherRBAC::canSeeModule($module, $user);
    }

    private static function donations(PDO $db, string $whereDate, array $params, array $user): array {
        $sql = "SELECT d.id, d.donation_code, d.amount, d.donation_date, d.status, d.payment_method,
                       dn.name AS donor_name, dn.email AS donor_email,
                       p.program_name
                FROM donations d
                LEFT JOIN donors dn ON dn.id = d.donor_id
                LEFT JOIN programs p ON p.id = d.program_id";
        if ($whereDate) $sql .= " WHERE d.donation_date $whereDate";
        $sql .= " ORDER BY d.donation_date DESC, d.id DESC LIMIT 200";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = AetherRBAC::redactRows($stmt->fetchAll(PDO::FETCH_ASSOC), $user);

        // Aggregates
        $sumSql = "SELECT COALESCE(SUM(amount),0) total, COUNT(*) c, AVG(amount) avg, MAX(amount) mx FROM donations" .
                  ($whereDate ? " WHERE donation_date $whereDate" : "");
        $aStmt = $db->prepare($sumSql); $aStmt->execute($params);
        $agg = $aStmt->fetch(PDO::FETCH_ASSOC);

        $byMethod = $db->prepare("SELECT payment_method, COUNT(*) c, SUM(amount) total FROM donations" .
                                 ($whereDate ? " WHERE donation_date $whereDate" : "") . " GROUP BY payment_method");
        $byMethod->execute($params);

        return [
            'ok'=>true, 'kind'=>'donations',
            'rows'=>$rows,
            'aggregates'=>[
                'total'   => (float)$agg['total'],
                'count'   => (int)$agg['c'],
                'average' => round((float)$agg['avg'], 2),
                'max'     => (float)$agg['mx'],
            ],
            'breakdown_by_method' => $byMethod->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private static function donors(PDO $db, string $whereDate, array $params, array $user): array {
        $sql = "SELECT dn.id, dn.name, dn.email, dn.phone, dn.donor_type, dn.pan,
                       COUNT(d.id) AS gifts,
                       COALESCE(SUM(d.amount),0) AS total,
                       MAX(d.donation_date) AS last_gift
                FROM donors dn
                LEFT JOIN donations d ON d.donor_id = dn.id";
        if ($whereDate) $sql .= " AND d.donation_date $whereDate";
        $sql .= " GROUP BY dn.id ORDER BY total DESC LIMIT 200";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = AetherRBAC::redactRows($stmt->fetchAll(PDO::FETCH_ASSOC), $user);

        return [
            'ok'=>true, 'kind'=>'donors',
            'rows'=>$rows,
            'aggregates'=>[
                'total_donors' => count($rows),
                'top_giver_amount' => $rows ? (float)$rows[0]['total'] : 0,
                'inactive_donors_90d' => count(array_filter($rows, fn($r) => !$r['last_gift'] || (strtotime($r['last_gift']) < strtotime('-90 days')))),
            ],
        ];
    }

    private static function expenses(PDO $db, string $whereDate, array $params, array $user): array {
        $sql = "SELECT e.id, e.expense_category, e.amount, e.expense_date, e.description,
                       p.program_name
                FROM expenses e LEFT JOIN programs p ON p.id = e.program_id";
        if ($whereDate) $sql .= " WHERE e.expense_date $whereDate";
        $sql .= " ORDER BY e.expense_date DESC LIMIT 200";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = AetherRBAC::redactRows($stmt->fetchAll(PDO::FETCH_ASSOC), $user);

        $byCat = $db->prepare("SELECT expense_category, SUM(amount) total, COUNT(*) c FROM expenses" .
                              ($whereDate ? " WHERE expense_date $whereDate" : "") . " GROUP BY expense_category ORDER BY total DESC");
        $byCat->execute($params);

        $sumSql = "SELECT COALESCE(SUM(amount),0) total, COUNT(*) c FROM expenses" .
                  ($whereDate ? " WHERE expense_date $whereDate" : "");
        $aStmt = $db->prepare($sumSql); $aStmt->execute($params);
        $agg = $aStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'ok'=>true, 'kind'=>'expenses', 'rows'=>$rows,
            'aggregates'=>['total'=>(float)$agg['total'], 'count'=>(int)$agg['c']],
            'breakdown_by_category'=>$byCat->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private static function employees(PDO $db, array $user): array {
        $rows = $db->query("SELECT id, name, employee_code, designation, department,
                                   email, phone, basic_salary, status
                            FROM employees ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $rows = AetherRBAC::redactRows($rows, $user);
        $depts = [];
        foreach ($rows as $r) {
            $d = $r['department'] ?? 'Unknown';
            $depts[$d] = ($depts[$d] ?? 0) + 1;
        }
        return ['ok'=>true, 'kind'=>'employees', 'rows'=>$rows,
                'aggregates'=>['count'=>count($rows), 'departments'=>$depts]];
    }
    private static function volunteers(PDO $db, array $user): array {
        $rows = $db->query("SELECT id, name, volunteer_code, designation, department, email, phone FROM volunteers ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $rows = AetherRBAC::redactRows($rows, $user);
        return ['ok'=>true, 'kind'=>'volunteers', 'rows'=>$rows, 'aggregates'=>['count'=>count($rows)]];
    }
    private static function inventory(PDO $db, array $user): array {
        $rows = $db->query("SELECT id, item_name, quantity, unit, category, min_stock,
                                   CASE WHEN min_stock>0 AND quantity<min_stock THEN 1 ELSE 0 END AS low
                            FROM inventory_items ORDER BY low DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $low = array_filter($rows, fn($r)=>$r['low'] == 1);
        return ['ok'=>true, 'kind'=>'inventory', 'rows'=>$rows,
                'aggregates'=>['count'=>count($rows), 'low_stock'=>count($low)]];
    }
    private static function programs(PDO $db, array $user): array {
        try {
            $rows = $db->query("SELECT p.id, p.program_name, p.description, p.start_date, p.end_date, p.status,
                                       COALESCE(p.budget,0) budget,
                                       COALESCE((SELECT SUM(amount) FROM expenses WHERE program_id = p.id),0) spent,
                                       COALESCE((SELECT SUM(amount) FROM donations WHERE program_id = p.id),0) raised
                                FROM programs p ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $rows = []; }
        return ['ok'=>true, 'kind'=>'programs', 'rows'=>$rows,
                'aggregates'=>['count'=>count($rows)]];
    }
}
