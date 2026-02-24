# Mini Metrics Light

Mini Metrics Light is a self-hosted web analytics tool that gives you the essentials: pageviews, unique visitors, top pages, referrers, and geo data without tracking cookies, fingerprinting, or sending visitor data to third parties. Install it on your own server, point it at your site, and you own everything.
No SaaS subscription. No GDPR headaches from third-party scripts. Just a single PHP file, a SQLite database, and a small JavaScript snippet.

> **Light version limitations:**
> - One site only
> - No date filtering
> - No CSV export
> - 6-month data retention
>
> **[Upgrade to the full version for €19 →](https://minimetrics.io)**
> Full version adds: multiple sites, date filtering, CSV export, 18-month retention.

## Requirements

- PHP 7.4+ with PDO SQLite extension
- Web server (Apache/Nginx)
- HTTPS recommended

## Installation

1. **Upload files** to your web server (e.g., `/var/www/analytics.example.com/`)

2. **Edit `config.php`**
   - Change `DASHBOARD_PASSWORD`
   - Set `SITE_DOMAIN` to your site (e.g., `example.com`)
   - Verify `DB_PATH` points outside webroot

3. **Set permissions**
```bash
# Database directory must be writable
chmod 755 /var/www  # or parent of webroot
```

4. **Add tracking code** to your website's `<head>`
```html
<script src="https://analytics.example.com/track.js" defer></script>
```

5. **Access dashboard**
   - Visit `https://analytics.example.com/`
   - Login with credentials from `config.php`

## Files

- `track.js` — Client-side tracking script
- `track.php` — API endpoint
- `index.php` — Dashboard
- `config.php` — Configuration

## Features

- Privacy-first (hashed IPs, no cookies)
- SPA-compatible (tracks history changes)
- GeoIP tracking with caching
- External referrer tracking only
- 6-month data retention

## Troubleshooting

- Enable debug mode: set `$DEBUG = true;` in `track.php`
- Check PHP error logs
- Verify database directory permissions

## License

MIT
