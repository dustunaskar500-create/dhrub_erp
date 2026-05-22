<?php
/**
 * ERP — Unified router
 *
 * Endpoint: POST /aetherV2/erp/api/router.php?action=...
 *
 * Modules:
 *   stock_*    — items + adjustments + movements
 *   vendor_*   — vendors
 *   po_*       — purchase orders
 *   grn_*      — goods receipt notes (with photo/video uploads)
 *   invoice_*  — GST tax invoices (PDF + payments + GST summary)
 *   ref_*      — lookups (states, settings)
 */

require_once __DIR__ . '/common.php';
erp_setup_cors();

$user = erp_require_user();
$action = $_GET['action'] ?? '';
if ($action === '') {
    $body = erp_body();
    $action = $body['action'] ?? '';
}
if ($action === '') erp_error('Missing action', 400);

try {
    [$module] = explode('_', $action, 2);
    switch ($module) {
        case 'stock':
            require_once __DIR__ . '/stock.php';
            erp_stock_dispatch($action, $user);
            break;
        case 'vendor':
            require_once __DIR__ . '/vendors.php';
            erp_vendor_dispatch($action, $user);
            break;
        case 'po':
            require_once __DIR__ . '/purchase.php';
            erp_po_dispatch($action, $user);
            break;
        case 'grn':
            require_once __DIR__ . '/grn.php';
            erp_grn_dispatch($action, $user);
            break;
        case 'invoice':
            require_once __DIR__ . '/invoice.php';
            erp_invoice_dispatch($action, $user);
            break;
        case 'ref':
            require_once __DIR__ . '/ref.php';
            erp_ref_dispatch($action, $user);
            break;
        case 'dash':
            require_once __DIR__ . '/dashboard.php';
            erp_dash_dispatch($action, $user);
            break;
        case 'seed':
            require_once __DIR__ . '/seed.php';
            erp_seed_dispatch($action, $user);
            break;
        default:
            erp_error("Unknown action: $action", 400);
    }
} catch (\Throwable $e) {
    erp_log_audit('erp_api_error', $e->getMessage(), ['action' => $action, 'trace' => $e->getTraceAsString()], 'high', $user['id'] ?? null);
    erp_error('Internal error: ' . $e->getMessage(), 500);
}
