<?php
/**
 * Script Runner API Endpoint
 *
 * Allows running maintenance scripts from the admin UI.
 * Only whitelisted scripts can be executed for security.
 *
 * Endpoints:
 *   GET  - List available scripts with their options
 *   POST - Execute a script with specified options
 */

require_once __DIR__ . '/bootstrap.php';

// Validate authentication
validateBearerToken($config);
// Validate VPN access (internal tool)
validateVpnAccess();

// Define available scripts with their metadata
$availableScripts = [
    'set-default-email-thumbnails' => [
        'file' => 'set-default-email-thumbnails.php',
        'name' => 'Set Default Email Thumbnails',
        'description' => 'Updates email content that has no thumbnail (or an old default) to use a default thumbnail image.',
        'options' => [
            'dry-run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without updating database',
                'default' => true
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed processing information',
                'default' => false
            ],
            'thumbnail' => [
                'type' => 'string',
                'description' => 'Custom thumbnail path (default: /images/email_default.png)',
                'default' => '/images/email_default.png',
                'placeholder' => '/images/email_default.png'
            ],
            'replace' => [
                'type' => 'string',
                'description' => 'Replace emails with this existing thumbnail path',
                'default' => '',
                'placeholder' => '/images/old_default.png'
            ]
        ]
    ],
    'cleanup-company-name-placeholders' => [
        'file' => 'cleanup-company-name-placeholders.php',
        'name' => 'Cleanup Company Name Placeholders',
        'description' => 'Finds and strips COMPANY_NAME placeholders from email content.',
        'options' => [
            'dry-run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without updating database',
                'default' => true
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed processing information',
                'default' => false
            ]
        ]
    ],
    'cleanup-domain-placeholders' => [
        'file' => 'cleanup-domain-placeholders.php',
        'name' => 'Cleanup Domain Placeholders',
        'description' => 'Cleans up TopLevelDomain and PhishingDomain placeholders in content.',
        'options' => [
            'dry-run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without updating database',
                'default' => true
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed processing information',
                'default' => false
            ]
        ]
    ],
    'cleanup-landing-placeholders' => [
        'file' => 'cleanup-landing-placeholders.php',
        'name' => 'Cleanup Landing Placeholders',
        'description' => 'Cleans up placeholders in landing page content.',
        'options' => [
            'dry-run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without updating database',
                'default' => true
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed processing information',
                'default' => false
            ]
        ]
    ],
    'count-legacy-placeholders' => [
        'file' => 'count-legacy-placeholders.php',
        'name' => 'Count Legacy Placeholders',
        'description' => 'Counts remaining legacy placeholders in all content types.',
        'options' => [
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed placeholder information',
                'default' => false
            ]
        ]
    ],
    'find-emails-without-phishing-links' => [
        'file' => 'find-emails-without-phishing-links.php',
        'name' => 'Find Emails Without Phishing Links',
        'description' => 'Identifies email templates that are missing phishing links.',
        'options' => [
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed information',
                'default' => false
            ]
        ]
    ],
    'fix-css-assets' => [
        'file' => 'fix-css-assets.php',
        'name' => 'Fix CSS Assets',
        'description' => 'Fixes CSS asset references in content.',
        'options' => [
            'dry-run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without updating',
                'default' => true
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed processing information',
                'default' => false
            ]
        ]
    ],
    'list-placeholders' => [
        'file' => 'list-placeholders.php',
        'name' => 'List Placeholders',
        'description' => 'Lists all placeholders found in content.',
        'options' => [
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Show detailed placeholder information',
                'default' => false
            ]
        ]
    ]
];

$scriptsDir = '/var/www/html/scripts/';

// Handle GET request - list available scripts
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scripts = [];
    foreach ($availableScripts as $id => $script) {
        $scripts[] = [
            'id' => $id,
            'name' => $script['name'],
            'description' => $script['description'],
            'options' => $script['options']
        ];
    }

    sendJSON([
        'success' => true,
        'scripts' => $scripts
    ]);
}

// Handle POST request - execute a script
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $scriptId = $input['script'] ?? null;
    $options = $input['options'] ?? [];

    if (!$scriptId) {
        sendJSON(['error' => 'Script ID is required'], 400);
    }

    if (!isset($availableScripts[$scriptId])) {
        sendJSON(['error' => 'Invalid script ID. Script not found or not allowed.'], 400);
    }

    $script = $availableScripts[$scriptId];
    $scriptPath = $scriptsDir . $script['file'];

    if (!file_exists($scriptPath)) {
        sendJSON(['error' => 'Script file not found on server'], 500);
    }

    // Build command with options
    $cmd = 'php ' . escapeshellarg($scriptPath);

    // Process options based on script definition
    foreach ($script['options'] as $optName => $optDef) {
        if (isset($options[$optName])) {
            $value = $options[$optName];

            if ($optDef['type'] === 'boolean') {
                if ($value === true || $value === 'true' || $value === '1') {
                    $cmd .= ' --' . $optName;
                }
            } elseif ($optDef['type'] === 'string') {
                if (!empty($value)) {
                    // Sanitize string value
                    $cmd .= ' --' . $optName . '=' . escapeshellarg($value);
                }
            }
        }
    }

    // Execute script and capture output
    $output = [];
    $returnCode = 0;

    // Set a reasonable timeout
    set_time_limit(300); // 5 minutes max

    exec($cmd . ' 2>&1', $output, $returnCode);

    $outputText = implode("\n", $output);

    sendJSON([
        'success' => $returnCode === 0,
        'script' => $scriptId,
        'script_name' => $script['name'],
        'return_code' => $returnCode,
        'output' => $outputText,
        'options_used' => $options
    ]);
}

// Other methods not allowed
sendJSON(['error' => 'Method not allowed'], 405);
