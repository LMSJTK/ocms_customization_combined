<?php
/**
 * Content Upload API
 * Handles file uploads for SCORM, HTML, videos, and raw HTML
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Increase limits for large content processing
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '512M'); // 512MB memory

error_log("=== UPLOAD.PHP STARTING ===");

require_once '/var/www/html/public/api/bootstrap.php';
error_log("Bootstrap loaded successfully");

// Validate bearer token authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

/**
 * Suggest and optionally apply a domain for content using Claude AI
 * Returns the suggested domain info or null if suggestion fails/disabled
 */
function suggestDomainForContent($contentId, $db, $claudeAPI, $autoApply = false) {
    try {
        $table = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content';
        $tagsTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'content_tags';

        // Fetch content details
        $content = $db->fetchOne(
            "SELECT id, title, description, content_domain FROM $table WHERE id = :id",
            [':id' => $contentId]
        );

        if (!$content) {
            error_log("suggestDomainForContent: Content not found: $contentId");
            return null;
        }

        // Skip if content already has a domain
        if (!empty($content['content_domain'])) {
            error_log("suggestDomainForContent: Content already has domain: " . $content['content_domain']);
            return ['domain' => $content['content_domain'], 'skipped' => true];
        }

        // Fetch available domains from pm_phishing_domain (is_hidden = false)
        $domains = $db->fetchAll(
            "SELECT tag, domain FROM global.pm_phishing_domain WHERE is_hidden = false ORDER BY tag"
        );

        if (empty($domains)) {
            error_log("suggestDomainForContent: No available domains found");
            return null;
        }

        // Fetch tags for this content
        $tags = $db->fetchAll(
            "SELECT tag_name FROM $tagsTable WHERE content_id = :id",
            [':id' => $contentId]
        );
        $tagNames = array_column($tags, 'tag_name');

        // Get suggestion from Claude
        $suggestion = $claudeAPI->suggestDomain(
            $content['title'] ?? '',
            $content['description'] ?? '',
            $tagNames,
            $domains
        );

        // Auto-apply if requested
        if ($autoApply && isset($suggestion['domain'])) {
            $db->query(
                "UPDATE $table SET content_domain = :domain WHERE id = :id",
                [':domain' => $suggestion['domain'], ':id' => $contentId]
            );
            error_log("suggestDomainForContent: Applied domain '{$suggestion['domain']}' to content $contentId");
        }

        return $suggestion;

    } catch (Exception $e) {
        error_log("suggestDomainForContent: Error - " . $e->getMessage());
        return null;
    }
}

/**
 * Generate a preview link for content using training_tracking
 */
