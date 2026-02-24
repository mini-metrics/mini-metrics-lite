<?php
require_once 'config.php';

// Enable error logging (logs to PHP error log)
$DEBUG = false; // Set to true for debugging

function logDebug($message, $data = null) {
    global $DEBUG;
    if ($DEBUG) {
        error_log('[Mini Metrics Light] ' . $message . ($data ? ': ' . print_r($data, true) : ''));
    }
}

// Set headers for CORS and no-cache
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logDebug('Invalid method', $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$rawData = file_get_contents('php://input');
logDebug('Received raw data', $rawData);

if (!$rawData) {
    logDebug('No data received');
    http_response_code(400);
    echo json_encode(['error' => 'No data']);
    exit;
}

$data = json_decode($rawData, true);
logDebug('Decoded data', $data);

if (!$data || !isset($data['url']) || !isset($data['path'])) {
    logDebug('Invalid data structure', $data);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Extract and sanitize data
$siteUrl = filter_var($data['url'], FILTER_SANITIZE_URL);
$pagePath = filter_var($data['path'], FILTER_SANITIZE_URL);
$referrer = isset($data['referrer']) && $data['referrer'] !== '' ? filter_var($data['referrer'], FILTER_SANITIZE_URL) : null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Check configured domain
$allowedDomain = SITE_DOMAIN;
if (!empty($allowedDomain)) {
    // Normalize: strip www. from both sides for comparison
    $normalizedUrl = preg_replace('/^www\./', '', $siteUrl);
    $normalizedAllowed = preg_replace('/^www\./', '', $allowedDomain);

    if ($normalizedUrl !== $normalizedAllowed) {
        logDebug('Domain not allowed', $siteUrl);
        http_response_code(403);
        echo json_encode(['error' => 'Domain not allowed']);
        exit;
    }
}

logDebug('Domain allowed', $siteUrl);

// Get IP and hash it
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ??
      $_SERVER['HTTP_X_FORWARDED_FOR'] ??
      $_SERVER['REMOTE_ADDR'] ??
      '0.0.0.0';

// Use first IP if multiple (X-Forwarded-For)
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

logDebug('Client IP', $ip);

// Hash IP for privacy
$ipHash = hash('sha256', $ip . date('Y-m-d') . 'mini-metrics-salt');

// Setup cache directory
$dbDir = dirname(DB_PATH);
$cacheDir = $dbDir . '/cache';
if (!is_dir($cacheDir)) {
    logDebug('Creating cache directory', $cacheDir);
    @mkdir($cacheDir, 0755, true);
}

// Get location data with caching
$country = null;
$city = null;
$region = null;

$cacheKey = hash('md5', $ip);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$cacheTTL = 86400 * 7; // 7 days

$useCache = false;
if (file_exists($cacheFile)) {
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge < $cacheTTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            $country = $cached['country'] ?? null;
            $city = $cached['city'] ?? null;
            $region = $cached['region'] ?? null;
            $useCache = true;
            logDebug('Using cached GeoIP', $cached);
        }
    } else {
        @unlink($cacheFile);
        logDebug('Cache expired, deleted', $cacheFile);
    }
}

if (!$useCache) {
    try {
        $geoipUrl = 'https://geoip.st/json/' . $ip;

        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($geoipUrl, false, $context);
        if ($response) {
            $geoip = json_decode($response, true);
            logDebug('GeoIP response', $geoip);

            if (isset($geoip['country']['names'])) {
                $country = is_array($geoip['country']['names']) ? reset($geoip['country']['names']) : $geoip['country']['names'];
            }
            if (isset($geoip['city']['names'])) {
                $city = is_array($geoip['city']['names']) ? reset($geoip['city']['names']) : $geoip['city']['names'];
            }
            if (isset($geoip['subdivisions'][0]['names'])) {
                $region = is_array($geoip['subdivisions'][0]['names']) ? reset($geoip['subdivisions'][0]['names']) : $geoip['subdivisions'][0]['names'];
            }

            if ($country || $city || $region) {
                $cacheData = ['country' => $country, 'city' => $city, 'region' => $region];
                @file_put_contents($cacheFile, json_encode($cacheData));
                logDebug('Cached GeoIP data', $cacheData);
            }
        }
    } catch (Exception $e) {
        logDebug('GeoIP failed', $e->getMessage());
    }
}

// Store in database
try {
    if (!is_dir($dbDir)) {
        logDebug('Creating database directory', $dbDir);
        if (!mkdir($dbDir, 0755, true)) {
            throw new Exception('Cannot create database directory');
        }
    }

    if (!is_writable($dbDir)) {
        throw new Exception('Database directory not writable: ' . $dbDir);
    }

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $stmt = $db->prepare("INSERT INTO pageviews (site_url, page_path, referrer, user_agent, ip_hash, country, city, region)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$siteUrl, $pagePath, $referrer, $userAgent, $ipHash, $country, $city, $region]);

    logDebug('Pageview saved', ['site_url' => $siteUrl, 'page_path' => $pagePath, 'country' => $country]);

    http_response_code(200);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    logDebug('Database error', $e->getMessage());
    error_log('[Mini Metrics Light] Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
