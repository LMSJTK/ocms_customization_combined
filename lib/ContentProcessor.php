<?php
/**
 * Content Processor Class
 * Handles post-upload processing of content files
 */

class ContentProcessor {
    private $db;
    private $claudeAPI;
    private $contentDir;
    private $basePath;
    private $s3Client;
    private $useS3;

    public function __construct($db, $claudeAPI, $contentDir, $basePath = '', $s3Client = null) {
        $this->db = $db;
        $this->claudeAPI = $claudeAPI;
        $this->contentDir = $contentDir;
        $this->basePath = $basePath;
        $this->s3Client = $s3Client;
        $this->useS3 = $s3Client && $s3Client->isEnabled();
    }

    /**
     * Check if S3 storage is being used
     */
    public function isUsingS3() {
        return $this->useS3;
    }

    /**
     * Process landing page placeholders during import
     *
     * Placeholder format: <span class="placeholder" data-basename="XXX">...</span>
     *
     * Rules (same as education content):
     * - IGNORE (leave as-is for launch.php to handle): CURRENT_YEAR, current_year,
     *   RECIPIENT_EMAIL_ADDRESS, recipient_email_address, RECIPIENT_EMAIL_DOMAIN,
     *   FROM_EMAIL_ADDRESS, from_full_email_address, FROM_FRIENDLY_NAME,
     *   SCENARIO_START_DATETIME
     * - STRIP (remove span and surrounding element): COMPANY_NAME, company_name
     * - REJECT (return error): NAME, RECIPIENT_NAME, and any other unlisted placeholders
     *
     * @param string $html The landing page HTML content
     * @return array ['success' => bool, 'html' => string, 'rejected' => array, 'processed' => array]
     */
    public function processLandingPlaceholders($html) {
        // Define placeholder categories
        $ignoreList = [
            'CURRENT_YEAR',
            'current_year',
            'RECIPIENT_EMAIL_ADDRESS',
            'recipient_email_address',
            'RECIPIENT_EMAIL_DOMAIN',
            'FROM_EMAIL_ADDRESS',
            'from_full_email_address',
            'FROM_FRIENDLY_NAME',
            'SCENARIO_START_DATETIME'
        ];

        $stripWithElementList = [
            'COMPANY_NAME',
            'company_name'
        ];

        $rejectList = [
            'NAME',
            'RECIPIENT_NAME'
        ];

        // Track what we found
        $rejected = [];
        $processed = [
            'ignored' => [],
            'stripped_with_element' => [],
            'rejected_unknown' => []
        ];

        // Pattern to match placeholder spans
        $pattern = '/<span[^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*data-basename=["\']([^"\']+)["\'][^>]*>.*?<\/span>/is';
        $pattern2 = '/<span[^>]*data-basename=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*>.*?<\/span>/is';

        // First pass: check for rejected placeholders and unknown placeholders
        $allMatches = [];
        preg_match_all($pattern, $html, $matches1, PREG_SET_ORDER);
        preg_match_all($pattern2, $html, $matches2, PREG_SET_ORDER);
        $allMatches = array_merge($matches1, $matches2);

        foreach ($allMatches as $match) {
            $basename = trim($match[1]); // Keep original case for matching

            // Check explicit reject list (case-insensitive)
            $basenameUpper = strtoupper($basename);
            if (in_array($basenameUpper, array_map('strtoupper', $rejectList)) && !in_array($basename, $rejected)) {
                $rejected[] = $basename;
            }
            // Check if it's not in any of our known lists (unknown placeholder = reject)
            elseif (!in_array($basename, $ignoreList) &&
                     !in_array($basename, $stripWithElementList) &&
                     !in_array($basenameUpper, array_map('strtoupper', $rejectList))) {
                // Unknown placeholder - reject it
                if (!in_array($basename, $rejected)) {
                    $rejected[] = $basename;
                    $processed['rejected_unknown'][] = $basename;
                }
            }
        }

        // If any rejected placeholders found, return error
        if (!empty($rejected)) {
            return [
                'success' => false,
                'html' => $html,
                'rejected' => $rejected,
                'processed' => $processed,
                'error' => 'Landing page contains unsupported placeholders: ' . implode(', ', $rejected)
            ];
        }

        // Second pass: process the placeholders
        $processedHtml = $html;

        // Process COMPANY_NAME/company_name - strip with surrounding element
        foreach ($stripWithElementList as $placeholderName) {
            // Match the placeholder and try to find its parent element
            // Pattern: matches an element that contains the placeholder span
            $elementPattern = '/<([a-z][a-z0-9]*)\b[^>]*>.*?<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>.*?<\/\1>/is';
            $processedHtml = preg_replace($elementPattern, '', $processedHtml);

            // Also handle if the placeholder is in attributes (less common)
            // Just remove the placeholder span itself if not in a parent element
            $standalonePattern = '/<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>/is';
            $processedHtml = preg_replace($standalonePattern, '', $processedHtml);

            if (!in_array($placeholderName, $processed['stripped_with_element'])) {
                $processed['stripped_with_element'][] = $placeholderName;
            }
        }

        // Placeholders in ignore list are left as-is (no processing needed)
        foreach ($allMatches as $match) {
            $basename = trim($match[1]);
            if (in_array($basename, $ignoreList) && !in_array($basename, $processed['ignored'])) {
                $processed['ignored'][] = $basename;
            }
        }

        return [
            'success' => true,
            'html' => $processedHtml,
            'rejected' => $rejected,
            'processed' => $processed
        ];
    }

