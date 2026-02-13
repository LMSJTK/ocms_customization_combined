<?php
/**
 * Claude API Integration Class
 * Handles communication with Anthropic's Claude API for content tagging
 */

class ClaudeAPI {
    private $config;
    private $apiKey;
    private $apiUrl;
    private $model;
    private $maxTokens;
    private $maxContentSize;

    // Allowed tags for educational content (used by both analyze and tag methods)
    private $allowedTags = [
        'brand-impersonation', 'compliance', 'emotions', 'financial-transactions',
        'cloud', 'mobile', 'news-and-events', 'office-communications',
        'passwords', 'reporting', 'safe-web-browsing', 'shipment-and-deliveries',
        'small-medium-businesses', 'social-media', 'spear-phishing', 'data-breach',
        'malware', 'mfa', 'personal-security', 'physical-security', 'ransomware',
        'shared-file', 'bec-ceo-fraud', 'credential-phish',
        'qr-codes', 'url-phish'
    ];

    public function __construct($config) {
        $this->config = $config;
        $this->apiKey = $config['api_key'];
        $this->apiUrl = $config['api_url'];
        $this->model = $config['model'];
        $this->maxTokens = $config['max_tokens'];
        $this->maxContentSize = $config['max_content_size'] ?? 500000; // Default to 500KB
    }

    /**
     * Send request to Claude API
     */
    private function sendRequest($messages, $systemPrompt = null) {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Claude API cURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("Claude API HTTP Error {$httpCode}: {$response}");
        }

        $result = json_decode($response, true);
        if (!isset($result['content'][0]['text'])) {
            throw new Exception("Unexpected Claude API response format");
        }

        // Log if response was truncated
        if (isset($result['stop_reason']) && $result['stop_reason'] === 'max_tokens') {
            error_log("WARNING: Claude API response truncated due to max_tokens limit");
        }

