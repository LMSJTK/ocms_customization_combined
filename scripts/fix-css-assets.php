#!/usr/bin/env php
<?php
/**
 * Fix CSS Assets Script
 *
 * Scans all existing content directories for CSS files containing /system/ references,
 * downloads any missing assets, and updates CSS paths to use /content/{id}/system/
 *
 * Usage: php scripts/fix-css-assets.php [--dry-run] [--verbose] [--content-id=UUID]
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'content-id::', 'content-dir::', 'base-path::', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/fix-css-assets.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run         Show what would be done without making changes\n";
    echo "  --verbose         Show detailed progress information\n";
    echo "  --content-id=ID   Process only a specific content ID\n";
    echo "  --content-dir=DIR Override content directory path\n";
    echo "  --base-path=PATH  Override base path for URLs (default: empty)\n";
    echo "  --help            Show this help message\n\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$specificContentId = $options['content-id'] ?? null;

// Try to load config, but allow overrides
$contentDir = null;
$basePath = '';

$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    // Suppress warnings from missing env vars
    $oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
    $config = @require $configPath;
    error_reporting($oldErrorReporting);

    if (is_array($config)) {
        $contentDir = $config['content']['upload_dir'] ?? null;
        $basePath = $config['app']['base_path'] ?? '';
    }
}

// Command line overrides
if (isset($options['content-dir'])) {
    $contentDir = rtrim($options['content-dir'], '/') . '/';
}
if (isset($options['base-path'])) {
    $basePath = $options['base-path'];
}

// Validate content directory
if (!$contentDir) {
    echo "ERROR: Content directory not specified.\n";
    echo "Use --content-dir=/path/to/content/ to specify the content directory.\n";
    exit(1);
}

$sourceHost = 'https://login.phishme.com';

// Stats
$stats = [
    'content_scanned' => 0,
    'css_files_found' => 0,
    'css_files_updated' => 0,
    'assets_downloaded' => 0,
    'assets_skipped' => 0,
    'assets_failed' => 0,
    'errors' => []
];

echo "=== CSS Asset Fixer ===\n";
echo "Content directory: $contentDir\n";
if ($dryRun) {
    echo "Mode: DRY RUN (no changes will be made)\n";
}
echo "\n";

// Validate content directory exists
if (!is_dir($contentDir)) {
    echo "ERROR: Content directory does not exist: $contentDir\n";
    exit(1);
}

/**
 * Check if a path is valid and safe
 */
function isValidSystemPath($path) {
    // Must start with /system/
    if (strpos($path, '/system/') !== 0) {
        return false;
    }

    // Check for directory traversal
    if (strpos($path, '..') !== false) {
        return false;
    }

    // Validate characters - allow common file path characters
    if (!preg_match('/^\/system\/[a-zA-Z0-9\/_.\-\s%()]+$/', $path)) {
        return false;
    }

    return true;
}

/**
 * Download an asset from the source server
 */
function downloadAsset($url, $localPath, $dryRun, $verbose) {
    if ($dryRun) {
        if ($verbose) {
            echo "  [DRY RUN] Would download: $url\n";
        }
        return true;
    }

    // Create directory if needed
    $dir = dirname($localPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $escapedUrl = escapeshellarg($url);
    $escapedPath = escapeshellarg($localPath);
    $command = "wget --timeout=10 --tries=2 -q -O $escapedPath $escapedUrl 2>&1";

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && file_exists($localPath) && filesize($localPath) > 0) {
        if ($verbose) {
            echo "  Downloaded: $url\n";
        }
        return true;
    }

    // Clean up failed download
    if (file_exists($localPath) && filesize($localPath) === 0) {
        unlink($localPath);
    }

    return false;
}

/**
 * Process a single CSS file
 */
