<?php
/**
 * Database Configuration
 */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'dhrub_erp');
define('DB_USER', getenv('DB_USER') ?: 'aether');
define('DB_PASS', getenv('DB_PASS') ?: 'AetherDev2026!');

define('JWT_SECRET', 'dhrub-foundation-erp-jwt-secret-2024');
define('JWT_EXPIRY', 28800);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Kolkata');
