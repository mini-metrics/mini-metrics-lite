# Changelog

Mini Metrics Lite uses the same version numbers as the full version of Mini Metrics.

## 1.1.0 (unreleased)

The first numbered release of Lite: a rewrite of the dashboard and tracking.

- New dashboard: choose Today, Last 7 days or Last 30 days. Page views, visitors and your busiest hour or day,
  with a chart per hour (today) or per day. The numbers now cover the period you chose instead of all time.
- Referrers are grouped by site ("news.ycombinator.com" instead of every single link). Each list shows a bar for its share.
- Days and times follow `TIMEZONE` everywhere (before, some used UTC).
- No more third-party code: Tailwind, Alpine.js and Chart.js are no longer loaded from CDNs. The dashboard is
  one small stylesheet (`lite.css`) and a server-drawn chart, and works offline from the internet.
- Bots, crawlers, uptime monitors and scripts are no longer counted.
- `/about/` and `/about?x=1` now count as the page `/about`.
- More reliable tracking: page views are saved before the location lookup, which now happens after the
  response is sent, so a slow location service can't delay your site or lose a page view. Uses `sendBeacon`
  as a simple request (no extra `OPTIONS` request), waits for a busy database instead of failing, counts
  back/forward navigation, and doesn't count pages the browser only loads ahead of time.
- Locations now come from GeoJS (get.geojs.io) and are cached in the database instead of a `cache/` folder.
- Privacy: visitor hashes now use a random secret per installation, so they can't be turned back into IP
  addresses. The old `cache/` folder and `login_attempts.json` (which held IP addresses) are deleted
  automatically.
- Security:
  - You can't log in with the default password `changeme`; set your own in `config.php`.
  - The login limit (5 wrong passwords per 15 minutes) can no longer be bypassed with forged
    `X-Forwarded-For` or `CF-Connecting-IP` headers, and is stored in the database with hashed IPs.
  - Fresh session after login, a one-time token on the login form, constant-time password check, and failed
    logins in the server's error log for fail2ban.
  - Tracking no longer shows database errors to visitors, and limits the length of what it stores.
  - The dashboard can't be put in a frame on another site.
- Fixed: login didn't work over plain HTTP, and logging in or out jumped to the site root when Lite is
  installed in a subfolder.
- Fixed: links pointed to the wrong website (minimetrics.io instead of mini-metrics.com).
- Added the `LICENSE` file (MIT).

**Upgrading from the earlier Lite:** upload all files and keep your `config.php`. The new files `lite.php` and
`lite.css` must be there too. You'll need to log in again once. If your password is still `changeme`, set
your own first. Because bots are no longer counted, your numbers may be a little lower than before, and
on the day you upgrade, returning visitors may be counted twice.
