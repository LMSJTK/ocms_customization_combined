#!/usr/bin/env php
<?php
/**
 * Fix Content Base URLs Script
 *
 * Updates hardcoded environment-specific base URLs in content to match
 * the current environment's base URL from config.
 *
 * This handles content that was uploaded/imported in one environment (e.g., staging)
 * and needs to work in another (e.g., production).
 *
 * Fixes TWO things:
 *   1. Content files on disk (PHP/HTML files in /content/ directory)
 *      - Specifically the <base href="..."> tags injected during upload
 *   2. Database fields (email_body_html, content_preview)
 *
 * Known source URLs that get replaced:
 *   - https://ecs-ocmsservice.cfp-dev.cofense-dev.com
 *   - https://ecs-ocmsservice.cfp-staging.cofense-dev.com
 *
 * Usage:
 *   php scripts/fix-content-base-urls.php [options]
 *
 * Options:
 *   --dry-run    Show what would be changed without making changes
 *   --verbose    Show detailed progress information
 *   --quiet      Only show errors and final summary
 *   --help       Show this help message
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// Parse command line arguments
$options = getopt('', [
    'dry-run',
    'verbose',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Fix Content Base URLs Script

Updates hardcoded environment-specific base URLs in content to match
the current environment's base URL from config.

Fixes TWO things:
  1. Content files on disk (PHP/HTML files in /content/ directory)
     - Specifically the <base href="..."> tags injected during upload
  2. Database fields (email_body_html, content_preview)

Usage:
  php scripts/fix-content-base-urls.php [options]

Options:
  --dry-run    Show what would be changed without making changes
  --verbose    Show detailed progress information
  --quiet      Only show errors and final summary
  --help       Show this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

// Logging functions
function logInfo($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        echo "[INFO] $message\n";
    }
}

function logWarning($message) {
    global $quiet;
    if (!$quiet) {
        echo "[WARNING] $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    global $quiet;
    if (!$quiet) {
        echo "[SUCCESS] $message\n";
    }
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}

if (!file_exists($configPath)) {
    logError("Configuration file not found");
    exit(1);
}

$config = require $configPath;

// Get the current environment's base URL
$currentBaseUrl = $config['app']['base_url'] ?? null;
if (empty($currentBaseUrl)) {
    logError("No base_url configured in app settings");
    exit(1);
}

// Remove trailing slash for consistency
$currentBaseUrl = rtrim($currentBaseUrl, '/');

logInfo("Current environment base URL: $currentBaseUrl");

// Get content directory
$contentDir = $config['content']['upload_dir'] ?? '/var/www/html/content/';
logInfo("Content directory: $contentDir");

// Known environment-specific base URLs that might be in imported content
$sourceBaseUrls = [
    'https://ecs-ocmsservice.cfp-dev.cofense-dev.com',
    'https://ecs-ocmsservice.cfp-staging.cofense-dev.com',
    'https://ecs-ocmsservice.cfp-prod.cofense-dev.com',
    'https://ecs-ocmsservice.cfp.cofense.com',
    // Add http variants just in case
    'http://ecs-ocmsservice.cfp-dev.cofense-dev.com',
    'http://ecs-ocmsservice.cfp-staging.cofense-dev.com',
];

// Remove the current base URL from the list (no need to replace with itself)
$sourceBaseUrls = array_filter($sourceBaseUrls, function($url) use ($currentBaseUrl) {
    return strcasecmp(rtrim($url, '/'), $currentBaseUrl) !== 0;
});

if (empty($sourceBaseUrls)) {
    logInfo("No source URLs to replace (current URL matches all known URLs)");
    exit(0);
}

logInfo("Will replace these base URLs: " . implode(', ', $sourceBaseUrls));

// ============================================================================
// PART 1: Fix content files on disk
// ============================================================================
logInfo("=== Scanning content files on disk ===");

$filesFixed = 0;
$filesScanned = 0;

if (is_dir($contentDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contentDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        // Only process PHP and HTML files
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'html', 'htm'])) {
            continue;
        }

        $filePath = $file->getPathname();
        $filesScanned++;

        $content = file_get_contents($filePath);
        if ($content === false) {
            logWarning("Could not read file: $filePath");
            continue;
        }

        $originalContent = $content;
        $fileModified = false;

        // Replace each source URL with the current environment's URL
        foreach ($sourceBaseUrls as $sourceUrl) {
            if (strpos($content, $sourceUrl) !== false) {
                $content = str_replace($sourceUrl, $currentBaseUrl, $content);
                $fileModified = true;
                logInfo("  Found '$sourceUrl' in: $filePath");
            }
        }

        if ($fileModified) {
            if ($dryRun) {
                logInfo("  Would fix: $filePath");
            } else {
                if (file_put_contents($filePath, $content) !== false) {
                    logInfo("  Fixed: $filePath");
                } else {
                    logWarning("  Failed to write: $filePath");
                }
            }
            $filesFixed++;
        }
    }
} else {
    logWarning("Content directory does not exist: $contentDir");
}

logInfo("Scanned $filesScanned files, found $filesFixed needing fixes");

// ============================================================================
// PART 2: Fix database fields
// ============================================================================
logInfo("=== Checking database fields ===");

// Load database class
require_once __DIR__ . '/../lib/Database.php';

$totalDbUpdated = 0;
$totalRowsAffected = 0;

try {
    logInfo("Connecting to database...");
    $db = Database::getInstance($config['database']);
    $dbType = $db->getDbType();
    logInfo("Connected to $dbType database");

    // Titles of system templates that should never be modified
    $excludedTitles = [
        'Default Direct Training Template',
        'Default Smart Reinforcement Template',
    ];

    // Build exclusion clause for SQL
    $excludeClause = '';
    $excludeParams = [];
    foreach ($excludedTitles as $i => $title) {
        $excludeParams[":excl_title_$i"] = $title;
    }
    if (!empty($excludeParams)) {
        $excludeClause = ' AND title NOT IN (' . implode(', ', array_keys($excludeParams)) . ')';
    }

    // Fields that may contain base URLs
    $fieldsToCheck = [
        'content_preview',
        'email_body_html'
    ];

    if (!$dryRun) {
        $db->beginTransaction();
    }

    try {
        foreach ($fieldsToCheck as $field) {
            logInfo("Checking field: $field");

            foreach ($sourceBaseUrls as $sourceUrl) {
                // Build the query to find and update rows
                // Use LIKE to find rows containing the source URL
                $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $sourceUrl) . '%';

                // First, count how many rows would be affected
                $countSql = "SELECT COUNT(*) as cnt FROM content WHERE $field LIKE :pattern" . $excludeClause;
                $countParams = array_merge([':pattern' => $likePattern], $excludeParams);
                $countResult = $db->fetchOne($countSql, $countParams);
                $matchCount = $countResult['cnt'] ?? 0;

                if ($matchCount == 0) {
                    logInfo("  No rows found with '$sourceUrl' in $field");
                    continue;
                }

                logInfo("  Found $matchCount rows with '$sourceUrl' in $field");

                if ($dryRun) {
                    $totalRowsAffected += $matchCount;
                    $totalDbUpdated++;
                    continue;
                }

                // Perform the replacement
                // PostgreSQL uses REPLACE(), MySQL also uses REPLACE()
                $updateSql = "UPDATE content SET $field = REPLACE($field, :source, :target) WHERE $field LIKE :pattern" . $excludeClause;
                $db->query($updateSql, array_merge([
                    ':source' => $sourceUrl,
                    ':target' => $currentBaseUrl,
                    ':pattern' => $likePattern
                ], $excludeParams));

                $totalRowsAffected += $matchCount;
                $totalDbUpdated++;
                logInfo("  Updated $matchCount rows: '$sourceUrl' -> '$currentBaseUrl'");
            }
        }

        if (!$dryRun) {
            $db->commit();
        }

    } catch (Exception $e) {
        if (!$dryRun && $db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

} catch (Exception $e) {
    logError("Database error: " . $e->getMessage());
    // Continue - file fixes were still applied
}

// ============================================================================
// Summary
// ============================================================================
$modeLabel = $dryRun ? "(DRY RUN) " : "";
$summary = [];

if ($filesFixed > 0) {
    $summary[] = "$filesFixed content files fixed";
}
if ($totalRowsAffected > 0) {
    $summary[] = "$totalRowsAffected database rows updated";
}

if (!empty($summary)) {
    logSuccess("{$modeLabel}Base URL fix complete: " . implode(', ', $summary));
} else {
    logInfo("No base URLs needed fixing");
}

exit(0);
