<?php
/**
 * Aether — config.php (Self-Sufficient Edition)
 * OpenAI dependency removed. No external AI API needed.
 */

$envFile = __DIR__ . '/../.env';

if (!file_exists($envFile)) {
    http_response_code(500);
    die(json_encode([
        "error" => "Configuration file (.env) is missing",
        "path"  => $envFile,
        "fix"   => "Create the .env file in the aether/ folder"
    ]));
}

if (!is_readable($envFile)) {
    http_response_code(500);
    die(json_encode([
        "error" => "Configuration file (.env) is not readable",
        "fix"   => "Run: chmod 600 " . $envFile
    ]));
}

$env = parse_ini_file($envFile, false, INI_SCANNER_RAW);

if ($env === false) {
    $lines    = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $errorMsg = "Failed to parse .env — syntax error detected\n\nLines:\n";
    foreach (array_slice($lines, 0, 10) as $i => $line) {
        $errorMsg .= "Line " . ($i + 1) . ": " . htmlspecialchars($line) . "\n";
    }
    http_response_code(500);
    die(json_encode(["error" => $errorMsg], JSON_UNESCAPED_SLASHES));
}

// ── Define constants ──────────────────────────────────────────────────────────
if (!defined('DB_HOST'))               define('DB_HOST',               $env['DB_HOST']               ?? 'localhost');
if (!defined('DB_NAME'))               define('DB_NAME',               $env['DB_NAME']               ?? '');
if (!defined('DB_USER'))               define('DB_USER',               $env['DB_USER']               ?? '');
if (!defined('DB_PASS'))               define('DB_PASS',               $env['DB_PASS']               ?? '');
if (!defined('SMTP_HOST'))             define('SMTP_HOST',             $env['SMTP_HOST']             ?? '');
if (!defined('SMTP_PORT'))             define('SMTP_PORT',             (int)($env['SMTP_PORT']       ?? 587));
if (!defined('SMTP_USERNAME'))         define('SMTP_USERNAME',         $env['SMTP_USERNAME']         ?? '');
if (!defined('SMTP_PASSWORD'))         define('SMTP_PASSWORD',         $env['SMTP_PASSWORD']         ?? '');
if (!defined('SMTP_FROM_NAME'))        define('SMTP_FROM_NAME',        $env['SMTP_FROM_NAME']        ?? 'Dhrub Foundation');
if (!defined('FAST2SMS_API_KEY'))      define('FAST2SMS_API_KEY',      $env['FAST2SMS_API_KEY']      ?? '');
if (!defined('EVOLUTION_API_URL'))     define('EVOLUTION_API_URL',     $env['EVOLUTION_API_URL']     ?? '');
if (!defined('EVOLUTION_INSTANCE_NAME')) define('EVOLUTION_INSTANCE_NAME', $env['EVOLUTION_INSTANCE_NAME'] ?? 'default');
if (!defined('EVOLUTION_API_KEY'))     define('EVOLUTION_API_KEY',     $env['EVOLUTION_API_KEY']     ?? '');

// Global variables for backward compatibility
global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS,
       $SMTP_HOST, $SMTP_PORT, $SMTP_USERNAME, $SMTP_PASSWORD, $SMTP_FROM_NAME,
       $FAST2SMS_API_KEY, $EVOLUTION_API_URL, $EVOLUTION_INSTANCE_NAME, $EVOLUTION_API_KEY;

$DB_HOST              = DB_HOST;
$DB_NAME              = DB_NAME;
$DB_USER              = DB_USER;
$DB_PASS              = DB_PASS;
$SMTP_HOST            = SMTP_HOST;
$SMTP_PORT            = SMTP_PORT;
$SMTP_USERNAME        = SMTP_USERNAME;
$SMTP_PASSWORD        = SMTP_PASSWORD;
$SMTP_FROM_NAME       = SMTP_FROM_NAME;
$FAST2SMS_API_KEY     = FAST2SMS_API_KEY;
$EVOLUTION_API_URL       = EVOLUTION_API_URL;
$EVOLUTION_INSTANCE_NAME = EVOLUTION_INSTANCE_NAME;
$EVOLUTION_API_KEY       = EVOLUTION_API_KEY;

// Suppress DB constant redefinition warnings from the ERP's database.php
set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
    if ($errno === E_WARNING
        && strpos($errstr, 'already defined') !== false
        && strpos($errfile, 'database.php') !== false
    ) {
        return true;
    }
    return false;
}, E_WARNING);
