#!/usr/bin/env php
<?php
/**
 * Migration: Add Customization Tables
 *
 * Creates brand_kits, brand_kit_assets, content_customizations, and
 * content_translations tables. Idempotent — safe to run multiple times.
 *
 * Usage:
 *   php scripts/migrate-add-customization-tables.php [options]
 *
 * Options:
 *   --dry-run   Show SQL without executing
 *   --verbose   Show detailed progress
 *   --quiet     Only show errors and final summary
 *   --help      Show this help message
 */

set_time_limit(0);
ini_set('memory_limit', '256M');

$options = getopt('', ['dry-run', 'verbose', 'quiet', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Migration: Add Customization Tables

Creates brand_kits, brand_kit_assets, content_customizations, and
content_translations tables with indexes and triggers.
Idempotent — safe to run multiple times (uses IF NOT EXISTS).

Usage:
  php scripts/migrate-add-customization-tables.php [options]

Options:
  --dry-run   Show SQL without executing
  --verbose   Show detailed progress
  --quiet     Only show errors and final summary
  --help      Show this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

function logInfo($message) {
    global $quiet;
    if (!$quiet) echo "[INFO] $message\n";
}

function logVerbose($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) echo "  $message\n";
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

if (!$quiet) {
    echo "=== Migration: Add Customization Tables ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    if ($dryRun) echo "*** DRY RUN MODE - No changes will be made ***\n";
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
require_once __DIR__ . '/../lib/Database.php';

try {
    $db = Database::getInstance($config['database']);
    logInfo("Connected to database");
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    exit(1);
}

$dbType = $db->getDbType();
$schema = ($dbType === 'pgsql') ? ($config['database']['schema'] ?? 'global') : '';
$prefix = $schema ? "{$schema}." : '';

logInfo("Database type: $dbType" . ($schema ? " (schema: $schema)" : ""));

// Build migration statements based on DB type
$statements = [];

if ($dbType === 'pgsql') {
    // ---- PostgreSQL ----

    $statements[] = [
        'desc' => 'Create brand_kits table',
        'sql' => "CREATE TABLE IF NOT EXISTS {$prefix}brand_kits (
            id text PRIMARY KEY,
            company_id text NOT NULL,
            name text NOT NULL DEFAULT 'Default',
            logo_url text,
            logo_filename text,
            primary_color text,
            secondary_color text,
            accent_color text,
            saved_colors jsonb DEFAULT '[]'::jsonb,
            primary_font text,
            secondary_font text,
            custom_font_urls jsonb DEFAULT '[]'::jsonb,
            is_default boolean DEFAULT false,
            created_at timestamp DEFAULT now(),
            updated_at timestamp DEFAULT now(),
            UNIQUE(company_id, name)
        )"
    ];

    $statements[] = [
        'desc' => 'Create brand_kit_assets table',
        'sql' => "CREATE TABLE IF NOT EXISTS {$prefix}brand_kit_assets (
            id text PRIMARY KEY,
            brand_kit_id text NOT NULL REFERENCES {$prefix}brand_kits(id) ON DELETE CASCADE,
            asset_type text NOT NULL,
            filename text NOT NULL,
            s3_url text NOT NULL,
            mime_type text,
            file_size integer,
            created_at timestamp DEFAULT now()
        )"
    ];

    $statements[] = [
        'desc' => 'Create content_customizations table',
        'sql' => "CREATE TABLE IF NOT EXISTS {$prefix}content_customizations (
            id text PRIMARY KEY,
            company_id text NOT NULL,
            base_content_id text NOT NULL REFERENCES {$prefix}content(id) ON DELETE CASCADE,
            brand_kit_id text REFERENCES {$prefix}brand_kits(id) ON DELETE SET NULL,
            title text,
            customized_html text,
            customization_data jsonb,
            status text DEFAULT 'draft',
            created_by text,
            created_at timestamp DEFAULT now(),
            updated_at timestamp DEFAULT now()
        )"
    ];

    $statements[] = [
        'desc' => 'Create content_translations table',
        'sql' => "CREATE TABLE IF NOT EXISTS {$prefix}content_translations (
            id SERIAL PRIMARY KEY,
            source_content_id text NOT NULL REFERENCES {$prefix}content(id) ON DELETE CASCADE,
            translated_content_id text NOT NULL REFERENCES {$prefix}content(id) ON DELETE CASCADE,
            source_language varchar(10) NOT NULL DEFAULT 'en',
            target_language varchar(10) NOT NULL,
            created_at timestamp DEFAULT now(),
            UNIQUE(source_content_id, target_language)
        )"
    ];

    // Indexes (CREATE INDEX IF NOT EXISTS is supported in PG 9.5+)
    $indexes = [
        ['brand_kits', 'company_id'],
        ['brand_kit_assets', 'brand_kit_id'],
        ['content_customizations', 'company_id'],
        ['content_customizations', 'base_content_id'],
        ['content_customizations', 'status'],
    ];
    foreach ($indexes as [$table, $col]) {
        $idxName = "idx_{$table}_{$col}";
        $statements[] = [
            'desc' => "Create index {$idxName}",
            'sql' => "CREATE INDEX IF NOT EXISTS {$idxName} ON {$prefix}{$table}({$col})"
        ];
    }

    // Composite index
    $statements[] = [
        'desc' => 'Create composite index on content_customizations(company_id, base_content_id)',
        'sql' => "CREATE INDEX IF NOT EXISTS idx_content_customizations_company_content ON {$prefix}content_customizations(company_id, base_content_id)"
    ];

    // updated_at trigger function (idempotent via CREATE OR REPLACE)
    $statements[] = [
        'desc' => 'Create or replace update_updated_at_column() function',
        'sql' => "CREATE OR REPLACE FUNCTION update_updated_at_column() RETURNS TRIGGER AS \$\$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
\$\$ language 'plpgsql'"
    ];

    // Triggers — DROP IF EXISTS then CREATE to be idempotent
    $triggerTables = ['brand_kits', 'content_customizations'];
    foreach ($triggerTables as $table) {
        $triggerName = "update_{$table}_updated_at";
        $statements[] = [
            'desc' => "Create trigger {$triggerName}",
            'sql' => "DROP TRIGGER IF EXISTS {$triggerName} ON {$prefix}{$table}; CREATE TRIGGER {$triggerName} BEFORE UPDATE ON {$prefix}{$table} FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()"
        ];
    }

} else {
    // ---- MySQL ----

    $statements[] = [
        'desc' => 'Create brand_kits table',
        'sql' => "CREATE TABLE IF NOT EXISTS brand_kits (
            id VARCHAR(255) PRIMARY KEY,
            company_id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT 'Default',
            logo_url TEXT,
            logo_filename VARCHAR(255),
            primary_color VARCHAR(7),
            secondary_color VARCHAR(7),
            accent_color VARCHAR(7),
            saved_colors JSON,
            primary_font VARCHAR(255),
            secondary_font VARCHAR(255),
            custom_font_urls JSON,
            is_default BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_company_kit (company_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    $statements[] = [
        'desc' => 'Create brand_kit_assets table',
        'sql' => "CREATE TABLE IF NOT EXISTS brand_kit_assets (
            id VARCHAR(255) PRIMARY KEY,
            brand_kit_id VARCHAR(255) NOT NULL,
            asset_type VARCHAR(50) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            s3_url TEXT NOT NULL,
            mime_type VARCHAR(100),
            file_size INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brand_kit_id) REFERENCES brand_kits(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    $statements[] = [
        'desc' => 'Create content_customizations table',
        'sql' => "CREATE TABLE IF NOT EXISTS content_customizations (
            id VARCHAR(255) PRIMARY KEY,
            company_id VARCHAR(255) NOT NULL,
            base_content_id VARCHAR(255) NOT NULL,
            brand_kit_id VARCHAR(255),
            title TEXT,
            customized_html LONGTEXT,
            customization_data JSON,
            status VARCHAR(50) DEFAULT 'draft',
            created_by VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (base_content_id) REFERENCES content(id) ON DELETE CASCADE,
            FOREIGN KEY (brand_kit_id) REFERENCES brand_kits(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    $statements[] = [
        'desc' => 'Create content_translations table',
        'sql' => "CREATE TABLE IF NOT EXISTS content_translations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_content_id VARCHAR(255) NOT NULL,
            translated_content_id VARCHAR(255) NOT NULL,
            source_language VARCHAR(10) NOT NULL DEFAULT 'en',
            target_language VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_source_lang (source_content_id, target_language),
            FOREIGN KEY (source_content_id) REFERENCES content(id) ON DELETE CASCADE,
            FOREIGN KEY (translated_content_id) REFERENCES content(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    // MySQL indexes — wrap in a procedure to make idempotent
    $mysqlIndexes = [
        ['brand_kits', 'idx_brand_kits_company_id', 'company_id'],
        ['brand_kit_assets', 'idx_brand_kit_assets_brand_kit_id', 'brand_kit_id'],
        ['content_customizations', 'idx_content_customizations_company_id', 'company_id'],
        ['content_customizations', 'idx_content_customizations_base_content', 'base_content_id'],
        ['content_customizations', 'idx_content_customizations_status', 'status'],
        ['content_customizations', 'idx_content_customizations_company_content', 'company_id, base_content_id'],
    ];

    foreach ($mysqlIndexes as [$table, $idxName, $cols]) {
        $statements[] = [
            'desc' => "Create index {$idxName}",
            'sql' => "CREATE INDEX {$idxName} ON {$table}({$cols})",
            'ignore_error' => true  // MySQL doesn't support IF NOT EXISTS on indexes
        ];
    }
}

// Execute statements
$executed = 0;
$skipped = 0;
$errors = 0;

foreach ($statements as $stmt) {
    $desc = $stmt['desc'];
    $sql = $stmt['sql'];
    $ignoreError = $stmt['ignore_error'] ?? false;

    if ($dryRun) {
        logInfo("[DRY RUN] $desc");
        logVerbose($sql);
        $executed++;
        continue;
    }

    try {
        logInfo($desc);
        logVerbose($sql);

        // For PostgreSQL trigger statements that contain multiple commands, split on semicolon
        if ($dbType === 'pgsql' && strpos($sql, 'DROP TRIGGER') !== false) {
            $parts = explode(';', $sql);
            foreach ($parts as $part) {
                $part = trim($part);
                if (!empty($part)) {
                    $db->getPDO()->exec($part);
                }
            }
        } else {
            $db->getPDO()->exec($sql);
        }

        $executed++;
    } catch (Exception $e) {
        if ($ignoreError && (
            strpos($e->getMessage(), 'Duplicate') !== false ||
            strpos($e->getMessage(), 'already exists') !== false
        )) {
            $skipped++;
            logVerbose("Skipped (already exists)");
        } else {
            $errors++;
            logError("$desc: " . $e->getMessage());
        }
    }
}

// Summary
echo "\n";
$modeLabel = $dryRun ? "(DRY RUN) " : "";
logInfo("{$modeLabel}Migration complete:");
logInfo("  Executed: $executed");
logInfo("  Skipped (already exists): $skipped");
if ($errors > 0) {
    logInfo("  Errors: $errors");
}
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);
