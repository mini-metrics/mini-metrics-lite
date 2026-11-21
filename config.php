<?php
// Mini Metrics Lite: settings
// Keep this file when you upgrade; new settings always have safe defaults.

// Dashboard login. You can't log in until you change the password.
define('DASHBOARD_USERNAME', 'admin');
define('DASHBOARD_PASSWORD', 'changeme');

// The site you track, without https:// (for example 'example.com').
// www.example.com is counted as example.com.
define('SITE_DOMAIN', '');

// Where the database is kept: outside the web root, so nobody can download it
define('DB_PATH', dirname($_SERVER['DOCUMENT_ROOT']) . '/analytics.db');

// Page views older than this are deleted
define('DATA_RETENTION_MONTHS', 6);

// Your time zone, for days and times in the dashboard
// (a name from https://www.php.net/manual/en/timezones.php)
define('TIMEZONE', 'Europe/Stockholm');
