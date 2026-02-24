(function() {
    'use strict';
    
    // Configuration
    var DEBUG = false; // Set to true for console logging
    
    function log(message, data) {
        if (DEBUG && console && console.log) {
            console.log('[Mini Metrics] ' + message, data || '');
        }
    }
    
    // Get tracking endpoint from script src
    var scripts = document.getElementsByTagName('script');
    var endpoint = null;
    
    for (var i = 0; i < scripts.length; i++) {
        if (scripts[i].src && scripts[i].src.indexOf('track.js') > -1) {
            endpoint = scripts[i].src.replace('track.js', 'track.php');
            break;
        }
    }
    
    if (!endpoint) {
        log('ERROR: Could not determine tracking endpoint');
        return;
    }
    
    log('Initialized with endpoint:', endpoint);
    
    // Track last path to prevent duplicates
    var lastTrackedPath = null;
    
    function track() {
        var currentPath = window.location.pathname;
        
        // Only track if path actually changed
        if (currentPath === lastTrackedPath) {
            log('Same path, skipping:', currentPath);
            return;
        }
        
        lastTrackedPath = currentPath;
        
        var hostname = window.location.hostname;
        
        // Normalize: strip www. prefix
        if (hostname.indexOf('www.') === 0) {
            hostname = hostname.substring(4);
        }
        
        // Filter referrer: only send if external domain
        var referrer = '';
        var rawReferrer = document.referrer || '';
        
        if (rawReferrer) {
            var referrerMatch = rawReferrer.match(/^https?:\/\/([^\/]+)/);
            if (referrerMatch) {
                var referrerHost = referrerMatch[1];
                
                if (referrerHost.indexOf('www.') === 0) {
                    referrerHost = referrerHost.substring(4);
                }
                
                if (referrerHost !== hostname) {
                    referrer = rawReferrer;
                }
            }
        }
        
        var data = {
            url: hostname,
            path: currentPath,
            referrer: referrer
        };
        
        log('Tracking pageview:', data);
        
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data),
            keepalive: true
        })
        .then(function(response) {
            if (!response.ok) {
                log('ERROR: Server returned ' + response.status);
            } else {
                log('Pageview tracked successfully');
            }
        })
        .catch(function(error) {
            log('ERROR: Failed to track', error);
        });
    }
    
    // Track initial pageview
    track();
    
    // Watch for URL changes (SPA navigation)
    var originalPushState = history.pushState;
    if (originalPushState) {
        history.pushState = function() {
            originalPushState.apply(history, arguments);
            track();
        };
    }
    
    var originalReplaceState = history.replaceState;
    if (originalReplaceState) {
        history.replaceState = function() {
            originalReplaceState.apply(history, arguments);
            track();
        };
    }
    
    window.addEventListener('popstate', function() {
        track();
    });
    
    log('Tracking initialized');
})();
