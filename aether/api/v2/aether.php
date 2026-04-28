<?php
/**
 * Aether v2 — Unified API Router
 *
 * Single endpoint serving every Aether v2 capability:
 *   POST  /aether/api/v2/aether.php    body { action, ... }
 *
 * Actions:
 *   chat              { message, conversation_id? }
 *   feedback          { score: -1|0|1 }
 *   approve_plan      { plan_id }
 *   reject_plan       { plan_id }
 *   list_plans        { status? }
 *   dashboard
 *   schema_sync
 *   schema_changes
 *   knowledge_summary
 *   knowledge_search  { query }
 *   health            { auto_heal? }
 *   issues            { status? }
 *   audit             { limit?, type? }
 *   learning_stats
 *   describe          { table }
 *   tick              (run all background jobs once)
 *
 * Auth: Bearer JWT from the existing ERP. Role-based gating where applicable.
 */

require_once __DIR__ . '/bootstrap.php';

// Always run migrations on first call (fast, idempotent)
require_once __DIR__ . '/migrate.php';
aether_run_migrations();

require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/schema-watcher.php';
require_once __DIR__ . '/knowledge-graph.php';
require_once __DIR__ . '/nlp-engine.php';
require_once __DIR__ . '/reasoner.php';
require_once __DIR__ . '/error-monitor.php';
require_once __DIR__ . '/learning-engine.php';

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$user = aether_require_user();
$body = aether_body();
$action = $_GET['action'] ?? ($body['action'] ?? 'chat');
$db = aether_db();

