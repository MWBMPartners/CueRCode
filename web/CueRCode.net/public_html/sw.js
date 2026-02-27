/**
 * ==============================================================================
 * 🔧 CueRCode - Progressive Web App Service Worker
 * ==============================================================================
 *
 * Service worker providing offline support, intelligent caching, and
 * performance optimisation for the CueRCode QR code generator.
 *
 * Cache Strategy:
 *   - Network-first for HTML pages and API endpoints
 *   - Cache-first for static assets (CSS, JS, images, fonts)
 *   - Runtime caching for CDN resources (Bootstrap, FontAwesome, jQuery)
 *   - API requests (/api/) are NEVER cached
 *   - Offline fallback page when network unavailable and no cache hit
 *
 * Lifecycle:
 *   1. Install  - precache key static assets
 *   2. Activate - clean up old versioned caches
 *   3. Fetch    - intercept requests with appropriate strategy
 *
 * Uses `var` instead of `let`/`const` for broadest browser compatibility.
 *
 * @package     CueRCode
 * @subpackage  ServiceWorker
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API
 * @see         https://developer.mozilla.org/en-US/docs/Web/API/Cache
 * @see         https://developer.mozilla.org/en-US/docs/Web/API/FetchEvent
 */


// ==============================================================================
// 📦 Cache Configuration
// ==============================================================================
// Cache name includes a version string for easy cache busting.
// Increment the version when deploying updated static assets.
// @see https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage
// ==============================================================================

/**
 * 📌 Cache version identifier.
 * Change this value to bust all caches on next service worker activation.
 * @type {string}
 */
var CACHE_VERSION = 'cuercode-v2';

/**
 * 📌 Static assets cache name.
 * Stores precached and runtime-cached static files (CSS, JS, images, fonts).
 * @type {string}
 */
var STATIC_CACHE = CACHE_VERSION + '-static';

/**
 * 📌 Pages cache name.
 * Stores HTML page responses for network-first strategy.
 * @type {string}
 */
var PAGES_CACHE = CACHE_VERSION + '-pages';

/**
 * 📌 CDN cache name.
 * Stores third-party CDN resources (Bootstrap, FontAwesome, jQuery).
 * @type {string}
 */
var CDN_CACHE = CACHE_VERSION + '-cdn';

/**
 * 📌 List of all active cache names.
 * Used during activation to identify which caches to keep.
 * @type {string[]}
 */
var ACTIVE_CACHES = [STATIC_CACHE, PAGES_CACHE, CDN_CACHE];

/**
 * 📌 Assets to precache on service worker install.
 * These are the critical static files needed for the app shell.
 * @type {string[]}
 */
var PRECACHE_ASSETS = [
    '/assets/css/cuercode.css',
    '/assets/js/cuercode-common.js',
    '/assets/js/cuercode-generator.js',
    '/assets/img/branding/favicon.ico',
    '/assets/img/branding/favicon-32.png',
    '/assets/img/branding/favicon-16.png',
    '/assets/img/branding/favicon.svg',
    '/assets/img/branding/logo-mark.svg',
    '/assets/img/branding/logo.svg',
    '/offline.html'
];

/**
 * 📌 CDN hostname patterns to identify third-party resources.
 * Requests to these hosts are cached at runtime on first use.
 * @type {string[]}
 */
var CDN_HOSTS = [
    'cdn.jsdelivr.net',
    'cdnjs.cloudflare.com',
    'unpkg.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
    'ka-f.fontawesome.com',
    'kit.fontawesome.com',
    'code.jquery.com',
    'stackpath.bootstrapcdn.com',
    'maxcdn.bootstrapcdn.com'
];


// ==============================================================================
// 📥 Install Event - Precache Static Assets
// ==============================================================================
// The install event fires when the service worker is first registered or
// when a new version of the service worker file is detected.
// We precache the app shell assets so they are available immediately.
//
// @see https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope/install_event
// @see https://developer.mozilla.org/en-US/docs/Web/API/ExtendableEvent/waitUntil
// @see https://developer.mozilla.org/en-US/docs/Web/API/Cache/addAll
// ==============================================================================

self.addEventListener('install', function (event) {
    /* 📌 Skip waiting so the new service worker activates immediately */
    /* @see https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope/skipWaiting */
    self.skipWaiting();

    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            /* 📌 Precache all critical static assets */
            /* addAll() fetches each URL and stores the response */
            return cache.addAll(PRECACHE_ASSETS);
        }).catch(function (error) {
            /* 📌 Log precache failures but don't block installation */
            console.warn('[CueRCode SW] Precache failed for some assets:', error);
        })
    );
});


