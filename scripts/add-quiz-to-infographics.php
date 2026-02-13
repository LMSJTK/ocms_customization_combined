#!/usr/bin/env php
<?php
/**
 * Add Quiz Questions to Infographic Content
 *
 * Finds English infographic content that doesn't have quizzes yet
 * and generates quiz questions using Claude API.
 *
 * Usage:
 *   php scripts/add-quiz-to-infographics.php [options]
 *
 * Options:
 *   --num-questions=N     Number of questions to generate (2-5, default 3)
 *   --additional-prompt="..." Extra instructions for quiz generation
 *   --dry-run             Show what would be done without making changes
 *   --verbose             Show detailed progress information
 *   --quiet               Only show errors and final summary
 *   --help                Show this help message
 *
 * Examples:
 *   php scripts/add-quiz-to-infographics.php --dry-run --verbose
 *   php scripts/add-quiz-to-infographics.php --num-questions=5
 *   php scripts/add-quiz-to-infographics.php --additional-prompt="Focus on practical security actions"
 *
 * Exit codes:
 *   0 - Success
 *   1 - Error (configuration, database, API, etc.)
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// Parse command line arguments
$options = getopt('', [
    'num-questions:',
    'additional-prompt:',
    'dry-run',
    'verbose',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Add Quiz Questions to Infographic Content

Finds English content with "infographic" in the title that doesn't have quizzes
yet and generates quiz questions using Claude API.

Usage:
  php scripts/add-quiz-to-infographics.php [options]

Options:
  --num-questions=N     Number of questions to generate (2-5, default 3)
  --additional-prompt="..." Extra instructions for quiz generation
  --dry-run             Show what would be done without making changes
  --verbose             Show detailed progress information
  --quiet               Only show errors and final summary
  --help                Show this help message

Examples:
  php scripts/add-quiz-to-infographics.php --dry-run --verbose
  php scripts/add-quiz-to-infographics.php --num-questions=5
  php scripts/add-quiz-to-infographics.php --additional-prompt="Focus on practical security actions"

Exit codes:
  0 - Success
  1 - Error (configuration, database, API, etc.)

HELP;
    exit(0);
}

$numQuestions = isset($options['num-questions']) ? (int)$options['num-questions'] : 3;
$numQuestions = max(2, min(5, $numQuestions)); // Clamp to 2-5
$additionalPrompt = $options['additional-prompt'] ?? null;
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

function logInfo($message) {
    global $quiet;
    if (!$quiet) {
        echo "[INFO] $message\n";
    }
}

function logVerbose($message) {
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        echo "  $message\n";
    }
}

function logError($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

function logSuccess($message) {
    global $quiet;
    if (!$quiet) {
        echo "[OK] $message\n";
    }
}

/**
 * Check if content already has a quiz
 */
function hasQuiz($htmlContent) {
    // Check for OCMS quiz section marker
    return stripos($htmlContent, 'ocms-quiz-section') !== false ||
           stripos($htmlContent, 'OCMS Auto-Generated Quiz Section') !== false;
}

// Header
if (!$quiet) {
    echo "=== Add Quiz Questions to Infographic Content ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n";
    }
    echo "Questions per content: $numQuestions\n";
    if ($additionalPrompt) {
        echo "Additional prompt: $additionalPrompt\n";
    }
    echo "\n";
}

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

// Load required classes
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/ClaudeAPI.php';

try {
    $db = Database::getInstance($config['database']);
    logInfo("Connected to database");
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    exit(1);
}

// Check if Claude API is configured
if (empty($config['claude']['api_key'])) {
    logError("Claude API key not configured. Please set claude.api_key in config.");
    exit(1);
}

$claudeAPI = new ClaudeAPI($config['claude']);
logInfo("Claude API initialized");

// Build upload directory path
$uploadDir = $config['content']['upload_dir'] ?? '/var/www/html/content/';

// Step 1: Find English infographic content
logInfo("Searching for English infographic content...\n");

// Query for content with "infographic" in title and English-only language
// Using ILIKE for case-insensitive search (PostgreSQL)
$query = "SELECT id, title, content_type, content_url, languages
          FROM content
          WHERE LOWER(title) LIKE '%infographic%'
          AND languages = 'en'
          ORDER BY title";

try {
    $contentRows = $db->fetchAll($query);
} catch (Exception $e) {
    logError("Failed to query content: " . $e->getMessage());
    exit(1);
}

$totalFound = count($contentRows);
logInfo("Found $totalFound infographic content items in English");

if ($totalFound === 0) {
    logInfo("No matching content found. Exiting.");
    exit(0);
}

// Step 2: Filter out content that already has quizzes
$candidates = [];
$alreadyHasQuiz = [];
$noFile = [];
$notFileBased = [];

