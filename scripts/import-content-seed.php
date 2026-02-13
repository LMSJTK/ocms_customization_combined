#!/usr/bin/env php
<?php
/**
 * Content Seed Import Script
 *
 * Imports content rows from a seed file into the database. Rows that already
 * exist (by ID) are skipped to avoid duplicates.
 *
 * Domain Mapping:
 *   The script automatically maps content_domain values to the equivalent domain
 *   in the target environment. Matching is done by extracting the "core" domain
 *   name (e.g., "newsalerts" from "staging.en.newsalerts.com.sg"), allowing
 *   matches across different TLDs (.com, .org, .eu, .com.sg, etc.).
 *
 *   Domain sources:
 *   - Staging environment: Uses data/smbstaging_domain_list.json
 *   - Other environments: Uses pm_phishing_domain database table
 *
 *   If no matching domain is found, defaults to "newsalerts" domain.
 *
 * Usage:
 *   php scripts/import-content-seed.php [options]
 *
 * Options:
 *   --input=FILE         Input seed file path (default: database/seeds/content-seed.json)
 *   --dry-run            Show what would be imported without making changes
 *   --force              Update existing rows instead of skipping them
 *   --verbose            Show detailed progress information
 *   --quiet              Only show errors and final summary
 *   --help               Show this help message
 *
 * Examples:
 *   php scripts/import-content-seed.php --verbose
 *   php scripts/import-content-seed.php --input=/tmp/content-export.json --dry-run
 *   php scripts/import-content-seed.php --force
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, etc.)
 */

set_time_limit(0);
ini_set('memory_limit', '1G');

// Parse command line arguments
$options = getopt('', [
    'input::',
    'dry-run',
    'force',
    'verbose',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Content Seed Import Script

Imports content rows from a seed file into the database. Rows that already
exist (by ID) are skipped to avoid duplicates.

Domain Mapping:
  The script automatically maps content_domain values to the equivalent domain
  in the target environment. Matching is done by extracting the "core" domain
  name (e.g., "newsalerts" from "staging.en.newsalerts.com.sg"), allowing
  matches across different TLDs (.com, .org, .eu, .com.sg, etc.).

  Domain sources:
  - Staging environment: Uses data/smbstaging_domain_list.json
  - Other environments: Uses pm_phishing_domain database table

  If no matching domain is found, defaults to "newsalerts" domain.

Usage:
  php scripts/import-content-seed.php [options]

Options:
  --input=FILE         Input seed file path (default: database/seeds/content-seed.json)
  --dry-run            Show what would be imported without making changes
  --force              Update existing rows instead of skipping them
  --verbose            Show detailed progress information
  --quiet              Only show errors and final summary
  --help               Show this help message

Examples:
  php scripts/import-content-seed.php --verbose
  php scripts/import-content-seed.php --input=/tmp/content-export.json --dry-run
  php scripts/import-content-seed.php --force

Exit codes:
  0 - Success
  1 - Error (configuration, database, etc.)

HELP;
    exit(0);
}

// Configuration from options
$inputFile = $options['input'] ?? __DIR__ . '/../database/seeds/content-seed.json';
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

// Logging functions
function logInfo($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        echo "[INFO] $message\n";
    }
}

function logWarning($message) {
    global $quiet;
    if (!$quiet) {
        echo "[WARNING] $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    global $quiet;
    if (!$quiet) {
        echo "[SUCCESS] $message\n";
    }
}

/**
 * Normalize language values into a clean comma-separated format.
 *
 * Handles: "en", '["en"]', '["ptbr"]', '["ar","de","en","esla","pt-br"]'
 * Output:  "en", "en",     "pt-br",   "ar,de,en,es-la,pt-br"
 */
function normalizeLanguages($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }

    $raw = trim($raw);

    // Try to decode as JSON array
    $codes = [];
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $codes = $decoded;
        } else {
            $codes = [$raw];
        }
    } else {
        $codes = array_map('trim', explode(',', $raw));
    }

    $normalized = [];
    foreach ($codes as $code) {
        $code = strtolower(trim($code));
        if ($code === '') {
            continue;
        }
        // 4-char codes without a hyphen: insert hyphen after first 2 chars
        if (strlen($code) === 4 && strpos($code, '-') === false) {
            $code = substr($code, 0, 2) . '-' . substr($code, 2);
        }
        $normalized[] = $code;
    }

    if (empty($normalized)) {
        return null;
    }

    sort($normalized);
    $normalized = array_unique($normalized);

    return implode(',', $normalized);
}

