<?php
/**
 * Auth bridge — uses dhrub_erp's existing JWT secret + users table.
 *
 * The dhrub_erp ecosystem stores its JWT secret in /config/database.php
 * or environment. We re-validate the same Bearer token here so users
 * who logged into the main ERP can use the stock module seamlessly.
 *
 * Provides:
 *   dhrub_db()                   -> PDO
 *   dhrub_current_user()         -> array|null
 *   dhrub_require_user()         -> exit on 401
 *   dhrub_require_role([..])     -> exit on 403
 *   dhrub_json($payload,$code)
 *   dhrub_error($msg,$code)
 *   dhrub_body()
 *   dhrub_setting($key,$default)
 *   dhrub_log_activity($action,$entity,$id,$desc)
 *   dhrub_jwt_secret()
 */

if (!function_exists('dhrub_jwt_secret')) {
    function dhrub_jwt_secret(): string {
        // Try several common sources (env, .env file, includes/db.php constant)
        $env = getenv('JWT_SECRET') ?: getenv('AETHER_JWT_SECRET') ?: '';
        if ($env) return $env;
        $envFiles = [
            dirname(__DIR__, 2) . '/.env',     // parent ERP root .env
            dirname(__DIR__) . '/.env',         // stock_module/.env (fallback)
            __DIR__ . '/.env',                  // api/.env
        ];
        foreach ($envFiles as $envFile) {
            if (!is_file($envFile)) continue;
            foreach (file($envFile) as $line) {
                if (preg_match('/^\s*JWT_SECRET\s*=\s*(.+)$/', $line, $m)) return trim($m[1], "\"' \r\n");
            }
        }
        if (defined('JWT_SECRET')) return (string)JWT_SECRET;
        // Last resort — same default the Aether stack uses
        return 'change-me-in-production';
    }
}

if (!function_exists('dhrub_db')) {
    function dhrub_db(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;

        // Read stock_module/.env first so we can override creds for local dev
        $env = [];
        foreach ([dirname(__DIR__, 2) . '/.env', dirname(__DIR__) . '/.env', __DIR__ . '/.env'] as $envFile) {
            if (!is_file($envFile)) continue;
            foreach (file($envFile) as $line) {
                if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.+)$/', $line, $m)) {
                    $env[$m[1]] = trim($m[2], "\"' \r\n");
                }
            }
        }
        // If the stock_module .env explicitly provides DB creds, honour them
        $useEnv = !empty($env['DB_HOST']) || !empty($env['DB_USER']);

        // Otherwise prefer dhrub_erp's includes/db.php if available
        if (!$useEnv) {
            $maybe = dirname(__DIR__, 2) . '/includes/db.php';
            if (is_file($maybe)) {
                try {
                    require_once $maybe;
                    if (class_exists('Database')) {
                        $pdo = \Database::getInstance()->getConnection();
                        if ($pdo) return $pdo;
                    }
                } catch (\Throwable $e) { /* fall through */ }
            }
            // Fallback: read /config/database.php constants
            $cfg = dirname(__DIR__, 2) . '/config/database.php';
            if (is_file($cfg)) require_once $cfg;
        }

        $host = $env['DB_HOST'] ?? (defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1'));
        $name = $env['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'dhrub_erp'));
        $user = $env['DB_USER'] ?? (defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root'));
        $pass = $env['DB_PASS'] ?? (defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: ''));
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}

if (!function_exists('dhrub_json')) {
    function dhrub_json(array $p, int $code = 200): void {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8');
        echo json_encode($p, JSON_UNESCAPED_SLASHES); exit;
    }
    function dhrub_error(string $m, int $code = 400): void { dhrub_json(['error' => $m], $code); }
}

if (!function_exists('dhrub_body')) {
    function dhrub_body(): array {
        static $c = null;
        if ($c !== null) return $c;
        $raw = file_get_contents('php://input');
        $c = json_decode($raw ?: '[]', true) ?: [];
        return $c;
    }
}

if (!function_exists('dhrub_current_user')) {
    function dhrub_current_user(): ?array {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$hdr && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return null;
        $payload = _dhrub_jwt_decode($m[1], dhrub_jwt_secret());
        if (!$payload || empty($payload['sub'])) return null;
        try {
            // dhrub_erp uses users.role_id with a roles lookup; try JOIN first, fallback to plain role column
            $db = dhrub_db();
            $sql = "SELECT u.id, u.username, u.full_name, u.email, u.is_active, r.role_name AS role
                    FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?";
            try {
                $s = $db->prepare($sql);
                $s->execute([(int)$payload['sub']]);
                $u = $s->fetch();
            } catch (\Throwable $e) {
                // schema without roles table — try plain `role` column
                $s = $db->prepare("SELECT id, username, full_name, email, role, is_active FROM users WHERE id = ?");
                $s->execute([(int)$payload['sub']]);
                $u = $s->fetch();
            }
            if ($u && !empty($u['is_active'])) {
                // If JOIN returned no role (NULL), fall back to JWT-provided role
                if (empty($u['role']) && !empty($payload['role'])) $u['role'] = $payload['role'];
                return $u;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    function dhrub_require_user(): array {
        $u = dhrub_current_user();
        if (!$u) {
            if ((php_sapi_name() !== 'cli') && (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                 || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
                dhrub_error('Authentication required', 401);
            }
            // For browser navigation to the SPA, send them to login
            header('Location: /index.php'); exit;
        }
        return $u;
    }

    function dhrub_require_role(array $allowed): array {
        $u = dhrub_require_user();
        if (!in_array($u['role'] ?? '', $allowed, true)) dhrub_error('Forbidden: role ' . ($u['role'] ?? '?'), 403);
        return $u;
    }
}

/* ── HS256 JWT decoder (zero-dep, compatible with most ERPs) ─────── */
function _dhrub_jwt_decode(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $sig = _b64url_decode($s);
    $expected = hash_hmac('sha256', "$h.$p", $secret, true);
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(_b64url_decode($p), true);
    if (!is_array($payload)) return null;
    if (!empty($payload['exp']) && time() > (int)$payload['exp']) return null;
    return $payload;
}
function _b64url_decode(string $s): string {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

if (!function_exists('dhrub_setting')) {
    function dhrub_setting(string $key, string $default = ''): string {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $s = dhrub_db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            $cache[$key] = $v !== false ? (string)$v : $default;
        } catch (\Throwable $e) { $cache[$key] = $default; }
        return $cache[$key];
    }
}

if (!function_exists('dhrub_log_activity')) {
    function dhrub_log_activity(string $action, string $entity, ?int $entityId, string $description): void {
        try {
            $u = dhrub_current_user();
            $sql = "INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
                    VALUES (?,?,?,?,?,?,?)";
            dhrub_db()->prepare($sql)->execute([
                $u['id'] ?? null, $action, $entity, $entityId, $description,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (\Throwable $e) {}
    }
}
