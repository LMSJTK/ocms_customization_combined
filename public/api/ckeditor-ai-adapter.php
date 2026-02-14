<?php
/**
 * CKEditor AI Adapter — OpenAI-to-Anthropic Streaming Proxy
 *
 * POST /api/ckeditor-ai-adapter.php
 *
 * Receives requests from CKEditor's AI Assistant (OpenAI chat completions format),
 * translates them to the Anthropic Messages API, and streams responses back
 * in OpenAI-compatible SSE format.
 *
 * This allows CKEditor's AI features to use Claude as the underlying model
 * instead of OpenAI, without any client-side awareness of the translation.
 *
 * Requires: Bearer token authentication
 */

require_once '/var/www/html/public/api/bootstrap.php';

validateBearerToken($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed. Use POST.'], 405);
}

// Parse the incoming OpenAI-format request
$input = getJSONInput();
if (!$input || !isset($input['messages'])) {
    sendJSON(['error' => 'Invalid request: messages field is required'], 400);
}

// ── Extract and transform messages ───────────────────────────────
// OpenAI format puts system messages in the messages array.
// Anthropic format uses a separate 'system' parameter.
$systemPrompt = null;
$anthropicMessages = [];

foreach ($input['messages'] as $msg) {
    if ($msg['role'] === 'system') {
        // Concatenate system messages (there may be multiple)
        $systemPrompt = $systemPrompt
            ? $systemPrompt . "\n\n" . $msg['content']
            : $msg['content'];
    } else {
        $anthropicMessages[] = [
            'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
            'content' => $msg['content']
        ];
    }
}

if (empty($anthropicMessages)) {
    sendJSON(['error' => 'No user/assistant messages provided'], 400);
}

// ── Build Anthropic API payload ──────────────────────────────────
$anthropicPayload = [
    'model' => $config['claude']['model'],
    'max_tokens' => $input['max_tokens'] ?? $config['claude']['max_tokens'] ?? 4096,
    'stream' => true,
    'messages' => $anthropicMessages
];

if ($systemPrompt) {
    $anthropicPayload['system'] = $systemPrompt;
}

// ── Set up SSE streaming response ────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable output buffering at all levels
while (ob_get_level()) {
    ob_end_flush();
}

// Generate a consistent completion ID for this response
$completionId = 'chatcmpl-' . bin2hex(random_bytes(12));

// ── Buffer for processing Anthropic SSE events ──────────────────
$sseBuffer = '';

/**
 * Emit an OpenAI-format SSE chunk to the client
 */
function emitOpenAIChunk($completionId, $delta, $finishReason = null) {
    $chunk = [
        'id' => $completionId,
        'object' => 'chat.completion.chunk',
        'choices' => [[
            'index' => 0,
            'delta' => $delta,
            'finish_reason' => $finishReason
        ]]
    ];
    echo 'data: ' . json_encode($chunk) . "\n\n";
    flush();
}

/**
 * Process a single Anthropic SSE event and emit the corresponding OpenAI chunk
 */
function processAnthropicEvent($eventType, $eventData, $completionId) {
    if (!$eventData) {
        return;
    }

    $data = json_decode($eventData, true);
    if (!$data) {
        return;
    }

    switch ($eventType) {
        case 'content_block_delta':
            if (isset($data['delta']['type']) && $data['delta']['type'] === 'text_delta') {
                $text = $data['delta']['text'] ?? '';
                if ($text !== '') {
                    emitOpenAIChunk($completionId, ['content' => $text]);
                }
            }
            break;

        case 'message_start':
            // Emit the initial chunk with role
            emitOpenAIChunk($completionId, ['role' => 'assistant', 'content' => '']);
            break;

        case 'message_delta':
            // Message is ending — emit finish_reason
            $stopReason = $data['delta']['stop_reason'] ?? 'stop';
            // Map Anthropic stop reasons to OpenAI format
            $finishReason = ($stopReason === 'end_turn') ? 'stop' : $stopReason;
            emitOpenAIChunk($completionId, (object)[], $finishReason);
            break;

        case 'message_stop':
            // End of stream
            echo "data: [DONE]\n\n";
            flush();
            break;

        // content_block_start, content_block_stop, ping — ignored
    }
}

// ── Make streaming request to Anthropic ──────────────────────────
$ch = curl_init($config['claude']['api_url']);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($anthropicPayload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $config['claude']['api_key'],
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 300,
    // Process each chunk as it arrives from Anthropic
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$sseBuffer, $completionId) {
        $sseBuffer .= $chunk;

        // Process complete SSE events (delimited by double newline)
        while (($pos = strpos($sseBuffer, "\n\n")) !== false) {
            $rawEvent = substr($sseBuffer, 0, $pos);
            $sseBuffer = substr($sseBuffer, $pos + 2);

            // Parse the SSE event — extract 'event:' and 'data:' lines
            $eventType = null;
            $eventData = null;

            foreach (explode("\n", $rawEvent) as $line) {
                if (strpos($line, 'event: ') === 0) {
                    $eventType = trim(substr($line, 7));
                } elseif (strpos($line, 'data: ') === 0) {
                    $eventData = trim(substr($line, 6));
                }
            }

            if ($eventType && $eventData) {
                processAnthropicEvent($eventType, $eventData, $completionId);
            }
        }

        return strlen($chunk);
    }
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle errors (if the stream didn't start properly)
if ($curlError) {
    error_log("CKEditor AI Adapter: cURL error: {$curlError}");
    // If we haven't sent any SSE data yet, we can send a JSON error
    echo "data: " . json_encode([
        'error' => ['message' => 'Upstream API error: ' . $curlError, 'type' => 'api_error']
    ]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
}

if ($httpCode !== 200 && $httpCode !== 0) {
    error_log("CKEditor AI Adapter: Anthropic API returned HTTP {$httpCode}");
    // Process any remaining buffer that might contain error info
    if (!empty($sseBuffer)) {
        $errorData = json_decode($sseBuffer, true);
        $errorMsg = $errorData['error']['message'] ?? "Upstream API returned HTTP {$httpCode}";
        echo "data: " . json_encode([
            'error' => ['message' => $errorMsg, 'type' => 'api_error']
        ]) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
    }
}
