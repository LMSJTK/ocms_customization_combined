#!/usr/bin/env php
<?php
/**
 * Count Placeholders in Legacy Email Templates
 *
 * Queries the pm_email_template table to count emails with placeholders
 * and list the unique placeholder names found.
 *
 * Usage: php scripts/count-legacy-placeholders.php [--verbose] [--list-all]
 */

// Parse command line arguments
$options = getopt('', ['verbose', 'list-all', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/count-legacy-placeholders.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose    Show detailed information about each template\n";
    echo "  --list-all   List all templates with placeholder counts\n";
    echo "  --help       Show this help message\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$listAll = isset($options['list-all']);

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    echo "ERROR: Config file not found: $configPath\n";
    exit(1);
}

$oldErrorReporting = error_reporting(E_ERROR | E_PARSE);
$config = @require $configPath;
error_reporting($oldErrorReporting);

// Check for legacy database config
$legacyDb = $config['legacy_database'] ?? null;
if (!$legacyDb || empty($legacyDb['host'])) {
    echo "ERROR: Legacy database not configured in config.php\n";
    echo "Expected 'legacy_database' configuration with host, port, dbname, username, password\n";
    exit(1);
}

echo "=== Legacy Email Template Placeholder Counter ===\n\n";

try {
    // Connect to legacy database
    $dsn = "pgsql:host={$legacyDb['host']};port={$legacyDb['port']};dbname={$legacyDb['dbname']}";
    $db = new PDO($dsn, $legacyDb['username'], $legacyDb['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = $legacyDb['schema'] ?? 'global';

    echo "Connected to legacy database\n";
    echo "Schema: $schema\n\n";

    // Count total templates
    $totalStmt = $db->query("SELECT COUNT(*) FROM {$schema}.pm_email_template WHERE deleted_at IS NULL");
    $totalCount = $totalStmt->fetchColumn();
    echo "Total active email templates: $totalCount\n\n";

    // Count templates with placeholders (using LIKE for the span pattern)
    $placeholderStmt = $db->query("
        SELECT COUNT(*)
        FROM {$schema}.pm_email_template
        WHERE deleted_at IS NULL
        AND body LIKE '%class=\"placeholder\"%'
    ");
    $placeholderCount = $placeholderStmt->fetchColumn();
    echo "Templates WITH placeholders: $placeholderCount\n";
    echo "Templates WITHOUT placeholders: " . ($totalCount - $placeholderCount) . "\n\n";

    // Get all unique placeholders
    echo "--- Extracting unique placeholder names ---\n\n";

    $stmt = $db->query("
        SELECT template_name, body
        FROM {$schema}.pm_email_template
        WHERE deleted_at IS NULL
        AND body LIKE '%data-basename=%'
    ");

    $allPlaceholders = [];
    $templatePlaceholders = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $templateName = $row['template_name'];
        $body = $row['body'];

        // Extract data-basename values
        $pattern = '/data-basename=["\']([^"\']+)["\']/i';
        if (preg_match_all($pattern, $body, $matches)) {
            $placeholders = array_unique($matches[1]);
            $templatePlaceholders[$templateName] = $placeholders;

            foreach ($placeholders as $p) {
                $p = trim($p);
                if (!isset($allPlaceholders[$p])) {
                    $allPlaceholders[$p] = 0;
                }
                $allPlaceholders[$p]++;
            }
        }
    }

    // Sort placeholders alphabetically
    ksort($allPlaceholders);

    echo "Found " . count($allPlaceholders) . " unique placeholders:\n\n";

    echo sprintf("%-40s %s\n", "PLACEHOLDER", "USED IN # TEMPLATES");
    echo str_repeat("-", 60) . "\n";

    foreach ($allPlaceholders as $placeholder => $count) {
        echo sprintf("%-40s %d\n", $placeholder, $count);
    }

    // List all templates with their placeholders if requested
    if ($listAll) {
        echo "\n\n--- Templates and Their Placeholders ---\n\n";

        foreach ($templatePlaceholders as $template => $placeholders) {
            echo "$template:\n";
            foreach ($placeholders as $p) {
                echo "  - $p\n";
            }
            echo "\n";
        }
    }

    // Verbose mode: show templates without placeholders
    if ($verbose) {
        echo "\n--- Templates WITHOUT Placeholders ---\n\n";

        $noPlaceholderStmt = $db->query("
            SELECT template_name
            FROM {$schema}.pm_email_template
            WHERE deleted_at IS NULL
            AND body NOT LIKE '%data-basename=%'
            ORDER BY template_name
        ");

        while ($row = $noPlaceholderStmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  - " . $row['template_name'] . "\n";
        }
    }

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
