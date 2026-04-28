<?php
/**
 * Aether v2 — Role-Based Access Control (RBAC) data scoping.
 *
 * Defines what slices of ERP data each role is allowed to see *through Aether*.
 * super_admin  : everything (no filtering)
 * admin        : everything except cross-org system controls
 * manager      : donations, expenses, programs, inventory, volunteers — but
 *                NOT employee salaries/personal info, NOT donor PII (email/phone)
 * accountant   : donations, expenses (full) — donor PII allowed for receipts.
 *                NO employee salaries, NO HR personal data.
 * hr           : employees, payroll, volunteers — NO donations/expenses detail
 *                (counts only). Sees employee personal info.
 * editor       : donors (no contact PII), gallery, blog — NO financial data,
 *                NO HR data.
 * viewer       : aggregate counts only — NO PII, NO amounts, NO names.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherRBAC
{
    /**
     * Modules each role can see at all. Anything outside the list is denied.
     * 'all' is a wildcard for super_admin/admin.
     */
    private const MODULE_ACCESS = [
        'super_admin' => ['all'],
        'admin'       => ['all'],
        'manager'     => ['donations','expenses','programs','inventory','volunteers','cms','audit_overview'],
        'accountant'  => ['donations','expenses','audit_overview'],
        'hr'          => ['hr','volunteers','audit_overview'],
        'editor'      => ['donors_basic','cms','programs_overview'],
        'viewer'      => ['overview_counts'],
    ];

    /** Fields hidden per role. Applied row-by-row. */
    private const FIELD_REDACTIONS = [
        'manager'    => ['email','phone','pan','basic_salary','net_salary','hra','da','password'],
        'accountant' => ['basic_salary','net_salary','hra','da','password','pan'],
        'hr'         => ['amount','transaction_id','expense_category','password'],
        'editor'     => ['email','phone','pan','amount','basic_salary','net_salary','password'],
        'viewer'     => ['email','phone','pan','amount','basic_salary','net_salary','transaction_id',
                         'donor_name','description','address','password','content','excerpt'],
    ];

    public static function canSeeModule(string $module, array $user): bool {
        $role = $user['role'] ?? 'viewer';
        $allowed = self::MODULE_ACCESS[$role] ?? [];
        if (in_array('all', $allowed, true)) return true;
        // map composite module names
        $mapping = [
            'donations' => ['donations','audit_overview'],
            'expenses'  => ['expenses','audit_overview'],
            'hr'        => ['hr'],
            'inventory' => ['inventory'],
            'programs'  => ['programs','programs_overview'],
            'volunteers'=> ['volunteers'],
            'cms'       => ['cms'],
            'audit'     => ['audit_overview'],
            'donors'    => ['donations','donors_basic'],
        ];
        $candidates = $mapping[$module] ?? [$module];
        foreach ($candidates as $c) if (in_array($c, $allowed, true)) return true;
        return false;
    }

    /** Strip restricted fields from a single row. */
    public static function redactRow(array $row, array $user): array {
        $role = $user['role'] ?? 'viewer';
        if (in_array($role, ['super_admin','admin'], true)) return $row;
        $hide = self::FIELD_REDACTIONS[$role] ?? [];
        if (!$hide) return $row;
        foreach ($hide as $k) {
            if (array_key_exists($k, $row)) $row[$k] = '••• redacted •••';
        }
        return $row;
    }

    public static function redactRows(array $rows, array $user): array {
        return array_map(fn($r) => self::redactRow($r, $user), $rows);
    }

    /**
     * For viewer / editor: collapse a list of rows into just an aggregate count
     * so they never see individual records. Returns ['count' => N] when blocked,
     * or the original rows when allowed.
     */
    public static function aggregateOrPass(array $rows, string $module, array $user): array|int {
        $role = $user['role'] ?? 'viewer';
        if ($role === 'viewer') return count($rows);   // viewer gets only the count
        return $rows;
    }

    /**
     * Modules a user is allowed to bulk-import via CSV. Stricter than viewing.
     */
    public static function csvImportableModules(array $user): array {
        $role = $user['role'] ?? '';
        $map = [
            'super_admin' => ['donors','donations','expenses','employees','volunteers','inventory','programs'],
            'admin'       => ['donors','donations','expenses','employees','volunteers','inventory','programs'],
            'manager'     => ['donors','donations','expenses','volunteers','inventory','programs'],
            'accountant'  => ['donors','donations','expenses'],
            'hr'          => ['employees','volunteers'],
            'editor'      => ['donors','volunteers'],
            'viewer'      => [],
        ];
        return $map[$role] ?? [];
    }

    /**
     * Human-readable description of what the current user is allowed to see.
     * Used by Aether's chat to explain permissions transparently.
     */
    public static function describe(array $user): string {
        $role = $user['role'] ?? 'viewer';
        $lines = [
            'super_admin' => 'You have full access — every record, every field, every action.',
            'admin'       => 'You have full data access. System-level toggles (schema sync, self-heal) are also enabled.',
            'manager'     => 'You can see donations, expenses, programs, inventory, and volunteers. Donor contact info, employee salaries, and PAN numbers are redacted for privacy.',
            'accountant'  => 'You can see donations and expenses in full (including donor contacts for receipts). Employee salaries and HR personal data are hidden.',
            'hr'          => 'You can see employees, payroll, and volunteers. Donations and expenses appear only as aggregate counts.',
            'editor'      => 'You can see donor names (no contacts), the gallery, and blog posts. Financial data and HR data are not exposed.',
            'viewer'      => 'You see aggregate counts only — no individual records, no PII, no monetary amounts.',
        ];
        return $lines[$role] ?? 'Your role has limited Aether access.';
    }
}