        return $result['content'][0]['text'];
    }

    /**
     * Strip markdown code blocks from response
     */
    private function stripMarkdownCodeBlocks($text) {
        // Remove ```html ... ``` or ```... ``` blocks
        $text = preg_replace('/```(?:html)?\s*\n?(.*?)\n?```/s', '$1', $text);
        // Also remove any leading/trailing whitespace
        return trim($text);
    }

    /**
     * Extract only HTML from response, removing explanatory text
     */
    private function extractHTMLOnly($text) {
        // If the text starts with <!DOCTYPE or <html or <, it's likely all HTML
        if (preg_match('/^\s*(<(!DOCTYPE|html|head|body|div|form|script|style|!--|meta|link))/i', $text)) {
            // Find where HTML ends - look for common ending patterns followed by explanation text
            // Look for closing </html> or </body> followed by non-HTML text
            if (preg_match('/(.*?<\/html>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/(.*?<\/body>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
        }

        // Try to extract HTML between first < and last >
        if (preg_match('/<.*>/s', $text, $matches)) {
            return trim($matches[0]);
        }

        return trim($text);
    }

    /**
     * Analyze HTML content to identify topics/tags without modifying the HTML
     * Uses a stripped-down version of the content to save tokens
     *
     * This is an optimized alternative to tagHTMLContent() that:
     * 1. Strips scripts, styles, and comments to reduce input tokens
     * 2. Returns only a JSON array of tags (not the full HTML)
     * 3. Significantly reduces both input and output token usage
     *
     * @param string $htmlContent The HTML content to analyze
     * @return array List of matching tag names from the allowed tags
     */
    public function analyzeHTMLContent($htmlContent) {
        // 1. Strip scripts, styles, and comments to reduce token usage
        $cleanHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $htmlContent);
        $cleanHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleanHtml);
        $cleanHtml = preg_replace('/<!--.*?-->/s', '', $cleanHtml);

        // 2. Remove excessive whitespace
        $cleanHtml = preg_replace('/\s+/', ' ', $cleanHtml);
        $cleanHtml = trim($cleanHtml);

        // 3. Limit content size for analysis (take the first 50KB of text content)
        // This is usually enough to determine the topic
        $maxAnalysisSize = 50000;
        if (strlen($cleanHtml) > $maxAnalysisSize) {
            $cleanHtml = substr($cleanHtml, 0, $maxAnalysisSize);
            error_log("analyzeHTMLContent: Content truncated to {$maxAnalysisSize} bytes for analysis");
        }

        $originalSize = strlen($htmlContent);
        $cleanedSize = strlen($cleanHtml);
        error_log("analyzeHTMLContent: Reduced content from {$originalSize} to {$cleanedSize} bytes (" .
                  round(($cleanedSize / $originalSize) * 100, 1) . "% of original)");

        $allowedTagsList = implode(', ', $this->allowedTags);

        $systemPrompt = "You are an expert at analyzing educational content about cybersecurity and phishing awareness. " .
            "Your task is to identify the core learning objectives and security topics in the provided HTML content.\n\n" .
            "Return a JSON array of tags from the ALLOWED LIST below that best match the content topics.\n\n" .
            "ALLOWED TAGS:\n" . $allowedTagsList . "\n\n" .
            "RULES:\n" .
            "1. Only use tags from the allowed list above\n" .
            "2. Return 1-5 tags that best describe the main topics\n" .
            "3. Return ONLY the JSON array, no explanation (e.g., [\"passwords\", \"social-media\"])\n" .
            "4. If no topics match, return an empty array: []";

        $messages = [
            [
                'role' => 'user',
                'content' => "Analyze this educational content and return matching tags as a JSON array:\n\n" . $cleanHtml
            ]
        ];

        try {
            $response = $this->sendRequest($messages, $systemPrompt);

            // Extract JSON array from response
            if (preg_match('/\[.*?\]/s', $response, $matches)) {
                $tags = json_decode($matches[0], true);
                // Filter to ensure only allowed tags are returned
                if (is_array($tags)) {
                    $validTags = array_values(array_intersect($tags, $this->allowedTags));
                    error_log("analyzeHTMLContent: Found " . count($validTags) . " valid tags: " . implode(', ', $validTags));
                    return $validTags;
                }
            }

            error_log("analyzeHTMLContent: Could not parse tags from response: " . substr($response, 0, 200));
        } catch (Exception $e) {
            error_log("analyzeHTMLContent: Error during analysis: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Suggest a phishing domain for content based on its title, description, and tags
     * Uses Claude to match content to the most appropriate domain from available options
     *
     * @param string $title Content title
     * @param string $description Content description
     * @param array $tags Content tags (array of tag names)
     * @param array $domains Available domains (array of ['tag' => string, 'domain' => string])
     * @return array Contains 'domain' (selected domain string), 'tag' (domain tag), 'reasoning' (explanation)
     */
    public function suggestDomain($title, $description, $tags, $domains) {
        if (empty($domains)) {
            throw new Exception("No domains available for selection");
        }

        // Build domain list for the prompt
        $domainList = [];
        foreach ($domains as $d) {
            $domainList[] = "- Tag: \"{$d['tag']}\" → Domain: {$d['domain']}";
        }
        $domainListText = implode("\n", $domainList);

        // Build content summary
        $tagsText = !empty($tags) ? implode(', ', $tags) : 'none';

        $systemPrompt = "You are an expert at matching phishing simulation content to appropriate phishing domains. " .
            "Your task is to select the most suitable domain for the given content based on its theme and purpose.\n\n" .
            "AVAILABLE DOMAINS:\n" . $domainListText . "\n\n" .
            "RULES:\n" .
            "1. Select the domain whose 'tag' best matches the content's theme, industry, or purpose\n" .
            "2. Consider the title, description, and tags to understand what the content is about\n" .
            "3. Match domains that would make the phishing simulation more realistic and believable\n" .
            "4. If the content is about a specific brand/service, choose a domain that mimics that type\n" .
            "5. If no domain is a great match, choose the most generic/versatile option\n\n" .
            "Return ONLY a JSON object with this format:\n" .
            "{\"tag\": \"selected-tag\", \"domain\": \"selected-domain.com\", \"reasoning\": \"Brief explanation\"}";

        $messages = [
            [
                'role' => 'user',
                'content' => "Select the best phishing domain for this content:\n\n" .
                    "Title: {$title}\n" .
                    "Description: {$description}\n" .
                    "Tags: {$tagsText}\n\n" .
                    "Return ONLY the JSON object with your selection."
            ]
        ];

        try {
            $response = $this->sendRequest($messages, $systemPrompt);

            // Extract JSON from response
            if (preg_match('/\{[\s\S]*\}/s', $response, $matches)) {
                $result = json_decode($matches[0], true);

                if (isset($result['domain']) && isset($result['tag'])) {
                    // Validate the selected domain is in our list
                    foreach ($domains as $d) {
                        if ($d['tag'] === $result['tag'] || $d['domain'] === $result['domain']) {
                            error_log("suggestDomain: Selected domain '{$result['domain']}' (tag: {$result['tag']}) for content: {$title}");
                            return [
                                'domain' => $d['domain'],
                                'tag' => $d['tag'],
                                'reasoning' => $result['reasoning'] ?? 'No reasoning provided'
                            ];
                        }
                    }

                    // If returned domain not in list, log warning and return first match by tag
                    error_log("suggestDomain: Warning - returned domain not in list, attempting tag match");
                    foreach ($domains as $d) {
                        if (stripos($d['tag'], $result['tag']) !== false) {
                            return [
                                'domain' => $d['domain'],
                                'tag' => $d['tag'],
                                'reasoning' => $result['reasoning'] ?? 'Matched by tag similarity'
                            ];
                        }
                    }
                }
            }

            // Fallback: return first domain if parsing fails
            error_log("suggestDomain: Could not parse response, using first available domain");
            return [
                'domain' => $domains[0]['domain'],
                'tag' => $domains[0]['tag'],
                'reasoning' => 'Default selection - could not parse AI response'
            ];

        } catch (Exception $e) {
            error_log("suggestDomain: Error during domain suggestion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Protect sensitive HTML blocks (e.g., script/link tags) by replacing them with placeholders
     * Returns array with 'html' (placeholders inserted) and 'protectedBlocks' (placeholder => original block)
     */
    private function protectSensitiveBlocks($html) {
        $protectedBlocks = [];
        $tokenCounter = 0;

        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<link\b[^>]*?>/i'
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function ($matches) use (&$protectedBlocks, &$tokenCounter) {
                $token = '__PROTECTED_BLOCK_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $placeholder = '<!-- ' . $token . ' -->';
                $protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            }, $html);
        }

        return [
            'html' => $html,
            'protectedBlocks' => $protectedBlocks
        ];
    }

    /**
     * Restore sensitive HTML blocks that were replaced with placeholders
     */
    private function restoreProtectedBlocks($html, $protectedBlocks) {
        foreach ($protectedBlocks as $placeholder => $originalBlock) {
            $html = str_replace($placeholder, $originalBlock, $html);
        }

        return $html;
    }

    /**
     * Extract and tokenize file references from HTML
     * Returns array with 'html' (tokenized) and 'referenceMap' (token => original value)
     */
    private function tokenizeReferences($html) {
        $referenceMap = [];
        $tokenCounter = 0;

        // Attributes that contain file references
        $attributePatterns = [
            '/\ssrc\s*=\s*["\']([^"\']+)["\']/i',
            '/\shref\s*=\s*["\']([^"\']+)["\']/i',
            '/\ssrcset\s*=\s*["\']([^"\']+)["\']/i',
            '/\sposter\s*=\s*["\']([^"\']+)["\']/i',
            '/\sdata-src\s*=\s*["\']([^"\']+)["\']/i',
            '/\sdata-href\s*=\s*["\']([^"\']+)["\']/i',
            '/\saction\s*=\s*["\']([^"\']+)["\']/i',
            '/\sbackground\s*=\s*["\']([^"\']+)["\']/i',
        ];

        foreach ($attributePatterns as $pattern) {
            $html = preg_replace_callback($pattern, function($matches) use (&$referenceMap, &$tokenCounter) {
                $fullMatch = $matches[0];
                $url = $matches[1];

                // Skip if it's already a placeholder, data URI, or absolute https URL to external domains
                if (strpos($url, '__ASSET_REF_') === 0 ||
                    strpos($url, 'data:') === 0 ||
                    strpos($url, 'javascript:') === 0 ||
                    strpos($url, 'mailto:') === 0) {
                    return $fullMatch;
                }

                // Generate unique token
                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                // Replace the URL in the matched attribute with the token
                return str_replace($url, $token, $fullMatch);
            }, $html);
        }

        // Handle CSS url() references in style attributes
        $html = preg_replace_callback('/\sstyle\s*=\s*["\']([^"\']*url\([^)]+\)[^"\']*)["\']/i', function($matches) use (&$referenceMap, &$tokenCounter) {
            $fullMatch = $matches[0];
            $styleContent = $matches[1];

            // Find all url() references within this style attribute
            $tokenizedStyle = preg_replace_callback('/url\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', function($urlMatches) use (&$referenceMap, &$tokenCounter) {
                $url = $urlMatches[1];

                // Skip data URIs and already-tokenized values
                if (strpos($url, '__ASSET_REF_') === 0 || strpos($url, 'data:') === 0) {
                    return $urlMatches[0];
                }

                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                return str_replace($url, $token, $urlMatches[0]);
            }, $styleContent);

            return str_replace($styleContent, $tokenizedStyle, $fullMatch);
        }, $html);

        // Handle CSS url() references in <style> tags
        $html = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) use (&$referenceMap, &$tokenCounter) {
            $fullMatch = $matches[0];
            $styleContent = $matches[1];

            $tokenizedStyle = preg_replace_callback('/url\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', function($urlMatches) use (&$referenceMap, &$tokenCounter) {
                $url = $urlMatches[1];

                if (strpos($url, '__ASSET_REF_') === 0 || strpos($url, 'data:') === 0) {
                    return $urlMatches[0];
                }

                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                return str_replace($url, $token, $urlMatches[0]);
            }, $styleContent);

            return str_replace($styleContent, $tokenizedStyle, $fullMatch);
        }, $html);

        return [
            'html' => $html,
            'referenceMap' => $referenceMap
        ];
    }

    /**
     * Restore original file references from tokens
     */
    private function restoreReferences($html, $referenceMap) {
        // Simple str_replace for each token
        foreach ($referenceMap as $token => $originalUrl) {
            $html = str_replace($token, $originalUrl, $html);
        }

        return $html;
    }

    /**
     * Tag HTML content with interactive elements
     */
    public function tagHTMLContent($htmlContent, $contentType = 'educational') {
        // Check content size - skip AI processing if too large
        $contentSize = strlen($htmlContent);
        if ($contentSize > $this->maxContentSize) {
            error_log("Content size ({$contentSize} bytes) exceeds max_content_size ({$this->maxContentSize} bytes) - skipping AI processing");
            return [
                'html' => $htmlContent,
                'tags' => []
            ];
        }

        error_log("Content size: {$contentSize} bytes - proceeding with AI processing");

        // STEP 0: Protect sensitive blocks before any other processing
        $protected = $this->protectSensitiveBlocks($htmlContent);
        $protectedHtml = $protected['html'];
        $protectedBlocks = $protected['protectedBlocks'];

        error_log("Protected " . count($protectedBlocks) . " sensitive blocks before AI processing");

        // STEP 1: Tokenize all file references before sending to AI
        $tokenized = $this->tokenizeReferences($protectedHtml);
        $tokenizedHtml = $tokenized['html'];
        $referenceMap = $tokenized['referenceMap'];

        error_log("Tokenized " . count($referenceMap) . " file references before AI processing");

        // Use class property for allowed tags
        $allowedTagsList = implode(', ', $this->allowedTags);

        $systemPrompt = "You are an expert at analyzing educational content and identifying key assessment elements. " .
            "Your task is to SELECTIVELY add data-tag attributes ONLY to the most important interactive elements that represent core learning objectives or assessments.\n\n" .
            "ALLOWED TAGS (use ONLY these tags):\n" .
            "$allowedTagsList\n\n" .
            "CRITICAL RULES - FOLLOW EXACTLY:\n" .
            "1. ONLY add data-tag attributes to interactive elements (inputs, buttons, selects, textareas, clickable elements)\n" .
            "2. Tag values MUST be one of the allowed tags listed above - use ONLY these exact tag names\n" .
            "3. Make ZERO other changes to the HTML - preserve ALL:\n" .
            "   - Exact formatting, spacing, and indentation\n" .
            "   - All existing attributes exactly as written\n" .
            "   - All content, text, and structure\n" .
            "   - All comments, scripts, and styles\n" .
            "   - Character encoding and special characters\n" .
            "4. Return the COMPLETE HTML exactly as provided, with ONLY data-tag attributes added where appropriate\n" .
            "5. Do not fix, clean, or optimize the HTML in any way\n" .
            "6. Do not include explanations, comments, or markdown - return ONLY the raw HTML\n" .
            "7. If this appears to be a partial HTML chunk (missing opening/closing tags), that's expected - process it as-is";

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-tag attributes to interactive elements in this HTML. Make NO other changes whatsoever. " .
                    "Return ONLY the HTML with data-tag attributes added:\n\n" . $htmlContent
            ]
        ];

        // STEP 2: Send tokenized HTML to AI
        $taggedHTML = $this->sendRequest($messages, $systemPrompt);

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($taggedHTML);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // STEP 3: Restore original file references
        $taggedHTML = $this->restoreReferences($taggedHTML, $referenceMap);

        error_log("Restored " . count($referenceMap) . " file references after AI processing");

        // STEP 4: Restore protected blocks (e.g., scripts, links)
        $taggedHTML = $this->restoreProtectedBlocks($taggedHTML, $protectedBlocks);

        error_log("Restored " . count($protectedBlocks) . " protected blocks after AI processing");

        // STEP 5: Validate output size - check if response was significantly truncated
        $outputSize = strlen($taggedHTML);
        $sizeRatio = $outputSize / $contentSize;

        if ($sizeRatio < 0.8) {
            error_log("WARNING: Output size ({$outputSize} bytes) is significantly smaller than input size ({$contentSize} bytes). Response may have been truncated.");
            error_log("Size ratio: " . round($sizeRatio * 100, 2) . "%. Consider increasing max_tokens or max_content_size config.");
        }

        // Extract tags that were added
        preg_match_all('/data-tag="([^"]+)"/', $taggedHTML, $matches);
        $tags = array_unique($matches[1]);

        // Filter to only allowed tags
        $tags = array_values(array_intersect($tags, $this->allowedTags));

        return [
            'html' => $taggedHTML,
            'tags' => $tags
        ];
    }

    /**
     * Tag phishing email content with NIST Phish Scale cues
     */
    public function tagPhishingEmail($emailHTML, $nistGuideContent = null) {
        // Load cue taxonomy from shared source of truth
        require_once '/var/www/html/lib/ThreatTaxonomy.php';
        $cueTypes = ThreatTaxonomy::getCueTypesForPrompt();

        // Build cue documentation for the prompt
        $cueDocumentation = "";
        foreach ($cueTypes as $cueType) {
            $cueDocumentation .= "\n{$cueType['type']}:\n";
            foreach ($cueType['cues'] as $cue) {
                $cueDocumentation .= "  - {$cue['name']}: {$cue['criteria']}\n";
            }
        }

        $systemPrompt = "You are an expert at analyzing phishing emails using the NIST Phishing Scale methodology. " .
            "Your task is to identify phishing indicators and add data-cue attributes to mark them.\n\n" .
            "PHISHING CUE TYPES AND CRITERIA:\n" .
            $cueDocumentation . "\n" .
            "NIST Phish Scale Difficulty Ratings:\n" .
            "- Least Difficult (\"least\"): Multiple obvious red flags, amateur mistakes, very easy to detect\n" .
            "- Moderately Difficult (\"moderately\"): Some red flags but requires closer inspection, decent attempt\n" .
            "- Very Difficult (\"very\"): Sophisticated, few obvious indicators, requires expert knowledge to detect\n\n" .
            "Rules:\n" .
            "1. Add data-cue attributes to elements containing phishing indicators\n" .
            "2. Use format: data-cue=\"cue-name\" using the exact cue names from the list above (e.g., data-cue=\"sense-of-urgency\")\n" .
            "3. Use ONLY the cue names provided in the list - these are the standardized NIST Phish Scale indicators\n" .
            "4. On the FIRST line, output: DIFFICULTY:X (where X is one of: \"least\", \"moderately\", \"very\")\n" .
            "5. Then output the complete modified HTML with data-cue attributes added\n" .
            "6. Do not modify the content or structure, only add data-cue attributes\n" .
            "7. Do not include any other explanations, comments, or markdown formatting\n" .
            "8. Only add cues where the criteria are clearly met in the email content";

        if ($nistGuideContent) {
            $systemPrompt .= "\n\nReference Guide:\n" . $nistGuideContent;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-cue attributes to phishing indicators in this email using the standardized cue names, and assess its difficulty level. " .
                    "First line must be DIFFICULTY:X (\"least\", \"moderately\", \"very\"), then the modified HTML:\n\n" . $emailHTML
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

 // Extract difficulty level from first line
        $difficulty = 'moderately'; // Default to moderate
        if (preg_match('/^DIFFICULTY:\s*(.+?)$/im', $response, $diffMatch)) {
            $difficultyText = trim($diffMatch[1]);
            // Normalize the difficulty text to match our standard format
            if (stripos($difficultyText, 'least') !== false) {
                $difficulty = 'least';
            } elseif (stripos($difficultyText, 'very') !== false) {
                $difficulty = 'very';
            } elseif (stripos($difficultyText, 'moderate') !== false) {
                $difficulty = 'moderately';
            }
            // Remove the difficulty line from response
            $response = preg_replace('/^DIFFICULTY:\s*.+?\n?/im', '', $response);
        }

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($response);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // Extract cues that were added
        preg_match_all('/data-cue="([^"]+)"/', $taggedHTML, $matches);
        $cues = array_unique($matches[1]);

        return [
            'html' => $taggedHTML,
            'cues' => $cues,
            'difficulty' => $difficulty
        ];
    }

    /**
     * Load an image for Claude Vision API
     * @param string $imageSrc The image source (URL or relative path)
     * @param string|null $contentDir The content directory for resolving relative paths
     * @return array|null Array with 'media_type' and 'data' (base64), or null if failed
     */
    private function loadImageForVision($imageSrc, $contentDir = null) {
        $imageData = null;
        $imagePath = null;

        // Check if it's a data URI
        if (strpos($imageSrc, 'data:') === 0) {
            if (preg_match('/^data:([^;]+);base64,(.+)$/', $imageSrc, $matches)) {
                return [
                    'media_type' => $matches[1],
                    'data' => $matches[2]
                ];
            }
            return null;
        }

        // Check if it's an absolute URL
        if (preg_match('/^https?:\/\//', $imageSrc)) {
            // Fetch from URL
            $imageData = @file_get_contents($imageSrc);
            if ($imageData === false) {
                error_log("Failed to fetch infographic image from URL: {$imageSrc}");
                return null;
            }
        } else {
            // It's a relative path - resolve against content directory
            if ($contentDir) {
                $imagePath = rtrim($contentDir, '/') . '/' . ltrim($imageSrc, '/');
            } else {
                $imagePath = $imageSrc;
            }

            if (!file_exists($imagePath)) {
                error_log("Infographic image not found at: {$imagePath}");
                return null;
            }

            $imageData = @file_get_contents($imagePath);
            if ($imageData === false) {
                error_log("Failed to read infographic image: {$imagePath}");
                return null;
            }
        }

        // Determine media type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->buffer($imageData);

        // Validate it's an image type Claude supports
        $supportedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mediaType, $supportedTypes)) {
            error_log("Unsupported image type for infographic: {$mediaType}");
            return null;
        }

        // Check file size (Claude has limits, typically 20MB but we'll be conservative)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if (strlen($imageData) > $maxSize) {
            error_log("Infographic image too large: " . strlen($imageData) . " bytes");
            return null;
        }

        return [
            'media_type' => $mediaType,
            'data' => base64_encode($imageData)
        ];
    }

    /**
     * Analyze SCORM content and suggest tags
     */
    public function analyzeSCORMContent($htmlContent) {
        $systemPrompt = "You are an expert at analyzing educational content. " .
            "Analyze the provided HTML content and identify the main topics, skills, or knowledge areas being taught or tested.";

        $messages = [
            [
                'role' => 'user',
                'content' => "Please analyze this SCORM content and list the main topics/skills covered. " .
                    "Return a JSON array of topic names (lowercase, hyphenated):\n\n" .
                    substr($htmlContent, 0, 10000) // Limit content size
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Try to parse JSON from response
        preg_match('/\[.*?\]/s', $response, $matches);
        if (!empty($matches)) {
            $tags = json_decode($matches[0], true);
            return is_array($tags) ? $tags : [];
        }

        return [];
    }

    /**
     * Generate quiz questions for educational content
     * @param string $htmlContent The HTML content to generate questions for
     * @param int $numQuestions Number of questions to generate (2-5)
     * @param string|null $contentDir Optional directory path for resolving relative image URLs
     * @param string|null $additionalPrompt Optional extra instructions for quiz generation
     * @return array Contains 'quiz_html' with the generated quiz section
     */
    public function generateQuizQuestions($htmlContent, $numQuestions = 3, $contentDir = null, $additionalPrompt = null) {
        // Clamp number of questions to 2-5
        $numQuestions = max(2, min(5, $numQuestions));

        // Check if this is infographic content (has div with class "infographic")
        $infographicImage = null;
        if (preg_match('/<div[^>]*class="[^"]*infographic[^"]*"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>/is', $htmlContent, $matches)) {
            $imageSrc = $matches[1];
            error_log("Detected infographic content with image: {$imageSrc}");

            // Try to load the image
            $infographicImage = $this->loadImageForVision($imageSrc, $contentDir);
        }

        // Extract text content for analysis (strip HTML tags but keep structure hints)
        $textContent = strip_tags($htmlContent);
        // Limit content size to avoid token limits
        $textContent = substr($textContent, 0, 50000);

        $systemPrompt = <<<PROMPT
You are an expert instructional designer creating assessment questions for educational content. Follow these principles:

IMPORTANT NOTE:
Try to match the theme of the content!

ASSESSMENT PHILOSOPHY:
1. Learning Objectives First: Every question should directly measure a specific learning objective or competency.
2. Bloom's Taxonomy: Include a mix of cognitive levels—knowledge, comprehension, application, and analysis.
3. Questions as Learning Tools: Design questions so that answering them deepens understanding, not just checks recall.

QUESTION TYPES:
1. True/False Questions:
   - Best for basic understanding and recognition of key concepts
   - Use statements that require reasoning, not just recall
   - Avoid trick questions or double negatives
   - Include cause-effect or conditional logic when possible

2. Multiple Choice Questions:
   - Ideal for deeper understanding, application, and decision-making
   - Include ONE correct answer and plausible distractors based on common misconceptions
   - Avoid "all of the above" or "none of the above"
   - Keep options parallel in structure and length
   - Present scenarios or problems requiring application of knowledge

QUALITY STANDARDS:
- Clarity and Precision: Simple wording, free of unnecessary complexity
- Bias-Free: Avoid cultural, gender, or language bias
- Balanced Difficulty: Mix of easy, moderate, and challenging
- Paraphrased: Do not copy text verbatim from the content
- Logic-Based: Require inference, application, or comparison

OUTPUT FORMAT:
Return ONLY a valid JSON object with this exact structure:
{
  "questions": [
    {
      "type": "true_false",
      "question": "Statement to evaluate",
      "correct_answer": true,
      "explanation": "Brief explanation of why this is true/false"
    },
    {
      "type": "multiple_choice",
      "question": "Question text here?",
      "options": ["Option A", "Option B", "Option C", "Option D"],
      "correct_index": 0,
      "explanation": "Brief explanation of the correct answer"
    }
  ]
}

IMPORTANT:
- Generate exactly {$numQuestions} questions
- Mix True/False and Multiple Choice questions
- Each question must be directly relevant to the content provided
- Make distractors plausible but clearly incorrect to someone who understood the material
- Return ONLY the JSON object, no other text
PROMPT;

        // Append additional prompt instructions if provided
        if ($additionalPrompt) {
            $systemPrompt .= "\n\nADDITIONAL INSTRUCTIONS:\n" . $additionalPrompt;
        }

        // Build message content - include image if this is an infographic
        if ($infographicImage) {
            $messageContent = [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $infographicImage['media_type'],
                        'data' => $infographicImage['data']
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => "Generate {$numQuestions} assessment questions based on this infographic image and any accompanying text. The questions should test understanding of the visual content. Return ONLY valid JSON.\n\nAccompanying text:\n" . $textContent
                ]
            ];
            error_log("Sending infographic image to Claude for quiz generation");
        } else {
            $messageContent = "Generate {$numQuestions} assessment questions for the following educational content. Return ONLY valid JSON:\n\n" . $textContent;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $messageContent
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Extract JSON from response
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $response, $jsonMatch)) {
            $questionsData = json_decode($jsonMatch[0], true);
        } else {
            throw new Exception("Failed to parse quiz questions from AI response");
        }

        if (!isset($questionsData['questions']) || !is_array($questionsData['questions'])) {
            throw new Exception("Invalid quiz questions format from AI");
        }

        // Generate HTML quiz section with self-scoring capability
        $quizHtml = $this->buildQuizHTML($questionsData['questions']);

        return [
            'questions' => $questionsData['questions'],
            'quiz_html' => $quizHtml,
            'num_questions' => count($questionsData['questions'])
        ];
    }

    /**
     * Build HTML for quiz section with self-scoring JavaScript
     */
    private function buildQuizHTML($questions) {
        $html = <<<HTML
<!-- OCMS Auto-Generated Quiz Section -->
<div id="ocms-quiz-section" style="margin-top: 40px; padding: 30px; background: #f8f9fa; border-radius: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <h2 style="color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #3498db;">Knowledge Check</h2>
    <form id="ocms-quiz-form" onsubmit="return ocmsScoreQuiz(event);">
HTML;

        foreach ($questions as $index => $q) {
            $qNum = $index + 1;
            $questionText = htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8');

            if ($q['type'] === 'true_false') {
                $correctValue = $q['correct_answer'] ? 'true' : 'false';
                $explanation = htmlspecialchars($q['explanation'] ?? '', ENT_QUOTES, 'UTF-8');

                $html .= <<<HTML
        <div class="ocms-question" data-question="{$qNum}" data-correct="{$correctValue}" data-explanation="{$explanation}" style="margin-bottom: 25px; padding: 20px; background: white; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <p style="font-weight: 600; color: #2c3e50; margin-bottom: 15px;"><span style="color: #3498db;">Q{$qNum}.</span> True or False: {$questionText}</p>
            <div style="display: flex; gap: 20px;">
                <label style="display: flex; align-items: center; cursor: pointer; padding: 10px 20px; border: 2px solid #e0e0e0; border-radius: 4px; transition: all 0.2s;">
                    <input type="radio" name="q{$qNum}" value="true" required style="margin-right: 8px;"> True
                </label>
                <label style="display: flex; align-items: center; cursor: pointer; padding: 10px 20px; border: 2px solid #e0e0e0; border-radius: 4px; transition: all 0.2s;">
                    <input type="radio" name="q{$qNum}" value="false" required style="margin-right: 8px;"> False
                </label>
            </div>
            <div class="ocms-feedback" style="display: none; margin-top: 15px; padding: 12px; border-radius: 4px;"></div>
        </div>
HTML;
            } else {
                // Multiple choice
                $correctIndex = (int)$q['correct_index'];
                $explanation = htmlspecialchars($q['explanation'] ?? '', ENT_QUOTES, 'UTF-8');

                $html .= <<<HTML
        <div class="ocms-question" data-question="{$qNum}" data-correct="{$correctIndex}" data-explanation="{$explanation}" style="margin-bottom: 25px; padding: 20px; background: white; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <p style="font-weight: 600; color: #2c3e50; margin-bottom: 15px;"><span style="color: #3498db;">Q{$qNum}.</span> {$questionText}</p>
            <div style="display: flex; flex-direction: column; gap: 10px;">
HTML;

                foreach ($q['options'] as $optIndex => $option) {
                    $optionText = htmlspecialchars($option, ENT_QUOTES, 'UTF-8');
                    $optionLetter = chr(65 + $optIndex); // A, B, C, D
                    $html .= <<<HTML
                <label style="display: flex; align-items: center; cursor: pointer; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 4px; transition: all 0.2s;">
                    <input type="radio" name="q{$qNum}" value="{$optIndex}" required style="margin-right: 10px;">
                    <span style="font-weight: 500; color: #7f8c8d; margin-right: 8px;">{$optionLetter}.</span> {$optionText}
                </label>
HTML;
                }

                $html .= <<<HTML
            </div>
            <div class="ocms-feedback" style="display: none; margin-top: 15px; padding: 12px; border-radius: 4px;"></div>
        </div>
HTML;
            }
        }

        $totalQuestions = count($questions);

        $html .= <<<HTML
        <div style="text-align: center; margin-top: 30px;">
            <button type="submit" id="ocms-submit-quiz" style="background: #3498db; color: white; padding: 15px 40px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.3s;">
                Submit Quiz
            </button>
        </div>
    </form>

    <div id="ocms-quiz-results" style="display: none; margin-top: 30px; padding: 25px; background: white; border-radius: 6px; text-align: center;">
        <h3 style="color: #2c3e50; margin-bottom: 15px;">Quiz Results</h3>
        <p style="font-size: 24px; font-weight: 700; margin-bottom: 10px;">
            Score: <span id="ocms-score-display">0</span>%
        </p>
        <p style="color: #7f8c8d;">
            You answered <span id="ocms-correct-count">0</span> out of {$totalQuestions} questions correctly.
        </p>
    </div>
</div>

<script src="/ocms-service/js/ocms-quiz.js"></script>
<!-- End OCMS Auto-Generated Quiz Section -->
HTML;

        return $html;
    }

    /**
     * Generate interaction tracking script for injecting into content
     *
     * @param string $trackingLinkId The tracking link ID (used for local PHP content)
     * @param string $basePath The base path for API calls and script loading
     * @param bool $useUrlParsing If true, use external script that reads tracking ID from URL (for S3 static HTML)
     */
    public function generateTrackingScript($trackingLinkId, $basePath = '', $useUrlParsing = false) {
        // Build API base URL
        $apiBase = $basePath . '/api';
        $scriptUrl = $basePath . '/js/ocms-tracker.js';

        if ($useUrlParsing) {
            // For S3 static HTML: use external tracking script
            // The script reads tid and nextUrl from URL query parameters
            return <<<JAVASCRIPT
<script src="{$scriptUrl}" data-api-base="{$apiBase}"></script>
JAVASCRIPT;
        }

        // For local PHP content: use external script with tracking ID passed via meta tag
        // This allows the script to be updated without re-processing content
        return <<<JAVASCRIPT
<meta name="ocms-tracking-id" content="<?php echo htmlspecialchars(\$trackingLinkId, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="ocms-api-base" content="{$apiBase}">
<script src="{$scriptUrl}"></script>
JAVASCRIPT;
    }

    /**
     * Translate HTML content into a target language while preserving structure
     *
     * @param string $htmlContent The HTML content to translate
     * @param string $targetLang Target language code (ISO 639-1, e.g. 'es', 'fr', 'ar')
     * @param string $sourceLang Source language code (default: 'en')
     * @return array ['html' => translated HTML, 'source_language' => string, 'target_language' => string]
     */
    public function translateContent($htmlContent, $targetLang, $sourceLang = 'en') {
        // Content size check
        $contentSize = strlen($htmlContent);
        if ($contentSize > $this->maxContentSize) {
            throw new Exception("Content size ({$contentSize} bytes) exceeds maximum for translation");
        }

        // Language names for the prompt
        $languageNames = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'pt' => 'Portuguese', 'pt-br' => 'Portuguese (Brazil)', 'ar' => 'Arabic',
            'ja' => 'Japanese', 'ko' => 'Korean', 'it' => 'Italian', 'nl' => 'Dutch',
            'zh' => 'Chinese (Simplified)', 'zh-tw' => 'Chinese (Traditional)',
            'ru' => 'Russian', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
            'fi' => 'Finnish', 'nb' => 'Norwegian', 'tr' => 'Turkish', 'he' => 'Hebrew',
            'th' => 'Thai', 'vi' => 'Vietnamese', 'hi' => 'Hindi',
        ];
        $targetName = $languageNames[$targetLang] ?? $targetLang;
        $sourceName = $languageNames[$sourceLang] ?? $sourceLang;

        // Protect sensitive blocks and file references
        $protected = $this->protectSensitiveBlocks($htmlContent);
        $tokenized = $this->tokenizeReferences($protected['html']);

        $isRtl = in_array($targetLang, ['ar', 'he']);

        $systemPrompt = "You are an expert translator specializing in web content localization. " .
            "Translate the following HTML content from {$sourceName} to {$targetName}.\n\n" .
            "CRITICAL RULES:\n" .
            "1. Translate ONLY human-readable text content and alt attributes\n" .
            "2. Preserve ALL HTML tags, attributes, and structure exactly as-is\n" .
            "3. Preserve ALL placeholder tokens (e.g. __ASSET_REF_XXXX__, __PROTECTED_BLOCK_XXXX__)\n" .
            "4. Preserve ALL data-cue, data-tag, data-basename, and other data-* attributes unchanged\n" .
            "5. Preserve ALL inline styles, classes, and IDs unchanged\n" .
            "6. Preserve ALL placeholder text like RECIPIENT_EMAIL_ADDRESS, CURRENT_YEAR, FROM_EMAIL_ADDRESS, etc.\n" .
            "7. Maintain the same tone and formality level as the original\n" .
            "8. Do NOT add explanations, comments, or markdown formatting\n" .
            "9. Return ONLY the translated HTML\n" .
            ($isRtl ? "10. Add dir=\"rtl\" to the root <html> or outermost <div> element for right-to-left display\n" : "");

        $messages = [
            [
                'role' => 'user',
                'content' => "Translate this HTML from {$sourceName} to {$targetName}. Return ONLY the translated HTML:\n\n" . $tokenized['html']
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Clean response
        $translatedHtml = $this->stripMarkdownCodeBlocks($response);
        $translatedHtml = $this->extractHTMLOnly($translatedHtml);

        // Restore protected content
        $translatedHtml = $this->restoreReferences($translatedHtml, $tokenized['referenceMap']);
        $translatedHtml = $this->restoreProtectedBlocks($translatedHtml, $protected['protectedBlocks']);

        return [
            'html' => $translatedHtml,
            'source_language' => $sourceLang,
            'target_language' => $targetLang,
        ];
    }
}
