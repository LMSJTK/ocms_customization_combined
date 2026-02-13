/**
 * Demo data for testing dashboards
 * This provides realistic sample data for both aggregated and individual training dashboards
 *
 * DATA SOURCE DOCUMENTATION:
 * - Total Recipients: COUNT(*) from training_tracking
 * - Susceptible Rate: COUNT(url_clicked_at) / COUNT(*) * 100
 * - Report Rate: COUNT(training_reported_at) / COUNT(*) * 100
 * - Average Competency: Calculated score based on:
 *     - Phishing Score (50%): Reported=100, Ignored=75, Clicked=0
 *     - Immediate Training Score (25%): training_score field
 *     - Follow-on Training Score (25%): follow_on_score field
 *     - Response Time Bonus: +5 to +20 for quick reporting
 */

// Data source explanations for tooltips
const DATA_SOURCES = {
    total_recipients: {
        title: 'Total Recipients',
        source: 'training_tracking table',
        calculation: 'COUNT(*) FROM training_tracking',
        description: 'Total number of people who were included in phishing simulations during the selected time period.'
    },
    susceptible_rate: {
        title: 'Susceptible Rate',
        source: 'training_tracking.url_clicked_at',
        calculation: 'COUNT(url_clicked_at) / COUNT(*) × 100',
        description: 'Percentage of recipients who clicked on the simulated phishing link. Lower is better.'
    },
    report_rate: {
        title: 'Report Rate',
        source: 'training_tracking.training_reported_at',
        calculation: 'COUNT(training_reported_at) / COUNT(*) × 100',
        description: 'Percentage of recipients who correctly reported the phishing email. Higher is better.'
    },
    average_competency: {
        title: 'Average Competency Score',
        source: 'Calculated from multiple fields',
        calculation: '(Phishing×0.50) + (ImmediateScore×0.25) + (FollowOnScore×0.25) + ResponseBonus',
        description: 'Composite score measuring overall security awareness. Phishing behavior (50%), immediate training score (25%), follow-on training score (25%), plus bonus for quick reporting.',
        breakdown: [
            'Phishing Score (50%): Reported=100pts, Ignored=75pts, Clicked=0pts',
            'Immediate Training (25%): training_score field (0-100)',
            'Follow-on Training (25%): follow_on_score field (0-100)',
            'Response Bonus: <1hr=+20, <24hr=+15, <72hr=+10, >72hr=+5'
        ]
    },
    risk_breakdown: {
        title: 'Risk Breakdown',
        source: 'Derived from competency scores',
        calculation: 'Categorize each recipient by their competency score',
        description: 'Distribution of recipients by risk level based on their competency scores.',
        breakdown: [
            'Low Risk: Competency score 80-100',
            'Medium Risk: Competency score 51-79',
            'High Risk: Competency score 0-50'
        ]
    },
    action_breakdown: {
        title: 'Action Breakdown',
        source: 'training_tracking status fields',
        calculation: 'COUNT by action type',
        description: 'How recipients responded to the phishing simulation.',
        breakdown: [
            'Reported: training_reported_at IS NOT NULL',
            'Clicked: url_clicked_at IS NOT NULL',
            'Ignored: No click, no report, sent >7 days ago',
            'Pending: Recently sent, awaiting action'
        ]
    },
    // Individual training metrics
    invited: {
        title: 'Invited Count',
        source: 'training_tracking table',
        calculation: 'COUNT(*) FROM training_tracking WHERE training_id = ?',
        description: 'Total number of recipients included in this specific training campaign.'
    },
    susceptible: {
        title: 'Susceptible Count',
        source: 'training_tracking.url_clicked_at',
        calculation: 'COUNT(url_clicked_at) FROM training_tracking WHERE training_id = ?',
        description: 'Number of recipients who clicked on the simulated phishing link in this training.'
    },
    immediate_education: {
        title: 'Immediate Education',
        source: 'training_tracking.training_completed_at',
        calculation: 'COUNT(training_completed_at) FROM training_tracking WHERE training_id = ?',
        description: 'Recipients who completed the immediate training after clicking the phishing link.'
    },
    smart_reinforcement: {
        title: 'Smart Reinforcement',
        source: 'training_tracking.follow_on_completed_at',
        calculation: 'COUNT(follow_on_completed_at) FROM training_tracking WHERE training_id = ?',
        description: 'Recipients who completed the follow-on reinforcement training module.'
    },
    training_funnel: {
        title: 'Training Funnel',
        source: 'training_tracking timestamp fields',
        calculation: 'Sequential COUNT of each stage',
        description: 'Shows dropoff at each stage of the training process.',
        breakdown: [
            'Invited: COUNT(*) - all recipients',
            'Sent: COUNT(email_sent_at) - emails delivered',
            'Opened: COUNT(email_opened_at) - emails viewed',
            'Clicked: COUNT(url_clicked_at) - link clicks',
            'Immediate Education: COUNT(training_completed_at)',
            'Smart Reinforcement: COUNT(follow_on_completed_at)'
        ]
    },
    completion_over_time: {
        title: 'Completion Over Time',
        source: 'training_tracking.training_completed_at',
        calculation: 'COUNT(*) GROUP BY DATE(training_completed_at)',
        description: 'Daily count of training completions, shown cumulatively.'
    },
    susceptible_over_time: {
        title: 'Susceptibility Over Time',
        source: 'training_tracking.url_clicked_at',
        calculation: 'COUNT(*) GROUP BY DATE(url_clicked_at)',
        description: 'Daily count of phishing clicks, shown cumulatively.'
    },
    status_breakdown: {
        title: 'Status Breakdown',
        source: 'Derived from training_tracking timestamp fields',
        calculation: 'COUNT(*) GROUP BY derived_status (CASE WHEN ...)',
        description: 'Distribution of recipients by their furthest progression. Status is derived from timestamps rather than a stored field to accurately reflect each stage.',
        breakdown: [
            'FOLLOW_ON_COMPLETED: follow_on_completed_at IS NOT NULL',
            'TRAINING_COMPLETED: training_completed_at IS NOT NULL',
            'TRAINING_REPORTED: training_reported_at IS NOT NULL',
            'TRAINING_CLICKED: url_clicked_at IS NOT NULL',
            'TRAINING_SENT: training_sent_at IS NOT NULL',
            'TRAINING_PENDING: No timestamps set'
        ]
    },
    competency_trend: {
        title: 'Competency Trends',
        source: 'training_tracking grouped by date',
        calculation: 'AVG(competency_score) GROUP BY date bucket',
        description: 'Average competency score over time, grouped by the selected aggregation level (daily/weekly/monthly).'
    },
    topics: {
        title: 'Topic Performance',
        source: 'content.tags joined with training_tracking',
        calculation: 'AVG(competency_score) GROUP BY content tag',
        description: 'Competency scores broken down by training topic/content tag. Topics come from the tags field on the content table associated with each training.'
    }
};

