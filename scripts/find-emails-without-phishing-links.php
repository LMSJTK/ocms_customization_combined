#!/usr/bin/env php
<?php
/**
 * Find Emails Without Phishing Links Script
 *
 * Scans all email content in the database to find emails that don't have
 * phishing links (class="phishing-link-do-not-delete" or {{{trainingURL}}})
 *
 * Usage: php scripts/find-emails-without-phishing-links.php [--verbose] [--format=table|csv|json]
 */

// Parse command line arguments
$options = getopt('', ['verbose', 'format::', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/find-emails-without-phishing-links.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose       Show detailed information about each email\n";
    echo "  --format=FORMAT Output format: table (default), csv, or json\n";
    echo "  --help          Show this help message\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$format = $options['format'] ?? 'table';

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    echo "ERROR: Config file not found: $configPath\n";
    exit(1);
}

$oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
$config = @require $configPath;
error_reporting($oldErrorReporting);

if (!is_array($config) || !isset($config['database'])) {
    echo "ERROR: Invalid configuration file\n";
    exit(1);
}

echo "=== Find Emails Without Phishing Links ===\n\n";

/**
 * Check if HTML contains phishing link indicators
 */
function hasPhishingLinks($html) {
    if (empty($html)) {
        return false;
    }

    // Check for phishing link class marker
    if (strpos($html, 'phishing-link-do-not-delete') !== false) {
        return true;
    }

    // Check for trainingURL placeholder
    if (strpos($html, '{{{trainingURL}}}') !== false) {
        return true;
    }

    return false;
}

/**
 * Extract snippet from HTML (remove tags, limit length)
 */
function extractSnippet($html, $maxLength = 100) {
    if (empty($html)) {
        return '';
    }

    // Remove HTML tags
    $text = strip_tags($html);

    // Remove extra whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    // Truncate if needed
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength) . '...';
    }

    return $text;
}

/**
 * Count links in HTML
 */
function countLinks($html) {
    if (empty($html)) {
        return 0;
    }

    // Count <a> tags
    preg_match_all('/<a\s+[^>]*href\s*=\s*["\']/i', $html, $matches);
    return count($matches[0]);
}

try {
    // Connect to database
    $dbConfig = $config['database'];
    $dbType = $dbConfig['type'] ?? 'mysql';

    // Validate database config
    if (empty($dbConfig['host']) || empty($dbConfig['dbname'])) {
        throw new Exception("Database configuration is incomplete. Make sure environment variables are set.");
    }

    if ($dbType === 'pgsql' || $dbType === 'postgres') {
        $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
        $db = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    } else {
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
        $db = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine table prefix (schema for PostgreSQL)
    $schema = $dbConfig['schema'] ?? null;
    $tablePrefix = '';
    if ($dbType === 'pgsql' && !empty($schema)) {
        $tablePrefix = $schema . '.';
    }

    if ($verbose) {
        echo "Connected to database: {$dbConfig['host']}/{$dbConfig['dbname']} ($dbType)\n";
        if (!empty($tablePrefix)) {
            echo "Using schema: $schema\n";
        }
        echo "\n";
    }

    // Find all email content
    echo "Searching for email content...\n";

    $query = "
        SELECT id, title, email_subject, email_body_html, created_at
        FROM {$tablePrefix}content
        WHERE content_type = 'email'
        AND email_body_html IS NOT NULL
        ORDER BY created_at DESC
    ";

    $stmt = $db->query($query);
    $allEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalEmails = count($allEmails);
    echo "Found $totalEmails email(s) in database\n";
    echo "Analyzing for phishing links...\n\n";

    // Analyze each email
    $withLinks = [];
    $withoutLinks = [];

    foreach ($allEmails as $email) {
        $hasLinks = hasPhishingLinks($email['email_body_html']);

        if ($hasLinks) {
            $withLinks[] = $email;
        } else {
            $withoutLinks[] = $email;
        }
    }

    $withLinksCount = count($withLinks);
    $withoutLinksCount = count($withoutLinks);

    // Output results based on format
    if ($format === 'json') {
        $output = [
            'summary' => [
                'total_emails' => $totalEmails,
                'with_phishing_links' => $withLinksCount,
                'without_phishing_links' => $withoutLinksCount
            ],
            'emails_without_links' => array_map(function($email) {
                return [
                    'id' => $email['id'],
                    'title' => $email['title'],
                    'subject' => $email['email_subject'],
                    'created_at' => $email['created_at'],
                    'link_count' => countLinks($email['email_body_html']),
                    'snippet' => extractSnippet($email['email_body_html'], 150)
                ];
            }, $withoutLinks)
        ];

        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    } elseif ($format === 'csv') {
        echo "ID,Title,Subject,Created At,Total Links,Snippet\n";
        foreach ($withoutLinks as $email) {
            $linkCount = countLinks($email['email_body_html']);
            $snippet = extractSnippet($email['email_body_html'], 100);

            echo sprintf(
                '"%s","%s","%s","%s",%d,"%s"' . "\n",
                $email['id'],
                str_replace('"', '""', $email['title'] ?? ''),
                str_replace('"', '""', $email['email_subject'] ?? ''),
                $email['created_at'] ?? '',
                $linkCount,
                str_replace('"', '""', $snippet)
            );
        }
    } else {
        // Table format (default)
        echo "=== Summary ===\n\n";
        echo "Total emails: $totalEmails\n";
        echo "With phishing links: $withLinksCount (" . round(($withLinksCount / $totalEmails) * 100, 1) . "%)\n";
        echo "WITHOUT phishing links: $withoutLinksCount (" . round(($withoutLinksCount / $totalEmails) * 100, 1) . "%)\n\n";

        if ($withoutLinksCount === 0) {
            echo "✓ All emails have phishing links!\n";
        } else {
            echo "=== Emails WITHOUT Phishing Links ===\n\n";
            echo sprintf("%-40s %-45s %8s %s\n", "ID", "TITLE", "LINKS", "CREATED");
            echo str_repeat("-", 120) . "\n";

            foreach ($withoutLinks as $email) {
                $linkCount = countLinks($email['email_body_html']);
                $title = $email['title'] ?? 'Untitled';

                // Truncate long titles
                if (strlen($title) > 43) {
                    $title = substr($title, 0, 40) . '...';
                }

                // Format created date
                $createdAt = $email['created_at'] ? date('Y-m-d', strtotime($email['created_at'])) : 'N/A';

                echo sprintf(
                    "%-40s %-45s %8d %s\n",
                    $email['id'],
                    $title,
                    $linkCount,
                    $createdAt
                );

                if ($verbose) {
                    echo "  Subject: " . ($email['email_subject'] ?? 'N/A') . "\n";
                    echo "  Snippet: " . extractSnippet($email['email_body_html'], 120) . "\n";
                    echo "\n";
                }
            }

            echo "\n";
            echo "Note: These emails may be legitimates (notifications, confirmations) or\n";
            echo "      may need phishing links added if they're intended for phishing simulations.\n";
        }
    }

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    if ($verbose) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}

echo "\nDone!\n";
