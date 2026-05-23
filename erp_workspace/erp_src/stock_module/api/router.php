<?php
/**
 * Stock & GST Router for dhrub_erp.
 *
 * Endpoint: POST /stock_module/api/router.php?action=<name>
 *
 * The router uses dhrub_erp's existing JWT for auth and operates on the
 * same `dhrub_erp` database (tables: erp_vendors, erp_grns, erp_tax_invoices …).
 */

require_once __DIR__ . '/auth_bridge.php';

// CORS / OPTIONS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }

$user = dhrub_require_user();
$action = $_GET['action'] ?? '';
if ($action === '') {
    $body = dhrub_body();
    $action = $body['action'] ?? '';
}
if ($action === '') dhrub_error('Missing action', 400);

// Polyfills so the shared module files (originally written for Aether's `erp_*` helpers)
// work without modification — we just alias dhrub_* to erp_*.
require_once __DIR__ . '/shared_helpers.php';

try {
    [$module] = explode('_', $action, 2);
    $modulesDir = __DIR__ . '/modules';
    $map = [
        'stock'   => 'stock.php',
        'vendor'  => 'vendors.php',
        'po'      => 'purchase.php',
        'grn'     => 'grn.php',
        'invoice' => 'invoice.php',
        'ref'     => 'ref.php',
        'dash'    => 'dashboard.php',
        'seed'    => 'seed.php',
    ];
    if (!isset($map[$module])) dhrub_error("Unknown action: $action", 400);
    require_once $modulesDir . '/' . $map[$module];
    // Each module exposes: function erp_<module>_dispatch(string $action, array $user): void
    $fn = 'erp_' . $module . '_dispatch';
    $fn($action, $user);
} catch (\Throwable $e) {
    dhrub_log_activity('stock_api_error', 'router', null, $e->getMessage());
    dhrub_error('Internal error: ' . $e->getMessage(), 500);
}