// Base topic definitions with characteristics
const TOPIC_DEFINITIONS = [
    { name: 'Brand Impersonation', baseScore: 68, volatility: 0.15, trend: -0.3 },
    { name: 'Compliance', baseScore: 75, volatility: 0.10, trend: 0.4 },
    { name: 'Financial Transactions', baseScore: 52, volatility: 0.20, trend: -0.5 },
    { name: 'Emotions', baseScore: 71, volatility: 0.12, trend: 0.1 },
    { name: 'General Phishing', baseScore: 65, volatility: 0.14, trend: 0.3 },
    { name: 'Mobile', baseScore: 58, volatility: 0.18, trend: -0.2 },
    { name: 'Cloud Security', baseScore: 72, volatility: 0.11, trend: 0.0 }
];

/**
 * Generate aggregated dashboard data with support for filters
 */
function generateAggregatedData(options = {}) {
    const {
        aggregation = 'monthly',
        dateRange = 365,
        trendFilter = 'all'
    } = options;

    // Seed random for consistency within same parameters
    const seed = dateRange * 1000 + (aggregation === 'daily' ? 1 : aggregation === 'weekly' ? 2 : 3);
    const seededRandom = createSeededRandom(seed);

    // Generate time buckets based on aggregation and date range
    const buckets = generateTimeBuckets(aggregation, dateRange);

    // Scale recipients based on date range
    const baseRecipients = 1554;
    const rangeMultiplier = dateRange / 365;
    const totalRecipients = Math.round(baseRecipients * rangeMultiplier);

    // Build topic time series
    let topicsData = TOPIC_DEFINITIONS.map((topic, idx) => {
        const timeSeries = buckets.map((bucket, i) => {
            // Calculate score with trend and volatility
            const progress = i / Math.max(buckets.length - 1, 1);
            const trendEffect = topic.trend * progress * 15;
            const volatility = (seededRandom() - 0.5) * topic.volatility * 30;
            const score = Math.max(0, Math.min(100, topic.baseScore + trendEffect + volatility));

            return {
                bucket: bucket.value,
                label: bucket.label,
                average_score: Math.round(score * 10) / 10
            };
        });

        // Calculate actual trend from time series
        let calculatedTrend = 'stable';
        if (timeSeries.length >= 2) {
            const first = timeSeries[0].average_score;
            const last = timeSeries[timeSeries.length - 1].average_score;
            if (last < first - 5) calculatedTrend = 'declining';
            else if (last > first + 5) calculatedTrend = 'improving';
        }

        const avgScore = timeSeries.reduce((sum, t) => sum + t.average_score, 0) / timeSeries.length;

        return {
            name: topic.name,
            average_score: Math.round(avgScore * 10) / 10,
            simulation_count: Math.floor(seededRandom() * 300 * rangeMultiplier) + Math.floor(50 * rangeMultiplier),
            trend: calculatedTrend,
            time_series: timeSeries
        };
    });

    // Apply trend filter
    if (trendFilter !== 'all') {
        topicsData = topicsData.filter(t => t.trend === trendFilter);
    }

    // Sort by simulation count
    topicsData.sort((a, b) => b.simulation_count - a.simulation_count);

    // Calculate overall metrics (scaled by date range)
    const susceptibleRate = 28 - (dateRange < 30 ? 5 : dateRange < 90 ? 2 : 0) + (seededRandom() - 0.5) * 6;
    const reportRate = 14 + (dateRange < 30 ? 3 : dateRange < 90 ? 1 : 0) + (seededRandom() - 0.5) * 4;
    const avgCompetency = 68 - (dateRange < 30 ? 2 : 0) + (seededRandom() - 0.5) * 8;

    const susceptibleCount = Math.round(totalRecipients * susceptibleRate / 100);
    const reportedCount = Math.round(totalRecipients * reportRate / 100);

    // Determine overall trend
    let overallTrend = 'stable';
    const decliningCount = topicsData.filter(t => t.trend === 'declining').length;
    const improvingCount = topicsData.filter(t => t.trend === 'improving').length;
    if (decliningCount > improvingCount + 1) overallTrend = 'declining';
    else if (improvingCount > decliningCount + 1) overallTrend = 'improving';

    // Generate competency trend
    const competencyTrend = buckets.map((bucket, i) => {
        const progress = i / Math.max(buckets.length - 1, 1);
        const trendEffect = (overallTrend === 'declining' ? -1 : overallTrend === 'improving' ? 1 : 0) * progress * 8;
        const variance = (seededRandom() - 0.5) * 6;
        const score = Math.max(0, Math.min(100, avgCompetency + trendEffect + variance));

        return {
            bucket: bucket.value,
            label: bucket.label,
            average_score: Math.round(score * 10) / 10,
            count: Math.floor(seededRandom() * 100 * rangeMultiplier) + Math.floor(30 * rangeMultiplier)
        };
    });

    // Risk breakdown
    const riskBreakdown = {
        high: Math.floor(totalRecipients * (0.15 + (seededRandom() - 0.5) * 0.05)),
        medium: Math.floor(totalRecipients * (0.35 + (seededRandom() - 0.5) * 0.08)),
        low: 0
    };
    riskBreakdown.low = totalRecipients - riskBreakdown.high - riskBreakdown.medium;

    // Action breakdown
    const actionBreakdown = {
        reported: reportedCount,
        clicked: susceptibleCount,
        ignored: Math.floor(totalRecipients * 0.45),
        pending: Math.floor(totalRecipients * 0.13)
    };

    // Calculate changes from previous period
    const prevRangeMultiplier = rangeMultiplier * 0.8; // Simulate less data in previous period
    const prevTotal = Math.round(baseRecipients * prevRangeMultiplier);

    return {
        success: true,
        summary: {
            total_recipients: totalRecipients,
            total_recipients_change: totalRecipients - prevTotal,
            susceptible_rate: Math.round(susceptibleRate * 10) / 10,
            susceptible_rate_change: Math.round((seededRandom() - 0.3) * 10) / 10,
            report_rate: Math.round(reportRate * 10) / 10,
            report_rate_change: Math.round((seededRandom() - 0.2) * 10) / 10,
            average_competency: Math.round(avgCompetency * 10) / 10,
            average_competency_change: Math.round((seededRandom() - 0.5) * 6) / 10,
            overall_trend: overallTrend
        },
        risk_breakdown: riskBreakdown,
        action_breakdown: actionBreakdown,
        competency_trend: competencyTrend,
        topics: topicsData,
        filters: {
            date_range: dateRange.toString(),
            trend_filter: trendFilter,
            aggregation: aggregation
        },
        _meta: {
            data_sources: DATA_SOURCES
        }
    };
}

