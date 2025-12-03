# Rediscover This Day

A nostalgic photo slideshow application that displays photos from previous years taken on the current calendar date. Integrates with Synology Photos to automatically pull and display your memories.

## Features

- 📅 **Date-Based Discovery**: Automatically shows photos taken on today's date from previous years
- 🎬 **Auto-Playing Slideshow**: Smooth transitions with configurable duration and effects
- 📱 **Legacy Browser Support**: Works on older devices including iPad 2 (Safari on iOS 9.3.5)
- ⚙️ **Easy Configuration**: Web-based admin interface for all settings
- 🔄 **Automatic Updates**: Daily scheduled refresh of photos
- 🖼️ **Local Caching**: Images cached locally for fast loading and offline viewing
- 🔐 **Secure**: Password-protected admin area

## Requirements

- **Synology NAS** with Photos package installed
- **Web Server** with PHP support (PHP 7.0 or higher recommended)
  - PHP extensions: `curl`, `json`, `session`
- **Storage**: Sufficient space in `cache/` directory for cached images
- **Scheduled Tasks**: Ability to run cron jobs (for automatic daily refresh)

## Installation

### 1. Deploy Files to Synology NAS

Upload all files to your Synology web directory, for example:

- `/volume1/web/rediscover-this-day/`

Or set up a virtual host in **Web Station**.

### 2. Set File Permissions

Ensure the web server can write to these directories:

```bash
chmod 755 /volume1/web/rediscover-this-day
chmod 777 /volume1/web/rediscover-this-day/config
chmod 777 /volume1/web/rediscover-this-day/cache
chmod 777 /volume1/web/rediscover-this-day/logs
```

### 3. Initial Configuration

1. Copy the default configuration:

   ```bash
   cp config/config.default.json config/config.json
   chmod 666 config/config.json
   ```

2. The default admin credentials are:

   - **Username**: `admin`
   - **Password**: `password`

   ⚠️ **Change these immediately after first login!**

### 4. Access Admin Interface

Navigate to: `http://your-nas-address/rediscover-this-day/admin/`

Log in with default credentials and configure:

1. **Synology Photos Connection**:

   - NAS URL (e.g., `https://192.168.1.100:5001` or `https://yournas.synology.me`)
   - Username (your Synology account)
   - Password (your Synology account password)
   - Click "Test Connection" to verify

   It is recomended to create a user specific for this application.
   e.g. app_rtd
   Then this user needs to be given necessary perimissions.

   - Access to Synology Photos (error code 801 if not done)
     - Sign in to as **admin**.
     - Navigate to **Control Panel > User & Group**.
     - Select the new user (e.g. app_rtd).
     - Click on **Edit**.
     - Go to **Applications** tab.
     - Scroll down to Synology Photos and click the Allow checkbox.
     - Click **Save**.
   - Access to Team Photos (error code 801 if not done)
     - Sign in to as **admin** and launch **Synology Photos App**.
     - Select **User Profile** in upper right, then **Settings**.
     - Select **Shared Space** tab.
     - Click on **Set Access Permissions**.
     - In Search box find user e.g. app_rtd
       - Select **Full Access**. (error code 120 shows up if not Full Access)
       - click **plus sign**.
     - Select **Save**.
     - Select **Save** again.

2. **Photo Settings**:

   - First year to include (e.g., `2010`)

3. **Slideshow Settings**:

   - Photo duration (default: 5 seconds)
   - Transition effect (fade or none)
   - Daily refresh time (default: 00:00)

4. **Change Admin Password**:
   - Use the "Admin Security" section to change your password

### 5. Set Up Scheduled Task (Cron Job)

To automatically refresh photos daily:

#### Option A: Synology Task Scheduler (Recommended)

1. Open **Control Panel** → **Task Scheduler**
2. Click **Create** → **Scheduled Task** → **User-defined script**
3. Configure:
   - **Task name**: Rediscover This Day - Daily Refresh
   - **User**: `http` (or your web server user)
   - **Schedule**: Daily at your preferred time (e.g., 00:00)
   - **User-defined script**:
     ```bash
     /usr/bin/php /volume1/web/rediscover-this-day/cron/daily_refresh.php
     ```
4. Click **OK** to save

#### Option B: Manual Cron Entry

Edit crontab for the web server user:

```bash
sudo -u http crontab -e
```

Add this line (replace path as needed):

```
0 0 * * * /usr/bin/php /volume1/web/rediscover-this-day/cron/daily_refresh.php
```

### 6. First Photo Refresh

Trigger the first refresh manually from the admin interface:

- Go to **Status** section
- Click **Refresh Now**

This will fetch and cache photos for today's date.

## Usage

### Viewing the Slideshow

Simply navigate to: `http://your-nas-address/rediscover-this-day/`

The slideshow will:

- Auto-start when the page loads
- Display photos in chronological order (oldest to newest)
- Loop continuously
- Show year and location (if available)

### Slideshow Controls

Controls appear when you move the mouse or touch the screen and auto-hide after 3 seconds:

