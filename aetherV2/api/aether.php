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

            // ── Hybrid: escalate to the LLM butler when rules are weak ─────
            require_once __DIR__ . '/hybrid-router.php';
            $router = new AetherHybridRouter($user);
            $useLlm = !$router->ruleAnswerSufficient($resp, $message);
            $llmMeta = null;
            if ($useLlm) {
                $llmResp = $router->llmReply($message, $conv, ['text' => $resp['reply'] ?? '']);
                $resp['reply'] = $llmResp['reply'];
                $llmMeta = $llmResp['meta'];
            }
            persistReply($db, $user, $conv, $resp['reply']);

            aether_json([
                'action'     => 'chat',
                'reply'      => $resp['reply'],
                'cards'      => $resp['cards'] ?? [],
                'plan'       => $resp['plan'] ?? null,
                'mode'       => $resp['mode'] ?? 'answer',
                'intent'     => $resp['intent'],
                'confidence' => $resp['confidence'],
                'source'     => $llmMeta['source'] ?? 'rules',
                'llm'        => $llmMeta,
                'kg_matches' => array_slice($resp['kg_matches'] ?? [], 0, 6),
            ]);
        }

        // ── Streaming chat (SSE) ────────────────────────────────────────
        case 'chat_stream': {
            $message = trim((string)($body['message'] ?? ($_GET['message'] ?? '')));
            $conv    = trim((string)($body['conversation_id'] ?? ($_GET['conversation_id'] ?? 'default'))) ?: 'default';
            if ($message === '') aether_error('Empty message', 400);

            // SSE headers
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');
            ignore_user_abort(false);

            require_once __DIR__ . '/hybrid-router.php';
            require_once __DIR__ . '/persona.php';
            require_once __DIR__ . '/chat-memory.php';
            require_once __DIR__ . '/brain.php';

            $brain = new AetherBrain();

            // Acknowledge instantly
            echo "event: status\ndata: " . json_encode(['t' => 'thinking', 'model' => $brain->model()]) . "\n\n";
            @ob_flush(); @flush();

            // Run rules quickly to provide grounding context
            $reasoner = new AetherReasoner($user, $db);
            $reasoner->setConversation($conv);
            $ruleResp = $reasoner->reason($message);

            // If rules covered it fully (write intent / slot fill / high-conf read), stream the result as-is
            $router = new AetherHybridRouter($user);
            if ($router->ruleAnswerSufficient($ruleResp, $message)) {
                echo "event: token\ndata: " . json_encode(['t' => $ruleResp['reply']]) . "\n\n";
                @ob_flush(); @flush();
                echo "event: done\ndata: " . json_encode([
                    'source' => 'rules',
                    'intent' => $ruleResp['intent'],
                    'confidence' => $ruleResp['confidence'],
                    'plan'   => $ruleResp['plan'] ?? null,
                ]) . "\n\n";
                @ob_flush(); @flush();
                persistReply($db, $user, $conv, $ruleResp['reply']);
                exit;
            }

            // Otherwise — stream from Claude with butler persona + live context
            $ctx = (function () use ($db, $user) {
                $kpis = [];
                try {
                    $don = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM donations WHERE donation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch();
                    $kpis[] = ['label' => 'Donations (90d)', 'value' => '₹' . number_format((float)$don['s'])];
                    $kpis[] = ['label' => 'Donation count', 'value' => (int)$don['c']];
                    $exp = $db->query("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch();
                    $kpis[] = ['label' => 'Expenses (90d)', 'value' => '₹' . number_format((float)$exp['s'])];
                } catch (\Throwable $e) {}
                $pending = 0;
                try { $pending = (int)$db->query("SELECT COUNT(*) FROM aether_action_plans WHERE status='proposed'")->fetchColumn(); } catch (\Throwable $e) {}
                return ['kpis' => $kpis, 'pending_plans' => $pending];
            })();
            $sys = AetherPersona::systemPrompt($user, $ctx);
            if (!empty($ruleResp['reply'])) {
                $sys .= "\n\n# Local rule engine result (live data; quote or paraphrase faithfully)\n" .
                        mb_substr($ruleResp['reply'], 0, 1800);
            }

            // Inject conversation history
            $messages = [['role' => 'system', 'content' => $sys]];
            foreach (AetherChatMemory::recent((int)$user['id'], $conv, 16) as $m) {
                if (in_array($m['role'], ['user', 'assistant'], true)) {
                    $messages[] = ['role' => $m['role'], 'content' => $m['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $message];

            AetherChatMemory::append((int)$user['id'], $conv, 'user', $message);

            // Capture streamed tokens to persist the full reply
            $captured = '';
            $origStream = function () use (&$captured, $brain, $messages, &$origStream) {};
            // Echo from Claude
            $brain->stream($messages);
            // Note: stream() writes directly to stdout. We can't easily capture the
            // text mid-flight from within this scope, so we re-fetch the assistant's
            // latest contribution from the captured output via output buffering.
            // For persistence, we run a quick non-streaming "what did you just say"
            // — but to keep this simple and idempotent, we re-emit a marker and
            // call AetherChatMemory.append on the client-acknowledged "done" frame.

            // No persistence here — frontend will call /history endpoint OR we'll
            // do a follow-up update via chat_persist_assistant if needed.
            exit;
        }

        // Persist the assistant's streamed reply (client calls this after SSE ends)
        case 'chat_persist_assistant': {
            require_once __DIR__ . '/chat-memory.php';
            $conv = trim((string)($body['conversation_id'] ?? 'default')) ?: 'default';
            $text = trim((string)($body['text'] ?? ''));
            if ($text !== '') {
                AetherChatMemory::append((int)$user['id'], $conv, 'assistant', $text, $body['meta'] ?? []);
                try {
                    $db->prepare("INSERT INTO aether_memory (user_id, conversation_id, role, content) VALUES (?,?,?,?)")
                       ->execute([(int)$user['id'], $conv, 'assistant', $text]);
                } catch (\Throwable $e) {}
            }
            aether_json(['ok' => true]);
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

        // ── Task assignments (super_admin / admin) ──────────────────────
        case 'all_pending_plans': {
            require_once __DIR__ . '/task-assignments.php';
            $intent = (string)($body['intent'] ?? '') ?: null;
            $assignedTo = (int)($body['assigned_to'] ?? 0) ?: null;
            aether_json(['action'=>'all_pending_plans'] + AetherTaskAssignments::listAllPending($user, $intent, $assignedTo));
        }
        case 'assign_plan': {
            require_once __DIR__ . '/task-assignments.php';
            $pid = (int)($body['plan_id'] ?? 0);
            $aid = (int)($body['assignee_id'] ?? 0);
            $note = trim((string)($body['note'] ?? ''));
            if (!$pid || !$aid) aether_error('plan_id + assignee_id required', 400);
            aether_json(['action'=>'assign_plan'] + AetherTaskAssignments::assign($user, $pid, $aid, $note));
        }
        case 'assigned_to_me': {
            require_once __DIR__ . '/task-assignments.php';
            aether_json(['action'=>'assigned_to_me'] + AetherTaskAssignments::assignedToMe($user));
        }
        case 'users_list': {
            require_once __DIR__ . '/task-assignments.php';
            aether_json(['action'=>'users_list'] + AetherTaskAssignments::userList($user));
        }

        // ── Reports history & exports ───────────────────────────────────
        case 'reports_history': {
            require_once __DIR__ . '/reports-history.php';
            aether_json(['action'=>'reports_history'] + AetherReportsHistory::list($user));
        }
        case 'report_export': {
            require_once __DIR__ . '/reports-history.php';
            $module = (string)($body['module'] ?? ($_GET['module'] ?? ''));
            $period = (string)($body['period'] ?? ($_GET['period'] ?? '90 days'));
            $from   = (string)($body['from'] ?? ($_GET['from'] ?? ''));
            $to     = (string)($body['to']   ?? ($_GET['to']   ?? ''));
            if (!$module) aether_error('module required', 400);
            AetherReportsHistory::exportModuleCsv($module, $period, $from, $to, $user);
        }

        // ── KPI drill-down (clickable cards on dashboard) ───────────────
        case 'kpi_details': {
            require_once __DIR__ . '/kpi-details.php';
            $kpi  = (string)($body['kpi'] ?? '');
            $from = (string)($body['from'] ?? '') ?: null;
            $to   = (string)($body['to']   ?? '') ?: null;
            if (!$kpi) aether_error('kpi required', 400);
            aether_json(['action'=>'kpi_details'] + AetherKPIDetails::build($user, $kpi, $from, $to));
        }

        // ── Indian compliance (80G, 12A, FCRA, Form10B, CSR) ────────────
        case 'compliance_report': {
            require_once __DIR__ . '/compliance.php';
            $section = (string)($body['section'] ?? 'overview');
            $from    = (string)($body['from']    ?? '');
            $to      = (string)($body['to']      ?? '');
            if (!$from || !$to) aether_error('from + to (YYYY-MM-DD) required', 400);
            aether_json(['action'=>'compliance_report'] + AetherCompliance::build($user, $section, $from, $to));
        }
        case 'compliance_export': {
            require_once __DIR__ . '/compliance.php';
            $section = (string)($body['section'] ?? ($_GET['section'] ?? 'overview'));
            $from    = (string)($body['from']    ?? ($_GET['from']    ?? ''));
            $to      = (string)($body['to']      ?? ($_GET['to']      ?? ''));
            if (!$from || !$to) aether_error('from + to required', 400);
            AetherCompliance::exportCsv($user, $section, $from, $to);
        }

        // ── RBAC info (UI uses to render permission hints) ──────────────
        case 'rbac_info': {
            require_once __DIR__ . '/rbac.php';
            aether_json([
                'action'  => 'rbac_info',
                'role'    => $user['role'],
                'description' => AetherRBAC::describe($user),
                'csv_modules' => AetherRBAC::csvImportableModules($user),
            ]);
        }

        // ── Module-level analytical reports ─────────────────────────────
        case 'module_report': {
            require_once __DIR__ . '/module-reports.php';
            $module = (string)($body['module'] ?? ($_GET['module'] ?? 'donations'));
            $from   = (string)($body['from'] ?? '');
            $to     = (string)($body['to']   ?? '');
            if ($from && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
                $period = ['days' => $days, 'label' => "$from to $to"];
            } else {
                $period = AetherModuleReports::detectPeriod((string)($body['period'] ?? '90 days'));
            }
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

        // ── Logout: wipe Aether's chat memory + pending intents ─────────
        // Privacy-first: any conversational context (which may include
        // mentions of donors, amounts, etc.) is purged for this user.
        // The JWT itself is invalidated by the ERP — Aether just clears
        // what it remembered.
        case 'logout': {
            try { $db->prepare("DELETE FROM aether_chat_memory WHERE user_id = ?")->execute([$user['id']]); } catch (\Throwable $e) {}
            try { $db->prepare("DELETE FROM aether_pending_intents WHERE user_id = ?")->execute([$user['id']]); } catch (\Throwable $e) {}
            try { $db->prepare("DELETE FROM aether_memory WHERE user_id = ?")->execute([$user['id']]); } catch (\Throwable $e) {}
            AetherAudit::log('logout', 'User signed out of Aether',
                ['user_id' => $user['id']], 'low', $user['id']);
            aether_json(['action' => 'logout', 'ok' => true]);
        }

        // ── Upload an attachment from chat (image / video / pdf / csv) ──
        // Multipart 'file' upload. Stored under /app/uploads/chat/<user>/...
        case 'upload_attachment': {
            if (!isset($_FILES['file'])) aether_error('file required', 400);
            $f = $_FILES['file'];
            if (!is_uploaded_file($f['tmp_name'] ?? '')) aether_error('Upload failed', 400);

            $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/heic','image/heif',
                        'video/mp4','video/quicktime','video/webm','video/x-matroska','video/mpeg',
                        'application/pdf',
                        'text/csv','application/csv','application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain'];
            $mime = mime_content_type($f['tmp_name']) ?: ($f['type'] ?? 'application/octet-stream');
            $okType = in_array($mime, $allowed, true)
                   || str_starts_with($mime, 'image/')
                   || str_starts_with($mime, 'video/');
            if (!$okType) aether_error('File type not allowed: ' . $mime, 415);
            if (($f['size'] ?? 0) > 64 * 1024 * 1024) aether_error('File exceeds 64 MB', 413);

            $dir = '/app/uploads/chat/' . (int)$user['id'];
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name'] ?: 'upload.bin');
            $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
            $abs  = $dir . '/' . $name;
            if (!move_uploaded_file($f['tmp_name'], $abs)) aether_error('Could not store file', 500);

            // Kind classification
            $kind = 'document';
            if (str_starts_with($mime, 'image/')) $kind = 'image';
            elseif (str_starts_with($mime, 'video/')) $kind = 'video';
            elseif ($mime === 'application/pdf') $kind = 'pdf';
            elseif (in_array($mime, ['text/csv','application/csv'], true) || preg_match('/\.csv$/i', $safe)) $kind = 'csv';
            elseif (str_contains($mime, 'sheet') || preg_match('/\.xlsx?$/i', $safe)) $kind = 'spreadsheet';

            $url = '/uploads/chat/' . (int)$user['id'] . '/' . $name;

            // Persist a note in chat memory so the butler can refer back to it
            try {
                $conv = (string)($_POST['conversation_id'] ?? 'standalone');
                $db->prepare("INSERT INTO aether_chat_memory (user_id, conversation_id, role, content)
                              VALUES (?,?,?,?)")
                   ->execute([(int)$user['id'], $conv, 'system',
                              "User attached: {$f['name']} ({$kind}, " . round(($f['size'] ?? 0) / 1024, 1) . " KB) at {$url}"]);
            } catch (\Throwable $e) {}

            AetherAudit::log('chat_attachment', "Attached $kind: {$f['name']}",
                ['url' => $url, 'size' => (int)$f['size'], 'mime' => $mime], 'low', $user['id']);

            aether_json([
                'ok' => true,
                'url' => $url,
                'original_name' => $f['name'],
                'mime' => $mime,
                'size' => (int)$f['size'],
                'kind' => $kind,
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
