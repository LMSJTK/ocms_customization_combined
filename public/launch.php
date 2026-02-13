<?php
/**
 * Content Launch Player
 * Displays content and tracks user interactions
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Calculate base path from config URL
$baseUrl = $config['app']['base_url'];
$parsedUrl = parse_url($baseUrl);
$basePath = $parsedUrl['path'] ?? '';

// ---------------------------------------------------------
// ID Extraction Logic
// ---------------------------------------------------------
// Supported formats:
// 1. Path Param:   ?path=/arbitrary/path/contentID/trackingID
// 2. PATH_INFO:   /launch.php/arbitrary/path/contentID/trackingID
// 3. Tracking and content params:      ?trackingId=xyz&content=abc
// I left this backwards compatible in case we need to change back. Once we have something rolling I can close off the excess legacy methods.

$pathInfo = $_SERVER['PATH_INFO'] ?? null;
$pathParam = $_GET['path'] ?? null;
$trackingParam = $_GET['trackingId'] ?? null;
$contentIdParam = $_GET['content'] ?? null;

// Determine if we have a path-based request (prioritize 'path' param)
$pathToParse = $pathParam ?: $pathInfo;

if ($pathToParse) {
    // Parse path: /arbitrary/paths/contentid/trackingid
    // We expect the IDs to be the last two segments of the path
    $pathSegments = array_values(array_filter(explode('/', $pathToParse), function($seg) {
        return $seg !== '';
    }));

    if (count($pathSegments) >= 2) {
        // Extract last two segments
        $contentIdDashless = $pathSegments[count($pathSegments) - 2];
        $trackingIdDashless = $pathSegments[count($pathSegments) - 1];

        // Restore dashes to UUIDs (helper from bootstrap.php)
        $contentId = restoreUUIDDashes($contentIdDashless);
        $trackingLinkId = restoreUUIDDashes($trackingIdDashless);

        if (!$contentId || !$trackingLinkId) {
            http_response_code(400);
            echo '<h1>Error: Invalid ID format in URL</h1>';
            exit;
        }

        $trainingType = null; // Not available in path formats
    } else {
        http_response_code(400);
        echo '<h1>Error: Invalid URL format</h1>';
        exit;
    }
} elseif ($trackingParam) {
    // Legacy query string format: ?trackingId=xyz&content=abc
    if (!$contentIdParam) {
        http_response_code(400);
        echo '<h1>Error: Missing content ID for legacy format</h1>';
        exit;
    }
    $trackingLinkId = $trackingParam;
    $contentId = $contentIdParam;
    $trainingType = null;
} else {
    // No valid ID information found
    http_response_code(400);
    echo '<h1>Error: Missing tracking information</h1>';
    exit;
}

// ---------------------------------------------------------
// Content Loading & Display Logic
// ---------------------------------------------------------

try {
    // Validate the tracking session exists in training_tracking table
    $session = $trackingManager->validateTrainingSession($trackingLinkId);
    if (!$session) {
        http_response_code(403);
        echo '<h1>Error: Invalid or expired tracking session</h1>';
        exit;
    }

    $recipientId = $session['recipient_id'];

    // Fetch content directly using the content ID
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        http_response_code(404);
        echo '<h1>Error: Content not found</h1>';
        exit;
    }

//Track view
$trainingId = $session['training_id']; // Extracted from training_tracking table

// Fetch the training definition to see which content is landing, training, or follow-on
$training = $db->fetchOne(
    'SELECT landing_content_id, training_content_id, follow_on_content_id, training_email_content_id FROM ' .
    ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training WHERE id = :training_id',
    [':training_id' => $trainingId]
);

if (!$training) {
    http_response_code(404);
    echo '<h1>Error: Training configuration not found</h1>';
    exit;
}

// Determine if this content matches the landing, training, or follow-on ID
$isLandingPage = ($contentId === $training['landing_content_id']);
$isFollowOn = ($contentId === $training['follow_on_content_id']);

if ($isFollowOn) {
    // Record that the follow-on content was viewed
    $trackingManager->trackFollowOnView($trackingLinkId);
} else {
    // Record that the initial training content was viewed
    $trackingManager->trackView($trackingLinkId, $contentId, $recipientId);
}

// Calculate the "next step" URL for landing pages
// This will be used to replace {{{trainingURL}}} placeholder
$nextStepUrl = '#'; // Default fallback
if ($isLandingPage && !empty($training['training_content_id'])) {
    // Build URL to the training content using dashless format
    $trainingContentIdNoDash = str_replace('-', '', $training['training_content_id']);
    $trackingIdNoDash = str_replace('-', '', $trackingLinkId);
    $nextStepUrl = $basePath . '/launch.php/' . $trainingContentIdNoDash . '/' . $trackingIdNoDash;
}

    // Determine how to display content based on type
    $contentType = $content['content_type'];
    $contentUrl = $content['content_url'];

    // Check if content is stored on S3 (URL starts with http:// or https://)
    $isS3Content = preg_match('/^https?:\/\//', $contentUrl);

    // S3 video content: redirect directly (too large to proxy through server)
    if ($isS3Content && $contentType === 'video') {
        header('Location: ' . $contentUrl);
        exit;
    }

    switch ($contentType) {
        case 'video':
            // Display local video player
            $videoPath = $config['content']['upload_dir'] . $contentUrl;
            $videoExt = pathinfo($videoPath, PATHINFO_EXTENSION);
            $mimeType = 'video/' . $videoExt;
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php echo htmlspecialchars($content['title']); ?></title>
                <style>
                    body {
                        margin: 0;
                        padding: 20px;
                        font-family: Arial, sans-serif;
                        background: #f5f5f5;
                    }
                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                        background: white;
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    h1 {
                        margin-top: 0;
                    }
                    video {
                        width: 100%;
                        max-width: 800px;
                        display: block;
                        margin: 20px auto;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1><?php echo htmlspecialchars($content['title']); ?></h1>
                    <video controls>
                        <source src="<?php echo htmlspecialchars($basePath); ?>/content/<?php echo htmlspecialchars($contentUrl); ?>" type="<?php echo $mimeType; ?>">
                        Your browser does not support the video tag.
                    </video>
                </div>
            </body>
            </html>
            <?php
            break;

        case 'scorm':
        case 'html':
        case 'training':
        case 'landing':
        case 'email':
        case 'direct':
            // ---------------------------------------------------------
            // Load HTML content from DB, S3, or local filesystem
            // ---------------------------------------------------------
            // Prefer entry_body_html from DB (stored at upload time with absolute URLs).
            // This eliminates the S3 network round-trip on every launch.
            // Falls back to S3 fetch or local file for content uploaded before this column existed.
            $servedFromDB = false;
            if (!empty($content['entry_body_html'])) {
                $htmlContent = $content['entry_body_html'];
                $servedFromDB = true;
                error_log("Using entry_body_html from DB for content {$contentId}");
            } elseif ($isS3Content) {
                // Fallback: Fetch HTML from S3 (legacy content without entry_body_html)
                error_log("Fetching S3 content for server-side processing: {$contentUrl}");
                $htmlContent = $s3Client->fetchContent($contentUrl);
            } else {
                // Local content: include PHP file and capture output
                $uploadDir = $config['content']['upload_dir'];
                $contentPath = $uploadDir . $contentUrl;

                // SECURITY: Validate that the resolved path stays within upload directory
                // This prevents directory traversal attacks (e.g., "../../../etc/passwd")
                $realContentPath = realpath($contentPath);
                $realUploadDir = realpath($uploadDir);

                if (!$realContentPath || !$realUploadDir) {
                    http_response_code(404);
                    echo '<h1>Error: Content file not found</h1>';
                    error_log("Launch Error: Invalid path - contentPath={$contentPath}, uploadDir={$uploadDir}");
                    exit;
                }

                // Ensure the resolved path is within the upload directory
                if (strpos($realContentPath, $realUploadDir) !== 0) {
                    http_response_code(403);
                    echo '<h1>Error: Access denied</h1>';
                    error_log("Launch Error: Path traversal attempt - realPath={$realContentPath}, uploadDir={$realUploadDir}");
                    exit;
                }

                // Verify file exists (redundant after realpath, but explicit check)
                if (!file_exists($realContentPath) || !is_file($realContentPath)) {
                    http_response_code(404);
                    echo '<h1>Error: Content file not found</h1>';
                    exit;
                }

                // Set tracking link ID for the content to use
                $_GET['tid'] = $trackingLinkId;

                // Use output buffering to capture and modify content
                // SECURITY: File path has been validated above to prevent inclusion attacks
                ob_start();
                include $realContentPath;
                $htmlContent = ob_get_clean();
            }

            // ---------------------------------------------------------
            // Inject Content ID meta tag for tracking script
            // ---------------------------------------------------------
            // This allows the tracker to pass content_id to record-score.php
            // so it can determine if this is training or follow-on content
            $contentIdMeta = '<meta name="ocms-content-id" content="' . htmlspecialchars($contentId, ENT_QUOTES, 'UTF-8') . '">';
            if (stripos($htmlContent, '<head>') !== false) {
                $htmlContent = preg_replace('/<head>/i', '<head>' . "\n" . $contentIdMeta, $htmlContent, 1);
            } elseif (stripos($htmlContent, '</head>') !== false) {
                $htmlContent = str_ireplace('</head>', $contentIdMeta . "\n" . '</head>', $htmlContent);
            }

            // ---------------------------------------------------------
            // Inject Tracking ID meta tag
            // ---------------------------------------------------------
            // DB-served content (entry_body_html) and S3 content don't have the
            // tracking ID embedded at upload time. Inject it here at serve time so
            // ocms-tracker.js can find it via <meta name="ocms-tracking-id">.
            // Local PHP content already has this via the PHP preamble, so skip it.
            if ($servedFromDB || $isS3Content) {
                $trackingIdMeta = '<meta name="ocms-tracking-id" content="' . htmlspecialchars($trackingLinkId, ENT_QUOTES, 'UTF-8') . '">';
                if (stripos($htmlContent, '</head>') !== false) {
                    $htmlContent = str_ireplace('</head>', $trackingIdMeta . "\n" . '</head>', $htmlContent);
                } elseif (stripos($htmlContent, '<head>') !== false) {
                    $htmlContent = preg_replace('/<head>/i', '<head>' . "\n" . $trackingIdMeta, $htmlContent, 1);
                }
            }

            // ---------------------------------------------------------
            // Landing Page Placeholder Replacement
            // ---------------------------------------------------------
            // Replace {{{trainingURL}}} with the actual next step URL
            $htmlContent = str_replace('{{{trainingURL}}}', htmlspecialchars($nextStepUrl), $htmlContent);

            // ---------------------------------------------------------
            // Runtime Placeholder Replacement (Education & Landing Pages)
            // ---------------------------------------------------------
            // These placeholders are populated at runtime based on tracking data

            // CURRENT_YEAR / current_year - Replace with current year
            $currentYear = date('Y');
            $htmlContent = preg_replace(
                '/<span[^>]*data-basename=["\']CURRENT_YEAR["\'][^>]*>.*?<\/span>/is',
                htmlspecialchars($currentYear),
                $htmlContent
            );
            $htmlContent = preg_replace(
                '/<span[^>]*data-basename=["\']current_year["\'][^>]*>.*?<\/span>/is',
                htmlspecialchars($currentYear),
                $htmlContent
            );

            // SCENARIO_START_DATETIME - Replace with training scheduled_at or created_at
            // Fetch from training table
            $scenarioStartDatetime = '';
            if (!empty($trainingId)) {
                $trainingRecord = $db->fetchOne(
                    'SELECT scheduled_at, created_at FROM ' .
                    ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training WHERE id = :training_id',
                    [':training_id' => $trainingId]
                );
                if ($trainingRecord) {
                    // Use scheduled_at if not null, otherwise use created_at
                    $scenarioStartDatetime = $trainingRecord['scheduled_at'] ?? $trainingRecord['created_at'];
                }
            }
            if ($scenarioStartDatetime) {
                // Format as human-readable date
                $formattedDate = date('F j, Y g:i A', strtotime($scenarioStartDatetime));
                $htmlContent = preg_replace(
                    '/<span[^>]*data-basename=["\']SCENARIO_START_DATETIME["\'][^>]*>.*?<\/span>/is',
                    htmlspecialchars($formattedDate),
                    $htmlContent
                );
            }

            // Get recipient email from training_tracking
            $recipientEmail = $session['recipient_email_address'] ?? '';

            // RECIPIENT_EMAIL_ADDRESS / recipient_email_address - Replace with recipient email
            if ($recipientEmail) {
                $htmlContent = preg_replace(
                    '/<span[^>]*data-basename=["\']RECIPIENT_EMAIL_ADDRESS["\'][^>]*>.*?<\/span>/is',
                    htmlspecialchars($recipientEmail),
                    $htmlContent
                );
                $htmlContent = preg_replace(
                    '/<span[^>]*data-basename=["\']recipient_email_address["\'][^>]*>.*?<\/span>/is',
                    htmlspecialchars($recipientEmail),
                    $htmlContent
                );

                // RECIPIENT_EMAIL_DOMAIN - Extract and replace domain
                $emailParts = explode('@', $recipientEmail);
                if (count($emailParts) === 2) {
                    $recipientDomain = $emailParts[1];
                    $htmlContent = preg_replace(
                        '/<span[^>]*data-basename=["\']RECIPIENT_EMAIL_DOMAIN["\'][^>]*>.*?<\/span>/is',
                        htmlspecialchars($recipientDomain),
                        $htmlContent
                    );
                }
            }

            // FROM_EMAIL_ADDRESS / from_full_email_address and FROM_FRIENDLY_NAME - Get from associated email content
            if (!empty($training['training_email_content_id'])) {
                // Get email content details
                $emailContent = $db->fetchOne(
                    'SELECT email_from_address, legacy_id FROM content WHERE id = :id',
                    [':id' => $training['training_email_content_id']]
                );

                if ($emailContent) {
                    // FROM_EMAIL_ADDRESS / from_full_email_address
                    if (!empty($emailContent['email_from_address'])) {
                        $htmlContent = preg_replace(
                            '/<span[^>]*data-basename=["\']FROM_EMAIL_ADDRESS["\'][^>]*>.*?<\/span>/is',
                            htmlspecialchars($emailContent['email_from_address']),
                            $htmlContent
                        );
                        $htmlContent = preg_replace(
                            '/<span[^>]*data-basename=["\']from_full_email_address["\'][^>]*>.*?<\/span>/is',
                            htmlspecialchars($emailContent['email_from_address']),
                            $htmlContent
                        );
                    }

                    // FROM_FRIENDLY_NAME - Look up from pm_email_template using legacy_id
                    if (!empty($emailContent['legacy_id'])) {
                        try {
                            $pmEmailTemplate = $db->fetchOne(
                                'SELECT from_name FROM pm_email_template WHERE id = :id',
                                [':id' => $emailContent['legacy_id']]
                            );

                            if ($pmEmailTemplate && !empty($pmEmailTemplate['from_name'])) {
                                $htmlContent = preg_replace(
                                    '/<span[^>]*data-basename=["\']FROM_FRIENDLY_NAME["\'][^>]*>.*?<\/span>/is',
                                    htmlspecialchars($pmEmailTemplate['from_name']),
                                    $htmlContent
                                );
                            }
                        } catch (Exception $e) {
                            // pm_email_template table may not exist or be accessible
                            error_log("Could not fetch FROM_FRIENDLY_NAME from pm_email_template: " . $e->getMessage());
                        }
                    }
                }
            }

            // ---------------------------------------------------------
            // Logo Placeholder Replacement
            // ---------------------------------------------------------
            // Replace img tags with class="logo" with the default company logo
            // Future: Look up company-specific logo from content table's company field
            $defaultLogoUrl = htmlspecialchars($basePath) . '/images/CofenseLogo2026.png';

            // Match <img ... class="logo" ... > or <img ... class="...logo..." ... >
            // and replace the src attribute with the default logo
            $htmlContent = preg_replace_callback(
                '/<img([^>]*class=["\'][^"\']*\blogo\b[^"\']*["\'][^>]*)>/i',
                function($matches) use ($defaultLogoUrl) {
                    $imgTag = $matches[1];
                    // Replace existing src attribute with new logo URL
                    if (preg_match('/src=["\'][^"\']*["\']/i', $imgTag)) {
                        $imgTag = preg_replace('/src=["\'][^"\']*["\']/i', 'src="' . $defaultLogoUrl . '"', $imgTag);
                    } else {
                        // Add src attribute if not present
                        $imgTag .= ' src="' . $defaultLogoUrl . '"';
                    }
                    return '<img' . $imgTag . '>';
                },
                $htmlContent
            );

            // ---------------------------------------------------------
            // Program Contact Details Placeholder Replacement
            // ---------------------------------------------------------
            // Replace PROGRAM_CONTACT_DETAILS with default contact text
            // Future: Look up company-specific contact info from content table's company field
            $defaultContactDetails = 'your IT security team';
            $htmlContent = preg_replace(
                '/<span[^>]*data-basename=["\']PROGRAM_CONTACT_DETAILS["\'][^>]*>.*?<\/span>/is',
                htmlspecialchars($defaultContactDetails),
                $htmlContent
            );

            // ---------------------------------------------------------
            // Inject Tracker Script for DB-served content
            // ---------------------------------------------------------
            // entry_body_html is the clean HTML captured before base tag and
            // tracking script injection at upload time, so it doesn't carry
            // ocms-tracker.js or the api-base meta tag. Inject them here.
            // This is needed for: view tracking, score recording (RecordTest /
            // SCORM API), interaction tracking, and landing page form interception.
            // S3 fallback and local fallback content already have these from upload.
            if ($servedFromDB) {
                $apiBaseMeta = '<meta name="ocms-api-base" content="' . htmlspecialchars($basePath) . '/api">';
                $trackerScript = '<script src="' . htmlspecialchars($basePath) . '/js/ocms-tracker.js"></script>';

                // Inject api-base meta in head
                if (stripos($htmlContent, '</head>') !== false) {
                    $htmlContent = str_ireplace('</head>', $apiBaseMeta . "\n</head>", $htmlContent);
                } elseif (stripos($htmlContent, '<head>') !== false) {
                    $htmlContent = preg_replace('/<head>/i', '<head>' . "\n" . $apiBaseMeta, $htmlContent, 1);
                }

                // Inject tracker script before closing body tag
                if (stripos($htmlContent, '</body>') !== false) {
                    $htmlContent = str_ireplace('</body>', $trackerScript . "\n</body>", $htmlContent);
                } else {
                    $htmlContent .= $trackerScript;
                }
            }

            // ---------------------------------------------------------
            // Form Interception for Landing Pages
            // ---------------------------------------------------------
            // For landing pages with forms, inject meta tag with next URL.
            // ocms-tracker.js handles form interception when it sees the next-url meta tag.
            // The tracker script itself is already present: injected above for DB-served
            // content, or baked in at upload time for S3/local content.
            // For non-DB landing pages that don't already have the tracker, also inject it.
            if ($isLandingPage && stripos($htmlContent, '<form') !== false) {
                $landingPageMeta = '<meta name="ocms-next-url" content="' . htmlspecialchars($nextStepUrl, ENT_QUOTES, 'UTF-8') . '">';

                // Inject meta tag in head
                if (stripos($htmlContent, '</head>') !== false) {
                    $htmlContent = str_ireplace('</head>', $landingPageMeta . "\n</head>", $htmlContent);
                } elseif (stripos($htmlContent, '<head>') !== false) {
                    $htmlContent = preg_replace('/<head>/i', '<head>' . "\n" . $landingPageMeta, $htmlContent, 1);
                }

                // Inject tracker script for non-DB content (DB content already has it above)
                if (!$servedFromDB) {
                    $trackerScript = '<script src="' . htmlspecialchars($basePath) . '/js/ocms-tracker.js"></script>';
                    if (stripos($htmlContent, '</body>') !== false) {
                        $htmlContent = str_ireplace('</body>', $trackerScript . "\n</body>", $htmlContent);
                    } else {
                        $htmlContent .= $trackerScript;
                    }
                }
            }

            // Output the (possibly modified) content
            echo $htmlContent;
            break;

        default:
            http_response_code(400);
            echo '<h1>Error: Unsupported content type</h1>';
            exit;
    }

} catch (Exception $e) {
    error_log("Launch Error: " . $e->getMessage());
    http_response_code(500);
    echo '<h1>Error: Failed to load content</h1>';
    if ($config['app']['debug']) {
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}
