<?php
/**
 * List Trainings API
 * Returns a list of all trainings with summary metrics
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    // Optional filters
    $statusFilter = $_GET['status'] ?? null;
    $companyFilter = $_GET['company_id'] ?? null;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    $params = [];
    $whereConditions = [];

    if ($statusFilter) {
        $whereConditions[] = 't.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($companyFilter) {
        $whereConditions[] = 't.company_id = :company_id';
        $params[':company_id'] = $companyFilter;
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM training t $whereClause";
    $countResult = $db->fetchOne($countSql, $params);
    $totalCount = $countResult['total'];

    // Get trainings with aggregated metrics
    $sql = "
        SELECT
            t.id,
            t.name,
            t.description,
            t.company_id,
            t.training_type,
            t.status,
            t.follow_on,
            t.scheduled_at,
            t.ends_at,
            t.created_at,
            t.updated_at,
            -- Aggregated metrics from training_tracking
            COUNT(tt.id) as invited_count,
            COUNT(tt.url_clicked_at) as susceptible_count,
            COUNT(tt.training_completed_at) as completed_count,
            COUNT(tt.follow_on_completed_at) as follow_on_count,
            COUNT(tt.training_reported_at) as reported_count
        FROM training t
        LEFT JOIN training_tracking tt ON t.id = tt.training_id
        $whereClause
        GROUP BY t.id, t.name, t.description, t.company_id, t.training_type,
                 t.status, t.follow_on, t.scheduled_at, t.ends_at, t.created_at, t.updated_at
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    $trainings = $db->fetchAll($sql, $params);

    // Calculate completion percentage for each training
    foreach ($trainings as &$training) {
        $invited = (int)$training['invited_count'];
        $completed = (int)$training['completed_count'];
        $training['completion_percentage'] = $invited > 0 ? round(($completed / $invited) * 100, 1) : 0;
    }

    sendJSON([
        'success' => true,
        'total' => (int)$totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'trainings' => $trainings
    ]);

} catch (Exception $e) {
    error_log("List Trainings Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to list trainings',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
