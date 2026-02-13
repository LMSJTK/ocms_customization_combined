<?php
/**
 * Content Search Tool
 * Search for strings within content fields and display surrounding context.
 * Useful for finding placeholders, patterns, or specific text in emails, educations, etc.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// Shutdown handler to catch fatal errors and return JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

// Custom error handler
function contentSearchErrorHandler($errno, $errstr, $errfile, $errline) {
    error_log("Content Search Error [$errno]: $errstr in $errfile on line $errline");
    return false;
}
set_error_handler('contentSearchErrorHandler');

require_once __DIR__ . '/api/bootstrap.php';

// Configuration
$SCHEMA = $config['database']['schema'] ?? 'public';
$DB_TYPE = $db->getDbType(); // 'pgsql' or 'mysql'
$CONTEXT_CHARS = 60; // Characters before/after match to display

// --- API HANDLING ---

if (isset($_GET['action'])) {
    // Clear output buffer for API responses
    ob_end_clean();
    header('Content-Type: application/json');

    try {

        // 1. List Tables
        if ($_GET['action'] === 'list_tables') {
            $tables = [];

            if ($DB_TYPE === 'pgsql') {
                $sql = "SELECT c.relname as name
                        FROM pg_catalog.pg_class c
                        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = :schema
                        AND c.relkind IN ('r', 'v', 'f', 'p')
                        ORDER BY c.relname";
                $rows = $db->fetchAll($sql, [':schema' => $SCHEMA]);
                $tables = array_column($rows, 'name');
            } else {
                $sql = "SELECT table_name as name
                        FROM information_schema.tables
                        WHERE table_schema = :dbname
                        ORDER BY table_name";
                $rows = $db->fetchAll($sql, [':dbname' => $config['database']['dbname']]);
                $tables = array_column($rows, 'name');
            }

            sendJSON(['success' => true, 'tables' => $tables]);
        }

        // 2. Get Columns for a Table (text-like columns only)
        if ($_GET['action'] === 'get_columns') {
            $tableName = $_GET['table'] ?? null;

            if (!$tableName || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name");
            }

            $columns = [];
            if ($DB_TYPE === 'pgsql') {
                $sql = "SELECT column_name, data_type
                        FROM information_schema.columns
                        WHERE table_schema = :schema AND table_name = :table
                        ORDER BY ordinal_position";
                $rows = $db->fetchAll($sql, [':schema' => $SCHEMA, ':table' => $tableName]);
            } else {
                $sql = "SELECT column_name, data_type
                        FROM information_schema.columns
                        WHERE table_schema = :dbname AND table_name = :table
                        ORDER BY ordinal_position";
                $rows = $db->fetchAll($sql, [':dbname' => $config['database']['dbname'], ':table' => $tableName]);
            }

            // Filter to text-like columns that are useful for content search
            $textTypes = ['text', 'varchar', 'character varying', 'char', 'longtext', 'mediumtext', 'tinytext', 'json', 'jsonb'];
            foreach ($rows as $row) {
                $isTextLike = false;
                foreach ($textTypes as $type) {
                    if (stripos($row['data_type'], $type) !== false) {
                        $isTextLike = true;
                        break;
                    }
                }
                $columns[] = [
                    'name' => $row['column_name'],
                    'type' => $row['data_type'],
                    'isTextLike' => $isTextLike
                ];
            }

            sendJSON(['success' => true, 'columns' => $columns]);
        }

        // 3. Get All Columns for Export Selection
        if ($_GET['action'] === 'get_all_columns') {
            $tableName = $_GET['table'] ?? null;

            if (!$tableName || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name");
            }

            if ($DB_TYPE === 'pgsql') {
                $sql = "SELECT column_name, data_type
                        FROM information_schema.columns
                        WHERE table_schema = :schema AND table_name = :table
                        ORDER BY ordinal_position";
                $rows = $db->fetchAll($sql, [':schema' => $SCHEMA, ':table' => $tableName]);
            } else {
                $sql = "SELECT column_name, data_type
                        FROM information_schema.columns
                        WHERE table_schema = :dbname AND table_name = :table
                        ORDER BY ordinal_position";
                $rows = $db->fetchAll($sql, [':dbname' => $config['database']['dbname'], ':table' => $tableName]);
            }

            $columns = [];
            foreach ($rows as $row) {
                $columns[] = [
                    'name' => $row['column_name'],
                    'type' => $row['data_type']
                ];
            }

            sendJSON(['success' => true, 'columns' => $columns]);
        }

        // 4. Search Content
        if ($_GET['action'] === 'search') {
            error_log("Content Search: Starting search action");

            $tableName = $_GET['table'] ?? null;
            $columnName = $_GET['column'] ?? null;
            $searchTerm = $_GET['term'] ?? null;
            $contextChars = (int)($_GET['context'] ?? $CONTEXT_CHARS);
            $limit = (int)($_GET['limit'] ?? 100);
            $offset = (int)($_GET['offset'] ?? 0);
            $idColumn = $_GET['id_column'] ?? 'id';
            $caseSensitive = ($_GET['case_sensitive'] ?? '0') === '1';

            error_log("Content Search: table=$tableName, column=$columnName, term=$searchTerm");

            if (!$tableName || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name");
            }
            if (!$columnName || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
                throw new Exception("Invalid column name");
            }
            if (!$searchTerm) {
                throw new Exception("Search term required");
            }
            if ($idColumn && !preg_match('/^[a-zA-Z0-9_]+$/', $idColumn)) {
                $idColumn = 'id';
            }

            $contextChars = max(20, min(200, $contextChars));
            $limit = max(1, min(1000, $limit));
            $offset = max(0, $offset);

            $fullTableName = ($DB_TYPE === 'pgsql') ? "{$SCHEMA}.{$tableName}" : $tableName;

            // Get all columns to find the ID column
            if ($DB_TYPE === 'pgsql') {
                $colSql = "SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table";
                $colRows = $db->fetchAll($colSql, [':schema' => $SCHEMA, ':table' => $tableName]);
            } else {
                $colSql = "SELECT column_name FROM information_schema.columns WHERE table_schema = :dbname AND table_name = :table";
                $colRows = $db->fetchAll($colSql, [':dbname' => $config['database']['dbname'], ':table' => $tableName]);
            }
            $allColumns = array_column($colRows, 'column_name');

            // Validate ID column exists
            if (!in_array($idColumn, $allColumns)) {
                // Try to find a suitable ID column
                $idCandidates = ['id', 'ID', 'content_id', 'email_id', 'template_id'];
                $idColumn = null;
                foreach ($idCandidates as $candidate) {
                    if (in_array($candidate, $allColumns)) {
                        $idColumn = $candidate;
                        break;
                    }
                }
            }

            // Build the query
            if ($DB_TYPE === 'pgsql') {
                $likeOp = $caseSensitive ? 'LIKE' : 'ILIKE';
                $whereClause = "\"{$columnName}\"::text {$likeOp} :search";
                $selectId = $idColumn ? "\"{$idColumn}\"" : "NULL";
                $selectCol = "\"{$columnName}\"::text";
            } else {
                $likeOp = $caseSensitive ? 'LIKE BINARY' : 'LIKE';
                $whereClause = "CAST(`{$columnName}` AS CHAR) {$likeOp} :search";
                $selectId = $idColumn ? "`{$idColumn}`" : "NULL";
                $selectCol = "CAST(`{$columnName}` AS CHAR)";
            }

            // Get total count first
            $countSql = "SELECT COUNT(*) as total FROM {$fullTableName} WHERE {$whereClause}";
            $countResult = $db->fetchOne($countSql, [':search' => '%' . $searchTerm . '%']);
            $totalRows = (int)($countResult['total'] ?? 0);

            // Note: LIMIT/OFFSET values are inlined because PostgreSQL doesn't accept string-bound parameters
            $sql = "SELECT {$selectId} as row_id, {$selectCol} as content FROM {$fullTableName} WHERE {$whereClause} LIMIT {$limit} OFFSET {$offset}";
            error_log("Content Search: SQL = $sql");

            $rows = $db->fetchAll($sql, [':search' => '%' . $searchTerm . '%']);
            error_log("Content Search: Query returned " . count($rows) . " rows (total: $totalRows)");

            // Process results to extract context around matches
            $results = [];
            $searchLower = strtolower($searchTerm);

            foreach ($rows as $row) {
                $content = $row['content'] ?? '';
                $rowId = $row['row_id'];

                // Find all occurrences
                $searchIn = $caseSensitive ? $content : strtolower($content);
                $searchFor = $caseSensitive ? $searchTerm : $searchLower;
                $pos = 0;
                $occurrences = [];

                while (($pos = strpos($searchIn, $searchFor, $pos)) !== false) {
                    // Extract context
                    $start = max(0, $pos - $contextChars);
                    $end = min(strlen($content), $pos + strlen($searchTerm) + $contextChars);

                    $before = substr($content, $start, $pos - $start);
                    $match = substr($content, $pos, strlen($searchTerm));
                    $after = substr($content, $pos + strlen($searchTerm), $end - $pos - strlen($searchTerm));

                    // Clean up for display (remove excessive whitespace, but keep structure hints)
                    $before = preg_replace('/\s+/', ' ', $before);
                    $after = preg_replace('/\s+/', ' ', $after);

                    $occurrences[] = [
                        'position' => $pos,
                        'before' => ($start > 0 ? '...' : '') . $before,
                        'match' => $match,
                        'after' => $after . ($end < strlen($content) ? '...' : '')
                    ];

                    $pos += strlen($searchTerm);
                }

                if (!empty($occurrences)) {
                    $results[] = [
                        'row_id' => $rowId,
                        'total_matches' => count($occurrences),
                        'content_length' => strlen($content),
                        'occurrences' => $occurrences
                    ];
                }
            }

            // Calculate summary stats
            $totalMatches = array_sum(array_column($results, 'total_matches'));

            error_log("Content Search: Processing complete. Found $totalMatches matches in " . count($results) . " rows");

            // Build response - use JSON flags to handle invalid UTF-8
            $response = [
                'success' => true,
                'table' => $tableName,
                'column' => $columnName,
                'searchTerm' => $searchTerm,
                'rowsMatched' => count($results),
                'totalMatches' => $totalMatches,
                'totalRows' => $totalRows,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => ($offset + $limit) < $totalRows,
                'idColumn' => $idColumn,
                'results' => $results
            ];

            $json = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                error_log("Content Search: JSON encode failed: " . json_last_error_msg());
                sendJSON(['success' => false, 'error' => 'Failed to encode results: ' . json_last_error_msg()], 500);
            }

            echo $json;
            exit;
        }

        // 5. Extract Unique Values (for finding placeholder names, etc.)
        if ($_GET['action'] === 'extract_values') {
            $tableName = $_GET['table'] ?? null;
            $columnName = $_GET['column'] ?? null;
            $searchTerm = $_GET['term'] ?? null;
            $extractPattern = $_GET['extract_pattern'] ?? null;
            $caseSensitive = ($_GET['case_sensitive'] ?? '0') === '1';
            $limit = (int)($_GET['limit'] ?? 1000);

            if (!$tableName || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name");
            }
            if (!$columnName || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
                throw new Exception("Invalid column name");
            }
            if (!$searchTerm) {
                throw new Exception("Search term required");
            }
            if (!$extractPattern) {
                throw new Exception("Extract pattern required");
            }

            $limit = max(1, min(5000, $limit));
            $fullTableName = ($DB_TYPE === 'pgsql') ? "{$SCHEMA}.{$tableName}" : $tableName;

            // Build query
            if ($DB_TYPE === 'pgsql') {
                $likeOp = $caseSensitive ? 'LIKE' : 'ILIKE';
                $whereClause = "\"{$columnName}\"::text {$likeOp} :search";
                $selectCol = "\"{$columnName}\"::text";
            } else {
                $likeOp = $caseSensitive ? 'LIKE BINARY' : 'LIKE';
                $whereClause = "CAST(`{$columnName}` AS CHAR) {$likeOp} :search";
                $selectCol = "CAST(`{$columnName}` AS CHAR)";
            }

            $sql = "SELECT {$selectCol} as content FROM {$fullTableName} WHERE {$whereClause} LIMIT {$limit}";
            $rows = $db->fetchAll($sql, [':search' => '%' . $searchTerm . '%']);

            // Extract values using the pattern
            $extractedValues = [];
            $valueOccurrences = [];
            $rowsScanned = count($rows);

            foreach ($rows as $row) {
                $content = $row['content'] ?? '';

                // Apply the extraction regex
                $flags = $caseSensitive ? '' : 'i';
                if (preg_match_all('/' . $extractPattern . '/' . $flags, $content, $matches)) {
                    // Get the first capture group if it exists, otherwise the full match
                    $values = isset($matches[1]) ? $matches[1] : $matches[0];
                    foreach ($values as $value) {
                        $value = trim($value);
                        if (!empty($value)) {
                            if (!isset($valueOccurrences[$value])) {
                                $valueOccurrences[$value] = 0;
                            }
                            $valueOccurrences[$value]++;
                        }
                    }
                }
            }

            // Sort by occurrence count (descending)
            arsort($valueOccurrences);

            // Build results
            foreach ($valueOccurrences as $value => $count) {
                $extractedValues[] = [
                    'value' => $value,
                    'occurrences' => $count
                ];
            }

            $response = [
                'success' => true,
                'table' => $tableName,
                'column' => $columnName,
                'searchTerm' => $searchTerm,
                'extractPattern' => $extractPattern,
                'rowsScanned' => $rowsScanned,
                'uniqueValues' => count($extractedValues),
                'totalOccurrences' => array_sum($valueOccurrences),
                'values' => $extractedValues
            ];

            $json = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            echo $json;
            exit;
        }

        // 6. Export Results
        if ($_GET['action'] === 'export') {
            $tableName = $_GET['table'] ?? null;
            $searchColumn = $_GET['column'] ?? null;
            $searchTerm = $_GET['term'] ?? null;
            $exportColumns = isset($_GET['export_columns']) ? explode(',', $_GET['export_columns']) : [];
            $includeContext = ($_GET['include_context'] ?? '1') === '1';
            $contextChars = (int)($_GET['context'] ?? $CONTEXT_CHARS);
            $caseSensitive = ($_GET['case_sensitive'] ?? '0') === '1';
            $format = $_GET['format'] ?? 'csv';
            $limit = (int)($_GET['limit'] ?? 500);

            if (!$tableName || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name");
            }
            if (!$searchColumn || !preg_match('/^[a-zA-Z0-9_]+$/', $searchColumn)) {
                throw new Exception("Invalid search column name");
            }
            if (!$searchTerm) {
                throw new Exception("Search term required");
            }

            $contextChars = max(20, min(200, $contextChars));
            $limit = max(1, min(1000, $limit));

            $fullTableName = ($DB_TYPE === 'pgsql') ? "{$SCHEMA}.{$tableName}" : $tableName;

            // Get all valid columns
            if ($DB_TYPE === 'pgsql') {
                $colSql = "SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table";
                $colRows = $db->fetchAll($colSql, [':schema' => $SCHEMA, ':table' => $tableName]);
            } else {
                $colSql = "SELECT column_name FROM information_schema.columns WHERE table_schema = :dbname AND table_name = :table";
                $colRows = $db->fetchAll($colSql, [':dbname' => $config['database']['dbname'], ':table' => $tableName]);
            }
            $allColumns = array_column($colRows, 'column_name');

            // Validate export columns
            $validExportColumns = array_filter($exportColumns, function($col) use ($allColumns) {
                return in_array($col, $allColumns) && preg_match('/^[a-zA-Z0-9_]+$/', $col);
            });

            // Build SELECT clause
            $selectParts = [];
            foreach ($validExportColumns as $col) {
                if ($DB_TYPE === 'pgsql') {
                    $selectParts[] = "\"{$col}\"";
                } else {
                    $selectParts[] = "`{$col}`";
                }
            }

            // Always include search column for context extraction
            $searchColQuoted = ($DB_TYPE === 'pgsql') ? "\"{$searchColumn}\"::text" : "CAST(`{$searchColumn}` AS CHAR)";
            if (!in_array($searchColumn, $validExportColumns)) {
                $selectParts[] = "{$searchColQuoted} as _search_content";
            }

            if (empty($selectParts)) {
                $selectParts[] = $searchColQuoted . " as _search_content";
            }

            // Build WHERE clause
            if ($DB_TYPE === 'pgsql') {
                $likeOp = $caseSensitive ? 'LIKE' : 'ILIKE';
                $whereClause = "\"{$searchColumn}\"::text {$likeOp} :search";
            } else {
                $likeOp = $caseSensitive ? 'LIKE BINARY' : 'LIKE';
                $whereClause = "CAST(`{$searchColumn}` AS CHAR) {$likeOp} :search";
            }

            // Note: LIMIT value is inlined because PostgreSQL doesn't accept string-bound LIMIT parameters
            $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$fullTableName} WHERE {$whereClause} LIMIT {$limit}";
            $rows = $db->fetchAll($sql, [':search' => '%' . $searchTerm . '%']);

            // Process results
            $exportData = [];
            $searchLower = strtolower($searchTerm);

            foreach ($rows as $row) {
                $content = $row['_search_content'] ?? $row[$searchColumn] ?? '';
                unset($row['_search_content']);

                // Find occurrences and extract context
                $searchIn = $caseSensitive ? $content : strtolower($content);
                $searchFor = $caseSensitive ? $searchTerm : $searchLower;
                $pos = 0;
                $contexts = [];

                while (($pos = strpos($searchIn, $searchFor, $pos)) !== false) {
                    $start = max(0, $pos - $contextChars);
                    $end = min(strlen($content), $pos + strlen($searchTerm) + $contextChars);

                    $before = substr($content, $start, $pos - $start);
                    $match = substr($content, $pos, strlen($searchTerm));
                    $after = substr($content, $pos + strlen($searchTerm), $end - $pos - strlen($searchTerm));

                    $before = preg_replace('/\s+/', ' ', $before);
                    $after = preg_replace('/\s+/', ' ', $after);

                    $contextStr = ($start > 0 ? '...' : '') . $before . '[' . $match . ']' . $after . ($end < strlen($content) ? '...' : '');
                    $contexts[] = $contextStr;

                    $pos += strlen($searchTerm);
                }

                if ($includeContext && !empty($contexts)) {
                    // One row per occurrence
                    foreach ($contexts as $idx => $ctx) {
                        $exportRow = [];
                        foreach ($validExportColumns as $col) {
                            $exportRow[$col] = $row[$col] ?? '';
                        }
                        $exportRow['match_number'] = $idx + 1;
                        $exportRow['match_context'] = $ctx;
                        $exportData[] = $exportRow;
                    }
                } else {
                    // One row per record
                    $exportRow = [];
                    foreach ($validExportColumns as $col) {
                        $exportRow[$col] = $row[$col] ?? '';
                    }
                    $exportRow['match_count'] = count($contexts);
                    if ($includeContext) {
                        $exportRow['all_contexts'] = implode(' | ', $contexts);
                    }
                    $exportData[] = $exportRow;
                }
            }

            // Output based on format
            if ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="search_export_' . $tableName . '_' . date('Y-m-d_His') . '.json"');
                echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                // CSV
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="search_export_' . $tableName . '_' . date('Y-m-d_His') . '.csv"');

                $output = fopen('php://output', 'w');
                // BOM for Excel UTF-8 compatibility
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                // Header row
                if (!empty($exportData)) {
                    fputcsv($output, array_keys($exportData[0]));
                    foreach ($exportData as $row) {
                        fputcsv($output, $row);
                    }
                }
                fclose($output);
            }
            exit;
        }

    } catch (Exception $e) {
        sendJSON(['success' => false, 'error' => $e->getMessage()], 500);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Search Tool</title>
    <style>
        :root { --primary: #3498db; --bg: #f5f7fa; --text: #2c3e50; --match: #e74c3c; --match-bg: #ffeaa7; }
        body { font-family: sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }

        .container { max-width: 1200px; margin: 0 auto; }

        h1 { margin: 0 0 10px 0; color: var(--text); }
        .subtitle { color: #666; margin-bottom: 20px; }

        /* Search Form */
        .search-panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 15px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
        }
        .form-group select, .form-group input {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 180px;
        }
        .form-group input[type="number"] { width: 80px; min-width: auto; }
        .form-group select:focus, .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(52,152,219,0.2);
        }
        .form-group.search-term { flex: 1; }
        .form-group.search-term input { width: 100%; min-width: 200px; }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
        }
        .checkbox-group input { width: auto; min-width: auto; }

        button {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        button:hover { background: #2980b9; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Results */
        .results-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .results-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .results-stats { font-size: 14px; color: #666; }
        .results-stats strong { color: var(--text); }
        .results-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .results-actions button { padding: 8px 16px; font-size: 13px; }
        .btn-export { background: #27ae60; }
        .btn-export:hover { background: #219a52; }
        .btn-extract { background: #9b59b6; }
        .btn-extract:hover { background: #8e44ad; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }
        .pagination button { padding: 8px 16px; }
        .pagination .page-info { font-size: 14px; color: #666; }

        /* Extracted Values Panel */
        .extracted-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-top: 20px;
            overflow: hidden;
        }
        .extracted-header {
            padding: 15px 20px;
            background: #9b59b6;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .extracted-header h3 { margin: 0; font-size: 16px; }
        .extracted-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .extracted-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 20px;
            border-bottom: 1px solid #eee;
            font-family: monospace;
        }
        .extracted-item:hover { background: #f8f9fa; }
        .extracted-item .value { color: #2c3e50; }
        .extracted-item .count { color: #888; font-size: 12px; }

        /* Export Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { margin: 0; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
            padding: 0;
            line-height: 1;
        }
        .modal-close:hover { color: #333; }
        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .column-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .column-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
        }
        .column-item input { margin: 0; }
        .column-item label { font-weight: normal; text-transform: none; cursor: pointer; }
        .column-item .col-type { color: #888; font-size: 12px; }
        .select-buttons { margin-bottom: 10px; display: flex; gap: 10px; }
        .select-buttons button { padding: 4px 10px; font-size: 12px; background: #95a5a6; }
        .export-options { margin-top: 15px; }
        .export-options label { display: block; margin-bottom: 8px; font-weight: normal; text-transform: none; }
        .export-options select { width: 100%; }

        .results-list { padding: 0; }

        .result-item {
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item:hover { background: #fafbfc; }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .result-id {
            font-weight: 600;
            color: var(--primary);
        }
        .result-meta {
            font-size: 12px;
            color: #888;
        }

        .occurrence {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 8px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .occurrence:last-child { margin-bottom: 0; }

        .match-highlight {
            background: var(--match-bg);
            color: var(--match);
            font-weight: bold;
            padding: 1px 3px;
            border-radius: 2px;
        }

        .context-text { color: #555; }
        .ellipsis { color: #999; }

        /* States */
        .state-msg {
            text-align: center;
            padding: 50px;
            color: #888;
        }
        .state-msg.error { color: #e74c3c; }

        /* Loading */
        .loading { opacity: 0.6; pointer-events: none; }

        @media (max-width: 768px) {
            .form-row { flex-direction: column; }
            .form-group select, .form-group input { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Content Search Tool</h1>
        <p class="subtitle">Search for text patterns and view surrounding context. Useful for finding placeholders before import.</p>

        <div class="search-panel">
            <div class="form-row">
                <div class="form-group">
                    <label for="tableSelect">Table</label>
                    <select id="tableSelect" onchange="loadColumns()">
                        <option value="">-- Select Table --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="columnSelect">Column</label>
                    <select id="columnSelect" disabled>
                        <option value="">-- Select Column --</option>
                    </select>
                </div>
                <div class="form-group search-term">
                    <label for="searchTerm">Search Term</label>
                    <input type="text" id="searchTerm" placeholder="e.g., {{PLACEHOLDER}} or placeholder" onkeypress="if(event.key==='Enter') doSearch()">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="contextChars">Context (chars)</label>
                    <input type="number" id="contextChars" value="60" min="20" max="200">
                </div>
                <div class="form-group">
                    <label for="resultLimit">Rows per page</label>
                    <input type="number" id="resultLimit" value="100" min="1" max="1000">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="caseSensitive">
                        <label for="caseSensitive" style="text-transform: none; font-weight: normal;">Case sensitive</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button onclick="doSearch()" id="searchBtn">Search</button>
                </div>
            </div>
        </div>

        <div class="results-panel" id="resultsPanel" style="display: none;">
            <div class="results-header">
                <div class="results-stats" id="resultsStats"></div>
                <div class="results-actions">
                    <button class="btn-extract" onclick="showExtractModal()">Extract Values</button>
                    <button class="btn-export" onclick="showExportModal()">Export Results</button>
                </div>
            </div>
            <div class="results-list" id="resultsList"></div>
            <div class="pagination" id="pagination" style="display: none;">
                <button onclick="prevPage()" id="btnPrev">Previous</button>
                <span class="page-info" id="pageInfo">Page 1</span>
                <button onclick="nextPage()" id="btnNext">Next</button>
            </div>
        </div>

        <!-- Extracted Values Panel -->
        <div class="extracted-panel" id="extractedPanel" style="display: none;">
            <div class="extracted-header">
                <h3 id="extractedTitle">Extracted Values</h3>
                <button onclick="hideExtractedPanel()" style="background: rgba(255,255,255,0.2);">Close</button>
            </div>
            <div class="extracted-list" id="extractedList"></div>
        </div>

        <!-- Export Modal -->
        <div class="modal-overlay" id="exportModal" style="display: none;" onclick="if(event.target===this) hideExportModal()">
            <div class="modal">
                <div class="modal-header">
                    <h3>Export Search Results</h3>
                    <button class="modal-close" onclick="hideExportModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select columns to include:</label>
                    <div class="select-buttons">
                        <button onclick="selectAllColumns()">Select All</button>
                        <button onclick="selectNoColumns()">Select None</button>
                    </div>
                    <div class="column-list" id="exportColumnList">
                        <!-- Populated dynamically -->
                    </div>

                    <div class="export-options">
                        <label>
                            <input type="checkbox" id="exportIncludeContext" checked>
                            Include match context (one row per match)
                        </label>
                        <label style="margin-top: 10px;">
                            Export format:
                            <select id="exportFormat">
                                <option value="csv">CSV (Excel compatible)</option>
                                <option value="json">JSON</option>
                            </select>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="secondary" onclick="hideExportModal()">Cancel</button>
                    <button class="btn-export" onclick="doExport()">Download Export</button>
                </div>
            </div>
        </div>

        <!-- Extract Modal -->
        <div class="modal-overlay" id="extractModal" style="display: none;" onclick="if(event.target===this) hideExtractModal()">
            <div class="modal">
                <div class="modal-header">
                    <h3>Extract Unique Values</h3>
                    <button class="modal-close" onclick="hideExtractModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin-top: 0; color: #666;">Extract and deduplicate values from your search results using a regex pattern.</p>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>Preset Patterns</label>
                        <select id="extractPreset" onchange="applyExtractPreset()" style="width: 100%;">
                            <option value="">-- Select a preset --</option>
                            <option value='data-basename="([^"]+)"'>Placeholder data-basename attributes</option>
                            <option value="\\{\\{([A-Z_]+)\\}\\}">{{PLACEHOLDER}} style variables</option>
                            <option value="\\[\\[([^\\]]+)\\]\\]">[[PLACEHOLDER]] style variables</option>
                            <option value='href="([^"]+)"'>href URLs</option>
                            <option value='src="([^"]+)"'>src URLs</option>
                            <option value="custom">Custom pattern...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Regex Pattern (use capture group for value)</label>
                        <input type="text" id="extractPattern" style="width: 100%; font-family: monospace;" placeholder='e.g., data-basename="([^"]+)"'>
                        <small style="color: #888; display: block; margin-top: 5px;">The first capture group () will be extracted as the value</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="secondary" onclick="hideExtractModal()">Cancel</button>
                    <button class="btn-extract" onclick="doExtract()">Extract Values</button>
                </div>
            </div>
        </div>

        <div id="stateMessage" class="state-msg">
            Select a table and column, then enter a search term to find content.
        </div>
    </div>

    <script>
        let currentIdColumn = 'id';
        let currentSearchData = null;
        let allTableColumns = [];
        let currentOffset = 0;
        let currentLimit = 100;
        let currentTotalRows = 0;

        document.addEventListener('DOMContentLoaded', loadTables);

        async function loadTables() {
            try {
                const res = await fetch('?action=list_tables');
                const data = await res.json();

                const select = document.getElementById('tableSelect');
                select.innerHTML = '<option value="">-- Select Table --</option>';

                if (data.tables) {
                    data.tables.forEach(table => {
                        const opt = document.createElement('option');
                        opt.value = table;
                        opt.textContent = table;
                        select.appendChild(opt);
                    });
                }
            } catch (err) {
                console.error(err);
                showMessage('Failed to load tables', true);
            }
        }

        async function loadColumns() {
            const table = document.getElementById('tableSelect').value;
            const colSelect = document.getElementById('columnSelect');

            colSelect.innerHTML = '<option value="">-- Select Column --</option>';
            colSelect.disabled = true;

            if (!table) return;

            try {
                const res = await fetch(`?action=get_columns&table=${encodeURIComponent(table)}`);
                const data = await res.json();

                if (data.columns) {
                    // Add text-like columns first, then others
                    const textCols = data.columns.filter(c => c.isTextLike);
                    const otherCols = data.columns.filter(c => !c.isTextLike);

                    if (textCols.length > 0) {
                        const group1 = document.createElement('optgroup');
                        group1.label = 'Text Columns (recommended)';
                        textCols.forEach(col => {
                            const opt = document.createElement('option');
                            opt.value = col.name;
                            opt.textContent = `${col.name} (${col.type})`;
                            group1.appendChild(opt);
                        });
                        colSelect.appendChild(group1);
                    }

                    if (otherCols.length > 0) {
                        const group2 = document.createElement('optgroup');
                        group2.label = 'Other Columns';
                        otherCols.forEach(col => {
                            const opt = document.createElement('option');
                            opt.value = col.name;
                            opt.textContent = `${col.name} (${col.type})`;
                            group2.appendChild(opt);
                        });
                        colSelect.appendChild(group2);
                    }

                    colSelect.disabled = false;
                }
            } catch (err) {
                console.error(err);
                showMessage('Failed to load columns', true);
            }
        }

        async function doSearch(resetOffset = true) {
            const table = document.getElementById('tableSelect').value;
            const column = document.getElementById('columnSelect').value;
            const term = document.getElementById('searchTerm').value.trim();
            const context = document.getElementById('contextChars').value;
            const limit = parseInt(document.getElementById('resultLimit').value) || 100;
            const caseSensitive = document.getElementById('caseSensitive').checked ? '1' : '0';

            if (!table || !column || !term) {
                showMessage('Please select a table, column, and enter a search term.', true);
                return;
            }

            if (resetOffset) {
                currentOffset = 0;
            }
            currentLimit = limit;

            const btn = document.getElementById('searchBtn');
            btn.disabled = true;
            btn.textContent = 'Searching...';
            showMessage('Searching...');

            try {
                const url = `?action=search&table=${encodeURIComponent(table)}&column=${encodeURIComponent(column)}&term=${encodeURIComponent(term)}&context=${context}&limit=${limit}&offset=${currentOffset}&case_sensitive=${caseSensitive}`;
                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                currentIdColumn = data.idColumn || 'id';
                currentTotalRows = data.totalRows || 0;
                currentSearchData = {
                    table: table,
                    column: column,
                    term: term,
                    context: context,
                    caseSensitive: caseSensitive,
                    results: data
                };
                renderResults(data);
                updatePagination(data);
            } catch (err) {
                console.error(err);
                showMessage('Error: ' + err.message, true);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Search';
            }
        }

        function updatePagination(data) {
            const pagination = document.getElementById('pagination');
            const pageInfo = document.getElementById('pageInfo');
            const btnPrev = document.getElementById('btnPrev');
            const btnNext = document.getElementById('btnNext');

            if (data.totalRows <= data.limit) {
                pagination.style.display = 'none';
                return;
            }

            pagination.style.display = 'flex';
            const currentPage = Math.floor(currentOffset / currentLimit) + 1;
            const totalPages = Math.ceil(data.totalRows / currentLimit);

            pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${data.totalRows} total rows)`;
            btnPrev.disabled = currentOffset === 0;
            btnNext.disabled = !data.hasMore;
        }

        function nextPage() {
            currentOffset += currentLimit;
            doSearch(false);
        }

        function prevPage() {
            currentOffset = Math.max(0, currentOffset - currentLimit);
            doSearch(false);
        }

        function renderResults(data) {
            const panel = document.getElementById('resultsPanel');
            const stats = document.getElementById('resultsStats');
            const list = document.getElementById('resultsList');
            const stateMsg = document.getElementById('stateMessage');

            if (data.results.length === 0) {
                panel.style.display = 'none';
                showMessage(`No matches found for "${data.searchTerm}" in ${data.table}.${data.column}`);
                return;
            }

            stateMsg.style.display = 'none';
            panel.style.display = 'block';

            stats.innerHTML = `
                Found <strong>${data.totalMatches}</strong> match${data.totalMatches !== 1 ? 'es' : ''}
                across <strong>${data.rowsMatched}</strong> row${data.rowsMatched !== 1 ? 's' : ''}
                in <strong>${data.table}.${data.column}</strong>
                for "<strong>${escapeHtml(data.searchTerm)}</strong>"
            `;

            let html = '';
            data.results.forEach(result => {
                const idDisplay = result.row_id !== null ? `${currentIdColumn}: ${result.row_id}` : 'Row';
                html += `
                    <div class="result-item">
                        <div class="result-header">
                            <span class="result-id">${escapeHtml(idDisplay)}</span>
                            <span class="result-meta">${result.total_matches} match${result.total_matches !== 1 ? 'es' : ''} | ${formatBytes(result.content_length)}</span>
                        </div>
                `;

                result.occurrences.forEach((occ, idx) => {
                    html += `
                        <div class="occurrence">
                            <span class="context-text">${escapeHtml(occ.before)}</span><span class="match-highlight">${escapeHtml(occ.match)}</span><span class="context-text">${escapeHtml(occ.after)}</span>
                        </div>
                    `;
                });

                html += '</div>';
            });

            list.innerHTML = html;
        }

        function showMessage(msg, isError = false) {
            const el = document.getElementById('stateMessage');
            const panel = document.getElementById('resultsPanel');
            panel.style.display = 'none';
            el.style.display = 'block';
            el.className = 'state-msg' + (isError ? ' error' : '');
            el.textContent = msg;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' chars';
            return (bytes / 1024).toFixed(1) + ' KB';
        }

        // Export Functions
        async function showExportModal() {
            if (!currentSearchData) return;

            const modal = document.getElementById('exportModal');
            const columnList = document.getElementById('exportColumnList');

            // Load all columns for the table
            try {
                const res = await fetch(`?action=get_all_columns&table=${encodeURIComponent(currentSearchData.table)}`);
                const data = await res.json();

                if (data.columns) {
                    allTableColumns = data.columns;
                    columnList.innerHTML = '';

                    data.columns.forEach(col => {
                        const div = document.createElement('div');
                        div.className = 'column-item';
                        div.innerHTML = `
                            <input type="checkbox" id="exp_${col.name}" value="${col.name}" checked>
                            <label for="exp_${col.name}">${col.name} <span class="col-type">(${col.type})</span></label>
                        `;
                        columnList.appendChild(div);
                    });
                }
            } catch (err) {
                console.error(err);
                alert('Failed to load columns');
                return;
            }

            modal.style.display = 'flex';
        }

        function hideExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }

        function selectAllColumns() {
            document.querySelectorAll('#exportColumnList input[type="checkbox"]').forEach(cb => cb.checked = true);
        }

        function selectNoColumns() {
            document.querySelectorAll('#exportColumnList input[type="checkbox"]').forEach(cb => cb.checked = false);
        }

        function doExport() {
            if (!currentSearchData) return;

            // Get selected columns
            const selectedColumns = [];
            document.querySelectorAll('#exportColumnList input[type="checkbox"]:checked').forEach(cb => {
                selectedColumns.push(cb.value);
            });

            if (selectedColumns.length === 0) {
                alert('Please select at least one column to export.');
                return;
            }

            const includeContext = document.getElementById('exportIncludeContext').checked ? '1' : '0';
            const format = document.getElementById('exportFormat').value;

            // Build export URL
            const params = new URLSearchParams({
                action: 'export',
                table: currentSearchData.table,
                column: currentSearchData.column,
                term: currentSearchData.term,
                context: currentSearchData.context,
                case_sensitive: currentSearchData.caseSensitive,
                export_columns: selectedColumns.join(','),
                include_context: includeContext,
                format: format,
                limit: 1000
            });

            // Trigger download
            window.location.href = '?' + params.toString();
            hideExportModal();
        }

        // Extract Functions
        function showExtractModal() {
            if (!currentSearchData) return;
            document.getElementById('extractModal').style.display = 'flex';
            document.getElementById('extractPreset').value = '';
            document.getElementById('extractPattern').value = '';
        }

        function hideExtractModal() {
            document.getElementById('extractModal').style.display = 'none';
        }

        function applyExtractPreset() {
            const preset = document.getElementById('extractPreset').value;
            const patternInput = document.getElementById('extractPattern');

            if (preset && preset !== 'custom') {
                patternInput.value = preset;
            } else if (preset === 'custom') {
                patternInput.value = '';
                patternInput.focus();
            }
        }

        async function doExtract() {
            if (!currentSearchData) return;

            const pattern = document.getElementById('extractPattern').value.trim();
            if (!pattern) {
                alert('Please enter a regex pattern.');
                return;
            }

            hideExtractModal();
            showMessage('Extracting values...');

            try {
                const params = new URLSearchParams({
                    action: 'extract_values',
                    table: currentSearchData.table,
                    column: currentSearchData.column,
                    term: currentSearchData.term,
                    extract_pattern: pattern,
                    case_sensitive: currentSearchData.caseSensitive,
                    limit: 5000
                });

                const res = await fetch('?' + params.toString());
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                renderExtractedValues(data);
            } catch (err) {
                console.error(err);
                showMessage('Error: ' + err.message, true);
            }
        }

        function renderExtractedValues(data) {
            const panel = document.getElementById('extractedPanel');
            const title = document.getElementById('extractedTitle');
            const list = document.getElementById('extractedList');

            title.textContent = `${data.uniqueValues} Unique Values (${data.totalOccurrences} total occurrences)`;

            if (data.values.length === 0) {
                list.innerHTML = '<div style="padding: 20px; text-align: center; color: #888;">No values found matching the pattern.</div>';
            } else {
                let html = '';
                data.values.forEach(item => {
                    html += `<div class="extracted-item">
                        <span class="value">${escapeHtml(item.value)}</span>
                        <span class="count">${item.occurrences}x</span>
                    </div>`;
                });
                list.innerHTML = html;
            }

            panel.style.display = 'block';
        }

        function hideExtractedPanel() {
            document.getElementById('extractedPanel').style.display = 'none';
        }
    </script>
</body>
</html>
