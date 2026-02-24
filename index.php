<?php
require_once 'config.php';

session_start();

// Simple rate limiting for login
function checkRateLimit($ip)
{
    $limitFile = dirname(DB_PATH) . '/login_attempts.json';
    $maxAttempts = 5;
    $lockoutTime = 900; // 15 minutes

    $attempts = [];
    if (file_exists($limitFile)) {
        $data = file_get_contents($limitFile);
        $attempts = $data ? json_decode($data, true) : [];
    }

    $cutoff = time() - $lockoutTime;
    $attempts = array_filter($attempts, function ($time) use ($cutoff) {
        return $time > $cutoff;
    });

    if (isset($attempts[$ip]) && count($attempts[$ip]) >= $maxAttempts) {
        return false;
    }

    return true;
}

function recordFailedLogin($ip)
{
    $limitFile = dirname(DB_PATH) . '/login_attempts.json';

    $attempts = [];
    if (file_exists($limitFile)) {
        $data = file_get_contents($limitFile);
        $attempts = $data ? json_decode($data, true) : [];
    }

    if (!isset($attempts[$ip])) {
        $attempts[$ip] = [];
    }

    $attempts[$ip][] = time();
    file_put_contents($limitFile, json_encode($attempts));
}

// Authentication
if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ??
            $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['REMOTE_ADDR'] ??
            '0.0.0.0';

        if (strpos($clientIp, ',') !== false) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        if (!checkRateLimit($clientIp)) {
            $loginError = 'Too many failed attempts. Please try again in 15 minutes.';
        } elseif (
            $_POST['username'] === DASHBOARD_USERNAME &&
            $_POST['password'] === DASHBOARD_PASSWORD
        ) {
            $_SESSION['authenticated'] = true;
            header('Location: /');
            exit;
        } else {
            recordFailedLogin($clientIp);
            $loginError = 'Invalid credentials';
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Login Form
if (!isset($_SESSION['authenticated'])) {
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mini Metrics Light — Login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gradient-to-br from-blue-50 to-indigo-100">
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-xl mb-4">
                        <svg class="w-6 h-6 text-white" viewBox="26 30 58 60" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M74 30H36c-5.512 0-10 4.488-10 10v33c0 5.512 4.488 10 10 10h10.238l4.199 4.898c1.641 1.911 4.239 2.59 6.629 1.731.942-.34 1.84-.988 2.621-1.891l4.07-4.75h10.238c5.512 0 10-4.488 10-10L83.999 40c0-5.511-4.488-10-10-10H74zm6 43a6.01 6.01 0 0 1-6 6H62.84a2.03 2.03 0 0 0-1.519.699l-4.84 5.649c-.782.91-2.18.91-2.949 0l-4.84-5.649a2 2 0 0 0-1.52-.699h-11.16a6.01 6.01 0 0 1-6-6v-4.18l15.02-15.02c1.059-1.058 2.91-1.058 3.961 0l8.012 8.012c1.179 1.18 2.719 2.012 4.379 2.16 2.058.18 4.019-.531 5.461-1.969l13.172-13.172v24.18L80 73zm0-29.84L63.98 59.18c-.531.531-1.23.82-1.98.82s-1.45-.289-1.981-.82l-8.191-8.191c-1.289-1.29-3.012-2-4.828-2a6.79 6.79 0 0 0-4.829 2L29.999 63.161v-23.16a6.01 6.01 0 0 1 6-6h38a6.01 6.01 0 0 1 6 6L80 43.16z"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800">Mini Metrics <span class="text-sm font-semibold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full align-middle">Light</span></h1>
                    <p class="text-gray-500 text-sm mt-1">Web Analytics Dashboard</p>
                </div>
                <?php if (isset($loginError)): ?>
                    <div class="bg-red-50 text-red-600 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2">Username</label>
                        <input type="text" name="username" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required autofocus>
                    </div>
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-medium mb-2">Password</label>
                        <input type="password" name="password" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white py-2.5 rounded-lg hover:bg-blue-700 transition font-medium">Sign In</button>
                </form>
            </div>
        </div>
    </body>
    </html>
<?php
    exit;
}

// Database Functions
function protectDirectory($dir)
{
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "<FilesMatch \"\.(db|json)$\">\n    Deny from all\n</FilesMatch>");
    }

    $indexPhp = $dir . '/index.php';
    if (!file_exists($indexPhp)) {
        file_put_contents($indexPhp, "<?php\n\$uri = \$_SERVER['REQUEST_URI'] ?? '';\nif (preg_match('/\\.(db|json)\$/i', \$uri)) { http_response_code(403); exit('Access denied'); }");
    }
}

