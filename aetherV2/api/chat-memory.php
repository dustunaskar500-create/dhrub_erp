<?php
/**
 * Aether v2 — Chat memory (persistent conversation history per user)
 *
 * Persists every chat exchange so Aether maintains genuine continuity. This
 * also feeds the LLM with the last N turns to enable contextual replies
 * ("…as I mentioned earlier, sir…").
 */

require_once __DIR__ . '/bootstrap.php';

class AetherChatMemory
{
    public static function ensureTable(PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS aether_chat_memory (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                user_id         INT NOT NULL,
                conversation_id VARCHAR(64) NOT NULL,
                role            ENUM('user','assistant','system') NOT NULL,
                content         MEDIUMTEXT NOT NULL,
                meta_json       TEXT,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id, conversation_id),
                INDEX (created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public static function append(int $userId, string $convId, string $role, string $content, array $meta = []): void {
        if ($content === '') return;
        try {
            $stmt = aether_db()->prepare(
                "INSERT INTO aether_chat_memory (user_id, conversation_id, role, content, meta_json)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $convId, $role, $content, $meta ? json_encode($meta) : null]);
        } catch (\Throwable $e) { /* memory is best-effort */ }
    }

    /** Return last N messages for an LLM call (role + content only). */
    public static function recent(int $userId, string $convId, int $limit = 16): array {
        try {
            $stmt = aether_db()->prepare(
                "SELECT role, content FROM aether_chat_memory
                 WHERE user_id = ? AND conversation_id = ?
                 ORDER BY id DESC LIMIT ?"
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $convId);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_reverse($rows);
        } catch (\Throwable $e) { return []; }
    }

    /**
     * A small set of "long-term" facts about the user/estate that survive
     * across conversations (e.g. "user prefers PDF receipts CC'd to admin").
     */
    public static function notes(int $userId): array {
        try {
            $stmt = aether_db()->prepare(
                "SELECT content FROM aether_chat_memory
                 WHERE user_id = ? AND role='system' AND conversation_id='__notes__'
                 ORDER BY id DESC LIMIT 10"
            );
            $stmt->execute([$userId]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'content');
        } catch (\Throwable $e) { return []; }
    }

    public static function rememberNote(int $userId, string $note): void {
        self::append($userId, '__notes__', 'system', $note);
    }
}
