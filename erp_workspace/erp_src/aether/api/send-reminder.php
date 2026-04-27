<?php
/**
 * Aether — send-reminder.php
 * Standalone endpoint to send Email / SMS / WhatsApp messages.
 * POST /dhrub_erp/aether/api/send-reminder
 */
header('Content-Type: application/json; charset=UTF-8');

// ── CORS ──────────────────────────────────────────────────────────────────────
// BUG-FIX: CORS origin should match your actual domain in production
$allowedOrigins = ['https://dhrubfoundation.org', 'https://erp.dhrubfoundation.org'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true) || empty($origin)) {
    // Same-origin or allowed cross-origin
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 3600');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// ── Auth (optional — remove if this endpoint is called internally only) ───────
$authFile = __DIR__ . '/../../includes/auth.php';
if (file_exists($authFile)) {
    require_once $authFile;
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
            exit;
        }
    }
}

// ── Parse body ────────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['type'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Missing required field 'type'. Valid values: email, sms, whatsapp"]);
    exit;
}

$type   = strtolower(trim($data['type']));
$result = '';

switch ($type) {

    case 'email': {
        $to      = filter_var(trim($data['to']      ?? ''), FILTER_VALIDATE_EMAIL);
        $subject = trim($data['subject'] ?? 'Dhrub Foundation Notification');
        $body    = trim($data['body']    ?? '');
        if (!$to)   { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid or missing email address.']); exit; }
        if (!$body) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Email body cannot be empty.']);       exit; }
        $result = sendEmail($to, $subject, $body);
        break;
    }

    case 'sms': {
        $phone   = preg_replace('/\D/', '', trim($data['phone']   ?? ''));
        $message = trim($data['message'] ?? '');
        if (strlen($phone) < 10) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid phone number — must be at least 10 digits.']); exit; }
        if (!$message)            { http_response_code(400); echo json_encode(['status'=>'error','message'=>'SMS message cannot be empty.']);                         exit; }
        $result = sendSMS($phone, $message);
        break;
    }

    case 'whatsapp': {
        $phone   = preg_replace('/\D/', '', trim($data['phone']   ?? ''));
        $message = trim($data['message'] ?? '');
        if (strlen($phone) < 10) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid phone number — must be at least 10 digits.']); exit; }
        if (!$message)            { http_response_code(400); echo json_encode(['status'=>'error','message'=>'WhatsApp message cannot be empty.']);                   exit; }
        $result = sendWhatsApp($phone, $message);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Invalid type '{$type}'. Use: email, sms, or whatsapp"]);
        exit;
    }
}

$status = str_starts_with($result, '✅') ? 'success' : 'error';
$code   = $status === 'success' ? 200 : 502;
http_response_code($code);
echo json_encode(['status' => $status, 'message' => $result], JSON_UNESCAPED_UNICODE);