function getDb()
{
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    protectDirectory($dbDir);

    $cacheDir = $dbDir . '/cache';
    if (is_dir($cacheDir)) {
        protectDirectory($cacheDir);
    }

    $dbExists = file_exists(DB_PATH);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!$dbExists) {
        $db->exec("CREATE TABLE pageviews (
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

        $db->exec("CREATE INDEX idx_created_at ON pageviews(created_at)");
        $db->exec("CREATE INDEX idx_page_path ON pageviews(page_path)");
        $db->exec("CREATE INDEX idx_ip_hash ON pageviews(ip_hash)");
    }

    // Migration: add columns if missing
    try { $db->exec("ALTER TABLE pageviews ADD COLUMN city TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE pageviews ADD COLUMN region TEXT"); } catch (Exception $e) {}

    return $db;
}

// Auto-cleanup old data
try {
    $db = getDb();
    $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . DATA_RETENTION_MONTHS . ' months'));
    $stmt = $db->prepare("DELETE FROM pageviews WHERE created_at < ?");
    $stmt->execute([$cutoffDate]);
} catch (Exception $e) {
    // Silent fail
}

// Build stats — always all-time totals, last 30 days for breakdowns
$stats = [
    'total_pageviews'  => 0,
    'unique_visitors'  => 0,
    'today_pageviews'  => 0,
    'top_pages'        => [],
    'top_referrers'    => [],
    'top_countries'    => [],
    'top_cities'       => [],
    'recent_activity'  => [],
    'daily_pageviews'  => [],
];

// Site filter — one domain only
$siteWhere  = '';
$siteParams = [];
if (!empty(SITE_DOMAIN)) {
    $siteWhere  = ' AND site_url = ?';
    $siteParams = [SITE_DOMAIN];
}

