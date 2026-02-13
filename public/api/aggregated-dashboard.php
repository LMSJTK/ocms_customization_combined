<?php
/**
 * Aggregated Dashboard API
 * Returns company-wide competency metrics and trends
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

// Get filter parameters
$dateRange = $_GET['range'] ?? '365'; // days
$trendFilter = $_GET['trend'] ?? 'all'; // all, declining, improving
$aggregation = $_GET['aggregation'] ?? 'monthly'; // daily, weekly, monthly

try {
    // Calculate date threshold in PHP (database-agnostic)
    $dateThreshold = null;
    if (is_numeric($dateRange)) {
        $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$dateRange} days"));
    }

    // Build the query - fetch all tracking data with content tags
    $scoreQuery = "
        SELECT
            t.id as training_id,
            t.name as campaign_name,
            t.training_type,
            t.status as training_status,
            tt.id as tracking_id,
            tt.recipient_email_address,
            tt.status,
            tt.training_sent_at,
            tt.training_reported_at,
            tt.url_clicked_at,
            tt.training_completed_at,
            tt.follow_on_completed_at,
            tt.training_score,
            tt.follow_on_score,
            c.tags as content_tags
        FROM training_tracking tt
        JOIN training t ON tt.training_id = t.id
        LEFT JOIN content c ON t.training_content_id = c.id
        ORDER BY tt.training_sent_at DESC
    ";

    $allResults = $db->fetchAll($scoreQuery);

    // Filter by date in PHP
    $results = [];
    $previousPeriodResults = [];
    $previousThreshold = null;

    if ($dateThreshold) {
        $previousThreshold = date('Y-m-d H:i:s', strtotime("-" . ((int)$dateRange * 2) . " days"));
    }

    foreach ($allResults as $row) {
        $sentAt = $row['training_sent_at'];

        if (!$dateThreshold || ($sentAt && $sentAt >= $dateThreshold)) {
            $results[] = $row;
        }

        // Also collect previous period data for comparison
        if ($previousThreshold && $sentAt && $sentAt >= $previousThreshold && $sentAt < $dateThreshold) {
            $previousPeriodResults[] = $row;
        }
    }

    // Initialize aggregation structures
    $totalRecipients = count($results);
    $susceptibleCount = 0;
    $reportedCount = 0;
    $completedCount = 0;
    $totalCompetencySum = 0;
    $scoredRecords = 0;

    // For time-based trends (supports daily, weekly, monthly)
    $timeScores = [];
    $topicScores = [];

    // Risk breakdown
    $riskBreakdown = [
        'high' => 0,    // 0-50
        'medium' => 0,  // 51-79
        'low' => 0      // 80-100
    ];

    // Action breakdown
    $actionBreakdown = [
        'reported' => 0,
        'clicked' => 0,
        'ignored' => 0,
        'pending' => 0
    ];

    $sevenDaysAgo = strtotime('-7 days');

    // Helper function to get time bucket based on aggregation level
    $getTimeBucket = function($timestamp) use ($aggregation) {
        if (!$timestamp) return null;
        $ts = strtotime($timestamp);
        switch ($aggregation) {
            case 'daily':
                return date('Y-m-d', $ts);
            case 'weekly':
                // Get the Monday of that week
                return date('Y-m-d', strtotime('monday this week', $ts));
            case 'monthly':
            default:
                return date('Y-m', $ts);
        }
    };

    // Helper function to format time bucket label
    $formatTimeBucket = function($bucket) use ($aggregation) {
        switch ($aggregation) {
            case 'daily':
                return date('M j', strtotime($bucket));
            case 'weekly':
                return 'Week of ' . date('M j', strtotime($bucket));
            case 'monthly':
            default:
                return date('M Y', strtotime($bucket . '-01'));
        }
    };

    // Helper function to parse tags from comma-separated string
    $parseTags = function($tagsString) {
        if (empty($tagsString)) return [];
        $tags = array_map('trim', explode(',', $tagsString));
        return array_filter($tags, fn($t) => !empty($t));
    };

    foreach ($results as $row) {
        // Count basic metrics
        if ($row['url_clicked_at']) {
            $susceptibleCount++;
            $actionBreakdown['clicked']++;
        }
        if ($row['training_reported_at']) {
            $reportedCount++;
            $actionBreakdown['reported']++;
        }
        if ($row['training_completed_at']) {
            $completedCount++;
        }

        // Calculate base phishing score
        $basePhishing = null;
        if ($row['training_reported_at']) {
            $basePhishing = 100;
        } elseif ($row['url_clicked_at']) {
            $basePhishing = 0;
        } elseif ($row['training_sent_at'] && strtotime($row['training_sent_at']) < $sevenDaysAgo) {
            // Ignored - no click, no report, sent > 7 days ago
            $basePhishing = 75;
        }

        if ($basePhishing !== null) {
            // Response time bonus (only for reported)
            $responseBonus = 0;
            if ($row['training_reported_at'] && $row['training_sent_at']) {
                $sentTime = strtotime($row['training_sent_at']);
                $reportedTime = strtotime($row['training_reported_at']);
                $hours = ($reportedTime - $sentTime) / 3600;

                if ($hours < 1) $responseBonus = 20;
                elseif ($hours <= 24) $responseBonus = 15;
                elseif ($hours <= 72) $responseBonus = 10;
                else $responseBonus = 5;
            }

            // Get training scores
            $immediateScore = (float)($row['training_score'] ?? 0);
            $followOnScore = (float)($row['follow_on_score'] ?? 0);

            // Calculate total score
            $competencyScore =
                ($basePhishing * 0.50) +
                ($immediateScore * 0.25) +
                ($followOnScore * 0.25) +
                $responseBonus;

            // Cap at 120 (100 + max bonus of 20)
            $competencyScore = min(120, $competencyScore);

            $totalCompetencySum += $competencyScore;
            $scoredRecords++;

            // Risk categorization
            if ($competencyScore >= 80) {
                $riskBreakdown['low']++;
            } elseif ($competencyScore >= 51) {
                $riskBreakdown['medium']++;
            } else {
                $riskBreakdown['high']++;
            }

            // Track by time bucket for trends
            $timeBucket = $getTimeBucket($row['training_sent_at']);
            if ($timeBucket) {
                if (!isset($timeScores[$timeBucket])) {
                    $timeScores[$timeBucket] = ['sum' => 0, 'count' => 0];
                }
                $timeScores[$timeBucket]['sum'] += $competencyScore;
                $timeScores[$timeBucket]['count']++;
            }

            // Track by content tags (topics)
            $tags = $parseTags($row['content_tags']);
            if (empty($tags)) {
                $tags = ['Untagged'];
            }

            foreach ($tags as $tag) {
                if (!isset($topicScores[$tag])) {
                    $topicScores[$tag] = [
                        'sum' => 0,
                        'count' => 0,
                        'timeBuckets' => []
                    ];
                }
                $topicScores[$tag]['sum'] += $competencyScore;
                $topicScores[$tag]['count']++;

                // Track topic by time bucket
                if ($timeBucket) {
                    if (!isset($topicScores[$tag]['timeBuckets'][$timeBucket])) {
                        $topicScores[$tag]['timeBuckets'][$timeBucket] = ['sum' => 0, 'count' => 0];
                    }
                    $topicScores[$tag]['timeBuckets'][$timeBucket]['sum'] += $competencyScore;
                    $topicScores[$tag]['timeBuckets'][$timeBucket]['count']++;
                }
            }
        } else {
            // Pending (not enough time to determine)
            $actionBreakdown['pending']++;
        }

        // Count ignored separately (not clicked, not reported, sent > 7 days ago)
        if (!$row['url_clicked_at'] && !$row['training_reported_at'] &&
            $row['training_sent_at'] && strtotime($row['training_sent_at']) < $sevenDaysAgo) {
            $actionBreakdown['ignored']++;
        }
    }

    // Calculate averages
    $avgCompetency = $scoredRecords > 0 ? round($totalCompetencySum / $scoredRecords, 1) : 0;
    $susceptibleRate = $totalRecipients > 0 ? round(($susceptibleCount / $totalRecipients) * 100, 1) : 0;
    $reportRate = $totalRecipients > 0 ? round(($reportedCount / $totalRecipients) * 100, 1) : 0;

    // Build time-based trend data
    ksort($timeScores);
    $trendData = [];
    foreach ($timeScores as $bucket => $data) {
        $avg = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;
        $trendData[] = [
            'bucket' => $bucket,
            'label' => $formatTimeBucket($bucket),
            'average_score' => $avg,
            'count' => $data['count']
        ];
    }

    // Determine overall trend
    $overallTrend = 'stable';
    if (count($trendData) >= 2) {
        $recent = array_slice($trendData, -3);
        $first = $recent[0]['average_score'] ?? 0;
        $last = end($recent)['average_score'] ?? 0;
        if ($last < $first - 5) $overallTrend = 'declining';
        elseif ($last > $first + 5) $overallTrend = 'improving';
    }

    // Build topic data with trends
    $topicsData = [];
    foreach ($topicScores as $topic => $data) {
        $topicAvg = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;

        // Calculate topic trend by time bucket
        ksort($data['timeBuckets']);
        $topicTimeSeries = [];
        foreach ($data['timeBuckets'] as $bucket => $bdata) {
            $topicTimeSeries[] = [
                'bucket' => $bucket,
                'label' => $formatTimeBucket($bucket),
                'average_score' => $bdata['count'] > 0 ? round($bdata['sum'] / $bdata['count'], 1) : 0
            ];
        }

        // Determine topic trend
        $topicTrend = 'stable';
        if (count($topicTimeSeries) >= 2) {
            $first = $topicTimeSeries[0]['average_score'];
            $last = end($topicTimeSeries)['average_score'];
            if ($last < $first - 5) $topicTrend = 'declining';
            elseif ($last > $first + 5) $topicTrend = 'improving';
        }

        // Apply trend filter
        if ($trendFilter !== 'all' && $topicTrend !== $trendFilter) {
            continue;
        }

        $topicsData[] = [
            'name' => $topic,
            'average_score' => $topicAvg,
            'simulation_count' => $data['count'],
            'trend' => $topicTrend,
            'time_series' => $topicTimeSeries
        ];
    }

    // Sort topics by count
    usort($topicsData, fn($a, $b) => $b['simulation_count'] - $a['simulation_count']);

    // Calculate previous period metrics for comparison
    $prevTotal = count($previousPeriodResults);
    $prevSusceptible = 0;
    $prevReported = 0;

    foreach ($previousPeriodResults as $row) {
        if ($row['url_clicked_at']) $prevSusceptible++;
        if ($row['training_reported_at']) $prevReported++;
    }

    $prevSusceptibleRate = $prevTotal > 0 ? round(($prevSusceptible / $prevTotal) * 100, 1) : 0;
    $prevReportRate = $prevTotal > 0 ? round(($prevReported / $prevTotal) * 100, 1) : 0;

    // Build response
    sendJSON([
        'success' => true,
        'summary' => [
            'total_recipients' => $totalRecipients,
            'total_recipients_change' => $totalRecipients - $prevTotal,
            'susceptible_rate' => $susceptibleRate,
            'susceptible_rate_change' => round($susceptibleRate - $prevSusceptibleRate, 1),
            'report_rate' => $reportRate,
            'report_rate_change' => round($reportRate - $prevReportRate, 1),
            'average_competency' => $avgCompetency,
            'average_competency_change' => 0,
            'overall_trend' => $overallTrend
        ],
        'risk_breakdown' => $riskBreakdown,
        'action_breakdown' => $actionBreakdown,
        'competency_trend' => $trendData,
        'topics' => $topicsData,
        'filters' => [
            'date_range' => $dateRange,
            'trend_filter' => $trendFilter,
            'aggregation' => $aggregation
        ],
        'debug' => [
            'total_records_fetched' => count($allResults),
            'records_in_range' => count($results),
            'date_threshold' => $dateThreshold
        ]
    ]);

} catch (Exception $e) {
    error_log("Aggregated Dashboard Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to load aggregated dashboard',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