/**
 * Extract the core domain name by stripping prefixes AND suffixes (TLDs)
 * This allows matching domains across different environments with different TLDs.
 *
 * Examples:
 *   staging.en.newsalerts.com.sg -> newsalerts
 *   smbstaging.newsalerts.eu -> newsalerts
 *   dev.perksbiz.com -> perksbiz
 *   smbstaging.en.enterpriserewards.com.sg -> enterpriserewards
 */
function extractCoreDomain($domain) {
    if (empty($domain)) {
        return null;
    }

    $domain = strtolower($domain);

    // Common environment prefixes to strip (order matters - check longer ones first)
    $envPrefixes = ['smbstaging.', 'production.', 'staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'prod.'];

    // Language/region prefixes that may appear after env prefix
    $langPrefixes = ['en.', 'us.', 'my.', 'uk.', 'au.', 'ca.', 'de.', 'fr.', 'es.', 'it.', 'jp.', 'cn.', 'kr.', 'br.', 'mx.', 'lol.'];

    // Strip environment prefix
    foreach ($envPrefixes as $prefix) {
        if (strpos($domain, $prefix) === 0) {
            $domain = substr($domain, strlen($prefix));
            break;
        }
    }

    // Strip language/region prefix
    foreach ($langPrefixes as $prefix) {
        if (strpos($domain, $prefix) === 0) {
            $domain = substr($domain, strlen($prefix));
            break;
        }
    }

    // Common TLD suffixes to strip (order matters - check longer/compound ones first)
    $tldSuffixes = [
        '.com.sg', '.com.au', '.com.br', '.com.mx', '.co.uk', '.co.nz', '.co.jp',
        '.pictures', '.support', '.juegos', '.webshar.es', '.asia',
        '.com', '.org', '.net', '.eu', '.info', '.us', '.mx', '.pl', '.club', '.xyz', '.me'
    ];

    // Strip TLD suffix
    foreach ($tldSuffixes as $suffix) {
        if (substr($domain, -strlen($suffix)) === $suffix) {
            $domain = substr($domain, 0, -strlen($suffix));
            break;
        }
    }

    return $domain ?: null;
}

/**
 * Check if we're running in the staging environment
 * Staging environment uses a hardcoded domain list instead of pm_phishing_domain table
 */
function isStagingEnvironment() {
    $ocmsEndpoint = $_ENV['MESSAGEHUB_ENDPOINTS_OCMSSERVICE'] ?? '';
    logInfo("MESSAGEHUB_ENDPOINTS_OCMSSERVICE = '$ocmsEndpoint'");

    // Check for staging environment - match on cfp-staging in the URL
    $isStaging = strpos($ocmsEndpoint, 'cfp-staging') !== false;

    if ($isStaging) {
        logInfo("Detected staging environment");
    }

    return $isStaging;
}

/**
 * Load domain list from JSON file (for staging environment)
 */
function loadDomainsFromJsonFile($filePath) {
    if (!file_exists($filePath)) {
        logWarning("Domain list file not found: $filePath");
        return [];
    }

    $json = file_get_contents($filePath);
    if ($json === false) {
        logWarning("Failed to read domain list file: $filePath");
        return [];
    }

    $data = json_decode($json, true);
    if ($data === null || !isset($data['domains'])) {
        logWarning("Invalid domain list file format: $filePath");
        return [];
    }

    return $data['domains'];
}

/**
 * Build domain mapping cache from pm_phishing_domain table or JSON file
 * Returns array mapping core domain names to environment-specific full domains,
 * plus a tag-to-domain mapping for direct tag lookups.
 *
 * @param Database $db Database instance
 * @return array ['cache' => [...], 'default' => 'full.default.domain', 'tagToDomain' => [...]]
 */
function buildDomainMappingCache($db) {
    $cache = [];
    $tagToDomain = [];
    $domains = [];

    // Check if we're in staging environment - use JSON file instead of database
    if (isStagingEnvironment()) {
        $jsonPath = __DIR__ . '/../data/smbstaging_domain_list.json';
        logInfo("Staging environment detected - loading domains from JSON file");
        $domains = loadDomainsFromJsonFile($jsonPath);
        logInfo("Loaded " . count($domains) . " domains from JSON file");
        // Note: JSON file doesn't have tags, so tagToDomain will be empty for staging
    } else {
        // Load from database with tags
        logInfo("Loading domains from pm_phishing_domain table");
        try {
            $rows = $db->fetchAll("SELECT tag, domain FROM pm_phishing_domain");
            foreach ($rows as $row) {
                $domains[] = $row['domain'];
                // Build tag-to-domain mapping
                if (!empty($row['tag'])) {
                    $tagToDomain[$row['tag']] = $row['domain'];
                }
            }
            logInfo("Loaded " . count($domains) . " domains (" . count($tagToDomain) . " with tags) from database");
        } catch (Exception $e) {
            logInfo("Could not load pm_phishing_domain table: " . $e->getMessage());
        }
    }

    // Build the cache using core domain extraction
    // Also track all newsalerts domains to pick the best default
    $newsalertsDomains = [];
    foreach ($domains as $domain) {
        $coreDomain = extractCoreDomain($domain);
        if ($coreDomain) {
            // Store mapping: coreDomain -> actual full domain in this environment
            $cache[$coreDomain] = $domain;

            // Track all newsalerts domains
            if ($coreDomain === 'newsalerts') {
                $newsalertsDomains[] = $domain;
            }
        }
    }

    // Pick the best newsalerts domain as default
    // Prefer shorter domains (e.g., smbstaging.newsalerts.eu over smbstaging.en.newsalerts.com.sg)
    $defaultFullDomain = null;
    if (!empty($newsalertsDomains)) {
        usort($newsalertsDomains, function($a, $b) {
            return strlen($a) - strlen($b);
        });
        $defaultFullDomain = $newsalertsDomains[0];
        logInfo("Selected default domain: $defaultFullDomain (from " . count($newsalertsDomains) . " newsalerts variants)");
    }

    return [
        'cache' => $cache,
        'default' => $defaultFullDomain,
        'tagToDomain' => $tagToDomain
    ];
}

/**
 * Map a seed domain to the equivalent domain in this environment
 * Falls back to default domain (newsalerts) if no match found
 */
function mapDomainForEnvironment($seedDomain, $domainCacheData) {
    if (empty($seedDomain)) {
        return $seedDomain;
    }

    $cache = $domainCacheData['cache'];
    $defaultDomain = $domainCacheData['default'];

    // Extract core domain from seed value
    $coreDomain = extractCoreDomain($seedDomain);
    if (!$coreDomain) {
        // Can't extract core domain, use default if available
        if ($defaultDomain) {
            logInfo("  Could not extract core domain from '$seedDomain', using default");
            return $defaultDomain;
        }
        return $seedDomain;
    }

    // Look for a match in the cache
    if (isset($cache[$coreDomain])) {
        return $cache[$coreDomain];
    }

    // No match found - use default domain (newsalerts) if available
    if ($defaultDomain) {
        logInfo("  No match found for core domain '$coreDomain', using default: $defaultDomain");
        return $defaultDomain;
    }

    // No default available, return original
    return $seedDomain;
}

/**
 * Map an email address's domain to the equivalent domain in this environment
 * e.g., user@dev.perksbiz.com -> user@smbstaging.perksbiz.com
 */
function mapEmailAddressForEnvironment($email, $domainCacheData) {
    if (empty($email) || strpos($email, '@') === false) {
        return $email;
    }

    // Split email into local part and domain
    $parts = explode('@', $email, 2);
    $localPart = $parts[0];
    $domain = $parts[1];

    // Map the domain
    $mappedDomain = mapDomainForEnvironment($domain, $domainCacheData);

    // Reconstruct the email
    return $localPart . '@' . $mappedDomain;
}

/**
 * Look up domain by tag from pm_phishing_domain
 * Returns the domain if found, null otherwise
 */
function lookupDomainByTag($tag, $domainCacheData) {
    if (empty($tag)) {
        return null;
    }

    $tagToDomain = $domainCacheData['tagToDomain'] ?? [];

    if (isset($tagToDomain[$tag])) {
        return $tagToDomain[$tag];
    }

    return null;
}

/**
 * Map email content fields using domain tag if available, falling back to pattern matching
 * Returns array with mapped content_domain and email_from_address
 */
function mapEmailContentByTag($row, $domainCacheData) {
    $contentDomain = $row['content_domain'] ?? null;
    $emailFromAddress = $row['email_from_address'] ?? null;
    $domainTag = $row['domain_tag'] ?? null;

    $mappedDomain = null;
    $usedTagLookup = false;

    // First, try to look up by domain tag (most reliable)
    if (!empty($domainTag)) {
        $tagLookupDomain = lookupDomainByTag($domainTag, $domainCacheData);
        if ($tagLookupDomain) {
            $mappedDomain = $tagLookupDomain;
            $usedTagLookup = true;
            logInfo("  Used domain tag '$domainTag' -> '$mappedDomain'");
        } else {
            logInfo("  Domain tag '$domainTag' not found in target environment, falling back to pattern matching");
        }
    }

    // Fall back to pattern matching if tag lookup didn't work
    if (!$mappedDomain && !empty($contentDomain)) {
        $mappedDomain = mapDomainForEnvironment($contentDomain, $domainCacheData);
    }

    // Map email_from_address
    $mappedEmailFromAddress = $emailFromAddress;
    if (!empty($emailFromAddress) && strpos($emailFromAddress, '@') !== false) {
        if ($mappedDomain && $usedTagLookup) {
            // We have a tag-based mapping, reconstruct the email with the new domain
            $parts = explode('@', $emailFromAddress, 2);
            $localPart = $parts[0];
            $mappedEmailFromAddress = $localPart . '@' . $mappedDomain;
        } else {
            // Fall back to pattern matching
            $mappedEmailFromAddress = mapEmailAddressForEnvironment($emailFromAddress, $domainCacheData);
        }
    }

    return [
        'content_domain' => $mappedDomain,
        'email_from_address' => $mappedEmailFromAddress,
        'used_tag_lookup' => $usedTagLookup
    ];
}

// Check if seed file exists
if (!file_exists($inputFile)) {
    logInfo("Seed file not found: $inputFile");
    logInfo("Nothing to import.");
    exit(0);
}

// Load seed file
logInfo("Loading seed file: $inputFile");
$json = file_get_contents($inputFile);
if ($json === false) {
    logError("Failed to read seed file: $inputFile");
    exit(1);
}

$seedData = json_decode($json, true);
if ($seedData === null) {
    logError("Failed to parse seed file as JSON: " . json_last_error_msg());
    exit(1);
}

if (!isset($seedData['content']) || !is_array($seedData['content'])) {
    logError("Invalid seed file format: missing 'content' array");
    exit(1);
}

$totalRows = count($seedData['content']);
if ($totalRows === 0) {
    logInfo("Seed file contains no content rows");
    exit(0);
}

logInfo("Seed file contains $totalRows content rows");
logInfo("Exported at: " . ($seedData['exported_at'] ?? 'unknown'));

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}

