<?php
/**
 * Aether Stock & GST Module — drop-in for dhrub_erp
 *
 * INSTALLATION on Hostinger / your live ERP:
 *   1. Copy this folder (`stock_module/`) into your ERP root
 *      e.g. /home/USER/public_html/stock_module/
 *
 *   2. Run the migration once:
 *      mysql -u <USER> -p <DB> < stock_module/migrations/001_stock_gst.sql
 *
 *   3. In your existing `index.php` (the API router), add:
 *      // Stock & GST module - mounts /api/stock, /api/vendors, /api/grn, /api/invoices
 *      if (preg_match('#^/api/(stock|vendors|grn|invoices|po|ref|dash)(/|$)#', $path)) {
 *          require __DIR__ . '/stock_module/api/router.php';
 *          exit;
 *      }
 *
 *   4. (Optional) Add a navigation link in your React app or HTML topbar:
 *      <a href="/stock_module/">Stock & GST</a>
 *
 * The module uses YOUR ERP's existing `dhrub_erp` MySQL database directly
 * (so Aether & your ERP read/write the same tables). Authentication
 * piggy-backs on YOUR JWT — the same `Authorization: Bearer <token>` header
 * the rest of your ERP already uses.
 */

require_once __DIR__ . '/api/auth_bridge.php';
// The SPA shell is publicly readable — auth is enforced at the API layer
// (the JS reads the JWT from localStorage and adds Authorization headers).
$orgName = dhrub_setting('org_name', 'Dhrub Foundation');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($orgName) ?> · Stock &amp; GST</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Crimson+Pro:ital,wght@0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="static/theme.css">
<link rel="stylesheet" href="static/erp.css">
<script src="static/theme.js"></script>
</head>
<body>
<div id="app">
  <div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#aeb9c8;font-family:'Crimson Pro';font-style:italic;font-size:15px">
    <span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(16,185,129,.2);border-top-color:#10b981;border-radius:50%;animation:spin .7s linear infinite;margin-right:10px"></span>
    Loading the stock module…
  </div>
</div>
<style>@keyframes spin { to { transform: rotate(360deg); } }</style>
<script src="static/erp.js"></script>
</body>
</html>
