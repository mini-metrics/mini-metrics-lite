<?php
// Mini Metrics Lite: the dashboard.

ini_set('display_errors', '0');
require_once __DIR__ . '/lite.php';

// ------------------------------------------------------------- helpers

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function postField($key)
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function isHttps()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// This page's URL path, e.g. "/" or "/analytics/"
function selfPath()
{
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
}

function redirectHere($query = '')
{
    header('Location: ' . selfPath() . $query, true, 303);
    exit;
}

// "assets/x.css?v=…": a new URL whenever the file changes, so no browser or CDN keeps an old copy
function assetUrl($file)
{
    return $file . '?v=' . (@filemtime(__DIR__ . '/' . $file) ?: LITE_VERSION);
}

function showLocalTime($utc, $format)
{
    $time = new DateTime($utc, new DateTimeZone('UTC'));
    $time->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $time->format($format);
}

function pageHead($title)
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?></title>
    <link rel="icon" href="<?= h(assetUrl('favicon.png')) ?>">
    <link rel="stylesheet" href="<?= h(assetUrl('lite.css')) ?>">
</head>
<?php
}

function fatalPage($message)
{
    http_response_code(500);
    pageHead('Mini Metrics Lite');
    echo '<body class="center"><main class="win narrow"><div class="win-bar"><span class="win-dot"></span><span class="win-title">Mini Metrics Lite</span></div>'
        . '<div class="win-body"><h1>Something needs fixing</h1><p>' . h($message) . '</p></div></main></body></html>';
    exit;
}

// ------------------------------------------------------------- session and login

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Secure cookies only over HTTPS (forcing them broke logging in over plain HTTP)
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isHttps() ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_name('mm_lite');
session_start();

try {
    $db = liteDb();
} catch (Throwable $e) {
    error_log('[Mini Metrics Lite] ' . $e->getMessage());
    fatalPage('The database can\'t be opened. Check that PHP can write to the folder in DB_PATH (config.php) and that the PDO SQLite extension is enabled. The server\'s error log has the details.');
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirectHere();
}

if (empty($_SESSION['lite_auth'])) {
    $passwordSet = DASHBOARD_PASSWORD !== '' && DASHBOARD_PASSWORD !== 'changeme';
    $loginError = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // The address the web server sees; headers can be forged to dodge the limit
        $remote = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $ipKey = liteIpKey($remote, 'login');
        $db->prepare("DELETE FROM lite_login_failures WHERE created_at < ?")->execute([time() - 86400]);
        $stmt = $db->prepare("SELECT COUNT(*) FROM lite_login_failures WHERE ip_key = ? AND created_at > ?");
        $stmt->execute([$ipKey, time() - 900]);
        $failures = (int) $stmt->fetchColumn();
        $tokenOk = isset($_SESSION['lite_login_token']) && hash_equals($_SESSION['lite_login_token'], postField('token'));

        if (!$passwordSet) {
            $loginError = 'Set your own password in config.php (DASHBOARD_PASSWORD) first.';
        } elseif ($failures >= 5) {
            $loginError = 'Too many wrong passwords. Try again in 15 minutes.';
        } elseif (!$tokenOk) {
            $loginError = 'The form had expired. Please log in again.';
        } elseif (hash_equals((string) DASHBOARD_USERNAME, postField('username')) & hash_equals((string) DASHBOARD_PASSWORD, postField('password'))) {
            session_regenerate_id(true);
            unset($_SESSION['lite_login_token']);
            $_SESSION['lite_auth'] = true;
            redirectHere();
        } else {
            $db->prepare("INSERT INTO lite_login_failures (ip_key, created_at) VALUES (?, ?)")->execute([$ipKey, time()]);
            error_log('Mini Metrics Lite: failed login from ' . $remote);
            $loginError = 'Wrong username or password.';
        }
    }

    $_SESSION['lite_login_token'] = bin2hex(random_bytes(16));
    pageHead('Log in · Mini Metrics Lite');
    ?>
<body class="center">
    <main class="win narrow">
        <div class="win-bar"><span class="win-dot"></span><span class="win-title">Mini Metrics Lite</span></div>
        <form method="post" class="win-body login">
            <h1>Log in</h1>
            <?php if (!$passwordSet): ?>
                <p class="notice">Before you can log in, set your own password in <code>config.php</code> (<code>DASHBOARD_PASSWORD</code>).</p>
            <?php endif; ?>
            <?php if ($loginError): ?>
                <p class="error" role="alert"><?= h($loginError) ?></p>
            <?php endif; ?>
            <input type="hidden" name="token" value="<?= h($_SESSION['lite_login_token']) ?>">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit" class="btn">Log in</button>
        </form>
    </main>
