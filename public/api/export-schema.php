<?php
/**
 * Export Database Schema API
 * Returns the current database schema for all tables
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $dbType = $db->getDbType();
    $schema = [];

    if ($dbType === 'pgsql') {
        // PostgreSQL - query information_schema for the configured schema
        $schemaName = $config['database']['schema'] ?? 'global';

        // Get all tables
        $tables = $db->fetchAll("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = :schema
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ", [':schema' => $schemaName]);

        foreach ($tables as $table) {
            $tableName = $table['table_name'];

            // Get columns for this table
            $columns = $db->fetchAll("
                SELECT
                    column_name,
                    data_type,
                    is_nullable,
                    column_default,
                    character_maximum_length,
                    numeric_precision,
                    numeric_scale
                FROM information_schema.columns
                WHERE table_schema = :schema
                AND table_name = :table
                ORDER BY ordinal_position
            ", [':schema' => $schemaName, ':table' => $tableName]);

            // Get primary key
            $primaryKey = $db->fetchAll("
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                AND tc.table_schema = :schema
                AND tc.table_name = :table
            ", [':schema' => $schemaName, ':table' => $tableName]);

            // Get foreign keys
            $foreignKeys = $db->fetchAll("
                SELECT
                    kcu.column_name,
                    ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_schema = :schema
                AND tc.table_name = :table
            ", [':schema' => $schemaName, ':table' => $tableName]);

            // Get indexes
            $indexes = $db->fetchAll("
                SELECT
                    i.relname AS index_name,
                    a.attname AS column_name,
                    ix.indisunique AS is_unique
                FROM pg_class t
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = :schema
                AND t.relname = :table
                AND NOT ix.indisprimary
                ORDER BY i.relname, a.attnum
            ", [':schema' => $schemaName, ':table' => $tableName]);

            $schema[$tableName] = [
                'columns' => $columns,
                'primary_key' => array_column($primaryKey, 'column_name'),
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes
            ];
        }

    } else {
        // MySQL - query information_schema for the current database
        $dbName = $config['database']['dbname'];

        // Get all tables
        $tables = $db->fetchAll("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = :dbname
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ", [':dbname' => $dbName]);

        foreach ($tables as $table) {
            $tableName = $table['table_name'];

            // Get columns for this table
            $columns = $db->fetchAll("
                SELECT
                    column_name,
                    data_type,
                    is_nullable,
                    column_default,
                    character_maximum_length,
                    numeric_precision,
                    numeric_scale,
                    column_type,
                    extra
                FROM information_schema.columns
                WHERE table_schema = :dbname
                AND table_name = :table
                ORDER BY ordinal_position
            ", [':dbname' => $dbName, ':table' => $tableName]);

            // Get primary key
            $primaryKey = $db->fetchAll("
                SELECT column_name
                FROM information_schema.key_column_usage
                WHERE table_schema = :dbname
                AND table_name = :table
                AND constraint_name = 'PRIMARY'
            ", [':dbname' => $dbName, ':table' => $tableName]);

            // Get foreign keys
            $foreignKeys = $db->fetchAll("
                SELECT
                    column_name,
                    referenced_table_name AS foreign_table_name,
                    referenced_column_name AS foreign_column_name
                FROM information_schema.key_column_usage
                WHERE table_schema = :dbname
                AND table_name = :table
                AND referenced_table_name IS NOT NULL
            ", [':dbname' => $dbName, ':table' => $tableName]);

            // Get indexes
            $indexes = $db->fetchAll("
                SELECT
                    index_name,
                    column_name,
                    non_unique
                FROM information_schema.statistics
                WHERE table_schema = :dbname
                AND table_name = :table
                AND index_name != 'PRIMARY'
                ORDER BY index_name, seq_in_index
            ", [':dbname' => $dbName, ':table' => $tableName]);

            $schema[$tableName] = [
                'columns' => $columns,
                'primary_key' => array_column($primaryKey, 'column_name'),
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes
            ];
        }
    }

    // Generate SQL output if requested
    $format = $_GET['format'] ?? 'json';

    if ($format === 'sql') {
        $sql = generateSQLSchema($schema, $dbType);

        // Set headers for file download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="schema_export_' . date('Y-m-d_His') . '.sql"');
        echo $sql;
        exit;
    }

    sendJSON([
        'success' => true,
        'database_type' => $dbType,
        'table_count' => count($schema),
        'schema' => $schema,
        'exported_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Schema export error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to export schema',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Database error'
    ], 500);
}

/**
 * Generate SQL CREATE statements from schema
 */
function generateSQLSchema($schema, $dbType) {
    $sql = "-- Database Schema Export\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database Type: " . strtoupper($dbType) . "\n\n";

    foreach ($schema as $tableName => $tableInfo) {
        $sql .= "-- Table: $tableName\n";
        $sql .= "CREATE TABLE $tableName (\n";

        $columnDefs = [];
        foreach ($tableInfo['columns'] as $col) {
            $def = "    " . $col['column_name'] . " ";

            // Data type
            if ($dbType === 'mysql' && isset($col['column_type'])) {
                $def .= $col['column_type'];
            } else {
                $def .= $col['data_type'];
                if (!empty($col['character_maximum_length'])) {
                    $def .= "(" . $col['character_maximum_length'] . ")";
                } elseif (!empty($col['numeric_precision'])) {
                    $def .= "(" . $col['numeric_precision'];
                    if (!empty($col['numeric_scale'])) {
                        $def .= "," . $col['numeric_scale'];
                    }
                    $def .= ")";
                }
            }

            // NOT NULL
            if ($col['is_nullable'] === 'NO') {
                $def .= " NOT NULL";
            }

            // Default value
            if ($col['column_default'] !== null) {
                $default = $col['column_default'];
                // Clean up PostgreSQL sequence defaults
                if (strpos($default, 'nextval') === false) {
                    $def .= " DEFAULT " . $default;
                }
            }

            // PRIMARY KEY (inline if single column)
            if (count($tableInfo['primary_key']) === 1 && $tableInfo['primary_key'][0] === $col['column_name']) {
                $def .= " PRIMARY KEY";
            }

            $columnDefs[] = $def;
        }

        // Composite primary key
        if (count($tableInfo['primary_key']) > 1) {
            $columnDefs[] = "    PRIMARY KEY (" . implode(", ", $tableInfo['primary_key']) . ")";
        }

        // Foreign keys
        foreach ($tableInfo['foreign_keys'] as $fk) {
            $columnDefs[] = "    FOREIGN KEY (" . $fk['column_name'] . ") REFERENCES " .
                           $fk['foreign_table_name'] . "(" . $fk['foreign_column_name'] . ")";
        }

        $sql .= implode(",\n", $columnDefs);
        $sql .= "\n);\n\n";

        // Indexes
        foreach ($tableInfo['indexes'] as $idx) {
            $unique = '';
            if ($dbType === 'pgsql' && $idx['is_unique']) {
                $unique = 'UNIQUE ';
            } elseif ($dbType === 'mysql' && !$idx['non_unique']) {
                $unique = 'UNIQUE ';
            }
            $sql .= "CREATE {$unique}INDEX " . $idx['index_name'] . " ON $tableName (" . $idx['column_name'] . ");\n";
        }

        $sql .= "\n";
    }

    return $sql;
}
