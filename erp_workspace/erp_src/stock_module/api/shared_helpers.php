<?php
/**
 * Shared helpers — bridges dhrub_erp's auth into the module's `erp_*` namespace.
 *
 * The module files (stock.php, grn.php, invoice.php …) were originally written
 * for the Aether codebase where helpers are named `erp_*`. To avoid copy-paste
 * drift we keep the SAME module files and define thin aliases here.
 */

if (!function_exists('erp_db')) {
    function erp_db(): PDO { return dhrub_db(); }
}
if (!function_exists('erp_json')) {
    function erp_json(array $p, int $c = 200): void { dhrub_json($p, $c); }
}
if (!function_exists('erp_error')) {
    function erp_error(string $m, int $c = 400): void { dhrub_error($m, $c); }
}
if (!function_exists('erp_body')) {
    function erp_body(): array { return dhrub_body(); }
}
if (!function_exists('erp_current_user')) {
    function erp_current_user(): ?array { return dhrub_current_user(); }
}
if (!function_exists('erp_require_user')) {
    function erp_require_user(): array { return dhrub_require_user(); }
}
if (!function_exists('erp_require_role')) {
    function erp_require_role(array $a): array { return dhrub_require_role($a); }
}
if (!function_exists('erp_is_super_admin')) {
    function erp_is_super_admin(?array $u): bool { return ($u['role'] ?? '') === 'super_admin'; }
}
if (!function_exists('erp_setting')) {
    function erp_setting(string $k, string $d = ''): string { return dhrub_setting($k, $d); }
}

