<?php
// Mini Metrics Light — Configuration
// Upgrade to the full version at https://minimetrics.io

// Dashboard Authentication
define('DASHBOARD_USERNAME', 'admin');
define('DASHBOARD_PASSWORD', 'changeme'); // Change this!

// Your Site Domain (one domain only in the Light version)
// Example: 'example.com' — do not include https:// or trailing slash
define('SITE_DOMAIN', '');

// Database Path (outside webroot recommended)
define('DB_PATH', dirname($_SERVER['DOCUMENT_ROOT']) . '/analytics.db');

// Data Retention (months) — Light version: 6 months max
define('DATA_RETENTION_MONTHS', 6);

// Timezone
define('TIMEZONE', 'Europe/Stockholm');

// Session Settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');

date_default_timezone_set(TIMEZONE);
