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
    var statCover = document.getElementById('stat-cover');
    var statScrim = document.getElementById('stat-scrim');
    var statBody = document.getElementById('stat-body');
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
    var coverImage = null;
    var resizeTimer = null;
    var GRID_FIT_RETRIES = 3; // smaller re-layouts of a grid that doesn't fit
    var LIST_ROW_HEIGHT = 24; // px, every month place-list row (see year-slideshow.css)
    var DOT = ' \u00b7 ';

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
     * 1234567 -> "1,234,567" (no toLocaleString: it varies by browser)
     */
    function formatNumber(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * "1 City" / "5 Cities"
     */
    function plural(n, one, many) {
        return formatNumber(n) + ' ' + (n === 1 ? one : many);
    }

    /**
     * An image URL from the API, or '' unless it is one of the two forms
     * yearThumbSrc() (api/year_review_lib.php) builds: the cached file or the
     * on-demand endpoint. Keep in sync with isYearThumbSrc() there.
     */
    var SAFE_SRC = /^(cache\/year_review\/\d{4}_\d+_(xl|m)\.jpg|api\/year_thumb\.php\?year=\d{4}&id=\d+(&size=(xl|m))?)$/;
    function safeSrc(s) {
        s = String(s || '');
        return SAFE_SRC.test(s) ? s : '';
    }

    /**
     * The summary stats as [{n, label}]: countries, states, cities, photos.
     * Zero values are omitted, except photos.
     */
    function summaryStats(data) {
        var defs = [
            ['countries', 'Country', 'Countries'],
            ['states', 'State', 'States'],
            ['cities', 'City', 'Cities'],
            ['total_photos', 'Photo', 'Photos']
        ];
        var stats = [];
        for (var i = 0; i < defs.length; i++) {
            var n = parseInt(data[defs[i][0]], 10) || 0;
            if (n === 0 && defs[i][0] !== 'total_photos') {
                continue;
            }
            stats.push({ n: n, one: defs[i][1], many: defs[i][2] });
        }
        return stats;
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
        window.addEventListener('resize', handleResize);
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
                // Start fetching the year cover now; don't wait for it
                if (summary.cover && safeSrc(summary.cover.src)) {
                    coverImage = new Image();
                    coverImage.src = safeSrc(summary.cover.src);
                }
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
                // The month name for the location header ("January 2023 · 9 photos")
                locationSlides[j].monthName = (month.summary || {}).month_name;
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
     * The only way to replace #photo-display's content: destroys the grid first,
     * so its checkWidth interval can't outlive it
     */
    function setPhotoDisplay(html) {
        destroyGallery();
        photoDisplay.innerHTML = html;
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
        counter.textContent = (currentIndex + 1) + ' / ' + slides.length;

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
            // No fade pending from here on (handleResize checks this)
            fadeTimer = null;
            if (index !== currentIndex) {
                return;
            }

            // The old grid is torn down only now (after the fade-out, by
            // setPhotoDisplay() in the render functions below), so it doesn't
            // collapse while still fading. With quick taps, earlier callbacks were
            // cleared, so this surviving one replaces whatever grid is in place.

            // Release the decoded year cover as soon as another slide is shown
            if (slide.type !== 'year-summary') {
                statCover.style.backgroundImage = '';
            }

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
     * Preload the photos of the next location slide (one slide only), plus the
     * strip photos of a month summary on the way there
     */
    function preloadNext(index) {
        var images = [];
        var monthSlide = null;
        var i;

        for (var step = 1; step < slides.length; step++) {
            var target = (index + step) % slides.length;
            var slide = slides[target];
            if (slide.type === 'month-summary' && monthSlide === null) {
                monthSlide = slide;
                continue;
            }
            if (slide.type !== 'location') {
                continue;
            }
            if (target === preloadedIndex) {
                // Already preloading this slide (e.g. passing a month summary)
                return;
            }
            preloadedIndex = target;
            var monthPhotos = monthSlide ? (monthSlide.data.photos || []) : [];
            for (i = 0; i < monthPhotos.length && i < 3; i++) {
                if (safeSrc(monthPhotos[i].src)) {
                    var mImg = new Image();
                    mImg.src = safeSrc(monthPhotos[i].src);
                    images.push(mImg);
                }
            }
            var photos = slide.data.photos || [];
            for (i = 0; i < photos.length && i < 9; i++) {
                var img = new Image();
                img.src = safeSrc(photos[i].src);
                images.push(img);
            }
            break;
        }

        // Replace (and so release) the previous preloads
        preloaded = images;
    }

    /**
     * Year summary slide: full-bleed cover, year and stats
     */
    function renderYearSummarySlide() {
        setPhotoDisplay('');
        var data = summary || {};
        var src = data.cover ? safeSrc(data.cover.src) : '';

        statDisplay.className = src ? 'slide-year' : 'slide-year no-cover';
        statCover.style.backgroundImage = src ? 'url(' + src + ')' : '';

        var html = '';
        if (!src) {
            html += '<span class="accent-rule"></span>';
        }
        html += '<div class="eyebrow">A Year of Places</div>';
        html += '<div class="year-title">' + escapeHtml(selectedYear) + '</div>';
        html += '<div class="year-stats">';
        var stats = summaryStats(data);
        for (var i = 0; i < stats.length; i++) {
            html += '<div class="year-stat">';
            html += '<div class="year-stat-num">' + escapeHtml(formatNumber(stats[i].n)) + '</div>';
            html += '<div class="stat-label">' + escapeHtml(stats[i].n === 1 ? stats[i].one : stats[i].many) + '</div>';
            html += '</div>';
        }
        html += '</div>';
        statBody.innerHTML = html;

        // The element now holds the cover; drop the preload so leaving this
        // slide (which clears backgroundImage) can release the decoded image.
        coverImage = null;
    }

    /**
     * Month place list as rows: country > state > city, missing parts omitted,
     * a spacer between countries. Each row is {type, text}.
     */
    function buildPlaceRows(locations) {
        var rows = [];
        var currentCountry = null;
        var currentState = null;

        for (var i = 0; i < locations.length; i++) {
            var loc = locations[i];
            var country = loc.country || '';
            var state = loc.state || '';

            if (i === 0 || country !== currentCountry) {
                if (i > 0) {
                    rows.push({ type: 'spacer', text: '' });
                }
                if (country) {
                    rows.push({ type: 'country', text: country });
                }
                currentCountry = country;
                currentState = null;
            }

            if (state !== currentState) {
                if (state) {
                    rows.push({ type: 'state', text: state });
                }
                currentState = state;
            }

            if (loc.city) {
                // Indented only when it sits under a state
                rows.push({ type: state ? 'city-indent' : 'city', text: loc.city });
            }
        }
        return rows;
    }

    /**
     * How many rows starting at i must share a column: the header chain
     * spacer > country > state > first city (at most 4), each header taking
     * the row after it. A country or spacer followed by a spacer (or the end)
     * stands alone, as does a state without an indented city. A chain taller
     * than a whole column is never started; it ends up in "+ N more".
     */
    function headerChainLength(rows, i) {
        var n = 0;
        for (; i < rows.length; i++) {
            var type = rows[i].type;
            n++;
            if (type === 'city' || type === 'city-indent') {
                break;
            }
            if (type === 'state') {
                if (i + 1 < rows.length && rows[i + 1].type === 'city-indent') {
                    n++;
                }
                break;
            }
            // spacer or country: needs the next row, unless that starts a new country
            if (i + 1 >= rows.length || rows[i + 1].type === 'spacer') {
                break;
            }
        }
        return n;
    }

    /**
     * Fill two columns of up to cap rows each (the second has lastCap). A spacer
     * never starts a column; a spacer, country or state never ends one when its
     * first place follows (the whole header chain moves to the next column, see
     * headerChainLength()). Returns {cols, next} (next = first row not placed).
     */
    function fillPlaceColumns(rows, cap, lastCap) {
        var cols = [[], []];
        var c = 0;
        var i = 0;

        while (i < rows.length && c < 2) {
            var col = cols[c];
            var limit = c === 0 ? cap : lastCap;
            var row = rows[i];

            if (row.type === 'spacer' && col.length === 0) {
                i++;
                continue;
            }
            var need = headerChainLength(rows, i);
            if (col.length + need > limit) {
                c++;
                continue;
            }
            col.push(row);
            i++;
        }
        return { cols: cols, next: i };
    }

    /**
     * Render the month place list into listEl (its height is already set)
     */
    function renderPlaceList(listEl, locations) {
        var rows = buildPlaceRows(locations);
        var cap = Math.floor(listEl.clientHeight / LIST_ROW_HEIGHT);
        if (rows.length === 0 || cap <= 0) {
            listEl.innerHTML = '';
            return;
        }

        var fill = fillPlaceColumns(rows, cap, cap);
        var more = 0;
        if (fill.next < rows.length) {
            // Doesn't fit: keep the second column's last slot for "+ N more"
            fill = fillPlaceColumns(rows, cap, cap - 1);
            var hiddenCountries = 0;
            for (var h = fill.next; h < rows.length; h++) {
                if (rows[h].type === 'country') {
                    hiddenCountries++;
                } else if (rows[h].type !== 'spacer') {
                    more++;
                }
            }
            if (more === 0) {
                more = hiddenCountries;
            }
        }

        var html = '';
        for (var c = 0; c < 2; c++) {
            html += '<div class="place-col' + (c === 0 ? ' place-col-first' : '') + '">';
            var col = fill.cols[c];
            for (var r = 0; r < col.length; r++) {
                html += '<div class="place-row place-' + col[r].type + '">' + escapeHtml(col[r].text) + '</div>';
            }
            if (c === 1 && more > 0) {
                html += '<div class="place-row place-more">+ ' + escapeHtml(formatNumber(more)) + ' more</div>';
            }
            html += '</div>';
        }
        listEl.innerHTML = html;
    }

    /**
     * Month summary slide: photo strip, title, stats line and place list
     */
    function renderMonthSummarySlide(monthData) {
        setPhotoDisplay('');
        statDisplay.className = 'slide-month';
        statCover.style.backgroundImage = '';

        var html = '';
        var photos = monthData.photos || [];
        var tiles = 0;
        for (var i = 0; i < photos.length && i < 3; i++) {
            var src = safeSrc(photos[i].src);
            html += (tiles === 0 ? '<div class="month-strip">' : '') +
                '<div class="month-tile"' + (src ? ' style="background-image: url(' + escapeHtml(src) + ')"' : '') + '></div>';
            tiles++;
        }
        if (tiles > 0) {
            html += '</div>';
        }

        html += '<div class="month-title"><span class="month-name">' + escapeHtml(monthData.month_name || '') +
            '</span><span class="month-year">' + escapeHtml(selectedYear) + '</span></div>';

        var stats = summaryStats(monthData);
        var parts = [];
        for (var s = 0; s < stats.length; s++) {
            parts.push(plural(stats[s].n, stats[s].one, stats[s].many));
        }
        html += '<div class="month-stats stat-label">' + escapeHtml(parts.join(DOT)) + '</div>';
        html += '<div class="place-list"></div>';
        statBody.innerHTML = html;

        // Tile widths: three across the body width with two 6px gaps
        var strip = statBody.firstChild;
        if (tiles > 0 && strip.className === 'month-strip') {
            var tileW = Math.floor((strip.clientWidth - 12) / 3);
            var tileEls = strip.childNodes;
            for (var t = 0; t < tileEls.length; t++) {
                tileEls[t].style.width = tileW + 'px';
            }
        }

        // The list takes the remaining height of the body
        var listEl = statBody.lastChild;
        var bodyStyle = window.getComputedStyle(statBody);
        var bottom = statBody.getBoundingClientRect().bottom - (parseFloat(bodyStyle.paddingBottom) || 0);
        var listH = Math.floor(bottom - listEl.getBoundingClientRect().top);
        listEl.style.height = Math.max(0, listH) + 'px';
        renderPlaceList(listEl, monthData.locations || []);
    }

    /**
     * Rotation/resize: re-fit the month slide on screen (no fade). Skipped while
     * a fade is pending: currentIndex already points at the incoming slide, but
     * the outgoing one (maybe a grid) is still in place, and the fade callback
     * renders the incoming slide at the new size anyway.
     */
    function handleResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            resizeTimer = null;
            if (fadeTimer !== null) {
                return;
            }
            var slide = slides[currentIndex];
            if (slide && slide.type === 'month-summary' && statDisplay.style.display !== 'none') {
                renderMonthSummarySlide(slide.data);
            }
        }, 250);
    }

    /**
     * Location slide: header plus a justifiedGallery grid of up to 9 photos
     */
    function renderLocationSlide(locationData, index) {
        var photos = locationData.photos || [];
        var count = locationData.photo_count || photos.length;

        var sub = (locationData.monthName ? locationData.monthName + ' ' + selectedYear + DOT : '') +
            plural(count, 'Photo', 'Photos');
        var html = '<div class="location-header">';
        html += '<h2>' + escapeHtml(locationData.title || '') + '</h2>';
        html += '<div class="location-sub"><span class="rule"></span><span class="stat-label">' + escapeHtml(sub) + '</span></div>';
        html += '</div>';

        html += '<div class="photo-grid" id="justified-gallery">';
        for (var i = 0; i < photos.length; i++) {
            var photo = photos[i];
            html += '<div><img src="' + escapeHtml(safeSrc(photo.src)) + '" alt="' + escapeHtml(photo.filename || '') + '"';
            if (photo.w && photo.h) {
                html += ' width="' + escapeHtml(String(photo.w)) + '" height="' + escapeHtml(String(photo.h)) + '"';
            }
            html += '></div>';
        }
        html += '</div>';

        setPhotoDisplay(html);

        // A failed image becomes a blank tile; the layout holds
        var imgs = photoDisplay.getElementsByTagName('img');
        for (var j = 0; j < imgs.length; j++) {
            imgs[j].onerror = function() {
                this.parentNode.className += ' thumb-missing';
            };
        }

        if (window.jQuery && jQuery.fn.justifiedGallery) {
            // Start sized so 9 photos fit on one screen at 1024x768, landscape and
            // portrait, with no scrollbar. #photo-display's padding (40px top, 80px
            // bottom) and the 62px header + 16px gap leave the grid ~570px in
            // landscape, so 3 rows of <= ~180px: maxRowHeight caps stretched rows
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
            margins: 6,
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
