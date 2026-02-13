<?php
/**
 * Report Phishing API
 * Records when a user reports an email as phishing
 * Sets training_reported_at, last_action_at, and status to TRAINING_REPORTED
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

    error_log("Report Phishing: Received request with trackingId=" . ($trackingLinkId ?? 'null'));

    if (!$trackingLinkId) {
        error_log("Report Phishing: Missing tracking ID in request: " . json_encode($input));
        sendJSON(['error' => 'Missing tracking ID (provide trackingId or tracking_link_id)'], 400);
    }

    $result = $trackingManager->reportPhishing($trackingLinkId);

    error_log("Report Phishing: Successfully recorded for trackingId=$trackingLinkId, recipient_id=" . ($result['recipient_id'] ?? 'null'));

    sendJSON([
        'success' => true,
        'message' => 'Phishing report recorded successfully',
        'recipient_id' => $result['recipient_id'] ?? null
    ]);

} catch (Exception $e) {
    error_log("Report Phishing Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to record phishing report',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
