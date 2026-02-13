 <?php
/**
 * Delete Content API
 * Deletes content and associated files/records
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
    $input = json_decode(file_get_contents('php://input'), true);
    $contentId = $input['content_id'] ?? null;

    if (empty($contentId)) {
        sendJSON(['error' => 'content_id is required'], 400);
    }

    // Verify content exists
    $content = $db->fetchOne(
        'SELECT id, title, content_url FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Delete associated tags
        $db->query(
            'DELETE FROM content_tags WHERE content_id = :id',
            [':id' => $contentId]
        );

        // Delete associated training_tracking records
        $db->query(
            'DELETE FROM training_tracking WHERE training_id IN (SELECT id FROM training WHERE training_content_id = :id)',
            [':id' => $contentId]
        );

        // Delete associated training records
        $db->query(
            'DELETE FROM training WHERE training_content_id = :id',
            [':id' => $contentId]
        );

        // Delete content record
        $db->query(
            'DELETE FROM content WHERE id = :id',
            [':id' => $contentId]
        );

        // Commit transaction
        $db->commit();

        // Try to delete content files (non-critical if fails)
        $contentDir = $config['content']['upload_dir'] . $contentId;
        if (is_dir($contentDir)) {
            deleteDirectory($contentDir);
        }

        sendJSON([
            'success' => true,
            'message' => 'Content deleted successfully',
            'content_id' => $contentId
        ]);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Content Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to delete content',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}

/**
 * Recursively delete a directory
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

