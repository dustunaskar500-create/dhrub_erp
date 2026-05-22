<?php
/**
 * Aether v2 — Cron Diagnostic
 *
 * Run this from SSH on Hostinger to verify everything the heartbeat needs
 * is in place before you set up the cron job:
 *
 *   /usr/bin/php /home/uXXXX/domains/erp.dhrubfoundation.org/public_html/aetherV2/api/cron-doctor.php
 *
 * It prints a coloured pass/fail report — and importantly does NOT modify
 * any data. Safe to run as many times as you want.
 */

$ANSI = function_exists('posix_isatty') && @posix_isatty(STDOUT);
function out($status, $label, $detail = '') {
    global $ANSI;
    $icon = match ($status) {
        'ok'   => $GLOBALS['ANSI'] ? "\e[32m✓\e[0m" : '[OK]',
        'warn' => $GLOBALS['ANSI'] ? "\e[33m⚠\e[0m" : '[WARN]',
        'fail' => $GLOBALS['ANSI'] ? "\e[31m✗\e[0m" : '[FAIL]',
        default => '·'
    };
    $b = $GLOBALS['ANSI'] ? "\e[1m" : '';
    $r = $GLOBALS['ANSI'] ? "\e[0m" : '';
    echo "  {$icon} {$b}{$label}{$r}" . ($detail ? " — {$detail}" : '') . "\n";
}
function section($t) {
    global $ANSI;
    $b = $ANSI ? "\e[1;36m" : '';
    $r = $ANSI ? "\e[0m" : '';
    echo "\n{$b}{$t}{$r}\n" . str_repeat('─', mb_strlen($t)) . "\n";
}

$pass = $fail = $warn = 0;
function pass(string $l, string $d = '')  { global $pass; out('ok',   $l, $d); $pass++; }
function failure(string $l, string $d='') { global $fail; out('fail', $l, $d); $fail++; }
function warning(string $l, string $d='') { global $warn; out('warn', $l, $d); $warn++; }

echo "\nAether v2 — Cron Doctor\n=======================\n";
echo "Date:  " . date('Y-m-d H:i:s') . "\n";
echo "Path:  " . __FILE__ . "\n";
echo "PHP:   " . PHP_VERSION . " @ " . PHP_BINARY . "\n";

// ── 1. PHP version
section('1) PHP runtime');
if (version_compare(PHP_VERSION, '8.0.0', '>=')) pass('PHP version >= 8.0', PHP_VERSION);
else failure('PHP version too old', PHP_VERSION . ' — Aether requires 8.0+');

foreach (['pdo_mysql','mbstring','json','curl','openssl','gd','xml'] as $ext) {
    if (extension_loaded($ext)) pass("ext: {$ext}");
    else if (in_array($ext, ['gd','xml'])) warning("ext: {$ext}", 'optional, recommended for PDFs');
    else failure("ext: {$ext}", 'required');
}

// ── 2. Files present
section('2) Required files');
$root = __DIR__ . '/..';
$need = [
    '.env'                 => 'Configuration',
    'api/aether.php'       => 'Main router',
    'api/bootstrap.php'    => 'Auth bootstrap',
    'api/config.php'       => 'Config loader',
    'api/heartbeat.php'    => 'Cron entry',
    'api/migrate.php'      => 'Migrations',
    'api/brain.php'        => 'LLM brain',
    'api/persona.php'      => 'Butler persona',
    'panel.js'             => 'Floating panel',
    'dashboard.php'        => 'Command Centre',
    'chat.php'             => 'Standalone chat',
    'vendor/autoload.php'  => 'Composer (mPDF)',
];
foreach ($need as $f => $desc) {
    $abs = "$root/$f";
    if (file_exists($abs)) pass($f, $desc . ' · ' . number_format(filesize($abs)) . ' bytes');
    else failure($f, "$desc missing");
}