foreach ($contentRows as $row) {
    $id = $row['id'];
    $title = $row['title'];
    $contentType = $row['content_type'];
    $contentUrl = $row['content_url'];

    // Skip email content (not file-based in the same way)
    if ($contentType === 'email') {
        $notFileBased[] = $row;
        logVerbose("Skipping email content: " . substr($title, 0, 60));
        continue;
    }

    // Check if content file exists
    if (empty($contentUrl)) {
        $noFile[] = $row;
        logVerbose("No content_url: " . substr($title, 0, 60));
        continue;
    }

    $contentPath = $uploadDir . $contentUrl;
    if (!file_exists($contentPath)) {
        $noFile[] = $row;
        logVerbose("File not found: " . substr($title, 0, 60) . " ($contentUrl)");
        continue;
    }

    // Read content to check for existing quiz
    $htmlContent = file_get_contents($contentPath);
    if ($htmlContent === false) {
        $noFile[] = $row;
        logVerbose("Could not read file: " . substr($title, 0, 60));
        continue;
    }

    if (hasQuiz($htmlContent)) {
        $alreadyHasQuiz[] = $row;
        logVerbose("Already has quiz: " . substr($title, 0, 60));
        continue;
    }

    // This content is a candidate for quiz generation
    $row['content_path'] = $contentPath;
    $row['html_content'] = $htmlContent;
    $candidates[] = $row;
}

// Summary of filtering
logInfo("\nFiltering results:");
logInfo("  Candidates for quiz generation: " . count($candidates));
logInfo("  Already have quizzes: " . count($alreadyHasQuiz));
logInfo("  Missing/unreadable files: " . count($noFile));
logInfo("  Email content (skipped): " . count($notFileBased));

if (count($candidates) === 0) {
    logInfo("\nNo content needs quiz generation. Exiting.");
    exit(0);
}

// In dry-run mode, list what would be processed
if ($dryRun) {
    echo "\n=== DRY RUN: Content that would have quizzes added ===\n";
    foreach ($candidates as $row) {
        echo "\n  [{$row['content_type']}] {$row['title']}\n";
        echo "    ID: {$row['id']}\n";
        echo "    File: {$row['content_url']}\n";
        echo "    Would add: $numQuestions questions\n";
    }

    if (count($alreadyHasQuiz) > 0) {
        echo "\n=== Content that already has quizzes (would be skipped) ===\n";
        foreach ($alreadyHasQuiz as $row) {
            echo "  [{$row['content_type']}] {$row['title']}\n";
        }
    }

    echo "\n";
    logInfo("DRY RUN complete. No changes were made.");
    exit(0);
}

// Step 3: Generate and inject quizzes
echo "\n=== Generating Quiz Questions ===\n";

$success = 0;
$failed = 0;

foreach ($candidates as $index => $row) {
    $id = $row['id'];
    $title = $row['title'];
    $contentPath = $row['content_path'];
    $htmlContent = $row['html_content'];
    $contentDir = dirname($contentPath);

    $progress = ($index + 1) . "/" . count($candidates);
    echo "\n[$progress] Processing: " . substr($title, 0, 60) . "...\n";

    try {
        // Generate quiz questions
        logVerbose("Generating $numQuestions questions...");

        if ($additionalPrompt) {
            logVerbose("With additional instructions: $additionalPrompt");
        }

        $quizResult = $claudeAPI->generateQuizQuestions($htmlContent, $numQuestions, $contentDir, $additionalPrompt);

        if (empty($quizResult['quiz_html'])) {
            throw new Exception("Empty quiz HTML returned");
        }

        logVerbose("Generated " . $quizResult['num_questions'] . " questions");

        // Inject quiz into content
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

        // Write modified content back to file
        if (file_put_contents($contentPath, $modifiedHtml) === false) {
            throw new Exception("Failed to write modified content to file");
        }

        // Update scorable flag in database
        $db->execute(
            "UPDATE content SET scorable = true WHERE id = ?",
            [$id]
        );

        $success++;
        logSuccess("Added quiz to: " . substr($title, 0, 50));

    } catch (Exception $e) {
        $failed++;
        logError("Failed to process '$title': " . $e->getMessage());
    }

    // Small delay between API calls to avoid rate limiting
    if ($index < count($candidates) - 1) {
        usleep(500000); // 0.5 second delay
    }
}

// Final summary
echo "\n=== Summary ===\n";
logInfo("Total candidates: " . count($candidates));
logInfo("Successfully added quizzes: $success");
logInfo("Failed: $failed");
logInfo("Already had quizzes: " . count($alreadyHasQuiz));
logInfo("Completed: " . date('Y-m-d H:i:s'));

exit($failed > 0 ? 1 : 0);
