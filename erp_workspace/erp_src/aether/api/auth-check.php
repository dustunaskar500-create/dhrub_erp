<?php
/**
 * Aether — auth-check.php
 * Called by AetherChat.tsx on load to verify the logged-in user's JWT.
 * Returns: {"authenticated": true, "name": "..."} or {"authenticated": false}
 *
 * FIX: Added getallheaders() fallback + robust header extraction so the
 * Bearer token is found regardless of Apache/cPanel config.
 */

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Suppress DB constant redefinition warnings from the ERP's database.php
set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
    return ($errno === E_WARNING
        && strpos($errstr, 'already defined') !== false
        && strpos($errfile, 'database.php') !== false);
}, E_WARNING);

require_once __DIR__ . '/../../includes/auth.php';

// ── Robust Authorization header extraction ────────────────────────────────────
// Apache on shared hosting (Hostinger/cPanel) often strips the Authorization
// header unless CGIPassAuth is set. We try every known server variable plus
// the rewrite-rule ENV fallback set in .htaccess.
function getAetherAuthHeader(): string {
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION']          ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_AUTHORIZATION']        ?? '', // some reverse proxies
    ];

    // apache_request_headers() — works on mod_php but not CGI/FPM
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $candidates[] = $h['Authorization']   ?? '';
        $candidates[] = $h['authorization']   ?? '';
    }

    // getallheaders() — works on PHP-FPM (Hostinger default)
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $candidates[] = $h['Authorization']   ?? '';
        $candidates[] = $h['authorization']   ?? '';
    }

    foreach ($candidates as $v) {
        $v = trim($v);
        if ($v !== '') return $v;
    }
    return '';
}

$authHeader = getAetherAuthHeader();

// No token at all → not authenticated (widget will hide itself)
if (empty($authHeader) || !preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    echo json_encode(['authenticated' => false, 'reason' => 'no_token']);
    exit;
}

$token = trim($matches[1]);

// Validate JWT using the ERP's own JWT class (loaded via auth.php above)
if (!class_exists('JWT')) {
    // JWT class not available — fall back to a raw decode check
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        echo json_encode(['authenticated' => false, 'reason' => 'malformed_token']);
        exit;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
} else {
    $payload = JWT::decode($token);
}

if (!$payload || !isset($payload['sub'])) {
    echo json_encode(['authenticated' => false, 'reason' => 'invalid_token']);
    exit;
}

// Check token expiry if present
if (isset($payload['exp']) && $payload['exp'] < time()) {
    echo json_encode(['authenticated' => false, 'reason' => 'token_expired']);
    exit;
}

// Confirm user is still active in DB
try {
    require_once __DIR__ . '/../../includes/db.php';
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, full_name, role, is_active FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['is_active']) {
        echo json_encode(['authenticated' => false, 'reason' => 'user_inactive']);
        exit;
    }

    echo json_encode([
        'authenticated' => true,
        'name'          => $user['full_name'],
        'role'          => $user['role'] ?? 'staff',
    ]);

} catch (\Throwable $e) {
    // DB error — don't expose details, just report failure
    echo json_encode(['authenticated' => false, 'reason' => 'db_error']);
}
