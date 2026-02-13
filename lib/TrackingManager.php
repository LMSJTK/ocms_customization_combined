<?php
/**
 * Tracking Manager Class
 * Handles interaction tracking, score recording, and SNS publishing
 *
 * Tracking state is determined by datetime fields rather than status flags:
 * - training_viewed_at: User viewed training content
 * - training_completed_at: User completed training
 * - training_reported_at: User reported email as phishing
 * - follow_on_viewed_at: User viewed follow-on content
 * - follow_on_completed_at: User completed follow-on training
 * - data_entered_at: User entered data in phishing form
 * - url_clicked_at: User clicked the link
 */

class TrackingManager {
    private $db;
    private $sns;

    public function __construct($db, $sns) {
        $this->db = $db;
        $this->sns = $sns;
    }

    /**
     * Validate external tracking session from training_tracking table
     * This supports the direct-link approach where external system generates tracking IDs
     *
     * @param string $trackingId The unique_tracking_id from training_tracking table
     * @return array|null Session data if valid, null otherwise
     */
    public function validateTrainingSession($trackingId) {
        try {
            $session = $this->db->fetchOne(
                'SELECT * FROM ' . ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking
                 WHERE unique_tracking_id = :id',
                [':id' => $trackingId]
            );

            if (!$session) {
                error_log("Invalid tracking session: $trackingId");
                return null;
            }

            return $session;
        } catch (Exception $e) {
            error_log("Error validating training session: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Track content view/click using training_tracking
     * Sets url_clicked_at, training_viewed_at, last_action_at
     * For non-scorable content, also auto-completes with training_completed_at
     */
    public function trackView($trackingLinkId, $contentId = null, $recipientId = null) {
        // Validate that tracking session exists in training_tracking
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Check if training has ended
        if ($this->isTrainingEnded($session['training_id'])) {
            return ['success' => false, 'reason' => 'training_ended'];
        }

        // Get recipient_id from session if not provided
        if (!$recipientId) {
            $recipientId = $session['recipient_id'];
        }

        $now = date('Y-m-d H:i:s');
        $tablePrefix = $this->db->getDbType() === 'pgsql' ? 'global.' : '';

        // Check if training content is non-scorable (auto-complete if so)
        $isNonScorable = false;
        try {
            $training = $this->db->fetchOne(
                "SELECT t.training_content_id, c.scorable
                 FROM {$tablePrefix}training t
                 LEFT JOIN {$tablePrefix}content c ON c.id = t.training_content_id
                 WHERE t.id = :training_id",
                [':training_id' => $session['training_id']]
            );

            if ($training && isset($training['scorable'])) {
                // Handle both boolean and string representations
                $scorable = $training['scorable'];
                $isNonScorable = ($scorable === false || $scorable === 'f' || $scorable === '0' || $scorable === 0);
            }
        } catch (Exception $e) {
            error_log("Could not check content scorable status: " . $e->getMessage());
        }

        // Build update data
        $updateData = [
            'url_clicked_at' => $now,
            'training_viewed_at' => $now,
            'last_action_at' => $now,
            'updated_at' => $now
        ];

        // For non-scorable content, auto-complete (but don't set a score)
        if ($isNonScorable) {
            $updateData['training_completed_at'] = $now;
            error_log("Auto-completing non-scorable training for tracking ID: $trackingLinkId");
        }

        // Update training_tracking with click/view timestamps
        // Set both url_clicked_at and training_viewed_at for compatibility with external systems
        try {
            $this->db->update(
                $tablePrefix . 'training_tracking',
                $updateData,
                'unique_tracking_id = :id AND url_clicked_at IS NULL',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking: " . $e->getMessage());
        }

        return ['success' => true, 'auto_completed' => $isNonScorable];
    }

    /**
     * Track follow-on content view
     * Sets follow_on_viewed_at and last_action_at
     */
    public function trackFollowOnView($trackingLinkId) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Check if training has ended
        if ($this->isTrainingEnded($session['training_id'])) {
            return ['success' => false, 'reason' => 'training_ended'];
        }

        $now = date('Y-m-d H:i:s');

        // Update training_tracking with follow-on view timestamp
        try {
            $this->db->update(
                ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
                [
                    'follow_on_viewed_at' => $now,
                    'last_action_at' => $now,
                    'updated_at' => $now
                ],
                'unique_tracking_id = :id AND follow_on_viewed_at IS NULL',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking for follow-on view: " . $e->getMessage());
        }

        return ['success' => true];
    }

    /**
     * Report phishing - user reported the email as phishing
     * Sets training_reported_at and last_action_at
     */
    public function reportPhishing($trackingLinkId) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Check if training has ended
        if ($this->isTrainingEnded($session['training_id'])) {
            error_log("reportPhishing: Training has ended for trackingId=$trackingLinkId");
            return ['success' => false, 'reason' => 'training_ended'];
        }

        $now = date('Y-m-d H:i:s');
        $tablePrefix = $this->db->getDbType() === 'pgsql' ? 'global.' : '';

        // Update training_tracking with reported timestamp
        try {
            error_log("reportPhishing: Updating training_tracking for trackingId=$trackingLinkId, setting training_reported_at=$now");

            $this->db->update(
                $tablePrefix . 'training_tracking',
                [
                    'training_reported_at' => $now,
                    'last_action_at' => $now,
                    'updated_at' => $now
                ],
                'unique_tracking_id = :id',
                [':id' => $trackingLinkId]
            );

            error_log("reportPhishing: Update completed successfully for trackingId=$trackingLinkId");
        } catch (Exception $e) {
            error_log("reportPhishing: Could not update training_tracking for report: " . $e->getMessage());
            throw $e;
        }

        return [
            'success' => true,
            'message' => 'Phishing report recorded',
            'recipient_id' => $session['recipient_id']
        ];
    }

    /**
     * Track data entry - user entered data in a phishing form
     * Sets data_entered_at and last_action_at
     */
    public function trackDataEntry($trackingLinkId) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Check if training has ended
        if ($this->isTrainingEnded($session['training_id'])) {
            return ['success' => false, 'reason' => 'training_ended'];
        }

        $now = date('Y-m-d H:i:s');

        // Update training_tracking with data entry timestamp
        try {
            $this->db->update(
                ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
                [
                    'data_entered_at' => $now,
                    'last_action_at' => $now,
                    'updated_at' => $now
                ],
                'unique_tracking_id = :id AND data_entered_at IS NULL',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking for data entry: " . $e->getMessage());
        }

        return ['success' => true];
    }

    /**
     * Track interaction with tagged element using training_tracking
     */
    public function trackInteraction($trackingLinkId, $tagName, $interactionType, $interactionValue = null, $success = null) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Check if training has ended
        if ($this->isTrainingEnded($session['training_id'])) {
            return ['success' => false, 'reason' => 'training_ended'];
        }

        // Insert interaction record
        $this->db->insert('content_interactions', [
            'tracking_link_id' => $trackingLinkId,
            'tag_name' => $tagName,
            'interaction_type' => $interactionType,
            'interaction_value' => $interactionValue,
            'success' => $success,
            'interaction_data' => json_encode([
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
            ])
        ]);

        return ['success' => true];
    }

    /**
     * Record test score using training_tracking
     * Determines whether to use training_score or follow_on_score based on content
     *
     * For training: Sets training_completed_at, last_action_at, training_score
     * For follow-on: Sets follow_on_completed_at, last_action_at, follow_on_score
     */
    public function recordScore($trackingLinkId, $score, $interactions = [], $contentId = null) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        $recipientId = $session['recipient_id'];
        $now = date('Y-m-d H:i:s');

        // Get the training record to determine which content this is and check end date
        $training = $this->db->fetchOne(
            'SELECT training_content_id, follow_on_content_id, ends_at FROM ' .
            ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training WHERE id = :training_id',
            [':training_id' => $session['training_id']]
        );

        if (!$training) {
            throw new Exception("Training record not found");
        }

        // Check if training has ended
        if (!empty($training['ends_at']) && time() > strtotime($training['ends_at'])) {
            return ['success' => false, 'reason' => 'training_ended'];
        }

        // Determine if this is training or follow-on content
        $isFollowOn = ($contentId && $contentId === $training['follow_on_content_id']);

        // Check if score already exists - only record FIRST non-zero score for competency messaging
        // Learners cannot change their score by re-taking training
        // Exception: Allow overwriting a score of 0 (handles SCORM packages that init with 0)
        if ($isFollowOn) {
            $existingScore = $session['follow_on_score'] ?? null;
        } else {
            $existingScore = $session['training_score'] ?? null;
        }

        // Block if: score exists AND is not empty AND (is non-zero OR new score is also 0)
        // Allow if: no existing score, or existing score is 0 and new score is > 0
        $hasExistingScore = ($existingScore !== null && $existingScore !== '');
        $existingIsZero = $hasExistingScore && (int)$existingScore === 0;
        $newIsNonZero = (int)$score > 0;

        if ($hasExistingScore && !($existingIsZero && $newIsNonZero)) {
            error_log("Score already recorded for tracking $trackingLinkId (existing: $existingScore, attempted: $score) - ignoring re-take");
            return [
                'success' => true,
                'score' => (int)$existingScore,
                'content_type' => $isFollowOn ? 'follow_on' : 'training',
                'already_recorded' => true
            ];
        }

        if ($existingIsZero && $newIsNonZero) {
            error_log("Overwriting initial score of 0 with real score $score for tracking $trackingLinkId");
        }

        // Build update data based on whether it's training or follow-on
        if ($isFollowOn) {
            // This is follow-on content
            $updateData = [
                'follow_on_score' => $score,
                'follow_on_completed_at' => $now,
                'last_action_at' => $now,
                'updated_at' => $now
            ];
            $contentType = 'follow_on';
        } else {
            // This is training content
            $updateData = [
                'training_score' => $score,
                'training_completed_at' => $now,
                'last_action_at' => $now,
                'updated_at' => $now
            ];
            $contentType = 'training';
        }

        // Update training_tracking with score and completion
        try {
            $this->db->update(
                ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
                $updateData,
                'unique_tracking_id = :id',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking: " . $e->getMessage());
        }

        // If passed (score >= 80), increment tag scores for recipient
        $passed = $score >= 80;
        if ($passed && $contentId) {
            $this->updateRecipientTagScores($recipientId, $contentId);
        }

        return [
            'success' => true,
            'score' => $score,
            'content_type' => $contentType
        ];
    }

    /**
     * Update recipient tag scores
     */
    private function updateRecipientTagScores($recipientId, $contentId) {
        // Get content tags
        $tags = $this->db->fetchAll(
            'SELECT DISTINCT tag_name FROM content_tags WHERE content_id = :content_id',
            [':content_id' => $contentId]
        );

        foreach ($tags as $tag) {
            $tagName = $tag['tag_name'];

            // Check if record exists
            $existing = $this->db->fetchOne(
                'SELECT * FROM recipient_tag_scores WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                [':recipient_id' => $recipientId, ':tag_name' => $tagName]
            );

            if ($existing) {
                // Update existing record
                $this->db->query(
                    'UPDATE recipient_tag_scores SET score_count = score_count + 1, total_attempts = total_attempts + 1, last_updated = NOW() WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                    [':recipient_id' => $recipientId, ':tag_name' => $tagName]
                );
            } else {
                // Insert new record
                $this->db->insert('recipient_tag_scores', [
                    'recipient_id' => $recipientId,
                    'tag_name' => $tagName,
                    'score_count' => 1,
                    'total_attempts' => 1
                ]);
            }
        }
    }

    /**
     * Generate unique ID
     */
    private function generateUniqueId() {
        return generateUUID4();
    }

    /**
     * Check if a training has ended based on its ends_at field
     * Returns true if training has an ends_at date that is in the past
     *
     * @param string $trainingId The training ID to check
     * @return bool True if training has ended, false otherwise
     */
    private function isTrainingEnded($trainingId) {
        try {
            $training = $this->db->fetchOne(
                'SELECT ends_at FROM ' . ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training WHERE id = :id',
                [':id' => $trainingId]
            );

            if (!$training || empty($training['ends_at'])) {
                // No end date set, training is still active
                return false;
            }

            // Compare ends_at with current time
            $endsAt = strtotime($training['ends_at']);
            $now = time();

            return $now > $endsAt;
        } catch (Exception $e) {
            error_log("Error checking training end date: " . $e->getMessage());
            // On error, allow the interaction (fail open)
            return false;
        }
    }
}
