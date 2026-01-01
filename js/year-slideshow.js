/* ES5-compatible Year Review Slideshow with Statistics */

(function() {
    'use strict';

    // DOM elements
    var loadingScreen = document.getElementById('loading-screen');
    var errorScreen = document.getElementById('error-screen');
    var errorText = document.getElementById('error-text');
    var photoDisplay = document.getElementById('photo-display');
    var statDisplay = document.getElementById('stat-display');
    var photoImg = document.getElementById('current-photo');
    var yearSpan = document.getElementById('year');
    var dateSpan = document.getElementById('date');
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
    var statTitle = document.querySelector('.stat-title');
    var statValue = document.querySelector('.stat-value');
    var statDetail = document.querySelector('.stat-detail');
    var counter = document.getElementById('counter');
    var controls = document.getElementById('controls');
    var backBtn = document.getElementById('back-btn');
    var prevBtn = document.getElementById('prev-btn');
    var playPauseBtn = document.getElementById('play-pause-btn');
    var nextBtn = document.getElementById('next-btn');

    // State
    var slides = [];
    var currentIndex = 0;
    var isPlaying = false;
    var slideshowTimer = null;
    var controlsTimer = null;
    var stats = null;

    // Config
    var duration = (config.duration || 5) * 1000;
    var statFrequency = (yearReviewConfig.stat_frequency || 8);

    /**
     * Load photos and stats from API
     */
    function loadPhotos() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/year_photos.php?year=' + selectedYear, true);

        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);

                        if (response.success && response.photos && response.photos.length > 0) {
                            stats = response.stats;
                            buildSlideshow(response.photos);
                            loadingScreen.style.display = 'none';
                            controls.style.display = 'flex';
                            init();
                        } else {
                            var message = response.photos && response.photos.length === 0
                                ? 'No photos found for ' + selectedYear
                                : 'Failed to load photos';
                            showError(message);
                        }
                    } catch (e) {
                        showError('Failed to parse response');
                    }
                } else {
                    showError('Failed to load photos (HTTP ' + xhr.status + ')');
                }
            }
        };

        xhr.onerror = function() {
            showError('Network error - please check connection');
        };

        xhr.timeout = 60000; // 60 second timeout
        xhr.ontimeout = function() {
            showError('Request timed out - server may be slow');
        };

        xhr.send();
    }

    /**
     * Build slideshow array with photos and stats interspersed
     */
    function buildSlideshow(photos) {
        slides = [];

        // Add intro stat
        slides.push({ type: 'stat', stat: 'intro' });

        // Add photos with stat slides interspersed
        for (var i = 0; i < photos.length; i++) {
            slides.push({ type: 'photo', photo: photos[i] });

            // Add stat slide after every N photos
            if ((i + 1) % statFrequency === 0) {
                var statType = getNextStatType((i + 1) / statFrequency);
                if (statType) {
                    slides.push({ type: 'stat', stat: statType });
                }
            }
        }
    }

    /**
     * Get next stat type based on sequence
     */
    function getNextStatType(sequence) {
        var statTypes = ['locations', 'most_photographed', 'top_tags', 'monthly'];
        var index = (sequence - 1) % statTypes.length;
        return statTypes[index];
    }

    /**
     * Show error screen
     */
    function showError(message) {
        loadingScreen.style.display = 'none';
        photoDisplay.style.display = 'none';
        statDisplay.style.display = 'none';
        errorScreen.style.display = 'flex';
        errorText.textContent = message;
    }

    /**
     * Display slide at given index
     */
    function showSlide(index) {
        if (index < 0 || index >= slides.length) {
            return;
        }

        currentIndex = index;
        var slide = slides[index];

        if (slide.type === 'photo') {
            showPhotoSlide(slide.photo);
        } else if (slide.type === 'stat') {
            showStatSlide(slide.stat);
        }

        // Update counter
        counter.textContent = (currentIndex + 1) + ' of ' + slides.length;
    }

    /**
     * Show photo slide
     */
    function showPhotoSlide(photo) {
        // Hide stat display, show photo display
        statDisplay.style.display = 'none';
        photoDisplay.style.display = 'flex';

        // Apply fade transition
        photoImg.style.opacity = '0';

        setTimeout(function() {
            updatePhotoContent(photo);
            photoImg.style.opacity = '1';
        }, 300);
    }

    /**
     * Show stat slide
     */
    function showStatSlide(statType) {
        // Hide photo display, show stat display
        photoDisplay.style.display = 'none';
        statDisplay.style.display = 'flex';

        // Apply fade transition
        statDisplay.style.opacity = '0';

        setTimeout(function() {
            renderStat(statType);
            statDisplay.style.opacity = '1';
        }, 300);
    }

    /**
     * Update photo content
     */
    function updatePhotoContent(photo) {
        // Set image source from local cache (same as regular slideshow)
        var imagePath = 'cache/' + photo.cached_filename;
        photoImg.src = imagePath;

        // Convert timestamp to date (same as regular slideshow)
        var photoDate = new Date(photo.time * 1000);
        var year = photoDate.getFullYear();
        var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                          'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var month = monthNames[photoDate.getMonth()];
        var day = photoDate.getDate();

        // Set year
        yearSpan.textContent = year;

        // Set date (full date for year review)
        dateSpan.textContent = month + ' ' + day + ', ' + year;

        // Set location
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

        landmarkSpan.textContent = photo.landmark || '';
        routeSpan.textContent = photo.route || '';
        villageDistrictTownCitySpan.textContent = photo.village_district_town_city || '';
        countySpan.textContent = photo.county || '';
        stateSpan.textContent = photo.state || '';
        countrySpan.textContent = photo.country || '';

        // Set image count
        imageCountSpan.textContent = (currentIndex + 1) + ' of ' + slides.length;

        // Set tags
        var tags = photo.tags || [];
        var maxTags = 10;

        if (tags.length === 0) {
            tagsLabel.textContent = 'Tags';
            tagsList.textContent = 'No Tags';
        } else {
            var sortedTags = tags.slice().sort();
            tagsLabel.textContent = 'Tags';
            tagsList.innerHTML = '';

            var displayCount = Math.min(sortedTags.length, maxTags);
            for (var i = 0; i < displayCount; i++) {
                var tagDiv = document.createElement('div');
                tagDiv.className = 'tag-item';
                tagDiv.textContent = sortedTags[i];
                tagsList.appendChild(tagDiv);
            }

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
     * Render stat slide
     */
    function renderStat(statType) {
        if (statType === 'intro') {
            statTitle.textContent = stats.year;
            statValue.textContent = stats.total_photos + ' Photos';
            statDetail.textContent = 'A year in review';
        } else if (statType === 'locations') {
            statTitle.textContent = 'Places Explored';
            statValue.textContent = stats.unique_cities + ' cities';

            // Build detail with state and country names
            var detailParts = [];

            if (stats.states_list && stats.states_list.length > 0) {
                var statesText = stats.states_list.join(', ');
                detailParts.push(statesText);
            }

            if (stats.countries_list && stats.countries_list.length > 0) {
                var countriesText = stats.countries_list.join(', ');
                detailParts.push(countriesText);
            }

            statDetail.textContent = detailParts.join(' • ');
        } else if (statType === 'most_photographed') {
            statTitle.textContent = 'Most Photographed';
            statValue.textContent = stats.most_photographed_location;
            statDetail.textContent = 'Your favorite spot this year';
        } else if (statType === 'top_tags') {
            statTitle.textContent = 'Top Tags';
            if (stats.top_tags && stats.top_tags.length > 0) {
                statValue.textContent = stats.top_tags.slice(0, 3).join(', ');
                if (stats.top_tags.length > 3) {
                    statDetail.textContent = 'and ' + (stats.top_tags.length - 3) + ' more';
                } else {
                    statDetail.textContent = 'Your most used tags';
                }
            } else {
                statValue.textContent = 'No tags used';
                statDetail.textContent = '';
            }
        } else if (statType === 'monthly') {
            statTitle.textContent = 'Peak Activity';
            statValue.textContent = stats.most_active_month;
            statDetail.textContent = 'Your most photographed month';
        }
    }

    /**
     * Preload next photo (if next slide is a photo)
     */
    function preloadNextPhoto() {
        var nextIndex = (currentIndex + 1) % slides.length;
        var nextSlide = slides[nextIndex];
        if (nextSlide && nextSlide.type === 'photo') {
            var img = new Image();
            var imagePath = 'cache/' + nextSlide.photo.cached_filename;
            img.src = imagePath;
        }
    }

    /**
     * Show next slide
     */
    function nextSlide() {
        var nextIndex = (currentIndex + 1) % slides.length;
        showSlide(nextIndex);

        if (isPlaying) {
            resetSlideshowTimer();
        }
    }

    /**
     * Show previous slide
     */
    function prevSlide() {
        var prevIndex = currentIndex - 1;
        if (prevIndex < 0) {
            prevIndex = slides.length - 1;
        }
        showSlide(prevIndex);

        if (isPlaying) {
            resetSlideshowTimer();
        }
    }

    /**
     * Start slideshow
     */
    function startSlideshow() {
        isPlaying = true;
        playPauseBtn.innerHTML = '&#10074;&#10074;';
        playPauseBtn.title = 'Pause';

        resetSlideshowTimer();
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
        playPauseBtn.innerHTML = '&#9658;';
        playPauseBtn.title = 'Play';
    }

    /**
     * Reset slideshow timer
     */
    function resetSlideshowTimer() {
        if (slideshowTimer) {
            clearInterval(slideshowTimer);
        }

        slideshowTimer = setInterval(nextSlide, duration);
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

        if (controlsTimer) {
            clearTimeout(controlsTimer);
        }

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

        if (key === 37 || key === 65) { // Left or A
            prevSlide();
        } else if (key === 39 || key === 68) { // Right or D
            nextSlide();
        } else if (key === 32) { // Space
            e.preventDefault();
            togglePlayPause();
        } else if (key === 27) { // Escape
            window.location.href = 'year-review.php';
        }
    }

    /**
     * Initialize slideshow
     */
    function init() {
        // Show first slide
        showSlide(0);

        // Start slideshow
        startSlideshow();

        // Event listeners
        backBtn.addEventListener('click', function() {
            window.location.href = 'year-review.php';
        });

        prevBtn.addEventListener('click', function() {
            prevSlide();
            showControls();
        });

        nextBtn.addEventListener('click', function() {
            nextSlide();
            showControls();
        });

        playPauseBtn.addEventListener('click', function() {
            togglePlayPause();
            showControls();
        });

        document.addEventListener('mousemove', showControls);
        document.addEventListener('touchstart', showControls);
        document.addEventListener('keydown', handleKeyPress);

        // Hide controls after 3 seconds
        setTimeout(function() {
            controls.classList.remove('visible');
        }, 3000);
    }

    // Start loading photos
    loadPhotos();
})();