if (!file_exists($configPath)) {
    logError("Configuration file not found");
    exit(1);
}

$config = require $configPath;

// Load database class
require_once __DIR__ . '/../lib/Database.php';

// Define the columns in content table (excluding auto-generated ones we want to preserve)
// Note: content_preview is excluded because we regenerate preview links on import
$contentColumns = [
    'id',
    'company_id',
    'title',
    'description',
    'content_type',
    'content_url',
    'email_from_address',
    'email_subject',
    'email_body_html',
    'email_attachment_filename',
    'email_attachment_content',
    'thumbnail_filename',
    'thumbnail_content',
    'tags',
    'difficulty',
    'content_domain',
    'languages',
    'scorable',
    'created_at',
    'updated_at'
];

// Get the target environment's UI external URL for fixing thumbnail/preview URLs
$targetUiExternalUrl = $_ENV['MESSAGEHUB_ENDPOINTS_UI_EXTERNAL'] ?? '';
if (!empty($targetUiExternalUrl)) {
    $targetUiExternalUrl = rtrim($targetUiExternalUrl, '/');
    logInfo("Target UI external URL: $targetUiExternalUrl");
}

// Get the target environment's OCMS service URL (base_url from config)
$targetOcmsServiceUrl = rtrim($config['app']['base_url'] ?? '', '/');
if (!empty($targetOcmsServiceUrl)) {
    logInfo("Target OCMS service URL: $targetOcmsServiceUrl");
}

