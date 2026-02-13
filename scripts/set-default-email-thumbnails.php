#!/usr/bin/env php
<?php
/**
 * Set Default Email Thumbnails Script
 *
 * Updates email content that has no thumbnail (or an old default) to use the default email thumbnail.
 *
 * Usage: php scripts/set-default-email-thumbnails.php [--dry-run] [--verbose] [--thumbnail=PATH] [--replace=OLD_PATH]
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help', 'thumbnail:', 'replace:']);

if (isset($options['help'])) {
    echo "Usage: php scripts/set-default-email-thumbnails.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run          Preview changes without updating database\n";
    echo "  --verbose          Show detailed processing information\n";
    echo "  --thumbnail=PATH   Set custom thumbnail path (default: /images/email_default.png)\n";
    echo "  --replace=PATH     Replace emails with this existing thumbnail path\n";
    echo "                     Use this to swap out an old default for a new one\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Set default thumbnail on emails without any thumbnail:\n";
    echo "  php scripts/set-default-email-thumbnails.php\n\n";
    echo "  # Use a custom thumbnail path:\n";
    echo "  php scripts/set-default-email-thumbnails.php --thumbnail=/images/custom_email.png\n\n";
    echo "  # Replace old default with new one:\n";
    echo "  php scripts/set-default-email-thumbnails.php --replace=/images/email_default.png --thumbnail=/images/new_default.png\n\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Default thumbnail path (can be overridden with --thumbnail)
$defaultThumbnail = $options['thumbnail'] ?? '/images/email_default.png';

// Optional: replace existing thumbnail path
$replaceThumbnail = $options['replace'] ?? null;

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    echo "ERROR: Config file not found: $configPath\n";
    exit(1);
}

$oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
$config = @require $configPath;
error_reporting($oldErrorReporting);

if (!is_array($config) || !isset($config['database'])) {
    echo "ERROR: Invalid configuration file\n";
    exit(1);
}

echo "=== Set Default Email Thumbnails ===\n\n";

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made to database\n\n";
}

try {
    // Connect to database
    $dbConfig = $config['database'];
    $dbType = $dbConfig['type'] ?? 'mysql';

    // Validate database config
    if (empty($dbConfig['host']) || empty($dbConfig['dbname'])) {
        throw new Exception("Database configuration is incomplete. Make sure environment variables are set.");
    }

    if ($dbType === 'pgsql' || $dbType === 'postgres') {
        $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
        $db = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    } else {
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
        $db = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine table prefix (schema for PostgreSQL)
    $schema = $dbConfig['schema'] ?? null;
    $tablePrefix = '';
    if ($dbType === 'pgsql' && !empty($schema)) {
        $tablePrefix = $schema . '.';
    }

    echo "Connected to database: {$dbConfig['host']}/{$dbConfig['dbname']} ($dbType)\n";
    if (!empty($tablePrefix)) {
        echo "Using schema: $schema\n";
    }
    echo "New thumbnail: $defaultThumbnail\n";
    if ($replaceThumbnail) {
        echo "Replacing: $replaceThumbnail\n";
    }
    echo "\n";

    // Find email content to update
    if ($replaceThumbnail) {
        echo "Searching for email content with thumbnail: $replaceThumbnail\n";
        $query = "
            SELECT id, title, thumbnail_filename
            FROM {$tablePrefix}content
            WHERE content_type = 'email'
            AND thumbnail_filename = :replace_thumbnail
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([':replace_thumbnail' => $replaceThumbnail]);
    } else {
        echo "Searching for email content without thumbnails...\n";
        $query = "
            SELECT id, title, thumbnail_filename
            FROM {$tablePrefix}content
            WHERE content_type = 'email'
            AND (thumbnail_filename IS NULL OR thumbnail_filename = '')
        ";
        $stmt = $db->query($query);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFound = count($results);
    if ($replaceThumbnail) {
        echo "Found $totalFound email(s) with thumbnail: $replaceThumbnail\n\n";
    } else {
        echo "Found $totalFound email(s) without thumbnails\n\n";
    }

    if ($totalFound === 0) {
        echo "No updates needed!\n";
        exit(0);
    }

    // Process each email
    $updated = 0;
    $errors = 0;

    if ($dryRun) {
        // In dry-run mode, show what would be updated
        foreach ($results as $index => $row) {
            $contentId = $row['id'];
            $title = $row['title'];

            if ($verbose) {
                echo "[" . ($index + 1) . "/$totalFound] Would update: $title (ID: $contentId)\n";
            }
            $updated++;
        }

        if (!$verbose) {
            echo "Would update $totalFound email(s) with default thumbnail\n";
        }
    } else {
        // Perform bulk update
        if ($replaceThumbnail) {
            $updateQuery = "
                UPDATE {$tablePrefix}content
                SET thumbnail_filename = :thumbnail
                WHERE content_type = 'email'
                AND thumbnail_filename = :replace_thumbnail
            ";
            $params = [':thumbnail' => $defaultThumbnail, ':replace_thumbnail' => $replaceThumbnail];
        } else {
            $updateQuery = "
                UPDATE {$tablePrefix}content
                SET thumbnail_filename = :thumbnail
                WHERE content_type = 'email'
                AND (thumbnail_filename IS NULL OR thumbnail_filename = '')
            ";
            $params = [':thumbnail' => $defaultThumbnail];
        }

        try {
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute($params);
            $updated = $updateStmt->rowCount();

            if ($verbose) {
                foreach ($results as $index => $row) {
                    echo "[" . ($index + 1) . "/$totalFound] Updated: {$row['title']} (ID: {$row['id']})\n";
                }
            }
        } catch (Exception $e) {
            echo "Error updating: " . $e->getMessage() . "\n";
            $errors++;
        }
    }

    // Summary
    echo "\n=== Summary ===\n";
    if ($replaceThumbnail) {
        echo "Total emails found with '$replaceThumbnail': $totalFound\n";
    } else {
        echo "Total emails found without thumbnails: $totalFound\n";
    }

    if ($dryRun) {
        echo "Would update: $updated email(s) to '$defaultThumbnail'\n";
        echo "\nRun without --dry-run to apply changes.\n";
    } else {
        echo "Successfully updated: $updated email(s) to '$defaultThumbnail'\n";
        if ($errors > 0) {
            echo "Errors: $errors\n";
        }
    }

} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