/**
 * Create a seeded random number generator for consistent results
 */
function createSeededRandom(seed) {
    return function() {
        seed = (seed * 9301 + 49297) % 233280;
        return seed / 233280;
    };
}

/**
 * Generate time buckets based on aggregation level and date range
 */
function generateTimeBuckets(aggregation, dateRange) {
    const buckets = [];
    const now = new Date();

    if (aggregation === 'daily') {
        const days = Math.min(dateRange, 60); // Cap at 60 days for daily view
        for (let i = days - 1; i >= 0; i--) {
            const date = new Date(now);
            date.setDate(date.getDate() - i);
            buckets.push({
                value: date.toISOString().split('T')[0],
                label: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            });
        }
    } else if (aggregation === 'weekly') {
        const weeks = Math.min(Math.ceil(dateRange / 7), 26); // Cap at 26 weeks
        for (let i = weeks - 1; i >= 0; i--) {
            const date = new Date(now);
            date.setDate(date.getDate() - (i * 7));
            const day = date.getDay();
            const diff = date.getDate() - day + (day === 0 ? -6 : 1);
            date.setDate(diff);
            buckets.push({
                value: date.toISOString().split('T')[0],
                label: 'Week of ' + date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            });
        }
    } else {
        const months = Math.min(Math.ceil(dateRange / 30), 12); // Cap at 12 months
        for (let i = months - 1; i >= 0; i--) {
            const date = new Date(now);
            date.setMonth(date.getMonth() - i);
            const yearMonth = date.toISOString().slice(0, 7);
            buckets.push({
                value: yearMonth,
                label: date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
            });
        }
    }

    return buckets;
}

