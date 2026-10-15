# Rediscover This Day - Testing Checklist

Use this checklist to verify the application is working correctly.

## Pre-Installation Tests

- [ ] PHP version is 7.0 or higher
  ```bash
  php -v
  ```
- [ ] Required PHP extensions are installed
  ```bash
  php -m | grep -E "curl|json|session"
  ```
- [ ] Synology Photos is installed and accessible
- [ ] Have Synology account credentials ready

## Installation Tests

- [ ] Files uploaded to web directory
- [ ] Directory permissions set correctly (777 for config, cache, logs)
- [ ] `config/config.php` created from default on first page load (or an existing
  `config/config.json` migrated to it and deleted)
- [ ] Upgrade only: right after `git pull`, follow "Upgrading an Existing Install"
  in `INSTALL.md`: load `admin/` in a browser first (the web user does the
  migration), check `ls -la config/ logs/`, delete the old `logs/app.log`
  (it contains historical session IDs), then run the refresh task once as the
  Task Scheduler user and confirm admin still loads and saves
- [ ] Can access main page: `http://NAS-IP/rediscover-this-day/`
- [ ] Can access admin login: `http://NAS-IP/rediscover-this-day/admin/`

## Admin Interface Tests

### Login

- [ ] Can login with default credentials (admin/password)
- [ ] Invalid credentials show error message (after a ~1 second delay)
- [ ] Submitting a login form left open across a session reset shows "Session expired, please try again"
- [ ] Successful login redirects to admin dashboard

### Synology Connection

- [ ] Can enter Synology URL
- [ ] Can enter username and password
- [ ] "Save Configuration" works
- [ ] "Test Connection" succeeds with valid credentials
- [ ] "Test Connection" fails with invalid credentials
- [ ] Error messages are clear and helpful

### Photo Settings

- [ ] Can set first year
- [ ] Validation prevents invalid years
- [ ] Settings persist after save

### Slideshow Settings

- [ ] Can change photo duration
- [ ] Can change transition effect
- [ ] Can change refresh time
- [ ] Settings persist after save

### Admin Security

- [ ] Can change admin password
- [ ] Old password is verified
- [ ] New password confirmation works
- [ ] Password mismatch shows error
- [ ] Can login with new password after change

### Status and Refresh

- [ ] Status shows "no photos cached" initially
- [ ] "Refresh Now" button works
- [ ] Status updates after refresh
- [ ] Photo count is accurate
- [ ] Last refresh timestamp is correct
- [ ] Log file shows refresh activity

### Navigation

- [ ] "View Slideshow" link opens slideshow in new tab
- [ ] "Logout" button works and redirects to login

## Slideshow Tests

### Basic Functionality

- [ ] Slideshow page loads without errors
- [ ] Shows "No photos for today" if cache is empty
- [ ] Displays first photo when photos are available
- [ ] Photos transition automatically
- [ ] Transition respects configured duration
- [ ] Slideshow loops after last photo

### Photo Display

- [ ] Photos scale to fit screen
- [ ] Photos maintain aspect ratio
- [ ] Year is displayed correctly
- [ ] Metadata overlay is visible
- [ ] Photo counter shows correct numbers

### Controls

- [ ] Controls are hidden on page load
- [ ] Controls appear on mouse movement
- [ ] Controls appear on screen touch
- [ ] Controls auto-hide after 3 seconds
- [ ] Previous button works
- [ ] Next button works
- [ ] Play/Pause button works
- [ ] Counter updates correctly

### Transitions

- [ ] Fade transition is smooth (if selected)
- [ ] Instant transition works (if selected)
- [ ] No flickering or glitches

### Browser Compatibility

- [ ] Works in Safari on iPad 2 (iOS 9.3.5)
- [ ] Works in modern Safari
- [ ] Works in Chrome
- [ ] Works in Firefox
- [ ] Works on mobile devices

### Keyboard Controls

- [ ] Left arrow shows previous photo
- [ ] Right arrow shows next photo
- [ ] Spacebar toggles play/pause
- [ ] 'A' key shows previous photo
- [ ] 'D' key shows next photo

