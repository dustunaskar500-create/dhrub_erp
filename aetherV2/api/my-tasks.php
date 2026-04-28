<?php
/**
 * Aether v2 — My Tasks
 *
 * For the logged-in user, build a role-aware task list:
 *   • Plans they personally proposed but haven't approved/rejected yet
 *   • Role-specific actionables:
 *       super_admin / admin — schema changes pending review, critical health issues, long-inactive donors
 *       accountant         — unapproved expenses, pending donation reconciliation
 *       hr                 — employees missing contact info, payroll mismatches
 *       editor             — blog drafts, gallery items without descriptions
 *       manager            — programs over budget, low-stock items
 *       viewer             — none (read-only)
 */

require_once __DIR__ . '/bootstrap.php';

class AetherMyTasks
{
    public static function for_(array $user): array {
        $db = aether_db();
        $tasks = [];

        // Universal: plans this user proposed, still pending
        try {
            $stmt = $db->prepare(
                "SELECT id, intent, preview, created_at FROM aether_action_plans
                 WHERE user_id = ? AND status = 'proposed' ORDER BY id DESC LIMIT 10"
            );
            $stmt->execute([$user['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $tasks[] = [
                    'kind' => 'pending_plan',
                    'priority' => 'medium',
                    'title' => 'Plan #' . $p['id'] . ' — ' . $p['intent'],
                    'detail' => mb_substr($p['preview'] ?? '', 0, 200),
                    'cta' => 'Approve or reject in the *Plans* tab',
                    'created_at' => $p['created_at'],
                ];
            }
        } catch (\Throwable $e) {}

        $role = $user['role'] ?? '';

        // Super admin / admin
        if (in_array($role, ['super_admin','admin'], true)) {
            $unack = (int)$db->query("SELECT COUNT(*) FROM aether_schema_changes WHERE acknowledged=0")->fetchColumn();
            if ($unack > 0) $tasks[] = [
                'kind'=>'schema_review','priority'=>'medium',
                'title'=>"$unack unreviewed schema change(s)",
                'detail'=>"Aether detected schema modifications. Review them in the dashboard's Schema Diff Viewer.",
                'cta'=>'Open dashboard',
            ];
            $crit = (int)$db->query("SELECT COUNT(*) FROM aether_issues WHERE status='open' AND severity IN ('high','critical')")->fetchColumn();
            if ($crit > 0) $tasks[] = [
                'kind'=>'critical_issue','priority'=>'high',
                'title'=>"$crit critical / high-severity issue(s) open",
                'detail'=>"Run *self-heal* or inspect them on the dashboard's Health card.",
                'cta'=>'Run self-heal',
            ];
            $inactive90 = (int)$db->query("SELECT COUNT(*) FROM (SELECT dn.id FROM donors dn LEFT JOIN donations d ON d.donor_id=dn.id GROUP BY dn.id HAVING MAX(d.donation_date) IS NOT NULL AND DATEDIFF(CURRENT_DATE,MAX(d.donation_date)) >= 90) x")->fetchColumn();
            if ($inactive90 > 0) $tasks[] = [
                'kind'=>'lapsed_donors','priority'=>'medium',
                'title'=>"$inactive90 donor(s) inactive 90+ days",
                'detail'=>"Run donation reminders to re-engage them.",
                'cta'=>'Type *donation reminders*',
            ];
        }

        // Accountant / Manager
        if (in_array($role, ['super_admin','admin','accountant','manager'], true)) {
            try {
                $cnt = (int)$db->query("SELECT COUNT(*) FROM expenses WHERE status IS NULL OR status='' OR status='pending'")->fetchColumn();
                if ($cnt > 0) $tasks[] = [
                    'kind'=>'pending_expenses','priority'=>'medium',
                    'title'=>"$cnt expense(s) awaiting approval",
                    'detail'=>"Review and approve via *approve expense #ID*.",
                ];
            } catch (\Throwable $e) {}
        }

        // HR
        if (in_array($role, ['super_admin','admin','hr'], true)) {
            try {
                $cnt = (int)$db->query("SELECT COUNT(*) FROM employees WHERE (email IS NULL OR email='') OR (phone IS NULL OR phone='')")->fetchColumn();
                if ($cnt > 0) $tasks[] = [
                    'kind'=>'employee_contact','priority'=>'low',
                    'title'=>"$cnt employee(s) missing email or phone",
                    'detail'=>"Update via your HR module — Aether can also help with *update employee contact*.",
                ];
            } catch (\Throwable $e) {}
        }

        // Editor
        if (in_array($role, ['super_admin','admin','editor'], true)) {
            try {
                $cnt = (int)$db->query("SELECT COUNT(*) FROM blog_posts WHERE is_published=0 OR is_published IS NULL")->fetchColumn();
                if ($cnt > 0) $tasks[] = [
                    'kind'=>'blog_drafts','priority'=>'low',
                    'title'=>"$cnt blog draft(s) waiting to be published",
                    'detail'=>"Publish them in your CMS, or ask Aether to *suggest blog about ...*.",
                ];
                $cnt2 = (int)$db->query("SELECT COUNT(*) FROM gallery WHERE description IS NULL OR description=''")->fetchColumn();
                if ($cnt2 > 0) $tasks[] = [
                    'kind'=>'gallery_captions','priority'=>'low',
                    'title'=>"$cnt2 gallery image(s) without descriptions",
                    'detail'=>"Drop the image into the Aether chat — I'll suggest captions and alt-text.",
                ];
            } catch (\Throwable $e) {}
        }

        // Manager: low stock + programs
        if (in_array($role, ['super_admin','admin','manager','editor'], true)) {
            try {
                $cnt = (int)$db->query("SELECT COUNT(*) FROM inventory_items WHERE min_stock > 0 AND quantity < min_stock")->fetchColumn();
                if ($cnt > 0) $tasks[] = [
                    'kind'=>'low_stock','priority'=>'medium',
                    'title'=>"$cnt inventory item(s) below minimum stock",
                    'detail'=>"Restock or adjust thresholds. Type *low stock* for details.",
                ];
            } catch (\Throwable $e) {}
        }

        if (!$tasks) {
            return ['text' => "✓ All clear, **{$user['full_name']}**. Nothing on your plate right now. Want me to surface ideas for proactive work? Try *report on donations this quarter*."];
        }

        $byPri = ['high'=>[],'medium'=>[],'low'=>[]];
        foreach ($tasks as $t) $byPri[$t['priority'] ?? 'low'][] = $t;
        $lines = ["**Your tasks, " . htmlspecialchars($user['full_name'] ?? $user['username']) . " (" . $role . ")** — " . count($tasks) . " item(s)", ""];
        foreach (['high','medium','low'] as $pri) {
            if (!$byPri[$pri]) continue;
            $emoji = $pri === 'high' ? '🔴' : ($pri === 'medium' ? '🟡' : '🟢');
            $lines[] = "$emoji **" . strtoupper($pri) . "**";
            foreach ($byPri[$pri] as $t) {
                $lines[] = "• **{$t['title']}** — {$t['detail']}";
                if (!empty($t['cta'])) $lines[] = "   _→ {$t['cta']}_";
            }
            $lines[] = "";
        }
        $lines[] = "_Tap any chip below or type a command to act._";
        return ['text' => implode("\n", $lines), 'cards' => [
            ['label'=>'High','value'=>count($byPri['high']),'icon'=>'circle-exclamation'],
            ['label'=>'Medium','value'=>count($byPri['medium']),'icon'=>'triangle-exclamation'],
            ['label'=>'Low','value'=>count($byPri['low']),'icon'=>'circle-info'],
            ['label'=>'Total','value'=>count($tasks),'icon'=>'list-check'],
        ]];
    }
}
