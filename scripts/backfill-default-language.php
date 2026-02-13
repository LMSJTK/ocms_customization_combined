#!/usr/bin/env php
<?php
/**
 * Backfill Default Language Script
 *
 * Sets 'en' (English) as the default language for content rows that don't have
 * a language set. This handles content that was uploaded before the language
 * field was required, or legacy content without language data in PM tables.
 *
 * Usage:
 *   php scripts/backfill-default-language.php [options]
 *
 * Options:
 *   --default=LANG   Language code to use as default (default: en)
 *   --dry-run        Show what would be updated without making changes
 *   --verbose        Show detailed progress information
 *   --quiet          Only show errors and final summary
 *   --help           Show this help message
 *
 * Examples:
 *   php scripts/backfill-default-language.php --dry-run --verbose
 *   php scripts/backfill-default-language.php --default=en
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, etc.)
 */

set_time_limit(0);
ini_set('memory_limit', '256M');

// Parse command line arguments
$options = getopt('', [
    'default:',
    'dry-run',
    'verbose',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Backfill Default Language Script

Sets a default language for content rows that don't have a language set.
This handles content uploaded before languages were required.

Usage:
  php scripts/backfill-default-language.php [options]

Options:
  --default=LANG   Language code to use as default (default: en)
  --dry-run        Show what would be updated without making changes
  --verbose        Show detailed progress information
  --quiet          Only show errors and final summary
  --help           Show this help message

Examples:
  php scripts/backfill-default-language.php --dry-run --verbose
  php scripts/backfill-default-language.php --default=en

Exit codes:
  0 - Success
  1 - Error (configuration, database, etc.)

HELP;
    exit(0);
}

$defaultLang = $options['default'] ?? 'en';
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

function logInfo($message) {
    global $quiet;
    if (!$quiet) {
        echo "[INFO] $message\n";
    }
}

function logVerbose($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        echo "  $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

// Header
if (!$quiet) {
    echo "=== Backfill Default Language ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo "Default language: $defaultLang\n";
    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n";
    }
    echo "\n";
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

// Load database class
require_once __DIR__ . '/../lib/Database.php';

try {
    $db = Database::getInstance($config['database']);
    logInfo("Connected to database");
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    exit(1);
}

// Find content rows without a language
logInfo("Finding content without languages...");

$query = "SELECT id, title, content_type FROM content WHERE languages IS NULL OR languages = ''";
$contentRows = $db->fetchAll($query);
$totalRows = count($contentRows);

logInfo("Found $totalRows content rows without languages");

if ($totalRows === 0) {
    logInfo("All content already has languages set. Nothing to do.");
    exit(0);
}

// Update content rows
$updated = 0;
$errors = 0;

foreach ($contentRows as $row) {
    $id = $row['id'];
    $title = $row['title'] ?? '(no title)';
    $type = $row['content_type'] ?? 'unknown';

    if ($dryRun) {
        $updated++;
        logVerbose("Would set: [$type] " . substr($title, 0, 60) . " -> '$defaultLang'");
        continue;
    }

    try {
        $db->query(
            "UPDATE content SET languages = :lang WHERE id = :id",
            [':lang' => $defaultLang, ':id' => $id]
        );
        $updated++;
        logVerbose("Updated: [$type] " . substr($title, 0, 60) . " -> '$defaultLang'");
    } catch (Exception $e) {
        $errors++;
        logError("Failed to update $id: " . $e->getMessage());
    }
}

// Summary
echo "\n";
$modeLabel = $dryRun ? "(DRY RUN) " : "";
logInfo("{$modeLabel}Backfill complete:");
logInfo("  Content without languages: $totalRows");
logInfo("  Updated to '$defaultLang': $updated");
if ($errors > 0) {
    logInfo("  Errors: $errors");
}
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);
