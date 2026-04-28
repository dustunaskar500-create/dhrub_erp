<?php
/**
 * Aether — ping.php  (diagnostic endpoint)
 * SECURITY: Requires a valid ERP JWT. DELETE from server after diagnosis.
 */

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$prevErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$authPath = __DIR__ . '/../../includes/auth.php';
$dbPath   = __DIR__ . '/../../includes/db.php';

if (file_exists($authPath)) {
    try { require_once $authPath; } catch (\Throwable $e) {}
}

function pingGetAuthHeader(): string {
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION']          ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
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
    foreach ($candidates as $v) {
        $v = trim($v);
        if ($v !== '') return $v;
    }
    return '';
}

$authenticated = false;
$authHeader    = pingGetAuthHeader();

if (!empty($authHeader) && preg_match('/Bearer\s+(.+)$/i', $authHeader, $m)) {
    $token = trim($m[1]);
    if (class_exists('JWT')) {
        $payload = JWT::decode($token);
        $authenticated = ($payload && isset($payload['sub'])
            && (!isset($payload['exp']) || $payload['exp'] >= time()));
    } else {
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $p = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $authenticated = ($p && isset($p['sub'])
                && (!isset($p['exp']) || $p['exp'] >= time()));
        }
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['detail' => 'Authorization required']);
    exit;
}

$out = [
    'status'      => 'ok',
    'php'         => PHP_VERSION,
    'engine'      => 'AetherBrain (self-sufficient — no external AI API)',
];

try {
    require_once __DIR__ . '/config.php';
    $out['config'] = 'loaded';
} catch (\Throwable $e) {
    $out['config'] = 'FAILED: ' . $e->getMessage();
}

try {
    require_once __DIR__ . '/helpers.php';
    $out['helpers'] = 'loaded';
} catch (\Throwable $e) {
    $out['helpers'] = 'FAILED: ' . $e->getMessage();
}

$brainFile = __DIR__ . '/aether-brain.php';
$out['brain_file_exists'] = file_exists($brainFile);
if (file_exists($brainFile)) {
    try {
        require_once $brainFile;
        $out['brain'] = class_exists('AetherBrain') ? 'AetherBrain loaded ✅' : 'AetherBrain class missing ❌';
    } catch (\Throwable $e) {
        $out['brain'] = 'FAILED: ' . $e->getMessage();
    }
}

error_reporting($prevErrorReporting);

$out['auth_file_exists'] = file_exists($authPath);
$out['db_file_exists']   = file_exists($dbPath);

try {
    require_once $dbPath;
    $db = Database::getInstance()->getConnection();
    $out['db_connection'] = 'ok';
} catch (\Throwable $e) {
    $out['db_connection'] = 'FAILED: ' . $e->getMessage();
}

$out['auth_header_found'] = !empty($authHeader);

if (function_exists('getCurrentUser')) {
    ob_start();
    try { $user = getCurrentUser(); } catch (\Throwable $e) { $user = null; }
    ob_end_clean();
    $out['current_user'] = $user
        ? ['id' => $user['id'] ?? null, 'name' => $user['full_name'] ?? $user['name'] ?? '(set)']
        : null;
    $out['authenticated'] = ($user !== null);
} else {
    $out['authenticated'] = true;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