try {
    // Normalize www. in existing data (one-time migration)
    try {
        $db->exec("UPDATE pageviews SET site_url = SUBSTR(site_url, 5) WHERE site_url LIKE 'www.%'");
    } catch (Exception $e) {}

    // Total pageviews (all-time)
    $stmt = $db->prepare("SELECT COUNT(*) FROM pageviews WHERE 1=1" . $siteWhere);
    $stmt->execute($siteParams);
    $stats['total_pageviews'] = $stmt->fetchColumn();

    // Unique visitors (all-time)
    $stmt = $db->prepare("SELECT COUNT(DISTINCT ip_hash) FROM pageviews WHERE 1=1" . $siteWhere);
    $stmt->execute($siteParams);
    $stats['unique_visitors'] = $stmt->fetchColumn();

    // Today's pageviews
    $stmt = $db->prepare("SELECT COUNT(*) FROM pageviews WHERE DATE(created_at) = DATE('now')" . $siteWhere);
    $stmt->execute($siteParams);
    $stats['today_pageviews'] = $stmt->fetchColumn();

    // Top pages — last 30 days
    $stmt = $db->prepare("SELECT page_path, COUNT(*) as views
                          FROM pageviews
                          WHERE created_at >= datetime('now', '-30 days')" . $siteWhere . "
                          GROUP BY page_path
                          ORDER BY views DESC
                          LIMIT 10");
    $stmt->execute($siteParams);
    $stats['top_pages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top referrers — last 30 days
    $stmt = $db->prepare("SELECT referrer, COUNT(*) as views
                          FROM pageviews
                          WHERE referrer IS NOT NULL AND referrer != ''
                          AND created_at >= datetime('now', '-30 days')" . $siteWhere . "
                          GROUP BY referrer
                          ORDER BY views DESC
                          LIMIT 10");
    $stmt->execute($siteParams);
    $stats['top_referrers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top countries — last 30 days
    $stmt = $db->prepare("SELECT country, COUNT(*) as views
                          FROM pageviews
                          WHERE country IS NOT NULL
                          AND created_at >= datetime('now', '-30 days')" . $siteWhere . "
                          GROUP BY country
                          ORDER BY views DESC
                          LIMIT 10");
    $stmt->execute($siteParams);
    $stats['top_countries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top cities — last 30 days
    $stmt = $db->prepare("SELECT city, region, country, COUNT(*) as views
                          FROM pageviews
                          WHERE city IS NOT NULL
                          AND created_at >= datetime('now', '-30 days')" . $siteWhere . "
                          GROUP BY city, region, country
                          ORDER BY views DESC
                          LIMIT 10");
    $stmt->execute($siteParams);
    $stats['top_cities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent activity
    $stmt = $db->prepare("SELECT page_path, city, region, country, created_at
                          FROM pageviews
                          WHERE 1=1" . $siteWhere . "
                          ORDER BY created_at DESC
                          LIMIT 20");
    $stmt->execute($siteParams);
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Daily pageviews — last 30 days
    $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as views
                          FROM pageviews
                          WHERE created_at >= datetime('now', '-30 days')" . $siteWhere . "
                          GROUP BY DATE(created_at)
                          ORDER BY date ASC");
    $stmt->execute($siteParams);
    $stats['daily_pageviews'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Silent fail
}

// Build tracking URL
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$path        = '/' . trim(dirname($_SERVER['PHP_SELF']), '/');
$trackingUrl = $protocol . '://' . $host . ($path === '/' ? '' : $path) . '/track.js';
$trackingCode = '<script src="' . $trackingUrl . '" defer></script>';

$domainConfigured = !empty(SITE_DOMAIN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mini Metrics Light — Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100">
<div class="min-h-screen" x-data="{ showSetup: <?= !$domainConfigured ? 'true' : 'false' ?>, showRecent: false, copied: false }">

    <!-- Header -->
    <div class="bg-white border-b border-gray-200 sticky top-0 z-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <a href="/" class="flex items-center space-x-3 hover:opacity-80 transition">
                    <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" viewBox="26 30 58 60" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M74 30H36c-5.512 0-10 4.488-10 10v33c0 5.512 4.488 10 10 10h10.238l4.199 4.898c1.641 1.911 4.239 2.59 6.629 1.731.942-.34 1.84-.988 2.621-1.891l4.07-4.75h10.238c5.512 0 10-4.488 10-10L83.999 40c0-5.511-4.488-10-10-10H74zm6 43a6.01 6.01 0 0 1-6 6H62.84a2.03 2.03 0 0 0-1.519.699l-4.84 5.649c-.782.91-2.18.91-2.949 0l-4.84-5.649a2 2 0 0 0-1.52-.699h-11.16a6.01 6.01 0 0 1-6-6v-4.18l15.02-15.02c1.059-1.058 2.91-1.058 3.961 0l8.012 8.012c1.179 1.18 2.719 2.012 4.379 2.16 2.058.18 4.019-.531 5.461-1.969l13.172-13.172v24.18L80 73zm0-29.84L63.98 59.18c-.531.531-1.23.82-1.98.82s-1.45-.289-1.981-.82l-8.191-8.191c-1.289-1.29-3.012-2-4.828-2a6.79 6.79 0 0 0-4.829 2L29.999 63.161v-23.16a6.01 6.01 0 0 1 6-6h38a6.01 6.01 0 0 1 6 6L80 43.16z"/>
                        </svg>
                    </div>
                    <div class="hidden sm:block">
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-gray-800">Mini Metrics</h1>
                            <span class="text-xs font-semibold text-blue-600 bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full">Light</span>
                        </div>
                        <?php if ($domainConfigured): ?>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(SITE_DOMAIN) ?></p>
                        <?php else: ?>
                            <p class="text-xs text-orange-500 font-medium">No domain configured</p>
                        <?php endif; ?>
                    </div>
                </a>

                <div class="flex items-center space-x-3">
                    <button @click="showSetup = !showSetup" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                        Setup
                    </button>
                    <a href="?logout=1" class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                        Log out
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Setup Panel -->
        <div x-show="showSetup" x-cloak class="bg-white rounded-xl shadow-sm border border-blue-200 p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Setup</h2>

            <!-- Domain config notice -->
            <div class="mb-6">
                <p class="text-sm text-gray-600 mb-3">
                    Your site domain is set in <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">config.php</code>.
                    <?php if ($domainConfigured): ?>
                        Currently tracking: <strong><?= htmlspecialchars(SITE_DOMAIN) ?></strong>.
                    <?php else: ?>
                        <span class="text-orange-600 font-medium">Set <code class="text-xs font-mono">SITE_DOMAIN</code> in config.php to start tracking.</span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Tracking code -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Add to your website's <code class="bg-gray-100 px-1 rounded text-xs">&lt;head&gt;</code></label>
                <div class="flex items-center gap-2">
                    <code class="flex-1 block bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 text-sm font-mono text-gray-800 overflow-x-auto whitespace-nowrap">
                        <?= htmlspecialchars($trackingCode) ?>
                    </code>
                    <button
                        @click="navigator.clipboard.writeText('<?= htmlspecialchars($trackingCode) ?>').then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="flex-shrink-0 px-3 py-2 border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition text-sm">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" class="text-green-600">Copied!</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Upgrade Banner -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl p-5 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <p class="text-white font-semibold text-sm">You're using Mini Metrics Light</p>
                <p class="text-blue-100 text-xs mt-0.5">Upgrade for multiple sites, date filtering, CSV export, and 18-month history — one-time payment of €19.</p>
            </div>
            <a href="https://minimetrics.io" target="_blank" rel="noopener" class="flex-shrink-0 bg-white text-blue-700 text-sm font-semibold px-5 py-2 rounded-lg hover:bg-blue-50 transition">
                Upgrade →
            </a>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500 mb-1">Total Pageviews</p>
                <p class="text-3xl font-bold text-gray-900"><?= number_format($stats['total_pageviews']) ?></p>
                <p class="text-xs text-gray-400 mt-1">All time</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500 mb-1">Unique Visitors</p>
                <p class="text-3xl font-bold text-gray-900"><?= number_format($stats['unique_visitors']) ?></p>
                <p class="text-xs text-gray-400 mt-1">All time</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <p class="text-sm font-medium text-gray-500 mb-1">Today's Pageviews</p>
                <p class="text-3xl font-bold text-gray-900"><?= number_format($stats['today_pageviews']) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= date('F j, Y') ?></p>
            </div>
        </div>

        <!-- Chart -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Pageviews — Last 30 Days</h2>
            <?php if (count($stats['daily_pageviews']) > 0): ?>
                <div class="relative h-56">
                    <canvas id="pageviewChart"></canvas>
                </div>
            <?php else: ?>
                <p class="text-gray-500 text-center py-8">No data yet</p>
            <?php endif; ?>
        </div>

        <!-- Top Pages + Top Referrers -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Top Pages <span class="text-sm font-normal text-gray-400">(last 30 days)</span></h2>
                <?php if (count($stats['top_pages']) > 0): ?>
                    <div class="space-y-1">
                        <?php foreach ($stats['top_pages'] as $page): ?>
                            <div class="flex justify-between items-center py-2.5 border-b border-gray-100 last:border-0">
                                <span class="text-sm text-gray-700 font-mono truncate flex-1"><?= htmlspecialchars($page['page_path']) ?></span>
                                <span class="text-sm font-semibold text-gray-900 ml-4 bg-gray-100 px-2 py-1 rounded"><?= number_format($page['views']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No data yet</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Top Referrers <span class="text-sm font-normal text-gray-400">(last 30 days)</span></h2>
                <?php if (count($stats['top_referrers']) > 0): ?>
                    <div class="space-y-1">
                        <?php foreach ($stats['top_referrers'] as $referrer): ?>
                            <div class="flex justify-between items-center py-2.5 border-b border-gray-100 last:border-0">
                                <span class="text-sm text-gray-700 font-mono truncate flex-1"><?= htmlspecialchars($referrer['referrer']) ?></span>
                                <span class="text-sm font-semibold text-gray-900 ml-4 bg-gray-100 px-2 py-1 rounded"><?= number_format($referrer['views']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No referrer data yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Countries + Top Cities -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Top Countries <span class="text-sm font-normal text-gray-400">(last 30 days)</span></h2>
                <?php if (count($stats['top_countries']) > 0): ?>
                    <div class="space-y-1">
                        <?php foreach ($stats['top_countries'] as $country): ?>
                            <div class="flex justify-between items-center py-2.5 border-b border-gray-100 last:border-0">
                                <span class="text-sm text-gray-700"><?= htmlspecialchars($country['country'] ?: 'Unknown') ?></span>
                                <span class="text-sm font-semibold text-gray-900 bg-gray-100 px-2 py-1 rounded"><?= number_format($country['views']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No data yet</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Top Cities <span class="text-sm font-normal text-gray-400">(last 30 days)</span></h2>
                <?php if (count($stats['top_cities']) > 0): ?>
                    <div class="space-y-1">
                        <?php foreach ($stats['top_cities'] as $city): ?>
                            <div class="flex justify-between items-center py-2.5 border-b border-gray-100 last:border-0">
                                <span class="text-sm text-gray-700">
                                    <?= htmlspecialchars($city['city']) ?>
                                    <?php if ($city['region']): ?>
                                        <span class="text-gray-500">, <?= htmlspecialchars($city['region']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($city['country']): ?>
                                        <span class="text-gray-400 text-xs ml-1">(<?= htmlspecialchars($city['country']) ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <span class="text-sm font-semibold text-gray-900 ml-4 bg-gray-100 px-2 py-1 rounded"><?= number_format($city['views']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No data yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Activity</h2>
                <button @click="showRecent = !showRecent" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                    <span x-show="!showRecent">Show</span>
                    <span x-show="showRecent">Hide</span>
                </button>
            </div>
            <div x-show="showRecent" class="space-y-1">
                <?php if (count($stats['recent_activity']) > 0): ?>
                    <?php foreach ($stats['recent_activity'] as $activity): ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-100 text-sm last:border-0">
                            <span class="text-gray-700 truncate flex-1 font-mono text-xs"><?= htmlspecialchars($activity['page_path']) ?></span>
                            <span class="text-gray-500 mx-3 text-xs whitespace-nowrap">
                                <?php if ($activity['city']): ?>
                                    <?= htmlspecialchars($activity['city']) ?>
                                    <?php if ($activity['country']): ?>
                                        <span class="text-gray-400">(<?= htmlspecialchars($activity['country']) ?>)</span>
                                    <?php endif; ?>
                                <?php elseif ($activity['country']): ?>
                                    <?= htmlspecialchars($activity['country']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                            <span class="text-gray-400 text-xs whitespace-nowrap"><?= date('M d, H:i', strtotime($activity['created_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No activity yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-xs text-gray-400 pb-4">
            Mini Metrics Light &mdash; <a href="https://minimetrics.io" target="_blank" rel="noopener" class="hover:text-blue-500 underline">Upgrade to full version</a> for €19 one-time
        </p>
    </div>
</div>

<?php if (count($stats['daily_pageviews']) > 0): ?>
<script>
    const ctx = document.getElementById('pageviewChart').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 224);
    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
    gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($stats['daily_pageviews'], 'date')) ?>,
            datasets: [{
                label: 'Pageviews',
                data: <?= json_encode(array_column($stats['daily_pageviews'], 'views')) ?>,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: gradient,
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: 'rgb(59, 130, 246)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
</script>
<?php endif; ?>

</body>
</html>
