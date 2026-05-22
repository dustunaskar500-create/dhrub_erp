<?php
/**
 * ERP Module — Common helpers
 *
 * Reuses Aether's bootstrap (config + JWT + DB + helpers) so we share the
 * same auth surface as the rest of the application.
 *
 * Exposes:
 *   erp_db()                 PDO from Aether
 *   erp_current_user()       JWT-resolved user row (null if anon)
 *   erp_require_user()       401-exit if missing
 *   erp_require_role([..])   403-exit if role not allowed
 *   erp_json($payload,$code)
 *   erp_error($msg,$code)
 *   erp_body()
 *   erp_next_doc_no($type)   "INV-2025-26-000123" style
 *   erp_setting($key,$def)
 *   erp_upload_dir($sub)     /app/uploads/erp/<sub> ensured
 *   erp_setup_cors()
 *   erp_log_audit(...)
 *   erp_number_to_words(...)
 */

require_once dirname(__DIR__, 2) . '/api/bootstrap.php';

function erp_db(): PDO { return aether_db(); }

function erp_setup_cors(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }
}

function erp_json(array $payload, int $code = 200): void { aether_json($payload, $code); }
function erp_error(string $msg, int $code = 400): void { aether_error($msg, $code); }

function erp_body(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input');
    $cached = json_decode($raw ?: '[]', true) ?: [];
    return $cached;
}

function erp_current_user(): ?array { return aether_current_user(); }

function erp_require_user(): array {
    $u = erp_current_user();
    if (!$u) erp_error('Authentication required', 401);
    return $u;
}

function erp_require_role(array $allowed): array {
    $u = erp_require_user();
    if (!in_array($u['role'] ?? '', $allowed, true)) {
        erp_error('Forbidden — role "' . ($u['role'] ?? 'unknown') . '" cannot perform this action', 403);
    }
    return $u;
}

function erp_is_super_admin(?array $u): bool { return ($u['role'] ?? '') === 'super_admin'; }

function erp_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = erp_db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        $cache[$key] = $v !== false ? (string)$v : $default;
    } catch (\Throwable $e) { $cache[$key] = $default; }
    return $cache[$key];
}

/**
 * Compute fiscal year code given a Y-m-d. Indian FY is Apr-Mar.
 * 2025-04-01 -> "2025-26"
 * 2026-01-15 -> "2025-26"
 */
function erp_fy_code(?string $dateYmd = null): string {
    $ts = $dateYmd ? strtotime($dateYmd) : time();
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    if ($m < 4) { $startY = $y - 1; }
    else        { $startY = $y; }
    return $startY . '-' . str_pad(($startY + 1) % 100, 2, '0', STR_PAD_LEFT);
}

/**
 * Issue the next sequential document number for a given type, scoped per FY.
 * e.g. erp_next_doc_no('invoice') -> "INV/2025-26/0001"
 */
function erp_next_doc_no(string $type, ?string $dateYmd = null): string {
    static $prefixMap = [
        'invoice' => 'invoice_prefix',
        'grn'     => 'grn_prefix',
        'po'      => 'po_prefix',
        'adj'     => 'adj_prefix',
        'vendor'  => null, // uses literal "VND"
    ];
    $fy = erp_fy_code($dateYmd);
    $prefix = 'DOC';
    if ($type === 'vendor') { $prefix = 'VND'; }
    elseif (!empty($prefixMap[$type])) {
        $p = erp_setting($prefixMap[$type], '');
        $prefix = $p ?: strtoupper($type);
    } else {
        $prefix = strtoupper($type);
    }

    $db = erp_db();
    // Single-statement atomic counter — uses LAST_INSERT_ID() trick.
    // INSERT a new row with seq=1, or bump existing row by 1, returning the new value.
    $db->prepare("INSERT INTO erp_doc_counters (doc_type, fy, last_seq) VALUES (?, ?, LAST_INSERT_ID(1))
                  ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)")
       ->execute([$type, $fy]);
    $seq = (int)$db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    if ($seq <= 0) $seq = 1;
    return sprintf('%s/%s/%04d', $prefix, $fy, $seq);
}

