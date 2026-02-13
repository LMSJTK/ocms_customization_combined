<?php
/**
 * Brand Kit CRUD API
 *
 * GET    /api/brand-kits.php?company_id=X          — list all brand kits for a company
 * GET    /api/brand-kits.php?company_id=X&default=true — get the default brand kit
 * GET    /api/brand-kits.php?id=X                   — get a single brand kit with its assets
 * POST   /api/brand-kits.php                        — create a new brand kit
 * PUT    /api/brand-kits.php?id=X                   — update a brand kit (partial)
 * DELETE /api/brand-kits.php?id=X                   — delete a brand kit and its S3 assets
 *
 * Requires: Bearer token authentication + VPN access
 */

require_once '/var/www/html/public/api/bootstrap.php';

validateBearerToken($config);
validateVpnAccess();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        // Single brand kit by ID
        if (!empty($_GET['id'])) {
            $kit = $db->fetchOne(
                'SELECT * FROM brand_kits WHERE id = :id',
                [':id' => $_GET['id']]
            );
            if (!$kit) {
                sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
            }

            // Decode JSONB fields
            $kit['saved_colors'] = json_decode($kit['saved_colors'] ?? '[]', true);
            $kit['custom_font_urls'] = json_decode($kit['custom_font_urls'] ?? '[]', true);

            // Fetch associated assets
            $assets = $db->fetchAll(
                'SELECT id, asset_type, filename, s3_url, mime_type, file_size, created_at FROM brand_kit_assets WHERE brand_kit_id = :id ORDER BY created_at DESC',
                [':id' => $_GET['id']]
            );

            $kit['assets'] = $assets;

            sendJSON(['success' => true, 'brand_kit' => $kit]);
        }

        // List by company_id
        if (empty($_GET['company_id'])) {
            sendJSON(['success' => false, 'error' => 'company_id or id parameter required'], 400);
        }

        $companyId = $_GET['company_id'];

        // Default-only filter
        if (!empty($_GET['default']) && filter_var($_GET['default'], FILTER_VALIDATE_BOOLEAN)) {
            $kit = $db->fetchOne(
                'SELECT * FROM brand_kits WHERE company_id = :company_id AND is_default = :is_default',
                [':company_id' => $companyId, ':is_default' => ($db->getDbType() === 'pgsql' ? 'true' : 1)]
            );
            if (!$kit) {
                sendJSON(['success' => true, 'brand_kit' => null, 'message' => 'No default brand kit found']);
            }

            $kit['saved_colors'] = json_decode($kit['saved_colors'] ?? '[]', true);
            $kit['custom_font_urls'] = json_decode($kit['custom_font_urls'] ?? '[]', true);

            sendJSON(['success' => true, 'brand_kit' => $kit]);
        }

        // List all kits for a company
        $kits = $db->fetchAll(
            'SELECT * FROM brand_kits WHERE company_id = :company_id ORDER BY is_default DESC, created_at DESC',
            [':company_id' => $companyId]
        );

        foreach ($kits as &$k) {
            $k['saved_colors'] = json_decode($k['saved_colors'] ?? '[]', true);
            $k['custom_font_urls'] = json_decode($k['custom_font_urls'] ?? '[]', true);
        }
        unset($k);

        sendJSON(['success' => true, 'brand_kits' => $kits, 'count' => count($kits)]);

    } catch (Exception $e) {
        error_log('Brand Kit GET Error: ' . $e->getMessage());
        sendJSON(['success' => false, 'error' => 'Failed to retrieve brand kits'], 500);
    }
}

