#!/usr/bin/env php
<?php
/**
 * Legacy Content Import Script
 *
 * Periodically imports content from legacy tables (pm_email_template, pm_education_template,
 * pm_landing_template) that hasn't been imported yet into OCMS.
 *
 * This script is designed to be run via cron or manually to sync legacy content.
 *
 * Usage:
 *   php scripts/import-legacy-content.php [options]
 *
 * Options:
 *   --max=N              Maximum number of items to import per content type (default: unlimited)
 *   --type=TYPE          Import only specific type: email, education, landing, or all (default: all)
 *   --dry-run            Show what would be imported without making changes
 *   --scorable-only      Only import scorable education templates (skips non-scorable)
 *   --created-after=DATE Only import templates created on or after this date (YYYY-MM-DD)
 *   --auto-suggest-domain Automatically suggest and apply domains using Claude AI
 *   --batch-size=N       Number of records to fetch per batch from legacy tables (default: 100)
 *   --verbose            Show detailed progress information
 *   --quiet              Only show errors and final summary
 *   --help               Show this help message
 *
 * Examples:
 *   php scripts/import-legacy-content.php --max=50 --verbose
 *   php scripts/import-legacy-content.php --type=email --dry-run
 *   php scripts/import-legacy-content.php --max=100 --auto-suggest-domain
 *   php scripts/import-legacy-content.php --scorable-only --created-after=2022-01-01
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, etc.)
 */

// Increase limits for large imports
set_time_limit(0);
ini_set('memory_limit', '1G');

// Parse command line arguments
$options = getopt('', [
    'max::',
    'type::',
    'dry-run',
    'scorable-only',
    'created-after::',
    'auto-suggest-domain',
    'batch-size::',
    'verbose',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Legacy Content Import Script

Imports content from legacy tables (pm_email_template, pm_education_template,
pm_landing_template) that hasn't been imported yet into OCMS.

Usage:
  php scripts/import-legacy-content.php [options]

Options:
  --max=N              Maximum number of items to import per content type (default: unlimited)
  --type=TYPE          Import only specific type: email, education, landing, or all (default: all)
  --dry-run            Show what would be imported without making changes
  --scorable-only      Only import scorable education templates (skips non-scorable)
  --created-after=DATE Only import templates created on or after this date (YYYY-MM-DD)
  --auto-suggest-domain Automatically suggest and apply domains using Claude AI
  --batch-size=N       Number of records to fetch per batch from legacy tables (default: 100)
  --verbose            Show detailed progress information
  --quiet              Only show errors and final summary
  --help               Show this help message

Examples:
  php scripts/import-legacy-content.php --max=50 --verbose
  php scripts/import-legacy-content.php --type=email --dry-run
  php scripts/import-legacy-content.php --max=100 --auto-suggest-domain
  php scripts/import-legacy-content.php --scorable-only --created-after=2022-01-01

Exit codes:
  0 - Success
  1 - Error (configuration, database, etc.)

HELP;
    exit(0);
}

// Configuration from options
$maxPerType = isset($options['max']) ? (int)$options['max'] : null;
$contentTypeFilter = $options['type'] ?? 'all';
$dryRun = isset($options['dry-run']);
$scorableOnly = isset($options['scorable-only']);
$createdAfter = $options['created-after'] ?? null;
$autoSuggestDomain = isset($options['auto-suggest-domain']);
$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : 100;
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

// Validate content type
$validTypes = ['all', 'email', 'education', 'landing'];
if (!in_array($contentTypeFilter, $validTypes)) {
    fwrite(STDERR, "ERROR: Invalid content type '$contentTypeFilter'. Must be one of: " . implode(', ', $validTypes) . "\n");
    exit(1);
}

// Validate batch size
if ($batchSize < 1 || $batchSize > 500) {
    fwrite(STDERR, "ERROR: Batch size must be between 1 and 500\n");
    exit(1);
}

// Validate --created-after date format
if ($createdAfter !== null) {
    $parsedDate = date_create($createdAfter);
    if (!$parsedDate) {
        fwrite(STDERR, "ERROR: Invalid date format for --created-after: '$createdAfter'. Use YYYY-MM-DD format.\n");
        exit(1);
    }
    $createdAfter = $parsedDate->format('Y-m-d');
}

/**
 * Output helper functions
 */
function logInfo($message) {
    global $quiet;
    if (!$quiet) {
        echo $message . "\n";
    }
}

function logVerbose($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        echo "  " . $message . "\n";
    }
}

