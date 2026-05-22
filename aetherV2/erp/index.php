<?php
/**
 * Aether ERP — Stock & GST Module
 *
 * Single-page app shell. Routing handled by hash in /static/erp.js.
 * Authentication: re-uses the same JWT stored by /aetherV2/chat.php.
 */
require_once __DIR__ . '/api/common.php';
$orgName = erp_setting('org_name', 'Dhrub Foundation');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($orgName) ?> · Stock & GST Billing</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Crimson+Pro:ital,wght@0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/svg+xml" href="/aetherV2/logo.svg">
<link rel="stylesheet" href="/aetherV2/erp/static/erp.css">
</head>
<body>
<div id="app">
  <div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#aeb9c8;font-family:'Crimson Pro';font-style:italic;font-size:15px">
    <span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(16,185,129,.2);border-top-color:#10b981;border-radius:50%;animation:spin .7s linear infinite;margin-right:10px"></span>
    One moment, sir…
  </div>
</div>
<style>@keyframes spin { to { transform: rotate(360deg); } }</style>
<script src="/aetherV2/erp/static/erp.js"></script>
</body>
</html>
