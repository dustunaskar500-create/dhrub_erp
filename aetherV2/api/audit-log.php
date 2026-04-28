<?php
/**
 * Aether v2 — Audit Logger
 * Append-only log of every Aether decision, fix, schema change, conversation event.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherAudit
{
    public static function log(
        string $eventType,
        string $summary,
        array $payload = [],
        string $severity = 'info',
        ?int $userId = null,
        ?string $target = null,
        string $actor = 'aether'
    ): void {
        try {
            $db = aether_db();
            $stmt = $db->prepare(
                "INSERT INTO aether_audit_log
                 (event_type, actor, user_id, target, summary, payload_json, severity)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $eventType, $actor, $userId, $target,
                mb_substr($summary, 0, 500),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $severity,
            ]);
        } catch (\Throwable $e) {
            // never let audit failures break the request
        }

        // Fire-and-forget notification for severe events
        try {
            if (!class_exists('AetherNotifier', false)) {
                @require_once __DIR__ . '/notifier.php';
            }
            if (class_exists('AetherNotifier', false)) {
                AetherNotifier::dispatch($eventType, $summary, $payload, $severity);
            }
        } catch (\Throwable $e) {}
    }

    public static function recent(int $limit = 50, ?string $eventType = null): array {
        $db = aether_db();
        if ($eventType) {
            $stmt = $db->prepare(
                "SELECT * FROM aether_audit_log
                 WHERE event_type = ?
                 ORDER BY id DESC LIMIT " . max(1, min($limit, 500))
            );
            $stmt->execute([$eventType]);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM aether_audit_log ORDER BY id DESC LIMIT " . max(1, min($limit, 500))
            );
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function counts(string $since = '24 HOUR'): array {
        $db = aether_db();
        $sinceClause = preg_match('/^\d+\s+(MINUTE|HOUR|DAY|WEEK|MONTH)$/i', $since) ? $since : '24 HOUR';
        $stmt = $db->query(
            "SELECT severity, COUNT(*) c FROM aether_audit_log
             WHERE created_at >= NOW() - INTERVAL $sinceClause
             GROUP BY severity"
        );
        $out = ['info' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['severity']] = (int)$r['c'];
        }
        return $out;
    }
}
