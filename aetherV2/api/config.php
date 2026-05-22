<?php
/**
 * Aether v2 — Configuration loader
 * Reads /aetherV2/.env (or environment variables) and exposes constants.
 * Self-contained: no dependency on the ERP's config files.
 */

if (defined('AETHER_CONFIG_LOADED')) return;
define('AETHER_CONFIG_LOADED', true);

$envFile = dirname(__DIR__) . '/.env';
$env = [];
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        // strip optional surrounding quotes
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
}

$pick = static function (string $k, $default = '') use ($env) {
    if (isset($env[$k]) && $env[$k] !== '') return $env[$k];
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
};

// ── Database ────────────────────────────────────────────────────────────
if (!defined('AETHER_DB_HOST')) define('AETHER_DB_HOST', $pick('DB_HOST', 'localhost'));
if (!defined('AETHER_DB_NAME')) define('AETHER_DB_NAME', $pick('DB_NAME', ''));
if (!defined('AETHER_DB_USER')) define('AETHER_DB_USER', $pick('DB_USER', ''));
if (!defined('AETHER_DB_PASS')) define('AETHER_DB_PASS', $pick('DB_PASS', ''));

// ── JWT (must match the ERP's JWT_SECRET so existing tokens validate) ──
if (!defined('AETHER_JWT_SECRET')) define('AETHER_JWT_SECRET', $pick('JWT_SECRET', 'dhrub-foundation-erp-jwt-secret-2024'));

// ── Notifications (optional) ────────────────────────────────────────────
if (!defined('AETHER_NOTIFY_THRESHOLD')) define('AETHER_NOTIFY_THRESHOLD', $pick('AETHER_NOTIFY_THRESHOLD', 'high')); // info|low|medium|high|critical
if (!defined('AETHER_NOTIFY_EMAIL'))     define('AETHER_NOTIFY_EMAIL',     $pick('AETHER_NOTIFY_EMAIL', ''));        // comma-separated
if (!defined('AETHER_NOTIFY_SMS'))       define('AETHER_NOTIFY_SMS',       $pick('AETHER_NOTIFY_SMS', ''));          // comma-separated

if (!defined('AETHER_SMTP_HOST'))     define('AETHER_SMTP_HOST',     $pick('SMTP_HOST', ''));
if (!defined('AETHER_SMTP_PORT'))     define('AETHER_SMTP_PORT',     (int)$pick('SMTP_PORT', '587'));
if (!defined('AETHER_SMTP_USERNAME')) define('AETHER_SMTP_USERNAME', $pick('SMTP_USERNAME', ''));
if (!defined('AETHER_SMTP_PASSWORD')) define('AETHER_SMTP_PASSWORD', $pick('SMTP_PASSWORD', ''));
if (!defined('AETHER_SMTP_FROM_NAME'))define('AETHER_SMTP_FROM_NAME',$pick('SMTP_FROM_NAME', 'Aether ERP'));
if (!defined('AETHER_SMTP_FROM_EMAIL'))define('AETHER_SMTP_FROM_EMAIL',$pick('SMTP_FROM_EMAIL', $pick('SMTP_USERNAME','')));

if (!defined('AETHER_FAST2SMS_KEY'))     define('AETHER_FAST2SMS_KEY',     $pick('FAST2SMS_API_KEY', ''));
if (!defined('AETHER_FAST2SMS_SENDER'))  define('AETHER_FAST2SMS_SENDER',  $pick('FAST2SMS_SENDER',  'AETHR'));

// ── Aether AI brain (hybrid: local rules + LLM via Emergent proxy) ──────
if (!defined('AETHER_LLM_KEY'))      define('AETHER_LLM_KEY',      $pick('EMERGENT_LLM_KEY', ''));
if (!defined('AETHER_LLM_ENDPOINT')) define('AETHER_LLM_ENDPOINT', $pick('AETHER_LLM_ENDPOINT', 'https://integrations.emergentagent.com/llm/chat/completions'));
if (!defined('AETHER_LLM_MODEL'))    define('AETHER_LLM_MODEL',    $pick('AETHER_LLM_MODEL', 'claude-sonnet-4-6'));
if (!defined('AETHER_LLM_FALLBACK')) define('AETHER_LLM_FALLBACK', $pick('AETHER_LLM_FALLBACK_MODEL', 'claude-haiku-4-5-20251001'));
if (!defined('AETHER_LLM_THRESHOLD'))define('AETHER_LLM_THRESHOLD',(float)$pick('AETHER_LLM_THRESHOLD','0.55'));
if (!defined('AETHER_LLM_MAX_TOKENS'))define('AETHER_LLM_MAX_TOKENS',(int)$pick('AETHER_LLM_MAX_TOKENS','1200'));

// ── ERP integration URLs (where "Back to ERP" + logout redirects land) ──
// Used by chat.php + dashboard.php to keep navigation consistent on production.
if (!defined('AETHER_ERP_URL'))    define('AETHER_ERP_URL',    rtrim($pick('ERP_URL',    'https://erp.dhrubfoundation.org/'), '/') . '/');
if (!defined('AETHER_ERP_LOGIN'))  define('AETHER_ERP_LOGIN',  rtrim($pick('ERP_LOGIN',  AETHER_ERP_URL . 'login'), '/'));
if (!defined('AETHER_ERP_LOGOUT'))define('AETHER_ERP_LOGOUT', rtrim($pick('ERP_LOGOUT', AETHER_ERP_URL . 'logout'), '/'));

date_default_timezone_set($pick('TIMEZONE', 'Asia/Kolkata'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Suppress redefine warnings if config.php loaded twice via different paths
set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
    return ($errno === E_WARNING && strpos($errstr, 'already defined') !== false);
}, E_WARNING);