function processCssFile($cssPath, $contentId, $contentDir, $basePath, $sourceHost, $dryRun, $verbose, &$stats) {
    $cssContent = file_get_contents($cssPath);
    if ($cssContent === false) {
        $stats['errors'][] = "Failed to read CSS file: $cssPath";
        return;
    }

    $stats['css_files_found']++;

    // Pattern to find /system/ references in url()
    $pattern = '/url\(\s*["\']?(\/system\/[^"\'\)\s]+)["\']?\s*\)/i';

    if (!preg_match_all($pattern, $cssContent, $matches)) {
        if ($verbose) {
            echo "  No /system/ references in: " . basename($cssPath) . "\n";
        }
        return;
    }

    $assetPaths = array_unique($matches[1]);
    $assetsToDownload = [];

    if ($verbose) {
        echo "  Found " . count($assetPaths) . " /system/ references in: " . basename($cssPath) . "\n";
    }

    foreach ($assetPaths as $fullPath) {
        // Strip query string for file path
        $parts = explode('?', $fullPath, 2);
        $pathOnly = $parts[0];

        if (!isValidSystemPath($pathOnly)) {
            if ($verbose) {
                echo "    Skipping invalid path: $pathOnly\n";
            }
            continue;
        }

        $localPath = $contentDir . $contentId . '/' . ltrim($pathOnly, '/');

        // Check if asset already exists
        if (file_exists($localPath)) {
            $stats['assets_skipped']++;
            if ($verbose) {
                echo "    Already exists: $pathOnly\n";
            }
            continue;
        }

        // Add to download list
        if (!isset($assetsToDownload[$pathOnly])) {
            $assetsToDownload[$pathOnly] = [
                'url' => $sourceHost . $pathOnly,
                'localPath' => $localPath
            ];
        }
    }

    // Download missing assets
    foreach ($assetsToDownload as $pathOnly => $asset) {
        $success = downloadAsset($asset['url'], $asset['localPath'], $dryRun, $verbose);

        if ($success) {
            $stats['assets_downloaded']++;
        } else {
            $stats['assets_failed']++;
            if ($verbose) {
                echo "    FAILED to download: $pathOnly\n";
            }
        }
    }

    // Check if CSS needs path updates (contains raw /system/ without content prefix)
    $contentPathPrefix = $basePath . '/content/' . $contentId;
    $expectedPrefix = $contentPathPrefix . '/system/';

    // Only update if we find /system/ that isn't already prefixed with content path
    if (strpos($cssContent, '/system/') !== false &&
        strpos($cssContent, $expectedPrefix) === false) {

        if ($dryRun) {
            if ($verbose) {
                echo "  [DRY RUN] Would update CSS paths in: " . basename($cssPath) . "\n";
            }
            $stats['css_files_updated']++;
        } else {
            $updatedCss = str_replace('/system/', $contentPathPrefix . '/system/', $cssContent);
            file_put_contents($cssPath, $updatedCss);
            $stats['css_files_updated']++;
            if ($verbose) {
                echo "  Updated CSS paths in: " . basename($cssPath) . "\n";
            }
        }
    }
}

/**
 * Find all CSS files in a directory recursively
 */
function findCssFiles($directory) {
    $cssFiles = [];

    if (!is_dir($directory)) {
        return $cssFiles;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
            $cssFiles[] = $file->getPathname();
        }
    }

    return $cssFiles;
}

/**
 * Process a single content directory
 */
function processContentDir($contentPath, $contentId, $contentDir, $basePath, $sourceHost, $dryRun, $verbose, &$stats) {
    $stats['content_scanned']++;

    if ($verbose) {
        echo "Processing content: $contentId\n";
    }

    // Find all CSS files
    $cssFiles = findCssFiles($contentPath);

    if (empty($cssFiles)) {
        if ($verbose) {
            echo "  No CSS files found\n";
        }
        return;
    }

    foreach ($cssFiles as $cssFile) {
        processCssFile($cssFile, $contentId, $contentDir, $basePath, $sourceHost, $dryRun, $verbose, $stats);
    }
}

// Main processing loop
if ($specificContentId) {
    // Process specific content
    $contentPath = $contentDir . $specificContentId;
    if (!is_dir($contentPath)) {
        echo "ERROR: Content directory not found: $contentPath\n";
        exit(1);
    }

    echo "Processing specific content: $specificContentId\n\n";
    processContentDir($contentPath, $specificContentId, $contentDir, $basePath, $sourceHost, $dryRun, $verbose, $stats);
} else {
    // Process all content directories
    $dirs = scandir($contentDir);
    $totalDirs = 0;

    // Count valid content directories
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        if (!is_dir($contentDir . $dir)) continue;
        $totalDirs++;
    }

    echo "Found $totalDirs content directories to scan\n\n";

    $processed = 0;
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        $contentPath = $contentDir . $dir;
        if (!is_dir($contentPath)) continue;

        $processed++;

        // Progress indicator (every 50 items or on verbose)
        if (!$verbose && $processed % 50 === 0) {
            echo "Progress: $processed / $totalDirs\n";
        }

        processContentDir($contentPath, $dir, $contentDir, $basePath, $sourceHost, $dryRun, $verbose, $stats);
    }
}

// Print summary
echo "\n=== Summary ===\n";
echo "Content directories scanned: {$stats['content_scanned']}\n";
echo "CSS files found: {$stats['css_files_found']}\n";
echo "CSS files updated: {$stats['css_files_updated']}\n";
echo "Assets downloaded: {$stats['assets_downloaded']}\n";
echo "Assets already existed: {$stats['assets_skipped']}\n";
echo "Assets failed to download: {$stats['assets_failed']}\n";

if (!empty($stats['errors'])) {
    echo "\nErrors:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($dryRun) {
    echo "\n[DRY RUN] No changes were made. Run without --dry-run to apply changes.\n";
}

echo "\nDone!\n";