- **Previous** (`<` button or Left Arrow): Go to previous photo
- **Play/Pause** (center button or Spacebar): Toggle playback
- **Next** (`>` button or Right Arrow): Go to next photo
- **Counter**: Shows current position (e.g., "5 of 23")

### Admin Functions

From the admin interface (`/admin/`), you can:

- ✅ Test Synology connection
- ⚙️ Update all configuration settings
- 🔄 Manually trigger photo refresh
- 📊 View status (last refresh time, photo count)
- 🔐 Change admin password
- 🚪 Logout securely

## File Structure

```
rediscover-this-day/
├── admin/              # Admin interface
│   ├── index.php       # Main admin page
│   ├── login.php       # Login page
│   └── logout.php      # Logout handler
├── api/                # API integration
│   └── synology.php    # Synology Photos API wrapper
├── cache/              # Cached images and metadata
│   ├── photos.json     # Photo metadata cache
│   ├── .htaccess       # Allow public access to images
│   └── *.jpg           # Cached photo files
├── config/             # Configuration
│   ├── config.json     # Active configuration (created on install)
│   ├── config.default.json  # Default configuration template
│   └── .htaccess       # Protect config from web access
├── cron/               # Scheduled tasks
│   └── daily_refresh.php  # Daily photo refresh script
├── css/                # Stylesheets
│   ├── admin.css       # Admin interface styles
│   └── slideshow.css   # Slideshow styles
├── js/                 # JavaScript
│   └── slideshow.js    # Slideshow logic (ES5 compatible)
├── logs/               # Application logs
│   ├── app.log         # Main log file
│   └── .htaccess       # Protect logs from web access
├── common.php          # Common functions and utilities
├── index.php           # Main slideshow page
├── .gitignore          # Git ignore rules
└── README.md           # This file
```

## Troubleshooting

### No photos showing

1. Check admin interface → Status section for last refresh info
2. Check logs: `logs/app.log`
3. Verify Synology connection in admin (Test Connection button)
4. Manually trigger refresh from admin interface
5. Ensure photos exist for today's date in previous years

### Connection to Synology fails

1. Verify NAS URL is correct (include `https://` and port if needed)
2. Check username and password
3. Ensure Synology Photos package is installed and running
4. Check firewall settings on NAS
5. Try accessing NAS URL directly in browser

### Images not loading in slideshow

1. Check `cache/` directory has write permissions (777)
2. Verify cached images exist: `ls cache/*.jpg`
3. Check `cache/photos.json` exists and contains data
4. Check browser console for errors (F12)

### Cron job not running

1. Verify task is enabled in Task Scheduler
2. Check task logs in Synology Task Scheduler
3. Verify PHP path: `which php`
4. Test script manually: `php cron/daily_refresh.php`
5. Check logs: `logs/app.log`

### Admin login not working

1. Verify `config/config.json` exists
2. Reset to default password by editing `config/config.json`:
   ```json
   "admin": {
     "username": "admin",
     "password_hash": "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"
   }
   ```
   (This sets password to: `password`)

### Slideshow not working on iPad 2

1. Verify you're using Safari (other browsers may not work)
2. Check JavaScript is enabled
3. Clear browser cache
4. Ensure images are being cached (check cache directory)

## Security Notes

- Default password is `password` - **change it immediately!**
- Configuration file stores Synology password in plain text - ensure file permissions are secure
- Admin session uses PHP sessions - cookies are required
- Consider using HTTPS for admin access
- `config/` and `logs/` directories are protected by `.htaccess` files

## API Reference

The application uses the Synology Photos API:

- **Authentication**: `SYNO.API.Auth` (login/logout)
- **Photo Search**: `SYNO.Foto.Browse.Item` (list photos by date)
- **Thumbnails**: `SYNO.Foto.Thumbnail` (get thumbnail images)
- **Downloads**: `SYNO.Foto.Download` (get full resolution)

For more details, see: https://github.com/zeichensatz/SynologyPhotosAPI

## Customization

### Change Slideshow Duration

1. Admin interface → Slideshow Settings → Photo Duration
2. Or edit `config/config.json`:
   ```json
   "slideshow": {
     "duration": 10
   }
   ```

### Change Transition Effect

1. Admin interface → Slideshow Settings → Transition
2. Options: `fade` or `none`

### Change Cached Image Quality

Edit `api/synology.php`, line ~170:

```php
$cachedFilename = $api->downloadThumbnail($photo['id'], $cacheKey, 'xl');
```

Options: `sm` (240px), `m` (320px), `xl` (1280px)

### Customize Styles

Edit CSS files:

- `css/slideshow.css` - Slideshow appearance
- `css/admin.css` - Admin interface appearance

## Support

For issues or questions:

1. Check the troubleshooting section above
2. Review logs in `logs/app.log`
3. Verify configuration in admin interface
4. Test Synology API connection manually

## License

This project is provided as-is for personal use.

## Credits

- Synology Photos API documentation: https://github.com/zeichensatz/SynologyPhotosAPI
- Built for legacy browser compatibility (iPad 2 / iOS 9.3.5)
