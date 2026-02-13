#!/usr/bin/env php
<?php
/**
 * List Placeholders Script
 *
 * Scans all content files for placeholder spans and lists unique data-basename values
 * along with the content type (email, landing, education/training)
 *
 * Usage: php scripts/list-placeholders.php [--content-dir=DIR] [--verbose] [--format=table|csv|json]
 */

// Parse command line arguments
$options = getopt('', ['content-dir::', 'verbose', 'format::', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/list-placeholders.php [options]\n\n";
    echo "Options:\n";
    echo "  --content-dir=DIR  Override content directory path\n";
    echo "  --verbose          Show detailed progress information\n";
    echo "  --format=FORMAT    Output format: table (default), csv, or json\n";
    echo "  --help             Show this help message\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$format = $options['format'] ?? 'table';

// Try to load config
$contentDir = null;
$db = null;

$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    $oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
    $config = @require $configPath;
    error_reporting($oldErrorReporting);

    if (is_array($config)) {
        $contentDir = $config['content']['upload_dir'] ?? null;

        // Try to connect to database for content type lookup
        try {
            $dbConfig = $config['database'] ?? [];
            if (!empty($dbConfig['host'])) {
                $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']}";
                $db = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (Exception $e) {
            // Database connection failed, will use file-based detection
            if ($verbose) {
                echo "Note: Database connection failed, using file-based content type detection\n";
            }
        }
    }
}

// Command line overrides
if (isset($options['content-dir'])) {
    $contentDir = rtrim($options['content-dir'], '/') . '/';
}

if (!$contentDir) {
    echo "ERROR: Content directory not specified.\n";
    echo "Use --content-dir=/path/to/content/ to specify the content directory.\n";
    exit(1);
}

if (!is_dir($contentDir)) {
    echo "ERROR: Content directory does not exist: $contentDir\n";
    exit(1);
}

echo "=== Placeholder Scanner ===\n";
echo "Content directory: $contentDir\n\n";

// Cache for content types from database
$contentTypeCache = [];

/**
 * Get content type from database or cache
 */
function getContentType($contentId, $db, &$cache) {
    if (isset($cache[$contentId])) {
        return $cache[$contentId];
    }

    if ($db) {
        try {
            $stmt = $db->prepare('SELECT content_type FROM content WHERE id = :id');
            $stmt->execute([':id' => $contentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cache[$contentId] = $row['content_type'];
                return $row['content_type'];
            }
        } catch (Exception $e) {
            // Ignore database errors
        }
    }

    return null;
}

/**
 * Try to detect content type from file contents
 */
function detectContentTypeFromFile($filePath) {
    $content = file_get_contents($filePath);
    if ($content === false) {
        return 'unknown';
    }

    // Look for clues in the content
    $contentLower = strtolower($content);

    // Email indicators
    if (strpos($contentLower, 'email') !== false &&
        (strpos($contentLower, 'subject') !== false || strpos($contentLower, 'from:') !== false)) {
        return 'email';
    }

    // Check for form elements (landing page indicator)
    if (preg_match('/<form[^>]*>/i', $content) &&
        (strpos($contentLower, 'password') !== false || strpos($contentLower, 'login') !== false)) {
        return 'landing';
    }

    // Training/education indicators
    if (strpos($contentLower, 'training') !== false ||
        strpos($contentLower, 'quiz') !== false ||
        strpos($contentLower, 'recordtest') !== false ||
        strpos($contentLower, 'education') !== false) {
        return 'training';
    }

    return 'unknown';
}

/**
 * Extract placeholders from HTML content
 */
function extractPlaceholders($content) {
    $placeholders = [];

    // Pattern to match placeholder spans with data-basename
    $pattern = '/<span[^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*data-basename=["\']([^"\']+)["\'][^>]*>/i';

    // Also try reverse order (data-basename before class)
    $pattern2 = '/<span[^>]*data-basename=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*>/i';

    if (preg_match_all($pattern, $content, $matches)) {
        foreach ($matches[1] as $basename) {
            $placeholders[] = trim($basename);
        }
    }

    if (preg_match_all($pattern2, $content, $matches)) {
        foreach ($matches[1] as $basename) {
            $placeholders[] = trim($basename);
        }
    }

    return array_unique($placeholders);
}

/**
 * Find all HTML/PHP files in a directory
 */
function findContentFiles($directory) {
    $files = [];
    $extensions = ['html', 'htm', 'php'];

    if (!is_dir($directory)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions)) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// Data structures for results
$placeholdersByType = [
    'email' => [],
    'landing' => [],
    'training' => [],
    'unknown' => []
];

$placeholderDetails = []; // basename => [types, count, content_ids]
$stats = [
    'content_scanned' => 0,
    'files_scanned' => 0,
    'placeholders_found' => 0
];

// Scan content directories
$dirs = scandir($contentDir);
$totalDirs = 0;

foreach ($dirs as $dir) {
    if ($dir === '.' || $dir === '..') continue;
    if (!is_dir($contentDir . $dir)) continue;
    $totalDirs++;
}

echo "Scanning $totalDirs content directories...\n\n";

$processed = 0;
foreach ($dirs as $dir) {
    if ($dir === '.' || $dir === '..') continue;

    $contentPath = $contentDir . $dir;
    if (!is_dir($contentPath)) continue;

    $contentId = $dir;
    $processed++;
    $stats['content_scanned']++;

    // Progress indicator
    if (!$verbose && $processed % 100 === 0) {
        echo "Progress: $processed / $totalDirs\n";
    }

    // Get content type
    $contentType = getContentType($contentId, $db, $contentTypeCache);

    // Find and scan files
    $files = findContentFiles($contentPath);

    foreach ($files as $filePath) {
        $stats['files_scanned']++;

        $content = file_get_contents($filePath);
        if ($content === false) continue;

        // If we don't have content type from DB, try to detect from file
        if (!$contentType) {
            $contentType = detectContentTypeFromFile($filePath);
        }

        $placeholders = extractPlaceholders($content);

        foreach ($placeholders as $basename) {
            $stats['placeholders_found']++;

            // Normalize content type
            $typeKey = $contentType ?: 'unknown';
            if ($typeKey === 'education') $typeKey = 'training';
            if (!isset($placeholdersByType[$typeKey])) {
                $typeKey = 'unknown';
            }

            // Track by type
            if (!in_array($basename, $placeholdersByType[$typeKey])) {
                $placeholdersByType[$typeKey][] = $basename;
            }

            // Track details
            if (!isset($placeholderDetails[$basename])) {
                $placeholderDetails[$basename] = [
                    'types' => [],
                    'count' => 0,
                    'content_ids' => []
                ];
            }

            $placeholderDetails[$basename]['count']++;

            if (!in_array($typeKey, $placeholderDetails[$basename]['types'])) {
                $placeholderDetails[$basename]['types'][] = $typeKey;
            }

            if (!in_array($contentId, $placeholderDetails[$basename]['content_ids'])) {
                $placeholderDetails[$basename]['content_ids'][] = $contentId;
            }

            if ($verbose) {
                echo "  Found: $basename in $contentId ($typeKey)\n";
            }
        }
    }
}

// Sort placeholders alphabetically
foreach ($placeholdersByType as &$list) {
    sort($list);
}
ksort($placeholderDetails);

// Output results
echo "\n=== Results ===\n\n";

if ($format === 'json') {
    echo json_encode([
        'stats' => $stats,
        'by_type' => $placeholdersByType,
        'details' => $placeholderDetails
    ], JSON_PRETTY_PRINT) . "\n";
} elseif ($format === 'csv') {
    echo "Placeholder,Types,Count,Content Count\n";
    foreach ($placeholderDetails as $basename => $details) {
        $types = implode('|', $details['types']);
        $count = $details['count'];
        $contentCount = count($details['content_ids']);
        echo "\"$basename\",\"$types\",$count,$contentCount\n";
    }
} else {
    // Table format (default)
    echo "--- Summary by Content Type ---\n\n";

    foreach ($placeholdersByType as $type => $placeholders) {
        if (empty($placeholders)) continue;

        echo strtoupper($type) . " (" . count($placeholders) . " unique placeholders):\n";
        foreach ($placeholders as $p) {
            echo "  - $p\n";
        }
        echo "\n";
    }

    echo "--- All Placeholders with Details ---\n\n";
    echo sprintf("%-35s %-20s %8s %8s\n", "PLACEHOLDER", "TYPES", "USES", "CONTENT");
    echo str_repeat("-", 75) . "\n";

    foreach ($placeholderDetails as $basename => $details) {
        $types = implode(', ', $details['types']);
        $count = $details['count'];
        $contentCount = count($details['content_ids']);

        // Truncate long names
        $displayName = strlen($basename) > 33 ? substr($basename, 0, 30) . '...' : $basename;

        echo sprintf("%-35s %-20s %8d %8d\n", $displayName, $types, $count, $contentCount);
    }

    echo "\n--- Statistics ---\n";
    echo "Content directories scanned: {$stats['content_scanned']}\n";
    echo "Files scanned: {$stats['files_scanned']}\n";
    echo "Total placeholder occurrences: {$stats['placeholders_found']}\n";
    echo "Unique placeholders: " . count($placeholderDetails) . "\n";
}

echo "\nDone!\n";
