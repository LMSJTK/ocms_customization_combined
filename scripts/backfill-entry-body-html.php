#!/usr/bin/env php
<?php
/**
 * Backfill entry_body_html Script
 *
 * Populates the entry_body_html column for existing content that was uploaded
 * before this column was added. Reads HTML from S3 or local filesystem, strips
 * upload-time injections (base tag, tracking script, PHP preamble), converts
 * relative URLs to absolute, and stores the result in the DB.
 *
 * This eliminates the S3 network round-trip on every content launch by making
 * the entry point HTML available directly from the content row.
 *
 * Does NOT modify or remove existing files on S3 or local filesystem.
 *
 * Usage:
 *   php scripts/backfill-entry-body-html.php [options]
 *
 * Options:
 *   --dry-run    Show what would be updated without making changes
 *   --verbose    Show detailed progress information
 *   --quiet      Only show errors and final summary
 *   --force      Re-process content that already has entry_body_html
 *   --id=UUID    Process only a specific content ID
 *   --help       Show this help message
 *
 * Examples:
 *   php scripts/backfill-entry-body-html.php --dry-run --verbose
 *   php scripts/backfill-entry-body-html.php --verbose
 *   php scripts/backfill-entry-body-html.php --id=abc123-def4-5678-9012-abcdef123456
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
    'force',
    'id:',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Backfill entry_body_html Script

Populates the entry_body_html column for existing content by reading the
entry point HTML from S3 or local filesystem, cleaning upload-time injections,
converting relative URLs to absolute, and storing in the DB.

Does NOT modify or remove existing files.

Usage:
  php scripts/backfill-entry-body-html.php [options]

Options:
  --dry-run    Show what would be updated without making changes
  --verbose    Show detailed progress information
  --quiet      Only show errors and final summary
  --force      Re-process content that already has entry_body_html
  --id=UUID    Process only a specific content ID
  --help       Show this help message

Examples:
  php scripts/backfill-entry-body-html.php --dry-run --verbose
  php scripts/backfill-entry-body-html.php --verbose
  php scripts/backfill-entry-body-html.php --id=abc123-def4-5678-9012-abcdef123456

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);
$force = isset($options['force']);
$specificId = $options['id'] ?? null;

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
 * Strip upload-time injections from HTML and extract the base URL.
 *
 * Removes:
 * - PHP preamble (<?php $trackingLinkId = ... ?>)
 * - <base href="..."> tag (extracting its URL for later use)
 * - Tracking script/meta tags injected by ContentProcessor
 *
 * @param string $html The HTML content (may start with PHP preamble)
 * @return array ['html' => cleaned HTML, 'baseUrl' => extracted base URL or null]
 */
function stripUploadInjections($html) {
    $baseUrl = null;

    // 1. Strip PHP preamble (local files start with this)
    $html = preg_replace(
        '/^<\?php\s+\$trackingLinkId\s*=\s*\$_GET\[.+?\?>\s*/s',
        '',
        $html
    );

    // 2. Extract and strip <base href="..."> tag
    if (preg_match('/<base\s+href="([^"]+)"[^>]*>/i', $html, $baseMatch)) {
        $baseUrl = rtrim($baseMatch[1], '/');
        $html = preg_replace('/<base\s+href="[^"]*"[^>]*>\s*/i', '', $html, 1);
    }

    // 3. Strip tracking meta tags and script injected at upload time
    // Local format: <meta name="ocms-tracking-id" ...><meta name="ocms-api-base" ...><script src="...ocms-tracker.js"></script>
    $html = preg_replace(
        '/<meta\s+name="ocms-tracking-id"\s+content="[^"]*"[^>]*>\s*/i',
        '',
        $html
    );
    $html = preg_replace(
        '/<meta\s+name="ocms-api-base"\s+content="[^"]*"[^>]*>\s*/i',
        '',
        $html
    );

    // S3 and local format: <script src="...ocms-tracker.js" ...></script>
    $html = preg_replace(
        '/<script\s+src="[^"]*ocms-tracker\.js"[^>]*><\/script>\s*/i',
        '',
        $html
    );

    return [
        'html' => $html,
        'baseUrl' => $baseUrl
    ];
}

