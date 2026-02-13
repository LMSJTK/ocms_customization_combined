<?php
/**
 * Token Management API
 * Manages access tokens for the testing tool
 * Tokens are stored in a JSON file (no database required)
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
    $dir = dirname($tokenFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($tokenFile, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Generate a random token
 */
function generateToken() {
    return bin2hex(random_bytes(32));
}

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

try {
    $data = loadTokens($tokenFile);

    if ($method === 'GET') {
        // Check admin password for listing tokens
        $adminPassword = $_GET['admin_password'] ?? '';

        if ($adminPassword !== $data['admin_password']) {
            sendJSON(['error' => 'Invalid admin password'], 401);
        }

        // Return list of tokens (without the actual token values for security)
        $tokenList = [];
        foreach ($data['tokens'] as $token => $info) {
            $tokenList[] = [
                'token' => substr($token, 0, 8) . '...' . substr($token, -8),
                'full_token' => $token,
                'name' => $info['name'],
                'created_at' => $info['created_at'],
                'last_used' => $info['last_used'] ?? null
            ];
        }

        sendJSON([
            'success' => true,
            'tokens' => $tokenList
        ]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $adminPassword = $input['admin_password'] ?? '';

        // Validate admin password
        if ($adminPassword !== $data['admin_password']) {
            sendJSON(['error' => 'Invalid admin password'], 401);
        }

        if ($action === 'create') {
            // Create new token
            $name = $input['name'] ?? '';
            if (empty($name)) {
                sendJSON(['error' => 'Name is required'], 400);
            }

            $token = generateToken();
            $data['tokens'][$token] = [
                'name' => $name,
                'created_at' => date('Y-m-d H:i:s'),
                'last_used' => null
            ];

            saveTokens($tokenFile, $data);

            sendJSON([
                'success' => true,
                'token' => $token,
                'name' => $name,
                'message' => 'Token created successfully'
            ]);

        } elseif ($action === 'revoke') {
            // Revoke (delete) a token
            $token = $input['token'] ?? '';
            if (empty($token)) {
                sendJSON(['error' => 'Token is required'], 400);
            }

            if (!isset($data['tokens'][$token])) {
                sendJSON(['error' => 'Token not found'], 404);
            }

            $name = $data['tokens'][$token]['name'];
            unset($data['tokens'][$token]);
            saveTokens($tokenFile, $data);

            sendJSON([
                'success' => true,
                'message' => "Token for '{$name}' has been revoked"
            ]);

        } elseif ($action === 'change_password') {
            // Change admin password
            $newPassword = $input['new_password'] ?? '';
            if (empty($newPassword) || strlen($newPassword) < 6) {
                sendJSON(['error' => 'New password must be at least 6 characters'], 400);
            }

            $data['admin_password'] = $newPassword;
            saveTokens($tokenFile, $data);

            sendJSON([
                'success' => true,
                'message' => 'Admin password changed successfully'
            ]);

        } else {
            sendJSON(['error' => 'Invalid action'], 400);
        }

    } else {
        sendJSON(['error' => 'Method not allowed'], 405);
    }

} catch (Exception $e) {
    error_log("Token API Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Token operation failed',
        'message' => $e->getMessage()
    ], 500);
}