// ==============================================================================
// 🔄 Activate Event - Clean Old Caches
// ==============================================================================
// The activate event fires after install, when the new service worker takes
// control. We use this opportunity to delete any old versioned caches that
// are no longer in ACTIVE_CACHES, ensuring stale assets are cleaned up.
//
// @see https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope/activate_event
// @see https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage/keys
// @see https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage/delete
// ==============================================================================

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (cacheNames) {
            return Promise.all(
                cacheNames
                    .filter(function (cacheName) {
                        /* 📌 Only delete caches that belong to CueRCode (start with 'cuercode-') */
                        /* but are NOT in the current active set */
                        var isCueRCodeCache = cacheName.indexOf('cuercode-') === 0;
                        var isActive = ACTIVE_CACHES.indexOf(cacheName) !== -1;
                        return isCueRCodeCache && !isActive;
                    })
                    .map(function (cacheName) {
                        /* 📌 Delete the outdated cache */
                        console.log('[CueRCode SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    })
            );
        }).then(function () {
            /* 📌 Claim all open clients so the new SW controls existing tabs */
            /* @see https://developer.mozilla.org/en-US/docs/Web/API/Clients/claim */
            return self.clients.claim();
        })
    );
});


// ==============================================================================
// 🌐 Fetch Event - Request Interception & Caching Strategies
// ==============================================================================
// Intercepts all network requests from controlled pages and applies the
// appropriate caching strategy based on the request type:
//
//   1. API requests   -> Always go to network (never cached)
//   2. HTML pages     -> Network-first, fall back to cache, then offline page
//   3. CDN resources  -> Cache-first, fall back to network
//   4. Static assets  -> Cache-first, fall back to network
//   5. Everything else -> Network-first
//
// @see https://developer.mozilla.org/en-US/docs/Web/API/FetchEvent
// @see https://developer.mozilla.org/en-US/docs/Web/API/FetchEvent/respondWith
// ==============================================================================

