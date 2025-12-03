# Rediscover This Day - Requirements Document

## 1. Project Overview

### 1.1 Purpose
Create a web application that displays a daily slideshow of photos from previous years taken on the current calendar date, using Synology Photos as the photo library source.

### 1.2 Target Deployment
- Host on Synology NAS
- Must support legacy browsers (Safari on iPad 2)

## 2. System Architecture

### 2.1 Technology Stack
- **Backend**: Python or PHP
- **Frontend**: HTML, CSS, vanilla JavaScript (ES5 compatible for iPad 2)
- **Storage**: Local file-based cache/database (SQLite or JSON files)
- **API Integration**: Synology Photos API

### 2.2 Components
1. **Admin Interface**: Configuration and credential management
2. **Slideshow Display**: Public-facing photo viewer
3. **Background Scheduler**: Daily photo fetch process
4. **Cache Layer**: Local storage for daily photo metadata

## 3. Functional Requirements

### 3.1 Photo Retrieval

#### 3.1.1 Source Integration
- Connect to Synology Photos via API
- Authenticate using stored credentials (username/password or API token)
- Query photos by capture date

#### 3.1.2 Date Matching Logic
- Match exact calendar date (month and day) across all years
- Example: On December 2, 2025, retrieve all photos taken on December 2 of any previous year
- Respect configured "first year" setting to exclude photos older than specified year

#### 3.1.3 Photo Selection
- Retrieve ALL matching photos (no limit)
- Sort chronologically (oldest to newest)
- Cache results daily

### 3.2 Slideshow Display

#### 3.2.1 Core Functionality
- Auto-start slideshow on page load
- Display photos in chronological order
- Loop continuously when reaching the end
- Support full-screen viewing

#### 3.2.2 Photo Display
- Scale photos to fit screen while maintaining aspect ratio
- Display metadata overlay:
  - Year photo was taken
  - Location (if available in EXIF data)
- Metadata should be clearly readable but not obtrusive

#### 3.2.3 Transitions
- Configurable transition effect (default: simple fade)
- Configurable duration per photo (default: 5 seconds)
- Transition options should include:
  - Fade
  - None (instant)
  - Slide (if feasible for iPad 2)

#### 3.2.4 Controls
- Hidden by default
- Reveal on mouse movement or screen tap
- Control features:
  - Play/Pause toggle
  - Previous photo button
  - Next photo button
  - Display photo count (e.g., "5 of 23")
- Auto-hide controls after 3 seconds of inactivity

#### 3.2.5 Browser Compatibility
- Must work on Safari (iPad 2) - approximately iOS 9.3.5
- Use ES5 JavaScript only
- Avoid modern CSS features unsupported by older browsers
- Provide graceful degradation for unsupported features

### 3.3 Admin/Configuration Interface

#### 3.3.1 Authentication
- Password-protected admin area
- Simple username/password login
- Session management to keep admin logged in

#### 3.3.2 Configuration Settings

**Synology Connection:**
- Synology NAS URL/IP address
- Synology Photos username
- Synology Photos password (stored securely)
- Connection test button