function erp_upload_dir(string $sub): string {
    $base = '/app/uploads/erp';
    $sub = preg_replace('/[^A-Za-z0-9._\/-]/', '_', $sub);
    $dir = rtrim($base . '/' . trim($sub, '/'), '/');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function erp_public_url(string $absPath): string {
    // /app/uploads/erp/grn/.. -> /uploads/erp/grn/..
    if (strpos($absPath, '/app/uploads/') === 0) {
        return substr($absPath, 4); // strip "/app"
    }
    return $absPath;
}

function erp_save_upload(array $file, string $sub, array $allowedMime = []): array {
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Upload failed: ' . ($file['error'] ?? 'unknown'));
    }
    $mime = mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream');
    if (!empty($allowedMime)) {
        $ok = false;
        foreach ($allowedMime as $rule) {
            if ($rule === $mime) { $ok = true; break; }
            if (substr($rule, -2) === '/*' && strpos($mime, substr($rule, 0, -2)) === 0) { $ok = true; break; }
        }
        if (!$ok) throw new RuntimeException('File type not allowed: ' . $mime);
    }
    $dir = erp_upload_dir($sub);
    $orig = $file['name'] ?: 'upload';
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
    $abs  = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        throw new RuntimeException('Could not store file');
    }
    return [
        'abs_path'      => $abs,
        'url'           => erp_public_url($abs),
        'original_name' => $orig,
        'mime'          => $mime,
        'size'          => (int)($file['size'] ?? filesize($abs)),
    ];
}

function erp_save_base64(string $base64, string $filename, string $sub, array $allowedMime = []): array {
    // base64 may include data URI prefix
    if (preg_match('/^data:([^;]+);base64,(.*)$/', $base64, $m)) {
        $declared = $m[1];
        $bin = base64_decode($m[2]) ?: '';
    } else {
        $declared = '';
        $bin = base64_decode($base64) ?: '';
    }
    if (!$bin) throw new RuntimeException('Empty file payload');
    $dir = erp_upload_dir($sub);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename ?: 'upload.bin');
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
    $abs  = $dir . '/' . $name;
    file_put_contents($abs, $bin);
    $mime = mime_content_type($abs) ?: $declared ?: 'application/octet-stream';
    if (!empty($allowedMime)) {
        $ok = false;
        foreach ($allowedMime as $rule) {
            if ($rule === $mime) { $ok = true; break; }
            if (substr($rule, -2) === '/*' && strpos($mime, substr($rule, 0, -2)) === 0) { $ok = true; break; }
        }
        if (!$ok) { @unlink($abs); throw new RuntimeException('File type not allowed: ' . $mime); }
    }
    return [
        'abs_path'      => $abs,
        'url'           => erp_public_url($abs),
        'original_name' => $filename,
        'mime'          => $mime,
        'size'          => strlen($bin),
    ];
}

function erp_log_audit(string $event, string $msg, array $meta = [], string $sev = 'info', ?int $uid = null): void {
    try {
        require_once dirname(__DIR__, 2) . '/api/audit-log.php';
        AetherAudit::log($event, $msg, $meta, $sev, $uid);
    } catch (\Throwable $e) {}
}

/* Number -> Indian-English words for invoice "Amount in words" */
function erp_number_to_words(float $n): string {
    $n = round($n, 2);
    $rupees = (int)floor($n);
    $paise  = (int)round(($n - $rupees) * 100);
    $words = _erp_int_words($rupees);
    $out = $words . ' Rupees';
    if ($paise > 0) $out .= ' and ' . _erp_int_words($paise) . ' Paise';
    return $out . ' Only';
}
function _erp_int_words(int $n): string {
    if ($n === 0) return 'Zero';
    $ones  = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens  = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $under100 = function (int $x) use ($ones, $tens) {
        if ($x < 20) return $ones[$x];
        $t = intdiv($x, 10); $u = $x % 10;
        return rtrim($tens[$t] . ($u ? ' ' . $ones[$u] : ''));
    };
    $under1000 = function (int $x) use ($ones, $under100) {
        $h = intdiv($x, 100); $r = $x % 100;
        $out = '';
        if ($h) $out .= $ones[$h] . ' Hundred';
        if ($r) $out .= ($h ? ' ' : '') . $under100($r);
        return $out;
    };
    $parts = [];
    $crore = intdiv($n, 10000000); $n %= 10000000;
    $lakh  = intdiv($n, 100000);   $n %= 100000;
    $thou  = intdiv($n, 1000);     $n %= 1000;
    if ($crore) $parts[] = $under100($crore) . ' Crore';
    if ($lakh)  $parts[] = $under100($lakh)  . ' Lakh';
    if ($thou)  $parts[] = $under100($thou)  . ' Thousand';
    if ($n)     $parts[] = $under1000($n);
    return implode(' ', $parts);
}

/* GSTIN -> state code (first 2 chars). Returns null if invalid. */
function erp_gstin_state_code(string $gstin): ?string {
    $gstin = strtoupper(trim($gstin));
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z][Z][0-9A-Z]$/', $gstin)) return null;
    return substr($gstin, 0, 2);
}
