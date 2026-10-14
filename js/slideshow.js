/* ES5-compatible slideshow JavaScript for iPad 2 compatibility */

(function() {
    'use strict';
    
    // Check if we have photos (polling still runs when empty, so a later
    // refresh can bring photos in without a manual reload)
    var hasPhotos = !!(photos && photos.length);
    
    // Slideshow state
    var currentIndex = 0;
    var isPlaying = true;
    var slideshowTimer = null;
    var controlsTimer = null;
    
    // DOM elements
    var photoImg = document.getElementById('current-photo');
    var yearSpan = document.getElementById('year');
    var locationSpan = document.getElementById('location');
    var landmarkSpan = document.getElementById('landmark');
    var routeSpan = document.getElementById('route');
    var villageDistrictTownCitySpan = document.getElementById('village_district_town_city');
    var countySpan = document.getElementById('county');
    var stateSpan = document.getElementById('state');
    var countrySpan = document.getElementById('country');
    var imageCountSpan = document.getElementById('image-count');
    var tagsLabel = document.getElementById('tags-label');
    var tagsList = document.getElementById('tags-list');
    var counter = document.getElementById('counter');
    var controls = document.getElementById('controls');
    var prevBtn = document.getElementById('prev-btn');
    var playPauseBtn = document.getElementById('play-pause-btn');
    var nextBtn = document.getElementById('next-btn');
    var yrBtn = document.getElementById('year-review-btn');
    var yrBtnEmpty = document.getElementById('year-review-btn-empty');
    var yrArmed = false; // was the Year in Review button visible when this tap began?
    
    // Duration in milliseconds
    var duration = (config.duration || 5) * 1000;
    var transition = config.transition || 'fade';
    
    // Polling for updates
    var pollInterval = 5 * 60 * 1000; // 5 minutes
    var pollTimer = null;
    
    /**
     * Poll server for a new photo cache; reload the page when its version changes
     */
    function pollForUpdates() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/photos.php?_=' + new Date().getTime(), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                var response = null;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    // Ignore parsing errors
                    return;
                }
                if (response && response.success === true &&
                        typeof response.version === 'string' &&
                        response.version !== photosVersion) {
                    window.location.reload();
                }
            }
        };
        xhr.send();
    }

    /**
     * Normalise a metadata value to display text (arrays are joined, non-strings become '')
     */
    function textOf(v) {
        if (v && typeof v === 'object' && typeof v.join === 'function') {
            return v.join(', ');
        }
        return typeof v === 'string' ? v : '';
    }
    
    /**
     * Display photo at given index
     */
    function showPhoto(index) {
        if (index < 0 || index >= photos.length) {
            return;
        }
        
        currentIndex = index;
        var photo = photos[index];
        
        // Apply transition effect
        if (transition === 'fade') {
            photoImg.style.opacity = '0';
            
            setTimeout(function() {
                updatePhotoContent(photo);
                photoImg.style.opacity = '1';
            }, 300);
        } else {
            updatePhotoContent(photo);
        }
        
        // Update counter
        counter.textContent = (currentIndex + 1) + ' of ' + photos.length;
    }
    
    /**
     * Update photo content
     */
    function updatePhotoContent(photo) {
        // Set image source
        var imagePath = 'cache/' + photo.cached_filename;
        photoImg.src = imagePath;
        
        // Set metadata
        var photoDate = new Date(photo.time * 1000);
        var year = photoDate.getFullYear();
        
        // Set year
        yearSpan.textContent = year;
        
        // Set location (always show, display "Missing Location" if none available)
        var hasLocationData = (
            textOf(photo.landmark).trim() !== '' ||
            textOf(photo.route).trim() !== '' ||
            textOf(photo.village_district_town_city).trim() !== '' ||
            textOf(photo.county).trim() !== '' ||
            textOf(photo.state).trim() !== '' ||
            textOf(photo.country).trim() !== ''
        );

        if (!hasLocationData) {
            locationSpan.textContent = 'Location Missing';
        } else {
            locationSpan.textContent = '';
        }

        // Set landmark
        landmarkSpan.textContent = textOf(photo.landmark);

        // Set route
        routeSpan.textContent = textOf(photo.route);

        // Set village/district/town/city
        villageDistrictTownCitySpan.textContent = textOf(photo.village_district_town_city);

        // Set county
        countySpan.textContent = textOf(photo.county);

        // Set state
        stateSpan.textContent = textOf(photo.state);

        // Set country
        countrySpan.textContent = textOf(photo.country);
        
        // Set image count (e.g., "5 of 85")
        var currentNumber = currentIndex + 1;
        var totalCount = photos.length;
        imageCountSpan.textContent = currentNumber + ' of ' + totalCount;

        // Set tags
        var tags = photo.tags || [];
        var maxTags = 10;

        if (tags.length === 0) {
            tagsLabel.textContent = 'Tags';
            tagsList.textContent = 'No Tags';
        } else {
            // Sort tags alphabetically
            var sortedTags = tags.slice().sort();

            tagsLabel.textContent = 'Tags';

            // Clear previous tags
            tagsList.innerHTML = '';

            // Display first 10 tags
            var displayCount = Math.min(sortedTags.length, maxTags);
            for (var i = 0; i < displayCount; i++) {
                var tagDiv = document.createElement('div');
                tagDiv.className = 'tag-item';
                tagDiv.textContent = sortedTags[i];
                tagsList.appendChild(tagDiv);
            }

            // If there are more than 10 tags, add "plus X more tags" line
            if (sortedTags.length > maxTags) {
                var remainingCount = sortedTags.length - maxTags;
                var moreTagsDiv = document.createElement('div');
                moreTagsDiv.className = 'tag-item tag-more';
                moreTagsDiv.textContent = 'plus ' + remainingCount + ' more tags';
                tagsList.appendChild(moreTagsDiv);
            }
        }

        // Preload next photo
        preloadNextPhoto();
    }
    
    /**
     * Preload next photo
     */
    function preloadNextPhoto() {
        var nextIndex = (currentIndex + 1) % photos.length;
        var nextPhoto = photos[nextIndex];
        var img = new Image();
        img.src = 'cache/' + nextPhoto.cached_filename;
    }
    
    /**
     * Show next photo
     */
    function nextPhoto() {
        var nextIndex = (currentIndex + 1) % photos.length;
        showPhoto(nextIndex);

        // Reset timer if autoplay is active
        if (isPlaying) {
            startSlideshow();
        }
    }

    /**
     * Show previous photo
     */
    function prevPhoto() {
        var prevIndex = currentIndex - 1;
        if (prevIndex < 0) {
            prevIndex = photos.length - 1;
        }
        showPhoto(prevIndex);

        // Reset timer if autoplay is active
        if (isPlaying) {
            startSlideshow();
        }
    }
    
    /**
     * Start slideshow
     */
    function startSlideshow() {
        if (slideshowTimer) {
            clearInterval(slideshowTimer);
        }
        
        isPlaying = true;
        playPauseBtn.innerHTML = '&#10074;&#10074;'; // Pause symbol
        playPauseBtn.title = 'Pause';
        
        slideshowTimer = setInterval(nextPhoto, duration);
    }
    
    /**
     * Stop slideshow
     */
    function stopSlideshow() {
        if (slideshowTimer) {
            clearInterval(slideshowTimer);
            slideshowTimer = null;
        }
        
        isPlaying = false;
        playPauseBtn.innerHTML = '&#9658;'; // Play symbol
        playPauseBtn.title = 'Play';
    }
    
    /**
     * Toggle play/pause
     */
    function togglePlayPause() {
        if (isPlaying) {
            stopSlideshow();
        } else {
            startSlideshow();
        }
    }
    
    /**
     * Show or hide the bottom controls and the Year in Review button together
     */
    function setControlsVisible(on) {
        if (controls) {
            controls.classList[on ? 'add' : 'remove']('visible');
        }
        if (yrBtn) {
            yrBtn.classList[on ? 'add' : 'remove']('visible');
        }
        if (!on) {
            yrArmed = false;
        }
    }
    
    /**
     * Show controls
     */
    function showControls() {
        setControlsVisible(true);
        
        // Clear existing timer
        if (controlsTimer) {
            clearTimeout(controlsTimer);
        }
        
        // Auto-hide after 3 seconds
        controlsTimer = setTimeout(function() {
            setControlsVisible(false);
        }, 3000);
    }
    
    /**
     * Year in Review buttons (upper-left on the slideshow, inline on the empty screen)
     */
    function setupYearReviewButtons() {
        if (yrBtnEmpty) {
            // Always visible: no tap guard
            yrBtnEmpty.addEventListener('click', function() {
                window.location.href = 'year-review.php';
            });
        }
        
        if (yrBtn) {
            // Runs before the document-level touchstart (which reveals the controls),
            // so it records whether the button was already visible before this tap
            yrBtn.addEventListener('touchstart', function() {
                yrArmed = yrBtn.classList.contains('visible');
            });
            
            yrBtn.addEventListener('click', function() {
                // A tap on the hidden button only reveals it
                if (!yrArmed && 'ontouchstart' in window) {
                    return;
                }
                window.location.href = 'year-review.php';
            });
        }
    }
    
    /**
     * Handle keyboard events
     */
    function handleKeyPress(e) {
        var key = e.keyCode || e.which;
        
        showControls();
        
        // Left arrow or 'a'
        if (key === 37 || key === 65) {
            prevPhoto();
        }
        // Right arrow or 'd'
        else if (key === 39 || key === 68) {
            nextPhoto();
        }
        // Space bar
        else if (key === 32) {
            e.preventDefault();
            togglePlayPause();
        }
    }
    
    /**
     * Initialize slideshow
     */
    function init() {
        // Start polling for updates (also on the empty "No photos" screen)
        pollTimer = setInterval(pollForUpdates, pollInterval);
        
        setupYearReviewButtons();
        
        if (!hasPhotos) {
            return;
        }
        
        // Show first photo
        showPhoto(0);
        
        // Start slideshow
        startSlideshow();
        
        // Event listeners
        prevBtn.addEventListener('click', function() {
            prevPhoto();
            showControls();
        });
        
        nextBtn.addEventListener('click', function() {
            nextPhoto();
            showControls();
        });
        
        playPauseBtn.addEventListener('click', function() {
            togglePlayPause();
            showControls();
        });
        
        // Show controls on mouse move or touch
        document.addEventListener('mousemove', showControls);
        document.addEventListener('touchstart', showControls);
        
        // Keyboard controls
        document.addEventListener('keydown', handleKeyPress);
        
        // Initially hide controls after 3 seconds
        setTimeout(function() {
            setControlsVisible(false);
        }, 3000);
    }
    
    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
