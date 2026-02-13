<?php
/**
 * Apply Brand Kit API
 *
 * POST /api/apply-brand-kit.php — apply a brand kit's styles to content HTML (preview, no save)
 *
 * JSON body: { content_id, brand_kit_id }
 *
 * Returns: { success, html, transformations }
 *
 * Requires: Bearer token authentication + VPN access
 */

require_once '/var/www/html/public/api/bootstrap.php';
require_once '/var/www/html/lib/BrandKitTransformer.php';

validateBearerToken($config);
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['content_id', 'brand_kit_id']);

    // Fetch content
    $content = $db->fetchOne(
        'SELECT id, title, content_type, entry_body_html, email_body_html FROM content WHERE id = :id',
        [':id' => $input['content_id']]
    );
    if (!$content) {
        sendJSON(['success' => false, 'error' => 'Content not found'], 404);
    }

    // Determine which HTML field to use
    $html = null;
    if ($content['content_type'] === 'email') {
        $html = $content['email_body_html'];
    }
    if (empty($html)) {
        $html = $content['entry_body_html'];
    }
    if (empty($html)) {
        sendJSON(['success' => false, 'error' => 'Content has no HTML body to transform'], 400);
    }

    // Fetch brand kit
    $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $input['brand_kit_id']]);
    if (!$kit) {
        sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
    }

    // Decode JSONB fields
    $kit['saved_colors'] = json_decode($kit['saved_colors'] ?? '[]', true);
    $kit['custom_font_urls'] = json_decode($kit['custom_font_urls'] ?? '[]', true);

    // Apply brand kit
    $result = BrandKitTransformer::apply($html, $kit);

    sendJSON([
        'success' => true,
        'html' => $result['html'],
        'transformations' => $result['transformations'],
        'content_id' => $input['content_id'],
        'brand_kit_id' => $input['brand_kit_id'],
    ]);

} catch (Exception $e) {
    error_log('Apply Brand Kit Error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'error' => 'Failed to apply brand kit',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