// ── POST (create) ────────────────────────────────────────────────────────
if ($method === 'POST') {
    try {
        $input = getJSONInput();
        validateRequired($input, ['company_id']);

        $companyId = $input['company_id'];
        $name = $input['name'] ?? 'Default';

        // Validate hex color format if provided
        $colorFields = ['primary_color', 'secondary_color', 'accent_color'];
        foreach ($colorFields as $field) {
            if (!empty($input[$field]) && !preg_match('/^#[a-f0-9]{6}$/i', $input[$field])) {
                sendJSON(['success' => false, 'error' => "$field must be a valid hex color (e.g. #4F46E5)"], 400);
            }
        }

        // Check name uniqueness per company
        $existing = $db->fetchOne(
            'SELECT id FROM brand_kits WHERE company_id = :company_id AND name = :name',
            [':company_id' => $companyId, ':name' => $name]
        );
        if ($existing) {
            sendJSON(['success' => false, 'error' => "A brand kit named '$name' already exists for this company"], 409);
        }

        $id = generateUUID4();

        // Determine if this should be the default (first kit for company, or explicitly requested)
        $isDefault = !empty($input['is_default']);
        $existingKits = $db->fetchOne(
            'SELECT COUNT(*) as cnt FROM brand_kits WHERE company_id = :company_id',
            [':company_id' => $companyId]
        );
        if (($existingKits['cnt'] ?? 0) == 0) {
            $isDefault = true; // First kit for company is always default
        }

        $db->beginTransaction();
        try {
            // If setting as default, clear any existing defaults
            if ($isDefault) {
                $db->query(
                    'UPDATE brand_kits SET is_default = :val WHERE company_id = :company_id',
                    [':val' => ($db->getDbType() === 'pgsql' ? 'false' : 0), ':company_id' => $companyId]
                );
            }

            $data = [
                'id' => $id,
                'company_id' => $companyId,
                'name' => $name,
                'primary_color' => $input['primary_color'] ?? null,
                'secondary_color' => $input['secondary_color'] ?? null,
                'accent_color' => $input['accent_color'] ?? null,
                'saved_colors' => json_encode($input['saved_colors'] ?? []),
                'primary_font' => $input['primary_font'] ?? null,
                'secondary_font' => $input['secondary_font'] ?? null,
                'custom_font_urls' => json_encode($input['custom_font_urls'] ?? []),
                'is_default' => $isDefault,
            ];

            $db->insert('brand_kits', $data);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        // Fetch the created record
        $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $id]);
        $kit['saved_colors'] = json_decode($kit['saved_colors'] ?? '[]', true);
        $kit['custom_font_urls'] = json_decode($kit['custom_font_urls'] ?? '[]', true);

        sendJSON(['success' => true, 'brand_kit' => $kit], 201);

    } catch (Exception $e) {
        error_log('Brand Kit POST Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to create brand kit',
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

        $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $id]);
        if (!$kit) {
            sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
        }

        $input = getJSONInput();
        if (empty($input)) {
            sendJSON(['success' => false, 'error' => 'No update data provided'], 400);
        }

        // Validate hex colors if provided
        $colorFields = ['primary_color', 'secondary_color', 'accent_color'];
        foreach ($colorFields as $field) {
            if (isset($input[$field]) && $input[$field] !== null && !preg_match('/^#[a-f0-9]{6}$/i', $input[$field])) {
                sendJSON(['success' => false, 'error' => "$field must be a valid hex color (e.g. #4F46E5)"], 400);
            }
        }

        // Check name uniqueness if changing name
        if (!empty($input['name']) && $input['name'] !== $kit['name']) {
            $existing = $db->fetchOne(
                'SELECT id FROM brand_kits WHERE company_id = :company_id AND name = :name AND id != :id',
                [':company_id' => $kit['company_id'], ':name' => $input['name'], ':id' => $id]
            );
            if ($existing) {
                sendJSON(['success' => false, 'error' => "A brand kit named '{$input['name']}' already exists for this company"], 409);
            }
        }

        // Allowed update fields
        $updateFields = [
            'name', 'logo_url', 'logo_filename', 'primary_color', 'secondary_color',
            'accent_color', 'primary_font', 'secondary_font', 'is_default'
        ];

        $data = [];
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        // Handle JSONB fields
        if (array_key_exists('saved_colors', $input)) {
            $data['saved_colors'] = json_encode($input['saved_colors']);
        }
        if (array_key_exists('custom_font_urls', $input)) {
            $data['custom_font_urls'] = json_encode($input['custom_font_urls']);
        }

        if (empty($data)) {
            sendJSON(['success' => false, 'error' => 'No valid fields to update'], 400);
        }

        $db->beginTransaction();
        try {
            // If setting as default, clear other defaults
            if (!empty($data['is_default'])) {
                $db->query(
                    'UPDATE brand_kits SET is_default = :val WHERE company_id = :company_id AND id != :id',
                    [':val' => ($db->getDbType() === 'pgsql' ? 'false' : 0), ':company_id' => $kit['company_id'], ':id' => $id]
                );
            }

            $db->update('brand_kits', $data, 'id = :where_id', [':where_id' => $id]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        $updated = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $id]);
        $updated['saved_colors'] = json_decode($updated['saved_colors'] ?? '[]', true);
        $updated['custom_font_urls'] = json_decode($updated['custom_font_urls'] ?? '[]', true);

        sendJSON(['success' => true, 'brand_kit' => $updated]);

    } catch (Exception $e) {
        error_log('Brand Kit PUT Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to update brand kit',
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

        $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $id]);
        if (!$kit) {
            sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
        }

        $db->beginTransaction();
        try {
            // CASCADE handles brand_kit_assets deletion in DB
            $db->query('DELETE FROM brand_kits WHERE id = :id', [':id' => $id]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        // Clean up S3 assets (non-critical)
        if ($s3Client && $s3Client->isEnabled()) {
            try {
                $s3Client->deleteBrandKitAssets($kit['company_id'], $id);
            } catch (Exception $e) {
                error_log('Brand Kit S3 cleanup warning: ' . $e->getMessage());
            }
        }

        sendJSON(['success' => true, 'message' => 'Brand kit deleted', 'id' => $id]);

    } catch (Exception $e) {
        error_log('Brand Kit DELETE Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to delete brand kit',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

sendJSON(['success' => false, 'error' => 'Method not allowed. Use GET, POST, PUT, or DELETE.'], 405);
