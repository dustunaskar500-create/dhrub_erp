<?php
/**
 * JWT Authentication
 */
require_once __DIR__ . '/../config/database.php';

class JWT {
    public static function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + JWT_EXPIRY;
        $payload = json_encode($payload);
        
        $base64Header = self::base64UrlEncode($header);
        $base64Payload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, JWT_SECRET, true);
        
        return $base64Header . '.' . $base64Payload . '.' . self::base64UrlEncode($signature);
    }
    
    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;
        
        $signature = self::base64UrlDecode($parts[2]);
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], JWT_SECRET, true);
        
        if (!hash_equals($signature, $expected)) return false;
        
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        if (isset($payload['exp']) && $payload['exp'] < time()) return false;
        
        return $payload;
    }
    
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

function getAuthHeader() {
    // Method 1: Standard $_SERVER
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Method 2: Redirect (Apache CGI/FastCGI)
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // Method 3: apache_request_headers
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (!empty($headers['Authorization'])) return $headers['Authorization'];
        if (!empty($headers['authorization'])) return $headers['authorization'];
    }
    // Method 4: getallheaders
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!empty($headers['Authorization'])) return $headers['Authorization'];
        if (!empty($headers['authorization'])) return $headers['authorization'];
    }
    return '';
}

function getCurrentUser() {
    $authHeader = getAuthHeader();
    
    if (empty($authHeader)) {
        jsonResponse(['detail' => 'Authorization required'], 401);
    }
    
    if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        jsonResponse(['detail' => 'Invalid authorization format'], 401);
    }
    
    $payload = JWT::decode(trim($matches[1]));
    if (!$payload || !isset($payload['sub'])) {
        jsonResponse(['detail' => 'Invalid or expired token'], 401);
    }
    
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.profile_picture, r.role_name as role 
                          FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['is_active']) {
        jsonResponse(['detail' => 'User not found or inactive'], 401);
    }
    
    return $user;
}

function checkPermission($role, $module) {
    if ($role === 'super_admin' || $role === 'admin') return true;
    
    $perms = [
        'manager' => ['donations', 'donors', 'inventory', 'projects', 'programs', 'volunteers', 'gallery', 'dashboard', 'reports', 'members', 'invoices'],
        'accountant' => ['donations', 'expenses', 'ledger', 'reports', 'dashboard', 'invoices'],
        'hr' => ['employees', 'payroll', 'volunteers', 'reports', 'dashboard'],
        'editor' => ['donations', 'donors', 'programs', 'gallery', 'dashboard'],
        'viewer' => ['dashboard', 'gallery']
    ];
    
    return in_array($module, $perms[$role] ?? []);
}

function requirePermission($module) {
    $user = getCurrentUser();
    if (!checkPermission($user['role'], $module)) {
        jsonResponse(['detail' => 'Permission denied'], 403);
    }
    return $user;
}

function logActivity($userId, $module, $action, $desc = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("INSERT INTO activity_logs (user_id, module, action, description) VALUES (?, ?, ?, ?)")
           ->execute([$userId, $module, $action, $desc]);
    } catch (Exception $e) {}
}
