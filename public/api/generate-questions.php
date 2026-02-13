<?php
/**
 * Generate Quiz Questions API Endpoint
 *
 * Generates AI-powered quiz questions for existing content
 * and optionally injects them into the content HTML.
 *
 * POST /api/generate-questions.php
 *
 * Request body:
 * {
 *   "content_id": "uuid",           // Required: Content to generate questions for
 *   "num_questions": 3,             // Optional: Number of questions (2-5, default 3)
 *   "inject": true                  // Optional: Whether to inject quiz into content (default false)
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "questions": [...],             // Array of generated questions
 *   "quiz_html": "...",             // HTML block with self-scoring quiz
 *   "num_questions": 3,
 *   "injected": false,              // Whether quiz was injected into content
 *   "content": {                    // Content info
 *     "id": "...",
 *     "title": "..."
 *   }
 * }
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

// Get JSON input
$input = getJSONInput();

// Validate required fields
if (!isset($input['content_id']) || empty($input['content_id'])) {
    sendJSON(['error' => 'Missing required field: content_id'], 400);
}

$contentId = $input['content_id'];
$numQuestions = isset($input['num_questions']) ? (int)$input['num_questions'] : 3;
$inject = isset($input['inject']) ? (bool)$input['inject'] : false;

// Clamp number of questions
$numQuestions = max(2, min(5, $numQuestions));

try {
    // Get content from database
    $content = $db->fetchOne(
        "SELECT id, title, content_type, content_url, email_body_html FROM content WHERE id = ?",
        [$contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Read the content HTML based on content type
    $uploadDir = $config['content']['upload_dir'];
    $htmlContent = '';
    $contentPath = null;

    if ($content['content_type'] === 'email') {
        // Email content is stored in the database
        $htmlContent = $content['email_body_html'] ?? '';
        if (empty($htmlContent)) {
            sendJSON(['error' => 'Email content has no HTML body'], 400);
        }
    } else {
        // File-based content (training, html, scorm, landing)
        if (empty($content['content_url'])) {
            sendJSON(['error' => 'Content has no content_url'], 400);
        }

        $contentPath = $uploadDir . $content['content_url'];

        if (!file_exists($contentPath)) {
            sendJSON(['error' => 'Content file not found on disk: ' . $content['content_url']], 404);
        }

        $htmlContent = file_get_contents($contentPath);
    }

    if (empty($htmlContent)) {
        sendJSON(['error' => 'Content is empty'], 400);
    }

    // Generate quiz questions using Claude API
    error_log("Generating {$numQuestions} quiz questions for content: {$contentId}");
    $quizResult = $claudeAPI->generateQuizQuestions($htmlContent, $numQuestions);

    $response = [
        'success' => true,
        'questions' => $quizResult['questions'],
        'quiz_html' => $quizResult['quiz_html'],
        'num_questions' => $quizResult['num_questions'],
        'injected' => false,
        'content' => [
            'id' => $content['id'],
            'title' => $content['title'],
            'content_type' => $content['content_type']
        ]
    ];

    // Optionally inject quiz into content
    if ($inject) {
        // Find injection point - before </body> or at end of content
        $quizHtml = $quizResult['quiz_html'];

        if (stripos($htmlContent, '</body>') !== false) {
            // Inject before </body>
            $modifiedHtml = preg_replace(
                '/<\/body>/i',
                $quizHtml . "\n</body>",
                $htmlContent,
                1
            );
        } else {
            // Append to end
            $modifiedHtml = $htmlContent . "\n" . $quizHtml;
        }

        // Write modified content back based on content type
        if ($content['content_type'] === 'email') {
            // Update database for email content (also set scorable=true since quiz includes RecordTest)
            $db->execute(
                "UPDATE content SET email_body_html = ?, scorable = true WHERE id = ?",
                [$modifiedHtml, $contentId]
            );
            $response['injected'] = true;
            $response['scorable'] = true;
            error_log("Quiz injected into email content (database): {$contentId}");
        } else {
            // Write to file for file-based content
            if ($contentPath && file_put_contents($contentPath, $modifiedHtml) !== false) {
                // Also set scorable=true since quiz includes RecordTest
                $db->execute(
                    "UPDATE content SET scorable = true WHERE id = ?",
                    [$contentId]
                );
                $response['injected'] = true;
                $response['scorable'] = true;
                error_log("Quiz injected into content file: {$contentId}");
            } else {
                error_log("Failed to write quiz to content file: {$contentPath}");
                $response['inject_error'] = 'Failed to write modified content';
            }
        }
    }

    sendJSON($response);

} catch (Exception $e) {
    error_log("Error generating quiz questions: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to generate quiz questions',
        'message' => $e->getMessage()
    ], 500);
}
