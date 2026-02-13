<?php
/**
 * List Content API
 * Returns a list of all uploaded content
 * Supports optional filtering by content_type via ?type= parameter
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    // Check for filters
    $typeFilter = $_GET['type'] ?? null;
    $searchFilter = $_GET['search'] ?? null;
    $tagFilter = $_GET['tag'] ?? null;
    $params = [];
    $whereConditions = [];

    // Build query with optional filters
    $query = '
        SELECT DISTINCT c.id, c.company_id, c.title, c.description, c.content_type, c.content_preview,
               c.content_url, c.email_from_address, c.email_subject, c.email_body_html,
               c.attachment_filename, c.thumbnail_filename, c.tags, c.difficulty, c.content_domain,
               c.scorable, c.created_at, c.updated_at
        FROM content c
    ';

    // Join with content_tags if filtering by tag
    if ($tagFilter) {
        $query .= ' JOIN content_tags ct ON c.id = ct.content_id';
        $whereConditions[] = 'ct.tag_name = :tag';
        $params[':tag'] = $tagFilter;
    }

    // Add type filter
    if ($typeFilter) {
        $whereConditions[] = 'c.content_type = :type';
        $params[':type'] = $typeFilter;
    }

    // Add search filter (searches title)
    if ($searchFilter) {
        $whereConditions[] = 'LOWER(c.title) LIKE LOWER(:search)';
        $params[':search'] = '%' . $searchFilter . '%';
    }

    // Apply WHERE conditions
    if (!empty($whereConditions)) {
        $query .= ' WHERE ' . implode(' AND ', $whereConditions);
    }

    $query .= ' ORDER BY c.created_at DESC';

    // Get content (excluding binary attachment and thumbnail content to avoid JSON encoding issues)
    $content = $db->fetchAll($query, $params);

    // For each content item, get its tags
    foreach ($content as &$item) {
        $tags = $db->fetchAll(
            'SELECT tag_name FROM content_tags WHERE content_id = :id',
            [':id' => $item['id']]
        );
        $item['tags'] = array_column($tags, 'tag_name');
    }

    sendJSON([
        'success' => true,
        'content' => $content
    ]);

} catch (Exception $e) {
    error_log("List Content Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to list content',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
