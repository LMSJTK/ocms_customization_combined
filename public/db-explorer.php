<?php
/**
 * Universal Database Explorer
 * Lists all tables in the configured schema and provides a paged view of their content.
 * Features: Search filtering (by column), sorting, pagination
 */

require_once __DIR__ . '/api/bootstrap.php';

// Validate VPN access (internal tool)
validateVpnAccess();

// Configuration
$SCHEMA = $config['database']['schema'] ?? 'public';
$DB_TYPE = $db->getDbType(); // 'pgsql' or 'mysql'

// --- API HANDLING ---

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {

        // 1. List Tables
        if ($_GET['action'] === 'list_tables') {
            $tables = [];

            if ($DB_TYPE === 'pgsql') {
                // Query pg_class to get Tables ('r'), Views ('v'), and Foreign Tables ('f')
                $sql = "SELECT c.relname as name
                        FROM pg_catalog.pg_class c
                        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = :schema
                        AND c.relkind IN ('r', 'v', 'f', 'p') -- r=table, v=view, f=foreign, p=partitioned
                        ORDER BY c.relname";
                $rows = $db->fetchAll($sql, [':schema' => $SCHEMA]);
                $tables = array_column($rows, 'name');
            } else {
                // MySQL
                $sql = "SELECT table_name as name
                        FROM information_schema.tables
                        WHERE table_schema = :dbname
                        ORDER BY table_name";
                $rows = $db->fetchAll($sql, [':dbname' => $config['database']['dbname']]);
                $tables = array_column($rows, 'name');
            }

            sendJSON(['success' => true, 'tables' => $tables]);
        }

        // 2. Fetch Table Data (with search, sort, pagination)
        if ($_GET['action'] === 'fetch_data') {
            $tableName = $_GET['table'] ?? null;
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            $sortColumn = $_GET['sort'] ?? null;
            $sortDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            $searchColumn = $_GET['search_column'] ?? null;
            $searchTerm = $_GET['search'] ?? null;

            if (!$tableName) {
                throw new Exception("Table name required");
            }

            // Security: Basic validation to prevent SQL injection on table names
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new Exception("Invalid table name format");
            }

            // Construct fully qualified name
            $fullTableName = ($DB_TYPE === 'pgsql') ? "{$SCHEMA}.{$tableName}" : $tableName;

            // Get Columns with data types
            $columns = [];
            $columnTypes = [];
            if ($DB_TYPE === 'pgsql') {
                $colSql = "SELECT column_name, data_type
                           FROM information_schema.columns
                           WHERE table_schema = :schema AND table_name = :table
                           ORDER BY ordinal_position";
                $colRows = $db->fetchAll($colSql, [':schema' => $SCHEMA, ':table' => $tableName]);
                foreach ($colRows as $col) {
                    $columns[] = $col['column_name'];
                    $columnTypes[$col['column_name']] = $col['data_type'];
                }
            } else {
                // MySQL
                $colSql = "SELECT column_name, data_type
                           FROM information_schema.columns
                           WHERE table_schema = :dbname AND table_name = :table
                           ORDER BY ordinal_position";
                $colRows = $db->fetchAll($colSql, [':dbname' => $config['database']['dbname'], ':table' => $tableName]);
                foreach ($colRows as $col) {
                    $columns[] = $col['column_name'];
                    $columnTypes[$col['column_name']] = $col['data_type'];
                }
            }

            // Build WHERE clause for search
            $whereClause = "";
            $params = [];

            if (!empty($searchTerm)) {
                if (!empty($searchColumn) && in_array($searchColumn, $columns)) {
                    // Search specific column
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $searchColumn)) {
                        throw new Exception("Invalid column name format");
                    }
                    if ($DB_TYPE === 'pgsql') {
                        $whereClause = " WHERE \"{$searchColumn}\"::text ILIKE :search";
                    } else {
                        $whereClause = " WHERE CAST(`{$searchColumn}` AS CHAR) LIKE :search";
                    }
                    $params[':search'] = '%' . $searchTerm . '%';
                } else {
                    // Search all columns
                    $searchConditions = [];
                    foreach ($columns as $col) {
                        if ($DB_TYPE === 'pgsql') {
                            $searchConditions[] = "\"{$col}\"::text ILIKE :search";
                        } else {
                            $searchConditions[] = "CAST(`{$col}` AS CHAR) LIKE :search";
                        }
                    }
                    if (!empty($searchConditions)) {
                        $whereClause = " WHERE (" . implode(" OR ", $searchConditions) . ")";
                        $params[':search'] = '%' . $searchTerm . '%';
                    }
                }
            }

            // Get Total Count (with search filter)
            $countSql = "SELECT COUNT(*) as total FROM $fullTableName" . $whereClause;
            $countResult = $db->fetchOne($countSql, $params);
            $total = $countResult['total'];

            // Validate and build ORDER BY clause
            $orderClause = "";
            if ($sortColumn && in_array($sortColumn, $columns)) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $sortColumn)) {
                    throw new Exception("Invalid sort column format");
                }
                if ($DB_TYPE === 'pgsql') {
                    $orderClause = " ORDER BY \"{$sortColumn}\" {$sortDir}";
                } else {
                    $orderClause = " ORDER BY `{$sortColumn}` {$sortDir}";
                }
            }

            // Fetch Rows
            $dataSql = "SELECT * FROM $fullTableName" . $whereClause . $orderClause . " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            $rows = $db->fetchAll($dataSql, $params);

            sendJSON([
                'success' => true,
                'table' => $tableName,
                'columns' => $columns,
                'columnTypes' => $columnTypes,
                'total' => $total,
                'rows' => $rows,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sortColumn,
                'dir' => $sortDir,
                'searchColumn' => $searchColumn,
                'searchTerm' => $searchTerm
            ]);
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
    <title>DB Explorer (<?php echo htmlspecialchars($SCHEMA); ?>)</title>
    <style>
        :root { --primary: #3498db; --bg: #f5f7fa; --text: #2c3e50; }
        body { font-family: sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .layout { display: flex; gap: 20px; height: calc(100vh - 40px); }

        /* Sidebar */
        .sidebar { width: 250px; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; flex-direction: column; overflow: hidden; }
        .sidebar-header { padding: 15px; border-bottom: 1px solid #eee; font-weight: bold; background: #34495e; color: white; }
        .table-list { overflow-y: auto; flex: 1; list-style: none; padding: 0; margin: 0; }
        .table-list li { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; transition: background 0.2s; }
        .table-list li:hover { background-color: #f0f2f5; }
        .table-list li.active { background-color: #e3f2fd; color: var(--primary); font-weight: 500; border-left: 4px solid var(--primary); }

        /* Main Content */
        .main { flex: 1; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; flex-direction: column; overflow: hidden; }
        .toolbar { padding: 15px; border-bottom: 1px solid #eee; display: flex; flex-direction: column; gap: 10px; }
        .toolbar-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .table-container { flex: 1; overflow: auto; padding: 0; }

        /* Search Controls */
        .search-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-controls select, .search-controls input[type="text"] {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;
        }
        .search-controls select { min-width: 150px; }
        .search-controls input[type="text"] { min-width: 200px; }
        .search-controls select:focus, .search-controls input:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(52,152,219,0.2);
        }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; position: sticky; top: 0; padding: 12px; text-align: left; border-bottom: 2px solid #ddd; z-index: 10; font-weight: 600; color: #555; cursor: pointer; user-select: none; }
        th:hover { background: #e9ecef; }
        th.sorted { background: #e3f2fd; color: var(--primary); }
        th .sort-icon { margin-left: 5px; opacity: 0.4; }
        th.sorted .sort-icon { opacity: 1; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; white-space: nowrap; max-width: 300px; overflow: hidden; text-overflow: ellipsis; }
        tr:hover { background-color: #f8f9fa; }

        /* Data type styling */
        .null { color: #999; font-style: italic; }
        .binary { color: #9b59b6; font-style: italic; }
        .json-cell { font-family: monospace; font-size: 11px; }

        /* Loading & Empty States */
        .state-msg { text-align: center; padding: 50px; color: #888; }

        /* Controls */
        button { background: var(--primary); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #2980b9; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.secondary { background: #95a5a6; }
        button.secondary:hover { background: #7f8c8d; }
        .pagination { display: flex; align-items: center; gap: 10px; font-size: 14px; }

        /* Filter indicator */
        .filter-indicator { background: #fff3cd; padding: 8px 15px; border-bottom: 1px solid #ffc107; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
        .filter-indicator .clear-btn { background: none; border: none; color: #856404; cursor: pointer; text-decoration: underline; padding: 0; font-size: 13px; }
        .filter-indicator .clear-btn:hover { color: #533f03; }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-header">Database Tables</div>
            <ul class="table-list" id="tableList">
                <li style="padding: 15px; color: #888;">Loading...</li>
            </ul>
        </aside>

        <main class="main">
            <div class="toolbar">
                <div class="toolbar-row">
                    <h2 id="currentTableName" style="margin: 0; font-size: 18px;">Select a table</h2>
                    <div class="pagination" id="paginationControls" style="display:none;">
                        <span id="recordCount">0 records</span>
                        <button onclick="prevPage()" id="btnPrev">Previous</button>
                        <span id="pageIndicator">Page 1</span>
                        <button onclick="nextPage()" id="btnNext">Next</button>
                    </div>
                </div>
                <div class="toolbar-row" id="searchRow" style="display:none;">
                    <div class="search-controls">
                        <select id="searchColumn">
                            <option value="">All Columns</option>
                        </select>
                        <input type="text" id="searchInput" placeholder="Search..." onkeypress="if(event.key==='Enter') doSearch()">
                        <button onclick="doSearch()">Search</button>
                        <button class="secondary" onclick="clearSearch()">Clear</button>
                    </div>
                </div>
            </div>
            <div id="filterIndicator" class="filter-indicator" style="display:none;">
                <span id="filterText"></span>
                <button class="clear-btn" onclick="clearSearch()">Clear filter</button>
            </div>
            <div class="table-container" id="tableContainer">
                <div class="state-msg">Select a table from the sidebar to view its data.</div>
            </div>
        </main>
    </div>

    <script>
        let currentTable = null;
        let currentOffset = 0;
        let limit = 50;
        let totalRecords = 0;
        let currentColumns = [];
        let currentColumnTypes = {};
        let sortColumn = null;
        let sortDir = 'ASC';
        let searchColumn = '';
        let searchTerm = '';

        // Init
        document.addEventListener('DOMContentLoaded', loadTableList);

        async function loadTableList() {
            try {
                const res = await fetch('?action=list_tables');
                const data = await res.json();

                const list = document.getElementById('tableList');
                list.innerHTML = '';

                if (data.tables && data.tables.length > 0) {
                    data.tables.forEach(table => {
                        const li = document.createElement('li');
                        li.textContent = table;
                        li.onclick = () => selectTable(table, li);
                        list.appendChild(li);
                    });
                } else {
                    list.innerHTML = '<li style="padding:15px;">No tables found</li>';
                }
            } catch (err) {
                console.error(err);
                alert("Failed to load tables. Check console.");
            }
        }

        function selectTable(tableName, element) {
            // Update UI active state
            document.querySelectorAll('.table-list li').forEach(el => el.classList.remove('active'));
            element.classList.add('active');

            currentTable = tableName;
            currentOffset = 0;
            sortColumn = null;
            sortDir = 'ASC';
            searchColumn = '';
            searchTerm = '';
            document.getElementById('searchInput').value = '';
            document.getElementById('searchColumn').value = '';
            document.getElementById('filterIndicator').style.display = 'none';

            loadData();
        }

        async function loadData() {
            if (!currentTable) return;

            const container = document.getElementById('tableContainer');
            const title = document.getElementById('currentTableName');
            const controls = document.getElementById('paginationControls');
            const searchRow = document.getElementById('searchRow');

            title.textContent = currentTable;
            container.innerHTML = '<div class="state-msg">Loading data...</div>';
            controls.style.display = 'none';
            searchRow.style.display = 'none';

            try {
                let url = `?action=fetch_data&table=${encodeURIComponent(currentTable)}&limit=${limit}&offset=${currentOffset}`;

                if (sortColumn) {
                    url += `&sort=${encodeURIComponent(sortColumn)}&dir=${sortDir}`;
                }
                if (searchTerm) {
                    url += `&search=${encodeURIComponent(searchTerm)}`;
                    if (searchColumn) {
                        url += `&search_column=${encodeURIComponent(searchColumn)}`;
                    }
                }

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                currentColumns = data.columns;
                currentColumnTypes = data.columnTypes || {};

                // Update search column dropdown
                updateSearchColumnDropdown(data.columns);
                searchRow.style.display = 'flex';

                // Update filter indicator
                updateFilterIndicator();

                renderTable(data);
                updatePagination(data);
            } catch (err) {
                container.innerHTML = `<div class="state-msg" style="color:red">Error: ${err.message}</div>`;
            }
        }

        function updateSearchColumnDropdown(columns) {
            const select = document.getElementById('searchColumn');
            const currentValue = select.value;
            select.innerHTML = '<option value="">All Columns</option>';
            columns.forEach(col => {
                const type = currentColumnTypes[col] || '';
                const option = document.createElement('option');
                option.value = col;
                option.textContent = type ? `${col} (${type})` : col;
                select.appendChild(option);
            });
            // Restore selection if still valid
            if (columns.includes(currentValue)) {
                select.value = currentValue;
            }
        }

        function updateFilterIndicator() {
            const indicator = document.getElementById('filterIndicator');
            const text = document.getElementById('filterText');

            if (searchTerm) {
                const colText = searchColumn ? `"${searchColumn}"` : 'all columns';
                text.textContent = `Filtering ${colText} containing "${searchTerm}"`;
                indicator.style.display = 'flex';
            } else {
                indicator.style.display = 'none';
            }
        }

        function renderTable(data) {
            const container = document.getElementById('tableContainer');

            if (data.rows.length === 0) {
                container.innerHTML = '<div class="state-msg">No data found' + (searchTerm ? ' matching your search.' : '.') + '</div>';
                return;
            }

            let html = '<table><thead><tr>';
            data.columns.forEach(col => {
                const isSorted = col === sortColumn;
                const sortClass = isSorted ? 'sorted' : '';
                const icon = isSorted ? (sortDir === 'ASC' ? '&#9650;' : '&#9660;') : '&#8645;';
                html += `<th class="${sortClass}" onclick="toggleSort('${col}')">${escapeHtml(col)}<span class="sort-icon">${icon}</span></th>`;
            });
            html += '</tr></thead><tbody>';

            data.rows.forEach(row => {
                html += '<tr>';
                data.columns.forEach(col => {
                    let val = row[col];
                    const dataType = currentColumnTypes[col] || '';

                    if (val === null) {
                        val = '<span class="null">null</span>';
                    } else if (dataType === 'bytea' || dataType === 'longblob' || dataType === 'blob') {
                        val = '<span class="binary">[BINARY]</span>';
                    } else if (typeof val === 'object') {
                        val = '<span class="json-cell">' + escapeHtml(JSON.stringify(val)) + '</span>';
                    } else {
                        val = escapeHtml(String(val));
                    }

                    html += `<td title="${escapeHtml(String(row[col] ?? ''))}">${val}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function toggleSort(column) {
            if (sortColumn === column) {
                sortDir = sortDir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                sortColumn = column;
                sortDir = 'ASC';
            }
            currentOffset = 0;
            loadData();
        }

        function doSearch() {
            searchColumn = document.getElementById('searchColumn').value;
            searchTerm = document.getElementById('searchInput').value.trim();
            currentOffset = 0;
            loadData();
        }

        function clearSearch() {
            searchColumn = '';
            searchTerm = '';
            document.getElementById('searchColumn').value = '';
            document.getElementById('searchInput').value = '';
            currentOffset = 0;
            loadData();
        }

        function updatePagination(data) {
            totalRecords = parseInt(data.total);
            const controls = document.getElementById('paginationControls');
            controls.style.display = 'flex';

            document.getElementById('recordCount').textContent = `${totalRecords} records`;

            const currentPage = Math.floor(currentOffset / limit) + 1;
            const totalPages = Math.ceil(totalRecords / limit) || 1;
            document.getElementById('pageIndicator').textContent = `Page ${currentPage} of ${totalPages}`;

            document.getElementById('btnPrev').disabled = currentOffset === 0;
            document.getElementById('btnNext').disabled = (currentOffset + limit) >= totalRecords;
        }

        function nextPage() {
            if ((currentOffset + limit) < totalRecords) {
                currentOffset += limit;
                loadData();
            }
        }

        function prevPage() {
            if (currentOffset >= limit) {
                currentOffset -= limit;
                loadData();
            }
        }
    </script>
</body>
</html>