### This Day "Year in Review" Button (iPad 2, launched from the Home Screen icon)

- [ ] "Year in Review" sits in the upper-left, clear of the status bar, overlapping nothing, in portrait and landscape
- [ ] It fades in and out together with the bottom controls
- [ ] A tap while it's hidden only reveals it; a second tap opens the year picker, still full screen
- [ ] Previous / Play-Pause / Next behave as before
- [ ] "No photos for today" screen: the button is always visible and opens the year picker
- [ ] With `year_review.enable_feature` off, neither button is shown
- [ ] Apart from the button, This Day looks unchanged
- [ ] The button (and the "No photos for today" one) is a small uppercase pill: Avenir Next DemiBold, dark translucent fill, thin border

## Year in Review Tests

### Year Picker and Slideshow (iPad 2, launched from the Home Screen icon)

- [ ] Year tiles and "This Day Slideshow" stay full screen
- [ ] A big year never opened before starts within seconds
- [ ] Grid images are mostly ready when each location slide fades in
- [ ] Every 9-photo grid fits on one screen with no scrollbar, in landscape and portrait (include a panorama and a slide of 16:9 photos)
- [ ] Grids of 2–4 photos fill most of the screen (centred, short last row centred); a 1-photo slide shows the photo large and centred; rotating on a 1-, 3- and 9-photo slide re-fits it with no scrollbar
- [ ] Avenir Next shows three distinct weights (Regular, Medium, DemiBold); DemiBold is not a smeared synthetic bold
- [ ] Picker: opened years show a cover photo with "N CITIES · N PHOTOS"; never-opened years show a plain tile with an amber rule; the page loads and scrolls with no reload (a reload means memory pressure)
- [ ] Year summary: full-bleed cover with a smooth dark gradient, "A YEAR OF PLACES", the year and the stats row; with no cover, a dark slide with a short amber rule
- [ ] Month summary: up to 3 photos in a strip, the month name and year, the stats line and a two-column place list (no dashes or bullets); a busy month ends with "+ N MORE"; rotating the iPad on a month slide re-fits the list
- [ ] Location slide: single-line title (long titles end in "…"), "MONTH YEAR · N PHOTOS" under an amber rule
- [ ] Merged places: January 2023 has "Klein area, Texas" with "· KLEIN · SPRING · BRIDGELAND" (then "+N" if the rest don't fit) after the photo count, and "Key West area, Florida"; June 2023 has "San Miguel de Cozumel area, Quintana Roo, Mexico" while Mahahual and Tulum stay separate; the sub-line stays one line in both orientations
- [ ] Controls: ←, ‹, play/pause and › glyphs render; the 40px buttons are easy to tap; the counter reads "2 / 98" for 2023 (80 for 2019)
- [ ] A failed tile shows as a blank dark tile and the layout holds
- [ ] Back works during loading, on the error screen and on the slideshow
- [ ] Turn on Airplane mode, open a year: "Unable to load photos" with Try Again; Try Again works once back online
- [ ] A year with no photos shows "No photos from YYYY" with only a Back button
- [ ] Quick next/prev taps: no double fades
- [ ] 10 minutes of autoplay: no crash or reload
- [ ] A portrait phone photo is laid out with the right proportions (not cropped oddly)

### Endpoints (local `php -S`)

- [ ] `api/year_photos.php?year=Y`: first call builds the list (seconds), the next is cached (`"cached": true`, well under 0.5s)
- [ ] Place grouping: 2019 has 80 slides and 2023 98; the log has one "Year in Review: 2023: N place groups -> 85 slides (20 km)" line per build; no `gps`/`latitude` anywhere in the response, the list file or the log; single-place slides have no `places`; a schema-3 list file is rebuilt on first open
- [ ] `api/year_thumb.php`: a miss returns a JPEG and creates `cache/year_review/{Y}_{id}_{xl|m}.jpg`; the next list call returns that file as a direct `src`; location grid photos are size m, or xl for tiles drawn larger than 320 px on the short edge (each location photo has `src` (m) and `src_xl`), the year cover xl
- [ ] iPad 2: step through every location slide of a big year (e.g. 2023) without the app dropping back to the Home Screen
- [ ] The list response has `year_summary.cover` `{id, w, h, src}` (src at size xl) and `months[].summary.photos` (up to 3, src at size m with `&size=m` or `_m.jpg`); an older-schema (1–3) list file is rebuilt on first open; portrait photos keep portrait `w`/`h` (compare with the real m thumbnail)
- [ ] `size=m` for an allow-listed id returns 200 image/jpeg and creates `{Y}_{id}_m.jpg`; `size=xx`, `size=M` and `size[]=m` return 404; no `size` still serves xl
- [ ] Bad input (`id=../x`, `id=1e3`, empty id, `id[]=1`, unknown id, years out of range) returns an empty 404 with no file written and no Synology call
- [ ] 9 parallel misses with no `config/synology_session.php`: all 200, exactly one Synology login in the log (DEBUG)
- [ ] Expired sid: write a junk sid (keep the guard line) with `created` more than 5 minutes ago: the next miss re-logs in once and returns 200; 9 parallel misses also cause exactly one login
- [ ] Junk sid with `created` under 5 minutes ago: a miss returns 502 with no login
- [ ] A photo that can't be fetched (e.g. deleted since the list was built): repeated requests cause at most one login per 5 minutes
- [ ] `config/` not writable: 9 parallel misses log as many Synology logouts as logins (DEBUG), so no unsaved session is left open

### Shared-sid invalidation (on the NAS)

`api/year_photos.php` does its own login/logout; check that this doesn't break the shared thumbnail session:

1. [ ] Open an uncached year A and view a location slide (its tiles load).
2. [ ] Within 5 minutes, open a different uncached year B.
3. [ ] B's tiles load.
4. [ ] Repeat steps 1–3 right after admin **Test Connection**.
- [ ] Feature off: `year_photos.php` 403 `disabled`, `year_thumb.php` 404, `year-slideshow.php` redirects, `year-review.php` shows the off panel
- [ ] No `_sid` values or the sid string in responses, `cache/year_review/*.cache.php` or the log

### Cache Maintenance

- [ ] `php cron/daily_refresh.php`: This Day output and exit code unchanged; the log has a "Year in Review cleanup: deleted N, kept N, failed N" line
- [ ] Cleanup deletes unreferenced thumbnails (any size) older than an hour and stale `.*.tmp`; keeps referenced ones at every size (xl and m), recent ones, and all thumbnails of a year whose list file is corrupt (WARNING); never touches `.gitignore`, `.htaccess` or `*.cache.php`
- [ ] Admin "Clear Year in Review cache" deletes lists, thumbnails and `config/synology_session.php`, keeps the dotfiles; it is rejected without a CSRF token
- [ ] Enter in an admin text field does not trigger the Clear button

## Daily Refresh Tests

### Manual Execution

- [ ] Can run script from command line:
  ```bash
  php cron/daily_refresh.php
  ```
- [ ] Script completes without errors
- [ ] Script logs to `logs/app.log.php`
- [ ] Photos are downloaded to `cache/`
- [ ] `cache/photos.json` is created/updated
- [ ] Old cache files are cleaned up

### Cron/Scheduled Task

- [ ] Task created in Synology Task Scheduler
- [ ] Task runs at scheduled time
- [ ] Task logs show successful execution
- [ ] Photos update automatically each day

### Error Handling

- [ ] Script handles invalid credentials gracefully
- [ ] Script handles network errors
- [ ] Script handles no photos found
- [ ] Errors are logged appropriately
- [ ] Previous cache retained if refresh fails

## API Integration Tests

### Authentication

- [ ] Can login to Synology API
- [ ] Session ID (sid) is obtained
- [ ] Can logout from API

### Photo Search

- [ ] Can search photos by date
- [ ] Returns photos for correct month/day
- [ ] Respects first_year setting
- [ ] Handles dates with no photos
- [ ] Handles dates with many photos (100+)

### Photo Download

- [ ] Thumbnails download successfully
- [ ] Downloaded files are valid images
- [ ] Cache key is correct
- [ ] Multiple photos download in sequence

## Security Tests

- [ ] Admin area requires login
- [ ] Direct access to admin/index.php redirects to login
- [ ] Session expires after logout
- [ ] Logout only works via the Logout button (a GET to `admin/logout.php` just redirects)
- [ ] An admin POST without the `csrf_token` field is rejected with "Session expired, please try again" and changes nothing
- [ ] Session cookie has `HttpOnly` and `SameSite=Strict` (no `Secure` on plain HTTP)
- [ ] Status codes from another machine on the LAN (expect 403 from the Nginx
  deny rules, or 404 with an empty body from a guarded `.php` file; never 200):
  ```bash
  B=http://NAS-IP/rediscover-this-day
  for u in config/config.php config/config.json config/config.default.json \
           logs/app.log.php logs/app.log \
           cron/daily_refresh.php "cron/daily_refresh.php?run" test_api.php \
           README.md composer.json vendor/autoload.php .git/HEAD .gitignore; do
    printf '%s -> ' "$u"; curl -s -o /dev/null -w '%{http_code}\n' "$B/$u"
  done
  ```
  `index.php`, `admin/login.php`, `api/photos.php` and `cache/photos.json` should still return 200.
  Re-run after DSM / Web Station updates.
- [ ] config/ directory not accessible via web
- [ ] logs/ directory not accessible via web
- [ ] cache/ directory allows image access only
- [ ] Password changes work correctly
- [ ] Old password is verified before change

## Performance Tests

- [ ] Admin page loads in < 2 seconds
- [ ] Slideshow page loads in < 2 seconds
- [ ] Photo transitions are smooth
- [ ] No memory leaks during long slideshow sessions
- [ ] iPad 2 performance is acceptable
- [ ] Large photo collections (100+) work well

## Edge Cases

### No Photos

- [ ] Graceful message when no photos for date
- [ ] Doesn't crash or show errors

### Single Photo

- [ ] Slideshow works with 1 photo
- [ ] Navigation works correctly
- [ ] Counter shows "1 of 1"

### Many Photos (100+)

- [ ] All photos download successfully
- [ ] Slideshow performance remains good
- [ ] Memory usage is acceptable
- [ ] Counter shows correct total

### Network Issues

- [ ] Handles Synology offline gracefully
- [ ] Shows appropriate error messages
- [ ] Logs errors for troubleshooting
- [ ] Retains cached photos when refresh fails

### Invalid Configuration

- [ ] Missing config file shows clear error
- [ ] Invalid JSON shows error message
- [ ] Empty settings validated
- [ ] Clear guidance on fixing issues

## Log File Tests

- [ ] `logs/app.log.php` is created, and its first line is the 404 guard
- [ ] No `_sid=`, `sid=` or `passwd=` values in the log (only `[redacted]`)
- [ ] Login attempts are logged
- [ ] Configuration changes are logged
- [ ] Refresh operations are logged
- [ ] Errors include sufficient detail
- [ ] Timestamps are correct
- [ ] Log file doesn't grow excessively

## Documentation Tests

- [ ] README.md is clear and complete
- [ ] INSTALL.md provides quick setup
- [ ] CONFIG.md explains all options
- [ ] Examples in docs are accurate
- [ ] Troubleshooting section is helpful

## Cleanup

After testing, verify:

- [ ] Default password has been changed
- [ ] Test accounts removed (if any)
- [ ] Unnecessary log entries removed
- [ ] File permissions are secure

## Sign-off

- [ ] All critical tests passed
- [ ] Known issues documented
- [ ] Ready for production use

**Tested by**: ******\_\_\_******  
**Date**: ******\_\_\_******  
**Notes**: ******\_\_\_******
