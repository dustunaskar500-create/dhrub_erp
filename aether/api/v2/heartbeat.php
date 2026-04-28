<?php
/**
 * Aether v2 — Background Heartbeat
 * Run as a long-lived process via supervisor. Every TICK_INTERVAL seconds:
 *   • Sync schema (detect changes, rebuild knowledge graph if needed)
 *   • Run all health checks (no auto-heal — that's user-confirmed)
 *   • Decay learning weights slightly toward 1.0 (mean reversion)
 *
 * No HTTP. Pure CLI. Reads env from Apache config or .env.
 */

declare(strict_types=1);

$envFile = __DIR__ . '/../.env';
$env = is_readable($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
foreach (($env ?: []) as $k => $v) {
    if (!getenv($k)) putenv("$k=$v");
}
foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $k) {
    if (!defined($k)) define($k, getenv($k) ?: '');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/schema-watcher.php';
require_once __DIR__ . '/knowledge-graph.php';
require_once __DIR__ . '/error-monitor.php';

aether_run_migrations();

$INTERVAL = (int)(getenv('AETHER_TICK_INTERVAL') ?: 60);  // seconds
$log = function (string $msg) {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
    @fflush(STDOUT);
};

$log("Aether heartbeat starting — interval={$INTERVAL}s");
AetherAudit::log('heartbeat_start', "Background heartbeat started (interval {$INTERVAL}s)", [], 'info');

while (true) {
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
    sleep($INTERVAL);
}