function logError($message) {
    fwrite(STDERR, "ERROR: " . $message . "\n");
}

function logWarning($message) {
    global $quiet;
    if (!$quiet) {
        echo "WARNING: " . $message . "\n";
    }
}

// Header
if (!$quiet) {
    echo "=== Legacy Content Import Script ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n";
    }
    if ($scorableOnly) {
        echo "Filter: --scorable-only (only importing scorable education templates)\n";
    }
    if ($createdAfter) {
        echo "Filter: --created-after=$createdAfter (skipping templates created before this date)\n";
    }
    echo "\n";
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    logError("Config file not found: $configPath");
    exit(1);
}

$oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
$config = @require $configPath;
error_reporting($oldErrorReporting);

if (!is_array($config) || !isset($config['database'])) {
    logError("Invalid configuration file");
    exit(1);
}

// Autoload classes
spl_autoload_register(function ($className) {
    $file = '/var/www/html/lib/' . $className . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize database connection
try {
    $dbConfig = $config['database'];
    $dbType = $dbConfig['type'] ?? 'mysql';

    if (empty($dbConfig['host']) || empty($dbConfig['dbname'])) {
        throw new Exception("Database configuration is incomplete. Make sure environment variables are set.");
    }

    if ($dbType === 'pgsql' || $dbType === 'postgres') {
        $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    } else {
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Also initialize Database singleton for ContentProcessor
    $db = Database::getInstance($config['database']);

    logVerbose("Connected to database: {$dbConfig['host']}/{$dbConfig['dbname']} ($dbType)");

} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    exit(1);
}

// Determine table prefix/schema
$schema = $dbConfig['schema'] ?? 'global';
$tablePrefix = ($dbType === 'pgsql') ? $schema . '.' : '';

// Initialize ContentProcessor for placeholder processing
try {
    $claudeAPI = new ClaudeAPI($config['claude']);
    $basePath = rtrim($config['app']['base_url'], '/');

    // Initialize S3Client if configured
    $s3Client = null;
    if (isset($config['s3']) && !empty($config['s3']['bucket'])) {
        $s3Client = new S3Client($config['s3']);
    }

    $contentProcessor = new ContentProcessor($db, $claudeAPI, $config['content']['upload_dir'], $basePath, $s3Client);
    logVerbose("ContentProcessor initialized");
} catch (Exception $e) {
    logError("Failed to initialize ContentProcessor: " . $e->getMessage());
    exit(1);
}

// Initialize SNS and TrackingManager for preview link generation
try {
    $sns = new AWSSNS($config['aws_sns']);
    $trackingManager = new TrackingManager($db, $sns);
    logVerbose("TrackingManager initialized");
} catch (Exception $e) {
    logWarning("TrackingManager initialization failed (preview links may not be generated): " . $e->getMessage());
    $trackingManager = null;
}

// Load phishing domains for email import
$phishingDomains = [];
try {
    $stmt = $pdo->query("SELECT tag, domain FROM {$tablePrefix}pm_phishing_domain WHERE is_hidden = false");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $phishingDomains[$row['tag']] = $row['domain'];
    }
    logVerbose("Loaded " . count($phishingDomains) . " phishing domains");
} catch (Exception $e) {
    logWarning("Could not load phishing domains: " . $e->getMessage());
}

/**
 * Check if HTML contains phishing link indicators
 */
function hasPhishingLinks($html) {
    if (empty($html)) {
        return false;
    }

    if (strpos($html, 'phishing-link-do-not-delete') !== false) {
        return true;
    }

    if (strpos($html, '{{{trainingURL}}}') !== false) {
        return true;
    }

    return false;
}

/**
 * Resolve phishing domain placeholder in from_address
 */
function resolvePhishingDomain($fromAddress, $phishingDomains) {
    // Pattern: {{{ PhishingDomain.domain_for_template('tag') }}}
    if (preg_match("/\{\{\{\s*PhishingDomain\.domain_for_template\(['\"]([^'\"]+)['\"]\)\s*\}\}\}/", $fromAddress, $matches)) {
        $tag = $matches[1];
        if (isset($phishingDomains[$tag])) {
            return str_replace($matches[0], $phishingDomains[$tag], $fromAddress);
        }
        return null; // Domain tag not found
    }
    return $fromAddress;
}

/**
 * Normalize phishing link hrefs to use {{{trainingURL}}} placeholder
 *
 * Legacy emails have phishing links with various href formats:
 * - {{{ TopLevelDomain.domain_for_template('tag') }}}
 * - {{{ PhishingDomain.domain_for_template('tag') }}}
 * - Hardcoded phishing URLs
 *
 * This function finds all <a> tags with class="phishing-link-do-not-delete"
 * and replaces their href attribute with {{{trainingURL}}}
 *
 * @param string $html The email HTML content
 * @return array ['html' => string, 'count' => int] The processed HTML and count of links fixed
 */
function normalizePhishingLinkHrefs($html) {
    $count = 0;

    // Pattern to match <a> tags with class containing "phishing-link-do-not-delete"
    // Captures the full tag so we can replace the href
    $pattern = '/(<a\s[^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*)href\s*=\s*["\'][^"\']*["\']([^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern,
        function($matches) use (&$count) {
            $count++;
            // Reconstruct the tag with {{{trainingURL}}} as the href
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $html
    );

    // Also handle case where href comes before class
    $pattern2 = '/(<a\s[^>]*)href\s*=\s*["\'][^"\']*["\']([^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern2,
        function($matches) use (&$count) {
            // Only increment if we haven't already processed this link
            if (strpos($matches[0], '{{{trainingURL}}}') === false) {
                $count++;
            }
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $processedHtml
    );

    return [
        'html' => $processedHtml,
        'count' => $count
    ];
}

/**
 * Generate a UUID4
 */
function generateUUID4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Download and save thumbnail image
 */
function processThumbnail($contentId, $thumbnailFilename, $config, $contentType) {
    if (empty($thumbnailFilename)) {
        return null;
    }

    // Determine the base URL for thumbnails based on content type
    $baseUrls = [
        'education' => 'https://login.phishme.com/images/education_templates/',
        'email' => 'https://login.phishme.com/images/email_templates/',
        'landing' => 'https://login.phishme.com/images/landing_templates/'
    ];

    $baseUrl = $baseUrls[$contentType] ?? $baseUrls['education'];
    $thumbnailUrl = $baseUrl . $thumbnailFilename;

    // Try to download the thumbnail
    $imageContent = @file_get_contents($thumbnailUrl);
    if ($imageContent === false) {
        return null;
    }

    // Create content directory
    $contentDir = $config['content']['upload_dir'] . $contentId . '/';
    if (!is_dir($contentDir)) {
        mkdir($contentDir, 0755, true);
    }

    // Extract filename extension
    $pathParts = pathinfo($thumbnailFilename);
    $extension = $pathParts['extension'] ?? 'jpg';
    $savedFilename = 'thumbnail.' . $extension;

    // Save to content directory
    $thumbnailPath = $contentDir . $savedFilename;
    if (file_put_contents($thumbnailPath, $imageContent) === false) {
        return null;
    }

    // Validate it's actually an image
    $imageInfo = @getimagesize($thumbnailPath);
    if ($imageInfo === false) {
        @unlink($thumbnailPath);
        return null;
    }

    // Return local URL
    return rtrim($config['app']['external_url'], '/') . '/content/' . $contentId . '/' . $savedFilename;
}

/**
 * Generate preview link for content
 */
function generatePreviewLink($contentId, $db, $trackingManager, $config) {
    if (!$trackingManager) {
        return null;
    }

    try {
        $trainingId = generateUUID4();
        $trainingTrackingId = generateUUID4();
        $uniqueTrackingId = generateUUID4();

        // Get content title
        $content = $db->fetchOne(
            'SELECT title FROM content WHERE id = :id',
            [':id' => $contentId]
        );

        // Create training record for preview
        $trainingRecord = [
            'id' => $trainingId,
            'company_id' => 'system',
            'name' => 'Preview: ' . ($content['title'] ?? 'Content'),
            'description' => 'Auto-generated training for content preview',
            'training_type' => 'preview',
            'training_content_id' => $contentId,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $tablePrefix = ($db->getDbType() === 'pgsql') ? 'global.' : '';
        $db->insert($tablePrefix . 'training', $trainingRecord);

        // Create training_tracking record
        $trainingTrackingData = [
            'id' => $trainingTrackingId,
            'training_id' => $trainingId,
            'recipient_id' => 'preview',
            'unique_tracking_id' => $uniqueTrackingId,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $db->insert($tablePrefix . 'training_tracking', $trainingTrackingData);

        // Build preview URL
        $contentIdNoDash = str_replace('-', '', $contentId);
        $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
        $previewUrl = rtrim($config['app']['external_url'], '/') . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

        // Update content with preview link
        $db->update('content',
            ['content_preview' => $previewUrl],
            'id = :id',
            [':id' => $contentId]
        );

        return $previewUrl;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Suggest and apply domain for content using Claude AI
 */
function suggestDomainForContent($contentId, $db, $claudeAPI, $phishingDomains) {
    try {
        $tablePrefix = ($db->getDbType() === 'pgsql') ? 'global.' : '';

        $content = $db->fetchOne(
            "SELECT id, title, description, content_domain FROM {$tablePrefix}content WHERE id = :id",
            [':id' => $contentId]
        );

        if (!$content || !empty($content['content_domain'])) {
            return null;
        }

        // Convert phishing domains to format expected by ClaudeAPI
        $domains = [];
        foreach ($phishingDomains as $tag => $domain) {
            $domains[] = ['tag' => $tag, 'domain' => $domain];
        }

        if (empty($domains)) {
            return null;
        }

        // Fetch tags for this content
        $tags = $db->fetchAll(
            "SELECT tag_name FROM {$tablePrefix}content_tags WHERE content_id = :id",
            [':id' => $contentId]
        );
        $tagNames = array_column($tags, 'tag_name');

        // Get suggestion from Claude
        $suggestion = $claudeAPI->suggestDomain(
            $content['title'] ?? '',
            $content['description'] ?? '',
            $tagNames,
            $domains
        );

        // Apply if we got a suggestion
        if (isset($suggestion['domain'])) {
            $db->query(
                "UPDATE {$tablePrefix}content SET content_domain = :domain WHERE id = :id",
                [':domain' => $suggestion['domain'], ':id' => $contentId]
            );
            return $suggestion['domain'];
        }

        return null;

    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get IDs that already exist in OCMS content table
 */
function getExistingIds($pdo, $ids, $tablePrefix) {
    if (empty($ids)) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $placeholders[] = ':id' . $index;
        $params[':id' . $index] = strtolower($id);
    }

    $query = "SELECT id FROM {$tablePrefix}content WHERE id IN (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['id']] = true;
    }

    return $existing;
}

/**
 * Detect if HTML content is scorable by checking for RecordTest() calls
 * This mirrors the logic in ContentProcessor::detectScorableContent() but
 * operates on a raw HTML string rather than files in a directory.
 */
function htmlHasRecordTestCalls($html) {
    if (empty($html) || stripos($html, 'RecordTest') === false) {
        return false;
    }

    $callPattern = '/RecordTest\s*\(/i';

    $excludePatterns = [
        '/window\.RecordTest\s*=/i',           // Tracker definition
        '/function\s+RecordTest\s*\(/i',       // Function definition
        '/typeof\s+RecordTest/i',              // Type check
    ];

    $offset = 0;
    while (($pos = stripos($html, 'RecordTest', $offset)) !== false) {
        $start = max(0, $pos - 50);
        $length = 100 + strlen('RecordTest');
        $contextSnippet = substr($html, $start, $length);

        // Check if this occurrence is a definition/exclusion
        $isExcluded = false;
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $contextSnippet)) {
                $isExcluded = true;
                break;
            }
        }

        if (!$isExcluded && preg_match($callPattern, $contextSnippet)) {
            return true;
        }

        $offset = $pos + 1;
    }

    return false;
}

/**
 * Import results tracking
 */
$results = [
    'education' => ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []],
    'email' => ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []],
    'landing' => ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []]
];

