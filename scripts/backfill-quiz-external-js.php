#!/usr/bin/env php
<?php
/**
 * Backfill Quiz External JS
 *
 * Updates existing quiz content to use external JavaScript instead of inline scripts.
 * This resolves Content Security Policy (CSP) violations.
 *
 * Usage:
 *   php scripts/backfill-quiz-external-js.php [options]
 *
 * Options:
 *   --dry-run    Show what would be done without making changes
 *   --verbose    Show detailed progress information
 *   --help       Show this help message
 */

set_time_limit(0);

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Backfill Quiz External JS

Updates existing quiz content to use external JavaScript instead of inline scripts.
This resolves Content Security Policy (CSP) violations.

Usage:
  php scripts/backfill-quiz-external-js.php [options]

Options:
  --dry-run    Show what would be done without making changes
  --verbose    Show detailed progress information
  --help       Show this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

function logInfo($message) {
    echo "[INFO] $message\n";
}

function logVerbose($message) {
    global $verbose;
    if ($verbose) {
        echo "  $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    echo "[OK] $message\n";
}

// Header
echo "=== Backfill Quiz External JS ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
if ($dryRun) {
    echo "*** DRY RUN MODE - No changes will be made ***\n";
}
echo "\n";

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
$uploadDir = $config['content']['upload_dir'] ?? '/var/www/html/content/';

// Pattern to match the inline quiz script block
// This matches from <script> containing ocmsScoreQuiz to </script>
$inlineScriptPattern = '/<script>\s*\(function\(\)\s*\{\s*window\.ocmsScoreQuiz\s*=.*?\}\);\s*\}\)\(\);\s*<\/script>/s';

// Replacement with external script
$externalScriptTag = '<script src="/ocms-service/js/ocms-quiz.js"></script>';

// Find all content directories
logInfo("Scanning content directory: $uploadDir");

$contentDirs = glob($uploadDir . '*', GLOB_ONLYDIR);
$totalDirs = count($contentDirs);
logInfo("Found $totalDirs content directories");

$updated = 0;
$skipped = 0;
$noQuiz = 0;
$errors = 0;

foreach ($contentDirs as $contentDir) {
    $contentId = basename($contentDir);

    // Look for index.php or index.html
    $indexFiles = ['index.php', 'index.html'];
    $found = false;

    foreach ($indexFiles as $indexFile) {
        $filePath = $contentDir . '/' . $indexFile;

        if (!file_exists($filePath)) {
            continue;
        }

        $found = true;
        $content = file_get_contents($filePath);

        if ($content === false) {
            logError("Could not read: $filePath");
            $errors++;
            continue;
        }

        // Check if this file has an OCMS quiz section
        if (strpos($content, 'ocms-quiz-section') === false) {
            logVerbose("No quiz found in: $contentId/$indexFile");
            $noQuiz++;
            continue;
        }

        // Check if it already uses external script
        if (strpos($content, 'ocms-quiz.js') !== false) {
            logVerbose("Already using external script: $contentId/$indexFile");
            $skipped++;
            continue;
        }

        // Check if it has inline script
        if (!preg_match($inlineScriptPattern, $content)) {
            logVerbose("Quiz found but no inline script pattern match: $contentId/$indexFile");
            $skipped++;
            continue;
        }

        // Replace inline script with external script reference
        $newContent = preg_replace($inlineScriptPattern, $externalScriptTag, $content);

        if ($newContent === $content) {
            logVerbose("No changes after replacement: $contentId/$indexFile");
            $skipped++;
            continue;
        }

        if ($dryRun) {
            logSuccess("Would update: $contentId/$indexFile");
            $updated++;
        } else {
            if (file_put_contents($filePath, $newContent) === false) {
                logError("Failed to write: $filePath");
                $errors++;
            } else {
                logSuccess("Updated: $contentId/$indexFile");
                $updated++;
            }
        }
    }

    if (!$found) {
        logVerbose("No index file in: $contentId");
    }
}

// Summary
echo "\n=== Summary ===\n";
logInfo("Total content directories: $totalDirs");
logInfo("Files updated: $updated" . ($dryRun ? " (would be)" : ""));
logInfo("Already using external JS: $skipped");
logInfo("No quiz section: $noQuiz");
logInfo("Errors: $errors");
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);