    /**
     * Process education placeholders during import
     *
     * Placeholder format: <span class="placeholder" data-basename="XXX">...</span>
     *
     * Rules:
     * - IGNORE (leave as-is for launch.php to handle): CURRENT_YEAR, RECIPIENT_EMAIL_ADDRESS,
     *   RECIPIENT_EMAIL_DOMAIN, FROM_EMAIL_ADDRESS, FROM_FRIENDLY_NAME
     * - STRIP (remove span and surrounding element): COMPANY_NAME, company_name
     * - REJECT (return error): NAME, RECIPIENT_NAME, and any other unlisted placeholders
     *
     * @param string $html The education HTML content
     * @return array ['success' => bool, 'html' => string, 'rejected' => array, 'processed' => array]
     */
    public function processEducationPlaceholders($html) {
        // Define placeholder categories
        $ignoreList = [
            'CURRENT_YEAR',
            'RECIPIENT_EMAIL_ADDRESS',
            'RECIPIENT_EMAIL_DOMAIN',
            'FROM_EMAIL_ADDRESS',
            'FROM_FRIENDLY_NAME'
        ];

        $stripWithElementList = [
            'COMPANY_NAME',
            'company_name'
        ];

        $rejectList = [
            'NAME',
            'RECIPIENT_NAME'
        ];

        // Track what we found
        $rejected = [];
        $processed = [
            'ignored' => [],
            'stripped_with_element' => [],
            'rejected_unknown' => []
        ];

        // Pattern to match placeholder spans
        $pattern = '/<span[^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*data-basename=["\']([^"\']+)["\'][^>]*>.*?<\/span>/is';
        $pattern2 = '/<span[^>]*data-basename=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*>.*?<\/span>/is';

        // First pass: check for rejected placeholders and unknown placeholders
        $allMatches = [];
        preg_match_all($pattern, $html, $matches1, PREG_SET_ORDER);
        preg_match_all($pattern2, $html, $matches2, PREG_SET_ORDER);
        $allMatches = array_merge($matches1, $matches2);

        foreach ($allMatches as $match) {
            $basename = trim($match[1]); // Keep case-sensitive for matching
            $basenameUpper = strtoupper($basename);

            // Check explicit reject list
            if (in_array($basenameUpper, $rejectList) && !in_array($basename, $rejected)) {
                $rejected[] = $basename;
            }
            // Check if it's not in any of our known lists (unknown placeholder = reject)
            elseif (!in_array($basenameUpper, $ignoreList) &&
                     !in_array($basename, $stripWithElementList) &&
                     !in_array($basenameUpper, $rejectList)) {
                // Unknown placeholder - reject it
                if (!in_array($basename, $rejected)) {
                    $rejected[] = $basename;
                    $processed['rejected_unknown'][] = $basename;
                }
            }
        }

        // If any rejected placeholders found, return error
        if (!empty($rejected)) {
            return [
                'success' => false,
                'html' => $html,
                'rejected' => $rejected,
                'processed' => $processed,
                'error' => 'Education content contains unsupported placeholders: ' . implode(', ', $rejected)
            ];
        }

        // Second pass: process the placeholders
        $processedHtml = $html;

        // Process COMPANY_NAME/company_name - strip with surrounding element
        foreach ($stripWithElementList as $placeholderName) {
            // Match the placeholder and try to find its parent element
            // Pattern: matches an element that contains the placeholder span
            $elementPattern = '/<([a-z][a-z0-9]*)\b[^>]*>.*?<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>.*?<\/\1>/is';
            $processedHtml = preg_replace($elementPattern, '', $processedHtml);

            // Also handle if the placeholder is in attributes (less common)
            // Just remove the placeholder span itself if not in a parent element
            $standalonePattern = '/<span[^>]*data-basename=["\']' . preg_quote($placeholderName, '/') . '["\'][^>]*>.*?<\/span>/is';
            $processedHtml = preg_replace($standalonePattern, '', $processedHtml);

            if (!in_array($placeholderName, $processed['stripped_with_element'])) {
                $processed['stripped_with_element'][] = $placeholderName;
            }
        }

        // Placeholders in ignore list are left as-is (no processing needed)
        foreach ($allMatches as $match) {
            $basename = trim($match[1]);
            $basenameUpper = strtoupper($basename);
            if (in_array($basenameUpper, $ignoreList) && !in_array($basenameUpper, $processed['ignored'])) {
                $processed['ignored'][] = $basenameUpper;
            }
        }

        return [
            'success' => true,
            'html' => $processedHtml,
            'rejected' => $rejected,
            'processed' => $processed
        ];
    }

    /**
     * Process email placeholders during import
     *
     * Placeholder format: <span class="placeholder" data-basename="XXX">...</span>
     *
     * Rules:
     * - IGNORE (leave as-is): RECIPIENT_EMAIL_ADDRESS, FROM_EMAIL_ADDRESS, SCENARIO_START_DATETIME, CURRENT_YEAR
     * - REPLACE: FROM_FRIENDLY_NAME (replaced with fromName parameter)
     * - REJECT (return error): FIRST_NAME, LAST_NAME, NAME, PROGRAM_CONTACT_NAME
     * - STRIP (remove span): COMPANY_NAME and any other placeholders
     *
     * @param string $html The email HTML content
     * @param string|null $fromName The from_name value from PM table for FROM_FRIENDLY_NAME replacement
     * @return array ['success' => bool, 'html' => string, 'rejected' => array, 'processed' => array]
     */
    public function processEmailPlaceholders($html, $fromName = null) {
        // Define placeholder categories
        // These are left as-is for the email generation side to handle
        $ignoreList = [
            'RECIPIENT_EMAIL_ADDRESS',
            'FROM_EMAIL_ADDRESS',
            'SCENARIO_START_DATETIME',
            'CURRENT_YEAR'
        ];

        $replaceMap = [
            'FROM_FRIENDLY_NAME' => $fromName
        ];

        $rejectList = [
            'FIRST_NAME',
            'LAST_NAME',
            'NAME',
            'PROGRAM_CONTACT_NAME'
        ];

        // Track what we found
        $rejected = [];
        $processed = [
            'ignored' => [],
            'replaced' => [],
            'stripped' => []
        ];

        // Pattern to match placeholder spans
        // Matches: <span class="placeholder" data-basename="XXX">content</span>
        // Also handles: <span data-basename="XXX" class="placeholder">content</span>
        $pattern = '/<span[^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*data-basename=["\']([^"\']+)["\'][^>]*>.*?<\/span>/is';
        $pattern2 = '/<span[^>]*data-basename=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*placeholder[^"\']*["\'][^>]*>.*?<\/span>/is';

        // First pass: check for rejected placeholders
        $allMatches = [];
        preg_match_all($pattern, $html, $matches1, PREG_SET_ORDER);
        preg_match_all($pattern2, $html, $matches2, PREG_SET_ORDER);
        $allMatches = array_merge($matches1, $matches2);

        foreach ($allMatches as $match) {
            $basename = strtoupper(trim($match[1]));
            if (in_array($basename, $rejectList) && !in_array($basename, $rejected)) {
                $rejected[] = $basename;
            }
        }

        // If any rejected placeholders found, return error
        if (!empty($rejected)) {
            return [
                'success' => false,
                'html' => $html,
                'rejected' => $rejected,
                'processed' => $processed,
                'error' => 'Email contains unsupported placeholders that require recipient data: ' . implode(', ', $rejected)
            ];
        }

        // Second pass: process the placeholders
        $processedHtml = preg_replace_callback(
            $pattern,
            function($match) use ($ignoreList, $replaceMap, &$processed) {
                return $this->processPlaceholderMatch($match, $ignoreList, $replaceMap, $processed);
            },
            $html
        );

        $processedHtml = preg_replace_callback(
            $pattern2,
            function($match) use ($ignoreList, $replaceMap, &$processed) {
                return $this->processPlaceholderMatch($match, $ignoreList, $replaceMap, $processed);
            },
            $processedHtml
        );

        return [
            'success' => true,
            'html' => $processedHtml,
            'rejected' => $rejected,
            'processed' => $processed
        ];
    }

    /**
     * Helper function to process a single placeholder match
     */
    private function processPlaceholderMatch($match, $ignoreList, $replaceMap, &$processed) {
        $fullMatch = $match[0];
        $basename = strtoupper(trim($match[1]));

        // Ignore - leave as-is
        if (in_array($basename, $ignoreList)) {
            if (!in_array($basename, $processed['ignored'])) {
                $processed['ignored'][] = $basename;
            }
            return $fullMatch;
        }

        // Replace - substitute with value from replaceMap
        if (isset($replaceMap[$basename])) {
            $replacement = $replaceMap[$basename];
            if ($replacement !== null && $replacement !== '') {
                if (!in_array($basename, $processed['replaced'])) {
                    $processed['replaced'][] = $basename;
                }
                return htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8');
            }
        }

        // Strip - remove the span entirely (including any content inside)
        if (!in_array($basename, $processed['stripped'])) {
            $processed['stripped'][] = $basename;
        }
        return '';
    }

