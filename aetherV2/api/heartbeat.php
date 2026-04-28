<?php
/**
 * Aether v2 — Background Heartbeat (CLI)
 * Run via supervisor / systemd / cron loop. Every TICK_INTERVAL seconds:
 *   • Sync schema (detect changes, rebuild knowledge graph if needed)
 *   • Run all health checks (no auto-heal — user-confirmed only)
 *   • Decay learning weights gently toward 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/schema-watcher.php';
require_once __DIR__ . '/knowledge-graph.php';
require_once __DIR__ . '/error-monitor.php';
require_once __DIR__ . '/notifier.php';

aether_run_migrations();

$INTERVAL = (int)(getenv('AETHER_TICK_INTERVAL') ?: 60);
$once = in_array('--once', $argv ?? [], true);
$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
    @fflush(STDOUT);
};

$log("Aether heartbeat starting — interval={$INTERVAL}s" . ($once ? " (single-run)" : ''));
if (!$once) AetherAudit::log('heartbeat_start', "Background heartbeat started (interval {$INTERVAL}s)", [], 'info');

$tick = function () use ($log) {
    try {
        $db = aether_db();
        $watcher = new AetherSchemaWatcher($db);
        $sync = $watcher->sync();
        if ($sync['changed']) {
            (new AetherKnowledgeGraph($db))->rebuild();
            $log("Schema changes: " . count($sync['changes']) . " — knowledge graph rebuilt");
        }

        $monitor = new AetherErrorMonitor($db);
        $report  = $monitor->runAll(false);
        if (($report['issue_count'] ?? 0) > 0 || $report['overall'] !== 'ok') {
            $log("Health: {$report['overall']} | open={$report['issue_count']}");
        }

        // Decay learning weights gently toward 1.0
        $db->exec("UPDATE aether_intent_weights
                   SET weight = weight + (CASE WHEN weight > 1 THEN -0.01 WHEN weight < 1 THEN 0.01 ELSE 0 END)
                   WHERE samples > 0");
    } catch (\Throwable $e) {
        $log("Heartbeat error: " . $e->getMessage());
    }
};

if ($once) { $tick(); $log("Done."); exit(0); }
while (true) { $tick(); sleep($INTERVAL); }
