# CLAUDE.MD

This file provides context and guidelines for Claude when working on this project.

## Project Overview

**Rediscover This Day** is a nostalgic photo slideshow application that displays photos from previous years taken on the current calendar date. It integrates with Synology Photos to automatically pull and display your memories from past years.

**Key Features:**
- Date-based photo discovery (shows photos taken on today's date from previous years)
- Auto-playing slideshow with configurable transitions
- Legacy browser support (works on iPad 2 with Safari iOS 9.3.5)
- Web-based admin interface for configuration
- Daily automated photo refresh via cron
- Local image caching for performance

**Target Deployment:** Synology NAS with Web Station and Synology Photos package

## Project Structure

```
rediscover-this-day/
├── index.php              # Main slideshow display page
├── common.php             # Shared utility functions (config, logging, auth)
├── composer.json          # PHP dependency management (Guzzle HTTP)
├── .htaccess              # Apache rewrite rules
│
├── admin/                 # Admin control panel (session-protected)
│   ├── index.php          # Main admin dashboard
│   ├── login.php          # Authentication form
│   └── logout.php         # Session terminator
│
├── api/                   # Synology API integration
│   ├── synology.php       # SynologyAPI class - API wrapper
│   └── photos.php         # JSON endpoint for photo polling
│
├── cache/                 # Cached images and metadata (writable, public)
│   ├── photos.json        # Cached photo metadata
│   ├── .htaccess          # Allow public access to cached images
│   └── *.jpg              # Cached photo files
│
├── config/                # Configuration management (writable, protected)
│   ├── config.json        # Active configuration (created on install)
│   ├── config.default.json # Default configuration template
│   └── .htaccess          # Protect config from web access
│
├── cron/                  # Scheduled task scripts
│   └── daily_refresh.php  # Fetches & caches photos for today's date
│
├── css/                   # Stylesheets
│   ├── slideshow.css      # Slideshow appearance and animations
│   └── admin.css          # Admin interface styling
│
├── js/                    # Client-side JavaScript
│   └── slideshow.js       # Slideshow logic (ES5, iPad 2 compatible)
│
├── logs/                  # Application logs (protected, writable)
│   ├── app.log            # Main application log
│   └── .htaccess          # Protect logs from web access
│
└── vendor/                # Composer dependencies (Guzzle)
```

## Technology Stack

**Backend:**
- PHP 7.0+ (server-side logic)
- Composer for dependency management
- Guzzle HTTP Client (v7.10) for Synology API requests
- Session-based authentication

**Frontend:**
- Vanilla JavaScript (ES5-compatible for iPad 2)
- CSS3 with fade transitions
- HTML5 with mobile meta tags
- NO frameworks or transpilers (legacy compatibility)

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
- Protect sensitive directories with `.htaccess` files

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
logMessage('INFO', 'Starting photo refresh');
logMessage('ERROR', 'Failed to connect to Synology', ['url' => $url]);
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
- DO NOT use external JavaScript libraries or frameworks
- DO NOT create new configuration options without updating `config.default.json`
- DO NOT bypass admin authentication checks
- DO NOT output HTML without using `h()` function
- DO NOT use CSS frameworks (Bootstrap, Tailwind, etc.)
- DO NOT commit `config/config.json` to git (contains credentials)
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
4. Review `logs/app.log` for errors

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
- **PHP 7.0+** web server (Apache or Nginx)
- **Task Scheduler** or cron for automated daily refresh

## Common Tasks

### Update Configuration

1. Admin interface: `/admin/` → Update fields → Save Configuration
2. Or manually edit `config/config.json` (restart not required)

### Manually Refresh Photos

**Via Admin Interface:**
1. Go to `/admin/`
2. Scroll to Status section
3. Click "Refresh Now" button

**Via Command Line:**
```bash
php /volume1/web/rediscover-this-day/cron/daily_refresh.php
```

### Check Logs

```bash
tail -f /volume1/web/rediscover-this-day/logs/app.log
```

### Test Synology API Connection

```bash
php /volume1/web/rediscover-this-day/test_api.php
```

### Reset Admin Password

Edit `config/config.json` and set:
```json
"admin": {
  "username": "admin",
  "password_hash": "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"
}
```
(This resets password to: `password`)

### Clear Cache

```bash
rm -f cache/photos.json cache/*.jpg
```

Then trigger a manual refresh.

### Update Dependencies

```bash
composer update
```

## File Permissions Requirements

```bash
# Application directory
chmod 755 /volume1/web/rediscover-this-day

# Writable directories (required)
chmod 777 config/
chmod 777 cache/
chmod 777 logs/

# Configuration file (after creation)
chmod 666 config/config.json
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
- **Daily refresh only**: No real-time photo updates (by design)
- **No mobile admin optimization**: Admin interface is functional but not mobile-first

### Recent Changes (Git History)

- **f68865f**: Separated county and state into their own rows (location metadata)
- **ca42ccc**: Changed `const` to `var` for legacy iPad 2 compatibility
- **fb53cd6**: Separated CSS files (slideshow.css and admin.css)
- **2422ace**: Added more location metadata to slideshow display
- **d0aa36b**: Improved BASE_PATH handling in common.php

### Important Context

**Why iPad 2 compatibility?**
The original device this was designed for is an iPad 2 running iOS 9.3.5 (last supported version). This device cannot run modern browsers, so ES5 JavaScript and basic CSS are required.

**Why PHP instead of Python?**
Synology NAS has PHP support built-in via Web Station, making deployment simpler than setting up Python.

**Why local caching?**
- Reduces API calls to Synology
- Improves slideshow loading performance on iPad 2
- Allows offline viewing after initial cache
- Simplifies photo display logic

**Why daily refresh?**
Photos for "this day" only change once per day (at midnight), so real-time updates aren't necessary. This reduces server load and complexity.

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
- **API Reference**: Synology Photos API documentation in [Documentation/](Documentation/)

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
