<?php
/**
 * Track Data Entry API
 * Records when a user enters data in a phishing form
 * Sets data_entered_at and last_action_at
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

    $result = $trackingManager->trackDataEntry($trackingLinkId);

    sendJSON([
        'success' => true,
        'message' => 'Data entry tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track Data Entry Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track data entry',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}

