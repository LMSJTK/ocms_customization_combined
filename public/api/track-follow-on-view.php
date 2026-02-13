<?php
/**
 * Track Follow-On View API
 * Records when a user views follow-on training content
 * Sets follow_on_viewed_at, last_action_at, and status to FOLLOW_ON_VIEWED
 */

require_once '/var/www/html/public/api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();

    // Support both parameter names
    // trackingId (preferred) or tracking_link_id (backwards compatibility)
    $trackingLinkId = $input['trackingId'] ?? $input['tracking_link_id'] ?? null;

    if (!$trackingLinkId) {
        sendJSON(['error' => 'Missing tracking ID (provide trackingId or tracking_link_id)'], 400);
    }

    $result = $trackingManager->trackFollowOnView($trackingLinkId);

    sendJSON([
        'success' => true,
        'message' => 'Follow-on view tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track Follow-On View Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track follow-on view',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
