<?php
/**
 * Aether v2 — Core Bootstrap
 * Loads config, DB, auth, and exposes shared utilities.
 * Single entry-point used by every Aether v2 endpoint.
 */

if (defined('AETHER_V2_BOOTED')) return;
define('AETHER_V2_BOOTED', true);

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');
set_time_limit(60);

// Suppress redefine warnings emitted by dual config loaders.
set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
    return ($errno === E_WARNING
        && strpos($errstr, 'already defined') !== false);
}, E_WARNING);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

function aether_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aether_error(string $msg, int $code = 400): void {
    aether_json(['error' => $msg], $code);
}

/**
 * Resolve the current authenticated user without exiting on missing auth.
 * Returns null if not authenticated.
 */
function aether_current_user(): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $auth = $h['Authorization'] ?? ($h['authorization'] ?? '');
    }
    if (!$auth || !preg_match('/Bearer\s+(.+)$/i', $auth, $m)) return null;

    $payload = JWT::decode(trim($m[1]));
    if (!$payload || empty($payload['sub'])) return null;

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.profile_picture,
                    r.role_name AS role, u.role_id
             FROM users u JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        if (!$u || !$u['is_active']) return null;
        return $u;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Require auth; emits 401 + exits if missing.
 */
function aether_require_user(): array {
    $u = aether_current_user();
    if (!$u) aether_error('Authentication required', 401);
    return $u;
}

/**
 * Role check: returns true if user has Aether v2 admin powers
 * (super_admin / admin → full control; others → assistant only).
 */
function aether_is_admin(array $user): bool {
    return in_array($user['role'] ?? '', ['super_admin', 'admin'], true);
}

function aether_db(): PDO {
    return Database::getInstance()->getConnection();
}

/**
 * Read JSON body once and cache.
 */
function aether_body(): array {
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '[]', true) ?: [];
    }
    return $body;
}