</body>
</html>
<?php
    exit;
}

// ------------------------------------------------------------- housekeeping (once a day)

try {
    if (liteMeta('cleaned_on') !== date('Y-m-d')) {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . max(1, (int) DATA_RETENTION_MONTHS) . ' months'));
        $db->prepare("DELETE FROM pageviews WHERE created_at < ?")->execute([$cutoff]);

        // Lite 1.0 kept files next to the database that could reveal visitor IPs: remove them
        $dataDir = dirname(DB_PATH);
        foreach (glob($dataDir . '/cache/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($dataDir . '/cache/.htaccess');
        @unlink($dataDir . '/cache/index.php');
        @rmdir($dataDir . '/cache');
        @unlink($dataDir . '/login_attempts.json');

        liteMeta('cleaned_on', date('Y-m-d'));
    }
} catch (Throwable $e) {
    error_log('[Mini Metrics Lite] Cleanup error: ' . $e->getMessage());
}

// ------------------------------------------------------------- numbers

$periods = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
$period = isset($_GET['period']) && is_string($_GET['period']) && isset($periods[$_GET['period']]) ? $_GET['period'] : '30d';
$days = ['today' => 1, '7d' => 7, '30d' => 30][$period];

$tz = new DateTimeZone(date_default_timezone_get());
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $tz);
$start = new DateTimeImmutable('today', $tz);
$start = $start->modify('-' . ($days - 1) . ' days');

$site = liteSiteDomain();
$where = ' WHERE created_at >= ?';
$params = [$start->setTimezone($utc)->format('Y-m-d H:i:s')];
$siteWhere = '';
$siteParams = [];
if ($site !== '') {
    $siteWhere = ' AND site_url IN (?, ?)';
    $siteParams = [$site, 'www.' . $site];
}
$where .= $siteWhere;
$params = array_merge($params, $siteParams);

function rows($sql, $params)
{
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totals = rows("SELECT COUNT(*) AS views, COUNT(DISTINCT ip_hash) AS visitors FROM pageviews" . $where, $params)[0];

// Chart: one bar per hour (today) or per day, in your time zone
$buckets = [];
if ($period === 'today') {
    for ($hour = 0; $hour < 24; $hour++) {
        $buckets[$hour] = ['label' => sprintf('%02d:00', $hour), 'views' => 0, 'state' => $hour < (int) $now->format('G') ? '' : ($hour === (int) $now->format('G') ? 'now' : 'later')];
    }
} else {
    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $buckets[$day->format('Y-m-d')] = ['label' => $day->format('D j M'), 'views' => 0, 'state' => $i === $days - 1 ? 'now' : ''];
    }
}
foreach (rows("SELECT strftime('%Y-%m-%d %H:00:00', created_at) AS hour, COUNT(*) AS views FROM pageviews" . $where . " GROUP BY hour", $params) as $row) {
    $local = (new DateTimeImmutable($row['hour'], $utc))->setTimezone($tz);
    $key = $period === 'today' ? (int) $local->format('G') : $local->format('Y-m-d');
    if (isset($buckets[$key])) {
        $buckets[$key]['views'] += (int) $row['views'];
    }
}
$maxViews = max(array_column($buckets, 'views') ?: [0]);
$busiest = null;
foreach ($buckets as $bucket) {
    if ($bucket['views'] > 0 && $bucket['views'] === $maxViews) {
        $busiest = $bucket;
        break;
    }
}

// Top lists
$topPages = rows("SELECT page_path AS label, COUNT(*) AS views FROM pageviews" . $where . " GROUP BY page_path ORDER BY views DESC LIMIT 10", $params);
$topCountries = rows("SELECT country AS label, COUNT(*) AS views FROM pageviews" . $where . " AND country IS NOT NULL AND country != '' GROUP BY country ORDER BY views DESC LIMIT 10", $params);
$topCities = rows("SELECT city AS label, country AS note, COUNT(*) AS views FROM pageviews" . $where . " AND city IS NOT NULL AND city != '' GROUP BY city, country ORDER BY views DESC LIMIT 10", $params);

