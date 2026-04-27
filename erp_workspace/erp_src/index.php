<?php
/**
 * Dhrub Foundation ERP - Main Entry Point
 * All API routes handled here
 */

// =====================================================
// HOSTINGER FIX: Pass Authorization header to PHP
// =====================================================
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (isset($h['Authorization'])) $_SERVER['HTTP_AUTHORIZATION'] = $h['Authorization'];
        elseif (isset($h['authorization'])) $_SERVER['HTTP_AUTHORIZATION'] = $h['authorization'];
    }
}
// =====================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// API Request
if (strpos($path, '/api') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    
    $apiPath = preg_replace('/^\/api\/?/', '', $path);
    $apiPath = trim($apiPath, '/');
    $parts = explode('/', $apiPath);
    $method = $_SERVER['REQUEST_METHOD'];
    
    $resource = $parts[0] ?? '';
    $id = $parts[1] ?? null;
    $action = $parts[2] ?? null;
    
    $db = Database::getInstance()->getConnection();
    
    // ======================== AUTH ========================
    if ($resource === 'auth') {
        if ($id === 'login' && $method === 'POST') {
            $data = getRequestBody();
            if (empty($data['email']) || empty($data['password'])) {
                jsonResponse(['detail' => 'Email and password required'], 400);
            }
            
            $stmt = $db->prepare("SELECT u.*, r.role_name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.is_active = 1");
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($data['password'], $user['password'])) {
                jsonResponse(['detail' => 'Invalid credentials'], 401);
            }
            
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            // Log login activity with selected role for tracking
            $loginRole = !empty($data['login_role']) ? $data['login_role'] : $user['role'];
            logActivity($user['id'], 'auth', 'login', "User logged in as {$loginRole}");
            
            $token = JWT::encode(['sub' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]);
            
            jsonResponse([
                'access_token' => $token,
                'token_type' => 'bearer',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'profile_picture' => $user['profile_picture'] ?? null
                ]
            ]);
        }
        
        if ($id === 'me' && $method === 'GET') {
            $user = getCurrentUser();
            jsonResponse([
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'profile_picture' => $user['profile_picture'] ?? null
            ]);
        }
        
        if ($id === 'change-password' && $method === 'POST') {
            $user = getCurrentUser();
            $data = getRequestBody();
            
            // Validate input
            if (empty($data['current_password']) || empty($data['new_password'])) {
                jsonResponse(['detail' => 'Current password and new password are required'], 400);
            }
            
            if (strlen($data['new_password']) < 6) {
                jsonResponse(['detail' => 'New password must be at least 6 characters'], 400);
            }
            
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch();
            
            if (!password_verify($data['current_password'], $userData['password'])) {
                jsonResponse(['detail' => 'Current password is incorrect'], 400);
            }
            
            $newHash = password_hash($data['new_password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
            
            logActivity($user['id'], 'auth', 'change_password', 'User changed their own password');
            jsonResponse(['message' => 'Password changed successfully']);
        }
        
        jsonResponse(['detail' => 'Not found'], 404);
    }
    
    // ======================== ROLES (Public for login page - NO AUTH required) ========================
    if ($resource === 'roles') {
        if ($method === 'GET') {
            // Hardcoded fallback roles - always returned even if DB has issues
            $defaultRoles = [
                ['id' => 1, 'role_name' => 'super_admin', 'description' => 'Full system access'],
                ['id' => 2, 'role_name' => 'admin',       'description' => 'Administrative access'],
                ['id' => 3, 'role_name' => 'manager',     'description' => 'Manage operations'],
                ['id' => 4, 'role_name' => 'accountant',  'description' => 'Financial operations'],
                ['id' => 5, 'role_name' => 'hr',          'description' => 'Human resources'],
                ['id' => 6, 'role_name' => 'editor',      'description' => 'Content editing'],
                ['id' => 7, 'role_name' => 'viewer',      'description' => 'Read-only access'],
            ];
            try {
                $stmt  = $db->query("SELECT id, role_name, description FROM roles ORDER BY id");
                $roles = $stmt->fetchAll();
                jsonResponse(['items' => (!empty($roles) ? $roles : $defaultRoles)]);
            } catch (Exception $e) {
                jsonResponse(['items' => $defaultRoles]);
            }
        }
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== USERS ========================
    if ($resource === 'users') {
        if ($method === 'GET' && !$id) {
            requirePermission('settings');
            
            $stmt = $db->query("SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.last_login, u.created_at, 
                               r.role_name as role FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.full_name");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('settings');
            $data = getRequestBody();
            
            if (empty($data['email']) || empty($data['password']) || empty($data['full_name'])) {
                jsonResponse(['detail' => 'Email, password and name required'], 400);
            }
            
            // Check if email exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                jsonResponse(['detail' => 'Email already exists'], 400);
            }
            
            // Get role ID
            $roleId = 7; // default viewer
            if (!empty($data['role'])) {
                $stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                $stmt->execute([$data['role']]);
                $role = $stmt->fetch();
                if ($role) $roleId = $role['id'];
            }
            
            $username = strtolower(str_replace(' ', '.', $data['full_name'])) . rand(100, 999);
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $data['email'], $password, $data['full_name'], $roleId]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'users', 'create', "Created user: {$data['email']}");
            
            $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.full_name, u.is_active, r.role_name as role 
                                  FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // Update user role
        if ($id && $action === 'role' && $method === 'PUT') {
            $user = requirePermission('settings');
            $data = getRequestBody();
            
            $stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
            $stmt->execute([$data['role']]);
            $role = $stmt->fetch();
            
            if (!$role) jsonResponse(['detail' => 'Invalid role'], 400);
            
            $db->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([$role['id'], $id]);
            logActivity($user['id'], 'users', 'update', "Updated role for user $id");
            
            jsonResponse(['message' => 'Role updated']);
        }
        
        // Toggle user status
        if ($id && $action === 'status' && $method === 'PUT') {
            $user = requirePermission('settings');
            
            $stmt = $db->prepare("SELECT is_active FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $userData = $stmt->fetch();
            
            if (!$userData) jsonResponse(['detail' => 'User not found'], 404);
            
            $newStatus = $userData['is_active'] ? 0 : 1;
            $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
            logActivity($user['id'], 'users', 'update', ($newStatus ? 'Activated' : 'Deactivated') . " user $id");
            
            jsonResponse(['message' => $newStatus ? 'User activated' : 'User deactivated', 'is_active' => $newStatus]);
        }
        
        // Update user profile (Super Admin can update any user, others can only update own profile)
        if ($id && $action === 'profile' && $method === 'PUT') {
            $user = getCurrentUser();
            $data = getRequestBody();
            
            // Check permissions
            $canEdit = ($user['role'] === 'super_admin') || ($id == $user['id']);
            if (!$canEdit) {
                jsonResponse(['detail' => 'You can only update your own profile'], 403);
            }
            
            // Verify target user exists
            $stmt = $db->prepare("SELECT id, email FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                jsonResponse(['detail' => 'User not found'], 404);
            }
            
            $updates = [];
            $params = [];
            
            // Update allowed fields
            if (!empty($data['full_name'])) {
                $updates[] = "full_name = ?";
                $params[] = $data['full_name'];
            }
            
            if (!empty($data['email'])) {
                // Check if email already exists for another user
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$data['email'], $id]);
                if ($stmt->fetch()) {
                    jsonResponse(['detail' => 'Email already in use by another user'], 400);
                }
                $updates[] = "email = ?";
                $params[] = $data['email'];
            }
            
            if (!empty($data['username'])) {
                // Check if username already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$data['username'], $id]);
                if ($stmt->fetch()) {
                    jsonResponse(['detail' => 'Username already in use'], 400);
                }
                $updates[] = "username = ?";
                $params[] = $data['username'];
            }
            
            if (empty($updates)) {
                jsonResponse(['detail' => 'No valid fields to update'], 400);
            }
            
            $params[] = $id;
            $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
            
            logActivity($user['id'], 'users', 'update_profile', "Updated profile for user $id");
            
            // Return updated user data
            $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.full_name, u.profile_picture, r.role_name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmt->execute([$id]);
            jsonResponse(['message' => 'Profile updated successfully', 'user' => $stmt->fetch()]);
        }
        
        // Reset user password (Super Admin only)
        if ($id && $action === 'password' && $method === 'PUT') {
            $user = getCurrentUser();
            
            // Only super_admin can reset other users' passwords
            if ($user['role'] !== 'super_admin') {
                jsonResponse(['detail' => 'Only Super Admin can reset user passwords'], 403);
            }
            
            $data = getRequestBody();
            
            if (empty($data['new_password'])) {
                jsonResponse(['detail' => 'New password is required'], 400);
            }
            
            if (strlen($data['new_password']) < 6) {
                jsonResponse(['detail' => 'Password must be at least 6 characters'], 400);
            }
            
            // Verify target user exists
            $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $targetUser = $stmt->fetch();
            
            if (!$targetUser) {
                jsonResponse(['detail' => 'User not found'], 404);
            }
            
            $newHash = password_hash($data['new_password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $id]);
            
            logActivity($user['id'], 'users', 'password_reset', "Super Admin reset password for user: {$targetUser['email']}");
            jsonResponse(['message' => "Password reset successfully for {$targetUser['full_name']}"]);
        }
        
        // Profile picture upload
        if ($id === 'profile-picture' && $method === 'POST') {
            $user = getCurrentUser();
            
            if (!isset($_FILES['file'])) {
                jsonResponse(['detail' => 'No file uploaded'], 400);
            }
            
            $file = $_FILES['file'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                jsonResponse(['detail' => 'Invalid file type. Use JPEG, PNG, GIF or WebP'], 400);
            }
            
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxSize) {
                jsonResponse(['detail' => 'File too large. Max 5MB'], 400);
            }
            
            $uploadDir = __DIR__ . '/uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user['id'] . '_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $profileUrl = 'uploads/profiles/' . $filename;
                $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$profileUrl, $user['id']]);
                logActivity($user['id'], 'users', 'update', 'Updated profile picture');
                jsonResponse(['profile_picture' => $profileUrl, 'message' => 'Profile picture updated']);
            }
            
            jsonResponse(['detail' => 'Failed to upload file'], 500);
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('settings');
            
            // Prevent deleting yourself
            if ($id == $user['id']) {
                jsonResponse(['detail' => 'Cannot delete yourself'], 400);
            }
            
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'users', 'delete', "Deleted user $id");
            
            jsonResponse(['message' => 'User deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== DASHBOARD ========================
    if ($resource === 'dashboard') {
        $user = getCurrentUser();
        
        if ($id === 'stats' || !$id) {
            $stats = [];
            
            $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as t FROM donations WHERE status = 'completed'");
            $stats['total_donations'] = (float)$stmt->fetch()['t'];
            
            $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as t FROM expenses");
            $stats['total_expenses'] = (float)$stmt->fetch()['t'];
            
            $stmt = $db->query("SELECT COUNT(*) as c FROM donors");
            $stats['donor_count'] = (int)$stmt->fetch()['c'];
            
            $stmt = $db->query("SELECT COUNT(*) as c FROM programs WHERE status = 'active'");
            $stats['program_count'] = (int)$stmt->fetch()['c'];
            
            $stmt = $db->query("SELECT COUNT(*) as c FROM employees WHERE status = 'active'");
            $stats['employee_count'] = (int)$stmt->fetch()['c'];
            
            $stmt = $db->query("SELECT COUNT(*) as c FROM volunteers WHERE status = 'active'");
            $stats['volunteer_count'] = (int)$stmt->fetch()['c'];
            
            $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as t FROM donations WHERE status = 'completed' AND MONTH(donation_date) = MONTH(NOW()) AND YEAR(donation_date) = YEAR(NOW())");
            $stats['monthly_donations'] = (float)$stmt->fetch()['t'];
            
            $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as t FROM expenses WHERE MONTH(expense_date) = MONTH(NOW()) AND YEAR(expense_date) = YEAR(NOW())");
            $stats['monthly_expenses'] = (float)$stmt->fetch()['t'];
            
            $stats['balance'] = $stats['total_donations'] - $stats['total_expenses'];
            
            jsonResponse($stats);
        }
        
        if ($id === 'recent-donations') {
            $stmt = $db->query("SELECT d.*, dn.name as donor_name FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id ORDER BY d.created_at DESC LIMIT 10");
            jsonResponse($stmt->fetchAll());
        }
        
        if ($id === 'donation-chart') {
            $stmt = $db->query("SELECT DATE_FORMAT(donation_date, '%Y-%m') as month, SUM(amount) as total 
                               FROM donations WHERE status = 'completed' AND donation_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                               GROUP BY DATE_FORMAT(donation_date, '%Y-%m') ORDER BY month");
            jsonResponse($stmt->fetchAll());
        }
        
        if ($id === 'expense-chart') {
            $stmt = $db->query("SELECT expense_category as category, SUM(amount) as total FROM expenses GROUP BY expense_category ORDER BY total DESC LIMIT 10");
            jsonResponse($stmt->fetchAll());
        }
        
        jsonResponse(['detail' => 'Not found'], 404);
    }
    
    // ======================== COUNTS ========================
    if ($resource === 'counts') {
        $user = getCurrentUser();
        $counts = [];
        
        $stmt = $db->query("SELECT COUNT(*) as c FROM donations");
        $counts['donations'] = (int)$stmt->fetch()['c'];
        
        $stmt = $db->query("SELECT COUNT(*) as c FROM donors");
        $counts['donors'] = (int)$stmt->fetch()['c'];
        
        $stmt = $db->query("SELECT COUNT(*) as c FROM programs");
        $counts['programs'] = (int)$stmt->fetch()['c'];
        
        $stmt = $db->query("SELECT COUNT(*) as c FROM employees");
        $counts['employees'] = (int)$stmt->fetch()['c'];
        
        jsonResponse($counts);
    }
    
    // ======================== DONORS ========================
    if ($resource === 'donors') {
        if ($method === 'GET' && !$id) {
            requirePermission('donors');
            
            $search = $_GET['search'] ?? '';
            $type = $_GET['type'] ?? '';
            
            $sql = "SELECT d.*, COALESCE((SELECT SUM(amount) FROM donations WHERE donor_id = d.id AND status = 'completed'), 0) as total_donations 
                    FROM donors d WHERE 1=1";
            $params = [];
            
            if ($search) {
                $sql .= " AND (d.name LIKE ? OR d.email LIKE ? OR d.phone LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($type) {
                $sql .= " AND d.donor_type = ?";
                $params[] = $type;
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            $items = $stmt->fetchAll();
            jsonResponse(['items' => $items, 'total' => count($items)]);
        }
        
        if ($method === 'GET' && $id) {
            requirePermission('donors');
            
            $stmt = $db->prepare("SELECT d.*, COALESCE((SELECT SUM(amount) FROM donations WHERE donor_id = d.id AND status = 'completed'), 0) as total_donations FROM donors d WHERE d.id = ?");
            $stmt->execute([$id]);
            $donor = $stmt->fetch();
            
            if (!$donor) jsonResponse(['detail' => 'Not found'], 404);
            
            $stmt = $db->prepare("SELECT * FROM donations WHERE donor_id = ? ORDER BY donation_date DESC LIMIT 20");
            $stmt->execute([$id]);
            $donor['donations'] = $stmt->fetchAll();
            
            jsonResponse($donor);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('donors');
            $data = getRequestBody();
            
            // Bulk delete
            if ($id === 'bulk-delete' || (!$id && isset($data['ids']))) {
                $ids = $data['ids'] ?? [];
                if (empty($ids)) jsonResponse(['detail' => 'No IDs provided'], 400);
                
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $db->prepare("DELETE FROM donors WHERE id IN ($placeholders)")->execute($ids);
                logActivity($user['id'], 'donors', 'bulk_delete', "Bulk deleted: " . count($ids) . " donors");
                jsonResponse(['message' => 'Donors deleted', 'count' => count($ids)]);
            }
            
            if (empty($data['name'])) jsonResponse(['detail' => 'Name required'], 400);
            
            $stmt = $db->prepare("INSERT INTO donors (donor_type, name, email, phone, pan, address, city, state, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['donor_type'] ?? 'individual',
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['pan'] ?? null,
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['country'] ?? 'India'
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'donors', 'create', "Created: {$data['name']}");
            
            $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('donors');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE donors SET donor_type = COALESCE(?, donor_type), name = COALESCE(?, name), 
                                  email = COALESCE(?, email), phone = COALESCE(?, phone), pan = COALESCE(?, pan),
                                  address = COALESCE(?, address), city = COALESCE(?, city), state = COALESCE(?, state),
                                  country = COALESCE(?, country) WHERE id = ?");
            $stmt->execute([
                $data['donor_type'] ?? null, $data['name'] ?? null, $data['email'] ?? null,
                $data['phone'] ?? null, $data['pan'] ?? null, $data['address'] ?? null,
                $data['city'] ?? null, $data['state'] ?? null, $data['country'] ?? null, $id
            ]);
            
            logActivity($user['id'], 'donors', 'update', "Updated: $id");
            
            $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('donors');
            
            $stmt = $db->prepare("SELECT name FROM donors WHERE id = ?");
            $stmt->execute([$id]);
            $donor = $stmt->fetch();
            
            if (!$donor) jsonResponse(['detail' => 'Not found'], 404);
            
            $db->prepare("DELETE FROM donors WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'donors', 'delete', "Deleted: {$donor['name']}");
            
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== DONATIONS ========================
    if ($resource === 'donations') {
        // Receipt action
        if ($id && $action === 'receipt' && $method === 'GET') {
            requirePermission('donations');
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name, dn.email as donor_email, dn.pan as donor_pan, dn.address as donor_address, p.program_name 
                                  FROM donations d 
                                  LEFT JOIN donors dn ON d.donor_id = dn.id 
                                  LEFT JOIN programs p ON d.program_id = p.id 
                                  WHERE d.id = ?");
            $stmt->execute([$id]);
            $donation = $stmt->fetch();
            
            if (!$donation) jsonResponse(['detail' => 'Not found'], 404);
            
            header('Content-Type: text/html');
            echo generateReceipt($donation);
            exit;
        }
        
        // Send receipt action
        if ($id && $action === 'send-receipt' && $method === 'POST') {
            $user = requirePermission('donations');
            
            $stmt = $db->prepare("SELECT d.*, dn.email as donor_email FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id WHERE d.id = ?");
            $stmt->execute([$id]);
            $donation = $stmt->fetch();
            
            if (!$donation) jsonResponse(['detail' => 'Not found'], 404);
            if (empty($donation['donor_email'])) jsonResponse(['detail' => 'No email'], 400);
            
            logActivity($user['id'], 'donations', 'send_receipt', "Receipt sent: {$donation['donation_code']}");
            jsonResponse(['message' => 'Receipt sent', 'email' => $donation['donor_email']]);
        }
        
        // Status update
        if ($id && $action === 'status' && $method === 'PUT') {
            $user = requirePermission('donations');
            $data = getRequestBody();
            
            $db->prepare("UPDATE donations SET status = ? WHERE id = ?")->execute([$data['status'], $id]);
            logActivity($user['id'], 'donations', 'update', "Status changed: $id");
            
            $stmt = $db->prepare("SELECT * FROM donations WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'GET' && !$id) {
            requirePermission('donations');
            
            $status = $_GET['status'] ?? '';
            $programId = $_GET['program_id'] ?? '';
            
            $sql = "SELECT d.*, dn.name as donor_name, dn.email as donor_email, p.program_name 
                    FROM donations d 
                    LEFT JOIN donors dn ON d.donor_id = dn.id
                    LEFT JOIN programs p ON d.program_id = p.id
                    WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND d.status = ?";
                $params[] = $status;
            }
            if ($programId) {
                $sql .= " AND d.program_id = ?";
                $params[] = $programId;
            }
            
            $sql .= " ORDER BY d.donation_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            $donItems = $stmt->fetchAll();
            jsonResponse(['items' => $donItems, 'total' => count($donItems)]);
        }
        
        if ($method === 'GET' && $id && !$action) {
            requirePermission('donations');
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name, dn.email as donor_email, p.program_name 
                                  FROM donations d 
                                  LEFT JOIN donors dn ON d.donor_id = dn.id 
                                  LEFT JOIN programs p ON d.program_id = p.id 
                                  WHERE d.id = ?");
            $stmt->execute([$id]);
            $donation = $stmt->fetch();
            
            if (!$donation) jsonResponse(['detail' => 'Not found'], 404);
            jsonResponse($donation);
        }
        
        if ($method === 'POST' && !$id) {
            $user = requirePermission('donations');
            $data = getRequestBody();
            
            if (empty($data['donor_id']) || empty($data['amount'])) {
                jsonResponse(['detail' => 'Donor and amount required'], 400);
            }
            
            $code = generateDonationCode();
            
            $stmt = $db->prepare("INSERT INTO donations (donation_code, donor_id, program_id, amount, donation_type, payment_method, transaction_id, status, donation_date) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $code,
                $data['donor_id'],
                $data['program_id'] ?? null,
                $data['amount'],
                $data['donation_type'] ?? 'monetary',
                $data['payment_method'] ?? 'cash',
                $data['transaction_id'] ?? null,
                $data['status'] ?? 'completed',
                $data['donation_date'] ?? date('Y-m-d')
            ]);
            
            $newId = $db->lastInsertId();
            
            // Ledger entry
            if (($data['status'] ?? 'completed') === 'completed') {
                $db->prepare("INSERT INTO ledger_entries (reference_type, reference_id, credit, entry_date) VALUES (?, ?, ?, ?)")
                   ->execute(['donation', $newId, $data['amount'], $data['donation_date'] ?? date('Y-m-d')]);
            }
            
            logActivity($user['id'], 'donations', 'create', "Created: $code");
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id WHERE d.id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id && !$action) {
            $user = requirePermission('donations');
            $data = getRequestBody();
            
            if (isset($data['status'])) {
                $db->prepare("UPDATE donations SET status = ? WHERE id = ?")->execute([$data['status'], $id]);
                logActivity($user['id'], 'donations', 'update', "Status: $id -> {$data['status']}");
            }
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id WHERE d.id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('donations');
            
            $stmt = $db->prepare("SELECT donation_code FROM donations WHERE id = ?");
            $stmt->execute([$id]);
            $donation = $stmt->fetch();
            
            if (!$donation) jsonResponse(['detail' => 'Not found'], 404);
            
            $db->prepare("DELETE FROM ledger_entries WHERE reference_type = 'donation' AND reference_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM donations WHERE id = ?")->execute([$id]);
            
            logActivity($user['id'], 'donations', 'delete', "Deleted: {$donation['donation_code']}");
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== PROGRAMS ========================
    if ($resource === 'programs') {
        if ($method === 'GET' && !$id) {
            requirePermission('programs');
            
            $status = $_GET['status'] ?? '';
            $sql = "SELECT p.*, 
                    COALESCE((SELECT SUM(amount) FROM donations WHERE program_id = p.id AND status = 'completed'), 0) as total_donations,
                    COALESCE((SELECT SUM(amount) FROM expenses WHERE program_id = p.id), 0) as total_expenses
                    FROM programs p WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND p.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY p.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'GET' && $id) {
            requirePermission('programs');
            
            $stmt = $db->prepare("SELECT p.*, 
                    COALESCE((SELECT SUM(amount) FROM donations WHERE program_id = p.id AND status = 'completed'), 0) as total_donations,
                    COALESCE((SELECT SUM(amount) FROM expenses WHERE program_id = p.id), 0) as total_expenses
                    FROM programs p WHERE p.id = ?");
            $stmt->execute([$id]);
            $program = $stmt->fetch();
            
            if (!$program) jsonResponse(['detail' => 'Not found'], 404);
            jsonResponse($program);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('programs');
            $data = getRequestBody();
            
            if (empty($data['program_name'])) jsonResponse(['detail' => 'Name required'], 400);
            
            $stmt = $db->prepare("INSERT INTO programs (program_name, description, start_date, end_date, budget, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['program_name'],
                $data['description'] ?? null,
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $data['budget'] ?? 0,
                $data['status'] ?? 'active'
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'programs', 'create', "Created: {$data['program_name']}");
            
            $stmt = $db->prepare("SELECT * FROM programs WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('programs');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE programs SET program_name = COALESCE(?, program_name), description = COALESCE(?, description),
                                  start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date),
                                  budget = COALESCE(?, budget), status = COALESCE(?, status) WHERE id = ?");
            $stmt->execute([
                $data['program_name'] ?? null, $data['description'] ?? null,
                $data['start_date'] ?? null, $data['end_date'] ?? null,
                $data['budget'] ?? null, $data['status'] ?? null, $id
            ]);
            
            logActivity($user['id'], 'programs', 'update', "Updated: $id");
            
            $stmt = $db->prepare("SELECT * FROM programs WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('programs');
            $db->prepare("DELETE FROM programs WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'programs', 'delete', "Deleted: $id");
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== PROJECTS ========================
    if ($resource === 'projects') {
        if ($method === 'GET' && !$id) {
            requirePermission('projects');
            $stmt = $db->query("SELECT p.*, pr.program_name FROM projects p LEFT JOIN programs pr ON p.program_id = pr.id ORDER BY p.id DESC");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('projects');
            $data = getRequestBody();
            
            $stmt = $db->prepare("INSERT INTO projects (program_id, project_name, budget, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data['program_id'] ?? null, $data['project_name'], $data['budget'] ?? 0, $data['status'] ?? 'active']);
            
            $newId = $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('projects');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE projects SET project_name = COALESCE(?, project_name), budget = COALESCE(?, budget), status = COALESCE(?, status) WHERE id = ?");
            $stmt->execute([$data['project_name'] ?? null, $data['budget'] ?? null, $data['status'] ?? null, $id]);
            
            $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            requirePermission('projects');
            $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== EXPENSES ========================
    if ($resource === 'expenses') {
        if ($method === 'GET' && $id) {
            requirePermission('expenses');
            $stmt = $db->prepare("SELECT e.*, p.program_name FROM expenses e LEFT JOIN programs p ON e.program_id = p.id WHERE e.id = ?");
            $stmt->execute([$id]);
            $expense = $stmt->fetch();
            if (!$expense) jsonResponse(['detail' => 'Not found'], 404);
            jsonResponse($expense);
        }
        
        if ($method === 'GET' && !$id) {
            requirePermission('expenses');
            $category  = $_GET['category']   ?? '';
            $programId = $_GET['program_id'] ?? '';
            $startDate = $_GET['start_date'] ?? '';
            $endDate   = $_GET['end_date']   ?? '';
            
            $sql    = "SELECT e.*, p.program_name FROM expenses e LEFT JOIN programs p ON e.program_id = p.id WHERE 1=1";
            $params = [];
            if ($category)  { $sql .= " AND e.expense_category = ?"; $params[] = $category; }
            if ($programId) { $sql .= " AND e.program_id = ?";       $params[] = $programId; }
            if ($startDate) { $sql .= " AND DATE(e.expense_date) >= ?"; $params[] = $startDate; }
            if ($endDate)   { $sql .= " AND DATE(e.expense_date) <= ?"; $params[] = $endDate; }
            $sql .= " ORDER BY e.expense_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $expItems = $stmt->fetchAll();
            jsonResponse(['items' => $expItems, 'total' => count($expItems)]);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('expenses');
            $data = getRequestBody();
            $fields = ['expense_category', 'amount', 'expense_date', 'program_id', 'description'];
            $updates = []; $params = [];
            foreach ($fields as $f) {
                if (isset($data[$f])) { $updates[] = "$f = ?"; $params[] = $data[$f]; }
            }
            if (!empty($updates)) {
                $params[] = $id;
                $db->prepare("UPDATE expenses SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
            logActivity($user['id'], 'expenses', 'update', "Updated: $id");
            $stmt = $db->prepare("SELECT e.*, p.program_name FROM expenses e LEFT JOIN programs p ON e.program_id = p.id WHERE e.id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'POST') {
            $user = requirePermission('expenses');
            $data = getRequestBody();
            
            $stmt = $db->prepare("INSERT INTO expenses (expense_category, amount, expense_date, program_id, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['expense_category'],
                $data['amount'],
                $data['expense_date'] ?? date('Y-m-d'),
                $data['program_id'] ?? null,
                $data['description'] ?? null,
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            
            // Ledger entry
            $db->prepare("INSERT INTO ledger_entries (reference_type, reference_id, debit, entry_date) VALUES (?, ?, ?, ?)")
               ->execute(['expense', $newId, $data['amount'], $data['expense_date'] ?? date('Y-m-d')]);
            
            logActivity($user['id'], 'expenses', 'create', "Created: {$data['expense_category']}");
            
            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('expenses');
            
            $db->prepare("DELETE FROM ledger_entries WHERE reference_type = 'expense' AND reference_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
            
            logActivity($user['id'], 'expenses', 'delete', "Deleted: $id");
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== EMPLOYEES ========================
    if ($resource === 'employees') {
        if ($method === 'GET' && !$id) {
            requirePermission('employees');
            $status = $_GET['status'] ?? '';
            
            $sql = "SELECT *, 
                    (COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) as gross_salary,
                    ((COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) - 
                     (COALESCE(pf_deduction,0) + COALESCE(esi_deduction,0) + COALESCE(tds_deduction,0) + COALESCE(professional_tax,0) + COALESCE(other_deductions,0))) as net_salary
                    FROM employees WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY name";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['items' => sanitizeRecords($stmt->fetchAll())]);
        }
        
        if ($method === 'GET' && $id) {
            requirePermission('employees');
            $stmt = $db->prepare("SELECT *, 
                    (COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) as gross_salary,
                    ((COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) - 
                     (COALESCE(pf_deduction,0) + COALESCE(esi_deduction,0) + COALESCE(tds_deduction,0) + COALESCE(professional_tax,0) + COALESCE(other_deductions,0))) as net_salary
                    FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $emp = $stmt->fetch();
            if (!$emp) jsonResponse(['detail' => 'Not found'], 404);
            $emp = sanitizeNumericFields($emp);
            
            // Get payroll history
            $stmt2 = $db->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY year DESC, 
                CASE month 
                    WHEN 'January' THEN 1 WHEN 'February' THEN 2 WHEN 'March' THEN 3 
                    WHEN 'April' THEN 4 WHEN 'May' THEN 5 WHEN 'June' THEN 6
                    WHEN 'July' THEN 7 WHEN 'August' THEN 8 WHEN 'September' THEN 9
                    WHEN 'October' THEN 10 WHEN 'November' THEN 11 WHEN 'December' THEN 12
                END DESC LIMIT 12");
            $stmt2->execute([$id]);
            $emp['payroll_history'] = $stmt2->fetchAll();
            
            jsonResponse($emp);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('employees');
            $data = getRequestBody();
            
            if (empty($data['name'])) jsonResponse(['detail' => 'Name required'], 400);
            
            // Generate short employee code (EMP-001, EMP-002, etc.)
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) as max_num FROM employees WHERE employee_code LIKE 'EMP-%'");
            $result = $stmt->fetch();
            $nextNum = ($result['max_num'] ?? 0) + 1;
            $employeeCode = 'EMP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO employees (employee_code, name, email, phone, designation, department,
                basic_salary, hra, da, travel_allowance, medical_allowance, special_allowance, other_allowances,
                pf_deduction, esi_deduction, tds_deduction, professional_tax, other_deductions,
                joining_date, bank_name, bank_account, ifsc_code, pan_number, aadhar_number, 
                status, address, emergency_contact) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $employeeCode,
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['designation'] ?? null,
                $data['department'] ?? null,
                $data['basic_salary'] ?? 0,
                $data['hra'] ?? 0,
                $data['da'] ?? 0,
                $data['travel_allowance'] ?? 0,
                $data['medical_allowance'] ?? 0,
                $data['special_allowance'] ?? 0,
                $data['other_allowances'] ?? 0,
                $data['pf_deduction'] ?? 0,
                $data['esi_deduction'] ?? 0,
                $data['tds_deduction'] ?? 0,
                $data['professional_tax'] ?? 0,
                $data['other_deductions'] ?? 0,
                $data['joining_date'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null,
                $data['pan_number'] ?? null,
                $data['aadhar_number'] ?? null,
                $data['status'] ?? 'active',
                $data['address'] ?? null,
                $data['emergency_contact'] ?? null
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'employees', 'create', "Created: {$data['name']} ($employeeCode)");
            
            $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('employees');
            $data = getRequestBody();
            
            // Build dynamic update query
            $fields = ['name', 'email', 'phone', 'designation', 'department',
                'basic_salary', 'hra', 'da', 'travel_allowance', 'medical_allowance', 
                'special_allowance', 'other_allowances', 'pf_deduction', 'esi_deduction', 
                'tds_deduction', 'professional_tax', 'other_deductions',
                'joining_date', 'bank_name', 'bank_account', 'ifsc_code', 
                'pan_number', 'aadhar_number', 'status', 'address', 'emergency_contact'];
            
            $updates = [];
            $params = [];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (!empty($updates)) {
                $params[] = $id;
                $sql = "UPDATE employees SET " . implode(', ', $updates) . " WHERE id = ?";
                $db->prepare($sql)->execute($params);
            }
            
            logActivity($user['id'], 'employees', 'update', "Updated: $id");
            
            $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('employees');
            $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'employees', 'delete', "Deleted: $id");
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== PAYROLL ========================
    if ($resource === 'payroll') {
        if ($method === 'GET' && !$id) {
            requirePermission('payroll');
            
            $employeeId = $_GET['employee_id'] ?? '';
            $month = $_GET['month'] ?? '';
            $year = $_GET['year'] ?? '';
            
            $sql = "SELECT p.*, e.name as employee_name, e.designation, e.department,
                    e.bank_name, e.bank_account 
                    FROM payroll p 
                    LEFT JOIN employees e ON p.employee_id = e.id 
                    WHERE 1=1";
            $params = [];
            
            if ($employeeId) {
                $sql .= " AND p.employee_id = ?";
                $params[] = $employeeId;
            }
            if ($month) {
                $sql .= " AND p.month = ?";
                $params[] = $month;
            }
            if ($year) {
                $sql .= " AND p.year = ?";
                $params[] = $year;
            }
            
            $sql .= " ORDER BY p.year DESC, 
                CASE p.month 
                    WHEN 'January' THEN 1 WHEN 'February' THEN 2 WHEN 'March' THEN 3 
                    WHEN 'April' THEN 4 WHEN 'May' THEN 5 WHEN 'June' THEN 6
                    WHEN 'July' THEN 7 WHEN 'August' THEN 8 WHEN 'September' THEN 9
                    WHEN 'October' THEN 10 WHEN 'November' THEN 11 WHEN 'December' THEN 12
                END DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['items' => sanitizeRecords($stmt->fetchAll())]);
        }
        
        if ($method === 'GET' && $id) {
            requirePermission('payroll');
            $stmt = $db->prepare("SELECT p.*, e.name as employee_name, e.designation, e.department,
                    e.bank_name, e.bank_account, e.ifsc_code, e.pan_number
                    FROM payroll p 
                    LEFT JOIN employees e ON p.employee_id = e.id 
                    WHERE p.id = ?");
            $stmt->execute([$id]);
            $payroll = $stmt->fetch();
            if (!$payroll) jsonResponse(['detail' => 'Not found'], 404);
            jsonResponse($payroll);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('payroll');
            $data = getRequestBody();
            
            if (empty($data['employee_id']) || empty($data['month']) || empty($data['year'])) {
                jsonResponse(['detail' => 'Employee, month and year required'], 400);
            }
            
            // Check for duplicate
            $stmt = $db->prepare("SELECT id FROM payroll WHERE employee_id = ? AND month = ? AND year = ?");
            $stmt->execute([$data['employee_id'], $data['month'], $data['year']]);
            if ($stmt->fetch()) {
                jsonResponse(['detail' => 'Payroll already exists for this period'], 400);
            }
            
            // Get employee details if salary breakdown not provided
            if (empty($data['basic_salary'])) {
                $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->execute([$data['employee_id']]);
                $emp = $stmt->fetch();
                if (!$emp) jsonResponse(['detail' => 'Employee not found'], 404);
                
                // Copy salary breakdown from employee
                $data['basic_salary'] = $data['basic_salary'] ?? $emp['basic_salary'] ?? 0;
                $data['hra'] = $data['hra'] ?? $emp['hra'] ?? 0;
                $data['da'] = $data['da'] ?? $emp['da'] ?? 0;
                $data['travel_allowance'] = $data['travel_allowance'] ?? $emp['travel_allowance'] ?? 0;
                $data['medical_allowance'] = $data['medical_allowance'] ?? $emp['medical_allowance'] ?? 0;
                $data['special_allowance'] = $data['special_allowance'] ?? $emp['special_allowance'] ?? 0;
                $data['other_allowances'] = $data['other_allowances'] ?? $emp['other_allowances'] ?? 0;
                $data['pf_deduction'] = $data['pf_deduction'] ?? $emp['pf_deduction'] ?? 0;
                $data['esi_deduction'] = $data['esi_deduction'] ?? $emp['esi_deduction'] ?? 0;
                $data['tds_deduction'] = $data['tds_deduction'] ?? $emp['tds_deduction'] ?? 0;
                $data['professional_tax'] = $data['professional_tax'] ?? $emp['professional_tax'] ?? 0;
            }
            
            // Calculate totals
            $grossSalary = ($data['basic_salary'] ?? 0) + ($data['hra'] ?? 0) + ($data['da'] ?? 0) + 
                          ($data['travel_allowance'] ?? 0) + ($data['medical_allowance'] ?? 0) + 
                          ($data['special_allowance'] ?? 0) + ($data['other_allowances'] ?? 0) +
                          ($data['overtime'] ?? 0) + ($data['bonus'] ?? 0);
            
            $totalDeductions = ($data['pf_deduction'] ?? 0) + ($data['esi_deduction'] ?? 0) + 
                              ($data['tds_deduction'] ?? 0) + ($data['professional_tax'] ?? 0) + 
                              ($data['loan_deduction'] ?? 0) + ($data['other_deductions'] ?? 0);
            
            $netSalary = $data['salary_paid'] ?? ($grossSalary - $totalDeductions);
            
            $stmt = $db->prepare("INSERT INTO payroll (employee_id, month, year,
                basic_salary, hra, da, travel_allowance, medical_allowance, special_allowance, other_allowances,
                overtime, bonus, gross_salary,
                pf_deduction, esi_deduction, tds_deduction, professional_tax, loan_deduction, other_deductions, total_deductions,
                salary_paid, payment_date, payment_method, transaction_reference, days_worked, days_absent, notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['employee_id'],
                $data['month'],
                $data['year'],
                $data['basic_salary'] ?? 0,
                $data['hra'] ?? 0,
                $data['da'] ?? 0,
                $data['travel_allowance'] ?? 0,
                $data['medical_allowance'] ?? 0,
                $data['special_allowance'] ?? 0,
                $data['other_allowances'] ?? 0,
                $data['overtime'] ?? 0,
                $data['bonus'] ?? 0,
                $grossSalary,
                $data['pf_deduction'] ?? 0,
                $data['esi_deduction'] ?? 0,
                $data['tds_deduction'] ?? 0,
                $data['professional_tax'] ?? 0,
                $data['loan_deduction'] ?? 0,
                $data['other_deductions'] ?? 0,
                $totalDeductions,
                $netSalary,
                $data['payment_date'] ?? date('Y-m-d'),
                $data['payment_method'] ?? 'bank_transfer',
                $data['transaction_reference'] ?? null,
                $data['days_worked'] ?? 0,
                $data['days_absent'] ?? 0,
                $data['notes'] ?? null,
                $data['status'] ?? 'paid',
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            
            // Ledger entry
            $db->prepare("INSERT INTO ledger_entries (reference_type, reference_id, debit, entry_date, description) VALUES (?, ?, ?, ?, ?)")
               ->execute(['payroll', $newId, $netSalary, $data['payment_date'] ?? date('Y-m-d'), 
                         "Salary: {$data['month']} {$data['year']}"]);
            
            logActivity($user['id'], 'payroll', 'create', "Payroll processed: {$data['month']} {$data['year']}");
            
            $stmt = $db->prepare("SELECT p.*, e.name as employee_name FROM payroll p 
                                  LEFT JOIN employees e ON p.employee_id = e.id WHERE p.id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('payroll');
            
            $stmt = $db->prepare("SELECT * FROM payroll WHERE id = ?");
            $stmt->execute([$id]);
            $payroll = $stmt->fetch();
            if (!$payroll) jsonResponse(['detail' => 'Not found'], 404);
            
            $db->prepare("DELETE FROM ledger_entries WHERE reference_type = 'payroll' AND reference_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM payroll WHERE id = ?")->execute([$id]);
            
            logActivity($user['id'], 'payroll', 'delete', "Deleted: {$payroll['month']} {$payroll['year']}");
            jsonResponse(['message' => 'Deleted']);
        }
        
        // Generate payslip
        if ($id === 'payslip' && $action && $method === 'GET') {
            requirePermission('payroll');
            
            $payrollId = $action;
            $stmt = $db->prepare("SELECT p.*, e.name as employee_name, e.designation, e.department,
                    e.bank_name, e.bank_account, e.ifsc_code, e.pan_number, e.aadhar_number
                    FROM payroll p 
                    LEFT JOIN employees e ON p.employee_id = e.id 
                    WHERE p.id = ?");
            $stmt->execute([$payrollId]);
            $payroll = $stmt->fetch();
            
            if (!$payroll) jsonResponse(['detail' => 'Not found'], 404);
            
            header('Content-Type: text/html');
            echo generatePayslip($payroll);
            exit;
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== VOLUNTEERS ========================
    if ($resource === 'volunteers') {
        if ($method === 'GET' && !$id) {
            requirePermission('volunteers');
            $stmt = $db->query("SELECT * FROM volunteers ORDER BY name");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('volunteers');
            $data = getRequestBody();
            
            // Generate short volunteer code (VOL-001, VOL-002, etc.)
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(volunteer_code, 5) AS UNSIGNED)) as max_num FROM volunteers WHERE volunteer_code LIKE 'VOL-%'");
            $result = $stmt->fetch();
            $nextNum = ($result['max_num'] ?? 0) + 1;
            $volunteerCode = 'VOL-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO volunteers (volunteer_code, name, email, phone, joined_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $volunteerCode,
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['joined_date'] ?? date('Y-m-d'),
                $data['status'] ?? 'active'
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'volunteers', 'create', "Created: {$data['name']} ($volunteerCode)");
            
            $stmt = $db->prepare("SELECT * FROM volunteers WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'PUT' && $id) {
            $user = requirePermission('volunteers');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE volunteers SET name = COALESCE(?, name), email = COALESCE(?, email), 
                                  phone = COALESCE(?, phone), joined_date = COALESCE(?, joined_date),
                                  status = COALESCE(?, status) WHERE id = ?");
            $stmt->execute([
                $data['name'] ?? null, $data['email'] ?? null, $data['phone'] ?? null,
                $data['joined_date'] ?? null, $data['status'] ?? null, $id
            ]);
            
            $stmt = $db->prepare("SELECT * FROM volunteers WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('volunteers');
            $db->prepare("DELETE FROM volunteers WHERE id = ?")->execute([$id]);
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== INVENTORY ========================
    if ($resource === 'inventory') {
        if ($id === 'items' && $method === 'GET') {
            requirePermission('inventory');
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';
            
            $sql = "SELECT * FROM inventory_items WHERE 1=1";
            $params = [];
            if ($search) {
                $sql .= " AND item_name LIKE ?";
                $params[] = "%$search%";
            }
            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            $sql .= " ORDER BY item_name";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($id === 'items' && $method === 'POST') {
            $user = requirePermission('inventory');
            $data = getRequestBody();
            
            $stmt = $db->prepare("INSERT INTO inventory_items (item_name, quantity, unit, category, min_stock, location, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['item_name'], 
                $data['quantity'] ?? 0, 
                $data['unit'] ?? null,
                $data['category'] ?? 'other',
                $data['min_stock'] ?? 0,
                $data['location'] ?? null,
                $data['description'] ?? null
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'inventory', 'create', "Created: {$data['item_name']}");
            
            $stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // Update item - using action parameter
        if ($id === 'items' && $action && $method === 'PUT') {
            $user = requirePermission('inventory');
            $data = getRequestBody();
            $itemId = $action;
            
            $stmt = $db->prepare("UPDATE inventory_items SET 
                item_name = COALESCE(?, item_name),
                quantity = COALESCE(?, quantity),
                unit = COALESCE(?, unit),
                category = COALESCE(?, category),
                min_stock = COALESCE(?, min_stock),
                location = COALESCE(?, location),
                description = COALESCE(?, description)
                WHERE id = ?");
            $stmt->execute([
                $data['item_name'] ?? null,
                $data['quantity'] ?? null,
                $data['unit'] ?? null,
                $data['category'] ?? null,
                $data['min_stock'] ?? null,
                $data['location'] ?? null,
                $data['description'] ?? null,
                $itemId
            ]);
            
            logActivity($user['id'], 'inventory', 'update', "Updated item: $itemId");
            
            $stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = ?");
            $stmt->execute([$itemId]);
            jsonResponse($stmt->fetch());
        }
        
        // Delete item - using action parameter
        if ($id === 'items' && $action && $method === 'DELETE') {
            $user = requirePermission('inventory');
            $db->prepare("DELETE FROM inventory_transactions WHERE item_id = ?")->execute([$action]);
            $db->prepare("DELETE FROM inventory_items WHERE id = ?")->execute([$action]);
            logActivity($user['id'], 'inventory', 'delete', "Deleted item: $action");
            jsonResponse(['message' => 'Deleted']);
        }
        
        if ($id === 'transactions' && $method === 'GET') {
            requirePermission('inventory');
            $stmt = $db->query("SELECT t.*, i.item_name FROM inventory_transactions t LEFT JOIN inventory_items i ON t.item_id = i.id ORDER BY t.transaction_date DESC, t.id DESC");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($id === 'transactions' && $method === 'POST') {
            $user = requirePermission('inventory');
            $data = getRequestBody();
            
            // Get item
            $stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = ?");
            $stmt->execute([$data['item_id']]);
            $item = $stmt->fetch();
            
            if (!$item) jsonResponse(['detail' => 'Item not found'], 404);
            
            if ($data['transaction_type'] === 'out' && $item['quantity'] < $data['quantity']) {
                jsonResponse(['detail' => 'Insufficient stock'], 400);
            }
            
            $stmt = $db->prepare("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, transaction_date, reference, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['item_id'],
                $data['transaction_type'],
                $data['quantity'],
                $data['transaction_date'] ?? date('Y-m-d'),
                $data['reference'] ?? null,
                $data['notes'] ?? null
            ]);
            
            $newQty = $data['transaction_type'] === 'in' ? $item['quantity'] + $data['quantity'] : $item['quantity'] - $data['quantity'];
            $db->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?")->execute([$newQty, $data['item_id']]);
            
            logActivity($user['id'], 'inventory', 'transaction', "{$data['transaction_type']}: {$data['quantity']} of {$item['item_name']}");
            
            jsonResponse(['message' => 'Transaction recorded', 'new_quantity' => $newQty], 201);
        }
        
        jsonResponse(['detail' => 'Invalid endpoint'], 404);
    }
    
    // ======================== LEDGER ========================
    if ($resource === 'ledger') {
        requirePermission('ledger');
        
        if ($id === 'summary' && $method === 'GET') {
            $stmt = $db->query("SELECT COALESCE(SUM(credit), 0) as total_credit, COALESCE(SUM(debit), 0) as total_debit FROM ledger_entries");
            $summary = $stmt->fetch();
            $summary['balance'] = $summary['total_credit'] - $summary['total_debit'];
            jsonResponse($summary);
        }
        
        if ($method === 'GET') {
            $startDate = $_GET['start_date'] ?? '';
            $endDate = $_GET['end_date'] ?? '';
            
            $sql = "SELECT * FROM ledger_entries WHERE 1=1";
            $params = [];
            
            if ($startDate) {
                $sql .= " AND DATE(entry_date) >= ?";
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= " AND DATE(entry_date) <= ?";
                $params[] = $endDate;
            }
            
            $sql .= " ORDER BY entry_date DESC, created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('ledger');
            $data = getRequestBody();
            
            $stmt = $db->prepare("INSERT INTO ledger_entries (reference_type, reference_id, description, debit, credit, entry_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['reference_type'] ?? 'manual',
                $data['reference_id'] ?? null,
                $data['description'] ?? null,
                $data['debit'] ?? 0,
                $data['credit'] ?? 0,
                $data['entry_date'] ?? date('Y-m-d')
            ]);
            
            $newId = $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM ledger_entries WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== ACTIVITY LOGS ========================
    if ($resource === 'activity-logs') {
        requirePermission('settings');
        
        $module = $_GET['module'] ?? '';
        
        $sql = "SELECT a.*, u.full_name as user_name FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE 1=1";
        $params = [];
        
        if ($module) {
            $sql .= " AND a.module = ?";
            $params[] = $module;
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        jsonResponse(['items' => $stmt->fetchAll()]);
    }
    
    // SETTINGS block is below (after seed-data) - this duplicate removed
    // (see the complete settings handler below)
    
    // ======================== GALLERY ========================
    if ($resource === 'gallery') {
        // Check if gallery table exists
        try {
            $db->query("SELECT 1 FROM gallery LIMIT 1");
        } catch (Exception $e) {
            if ($method === 'GET') jsonResponse(['items' => []]);
            jsonResponse(['detail' => 'Gallery table not found. Run the SQL to create it.'], 400);
        }
        
        if ($id === 'categories' && $method === 'GET') {
            $stmt = $db->query("SELECT DISTINCT category FROM gallery WHERE category IS NOT NULL ORDER BY category");
            jsonResponse(array_column($stmt->fetchAll(), 'category'));
        }
        
        if ($method === 'GET' && !$id) {
            requirePermission('gallery');
            
            $category = $_GET['category'] ?? '';
            $programId = $_GET['program_id'] ?? '';
            
            $sql = "SELECT g.*, p.program_name FROM gallery g LEFT JOIN programs p ON g.program_id = p.id WHERE 1=1";
            $params = [];
            
            if ($category) {
                $sql .= " AND g.category = ?";
                $params[] = $category;
            }
            if ($programId) {
                $sql .= " AND g.program_id = ?";
                $params[] = $programId;
            }
            
            $sql .= " ORDER BY g.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        if ($method === 'POST') {
            $user = requirePermission('gallery');
            
            if (empty($_FILES['image'])) {
                jsonResponse(['detail' => 'Image required'], 400);
            }
            
            $file = $_FILES['image'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowed)) {
                jsonResponse(['detail' => 'Invalid file type'], 400);
            }
            
            if ($file['size'] > 5 * 1024 * 1024) {
                jsonResponse(['detail' => 'File too large (max 5MB)'], 400);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $ext;
            $uploadPath = __DIR__ . '/uploads/gallery/' . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                jsonResponse(['detail' => 'Upload failed'], 500);
            }
            
            $imageUrl = '/uploads/gallery/' . $filename;
            
            $stmt = $db->prepare("INSERT INTO gallery (title, description, category, program_id, image_url, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'] ?? 'Untitled',
                $_POST['description'] ?? null,
                $_POST['category'] ?? null,
                $_POST['program_id'] ?? null,
                $imageUrl,
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'gallery', 'upload', "Uploaded: {$_POST['title']}");
            
            $stmt = $db->prepare("SELECT * FROM gallery WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('gallery');
            
            $stmt = $db->prepare("SELECT * FROM gallery WHERE id = ?");
            $stmt->execute([$id]);
            $image = $stmt->fetch();
            
            if (!$image) jsonResponse(['detail' => 'Not found'], 404);
            
            // Delete file
            $filePath = __DIR__ . $image['image_url'];
            if (file_exists($filePath)) unlink($filePath);
            
            $db->prepare("DELETE FROM gallery WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'gallery', 'delete', "Deleted: {$image['title']}");
            
            jsonResponse(['message' => 'Deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== REPORTS ========================
    if ($resource === 'reports') {
        $user = requirePermission('reports');
        
        if ($id === 'donations') {
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name, p.program_name 
                                  FROM donations d 
                                  LEFT JOIN donors dn ON d.donor_id = dn.id 
                                  LEFT JOIN programs p ON d.program_id = p.id 
                                  WHERE d.donation_date BETWEEN ? AND ? 
                                  ORDER BY d.donation_date DESC");
            $stmt->execute([$startDate, $endDate]);
            
            $donations = $stmt->fetchAll();
            $total = array_sum(array_column($donations, 'amount'));
            
            jsonResponse(['items' => $donations, 'total' => $total, 'start_date' => $startDate, 'end_date' => $endDate]);
        }
        
        if ($id === 'expenses') {
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $stmt = $db->prepare("SELECT e.*, p.program_name 
                                  FROM expenses e 
                                  LEFT JOIN programs p ON e.program_id = p.id 
                                  WHERE e.expense_date BETWEEN ? AND ? 
                                  ORDER BY e.expense_date DESC");
            $stmt->execute([$startDate, $endDate]);
            
            $expenses = $stmt->fetchAll();
            $total = array_sum(array_column($expenses, 'amount'));
            
            jsonResponse(['items' => $expenses, 'total' => $total, 'start_date' => $startDate, 'end_date' => $endDate]);
        }
        
        jsonResponse(['detail' => 'Invalid report type'], 404);
    }
    
    // ======================== EXPORT ========================
    if ($resource === 'export') {
        $user = requirePermission($id);
        
        $filename = $id . '_export_' . date('Ymd_His') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        switch ($id) {
            case 'donations':
                $stmt = $db->query("SELECT d.donation_code, dn.name as donor_name, dn.email, p.program_name, d.amount, d.payment_method, d.status, d.donation_date FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id LEFT JOIN programs p ON d.program_id = p.id ORDER BY d.donation_date DESC");
                fputcsv($output, ['Code', 'Donor', 'Email', 'Program', 'Amount', 'Method', 'Status', 'Date']);
                break;
            case 'donors':
                $stmt = $db->query("SELECT name, donor_type, email, phone, pan, city, state, country FROM donors ORDER BY name");
                fputcsv($output, ['Name', 'Type', 'Email', 'Phone', 'PAN', 'City', 'State', 'Country']);
                break;
            case 'employees':
                $stmt = $db->query("SELECT name, email, phone, designation, department, basic_salary, hra, da, 
                    travel_allowance, medical_allowance, special_allowance, 
                    (COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) as gross_salary,
                    pf_deduction, esi_deduction, tds_deduction, professional_tax,
                    ((COALESCE(basic_salary,0) + COALESCE(hra,0) + COALESCE(da,0) + COALESCE(travel_allowance,0) + COALESCE(medical_allowance,0) + COALESCE(special_allowance,0) + COALESCE(other_allowances,0)) - 
                     (COALESCE(pf_deduction,0) + COALESCE(esi_deduction,0) + COALESCE(tds_deduction,0) + COALESCE(professional_tax,0) + COALESCE(other_deductions,0))) as net_salary,
                    joining_date, bank_name, bank_account, status FROM employees ORDER BY name");
                fputcsv($output, ['Name', 'Email', 'Phone', 'Designation', 'Department', 'Basic', 'HRA', 'DA', 
                    'Travel', 'Medical', 'Special', 'Gross Salary', 'PF', 'ESI', 'TDS', 'Prof. Tax', 'Net Salary',
                    'Joining Date', 'Bank Name', 'Bank Account', 'Status']);
                break;
            case 'expenses':
                $stmt = $db->query("SELECT e.expense_category, e.amount, e.expense_date, p.program_name, e.description FROM expenses e LEFT JOIN programs p ON e.program_id = p.id ORDER BY e.expense_date DESC");
                fputcsv($output, ['Category', 'Amount', 'Date', 'Program', 'Description']);
                break;
            case 'volunteers':
                $stmt = $db->query("SELECT name, email, phone, joined_date, status FROM volunteers ORDER BY name");
                fputcsv($output, ['Name', 'Email', 'Phone', 'Joined', 'Status']);
                break;
            case 'programs':
                $stmt = $db->query("SELECT program_name, description, start_date, end_date, budget, status FROM programs ORDER BY program_name");
                fputcsv($output, ['Name', 'Description', 'Start', 'End', 'Budget', 'Status']);
                break;
            case 'inventory':
                $stmt = $db->query("SELECT item_name, quantity, unit FROM inventory_items ORDER BY item_name");
                fputcsv($output, ['Item', 'Quantity', 'Unit']);
                break;
            case 'payroll':
                $stmt = $db->query("SELECT e.name, p.month, p.year, p.basic_salary, p.hra, p.da, p.gross_salary,
                    p.pf_deduction, p.tds_deduction, p.total_deductions, p.salary_paid, p.payment_date, p.status
                    FROM payroll p LEFT JOIN employees e ON p.employee_id = e.id 
                    ORDER BY p.year DESC, p.month");
                fputcsv($output, ['Employee', 'Month', 'Year', 'Basic', 'HRA', 'DA', 'Gross', 
                    'PF', 'TDS', 'Total Deductions', 'Net Paid', 'Payment Date', 'Status']);
                break;
            case 'ledger':
                $stmt = $db->query("SELECT entry_date, reference_type, description, debit, credit FROM ledger_entries ORDER BY entry_date DESC, id DESC");
                fputcsv($output, ['Date', 'Type', 'Description', 'Debit', 'Credit']);
                break;
            default:
                jsonResponse(['detail' => 'Invalid module'], 400);
        }
        
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($output, array_values($row));
        }
        
        fclose($output);
        exit;
    }
    
    // ======================== PDF EXPORT (REPORTS) ========================
    if ($resource === 'export-pdf') {
        $user = requirePermission('reports');
        
        $reportType = $id; // donations, expenses, inventory, donors
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        
        // Set headers for PDF (actually HTML that can be printed to PDF)
        header('Content-Type: text/html; charset=utf-8');
        
        $reportTitle = '';
        $reportData = [];
        
        switch ($reportType) {
            case 'donations':
                $reportTitle = 'Donation Trends Report';
                
                // Monthly trends
                $stmt = $db->prepare("SELECT 
                    MONTH(donation_date) as month,
                    COUNT(*) as count,
                    SUM(amount) as total
                    FROM donations 
                    WHERE YEAR(donation_date) = ? AND status = 'completed'
                    GROUP BY MONTH(donation_date)
                    ORDER BY month");
                $stmt->execute([$year]);
                $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // By payment method
                $stmt = $db->prepare("SELECT 
                    payment_method,
                    COUNT(*) as count,
                    SUM(amount) as total
                    FROM donations 
                    WHERE YEAR(donation_date) = ? AND status = 'completed'
                    GROUP BY payment_method
                    ORDER BY total DESC");
                $stmt->execute([$year]);
                $methodData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Top donors
                $stmt = $db->prepare("SELECT 
                    dn.name,
                    COUNT(*) as donation_count,
                    SUM(d.amount) as total
                    FROM donations d
                    JOIN donors dn ON d.donor_id = dn.id
                    WHERE YEAR(d.donation_date) = ? AND d.status = 'completed'
                    GROUP BY d.donor_id, dn.name
                    ORDER BY total DESC
                    LIMIT 10");
                $stmt->execute([$year]);
                $topDonors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Total
                $stmt = $db->prepare("SELECT SUM(amount) as total FROM donations WHERE YEAR(donation_date) = ? AND status = 'completed'");
                $stmt->execute([$year]);
                $total = $stmt->fetch()['total'] ?? 0;
                
                $reportData = ['monthly' => $monthlyData, 'methods' => $methodData, 'topDonors' => $topDonors, 'total' => $total];
                break;
                
            case 'expenses':
                $reportTitle = 'Expense Analysis Report';
                
                // Monthly trends
                $stmt = $db->prepare("SELECT 
                    MONTH(expense_date) as month,
                    COUNT(*) as count,
                    SUM(amount) as total
                    FROM expenses 
                    WHERE YEAR(expense_date) = ?
                    GROUP BY MONTH(expense_date)
                    ORDER BY month");
                $stmt->execute([$year]);
                $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // By category
                $stmt = $db->prepare("SELECT 
                    expense_category as category,
                    COUNT(*) as count,
                    SUM(amount) as total
                    FROM expenses 
                    WHERE YEAR(expense_date) = ?
                    GROUP BY expense_category
                    ORDER BY total DESC");
                $stmt->execute([$year]);
                $categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Total
                $stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE YEAR(expense_date) = ?");
                $stmt->execute([$year]);
                $total = $stmt->fetch()['total'] ?? 0;
                
                $reportData = ['monthly' => $monthlyData, 'categories' => $categoryData, 'total' => $total];
                break;
                
            case 'inventory':
                $reportTitle = 'Inventory Distribution Report';
                
                // By category
                $stmt = $db->query("SELECT 
                    COALESCE(category, 'other') as category,
                    COUNT(*) as item_count,
                    SUM(quantity) as total_quantity
                    FROM inventory_items 
                    GROUP BY category
                    ORDER BY total_quantity DESC");
                $categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Total items
                $stmt = $db->query("SELECT COUNT(*) as count, SUM(quantity) as total FROM inventory_items");
                $totals = $stmt->fetch();
                
                $reportData = ['categories' => $categoryData, 'itemCount' => $totals['count'], 'totalQuantity' => $totals['total']];
                break;
                
            case 'donors':
                $reportTitle = 'Donor Analysis Report';
                
                // By type
                $stmt = $db->query("SELECT 
                    donor_type as type,
                    COUNT(*) as count
                    FROM donors 
                    GROUP BY donor_type
                    ORDER BY count DESC");
                $typeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Donations by donor type
                $stmt = $db->prepare("SELECT 
                    dn.donor_type as type,
                    SUM(d.amount) as total
                    FROM donations d
                    JOIN donors dn ON d.donor_id = dn.id
                    WHERE YEAR(d.donation_date) = ? AND d.status = 'completed'
                    GROUP BY dn.donor_type
                    ORDER BY total DESC");
                $stmt->execute([$year]);
                $donationsByType = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Top donors with details
                $stmt = $db->prepare("SELECT 
                    dn.name, dn.donor_type, dn.email, dn.city,
                    COUNT(*) as donation_count,
                    SUM(d.amount) as total
                    FROM donations d
                    JOIN donors dn ON d.donor_id = dn.id
                    WHERE d.status = 'completed'
                    GROUP BY d.donor_id, dn.name, dn.donor_type, dn.email, dn.city
                    ORDER BY total DESC
                    LIMIT 15");
                $stmt->execute();
                $topDonors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $reportData = ['types' => $typeData, 'donationsByType' => $donationsByType, 'topDonors' => $topDonors];
                break;
                
            case 'summary':
                $reportTitle = 'Annual Summary Report';
                
                // Donations summary
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM donations WHERE YEAR(donation_date) = ? AND status = 'completed'");
                $stmt->execute([$year]);
                $donations = $stmt->fetch();
                $donations['total'] = (float)($donations['total'] ?? 0);
                $donations['count'] = (int)($donations['count'] ?? 0);
                
                // Expenses summary
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM expenses WHERE YEAR(expense_date) = ?");
                $stmt->execute([$year]);
                $expenses = $stmt->fetch();
                $expenses['total'] = (float)($expenses['total'] ?? 0);
                $expenses['count'] = (int)($expenses['count'] ?? 0);
                
                // Donors count
                $stmt = $db->query("SELECT COUNT(*) as count FROM donors");
                $donors = (int)$stmt->fetch()['count'];
                
                // Programs
                $stmt = $db->query("SELECT COUNT(*) as count FROM programs WHERE status = 'active'");
                $programs = (int)$stmt->fetch()['count'];
                
                // Inventory
                $stmt = $db->query("SELECT COALESCE(SUM(quantity), 0) as total FROM inventory_items");
                $inventory = (int)($stmt->fetch()['total'] ?? 0);
                
                $reportData = [
                    'donations' => $donations,
                    'expenses' => $expenses,
                    'donors' => $donors,
                    'programs' => $programs,
                    'inventory' => $inventory,
                    'netBalance' => (float)$donations['total'] - (float)$expenses['total']
                ];
                break;
                
            default:
                jsonResponse(['detail' => 'Invalid report type'], 400);
        }
        
        $months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        
        // Generate HTML report
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($reportTitle) . ' - Dhrub Foundation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #1e293b; background: #f8fafc; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid #059669; }
        .logo { font-size: 28px; font-weight: 700; color: #059669; margin-bottom: 5px; }
        .subtitle { color: #64748b; font-size: 14px; }
        .report-title { font-size: 24px; margin: 20px 0 5px; color: #1e293b; }
        .report-meta { color: #64748b; font-size: 14px; }
        .section { margin: 30px 0; }
        .section-title { font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f1f5f9; padding: 12px; text-align: left; font-weight: 600; color: #475569; font-size: 13px; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #1e293b; font-size: 14px; }
        tr:hover { background: #f8fafc; }
        .amount { text-align: right; font-weight: 600; color: #059669; }
        .amount.expense { color: #ef4444; }
        .summary-box { display: inline-block; background: linear-gradient(135deg, #059669, #047857); color: white; padding: 20px 30px; border-radius: 10px; margin: 10px 10px 10px 0; min-width: 180px; }
        .summary-box.blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .summary-box.amber { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .summary-box.red { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .summary-label { font-size: 13px; opacity: 0.9; }
        .summary-value { font-size: 28px; font-weight: 700; margin-top: 5px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 12px; }
        .print-btn { background: #059669; color: white; border: none; padding: 10px 25px; border-radius: 6px; cursor: pointer; font-size: 14px; margin-bottom: 20px; }
        .print-btn:hover { background: #047857; }
        @media print { .print-btn { display: none; } .container { box-shadow: none; } body { background: white; padding: 0; } }
    </style>
</head>
<body>
    <div class="container">
        <button class="print-btn" onclick="window.print()">Download PDF / Print</button>
        <div class="header">
            <div class="logo">Dhrub Foundation</div>
            <div class="subtitle">Enterprise Resource Planning System</div>
            <h1 class="report-title">' . htmlspecialchars($reportTitle) . '</h1>
            <div class="report-meta">Year: ' . $year . ' | Generated: ' . date('F d, Y H:i') . '</div>
        </div>';
        
        // Render report based on type
        switch ($reportType) {
            case 'donations':
                echo '<div class="section">
                    <div class="summary-box"><div class="summary-label">Total Donations</div><div class="summary-value">₹' . number_format($reportData['total']) . '</div></div>
                    <div class="summary-box blue"><div class="summary-label">Transactions</div><div class="summary-value">' . array_sum(array_column($reportData['monthly'], 'count')) . '</div></div>
                </div>
                
                <div class="section">
                    <h2 class="section-title">Monthly Donation Trends</h2>
                    <table>
                        <tr><th>Month</th><th>Donations</th><th style="text-align:right">Amount</th></tr>';
                foreach ($reportData['monthly'] as $row) {
                    echo '<tr><td>' . $months[$row['month']] . '</td><td>' . $row['count'] . '</td><td class="amount">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>
                
                <div class="section">
                    <h2 class="section-title">By Payment Method</h2>
                    <table>
                        <tr><th>Method</th><th>Count</th><th style="text-align:right">Amount</th></tr>';
                foreach ($reportData['methods'] as $row) {
                    echo '<tr><td>' . ucfirst(str_replace('_', ' ', $row['payment_method'])) . '</td><td>' . $row['count'] . '</td><td class="amount">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>
                
                <div class="section">
                    <h2 class="section-title">Top 10 Donors</h2>
                    <table>
                        <tr><th>#</th><th>Donor Name</th><th>Donations</th><th style="text-align:right">Total</th></tr>';
                $rank = 1;
                foreach ($reportData['topDonors'] as $row) {
                    echo '<tr><td>' . $rank++ . '</td><td>' . htmlspecialchars($row['name']) . '</td><td>' . $row['donation_count'] . '</td><td class="amount">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>';
                break;
                
            case 'expenses':
                echo '<div class="section">
                    <div class="summary-box red"><div class="summary-label">Total Expenses</div><div class="summary-value">₹' . number_format($reportData['total']) . '</div></div>
                    <div class="summary-box amber"><div class="summary-label">Transactions</div><div class="summary-value">' . array_sum(array_column($reportData['monthly'], 'count')) . '</div></div>
                </div>
                
                <div class="section">
                    <h2 class="section-title">Monthly Expense Trends</h2>
                    <table>
                        <tr><th>Month</th><th>Expenses</th><th style="text-align:right">Amount</th></tr>';
                foreach ($reportData['monthly'] as $row) {
                    echo '<tr><td>' . $months[$row['month']] . '</td><td>' . $row['count'] . '</td><td class="amount expense">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>
                
                <div class="section">
                    <h2 class="section-title">By Category</h2>
                    <table>
                        <tr><th>Category</th><th>Count</th><th style="text-align:right">Amount</th><th style="text-align:right">%</th></tr>';
                foreach ($reportData['categories'] as $row) {
                    $pct = $reportData['total'] > 0 ? round($row['total'] / $reportData['total'] * 100, 1) : 0;
                    echo '<tr><td>' . ucfirst($row['category']) . '</td><td>' . $row['count'] . '</td><td class="amount expense">₹' . number_format($row['total']) . '</td><td style="text-align:right">' . $pct . '%</td></tr>';
                }
                echo '</table></div>';
                break;
                
            case 'inventory':
                echo '<div class="section">
                    <div class="summary-box amber"><div class="summary-label">Total Items</div><div class="summary-value">' . number_format($reportData['totalQuantity']) . '</div></div>
                    <div class="summary-box blue"><div class="summary-label">Categories</div><div class="summary-value">' . count($reportData['categories']) . '</div></div>
                </div>
                
                <div class="section">
                    <h2 class="section-title">Inventory by Category</h2>
                    <table>
                        <tr><th>Category</th><th style="text-align:right">Unique Items</th><th style="text-align:right">Total Quantity</th><th style="text-align:right">%</th></tr>';
                $categoryLabels = ['food' => 'Food & Grains', 'clothing' => 'Clothing', 'medical' => 'Medical', 'educational' => 'Educational', 'household' => 'Household', 'equipment' => 'Equipment', 'other' => 'Other'];
                foreach ($reportData['categories'] as $row) {
                    $pct = $reportData['totalQuantity'] > 0 ? round($row['total_quantity'] / $reportData['totalQuantity'] * 100, 1) : 0;
                    $label = $categoryLabels[$row['category']] ?? ucfirst($row['category']);
                    echo '<tr><td>' . $label . '</td><td style="text-align:right">' . $row['item_count'] . '</td><td style="text-align:right;font-weight:600">' . number_format($row['total_quantity']) . '</td><td style="text-align:right">' . $pct . '%</td></tr>';
                }
                echo '</table></div>';
                break;
                
            case 'donors':
                echo '<div class="section">
                    <h2 class="section-title">Donors by Type</h2>
                    <table>
                        <tr><th>Type</th><th style="text-align:right">Count</th></tr>';
                foreach ($reportData['types'] as $row) {
                    echo '<tr><td>' . ucfirst($row['type']) . '</td><td style="text-align:right;font-weight:600">' . $row['count'] . '</td></tr>';
                }
                echo '</table></div>
                
                <div class="section">
                    <h2 class="section-title">Donations by Donor Type (' . $year . ')</h2>
                    <table>
                        <tr><th>Type</th><th style="text-align:right">Total Donated</th></tr>';
                foreach ($reportData['donationsByType'] as $row) {
                    echo '<tr><td>' . ucfirst($row['type']) . '</td><td class="amount">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>
                
                <div class="section">
                    <h2 class="section-title">Top 15 Donors (All Time)</h2>
                    <table>
                        <tr><th>#</th><th>Donor</th><th>Type</th><th>City</th><th>Donations</th><th style="text-align:right">Total</th></tr>';
                $rank = 1;
                foreach ($reportData['topDonors'] as $row) {
                    echo '<tr><td>' . $rank++ . '</td><td>' . htmlspecialchars($row['name']) . '</td><td>' . ucfirst($row['donor_type']) . '</td><td>' . htmlspecialchars($row['city'] ?? '-') . '</td><td>' . $row['donation_count'] . '</td><td class="amount">₹' . number_format($row['total']) . '</td></tr>';
                }
                echo '</table></div>';
                break;
                
            case 'summary':
                $netBalance = $reportData['netBalance'];
                $balanceClass = $netBalance >= 0 ? '' : 'red';
                echo '<div class="section">
                    <div class="summary-box"><div class="summary-label">Total Donations</div><div class="summary-value">₹' . number_format($reportData['donations']['total'] ?? 0) . '</div></div>
                    <div class="summary-box red"><div class="summary-label">Total Expenses</div><div class="summary-value">₹' . number_format($reportData['expenses']['total'] ?? 0) . '</div></div>
                    <div class="summary-box ' . $balanceClass . '"><div class="summary-label">Net Balance</div><div class="summary-value">₹' . number_format($netBalance) . '</div></div>
                </div>
                <div class="section">
                    <div class="summary-box blue"><div class="summary-label">Total Donors</div><div class="summary-value">' . number_format($reportData['donors']) . '</div></div>
                    <div class="summary-box amber"><div class="summary-label">Active Programs</div><div class="summary-value">' . number_format($reportData['programs']) . '</div></div>
                    <div class="summary-box"><div class="summary-label">Inventory Items</div><div class="summary-value">' . number_format($reportData['inventory']) . '</div></div>
                </div>';
                break;
        }
        
        echo '<div class="footer">
            <p>Dhrub Foundation ERP System | Report generated on ' . date('F d, Y \a\t H:i:s') . '</p>
            <p>This is a computer-generated report.</p>
        </div>
    </div>
</body>
</html>';
        exit;
    }
    
    // ======================== IMPORT ========================
    if ($resource === 'import') {
        if ($method !== 'POST') jsonResponse(['detail' => 'Method not allowed'], 405);
        
        $user = requirePermission($id);
        
        if (empty($_FILES['file'])) {
            jsonResponse(['detail' => 'File required'], 400);
        }
        
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            jsonResponse(['detail' => 'Please use CSV format'], 400);
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);
        
        $success = 0;
        $errors = [];
        
        while (($row = fgetcsv($handle)) !== false) {
            try {
                $data = array_combine($header, $row);
                
                switch ($id) {
                    case 'donors':
                        if (empty($data['name'])) throw new Exception('Name required');
                        $stmt = $db->prepare("INSERT INTO donors (name, donor_type, email, phone, pan, city, state, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $data['name'], $data['donor_type'] ?? 'individual',
                            $data['email'] ?? null, $data['phone'] ?? null, $data['pan'] ?? null,
                            $data['city'] ?? null, $data['state'] ?? null, $data['country'] ?? 'India'
                        ]);
                        break;
                    case 'employees':
                        if (empty($data['name'])) throw new Exception('Name required');
                        $stmt = $db->prepare("INSERT INTO employees (name, email, phone, designation, salary, joining_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $data['name'], $data['email'] ?? null, $data['phone'] ?? null,
                            $data['designation'] ?? null, $data['salary'] ?? 0,
                            $data['joining_date'] ?? null, $data['status'] ?? 'active'
                        ]);
                        break;
                    case 'donations':
                        if (empty($data['donor_name']) || empty($data['amount'])) throw new Exception('Donor and amount required');
                        
                        // Find or create donor
                        $stmt = $db->prepare("SELECT id FROM donors WHERE name = ?");
                        $stmt->execute([$data['donor_name']]);
                        $donor = $stmt->fetch();
                        
                        if (!$donor) {
                            $db->prepare("INSERT INTO donors (name, email, phone) VALUES (?, ?, ?)")
                               ->execute([$data['donor_name'], $data['donor_email'] ?? null, $data['donor_phone'] ?? null]);
                            $donorId = $db->lastInsertId();
                        } else {
                            $donorId = $donor['id'];
                        }
                        
                        // Find program if specified
                        $programId = null;
                        if (!empty($data['program_name'])) {
                            $stmt = $db->prepare("SELECT id FROM programs WHERE program_name = ?");
                            $stmt->execute([$data['program_name']]);
                            $program = $stmt->fetch();
                            $programId = $program ? $program['id'] : null;
                        }
                        
                        $code = generateDonationCode();
                        $stmt = $db->prepare("INSERT INTO donations (donation_code, donor_id, program_id, amount, payment_method, status, donation_date, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $code, $donorId, $programId,
                            $data['amount'], $data['payment_method'] ?? 'cash',
                            'completed', $data['donation_date'] ?? date('Y-m-d'),
                            $data['transaction_id'] ?? null
                        ]);
                        
                        // Ledger entry
                        $db->prepare("INSERT INTO ledger_entries (reference_type, reference_id, credit, entry_date) VALUES (?, ?, ?, ?)")
                           ->execute(['donation', $db->lastInsertId(), $data['amount'], $data['donation_date'] ?? date('Y-m-d')]);
                        
                        break;
                    case 'volunteers':
                        if (empty($data['name'])) throw new Exception('Name required');
                        
                        // Generate volunteer code
                        $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(volunteer_code, 5) AS UNSIGNED)) as max_num FROM volunteers WHERE volunteer_code LIKE 'VOL-%'");
                        $maxNum = $stmt->fetch()['max_num'] ?? 0;
                        $volunteerCode = 'VOL-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
                        
                        $stmt = $db->prepare("INSERT INTO volunteers (volunteer_code, name, email, phone, joined_date, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $volunteerCode,
                            $data['name'],
                            $data['email'] ?? null,
                            $data['phone'] ?? null,
                            $data['joined_date'] ?? date('Y-m-d'),
                            $data['status'] ?? 'active'
                        ]);
                        break;
                    default:
                        throw new Exception('Invalid import type');
                }
                $success++;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        fclose($handle);
        logActivity($user['id'], $id, 'import', "Imported $success records");
        
        jsonResponse(['success_count' => $success, 'error_count' => count($errors), 'errors' => array_slice($errors, 0, 10)]);
    }
    
    // ======================== BLOG POSTS (Website CMS) ========================
    if ($resource === 'blog') {
        // Get all posts
        if ($method === 'GET' && !$id) {
            requirePermission('gallery'); // Using gallery permission for content management
            $stmt = $db->query("SELECT * FROM blog_posts ORDER BY created_at DESC");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        // Get single post
        if ($method === 'GET' && $id) {
            requirePermission('gallery');
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
            if (!$post) jsonResponse(['detail' => 'Post not found'], 404);
            jsonResponse($post);
        }
        
        // Create post
        if ($method === 'POST') {
            $user = requirePermission('gallery');
            $data = getRequestBody();
            
            if (empty($data['title'])) jsonResponse(['detail' => 'Title is required'], 400);
            
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['title']));
            $slug = trim($slug, '-');
            
            $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, excerpt, content, category, featured_image, is_published, published_at, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['title'],
                $slug,
                $data['excerpt'] ?? null,
                $data['content'] ?? null,
                $data['category'] ?? 'News',
                $data['featured_image'] ?? null,
                $data['is_published'] ?? 0,
                $data['is_published'] ? date('Y-m-d H:i:s') : null,
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'blog', 'create', "Created blog post: {$data['title']}");
            
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // Update post
        if ($method === 'PUT' && $id) {
            $user = requirePermission('gallery');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE blog_posts SET 
                title = COALESCE(?, title),
                excerpt = COALESCE(?, excerpt),
                content = COALESCE(?, content),
                category = COALESCE(?, category),
                featured_image = COALESCE(?, featured_image),
                is_published = COALESCE(?, is_published),
                published_at = CASE WHEN ? = 1 AND published_at IS NULL THEN NOW() ELSE published_at END,
                updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([
                $data['title'] ?? null,
                $data['excerpt'] ?? null,
                $data['content'] ?? null,
                $data['category'] ?? null,
                $data['featured_image'] ?? null,
                $data['is_published'] ?? null,
                $data['is_published'] ?? 0,
                $id
            ]);
            
            logActivity($user['id'], 'blog', 'update', "Updated blog post ID: $id");
            
            $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        // Delete post
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('gallery');
            $db->prepare("DELETE FROM blog_posts WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'blog', 'delete', "Deleted blog post ID: $id");
            jsonResponse(['message' => 'Blog post deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== SUCCESS STORIES (Website CMS) ========================
    if ($resource === 'stories') {
        // Get all stories
        if ($method === 'GET' && !$id) {
            requirePermission('gallery');
            $stmt = $db->query("SELECT s.*, p.program_name FROM success_stories s LEFT JOIN programs p ON s.program_id = p.id ORDER BY s.created_at DESC");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        // Get single story
        if ($method === 'GET' && $id) {
            requirePermission('gallery');
            $stmt = $db->prepare("SELECT s.*, p.program_name FROM success_stories s LEFT JOIN programs p ON s.program_id = p.id WHERE s.id = ?");
            $stmt->execute([$id]);
            $story = $stmt->fetch();
            if (!$story) jsonResponse(['detail' => 'Story not found'], 404);
            jsonResponse($story);
        }
        
        // Create story
        if ($method === 'POST') {
            $user = requirePermission('gallery');
            $data = getRequestBody();
            
            if (empty($data['title']) || empty($data['story'])) {
                jsonResponse(['detail' => 'Title and story content are required'], 400);
            }
            
            $stmt = $db->prepare("INSERT INTO success_stories (title, beneficiary_name, age, story, program_id, featured_image, is_published) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['title'],
                $data['beneficiary_name'] ?? null,
                $data['age'] ?? null,
                $data['story'],
                $data['program_id'] ?? null,
                $data['featured_image'] ?? null,
                $data['is_published'] ?? 1
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'stories', 'create', "Created success story: {$data['title']}");
            
            $stmt = $db->prepare("SELECT * FROM success_stories WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // Update story
        if ($method === 'PUT' && $id) {
            $user = requirePermission('gallery');
            $data = getRequestBody();
            
            $stmt = $db->prepare("UPDATE success_stories SET 
                title = COALESCE(?, title),
                beneficiary_name = COALESCE(?, beneficiary_name),
                age = COALESCE(?, age),
                story = COALESCE(?, story),
                program_id = COALESCE(?, program_id),
                featured_image = COALESCE(?, featured_image),
                is_published = COALESCE(?, is_published),
                updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([
                $data['title'] ?? null,
                $data['beneficiary_name'] ?? null,
                $data['age'] ?? null,
                $data['story'] ?? null,
                $data['program_id'] ?? null,
                $data['featured_image'] ?? null,
                $data['is_published'] ?? null,
                $id
            ]);
            
            logActivity($user['id'], 'stories', 'update', "Updated success story ID: $id");
            
            $stmt = $db->prepare("SELECT * FROM success_stories WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        // Delete story
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('gallery');
            $db->prepare("DELETE FROM success_stories WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'stories', 'delete', "Deleted success story ID: $id");
            jsonResponse(['message' => 'Success story deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== CMS (Media Upload & Management) ========================
    if ($resource === 'cms') {
        $user = requirePermission('gallery');
        
        // Upload media
        if ($id === 'upload' && $method === 'POST') {
            if (empty($_FILES['file'])) {
                jsonResponse(['detail' => 'No file uploaded'], 400);
            }
            
            $file = $_FILES['file'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                jsonResponse(['detail' => 'Invalid file type. Only images are allowed.'], 400);
            }
            
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxSize) {
                jsonResponse(['detail' => 'File too large. Maximum 5MB allowed.'], 400);
            }
            
            $uploadDir = __DIR__ . '/uploads/cms/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'cms_' . time() . '_' . uniqid() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Save to database
                $url = '/uploads/cms/' . $filename;
                $stmt = $db->prepare("INSERT INTO cms_media (filename, filepath, url, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $file['name'],
                    $filepath,
                    $url,
                    $file['type'],
                    $file['size'],
                    $user['id']
                ]);
                
                $mediaId = $db->lastInsertId();
                logActivity($user['id'], 'cms', 'upload', "Uploaded media: {$file['name']}");
                
                jsonResponse([
                    'id' => $mediaId,
                    'url' => $url,
                    'filename' => $file['name'],
                    'message' => 'File uploaded successfully'
                ], 201);
            } else {
                jsonResponse(['detail' => 'Failed to upload file'], 500);
            }
        }
        
        // List media
        if ($id === 'media' && $method === 'GET') {
            $stmt = $db->query("SELECT id, filename, url, file_type, file_size, created_at FROM cms_media ORDER BY created_at DESC");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        // Delete media
        if ($id && $method === 'DELETE' && $id !== 'upload' && $id !== 'media') {
            $stmt = $db->prepare("SELECT * FROM cms_media WHERE id = ?");
            $stmt->execute([$id]);
            $media = $stmt->fetch();
            
            if ($media) {
                // Delete file
                if (file_exists($media['filepath'])) {
                    unlink($media['filepath']);
                }
                // Delete from database
                $db->prepare("DELETE FROM cms_media WHERE id = ?")->execute([$id]);
                logActivity($user['id'], 'cms', 'delete', "Deleted media: {$media['filename']}");
                jsonResponse(['message' => 'Media deleted']);
            } else {
                jsonResponse(['detail' => 'Media not found'], 404);
            }
        }
        
        jsonResponse(['detail' => 'Invalid CMS endpoint'], 404);
    }
    
    // ======================== SEARCH ========================
    if ($resource === 'search') {
        $user = getCurrentUser();
        
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) jsonResponse(['detail' => 'Query too short'], 400);
        
        $results = [];
        $search = "%$q%";
        
        // Donations
        $stmt = $db->prepare("SELECT d.id, d.donation_code as title, CONCAT(dn.name, ' - ₹', FORMAT(d.amount, 2)) as subtitle 
                              FROM donations d LEFT JOIN donors dn ON d.donor_id = dn.id 
                              WHERE d.donation_code LIKE ? LIMIT 5");
        $stmt->execute([$search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'donation', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'], 'link' => '/donations', 'icon' => 'heart'];
        }
        
        // Donors
        $stmt = $db->prepare("SELECT id, name as title, COALESCE(email, phone, donor_type) as subtitle FROM donors WHERE name LIKE ? OR email LIKE ? LIMIT 5");
        $stmt->execute([$search, $search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'donor', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'], 'link' => '/donors', 'icon' => 'users'];
        }
        
        // Programs
        $stmt = $db->prepare("SELECT id, program_name as title, status as subtitle FROM programs WHERE program_name LIKE ? LIMIT 5");
        $stmt->execute([$search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'program', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => ucfirst($row['subtitle']), 'link' => '/programs', 'icon' => 'folder'];
        }
        
        // Employees
        $stmt = $db->prepare("SELECT id, name as title, COALESCE(designation, email) as subtitle FROM employees WHERE name LIKE ? LIMIT 5");
        $stmt->execute([$search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'employee', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'] ?? 'Employee', 'link' => '/employees', 'icon' => 'user'];
        }
        
        // Volunteers
        $stmt = $db->prepare("SELECT id, name as title, email as subtitle FROM volunteers WHERE name LIKE ? LIMIT 5");
        $stmt->execute([$search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'volunteer', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'] ?? 'Volunteer', 'link' => '/volunteers', 'icon' => 'hand-heart'];
        }
        
        // Inventory
        $stmt = $db->prepare("SELECT id, item_name as title, CONCAT('Qty: ', quantity, ' ', COALESCE(unit, '')) as subtitle FROM inventory_items WHERE item_name LIKE ? LIMIT 5");
        $stmt->execute([$search]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = ['type' => 'inventory', 'id' => $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'], 'link' => '/inventory', 'icon' => 'package'];
        }
        
        jsonResponse($results);
    }
    
    // ======================== SEED DATA ========================
    if ($resource === 'seed-data') {
        $user = requirePermission('settings');
        
        // Only super_admin can seed data
        if ($user['role'] !== 'super_admin') {
            jsonResponse(['detail' => 'Only Super Admin can load sample data'], 403);
        }
        
        if ($method !== 'POST') {
            jsonResponse(['detail' => 'Use POST method'], 405);
        }
        
        $created = [];
        
        try {
            // Seed Programs
            $programs = [
                ['Education for All', 'Free education for underprivileged children', 500000, 'active'],
                ['Healthcare Initiative', 'Medical camps and health awareness', 300000, 'active'],
                ['Women Empowerment', 'Skill development for women', 200000, 'active'],
                ['Rural Development', 'Infrastructure and support in rural areas', 400000, 'active'],
                ['Environmental Conservation', 'Tree plantation and awareness drives', 150000, 'active'],
            ];
            
            $stmt = $db->prepare("INSERT IGNORE INTO programs (program_name, description, budget, status) VALUES (?, ?, ?, ?)");
            foreach ($programs as $p) {
                $stmt->execute($p);
                if ($db->lastInsertId()) $created['programs'] = ($created['programs'] ?? 0) + 1;
            }
            
            // Seed Donors
            $donors = [
                ['Rajesh Kumar', 'individual', 'rajesh@email.com', '9876543210', 'Mumbai', 'Maharashtra'],
                ['Priya Sharma', 'individual', 'priya@email.com', '9876543211', 'Delhi', 'Delhi'],
                ['ABC Corporation', 'corporate', 'contact@abc.com', '9876543212', 'Bangalore', 'Karnataka'],
                ['XYZ Trust', 'trust', 'info@xyz.org', '9876543213', 'Chennai', 'Tamil Nadu'],
                ['Amit Patel', 'individual', 'amit@email.com', '9876543214', 'Ahmedabad', 'Gujarat'],
                ['Global Foundation', 'trust', 'donate@global.org', '9876543215', 'Pune', 'Maharashtra'],
                ['Tech Solutions Ltd', 'corporate', 'csr@techsol.com', '9876543216', 'Hyderabad', 'Telangana'],
                ['Neha Gupta', 'individual', 'neha@email.com', '9876543217', 'Kolkata', 'West Bengal'],
            ];
            
            $stmt = $db->prepare("INSERT IGNORE INTO donors (name, donor_type, email, phone, city, state) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($donors as $d) {
                $stmt->execute($d);
                if ($db->lastInsertId()) $created['donors'] = ($created['donors'] ?? 0) + 1;
            }
            
            // Get donor IDs for donations
            $donorIds = $db->query("SELECT id FROM donors ORDER BY id LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
            $programIds = $db->query("SELECT id FROM programs ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
            
            // Seed Donations
            if (!empty($donorIds) && !empty($programIds)) {
                $donations = [];
                $paymentMethods = ['cash', 'upi', 'bank_transfer', 'cheque', 'card'];
                
                for ($i = 0; $i < 20; $i++) {
                    $donorId = $donorIds[array_rand($donorIds)];
                    $programId = $programIds[array_rand($programIds)];
                    $amount = rand(1, 50) * 1000;
                    $method = $paymentMethods[array_rand($paymentMethods)];
                    $date = date('Y-m-d', strtotime("-" . rand(0, 180) . " days"));
                    $code = 'DON-' . date('Ymd', strtotime($date)) . '-' . rand(1000, 9999);
                    
                    $stmt = $db->prepare("INSERT INTO donations (donation_code, donor_id, program_id, amount, payment_method, donation_date, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
                    $stmt->execute([$code, $donorId, $programId, $amount, $method, $date]);
                    $created['donations'] = ($created['donations'] ?? 0) + 1;
                }
            }
            
            // Seed Employees
            $employees = [
                ['Vikram Singh', 'vikram@dhrub.org', '9876543220', 'Program Manager', 'Operations', '2023-01-15', 45000, 5000, 2000],
                ['Meera Joshi', 'meera@dhrub.org', '9876543221', 'Accountant', 'Finance', '2023-03-01', 35000, 3000, 1500],
                ['Rahul Verma', 'rahul@dhrub.org', '9876543222', 'Field Coordinator', 'Operations', '2023-06-10', 30000, 2500, 1000],
                ['Anjali Nair', 'anjali@dhrub.org', '9876543223', 'HR Executive', 'Human Resources', '2023-02-20', 32000, 3000, 1200],
                ['Suresh Reddy', 'suresh@dhrub.org', '9876543224', 'IT Support', 'IT', '2023-07-01', 28000, 2000, 1000],
            ];
            
            $stmt = $db->prepare("INSERT IGNORE INTO employees (name, email, phone, designation, department, joining_date, basic_salary, hra, other_allowances, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            foreach ($employees as $e) {
                $stmt->execute($e);
                if ($db->lastInsertId()) $created['employees'] = ($created['employees'] ?? 0) + 1;
            }
            
            // Seed Volunteers
            $volunteers = [
                ['Kiran Mehta', 'kiran@email.com', '9876543230', 'Available on weekends', 'active'],
                ['Pooja Shah', 'pooja@email.com', '9876543231', 'Available full-time', 'active'],
                ['Arjun Rao', 'arjun@email.com', '9876543232', 'Available evenings', 'active'],
                ['Sneha Kapoor', 'sneha@email.com', '9876543233', 'Part-time availability', 'active'],
                ['Rohan Das', 'rohan@email.com', '9876543234', 'Weekend volunteer', 'active'],
            ];
            
            $stmt = $db->prepare("INSERT IGNORE INTO volunteers (name, email, phone, availability, status) VALUES (?, ?, ?, ?, ?)");
            foreach ($volunteers as $v) {
                $stmt->execute($v);
                if ($db->lastInsertId()) $created['volunteers'] = ($created['volunteers'] ?? 0) + 1;
            }
            
            // Seed Inventory Items
            $inventory = [
                ['Rice Bags (25kg)', 'food', 150, 'bags', 'Godown A'],
                ['School Uniforms', 'clothing', 200, 'pieces', 'Store B'],
                ['First Aid Kits', 'medical', 50, 'kits', 'Medical Store'],
                ['Notebooks', 'educational', 500, 'pieces', 'Education Store'],
                ['Blankets', 'household', 100, 'pieces', 'Godown A'],
                ['Water Filters', 'equipment', 30, 'units', 'Store C'],
                ['Wheat Flour (10kg)', 'food', 200, 'bags', 'Godown A'],
                ['Medicines - General', 'medical', 100, 'boxes', 'Medical Store'],
            ];
            
            $stmt = $db->prepare("INSERT IGNORE INTO inventory_items (item_name, category, quantity, unit, location) VALUES (?, ?, ?, ?, ?)");
            foreach ($inventory as $item) {
                $stmt->execute($item);
                if ($db->lastInsertId()) $created['inventory'] = ($created['inventory'] ?? 0) + 1;
            }
            
            // Seed Expenses
            $expenses = [
                ['Office Rent', 'Rent', 25000, '2024-01-05'],
                ['Electricity Bill', 'Utilities', 5000, '2024-01-10'],
                ['Staff Salaries', 'Salary', 150000, '2024-01-31'],
                ['Medical Supplies', 'Program', 30000, '2024-01-15'],
                ['Educational Materials', 'Program', 20000, '2024-01-20'],
                ['Transportation', 'Operations', 15000, '2024-01-25'],
                ['Office Supplies', 'Administrative', 5000, '2024-02-01'],
                ['Event Organization', 'Program', 40000, '2024-02-10'],
            ];
            
            $stmt = $db->prepare("INSERT INTO expenses (description, expense_category, amount, expense_date) VALUES (?, ?, ?, ?)");
            foreach ($expenses as $e) {
                $stmt->execute($e);
                $created['expenses'] = ($created['expenses'] ?? 0) + 1;
            }
            
            logActivity($user['id'], 'settings', 'seed_data', 'Loaded sample data: ' . json_encode($created));
            
            jsonResponse([
                'message' => 'Sample data loaded successfully',
                'created' => $created
            ]);
            
        } catch (Exception $e) {
            jsonResponse(['detail' => 'Failed to seed data: ' . $e->getMessage()], 500);
        }
    }
    
    // ======================== SETTINGS (Organization) ========================
    // NOTE: Only one settings block - duplicate removed
    if ($resource === 'settings') {
        if ($method === 'GET') {
            getCurrentUser(); // Require authentication
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            jsonResponse($settings);
        }
        
        if ($method === 'PUT') {
            $user = getCurrentUser();
            if (!in_array($user['role'], ['super_admin', 'admin'])) {
                jsonResponse(['detail' => 'Only Admin or Super Admin can update settings'], 403);
            }
            $data    = getRequestBody();
            $updated = [];
            $allowedKeys = [
                'org_name', 'org_address', 'org_phone', 'org_email',
                'org_pan', 'org_80g_number', 'org_bank_name', 'org_bank_account',
                'org_bank_ifsc', 'org_bank_branch', 'currency_symbol', 'fiscal_year_start'
            ];
            foreach ($data as $key => $value) {
                if (in_array($key, $allowedKeys)) {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                    $updated[] = $key;
                }
            }
            logActivity($user['id'], 'settings', 'update', 'Updated: ' . implode(', ', $updated));
            jsonResponse(['message' => 'Settings updated successfully', 'updated' => $updated]);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== INVOICES ========================
    if ($resource === 'invoices') {
        // GET /api/invoices - List all invoices
        if ($method === 'GET' && !$id) {
            requirePermission('invoices');
            $type = $_GET['type'] ?? null;
            
            $sql = "SELECT i.*, u.full_name as created_by_name FROM invoices i 
                    LEFT JOIN users u ON i.created_by = u.id";
            if ($type) {
                $stmt = $db->prepare($sql . " WHERE i.invoice_type = ? ORDER BY i.created_at DESC");
                $stmt->execute([$type]);
            } else {
                $stmt = $db->query($sql . " ORDER BY i.created_at DESC");
            }
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        // GET /api/invoices/{id} - Get single invoice
        if ($method === 'GET' && $id && !$action) {
            requirePermission('invoices');
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch();
            if (!$invoice) jsonResponse(['detail' => 'Invoice not found'], 404);
            jsonResponse($invoice);
        }
        
        // GET /api/invoices/{id}/view - View/Print invoice HTML
        if ($method === 'GET' && $id && $action === 'view') {
            requirePermission('invoices');
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch();
            if (!$invoice) jsonResponse(['detail' => 'Invoice not found'], 404);
            
            header('Content-Type: text/html');
            echo generateInvoice($invoice, $db);
            exit;
        }
        
        // POST /api/invoices - Create new invoice
        if ($method === 'POST' && !$id) {
            $user = requirePermission('invoices');
            $data = getRequestBody();
            
            // Generate invoice number
            $year = date('Y');
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM invoices WHERE YEAR(created_at) = $year");
            $count = $stmt->fetch()['cnt'] + 1;
            $invoiceNumber = 'INV-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO invoices (invoice_number, invoice_type, reference_id, donor_id, donor_name, donor_email, donor_address, donor_pan, amount, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $invoiceNumber,
                $data['invoice_type'] ?? 'donation',
                $data['reference_id'] ?? null,
                $data['donor_id'] ?? null,
                $data['donor_name'] ?? '',
                $data['donor_email'] ?? null,
                $data['donor_address'] ?? null,
                $data['donor_pan'] ?? null,
                $data['amount'] ?? 0,
                $data['description'] ?? null,
                $data['status'] ?? 'draft',
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'invoices', 'create', "Invoice created: $invoiceNumber");
            
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // POST /api/invoices/generate-from-donation/{donation_id} - Generate invoice from donation
        if ($method === 'POST' && $id === 'generate-from-donation' && $action) {
            $user = requirePermission('invoices');
            $donationId = $action;
            
            $stmt = $db->prepare("SELECT d.*, dn.name as donor_name, dn.email as donor_email, dn.pan as donor_pan, 
                                  dn.address as donor_address, p.program_name 
                                  FROM donations d 
                                  LEFT JOIN donors dn ON d.donor_id = dn.id 
                                  LEFT JOIN programs p ON d.program_id = p.id 
                                  WHERE d.id = ?");
            $stmt->execute([$donationId]);
            $donation = $stmt->fetch();
            
            if (!$donation) jsonResponse(['detail' => 'Donation not found'], 404);
            
            // Generate invoice number
            $year = date('Y');
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM invoices WHERE YEAR(created_at) = $year");
            $count = $stmt->fetch()['cnt'] + 1;
            $invoiceNumber = 'INV-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            
            $description = "Donation Receipt - " . ($donation['program_name'] ?? 'General Fund');
            if ($donation['donation_code']) {
                $description .= " (Ref: {$donation['donation_code']})";
            }
            
            $stmt = $db->prepare("INSERT INTO invoices (invoice_number, invoice_type, reference_id, donor_id, donor_name, donor_email, donor_address, donor_pan, amount, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $invoiceNumber,
                'donation',
                $donationId,
                $donation['donor_id'],
                $donation['donor_name'],
                $donation['donor_email'],
                $donation['donor_address'],
                $donation['donor_pan'],
                $donation['amount'],
                $description,
                'sent',
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'invoices', 'create', "Invoice generated from donation: $invoiceNumber");
            
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // DELETE /api/invoices/{id}
        if ($method === 'DELETE' && $id) {
            $user = requirePermission('invoices');
            
            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch();
            if (!$invoice) jsonResponse(['detail' => 'Invoice not found'], 404);
            
            $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'invoices', 'delete', "Deleted invoice: {$invoice['invoice_number']}");
            
            jsonResponse(['message' => 'Invoice deleted']);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // ======================== MEMBERS (Organization Leadership) ========================
    if ($resource === 'members') {
        // GET /api/members - List all members
        if ($method === 'GET' && !$id) {
            requirePermission('members'); // Require members permission
            $stmt = $db->query("SELECT * FROM members ORDER BY display_order, id");
            jsonResponse(['items' => $stmt->fetchAll()]);
        }
        
        // GET /api/members/{id} - Get single member
        if ($method === 'GET' && $id) {
            getCurrentUser();
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch();
            if (!$member) jsonResponse(['detail' => 'Member not found'], 404);
            jsonResponse($member);
        }
        
        // POST /api/members - Create new member (Super Admin only)
        if ($method === 'POST' && !$id) {
            $user = getCurrentUser();
            if ($user['role'] !== 'super_admin') {
                jsonResponse(['detail' => 'Only Super Admin can manage members'], 403);
            }
            
            $data = getRequestBody();
            
            if (empty($data['name']) || empty($data['designation'])) {
                jsonResponse(['detail' => 'Name and designation are required'], 400);
            }
            
            $stmt = $db->prepare("INSERT INTO members (name, designation, role_type, email, phone, bio, photo_url, linkedin_url, display_order, is_active, joined_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['designation'],
                $data['role_type'] ?? 'other',
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['bio'] ?? null,
                $data['photo_url'] ?? null,
                $data['linkedin_url'] ?? null,
                $data['display_order'] ?? 0,
                isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
                $data['joined_date'] ?? null,
                $user['id']
            ]);
            
            $newId = $db->lastInsertId();
            logActivity($user['id'], 'members', 'create', "Created member: {$data['name']}");
            
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        }
        
        // PUT /api/members/{id} - Update member (Super Admin only)
        if ($method === 'PUT' && $id) {
            $user = getCurrentUser();
            if ($user['role'] !== 'super_admin') {
                jsonResponse(['detail' => 'Only Super Admin can manage members'], 403);
            }
            
            $stmt = $db->prepare("SELECT id FROM members WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) jsonResponse(['detail' => 'Member not found'], 404);
            
            $data = getRequestBody();
            
            $updates = [];
            $params = [];
            
            $allowedFields = ['name', 'designation', 'role_type', 'email', 'phone', 'bio', 'photo_url', 'linkedin_url', 'display_order', 'is_active', 'joined_date'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $field === 'is_active' ? ($data[$field] ? 1 : 0) : $data[$field];
                }
            }
            
            if (empty($updates)) {
                jsonResponse(['detail' => 'No valid fields to update'], 400);
            }
            
            $params[] = $id;
            $sql = "UPDATE members SET " . implode(", ", $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
            
            logActivity($user['id'], 'members', 'update', "Updated member ID: $id");
            
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        }
        
        // DELETE /api/members/{id} - Delete member (Super Admin only)
        if ($method === 'DELETE' && $id) {
            $user = getCurrentUser();
            if ($user['role'] !== 'super_admin') {
                jsonResponse(['detail' => 'Only Super Admin can manage members'], 403);
            }
            
            $stmt = $db->prepare("SELECT name FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch();
            if (!$member) jsonResponse(['detail' => 'Member not found'], 404);
            
            $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
            logActivity($user['id'], 'members', 'delete', "Deleted member: {$member['name']}");
            
            jsonResponse(['message' => 'Member deleted successfully']);
        }
        
        // POST /api/members/upload-photo - Upload member photo
        if ($id === 'upload-photo' && $method === 'POST') {
            $user = getCurrentUser();
            if ($user['role'] !== 'super_admin') {
                jsonResponse(['detail' => 'Only Super Admin can upload photos'], 403);
            }
            
            if (!isset($_FILES['file'])) {
                jsonResponse(['detail' => 'No file uploaded'], 400);
            }
            
            $file = $_FILES['file'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                jsonResponse(['detail' => 'Invalid file type'], 400);
            }
            
            $maxSize = 5 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                jsonResponse(['detail' => 'File too large. Max 5MB'], 400);
            }
            
            $uploadDir = __DIR__ . '/uploads/members/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'member_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                jsonResponse(['photo_url' => 'uploads/members/' . $filename]);
            }
            
            jsonResponse(['detail' => 'Upload failed'], 500);
        }
        
        jsonResponse(['detail' => 'Method not allowed'], 405);
    }
    
    // 404 for unknown API endpoints
    jsonResponse(['detail' => 'Endpoint not found', 'path' => $apiPath], 404);
}

// Serve CMS page
if ($path === '/cms' || $path === '/cms.html' || strpos($path, '/cms/') === 0) {
    // Serve CMS index
    if ($path === '/cms' || $path === '/cms/' || $path === '/cms/index.html') {
        $cmsFile = __DIR__ . '/cms/index.html';
        if (file_exists($cmsFile)) {
            header('Content-Type: text/html');
            readfile($cmsFile);
            exit;
        }
    }
    
    // Serve CMS static files (CSS, JS)
    $cmsPath = __DIR__ . $path;
    if (file_exists($cmsPath)) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml'
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header("Content-Type: $mime");
        readfile($cmsPath);
        exit;
    }
}

// Serve React App for non-API requests
$htmlFile = __DIR__ . '/app.html';
if (file_exists($htmlFile)) {
    header('Content-Type: text/html');
    readfile($htmlFile);
} else {
    // Fallback
    echo "<!DOCTYPE html><html><head><title>Dhrub Foundation ERP</title></head><body><h1>ERP System</h1><p>Frontend not found. Please upload the React build files.</p></body></html>";
}

// ======================== HELPER FUNCTIONS ========================
function generateReceipt($donation) {
    $amount = number_format($donation['amount'], 2);
    $date = date('d M Y', strtotime($donation['donation_date']));
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Receipt - {$donation['donation_code']}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; max-width: 800px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #16a34a; font-size: 28px; }
        .header p { margin: 5px 0; color: #666; }
        h2 { text-align: center; color: #333; }
        .info { margin: 20px 0; }
        .row { display: flex; padding: 10px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; width: 200px; color: #333; }
        .value { flex: 1; color: #666; }
        .amount { font-size: 32px; color: #16a34a; font-weight: bold; text-align: center; margin: 30px 0; padding: 20px; background: #f0fdf4; border-radius: 10px; }
        .tax-note { background: #fef3c7; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f59e0b; }
        .footer { margin-top: 50px; text-align: center; color: #999; font-size: 12px; }
        @media print { body { margin: 20px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Dhrub Foundation</h1>
        <p>Registered NGO</p>
        <p>PAN: AAAAA1234A | 80G Registration: 80G/2024/12345</p>
    </div>
    
    <h2>DONATION RECEIPT</h2>
    
    <div class="info">
        <div class="row"><span class="label">Receipt Number:</span><span class="value">{$donation['donation_code']}</span></div>
        <div class="row"><span class="label">Date:</span><span class="value">{$date}</span></div>
        <div class="row"><span class="label">Donor Name:</span><span class="value">{$donation['donor_name']}</span></div>
        <div class="row"><span class="label">Donor PAN:</span><span class="value">{$donation['donor_pan']}</span></div>
        <div class="row"><span class="label">Address:</span><span class="value">{$donation['donor_address']}</span></div>
        <div class="row"><span class="label">Payment Method:</span><span class="value">{$donation['payment_method']}</span></div>
        <div class="row"><span class="label">Program:</span><span class="value">{$donation['program_name']}</span></div>
    </div>
    
    <div class="amount">₹ {$amount}</div>
    
    <div class="tax-note">
        <strong>Tax Exemption:</strong> This donation is eligible for 50% tax exemption under Section 80G of the Income Tax Act, 1961.
    </div>
    
    <div class="footer">
        <p>Thank you for your generous contribution!</p>
        <p>This is a computer-generated receipt and does not require a signature.</p>
        <p class="no-print"><a href="javascript:window.print()">Print Receipt</a></p>
    </div>
</body>
</html>
HTML;
}

function generateInvoice($invoice, $db) {
    // Get organization settings
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $orgName = $settings['org_name'] ?? 'Dhrub Foundation';
    $orgAddress = $settings['org_address'] ?? '';
    $orgPhone = $settings['org_phone'] ?? '';
    $orgEmail = $settings['org_email'] ?? '';
    $orgPan = $settings['org_pan'] ?? '';
    $org80g = $settings['org_80g_number'] ?? '';
    $bankName = $settings['org_bank_name'] ?? '';
    $bankAccount = $settings['org_bank_account'] ?? '';
    $bankIfsc = $settings['org_bank_ifsc'] ?? '';
    $bankBranch = $settings['org_bank_branch'] ?? '';
    $currency = $settings['currency_symbol'] ?? '₹';
    
    $amount = number_format($invoice['amount'], 2);
    $date = date('d M Y', strtotime($invoice['created_at']));
    $invoiceType = ucfirst($invoice['invoice_type']);
    $is80gEligible = $invoice['invoice_type'] === 'donation';
    
    // Logo path (relative to index.php)
    $logoPath = '/logo.png';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Invoice - {$invoice['invoice_number']}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; margin: 0; padding: 30px; max-width: 800px; margin: 0 auto; background: #fff; color: #333; line-height: 1.6; }
        
        .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 25px; border-bottom: 3px solid #16a34a; margin-bottom: 30px; }
        .logo-section { display: flex; align-items: center; gap: 15px; }
        .logo-section img { max-width: 80px; max-height: 80px; object-fit: contain; }
        .org-info h1 { color: #16a34a; font-size: 26px; font-weight: 700; margin-bottom: 5px; }
        .org-info p { color: #666; font-size: 12px; }
        
        .invoice-title { text-align: right; }
        .invoice-title h2 { font-size: 28px; color: #333; font-weight: 700; margin-bottom: 5px; }
        .invoice-title .invoice-number { font-size: 14px; color: #16a34a; font-weight: 600; }
        .invoice-title .invoice-date { font-size: 12px; color: #666; }
        .invoice-title .invoice-type { display: inline-block; background: #16a34a; color: white; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-top: 5px; text-transform: uppercase; }
        
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }
        .party-box { background: #f8fafc; padding: 20px; border-radius: 10px; border-left: 4px solid #16a34a; }
        .party-box h3 { color: #16a34a; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .party-box .name { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .party-box p { font-size: 13px; color: #666; margin: 3px 0; }
        
        .amount-section { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: white; padding: 30px; border-radius: 12px; text-align: center; margin-bottom: 30px; }
        .amount-section .label { font-size: 12px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.9; margin-bottom: 5px; }
        .amount-section .amount { font-size: 42px; font-weight: 700; }
        .amount-section .description { margin-top: 15px; font-size: 14px; opacity: 0.9; max-width: 80%; margin-left: auto; margin-right: auto; }
        
        .tax-notice { background: #fef3c7; border: 1px solid #fcd34d; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: flex-start; gap: 12px; }
        .tax-notice svg { flex-shrink: 0; color: #d97706; }
        .tax-notice-content { flex: 1; }
        .tax-notice-content strong { color: #92400e; font-size: 14px; }
        .tax-notice-content p { color: #92400e; font-size: 13px; margin-top: 5px; }
        
        .bank-details { background: #f0f9ff; border: 1px solid #bae6fd; padding: 20px; border-radius: 10px; margin-bottom: 25px; }
        .bank-details h3 { color: #0369a1; font-size: 14px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .bank-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .bank-item { font-size: 13px; }
        .bank-item .label { color: #666; }
        .bank-item .value { color: #333; font-weight: 600; }
        
        .footer { text-align: center; padding-top: 25px; border-top: 1px solid #e5e7eb; }
        .footer p { font-size: 12px; color: #999; margin: 5px 0; }
        .footer .thank-you { font-size: 16px; color: #16a34a; font-weight: 600; margin-bottom: 10px; }
        
        .print-btn { display: inline-block; background: #16a34a; color: white; padding: 10px 25px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 15px; cursor: pointer; border: none; font-size: 14px; }
        .print-btn:hover { background: #15803d; }
        
        @media print {
            body { padding: 15px; }
            .no-print { display: none !important; }
            .invoice-header { page-break-after: avoid; }
            .amount-section { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .tax-notice { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bank-details { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="invoice-header">
        <div class="logo-section">
            <img src="{$logoPath}" alt="{$orgName} Logo" onerror="this.style.display='none'">
            <div class="org-info">
                <h1>{$orgName}</h1>
                <p>{$orgAddress}</p>
                <p>Phone: {$orgPhone} | Email: {$orgEmail}</p>
                <p><strong>PAN:</strong> {$orgPan} | <strong>80G:</strong> {$org80g}</p>
            </div>
        </div>
        <div class="invoice-title">
            <h2>INVOICE</h2>
            <div class="invoice-number">{$invoice['invoice_number']}</div>
            <div class="invoice-date">Date: {$date}</div>
            <div class="invoice-type">{$invoiceType}</div>
        </div>
    </div>
    
    <div class="parties">
        <div class="party-box">
            <h3>Bill To</h3>
            <div class="name">{$invoice['donor_name']}</div>
            <p>{$invoice['donor_address']}</p>
            <p>Email: {$invoice['donor_email']}</p>
            <p>PAN: {$invoice['donor_pan']}</p>
        </div>
        <div class="party-box">
            <h3>From</h3>
            <div class="name">{$orgName}</div>
            <p>{$orgAddress}</p>
            <p>Email: {$orgEmail}</p>
            <p>PAN: {$orgPan}</p>
        </div>
    </div>
    
    <div class="amount-section">
        <div class="label">Total Amount</div>
        <div class="amount">{$currency} {$amount}</div>
        <div class="description">{$invoice['description']}</div>
    </div>
HTML;

    // Add 80G notice only for donations
    $html = '';
    if ($is80gEligible) {
        $html .= <<<HTML
    <div class="tax-notice">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
        <div class="tax-notice-content">
            <strong>Tax Exemption Certificate (80G)</strong>
            <p>This donation is eligible for 50% tax exemption under Section 80G of the Income Tax Act, 1961. Registration No: {$org80g}</p>
        </div>
    </div>
HTML;
    }
    
    // Bank details
    $html .= <<<HTML
    <div class="bank-details">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                <line x1="1" y1="10" x2="23" y2="10"></line>
            </svg>
            Bank Details for Future Contributions
        </h3>
        <div class="bank-grid">
            <div class="bank-item"><span class="label">Bank Name:</span> <span class="value">{$bankName}</span></div>
            <div class="bank-item"><span class="label">Account No:</span> <span class="value">{$bankAccount}</span></div>
            <div class="bank-item"><span class="label">IFSC Code:</span> <span class="value">{$bankIfsc}</span></div>
            <div class="bank-item"><span class="label">Branch:</span> <span class="value">{$bankBranch}</span></div>
        </div>
    </div>
    
    <div class="footer">
        <p class="thank-you">Thank you for your generous support!</p>
        <p>This is a computer-generated invoice and does not require a signature.</p>
        <p>For any queries, please contact us at {$orgEmail}</p>
        <button class="print-btn no-print" onclick="window.print()">Download / Print Invoice</button>
    </div>
</body>
</html>
HTML;
    
    return $html;
}

function generatePayslip($payroll) {
    $grossSalary = number_format($payroll['gross_salary'] ?? 0, 2);
    $netSalary = number_format($payroll['salary_paid'] ?? 0, 2);
    $totalDeductions = number_format($payroll['total_deductions'] ?? 0, 2);
    $paymentDate = date('d M Y', strtotime($payroll['payment_date']));
    
    $basicSalary = number_format($payroll['basic_salary'] ?? 0, 2);
    $hra = number_format($payroll['hra'] ?? 0, 2);
    $da = number_format($payroll['da'] ?? 0, 2);
    $travelAllowance = number_format($payroll['travel_allowance'] ?? 0, 2);
    $medicalAllowance = number_format($payroll['medical_allowance'] ?? 0, 2);
    $specialAllowance = number_format($payroll['special_allowance'] ?? 0, 2);
    $otherAllowances = number_format($payroll['other_allowances'] ?? 0, 2);
    $overtime = number_format($payroll['overtime'] ?? 0, 2);
    $bonus = number_format($payroll['bonus'] ?? 0, 2);
    
    $pfDeduction = number_format($payroll['pf_deduction'] ?? 0, 2);
    $esiDeduction = number_format($payroll['esi_deduction'] ?? 0, 2);
    $tdsDeduction = number_format($payroll['tds_deduction'] ?? 0, 2);
    $professionalTax = number_format($payroll['professional_tax'] ?? 0, 2);
    $loanDeduction = number_format($payroll['loan_deduction'] ?? 0, 2);
    $otherDeductions = number_format($payroll['other_deductions'] ?? 0, 2);
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Payslip - {$payroll['month']} {$payroll['year']}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 800px; font-size: 12px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #1e40af; font-size: 24px; }
        .header p { margin: 5px 0; color: #666; }
        h2 { text-align: center; color: #333; font-size: 16px; margin: 15px 0; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; padding: 10px; background: #f8fafc; border-radius: 8px; }
        .info-item { display: flex; }
        .info-label { font-weight: bold; width: 120px; color: #333; }
        .info-value { color: #666; }
        .salary-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .salary-table th { background: #1e40af; color: white; padding: 10px; text-align: left; }
        .salary-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; }
        .salary-table tr:hover { background: #f8fafc; }
        .earnings { width: 50%; }
        .deductions { width: 50%; }
        .tables-container { display: flex; gap: 20px; }
        .table-section { flex: 1; }
        .table-section h3 { color: #1e40af; margin-bottom: 10px; font-size: 14px; }
        .total-row { font-weight: bold; background: #f0f9ff !important; }
        .net-salary { font-size: 24px; color: #16a34a; font-weight: bold; text-align: center; margin: 20px 0; padding: 15px; background: #f0fdf4; border-radius: 10px; border: 2px solid #16a34a; }
        .footer { margin-top: 40px; text-align: center; color: #999; font-size: 11px; border-top: 1px solid #e5e7eb; padding-top: 15px; }
        .signature { margin-top: 50px; display: flex; justify-content: space-between; }
        .signature-box { text-align: center; width: 200px; }
        .signature-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 5px; }
        @media print { 
            body { margin: 10px; } 
            .no-print { display: none; }
            .salary-table th { background: #333 !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Dhrub Foundation</h1>
        <p>Registered NGO</p>
        <p>PAN: AAAAA1234A</p>
    </div>
    
    <h2>SALARY SLIP - {$payroll['month']} {$payroll['year']}</h2>
    
    <div class="info-grid">
        <div class="info-item"><span class="info-label">Employee Name:</span><span class="info-value">{$payroll['employee_name']}</span></div>
        <div class="info-item"><span class="info-label">Department:</span><span class="info-value">{$payroll['department']}</span></div>
        <div class="info-item"><span class="info-label">Designation:</span><span class="info-value">{$payroll['designation']}</span></div>
        <div class="info-item"><span class="info-label">Payment Date:</span><span class="info-value">{$paymentDate}</span></div>
        <div class="info-item"><span class="info-label">PAN Number:</span><span class="info-value">{$payroll['pan_number']}</span></div>
        <div class="info-item"><span class="info-label">Bank Account:</span><span class="info-value">{$payroll['bank_account']}</span></div>
        <div class="info-item"><span class="info-label">Bank Name:</span><span class="info-value">{$payroll['bank_name']}</span></div>
        <div class="info-item"><span class="info-label">Days Worked:</span><span class="info-value">{$payroll['days_worked']}</span></div>
    </div>
    
    <div class="tables-container">
        <div class="table-section">
            <h3>Earnings</h3>
            <table class="salary-table">
                <tr><td>Basic Salary</td><td style="text-align:right">₹ {$basicSalary}</td></tr>
                <tr><td>House Rent Allowance (HRA)</td><td style="text-align:right">₹ {$hra}</td></tr>
                <tr><td>Dearness Allowance (DA)</td><td style="text-align:right">₹ {$da}</td></tr>
                <tr><td>Travel Allowance</td><td style="text-align:right">₹ {$travelAllowance}</td></tr>
                <tr><td>Medical Allowance</td><td style="text-align:right">₹ {$medicalAllowance}</td></tr>
                <tr><td>Special Allowance</td><td style="text-align:right">₹ {$specialAllowance}</td></tr>
                <tr><td>Other Allowances</td><td style="text-align:right">₹ {$otherAllowances}</td></tr>
                <tr><td>Overtime</td><td style="text-align:right">₹ {$overtime}</td></tr>
                <tr><td>Bonus</td><td style="text-align:right">₹ {$bonus}</td></tr>
                <tr class="total-row"><td><strong>Gross Salary</strong></td><td style="text-align:right"><strong>₹ {$grossSalary}</strong></td></tr>
            </table>
        </div>
        
        <div class="table-section">
            <h3>Deductions</h3>
            <table class="salary-table">
                <tr><td>Provident Fund (PF)</td><td style="text-align:right">₹ {$pfDeduction}</td></tr>
                <tr><td>ESI</td><td style="text-align:right">₹ {$esiDeduction}</td></tr>
                <tr><td>TDS</td><td style="text-align:right">₹ {$tdsDeduction}</td></tr>
                <tr><td>Professional Tax</td><td style="text-align:right">₹ {$professionalTax}</td></tr>
                <tr><td>Loan Deduction</td><td style="text-align:right">₹ {$loanDeduction}</td></tr>
                <tr><td>Other Deductions</td><td style="text-align:right">₹ {$otherDeductions}</td></tr>
                <tr class="total-row"><td><strong>Total Deductions</strong></td><td style="text-align:right"><strong>₹ {$totalDeductions}</strong></td></tr>
            </table>
        </div>
    </div>
    
    <div class="net-salary">
        Net Salary: ₹ {$netSalary}
    </div>
    
    <div class="signature">
        <div class="signature-box">
            <div class="signature-line">Employee Signature</div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Authorized Signatory</div>
        </div>
    </div>
    
    <div class="footer">
        <p>This is a computer-generated payslip and does not require a signature.</p>
        <p class="no-print"><a href="javascript:window.print()">Print Payslip</a></p>
    </div>
</body>
</html>
HTML;
}