// Known source UI URLs that need to be replaced (platform URLs)
$sourceUiUrls = [
    'https://platform-dev.cofense-dev.com',
    'https://platform-staging.cofense-dev.com',
    'https://platform.cofense.com',
    'https://login.phishme.com',
    'http://platform-dev.cofense-dev.com',
    'http://platform-staging.cofense-dev.com',
];

// Known source OCMS service URLs that need to be replaced
// These are used for things like default email thumbnails: /images/email_default.png
$sourceOcmsUrls = [
    'https://ecs-ocmsservice.cfp-dev.cofense-dev.com',
    'https://ecs-ocmsservice.cfp-staging.cofense-dev.com',
    'https://ecs-ocmsservice.cfp-prod.cofense-dev.com',
    'https://ecs-ocmsservice.cfp.cofense.com',
    'http://ecs-ocmsservice.cfp-dev.cofense-dev.com',
    'http://ecs-ocmsservice.cfp-staging.cofense-dev.com',
];

// Titles of system templates that should never be touched by seed import
$excludedTitles = [
    'Default Direct Training Template',
    'Default Smart Reinforcement Template',
];

/**
 * Generate a UUID4 (RFC 4122 compliant)
 */
function generateUUID4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a preview link for content by creating training + training_tracking records
 * Returns the preview URL or null on failure
 */