// Referrers, grouped by site ("news.ycombinator.com" instead of every single link)
$referrerSites = [];
foreach (rows("SELECT referrer, COUNT(*) AS views FROM pageviews" . $where . " AND referrer IS NOT NULL AND referrer != '' GROUP BY referrer ORDER BY views DESC LIMIT 1000", $params) as $row) {
    $refHost = liteHost($row['referrer']) ?: 'Other';
    $referrerSites[$refHost] = ($referrerSites[$refHost] ?? 0) + (int) $row['views'];
}
arsort($referrerSites);
$topReferrers = [];
foreach (array_slice($referrerSites, 0, 10, true) as $label => $views) {
    $topReferrers[] = ['label' => $label, 'views' => $views];
}

$recent = rows("SELECT page_path, city, country, created_at FROM pageviews WHERE 1 = 1" . $siteWhere . " ORDER BY id DESC LIMIT 15", $siteParams);
$hasData = (bool) rows("SELECT 1 FROM pageviews WHERE 1 = 1" . $siteWhere . " LIMIT 1", $siteParams);

// Tracking code for the setup steps
$base = selfPath();
$base = substr($base, 0, strrpos($base, '/') + 1);
$httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $httpHost)) {
    $httpHost = 'analytics.example.com';
}
$trackingCode = '<script src="' . (isHttps() ? 'https' : 'http') . '://' . $httpHost . $base . 'track.js" defer></script>';

// One list on the dashboard: label, count and a bar for its share of the top row
function topList($id, $title, $rows, $empty, $mono = false)
{
    $top = $rows ? max(1, (int) $rows[0]['views']) : 1;
    echo '<section class="panel" aria-labelledby="' . $id . '"><h2 id="' . $id . '">' . h($title) . '</h2>';
    if (!$rows) {
        echo '<p class="empty">' . h($empty) . '</p></section>';
        return;
    }
    echo '<ol class="list">';
    foreach ($rows as $row) {
        $share = max(2, round((int) $row['views'] / $top * 100));
        echo '<li style="--share:' . $share . '%"><span class="label' . ($mono ? ' mono' : '') . '" title="' . h($row['label']) . '">' . h($row['label'])
            . (!empty($row['note']) ? ' <span class="note">' . h($row['note']) . '</span>' : '')
            . '</span><span class="count">' . number_format((int) $row['views']) . '</span></li>';
    }
    echo '</ol></section>';
}

$periodWords = ['today' => 'today', '7d' => 'in the last 7 days', '30d' => 'in the last 30 days'][$period];
pageHead('Mini Metrics Lite' . ($site !== '' ? ' · ' . $site : ''));
?>
<body>
<header class="top">
    <a class="brand" href="<?= h(selfPath()) ?>"><span class="pixel" aria-hidden="true"></span>Mini Metrics <span class="edition">Lite</span></a>
    <a class="logout" href="?logout=1">Log out</a>
</header>

