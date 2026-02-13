#!/usr/bin/env php
<?php
/**
 * Backfill Shared Images Script
 *
 * Scans existing content for references to /images/ paths (e.g., /images/shared_landing,
 * /images/education_templates) and:
 * 1. Downloads missing assets from login.phishme.com
 * 2. Updates HTML references in content files to use relative paths
 * 3. Updates thumbnail_filename URLs in the database to use correct paths
 *
 * This fixes content imported before the ingestion was updated to handle /images/ paths.
 *
 * Usage:
 *   php scripts/backfill-shared-images.php [options]
 *
 * Options:
 *   --dry-run    Show what would be done without making changes
 *   --verbose    Show detailed progress information
 *   --quiet      Only show errors and final summary
 *   --help       Show this help message
 *
 * Examples:
 *   php scripts/backfill-shared-images.php --dry-run --verbose
 *   php scripts/backfill-shared-images.php --verbose
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
Backfill Shared Images Script

Scans existing content for references to /images/ paths (e.g., /images/shared_landing),
downloads missing assets from login.phishme.com, and updates references.

Handles:
- HTML references in content files (converted to relative paths)
- thumbnail_filename URLs in the database (converted to external URL + /ocms-service path)

Usage:
  php scripts/backfill-shared-images.php [options]

Options:
  --dry-run    Show what would be done without making changes
  --verbose    Show detailed progress information
  --quiet      Only show errors and final summary
  --help       Show this help message

Examples:
  php scripts/backfill-shared-images.php --dry-run --verbose
  php scripts/backfill-shared-images.php --verbose

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

function logWarning($message) {
    echo "[WARNING] $message\n";
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    echo "[SUCCESS] $message\n";
}

/**
 * Extract /images/ paths from HTML content
 * Returns array of paths (with leading slash)
 */
function extractImagePaths($html) {
    $paths = [];

    // Patterns to find /images/ references in HTML
    $patterns = [
        '/src=["\']?(\/images\/[^"\'\s>]+)["\'\s>]/i',
        '/href=["\']?(\/images\/[^"\'\s>]+)["\'\s>]/i',
        '/url\(["\']?(\/images\/[^"\'\)]+)["\'\)]/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $path) {
                // Strip query strings
                $parts = explode('?', $path, 2);
                $pathOnly = $parts[0];
                $paths[$pathOnly] = true;
            }
        }
    }

    return array_keys($paths);
}

/**
 * Extract /images/ path from a full URL
 * Returns the path (e.g., /images/education_templates/foo.gif) or null if not found
 */
