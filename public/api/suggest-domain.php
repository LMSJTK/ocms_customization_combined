<?php
/**
 * Suggest Domain API
 * Uses Claude to suggest appropriate phishing domains for content
 * Supports single content and batch processing
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Handle POST request - suggest domain(s)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJSONInput();

    // Determine if this is a batch request
    $isBatch = isset($input['batch']) && $input['batch'] === true;
    $autoApply = isset($input['apply']) && $input['apply'] === true;

    try {
        // Fetch available domains from pm_phishing_domain (is_hidden = false)
        $domains = fetchAvailableDomains($db);

        if (empty($domains)) {
            sendJSON([
                'success' => false,
                'error' => 'No available domains found in pm_phishing_domain table'
            ], 400);
        }

        if ($isBatch) {
            // Batch mode - process multiple content items
            $results = processBatch($db, $claudeAPI, $domains, $input, $autoApply);
            sendJSON([
                'success' => true,
                'batch' => true,
                'applied' => $autoApply,
                'results' => $results
            ]);
        } else {
            // Single content mode
            if (!isset($input['content_id']) || empty($input['content_id'])) {
                sendJSON([
                    'success' => false,
                    'error' => 'content_id is required for single content mode'
                ], 400);
            }

            $result = processSingleContent($db, $claudeAPI, $domains, $input['content_id'], $autoApply);
            sendJSON(array_merge(['success' => true, 'applied' => $autoApply], $result));
        }

    } catch (Exception $e) {
        error_log("Suggest domain error: " . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to suggest domain',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Processing error'
        ], 500);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET request - return stats about content needing domains
    try {
        $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';

        // Count content without domains
        $countSql = "SELECT COUNT(*) as total FROM $table WHERE content_domain IS NULL OR content_domain = ''";
        $result = $db->fetchOne($countSql);
        $needsDomain = (int)$result['total'];

        // Count content with domains
        $countSql = "SELECT COUNT(*) as total FROM $table WHERE content_domain IS NOT NULL AND content_domain != ''";
        $result = $db->fetchOne($countSql);
        $hasDomain = (int)$result['total'];

        // Fetch available domains count
        $domains = fetchAvailableDomains($db);

        sendJSON([
            'success' => true,
            'stats' => [
                'content_needs_domain' => $needsDomain,
                'content_has_domain' => $hasDomain,
                'available_domains' => count($domains)
            ],
            'domains' => $domains
        ]);

    } catch (Exception $e) {
        error_log("Suggest domain stats error: " . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to fetch stats'
        ], 500);
    }

} else {
    sendJSON([
        'success' => false,
        'error' => 'Method not allowed',
        'allowed_methods' => ['GET', 'POST']
    ], 405);
}

/**
 * Fetch available domains from pm_phishing_domain table
 */
function fetchAvailableDomains($db) {
    // Query the legacy pm_phishing_domain table for non-hidden domains
    $sql = "SELECT tag, domain FROM global.pm_phishing_domain WHERE is_hidden = false ORDER BY tag";

    try {
        $rows = $db->fetchAll($sql);
        return $rows;
    } catch (Exception $e) {
        error_log("Failed to fetch phishing domains: " . $e->getMessage());
        return [];
    }
}

/**
 * Process a single content item
 */
function processSingleContent($db, $claudeAPI, $domains, $contentId, $autoApply) {
    $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';
    $tagsTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content_tags';

    // Fetch content details
    $content = $db->fetchOne(
        "SELECT id, title, description, content_domain FROM $table WHERE id = :id",
        [':id' => $contentId]
    );

    if (!$content) {
        throw new Exception("Content not found: $contentId");
    }

    // Fetch tags for this content
    $tags = $db->fetchAll(
        "SELECT tag_name FROM $tagsTable WHERE content_id = :id",
        [':id' => $contentId]
    );
    $tagNames = array_column($tags, 'tag_name');

    // Get suggestion from Claude
    $suggestion = $claudeAPI->suggestDomain(
        $content['title'] ?? '',
        $content['description'] ?? '',
        $tagNames,
        $domains
    );

    // Auto-apply if requested
    if ($autoApply) {
        $db->query(
            "UPDATE $table SET content_domain = :domain WHERE id = :id",
            [':domain' => $suggestion['domain'], ':id' => $contentId]
        );
    }

    return [
        'content_id' => $contentId,
        'title' => $content['title'],
        'current_domain' => $content['content_domain'],
        'suggested_domain' => $suggestion['domain'],
        'suggested_tag' => $suggestion['tag'],
        'reasoning' => $suggestion['reasoning']
    ];
}

/**
 * Process batch of content items
 */
function processBatch($db, $claudeAPI, $domains, $input, $autoApply) {
    $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';
    $tagsTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content_tags';

    // Determine which content to process
    $limit = isset($input['limit']) ? min((int)$input['limit'], 100) : 50;

    if (isset($input['content_ids']) && is_array($input['content_ids'])) {
        // Process specific content IDs
        $contentIds = array_slice($input['content_ids'], 0, $limit);
        $placeholders = [];
        $params = [];
        foreach ($contentIds as $i => $id) {
            $placeholders[] = ":id$i";
            $params[":id$i"] = $id;
        }
        $sql = "SELECT id, title, description, content_domain FROM $table WHERE id IN (" . implode(',', $placeholders) . ")";
        $contents = $db->fetchAll($sql, $params);
    } else {
        // Process all content without domains
        $sql = "SELECT id, title, description, content_domain FROM $table
                WHERE (content_domain IS NULL OR content_domain = '')
                LIMIT :limit";
        $contents = $db->fetchAll($sql, [':limit' => $limit]);
    }

    $results = [];
    $processed = 0;
    $applied = 0;
    $errors = 0;

    foreach ($contents as $content) {
        try {
            // Fetch tags for this content
            $tags = $db->fetchAll(
                "SELECT tag_name FROM $tagsTable WHERE content_id = :id",
                [':id' => $content['id']]
            );
            $tagNames = array_column($tags, 'tag_name');

            // Get suggestion from Claude
            $suggestion = $claudeAPI->suggestDomain(
                $content['title'] ?? '',
                $content['description'] ?? '',
                $tagNames,
                $domains
            );

            // Auto-apply if requested
            if ($autoApply) {
                $db->query(
                    "UPDATE $table SET content_domain = :domain WHERE id = :id",
                    [':domain' => $suggestion['domain'], ':id' => $content['id']]
                );
                $applied++;
            }

            $results[] = [
                'content_id' => $content['id'],
                'title' => $content['title'],
                'current_domain' => $content['content_domain'],
                'suggested_domain' => $suggestion['domain'],
                'suggested_tag' => $suggestion['tag'],
                'reasoning' => $suggestion['reasoning'],
                'status' => 'success'
            ];
            $processed++;

            // Small delay between API calls to avoid rate limiting
            usleep(100000); // 100ms

        } catch (Exception $e) {
            error_log("Batch domain suggestion error for {$content['id']}: " . $e->getMessage());
            $results[] = [
                'content_id' => $content['id'],
                'title' => $content['title'],
                'status' => 'error',
                'error' => $e->getMessage()
            ];
            $errors++;
        }
    }

    return [
        'processed' => $processed,
        'applied' => $applied,
        'errors' => $errors,
        'items' => $results
    ];
}
