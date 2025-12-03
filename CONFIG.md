# Configuration Reference

This document describes all configuration options in `config/config.json`.

## Configuration Structure

```json
{
  "synology": {
    "url": "",
    "username": "",
    "password": ""
  },
  "photos": {
    "first_year": 2010
  },
  "slideshow": {
    "duration": 5,
    "transition": "fade"
  },
  "admin": {
    "username": "admin",
    "password_hash": "$2y$10$..."
  }
}
```

## Synology Configuration

### `synology.url` (string)

- **Description**: Base URL of your Synology NAS
- **Format**: Must include protocol (`http://` or `https://`)
- **Examples**:
  - `https://192.168.1.100:5001`
  - `https://yournas.synology.me`
  - `http://192.168.1.100:5000`
- **Required**: Yes
- **Default**: Empty string

### `synology.username` (string)

- **Description**: Synology account username with access to Photos
- **Format**: String
- **Required**: Yes
- **Default**: Empty string
- **Security**: Needs read access to Photos library

### `synology.password` (string)

- **Description**: Synology account password
- **Format**: Plain text string
- **Required**: Yes
- **Default**: Empty string
- **Security**: Stored in plain text - ensure file permissions are secure (666 or 600)

## Photo Settings

### `photos.first_year` (integer)

- **Description**: Oldest year to include in photo search
- **Format**: 4-digit year
- **Range**: 1900 to current year
- **Example**: `2010` will exclude all photos taken before 2010
- **Required**: Yes
- **Default**: `2010`

## Slideshow Settings

### `slideshow.duration` (integer)

- **Description**: How long each photo displays (in seconds)
- **Format**: Integer
- **Range**: 1-60 seconds
- **Required**: Yes
- **Default**: `5`
- **Note**: Lower values = faster slideshow

### `slideshow.transition` (string)

- **Description**: Visual transition effect between photos
- **Format**: String (enum)
- **Options**:
  - `"fade"` - Smooth crossfade transition (300ms)
  - `"none"` - Instant change (no transition)
- **Required**: Yes
- **Default**: `"fade"`
- **Compatibility**: Both work on iPad 2

## Admin Settings

### `admin.username` (string)

- **Description**: Username for admin login
- **Format**: String
- **Required**: Yes
- **Default**: `"admin"`
- **Note**: Can be changed but requires manual editing of config file

### `admin.password_hash` (string)

- **Description**: Bcrypt hash of admin password
- **Format**: Bcrypt hash string (starts with `$2y$`)
- **Required**: Yes
- **Default**: `"$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"` (password: `password`)
- **Security**: Use PHP's `password_hash()` function
- **Change**: Use admin interface → Admin Security section

## Creating Password Hash

To manually create a password hash:

```php
<?php
echo password_hash('your_new_password', PASSWORD_DEFAULT);
?>
```

Or via command line:

```bash
php -r "echo password_hash('your_new_password', PASSWORD_DEFAULT);"
```

## Security Best Practices

1. **Change default password immediately**
2. **Use HTTPS** for admin access when possible
3. **Restrict file permissions**:
   ```bash
   chmod 600 config/config.json  # Owner read/write only
   ```
4. **Use strong Synology password** - this is stored in plain text
5. **Protect config directory** with `.htaccess` (already included)

## Example Configurations

### Minimal Configuration

```json
{
  "synology": {
    "url": "https://192.168.1.100:5001",
    "username": "photos_user",
    "password": "secure_password"
  },
  "photos": {
    "first_year": 2000
  },
  "slideshow": {
    "duration": 5,
    "transition": "fade"
  },
  "admin": {
    "username": "admin",
    "password_hash": "$2y$10$..."
  }
}
```

### Custom Configuration

```json
{
  "synology": {
    "url": "https://mynas.synology.me",
    "username": "admin",
    "password": "mypassword123"
  },
  "photos": {
    "first_year": 2015
  },
  "slideshow": {
    "duration": 10,
    "transition": "none"
  },
  "admin": {
    "username": "admin",
    "password_hash": "$2y$10$customhash..."
  }
}
```

## Validation

The application will validate:

- Synology URL is not empty
- Username and password are not empty
- First year is between 1900 and current year
- Duration is between 1 and 60
- Transition is either "fade" or "none"
- Refresh time matches HH:MM format

Invalid configurations will show errors in the admin interface.

## Backup

Always backup your configuration before making changes:

```bash
cp config/config.json config/config.json.backup
```

## Restoring Defaults

To restore default configuration:

```bash
cp config/config.default.json config/config.json
```

**Note**: This will reset admin password to `password` - change it immediately!
