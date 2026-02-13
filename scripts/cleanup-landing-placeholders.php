#!/usr/bin/env php
<?php
/**
 * Cleanup COMPANY_NAME Placeholders in Landing Pages Script
 *
 * Finds landing page content with COMPANY_NAME placeholders in their index.php files
 * and strips them (including surrounding element) using the same logic as import processing.
 *
 * Usage: php scripts/cleanup-landing-placeholders.php [--dry-run] [--verbose]
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/cleanup-landing-placeholders.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run   Preview changes without updating files\n";
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

echo "=== Landing Page COMPANY_NAME Placeholder Cleanup ===\n\n";

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made to files\n\n";
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

    // Get content directory
    $contentDir = $config['content']['upload_dir'] ?? null;
    if (!$contentDir || !is_dir($contentDir)) {
        throw new Exception("Content directory not found or not configured: $contentDir");
    }

    echo "Content directory: $contentDir\n\n";

    // Find all landing page content
    echo "Searching for landing page content...\n";

    $query = "
        SELECT id, title, content_url
        FROM {$tablePrefix}content
        WHERE content_type = 'landing'
        AND content_url IS NOT NULL
        ORDER BY created_at DESC
    ";

    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFound = count($results);
    echo "Found $totalFound landing page(s)\n";
    echo "Checking for COMPANY_NAME placeholders...\n\n";

    // Process each landing page
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $notFound = 0;

    foreach ($results as $index => $row) {
        $contentId = $row['id'];
        $title = $row['title'];
        $contentUrl = $row['content_url'];

        echo "[" . ($index + 1) . "/$totalFound] Processing: $title (ID: $contentId)\n";

        // Determine file path
        // Landing pages are typically stored as contentId/index.php
        $filePath = $contentDir . $contentId . '/index.php';

        if (!file_exists($filePath)) {
            // Try without .php extension
            $filePath = $contentDir . $contentId . '/index.html';

            if (!file_exists($filePath)) {
                echo "  ⚠️  File not found: $filePath\n\n";
                $notFound++;
                continue;
            }
        }

        if ($verbose) {
            echo "  File: $filePath\n";
        }

        // Read file content
        $originalHtml = file_get_contents($filePath);
        if ($originalHtml === false) {
            echo "  ✗ Error reading file\n\n";
            $errors++;
            continue;
        }

        // Check if it contains COMPANY_NAME placeholders
        if (strpos($originalHtml, 'data-basename="COMPANY_NAME"') === false &&
            strpos($originalHtml, "data-basename='COMPANY_NAME'") === false &&
            strpos($originalHtml, 'data-basename="company_name"') === false &&
            strpos($originalHtml, "data-basename='company_name'") === false) {
            echo "  ℹ️  No COMPANY_NAME placeholders found\n\n";
            $skipped++;
            continue;
        }

        // Strip COMPANY_NAME placeholders
        $result = stripCompanyNamePlaceholders($originalHtml);

        if (!$result['found']) {
            echo "  ⚠️  COMPANY_NAME not found (false positive)\n\n";
            $skipped++;
            continue;
        }

        if (!$result['changed']) {
            echo "  ℹ️  No changes needed (placeholder already clean)\n\n";
            $skipped++;
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
            $updated++;
        } else {
            // Update the file
            try {
                $writeResult = file_put_contents($filePath, $result['html']);
                if ($writeResult === false) {
                    throw new Exception("Failed to write file");
                }

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
    echo "Total landing pages: $totalFound\n";
    echo "Files not found: $notFound\n";
    echo "Skipped (no placeholders): $skipped\n";

    if ($dryRun) {
        echo "Would update: $updated landing page(s)\n";
        echo "\nRun without --dry-run to apply changes.\n";
    } else {
        echo "Successfully updated: $updated landing page(s)\n";
        if ($errors > 0) {
            echo "Errors: $errors\n";
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
