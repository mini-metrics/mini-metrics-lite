# Mini Metrics Lite

Free, self-hosted web analytics for one website. Page views, visitors, top pages, referrers, countries and
cities, on a dashboard that loads instantly. No cookies, no cookie banner, nothing sent to Google.

It's a few plain PHP files and one SQLite database. No Composer, no Node, no Docker, no build step:
it runs on a €3 shared hosting plan.

**[See the dashboard in action →](https://mini-metrics.com/demo)** (the demo shows the full version)

## What you get

- Page views and visitors for today, the last 7 days or the last 30 days
- A chart per hour (today) or per day, and your busiest hour or day
- Top pages, referrers (grouped by site), countries and cities
- Recent page views
- Privacy first: no cookies, nothing stored in the visitor's browser, IP addresses are never stored
  (only a hash that changes every day and can't be turned back into an address)
- Bots, crawlers and uptime monitors are not counted
- Works with single-page apps (React, Vue, Svelte…), and with back/forward navigation
- A tracking script under 2 KB that never slows down or breaks your site
- No third-party code: no CDNs, no JavaScript libraries, no web fonts

Lite tracks one site. Need more sites, any date range, comparisons with the previous period, filters or
CSV export? That's [Mini Metrics](https://mini-metrics.com/#pricing), €19 once, and your data comes along
(see [Moving to the full version](#moving-to-the-full-version)).

## Requirements

- PHP 7.4 or newer with the PDO SQLite extension (most hosts have it switched on)
- Apache or Nginx (or any web server that runs PHP)
- HTTPS recommended

## Install

1. **Upload the files** to their own (sub)domain or folder, for example `analytics.example.com`:
   `index.php`, `lite.php`, `track.php`, `track.js`, `lite.css`, `favicon.png` and `config.php`.
2. **Edit `config.php`:**
   - `DASHBOARD_PASSWORD`: your own password (you can't log in with `changeme`)
   - `SITE_DOMAIN`: your website, for example `example.com` (no `https://`)
   - `DB_PATH`: where the database goes. The default is the folder *above* your web root, so nobody can
     download it. PHP must be able to write there.
   - `TIMEZONE`: your time zone, for days and times in the dashboard
3. **Open the dashboard** (for example `https://analytics.example.com/`) and log in.
4. **Add the tracking code** from the dashboard's setup steps to the `<head>` of every page:
   ```html
   <script src="https://analytics.example.com/track.js" defer></script>
   ```
5. Visit your site. The page view shows up on the dashboard right away.

## Settings (`config.php`)

| Setting | Default | What it does |
|---|---|---|
| `DASHBOARD_USERNAME` | `admin` | Login name |
| `DASHBOARD_PASSWORD` | `changeme` | Login password; you can't log in until you change it |
| `SITE_DOMAIN` | empty | Your site; page views from other sites are refused. `www.` is ignored. While empty, every site is accepted |
| `DB_PATH` | folder above the web root | The SQLite database file |
| `DATA_RETENTION_MONTHS` | `6` | Page views older than this are deleted |
| `TIMEZONE` | `Europe/Stockholm` | Time zone for the dashboard |

## Upgrading

Replace every file except `config.php`, then open the dashboard once. Your settings and data stay. See
[CHANGELOG.md](CHANGELOG.md) for what changed and any notes per version.

## Moving to the full version

Lite and [Mini Metrics](https://mini-metrics.com) store page views the same way, so your history comes along:

1. Install Mini Metrics (its own `config.php`: set your password and add your domain to `ALLOWED_DOMAINS`).
2. Point its `DB_PATH` at your existing `analytics.db`, or copy the file there.
3. Swap the tracking code on your site for the new one, if the address changed.

## Security

- Login is limited to 5 wrong passwords per 15 minutes per IP address, and failed logins go to the server's
  error log as `Mini Metrics Lite: failed login from <IP>` (ready for fail2ban).
- Keep `analytics.db` outside the web root. On Apache, Lite also adds an `.htaccess` next to the database that
  blocks downloads, just in case.
- Found a security problem? Email support@mini-metrics.com instead of opening a public issue.

## Troubleshooting

- **No page views show up:** check that `SITE_DOMAIN` matches your site, and look at the browser's network
  tab for the request to `track.php` (it answers `204` when it worked, `403` when the domain doesn't match).
- **No countries or cities:** the location lookup needs outgoing HTTPS from your server to `get.geojs.io`.
- **"The database can't be opened":** make the folder in `DB_PATH` writable for PHP, and check that PDO
  SQLite is enabled (`php -m | grep -i sqlite`).
- **Login keeps failing over plain HTTP:** this is fixed in 1.1.0; upgrade, or use HTTPS.

## License

MIT, see [LICENSE](LICENSE).
