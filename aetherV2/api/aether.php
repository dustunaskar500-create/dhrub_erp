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

            // Special: explicit approve of last plan via natural language
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
            // Reject latest plan — only if NO pending multi-turn intent (cancel is reserved for that)
            if (preg_match('/^\s*(reject|abort)\b/i', $message)) {
                require_once __DIR__ . '/pending-intents.php';
                $hasPending = AetherPendingIntents::open((int)$user['id'], $conv);
                if (!$hasPending) {
                    $row = $db->prepare("SELECT id FROM aether_action_plans WHERE user_id=? AND status='proposed' ORDER BY id DESC LIMIT 1");
                    $row->execute([$user['id']]);
                    $pid = (int)$row->fetchColumn();
                    if ($pid) {
                        (new AetherReasoner($user, $db))->rejectPlan($pid);
                        persistReply($db, $user, $conv, "Plan #$pid rejected. No changes made.");
                        aether_json(['action' => 'chat', 'reply' => "Plan #$pid rejected. No changes made.", 'plan' => ['id' => $pid, 'status' => 'rejected']]);
                    }
                }
            }

            $reasoner = new AetherReasoner($user, $db);
            $reasoner->setConversation($conv);
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
            $stmt->execute([AETHER_DB_NAME, $tbl]);
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

        // ── Schema diff (visual viewer) ─────────────────────────────────
        case 'schema_diff': {
            $stmt = $db->query("SELECT id, fingerprint, table_count, column_count, fk_count, taken_at FROM aether_schema_snapshots ORDER BY id DESC LIMIT 2");
            $snaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($snaps) < 1) aether_json(['action'=>'schema_diff','snapshots'=>[],'changes'=>[]]);
            $changesStmt = $db->prepare("SELECT * FROM aether_schema_changes ORDER BY id DESC LIMIT 200");
            $changesStmt->execute();
            aether_json([
                'action'    => 'schema_diff',
                'snapshots' => $snaps,
                'changes'   => $changesStmt->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        // ── PDF receipts & payslips ─────────────────────────────────────
        case 'download_receipt': {
            require_once __DIR__ . '/pdf-receipt.php';
            $id = (int)($body['donation_id'] ?? ($_GET['donation_id'] ?? 0));
            if (!$id) aether_error('donation_id required');
            AetherPDF::streamReceipt($id);
        }
        case 'download_payslip': {
            require_once __DIR__ . '/pdf-receipt.php';
            $id = (int)($body['payroll_id'] ?? ($_GET['payroll_id'] ?? 0));
            if (!$id) aether_error('payroll_id required');
            AetherPDF::streamPayslip($id);
        }

        // ── CSV bulk import ─────────────────────────────────────────────
        case 'csv_template': {
            require_once __DIR__ . '/csv-importer.php';
            $module = (string)($body['module'] ?? ($_GET['module'] ?? ''));
            if (!$module) aether_error('module required');
            AetherCsvImporter::streamTemplate($module);
        }
        case 'csv_import_preview': {
            require_once __DIR__ . '/csv-importer.php';
            $module = (string)($body['module'] ?? '');
            $data   = (string)($body['data']   ?? '');
            $name   = (string)($body['filename'] ?? 'upload.csv');
            if (!$module || !$data) aether_error('module + data (base64 csv) required');
            aether_json(['action'=>'csv_import_preview'] + AetherCsvImporter::preview($user, $module, $data, $name));
        }
        case 'csv_import_execute': {
            require_once __DIR__ . '/csv-importer.php';
            $importId = (int)($body['import_id'] ?? 0);
            if (!$importId) aether_error('import_id required');
            aether_json(['action'=>'csv_import_execute'] + AetherCsvImporter::execute($user, $importId));
        }

        // ── Reminders & impact reports — quick endpoints ────────────────
        case 'reminders_scan': {
            require_once __DIR__ . '/reminders.php';
            aether_json(['action'=>'reminders_scan','buckets'=>AetherReminders::scan()]);
        }
        case 'my_tasks': {
            require_once __DIR__ . '/my-tasks.php';
            aether_json(['action'=>'my_tasks'] + AetherMyTasks::for_($user));
        }

        // ── Module-level analytical reports ─────────────────────────────
        case 'module_report': {
            require_once __DIR__ . '/module-reports.php';
            $module = (string)($body['module'] ?? ($_GET['module'] ?? 'donations'));
            $period = AetherModuleReports::detectPeriod((string)($body['period'] ?? '90 days'));
            $r = AetherModuleReports::build($db, $module, $period);
            aether_json(['action' => 'module_report', 'module' => $module, 'period' => $period] + $r);
        }

        // ── Image upload (gallery) ──────────────────────────────────────
        case 'upload_image': {
            $filename = (string)($body['filename'] ?? '');
            $base64   = (string)($body['data'] ?? '');
            $title    = (string)($body['title'] ?? '');
            $caption  = (string)($body['caption'] ?? '');
            $category = (string)($body['category'] ?? 'general');
            if (!$filename || !$base64) aether_error('filename and data (base64) required');
            if (!aether_is_admin($user) && !in_array($user['role'] ?? '', ['editor','manager'])) {
                aether_error('Your role cannot upload to gallery', 403);
            }
            // direct insert (no plan — uploads are immediate by design)
            $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $clean = uniqid() . '_' . $clean;
            $dir = '/app/uploads/aether';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $bin = base64_decode($base64) ?: '';
            file_put_contents("$dir/$clean", $bin);
            $url = "/uploads/aether/$clean";
            $stmt = $db->prepare("INSERT INTO gallery (title, image_url, description, category) VALUES (?,?,?,?)");
            $stmt->execute([$title ?: $filename, $url, $caption, $category]);
            $id = (int)$db->lastInsertId();
            AetherAudit::log('image_uploaded', "Image '$filename' uploaded to gallery #$id", ['url'=>$url], 'info', $user['id']);
            aether_json(['action'=>'upload_image','id'=>$id,'url'=>$url,'size'=>strlen($bin)]);
        }

        // ── Suggest caption / blog draft (no plan, just a reply) ────────
        case 'suggest_caption': {
            $msg = 'suggest caption for "' . ($body['filename'] ?? 'this image') . '"';
            require_once __DIR__ . '/reasoner.php';
            $r = (new AetherReasoner($user, $db))->reason($msg);
            aether_json(['action'=>'suggest_caption','reply'=>$r['reply']]);
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
