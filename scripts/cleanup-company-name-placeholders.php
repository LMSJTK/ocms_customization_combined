#!/usr/bin/env php
<?php
/**
 * Cleanup COMPANY_NAME Placeholders Script
 *
 * Finds email content in the database with COMPANY_NAME placeholders in email_body_html
 * and strips them (including surrounding element) using the same logic as import processing.
 *
 * Usage: php scripts/cleanup-company-name-placeholders.php [--dry-run] [--verbose]
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/cleanup-company-name-placeholders.php [options]\n\n";
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

echo "=== COMPANY_NAME Placeholder Cleanup ===\n\n";

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made to database\n\n";
}

/**
 * Strip COMPANY_NAME placeholders from HTML (same logic as ContentProcessor)
 */
function stripCompanyNamePlaceholders($html) {
    $stripWithElementList = ['COMPANY_NAME', 'company_name'];
    $processedHtml = $html;
    $found = false;

    foreach ($stripWithElementList as $placeholderName) {
        // Match the placeholder and try to find its parent element
        // Pattern: matches an element that contains the placeholder span
        $elementPattern = '/<([a-z][a-z0-9]*)\b[^>]*>.*?<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>.*?<\/\1>/is';

        $before = $processedHtml;
        $processedHtml = preg_replace($elementPattern, '', $processedHtml);

        if ($processedHtml !== $before) {
            $found = true;
        }

        // Also handle if the placeholder is standalone (less common)
        $standalonePattern = '/<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>/is';

        $before = $processedHtml;
        $processedHtml = preg_replace($standalonePattern, '', $processedHtml);

        if ($processedHtml !== $before) {
            $found = true;
        }
    }

    return [
        'html' => $processedHtml,
        'found' => $found,
        'changed' => $html !== $processedHtml
    ];
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

    // Find all email content with COMPANY_NAME placeholders
    echo "Searching for email content with COMPANY_NAME placeholders...\n";

    $query = "
        SELECT id, title, email_body_html
        FROM {$tablePrefix}content
        WHERE content_type = 'email'
        AND email_body_html IS NOT NULL
        AND (
            email_body_html LIKE '%data-basename=\"COMPANY_NAME\"%'
            OR email_body_html LIKE '%data-basename=''COMPANY_NAME''%'
            OR email_body_html LIKE '%data-basename=\"company_name\"%'
            OR email_body_html LIKE '%data-basename=''company_name''%'
        )
    ";

    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFound = count($results);
    echo "Found $totalFound email(s) with COMPANY_NAME placeholders\n\n";

    if ($totalFound === 0) {
        echo "No cleanup needed!\n";
        exit(0);
    }

    // Process each email
    $updated = 0;
    $errors = 0;

    foreach ($results as $index => $row) {
        $contentId = $row['id'];
        $title = $row['title'];
        $originalHtml = $row['email_body_html'];

        echo "[" . ($index + 1) . "/$totalFound] Processing: $title (ID: $contentId)\n";

        // Strip COMPANY_NAME placeholders
        $result = stripCompanyNamePlaceholders($originalHtml);

        if (!$result['found']) {
            echo "  ⚠️  COMPANY_NAME not found (false positive from SQL query)\n\n";
            continue;
        }

        if (!$result['changed']) {
            echo "  ℹ️  No changes needed (placeholder already clean)\n\n";
            continue;
        }

        if ($verbose) {
            $originalLength = strlen($originalHtml);
            $newLength = strlen($result['html']);
            $removed = $originalLength - $newLength;
            echo "  Original length: $originalLength bytes\n";
            echo "  New length: $newLength bytes\n";
            echo "  Removed: $removed bytes\n";
        }

        if ($dryRun) {
            echo "  ✓ Would update (dry-run mode)\n\n";
        } else {
            // Update the database
            try {
                $updateStmt = $db->prepare("UPDATE {$tablePrefix}content SET email_body_html = :html WHERE id = :id");
                $updateStmt->execute([
                    ':html' => $result['html'],
                    ':id' => $contentId
                ]);

                echo "  ✓ Updated successfully\n\n";
                $updated++;
            } catch (Exception $e) {
                echo "  ✗ Error updating: " . $e->getMessage() . "\n\n";
                $errors++;
            }
        }
    }

    // Summary
    echo "\n=== Summary ===\n";
    echo "Total emails found: $totalFound\n";

    if ($dryRun) {
        echo "Would update: $updated email(s)\n";
        echo "\nRun without --dry-run to apply changes.\n";
    } else {
        echo "Successfully updated: $updated email(s)\n";
        if ($errors > 0) {
            echo "Errors: $errors\n";
        }
    }

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
