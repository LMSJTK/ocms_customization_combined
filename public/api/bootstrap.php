<?php
/**
 * Bootstrap file for API endpoints
 * Loads configuration and initializes core classes
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// Note: CORS headers are set dynamically after database initialization
// to validate origins against the domains table

// Load configuration
$configPath = '/var/www/html/config/config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/config/config.example.php';
}
error_log("Loading config from: " . $configPath);
$config = require $configPath;
error_log("Config loaded, debug mode: " . ($config['app']['debug'] ? 'true' : 'false'));

// Extract base path from base_url for API calls
$parsedUrl = parse_url($config['app']['base_url']);
//$basePath = $parsedUrl['path'] ?? '';
$basePath = rtrim($config['app']['base_url'], '/');

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoload classes
spl_autoload_register(function ($className) {
    $file = '/var/www/html/lib/' . $className . '.php';
    error_log("Autoloading class: $className from $file");
    if (file_exists($file)) {
        require_once $file;
    } else {
        error_log("Class file not found: $file");
    }
});

// Initialize core classes
try {
    error_log("Initializing Database...");
    $db = Database::getInstance($config['database']);
    error_log("Database initialized");

    // --- Dynamic CORS handling based on domains table ---
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigin = '*'; // Default fallback

    if ($origin) {
        // Always allow the configured base_url
        $configuredBaseUrl = rtrim($config['app']['base_url'] ?? '', '/');
        $originNormalized = rtrim($origin, '/');

        // Get external UI origin from config (MESSAGEHUB_ENDPOINTS_UI_EXTERNAL)
        // This is the UI that embeds content and needs CORS access
        $externalUrl = $config['app']['external_url'] ?? '';
        $externalHost = parse_url($externalUrl, PHP_URL_HOST);
        $externalScheme = parse_url($externalUrl, PHP_URL_SCHEME);
        $externalOrigin = ($externalScheme && $externalHost) ? "{$externalScheme}://{$externalHost}" : '';

        if ($configuredBaseUrl && $originNormalized === $configuredBaseUrl) {
            $allowedOrigin = $origin;
        } elseif ($externalOrigin && $originNormalized === $externalOrigin) {
            // Allow the external UI domain
            $allowedOrigin = $origin;
            error_log("CORS: Origin '$origin' matched external_url config");
        } else {
            // Check if this origin exists in the domains table or pm_phishing_domain table
            try {
                $domainFound = false;

                // 1. Check the 'domains' table first (stores full URLs like https://example.com)
                $domainsTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains';
                try {
                    $checkSql = $db->getDbType() === 'pgsql'
                        ? "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'global' AND table_name = 'domains')"
                        : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'domains'";
                    $result = $db->fetchOne($checkSql, []);
                    $tableExists = $db->getDbType() === 'pgsql' ? ($result['exists'] ?? false) : (($result['COUNT(*)'] ?? 0) > 0);

                    if ($tableExists) {
                        $domain = $db->fetchOne(
                            "SELECT domain_url FROM {$domainsTable} WHERE domain_url = :url AND is_active = 1",
                            [':url' => $originNormalized]
                        );
                        if ($domain) {
                            $domainFound = true;
                            error_log("CORS: Origin '$origin' matched active domain in domains table");
                        }
                    }
                } catch (Exception $e) {
                    error_log("CORS: Could not check domains table: " . $e->getMessage());
                }

                // 2. If not found, check pm_phishing_domain table (stores just hostname like example.com)
                if (!$domainFound) {
                    $phishingTable = ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'pm_phishing_domain';
                    try {
                        // Extract hostname from origin (https://staging.example.com -> staging.example.com)
                        $originHost = parse_url($origin, PHP_URL_HOST);
                        if ($originHost) {
                            $phishingDomain = $db->fetchOne(
                                "SELECT domain FROM {$phishingTable} WHERE domain = :host AND is_hidden = false",
                                [':host' => $originHost]
                            );
                            if ($phishingDomain) {
                                $domainFound = true;
                                error_log("CORS: Origin '$origin' matched phishing domain '{$originHost}' in pm_phishing_domain table");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("CORS: Could not check pm_phishing_domain table: " . $e->getMessage());
                    }
                }

                if ($domainFound) {
                    $allowedOrigin = $origin;
                } else {
                    error_log("CORS: Origin '$origin' not found in any domain table, using default");
                }
            } catch (Exception $e) {
                // Fail silently to default '*' if there's any database issue
                error_log("CORS: Domain check failed: " . $e->getMessage());
            }
        }
    }

    // Set CORS headers
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    // Allow credentials only when we have a specific origin (not wildcard)
    if ($allowedOrigin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    // --- End CORS handling ---

    error_log("Initializing ClaudeAPI...");
    $claudeAPI = new ClaudeAPI($config['claude']);
    error_log("ClaudeAPI initialized");

    error_log("Initializing AWSSNS...");
    $sns = new AWSSNS($config['aws_sns']);
    error_log("AWSSNS initialized");

    // Initialize S3Client if S3 storage is configured
    $s3Client = null;
    if (isset($config['s3']) && !empty($config['s3']['bucket'])) {
        error_log("Initializing S3Client...");
        $s3Client = new S3Client($config['s3']);
        if ($s3Client->isEnabled()) {
            error_log("S3Client initialized - S3 storage ENABLED");
        } else {
            error_log("S3Client initialized but DISABLED (check S3_CONTENT_ENABLED env var)");
        }
    } else {
        error_log("S3Client not initialized - no S3 config found, using local storage");
    }

    error_log("Initializing ContentProcessor...");
    $contentProcessor = new ContentProcessor($db, $claudeAPI, $config['content']['upload_dir'], $basePath, $s3Client);
    error_log("ContentProcessor initialized" . ($s3Client && $s3Client->isEnabled() ? " with S3 storage" : " with local storage"));

    error_log("Initializing TrackingManager...");
    $trackingManager = new TrackingManager($db, $sns);
    error_log("TrackingManager initialized");

    error_log("All classes initialized successfully");
} catch (Exception $e) {
    error_log("Bootstrap initialization failed: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'System initialization failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}

/**
 * Helper function to send JSON response
 */