    /**
     * Check if a URL is relative (needs to be converted to absolute)
     */
    private function isRelativeUrl($url) {
        $url = trim($url);
        // Skip empty URLs
        if (empty($url)) return false;
        // Skip template tags (e.g., {{{ link }}} placeholders)
        if (strpos($url, '{') === 0) return false;
        if (stripos($url, '%7B') === 0) return false; // URL-encoded {
        // Skip URLs containing template placeholders anywhere (e.g., {{{ domain_for_template('x') }}})
        if (strpos($url, '{{{') !== false || strpos($url, '}}}') !== false) return false;
        // Skip absolute URLs (http://, https://)
        if (preg_match('/^https?:\/\//i', $url)) return false;
        // Skip protocol-relative URLs (//)
        if (strpos($url, '//') === 0) return false;
        // Skip data URIs
        if (stripos($url, 'data:') === 0) return false;
        // Skip anchor links
        if (strpos($url, '#') === 0) return false;
        // Skip root-relative URLs (starting with /)
        if (strpos($url, '/') === 0) return false;
        // Skip javascript: URLs
        if (stripos($url, 'javascript:') === 0) return false;
        // Skip mailto: URLs
        if (stripos($url, 'mailto:') === 0) return false;
        // Skip tel: URLs
        if (stripos($url, 'tel:') === 0) return false;
        // This is a relative URL
        return true;
    }

    /**
     * Convert relative URLs in HTML to absolute URLs
     * This is needed for email previews where there's no <base> tag context
     *
     * @param string $html The HTML content
     * @param string $baseUrl The base URL to prepend to relative URLs
     * @return string The HTML with absolute URLs
     */
    public function convertRelativeUrlsToAbsolute($html, $baseUrl) {
        // Ensure base URL ends with /
        $baseUrl = rtrim($baseUrl, '/') . '/';

        $self = $this;

        // Process src attributes (double-quoted)
        $html = preg_replace_callback(
            '/(<[^>]+\ssrc\s*=\s*")([^"]*)(")/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];
                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process src attributes (single-quoted)
        $html = preg_replace_callback(
            '/(<[^>]+\ssrc\s*=\s*\')([^\']*)(\')/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];
                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process href attributes (double-quoted)
        // Only for asset tags like <link>, NOT for <a> anchor tags
        $html = preg_replace_callback(
            '/(<[^>]+\shref\s*=\s*")([^"]*)(")/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];

                // Skip <a> tags - only process assets like <link rel="stylesheet">
                if (preg_match('/^<\s*a\b/i', trim($prefix))) {
                    return $matches[0];
                }

                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process href attributes (single-quoted)
        // Only for asset tags like <link>, NOT for <a> anchor tags
        $html = preg_replace_callback(
            '/(<[^>]+\shref\s*=\s*\')([^\']*)(\')/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];

                // Skip <a> tags - only process assets like <link rel="stylesheet">
                if (preg_match('/^<\s*a\b/i', trim($prefix))) {
                    return $matches[0];
                }

                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process background attributes (double-quoted)
        $html = preg_replace_callback(
            '/(<[^>]+\sbackground\s*=\s*")([^"]*)(")/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];
                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process background attributes (single-quoted)
        $html = preg_replace_callback(
            '/(<[^>]+\sbackground\s*=\s*\')([^\']*)(\')/i',
            function($matches) use ($baseUrl, $self) {
                $prefix = $matches[1];
                $url = $matches[2];
                $suffix = $matches[3];
                if ($self->isRelativeUrl($url)) {
                    return $prefix . $baseUrl . $url . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // Process url() in inline styles (double-quoted)
        $html = preg_replace_callback(
            '/url\(\s*"([^"]*)"\s*\)/i',
            function($matches) use ($baseUrl, $self) {
                $url = $matches[1];
                if ($self->isRelativeUrl($url)) {
                    return 'url("' . $baseUrl . $url . '")';
                }
                return $matches[0];
            },
            $html
        );

        // Process url() in inline styles (single-quoted)
        $html = preg_replace_callback(
            '/url\(\s*\'([^\']*)\'\s*\)/i',
            function($matches) use ($baseUrl, $self) {
                $url = $matches[1];
                if ($self->isRelativeUrl($url)) {
                    return "url('" . $baseUrl . $url . "')";
                }
                return $matches[0];
            },
            $html
        );

        // Process url() in inline styles (unquoted)
        $html = preg_replace_callback(
            '/url\(\s*([^"\'\)]+)\s*\)/i',
            function($matches) use ($baseUrl, $self) {
                $url = trim($matches[1]);
                if ($self->isRelativeUrl($url)) {
                    return 'url(' . $baseUrl . $url . ')';
                }
                return $matches[0];
            },
            $html
        );

        return $html;
    }

    /**
     * Detect if content is scorable by scanning for RecordTest calls
     * Optimized for large/minified files to avoid PCRE backtrack limits
     */
    public function detectScorableContent($directory) {
        $scorable = false;
        $extensions = ['js', 'html', 'htm', 'php'];

        // Define patterns to check against specific occurrences
        // Note: These check small substrings, not the whole file
        $callPattern = '/RecordTest\s*\(/i';

        $excludePatterns = [
            '/window\.RecordTest\s*=/i',           // Our tracker definition
            '/function\s+RecordTest\s*\(/i',       // Function definition
            '/typeof\s+RecordTest/i',              // Type check
        ];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!in_array(strtolower($file->getExtension()), $extensions)) continue;

                $content = file_get_contents($file->getPathname());
                if ($content === false) continue;

                // OPTIMIZATION: Quick check using strpos before doing anything heavy
                // If "RecordTest" isn't in the file at all, skip it immediately.
                if (stripos($content, 'RecordTest') === false) {
                    continue;
                }

                // Scan through all occurrences of "RecordTest"
                $offset = 0;
                while (($pos = stripos($content, 'RecordTest', $offset)) !== false) {
                    // Extract a context window around the match (e.g., 50 chars before and after)
                    // This creates a tiny string that Regex handles easily without crashing
                    $start = max(0, $pos - 50);
                    $length = 100 + strlen('RecordTest'); // 50 before + keyword + 50 after
                    $contextSnippet = substr($content, $start, $length);

                    // 1. Check if this specific occurrence is a Definition/Exclusion
                    $isExcluded = false;
                    foreach ($excludePatterns as $pattern) {
                        if (preg_match($pattern, $contextSnippet)) {
                            $isExcluded = true;
                            break;
                        }
                    }

                    if ($isExcluded) {
                        // Move offset past this occurrence and continue looking
                        $offset = $pos + 1;
                        continue;
                    }

                    // 2. If not excluded, check if it is a Call
                    if (preg_match($callPattern, $contextSnippet)) {
                        error_log("Found RecordTest call in: " . $file->getPathname());
                        $scorable = true;
                        break 2; // Break both the while loop and the foreach loop
                    }

                    // Move offset to continue search
                    $offset = $pos + 1;
                }
            }
        } catch (Exception $e) {
            error_log("Error scanning for RecordTest: " . $e->getMessage());
        }

        return $scorable;
    }

    /**
     * Normalize RecordTest calls by removing parent. prefixes
     * SCORM content often uses parent.RecordTest() to communicate with parent frame,
     * but we inject RecordTest directly into the content window, so we rewrite these calls
     * Optimized for large/minified files using str_replace instead of regex
     */
    public function normalizeRecordTestCalls($directory) {
        $extensions = ['js', 'html', 'htm'];
        $filesModified = 0;

        // Use simple string replacements - safe for large files, no PCRE limits
        // Order matters: replace longer patterns first to avoid partial matches
        $replacements = [
            'window.parent.RecordTest(' => 'RecordTest(',
            'Window.parent.RecordTest(' => 'RecordTest(',
            'parent.RecordTest(' => 'RecordTest(',
            'Parent.RecordTest(' => 'RecordTest(',
        ];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!in_array(strtolower($file->getExtension()), $extensions)) continue;

                $content = file_get_contents($file->getPathname());
                if ($content === false) continue;

                // Quick check: skip files that don't contain RecordTest at all
                if (stripos($content, 'RecordTest') === false) continue;

                // Apply replacements
                $modified = $content;
                foreach ($replacements as $search => $replace) {
                    $modified = str_replace($search, $replace, $modified);
                }

                // Only write if content changed
                if ($modified !== $content) {
                    file_put_contents($file->getPathname(), $modified);
                    $filesModified++;
                    error_log("Normalized RecordTest calls in: " . $file->getPathname());
                }
            }
        } catch (Exception $e) {
            error_log("Error normalizing RecordTest calls: " . $e->getMessage());
        }

        return $filesModified;
    }

    /**
     * Process uploaded content based on type
     */
    public function processContent($contentId, $contentType, $filePath) {
        switch ($contentType) {
            case 'scorm':
            case 'html':
                return $this->processZipContent($contentId, $contentType, $filePath);

            case 'email':
            case 'direct':
                return $this->processEmailContent($contentId, $filePath);

            case 'training':
            case 'landing':
                return $this->processRawHTML($contentId, $filePath);

            case 'video':
                // Videos don't need processing, just store path
                return ['success' => true, 'message' => 'Video uploaded successfully'];

            default:
                throw new Exception("Unsupported content type: {$contentType}");
        }
    }

    /**
     * Process ZIP content (SCORM or HTML)
     */
    private function processZipContent($contentId, $contentType, $zipPath) {
        $extractPath = $this->contentDir . $contentId . '/';

        // Create extraction directory
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        // Extract ZIP with Windows backslash path handling
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception("Failed to open ZIP file");
        }

        // Custom extraction to handle Windows-style backslash paths
        // Some ZIP files (especially from Windows) use backslashes which
        // PHP's extractTo() doesn't convert to directory separators
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $originalName = $zip->getNameIndex($i);

            // Convert backslashes to forward slashes
            $normalizedName = str_replace('\\', '/', $originalName);

            // Skip if no change needed and it's not a directory path issue
            $destPath = $extractPath . $normalizedName;

            // Create directory structure if needed
            $dirPath = dirname($destPath);
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755, true);
            }