/**
 * Generate individual training data
 */
function generateTrainingData(id, name, status, type) {
    const seed = id.split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);
    const seededRandom = createSeededRandom(seed);

    const invited = Math.floor(seededRandom() * 300) + 100;
    const susceptible = Math.floor(invited * (0.15 + seededRandom() * 0.25));
    const reported = Math.floor(invited * (0.05 + seededRandom() * 0.15));
    const completed = Math.floor(invited * (0.4 + seededRandom() * 0.4));

    // Generate daily completion data for last 30 days
    const completionOverTime = [];
    const susceptibleOverTime = [];
    const now = new Date();

    for (let i = 29; i >= 0; i--) {
        const date = new Date(now);
        date.setDate(date.getDate() - i);
        const dateStr = date.toISOString().split('T')[0];

        if (seededRandom() > 0.3) {
            completionOverTime.push({
                date: dateStr,
                completed_count: Math.floor(seededRandom() * 15) + 1
            });
        }

        if (seededRandom() > 0.5) {
            susceptibleOverTime.push({
                date: dateStr,
                clicked_count: Math.floor(seededRandom() * 8) + 1
            });
        }
    }

    // Status breakdown
    const statusBreakdown = [
        { status: 'TRAINING_COMPLETED', count: completed },
        { status: 'TRAINING_CLICKED', count: susceptible },
        { status: 'TRAINING_SENT', count: Math.floor(invited * 0.2) },
        { status: 'TRAINING_REPORTED', count: reported },
        { status: 'TRAINING_PENDING', count: Math.floor(invited * 0.1) }
    ];

    // Funnel data
    const funnel = [
        { stage: 'Invited', count: invited },
        { stage: 'Sent', count: Math.floor(invited * 0.95) },
        { stage: 'Opened', count: Math.floor(invited * 0.72) },
        { stage: 'Clicked (Susceptible)', count: susceptible },
        { stage: 'Immediate Education', count: completed },
        { stage: 'Smart Reinforcement', count: Math.floor(completed * 0.6) }
    ];

    // Generate sample recipients
    const recentActivity = generateRecentActivity(seededRandom, 20);

    const createdAt = new Date();
    createdAt.setDate(createdAt.getDate() - Math.floor(seededRandom() * 60) - 10);

    return {
        success: true,
        training: {
            id: id,
            name: name,
            description: `Security awareness training simulation for ${type}`,
            status: status,
            training_type: type,
            follow_on_enabled: seededRandom() > 0.3,
            scheduled_at: createdAt.toISOString(),
            ends_at: null,
            created_at: createdAt.toISOString(),
            content: {
                email: `${type} Phishing Email Template`,
                landing: `${type} Landing Page`,
                training: `${type} Training Module`,
                follow_on: seededRandom() > 0.3 ? `${type} Follow-on Training` : null
            }
        },
        metrics: {
            invited: invited,
            sent: Math.floor(invited * 0.95),
            opened: Math.floor(invited * 0.72),
            susceptible: susceptible,
            data_entered: Math.floor(susceptible * 0.3),
            reported: reported,
            immediate_education: completed,
            follow_on_sent: Math.floor(completed * 0.8),
            follow_on_viewed: Math.floor(completed * 0.6),
            smart_reinforcement: Math.floor(completed * 0.5),
            completion_percentage: Math.round((completed / invited) * 100 * 10) / 10,
            avg_training_score: Math.round((70 + seededRandom() * 20) * 10) / 10,
            avg_follow_on_score: Math.round((65 + seededRandom() * 25) * 10) / 10
        },
        charts: {
            completion_over_time: completionOverTime,
            susceptible_over_time: susceptibleOverTime,
            status_breakdown: statusBreakdown,
            funnel: funnel
        },
        recent_activity: recentActivity,
        _meta: {
            data_sources: {
                invited: { source: 'COUNT(*) FROM training_tracking WHERE training_id = ?', description: 'Total recipients in this training' },
                susceptible: { source: 'COUNT(url_clicked_at) FROM training_tracking WHERE training_id = ?', description: 'Recipients who clicked the phishing link' },
                reported: { source: 'COUNT(training_reported_at) FROM training_tracking WHERE training_id = ?', description: 'Recipients who reported the email' },
                completed: { source: 'COUNT(training_completed_at) FROM training_tracking WHERE training_id = ?', description: 'Recipients who completed immediate education' },
                funnel: { source: 'Sequential COUNT of timestamp fields', description: 'Shows dropoff at each stage of the training process' }
            }
        }
    };
}