// ── 3. .env validity
section('3) .env');
$envPath = "$root/.env";
$envOk = false;
$envVars = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
        if (str_starts_with(trim($l), '#') || !str_contains($l, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $l, 2));
        $envVars[$k] = trim($v, '"\'');
    }
    $envOk = true;
}
if (!$envOk) { failure('.env unreadable'); }
else {
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','JWT_SECRET'] as $k) {
        if (!empty($envVars[$k])) pass("$k set");
        else failure("$k missing", "edit $envPath");
    }
    if (!empty($envVars['EMERGENT_LLM_KEY']) && str_starts_with($envVars['EMERGENT_LLM_KEY'], 'sk-')) {
        pass('EMERGENT_LLM_KEY set', 'butler brain is ON');
    } else {
        warning('EMERGENT_LLM_KEY missing', 'butler will run rule-only (no Claude)');
    }
    foreach (['SMTP_HOST','SMTP_USER','SMTP_PASS'] as $k) {
        if (!empty($envVars[$k])) pass("$k set");
        else warning("$k empty", 'email notifications will be skipped');
    }
    if (!empty($envVars['FAST2SMS_API_KEY'])) pass('FAST2SMS_API_KEY set');
    else warning('FAST2SMS_API_KEY empty', 'SMS notifications will be skipped');
}

// ── 4. Database
section('4) Database connectivity');
if ($envOk && !empty($envVars['DB_HOST'])) {
    try {
        $dsn = "mysql:host={$envVars['DB_HOST']};dbname={$envVars['DB_NAME']};charset=utf8mb4";
        $db = new PDO($dsn, $envVars['DB_USER'], $envVars['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        pass('DB connected', "{$envVars['DB_HOST']} / {$envVars['DB_NAME']}");
        // Aether tables
        $rows = $db->query("SHOW TABLES LIKE 'aether_%'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) >= 9) pass('Aether tables present', count($rows) . ' table(s): ' . implode(', ', array_slice($rows, 0, 5)) . '…');
        else warning('Aether tables incomplete', "found only " . count($rows) . " — run migrate.php");

        // ERP tables
        foreach (['users','donations','donors','expenses','employees'] as $t) {
            try {
                $n = (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
                pass("ERP table $t", "$n row(s)");
            } catch (\Throwable $e) {
                warning("ERP table $t", "missing or unreadable");
            }
        }
    } catch (\Throwable $e) {
        failure('DB connection failed', $e->getMessage());
    }
} else {
    failure('Cannot test DB', '.env missing DB credentials');
}

// ── 5. Heartbeat dry-run
section('5) Heartbeat dry-run');
$hb = "$root/api/heartbeat.php";
if (file_exists($hb)) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($hb) . ' --once 2>&1', $out, $code);
    if ($code === 0) {
        pass('Heartbeat ran cleanly', 'see output below');
        foreach ($out as $line) echo "      | $line\n";
    } else {
        failure('Heartbeat exited non-zero', 'code=' . $code);
        foreach ($out as $line) echo "      | $line\n";
    }
} else {
    failure('heartbeat.php not found', $hb);
}

// ── 6. Cron suggestion
section('6) Recommended Hostinger cron');
echo "  Open hPanel → Advanced → Cron Jobs and add:\n\n";
$absPath = realpath($hb);
echo "    " . ($ANSI ? "\e[1;33m" : "") . "Every 2 minutes:" . ($ANSI ? "\e[0m" : "") . "\n";
echo "      " . PHP_BINARY . " {$absPath} --once\n\n";
echo "  If Hostinger pre-fills /usr/bin/php /home/uXXXX/ for you, paste only:\n";
$relForHostinger = preg_replace('#^/home/[^/]+/#', '', $absPath);
echo "    " . ($ANSI ? "\e[1;33m" : "") . "{$relForHostinger} --once" . ($ANSI ? "\e[0m" : "") . "\n";

// ── Summary
section('Summary');
$total = $pass + $fail + $warn;
echo "  Passed:   {$pass} / {$total}\n";
echo "  Warnings: {$warn}\n";
echo "  Failed:   {$fail}\n\n";
if ($fail === 0) {
    echo ($ANSI ? "\e[1;32m" : '') . "All systems nominal. Aether is ready for production." . ($ANSI ? "\e[0m" : '') . "\n\n";
    exit(0);
} else {
    echo ($ANSI ? "\e[1;31m" : '') . "{$fail} issue(s) require attention before the cron will run reliably." . ($ANSI ? "\e[0m" : '') . "\n\n";
    exit(1);
}
