/* ES5-compatible Year Review - Places Explored */

(function() {
    'use strict';

    // DOM elements
    var loadingScreen = document.getElementById('loading-screen');
    var errorScreen = document.getElementById('error-screen');
    var errorText = document.getElementById('error-text');
    var photoDisplay = document.getElementById('photo-display');
    var statDisplay = document.getElementById('stat-display');
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
    var summary = null;
    var hierarchy = null;

    // Config
    var duration = (config.duration || 5) * 1000;

    /**
     * Load photos from API
     */
    function loadPhotos() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/year_photos.php?year=' + selectedYear, true);

        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);

                        if (response.success && response.months && response.months.length > 0) {
                            summary = response.year_summary;
                            buildSlideshow(response.months);
                            loadingScreen.style.display = 'none';
                            controls.style.display = 'flex';
                            init();
                        } else {
                            var message = response.months && response.months.length === 0
                                ? 'No photos found for ' + selectedYear
                                : 'Failed to load photos';
                            showError(message);
                        }
                    } catch (e) {
                        showError('Failed to parse response: ' + e.message);
                    }
                } else {
                    showError('Failed to load photos (HTTP ' + xhr.status + ')');
                }
            }
        };

        xhr.onerror = function() {
            showError('Network error - please check connection');
        };

        xhr.timeout = 60000;
        xhr.ontimeout = function() {
            showError('Request timed out - server may be slow');
        };

        xhr.send();
    }

    /**
     * Build slideshow array
     */
    function buildSlideshow(months) {
        slides = [];
        
        // Add year summary slide first
        slides.push({ type: 'year-summary' });
        
        // Add month summary and location slides for each month
        for (var i = 0; i < months.length; i++) {
            var month = months[i];
            
            // Add month summary slide
            slides.push({ type: 'month-summary', data: month.summary });
            
            // Add location slides for this month
            for (var j = 0; j < month.location_slides.length; j++) {
                slides.push({ type: 'location', data: month.location_slides[j] });
            }
        }
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

        if (slide.type === 'year-summary') {
            showYearSummarySlide();
        } else if (slide.type === 'month-summary') {
            showMonthSummarySlide(slide.data);
        } else if (slide.type === 'summary') {
            showYearSummarySlide();
        } else if (slide.type === 'location') {
            showLocationSlide(slide.data);
        }

        // Update counter
        counter.textContent = (currentIndex + 1) + ' of ' + slides.length;
    }

    /**
     * Show year summary slide
     */
    function showYearSummarySlide() {
        photoDisplay.style.display = 'none';
        statDisplay.style.display = 'flex';
        
        statDisplay.style.opacity = '0';
        setTimeout(function() {
            statTitle.textContent = selectedYear + ' - Places Explored';
            
            var summaryHtml = '<div class="summary-stats">';
            summaryHtml += '<div>' + summary.countries + ' Countries</div>';
            summaryHtml += '<div>' + summary.states + ' States</div>';
            summaryHtml += '<div>' + summary.cities + ' Cities</div>';
            summaryHtml += '<div>' + summary.total_photos + ' Photos</div>';
            summaryHtml += '</div>';
            
            statValue.innerHTML = summaryHtml;
            statDetail.textContent = 'Organized by month and location';
            
            statDisplay.style.opacity = '1';
        }, 300);
    }

    /**
     * Show month summary slide
     */
    function showMonthSummarySlide(monthData) {
        photoDisplay.style.display = 'none';
        statDisplay.style.display = 'flex';
        
        statDisplay.style.opacity = '0';
        setTimeout(function() {
            statTitle.textContent = monthData.month_name + ' ' + selectedYear;
            
            var summaryHtml = '<div class="summary-stats">';
            summaryHtml += '<div>' + monthData.countries + ' Countries</div>';
            summaryHtml += '<div>' + monthData.states + ' States</div>';
            summaryHtml += '<div>' + monthData.cities + ' Cities</div>';
            summaryHtml += '<div>' + monthData.total_photos + ' Photos</div>';
            summaryHtml += '</div>';
            
            // Add locations list
            if (monthData.locations && monthData.locations.length > 0) {
                summaryHtml += '<div class="hierarchy-list">';
                var currentCountry = '';
                var currentState = '';
                
                for (var i = 0; i < monthData.locations.length; i++) {
                    var loc = monthData.locations[i];
                    
                    // New country
                    if (loc.country !== currentCountry) {
                        if (currentCountry !== '') {
                            summaryHtml += '</div>'; // Close previous country group
                        }
                        currentCountry = loc.country;
                        currentState = ''; // Reset state
                        summaryHtml += '<div class="country-group">';
                        summaryHtml += '<div class="country-name">' + loc.country + '</div>';
                    }
                    
                    // New state within country
                    if (loc.state !== currentState) {
                        currentState = loc.state;
                        summaryHtml += '<div class="state-name">- ' + loc.state + '</div>';
                    }
                    
                    // Add city
                    summaryHtml += '<div class="city-name">  • ' + loc.city + '</div>';
                }
                
                if (currentCountry !== '') {
                    summaryHtml += '</div>'; // Close last country group
                }
                summaryHtml += '</div>';
            }
            
            statValue.innerHTML = summaryHtml;
            statDetail.textContent = '';
            
            statDisplay.style.opacity = '1';
        }, 300);
    }

    /**
     * Show summary slide with hierarchy (legacy - redirects to year summary)
     */
    function showSummarySlide() {
        showYearSummarySlide();
    }

    /**
     * Show location slide with multiple photos using Justified Gallery
     */
    function showLocationSlide(locationData) {
        statDisplay.style.display = 'none';
        photoDisplay.style.display = 'flex';
        
        photoDisplay.style.opacity = '0';
        setTimeout(function() {
            var locationTitle = locationData.city + ', ' + locationData.state;
            if (locationData.country !== 'United States') {
                locationTitle += ', ' + locationData.country;
            }
            
            var gridHtml = '<div class="location-header">';
            gridHtml += '<h2>' + locationTitle + '</h2>';
            var pageInfo = locationData.photo_count + ' photo' + (locationData.photo_count !== 1 ? 's' : '');
            if (locationData.total_pages > 1) {
                pageInfo += ' (page ' + locationData.page + ' of ' + locationData.total_pages + ')';
            }
            gridHtml += '<p>' + pageInfo + '</p>';
            gridHtml += '</div>';
            
            gridHtml += '<div class="photo-grid" id="justified-gallery">';
            for (var i = 0; i < locationData.photos.length; i++) {
                var photo = locationData.photos[i];
                var imagePath = 'cache/' + photo.cached_filename;
                gridHtml += '<a href="' + imagePath + '">';
                gridHtml += '<img src="' + imagePath + '" alt="' + photo.filename + '">';
                gridHtml += '</a>';
            }
            gridHtml += '</div>';
            
            photoDisplay.innerHTML = gridHtml;
            
            // Initialize Justified Gallery
            jQuery('#justified-gallery').justifiedGallery({
                rowHeight: 300,
                maxRowHeight: 400,
                margins: 8,
                border: 0,
                lastRow: 'nojustify',
                captions: false,
                randomize: false,
                waitThumbnailsLoad: false,
                rel: 'gallery',
                cssAnimation: true
            });
            
            photoDisplay.style.opacity = '1';
        }, 300);
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
