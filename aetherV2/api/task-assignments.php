<?php
/**
 * Aether v2 — Task Assignments
 *
 * Super-admin can re-assign any pending plan to any user with a note. The
 * assigned user sees the plan in their "Tasks" list (panel) and can approve
 * or reject. Super-admin can also approve/reject plans on behalf of others
 * directly from the Command Centre when a task is delayed.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherTaskAssignments
{
    public static function ensureTable(PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS aether_task_assignments (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                plan_id      INT NOT NULL,
                assigner_id  INT NOT NULL,
                assignee_id  INT NOT NULL,
                note         TEXT,
                status       ENUM('pending','done','withdrawn') DEFAULT 'pending',
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (assignee_id, status),
                INDEX (plan_id),
                UNIQUE KEY uniq_active (plan_id, assignee_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** List ALL pending plans across users (super_admin / admin only). */
    public static function listAllPending(array $user, ?string $intent = null, ?int $assignedTo = null): array {
        if (!aether_is_admin($user)) return ['ok' => false, 'error' => 'Admin only'];
        $db = aether_db();
        $sql = "SELECT p.id, p.user_id, p.intent, p.preview, p.status, p.created_at,
                       u.username, u.full_name, r.role_name AS role,
                       (SELECT COUNT(*) FROM aether_task_assignments a WHERE a.plan_id = p.id AND a.status='pending') AS active_assignments,
                       TIMESTAMPDIFF(HOUR, p.created_at, NOW()) AS age_hours
                FROM aether_action_plans p
                LEFT JOIN users u ON u.id = p.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE p.status = 'proposed'";
        $params = [];
        if ($intent) { $sql .= " AND p.intent = ?"; $params[] = $intent; }
        if ($assignedTo) {
            $sql .= " AND p.id IN (SELECT plan_id FROM aether_task_assignments WHERE assignee_id = ? AND status='pending')";
            $params[] = $assignedTo;
        }
        $sql .= " ORDER BY p.created_at DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // attach assignment list per plan
        if ($rows) {
            $ids = array_map(fn($r)=>$r['id'], $rows);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $aStmt = $db->prepare(
                "SELECT a.plan_id, a.id AS assignment_id, a.assignee_id, a.note, a.status, a.created_at,
                        u.full_name AS assignee_name, r.role_name AS assignee_role
                 FROM aether_task_assignments a
                 LEFT JOIN users u ON u.id = a.assignee_id
                 LEFT JOIN roles r ON r.id = u.role_id
                 WHERE a.plan_id IN ($place) AND a.status='pending'"
            );
            $aStmt->execute($ids);
            $byPlan = [];
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $byPlan[$a['plan_id']][] = $a;
            }
            foreach ($rows as &$r) {
                $r['assignments'] = $byPlan[$r['id']] ?? [];
            }
        }

        // Summary counts
        $summary = $db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) > 48 THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24 THEN 1 ELSE 0 END) AS aging
             FROM aether_action_plans WHERE status='proposed'"
        )->fetch(PDO::FETCH_ASSOC);

        return ['ok' => true, 'plans' => $rows, 'summary' => $summary];
    }

    /** Assign a plan to a user with an optional note. */
    public static function assign(array $user, int $planId, int $assigneeId, string $note = ''): array {
        if (!aether_is_admin($user)) return ['ok' => false, 'error' => 'Admin only'];
        $db = aether_db();
        $plan = $db->prepare("SELECT id, intent, status FROM aether_action_plans WHERE id = ?");
        $plan->execute([$planId]);
        $p = $plan->fetch();
        if (!$p) return ['ok' => false, 'error' => 'Plan not found'];
        if ($p['status'] !== 'proposed') return ['ok' => false, 'error' => "Plan already {$p['status']}"];

        $u = $db->prepare("SELECT u.id, u.username, u.full_name, r.role_name AS role
                           FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
        $u->execute([$assigneeId]);
        $assignee = $u->fetch();
        if (!$assignee) return ['ok' => false, 'error' => 'Assignee not found'];

        // close any prior pending assignments for this plan
        $db->prepare("UPDATE aether_task_assignments SET status='withdrawn' WHERE plan_id = ? AND status='pending'")
           ->execute([$planId]);
        $db->prepare(
            "INSERT INTO aether_task_assignments (plan_id, assigner_id, assignee_id, note)
             VALUES (?, ?, ?, ?)"
        )->execute([$planId, $user['id'], $assigneeId, $note]);
        $assignmentId = (int)$db->lastInsertId();

        AetherAudit::log(
            'task_assigned',
            "Plan #$planId ({$p['intent']}) assigned to {$assignee['full_name']} ({$assignee['role']})",
            ['plan_id' => $planId, 'assignee_id' => $assigneeId, 'note' => $note],
            'low', $user['id'], "plan:$planId"
        );
        return ['ok' => true, 'assignment_id' => $assignmentId,
                'assignee' => ['id'=>$assignee['id'], 'name'=>$assignee['full_name'], 'role'=>$assignee['role']]];
    }

    /** Pending plans assigned to current user. */
    public static function assignedToMe(array $user): array {
        $db = aether_db();
        $stmt = $db->prepare(
            "SELECT a.id AS assignment_id, a.plan_id, a.note, a.created_at AS assigned_at,
                    p.intent, p.preview, p.status,
                    u.full_name AS assigner_name, r.role_name AS assigner_role
             FROM aether_task_assignments a
             LEFT JOIN aether_action_plans p ON p.id = a.plan_id
             LEFT JOIN users u ON u.id = a.assigner_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE a.assignee_id = ? AND a.status='pending' AND p.status='proposed'
             ORDER BY a.created_at DESC LIMIT 50"
        );
        $stmt->execute([$user['id']]);
        return ['ok' => true, 'assignments' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** List of users that super_admin can assign tasks to. */
    public static function userList(array $user): array {
        if (!aether_is_admin($user)) return ['ok' => false, 'error' => 'Admin only'];
        $db = aether_db();
        $stmt = $db->query(
            "SELECT u.id, u.username, u.full_name, u.email, r.role_name AS role
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE (u.is_active = 1 OR u.is_active IS NULL)
               AND r.role_name IN ('super_admin','admin','manager','accountant','hr','editor')
             ORDER BY FIELD(r.role_name,'super_admin','admin','manager','accountant','hr','editor'), u.full_name ASC"
        );
        return ['ok' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Mark assignments done when plan is approved/rejected by anyone. */
    public static function markPlanResolved(int $planId): void {
        try {
            aether_db()->prepare(
                "UPDATE aether_task_assignments SET status='done' WHERE plan_id = ? AND status='pending'"
            )->execute([$planId]);
        } catch (\Throwable $e) {}
    }
}
