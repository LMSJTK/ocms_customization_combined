<?php
/**
 * Training Dashboard API
 * Returns detailed metrics and time-series data for a specific training
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

$trainingId = $_GET['training_id'] ?? null;

if (!$trainingId) {
    sendJSON(['error' => 'Missing required parameter: training_id'], 400);
}

try {
    // Get training details
    $training = $db->fetchOne("
        SELECT
            t.*,
            -- Get content titles
            ec.title as email_content_title,
            lc.title as landing_content_title,
            tc.title as training_content_title,
            fc.title as follow_on_content_title
        FROM training t
        LEFT JOIN content ec ON t.training_email_content_id = ec.id
        LEFT JOIN content lc ON t.landing_content_id = lc.id
        LEFT JOIN content tc ON t.training_content_id = tc.id
        LEFT JOIN content fc ON t.follow_on_content_id = fc.id
        WHERE t.id = :id
    ", [':id' => $trainingId]);

    if (!$training) {
        sendJSON(['error' => 'Training not found'], 404);
    }

    // Get summary metrics
    $metrics = $db->fetchOne("
        SELECT
            COUNT(*) as invited,
            COUNT(training_sent_at) as sent,
            COUNT(training_viewed_at) as opened,
            COUNT(url_clicked_at) as susceptible,
            COUNT(data_entered_at) as data_entered,
            COUNT(training_reported_at) as reported,
            COUNT(training_completed_at) as immediate_education,
            COUNT(follow_on_sent_at) as follow_on_sent,
            COUNT(follow_on_viewed_at) as follow_on_viewed,
            COUNT(follow_on_completed_at) as smart_reinforcement,
            AVG(training_score) as avg_training_score,
            AVG(follow_on_score) as avg_follow_on_score
        FROM training_tracking
        WHERE training_id = :id
    ", [':id' => $trainingId]);

    // Calculate completion percentage
    $invited = (int)$metrics['invited'];
    $completed = (int)$metrics['immediate_education'];
    $completionPercentage = $invited > 0 ? round(($completed / $invited) * 100, 1) : 0;

    // Get completion over time (daily aggregation)
    $completionOverTime = $db->fetchAll("
        SELECT
            DATE(training_completed_at) as date,
            COUNT(*) as completed_count
        FROM training_tracking
        WHERE training_id = :id
        AND training_completed_at IS NOT NULL
        GROUP BY DATE(training_completed_at)
        ORDER BY date ASC
    ", [':id' => $trainingId]);

    // Get susceptible over time (daily aggregation)
    $susceptibleOverTime = $db->fetchAll("
        SELECT
            DATE(url_clicked_at) as date,
            COUNT(*) as clicked_count
        FROM training_tracking
        WHERE training_id = :id
        AND url_clicked_at IS NOT NULL
        GROUP BY DATE(url_clicked_at)
        ORDER BY date ASC
    ", [':id' => $trainingId]);

    // Get status breakdown - derived from timestamps instead of status field
    // This ensures accurate counts even when status only reflects "last action"
    $statusBreakdown = $db->fetchAll("
        SELECT
            CASE
                WHEN follow_on_completed_at IS NOT NULL THEN 'FOLLOW_ON_COMPLETED'
                WHEN follow_on_viewed_at IS NOT NULL THEN 'FOLLOW_ON_VIEWED'
                WHEN follow_on_sent_at IS NOT NULL THEN 'FOLLOW_ON_SENT'
                WHEN training_completed_at IS NOT NULL THEN 'TRAINING_COMPLETED'
                WHEN training_reported_at IS NOT NULL THEN 'TRAINING_REPORTED'
                WHEN url_clicked_at IS NOT NULL THEN 'TRAINING_CLICKED'
                WHEN training_viewed_at IS NOT NULL THEN 'TRAINING_OPENED'
                WHEN training_sent_at IS NOT NULL THEN 'TRAINING_SENT'
                ELSE 'TRAINING_PENDING'
            END as status,
            COUNT(*) as count
        FROM training_tracking
        WHERE training_id = :id
        GROUP BY 1
        ORDER BY count DESC
    ", [':id' => $trainingId]);

    // Get recent activity (last 20 events) - derive status from timestamps
    $recentActivity = $db->fetchAll("
        SELECT
            id,
            recipient_id,
            recipient_email_address,
            CASE
                WHEN follow_on_completed_at IS NOT NULL THEN 'FOLLOW_ON_COMPLETED'
                WHEN follow_on_viewed_at IS NOT NULL THEN 'FOLLOW_ON_VIEWED'
                WHEN follow_on_sent_at IS NOT NULL THEN 'FOLLOW_ON_SENT'
                WHEN training_completed_at IS NOT NULL THEN 'TRAINING_COMPLETED'
                WHEN training_reported_at IS NOT NULL THEN 'TRAINING_REPORTED'
                WHEN url_clicked_at IS NOT NULL THEN 'TRAINING_CLICKED'
                WHEN training_viewed_at IS NOT NULL THEN 'TRAINING_OPENED'
                WHEN training_sent_at IS NOT NULL THEN 'TRAINING_SENT'
                ELSE 'TRAINING_PENDING'
            END as status,
            last_action_at,
            url_clicked_at,
            training_completed_at,
            follow_on_completed_at,
            training_score,
            follow_on_score
        FROM training_tracking
        WHERE training_id = :id
        ORDER BY last_action_at DESC
        LIMIT 20
    ", [':id' => $trainingId]);

    // Build funnel data
    $funnel = [
        ['stage' => 'Invited', 'count' => (int)$metrics['invited']],
        ['stage' => 'Sent', 'count' => (int)$metrics['sent']],
        ['stage' => 'Opened', 'count' => (int)$metrics['opened']],
        ['stage' => 'Clicked (Susceptible)', 'count' => (int)$metrics['susceptible']],
        ['stage' => 'Immediate Education', 'count' => (int)$metrics['immediate_education']],
        ['stage' => 'Smart Reinforcement', 'count' => (int)$metrics['smart_reinforcement']],
    ];

    sendJSON([
        'success' => true,
        'training' => [
            'id' => $training['id'],
            'name' => $training['name'],
            'description' => $training['description'],
            'status' => $training['status'],
            'training_type' => $training['training_type'],
            'follow_on_enabled' => (bool)$training['follow_on'],
            'scheduled_at' => $training['scheduled_at'],
            'ends_at' => $training['ends_at'],
            'created_at' => $training['created_at'],
            'content' => [
                'email' => $training['email_content_title'],
                'landing' => $training['landing_content_title'],
                'training' => $training['training_content_title'],
                'follow_on' => $training['follow_on_content_title']
            ]
        ],
        'metrics' => [
            'invited' => (int)$metrics['invited'],
            'sent' => (int)$metrics['sent'],
            'opened' => (int)$metrics['opened'],
            'susceptible' => (int)$metrics['susceptible'],
            'data_entered' => (int)$metrics['data_entered'],
            'reported' => (int)$metrics['reported'],
            'immediate_education' => (int)$metrics['immediate_education'],
            'follow_on_sent' => (int)$metrics['follow_on_sent'],
            'follow_on_viewed' => (int)$metrics['follow_on_viewed'],
            'smart_reinforcement' => (int)$metrics['smart_reinforcement'],
            'completion_percentage' => $completionPercentage,
            'avg_training_score' => $metrics['avg_training_score'] ? round((float)$metrics['avg_training_score'], 1) : null,
            'avg_follow_on_score' => $metrics['avg_follow_on_score'] ? round((float)$metrics['avg_follow_on_score'], 1) : null
        ],
        'charts' => [
            'completion_over_time' => $completionOverTime,
            'susceptible_over_time' => $susceptibleOverTime,
            'status_breakdown' => $statusBreakdown,
            'funnel' => $funnel
        ],
        'recent_activity' => $recentActivity
    ]);

} catch (Exception $e) {
    error_log("Training Dashboard Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to load training dashboard',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
