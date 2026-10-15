/* ES5-compatible Year Review - Places Explored */

(function() {
    'use strict';

    // DOM elements
    var slideshowContainer = document.getElementById('slideshow-container');
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
    var preloadedKey = ''; // grid size (gridKey()) the preload was made for
    var coverImage = null;
    var resizeTimer = null;
    var GRID_FIT_RETRIES = 3; // smaller re-layouts of a grid that doesn't fit
    var ALBUM_GAP = 8;            // px between month album tiles
    var ALBUM_MIN_W = 110;        // px, narrowest album tile before the rest go under "+N more"
    var ALBUM_MAX_RATIO = 1.8;    // widest tile (w/h)
    var ALBUM_XL_MIN_SHORT = 330; // px, tiles bigger than this on the short edge use the xl photo
    // Location slide grid area. Keep in sync with year-slideshow.css:
    // #photo-display padding 40px 48px 80px, .location-header 62px + 16px gap
    var GRID_PAD_X = 48 + 48;
    var GRID_PAD_Y = 40 + 80 + 62 + 16;
    var GRID_MARGIN = 6; // jG margins between tiles
    var GRID_SLACK = 2; // px a predicted layout must leave free (rounding)
    var XL_MIN_SHORT_EDGE = 320; // m is 320px on the short edge: larger tiles may use xl
    var SLIDE_DECODE_BUDGET = 18 * 1024 * 1024; // bytes of decoded images per location slide
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
            slides.push({ type: 'month-summary', data: month.summary, albums: month.location_slides || [] });

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
                renderMonthSummarySlide(slide);
            } else if (slide.type === 'location') {
                renderLocationSlide(slide.data, index);
            }

            // Fade in. The preload (and its layout search) waits until the
            // fade-in is done, so it doesn't compete with it.
            target.style.opacity = '1';
            setTimeout(function() {
                if (index === currentIndex) {
                    preloadNext(index);
                }
            }, 350);
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
            if (target === preloadedIndex && preloadedKey === gridKey()) {
                // Already preloading this slide at this size (e.g. passing a
                // month summary); after a rotation it is redone
                return;
            }
            preloadedIndex = target;
            preloadedKey = gridKey();
            // The month summary on the way there shows one photo per album
            var albums = monthSlide ? albumItems(monthSlide) : [];
            for (i = 0; i < albums.length; i++) {
                var mImg = new Image();
                mImg.src = albums[i].src;
                images.push(mImg);
            }
            // The same sizes (m or xl) the slide will render with
            var srcs = slideLayout(slide).srcs;
            for (i = 0; i < srcs.length && i < 9; i++) {
                var img = new Image();
                img.src = srcs[i];
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
     * The albums of a month summary: one per location slide, with that slide's
     * first photo and its title. Slides without a usable photo are skipped.
     */
    function albumItems(monthSlide) {
        var items = [];
        var list = monthSlide.albums || [];
        for (var i = 0; i < list.length; i++) {
            var photo = (list[i].photos || [])[0];
            var src = photo ? safeSrc(photo.src) : '';
            if (!src) {
                continue;
            }
            items.push({
                title: list[i].title || '',
                src: src,
                srcXl: safeSrc(photo.src_xl) || src
            });
        }
        return items;
    }

    /**
     * Best grid for n album tiles in a W x H area: the column count that gives
     * the largest tile (aspect kept between 1:1 and ALBUM_MAX_RATIO:1).
     * Returns {cols, w, h}.
     */
    function bestAlbumLayout(n, W, H) {
        var best = null;
        for (var cols = 1; cols <= n; cols++) {
            var rows = Math.ceil(n / cols);
            var w = (W - (cols - 1) * ALBUM_GAP) / cols;
            var h = (H - (rows - 1) * ALBUM_GAP) / rows;
            if (w <= 0 || h <= 0) {
                continue;
            }
            if (w / h < 1) {
                h = w;
            } else if (w / h > ALBUM_MAX_RATIO) {
                w = h * ALBUM_MAX_RATIO;
            }
            if (best === null || w * h > best.w * best.h) {
                best = { cols: cols, w: w, h: h };
            }
        }
        if (best === null) {
            return { cols: 1, w: 0, h: 0 };
        }
        return { cols: best.cols, w: Math.floor(best.w), h: Math.floor(best.h) };
    }

    /**
     * Month summary slide: month title, stats line, then the month's places as
     * album tiles (one photo and a title each) filling the rest of the screen
     */
    function renderMonthSummarySlide(slide) {
        var monthData = slide.data || {};
        setPhotoDisplay('');
        statDisplay.className = 'slide-month';
        statCover.style.backgroundImage = '';

        var html = '<div class="month-title"><span class="month-name">' + escapeHtml(monthData.month_name || '') +
            '</span><span class="month-year">' + escapeHtml(selectedYear) + '</span></div>';

        var stats = summaryStats(monthData);
        var parts = [];
        for (var s = 0; s < stats.length; s++) {
            parts.push(plural(stats[s].n, stats[s].one, stats[s].many));
        }
        html += '<div class="month-stats stat-label">' + escapeHtml(parts.join(DOT)) + '</div>';
        html += '<div class="album-grid"></div>';
        statBody.innerHTML = html;

        // The grid takes the remaining height of the body
        var gridEl = statBody.lastChild;
        var bodyStyle = window.getComputedStyle(statBody);
        var bottom = statBody.getBoundingClientRect().bottom - (parseFloat(bodyStyle.paddingBottom) || 0);
        var H = Math.max(0, Math.floor(bottom - gridEl.getBoundingClientRect().top));
        var W = gridEl.clientWidth;

        var items = albumItems(slide);
        if (items.length === 0 || H <= 0 || W <= 0) {
            return;
        }

        // Too many for the minimum tile size: show fewer, the rest as "+N more"
        var shown = items.length;
        var more = 0;
        var layout = bestAlbumLayout(shown, W, H);
        while (layout.w < ALBUM_MIN_W && shown > 1) {
            shown--;
            more = items.length - shown;
            layout = bestAlbumLayout(shown + 1, W, H);
        }

        var count = shown + (more > 0 ? 1 : 0);
        var rows = Math.ceil(count / layout.cols);
        var useXl = Math.min(layout.w, layout.h) > ALBUM_XL_MIN_SHORT;
        var out = '';
        for (var i = 0; i < count; i++) {
            var col = i % layout.cols;
            var style = 'width: ' + layout.w + 'px; height: ' + layout.h + 'px; margin-right: ' +
                (col === layout.cols - 1 ? 0 : ALBUM_GAP) + 'px; margin-bottom: ' + ALBUM_GAP + 'px';
            if (i < shown) {
                var item = items[i];
                out += '<div class="album' + (layout.w >= 300 ? ' album-lg' : '') + '" style="' + style +
                    '; background-image: url(' + escapeHtml(useXl ? item.srcXl : item.src) + ')">' +
                    '<div class="album-caption"><div class="album-title">' + escapeHtml(item.title) + '</div></div></div>';
            } else {
                out += '<div class="album album-more" style="' + style + '"><div class="album-more-text">+ ' +
                    escapeHtml(more) + ' more</div></div>';
            }
        }
        gridEl.innerHTML = out;
        gridEl.style.width = (layout.cols * layout.w + (layout.cols - 1) * ALBUM_GAP) + 'px';
        gridEl.style.height = (rows * layout.h + (rows - 1) * ALBUM_GAP) + 'px';
    }

    /**
     * Rotation/resize: re-fit the month or location slide on screen (no fade),
     * then redo the preload for the new size. Skipped while a fade is pending:
     * currentIndex already points at the incoming slide, but the outgoing one
     * (maybe a grid) is still in place, and the fade callback renders the
     * incoming slide at the new size anyway.
     */
    function handleResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            resizeTimer = null;
            if (fadeTimer !== null) {
                return;
            }
            var slide = slides[currentIndex];
            if (!slide) {
                return;
            }
            if (slide.type === 'month-summary' && statDisplay.style.display !== 'none') {
                renderMonthSummarySlide(slide);
            } else if (slide.type === 'location' && photoDisplay.style.display !== 'none' &&
                    slide.layoutKey !== gridKey()) {
                // Only when the grid size changed. setPhotoDisplay() destroys the
                // old grid (and its interval); a pending fit-retry sees a
                // different element and stops
                renderLocationSlide(slide.data, currentIndex);
            }
            // Redone only if the size changed (see preloadNext())
            preloadNext(currentIndex);
        }, 250);
    }

    /**
     * The area a location slide's grid may fill, {w, h} in px, from the
     * always-displayed container (so it works for a preload during a stat
     * slide too): 928x570 at 1024x768, 672x826 at 768x1024
     */
    function gridArea() {
        return {
            w: Math.floor(slideshowContainer.clientWidth - GRID_PAD_X),
            h: Math.floor(slideshowContainer.clientHeight - GRID_PAD_Y)
        };
    }

    /**
     * gridArea() as a cache key, "928x570"
     */
    function gridKey() {
        var area = gridArea();
        return area.w + 'x' + area.h;
    }

    /**
     * What justifiedGallery 3.8.1 will draw for these aspect ratios (w/h) at
     * gallery width W and rowHeight rh, with our options (margins 6, border 0,
     * lastRow 'center', maxRowHeight false, justifyThreshold 0.9): an exact
     * port of its row builder. Returns {height, area} (area = sum of tile
     * w*h), plus tiles [{w, h}] when withTiles. Stops early, with a height
     * over maxH, once the rows already exceed maxH (if given). No closures or
     * per-row arrays: the search calls this ~1,800 times per slide.
     */
    function simulateJustified(ars, W, rh, withTiles, maxH) {
        var M = GRID_MARGIN;
        var tiles = withTiles ? [] : null;
        var n = ars.length;
        var limit = maxH > 0 ? maxH + M : Infinity;
        var start = 0; // first photo of the row being built
        var bufAR = 0;
        var rowW = 0;
        var offY = 0;
        var rows = 0;
        var area = 0;

        for (var i = 0; i <= n; i++) {
            var isLast = i === n;
            if (!isLast) {
                // jG measures the space before the push, then adds the new ratio twice
                var space = W - (i - start - 1) * M;
                var ar = ars[i];
                bufAR += ar;
                rowW += ar * rh;
                if (!(space / (bufAR + ar) < rh)) {
                    continue;
                }
            } else if (start === n) {
                // The last photo ended a full row: no margin after it
                if (rows > 0) {
                    offY -= M;
                }
                break;
            }

            // Flush photos start..end-1 as one row
            var end = isLast ? n : i + 1;
            var g = W - (end - start - 1) * M;
            var h = g / bufAR;
            var justified = true;
            if (isLast && !(rowW / g > 0.9)) {
                // A short last row: the average height of the rows above, centred
                justified = false;
                h = rh;
                if (rows > 0) {
                    h = (offY - M * rows) / rows;
                    justified = h * bufAR / g > 0.9;
                    if (justified) {
                        h = g / bufAR;
                    }
                }
            }
            var sumW = 0;
            for (var e = start; e < end; e++) {
                var w = Math.round(justified ? (e === end - 1 ? g : h * ars[e]) : h * ars[e]);
                g -= w;
                sumW += w;
                if (tiles) {
                    tiles.push({ w: w, h: h });
                }
            }
            area += sumW * h;
            start = end;
            bufAR = 0;
            rowW = 0;
            if (isLast) {
                offY += h;
            } else {
                offY += h + M;
                rows++;
                if (offY > limit) {
                    // Already too tall (the final height is at least offY - M)
                    return { height: offY - M, area: area, tiles: tiles };
                }
            }
        }
        return { height: offY, area: area, tiles: tiles };
    }

    /**
     * The gallery width and rowHeight whose justifiedGallery layout covers the
     * most of the area while fitting its height (2-9 photos). A coarse pass,
     * then a fine one around the best; only a strictly larger area wins, so
     * the result is deterministic. Narrower galleries allow 3-5 photos to form
     * taller rows. Returns {width, rowHeight, height, tiles}, or null.
     */
    function bestGridLayout(ars, area) {
        var maxH = area.h - GRID_SLACK;
        var wMin = Math.round(0.4 * area.w);
        var bestW = 0;
        var bestRh = 0;
        var bestArea = -1;
        var W;
        var rh;
        var sim;

        for (W = area.w; W >= wMin; W -= 24) {
            for (rh = area.h; rh >= 40; rh -= 8) {
                sim = simulateJustified(ars, W, rh, false, maxH);
                if (sim.height <= maxH && sim.area > bestArea) {
                    bestArea = sim.area;
                    bestW = W;
                    bestRh = rh;
                }
            }
        }
        if (bestArea < 0) {
            return null;
        }
        var cW = bestW;
        var cRh = bestRh;
        for (W = Math.min(area.w, cW + 24); W >= Math.max(wMin, cW - 24); W -= 4) {
            for (rh = Math.min(area.h, cRh + 8); rh >= Math.max(40, cRh - 8); rh -= 2) {
                sim = simulateJustified(ars, W, rh, false, maxH);
                if (sim.height <= maxH && sim.area > bestArea) {
                    bestArea = sim.area;
                    bestW = W;
                    bestRh = rh;
                }
            }
        }
        sim = simulateJustified(ars, bestW, bestRh, true, 0);
        return { width: bestW, rowHeight: bestRh, height: sim.height, tiles: sim.tiles };
    }

    /**
     * Estimated decoded bytes of a photo's thumbnail capped at cap px on the
     * short edge (m: 320, xl: 1280). Unknown size: an m 4:3 portrait.
     */
    function decodedBytes(photo, cap) {
        if (!(photo.w > 0 && photo.h > 0)) {
            return 320 * 427 * 4;
        }
        var s0 = Math.min(photo.w, photo.h);
        var l0 = Math.max(photo.w, photo.h);
        var s1 = Math.min(cap, s0);
        return s1 * Math.round(s1 * l0 / s0) * 4;
    }

    /**
     * Image URL per tile: m, upgraded to xl for tiles drawn more than 320px on
     * their short edge, largest first, while the slide's decoded total stays
     * within SLIDE_DECODE_BUDGET. Returns {srcs, bytes}.
     */
    function chooseTileSrcs(photos, tiles) {
        var srcs = [];
        var bytes = 0;
        var candidates = [];
        var i;
        for (i = 0; i < photos.length; i++) {
            srcs.push(safeSrc(photos[i].src));
            bytes += decodedBytes(photos[i], 320);
            var tile = tiles[i];
            if (tile && photos[i].w > 0 && photos[i].h > 0 &&
                    Math.min(tile.w, tile.h) > XL_MIN_SHORT_EDGE && safeSrc(photos[i].src_xl)) {
                candidates.push(i);
            }
        }
        candidates.sort(function(a, b) {
            var d = tiles[b].w * tiles[b].h - tiles[a].w * tiles[a].h;
            return d !== 0 ? d : a - b;
        });
        for (var c = 0; c < candidates.length; c++) {
            var p = photos[candidates[c]];
            var extra = decodedBytes(p, 1280) - decodedBytes(p, 320);
            if (bytes + extra <= SLIDE_DECODE_BUDGET) {
                srcs[candidates[c]] = safeSrc(p.src_xl);
                bytes += extra;
            }
        }
        return { srcs: srcs, bytes: bytes };
    }

    /**
     * A location slide's layout for the current screen size, cached on the
     * slide until the size changes: {mode: 'single'|'grid', area, width (px,
     * or 0 for full width), rowHeight, tiles, srcs, bytes}. Computed by the
     * preload (idle time) and reused by the render.
     */
    function slideLayout(slide) {
        var area = gridArea();
        var key = area.w + 'x' + area.h;
        if (slide.layout && slide.layoutKey === key) {
            return slide.layout;
        }
        var photos = slide.data.photos || [];
        var layout = { mode: 'grid', area: area, width: 0, rowHeight: 165, tiles: [] };
        var i;

        if (photos.length === 1) {
            layout.mode = 'single';
            var p = photos[0];
            if (p.w > 0 && p.h > 0) {
                var scale = Math.min(area.w / p.w, area.h / p.h);
                layout.tiles = [{ w: Math.floor(p.w * scale), h: Math.floor(p.h * scale) }];
            }
        } else if (photos.length > 1) {
            var ars = [];
            for (i = 0; i < photos.length; i++) {
                ars.push(photos[i].w > 0 && photos[i].h > 0 ? photos[i].w / photos[i].h : 4 / 3);
            }
            var best = bestGridLayout(ars, area);
            if (best) {
                layout.width = best.width;
                layout.rowHeight = best.rowHeight;
                layout.height = best.height;
                layout.tiles = best.tiles;
            }
        }

        var chosen = chooseTileSrcs(photos, layout.tiles);
        layout.srcs = chosen.srcs;
        layout.bytes = chosen.bytes;
        slide.layout = layout;
        slide.layoutKey = key;
        return layout;
    }

    /**
     * Merged slides list their member places after the photo count, as many
     * as fit on the one-line sub-header, then "+N" for the rest:
     * "JANUARY 2023 · 28 PHOTOS · KLEIN · SPRING · BRIDGELAND +2". At least one
     * name is shown; if even that doesn't fit, the name itself is clipped with
     * an ellipsis so "+N" stays visible. Set as text, measured in place, so
     * the header keeps its fixed height.
     */
    function fitPlaceNames(places, base) {
        if (!places || typeof places.length !== 'number' || places.length === 0) {
            return;
        }
        var subEl = photoDisplay.getElementsByClassName('location-sub')[0];
        var label = subEl ? subEl.getElementsByClassName('stat-label')[0] : null;
        if (!label) {
            return;
        }
        var names = [];
        for (var i = 0; i < places.length; i++) {
            names.push(String(places[i]));
        }
        var namesEl = document.createElement('span');
        var moreEl = document.createElement('span');
        label.textContent = base;
        label.appendChild(namesEl);
        label.appendChild(moreEl);

        var right = subEl.getBoundingClientRect().right;
        for (var k = names.length; k >= 1; k--) {
            namesEl.textContent = DOT + names.slice(0, k).join(DOT);
            moreEl.textContent = k < names.length ? ' +' + (names.length - k) : '';
            if (moreEl.getBoundingClientRect().right <= right &&
                    namesEl.getBoundingClientRect().right <= right) {
                return;
            }
        }
        // Even one name is too long: clip it, keep "+N"
        var avail = right - namesEl.getBoundingClientRect().left - moreEl.getBoundingClientRect().width - 2;
        namesEl.className = 'place-names-clip';
        namesEl.style.maxWidth = Math.max(0, Math.floor(avail)) + 'px';
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

        var layout = slideLayout(slides[index]);
        var area = layout.area;
        var i;
        if (layout.mode === 'single') {
            // One photo: contained and centred in the grid area, no jG
            var photo = photos[0];
            var tile = layout.tiles[0];
            html += '<div class="photo-single" style="height:' + escapeHtml(String(area.h)) + 'px">';
            html += '<img src="' + escapeHtml(layout.srcs[0]) + '" alt="' + escapeHtml(photo.filename || '') + '"';
            if (tile) {
                html += ' width="' + escapeHtml(String(tile.w)) + '" height="' + escapeHtml(String(tile.h)) + '"' +
                    ' style="margin-top:' + escapeHtml(String(Math.floor((area.h - tile.h) / 2))) + 'px"';
            } else {
                html += ' style="max-width:100%;max-height:' + escapeHtml(String(area.h)) + 'px"';
            }
            html += '></div>';
        } else {
            html += '<div class="photo-grid" id="justified-gallery"' +
                (layout.width ? ' style="width:' + escapeHtml(String(layout.width)) + 'px"' : '') + '>';
            for (i = 0; i < photos.length; i++) {
                // width/height: jG reads each photo's aspect ratio from them
                html += '<div><img src="' + escapeHtml(layout.srcs[i]) + '" alt="' + escapeHtml(photos[i].filename || '') + '"';
                if (photos[i].w && photos[i].h) {
                    html += ' width="' + escapeHtml(String(photos[i].w)) + '" height="' + escapeHtml(String(photos[i].h)) + '"';
                }
                html += '></div>';
            }
            html += '</div>';
        }

        setPhotoDisplay(html);
        fitPlaceNames(locationData.places, sub);

        // A failed image becomes a blank tile; the layout holds
        var imgs = photoDisplay.getElementsByTagName('img');
        for (var j = 0; j < imgs.length; j++) {
            imgs[j].onerror = function() {
                this.parentNode.className += ' thumb-missing';
            };
        }

        if (layout.mode === 'grid' && window.jQuery && jQuery.fn.justifiedGallery) {
            // slideLayout() picked the gallery width and rowHeight whose
            // simulated jG layout fills the most of the area and still fits
            // (2px slack), so jG needs no maxRowHeight (no cropping); a short
            // last row is centred at the height of the rows above. If the real
            // grid still overflows (rounding, or no layout fitted), it is
            // re-laid out smaller by layoutGallery().
            layoutGallery(index, layout.rowHeight, GRID_FIT_RETRIES);
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
     * Lay out the grid with justifiedGallery (at the inline width set by
     * renderLocationSlide()). If it doesn't fit, destroy it and lay it out
     * again with rows 15% lower, up to triesLeft more times; after that it
     * keeps its scrollbar. Only for the slide still on screen.
     */
    function layoutGallery(slideIndex, rowHeight, triesLeft) {
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
                layoutGallery(slideIndex, Math.round(rowHeight * 0.85), triesLeft - 1);
            }, 0);
        });

        $g.justifiedGallery({
            rowHeight: rowHeight,
            maxRowHeight: false,
            margins: GRID_MARGIN,
            border: 0,
            lastRow: 'center',
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