/**
 * Import education templates
 */
function importEducationTemplates() {
    global $pdo, $db, $tablePrefix, $contentProcessor, $trackingManager, $claudeAPI, $config, $phishingDomains;
    global $batchSize, $maxPerType, $dryRun, $autoSuggestDomain, $scorableOnly, $createdAfter, $results;

    logInfo("--- Importing Education Templates ---");

    $offset = 0;
    $imported = 0;
    $target = $maxPerType;

    while ($target === null || $imported < $target) {
        // Fetch batch from legacy table
        $whereExtra = '';
        $extraParams = [];

        if ($createdAfter !== null) {
            $whereExtra .= ' AND created_at >= :created_after';
            $extraParams[':created_after'] = $createdAfter;
        }

        $query = "
            SELECT id, template_name, html, description, smallimage, content_preview_thumbnail
            FROM {$tablePrefix}pm_education_template
            WHERE is_active = true AND deleted_at IS NULL{$whereExtra}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($extraParams as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            logVerbose("No more education templates to process");
            break;
        }

        // Check which IDs already exist
        $ids = array_column($rows, 'id');
        $existingIds = getExistingIds($pdo, $ids, $tablePrefix);

        foreach ($rows as $row) {
            if ($target !== null && $imported >= $target) {
                break;
            }

            $results['education']['processed']++;
            $id = strtolower($row['id']);

            // Skip if already exists
            if (isset($existingIds[$id])) {
                $results['education']['skipped']++;
                logVerbose("Skipped (exists): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no HTML content
            if (empty($row['html'])) {
                $results['education']['skipped']++;
                logVerbose("Skipped (no HTML): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip non-scorable content when --scorable-only is set
            // Scorable = contains RecordTest() calls (same detection as ContentProcessor)
            if ($scorableOnly && !htmlHasRecordTestCalls($row['html'])) {
                $results['education']['skipped']++;
                logVerbose("Skipped (not scorable): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no title
            if (empty(trim($row['template_name'] ?? ''))) {
                $results['education']['skipped']++;
                logVerbose("Skipped (no title): ID " . $id);
                continue;
            }

            // Process placeholders
            $placeholderResult = $contentProcessor->processEducationPlaceholders($row['html']);

            if (!$placeholderResult['success']) {
                $results['education']['errors']++;
                $errorMsg = $placeholderResult['error'] ?? 'Invalid placeholders';
                $results['education']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $errorMsg
                ];
                logVerbose("Error (placeholders): " . substr($row['template_name'], 0, 40) . " - " . $errorMsg);
                continue;
            }

            $processedHtml = $placeholderResult['html'];

            if ($dryRun) {
                $imported++;
                $results['education']['imported']++;
                logVerbose("Would import: " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Import the content
            try {
                // Process thumbnail
                $thumbnailUrl = processThumbnail($id, $row['content_preview_thumbnail'] ?? $row['smallimage'], $config, 'education');

                // Insert content record
                $insertData = [
                    'id' => $id,
                    'company_id' => null,
                    'title' => $row['template_name'],
                    'description' => $row['description'] ?? '',
                    'content_type' => 'training',
                    'content_url' => null
                ];

                if ($thumbnailUrl) {
                    $insertData['thumbnail_filename'] = $thumbnailUrl;
                }

                $db->insert('content', $insertData);

                // Process content
                $contentProcessor->processContent($id, 'training', $processedHtml);

                // Generate preview link
                generatePreviewLink($id, $db, $trackingManager, $config);

                // Auto-suggest domain
                if ($autoSuggestDomain) {
                    suggestDomainForContent($id, $db, $claudeAPI, $phishingDomains);
                }

                $imported++;
                $results['education']['imported']++;
                logVerbose("Imported: " . substr($row['template_name'], 0, 50));

            } catch (Exception $e) {
                $results['education']['errors']++;
                $results['education']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $e->getMessage()
                ];
                logVerbose("Error: " . substr($row['template_name'], 0, 40) . " - " . $e->getMessage());
            }
        }

        $offset += $batchSize;

        if (count($rows) < $batchSize) {
            break; // No more records
        }
    }

    logInfo("Education templates: {$results['education']['imported']} imported, {$results['education']['skipped']} skipped, {$results['education']['errors']} errors");
}

/**
 * Import email templates
 */
function importEmailTemplates() {
    global $pdo, $db, $tablePrefix, $contentProcessor, $trackingManager, $claudeAPI, $config, $phishingDomains;
    global $batchSize, $maxPerType, $dryRun, $autoSuggestDomain, $createdAfter, $results;

    logInfo("--- Importing Email Templates ---");

    $offset = 0;
    $imported = 0;
    $target = $maxPerType;

    while ($target === null || $imported < $target) {
        // Fetch batch from legacy table
        $whereExtra = '';
        $extraParams = [];

        if ($createdAfter !== null) {
            $whereExtra .= ' AND created_at >= :created_after';
            $extraParams[':created_after'] = $createdAfter;
        }

        $query = "
            SELECT id, template_name, from_address, subject, body, from_name
            FROM {$tablePrefix}pm_email_template
            WHERE is_active = true AND deleted_at IS NULL{$whereExtra}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($extraParams as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            logVerbose("No more email templates to process");
            break;
        }

        // Check which IDs already exist
        $ids = array_column($rows, 'id');
        $existingIds = getExistingIds($pdo, $ids, $tablePrefix);

        foreach ($rows as $row) {
            if ($target !== null && $imported >= $target) {
                break;
            }

            $results['email']['processed']++;
            $id = strtolower($row['id']);

            // Skip if already exists
            if (isset($existingIds[$id])) {
                $results['email']['skipped']++;
                logVerbose("Skipped (exists): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no body content
            if (empty($row['body'])) {
                $results['email']['skipped']++;
                logVerbose("Skipped (no body): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no title
            if (empty(trim($row['template_name'] ?? ''))) {
                $results['email']['skipped']++;
                logVerbose("Skipped (no title): ID " . $id);
                continue;
            }

            $emailHtml = $row['body'];
            $fromName = $row['from_name'] ?? null;

            // Process placeholders
            $placeholderResult = $contentProcessor->processEmailPlaceholders($emailHtml, $fromName);

            if (!$placeholderResult['success']) {
                $results['email']['errors']++;
                $errorMsg = $placeholderResult['error'] ?? 'Invalid placeholders';
                $results['email']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $errorMsg
                ];
                logVerbose("Error (placeholders): " . substr($row['template_name'], 0, 40) . " - " . $errorMsg);
                continue;
            }

            $emailHtml = $placeholderResult['html'];

            // Normalize phishing link hrefs to use {{{trainingURL}}} placeholder
            // This converts legacy hrefs (with TopLevelDomain/PhishingDomain placeholders or hardcoded URLs)
            // to the standard {{{trainingURL}}} format that gets resolved at email send time
            $normalizeResult = normalizePhishingLinkHrefs($emailHtml);
            $emailHtml = $normalizeResult['html'];
            if ($normalizeResult['count'] > 0) {
                logVerbose("Normalized " . $normalizeResult['count'] . " phishing link href(s) to {{{trainingURL}}}");
            }

            // Check for phishing links
            if (!hasPhishingLinks($emailHtml)) {
                $results['email']['skipped']++;
                logVerbose("Skipped (no phishing links): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Resolve phishing domain in from_address
            $fromAddress = resolvePhishingDomain($row['from_address'] ?? '', $phishingDomains);
            if ($fromAddress === null) {
                $results['email']['errors']++;
                $results['email']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => 'Could not resolve phishing domain in from_address'
                ];
                logVerbose("Error (domain): " . substr($row['template_name'], 0, 40) . " - Unknown phishing domain tag");
                continue;
            }

            // Extract domain from resolved from_address for content_domain
            $contentDomain = null;
            if (preg_match('/@([a-zA-Z0-9.-]+)/', $fromAddress, $matches)) {
                $contentDomain = $matches[1];
            }

            if ($dryRun) {
                $imported++;
                $results['email']['imported']++;
                logVerbose("Would import: " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Import the content
            try {
                // Insert content record
                $insertData = [
                    'id' => $id,
                    'company_id' => null,
                    'title' => $row['template_name'],
                    'description' => '',
                    'content_type' => 'email',
                    'email_subject' => $row['subject'] ?? '',
                    'email_from_address' => $fromAddress,
                    'email_body_html' => $emailHtml,
                    'content_url' => null,
                    'thumbnail_filename' => '/images/email_default.png'
                ];

                if ($contentDomain) {
                    $insertData['content_domain'] = $contentDomain;
                }

                $db->insert('content', $insertData);

                // Save HTML to temp file and process
                $tempPath = $config['content']['upload_dir'] . 'temp_' . $id . '.html';
                file_put_contents($tempPath, $emailHtml);
                $contentProcessor->processContent($id, 'email', $tempPath);
                @unlink($tempPath);

                // Generate preview link
                generatePreviewLink($id, $db, $trackingManager, $config);

                // Auto-suggest domain if not already set
                if ($autoSuggestDomain && !$contentDomain) {
                    suggestDomainForContent($id, $db, $claudeAPI, $phishingDomains);
                }

                $imported++;
                $results['email']['imported']++;
                logVerbose("Imported: " . substr($row['template_name'], 0, 50));

            } catch (Exception $e) {
                $results['email']['errors']++;
                $results['email']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $e->getMessage()
                ];
                logVerbose("Error: " . substr($row['template_name'], 0, 40) . " - " . $e->getMessage());
            }
        }

        $offset += $batchSize;

        if (count($rows) < $batchSize) {
            break; // No more records
        }
    }

    logInfo("Email templates: {$results['email']['imported']} imported, {$results['email']['skipped']} skipped, {$results['email']['errors']} errors");
}

/**
 * Import landing page templates
 */
function importLandingTemplates() {
    global $pdo, $db, $tablePrefix, $contentProcessor, $trackingManager, $claudeAPI, $config, $phishingDomains;
    global $batchSize, $maxPerType, $dryRun, $autoSuggestDomain, $createdAfter, $results;

    logInfo("--- Importing Landing Page Templates ---");

    $offset = 0;
    $imported = 0;
    $target = $maxPerType;

    while ($target === null || $imported < $target) {
        // Fetch batch from legacy table
        $whereExtra = '';
        $extraParams = [];

        if ($createdAfter !== null) {
            $whereExtra .= ' AND created_at >= :created_after';
            $extraParams[':created_after'] = $createdAfter;
        }

        $query = "
            SELECT id, template_name, html, description, smallimage
            FROM {$tablePrefix}pm_landing_template
            WHERE is_active = true AND deleted_at IS NULL{$whereExtra}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($extraParams as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            logVerbose("No more landing templates to process");
            break;
        }

        // Check which IDs already exist
        $ids = array_column($rows, 'id');
        $existingIds = getExistingIds($pdo, $ids, $tablePrefix);

        foreach ($rows as $row) {
            if ($target !== null && $imported >= $target) {
                break;
            }

            $results['landing']['processed']++;
            $id = strtolower($row['id']);

            // Skip if already exists
            if (isset($existingIds[$id])) {
                $results['landing']['skipped']++;
                logVerbose("Skipped (exists): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no HTML content
            if (empty($row['html'])) {
                $results['landing']['skipped']++;
                logVerbose("Skipped (no HTML): " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Skip if no title
            if (empty(trim($row['template_name'] ?? ''))) {
                $results['landing']['skipped']++;
                logVerbose("Skipped (no title): ID " . $id);
                continue;
            }

            // Process placeholders
            $placeholderResult = $contentProcessor->processLandingPlaceholders($row['html']);

            if (!$placeholderResult['success']) {
                $results['landing']['errors']++;
                $errorMsg = $placeholderResult['error'] ?? 'Invalid placeholders';
                $results['landing']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $errorMsg
                ];
                logVerbose("Error (placeholders): " . substr($row['template_name'], 0, 40) . " - " . $errorMsg);
                continue;
            }

            $processedHtml = $placeholderResult['html'];

            if ($dryRun) {
                $imported++;
                $results['landing']['imported']++;
                logVerbose("Would import: " . substr($row['template_name'], 0, 50));
                continue;
            }

            // Import the content
            try {
                // Process thumbnail
                $thumbnailUrl = processThumbnail($id, $row['smallimage'], $config, 'landing');

                // Insert content record
                $insertData = [
                    'id' => $id,
                    'company_id' => null,
                    'title' => $row['template_name'],
                    'description' => $row['description'] ?? '',
                    'content_type' => 'landing',
                    'content_url' => null,
                    // Use default landing page thumbnail if none downloaded
                    'thumbnail_filename' => $thumbnailUrl ?? '/images/landing_default.png'
                ];

                $db->insert('content', $insertData);

                // Process content
                $contentProcessor->processContent($id, 'landing', $processedHtml);

                // Generate preview link
                generatePreviewLink($id, $db, $trackingManager, $config);

                // Auto-suggest domain
                if ($autoSuggestDomain) {
                    suggestDomainForContent($id, $db, $claudeAPI, $phishingDomains);
                }

                $imported++;
                $results['landing']['imported']++;
                logVerbose("Imported: " . substr($row['template_name'], 0, 50));

            } catch (Exception $e) {
                $results['landing']['errors']++;
                $results['landing']['error_details'][] = [
                    'id' => $id,
                    'title' => $row['template_name'],
                    'error' => $e->getMessage()
                ];
                logVerbose("Error: " . substr($row['template_name'], 0, 40) . " - " . $e->getMessage());
            }
        }

        $offset += $batchSize;

        if (count($rows) < $batchSize) {
            break; // No more records
        }
    }

    logInfo("Landing templates: {$results['landing']['imported']} imported, {$results['landing']['skipped']} skipped, {$results['landing']['errors']} errors");
}

// Run imports based on content type filter
try {
    if ($contentTypeFilter === 'all' || $contentTypeFilter === 'education') {
        importEducationTemplates();
        echo "\n";
    }

    if ($contentTypeFilter === 'all' || $contentTypeFilter === 'email') {
        importEmailTemplates();
        echo "\n";
    }

    if ($contentTypeFilter === 'all' || $contentTypeFilter === 'landing') {
        importLandingTemplates();
        echo "\n";
    }
} catch (Exception $e) {
    logError("Import failed: " . $e->getMessage());
    exit(1);
}

// Final summary
if (!$quiet) {
    echo "=== Import Summary ===\n";
    if ($dryRun) {
        echo "*** DRY RUN - No changes were made ***\n";
    }
    echo "\n";

    $totalImported = 0;
    $totalSkipped = 0;
    $totalErrors = 0;

    foreach ($results as $type => $stats) {
        if ($contentTypeFilter !== 'all' && $contentTypeFilter !== $type) {
            continue;
        }
        echo ucfirst($type) . ":\n";
        echo "  Processed: {$stats['processed']}\n";
        echo "  Imported:  {$stats['imported']}\n";
        echo "  Skipped:   {$stats['skipped']}\n";
        echo "  Errors:    {$stats['errors']}\n";

        $totalImported += $stats['imported'];
        $totalSkipped += $stats['skipped'];
        $totalErrors += $stats['errors'];
    }

    echo "\n";
    echo "Total imported: $totalImported\n";
    echo "Total skipped:  $totalSkipped\n";
    echo "Total errors:   $totalErrors\n";
    echo "\n";
    echo "Completed: " . date('Y-m-d H:i:s') . "\n";
}

// Output error details in verbose mode
if ($verbose && !$quiet) {
    $hasErrors = false;
    foreach ($results as $type => $stats) {
        if (!empty($stats['error_details'])) {
            if (!$hasErrors) {
                echo "\n=== Error Details ===\n";
                $hasErrors = true;
            }
            echo "\n$type errors:\n";
            foreach ($stats['error_details'] as $error) {
                echo "  - {$error['id']}: {$error['title']}\n";
                echo "    Error: {$error['error']}\n";
            }
        }
    }
}

exit(0);
