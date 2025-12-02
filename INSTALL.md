# Rediscover This Day - Quick Setup Guide

## Prerequisites

- Synology NAS with Photos package installed
- Access to Synology web directory or Web Station
- SSH access (optional, for command line setup)

## Quick Start (5 minutes)

### 1. Upload Files

Upload entire project to your Synology web directory:

```
/volume1/web/rediscover-this-day/
```

### 2. Set Permissions

Via SSH:

```bash
cd /volume1/web/rediscover-this-day
chmod 755 .
chmod 777 config cache logs
cp config/config.default.json config/config.json
chmod 666 config/config.json
```

Or via File Station:

- Right-click folders → Properties → Permissions
- Set `config`, `cache`, `logs` to: Everyone = Read/Write

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
     /usr/bin/php /volume1/web/rediscover-this-day/cron/daily_refresh.php
     ```
4. Save and enable the task

## Done! 🎉

Your slideshow should now display photos from today's date in previous years.

## Common Issues

**No photos showing?**

- Check that photos exist for today's date from previous years
- Click "Refresh Now" in admin
- Check `logs/app.log` for errors

**Can't login to admin?**

- Default credentials: `admin` / `password`
- Check that `config/config.json` exists

**Synology connection fails?**

- Verify URL includes `https://` and correct port
- Test URL in browser first
- Check username/password

**Images not loading?**

- Check `cache/` folder permissions (should be 777)
- Run refresh manually from admin

## Need Help?

Check the full README.md for detailed troubleshooting and configuration options.