function extractImagePathFromUrl($url) {
    if (empty($url)) {
        return null;
    }

    // Look for /images/ in the URL
    if (preg_match('/(\/images\/[^\s\?#]+)/', $url, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Validate that a path is safe (no directory traversal)
 */
function isValidImagePath($path) {
    // Must start with /images/
    if (strpos($path, '/images/') !== 0) {
        return false;
    }

    // Check for directory traversal
    if (strpos($path, '..') !== false) {
        return false;
    }

    // Ensure path only contains safe filesystem characters
    if (!preg_match('/^\/images\/[a-zA-Z0-9\/_.\-\s%()]+$/', $path)) {
        return false;
    }

    return true;
}

/**
 * Download an asset from login.phishme.com
 */
function downloadAsset($remotePath, $localPath, $dryRun) {
    if ($dryRun) {
        return true;
    }

    // Create directory structure
    $localDir = dirname($localPath);
    if (!is_dir($localDir)) {
        if (!mkdir($localDir, 0755, true)) {
            return false;
        }
    }

    $downloadUrl = 'https://login.phishme.com' . $remotePath;
    $escapedUrl = escapeshellarg($downloadUrl);
    $escapedPath = escapeshellarg($localPath);

    $command = "wget --timeout=15 --tries=3 -q -O $escapedPath $escapedUrl 2>&1";
    exec($command, $output, $returnCode);

    return $returnCode === 0;
}

/**
 * Update HTML to change /images/ paths to relative paths (images/)
 * This makes them resolve relative to the content's <base> tag
 */
function updateHtmlReferences($html, $imagePaths) {
    $updatedHtml = $html;

    foreach ($imagePaths as $absolutePath) {
        // Convert /images/... to images/... (remove leading slash)
        $relativePath = ltrim($absolutePath, '/');

        // Replace in src attributes
        $updatedHtml = str_replace('src="' . $absolutePath . '"', 'src="' . $relativePath . '"', $updatedHtml);
        $updatedHtml = str_replace("src='" . $absolutePath . "'", "src='" . $relativePath . "'", $updatedHtml);

        // Replace in href attributes
        $updatedHtml = str_replace('href="' . $absolutePath . '"', 'href="' . $relativePath . '"', $updatedHtml);
        $updatedHtml = str_replace("href='" . $absolutePath . "'", "href='" . $relativePath . "'", $updatedHtml);

        // Replace in CSS url()
        $updatedHtml = str_replace('url(' . $absolutePath . ')', 'url(' . $relativePath . ')', $updatedHtml);
        $updatedHtml = str_replace('url("' . $absolutePath . '")', 'url("' . $relativePath . '")', $updatedHtml);
        $updatedHtml = str_replace("url('" . $absolutePath . "')", "url('" . $relativePath . "')", $updatedHtml);
    }

    return $updatedHtml;
}

// Header
if (!$quiet) {
    echo "=== Backfill Shared Images ===\n";
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

// Directories
$contentDir = $config['content']['upload_dir'] ?? '/var/www/html/content/';
$sharedImagesDir = '/var/www/html/images/';

// Get external URL for thumbnail URL updates
$externalUrl = $config['messagehub']['endpoints']['ui_external'] ?? '';
if (empty($externalUrl)) {
    $externalUrl = getenv('MESSAGEHUB_ENDPOINTS_UI_EXTERNAL') ?: '';
}

if (!is_dir($contentDir)) {
    logError("Content directory not found: $contentDir");
    exit(1);
}

// Create shared images directory if needed
if (!is_dir($sharedImagesDir)) {
    if (!$dryRun) {
        mkdir($sharedImagesDir, 0755, true);
    }
}

logInfo("Content directory: $contentDir");
logInfo("Shared images directory: $sharedImagesDir");
if (!empty($externalUrl)) {
    logInfo("External URL: $externalUrl");
}

// Track statistics
$rowsWithImages = 0;
$totalAssets = 0;
$assetsDownloaded = 0;
$assetsFailed = 0;
$assetsAlreadyExist = 0;
$filesUpdated = 0;
$dbRowsUpdated = 0;
$thumbnailsFixed = 0;

// =========================================================================
// PART 1: Fix thumbnail_filename URLs in the database
// =========================================================================
logInfo("\n--- Part 1: Fixing thumbnail_filename URLs ---");

$contentRows = $db->fetchAll("SELECT id, title, content_type, thumbnail_filename FROM content WHERE thumbnail_filename IS NOT NULL");
logInfo("Found " . count($contentRows) . " content rows with thumbnails");

foreach ($contentRows as $row) {
    $contentId = $row['id'];
    $title = $row['title'] ?? '(no title)';
    $contentType = $row['content_type'] ?? 'unknown';
    $thumbnailUrl = $row['thumbnail_filename'];

    // Extract /images/ path from the thumbnail URL
    $imagePath = extractImagePathFromUrl($thumbnailUrl);

    if ($imagePath === null) {
        continue; // Not an /images/ path
    }

    if (!isValidImagePath($imagePath)) {
        logWarning("Invalid thumbnail image path in $contentId: $imagePath");
        continue;
    }

    $totalAssets++;

    // Email content uses shared images directory (they all use the same default thumbnail)
    // Education/landing content uses content-specific directories
    $isEmail = ($contentType === 'email');

    if ($isEmail) {
        // Emails: Store in shared /images/ directory
        $localPath = $sharedImagesDir . ltrim($imagePath, '/images/');
        $newUrlPath = '/ocms-service' . $imagePath;
    } else {
        // Education/landing: Store in content-specific directory
        $contentPath = $contentDir . $contentId;
        $localPath = $contentPath . $imagePath;
        $newUrlPath = '/ocms-service/content/' . $contentId . $imagePath;
    }

    // Download if missing
    if (file_exists($localPath)) {
        $assetsAlreadyExist++;
        logVerbose("Thumbnail asset exists: $imagePath" . ($isEmail ? " (shared)" : " (content-specific)"));
    } else {
        $modeLabel = $dryRun ? "[DRY RUN] " : "";

        if (downloadAsset($imagePath, $localPath, $dryRun)) {
            $assetsDownloaded++;
            logVerbose("{$modeLabel}Downloaded thumbnail: $imagePath" . ($isEmail ? " (shared)" : " (content-specific)"));
        } else {
            $assetsFailed++;
            logWarning("{$modeLabel}Failed to download thumbnail: $imagePath");
        }
    }

    // Update the URL to use external URL + appropriate path
    if (!empty($externalUrl)) {
        $newUrl = $externalUrl . $newUrlPath;

        if ($thumbnailUrl !== $newUrl) {
            $modeLabel = $dryRun ? "[DRY RUN] " : "";

            if (!$dryRun) {
                try {
                    $db->query(
                        "UPDATE content SET thumbnail_filename = :url WHERE id = :id",
                        [':url' => $newUrl, ':id' => $contentId]
                    );
                    $thumbnailsFixed++;
                    logVerbose("{$modeLabel}Fixed thumbnail URL: $title");
                } catch (Exception $e) {
                    logWarning("Failed to update thumbnail for $contentId: " . $e->getMessage());
                }
            } else {
                $thumbnailsFixed++;
                logVerbose("{$modeLabel}Would fix thumbnail URL: $title");
                logVerbose("  From: $thumbnailUrl");
                logVerbose("  To:   $newUrl");
            }
        }
    }
}

// =========================================================================
// PART 2: Fix HTML content references (in files and email_body_html)
// =========================================================================
logInfo("\n--- Part 2: Fixing HTML content references ---");

$contentRows = $db->fetchAll("SELECT id, title, content_type, email_body_html FROM content");
logInfo("Found " . count($contentRows) . " content rows to scan");

foreach ($contentRows as $row) {
    $contentId = $row['id'];
    $title = $row['title'] ?? '(no title)';
    $contentType = $row['content_type'] ?? 'unknown';
    $contentPath = $contentDir . $contentId;

    // Track HTML sources and their paths for this content
    $htmlSources = [];
    $allImagePaths = [];

    // Check email_body_html from database
    if (!empty($row['email_body_html'])) {
        $paths = extractImagePaths($row['email_body_html']);
        if (!empty($paths)) {
            $htmlSources['email_body_html'] = [
                'html' => $row['email_body_html'],
                'path' => null,
                'paths' => $paths
            ];
            foreach ($paths as $path) {
                $allImagePaths[$path] = true;
            }
        }
    }

    // Check on-disk content (index.php or index.html)
    if (is_dir($contentPath)) {
        foreach (['index.php', 'index.html'] as $indexFile) {
            $indexPath = $contentPath . '/' . $indexFile;
            if (file_exists($indexPath)) {
                $html = file_get_contents($indexPath);
                $paths = extractImagePaths($html);
                if (!empty($paths)) {
                    $htmlSources[$indexFile] = [
                        'html' => $html,
                        'path' => $indexPath,
                        'paths' => $paths
                    ];
                    foreach ($paths as $path) {
                        $allImagePaths[$path] = true;
                    }
                }
                break;
            }
        }
    }

    if (empty($allImagePaths)) {
        continue;
    }

    $rowsWithImages++;
    $validImagePaths = [];

    logVerbose("[$contentType] $title - found " . count($allImagePaths) . " /images/ reference(s)");

    // Download missing assets to content-specific directory
    foreach (array_keys($allImagePaths) as $imagePath) {
        $totalAssets++;

        if (!isValidImagePath($imagePath)) {
            logWarning("Invalid image path in $contentId: $imagePath");
            $assetsFailed++;
            continue;
        }

        $validImagePaths[] = $imagePath;
        $localPath = $contentPath . $imagePath;

        if (file_exists($localPath)) {
            $assetsAlreadyExist++;
            logVerbose("  Asset exists: $imagePath");
        } else {
            $modeLabel = $dryRun ? "[DRY RUN] " : "";

            if (downloadAsset($imagePath, $localPath, $dryRun)) {
                $assetsDownloaded++;
                logVerbose("{$modeLabel}Downloaded: $imagePath");
            } else {
                $assetsFailed++;
                logWarning("{$modeLabel}Failed to download: $imagePath");
            }
        }
    }

    // Update HTML references in all sources
    if (!empty($validImagePaths)) {
        foreach ($htmlSources as $sourceName => $sourceData) {
            $originalHtml = $sourceData['html'];
            $updatedHtml = updateHtmlReferences($originalHtml, $sourceData['paths']);

            if ($updatedHtml !== $originalHtml) {
                $modeLabel = $dryRun ? "[DRY RUN] " : "";

                if ($sourceData['path'] === null) {
                    // Update database (email_body_html)
                    if (!$dryRun) {
                        try {
                            $db->query(
                                "UPDATE content SET email_body_html = :html WHERE id = :id",
                                [':html' => $updatedHtml, ':id' => $contentId]
                            );
                            $dbRowsUpdated++;
                            logVerbose("{$modeLabel}Updated email_body_html in database");
                        } catch (Exception $e) {
                            logWarning("Failed to update email_body_html for $contentId: " . $e->getMessage());
                        }
                    } else {
                        $dbRowsUpdated++;
                        logVerbose("{$modeLabel}Would update email_body_html in database");
                    }
                } else {
                    // Update file on disk
                    if (!$dryRun) {
                        if (file_put_contents($sourceData['path'], $updatedHtml) !== false) {
                            $filesUpdated++;
                            logVerbose("{$modeLabel}Updated file: {$sourceData['path']}");
                        } else {
                            logWarning("Failed to write file: {$sourceData['path']}");
                        }
                    } else {
                        $filesUpdated++;
                        logVerbose("{$modeLabel}Would update file: {$sourceData['path']}");
                    }
                }
            }
        }
    }
}

// Summary
echo "\n";
$modeLabel = $dryRun ? "(DRY RUN) " : "";
logInfo("{$modeLabel}Backfill complete:");
logInfo("  Thumbnail URLs fixed: $thumbnailsFixed");
logInfo("  Content rows with /images/ refs: $rowsWithImages");
logInfo("  Assets downloaded: $assetsDownloaded");
logInfo("  Assets already existed: $assetsAlreadyExist");
logInfo("  Assets failed: $assetsFailed");
logInfo("  HTML files updated (on disk): $filesUpdated");
logInfo("  Database rows updated (email_body_html): $dbRowsUpdated");
logInfo("Completed: " . date('Y-m-d H:i:s'));

if ($assetsFailed > 0) {
    logWarning("Some assets failed to download. They may not exist on login.phishme.com or may require authentication.");
}

exit($assetsFailed > 0 ? 1 : 0);
