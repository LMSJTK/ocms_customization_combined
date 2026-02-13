<?php
/**
 * Check Content API
 * Checks if content exists by ID(s) in the database
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Handle different request methods
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Single ID check via query parameter
    $id = $_GET['id'] ?? null;

    if (!$id) {
        sendJSON(['error' => 'id parameter is required'], 400);
    }

    // Normalize UUID format
    $id = strtolower(trim($id));

    try {
        $content = $db->fetchOne(
            'SELECT id, title, content_type, created_at FROM content WHERE id = :id',
            [':id' => $id]
        );

        sendJSON([
            'success' => true,
            'exists' => $content !== null,
            'content' => $content
        ]);
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Batch ID check via POST body
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['ids']) || !is_array($input['ids'])) {
        sendJSON(['error' => 'ids array is required in POST body'], 400);
    }

    $ids = $input['ids'];

    if (empty($ids)) {
        sendJSON([
            'success' => true,
            'existing' => [],
            'missing' => []
        ]);
    }

    // Normalize all IDs
    $normalizedIds = array_map(function($id) {
        return strtolower(trim($id));
    }, $ids);

    try {
        // Build query with placeholders
        $placeholders = [];
        $params = [];
        foreach ($normalizedIds as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params[':id' . $index] = $id;
        }

        $query = 'SELECT id FROM content WHERE id IN (' . implode(', ', $placeholders) . ')';
        $existingRows = $db->fetchAll($query, $params);

        // Build set of existing IDs
        $existingIds = [];
        foreach ($existingRows as $row) {
            $existingIds[$row['id']] = true;
        }

        // Separate into existing and missing
        $existing = [];
        $missing = [];
        foreach ($normalizedIds as $id) {
            if (isset($existingIds[$id])) {
                $existing[] = $id;
            } else {
                $missing[] = $id;
            }
        }

        sendJSON([
            'success' => true,
            'existing' => $existing,
            'missing' => $missing,
            'total_checked' => count($normalizedIds),
            'total_existing' => count($existing),
            'total_missing' => count($missing)
        ]);

    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

} else {
    sendJSON(['error' => 'Method not allowed'], 405);
}