/**
 * Derive status from timestamps (mirrors the SQL CASE logic)
 */
function deriveStatusFromTimestamps(timestamps) {
    if (timestamps.follow_on_completed_at) return 'FOLLOW_ON_COMPLETED';
    if (timestamps.follow_on_viewed_at) return 'FOLLOW_ON_VIEWED';
    if (timestamps.follow_on_sent_at) return 'FOLLOW_ON_SENT';
    if (timestamps.training_completed_at) return 'TRAINING_COMPLETED';
    if (timestamps.training_reported_at) return 'TRAINING_REPORTED';
    if (timestamps.url_clicked_at) return 'TRAINING_CLICKED';
    if (timestamps.training_viewed_at) return 'TRAINING_OPENED';
    if (timestamps.training_sent_at) return 'TRAINING_SENT';
    return 'TRAINING_PENDING';
}

function generateRecentActivity(seededRandom, count) {
    const domains = ['acme.com', 'globex.com', 'initech.com', 'umbrella.corp'];
    const firstNames = ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
    const lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Taylor'];

    const activity = [];
    for (let i = 0; i < count; i++) {
        const firstName = firstNames[Math.floor(seededRandom() * firstNames.length)];
        const lastName = lastNames[Math.floor(seededRandom() * lastNames.length)];
        const domain = domains[Math.floor(seededRandom() * domains.length)];

        // Generate timestamps based on random progression through funnel
        const daysBack = Math.floor(seededRandom() * 14);
        const sentDate = randomDate(seededRandom, daysBack + 7);
        const reportedDate = seededRandom() > 0.85 ? randomDate(seededRandom, daysBack) : null;
        const clickedDate = !reportedDate && seededRandom() > 0.4 ? randomDate(seededRandom, daysBack) : null;
        const completedDate = clickedDate && seededRandom() > 0.3 ? randomDate(seededRandom, 7) : null;
        const followOnCompleted = completedDate && seededRandom() > 0.5 ? randomDate(seededRandom, 5) : null;

        const timestamps = {
            training_sent_at: sentDate,
            training_reported_at: reportedDate,
            url_clicked_at: clickedDate,
            training_completed_at: completedDate,
            follow_on_completed_at: followOnCompleted
        };

        // Derive status from timestamps (same logic as SQL CASE statement)
        const derivedStatus = deriveStatusFromTimestamps(timestamps);

        activity.push({
            id: `rec-${i}`,
            recipient_id: `user-${1000 + i}`,
            recipient_email_address: `${firstName.toLowerCase()}.${lastName.toLowerCase()}@${domain}`,
            status: derivedStatus,
            last_action_at: randomDate(seededRandom, 3),
            url_clicked_at: clickedDate,
            training_completed_at: completedDate,
            follow_on_completed_at: followOnCompleted,
            training_score: completedDate ? Math.floor(seededRandom() * 40) + 60 : null,
            follow_on_score: followOnCompleted ? Math.floor(seededRandom() * 40) + 60 : null
        });
    }
    return activity;
}

