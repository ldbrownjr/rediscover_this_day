# Backlog

Open bugs and follow-ups from the full code review (October 2026). Batch 1,
"This Day reliability" (C1, C2, C3, H3, M1, M6, M8, L1), is done.

Each item gives its review ID, the file it's in, and the likely direction for a
fix. Plan anything non-trivial with the architect first (see
`.claude/CLAUDE.md`).

## Follow-ups from Batch 1

- **S1. A chmod failure aborts every write.** File: `common.php`,
  `writeFileAtomic()`.
  - A failed `chmod` makes the whole write fail. Synology shares with ACLs can
    refuse `chmod`, and then every refresh and every config save would fail.
  - Fix: log a WARNING and continue. **Do this before relying on it on the NAS.**
- **S2. Directory permissions.** Atomic writes need `cache/` and `config/` to be
  writable by both the cron user and the web user, and each file ends up owned
  by whoever last wrote it.
  - After deploying, check `ls -l cache/` once after a cron run and once after
    an admin refresh.
  - Mention the requirement in `INSTALL.md` and `TESTING.md`.
- **S3. `_sid` still reaches `logs/app.log`.** See M4.
- **S4. Leftover temp files.** A killed write leaves `.<name>.<hex>.tmp` in
  `cache/` or `config/` forever.
  - Fix: have `cleanOrphanedCache()` also remove `.tmp` files older than an
    hour.
- **Leftover JPEGs when the save fails.** If the `photos.json` write fails after
  the downloads, the newly downloaded JPEGs stay in `cache/`. The display isn't
  affected, and the next successful run removes them.
- **Nits:**
  - N1: an unexpected error shows in admin as "Refresh failed: Refresh failed".
    Better wording: "Unexpected error (see log)".
  - N2: a partial run caused only by failed downloads shows no partial note in
    admin. Consider showing "X of Y downloaded".
  - N3: unguarded keys in `cron/daily_refresh.php`: `photos.first_year`, and the
    `id`, `filename` and `time` fields on each photo. Use `??`.
  - N4: if `$config['slideshow']` is missing, `index.php` makes `config` null,
    and `slideshow.js` throws before polling starts.
  - N5: `api/photos.php` still sends the full photo list, but the client only
    uses `version`.
  - N6: the `filemtime` paths in `year-slideshow.php` are inconsistent
    (`__DIR__` vs relative).
  - N8: unused `downloadFullPhoto()` still writes non-atomically.

## Batch 2: Security

- **H1. Unauthenticated refresh trigger.** `cron/daily_refresh.php?run` lets
  anyone on the LAN trigger a refresh.
  - Fix: make it CLI-only, or require admin.
- **H2. No CSRF protection on admin forms.** A malicious page could change the
  Synology URL, and the next refresh would send the Synology password there.
  - Fix: add a session CSRF token to the forms and set
    `session.cookie_samesite=Strict`.
- **H7. Config and logs may be web-readable.** `config/`, `logs/` and `.git/`
  are protected only by Apache `.htaccess` rules.
  - First confirm whether Web Station serves this site with Apache or Nginx.
- **M4. Synology session IDs (`_sid`) in `logs/app.log`.** Sources in
  `api/synology.php`:
  - `API Request params` logs the full `_sid` at INFO.
  - The download URL is logged at DEBUG.
  - Guzzle error messages in `makeRequest()` and `downloadFile()` include it.

  Fix: redact `_sid` before logging.
- **M5. Login hardening.**
  - Call `session_regenerate_id(true)` on login.
  - Set the cookie and session options in PHP code, because the `mod_php`
    `.htaccess` block doesn't apply under PHP-FPM.
  - Optional: throttle login attempts.
- **M10. `test_api.php` is reachable from the web without login.**
  - Fix: allow it to run only from the CLI.

## Batch 3: Year in Review (uncommitted work)

- **H4. Slow loads and timeouts.** `api/year_photos.php` re-downloads every
  thumbnail on every request and often runs past the 60s client timeout.
  - Fix: use the existing thumbnail cache, cache the year's JSON, and align the
    client and server timeouts.
- **H5. Unescaped values in `innerHTML`.** Location names and filenames are
  inserted into `js/year-slideshow.js` markup without escaping.
  - Fix: escape them, or build the nodes with `textContent`.
- **H6. justifiedGallery timer leak.** Each location slide starts a timer that
  is never stopped. Call `justifiedGallery('destroy')` before the next slide
  replaces it.
- **H8. Pending decision: CDN vs self-hosting jQuery and justifiedGallery.**
  The CDN certificates may not be trusted on iOS 9.
- **M1 / M6 in `api/year_photos.php`.** Move `logout()` into a `finally`
  block, and write thumbnails with `writeFileAtomic()`.
- **M2. An empty year shows HTTP 500.** Show an empty state instead.
- **M3. No way out while loading.** Wire up the back button and touch handling
  before `loadPhotos()`.
- **M9. "Unknown City/State/Country" shown.** Omit missing location parts
  instead.
- **Unused stat styles.** The earlier uncommitted stat styles (36px values,
  lighter weights) were dropped in Batch 1 because they never rendered. Bring
  them back only if that was the intended look.
- **Low items:**
  - L7: flex `gap` doesn't work on iOS 9 (`.summary-stats`,
    `css/year-review.css`).
  - L8: the hover zoom on grid photos sticks on touch.
  - L10: overlapping fades on quick taps.
  - L11: dead code (`hierarchy`, `showSummarySlide`, `calculateYearStats`,
    `orientation`, a duplicate thumbnail downloader).
  - L12: missing `isset` on the photo dimensions (`resolution`).

## Batch 4: Other

- **M7. Timezone handling (uncertain).** Confirm how Synology's `time` field is
  stored using a photo with a known capture time. Then use `gmmktime`/`gmdate`
  and `getUTCFullYear()`, or set an explicit timezone.
- **L2. No validation on admin form fields.** Whitelist `transition`,
  `selection_strategy` and `log_level`, and clamp `duration`. An invalid log
  level silences all logging.
- **L3. Possible password autofill.** The browser may autofill the admin
  password into the Synology password field. Use
  `autocomplete="new-password"`.
- **L4. Default credentials shown.** The login page shows the default
  `admin`/`password` credentials, which are recreated automatically if
  `config.json` is missing.
- **L5. Comment before the doctype.** `admin/index.php` outputs a debug comment
  before `<!DOCTYPE>`.
- **L6. Year in Review admin link hidden by default.** The checkbox uses
  `?? true`, but the link uses `isset`.
- **L13. Year picker CSS is never cached.** `year-review.php` uses `time()` as
  the cache-buster. Use `filemtime`.
- **L14. Raw username in the log.** `admin/login.php` logs the username as
  entered, which allows log injection.
- **L16. `index.php` assumes `$config['slideshow']` exists.** Related to N4.

## Testing infrastructure

- There is no automated test suite. The tester checked Batch 1 with throwaway
  harnesses: a mock `SynologyAPI` for the cron failure scenarios, and Node with
  a stubbed DOM for `slideshow.js`.
- Consider adding a small, real harness, such as PHPUnit or plain PHP scripts
  under `tests/`, that covers `writeFileAtomic`, `cleanOrphanedCache`, and the
  refresh failure scenarios.
