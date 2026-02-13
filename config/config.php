<?php
/**
 * Configuration file for Headless PHP Content Platform
 * Copy this file to config.php and fill in your actual credentials
 */

 return [
    // Database Configuration
    'database' => [
        'type' => 'pgsql', // 'pgsql' for PostgreSQL or 'mysql' for MySQL
        'host' => $_ENV['aurora_aurora_oneviewservice_host'],
        'port' => $_ENV['aurora_aurora_oneviewservice_port'],
        'dbname' => $_ENV['aurora_aurora_oneviewservice_db'],
        'username' => $_ENV['aurora_aurora_oneviewservice_user'],
        'password' => $_ENV['aurora_aurora_oneviewservice_pass'],
        'schema' => 'global'
    ],

    // Claude API Configuration
    'claude' => [
        'api_key' => '',
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'model' => 'claude-sonnet-4-5',
        'max_tokens' => 64000 // Increased to handle large educational content without truncation
    ],

    // AWS SNS Configuration
    'aws_sns' => [
        'region' => $_ENV['AWS_REGION'],
        'access_key_id' => $_ENV['AWS_ACCESS_KEY_ID'],
        'secret_access_key' => $_ENV['AWS_SECRET_ACCESS_KEY'],
        'topic_arn' => $_ENV['sns_topic_ocmsworker_arn']
    ],

    // AWS S3 Configuration for Content Storage
    's3' => [
        'enabled' => filter_var($_ENV['S3_CONTENT_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
        'bucket' => $_ENV['S3_CONTENT_BUCKET'] ?? '',
        'access_key_id' => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
        'secret_access_key' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
        'prefix' => $_ENV['S3_CONTENT_PREFIX'] ?? 'content/', // Prefix for content objects in bucket
        'cdn_url' => $_ENV['S3_CDN_URL'] ?? '' // Optional CloudFront CDN URL
    ],

    // Content Storage
    'content' => [
        'upload_dir' => '/var/www/html/content/',
        'max_upload_size' => 100 * 1024 * 1024, // 100MB
        'allowed_types' => [
            'scorm' => ['zip'],
            'html' => ['zip'],
            'video' => ['mp4', 'webm', 'ogg'],
            'training' => ['html']
        ]
    ],

    // Application Settings
    'app' => [
        'base_url' => $_ENV['MESSAGEHUB_ENDPOINTS_OCMSSERVICE'] ?? 'https://ecs-ocmsservice.cfp-dev.cofense-dev.com',
        'external_url' => (($_ENV['MESSAGEHUB_ENDPOINTS_UI_EXTERNAL'] ?? 'https://platform-dev.cofense-dev.com') . '/ocms-service'),
        'debug' => true,
        'timezone' => 'UTC'
    ],
    
    // API Authentication
    'api' => [
        'bearer_token' => '', // Get yourself a secure random token
        'enabled' => true // Set to false to disable authentication (not recommended for production)
    ],

    // default passing score
    'scorm' => [
        'passing_score' => 80
    ]
];
