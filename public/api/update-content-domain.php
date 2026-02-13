<?php
/**
 * Update Content Domain API
 * Allows updating the content_domain field for a piece of content
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Handle POST request - update content domain
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJSONInput();

    // Validate required fields
    if (!isset($input['content_id']) || empty($input['content_id'])) {
        sendJSON([
            'success' => false,
            'error' => 'content_id is required'
        ], 400);
    }

    // content_domain can be null to clear the domain
    $contentId = $input['content_id'];
    $contentDomain = isset($input['content_domain']) ? trim($input['content_domain']) : null;

    // If empty string, treat as null (clear the domain)
    if ($contentDomain === '') {
        $contentDomain = null;
    }

    try {
        // Verify content exists
        $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';
        $content = $db->fetchOne("SELECT id, title FROM $table WHERE id = :id", [':id' => $contentId]);

        if (!$content) {
            sendJSON([
                'success' => false,
                'error' => 'Content not found'
            ], 404);
        }

        // Update the content_domain field
        $updateSql = "UPDATE $table SET content_domain = :domain WHERE id = :id";
        $db->query($updateSql, [
            ':domain' => $contentDomain,
            ':id' => $contentId
        ]);

        sendJSON([
            'success' => true,
            'message' => $contentDomain ? 'Content domain updated' : 'Content domain cleared',
            'content_id' => $contentId,
            'content_domain' => $contentDomain
        ]);

    } catch (Exception $e) {
        error_log("Update content domain error: " . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to update content domain',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Database error'
        ], 500);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET request - list content with domain info for the domain testing UI

    try {
        $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';

        // Pagination parameters
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        // Optional search
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $whereConditions = [];
        $params = [];

        if ($search !== '') {
            $whereConditions[] = "(LOWER(title) LIKE LOWER(:search) OR LOWER(id) LIKE LOWER(:search))";
            $params[':search'] = '%' . $search . '%';
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM $table $whereClause";
        $countResult = $db->fetchOne($countSql, $params);
        $totalCount = $countResult['total'];

        // Fetch content with domain info
        $sql = "SELECT id, title, content_type, content_domain, created_at, updated_at
                FROM $table $whereClause
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $content = $db->fetchAll($sql, $params);

        sendJSON([
            'success' => true,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'content' => $content
        ]);

    } catch (Exception $e) {
        error_log("List content for domain testing error: " . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to fetch content',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Database error'
        ], 500);
    }

} else {
    sendJSON([
        'success' => false,
        'error' => 'Method not allowed',
        'allowed_methods' => ['GET', 'POST']
    ], 405);
}
