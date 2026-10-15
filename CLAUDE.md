# CLAUDE.MD

This file provides context and guidelines for Claude when working on this project.

## Project Overview

**Rediscover This Day** is a nostalgic photo slideshow application that displays photos from previous years taken on the current calendar date. It integrates with Synology Photos to automatically pull and display your memories from past years.

**Key Features:**
- Date-based photo discovery (shows photos taken on today's date from previous years)
- Year in Review: pick a year, then step through a year summary, per-month summaries, and per-location photo grids ("Places Explored")
- Auto-playing slideshow with configurable transitions
- Legacy browser support (works on iPad 2 with Safari iOS 9.3.5)
- Web-based admin interface for configuration
- Daily automated photo refresh via cron
- Local image caching for performance

**Target Deployment:** Synology NAS with Web Station and Synology Photos package

## Project Structure

```
rediscover-this-day/
├── index.php              # Main "This Day" slideshow display page
├── year-review.php        # Year in Review: year picker grid
├── year-slideshow.php     # Year in Review: slideshow for ?year=YYYY
├── common.php             # Shared utility functions (config, logging, auth)
├── test_api.php           # CLI-only smoke test for the Synology API (404 over the web)
├── composer.json          # PHP dependency management (Guzzle HTTP)
├── .htaccess              # Apache rules (ignored: the NAS serves this site with Nginx)
│
├── admin/                 # Admin control panel (session-protected)
│   ├── index.php          # Main admin dashboard
│   ├── login.php          # Authentication form
│   └── logout.php         # Session terminator
│
├── api/                   # Synology API integration
│   ├── synology.php       # SynologyAPI class - API wrapper
│   ├── photos.php         # JSON endpoint for photo polling
│   ├── year_photos.php    # JSON endpoint: Year in Review slide list (cached per year)
│   ├── year_thumb.php     # Image endpoint: Year in Review thumbnail, fetched on demand
│   └── year_review_lib.php # Year in Review library (functions only, no output)
│
├── cache/                 # Cached images and metadata (writable, public)
│   ├── photos.json        # Cached photo metadata
│   ├── .htaccess          # Allow public access to cached images
│   ├── *.jpg              # Cached photo files
│   └── year_review/       # Year in Review: {year}.cache.php (guarded slide list) + thumbnails {year}_{id}_{xl|m}.jpg
│
├── config/                # Configuration management (writable, protected)
│   ├── config.php         # Active configuration: 404 guard line + JSON (auto-migrated from config.json)
│   ├── config.default.json # Default configuration template (seed)
│   ├── synology_session.php # Shared Synology sid for api/year_thumb.php (guarded, gitignored, live credential)
│   └── .htaccess          # Apache only; Nginx deny rules protect config/ on the NAS
│
├── cron/                  # Scheduled task scripts
│   └── daily_refresh.php  # Fetches & caches photos for today's date (CLI only; 404 over the web)
│
├── css/                   # Stylesheets
│   ├── slideshow.css      # Slideshow styles (shared by index.php AND year-slideshow.php)
│   ├── year-review.css    # Year picker page
│   ├── year-slideshow.css # Year in Review slideshow (loaded after slideshow.css)
│   ├── lib/               # justifiedGallery 3.8.1 CSS (self-hosted, byte-identical to upstream)
│   └── admin.css          # Admin interface styling
│
├── js/                    # Client-side JavaScript
│   ├── slideshow.js       # This Day slideshow logic (ES5, iPad 2 compatible)
│   ├── year-slideshow.js  # Year in Review slideshow logic (ES5)
│   └── lib/               # jQuery 3.7.1 + justifiedGallery 3.8.1 (self-hosted, byte-identical) + THIRD_PARTY_LICENSES.txt
│
├── logs/                  # Application logs (protected, writable)
│   ├── app.log.php        # Main application log (first line is a 404 guard)
│   └── .htaccess          # Apache only; Nginx deny rules protect logs/ on the NAS
│
└── vendor/                # Composer dependencies (Guzzle) — committed to git, NAS doesn't run Composer
```

## Technology Stack

**Backend:**
- PHP 8.2 on the NAS (locked dependencies require ≥ 8.1; avoid 8.3+ features)
- Composer for dependency management
- Guzzle HTTP Client (v7.10) for Synology API requests
- Session-based authentication

**Frontend:**
- Vanilla JavaScript (ES5-compatible for iPad 2)
- CSS3 with fade transitions
- HTML5 with mobile meta tags
- NO frameworks or transpilers (legacy compatibility)
- Exception (provisional): jQuery 3.7.1 + justifiedGallery 3.8.1, self-hosted in `js/lib/` and `css/lib/`, used by `year-slideshow.php` for the Year in Review location grid only. Still unverified on the iPad 2.

**APIs:**
- Synology Photos API (SYNO.Foto.Browse.Item, SYNO.Foto.Thumbnail)
- Synology Auth API (SYNO.API.Auth)

## Development Guidelines

### Code Style

**PHP:**
- Use clear, descriptive function names
- Follow existing patterns in `common.php`
- Always use `h()` function for HTML output escaping
- Use `logMessage()` for logging with appropriate levels (ERROR, WARNING, INFO, DEBUG)
- Keep functions focused and single-purpose

**JavaScript:**
- MUST be ES5-compatible (no arrow functions, const/let, template literals)
- Use `var` for all variable declarations
- Avoid modern APIs not supported by Safari iOS 9.3.5
- Test on iPad 2 when possible, or verify ES5 compatibility
- Use XMLHttpRequest (not fetch API)

**CSS:**
- Use simple, well-supported properties
- Avoid flexbox/grid if possible (use floats or inline-block)
- Test layout on iPad 2 resolution (1024x768)
- Keep animations simple (fade transitions only)

### Best Practices

**Security:**
- ALWAYS use `h()` function for HTML output to prevent XSS
- NEVER skip `requireAdmin()` checks for admin pages
- Use `password_hash()` and `password_verify()` for password handling
- Configuration file contains plain-text Synology password - file permissions are critical
- Sensitive data files are guarded `.php` files (`config/config.php`, `logs/app.log.php`) whose first line returns an empty 404; the NAS's Nginx deny rules (see `INSTALL.md`) are the second layer. `.htaccess` is ignored under Nginx
- Admin POST forms need `csrfField()` and the handler must check `verifyCsrf()`
- Never log Synology `_sid`/passwords; `logMessage()` runs everything through `redactSecrets()`

**Error Handling:**
- Use `logMessage()` for all errors, warnings, and important events
- Handle Synology API errors gracefully (network failures, authentication errors)
- Return to previous day's photos if refresh fails
- Display user-friendly error messages in admin interface

**Performance:**
- Use thumbnail images (not full resolution) for slideshow
- Cache images locally to reduce API calls
- Preload next image during current photo display
- Keep cache directory clean (overwrite daily)

**Compatibility:**
- Test all JavaScript changes for ES5 compatibility
- Avoid modern CSS features (CSS variables, modern selectors)
- Use simple HTML5 tags only
- Consider iPad 2 Safari rendering limitations

### Common Patterns to Follow

**Loading Configuration:**
```php
require_once __DIR__ . '/common.php';
$config = loadConfig();
```

**Logging:**
```php
// Signature: logMessage($message, $level = 'INFO') — message first, no context array
logMessage('Starting photo refresh', 'INFO');
logMessage('Failed to connect to Synology: ' . $url, 'ERROR');
```

**HTML Output:**
```php
echo '<div class="message">' . h($userInput) . '</div>';
```

**Admin Protection:**
```php
requireAdmin(); // At top of admin pages
```

**API Instantiation:**
```php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/synology.php';

$api = new SynologyAPI($config['synology']['url'], $config['synology']['username'], $config['synology']['password']);
```

### Things to Avoid

- DO NOT use modern JavaScript (ES6+) features
- DO NOT use external JavaScript libraries or frameworks (beyond the provisional jQuery 3.7.1 + justifiedGallery 3.8.1 exception, self-hosted in `js/lib/` and `css/lib/`, Year in Review grid only; still unverified on the iPad 2)
- DO NOT create new configuration options without updating `config.default.json`
- DO NOT bypass admin authentication checks
- DO NOT output HTML without using `h()` function
- DO NOT use CSS frameworks (Bootstrap, Tailwind, etc.)
- DO NOT commit `config/config.php` (or a legacy `config/config.json`) to git (contains credentials)
- DO NOT commit cached images or logs to git
- DO NOT use `const` or `let` in JavaScript (use `var`)
- DO NOT use template literals in JavaScript (use string concatenation)
- DO NOT use arrow functions in JavaScript (use `function` keyword)

## Testing

### Manual Testing

**Admin Interface:**
1. Test login with valid/invalid credentials
2. Test connection to Synology (Test Connection button)
3. Update configuration and verify changes persist
4. Change admin password and verify new password works
5. Manually trigger refresh and check for errors

**Slideshow:**
1. Test on iPad 2 Safari (primary target)
2. Verify auto-play starts on page load
3. Test manual controls (prev/next/play/pause)
4. Test keyboard shortcuts (arrows, spacebar)
5. Verify metadata displays correctly (year, location)
6. Check fade transitions work smoothly

**API Integration:**
1. Use `test_api.php` for API testing
2. Test with valid Synology credentials
3. Test photo retrieval for current date
4. Verify thumbnails download correctly
5. Check error handling for invalid credentials

**Cron Job:**
1. Run `php cron/daily_refresh.php` manually
2. Verify photos are cached in `cache/` directory
3. Check `cache/photos.json` is created/updated
4. Review `logs/app.log.php` for errors

### Edge Cases to Test

- No photos for current date (should show empty state)
- Synology server unreachable (should retain previous photos)
- Very large photo files (should use thumbnails)
- Missing location metadata (should gracefully omit)
- Photos with special characters in filenames
- Years before configured `first_year` (should be excluded)

## Dependencies

### Production Dependencies (composer.json)

- **guzzlehttp/guzzle** (v7.10): HTTP client for Synology API requests
  - Provides PSR-7 HTTP message interfaces
  - Handles authentication, retries, and errors

### PHP Extensions Required

- `curl`: For HTTP requests via Guzzle
- `json`: For JSON encoding/decoding
- `session`: For admin authentication
- `mbstring`: For string handling (recommended)

### External Dependencies

- **Synology NAS** with Photos package installed
- **PHP 8.2** web server (Apache or Nginx) — minimum 8.1 for the locked dependencies
- **Task Scheduler** or cron for automated daily refresh

## Common Tasks

### Update Configuration

1. Admin interface: `/admin/` → Update fields → Save Configuration
2. Or manually edit `config/config.php` (keep its first guard line; restart not required)

### Manually Refresh Photos

**Via Admin Interface:**
1. Go to `/admin/`
2. Scroll to Status section
3. Click "Refresh Now" button

**Via Command Line** (CLI only; there is no web `?run` trigger):
```bash
php /volume2/web/rediscover-this-day/cron/daily_refresh.php
```

### Check Logs

```bash
tail -f /volume2/web/rediscover-this-day/logs/app.log.php
```

The first line of the log is the `<?php ... ?>` guard; `tail -f` works as usual.

### Test Synology API Connection

```bash
php /volume2/web/rediscover-this-day/test_api.php
```

### Reset Admin Password

Edit `config/config.php` (keep the first `<?php ... ?>` guard line) and set:
```json
"admin": {
  "username": "admin",
  "password_hash": "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"
}
```
(This resets password to: `password`)

### Clear Cache

```bash
cd /volume2/web/rediscover-this-day
rm -f cache/photos.json cache/*.jpg
rm -f cache/year_review/*.jpg cache/year_review/*.cache.php
```

Year in Review only: admin → **Clear Year in Review cache** does the same (keeps `.gitignore`/`.htaccess`) and also deletes the shared Synology session `config/synology_session.php`.

(Don't delete `config/config.php` or `logs/app.log.php` here.)

Then trigger a manual refresh.

### Update Dependencies

```bash
composer update
```

`vendor/` is committed to git because the NAS doesn't run Composer. Commit the resulting `vendor/` and `composer.lock` changes along with any `composer update`.

### Deploy to the NAS

Deploys are manual via git:

1. Commit and push to `origin` (github.com/ldbrownjr/rediscover_this_day).
2. On the NAS: `cd /volume2/web/rediscover-this-day && git pull`.

Gitignored runtime files (`config/config.php`, logs, cached images) are left untouched on the NAS. The first page load or cron run after deploying this change migrates an existing `config/config.json` to `config/config.php` and deletes the old file. Right after the pull, follow "Upgrading an Existing Install" in `INSTALL.md` (load the This Day page first, check `ls -la config/ logs/`, delete the old `logs/app.log`, run the refresh once as the Task Scheduler user).

## File Permissions Requirements

```bash
# Application directory
chmod 755 /volume2/web/rediscover-this-day

# Writable directories (required)
chmod 777 config/
chmod 777 cache/
chmod 777 logs/

# Configuration file (after creation; writes are atomic renames, so the
# directory permissions above are what matters)
chmod 666 config/config.php

# logs/app.log.php is created 0666 automatically so both the cron user
# (possibly root) and the web user can append
```

## Configuration Reference

See `config/config.default.json` for complete configuration structure.

**Key Configuration Options:**

- `synology.url`: Synology NAS URL (include port, e.g., `https://192.168.1.100:5001`)
- `synology.username`: Synology account username
- `synology.password`: Synology account password (plain text - secure file permissions!)
- `photos.first_year`: Earliest year to include (default: 2010)
- `slideshow.duration`: Photo display duration in seconds (default: 5)
- `slideshow.transition`: Transition effect (`fade` or `none`)
- `year_review.enable_feature` (default `true`): when off, hides the This Day "Year in Review" button and the admin link, and turns off the Year in Review pages and endpoints (`year-review.php` shows an "off" panel, `year-slideshow.php` redirects, `api/year_photos.php` returns 403, `api/year_thumb.php` 404). The old `photos_per_year`, `selection_strategy` and `stat_frequency` keys are removed on the next admin save.
- `admin.username`: Admin username (default: `admin`)
- `admin.password_hash`: Bcrypt hash of admin password
- `debug.log_level`: Logging level (`DEBUG`, `INFO`, `WARNING`, `ERROR`)

## Notes for Claude

### When Making Changes

1. **Always read files before editing** - Understand existing patterns
2. **Test ES5 compatibility** - No modern JavaScript features
3. **Use existing utility functions** - Don't reinvent `h()`, `logMessage()`, etc.
4. **Follow security practices** - Always escape HTML, check authentication
5. **Update documentation** - If changing features, update README.md
6. **Consider legacy browsers** - iPad 2 Safari is the primary target
7. **Log appropriately** - Use correct log levels for debugging

### Known Limitations

- **Plain-text passwords**: Synology credentials stored unencrypted in config file
- **Single user**: Admin interface supports one admin account only
- **No photo upload**: Application is read-only from Synology Photos
- **Daily refresh only**: No real-time photo updates for This Day (by design)
- **No mobile admin optimization**: Admin interface is functional but not mobile-first

### Recent Changes (Git History)

- **bc0d5ca**: Thumbnail downloads use `unit_id` (required alongside `cache_key`)
- **70caab4**: Fixed Year in Review photo matching
- **04d0dbe**: Added Year in Review feature with location-based statistics
- **c3ab502**: Added photo tags display in lower-left corner
- **f68865f**: Separated county and state into their own rows (location metadata)
- **ca42ccc**: Changed `const` to `var` for legacy iPad 2 compatibility
- **fb53cd6**: Separated CSS files (slideshow.css and admin.css)
- **2422ace**: Added more location metadata to slideshow display
- **d0aa36b**: Improved BASE_PATH handling in common.php

### Important Context

**Why iPad 2 compatibility?**
The original device this was designed for is an iPad 2 running iOS 9.3.5 (last supported version). This device cannot run modern browsers, so ES5 JavaScript and basic CSS are required.

**How the iPad launches the app (Home Screen web app)**
On the iPad, the app was set up by browsing to the URL in Safari and choosing **Add to Home Screen**. The user then launches it from the Home Screen icon, which opens it full screen (standalone mode, enabled by the `apple-mobile-web-app-capable` meta tags in `index.php`, `year-review.php` and `year-slideshow.php`). Implications for changes:
- There's no Safari address bar, back button or reload button. Every page needs its own on-screen way to navigate (e.g. the slideshow's back button), and an error screen needs a link out.
- The saved URL (exact value in the gitignored `CLAUDE.local.md`) is plain HTTP on the NAS's LAN IP, in the `/rediscover-this-day/` subdirectory, and resolves to `index.php` through `DirectoryIndex`. The icon always opens this URL, so it must keep serving the This Day slideshow. Moving the folder, changing the NAS IP or forcing HTTPS means re-adding the icon on the iPad.
- Because the app lives under `/rediscover-this-day/`, keep URLs relative (`css/…`, `year-review.php`, `../index.php`). Root-relative paths (`/css/…`) would break.
- Navigate between pages with `window.location.href` from JS. On older iOS, plain `<a href>` links in a standalone web app can open in Safari instead of staying full screen. Verify any new link from the Home Screen icon.
- New pages meant for the iPad need the same `apple-mobile-web-app-*` meta tags.
- Year in Review is reached from the This Day slideshow's **Year in Review** button (upper-left; it fades in and out with the bottom controls, and a tap while it's hidden only reveals it), or from the button on the "No photos for today" screen. A second Home Screen icon saved at `year-review.php` also works. Navigating from the year picker to `year-slideshow.php` and back must stay full screen (the picker's links navigate via `window.location.href`).
- Test from the Home Screen icon, not just a Safari tab. Behavior and caching differ between the two.

**Why PHP instead of Python?**
Synology NAS has PHP support built-in via Web Station, making deployment simpler than setting up Python.

**Why local caching?**
- Reduces API calls to Synology
- Improves slideshow loading performance on iPad 2
- Allows offline viewing after initial cache
- Simplifies photo display logic

**Why daily refresh?**
Photos for "this day" only change once per day (at midnight), so real-time updates aren't necessary. This reduces server load and complexity.

**How Year in Review works**
`year-review.php` lists years from `photos.first_year` to the current year. A year whose list file exists (even a stale one) gets a cover tile: its year cover at size **m** plus "N Cities · N Photos", read with `readYearCover()` (no Synology calls); years never opened (or with an older-schema list) get a plain tile. `year-slideshow.php?year=YYYY` calls `api/year_photos.php` via XHR. The endpoint:
1. Serves the year's slide list from the guarded `cache/year_review/{year}.cache.php` if it's fresh (past years: built after the year ended, then permanent; current year: built today).
2. Otherwise logs in, fetches every photo for the year, groups them by month and then by city/state/country from `additional.address` (empty parts omitted; photos with no address go to a final "Unknown location" slide), picks up to 9 photos per location, writes the list file atomically and logs out. No thumbnails are downloaded here.
3. Returns `{ year_summary: { total_photos, countries, states, cities, cover }, months: [{ summary: { ..., photos }, location_slides: [{ title, photo_count, photos: [{ id, filename, src, w?, h? }] }] }] }`. `year_summary.cover` is one `{ id, w?, h?, src }` (size xl) or null; `months[].summary.photos` is up to 3 `{ id, w?, h?, src }` (size m) for the month's photo strip. Both are picked deterministically from the photos already selected into slides (landscape 1.3–1.9 photos, median capture time, largest locations first; see `pickYearCover()` / `pickMonthPhotos()`), so they are in the allow-list. Each `src` is the cached `cache/year_review/{year}_{id}_{size}.jpg` if it exists, otherwise `api/year_thumb.php?year=Y&id=N` (plus `&size=m` for m). The list file is schema 3; older-schema files are rebuilt on first open. Photo `w`/`h` are `additional.resolution` as-is: Synology already reports it display-oriented, so it must not be swapped for EXIF `orientation` 5–8 (schema 2 did, which made portrait photos look landscape).

`api/year_thumb.php` (`?year=Y&id=N[&size=m]`, size `xl` by default, anything outside `YEAR_THUMB_SIZES` is a 404) serves a cached thumbnail, or looks the id up in the year's list file (only photos selected into slides are allowed), fetches it from Synology with a **shared sid** (`config/synology_session.php`, guarded, never logged, never logged out by design), saves it atomically and streams it. The shared sid is the one exception to "login() then logout()". Synology's thumbnail API answers an expired or invalid sid with a plain HTML 404, the same as for a deleted photo, so on any failed fetch the endpoint re-logs in once (under `config/.synology_session.lock`, so concurrent requests share one login) and retries, but only if the stored sid is at least 5 minutes old (`SHARED_SID_RETRY_MIN_AGE`); a younger sid gets a 502 straight away. Synology codes 106/107/119, if they ever appear, always re-log in. A deleted photo therefore costs at most one login per 5 minutes. A request makes at most 2 logins, and a fresh login that can't be saved (e.g. `config/` not writable) is logged out at the end of that request and not retried.

`js/year-slideshow.js` then plays the slides in order: the year summary (full-bleed xl cover, released when you leave the slide), then each month's summary (photo strip, title, stats line and a two-column place list of fixed 24px rows, truncated with "+ N more" and re-fitted on rotation) followed by that month's location grids (size m: Synology's m is 320px on the short edge, already larger than a ~165px tile, and xl grids ran the iPad 2 out of memory), preloading the next location slide's photos and the next month's strip photos. Styling is editorial dark (Avenir Next Regular/Medium/DemiBold, amber accent) in `css/year-slideshow.css` and `css/year-review.css`. Slides are pinned to the top (the year summary text sits top-left over the cover). Location grids are laid out by justifiedGallery with `rowHeight` 165 / `maxRowHeight` 175 so 9 photos fit on one 1024×768 screen; if a grid still overflows (e.g. a panorama, or 16:9 photos in portrait), it is re-laid out at 85% row height, up to 3 times. The nightly cron deletes thumbnails no list file references, at any size (older than an hour); the admin **Clear Year in Review cache** button deletes all list files and thumbnails.

### Architecture Decisions

**Session-based authentication** (not JWT):
- Simpler implementation for single-user admin interface
- Adequate security for local network deployment
- Works without external dependencies

**JSON file storage** (not SQLite):
- Simpler to debug and inspect
- Adequate for small data size (one day's photos)
- No database setup required

**Guzzle HTTP client** (not curl directly):
- Better error handling and retry logic
- PSR-7 compliant (industry standard)
- Easier to work with complex API calls

**Thumbnail images** (not full resolution):
- Faster loading on iPad 2
- Reduced bandwidth and storage
- Still sufficient quality for slideshow display

## Support & Documentation

- **Main README**: [README.md](README.md) - User-facing documentation
- **Requirements**: [rediscover_requirements.md](rediscover_requirements.md) - Original project specification
- **Installation**: See README.md Installation section
- **Configuration**: [CONFIG.md](CONFIG.md) · **Install**: [INSTALL.md](INSTALL.md) · **Test checklist**: [TESTING.md](TESTING.md)
- **API Reference**: [Synology_File_Station_API_Guide.pdf](Synology_File_Station_API_Guide.pdf); the Photos API wrapper is based on github.com/zeichensatz/SynologyPhotosAPI
- **Dev team pipeline**: [.claude/CLAUDE.md](.claude/CLAUDE.md)

## Future Enhancement Ideas (Not Implemented)

- Multiple admin users with roles
- Weekly/monthly "rediscover" modes
- Photo captions from EXIF description
- Shareable slideshow URLs
- Customizable themes
- Weather data from photo date
- Music accompaniment
- Mobile-optimized admin interface
- Real-time photo polling (WebSocket updates)

When implementing new features, consider maintaining backward compatibility with iPad 2 and keeping the application simple and focused.
