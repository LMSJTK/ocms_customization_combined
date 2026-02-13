#!/usr/bin/env php
<?php
/**
 * Backfill Content Languages Script
 *
 * Looks up languages from legacy PM tables (pm_education_template,
 * pm_email_template, pm_landing_template) and updates content rows with
 * normalized language values. Normalizes formats like '["en"]' or '["ptbr"]'
 * into clean comma-separated format like 'en' or 'pt-br'.
 *
 * Matching is done by ID — content.id corresponds to the legacy template ID.
 *
 * Usage:
 *   php scripts/backfill-content-languages.php [options]
 *
 * Options:
 *   --dry-run    Show what would be updated without making changes
 *   --verbose    Show detailed progress information
 *   --quiet      Only show errors and final summary
 *   --help       Show this help message
 *
 * Examples:
 *   php scripts/backfill-content-languages.php --dry-run --verbose
 *   php scripts/backfill-content-languages.php --verbose
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, etc.)
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
Backfill Content Languages Script

Looks up languages from legacy PM tables and updates ALL content rows with
normalized language values. Normalizes formats like '["en"]' or '["ptbr"]'
into clean comma-separated format like 'en' or 'pt-br'.

Usage:
  php scripts/backfill-content-languages.php [options]

Options:
  --dry-run    Show what would be updated without making changes
  --verbose    Show detailed progress information
  --quiet      Only show errors and final summary
  --help       Show this help message

Examples:
  php scripts/backfill-content-languages.php --dry-run --verbose
  php scripts/backfill-content-languages.php --verbose

Exit codes:
  0 - Success
  1 - Error (configuration, database, etc.)

HELP;
    exit(0);
}

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

/**
 * Normalize language values into a clean comma-separated format.
 *
 * Handles: "en", '["en"]', '["ptbr"]', '["ar","de","en","esla","pt-br"]'
 * Output:  "en", "en",     "pt-br",   "ar,de,en,es-la,pt-br"
 */
function normalizeLanguages($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }

    $raw = trim($raw);

    // Try to decode as JSON array
    $codes = [];
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $codes = $decoded;
        } else {
            $codes = [$raw];
        }
    } else {
        $codes = array_map('trim', explode(',', $raw));
    }

    $normalized = [];
    foreach ($codes as $code) {
        $code = strtolower(trim($code));
        if ($code === '') {
            continue;
        }
        // 4-char codes without a hyphen: insert hyphen after first 2 chars
        if (strlen($code) === 4 && strpos($code, '-') === false) {
            $code = substr($code, 0, 2) . '-' . substr($code, 2);
        }
        $normalized[] = $code;
    }

    if (empty($normalized)) {
        return null;
    }

    sort($normalized);
    $normalized = array_unique($normalized);

    return implode(',', $normalized);
}

// Header
if (!$quiet) {
    echo "=== Backfill Content Languages ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
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

// Step 1: Find all content rows
logInfo("Finding content rows...");
$contentRows = $db->fetchAll("SELECT id, title, content_type, languages FROM content");
$totalRows = count($contentRows);
logInfo("Found $totalRows content rows");

if ($totalRows === 0) {
    logInfo("No content rows found.");
    exit(0);
}

// Step 2: Build language lookup from legacy PM tables
logInfo("Loading language data from legacy PM tables...");

$languageMapping = [];

try {
    // pm_education_template has 'languages' field
    $educationRows = $db->fetchAll("SELECT id, languages FROM pm_education_template WHERE languages IS NOT NULL AND languages != ''");
    foreach ($educationRows as $row) {
        $languageMapping[strtolower($row['id'])] = $row['languages'];
    }
    logInfo("Loaded " . count($educationRows) . " education template languages");
} catch (Exception $e) {
    logInfo("Could not load pm_education_template: " . $e->getMessage());
}

try {
    // pm_email_template has 'language_code' field
    $emailRows = $db->fetchAll("SELECT id, language_code FROM pm_email_template WHERE language_code IS NOT NULL AND language_code != ''");
    foreach ($emailRows as $row) {
        $languageMapping[strtolower($row['id'])] = $row['language_code'];
    }
    logInfo("Loaded " . count($emailRows) . " email template languages");
} catch (Exception $e) {
    logInfo("Could not load pm_email_template: " . $e->getMessage());
}

try {
    // pm_landing_template has 'language_code' field
    $landingRows = $db->fetchAll("SELECT id, language_code FROM pm_landing_template WHERE language_code IS NOT NULL AND language_code != ''");
    foreach ($landingRows as $row) {
        $languageMapping[strtolower($row['id'])] = $row['language_code'];
    }
    logInfo("Loaded " . count($landingRows) . " landing template languages");
} catch (Exception $e) {
    logInfo("Could not load pm_landing_template: " . $e->getMessage());
}

$totalMappings = count($languageMapping);
logInfo("Total language mappings available: $totalMappings");

if ($totalMappings === 0) {
    logInfo("No language data found in legacy tables. Nothing to backfill.");
    exit(0);
}

// Step 3: Update content rows
logInfo("Backfilling and normalizing languages...\n");

$updated = 0;
$noMatch = 0;
$unchanged = 0;
$errors = 0;

foreach ($contentRows as $row) {
    $id = strtolower($row['id']);
    $title = $row['title'] ?? '(no title)';
    $type = $row['content_type'] ?? 'unknown';
    $currentLang = $row['languages'];

    if (!isset($languageMapping[$id])) {
        $noMatch++;
        logVerbose("No match: [$type] " . substr($title, 0, 60) . " ($id)");
        continue;
    }

    $language = normalizeLanguages($languageMapping[$id]);
    if ($language === null) {
        $noMatch++;
        logVerbose("No match: [$type] " . substr($title, 0, 60) . " (language normalized to null)");
        continue;
    }

    // Skip if already has the same normalized value
    if ($currentLang === $language) {
        $unchanged++;
        logVerbose("Unchanged: [$type] " . substr($title, 0, 50) . " = '$language'");
        continue;
    }

    if ($dryRun) {
        $updated++;
        $from = $currentLang ?? 'NULL';
        logVerbose("Would set: [$type] " . substr($title, 0, 50) . " '$from' -> '$language'");
        continue;
    }

    try {
        $db->query(
            "UPDATE content SET languages = :lang WHERE id = :id",
            [':lang' => $language, ':id' => $row['id']]
        );
        $updated++;
        $from = $currentLang ?? 'NULL';
        logVerbose("Updated: [$type] " . substr($title, 0, 50) . " '$from' -> '$language'");
    } catch (Exception $e) {
        $errors++;
        logError("Failed to update $id: " . $e->getMessage());
    }
}

// Summary
echo "\n";
$modeLabel = $dryRun ? "(DRY RUN) " : "";
logInfo("{$modeLabel}Backfill complete:");
logInfo("  Total content rows: $totalRows");
logInfo("  Updated:   $updated");
logInfo("  Unchanged: $unchanged (already normalized)");
logInfo("  No match:  $noMatch (no language found in legacy tables)");
if ($errors > 0) {
    logInfo("  Errors:    $errors");
}
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);
