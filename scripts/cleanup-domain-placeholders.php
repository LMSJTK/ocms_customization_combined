#!/usr/bin/env php
<?php
/**
 * Cleanup Phishing Link Hrefs Script
 *
 * Finds email content in the database where phishing links have legacy hrefs
 * (containing TopLevelDomain/PhishingDomain placeholders or hardcoded URLs)
 * and normalizes them to use {{{trainingURL}}} placeholder.
 *
 * The phishing links are identified by class="phishing-link-do-not-delete"
 * and their href should always be {{{trainingURL}}} which gets resolved
 * at email send time.
 *
 * Usage: php scripts/cleanup-domain-placeholders.php [--dry-run] [--verbose]
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/cleanup-domain-placeholders.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run   Preview changes without updating database\n";
    echo "  --verbose   Show detailed processing information\n";
    echo "  --help      Show this help message\n\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

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

echo "=== Phishing Link Href Cleanup ===\n\n";

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made to database\n\n";
}

/**
 * Normalize phishing link hrefs to use {{{trainingURL}}} placeholder
 *
 * Finds all <a> tags with class="phishing-link-do-not-delete" and replaces
 * their href attribute with {{{trainingURL}}}
 */
function normalizePhishingLinkHrefs($html) {
    $count = 0;

    // Pattern to match <a> tags with class containing "phishing-link-do-not-delete"
    // where href is NOT already {{{trainingURL}}}
    $pattern = '/(<a\s[^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*)href\s*=\s*["\'](?!\{\{\{trainingURL\}\}\})[^"\']*["\']([^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern,
        function($matches) use (&$count) {
            $count++;
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $html
    );

    // Also handle case where href comes before class
    $pattern2 = '/(<a\s[^>]*)href\s*=\s*["\'](?!\{\{\{trainingURL\}\}\})[^"\']*["\']([^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern2,
        function($matches) use (&$count) {
            $count++;
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $processedHtml
    );

    return [
        'html' => $processedHtml,
        'count' => $count,
        'changed' => $html !== $processedHtml
    ];
}

/**
 * Extract the current href value from a phishing link for verbose output
 */
function extractPhishingLinkHrefs($html) {
    $hrefs = [];

    // Match phishing links and capture their href values
    $pattern = '/<a\s[^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>/i';
    if (preg_match_all($pattern, $html, $matches)) {
        $hrefs = array_merge($hrefs, $matches[1]);
    }

    // Also check href before class pattern
    $pattern2 = '/<a\s[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*>/i';
    if (preg_match_all($pattern2, $html, $matches)) {
        $hrefs = array_merge($hrefs, $matches[1]);
    }

    return array_unique($hrefs);
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

    echo "Connected to database: {$dbConfig['host']}/{$dbConfig['dbname']} ($dbType)\n";
    if (!empty($tablePrefix)) {
        echo "Using schema: $schema\n";
    }
    echo "\n";

    // Find all email content with phishing links that have non-standard hrefs
    echo "Searching for email content with phishing links needing normalization...\n";

    // Look for phishing links where href is NOT {{{trainingURL}}}
    $query = "
        SELECT id, title, email_body_html
        FROM {$tablePrefix}content
        WHERE content_type = 'email'
        AND email_body_html IS NOT NULL
        AND email_body_html LIKE '%phishing-link-do-not-delete%'
        AND (
            email_body_html LIKE '%TopLevelDomain.domain_for_template%'
            OR email_body_html LIKE '%PhishingDomain.domain_for_template%'
            OR (
                email_body_html LIKE '%phishing-link-do-not-delete%'
                AND email_body_html NOT LIKE '%href=\"{{{trainingURL}}}%'
                AND email_body_html NOT LIKE '%href=''{{{trainingURL}}}%'
            )
        )
    ";

    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFound = count($results);
    echo "Found $totalFound email(s) with potential phishing link issues\n\n";

    if ($totalFound === 0) {
        echo "No cleanup needed!\n";
        exit(0);
    }

    // Process each email
    $updated = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($results as $index => $row) {
        $contentId = $row['id'];
        $title = $row['title'];
        $originalHtml = $row['email_body_html'];

        echo "[" . ($index + 1) . "/$totalFound] Processing: $title (ID: $contentId)\n";

        if ($verbose) {
            $currentHrefs = extractPhishingLinkHrefs($originalHtml);
            if (!empty($currentHrefs)) {
                echo "  Current phishing link hrefs:\n";
                foreach ($currentHrefs as $href) {
                    $displayHref = strlen($href) > 80 ? substr($href, 0, 77) . '...' : $href;
                    echo "    - $displayHref\n";
                }
            }
        }

        // Normalize phishing link hrefs
        $result = normalizePhishingLinkHrefs($originalHtml);

        if (!$result['changed']) {
            echo "  No changes needed (hrefs already normalized)\n\n";
            $skipped++;
            continue;
        }

        echo "  Found " . $result['count'] . " phishing link(s) to normalize\n";

        if ($dryRun) {
            echo "  Would update (dry-run mode)\n\n";
            $updated++;
        } else {
            // Update the database
            try {
                $updateStmt = $db->prepare("UPDATE {$tablePrefix}content SET email_body_html = :html WHERE id = :id");
                $updateStmt->execute([
                    ':html' => $result['html'],
                    ':id' => $contentId
                ]);

                echo "  Updated successfully\n\n";
                $updated++;
            } catch (Exception $e) {
                echo "  Error updating: " . $e->getMessage() . "\n\n";
                $errors++;
            }
        }
    }

    // Summary
    echo "\n=== Summary ===\n";
    echo "Total emails checked: $totalFound\n";

    if ($dryRun) {
        echo "Would update: $updated email(s)\n";
    } else {
        echo "Successfully updated: $updated email(s)\n";
    }

    if ($skipped > 0) {
        echo "Already normalized: $skipped email(s)\n";
    }

    if ($errors > 0) {
        echo "Errors: $errors\n";
    }

    if ($dryRun && $updated > 0) {
        echo "\nRun without --dry-run to apply changes.\n";
    }

} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