if (!function_exists('erp_fy_code')) {
    function erp_fy_code(?string $dateYmd = null): string {
        $ts = $dateYmd ? strtotime($dateYmd) : time();
        $y = (int)date('Y', $ts); $m = (int)date('n', $ts);
        $startY = $m < 4 ? $y - 1 : $y;
        return $startY . '-' . str_pad(($startY + 1) % 100, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('erp_next_doc_no')) {
    function erp_next_doc_no(string $type, ?string $dateYmd = null): string {
        static $prefixMap = ['invoice'=>'invoice_prefix','grn'=>'grn_prefix','po'=>'po_prefix','adj'=>'adj_prefix'];
        $fy = erp_fy_code($dateYmd);
        $prefix = 'DOC';
        if ($type === 'vendor') $prefix = 'VND';
        elseif (!empty($prefixMap[$type])) $prefix = erp_setting($prefixMap[$type], strtoupper($type));
        else $prefix = strtoupper($type);

        $db = erp_db();
        $db->prepare("INSERT INTO erp_doc_counters (doc_type, fy, last_seq) VALUES (?, ?, LAST_INSERT_ID(1))
                      ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)")
           ->execute([$type, $fy]);
        $seq = (int)$db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
        if ($seq <= 0) $seq = 1;
        return sprintf('%s/%s/%04d', $prefix, $fy, $seq);
    }
}

if (!function_exists('erp_upload_dir')) {
    function erp_upload_dir(string $sub): string {
        // Use a local uploads/ folder inside the stock_module
        $base = dirname(__DIR__, 2) . '/uploads/stock_module';
        $sub  = preg_replace('/[^A-Za-z0-9._\/-]/', '_', $sub);
        $dir  = rtrim($base . '/' . trim($sub, '/'), '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}
if (!function_exists('erp_public_url')) {
    function erp_public_url(string $absPath): string {
        $base = dirname(__DIR__, 2);
        if (strpos($absPath, $base) === 0) return substr($absPath, strlen($base));
        return $absPath;
    }
}

if (!function_exists('erp_save_upload')) {
    function erp_save_upload(array $file, string $sub, array $allowedMime = []): array {
        if (!is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload failed: ' . ($file['error'] ?? 'unknown'));
        $mime = mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream');
        if ($allowedMime) {
            $ok = false;
            foreach ($allowedMime as $r) {
                if ($r === $mime) { $ok = true; break; }
                if (substr($r, -2) === '/*' && strpos($mime, substr($r, 0, -2)) === 0) { $ok = true; break; }
            }
            if (!$ok) throw new RuntimeException('File type not allowed: ' . $mime);
        }
        $dir = erp_upload_dir($sub);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name'] ?: 'upload');
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
        $abs = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $abs)) throw new RuntimeException('Storage failed');
        return ['abs_path'=>$abs, 'url'=>erp_public_url($abs), 'original_name'=>$file['name'], 'mime'=>$mime, 'size'=>(int)($file['size'] ?? filesize($abs))];
    }
}
if (!function_exists('erp_save_base64')) {
    function erp_save_base64(string $base64, string $filename, string $sub, array $allowedMime = []): array {
        if (preg_match('/^data:([^;]+);base64,(.*)$/', $base64, $m)) {
            $bin = base64_decode($m[2]) ?: '';
        } else {
            $bin = base64_decode($base64) ?: '';
        }
        if (!$bin) throw new RuntimeException('Empty file');
        $dir = erp_upload_dir($sub);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename ?: 'upload.bin');
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
        $abs = $dir . '/' . $name;
        file_put_contents($abs, $bin);
        $mime = mime_content_type($abs) ?: 'application/octet-stream';
        if ($allowedMime) {
            $ok = false;
            foreach ($allowedMime as $r) {
                if ($r === $mime || (substr($r, -2) === '/*' && strpos($mime, substr($r, 0, -2)) === 0)) { $ok = true; break; }
            }
            if (!$ok) { @unlink($abs); throw new RuntimeException('File type not allowed'); }
        }
        return ['abs_path'=>$abs, 'url'=>erp_public_url($abs), 'original_name'=>$filename, 'mime'=>$mime, 'size'=>strlen($bin)];
    }
}

if (!function_exists('erp_log_audit')) {
    // Map Aether's audit logger to dhrub_erp's activity_log
    function erp_log_audit(string $event, string $msg, array $meta = [], string $sev = 'info', ?int $uid = null): void {
        dhrub_log_activity($event, 'stock_module', null, $msg);
    }
}

if (!function_exists('erp_gstin_state_code')) {
    function erp_gstin_state_code(string $gstin): ?string {
        $gstin = strtoupper(trim($gstin));
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z][Z][0-9A-Z]$/', $gstin)) return null;
        return substr($gstin, 0, 2);
    }
}

if (!function_exists('erp_number_to_words')) {
    function erp_number_to_words(float $n): string {
        $n = round($n, 2);
        $r = (int)floor($n); $p = (int)round(($n - $r) * 100);
        $out = _erp_int_words($r) . ' Rupees';
        if ($p > 0) $out .= ' and ' . _erp_int_words($p) . ' Paise';
        return $out . ' Only';
    }
    function _erp_int_words(int $n): string {
        if ($n === 0) return 'Zero';
        $o = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
        $t = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        $u100 = function($x) use ($o, $t) { return $x < 20 ? $o[$x] : rtrim($t[intdiv($x,10)] . ($x%10 ? ' '.$o[$x%10] : '')); };
        $u1k  = function($x) use ($o, $u100) {
            $h = intdiv($x,100); $r = $x%100; $out = '';
            if ($h) $out .= $o[$h] . ' Hundred';
            if ($r) $out .= ($h ? ' ' : '') . $u100($r);
            return $out;
        };
        $parts = [];
        $cr = intdiv($n,10000000); $n %= 10000000;
        $lk = intdiv($n,100000);   $n %= 100000;
        $th = intdiv($n,1000);     $n %= 1000;
        if ($cr) $parts[] = $u100($cr) . ' Crore';
        if ($lk) $parts[] = $u100($lk) . ' Lakh';
        if ($th) $parts[] = $u100($th) . ' Thousand';
        if ($n)  $parts[] = $u1k($n);
        return implode(' ', $parts);
    }
}
