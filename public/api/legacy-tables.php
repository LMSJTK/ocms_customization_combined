<?php
/**
 * Legacy Tables API
 * Provides read access to legacy PM management tables for content review
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Define allowed tables and their schemas for security
$allowedTables = [
    'pm_email_template' => [
        'table' => 'global.pm_email_template',
        'columns' => [
            'id', 'template_name', 'from_address', 'subject', 'body', 'body_type',
            'is_active', 'from_name', 'deleted_at', 'created_at', 'updated_at',
            'urgency', 'language_code'
        ],
        'description' => 'Email templates for phishing simulations'
    ],
    'pm_education_template' => [
        'table' => 'global.pm_education_template',
        'columns' => [
            'id', 'template_name', 'html', 'is_active', 'is_default', 'description',
            'smallimage', 'scenario_type_id', 'table_order', 'education_type_id',
            'company_id', 'deleted_at', 'content_preview_image', 'created_at',
            'updated_at', 'to_be_deleted_on', 'vimeo_id', 'content_preview_thumbnail',
            'approved_brands', 'paid_content', 'languages'
        ],
        'description' => 'Education/training content templates'
    ],
    'pm_doc_attachment_template' => [
        'table' => 'global.pm_doc_attachment_template',
        'columns' => [
            'attachment_type_id', 'name', 'description', 'created_at', 'updated_at',
            'requires_logo', 'preview_html', 'preview_bg_image_filename', 'template_filename',
            'is_active', 'deleted_at', 'remote_id', 'language_code', 'link_text',
            'phishing_domain_tag', 'link_subdomain', 'link_subdirectory'
        ],
        'description' => 'Document attachment templates'
    ],
    'pm_landing_template' => [
        'table' => 'global.pm_landing_template',
        'columns' => [
            'id', 'template_name', 'html', 'is_active', 'description',
            'smallimage', 'deleted_at', 'created_at', 'updated_at',
            'language_code', 'scenario_type_id'
        ],
        'description' => 'Phishing landing page templates'
    ],
    'pm_phishing_domain' => [
        'table' => 'global.pm_phishing_domain',
        'columns' => [
            'company_id', 'tag', 'is_hidden', 'domain', 'created_at',
            'updated_at', 'available_for_https'
        ],
        'description' => 'Phishing domains configuration'
    ]
];

// Handle GET request - list tables or fetch table data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // If no table specified, return list of available tables
    if (!isset($_GET['table'])) {
        $tableList = [];
        foreach ($allowedTables as $key => $info) {
            $tableList[] = [
                'key' => $key,
                'table' => $info['table'],
                'description' => $info['description'],
                'columns' => $info['columns']
            ];
        }

        sendJSON([
            'success' => true,
            'tables' => $tableList
        ]);
    }

    $tableName = $_GET['table'];

    // Validate table name
    if (!isset($allowedTables[$tableName])) {
        sendJSON([
            'success' => false,
            'error' => 'Invalid table name',
            'allowed_tables' => array_keys($allowedTables)
        ], 400);
    }

    $tableConfig = $allowedTables[$tableName];
    $fullTableName = $tableConfig['table'];

    // Pagination parameters
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Optional filter for active only
    $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === 'true';

    // Optional search term
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    try {
        // Build query
        $whereConditions = [];
        $params = [];

        // Filter by active status if applicable and requested
        if ($activeOnly && in_array('is_active', $tableConfig['columns'])) {
            $whereConditions[] = 'is_active = true';
        }

        // Filter out deleted records if deleted_at column exists
        if (in_array('deleted_at', $tableConfig['columns'])) {
            $whereConditions[] = 'deleted_at IS NULL';
        }

        // Search functionality
        if ($search !== '') {
            $searchConditions = [];
            $searchableColumns = ['template_name', 'name', 'subject', 'description', 'domain', 'tag', 'from_address'];
            foreach ($searchableColumns as $col) {
                if (in_array($col, $tableConfig['columns'])) {
                    $searchConditions[] = "LOWER($col) LIKE LOWER(:search)";
                }
            }
            if (!empty($searchConditions)) {
                $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
                $params[':search'] = '%' . $search . '%';
            }
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM $fullTableName $whereClause";
        $countResult = $db->fetchOne($countSql, $params);
        $totalCount = $countResult['total'];

        // Build column list (exclude large text fields from list view if requested)
        $excludeLargeFields = isset($_GET['exclude_large']) && $_GET['exclude_large'] === 'true';
        $largeFields = ['html', 'body', 'preview_html'];

        $columns = $tableConfig['columns'];
        if ($excludeLargeFields) {
            $columns = array_filter($columns, function($col) use ($largeFields) {
                return !in_array($col, $largeFields);
            });
        }
        $columnList = implode(', ', $columns);

        // Determine order column
        $orderColumn = in_array('created_at', $tableConfig['columns']) ? 'created_at' : $tableConfig['columns'][0];
        $orderDir = 'DESC';

        // Allow custom ordering
        if (isset($_GET['order_by']) && in_array($_GET['order_by'], $tableConfig['columns'])) {
            $orderColumn = $_GET['order_by'];
        }
        if (isset($_GET['order_dir']) && in_array(strtoupper($_GET['order_dir']), ['ASC', 'DESC'])) {
            $orderDir = strtoupper($_GET['order_dir']);
        }

        // Fetch data
        $sql = "SELECT $columnList FROM $fullTableName $whereClause ORDER BY $orderColumn $orderDir LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $rows = $db->fetchAll($sql, $params);

        sendJSON([
            'success' => true,
            'table' => $tableName,
            'description' => $tableConfig['description'],
            'columns' => array_values($columns),
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'rows' => $rows
        ]);

    } catch (Exception $e) {
        error_log("Legacy tables API error: " . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to fetch table data',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Database error'
        ], 500);
    }

} else {
    sendJSON([
        'success' => false,
        'error' => 'Method not allowed',
        'allowed_methods' => ['GET']
    ], 405);
}