function randomDate(seededRandom, daysBack) {
    const date = new Date();
    date.setDate(date.getDate() - Math.floor(seededRandom() * daysBack));
    date.setHours(Math.floor(seededRandom() * 24));
    date.setMinutes(Math.floor(seededRandom() * 60));
    return date.toISOString();
}

// Pre-generate training data
const TRAINING_DEFINITIONS = [
    { id: 'tr-001', name: 'Q4 Phishing Simulation - Brand Impersonation', status: 'completed', type: 'Brand Impersonation' },
    { id: 'tr-002', name: 'Holiday Security Awareness', status: 'completed', type: 'Financial Transactions' },
    { id: 'tr-003', name: 'Compliance Training - December', status: 'active', type: 'Compliance' },
    { id: 'tr-004', name: 'Social Engineering Awareness', status: 'completed', type: 'Emotions' },
    { id: 'tr-005', name: 'Mobile Device Security', status: 'draft', type: 'Mobile' }
];

const DEMO_TRAININGS = TRAINING_DEFINITIONS.map(t =>
    generateTrainingData(t.id, t.name, t.status, t.type)
);

// Training list data
const DEMO_TRAININGS_LIST = {
    success: true,
    total: DEMO_TRAININGS.length,
    limit: 50,
    offset: 0,
    trainings: DEMO_TRAININGS.map(t => ({
        id: t.training.id,
        name: t.training.name,
        description: t.training.description,
        training_type: t.training.training_type,
        status: t.training.status,
        created_at: t.training.created_at,
        invited_count: t.metrics.invited,
        susceptible_count: t.metrics.susceptible,
        completed_count: t.metrics.immediate_education,
        follow_on_count: t.metrics.smart_reinforcement,
        reported_count: t.metrics.reported,
        completion_percentage: t.metrics.completion_percentage
    }))
};

// Export for use in demo pages
if (typeof window !== 'undefined') {
    window.generateAggregatedData = generateAggregatedData;
    window.DEMO_TRAININGS = DEMO_TRAININGS;
    window.DEMO_TRAININGS_LIST = DEMO_TRAININGS_LIST;
    window.DATA_SOURCES = DATA_SOURCES;
}
