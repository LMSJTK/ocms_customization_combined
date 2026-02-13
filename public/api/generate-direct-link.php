<?php
/**
 * Generate Direct Link API
 * Creates a training_tracking entry and returns the direct link
 * Optionally creates follow-on content link as well
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['content_id', 'recipient_id']);

    $contentId = $input['content_id'];
    $recipientId = $input['recipient_id'];
    $email = $input['email'] ?? '';
    $firstName = $input['first_name'] ?? '';
    $lastName = $input['last_name'] ?? '';
    // Optional: For landing page testing - specify the training content to redirect to
    $targetContentId = $input['target_content_id'] ?? null;
    // Optional: Follow-on content for the training
    $followOnContentId = $input['follow_on_content_id'] ?? null;

    // Verify content exists
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Verify follow-on content exists if provided
    $followOnContent = null;
    if ($followOnContentId) {
        $followOnContent = $db->fetchOne(
            'SELECT * FROM content WHERE id = :id',
            [':id' => $followOnContentId]
        );
        if (!$followOnContent) {
            sendJSON(['error' => 'Follow-on content not found'], 404);
        }
    }

    // Generate unique IDs
    $trainingId = generateUUID4();
    $trainingTrackingId = generateUUID4();
    $uniqueTrackingId = generateUUID4();

    // First, create a training record
    // This is required because training_tracking.training_id references training.id
    // If target_content_id is provided, this is a landing page test:
    //   - landing_content_id = the content being launched (the landing page)
    //   - training_content_id = the target to redirect to after form submission
    // Otherwise, it's a direct content test:
    //   - training_content_id = the content being launched
    $trainingRecord = [
        'id' => $trainingId,
        'company_id' => $input['company_id'] ?? 'default',
        'name' => 'Direct Link: ' . $content['title'],
        'description' => 'Auto-generated training for direct link',
        'training_type' => $targetContentId ? 'landing_page_test' : 'direct_link',
        'landing_content_id' => $targetContentId ? $contentId : null,
        'training_content_id' => $targetContentId ? $targetContentId : $contentId,
        'follow_on_content_id' => $followOnContentId,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training',
        $trainingRecord
    );

    // Now insert into training_tracking table with reference to the training record
    $trainingTrackingData = [
        'id' => $trainingTrackingId,
        'training_id' => $trainingId, // References the training record we just created
        'recipient_id' => $recipientId,
        'unique_tracking_id' => $uniqueTrackingId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
        $trainingTrackingData
    );

    // Determine which domain to use
    $domainUrl = $config['app']['base_url']; // Default fallback

    // Check if content has a domain
    if (!empty($content['domain_id'])) {
        try {
            $contentDomain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id',
                [':id' => $content['domain_id']]
            );

            if ($contentDomain && $contentDomain['is_active']) {
                $domainUrl = $contentDomain['domain_url'];
            }
        } catch (Exception $e) {
            error_log("Domain lookup failed: " . $e->getMessage());
        }
    }

    // Build direct launch URL with PATH_INFO format (dashless IDs)
    // Format: /launch.php/{content_id_without_dashes}/{tracking_id_without_dashes}
    $contentIdNoDash = str_replace('-', '', $contentId);
    $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
    $directUrl = rtrim($domainUrl, '/') . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

    // Build follow-on URL if follow-on content is provided
    $followOnUrl = null;
    if ($followOnContentId) {
        $followOnIdNoDash = str_replace('-', '', $followOnContentId);
        $followOnUrl = rtrim($domainUrl, '/') . '/launch.php/' . $followOnIdNoDash . '/' . $trackingIdNoDash;
    }

    // Build response
    $response = [
        'success' => true,
        'training_id' => $trainingId,
        'tracking_id' => $trainingTrackingId,
        'unique_tracking_id' => $uniqueTrackingId,
        'direct_url' => $directUrl,
        'content' => [
            'id' => $content['id'],
            'title' => $content['title'],
            'type' => $content['content_type']
        ],
        'recipient' => [
            'id' => $recipientId,
            'email' => $email
        ]
    ];

    // Add follow-on info if provided
    if ($followOnContentId && $followOnContent) {
        $response['follow_on_url'] = $followOnUrl;
        $response['follow_on_content'] = [
            'id' => $followOnContent['id'],
            'title' => $followOnContent['title'],
            'type' => $followOnContent['content_type']
        ];
    }

    sendJSON($response, 201);

} catch (Exception $e) {
    error_log("Generate Direct Link Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to generate direct link',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
