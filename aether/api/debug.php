<?php
/**
 * Aether Debug Tool — REMOVE THIS FILE AFTER SETUP IS CONFIRMED
 * Visit: erp.dhrubfoundation.org/dhrub_erp/aether/api/debug.php
 *
 * BUG-FIX: original reused an exhausted RecursiveIteratorIterator; each scan
 * now creates a fresh iterator.
 */

// ── Basic security: only allow access from local IP or logged-in admin ────────
// Uncomment to lock down:
// if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) { http_response_code(403); die('Forbidden'); }

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$base = '/home/u135884328/domains/dhrubfoundation.org/public_html';
$erp  = $base . '/dhrub_erp';
$out  = [];

// ── Helper: fresh recursive iterator ─────────────────────────────────────────
function freshIter(string $dir): RecursiveIteratorIterator {
    return new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
}

// ── 1. PHP environment ────────────────────────────────────────────────────────
$out['php'] = [
    'version'    => PHP_VERSION,
    'extensions' => [
        'curl'    => extension_loaded('curl'),
        'pdo_mysql'=> extension_loaded('pdo_mysql'),
        'mbstring'=> extension_loaded('mbstring'),
        'gd'      => extension_loaded('gd'),
        'zip'     => extension_loaded('zip'),
        'openssl' => extension_loaded('openssl'),
    ],
    'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
    'upload_max_size' => ini_get('upload_max_filesize'),
    'temp_dir'        => sys_get_temp_dir(),
    'temp_writable'   => is_writable(sys_get_temp_dir()),
];

// ── 2. Folder map (2 levels) ──────────────────────────────────────────────────
$out['folder_map'] = [];
if (is_dir($erp)) {
    foreach (new DirectoryIterator($erp) as $l1) {
        if ($l1->isDot()) continue;
        $name = $l1->getFilename();
        if ($l1->isDir()) {
            $children = [];
            foreach (new DirectoryIterator($l1->getPathname()) as $l2) {
                if (!$l2->isDot()) $children[] = $l2->getFilename() . ($l2->isDir() ? '/' : '');
            }
            sort($children);
            $out['folder_map'][$name . '/'] = $children;
        } else {
            $out['folder_map'][$name] = 'file';
        }
    }
    ksort($out['folder_map']);
}

// ── 3. auth.php locations ─────────────────────────────────────────────────────
$out['auth_php_locations'] = [];
foreach (freshIter($erp) as $file) {
    if ($file->getFilename() === 'auth.php') {
        $out['auth_php_locations'][] = str_replace($base, '', $file->getPathname());
    }
}

// ── 4. autoload.php locations ─────────────────────────────────────────────────
$out['autoload_locations'] = [];
foreach (freshIter($erp) as $file) {
    if ($file->getFilename() === 'autoload.php') {
        $out['autoload_locations'][] = str_replace($base, '', $file->getPathname());
    }
}

// ── 5. Database class ─────────────────────────────────────────────────────────
$out['database_class_in'] = [];
foreach (freshIter($erp) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && $file->getSize() < 200000) {
        $src = @file_get_contents($file->getPathname());
        if ($src && str_contains($src, 'class Database')) {
            $out['database_class_in'][] = str_replace($base, '', $file->getPathname());
        }
    }
}

// ── 6. .env readable? ────────────────────────────────────────────────────────
$envPath = dirname(__DIR__) . '/.env';
$out['env_file'] = [
    'path'     => $envPath,
    'exists'   => file_exists($envPath),
    'readable' => is_readable($envPath),
];

// ── 7. Vendor libraries ───────────────────────────────────────────────────────
$vendorBase = dirname(__DIR__) . '/vendor';
$out['vendor'] = [
    'phpmailer_composer' => file_exists($vendorBase . '/phpmailer/phpmailer/src/PHPMailer.php'),
    'phpmailer_flat'     => file_exists($vendorBase . '/phpmailer/src/PHPMailer.php'),
    'mpdf_autoload'      => file_exists($vendorBase . '/mpdf/mpdf/vendor/autoload.php'),
    'root_autoload'      => file_exists($vendorBase . '/autoload.php'),
];

// ── 8. uploads/documents writable ────────────────────────────────────────────
$uploadDir = dirname(__DIR__) . '/uploads/documents';
$out['uploads_dir'] = [
    'path'     => $uploadDir,
    'exists'   => is_dir($uploadDir),
    'writable' => is_writable($uploadDir),
];

// ── 9. DB connectivity ────────────────────────────────────────────────────────
if (file_exists(dirname(__FILE__) . '/config.php')) {
    require_once __DIR__ . '/config.php';
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
        $out['db'] = ['connected' => true, 'host' => DB_HOST, 'name' => DB_NAME];
        // Check if aether_memory table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'aether_memory'")->fetchAll();
        $out['db']['aether_memory_table'] = !empty($tables);
    } catch (PDOException $e) {
        $out['db'] = ['connected' => false, 'error' => $e->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
