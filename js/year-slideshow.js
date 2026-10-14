/* ES5-compatible Year Review - Places Explored */

(function() {
    'use strict';

    // DOM elements
    var loadingScreen = document.getElementById('loading-screen');
    var loadingBackBtn = document.getElementById('loading-back-btn');
    var errorScreen = document.getElementById('error-screen');
    var errorTitle = document.getElementById('error-title');
    var errorText = document.getElementById('error-text');
    var errorBackBtn = document.getElementById('error-back-btn');
    var errorRetryBtn = document.getElementById('error-retry-btn');
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
    var fadeTimer = null;
    var summary = null;
    var activeXhr = null;
    var preloaded = [];
    var preloadedIndex = -1;
    var GRID_FIT_RETRIES = 3; // smaller re-layouts of a grid that doesn't fit

    // Config
    var duration = (config.duration || 5) * 1000;

    /**
     * Escape a value for use inside innerHTML (text or attribute)
     */
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Leave for the year picker (stays full screen in the Home Screen app)
     */
    function goBack() {
        if (activeXhr) {
            var xhr = activeXhr;
            activeXhr = null;
            xhr.abort();
        }
        window.location.href = 'year-review.php';
    }

    /**
     * Wire every button and listener once (before loading, so Back always works)
     */
    function setupNavigation() {
        // Hidden until the slides are loaded
        controls.style.display = 'none';

        backBtn.addEventListener('click', goBack);
        loadingBackBtn.addEventListener('click', goBack);
        errorBackBtn.addEventListener('click', goBack);

        errorRetryBtn.addEventListener('click', function() {
            errorScreen.style.display = 'none';
            loadingScreen.style.display = '';
            loadPhotos();
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
            if (slides.length === 0) {
                return;
            }
            togglePlayPause();
            showControls();
        });

        document.addEventListener('mousemove', showControls);
        document.addEventListener('touchstart', showControls);
        document.addEventListener('keydown', handleKeyPress);
    }

    /**
     * Load the slide list from the API
     */
    function loadPhotos() {
        if (activeXhr) {
            var old = activeXhr;
            activeXhr = null;
            old.abort();
        }

        var xhr = new XMLHttpRequest();
        var handled = false;
        activeXhr = xhr;

        function finish() {
            if (handled || activeXhr !== xhr) {
                return false;
            }
            handled = true;
            activeXhr = null;
            return true;
        }

        function fail() {
            if (finish()) {
                showMessage('Unable to load photos', 'Please try again.', true);
            }
        }

        xhr.open('GET', 'api/year_photos.php?year=' + selectedYear, true);
        xhr.timeout = 60000;

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) {
                return;
            }
            if (xhr.status === 0) {
                // Network error / timeout (onerror / ontimeout normally get it
                // first; fail() runs once). An abort is ignored by finish().
                setTimeout(fail, 0);
                return;
            }

            var response = null;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }

            if (xhr.status === 200 && response && response.success === true &&
                    response.months && typeof response.months.length === 'number') {
                if (!finish()) {
                    return;
                }
                if (response.months.length === 0) {
                    showMessage('No photos from ' + selectedYear, 'Try another year.', false);
                    return;
                }
                summary = response.year_summary || {};
                buildSlideshow(response.months);
                loadingScreen.style.display = 'none';
                controls.style.display = 'flex';
                init();
            } else if (xhr.status === 403 && response && response.disabled) {
                if (finish()) {
                    showMessage('Year in Review is turned off', '', false);
                }
            } else {
                fail();
            }
        };

        xhr.onerror = fail;
        xhr.ontimeout = fail;

        xhr.send();
    }

    /**
     * Build slideshow array
     */
    function buildSlideshow(months) {
        slides = [];

        // Year summary slide first
        slides.push({ type: 'year-summary' });

        // Month summary, then that month's location slides
        for (var i = 0; i < months.length; i++) {
            var month = months[i];
            slides.push({ type: 'month-summary', data: month.summary });

            var locationSlides = month.location_slides || [];
            for (var j = 0; j < locationSlides.length; j++) {
                slides.push({ type: 'location', data: locationSlides[j] });
            }
        }
    }

    /**
     * Show a message on the error screen (Back always; Retry only if allowed)
     */
    function showMessage(title, text, allowRetry) {
        loadingScreen.style.display = 'none';
        photoDisplay.style.display = 'none';
        statDisplay.style.display = 'none';
        controls.style.display = 'none';
        errorTitle.textContent = title;
        errorText.textContent = text;
        errorText.style.display = text ? '' : 'none';
        errorRetryBtn.style.display = allowRetry ? '' : 'none';
        errorScreen.style.display = 'flex';
    }

    /**
     * Tear down justifiedGallery (clears its timers) before the grid is replaced
     */
    function destroyGallery() {
        if (window.jQuery && jQuery.fn.justifiedGallery) {
            var $g = jQuery('#justified-gallery');
            if ($g.length) {
                $g.justifiedGallery('destroy');
            }
        }
    }

    /**
     * Display slide at given index. The ONLY function that changes the display.
     */
    function showSlide(index) {
        if (index < 0 || index >= slides.length) {
            return;
        }

        clearTimeout(fadeTimer);

        currentIndex = index;
        counter.textContent = (currentIndex + 1) + ' of ' + slides.length;

        var slide = slides[index];
        var target;

        // Fade out
        if (slide.type === 'location') {
            statDisplay.style.display = 'none';
            target = photoDisplay;
        } else {
            photoDisplay.style.display = 'none';
            target = statDisplay;
        }
        target.style.display = 'flex';
        target.style.opacity = '0';

        fadeTimer = setTimeout(function() {
            if (index !== currentIndex) {
                return;
            }

            // Tear down the old grid only now (after the fade-out), so it doesn't
            // collapse while still fading. With quick taps, earlier callbacks were
            // cleared, so this surviving one destroys whatever grid is in place.
            destroyGallery();

            if (slide.type === 'year-summary') {
                renderYearSummarySlide();
            } else if (slide.type === 'month-summary') {
                renderMonthSummarySlide(slide.data);
            } else if (slide.type === 'location') {
                renderLocationSlide(slide.data, index);
            }

            // Fade in
            target.style.opacity = '1';
            preloadNext(index);
        }, 300);
    }

    /**
     * Preload the photos of the next location slide (one slide only)
     */
    function preloadNext(index) {
        var images = [];

        for (var step = 1; step < slides.length; step++) {
            var target = (index + step) % slides.length;
            var slide = slides[target];
            if (slide.type !== 'location') {
                continue;
            }
            if (target === preloadedIndex) {
                // Already preloading this slide (e.g. passing a month summary)
                return;
            }
            preloadedIndex = target;
            var photos = slide.data.photos || [];
            for (var i = 0; i < photos.length && i < 9; i++) {
                var img = new Image();
                img.src = photos[i].src;
                images.push(img);
            }
            break;
        }

        // Replace (and so release) the previous preloads
        preloaded = images;
    }

    /**
     * Summary counts block
     */
    function summaryStatsHtml(data) {
        var html = '<div class="summary-stats">';
        html += '<div>' + escapeHtml(String(data.countries || 0)) + ' Countries</div>';
        html += '<div>' + escapeHtml(String(data.states || 0)) + ' States</div>';
        html += '<div>' + escapeHtml(String(data.cities || 0)) + ' Cities</div>';
        html += '<div>' + escapeHtml(String(data.total_photos || 0)) + ' Photos</div>';
        html += '</div>';
        return html;
    }

    /**
     * Year summary slide
     */
    function renderYearSummarySlide() {
        photoDisplay.innerHTML = '';
        statTitle.textContent = selectedYear + ' - Places Explored';
        statValue.innerHTML = summaryStatsHtml(summary || {});
        statDetail.textContent = 'Organized by month and location';
    }

    /**
     * Month summary slide (countries > states > cities; missing parts omitted)
     */
    function renderMonthSummarySlide(monthData) {
        photoDisplay.innerHTML = '';
        statTitle.textContent = monthData.month_name + ' ' + selectedYear;

        var html = summaryStatsHtml(monthData);
        var locations = monthData.locations || [];

        if (locations.length > 0) {
            html += '<div class="hierarchy-list">';
            var groupOpen = false;
            var currentCountry = null;
            var currentState = null;

            for (var i = 0; i < locations.length; i++) {
                var loc = locations[i];
                var country = loc.country || '';
                var state = loc.state || '';

                // New country group
                if (!groupOpen || country !== currentCountry) {
                    if (groupOpen) {
                        html += '</div>';
                    }
                    html += '<div class="country-group">';
                    groupOpen = true;
                    if (country) {
                        html += '<div class="country-name">' + escapeHtml(country) + '</div>';
                    }
                    currentCountry = country;
                    currentState = null;
                }

                // New state within the country
                if (state !== currentState) {
                    if (state) {
                        html += '<div class="state-name">- ' + escapeHtml(state) + '</div>';
                    }
                    currentState = state;
                }

                if (loc.city) {
                    html += '<div class="city-name">  • ' + escapeHtml(loc.city) + '</div>';
                }
            }

            if (groupOpen) {
                html += '</div>';
            }
            html += '</div>';
        }

        statValue.innerHTML = html;
        statDetail.textContent = '';
    }

    /**
     * Location slide: header plus a justifiedGallery grid of up to 9 photos
     */
    function renderLocationSlide(locationData, index) {
        var photos = locationData.photos || [];
        var count = locationData.photo_count || photos.length;

        var html = '<div class="location-header">';
        html += '<h2>' + escapeHtml(locationData.title || '') + '</h2>';
        html += '<p>' + escapeHtml(String(count)) + ' photo' + (count !== 1 ? 's' : '') + '</p>';
        html += '</div>';

        html += '<div class="photo-grid" id="justified-gallery">';
        for (var i = 0; i < photos.length; i++) {
            var photo = photos[i];
            html += '<div><img src="' + escapeHtml(photo.src || '') + '" alt="' + escapeHtml(photo.filename || '') + '"';
            if (photo.w && photo.h) {
                html += ' width="' + escapeHtml(String(photo.w)) + '" height="' + escapeHtml(String(photo.h)) + '"';
            }
            html += '></div>';
        }
        html += '</div>';

        photoDisplay.innerHTML = html;

        // A failed image becomes a blank rounded tile; the layout holds
        var imgs = photoDisplay.getElementsByTagName('img');
        for (var j = 0; j < imgs.length; j++) {
            imgs[j].onerror = function() {
                this.parentNode.className += ' thumb-missing';
            };
        }

        if (window.jQuery && jQuery.fn.justifiedGallery) {
            // Start sized so 9 photos fit on one screen at 1024x768, landscape and
            // portrait, with no scrollbar. Landscape leaves the grid ~556px under
            // the header and #photo-display's padding (50px top, 90px bottom), so
            // 3 rows must each be <= ~180px: maxRowHeight caps stretched rows
            // (jG's last row takes the average). In portrait, rowHeight <= 175
            // keeps three 4:3 photos per row. Grids that still don't fit (e.g. a
            // panorama on its own row, or 16:9 photos in portrait) are shrunk by
            // layoutGallery().
            layoutGallery(index, 165, 175, GRID_FIT_RETRIES);
        }
    }

    /**
     * Whether the laid-out grid doesn't fit: it scrolls, or runs off the screen
     */
    function galleryOverflows(g) {
        return g.scrollHeight > g.clientHeight + 1 ||
            g.getBoundingClientRect().bottom > window.innerHeight;
    }

    /**
     * Lay out the grid with justifiedGallery. If it doesn't fit, destroy it and
     * lay it out again with rows 15% lower, up to triesLeft more times; after
     * that it keeps its scrollbar. Only for the slide still on screen.
     */
    function layoutGallery(slideIndex, rowHeight, maxRowHeight, triesLeft) {
        var $g = jQuery('#justified-gallery');
        if (!$g.length) {
            return;
        }
        var el = $g[0];

        // Bound before the call: jG can finish (and trigger) synchronously
        $g.one('jg.complete', function() {
            if (triesLeft <= 0 || slideIndex !== currentIndex ||
                    document.getElementById('justified-gallery') !== el ||
                    !galleryOverflows(el)) {
                return;
            }
            // Outside jG's own callback; re-check the slide is still showing
            setTimeout(function() {
                if (slideIndex !== currentIndex || document.getElementById('justified-gallery') !== el) {
                    return;
                }
                destroyGallery();
                layoutGallery(slideIndex, Math.round(rowHeight * 0.85),
                    Math.round(maxRowHeight * 0.85), triesLeft - 1);
            }, 0);
        });

        $g.justifiedGallery({
            rowHeight: rowHeight,
            maxRowHeight: maxRowHeight,
            margins: 8,
            border: 0,
            lastRow: 'nojustify',
            captions: false,
            randomize: false,
            waitThumbnailsLoad: false,
            cssAnimation: true
        });
    }

    /**
     * Show next slide
     */
    function nextSlide() {
        if (slides.length === 0) {
            return;
        }
        showSlide((currentIndex + 1) % slides.length);

        if (isPlaying) {
            resetSlideshowTimer();
        }
    }

    /**
     * Show previous slide
     */
    function prevSlide() {
        if (slides.length === 0) {
            return;
        }
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

        if (key === 27) { // Escape
            goBack();
            return;
        }

        showControls();

        if (slides.length === 0) {
            return;
        }

        if (key === 37 || key === 65) { // Left or A
            prevSlide();
        } else if (key === 39 || key === 68) { // Right or D
            nextSlide();
        } else if (key === 32) { // Space
            e.preventDefault();
            togglePlayPause();
        }
    }

    /**
     * Start the slideshow once the slides are loaded
     */
    function init() {
        showSlide(0);
        startSlideshow();

        // Hide controls after 3 seconds
        setTimeout(function() {
            controls.classList.remove('visible');
        }, 3000);
    }

    setupNavigation();
    loadPhotos();
})();
