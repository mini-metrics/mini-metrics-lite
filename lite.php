<?php
// Mini Metrics Lite: shared setup for index.php and track.php.

// Same version numbers as the full version of Mini Metrics
define('LITE_VERSION', '1.1.0');

if (!is_file(__DIR__ . '/config.php')) {
    error_log('[Mini Metrics Lite] config.php is missing');
    http_response_code(500);
    exit('Mini Metrics Lite: config.php is missing.');
}
require_once __DIR__ . '/config.php';

// Safe defaults, so an older config.php keeps working after an upgrade
if (!defined('SITE_DOMAIN')) define('SITE_DOMAIN', '');
if (!defined('DATA_RETENTION_MONTHS')) define('DATA_RETENTION_MONTHS', 6);
if (!defined('DB_PATH')) define('DB_PATH', dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/analytics.db');
if (defined('TIMEZONE') && @date_default_timezone_set(TIMEZONE) === false) {
    date_default_timezone_set('UTC');
}

// "www.Example.com" and "example.com" are the same site
function liteHost($host)
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('/^https?:\/\//', '', $host);
    $host = preg_replace('/[\/?#:].*$/s', '', $host);
    $host = preg_replace('/^www\./', '', $host);
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) ? $host : '';
}

function liteSiteDomain()
{
    return liteHost(SITE_DOMAIN);
}

// The database, with all tables in place. Lite's own tables start with "lite_",
// so the same analytics.db also works in the full version of Mini Metrics.
function liteDb()
{
    static $db = null;
    if ($db) {
        return $db;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Cannot create the database folder: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('The database folder is not writable: ' . $dir);
    }
    // Apache: never serve the database, even if it ended up inside the web root
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(db|db-journal|db-wal|db-shm)$\">\n    Require all denied\n</FilesMatch>\n");
    }

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000'); // wait for a busy database instead of failing

    $db->exec("CREATE TABLE IF NOT EXISTS pageviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_url TEXT NOT NULL,
        page_path TEXT NOT NULL,
        referrer TEXT,
        user_agent TEXT,
        ip_hash TEXT NOT NULL,
        country TEXT,
        city TEXT,
        region TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_created_at ON pageviews(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_page_path ON pageviews(page_path)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_hash ON pageviews(ip_hash)");

    $db->exec("CREATE TABLE IF NOT EXISTS lite_meta (name TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS lite_geo_cache (
        ip_key TEXT PRIMARY KEY, country TEXT, city TEXT, region TEXT, created_at INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS lite_login_failures (ip_key TEXT NOT NULL, created_at INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lite_login_failures ON lite_login_failures(ip_key, created_at)");

    return $db;
}

function liteMeta($name, $value = null)
{
    $db = liteDb();
    if ($value !== null) {
        $db->prepare("INSERT OR REPLACE INTO lite_meta (name, value) VALUES (?, ?)")->execute([$name, (string) $value]);
        return (string) $value;
    }
    $stmt = $db->prepare("SELECT value FROM lite_meta WHERE name = ?");
    $stmt->execute([$name]);
    $found = $stmt->fetchColumn();
    return $found === false ? null : $found;
}

// A random secret for this installation, so stored hashes can't be turned back into IP addresses
function liteSecret()
{
    static $secret = null;
    if ($secret === null) {
        $secret = liteMeta('secret');
        if (!$secret) {
            liteDb()->prepare("INSERT OR IGNORE INTO lite_meta (name, value) VALUES ('secret', ?)")
                ->execute([bin2hex(random_bytes(32))]);
            $secret = liteMeta('secret');
        }
    }
    return $secret;
}

// A hash of an IP address that can't be reversed (the secret is never shown)
function liteIpKey($ip, $purpose)
{
    return hash_hmac('sha256', $ip, liteSecret() . '|' . $purpose);
}
