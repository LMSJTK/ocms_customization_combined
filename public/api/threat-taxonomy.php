<?php
/**
 * Threat Taxonomy API Endpoint
 *
 * GET /api/threat-taxonomy.php - Returns the full NIST Phish Scale cue taxonomy
 *
 * No authentication required (reference data).
 */

require_once '/var/www/html/public/api/bootstrap.php';
require_once '/var/www/html/lib/ThreatTaxonomy.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'error' => 'Method not allowed. Use GET.'], 405);
}

sendJSON([
    'success' => true,
    'taxonomy' => ThreatTaxonomy::getCueTypes(),
    'difficulty_levels' => ThreatTaxonomy::getDifficultyLevels()
]);
