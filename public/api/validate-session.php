<?php
/**
 * Session Validation API
 * Validates access tokens for the testing tool
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate VPN access (internal tool)
validateVpnAccess();

// Token file path
$tokenFile = '/var/www/html/data/access_tokens.json';

/**
 * Load tokens from file
 */
function loadTokens($tokenFile) {
    if (!file_exists($tokenFile)) {
        return ['tokens' => [], 'admin_password' => 'admin123'];
    }
    $content = file_get_contents($tokenFile);
    return json_decode($content, true) ?: ['tokens' => [], 'admin_password' => 'admin123'];
}

/**
 * Save tokens to file
 */
function saveTokens($tokenFile, $data) {
    file_put_contents($tokenFile, json_encode($data, JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';

    if (empty($token)) {
        sendJSON(['error' => 'Token is required'], 400);
    }

    $data = loadTokens($tokenFile);

    // Check if token exists
    if (!isset($data['tokens'][$token])) {
        sendJSON([
            'success' => false,
            'valid' => false,
            'error' => 'Invalid token'
        ], 401);
    }

    // Update last used timestamp
    $data['tokens'][$token]['last_used'] = date('Y-m-d H:i:s');
    saveTokens($tokenFile, $data);

    sendJSON([
        'success' => true,
        'valid' => true,
        'name' => $data['tokens'][$token]['name']
    ]);

} catch (Exception $e) {
    error_log("Session Validation Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Validation failed',
        'message' => $e->getMessage()
    ], 500);
}