function generatePreviewLink($contentId, $db, $trackingManager, $config) {
    // Generate unique IDs for preview
    $trainingId = generateUUID4();
    $trainingTrackingId = generateUUID4();
    $uniqueTrackingId = generateUUID4();

    // Get content details for training name
    $content = $db->fetchOne(
        'SELECT title FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    // Create training record for preview
    $trainingRecord = [
        'id' => $trainingId,
        'company_id' => 'system',
        'name' => 'Preview: ' . ($content['title'] ?? 'Content'),
        'description' => 'Auto-generated training for content preview',
        'training_type' => 'preview',
        'training_content_id' => $contentId,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training',
        $trainingRecord
    );

    // Create training_tracking record
    $trainingTrackingData = [
        'id' => $trainingTrackingId,
        'training_id' => $trainingId,
        'recipient_id' => 'preview',
        'unique_tracking_id' => $uniqueTrackingId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
        $trainingTrackingData
    );

    // Build preview URL with PATH_INFO format (dashless IDs)
    // Format: /launch.php/{content_id_without_dashes}/{tracking_id_without_dashes}
    // Uses external URL for preview links (MESSAGEHUB_ENDPOINTS_UI_EXTERNAL + /ocms-service/)
    $contentIdNoDash = str_replace('-', '', $contentId);
    $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
    $previewUrl = rtrim($config['app']['external_url'], '/') . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

    // Update content with preview link
    $db->update('content',
        ['content_preview' => $previewUrl],
        'id = :id',
        [':id' => $contentId]
    );

    return $previewUrl;
}

/**
 * Process thumbnail upload or URL
 * Returns full URL to the thumbnail, or null if no thumbnail
 * Accepts either a file upload or a thumbnail_url POST parameter
 */
function processThumbnail($contentId, $config) {
    // Check if a thumbnail URL was provided (for imports from legacy systems)
    if (isset($_POST['thumbnail_url']) && !empty($_POST['thumbnail_url'])) {
        $thumbnailUrl = $_POST['thumbnail_url'];

	// Validate URL format
        if (!filter_var($thumbnailUrl, FILTER_VALIDATE_URL)) {
            // Handle legacy filenames: if it's not a URL and has no slashes, assume it's a legacy template image
            if (strpos($thumbnailUrl, 'http') === false && strpos($thumbnailUrl, '/') === false) {
                $thumbnailUrl = 'https://login.phishme.com/images/education_templates/' . $thumbnailUrl;
                error_log("Legacy import: Fetching thumbnail from " . $thumbnailUrl);
            } else {
                throw new Exception('Invalid thumbnail URL format');
            }
        }

        // Try to download the thumbnail
        $imageContent = @file_get_contents($thumbnailUrl);
        if ($imageContent === false) {
            // If download fails, just return the URL as-is (it may still be valid)
            error_log("Could not download thumbnail from URL: $thumbnailUrl - using URL directly");
            return $thumbnailUrl;
        }

        // Create content directory
        $contentDir = $config['content']['upload_dir'] . $contentId . '/';
        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        // Extract filename from URL or generate one
        $urlParts = parse_url($thumbnailUrl);
        $pathParts = pathinfo($urlParts['path'] ?? 'thumbnail.jpg');
        $extension = $pathParts['extension'] ?? 'jpg';
        $thumbnailFilename = 'thumbnail.' . $extension;

        // Save to content directory
        $thumbnailPath = $contentDir . $thumbnailFilename;
        if (file_put_contents($thumbnailPath, $imageContent) === false) {
            throw new Exception('Failed to save downloaded thumbnail');
        }

        // Validate it's actually an image
        $imageInfo = getimagesize($thumbnailPath);
        if ($imageInfo === false) {
            unlink($thumbnailPath);
            throw new Exception('Downloaded thumbnail is not a valid image');
        }

        // Build full URL to local thumbnail (uses external URL)
        return rtrim($config['app']['external_url'], '/') . '/content/' . $contentId . '/' . $thumbnailFilename;
    }

    // Otherwise, check for file upload
    if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Thumbnail upload failed');
    }

    $thumbnailFile = $_FILES['thumbnail'];
    $thumbnailFilename = basename($thumbnailFile['name']);

    // Validate it's an image file
    $imageInfo = getimagesize($thumbnailFile['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('Thumbnail must be a valid image file');
    }

    // Create content directory
    $contentDir = $config['content']['upload_dir'] . $contentId . '/';
    if (!is_dir($contentDir)) {
        mkdir($contentDir, 0755, true);
    }

    // Save thumbnail to content directory
    $thumbnailPath = $contentDir . $thumbnailFilename;
    if (!move_uploaded_file($thumbnailFile['tmp_name'], $thumbnailPath)) {
        throw new Exception('Failed to save thumbnail file');
    }

    // Build full URL to thumbnail (uses external URL)
    $thumbnailUrl = rtrim($config['app']['external_url'], '/') . '/content/' . $contentId . '/' . $thumbnailFilename;

    return $thumbnailUrl;
}

/**
 * Normalize phishing link hrefs to use {{{trainingURL}}} placeholder
 *
 * Legacy emails have phishing links with various href formats:
 * - {{{ TopLevelDomain.domain_for_template('tag') }}}
 * - {{{ PhishingDomain.domain_for_template('tag') }}}
 * - Hardcoded phishing URLs
 *
 * This function finds all <a> tags with class="phishing-link-do-not-delete"
 * and replaces their href attribute with {{{trainingURL}}}
 *
 * @param string $html The email HTML content
 * @return array ['html' => string, 'count' => int] The processed HTML and count of links fixed
 */
function normalizePhishingLinkHrefs($html) {
    $count = 0;

    // Pattern to match <a> tags with class containing "phishing-link-do-not-delete"
    // Captures the full tag so we can replace the href
    $pattern = '/(<a\s[^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*)href\s*=\s*["\'][^"\']*["\']([^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern,
        function($matches) use (&$count) {
            $count++;
            // Reconstruct the tag with {{{trainingURL}}} as the href
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $html
    );

    // Also handle case where href comes before class
    $pattern2 = '/(<a\s[^>]*)href\s*=\s*["\'][^"\']*["\']([^>]*class\s*=\s*["\'][^"\']*phishing-link-do-not-delete[^"\']*["\'][^>]*>)/i';

    $processedHtml = preg_replace_callback(
        $pattern2,
        function($matches) use (&$count) {
            // Only increment if we haven't already processed this link
            if (strpos($matches[0], '{{{trainingURL}}}') === false) {
                $count++;
            }
            return $matches[1] . 'href="{{{trainingURL}}}"' . $matches[2];
        },
        $processedHtml
    );

    return [
        'html' => $processedHtml,
        'count' => $count
    ];
}

/**
 * Check if email HTML contains phishing link indicators
 * Returns true if phishing links are present, false otherwise
 *
 * Phishing links are identified by:
 * - class="phishing-link-do-not-delete" marker in HTML
 * - {{{trainingURL}}} placeholder for runtime phishing URL
 */
function hasPhishingLinks($html) {
    if (empty($html)) {
        return false;
    }

    // Check for phishing link class marker
    if (strpos($html, 'phishing-link-do-not-delete') !== false) {
        return true;
    }

    // Check for trainingURL placeholder
    if (strpos($html, '{{{trainingURL}}}') !== false) {
        return true;
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Wrong method: " . $_SERVER['REQUEST_METHOD']);
    sendJSON(['error' => 'Method not allowed'], 405);
}

error_log("POST request received");

try {
    // Get form data
    $contentType = $_POST['content_type'] ?? null;
    $title = $_POST['title'] ?? 'Untitled Content';
    $description = $_POST['description'] ?? '';
    $companyId = $_POST['company_id'] ?? null;
    $domainId = isset($_POST['domain_id']) && $_POST['domain_id'] !== '' ? $_POST['domain_id'] : null;
    $contentDomain = isset($_POST['domain']) && $_POST['domain'] !== '' ? $_POST['domain'] : null;
    $legacyId = isset($_POST['legacy_id']) && $_POST['legacy_id'] !== '' ? $_POST['legacy_id'] : null;
    $autoSuggestDomain = isset($_POST['auto_suggest_domain']) && ($_POST['auto_suggest_domain'] === 'true' || $_POST['auto_suggest_domain'] === '1');
    // Language defaults to 'en' (English) if not provided
    $language = isset($_POST['language']) && $_POST['language'] !== '' ? strtolower(trim($_POST['language'])) : 'en';

    error_log("Content type: " . ($contentType ?? 'NULL'));
    error_log("Title: " . $title);
    error_log("Domain ID: " . ($domainId ?? 'NULL'));
    error_log("Content Domain: " . ($contentDomain ?? 'NULL'));
    error_log("Legacy ID: " . ($legacyId ?? 'NULL'));
    error_log("Language: " . $language);
    error_log("Auto suggest domain: " . ($autoSuggestDomain ? 'true' : 'false'));

    if (!$contentType) {
        sendJSON(['error' => 'content_type is required'], 400);
    }

    // Validate domain_id if provided (check if table exists first)
    if ($domainId !== null) {
        try {
            $domain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id AND is_active = :is_active',
                [':id' => $domainId, ':is_active' => 1]
            );

            if (!$domain) {
                sendJSON(['error' => 'Invalid or inactive domain_id'], 400);
            }
        } catch (Exception $e) {
            // If domains table doesn't exist, just log and continue without domain
            error_log("Domain validation skipped (table may not exist): " . $e->getMessage());
            $domainId = null;
        }
    }

    // Use legacy ID if provided (for imports), otherwise generate new UUID
    if ($legacyId !== null) {
        // Validate UUID format (with or without dashes)
        $uuidPattern = '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i';
        if (!preg_match($uuidPattern, $legacyId)) {
            sendJSON(['error' => 'Invalid legacy_id format - must be a valid UUID'], 400);
        }
        // Normalize UUID format (add dashes if missing)
        if (strlen($legacyId) === 32) {
            $legacyId = substr($legacyId, 0, 8) . '-' . substr($legacyId, 8, 4) . '-' .
                        substr($legacyId, 12, 4) . '-' . substr($legacyId, 16, 4) . '-' .
                        substr($legacyId, 20);
        }
        $contentId = strtolower($legacyId);
        error_log("Using legacy ID as content ID: " . $contentId);
    } else {
        // Generate unique content ID
        $contentId = generateUUID4();
    }

    // Handle different content types
    switch ($contentType) {
        case 'scorm':
        case 'html':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($fileExt !== 'zip') {
                sendJSON(['error' => 'Only ZIP files are allowed for ' . $contentType], 400);
            }

            // Move uploaded file to temp location
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                sendJSON(['error' => 'Failed to save uploaded file'], 500);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // SCORM packages should be stored as 'training' type and default to scorable
            $storageContentType = ($contentType === 'scorm') ? 'training' : $contentType;
            $isScorm = ($contentType === 'scorm');

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => $storageContentType,
                'content_url' => null, // Will be set after processing
                'languages' => $language
            ];

            // SCORM content defaults to scorable (can be overridden by detection)
            if ($isScorm) {
                $insertData['scorable'] = true;
            }

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process content
            $result = $contentProcessor->processContent($contentId, $contentType, $tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => $result['message'] ?? 'Content uploaded and processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ];

            // Add S3 indicator if content was uploaded to S3
            if (isset($result['s3']) && $result['s3']) {
                $response['s3'] = true;
                $response['storage'] = 's3';
            } else {
                $response['storage'] = 'local';
            }

            sendJSON($response);
            break;

        case 'video':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($fileExt, ['mp4', 'webm', 'ogg'])) {
                sendJSON(['error' => 'Invalid video format. Allowed: mp4, webm, ogg'], 400);
            }

            // Create directory
            $videoDir = $config['content']['upload_dir'] . $contentId . '/';
            if (!is_dir($videoDir)) {
                mkdir($videoDir, 0755, true);
            }

            $videoPath = $videoDir . 'video.' . $fileExt;
            if (!move_uploaded_file($file['tmp_name'], $videoPath)) {
                sendJSON(['error' => 'Failed to save video file'], 500);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'video',
                'content_url' => $contentId . '/video.' . $fileExt,
                'languages' => $language
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Video uploaded successfully',
                'path' => $contentId . '/video.' . $fileExt,
                'preview_url' => $previewUrl
            ]);
            break;

        case 'training':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Process placeholders in education HTML before importing
            $placeholderResult = $contentProcessor->processEducationPlaceholders($htmlContent);

            if (!$placeholderResult['success']) {
                // Education content contains unsupported placeholders - reject the import
                sendJSON([
                    'success' => false,
                    'error' => $placeholderResult['error'],
                    'rejected_placeholders' => $placeholderResult['rejected'],
                    'details' => $placeholderResult['processed']
                ], 400);
            }

            // Use processed HTML (with COMPANY_NAME stripped, runtime placeholders preserved)
            $htmlContent = $placeholderResult['html'];

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'training',
                'content_url' => null,
                'languages' => $language
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process content
            $result = $contentProcessor->processContent($contentId, 'training', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            // Auto-suggest domain if requested
            $suggestedDomain = null;
            if ($autoSuggestDomain) {
                $suggestedDomain = suggestDomainForContent($contentId, $db, $claudeAPI, true);
            }

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => $result['message'] ?? 'HTML content processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl,
                'storage' => isset($result['s3']) && $result['s3'] ? 's3' : 'local'
            ];

            // Include domain if suggested
            if ($suggestedDomain !== null && isset($suggestedDomain['domain'])) {
                $response['domain'] = $suggestedDomain['domain'];
                $response['domain_suggested'] = true;
                $response['domain_reasoning'] = $suggestedDomain['reasoning'] ?? null;
            }

            sendJSON($response);
            break;

        case 'landing':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Process placeholders in landing page HTML before importing
            $placeholderResult = $contentProcessor->processLandingPlaceholders($htmlContent);

            if (!$placeholderResult['success']) {
                // Landing page contains unsupported placeholders - reject the import
                sendJSON([
                    'success' => false,
                    'error' => $placeholderResult['error'],
                    'rejected_placeholders' => $placeholderResult['rejected'],
                    'details' => $placeholderResult['processed']
                ], 400);
            }

            // Use processed HTML (with COMPANY_NAME stripped, runtime placeholders preserved)
            $htmlContent = $placeholderResult['html'];

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'landing',
                'content_url' => null,
                'languages' => $language
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            // Add thumbnail field - use default if none provided
            $insertData['thumbnail_filename'] = $thumbnailUrl ?? '/images/landing_default.png';

            $db->insert('content', $insertData);

            // Process landing page content (similar to training but designated as landing)
            $result = $contentProcessor->processContent($contentId, 'landing', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            // Auto-suggest domain if requested
            $suggestedDomain = null;
            if ($autoSuggestDomain) {
                $suggestedDomain = suggestDomainForContent($contentId, $db, $claudeAPI, true);
            }

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => $result['message'] ?? 'Landing page processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl,
                'storage' => isset($result['s3']) && $result['s3'] ? 's3' : 'local'
            ];

            // Include domain if suggested
            if ($suggestedDomain !== null && isset($suggestedDomain['domain'])) {
                $response['domain'] = $suggestedDomain['domain'];
                $response['domain_suggested'] = true;
                $response['domain_reasoning'] = $suggestedDomain['reasoning'] ?? null;
            }

            sendJSON($response);
            break;

        case 'email':
            $emailHTML = $_POST['email_html'] ?? null;
            $emailSubject = $_POST['email_subject'] ?? '';
            $emailFrom = $_POST['email_from'] ?? '';
            $fromName = $_POST['from_name'] ?? null; // For FROM_FRIENDLY_NAME placeholder replacement

            if (!$emailHTML) {
                sendJSON(['error' => 'email_html is required'], 400);
            }

            // Process placeholders in email HTML before importing
            $placeholderResult = $contentProcessor->processEmailPlaceholders($emailHTML, $fromName);

            if (!$placeholderResult['success']) {
                // Email contains unsupported placeholders - reject the import
                sendJSON([
                    'success' => false,
                    'error' => 'Email rejected due to unsupported placeholders',
                    'rejected_placeholders' => $placeholderResult['rejected'],
                    'message' => $placeholderResult['error']
                ], 400);
            }

            // Use processed HTML with placeholders handled
            $emailHTML = $placeholderResult['html'];
            $processedPlaceholders = $placeholderResult['processed'];
            error_log("Placeholder processing: ignored=" . implode(',', $processedPlaceholders['ignored'] ?? []) .
                      ", replaced=" . implode(',', $processedPlaceholders['replaced'] ?? []) .
                      ", stripped=" . implode(',', $processedPlaceholders['stripped'] ?? []));

            // Normalize phishing link hrefs to use {{{trainingURL}}} placeholder
            // This converts legacy hrefs (with TopLevelDomain/PhishingDomain placeholders or hardcoded URLs)
            // to the standard {{{trainingURL}}} format that gets resolved at email send time
            $normalizeResult = normalizePhishingLinkHrefs($emailHTML);
            $emailHTML = $normalizeResult['html'];
            if ($normalizeResult['count'] > 0) {
                error_log("Normalized " . $normalizeResult['count'] . " phishing link href(s) to {{{trainingURL}}}");
            }

            // Validate that email contains phishing links
            // Emails without phishing links are skipped during import
            if (!hasPhishingLinks($emailHTML)) {
                error_log("Email rejected: No phishing links found in content (missing class='phishing-link-do-not-delete' or {{{trainingURL}}})");
                sendJSON([
                    'success' => false,
                    'error' => 'Email skipped: No phishing links detected',
                    'message' => 'Email content must contain phishing links (class="phishing-link-do-not-delete" or {{{trainingURL}}} placeholder) to be imported. This email appears to be a notification or non-phishing content.',
                    'skipped' => true
                ], 400);
            }

            // Handle attachment if provided
            $attachmentFilename = null;
            $attachmentContent = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $attachmentFile = $_FILES['attachment'];
                $attachmentFilename = basename($attachmentFile['name']);

                // Create content directory
                $contentDir = $config['content']['upload_dir'] . $contentId . '/';
                if (!is_dir($contentDir)) {
                    mkdir($contentDir, 0755, true);
                }

                // Save attachment to content directory
                $attachmentPath = $contentDir . $attachmentFilename;
                if (!move_uploaded_file($attachmentFile['tmp_name'], $attachmentPath)) {
                    sendJSON(['error' => 'Failed to save attachment file'], 500);
                }

                // Read file content as binary for database storage
                $attachmentContent = file_get_contents($attachmentPath);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Save email HTML to temp file
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.html';
            file_put_contents($tempPath, $emailHTML);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'email',
                'email_subject' => $emailSubject,
                'email_from_address' => $emailFrom,
                'email_body_html' => $emailHTML,
                'content_url' => null,
                'languages' => $language
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            // Add content_domain if it's set (from phishing domain lookup during import)
            if ($contentDomain !== null) {
                $insertData['content_domain'] = $contentDomain;
            }

            // Add attachment fields if attachment was provided
            if ($attachmentFilename !== null) {
                $insertData['email_attachment_filename'] = $attachmentFilename;
                $insertData['email_attachment_content'] = $attachmentContent;
            }

            // Add thumbnail field - use default if none provided
            $insertData['thumbnail_filename'] = $thumbnailUrl ?? '/images/email_default.png';

            $db->insert('content', $insertData);

            // Process email content
            $result = $contentProcessor->processContent($contentId, 'email', $tempPath);

            // Clean up temp file
            unlink($tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            // Auto-suggest domain if requested and no domain was provided
            $suggestedDomain = null;
            if ($autoSuggestDomain && $contentDomain === null) {
                $suggestedDomain = suggestDomainForContent($contentId, $db, $claudeAPI, true);
            }

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => $result['message'] ?? 'Email content processed successfully',
                'cues' => $result['cues'] ?? [],
                'difficulty' => $result['difficulty'] ?? null,
                'path' => $result['path'],
                'preview_url' => $previewUrl,
                'storage' => isset($result['s3']) && $result['s3'] ? 's3' : 'local'
            ];

            // Include placeholder processing info
            if (!empty($processedPlaceholders['ignored']) || !empty($processedPlaceholders['replaced']) || !empty($processedPlaceholders['stripped'])) {
                $response['placeholders'] = $processedPlaceholders;
            }

            // Include attachment info if present
            if ($attachmentFilename !== null) {
                $response['attachment_filename'] = $attachmentFilename;
                $response['attachment_size'] = strlen($attachmentContent);
            }

            // Include domain if it was set or suggested
            if ($contentDomain !== null) {
                $response['domain'] = $contentDomain;
            } elseif ($suggestedDomain !== null && isset($suggestedDomain['domain'])) {
                $response['domain'] = $suggestedDomain['domain'];
                $response['domain_suggested'] = true;
                $response['domain_reasoning'] = $suggestedDomain['reasoning'] ?? null;
            }

            sendJSON($response);
            break;

        default:
            sendJSON(['error' => 'Invalid content_type'], 400);
    }

} catch (Exception $e) {
    error_log("Upload Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJSON([
        'error' => 'Upload failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $config['app']['debug'] ? $e->getTraceAsString() : null
    ], 500);
}
