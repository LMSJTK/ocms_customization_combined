#!/usr/bin/env php
<?php
/**
 * Content Seed Export Script
 *
 * Exports all rows from the content table as a JSON seed file that can be
 * used to seed the database in other environments.
 *
 * Usage:
 *   php scripts/export-content-seed.php [options]
 *
 * Options:
 *   --output=FILE        Output file path (default: database/seeds/content-seed.json)
 *   --pretty             Pretty-print JSON output
 *   --exclude-binary     Exclude binary fields (thumbnail_content, email_attachment_content)
 *   --verbose            Show detailed progress information
 *   --help               Show this help message
 *
 * Examples:
 *   php scripts/export-content-seed.php --pretty --verbose
 *   php scripts/export-content-seed.php --output=/tmp/content-export.json
 *   php scripts/export-content-seed.php --exclude-binary
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, etc.)
 */

set_time_limit(0);
ini_set('memory_limit', '1G');

// Parse command line arguments
$options = getopt('', [
    'output::',
    'pretty',
    'exclude-binary',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Content Seed Export Script

Exports all rows from the content table as a JSON seed file that can be
used to seed the database in other environments.

Usage:
  php scripts/export-content-seed.php [options]

Options:
  --output=FILE        Output file path (default: database/seeds/content-seed.json)
  --pretty             Pretty-print JSON output
  --exclude-binary     Exclude binary fields (thumbnail_content, email_attachment_content)
  --verbose            Show detailed progress information
  --help               Show this help message

Examples:
  php scripts/export-content-seed.php --pretty --verbose
  php scripts/export-content-seed.php --output=/tmp/content-export.json
  php scripts/export-content-seed.php --exclude-binary

Exit codes:
  0 - Success
  1 - Error (configuration, database, etc.)

HELP;
    exit(0);
}

// Configuration from options
$outputFile = $options['output'] ?? __DIR__ . '/../database/seeds/content-seed.json';
$prettyPrint = isset($options['pretty']);
$excludeBinary = isset($options['exclude-binary']);
$verbose = isset($options['verbose']);

// Logging functions
function logInfo($message) {
    global $verbose;
    if ($verbose) {
        echo "[INFO] $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    echo "[SUCCESS] $message\n";
}

/**
 * Normalize language values from legacy PM tables into a clean comma-separated format.
 *
 * Handles these input formats:
 *   "en"                              -> "en"
 *   '["en"]'                          -> "en"
 *   '["pt-br"]'                       -> "pt-br"
 *   '["ptbr"]'                        -> "pt-br"
 *   '["ar","de","en","esla","pt-br"]' -> "ar,de,en,es-la,pt-br"
 *
 * Rules:
 *   - JSON arrays are decoded; bare strings are used as-is
 *   - 4-char codes without a hyphen get a hyphen inserted after char 2 (esla -> es-la)
 *   - Output is lowercase, comma-separated, sorted, deduplicated
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
            // Malformed JSON, treat as bare string
            $codes = [$raw];
        }
    } else {
        // Bare string — could be comma-separated already or a single code
        $codes = array_map('trim', explode(',', $raw));
    }

    // Normalize each code
    $normalized = [];
    foreach ($codes as $code) {
        $code = strtolower(trim($code));
        if ($code === '') {
            continue;
        }
        // 4-char codes without a hyphen: insert hyphen after first 2 chars
        // e.g. "esla" -> "es-la", "ptbr" -> "pt-br"
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
    logInfo("Connecting to database...");
    $db = Database::getInstance($config['database']);
    $dbType = $db->getDbType();
    logInfo("Connected to $dbType database");

    // Load domain-to-tag mapping from pm_phishing_domain
    // This allows the import script to look up the correct domain by tag
    $domainToTag = [];
    try {
        $domainRows = $db->fetchAll("SELECT tag, domain FROM pm_phishing_domain WHERE tag IS NOT NULL AND tag != ''");
        foreach ($domainRows as $domainRow) {
            $domain = strtolower($domainRow['domain']);
            $domainToTag[$domain] = $domainRow['tag'];
        }
        logInfo("Loaded " . count($domainToTag) . " domain-to-tag mappings");
    } catch (Exception $e) {
        logInfo("Could not load pm_phishing_domain (tags will not be included): " . $e->getMessage());
    }

    // Load language mappings from PM legacy tables
    // Content IDs map back to legacy PM table IDs
    $languageMapping = [];
    try {
        // pm_education_template has 'languages' field
        $educationRows = $db->fetchAll("SELECT id, languages FROM pm_education_template WHERE languages IS NOT NULL AND languages != ''");
        foreach ($educationRows as $row) {
            $languageMapping[$row['id']] = $row['languages'];
        }
        logInfo("Loaded " . count($educationRows) . " education template languages");

        // pm_email_template has 'language_code' field
        $emailRows = $db->fetchAll("SELECT id, language_code FROM pm_email_template WHERE language_code IS NOT NULL AND language_code != ''");
        foreach ($emailRows as $row) {
            $languageMapping[$row['id']] = $row['language_code'];
        }
        logInfo("Loaded " . count($emailRows) . " email template languages");

        // pm_landing_template has 'language_code' field
        $landingRows = $db->fetchAll("SELECT id, language_code FROM pm_landing_template WHERE language_code IS NOT NULL AND language_code != ''");
        foreach ($landingRows as $row) {
            $languageMapping[$row['id']] = $row['language_code'];
        }
        logInfo("Loaded " . count($landingRows) . " landing template languages");

        logInfo("Total language mappings: " . count($languageMapping));
    } catch (Exception $e) {
        logInfo("Could not load PM tables for languages: " . $e->getMessage());
    }

    // Titles of system templates that should never be exported
    $excludedTitles = [
        'Default Direct Training Template',
        'Default Smart Reinforcement Template',
    ];

    // Fetch all content rows
    logInfo("Fetching content from database...");
    $rows = $db->fetchAll("SELECT * FROM content ORDER BY created_at ASC");

    // Filter out system templates
    $rows = array_filter($rows, function($row) use ($excludedTitles) {
        return !in_array($row['title'] ?? '', $excludedTitles, true);
    });
    $rows = array_values($rows); // re-index

    $count = count($rows);
    logInfo("Found $count content rows (excluding system templates)");

    if ($count === 0) {
        logInfo("No content to export");
        exit(0);
    }

    // Process rows for export
    $exportData = [
        'exported_at' => date('c'),
        'source_db_type' => $dbType,
        'row_count' => $count,
        'content' => []
    ];

    foreach ($rows as $index => $row) {
        logInfo("Processing row " . ($index + 1) . " of $count: {$row['id']}");

        $exportRow = [];
        foreach ($row as $key => $value) {
            // Handle binary fields
            if (in_array($key, ['thumbnail_content', 'email_attachment_content'])) {
                if ($excludeBinary) {
                    $exportRow[$key] = null;
                } elseif ($value !== null) {
                    // For PostgreSQL, bytea comes as a stream or hex string
                    if (is_resource($value)) {
                        $value = stream_get_contents($value);
                    }
                    // Encode binary data as base64
                    $exportRow[$key] = base64_encode($value);
                    $exportRow[$key . '_encoding'] = 'base64';
                } else {
                    $exportRow[$key] = null;
                }
            }
            // Handle boolean fields
            elseif ($key === 'scorable') {
                // Normalize boolean values across database types
                if ($value === 't' || $value === 'true' || $value === true || $value === 1 || $value === '1') {
                    $exportRow[$key] = true;
                } elseif ($value === 'f' || $value === 'false' || $value === false || $value === 0 || $value === '0') {
                    $exportRow[$key] = false;
                } else {
                    $exportRow[$key] = false; // default
                }
            }
            // Handle timestamps
            elseif (in_array($key, ['created_at', 'updated_at'])) {
                // Store as ISO 8601 format
                if ($value !== null) {
                    $exportRow[$key] = date('c', strtotime($value));
                } else {
                    $exportRow[$key] = null;
                }
            }
            else {
                $exportRow[$key] = $value;
            }
        }

        // For email content, look up and store the domain tag
        // This allows the import script to look up the correct domain by tag
        if (($exportRow['content_type'] ?? '') === 'email' && !empty($exportRow['content_domain'])) {
            $contentDomainLower = strtolower($exportRow['content_domain']);
            if (isset($domainToTag[$contentDomainLower])) {
                $exportRow['domain_tag'] = $domainToTag[$contentDomainLower];
                logInfo("  Found domain tag '{$domainToTag[$contentDomainLower]}' for domain '{$exportRow['content_domain']}'");
            } else {
                logInfo("  No domain tag found for domain '{$exportRow['content_domain']}'");
            }
        }

        // Look up language from PM legacy tables if not already set
        if (empty($exportRow['languages']) && !empty($exportRow['id'])) {
            if (isset($languageMapping[$exportRow['id']])) {
                $exportRow['languages'] = $languageMapping[$exportRow['id']];
                logInfo("  Found language '{$languageMapping[$exportRow['id']]}' from PM table");
            }
        }

        // Normalize languages to clean comma-separated format
        if (!empty($exportRow['languages'])) {
            $original = $exportRow['languages'];
            $exportRow['languages'] = normalizeLanguages($original);
            if ($exportRow['languages'] !== $original) {
                logInfo("  Normalized languages: '$original' -> '{$exportRow['languages']}'");
            }
        }

        $exportData['content'][] = $exportRow;
    }

    // Ensure output directory exists
    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        logInfo("Creating output directory: $outputDir");
        if (!mkdir($outputDir, 0755, true)) {
            logError("Failed to create output directory: $outputDir");
            exit(1);
        }
    }

    // Write to file
    logInfo("Writing to $outputFile...");
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($prettyPrint) {
        $jsonFlags |= JSON_PRETTY_PRINT;
    }

    $json = json_encode($exportData, $jsonFlags);
    if ($json === false) {
        logError("Failed to encode data as JSON: " . json_last_error_msg());
        exit(1);
    }

    if (file_put_contents($outputFile, $json) === false) {
        logError("Failed to write to file: $outputFile");
        exit(1);
    }

    $fileSize = filesize($outputFile);
    $fileSizeFormatted = $fileSize > 1048576
        ? round($fileSize / 1048576, 2) . ' MB'
        : round($fileSize / 1024, 2) . ' KB';

    logSuccess("Exported $count content rows to $outputFile ($fileSizeFormatted)");
    exit(0);

} catch (Exception $e) {
    logError($e->getMessage());
    exit(1);
}