try {
    switch ($action) {

        // ── Conversational entry point ──────────────────────────────────
        case 'chat': {
            $message = trim((string)($body['message'] ?? ''));
            $conv    = trim((string)($body['conversation_id'] ?? 'default')) ?: 'default';
            if ($message === '') aether_error('Empty message', 400);

            // Persist into the existing aether_memory table for continuity
            try {
                $db->prepare("INSERT INTO aether_memory (user_id, conversation_id, role, content) VALUES (?,?,?,?)")
                   ->execute([(int)$user['id'], $conv, 'user', $message]);
            } catch (\Throwable $e) {}

            // Special: explicit approve/reject of last plan via natural language
            if (preg_match('/^\s*(approve|confirm|do it|yes execute)\b/i', $message)) {
                $row = $db->prepare("SELECT id FROM aether_action_plans WHERE user_id=? AND status='proposed' ORDER BY id DESC LIMIT 1");
                $row->execute([$user['id']]);
                $pid = (int)$row->fetchColumn();
                if ($pid) {
                    $r = (new AetherReasoner($user, $db))->executePlan($pid);
                    $reply = $r['ok']
                        ? "✓ Plan #$pid executed successfully."
                        : "✗ Execution failed: " . ($r['error'] ?? 'unknown');
                    persistReply($db, $user, $conv, $reply);
                    aether_json(['action' => 'chat', 'reply' => $reply, 'plan' => ['id' => $pid, 'status' => $r['ok'] ? 'executed' : 'failed']]);
                }
            }
            if (preg_match('/^\s*(reject|cancel|abort|no don\'?t)\b/i', $message)) {
                $row = $db->prepare("SELECT id FROM aether_action_plans WHERE user_id=? AND status='proposed' ORDER BY id DESC LIMIT 1");
                $row->execute([$user['id']]);
                $pid = (int)$row->fetchColumn();
                if ($pid) {
                    (new AetherReasoner($user, $db))->rejectPlan($pid);
                    persistReply($db, $user, $conv, "Plan #$pid rejected. No changes made.");
                    aether_json(['action' => 'chat', 'reply' => "Plan #$pid rejected. No changes made.", 'plan' => ['id' => $pid, 'status' => 'rejected']]);
                }
            }

            $reasoner = new AetherReasoner($user, $db);
            $resp = $reasoner->reason($message);
            persistReply($db, $user, $conv, $resp['reply']);

            aether_json([
                'action'     => 'chat',
                'reply'      => $resp['reply'],
                'cards'      => $resp['cards'] ?? [],
                'plan'       => $resp['plan'] ?? null,
                'mode'       => $resp['mode'] ?? 'answer',
                'intent'     => $resp['intent'],
                'confidence' => $resp['confidence'],
                'kg_matches' => array_slice($resp['kg_matches'] ?? [], 0, 6),
            ]);
        }

        // ── Conversation history ────────────────────────────────────────
        case 'history': {
            $conv = trim((string)($body['conversation_id'] ?? ($_GET['conversation_id'] ?? 'default'))) ?: 'default';
            $stmt = $db->prepare("SELECT role, content, created_at FROM aether_memory WHERE user_id=? AND conversation_id=? ORDER BY id ASC LIMIT 100");
            $stmt->execute([$user['id'], $conv]);
            aether_json(['action' => 'history', 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'clear_history': {
            $conv = trim((string)($body['conversation_id'] ?? 'default')) ?: 'default';
            $db->prepare("DELETE FROM aether_memory WHERE user_id=? AND conversation_id=?")->execute([$user['id'], $conv]);
            aether_json(['action' => 'clear_history', 'ok' => true]);
        }

        // ── Plan workflow ───────────────────────────────────────────────
        case 'list_plans': {
            $status = (string)($body['status'] ?? ($_GET['status'] ?? 'proposed'));
            $stmt = $db->prepare("SELECT * FROM aether_action_plans WHERE user_id=? " .
                ($status ? "AND status=? " : "") . "ORDER BY id DESC LIMIT 50");
            $params = [$user['id']];
            if ($status) $params[] = $status;
            $stmt->execute($params);
            aether_json(['action' => 'list_plans', 'plans' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'approve_plan': {
            $pid = (int)($body['plan_id'] ?? 0);
            if (!$pid) aether_error('plan_id required');
            $r = (new AetherReasoner($user, $db))->executePlan($pid);
            aether_json(['action' => 'approve_plan'] + $r);
        }

        case 'reject_plan': {
            $pid = (int)($body['plan_id'] ?? 0);
            if (!$pid) aether_error('plan_id required');
            $r = (new AetherReasoner($user, $db))->rejectPlan($pid);
            aether_json(['action' => 'reject_plan'] + $r);
        }

        // ── Dashboard data ──────────────────────────────────────────────
        case 'dashboard': {
            $reasoner = new AetherReasoner($user, $db);
            $monitor = new AetherErrorMonitor($db);
            $kg = new AetherKnowledgeGraph($db);
            $watcher = new AetherSchemaWatcher($db);

            $health = $monitor->runAll(false);
            $kgSum  = $kg->summary();
            $last   = $watcher->lastSnapshot();
            $audit  = AetherAudit::recent(20);
            $auditCounts = AetherAudit::counts('24 HOUR');
            $learn  = (new AetherLearning($db))->stats();

            // dashboard cards (high-level KPIs)
            $dashCards = $reasoner->reason('show dashboard');

            aether_json([
                'action' => 'dashboard',
                'kpis'   => $dashCards['cards'] ?? [],
                'health' => [
                    'overall'      => $health['overall'],
                    'issue_count'  => $health['issue_count'],
                    'healed_count' => $health['healed_count'],
                    'checks'       => $health['checks'],
                ],
                'knowledge' => $kgSum,
                'schema' => [
                    'fingerprint' => $last['fingerprint'] ?? null,
                    'taken_at'    => $last['taken_at'] ?? null,
                    'tables'      => (int)($last['table_count'] ?? 0),
                    'columns'     => (int)($last['column_count'] ?? 0),
                ],
                'audit' => [
                    'recent'  => $audit,
                    'counts'  => $auditCounts,
                ],
                'learning' => $learn,
            ]);
        }

        // ── Schema awareness ────────────────────────────────────────────
        case 'schema_sync': {
            if (!aether_is_admin($user)) aether_error('Admin only', 403);
            $watcher = new AetherSchemaWatcher($db);
            $r = $watcher->sync();
            // rebuild knowledge graph on any change (or first run)
            if ($r['changed']) {
                (new AetherKnowledgeGraph($db))->rebuild();
                AetherAudit::log('knowledge_rebuild', 'Knowledge graph rebuilt after schema change', [], 'medium');
            }
            aether_json(['action' => 'schema_sync'] + $r);
        }

        case 'schema_changes': {
            $r = (new AetherSchemaWatcher($db))->recentChanges(50);
            aether_json(['action' => 'schema_changes', 'changes' => $r]);
        }

        case 'knowledge_summary': {
            aether_json(['action' => 'knowledge_summary', 'data' => (new AetherKnowledgeGraph($db))->summary()]);
        }

        case 'knowledge_search': {
            $q = (string)($body['query'] ?? ($_GET['query'] ?? ''));
            aether_json(['action' => 'knowledge_search', 'matches' => (new AetherKnowledgeGraph($db))->findEntities($q, 25)]);
        }

        case 'describe': {
            $tbl = (string)($body['table'] ?? ($_GET['table'] ?? ''));
            if (!$tbl) aether_error('table required');
            $stmt = $db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
            $stmt->execute([DB_NAME, $tbl]);
            aether_json(['action' => 'describe', 'table' => $tbl, 'columns' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        // ── Health & issues ─────────────────────────────────────────────
        case 'health': {
            $heal = (bool)($body['auto_heal'] ?? ($_GET['auto_heal'] ?? false));
            if ($heal && !aether_is_admin($user)) aether_error('Self-heal is admin-only', 403);
            $r = (new AetherErrorMonitor($db))->runAll($heal);
            aether_json(['action' => 'health'] + $r);
        }

        case 'issues': {
            $status = (string)($body['status'] ?? ($_GET['status'] ?? 'open'));
            aether_json(['action' => 'issues', 'issues' => (new AetherErrorMonitor($db))->listIssues($status, 100)]);
        }

        case 'self_heal': {
            if (!aether_is_admin($user)) aether_error('Admin only', 403);
            $r = (new AetherErrorMonitor($db))->runAll(true);
            aether_json(['action' => 'self_heal'] + $r);
        }

        // ── Audit & learning ────────────────────────────────────────────
        case 'audit': {
            $limit = (int)($body['limit'] ?? ($_GET['limit'] ?? 50));
            $type  = (string)($body['type'] ?? ($_GET['type'] ?? '')) ?: null;
            aether_json(['action' => 'audit', 'events' => AetherAudit::recent($limit, $type)]);
        }

        case 'learning_stats': {
            aether_json(['action' => 'learning_stats', 'stats' => (new AetherLearning($db))->stats()]);
        }

        case 'feedback': {
            $score = (int)($body['score'] ?? 0);
            aether_json(['action' => 'feedback'] + (new AetherLearning($db))->recordFeedback((int)$user['id'], $score));
        }

        // ── Background tick ─────────────────────────────────────────────
        case 'tick': {
            $watcher = new AetherSchemaWatcher($db);
            $sync = $watcher->sync();
            if ($sync['changed']) (new AetherKnowledgeGraph($db))->rebuild();

            $monitor = new AetherErrorMonitor($db);
            $report  = $monitor->runAll(false);

            aether_json([
                'action'  => 'tick',
                'schema'  => ['changed' => $sync['changed'], 'changes' => count($sync['changes'])],
                'health'  => ['overall' => $report['overall'], 'issues' => $report['issue_count']],
            ]);
        }

        case 'identity': {
            aether_json([
                'action' => 'identity',
                'user' => [
                    'id'        => $user['id'],
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                    'is_admin'  => aether_is_admin($user),
                ],
            ]);
        }

        default:
            aether_error("Unknown action: $action", 400);
    }
} catch (\Throwable $e) {
    AetherAudit::log('api_error', $e->getMessage(), ['trace' => $e->getTraceAsString()], 'high', $user['id'] ?? null);
    aether_error('Internal error: ' . $e->getMessage(), 500);
}

function persistReply(PDO $db, array $user, string $conv, string $reply): void {
    try {
        $db->prepare("INSERT INTO aether_memory (user_id, conversation_id, role, content) VALUES (?,?,?,?)")
           ->execute([(int)$user['id'], $conv, 'assistant', $reply]);
    } catch (\Throwable $e) {}
}
