<?php
/**
 * Aether v2 — Pending Intents (multi-turn data collection)
 *
 * When a planner needs more information than the user provided in a single
 * message, the planner stores a "pending intent" with the slots collected so
 * far and the next slot to ask for. The next user message fills that slot;
 * once all required slots are filled, the planner is re-invoked and the plan
 * is created.
 *
 * Slot definitions are declarative — see Reasoner::SLOT_DEFINITIONS.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherPendingIntents
{
    /** Schema bootstrap (called once from migrate) */
    public static function ensureTable(PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS aether_pending_intents (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                conv_id      VARCHAR(64) NOT NULL DEFAULT 'default',
                intent       VARCHAR(64) NOT NULL,
                slots_json   TEXT NOT NULL,
                missing_json TEXT NOT NULL,
                status       ENUM('open','done','cancelled','expired') DEFAULT 'open',
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (user_id, status, conv_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public static function open(int $userId, string $convId = 'default'): ?array {
        $db = aether_db();
        // auto-expire stale ones (>20 min)
        $db->prepare("UPDATE aether_pending_intents
                      SET status='expired'
                      WHERE status='open' AND updated_at < NOW() - INTERVAL 20 MINUTE")
           ->execute();
        $stmt = $db->prepare(
            "SELECT * FROM aether_pending_intents
             WHERE user_id = ? AND conv_id = ? AND status = 'open'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $convId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['slots']   = json_decode($row['slots_json'], true) ?: [];
        $row['missing'] = json_decode($row['missing_json'], true) ?: [];
        return $row;
    }

    /**
     * Create / update a pending intent.
     */
    public static function save(int $userId, string $convId, string $intent, array $slots, array $missing): int {
        $db = aether_db();
        $existing = self::open($userId, $convId);
        if ($existing && $existing['intent'] === $intent) {
            $db->prepare("UPDATE aether_pending_intents SET slots_json=?, missing_json=?, updated_at=NOW() WHERE id=?")
               ->execute([json_encode($slots), json_encode($missing), $existing['id']]);
            return (int)$existing['id'];
        }
        // close any other open intent for the same conv
        $db->prepare("UPDATE aether_pending_intents SET status='cancelled' WHERE user_id=? AND conv_id=? AND status='open'")
           ->execute([$userId, $convId]);
        $db->prepare("INSERT INTO aether_pending_intents (user_id, conv_id, intent, slots_json, missing_json) VALUES (?,?,?,?,?)")
           ->execute([$userId, $convId, $intent, json_encode($slots), json_encode($missing)]);
        return (int)$db->lastInsertId();
    }

    public static function close(int $id, string $status = 'done'): void {
        aether_db()->prepare("UPDATE aether_pending_intents SET status=? WHERE id=?")
                   ->execute([$status, $id]);
    }

    public static function cancelAll(int $userId, string $convId = 'default'): int {
        $stmt = aether_db()->prepare("UPDATE aether_pending_intents SET status='cancelled' WHERE user_id=? AND conv_id=? AND status='open'");
        $stmt->execute([$userId, $convId]);
        return $stmt->rowCount();
    }
}
