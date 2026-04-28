<?php
/**
 * Aether v2 — Self-contained Bootstrap
 * No dependency on the ERP's includes/. Exposes:
 *   • aether_db()             PDO connection
 *   • aether_current_user()   resolves JWT → user row, or null
 *   • aether_require_user()   401-exits if missing
 *   • aether_is_admin($u)     role check
 *   • aether_json($payload, $code)
 *   • aether_error($msg, $code)
 *   • aether_body()           cached JSON body
 */

if (defined('AETHER_BOOTED')) return;
define('AETHER_BOOTED', true);

while (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/config.php';

/* ═══════════════════════════════════════════════════════════════════════
   JWT (HS256) — minimal, matching the ERP's existing tokens
   ═══════════════════════════════════════════════════════════════════════ */
final class AetherJWT
{
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $signature = self::b64uDecode($parts[2]);
        $expected  = hash_hmac('sha256', $parts[0] . '.' . $parts[1], AETHER_JWT_SECRET, true);
        if (!hash_equals($signature, $expected)) return null;

        $payload = json_decode(self::b64uDecode($parts[1]), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        return $payload;
    }

    private static function b64uDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Database singleton (its own — won't conflict with ERP's Database class)
   ═══════════════════════════════════════════════════════════════════════ */
final class AetherDB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO(
                    'mysql:host=' . AETHER_DB_HOST . ';dbname=' . AETHER_DB_NAME . ';charset=utf8mb4',
                    AETHER_DB_USER,
                    AETHER_DB_PASS,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (\Throwable $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'Aether DB connection failed', 'detail' => $e->getMessage()]);
                exit;
            }
        }
        return self::$pdo;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Helpers
   ═══════════════════════════════════════════════════════════════════════ */
function aether_db(): PDO { return AetherDB::pdo(); }

function aether_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aether_error(string $msg, int $code = 400): void {
    aether_json(['error' => $msg], $code);
}

function aether_body(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '[]', true) ?: [];
    }
    return $body;
}

function aether_auth_header(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION']
         ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
         ?? '';
    if (!$h && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $h = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    }
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    }
    return trim((string)$h);
}

function aether_current_user(): ?array {
    $auth = aether_auth_header();
    if (!$auth || !preg_match('/Bearer\s+(.+)$/i', $auth, $m)) return null;

    $payload = AetherJWT::decode(trim($m[1]));
    if (!$payload || empty($payload['sub'])) return null;

    try {
        $stmt = aether_db()->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.profile_picture,
                    r.role_name AS role, u.role_id
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        return ($u && $u['is_active']) ? $u : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function aether_require_user(): array {
    $u = aether_current_user();
    if (!$u) aether_error('Authentication required', 401);
    return $u;
}

function aether_is_admin(array $user): bool {
    return in_array($user['role'] ?? '', ['super_admin', 'admin'], true);
}
