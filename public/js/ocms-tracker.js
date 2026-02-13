/**
 * OCMS Tracking Script v1.0
 *
 * External tracking script for OCMS content. Handles:
 * - Page view tracking
 * - Element interaction tracking (data-tag, data-cue attributes)
 * - SCORM score recording (RecordTest hijacking)
 * - Landing page form submissions
 *
 * Configuration via script tag data attributes:
 *   <script src="ocms-tracker.js" data-api-base="https://server.com/api"></script>
 *
 * Or via meta tags:
 *   <meta name="ocms-api-base" content="https://server.com/api">
 *   <meta name="ocms-tracking-id" content="tracking-id-here">
 *
 * URL Parameters (override meta tags):
 *   ?tid=<tracking-id>     - Tracking session ID (overrides meta tag)
 *   ?nextUrl=<url>         - Redirect URL after form submission (landing pages)
 */
(function() {
    'use strict';

    // Get API base URL from script data attribute or meta tag
    function getApiBase() {
        // Try script tag data attribute first
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('ocms-tracker') !== -1) {
                var apiBase = scripts[i].getAttribute('data-api-base');
                if (apiBase) return apiBase;
            }
        }

        // Try meta tag
        var meta = document.querySelector('meta[name="ocms-api-base"]');
        if (meta) {
            return meta.getAttribute('content');
        }

        // Fallback: try to detect from current URL
        console.warn('OCMS Tracker: No API base configured, using relative /api path');
        return '/api';
    }

    // Get tracking ID from URL params or meta tag
    function getTrackingId() {
        // URL param takes priority (for S3 content)
        var urlParams = getUrlParams();
        if (urlParams.tid) {
            return urlParams.tid;
        }

        // Try meta tag (for local PHP content)
        var meta = document.querySelector('meta[name="ocms-tracking-id"]');
        if (meta) {
            return meta.getAttribute('content');
        }

        return 'unknown';
    }

    // Get content ID from meta tag (injected by launch.php)
    function getContentId() {
        var meta = document.querySelector('meta[name="ocms-content-id"]');
        if (meta) {
            return meta.getAttribute('content');
        }
        return null;
    }

    // Extract URL parameters
    function getUrlParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (search) {
            var pairs = search.split('&');
            for (var i = 0; i < pairs.length; i++) {
                var pair = pairs[i].split('=');
                params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
            }
        }
        return params;
    }

    // Get next URL from URL params or meta tag (for landing page form interception)
    function getNextUrl() {
        var urlParams = getUrlParams();
        if (urlParams.nextUrl) {
            return urlParams.nextUrl;
        }

        // Try meta tag (injected by launch.php for landing pages)
        var meta = document.querySelector('meta[name="ocms-next-url"]');
        if (meta) {
            return meta.getAttribute('content');
        }

        return null;
    }

    // Configuration
    var API_BASE = getApiBase();
    var urlParams = getUrlParams();
    var TRACKING_LINK_ID = getTrackingId();
    var CONTENT_ID = getContentId();
    var NEXT_URL = getNextUrl();

    // State
    var interactions = [];
    var finalScore = null;
    var viewTracked = false;

    // Warn if no tracking ID
    if (TRACKING_LINK_ID === 'unknown') {
        console.warn('OCMS Tracking: No tracking ID found in URL (expected ?tid=...)');
    }

    /**
     * Send data to API endpoint
     */
    function sendToApi(endpoint, data) {
        var url = API_BASE + '/' + endpoint;

        // Use sendBeacon for reliability (won't be cancelled on page unload)
        if (navigator.sendBeacon && typeof Blob !== 'undefined') {
            var blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
            return;
        }

        // Fallback to fetch
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            keepalive: true
        }).catch(function(err) {
            console.error('OCMS Tracking error:', err);
        });
    }

    /**
     * Track interaction with a tagged element
     */
    function trackInteraction(element, interactionType, value) {
        var tag = element.getAttribute('data-tag') || element.getAttribute('data-cue');
        if (!tag) return;

        var interaction = {
            tag: tag,
            type: interactionType,
            value: value || null,
            timestamp: new Date().toISOString()
        };

        interactions.push(interaction);

        sendToApi('track-interaction.php', {
            tracking_link_id: TRACKING_LINK_ID,
            tag_name: tag,
            interaction_type: interactionType,
            interaction_value: value || null
        });
    }

    /**
     * Track page view
     */
    function trackView() {
        if (viewTracked) return;
        viewTracked = true;

        sendToApi('track-view.php', {
            tracking_link_id: TRACKING_LINK_ID
        });
    }

    /**
     * Track form submission (for landing pages)
     */
    function trackDataEntry(callback) {
        sendToApi('track-data-entry.php', {
            trackingId: TRACKING_LINK_ID
        });

        // Give the beacon time to send, then callback
        setTimeout(callback, 100);
    }

    /**
     * Record test/quiz score (SCORM compatibility)
     */
    function recordScore(score) {
        finalScore = score;

        var data = {
            tracking_link_id: TRACKING_LINK_ID,
            score: score,
            interactions: interactions
        };

        // Include content_id if available (needed to distinguish training vs follow-on)
        if (CONTENT_ID) {
            data.content_id = CONTENT_ID;
        }

        sendToApi('record-score.php', data);

        return true;
    }

    /**
     * Initialize tracking on DOM ready
     */
    function initTracking() {
        // Track page view
        trackView();

        // Fix for href="#" links: Prevent navigation due to <base> tag
        // The <base> tag makes href="#" resolve to the content directory
        // instead of the current page, causing 404s on education pages.
        var placeholderLinks = document.querySelectorAll('a[href="#"]');
        placeholderLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
            });
        });

        // Find all elements with data-tag or data-cue attributes
        var taggedElements = document.querySelectorAll('[data-tag], [data-cue]');

        taggedElements.forEach(function(el) {
            // Track clicks
            el.addEventListener('click', function() {
                trackInteraction(this, 'click');
            });

            // Track input changes for form elements
            var tagName = el.tagName.toUpperCase();
            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
                el.addEventListener('change', function() {
                    trackInteraction(this, 'input', this.value);
                });
            }
        });

        // Handle landing page form submissions (redirect to nextUrl after tracking)
        if (NEXT_URL) {
            var forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    trackDataEntry(function() {
                        window.location.href = NEXT_URL;
                    });
                });
            });
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTracking);
    } else {
        initTracking();
    }

    // Expose RecordTest globally for SCORM content
    window.RecordTest = recordScore;

    /**
     * SCORM 1.2 API Adapter
     * Provides standard SCORM 1.2 API methods and translates them to OCMS tracking
     * Standard SCORM packages look for window.API to communicate with the LMS
     */
    var scormData = {
        'cmi.core.score.raw': '',
        'cmi.core.score.min': '0',
        'cmi.core.score.max': '100',
        'cmi.core.lesson_status': 'incomplete',
        'cmi.core.lesson_location': '',
        'cmi.core.session_time': '',
        'cmi.suspend_data': '',
        'cmi.core.student_id': TRACKING_LINK_ID,
        'cmi.core.student_name': ''
    };

    var scormAPI = {
        LMSInitialize: function(param) {
            console.log('SCORM: LMSInitialize called');
            return 'true';
        },

        LMSFinish: function(param) {
            console.log('SCORM: LMSFinish called');
            // Send score on finish if not already sent
            sendScoreIfReady();
            return 'true';
        },

        LMSGetValue: function(element) {
            var value = scormData[element] || '';
            console.log('SCORM: LMSGetValue(' + element + ') = ' + value);
            return value;
        },

        LMSSetValue: function(element, value) {
            console.log('SCORM: LMSSetValue(' + element + ', ' + value + ')');
            scormData[element] = value;

            // Don't send score immediately on every score.raw update
            // SCORM packages may update score progressively (25% -> 50% -> 75% -> 100%)
            // We only want to record the FINAL score, not intermediate values
            if (element === 'cmi.core.score.raw') {
                console.log('SCORM: Score updated to ' + value + ' (will send on completion/finish)');
            }

            // Send score when lesson_status changes to completed/passed
            // This is the proper signal that the learner has finished
            if (element === 'cmi.core.lesson_status') {
                if (value === 'completed' || value === 'passed') {
                    console.log('SCORM: Lesson marked as ' + value + ', sending final score');
                    sendScoreIfReady();
                }
            }

            return 'true';
        },

        LMSCommit: function(param) {
            console.log('SCORM: LMSCommit called');
            // Send score on commit if not already sent
            sendScoreIfReady();
            return 'true';
        },

        LMSGetLastError: function() {
            return '0';
        },

        LMSGetErrorString: function(errorCode) {
            return 'No error';
        },

        LMSGetDiagnostic: function(errorCode) {
            return 'No diagnostic information available';
        }
    };

    /**
     * Send score to backend if we have a valid score
     * Allows re-sending if the score has changed (handles SCORM packages that initialize to 0)
     */
    var lastSentScore = null;  // Track the last score we sent

    function sendScoreIfReady() {
        var score = scormData['cmi.core.score.raw'];
        if (score !== '' && score !== null && score !== undefined) {
            var numScore = parseFloat(score);
            if (!isNaN(numScore)) {
                // Only send if score has changed from what we last sent
                if (lastSentScore === numScore) {
                    console.log('SCORM: Score ' + numScore + ' already sent, skipping');
                    return;
                }
                console.log('SCORM: Sending score to backend: ' + numScore);
                lastSentScore = numScore;
                recordScore(numScore);
            }
        }
    }

    // Safety net: send score on page unload if not already sent
    window.addEventListener('beforeunload', function() {
        if (lastSentScore === null) {
            sendScoreIfReady();
        }
    });

    // Expose SCORM 1.2 API - SCORM content looks for window.API
    window.API = scormAPI;

    // Also expose for SCORM 2004 compatibility (uses API_1484_11)
    // Basic adapter - just forwards to SCORM 1.2 API
    window.API_1484_11 = {
        Initialize: scormAPI.LMSInitialize,
        Terminate: scormAPI.LMSFinish,
        GetValue: scormAPI.LMSGetValue,
        SetValue: scormAPI.LMSSetValue,
        Commit: scormAPI.LMSCommit,
        GetLastError: scormAPI.LMSGetLastError,
        GetErrorString: scormAPI.LMSGetErrorString,
        GetDiagnostic: scormAPI.LMSGetDiagnostic
    };

    // Expose OCMS tracker API for advanced usage
    window.OCMSTracker = {
        version: '1.1',
        trackingId: TRACKING_LINK_ID,
        contentId: CONTENT_ID,
        trackInteraction: trackInteraction,
        trackView: trackView,
        recordScore: recordScore,
        getInteractions: function() { return interactions.slice(); },
        scormAPI: scormAPI
    };

})();
