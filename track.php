<?php
// Mini Metrics Lite: receives page views from track.js.
// It must never break or slow down the website: errors go to the server's
// error log, and the location lookup happens after the response is sent.

ini_set('display_errors', '0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

function trackDone($status)
{
    http_response_code($status);
    exit;
}

set_exception_handler(function ($e) {
    error_log('[Mini Metrics Lite] Tracking error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit;
});

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') trackDone(204);
if ($method !== 'POST') trackDone(405);

require_once __DIR__ . '/lite.php';

$data = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($data) || !isset($data['url'], $data['path']) || !is_string($data['url']) || !is_string($data['path'])) {
    trackDone(400);
}

// Don't count bots, crawlers, uptime monitors and scripts
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
if ($userAgent === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|go-http|java\/|httpclient|facebookexternalhit|preview/i', $userAgent)) {
    trackDone(204);
}

// Only the configured site (any site while SITE_DOMAIN is still empty)
$host = liteHost($data['url']);
$site = liteSiteDomain();
if ($host === '' || ($site !== '' && $host !== $site)) {
    trackDone(403);
}

// One page, one path: no query string or #fragment, no trailing slash
$path = preg_replace('/[?#].*$/s', '', $data['path']);
$path = substr('/' . trim(preg_replace('/[\x00-\x1F\x7F]/', '', $path), '/'), 0, 1024);

// Only links from other sites count as referrers
$referrer = null;
if (isset($data['referrer']) && is_string($data['referrer']) && preg_match('/^https?:\/\//i', $data['referrer'])) {
    $refHost = liteHost($data['referrer']);
    if ($refHost !== '' && $refHost !== $host) {
        $referrer = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $data['referrer']), 0, 1024);
    }
}

// The visitor's IP, only to tell visitors apart; it is never stored.
// Behind Cloudflare or a proxy the real address is in a header. A forged header can
// only change the visitor count, so a valid address from the header is good enough here.
$ip = '';
foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
    $candidate = trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
        $ip = $candidate;
        break;
    }
}

$db = liteDb();
// A new hash every day: visitors can't be followed from one day to the next
$ipHash = liteIpKey($ip . '|' . date('Y-m-d'), 'visitor');

// Location from the cache (7 days); the network lookup comes after the response
$geoTtl = 7 * 86400;
$geoKey = liteIpKey($ip, 'geo');
$stmt = $db->prepare("SELECT country, city, region FROM lite_geo_cache WHERE ip_key = ? AND created_at > ?");
$stmt->execute([$geoKey, time() - $geoTtl]);
$geo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Save the page view first, so a slow location service can never lose it
$db->prepare("INSERT INTO pageviews (site_url, page_path, referrer, user_agent, ip_hash, country, city, region) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$host, $path, $referrer, $userAgent, $ipHash, $geo['country'] ?? null, $geo['city'] ?? null, $geo['region'] ?? null]);
$id = (int) $db->lastInsertId();

// Answer the browser now, then keep working
ignore_user_abort(true);
http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}

try {
    // Private and reserved addresses have no location
    if (!$geo && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $url = 'https://get.geojs.io/v1/ip/geo/' . rawurlencode($ip) . '.json';
        $response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
            $response = curl_exec($ch);
            curl_close($ch);
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $response = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));
        }
        $found = is_string($response) ? json_decode($response, true) : null;
        if (is_array($found)) {
            $geo = [];
            foreach (['country', 'city', 'region'] as $field) {
                $geo[$field] = isset($found[$field]) && is_string($found[$field]) && $found[$field] !== ''
                    ? substr($found[$field], 0, 100) : null;
            }
            if ($geo['country'] || $geo['city']) {
                $db->prepare("INSERT OR REPLACE INTO lite_geo_cache (ip_key, country, city, region, created_at) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$geoKey, $geo['country'], $geo['city'], $geo['region'], time()]);
                $db->prepare("UPDATE pageviews SET country = ?, city = ?, region = ? WHERE id = ?")
                    ->execute([$geo['country'], $geo['city'], $geo['region'], $id]);
            }
        }
    }

    // Now and then, drop expired cache entries
    if (mt_rand(1, 100) === 1) {
        $db->prepare("DELETE FROM lite_geo_cache WHERE created_at <= ?")->execute([time() - $geoTtl]);
    }
} catch (Throwable $e) {
    error_log('[Mini Metrics Lite] Location lookup error: ' . $e->getMessage());
}
