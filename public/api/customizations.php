<?php
/**
 * Content Customization CRUD API
 *
 * GET    /api/customizations.php?company_id=X                     — list all customizations for a company
 * GET    /api/customizations.php?company_id=X&base_content_id=Y   — list for a specific template
 * GET    /api/customizations.php?id=X                             — get single customization with full HTML
 * GET    /api/customizations.php?id=X&action=preview              — generate a preview link for a customization
 * POST   /api/customizations.php                                  — create customization
 * PUT    /api/customizations.php?id=X                             — update customization (partial)
 * DELETE /api/customizations.php?id=X                             — delete customization
 *
 * Requires: Bearer token authentication + VPN access
 */

require_once '/var/www/html/public/api/bootstrap.php';
require_once '/var/www/html/lib/BrandKitTransformer.php';

validateBearerToken($config);
validateVpnAccess();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        // ── Preview link generation (Story 2.3) ──
        // GET ?id=X&action=preview — generate a preview URL for a customization
        if (!empty($_GET['id']) && !empty($_GET['action']) && $_GET['action'] === 'preview') {
            $custId = $_GET['id'];
            $cust = $db->fetchOne(
                'SELECT * FROM content_customizations WHERE id = :id',
                [':id' => $custId]
            );
            if (!$cust) {
                sendJSON(['success' => false, 'error' => 'Customization not found'], 404);
            }

            $baseContentId = $cust['base_content_id'];
            $companyId = $cust['company_id'];

            // Verify base content exists
            $content = $db->fetchOne(
                'SELECT title FROM content WHERE id = :id',
                [':id' => $baseContentId]
            );
            if (!$content) {
                sendJSON(['success' => false, 'error' => 'Base content not found'], 404);
            }

            // Generate unique IDs for preview (follows upload.php generatePreviewLink pattern)
            $trainingId = generateUUID4();
            $trainingTrackingId = generateUUID4();
            $uniqueTrackingId = generateUUID4();

            $trainingTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training';
            $trackingTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking';

            // Create training record with the customization's company_id
            $db->insert($trainingTable, [
                'id' => $trainingId,
                'company_id' => $companyId,
                'name' => 'Preview: ' . ($content['title'] ?? 'Customization'),
                'description' => 'Auto-generated preview for customization ' . $custId,
                'training_type' => 'preview',
                'training_content_id' => $baseContentId,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Create training_tracking record
            $db->insert($trackingTable, [
                'id' => $trainingTrackingId,
                'training_id' => $trainingId,
                'recipient_id' => 'preview',
                'unique_tracking_id' => $uniqueTrackingId,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Build preview URL: /launch.php/{contentId}/{trackingId}?customization_id=X
            $contentIdNoDash = str_replace('-', '', $baseContentId);
            $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
            $previewUrl = rtrim($config['app']['external_url'], '/')
                . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash
                . '?customization_id=' . urlencode($custId);

            sendJSON([
                'success' => true,
                'preview_url' => $previewUrl,
                'customization_id' => $custId,
                'status' => $cust['status'],
            ]);
        }

        // Single customization by ID
        if (!empty($_GET['id'])) {
            $cust = $db->fetchOne(
                'SELECT * FROM content_customizations WHERE id = :id',
                [':id' => $_GET['id']]
            );
            if (!$cust) {
                sendJSON(['success' => false, 'error' => 'Customization not found'], 404);
            }

            $cust['customization_data'] = json_decode($cust['customization_data'] ?? 'null', true);

            sendJSON(['success' => true, 'customization' => $cust]);
        }

        // List by company_id
        if (empty($_GET['company_id'])) {
            sendJSON(['success' => false, 'error' => 'company_id or id parameter required'], 400);
        }

        $companyId = $_GET['company_id'];
        $params = [':company_id' => $companyId];

        $sql = 'SELECT cc.id, cc.company_id, cc.base_content_id, cc.brand_kit_id, cc.title, cc.status, cc.created_by, cc.created_at, cc.updated_at, c.content_type, c.thumbnail_filename'
             . ' FROM content_customizations cc'
             . ' LEFT JOIN content c ON c.id = cc.base_content_id'
             . ' WHERE cc.company_id = :company_id';

        // Filter by base content
        if (!empty($_GET['base_content_id'])) {
            $sql .= ' AND cc.base_content_id = :base_content_id';
            $params[':base_content_id'] = $_GET['base_content_id'];
        }

        // Filter by status
        if (!empty($_GET['status'])) {
            $sql .= ' AND cc.status = :status';
            $params[':status'] = $_GET['status'];
        }

        $sql .= ' ORDER BY cc.updated_at DESC';

        $customizations = $db->fetchAll($sql, $params);

        sendJSON(['success' => true, 'customizations' => $customizations, 'count' => count($customizations)]);

    } catch (Exception $e) {
        error_log('Customization GET Error: ' . $e->getMessage());
        sendJSON(['success' => false, 'error' => 'Failed to retrieve customizations'], 500);
    }
}

// ── POST (create) ────────────────────────────────────────────────────────
if ($method === 'POST') {
    try {
        $input = getJSONInput();
        validateRequired($input, ['company_id', 'base_content_id']);

        $companyId = $input['company_id'];
        $baseContentId = $input['base_content_id'];

        // Verify base content exists
        $content = $db->fetchOne(
            'SELECT id, title, content_type, entry_body_html, email_body_html FROM content WHERE id = :id',
            [':id' => $baseContentId]
        );
        if (!$content) {
            sendJSON(['success' => false, 'error' => 'Base content not found'], 404);
        }

        // Verify brand kit exists if provided
        $brandKitId = $input['brand_kit_id'] ?? null;
        $kit = null;
        if ($brandKitId) {
            $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $brandKitId]);
            if (!$kit) {
                sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
            }
            $kit['saved_colors'] = json_decode($kit['saved_colors'] ?? '[]', true);
            $kit['custom_font_urls'] = json_decode($kit['custom_font_urls'] ?? '[]', true);
        }

        // Determine HTML
        $customizedHtml = $input['customized_html'] ?? null;

        if (!$customizedHtml && $kit) {
            // Auto-apply brand kit to base HTML
            $baseHtml = ($content['content_type'] === 'email')
                ? ($content['email_body_html'] ?: $content['entry_body_html'])
                : $content['entry_body_html'];

            if ($baseHtml) {
                $result = BrandKitTransformer::apply($baseHtml, $kit);
                $customizedHtml = $result['html'];

                // Build initial customization_data from transformations
                if (!isset($input['customization_data'])) {
                    $input['customization_data'] = [
                        'brand_kit_applied' => true,
                        'brand_kit_id' => $brandKitId,
                        'element_edits' => $result['transformations']
                    ];
                }
            }
        }

        $id = generateUUID4();
        $status = $input['status'] ?? 'draft';

        if (!in_array($status, ['draft', 'published'])) {
            sendJSON(['success' => false, 'error' => 'status must be "draft" or "published"'], 400);
        }

        // If publishing, enforce one published per (company_id, base_content_id)
        if ($status === 'published') {
            $db->query(
                'UPDATE content_customizations SET status = :draft WHERE company_id = :company_id AND base_content_id = :base_content_id AND status = :published',
                [':draft' => 'draft', ':company_id' => $companyId, ':base_content_id' => $baseContentId, ':published' => 'published']
            );
        }

        $data = [
            'id' => $id,
            'company_id' => $companyId,
            'base_content_id' => $baseContentId,
            'brand_kit_id' => $brandKitId,
            'title' => $input['title'] ?? $content['title'],
            'customized_html' => $customizedHtml,
            'customization_data' => json_encode($input['customization_data'] ?? null),
            'status' => $status,
            'created_by' => $input['created_by'] ?? null,
        ];

        $db->insert('content_customizations', $data);

        // Fetch the created record
        $created = $db->fetchOne('SELECT * FROM content_customizations WHERE id = :id', [':id' => $id]);
        $created['customization_data'] = json_decode($created['customization_data'] ?? 'null', true);

        sendJSON(['success' => true, 'customization' => $created], 201);

    } catch (Exception $e) {
        error_log('Customization POST Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to create customization',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

// ── PUT (update) ─────────────────────────────────────────────────────────
if ($method === 'PUT') {
    try {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJSON(['success' => false, 'error' => 'id query parameter required'], 400);
        }

        $cust = $db->fetchOne('SELECT * FROM content_customizations WHERE id = :id', [':id' => $id]);
        if (!$cust) {
            sendJSON(['success' => false, 'error' => 'Customization not found'], 404);
        }

        $input = getJSONInput();
        if (empty($input)) {
            sendJSON(['success' => false, 'error' => 'No update data provided'], 400);
        }

        $allowedFields = ['title', 'customized_html', 'status', 'brand_kit_id', 'created_by'];
        $data = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        // Handle JSONB field
        if (array_key_exists('customization_data', $input)) {
            $data['customization_data'] = json_encode($input['customization_data']);
        }

        if (!empty($data['status']) && !in_array($data['status'], ['draft', 'published'])) {
            sendJSON(['success' => false, 'error' => 'status must be "draft" or "published"'], 400);
        }

        // If publishing, enforce one published per (company_id, base_content_id)
        if (!empty($data['status']) && $data['status'] === 'published') {
            $db->query(
                'UPDATE content_customizations SET status = :draft WHERE company_id = :company_id AND base_content_id = :base_content_id AND status = :published AND id != :id',
                [':draft' => 'draft', ':company_id' => $cust['company_id'], ':base_content_id' => $cust['base_content_id'], ':published' => 'published', ':id' => $id]
            );
        }

        if (empty($data)) {
            sendJSON(['success' => false, 'error' => 'No valid fields to update'], 400);
        }

        $db->update('content_customizations', $data, 'id = :where_id', [':where_id' => $id]);

        $updated = $db->fetchOne('SELECT * FROM content_customizations WHERE id = :id', [':id' => $id]);
        $updated['customization_data'] = json_decode($updated['customization_data'] ?? 'null', true);

        sendJSON(['success' => true, 'customization' => $updated]);

    } catch (Exception $e) {
        error_log('Customization PUT Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to update customization',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    try {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJSON(['success' => false, 'error' => 'id query parameter required'], 400);
        }

        $cust = $db->fetchOne('SELECT id FROM content_customizations WHERE id = :id', [':id' => $id]);
        if (!$cust) {
            sendJSON(['success' => false, 'error' => 'Customization not found'], 404);
        }

        $db->query('DELETE FROM content_customizations WHERE id = :id', [':id' => $id]);

        sendJSON(['success' => true, 'message' => 'Customization deleted', 'id' => $id]);

    } catch (Exception $e) {
        error_log('Customization DELETE Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to delete customization',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

sendJSON(['success' => false, 'error' => 'Method not allowed. Use GET, POST, PUT, or DELETE.'], 405);
