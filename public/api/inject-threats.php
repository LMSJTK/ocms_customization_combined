<?php
/**
 * AI Threat Injection API
 *
 * POST /api/inject-threats.php — use Claude to inject phishing threat indicators into email HTML
 *
 * JSON body:
 *   - html (required): the email HTML to modify
 *   - threat_types (required): array of cue names to inject
 *   - intensity (optional): 'subtle' or 'obvious' (default: 'subtle')
 *
 * Returns: { success, html, injected_cues, difficulty }
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
    $input = getJSONInput();

    if (empty($input['html']) && empty($input['content_id'])) {
        sendJSON(['success' => false, 'error' => 'html or content_id is required'], 400);
    }

    if (empty($input['threat_types']) || !is_array($input['threat_types'])) {
        sendJSON(['success' => false, 'error' => 'threat_types array is required'], 400);
    }

    // Get HTML from content_id if not provided directly
    $html = $input['html'] ?? null;
    if (!$html && !empty($input['content_id'])) {
        $content = $db->fetchOne(
            'SELECT email_body_html, entry_body_html FROM content WHERE id = :id',
            [':id' => $input['content_id']]
        );
        if (!$content) {
            sendJSON(['success' => false, 'error' => 'Content not found'], 404);
        }
        $html = $content['email_body_html'] ?: $content['entry_body_html'];
    }

    if (empty($html)) {
        sendJSON(['success' => false, 'error' => 'No HTML content to process'], 400);
    }

    // Validate threat types against taxonomy
    require_once '/var/www/html/lib/ThreatTaxonomy.php';
    $validCues = ThreatTaxonomy::getAllCueNames();
    $threatTypes = array_filter($input['threat_types'], function($t) use ($validCues) {
        return in_array($t, $validCues);
    });

    if (empty($threatTypes)) {
        sendJSON(['success' => false, 'error' => 'No valid threat types provided'], 400);
    }

    $intensity = $input['intensity'] ?? 'subtle';
    if (!in_array($intensity, ['subtle', 'obvious'])) {
        $intensity = 'subtle';
    }

    $result = $claudeAPI->injectThreats($html, $threatTypes, $intensity);

    sendJSON([
        'success' => true,
        'html' => $result['html'],
        'injected_cues' => $result['injected_cues'],
        'difficulty' => $result['difficulty'],
    ]);

} catch (Exception $e) {
    error_log('Threat Injection Error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'error' => 'Threat injection failed',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