**Photo Selection:**
- First/oldest year to include (e.g., don't show photos before 2010)

**Slideshow Settings:**
- Transition duration (seconds) - default: 5
- Transition effect (dropdown) - default: fade
- Auto-refresh time (what time each day to fetch new photos) - default: 12:00 AM

**Admin Security:**
- Change admin password
- Admin username (optional, can be hardcoded as "admin")

#### 3.3.3 Admin Features
- View current configuration
- Edit and save configuration
- Test Synology connection
- Manually trigger photo refresh
- View logs/status of last refresh
- Display count of photos for current date

### 3.4 Background Scheduler

#### 3.4.1 Daily Refresh Process
- Run automatically at configured time each day
- Fetch photos matching current date from Synology Photos
- Store photo metadata in local cache (overwrite previous day's data)
- Log success/failure with timestamp

#### 3.4.2 Cache Management
- Store photo metadata:
  - Photo ID/path from Synology
  - Capture date (full)
  - Year (extracted for display)
  - Location (if available)
  - Thumbnail URL or path
  - Full-resolution URL or path
- Use SQLite database or JSON file for cache
- Overwrite cache daily (no historical data retention needed)

#### 3.4.3 Error Handling
- Log errors if Synology connection fails
- Retain previous day's photos if refresh fails
- Display error indicator in admin interface

## 4. Non-Functional Requirements

### 4.1 Performance
- Slideshow page should load quickly on iPad 2
- Use thumbnail images for faster loading where possible
- Preload next photo during current photo display

### 4.2 Security
- Store Synology credentials encrypted or hashed
- Admin interface must require authentication
- Protect against common vulnerabilities (SQL injection, XSS)
- Use HTTPS if possible (depends on Synology setup)

### 4.3 Reliability
- Handle network failures gracefully
- Continue showing cached photos if Synology is unreachable
- Log all errors for troubleshooting

### 4.4 Usability
- Admin interface should be intuitive and simple
- Slideshow should "just work" without user interaction
- Mobile-friendly admin interface (bonus)

### 4.5 Maintainability
- Clean, documented code
- Separate configuration from code
- Easy to update settings without code changes

## 5. User Interface Specifications

### 5.1 Slideshow Page (/)
```
+--------------------------------------------------+
|                                                  |
|                                                  |
|                                                  |
|              [Photo Display Area]               |
|                                                  |
|                                                  |
|                                                  |
|  [Year: 2018]                    [Location: XYZ] |
+--------------------------------------------------+
     [< Prev] [Play/Pause] [Next >] [5 of 23]
     (Controls shown on hover/tap, auto-hide)
```

### 5.2 Admin Interface (/admin)

**Login Page:**
```
+---------------------------+
|   Rediscover This Day     |
|                           |
|  Username: [___________]  |
|  Password: [___________]  |
|                           |
|        [  Login  ]        |
+---------------------------+
```

**Configuration Page:**
```
+--------------------------------------------------+
| Rediscover This Day - Admin                      |
+--------------------------------------------------+
| 
| Synology Photos Connection
| ┌────────────────────────────────────────────┐
| │ NAS URL: [__________________________]      │
| │ Username: [__________________________]      │
| │ Password: [__________________________]      │
| │                                             │
| │ [Test Connection]                           │
| └────────────────────────────────────────────┘
|
| Photo Settings
| ┌────────────────────────────────────────────┐
| │ First Year: [____]  (e.g., 2010)           │
| └────────────────────────────────────────────┘
|
| Slideshow Settings
| ┌────────────────────────────────────────────┐
| │ Photo Duration: [___] seconds              │
| │ Transition: [Fade ▼]                       │
| │ Daily Refresh: [00:00] (HH:MM)             │
| └────────────────────────────────────────────┘
|
| Admin Security
| ┌────────────────────────────────────────────┐
| │ Current Password: [______________]         │
| │ New Password: [______________]             │
| │ Confirm: [______________]                  │
| └────────────────────────────────────────────┘
|
| [Save Configuration]
|
| Status
| ┌────────────────────────────────────────────┐
| │ Last Refresh: 2025-12-02 00:00:15          │
| │ Photos Today: 23                            │
| │ [Refresh Now]                               │
| └────────────────────────────────────────────┘
|
| [Logout]
+--------------------------------------------------+
```

## 6. API Integration Details

### 6.1 Synology Photos API
- Use Synology Photo Station API or Synology Photos API (newer)
- Required API calls:
  - Authentication/login
  - Query photos by date range
  - Retrieve photo metadata
  - Fetch photo files or URLs

### 6.2 Error Codes to Handle
- Authentication failures
- Network timeouts
- Invalid photo IDs
- Missing permissions

## 7. Data Models

### 7.1 Configuration
```
{
  "synology": {
    "url": "string",
    "username": "string",
    "password": "string (encrypted)"
  },
  "photos": {
    "first_year": "integer"
  },
  "slideshow": {
    "duration": "integer (seconds)",
    "transition": "string (fade|none|slide)"
  },
  "admin": {
    "password_hash": "string"
  }
}
```

### 7.2 Photo Cache
```
[
  {
    "id": "string (Synology photo ID)",
    "capture_date": "string (ISO 8601)",
    "year": "integer",
    "location": "string or null",
    "thumbnail_url": "string",
    "full_url": "string"
  },
  ...
]
```

## 8. File Structure (Recommended)

```
/rediscover-this-day/
├── admin/
│   ├── index.php (or index.py)
│   ├── login.php
│   └── config.php
├── api/
│   ├── synology.php (API wrapper)
│   └── photos.php (photo operations)
├── cache/
│   └── photos.json (or photos.db)
├── config/
│   └── config.json (encrypted)
├── cron/
│   └── daily_refresh.php (scheduled task)
├── css/
│   ├── admin.css
│   └── slideshow.css
├── js/
│   ├── admin.js
│   └── slideshow.js (ES5 compatible)
├── logs/
│   └── refresh.log
├── index.php (slideshow page)
└── README.md
```

## 9. Implementation Phases

### Phase 1: Core Infrastructure
- Set up project structure
- Implement configuration storage
- Create admin login and authentication

### Phase 2: Synology Integration
- Implement Synology Photos API wrapper
- Test connection and photo retrieval
- Implement date-based photo query

### Phase 3: Caching & Scheduling
- Build cache storage system
- Implement daily refresh logic
- Set up scheduled task (cron job)

### Phase 4: Slideshow Display
- Create basic slideshow HTML/CSS
- Implement photo loading and display
- Add transitions and timing
- Ensure iPad 2 compatibility

### Phase 5: Controls & Polish
- Add slideshow controls
- Implement metadata display
- Add admin status dashboard
- Testing and refinement

## 10. Testing Requirements

### 10.1 Browser Testing
- Safari on iPad 2 (primary target)
- Modern browsers (Chrome, Firefox, Safari) for admin
- Mobile browsers for admin interface

### 10.2 Functional Testing
- Synology connection with valid/invalid credentials
- Photo retrieval for dates with 0, 1, and many photos
- Slideshow transitions and controls
- Admin configuration changes
- Daily refresh automation
- Year filtering

### 10.3 Edge Cases
- No photos for current date
- Synology server unreachable
- Very large photo files
- Missing location metadata
- Photos from different timezones

## 11. Deployment Notes

### 11.1 Synology NAS Setup
- Install Web Station package
- Install PHP or Python package (if needed)
- Configure virtual host or subfolder
- Set up scheduled task for daily refresh

### 11.2 Initial Configuration
- Deploy files to web directory
- Set proper file permissions
- Create initial config file with default admin password
- Run first-time setup wizard (optional enhancement)

### 11.3 Scheduled Task
- Create scheduled task in Synology Task Scheduler
- Run daily_refresh script at configured time
- Ensure script has proper permissions to write to cache

## 12. Future Enhancements (Optional)

- Support multiple photo libraries
- Customizable themes
- Photo captions from EXIF description
- Share slideshow URL with friends/family
- Weekly or monthly recollection modes
- Weather data from photo date
- Music/audio accompaniment
- Memories/notes annotation system