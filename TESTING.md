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
- [ ] `config/config.json` created from default
- [ ] Can access main page: `http://NAS-IP/rediscover-this-day/`
- [ ] Can access admin login: `http://NAS-IP/rediscover-this-day/admin/`

## Admin Interface Tests

### Login

- [ ] Can login with default credentials (admin/password)
- [ ] Invalid credentials show error message
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

## Daily Refresh Tests

### Manual Execution

- [ ] Can run script from command line:
  ```bash
  php cron/daily_refresh.php
  ```
- [ ] Script completes without errors
- [ ] Script logs to `logs/app.log`
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

- [ ] `logs/app.log` is created
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