            // Skip directories (they end with /)
            if (substr($normalizedName, -1) === '/') {
                continue;
            }

            // Extract file content and write to correct location
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($destPath, $content);
            }
        }

        $zip->close();

        // Delete the ZIP file after extraction
        unlink($zipPath);

        // Normalize RecordTest calls (convert parent.RecordTest to RecordTest)
        // This ensures scoring works regardless of iframe context
        $filesNormalized = $this->normalizeRecordTestCalls($extractPath);
        if ($filesNormalized > 0) {
            error_log("Normalized RecordTest calls in {$filesNormalized} file(s) for content {$contentId}");
        }

        // Detect if content is scorable (contains RecordTest calls)
        // SCORM content defaults to scorable=true since it may use standard SCORM API
        // instead of RecordTest() calls
        $scorable = $this->detectScorableContent($extractPath);
        if ($contentType === 'scorm') {
            $scorable = true;
            error_log("Content {$contentId} is SCORM - defaulting scorable to true");
        } else {
            error_log("Content {$contentId} scorable detection: " . ($scorable ? 'true' : 'false'));
        }

        // Find index.html
        $indexPath = $this->findIndexFile($extractPath);
        if (!$indexPath) {
            throw new Exception("index.html not found in ZIP");
        }

        // Read HTML content
        $htmlContent = file_get_contents($indexPath);
        $contentSize = strlen($htmlContent);

        error_log("Processing {$contentType} content, size: {$contentSize} bytes");

        // OPTIMIZATION: Use analyze-only approach instead of full HTML rewriting
        // This significantly reduces token usage by:
        // 1. Stripping scripts/styles before analysis
        // 2. Only returning a JSON array of tags (not rewritten HTML)
        // The original HTML is preserved (no data-tag attributes added)
        $tags = [];
        $modifiedHTML = $htmlContent; // Keep original HTML

        // Skip AI processing if content is too large (>500KB) to prevent timeout
        $maxSizeForAI = 500000; // 500KB

        if ($contentType === 'html' && $contentSize <= $maxSizeForAI) {
            try {
                // Use the optimized analyze-only method to get tags without rewriting HTML
                error_log("Analyzing HTML content for tags (no modification)...");
                $tags = $this->claudeAPI->analyzeHTMLContent($htmlContent);
                error_log("Found tags: " . implode(', ', $tags));
            } catch (Exception $e) {
                error_log("AI Analysis failed: " . $e->getMessage());
                // Continue without tags
            }
        } else {
            if ($contentType === 'html') {
                error_log("HTML content too large ({$contentSize} bytes), skipping AI analysis");
            }
            // For SCORM or extremely large HTML, extract minimal tags from title
            if (preg_match('/<title>(.*?)<\/title>/i', $htmlContent, $matches)) {
                $title = strtolower(trim($matches[1]));
                // Basic keyword detection
                $keywords = ['phishing', 'ransomware', 'malware', 'password', 'security', 'privacy', 'email'];
                foreach ($keywords as $keyword) {
                    if (stripos($title, $keyword) !== false) {
                        $tags[] = $keyword;
                    }
                }
            }
        }

        // Download external assets (login.phishme.com, CDN, etc.)
        $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);

        // Store the HTML after asset download (before base tag injection)
        // This will be used to create entry_body_html with absolute URLs
        $htmlForEntryBody = $modifiedHTML;

        // Calculate the relative path from extraction root to entry point
        // This handles subdirectory entry points like scormcontent/index.html
        $relativeEntryPath = str_replace($extractPath, '', $indexPath);

        if ($this->useS3) {
            // S3 Storage Mode: Keep as static HTML, use URL-based tracking
            error_log("S3 mode enabled - processing as static HTML");

            // Inject base tag pointing to S3 location
            $s3BaseUrl = $this->s3Client->getContentBaseUrl($contentId);
            $baseTag = "<base href=\"{$s3BaseUrl}\">";
            if (stripos($modifiedHTML, '<head>') !== false) {
                $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
            } elseif (stripos($modifiedHTML, '</head>') !== false) {
                $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
            }

            // Inject tracking script that reads tracking ID from URL parameter
            $trackingJS = $this->claudeAPI->generateTrackingScript('', $this->basePath, true);
            $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

            // Write modified HTML back to entry point file
            file_put_contents($indexPath, $modifiedHTML);

            // Upload entire directory to S3
            $uploadedFiles = $this->s3Client->uploadDirectory($contentId, $extractPath);
            error_log("S3: Uploaded " . count($uploadedFiles) . " files for content {$contentId}");

            // Get S3 URL for the content (use relative path including subdirectories)
            $s3Url = $this->s3Client->getContentUrl($contentId, $relativeEntryPath);

            // Store tags in database
            $this->storeTags($contentId, $tags);

            // Convert relative URLs to absolute in entry_body_html for DB storage
            $entryBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEntryBody, $s3BaseUrl);

            // Update content URL, scorable flag, and entry_body_html in database
            $this->db->update('content',
                ['content_url' => $s3Url, 'scorable' => $scorable, 'entry_body_html' => $entryBodyWithAbsoluteUrls],
                'id = :id',
                [':id' => $contentId]
            );

            // Clean up local files (optional - keep for backup/fallback)
            // $this->cleanupLocalDirectory($extractPath);

            return [
                'success' => true,
                'message' => 'Content processed and uploaded to S3 successfully',
                'tags' => $tags,
                'path' => $s3Url,
                's3' => true,
                'scorable' => $scorable
            ];
        }

        // Local Storage Mode: Convert to PHP for server-side tracking
        // Rename .html to .php (handles index.html, index_scorm.html, etc.)
        $phpPath = preg_replace('/\.html$/i', '.php', $indexPath);
        $relativePhpPath = preg_replace('/\.html$/i', '.php', $relativeEntryPath);
        rename($indexPath, $phpPath);
        error_log("Renamed entry point to: $phpPath (relative: $relativePhpPath)");

        // Add tracking script to the HTML
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script before </body>
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

        // Write modified content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store tags in database
        $this->storeTags($contentId, $tags);

        // Convert relative URLs to absolute in entry_body_html for DB storage
        $localBaseUrl = $this->basePath . '/content/' . $contentId;
        $entryBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEntryBody, $localBaseUrl);

        // Update content URL, scorable flag, and entry_body_html in database
        // Use the relative path including any subdirectories (e.g., scormcontent/index.php)
        $this->db->update('content',
            ['content_url' => $contentId . '/' . $relativePhpPath, 'scorable' => $scorable, 'entry_body_html' => $entryBodyWithAbsoluteUrls],
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'Content processed successfully',
            'tags' => $tags,
            'path' => $contentId . '/' . $relativePhpPath,
            'scorable' => $scorable
        ];
    }

    /**
     * Process email content with NIST Phish Scales
     */
    private function processEmailContent($contentId, $htmlPath) {
        $htmlContent = file_get_contents($htmlPath);

        // Load NIST guide if available
        $nistGuidePath = '/var/www/html/NIST_Phish_Scales_guide.pdf';
        $nistGuide = null;
        // Note: For full implementation, you'd extract text from PDF
        // For now, we'll use a summary prompt

        // OPTIMIZATION: Analyze email for phishing cues without modifying HTML
        // This prevents corruption of template placeholders like {{{trainingURL}}}
        // We only use the cues and difficulty from the AI response, not the modified HTML
        $cues = [];
        $difficulty = null;

        try {
            error_log("Analyzing email content for phishing cues (no HTML modification)...");
            $result = $this->claudeAPI->tagPhishingEmail($htmlContent, $nistGuide);
            $cues = $result['cues'] ?? [];
            $difficulty = $result['difficulty'] ?? null;
            error_log("Found phishing cues: " . implode(', ', $cues) . ", difficulty: " . ($difficulty ?? 'unknown'));
        } catch (Exception $e) {
            error_log("AI Analysis failed for email cues: " . $e->getMessage());
            // Continue without cues - email can still be imported
        }

        // Create directory for email content
        $extractPath = $this->contentDir . $contentId . '/';
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        // Use ORIGINAL HTML (not AI-modified) to preserve template placeholders
        $modifiedHTML = $htmlContent;
        $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);

        // Store the HTML with downloaded assets (before base tag injection)
        // This will be used to update email_body_html with absolute URLs
        $htmlForEmailBody = $modifiedHTML;

        if ($this->useS3) {
            // S3 Storage Mode
            error_log("S3 mode enabled - processing email as static HTML");

            $htmlPath = $extractPath . 'index.html';

            // Inject base tag pointing to S3 location
            $s3BaseUrl = $this->s3Client->getContentBaseUrl($contentId);
            $baseTag = "<base href=\"{$s3BaseUrl}\">";
            if (stripos($modifiedHTML, '<head>') !== false) {
                $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
            } elseif (stripos($modifiedHTML, '</head>') !== false) {
                $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
            }

            // Inject tracking script that reads tracking ID from URL parameter
            $trackingJS = $this->claudeAPI->generateTrackingScript('', $this->basePath, true);
            $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

            // Write HTML file
            file_put_contents($htmlPath, $modifiedHTML);

            // Upload directory to S3
            $uploadedFiles = $this->s3Client->uploadDirectory($contentId, $extractPath);
            error_log("S3: Uploaded " . count($uploadedFiles) . " files for email content {$contentId}");

            // Get S3 URL
            $s3Url = $this->s3Client->getContentUrl($contentId, 'index.html');

            // Store cues as tags
            $this->storeTags($contentId, $cues, 'phish-cue');

            // Update content URL and difficulty score
            $updateData = ['content_url' => $s3Url];
            if ($difficulty !== null) {
                $updateData['difficulty'] = (string)$difficulty;
            }

            // Convert relative URLs to absolute in email_body_html for external preview
            $emailBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEmailBody, $s3BaseUrl);
            $updateData['email_body_html'] = $emailBodyWithAbsoluteUrls;

            $this->db->update('content',
                $updateData,
                'id = :id',
                [':id' => $contentId]
            );

            return [
                'success' => true,
                'message' => 'Email content processed and uploaded to S3 successfully',
                'cues' => $cues,
                'difficulty' => $difficulty,
                'path' => $s3Url,
                's3' => true
            ];
        }

        // Local Storage Mode
        $phpPath = $extractPath . 'index.php';

        // Add tracking script
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

        // Write modified content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store cues as tags
        $this->storeTags($contentId, $cues, 'phish-cue');

        // Update content URL and difficulty score
        $updateData = ['content_url' => $contentId . '/index.php'];

        // Add difficulty score if provided
        if ($difficulty !== null) {
            $updateData['difficulty'] = (string)$difficulty;
        }

        // Convert relative URLs to absolute in email_body_html for external preview
        $localBaseUrl = $this->basePath . '/content/' . $contentId;
        $emailBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEmailBody, $localBaseUrl);
        $updateData['email_body_html'] = $emailBodyWithAbsoluteUrls;

        $this->db->update('content',
            $updateData,
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'Email content processed successfully',
            'cues' => $cues,
            'difficulty' => $difficulty,
            'path' => $contentId . '/index.php'
        ];
    }

    /**
     * Process raw HTML content
     */
    private function processRawHTML($contentId, $htmlContent) {
        $contentSize = strlen($htmlContent);
        error_log("Processing raw HTML content, size: {$contentSize} bytes");

        // OPTIMIZATION: Use analyze-only approach instead of full HTML rewriting
        // This significantly reduces token usage by:
        // 1. Stripping scripts/styles before analysis
        // 2. Only returning a JSON array of tags (not rewritten HTML)
        // The original HTML is preserved (no data-tag attributes added)
        $tags = [];
        $modifiedHTML = $htmlContent; // Keep original HTML

        // Skip AI processing if content is too large (>500KB) to prevent timeout
        $maxSizeForAI = 500000; // 500KB

        if ($contentSize <= $maxSizeForAI) {
            try {
                // Use the optimized analyze-only method to get tags without rewriting HTML
                error_log("Analyzing raw HTML content for tags (no modification)...");
                $tags = $this->claudeAPI->analyzeHTMLContent($htmlContent);
                error_log("Found tags: " . implode(', ', $tags));
            } catch (Exception $e) {
                error_log("AI Analysis failed: " . $e->getMessage());
                // Continue without tags
            }
        } else {
            error_log("Raw HTML content too large ({$contentSize} bytes), skipping AI analysis");
            // Extract tags from title as fallback
            if (preg_match('/<title>(.*?)<\/title>/i', $htmlContent, $matches)) {
                $title = strtolower(trim($matches[1]));
                $keywords = ['phishing', 'ransomware', 'malware', 'password', 'security', 'privacy', 'email'];
                foreach ($keywords as $keyword) {
                    if (stripos($title, $keyword) !== false) {
                        $tags[] = $keyword;
                    }
                }
            }
        }

        // Create directory
        $extractPath = $this->contentDir . $contentId . '/';
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        // Download any /system assets referenced in the HTML
        $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);

        // Store the HTML after asset download (before base tag injection)
        // This will be used to create entry_body_html with absolute URLs
        $htmlForEntryBody = $modifiedHTML;

        // Normalize RecordTest calls (convert parent.RecordTest to RecordTest)
        // This ensures scoring works regardless of iframe context
        $filesNormalized = $this->normalizeRecordTestCalls($extractPath);
        if ($filesNormalized > 0) {
            error_log("Normalized RecordTest calls in {$filesNormalized} file(s) for content {$contentId}");
        }

        // Detect if content is scorable (contains RecordTest calls)
        $scorable = $this->detectScorableContent($extractPath);
        error_log("Content {$contentId} scorable detection: " . ($scorable ? 'true' : 'false'));

        if ($this->useS3) {
            // S3 Storage Mode
            error_log("S3 mode enabled - processing raw HTML as static content");

            $htmlPath = $extractPath . 'index.html';

            // Inject base tag pointing to S3 location
            $s3BaseUrl = $this->s3Client->getContentBaseUrl($contentId);
            $baseTag = "<base href=\"{$s3BaseUrl}\">";
            if (stripos($modifiedHTML, '<head>') !== false) {
                $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
            } elseif (stripos($modifiedHTML, '</head>') !== false) {
                $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
            }

            // Inject tracking script that reads tracking ID from URL parameter
            $trackingJS = $this->claudeAPI->generateTrackingScript('', $this->basePath, true);
            if (stripos($modifiedHTML, '</body>') !== false) {
                $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);
            } else {
                $modifiedHTML .= "\n" . $trackingJS;
            }

            // Write HTML file
            file_put_contents($htmlPath, $modifiedHTML);

            // Upload directory to S3
            $uploadedFiles = $this->s3Client->uploadDirectory($contentId, $extractPath);
            error_log("S3: Uploaded " . count($uploadedFiles) . " files for raw HTML content {$contentId}");

            // Get S3 URL
            $s3Url = $this->s3Client->getContentUrl($contentId, 'index.html');

            // Store tags
            $this->storeTags($contentId, $tags);

            // Convert relative URLs to absolute in entry_body_html for DB storage
            $entryBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEntryBody, $s3BaseUrl);

            // Update content URL, scorable flag, and entry_body_html
            $this->db->update('content',
                ['content_url' => $s3Url, 'scorable' => $scorable, 'entry_body_html' => $entryBodyWithAbsoluteUrls],
                'id = :id',
                [':id' => $contentId]
            );

            return [
                'success' => true,
                'message' => 'HTML content processed and uploaded to S3 successfully',
                'tags' => $tags,
                'path' => $s3Url,
                's3' => true,
                'scorable' => $scorable
            ];
        }

        // Local Storage Mode
        $phpPath = $extractPath . 'index.php';

        // Add tracking script
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        if (stripos($modifiedHTML, '</body>') !== false) {
            $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);
        } else {
            $modifiedHTML .= "\n" . $trackingJS;
        }

        // Write content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store tags
        $this->storeTags($contentId, $tags);

        // Convert relative URLs to absolute in entry_body_html for DB storage
        $localBaseUrl = $this->basePath . '/content/' . $contentId;
        $entryBodyWithAbsoluteUrls = $this->convertRelativeUrlsToAbsolute($htmlForEntryBody, $localBaseUrl);

        // Update content URL, scorable flag, and entry_body_html
        $this->db->update('content',
            ['content_url' => $contentId . '/index.php', 'scorable' => $scorable, 'entry_body_html' => $entryBodyWithAbsoluteUrls],
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'HTML content processed successfully',
            'tags' => $tags,
            'path' => $contentId . '/index.php',
            'scorable' => $scorable
        ];
    }

    /**
     * Find index file in extracted directory
     * Supports common SCORM entry point names
     */
    private function findIndexFile($dir) {
        // Common SCORM/HTML entry point filenames in order of preference
        $entryPointNames = [
            'index.html',
            'index_scorm.html',  // Adobe Captivate
            'index_lms.html',    // Some LMS exports
            'launch.html',       // Common SCORM name
            'player.html',       // Some players
            'scormcontent/index.html',  // Articulate Storyline
            'story.html',        // Articulate Storyline
            'presentation.html'  // Some presentation tools
        ];

        // Check root directory first for each entry point name
        foreach ($entryPointNames as $name) {
            $path = $dir . $name;
            if (file_exists($path)) {
                error_log("Found entry point: $path");
                return $path;
            }
        }

        // Check subdirectories for index.html or index_scorm.html
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filename = strtolower($file->getFilename());
                if ($filename === 'index.html' || $filename === 'index_scorm.html') {
                    error_log("Found entry point in subdirectory: " . $file->getPathname());
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Store tags in database
     */
    private function storeTags($contentId, $tags, $tagType = 'interaction') {
        // Store in content_tags table (structured data for queries)
        foreach ($tags as $tag) {
            try {
		$randomBytes = bin2hex(random_bytes(5));
		$tagId = $contentId . $randomBytes;
                $this->db->insert('content_tags', [
		    'id' => $tagId,
		    'content_id' => $contentId,
                    'tag_name' => $tag,
                    'tag_type' => $tagType,
                    'confidence_score' => 1.0
                ]);
            } catch (Exception $e) {
                // Tag might already exist, ignore duplicate errors
                if (strpos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
            }
        }

        // Also store in content.tags field (comma-separated for compatibility)
        if (!empty($tags)) {
            $tagsString = implode(', ', $tags);
            try {
                $this->db->update('content',
                    ['tags' => $tagsString],
                    'id = :id',
                    [':id' => $contentId]
                );
            } catch (Exception $e) {
                // Column might not exist yet, log but don't fail
                error_log("Warning: Could not update content.tags field: " . $e->getMessage());
            }
        }
    }

    /**
     * Get content tags
     */
    public function getContentTags($contentId) {
        return $this->db->fetchAll(
            'SELECT * FROM content_tags WHERE content_id = :content_id',
            [':content_id' => $contentId]
        );
    }

    /**
     * Download external assets referenced in HTML
     * Finds references to /system and /images paths (from login.phishme.com) and CDN assets (images.pmeimg.com)
     * Downloads them and updates HTML references to point to local copies
     * Also scans downloaded CSS files for nested asset references (fonts, images, etc.)
     */
    private function downloadSystemAssets($html, $contentDir, $contentId) {
        $assetsToDownload = [];

        // Pattern 1: /system paths (from login.phishme.com)
        $systemPatterns = [
            '/src=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/system\/[^"\'\)]+)["\'\)]/i' // CSS url() references
        ];

        foreach ($systemPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullPath) {
                    // Separate path from query string
                    $parts = explode('?', $fullPath, 2);
                    $pathOnly = $parts[0];

                    // Validate path to prevent directory traversal
                    if ($this->isValidPhishmePath($pathOnly, $contentDir)) {
                        $assetsToDownload[$fullPath] = [
                            'fullPath' => $fullPath,
                            'pathOnly' => $pathOnly,
                            'downloadUrl' => 'https://login.phishme.com' . $fullPath,
                            'localPath' => $contentDir . ltrim($pathOnly, '/'),
                            'newHtmlPath' => $this->basePath . '/content/' . $contentId . $pathOnly
                        ];
                    } else {
                        error_log("Rejected invalid system path (directory traversal attempt): $pathOnly");
                    }
                }
            }
        }

        // Pattern 2: /images paths (from login.phishme.com, e.g. /images/shared_landing/...)
        $imagesPatterns = [
            '/src=["\']?(\/images\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\']?(\/images\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/images\/[^"\'\)]+)["\'\)]/i' // CSS url() references
        ];

        foreach ($imagesPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullPath) {
                    // Separate path from query string
                    $parts = explode('?', $fullPath, 2);
                    $pathOnly = $parts[0];

                    // Validate path to prevent directory traversal
                    if ($this->isValidPhishmePath($pathOnly, $contentDir)) {
                        $assetsToDownload[$fullPath] = [
                            'fullPath' => $fullPath,
                            'pathOnly' => $pathOnly,
                            'downloadUrl' => 'https://login.phishme.com' . $fullPath,
                            'localPath' => $contentDir . ltrim($pathOnly, '/'),
                            'newHtmlPath' => $this->basePath . '/content/' . $contentId . $pathOnly
                        ];
                    } else {
                        error_log("Rejected invalid images path (directory traversal attempt): $pathOnly");
                    }
                }
            }
        }

        // Pattern 3: CDN assets (//images.pmeimg.com, //cdn.example.com, etc.)
        $cdnPatterns = [
            '/src=["\'](\/\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\'](\/\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/\/[^"\'\)]+)["\'\)]/i'
        ];

        foreach ($cdnPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullUrl) {
                    // Parse URL to extract components
                    $urlParts = parse_url('https:' . $fullUrl);
                    if (!$urlParts || !isset($urlParts['host'])) {
                        continue;
                    }

                    $host = $urlParts['host'];
                    $path = $urlParts['path'] ?? '/';
                    $query = isset($urlParts['query']) ? '?' . $urlParts['query'] : '';

                    // Create safe local directory structure: cdn/{host}/path
                    $localRelativePath = 'cdn/' . $host . $path;
                    $localPath = $contentDir . $localRelativePath;

                    $assetsToDownload[$fullUrl] = [
                        'fullPath' => $fullUrl,
                        'pathOnly' => $path,
                        'downloadUrl' => 'https:' . $fullUrl,
                        'localPath' => $localPath,
                        'newHtmlPath' => $this->basePath . '/content/' . $contentId . '/' . $localRelativePath
                    ];
                }
            }
        }

        // Download each unique asset
        $downloadedCssFiles = [];
        foreach ($assetsToDownload as $originalRef => $asset) {
            $downloadUrl = $asset['downloadUrl'];
            $localPath = $asset['localPath'];

            // Create directory structure
            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            // Download file using wget (with timeout and error handling)
            $escapedUrl = escapeshellarg($downloadUrl);
            $escapedPath = escapeshellarg($localPath);

            // Use wget with: timeout, follow redirects, quiet mode, overwrite existing
            $command = "wget --timeout=10 --tries=2 -q -O $escapedPath $escapedUrl 2>&1";

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                error_log("Downloaded asset: $originalRef from $downloadUrl");

                // Track CSS files for nested asset scanning
                if (preg_match('/\.css(\?.*)?$/i', $localPath)) {
                    $downloadedCssFiles[] = $localPath;
                }
            } else {
                error_log("Failed to download asset: $originalRef from $downloadUrl (exit code: $returnCode)");
                // Continue with other assets even if one fails
            }
        }

        // Update HTML references to point to the new local location
        foreach ($assetsToDownload as $originalRef => $asset) {
            $newPath = $asset['newHtmlPath'];

            // Replace all variations of the reference
            $html = str_replace('src="' . $originalRef . '"', 'src="' . $newPath . '"', $html);
            $html = str_replace("src='" . $originalRef . "'", "src='" . $newPath . "'", $html);
            $html = str_replace('href="' . $originalRef . '"', 'href="' . $newPath . '"', $html);
            $html = str_replace("href='" . $originalRef . "'", "href='" . $newPath . "'", $html);
            $html = str_replace('url(' . $originalRef . ')', 'url(' . $newPath . ')', $html);
            $html = str_replace('url("' . $originalRef . '")', 'url("' . $newPath . '")', $html);
            $html = str_replace("url('" . $originalRef . "')", "url('" . $newPath . "')", $html);
        }

        // Scan downloaded CSS files for nested asset references (fonts, images, etc.)
        foreach ($downloadedCssFiles as $cssFilePath) {
            $this->processNestedCssAssets($cssFilePath, $contentDir, $contentId);
        }

        return $html;
    }

    /**
     * Scan a downloaded CSS file for nested asset references and download them
     * Handles @font-face src, background images, and other url() references
     */
    private function processNestedCssAssets($cssFilePath, $contentDir, $contentId) {
        if (!file_exists($cssFilePath)) {
            return;
        }

        $cssContent = file_get_contents($cssFilePath);
        if ($cssContent === false) {
            error_log("Failed to read CSS file for nested asset processing: $cssFilePath");
            return;
        }

        $nestedAssets = [];

        // Patterns for /system/ and /images/ paths in CSS url() references
        // Captures paths with optional query strings/fragments
        $patterns = [
            '/url\(\s*["\']?(\/system\/[^"\'\)\s]+)["\']?\s*\)/i',
            '/url\(\s*["\']?(\/images\/[^"\'\)\s]+)["\']?\s*\)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $cssContent, $matches)) {
                foreach ($matches[1] as $fullPath) {
                    // Separate path from query string for download
                    $parts = explode('?', $fullPath, 2);
                    $pathOnly = $parts[0];

                    // Validate path
                    if ($this->isValidPhishmePath($pathOnly, $contentDir)) {
                        $localPath = $contentDir . ltrim($pathOnly, '/');

                        // Store with pathOnly as key to deduplicate same file with different query strings
                        if (!isset($nestedAssets[$pathOnly])) {
                            $nestedAssets[$pathOnly] = [
                                'downloadUrl' => 'https://login.phishme.com' . $pathOnly,
                                'localPath' => $localPath,
                            ];
                        }
                    }
                }
            }
        }

        // Download nested assets
        foreach ($nestedAssets as $pathOnly => $asset) {
            $downloadUrl = $asset['downloadUrl'];
            $localPath = $asset['localPath'];

            // Skip if already downloaded
            if (file_exists($localPath)) {
                error_log("Nested CSS asset already exists: $localPath");
                continue;
            }

            // Create directory structure
            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            // Download file
            $escapedUrl = escapeshellarg($downloadUrl);
            $escapedPath = escapeshellarg($localPath);
            $command = "wget --timeout=10 --tries=2 -q -O $escapedPath $escapedUrl 2>&1";

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                error_log("Downloaded nested CSS asset: $pathOnly from $downloadUrl");
            } else {
                error_log("Failed to download nested CSS asset: $pathOnly (exit code: $returnCode)");
            }
        }

        // Do a blanket replacement of ALL /system/ and /images/ references in the CSS
        // This is more robust than trying to match exact url() patterns
        // because CSS can have various whitespace, quote styles, etc.
        $contentPathPrefix = $this->basePath . '/content/' . $contentId;
        $originalContent = $cssContent;

        // Replace /system/ and /images/ with the full content path prefix
        // This handles all url() variations without needing to match exact patterns
        $cssContent = str_replace('/system/', $contentPathPrefix . '/system/', $cssContent);
        $cssContent = str_replace('/images/', $contentPathPrefix . '/images/', $cssContent);

        // Write modified CSS back to file if changed
        if ($cssContent !== $originalContent) {
            file_put_contents($cssFilePath, $cssContent);
            error_log("Updated CSS file with /system/ and /images/ path replacements: $cssFilePath");
        }
    }

    /**
     * Validate that a phishme asset path is safe and doesn't contain directory traversal
     * Handles both /system/ and /images/ paths from login.phishme.com
     * Note: Path should not include query strings - they should be stripped before validation
     */
    private function isValidPhishmePath($path, $contentDir) {
        // Must start with /system/ or /images/
        $validPrefixes = ['/system/', '/images/'];
        $hasValidPrefix = false;
        foreach ($validPrefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $hasValidPrefix = true;
                break;
            }
        }
        if (!$hasValidPrefix) {
            return false;
        }

        // Remove leading slash for path construction
        $relativePath = ltrim($path, '/');

        // Check for directory traversal sequences
        if (strpos($relativePath, '..') !== false) {
            return false;
        }

        // Construct the full path
        $fullPath = $contentDir . $relativePath;

        // Normalize the path to resolve any . or .. segments
        $realContentDir = realpath($contentDir);

        // If directory doesn't exist yet, check parent directories
        $pathToCheck = $fullPath;
        while (!file_exists($pathToCheck)) {
            $parent = dirname($pathToCheck);
            if ($parent === $pathToCheck) {
                // Reached root without finding existing path
                break;
            }
            $pathToCheck = $parent;
        }

        if (file_exists($pathToCheck)) {
            $resolvedPath = realpath($pathToCheck);
            // Ensure the resolved path is within the content directory
            if ($resolvedPath === false || strpos($resolvedPath, $realContentDir) !== 0) {
                return false;
            }
        }

        // Additional check: ensure path only contains safe filesystem characters
        // This validates the path portion only (query strings should be stripped before calling)
        if (!preg_match('/^\/(system|images)\/[a-zA-Z0-9\/_.\-\s%()]+$/', $path)) {
            return false;
        }

        return true;
    }

    /**
     * Split HTML content into chunks at safe boundaries
     * Splits at closing tags to avoid breaking HTML structure
     */
    private function splitHTMLIntoChunks($html, $maxChunkSize = 50000) {
        if (strlen($html) <= $maxChunkSize) {
            return [$html];
        }

        $chunks = [];
        $currentPos = 0;
        $htmlLength = strlen($html);

        error_log("Splitting HTML (" . $htmlLength . " bytes) into chunks of ~" . $maxChunkSize . " bytes");

        while ($currentPos < $htmlLength) {
            // Calculate the end position for this chunk
            $endPos = min($currentPos + $maxChunkSize, $htmlLength);

            // If we're at the end, just take the rest
            if ($endPos >= $htmlLength) {
                $chunks[] = substr($html, $currentPos);
                break;
            }

            // Extract chunk from current position to end position
            $chunk = substr($html, $currentPos, $endPos - $currentPos);

            // Find the last safe closing tag in this chunk
            // Look for common closing tags
            $safeTagPattern = '/<\/(div|section|form|article|main|p|li|ul|ol|table|tr|td|th|header|footer|nav|aside)>/i';

            if (preg_match_all($safeTagPattern, $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                // Get the last match
                $lastMatch = end($matches[0]);
                $splitPoint = $lastMatch[1] + strlen($lastMatch[0]);

                // Adjust chunk to end at this safe point
                $chunk = substr($chunk, 0, $splitPoint);
            }
            // If no safe tags found, try to at least split at a tag boundary (any closing tag)
            elseif (preg_match_all('/<\/[^>]+>/i', $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                $lastMatch = end($matches[0]);
                $splitPoint = $lastMatch[1] + strlen($lastMatch[0]);
                $chunk = substr($chunk, 0, $splitPoint);
            }

            $chunks[] = $chunk;
            $currentPos += strlen($chunk);
        }

        error_log("Split HTML into " . count($chunks) . " chunks");
        return $chunks;
    }

    /**
     * Process large HTML content in chunks
     * Each chunk is tagged separately and then reassembled
     */
    private function processHTMLInChunks($htmlContent, $contentType = 'educational') {
        $chunks = $this->splitHTMLIntoChunks($htmlContent, 50000); // 50KB chunks

        $taggedChunks = [];
        $allTags = [];

        foreach ($chunks as $index => $chunk) {
            $chunkNum = $index + 1;
            $totalChunks = count($chunks);

            error_log("Processing chunk {$chunkNum}/{$totalChunks} (" . strlen($chunk) . " bytes)");

            try {
                $result = $this->claudeAPI->tagHTMLContent($chunk, $contentType);
                $taggedChunks[] = $result['html'];

                // Accumulate tags from all chunks
                foreach ($result['tags'] as $tag) {
                    if (!in_array($tag, $allTags)) {
                        $allTags[] = $tag;
                    }
                }

                error_log("Chunk {$chunkNum}/{$totalChunks} completed, found " . count($result['tags']) . " tags");
            } catch (Exception $e) {
                error_log("Error processing chunk {$chunkNum}/{$totalChunks}: " . $e->getMessage());
                // On error, use original chunk without tags
                $taggedChunks[] = $chunk;
            }
        }

        // Reassemble all chunks
        $taggedHTML = implode('', $taggedChunks);

        error_log("Chunked processing complete. Total unique tags: " . count($allTags));

        return [
            'html' => $taggedHTML,
            'tags' => $allTags
        ];
    }
}