// Header
if (!$quiet) {
    echo "=== Backfill entry_body_html ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n";
    }
    if ($force) {
        echo "*** FORCE MODE - Re-processing existing entry_body_html ***\n";
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

// Load required classes
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/S3Client.php';
require_once __DIR__ . '/../lib/ContentProcessor.php';

// We need a minimal ClaudeAPI stub since ContentProcessor requires it,
// but we only use convertRelativeUrlsToAbsolute() which doesn't need it.
// Load it if available, otherwise create a stub.
$claudeAPIPath = __DIR__ . '/../lib/ClaudeAPI.php';
if (file_exists($claudeAPIPath)) {
    require_once $claudeAPIPath;
}

try {
    $db = Database::getInstance($config['database']);
    logInfo("Connected to database");
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    exit(1);
}

// Initialize S3Client if configured
$s3Client = null;
if (isset($config['s3']) && !empty($config['s3']['bucket'])) {
    $s3Client = new S3Client($config['s3']);
    if ($s3Client->isEnabled()) {
        logInfo("S3 storage enabled");
    } else {
        logInfo("S3 configured but disabled");
    }
} else {
    logInfo("S3 not configured - local storage only");
}

// Extract base path from config
$basePath = rtrim($config['app']['base_url'], '/');

// Initialize ContentProcessor (for convertRelativeUrlsToAbsolute)
$claudeAPI = class_exists('ClaudeAPI') ? new ClaudeAPI($config['claude'] ?? []) : null;
$contentProcessor = new ContentProcessor(
    $db,
    $claudeAPI,
    $config['content']['upload_dir'],
    $basePath,
    $s3Client
);

$uploadDir = $config['content']['upload_dir'];

// Build query - find content that needs backfilling
$whereConditions = ["c.content_type NOT IN ('video', 'email')"];
$params = [];

if (!$force) {
    $whereConditions[] = "(c.entry_body_html IS NULL OR c.entry_body_html = '')";
}

if ($specificId) {
    $whereConditions[] = "c.id = :id";
    $params[':id'] = $specificId;
}

$whereClause = implode(' AND ', $whereConditions);
$query = "SELECT c.id, c.title, c.content_type, c.content_url FROM content c WHERE {$whereClause} ORDER BY c.created_at DESC";

logInfo("Finding content to backfill...");
$contentRows = $db->fetchAll($query, $params);
$totalRows = count($contentRows);
logInfo("Found $totalRows content item(s) to process");

if ($totalRows === 0) {
    logInfo("Nothing to backfill.");
    exit(0);
}

// Process each content item
$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($contentRows as $index => $row) {
    $id = $row['id'];
    $title = $row['title'] ?? '(no title)';
    $type = $row['content_type'] ?? 'unknown';
    $contentUrl = $row['content_url'];
    $progress = ($index + 1) . "/$totalRows";

    if (empty($contentUrl)) {
        $skipped++;
        logVerbose("[$progress] Skipped (no content_url): [$type] " . substr($title, 0, 50));
        continue;
    }

    // Determine if content is on S3 or local
    $isS3 = (bool) preg_match('/^https?:\/\//', $contentUrl);

    try {
        $htmlContent = null;

        if ($isS3) {
            // Fetch from S3
            if (!$s3Client || !$s3Client->isEnabled()) {
                $skipped++;
                logVerbose("[$progress] Skipped (S3 not available): [$type] " . substr($title, 0, 50));
                continue;
            }

            logVerbose("[$progress] Fetching from S3: [$type] " . substr($title, 0, 50));
            $htmlContent = $s3Client->fetchContent($contentUrl);
        } else {
            // Read from local filesystem
            $localPath = $uploadDir . $contentUrl;
            if (!file_exists($localPath)) {
                $skipped++;
                logVerbose("[$progress] Skipped (file not found: $localPath): [$type] " . substr($title, 0, 50));
                continue;
            }

            logVerbose("[$progress] Reading local: [$type] " . substr($title, 0, 50));
            $htmlContent = file_get_contents($localPath);
        }

        if ($htmlContent === false || $htmlContent === null || trim($htmlContent) === '') {
            $skipped++;
            logVerbose("[$progress] Skipped (empty content): [$type] " . substr($title, 0, 50));
            continue;
        }

        // Strip upload-time injections and extract base URL
        $cleaned = stripUploadInjections($htmlContent);
        $cleanedHtml = $cleaned['html'];
        $extractedBaseUrl = $cleaned['baseUrl'];

        // Determine base URL for absolute URL conversion
        $baseUrl = $extractedBaseUrl;
        if (!$baseUrl) {
            // Fallback: construct from content ID
            if ($isS3 && $s3Client) {
                $baseUrl = $s3Client->getContentBaseUrl($id);
            } else {
                $baseUrl = $basePath . '/content/' . $id;
            }
            logVerbose("  No base tag found, using constructed base URL: $baseUrl");
        }

        // Convert relative URLs to absolute
        $entryBodyHtml = $contentProcessor->convertRelativeUrlsToAbsolute($cleanedHtml, $baseUrl);

        $htmlSize = strlen($entryBodyHtml);
        $htmlSizeKB = round($htmlSize / 1024, 1);

        if ($dryRun) {
            $updated++;
            logVerbose("  Would store entry_body_html ({$htmlSizeKB} KB) from " . ($isS3 ? 'S3' : 'local'));
            continue;
        }

        // Store in database
        $db->update('content',
            ['entry_body_html' => $entryBodyHtml],
            'id = :id',
            [':id' => $id]
        );

        $updated++;
        logVerbose("  Stored entry_body_html ({$htmlSizeKB} KB) from " . ($isS3 ? 'S3' : 'local'));

    } catch (Exception $e) {
        $errors++;
        logError("[$progress] Failed [$type] " . substr($title, 0, 50) . " ($id): " . $e->getMessage());
    }
}

// Summary
echo "\n";
$modeLabel = $dryRun ? "(DRY RUN) " : "";
logInfo("{$modeLabel}Backfill complete:");
logInfo("  Total content items: $totalRows");
logInfo("  Updated:  $updated");
logInfo("  Skipped:  $skipped");
if ($errors > 0) {
    logInfo("  Errors:   $errors");
}
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);
