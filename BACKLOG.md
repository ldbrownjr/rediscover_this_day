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

Done: H1, H2, H7 (guarded `config/config.php` and `logs/app.log.php`), M4/S3,
M5, M10 and L14.

- **Nginx deny rules.** Web Station serves this site with Nginx, so
  `.htaccess` is ignored. Deny rules for `config/`, `logs/`, `cron/`,
  `vendor/`, `.git`, `test_api.php`, dotfiles, `*.md`, `composer.*` and
  `Synology_File_Station_API_Guide.pdf` live in
  `/usr/local/etc/nginx/conf.d/www.rediscover-this-day.conf` on the NAS (see
  `INSTALL.md`). DSM / Web Station updates can drop this file, so re-verify it
  after every update with the status-code check in `TESTING.md`.
- **Follow-ups from the Batch 2 review:**
  - **Redaction gaps.** `redactSecrets()` doesn't cover:
    - URL-encoded keys (`%3F_sid%3D`);
    - escaped quotes inside JSON values;
    - truncated JSON with no closing quote (`synology.php` logs
      `substr($body, 0, 200)`);
    - `print_r` / `var_export` output, `SynoToken`, or `user:pass@host` URLs.

    None of these appeared in real logs.
  - **Redundant migrations.** When many requests arrive at once on the first
    load, each one migrates again and logs an INFO line. They all write the same
    data. In theory, a late migration could overwrite a Save made in between.
    Fix: make the migration write create-only, like the seed.
  - **Repeated ERRORs when `config/` isn't writable.** A failed migration
    logs 2 ERRORs on every request, because the retry guard only lasts for one
    process. Use a marker file, or log later failures at WARNING.
  - **First log line can miss the guard.** When `logs/app.log.php` is first
    created, a concurrent append can land before the guard line. Fix: write the
    guard to a temp file and rename it into place.
  - **Sessions start for CLI scripts.** `startAppSession()` also runs for the
    cron and `test_api.php`, creating session files (owned by root if the cron
    runs as root). Fix: skip it when `PHP_SAPI === 'cli'`.
  - **Session lock blocks the back button.** `api/year_photos.php` holds the
    session lock for up to 300s, so the iPad's back button can hang while a
    year loads. Fix: call `session_write_close()` in the `api/*.php` endpoints
    and on the other non-admin pages.
  - **Wrong JSON error message.** `json_last_error_msg()` is read after a
    `logMessage()` call that decodes JSON again, so the log says "JSON decode
    error: No error".
  - **Agent files are out of date.** `.claude/agents/*.md` still mention
    `config/config.json`, `logs/app.log` and `/volume1`.
  - **Not tested:** the root `chown` path in `migrateLegacyConfig()`, and the
    code on a real PHP 8.2 (only 8.4 is installed locally).

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
  `config/config.php` is missing.
- **L5. Comment before the doctype.** `admin/index.php` outputs a debug comment
  before `<!DOCTYPE>`.
- **L6. Year in Review admin link hidden by default.** The checkbox uses
  `?? true`, but the link uses `isset`.
- **L13. Year picker CSS is never cached.** `year-review.php` uses `time()` as
  the cache-buster. Use `filemtime`.
- **L16. `index.php` assumes `$config['slideshow']` exists.** Related to N4.

## Testing infrastructure

- There is no automated test suite. The tester checked Batch 1 with throwaway
  harnesses: a mock `SynologyAPI` for the cron failure scenarios, and Node with
  a stubbed DOM for `slideshow.js`.
- Consider adding a small, real harness, such as PHPUnit or plain PHP scripts
  under `tests/`, that covers `writeFileAtomic`, `cleanOrphanedCache`, and the
  refresh failure scenarios.