<main class="page">
    <section class="win" aria-labelledby="summary-title">
        <div class="win-bar">
            <span class="win-dot"></span>
            <h1 id="summary-title" class="win-title"><?= $site !== '' ? h($site) : 'No site set yet' ?></h1>
        </div>

        <nav class="tabs" aria-label="Period">
            <?php foreach ($periods as $key => $label): ?>
                <a href="?period=<?= h($key) ?>"<?= $key === $period ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="figures">
            <div>
                <p class="figure"><?= number_format((int) $totals['views']) ?></p>
                <p class="caption">page views <?= h($periodWords) ?></p>
            </div>
            <div>
                <p class="figure"><?= number_format((int) $totals['visitors']) ?></p>
                <p class="caption" title="Visitors are counted per day: someone who comes back on another day counts again">visitors</p>
            </div>
            <div>
                <p class="figure"><?= $busiest ? h($busiest['label']) : '–' ?></p>
                <p class="caption"><?= $busiest ? 'busiest ' . ($period === 'today' ? 'hour' : 'day') . ', ' . number_format($busiest['views']) . ' views' : 'busiest ' . ($period === 'today' ? 'hour' : 'day') ?></p>
            </div>
        </div>

        <figure class="chart">
            <?php
            $count = count($buckets);
            $scale = max(1, $maxViews);
            ?>
            <svg viewBox="0 0 <?= $count * 10 ?> 100" preserveAspectRatio="none" role="img"
                 aria-label="<?= h(number_format((int) $totals['views']) . ' page views ' . $periodWords . ($busiest ? ', most on ' . $busiest['label'] . ' (' . $busiest['views'] . ')' : '')) ?>">
                <?php $i = 0; foreach ($buckets as $bucket):
                    $height = $bucket['views'] > 0 ? max(3, round($bucket['views'] / $scale * 94)) : 0;
                    $x = $i * 10; ?>
                    <g class="bar <?= h($bucket['state']) ?>">
                        <title><?= h($bucket['label'] . ': ' . number_format($bucket['views']) . ' page view' . ($bucket['views'] === 1 ? '' : 's')) ?></title>
                        <rect class="hit" x="<?= $x ?>" y="0" width="10" height="100"/>
                        <?php if ($height > 0): ?>
                            <rect class="fill" x="<?= $x + 1.5 ?>" y="<?= 100 - $height ?>" width="7" height="<?= $height ?>"/>
                            <rect class="cap" x="<?= $x + 1.5 ?>" y="<?= 100 - $height ?>" width="7" height="2.5"/>
                        <?php endif; ?>
                    </g>
                <?php $i++; endforeach; ?>
            </svg>
            <figcaption class="axis">
                <?php $labels = array_column(array_values($buckets), 'label'); ?>
                <span><?= h($labels[0]) ?></span>
                <span><?= h($labels[intdiv($count, 2)]) ?></span>
                <span><?= h(end($labels)) ?></span>
            </figcaption>
        </figure>
    </section>

    <details class="setup panel"<?= ($site === '' || !$hasData) ? ' open' : '' ?>>
        <summary><?= $hasData ? 'Tracking code' : 'Set up tracking' ?></summary>
        <ol class="steps">
            <li>
                <?php if ($site !== ''): ?>
                    Your site is <strong><?= h($site) ?></strong>. To change it, edit <code>SITE_DOMAIN</code> in <code>config.php</code>.
                <?php else: ?>
                    Put your site's domain in <code>config.php</code>, for example <code>define('SITE_DOMAIN', 'example.com');</code> Until then, page views from any site are accepted.
                <?php endif; ?>
            </li>
            <li>
                Paste this into the <code>&lt;head&gt;</code> of every page of your site:
                <div class="snippet">
                    <code id="tracking-code"><?= h($trackingCode) ?></code>
                    <button type="button" class="btn small" data-copy="tracking-code">Copy</button>
                </div>
            </li>
            <li>Open your site in another tab. The visit shows up here when you reload this page.</li>
        </ol>
    </details>

    <div class="grid">
        <?php
        topList('pages', 'Pages', $topPages, 'No page views ' . $periodWords . '.', true);
        topList('referrers', 'Referrers', $topReferrers, 'No visits from other sites ' . $periodWords . '.');
        topList('countries', 'Countries', $topCountries, 'No locations ' . $periodWords . '.');
        topList('cities', 'Cities', $topCities, 'No locations ' . $periodWords . '.');
        ?>
    </div>

    <details class="panel recent">
        <summary>Recent page views</summary>
        <?php if ($recent): ?>
            <ol class="list plain">
                <?php foreach ($recent as $view): ?>
                    <li>
                        <span class="label mono" title="<?= h($view['page_path']) ?>"><?= h($view['page_path']) ?></span>
                        <span class="note"><?= h(trim(($view['city'] ?: '') . ($view['city'] && $view['country'] ? ', ' : '') . ($view['country'] ?: ''))) ?: '–' ?></span>
                        <time datetime="<?= h($view['created_at']) ?>Z"><?= h(showLocalTime($view['created_at'], 'j M, H:i')) ?></time>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="empty">No page views yet.</p>
        <?php endif; ?>
    </details>
</main>

<footer class="foot">
    <p>Mini Metrics Lite <?= h(LITE_VERSION) ?>, free and open source (MIT). Times are in <?= h(date_default_timezone_get()) ?>.</p>
    <p>Need more sites, any date range, comparisons or CSV export? <a href="https://mini-metrics.com/#pricing" rel="noopener" target="_blank">The full version of Mini Metrics</a> is €19 once, and your data comes along.</p>
</footer>

<script>
document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
        var text = document.getElementById(button.dataset.copy).textContent;
        navigator.clipboard.writeText(text).then(function () {
            button.textContent = 'Copied';
            setTimeout(function () { button.textContent = 'Copy'; }, 2000);
        });
    });
});
</script>
</body>
</html>
