<?php
/**
 * Aether AI — Main API endpoint (Self-Sufficient Edition)
 * POST /dhrub_erp/aether/api/aether.php
 *
 * NO external AI API dependency. All intelligence runs locally via AetherBrain.
 * Works on Hostinger shared hosting (PHP 8.1+, no extra extensions needed).
 */

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');
set_time_limit(60);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function jsonOk(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jsonError(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
    return ($errno === E_WARNING
        && strpos($errstr, 'already defined') !== false
        && strpos($errfile, 'database.php') !== false);
}, E_WARNING);

try {
    require_once __DIR__ . '/config.php';
} catch (\Throwable $e) {
    jsonError('Configuration error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/auth.php';

function getAetherUser(): ?array {
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION']          ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_AUTHORIZATION']        ?? '',
    ];
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $candidates[] = $h['Authorization'] ?? '';
        $candidates[] = $h['authorization'] ?? '';
    }
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $candidates[] = $h['Authorization'] ?? '';
        $candidates[] = $h['authorization'] ?? '';
    }
    $authHeader = '';
    foreach ($candidates as $v) {
        $v = trim($v);
        if ($v !== '') { $authHeader = $v; break; }
    }
    if (empty($authHeader)) return null;

    ob_start();
    try { $user = getCurrentUser(); } catch (\Throwable $e) { ob_end_clean(); return null; }
    ob_end_clean();
    return $user ?: null;
}

$user = getAetherUser();
if (!$user) { jsonError('Authentication required. Please log in.', 401); }

try {
    require_once __DIR__ . '/../../includes/db.php';
    $db = Database::getInstance()->getConnection();
} catch (\Throwable $e) {
    jsonError('Database connection failed: ' . $e->getMessage());
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS aether_memory (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT          NOT NULL DEFAULT 0,
            conversation_id VARCHAR(64)  NOT NULL DEFAULT 'default',
            role            ENUM('user','assistant') NOT NULL,
            content         TEXT         NOT NULL,
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv  (user_id, conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\Throwable $e) {}

$raw            = file_get_contents('php://input');
$data           = json_decode($raw, true) ?? [];
$userMessage    = trim($data['message'] ?? '');
$conversationId = trim($data['conversation_id'] ?? 'default') ?: 'default';
$action         = $data['action'] ?? null;
$userId         = (int)($user['id'] ?? 0);

if ($action === 'clear') {
    try {
        $db->prepare('DELETE FROM aether_memory WHERE user_id = ? AND conversation_id = ?')
           ->execute([$userId, $conversationId]);
    } catch (\Throwable $e) {}
    jsonOk(['reply' => '✅ Chat history cleared. Starting fresh!']);
}

if (empty($userMessage)) { jsonError('Empty message.'); }

$history = [];
try {
    $stmt = $db->prepare(
        'SELECT role, content FROM aether_memory
         WHERE user_id = ? AND conversation_id = ?
         ORDER BY id ASC LIMIT 30'
    );
    $stmt->execute([$userId, $conversationId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

require_once __DIR__ . '/aether-brain.php';

$brain = new AetherBrain($db, $user, $history);
$reply = $brain->respond($userMessage, $conversationId);

if ($reply === '__CLEAR_CHAT__') {
    try {
        $db->prepare('DELETE FROM aether_memory WHERE user_id = ? AND conversation_id = ?')
           ->execute([$userId, $conversationId]);
    } catch (\Throwable $e) {}
    jsonOk(['reply' => '✅ Chat history cleared. How can I help you?']);
}

try {
    $stmt = $db->prepare('INSERT INTO aether_memory (user_id, conversation_id, role, content) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $conversationId, 'user',      $userMessage]);
    $stmt->execute([$userId, $conversationId, 'assistant', $reply]);
} catch (\Throwable $e) {}

jsonOk(['reply' => $reply]);
