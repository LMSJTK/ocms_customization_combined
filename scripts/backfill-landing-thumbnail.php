#!/usr/bin/env php
<?php
/**
 * Backfill Landing Page Thumbnails
 *
 * Updates existing landing page content that has no thumbnail to use the
 * default landing page image (/images/landing_default.png).
 *
 * Usage:
 *   php scripts/backfill-landing-thumbnail.php [options]
 *
 * Options:
 *   --dry-run    Show what would be updated without making changes
 *   --force      Update ALL landing pages, even those with existing thumbnails
 *   --verbose    Show detailed progress information
 *   --help       Show this help message
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'force', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Backfill Landing Page Thumbnails

Updates existing landing page content that has no thumbnail to use the
default landing page image (/images/landing_default.png).

Usage:
  php scripts/backfill-landing-thumbnail.php [options]

Options:
  --dry-run    Show what would be updated without making changes
  --force      Update ALL landing pages, even those with existing thumbnails
  --verbose    Show detailed progress information
  --help       Show this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$force = isset($options['force']);
$verbose = isset($options['verbose']);

echo "=== Backfill Landing Page Thumbnails ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
if ($dryRun) {
    echo "*** DRY RUN MODE - No changes will be made ***\n";
}
if ($force) {
    echo "*** FORCE MODE - Updating ALL landing pages ***\n";
}
echo "\n";

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: Config file not found: $configPath\n");
    exit(1);
}

$config = require $configPath;

if (!is_array($config) || !isset($config['database'])) {
    fwrite(STDERR, "ERROR: Invalid configuration file\n");
    exit(1);
}

// Initialize database connection
try {
    $dbConfig = $config['database'];
    $dbType = $dbConfig['type'] ?? 'mysql';

    if ($dbType === 'pgsql' || $dbType === 'postgres') {
        $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
    } else {
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    }

    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($verbose) {
        echo "Connected to database: {$dbConfig['host']}/{$dbConfig['dbname']}\n\n";
    }

} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Determine table prefix/schema
$schema = $dbConfig['schema'] ?? 'global';
$tablePrefix = ($dbType === 'pgsql') ? $schema . '.' : '';

// Default landing page thumbnail
$defaultThumbnail = '/images/landing_default.png';

// Find landing pages to update
if ($force) {
    // Force mode: update ALL landing pages
    $query = "
        SELECT id, title, thumbnail_filename
        FROM {$tablePrefix}content
        WHERE content_type = 'landing'
        ORDER BY title
    ";
} else {
    // Normal mode: only update landing pages without thumbnails
    $query = "
        SELECT id, title, thumbnail_filename
        FROM {$tablePrefix}content
        WHERE content_type = 'landing'
        AND (thumbnail_filename IS NULL OR thumbnail_filename = '')
        ORDER BY title
    ";
}

$stmt = $pdo->query($query);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count = count($rows);
if ($force) {
    echo "Found $count landing page(s) to update (force mode)\n\n";
} else {
    echo "Found $count landing page(s) without thumbnails\n\n";
}

if ($count === 0) {
    echo "Nothing to update.\n";
    exit(0);
}

$updated = 0;
$errors = 0;

foreach ($rows as $row) {
    $id = $row['id'];
    $title = $row['title'];

    if ($verbose) {
        echo "  Processing: " . substr($title, 0, 60) . "\n";
    }

    if ($dryRun) {
        $updated++;
        continue;
    }

    try {
        $updateQuery = "
            UPDATE {$tablePrefix}content
            SET thumbnail_filename = :thumbnail
            WHERE id = :id
        ";

        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            ':thumbnail' => $defaultThumbnail,
            ':id' => $id
        ]);

        $updated++;

    } catch (Exception $e) {
        $errors++;
        fwrite(STDERR, "  ERROR updating $id: " . $e->getMessage() . "\n");
    }
}

echo "\n=== Summary ===\n";
if ($dryRun) {
    echo "Would update: $updated landing page(s)\n";
} else {
    echo "Updated: $updated landing page(s)\n";
}
if ($errors > 0) {
    echo "Errors: $errors\n";
}
echo "\nDefault thumbnail set to: $defaultThumbnail\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

exit($errors > 0 ? 1 : 0);
