<?php
/**
 * Brand Kit Asset Upload API
 *
 * POST /api/brand-kit-upload.php — upload a logo or font file to a brand kit
 *
 * Accepts multipart form data with fields:
 *   - brand_kit_id (required)
 *   - asset_type: 'logo' or 'font' (required)
 *   - file: the uploaded file (required)
 *
 * Requires: Bearer token authentication + VPN access
 */

require_once '/var/www/html/public/api/bootstrap.php';

validateBearerToken($config);
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

try {
    $brandKitId = $_POST['brand_kit_id'] ?? null;
    $assetType = $_POST['asset_type'] ?? null;

    if (!$brandKitId || !$assetType) {
        sendJSON(['success' => false, 'error' => 'brand_kit_id and asset_type are required'], 400);
    }

    if (!in_array($assetType, ['logo', 'font'])) {
        sendJSON(['success' => false, 'error' => 'asset_type must be "logo" or "font"'], 400);
    }

    // Verify brand kit exists
    $kit = $db->fetchOne('SELECT * FROM brand_kits WHERE id = :id', [':id' => $brandKitId]);
    if (!$kit) {
        sendJSON(['success' => false, 'error' => 'Brand kit not found'], 404);
    }

    // Check file upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['file']['error'] ?? 'no file';
        sendJSON(['success' => false, 'error' => "File upload failed (code: $errorCode)"], 400);
    }

    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $maxSize = 10 * 1024 * 1024; // 10MB

    if ($fileSize > $maxSize) {
        sendJSON(['success' => false, 'error' => 'File exceeds maximum size of 10MB'], 400);
    }

    // Validate file type based on asset_type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($assetType === 'logo') {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowedExtensions)) {
            sendJSON(['success' => false, 'error' => 'Logo must be JPG, PNG, GIF, or WebP'], 400);
        }
        // Validate it's actually an image
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            sendJSON(['success' => false, 'error' => 'File does not appear to be a valid image'], 400);
        }
        $mimeType = $imageInfo['mime'];
    } elseif ($assetType === 'font') {
        $allowedExtensions = ['woff', 'woff2', 'ttf', 'otf'];
        if (!in_array($ext, $allowedExtensions)) {
            sendJSON(['success' => false, 'error' => 'Font must be WOFF, WOFF2, TTF, or OTF'], 400);
        }
        $mimeTypes = [
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
        ];
        $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    $companyId = $kit['company_id'];
    $assetId = generateUUID4();

    // Upload to S3 or local storage
    if ($s3Client && $s3Client->isEnabled()) {
        $s3Url = $s3Client->uploadBrandKitAsset($companyId, $brandKitId, $assetType, $filename, $tmpPath, $mimeType);
    } else {
        // Local storage fallback
        $localDir = "/var/www/html/content/brand-kits/{$companyId}/{$brandKitId}/{$assetType}s";
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }
        $localPath = "{$localDir}/{$filename}";
        if (!move_uploaded_file($tmpPath, $localPath)) {
            sendJSON(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
        }
        $s3Url = "/content/brand-kits/{$companyId}/{$brandKitId}/{$assetType}s/{$filename}";
    }

    // Create brand_kit_assets record
    $db->insert('brand_kit_assets', [
        'id' => $assetId,
        'brand_kit_id' => $brandKitId,
        'asset_type' => $assetType,
        'filename' => $filename,
        's3_url' => $s3Url,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
    ]);

    // Update brand kit record based on asset type
    if ($assetType === 'logo') {
        $db->update('brand_kits', [
            'logo_url' => $s3Url,
            'logo_filename' => $filename,
        ], 'id = :where_id', [':where_id' => $brandKitId]);
    } elseif ($assetType === 'font') {
        // Append to custom_font_urls JSONB array
        $currentFonts = json_decode($kit['custom_font_urls'] ?? '[]', true);
        $currentFonts[] = $s3Url;
        $db->update('brand_kits', [
            'custom_font_urls' => json_encode($currentFonts),
        ], 'id = :where_id', [':where_id' => $brandKitId]);
    }

    sendJSON([
        'success' => true,
        'asset' => [
            'id' => $assetId,
            'brand_kit_id' => $brandKitId,
            'asset_type' => $assetType,
            'filename' => $filename,
            's3_url' => $s3Url,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ]
    ], 201);

} catch (Exception $e) {
    error_log('Brand Kit Upload Error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'error' => 'Failed to upload brand kit asset',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
