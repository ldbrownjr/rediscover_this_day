# Rediscover This Day - Quick Setup Guide

## Prerequisites

- Synology NAS with Photos package installed
- Access to Synology web directory or Web Station
- SSH access (optional, for command line setup)
- **PHP 7.0+ with cURL extension enabled**

## PHP Requirements

This application requires PHP with the following extensions:

- **cURL extension** - Required for HTTP requests to Synology API
- JSON extension (usually enabled by default)
- File system functions enabled

### Installing cURL Extension on Synology

If you get connection errors, you may need to enable the cURL extension:

1. Open **Synology DSM Control Panel**
2. Go to **Web Station** → **PHP Settings**
3. Select your PHP profile
4. Check **cURL** in the extensions list
5. Apply changes
6. Restart Web Station

## Quick Start (5 minutes)

### 1. Upload Files

Upload entire project to your Synology web directory:

```
/volume2/web/rediscover-this-day/
```

(Use your actual volume; this NAS uses `/volume2`.)

### 2. Set Permissions

Via SSH:

```bash
cd /volume2/web/rediscover-this-day
chmod 755 .
chmod 777 config cache logs
```

Or via File Station:

- Right-click folders → Properties → Permissions
- Set `config`, `cache`, `logs` to: Everyone = Read/Write

No config copy is needed: the first page load creates `config/config.php` from
`config/config.default.json` (an existing `config/config.json` is migrated and
deleted automatically).

### 3. Login to Admin

1. Open browser: `http://YOUR-NAS-IP/rediscover-this-day/admin/`
2. Login with:
   - Username: `admin`
   - Password: `password`
3. **CHANGE PASSWORD IMMEDIATELY!**

### 4. Configure Synology Connection

In admin interface:

1. Enter your NAS URL (e.g., `https://192.168.1.100:5001`)
2. Enter your Synology username and password
3. Click "Save Configuration"
4. Click "Test Connection" to verify

### 5. Configure Settings

1. Set "First Year" (e.g., 2010)
2. Set slideshow duration (default: 5 seconds)
3. Click "Save Settings"

### 6. Get First Photos

1. Go to Status section
2. Click "Refresh Now"
3. Wait for photos to download (may take a minute)

### 7. View Slideshow

Open: `http://YOUR-NAS-IP/rediscover-this-day/`

### 8. Set Up Daily Auto-Refresh

1. Open Synology **Control Panel** → **Task Scheduler**
2. Create → Scheduled Task → User-defined script
3. Settings:
   - Task: `Rediscover Daily Refresh`
   - User: `http`
   - Schedule: Daily, 00:00 (or your preference)
   - Script:
     ```bash
     /usr/bin/php /volume2/web/rediscover-this-day/cron/daily_refresh.php
     ```
4. Save and enable the task

The refresh script is CLI-only. Requesting `cron/daily_refresh.php` over the web
returns 404 (there is no `?run` trigger); use **Refresh Now** in admin instead.

### 9. Block Non-Public Files (Nginx)

Web Station serves this site with **Nginx**, which ignores the `.htaccess`
files. Config and logs are already safe on their own (`config/config.php` and
`logs/app.log.php` start with a guard line that returns an empty 404), but add
deny rules for everything else that shouldn't be public.

The rules live in `/usr/local/etc/nginx/conf.d/www.rediscover-this-day.conf`
on the NAS. They block `config/`, `logs/`, `cron/`, `vendor/`, `.git`,
`test_api.php`, dotfiles, `*.md`, `composer.*` and
`Synology_File_Station_API_Guide.pdf`. The file is included by the
`include conf.d/www.*.conf;` line in the default port 80/443 server blocks
(`/etc/nginx/conf.d` links to `/usr/local/etc/nginx/conf.d`):

```nginx
# rediscover-this-day: block private files (Web Station's Nginx ignores .htaccess)
location ^~ /rediscover-this-day/config/       { deny all; }
location ^~ /rediscover-this-day/logs/         { deny all; }
location ^~ /rediscover-this-day/cron/         { deny all; }
location ^~ /rediscover-this-day/vendor/       { deny all; }
location ^~ /rediscover-this-day/.git          { deny all; }
location =  /rediscover-this-day/test_api.php  { deny all; }
location ~ ^/rediscover-this-day/(?:.*/)?\.    { deny all; }
location ~ ^/rediscover-this-day/(?:[^/]+\.md|composer\.(?:json|lock)|Synology_File_Station_API_Guide\.pdf)$ { deny all; }
```

`^~` and `=` take priority over Nginx's PHP regex location, so `cron/*.php`,
`vendor/*.php` and `test_api.php` are blocked before PHP runs. Denied paths
return 403.

Check and reload Nginx after editing:

```bash
sudo nginx -t && sudo nginx -s reload
```

DSM / Web Station updates can remove files under `/usr/local/etc/nginx/`.
Re-run the status-code check in `TESTING.md` (Security Tests) after updates.

## Upgrading an Existing Install

After `git pull` on the NAS, do these right away, in order:

1. **Load `http://YOUR-NAS-IP/rediscover-this-day/` (the This Day page) in a
   browser.** This makes the web server user migrate `config/config.json` to
   the guarded `config/config.php` (and create `logs/app.log.php`). Opening
   `admin/` while logged out does *not* trigger it, because it redirects to the
   login page before reading the config. If the Task Scheduler task runs as root
   and gets there first, the migration hands the new file to the old file's
   owner, but loading the page first avoids the question.
2. **Check ownership and permissions:**
   ```bash
   cd /volume2/web/rediscover-this-day
   ls -la config/ logs/
   ```
   Expect `config/config.php` (no `config.json` left over), readable by both the
   web user and the Task Scheduler user (see the permissions in `CLAUDE.md`),
   and `logs/app.log.php` with mode `-rw-rw-rw-`.
3. **Delete the old log:** `rm -f logs/app.log`. It's no longer written to and
   contains historical Synology session IDs.
4. **Run the refresh once as the Task Scheduler user:** Control Panel → Task
   Scheduler → select `Rediscover Daily Refresh` → **Run**. Then check
   `logs/app.log.php` for errors and that admin still loads and saves settings.

## Done! 🎉

Your slideshow should now display photos from today's date in previous years.

## Common Issues

**No photos showing?**

- Check that photos exist for today's date from previous years
- Click "Refresh Now" in admin
- Check `logs/app.log.php` for errors (first line is a guard; `tail -f` works as usual)

**Can't login to admin?**

- Default credentials: `admin` / `password`
- Check that `config/config.php` exists

**Synology connection fails?**

- Verify URL includes `https://` and correct port
- Test URL in browser first
- Check username/password
- **Ensure cURL extension is enabled in PHP settings** (see PHP Requirements above)

**Images not loading?**

- Check `cache/` folder permissions (should be 777)
- Run refresh manually from admin

## Need Help?

Check the full README.md for detailed troubleshooting and configuration options.