function sendJSON($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Helper function to get JSON input
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Helper function to validate required fields
 */
function validateRequired($data, $required) {
    $missing = [];
    foreach ($required as $field) {
        // Check if not set OR (empty AND not distinct from 0)
        if (!isset($data[$field]) || (empty($data[$field]) && $data[$field] !== 0 && $data[$field] !== '0')) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        sendJSON([
            'error' => 'Missing required fields',
            'fields' => $missing
        ], 400);
    }
}

/*
 * Helper for the helper, adding misssing function
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    return $headers;
    }
}

/**
 * Helper function to validate bearer token authentication
 */
function validateBearerToken($config) {
    // Check if API authentication is enabled
    if (!isset($config['api']['enabled']) || !$config['api']['enabled']) {
        return; // Authentication disabled
    }

    // Get the Authorization header
    $headers = getallheaders();
    $authHeader = null;

    // Look for Authorization header (case-insensitive)
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }

    // Check if Authorization header exists
    if (!$authHeader) {
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Missing Authorization header'
        ], 401);
    }

    // Extract bearer token
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Invalid Authorization header format. Expected: Bearer <token>'
        ], 401);
    }

    $providedToken = $matches[1];
    $expectedToken = $config['api']['bearer_token'] ?? null;

    // Validate token
    if (!$expectedToken) {
        error_log('Warning: API bearer token not configured in config.php');
        sendJSON([
            'error' => 'Server configuration error',
            'message' => 'API authentication not properly configured'
        ], 500);
    }

    // Use timing-safe comparison to prevent timing attacks
    if (!hash_equals($expectedToken, $providedToken)) {
        error_log('Authentication failed: Invalid bearer token provided');
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Invalid bearer token'
        ], 401);
    }

    // Token is valid, continue
}

/**
 * Helper function to validate VPN access based on client IP address
 * Restricts access to internal tools (testing, db-explorer, reporting) to VPN IPs only
 */
function validateVpnAccess() {
    // Allowed VPN IP ranges (all /32 single IPs)
    $allowedIps = [
        '23.101.219.238',
        '40.121.106.7',
        '40.123.206.69',
        '51.104.226.191',
        '104.40.29.52',
    ];

    // Get client IP address
    // Check for proxy headers first (in case behind load balancer)
    $clientIp = null;
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // X-Forwarded-For can contain multiple IPs, take the first one (original client)
        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $clientIp = $ips[0];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $clientIp = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $clientIp = $_SERVER['REMOTE_ADDR'];
    }

    // Allow localhost for development
    if ($clientIp === '127.0.0.1' || $clientIp === '::1' || $clientIp === 'localhost') {
        return; // Allow local access
    }

    // Check if client IP is in the allowed list
    if (!in_array($clientIp, $allowedIps, true)) {
        error_log("VPN access denied for IP: $clientIp");
        sendJSON([
            'error' => 'Forbidden',
            'message' => 'Access restricted to VPN'
        ], 403);
    }

    // IP is allowed, continue
}

/**
 * Helper function to generate a UUID4 (RFC 4122 compliant)
 * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 */
function generateUUID4() {
    $data = random_bytes(16);

    // Set version to 0100 (UUID version 4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

    // Set bits 6-7 to 10 (RFC 4122 variant)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Format as UUID with dashes
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Helper function to restore dashes to a UUID from dashless format
 * Converts: abc123def456789012345678901234567890
 * To:       abc123de-f456-7890-1234-567890123456
 */
function restoreUUIDDashes($dashlessUUID) {
    // Remove any existing dashes (in case they're there)
    $clean = str_replace('-', '', $dashlessUUID);

    // Validate length (should be 32 hex characters)
    if (strlen($clean) !== 32) {
        return null;
    }

    // Insert dashes at the proper positions: 8-4-4-4-12
    return substr($clean, 0, 8) . '-' .
           substr($clean, 8, 4) . '-' .
           substr($clean, 12, 4) . '-' .
           substr($clean, 16, 4) . '-' .
           substr($clean, 20);
}