self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url = new URL(request.url);

    /* 📌 Only handle GET requests - POST/PUT/DELETE go straight to network */
    /* @see https://developer.mozilla.org/en-US/docs/Web/API/Request/method */
    if (request.method !== 'GET') {
        return;
    }

    /* 📌 Skip non-http(s) requests (e.g. chrome-extension://) */
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return;
    }

    // --------------------------------------------------------------------------
    // 🚫 API Exclusion - Never cache API requests
    // --------------------------------------------------------------------------
    // API responses are dynamic and must always be fresh from the server.
    // @see https://developer.mozilla.org/en-US/docs/Web/API/URL/pathname
    // --------------------------------------------------------------------------

    if (url.pathname.indexOf('/api/') !== -1) {
        /* 📌 Let API requests pass through to the network unmodified */
        return;
    }

    // --------------------------------------------------------------------------
    // 🌍 CDN Resources - Cache-first with network fallback
    // --------------------------------------------------------------------------
    // Third-party CDN resources (Bootstrap CSS/JS, FontAwesome, jQuery, Google
    // Fonts) change infrequently and are versioned via their URLs. We cache
    // them on first use and serve from cache on subsequent requests.
    //
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Cache/put
    // --------------------------------------------------------------------------

    if (isCDNRequest(url)) {
        event.respondWith(
            caches.match(request).then(function (cachedResponse) {
                if (cachedResponse) {
                    /* 📌 Cache hit - serve from CDN cache */
                    return cachedResponse;
                }

                /* 📌 Cache miss - fetch from network and store in CDN cache */
                return fetch(request).then(function (networkResponse) {
                    /* 📌 Only cache successful responses */
                    if (networkResponse && networkResponse.ok) {
                        var responseClone = networkResponse.clone();
                        caches.open(CDN_CACHE).then(function (cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                }).catch(function () {
                    /* 📌 CDN unavailable and not cached - nothing we can do */
                    return new Response('', {
                        status: 503,
                        statusText: 'CDN resource unavailable offline'
                    });
                });
            })
        );
        return;
    }

    // --------------------------------------------------------------------------
    // 📄 HTML Pages - Network-first with cache fallback and offline page
    // --------------------------------------------------------------------------
    // HTML pages should always try the network first for fresh content.
    // If the network fails, fall back to a cached version. If no cached
    // version exists, show the offline fallback page.
    //
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Request/headers
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Headers/get
    // --------------------------------------------------------------------------

    if (isNavigationRequest(request)) {
        event.respondWith(
            fetch(request).then(function (networkResponse) {
                /* 📌 Network success - cache the fresh page and return it */
                if (networkResponse && networkResponse.ok) {
                    var responseClone = networkResponse.clone();
                    caches.open(PAGES_CACHE).then(function (cache) {
                        cache.put(request, responseClone);
                    });
                }
                return networkResponse;
            }).catch(function () {
                /* 📌 Network failed - try the pages cache */
                return caches.match(request).then(function (cachedResponse) {
                    if (cachedResponse) {
                        return cachedResponse;
                    }

                    /* 📌 No cached page - serve the offline fallback */
                    return caches.match('/offline.html');
                });
            })
        );
        return;
    }

    // --------------------------------------------------------------------------
    // 🎨 Static Assets - Cache-first with network fallback
    // --------------------------------------------------------------------------
    // Local static assets (CSS, JS, images, fonts, SVG) are served from
    // cache when available for best performance. If not cached, they are
    // fetched from the network and added to the static cache for next time.
    //
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Cache/match
    // --------------------------------------------------------------------------

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then(function (cachedResponse) {
                if (cachedResponse) {
                    /* 📌 Cache hit - serve from static cache */
                    /* Also trigger a background network fetch to keep cache fresh */
                    fetchAndUpdateCache(request, STATIC_CACHE);
                    return cachedResponse;
                }

                /* 📌 Cache miss - fetch from network and cache the response */
                return fetch(request).then(function (networkResponse) {
                    if (networkResponse && networkResponse.ok) {
                        var responseClone = networkResponse.clone();
                        caches.open(STATIC_CACHE).then(function (cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                }).catch(function () {
                    /* 📌 Static asset unavailable - return empty response */
                    return new Response('', {
                        status: 503,
                        statusText: 'Asset unavailable offline'
                    });
                });
            })
        );
        return;
    }

    // --------------------------------------------------------------------------
    // 🔀 Default - Network-first for all other requests
    // --------------------------------------------------------------------------
    // Any request not matching the above categories uses a simple
    // network-first strategy with no caching.
    //
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Response
    // --------------------------------------------------------------------------

    event.respondWith(
        fetch(request).catch(function () {
            return caches.match(request);
        })
    );
});


// ==============================================================================
// 🔧 Helper Functions
// ==============================================================================
// Utility functions used by the fetch event handler to classify requests
// and manage cache updates.
// ==============================================================================

/**
 * 🌍 Check if a URL points to a known CDN host
 *
 * Compares the URL hostname against the list of known CDN hostnames
 * to determine whether the request should use CDN caching strategy.
 *
 * @param {URL} url - The parsed URL object to check
 * @returns {boolean} True if the URL hostname matches a known CDN host
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/URL/hostname
 */
function isCDNRequest(url) {
    var hostname = url.hostname;
    for (var i = 0; i < CDN_HOSTS.length; i++) {
        if (hostname === CDN_HOSTS[i] || hostname.indexOf('.' + CDN_HOSTS[i]) !== -1) {
            return true;
        }
    }
    return false;
}

/**
 * 📄 Check if a request is a navigation (HTML page) request
 *
 * Uses the Request.mode property when available, with a fallback
 * that checks the Accept header for text/html content type.
 *
 * @param {Request} request - The fetch request to check
 * @returns {boolean} True if the request appears to be a page navigation
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Request/mode
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Request/destination
 */
function isNavigationRequest(request) {
    /* 📌 Modern browsers support request.mode === 'navigate' */
    if (request.mode === 'navigate') {
        return true;
    }

    /* 📌 Fallback: check Accept header for text/html */
    var acceptHeader;
    if (request.headers.get('Accept')) {
        acceptHeader = request.headers.get('Accept');
    } else {
        acceptHeader = '';
    }
    return acceptHeader.indexOf('text/html') !== -1;
}

/**
 * 🎨 Check if a URL points to a static asset
 *
 * Matches URLs with common static file extensions: CSS, JavaScript,
 * images (PNG, JPG, GIF, SVG, WebP, ICO), and fonts (WOFF, WOFF2, TTF, EOT).
 *
 * @param {URL} url - The parsed URL object to check
 * @returns {boolean} True if the URL pathname ends with a static file extension
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/URL/pathname
 */
function isStaticAsset(url) {
    /* 📌 Static asset file extensions */
    var staticExtensions = [
        '.css', '.js',
        '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico',
        '.woff', '.woff2', '.ttf', '.eot'
    ];

    var pathname = url.pathname.toLowerCase();

    for (var i = 0; i < staticExtensions.length; i++) {
        /* 📌 Check if pathname ends with the extension */
        var ext = staticExtensions[i];
        if (pathname.indexOf(ext, pathname.length - ext.length) !== -1) {
            return true;
        }
    }

    return false;
}

/**
 * 🔄 Background fetch-and-cache-update (stale-while-revalidate helper)
 *
 * Fetches a fresh copy of the resource in the background and updates
 * the cache. This keeps cached static assets reasonably fresh without
 * blocking the response to the client.
 *
 * @param {Request} request - The request to re-fetch
 * @param {string} cacheName - The cache to update with the fresh response
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Cache/put
 */
function fetchAndUpdateCache(request, cacheName) {
    fetch(request).then(function (networkResponse) {
        if (networkResponse && networkResponse.ok) {
            caches.open(cacheName).then(function (cache) {
                cache.put(request, networkResponse);
            });
        }
    }).catch(function () {
        /* 📌 Background update failed silently - cached version remains */
    });
}