function generatePreviewLink($contentId, $title, $db, $config) {
    try {
        $trainingId = generateUUID4();
        $trainingTrackingId = generateUUID4();
        $uniqueTrackingId = generateUUID4();

        $tablePrefix = ($db->getDbType() === 'pgsql') ? 'global.' : '';

        // Create training record for preview
        $db->insert($tablePrefix . 'training', [
            'id' => $trainingId,
            'company_id' => 'system',
            'name' => 'Preview: ' . ($title ?? 'Content'),
            'description' => 'Auto-generated training for content preview',
            'training_type' => 'preview',
            'training_content_id' => $contentId,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Create training_tracking record
        $db->insert($tablePrefix . 'training_tracking', [
            'id' => $trainingTrackingId,
            'training_id' => $trainingId,
            'recipient_id' => 'preview',
            'unique_tracking_id' => $uniqueTrackingId,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Build preview URL
        $contentIdNoDash = str_replace('-', '', $contentId);
        $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
        $externalUrl = rtrim($config['app']['external_url'] ?? $config['app']['base_url'] ?? '', '/');
        $previewUrl = $externalUrl . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

        // Update content with preview link
        $db->query(
            "UPDATE content SET content_preview = :url WHERE id = :id",
            [':url' => $previewUrl, ':id' => $contentId]
        );

        return $previewUrl;
    } catch (Exception $e) {
        logWarning("Failed to generate preview link for $contentId: " . $e->getMessage());
        return null;
    }
}

try {
    logInfo("Connecting to database...");
    $db = Database::getInstance($config['database']);
    $dbType = $db->getDbType();
    logInfo("Connected to $dbType database");

    // Get list of existing content IDs
    logInfo("Fetching existing content IDs...");
    $existingRows = $db->fetchAll("SELECT id FROM content");
    $existingIds = array_column($existingRows, 'id');
    $existingCount = count($existingIds);
    logInfo("Found $existingCount existing content rows");

    // Build domain mapping cache for environment-specific domain translation
    logInfo("Building domain mapping cache...");
    $domainCacheData = buildDomainMappingCache($db);
    $domainCacheCount = count($domainCacheData['cache']);
    $defaultDomain = $domainCacheData['default'] ?? 'none';
    logInfo("Loaded $domainCacheCount domain mappings (default: $defaultDomain)");

    // Process import
    $imported = 0;
    $skipped = 0;
    $updated = 0;
    $errors = 0;
    $domainsMapped = 0;
    $previewsGenerated = 0;
    $contentForPreviewLinks = []; // Collect content IDs for preview link generation after commit

    if (!$dryRun) {
        $db->beginTransaction();
    }

    try {
        foreach ($seedData['content'] as $index => $row) {
            $rowNum = $index + 1;
            $id = $row['id'] ?? null;

            if (!$id) {
                logWarning("Row $rowNum: Missing ID, skipping");
                $errors++;
                continue;
            }

            // Skip system templates that should not be overwritten
            $rowTitle = $row['title'] ?? '';
            if (in_array($rowTitle, $excludedTitles, true)) {
                logInfo("Row $rowNum: Skipping system template '$rowTitle'");
                $skipped++;
                continue;
            }

            $exists = in_array($id, $existingIds);

            if ($exists && !$force) {
                logInfo("Row $rowNum: ID $id already exists, skipping");
                $skipped++;
                continue;
            }

            // For email content, handle domain mapping via tag lookup first
            $emailMappingResult = null;
            if (($row['content_type'] ?? '') === 'email') {
                $emailMappingResult = mapEmailContentByTag($row, $domainCacheData);
            }

            // Prepare data for insert/update
            $data = [];
            foreach ($contentColumns as $col) {
                if (!array_key_exists($col, $row)) {
                    continue;
                }

                $value = $row[$col];

                // Handle base64-encoded binary fields
                if (in_array($col, ['thumbnail_content', 'email_attachment_content'])) {
                    $encodingKey = $col . '_encoding';
                    if (isset($row[$encodingKey]) && $row[$encodingKey] === 'base64' && $value !== null) {
                        $value = base64_decode($value);
                        if ($value === false) {
                            logWarning("Row $rowNum: Failed to decode base64 for $col");
                            $value = null;
                        }
                    }
                }

                // Handle boolean fields
                if ($col === 'scorable') {
                    if ($dbType === 'mysql') {
                        $value = $value ? 1 : 0;
                    } else {
                        $value = $value ? 'true' : 'false';
                    }
                }

                // Normalize languages to clean comma-separated format
                if ($col === 'languages' && $value !== null) {
                    $value = normalizeLanguages($value);
                }

                // Handle domain mapping for environment-specific domains
                if ($col === 'content_domain' && $value !== null) {
                    if ($emailMappingResult && $emailMappingResult['content_domain']) {
                        // Use tag-based mapping result for email content
                        $originalDomain = $value;
                        $value = $emailMappingResult['content_domain'];
                        if ($value !== $originalDomain) {
                            $method = $emailMappingResult['used_tag_lookup'] ? 'tag lookup' : 'pattern matching';
                            logInfo("Row $rowNum: Mapped content_domain '$originalDomain' -> '$value' ($method)");
                            $domainsMapped++;
                        }
                    } else {
                        // Non-email content, use pattern matching
                        $originalDomain = $value;
                        $value = mapDomainForEnvironment($value, $domainCacheData);
                        if ($value !== $originalDomain) {
                            logInfo("Row $rowNum: Mapped content_domain '$originalDomain' -> '$value' (pattern matching)");
                            $domainsMapped++;
                        }
                    }
                }

                // Handle email from address domain mapping
                if ($col === 'email_from_address' && $value !== null) {
                    if ($emailMappingResult && $emailMappingResult['email_from_address']) {
                        // Use tag-based mapping result
                        $originalEmail = $value;
                        $value = $emailMappingResult['email_from_address'];
                        if ($value !== $originalEmail) {
                            $method = $emailMappingResult['used_tag_lookup'] ? 'tag lookup' : 'pattern matching';
                            logInfo("Row $rowNum: Mapped email_from_address '$originalEmail' -> '$value' ($method)");
                            $domainsMapped++;
                        }
                    } else {
                        // Fallback for non-email content (shouldn't happen but just in case)
                        $originalEmail = $value;
                        $value = mapEmailAddressForEnvironment($value, $domainCacheData);
                        if ($value !== $originalEmail) {
                            logInfo("Row $rowNum: Mapped email_from_address '$originalEmail' -> '$value' (pattern matching)");
                            $domainsMapped++;
                        }
                    }
                }

                // Handle URL fields that need environment-specific base URL
                // thumbnail_filename may contain source environment URLs
                // (content_preview is regenerated via generatePreviewLink, not imported)
                if ($col === 'thumbnail_filename' && $value !== null) {
                    $originalUrl = $value;
                    $urlReplaced = false;
                    $contentType = $row['content_type'] ?? '';
                    $isEmail = ($contentType === 'email');

                    // First, check for UI URLs (platform-*.cofense-dev.com etc.)
                    // These already have the correct host but may need /ocms-service path
                    if (!empty($targetUiExternalUrl)) {
                        foreach ($sourceUiUrls as $sourceUrl) {
                            if (strpos($value, $sourceUrl) !== false) {
                                // Check if it's an /images/ path that needs /ocms-service prefix
                                $path = str_replace($sourceUrl, '', $value);
                                if (strpos($path, '/images/') === 0) {
                                    // Email: shared /images/ path
                                    // Education/landing: content-specific path
                                    if ($isEmail) {
                                        $value = $targetUiExternalUrl . '/ocms-service' . $path;
                                    } else {
                                        $value = $targetUiExternalUrl . '/ocms-service/content/' . $id . $path;
                                    }
                                } else {
                                    $value = str_replace($sourceUrl, $targetUiExternalUrl, $value);
                                }
                                $urlReplaced = true;
                                break;
                            }
                        }
                    }

                    // Second, check for OCMS service URLs (ecs-ocmsservice.* etc.)
                    // These need to be converted to external URL + /ocms-service path
                    // so they route through the platform to the OCMS service
                    if (!$urlReplaced && !empty($targetUiExternalUrl)) {
                        foreach ($sourceOcmsUrls as $sourceUrl) {
                            if (strpos($value, $sourceUrl) !== false) {
                                // Replace with external URL + /ocms-service prefix
                                $path = str_replace($sourceUrl, '', $value);
                                if (strpos($path, '/images/') === 0) {
                                    // Email: shared /images/ path
                                    // Education/landing: content-specific path
                                    if ($isEmail) {
                                        $value = $targetUiExternalUrl . '/ocms-service' . $path;
                                    } else {
                                        $value = $targetUiExternalUrl . '/ocms-service/content/' . $id . $path;
                                    }
                                } else {
                                    $value = $targetUiExternalUrl . '/ocms-service' . $path;
                                }
                                $urlReplaced = true;
                                break;
                            }
                        }
                    }

                    if ($value !== $originalUrl) {
                        logInfo("Row $rowNum: Updated $col URL to target environment");
                    }
                }

                $data[$col] = $value;
            }

            if ($dryRun) {
                if ($exists) {
                    logInfo("Row $rowNum: Would update ID $id");
                    $updated++;
                } else {
                    logInfo("Row $rowNum: Would import ID $id");
                    $imported++;
                }
                continue;
            }

            // Perform actual insert or update
            if ($exists && $force) {
                // Update existing row
                $setClauses = [];
                $params = [':id' => $id];
                foreach ($data as $col => $value) {
                    if ($col === 'id') continue;
                    $setClauses[] = "$col = :$col";
                    $params[":$col"] = $value;
                }
                $sql = "UPDATE content SET " . implode(', ', $setClauses) . " WHERE id = :id";
                $db->query($sql, $params);
                logInfo("Row $rowNum: Updated ID $id");
                $updated++;
            } else {
                // Insert new row
                $columns = array_keys($data);
                $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
                $sql = "INSERT INTO content (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

                $params = [];
                foreach ($data as $col => $value) {
                    $params[":$col"] = $value;
                }

                $db->query($sql, $params);
                logInfo("Row $rowNum: Imported ID $id");
                $imported++;
            }

            // Queue for preview link generation (done after commit to avoid transaction issues)
            $contentForPreviewLinks[] = ['id' => $id, 'title' => $rowTitle, 'rowNum' => $rowNum];
        }

        if (!$dryRun) {
            $db->commit();
        }

        // Generate preview links AFTER commit (separate from main transaction)
        // This prevents PostgreSQL transaction abort cascade if preview generation fails
        if (!$dryRun && !empty($contentForPreviewLinks)) {
            logInfo("Generating preview links...");
            foreach ($contentForPreviewLinks as $content) {
                $previewUrl = generatePreviewLink($content['id'], $content['title'], $db, $config);
                if ($previewUrl) {
                    logInfo("Row {$content['rowNum']}: Generated preview link");
                    $previewsGenerated++;
                }
            }
        }

    } catch (Exception $e) {
        if (!$dryRun && $db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

    // Summary
    $modeLabel = $dryRun ? "(DRY RUN) " : "";
    $domainMsg = $domainsMapped > 0 ? ", $domainsMapped domains mapped" : "";
    $previewMsg = $previewsGenerated > 0 ? ", $previewsGenerated preview links generated" : "";
    logSuccess("{$modeLabel}Import complete: $imported imported, $updated updated, $skipped skipped, $errors errors{$domainMsg}{$previewMsg}");

    exit($errors > 0 ? 1 : 0);

} catch (Exception $e) {
    logError($e->getMessage());
    exit(1);
}
