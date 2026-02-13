<?php
/**
 * Content Translation API
 *
 * POST /api/translate-content.php              — translate content HTML (preview, no save)
 * POST /api/translate-content.php?action=save  — save translation as a new content record
 *
 * Requires: Bearer token authentication + VPN access
 */

require_once '/var/www/html/public/api/bootstrap.php';

validateBearerToken($config);
validateVpnAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

$action = $_GET['action'] ?? 'translate';

// Supported language codes
$supportedLanguages = [
    'en', 'es', 'fr', 'de', 'pt', 'pt-br', 'ar', 'ja', 'ko',
    'it', 'nl', 'zh', 'zh-tw', 'ru', 'pl', 'sv', 'da', 'fi',
    'nb', 'tr', 'he', 'th', 'vi', 'hi'
];

$languageNames = [
    'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
    'pt' => 'Portuguese', 'pt-br' => 'Portuguese (Brazil)', 'ar' => 'Arabic',
    'ja' => 'Japanese', 'ko' => 'Korean', 'it' => 'Italian', 'nl' => 'Dutch',
    'zh' => 'Chinese (Simplified)', 'zh-tw' => 'Chinese (Traditional)',
    'ru' => 'Russian', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
    'fi' => 'Finnish', 'nb' => 'Norwegian', 'tr' => 'Turkish', 'he' => 'Hebrew',
    'th' => 'Thai', 'vi' => 'Vietnamese', 'hi' => 'Hindi',
];

// ── Translate (preview) ──────────────────────────────────────────────────
if ($action === 'translate') {
    try {
        $input = getJSONInput();
        validateRequired($input, ['content_id', 'target_language']);

        $targetLang = strtolower($input['target_language']);
        $sourceLang = strtolower($input['source_language'] ?? 'en');

        if (!in_array($targetLang, $supportedLanguages)) {
            sendJSON(['success' => false, 'error' => "Unsupported target language: {$targetLang}"], 400);
        }

        // Fetch content
        $content = $db->fetchOne(
            'SELECT id, title, content_type, entry_body_html, email_body_html FROM content WHERE id = :id',
            [':id' => $input['content_id']]
        );
        if (!$content) {
            sendJSON(['success' => false, 'error' => 'Content not found'], 404);
        }

        // Determine which HTML to translate
        $html = null;
        if ($content['content_type'] === 'email') {
            $html = $content['email_body_html'];
        }
        if (empty($html)) {
            $html = $content['entry_body_html'];
        }
        if (empty($html)) {
            sendJSON(['success' => false, 'error' => 'Content has no HTML body to translate'], 400);
        }

        // Translate
        $result = $claudeAPI->translateContent($html, $targetLang, $sourceLang);

        sendJSON([
            'success' => true,
            'html' => $result['html'],
            'source_language' => $result['source_language'],
            'target_language' => $result['target_language'],
            'content_id' => $input['content_id'],
        ]);

    } catch (Exception $e) {
        error_log('Translation Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Translation failed',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

// ── Save translation as new content record ───────────────────────────────
if ($action === 'save') {
    try {
        $input = getJSONInput();
        validateRequired($input, ['content_id', 'target_language', 'translated_html']);

        $sourceContentId = $input['content_id'];
        $targetLang = strtolower($input['target_language']);
        $translatedHtml = $input['translated_html'];

        if (!in_array($targetLang, $supportedLanguages)) {
            sendJSON(['success' => false, 'error' => "Unsupported target language: {$targetLang}"], 400);
        }

        // Fetch source content
        $source = $db->fetchOne(
            'SELECT * FROM content WHERE id = :id',
            [':id' => $sourceContentId]
        );
        if (!$source) {
            sendJSON(['success' => false, 'error' => 'Source content not found'], 404);
        }

        // Check if translation already exists for this language
        try {
            $existingTranslation = $db->fetchOne(
                'SELECT translated_content_id FROM content_translations WHERE source_content_id = :source AND target_language = :lang',
                [':source' => $sourceContentId, ':lang' => $targetLang]
            );
            if ($existingTranslation) {
                sendJSON([
                    'success' => false,
                    'error' => "A translation to {$targetLang} already exists for this content",
                    'existing_content_id' => $existingTranslation['translated_content_id']
                ], 409);
            }
        } catch (Exception $e) {
            // content_translations table may not exist yet — proceed
            error_log("content_translations lookup skipped: " . $e->getMessage());
        }

        $langName = $languageNames[$targetLang] ?? $targetLang;
        $newId = generateUUID4();
        $title = $input['title'] ?? "{$source['title']} ({$langName})";

        // Determine which HTML field to populate
        $htmlField = ($source['content_type'] === 'email') ? 'email_body_html' : 'entry_body_html';

        $db->beginTransaction();
        try {
            // Create new content record
            $newContent = [
                'id' => $newId,
                'company_id' => $source['company_id'],
                'title' => $title,
                'description' => $source['description'],
                'content_type' => $source['content_type'],
                'tags' => $source['tags'],
                'difficulty' => $source['difficulty'],
                'scorable' => $source['scorable'],
                $htmlField => $translatedHtml,
            ];

            // Copy email-specific fields if applicable
            if ($source['content_type'] === 'email') {
                $newContent['email_from_address'] = $source['email_from_address'];
                $newContent['email_subject'] = $source['email_subject'];
                $newContent['content_domain'] = $source['content_domain'];
            }

            $db->insert('content', $newContent);

            // Record the translation relationship
            try {
                $db->insert('content_translations', [
                    'source_content_id' => $sourceContentId,
                    'translated_content_id' => $newId,
                    'source_language' => $input['source_language'] ?? 'en',
                    'target_language' => $targetLang,
                ]);
            } catch (Exception $e) {
                // content_translations table may not exist — log but don't fail
                error_log("Could not record translation relationship: " . $e->getMessage());
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        sendJSON([
            'success' => true,
            'content_id' => $newId,
            'title' => $title,
            'language' => $targetLang,
            'source_content_id' => $sourceContentId,
        ], 201);

    } catch (Exception $e) {
        error_log('Translation Save Error: ' . $e->getMessage());
        sendJSON([
            'success' => false,
            'error' => 'Failed to save translation',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

sendJSON(['success' => false, 'error' => 'Invalid action. Use translate (default) or save.'], 400);
