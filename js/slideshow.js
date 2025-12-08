/* ES5-compatible slideshow JavaScript for iPad 2 compatibility */

(function() {
    'use strict';
    
    // Check if we have photos
    if (!photos || photos.length === 0) {
        return;
    }
    
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
    
    // Duration in milliseconds
    var duration = (config.duration || 5) * 1000;
    var transition = config.transition || 'fade';
    
    // Polling for updates
    var pollInterval = 5 * 60 * 1000; // 5 minutes
    var pollTimer = null;
    
    /**
     * Poll server for updated photos
     */
    function pollForUpdates() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/photos.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.photos && response.photos.length !== photos.length) {
                        photos = response.photos;
                        // If we have more photos and we're at the end, continue
                        if (currentIndex >= photos.length) {
                            currentIndex = photos.length - 1;
                        }
                        updatePhotoContent(photos[currentIndex]);
                    }
                } catch (e) {
                    // Ignore parsing errors
                }
            }
        };
        xhr.send();
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
            (photo.landmark && photo.landmark.trim() !== '') ||
            (photo.route && photo.route.trim() !== '') ||
            (photo.village_district_town_city && photo.village_district_town_city.trim() !== '') ||
            (photo.county && photo.county.trim() !== '') ||
            (photo.state && photo.state.trim() !== '') ||
            (photo.country && photo.country.trim() !== '')
        );

        if (!hasLocationData) {
            locationSpan.textContent = 'Location Missing';
        } else {
            locationSpan.textContent = '';
        }

        // Set landmark
        landmarkSpan.textContent = photo.landmark || '';

        // Set route
        routeSpan.textContent = photo.route || '';

        // Set village/district/town/city
        villageDistrictTownCitySpan.textContent = photo.village_district_town_city || '';

        // Set county
        countySpan.textContent = photo.county || '';

        // Set state
        stateSpan.textContent = photo.state || '';

        // Set country
        countrySpan.textContent = photo.country || '';
        
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
     * Show controls
     */
    function showControls() {
        controls.classList.add('visible');
        
        // Clear existing timer
        if (controlsTimer) {
            clearTimeout(controlsTimer);
        }
        
        // Auto-hide after 3 seconds
        controlsTimer = setTimeout(function() {
            controls.classList.remove('visible');
        }, 3000);
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
        // Show first photo
        showPhoto(0);
        
        // Start slideshow
        startSlideshow();
        
        // Start polling for updates
        pollTimer = setInterval(pollForUpdates, pollInterval);
        
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
            controls.classList.remove('visible');
        }, 3000);
    }
    
    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
