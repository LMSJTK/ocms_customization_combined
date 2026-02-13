<?php
/**
 * NIST Phish Scale Threat Taxonomy
 *
 * Single source of truth for phishing cue categories, cue definitions,
 * and difficulty levels. Used by:
 * - ClaudeAPI::tagPhishingEmail() for AI tagging prompts
 * - public/api/threat-taxonomy.php for frontend consumption
 */

class ThreatTaxonomy {

    /**
     * Get the full cue taxonomy with categories, colors, and cue definitions
     */
    public static function getCueTypes(): array {
        return [
            [
                'type' => 'Error',
                'color' => '#EF4444',
                'cues' => [
                    [
                        'name' => 'spelling-grammar',
                        'label' => 'Spelling & Grammar',
                        'criteria' => 'Does the message contain inaccurate spelling or grammar use, including mismatched plurality?'
                    ],
                    [
                        'name' => 'inconsistency',
                        'label' => 'Inconsistency',
                        'criteria' => 'Are there inconsistencies contained in the email message?'
                    ]
                ]
            ],
            [
                'type' => 'Technical indicator',
                'color' => '#F59E0B',
                'cues' => [
                    [
                        'name' => 'attachment-type',
                        'label' => 'Attachment Type',
                        'criteria' => 'Is there a potentially dangerous attachment?'
                    ],
                    [
                        'name' => 'display-name-email-mismatch',
                        'label' => 'Display Name / Email Mismatch',
                        'criteria' => 'Does a display name hide the real sender or reply-to email address?'
                    ],
                    [
                        'name' => 'url-hyperlinking',
                        'label' => 'URL Hyperlinking',
                        'criteria' => 'Is there text that hides the true URL behind the text?'
                    ],
                    [
                        'name' => 'domain-spoofing',
                        'label' => 'Domain Spoofing',
                        'criteria' => 'Is a domain name used in addresses or links plausibly similar to a legitimate entity\'s domain?'
                    ]
                ]
            ],
            [
                'type' => 'Visual presentation indicator',
                'color' => '#8B5CF6',
                'cues' => [
                    [
                        'name' => 'no-minimal-branding',
                        'label' => 'No / Minimal Branding',
                        'criteria' => 'Are appropriately branded labeling, symbols, or insignias missing?'
                    ],
                    [
                        'name' => 'logo-imitation-outdated',
                        'label' => 'Logo Imitation / Outdated',
                        'criteria' => 'Do any branding elements appear to be an imitation or out-of-date?'
                    ],
                    [
                        'name' => 'unprofessional-design',
                        'label' => 'Unprofessional Design',
                        'criteria' => 'Does the design and formatting violate any conventional professional practices? Do the design elements appear to be unprofessionally generated?'
                    ],
                    [
                        'name' => 'security-indicators-icons',
                        'label' => 'Security Indicators / Icons',
                        'criteria' => 'Are any markers, images, or logos that imply the security of the email present?'
                    ]
                ]
            ],
            [
                'type' => 'Language and content',
                'color' => '#3B82F6',
                'cues' => [
                    [
                        'name' => 'legal-language-disclaimers',
                        'label' => 'Legal Language & Disclaimers',
                        'criteria' => 'Does the message contain any legal-type language such as copyright information, disclaimers, or tax information?'
                    ],
                    [
                        'name' => 'distracting-detail',
                        'label' => 'Distracting Detail',
                        'criteria' => 'Does the email contain details that are superfluous or unrelated to the email\'s main premise?'
                    ],
                    [
                        'name' => 'requests-sensitive-info',
                        'label' => 'Requests Sensitive Info',
                        'criteria' => 'Does the message contain a request for any sensitive information, including personally identifying information or credentials?'
                    ],
                    [
                        'name' => 'sense-of-urgency',
                        'label' => 'Sense of Urgency',
                        'criteria' => 'Does the message contain time pressure to get users to quickly comply with the request, including implied pressure?'
                    ],
                    [
                        'name' => 'threatening-language',
                        'label' => 'Threatening Language',
                        'criteria' => 'Does the message contain a threat, including an implied threat, such as legal ramifications for inaction?'
                    ],
                    [
                        'name' => 'generic-greeting',
                        'label' => 'Generic Greeting',
                        'criteria' => 'Does the message lack a greeting or lack personalization in the message?'
                    ],
                    [
                        'name' => 'lack-signer-details',
                        'label' => 'Lack of Signer Details',
                        'criteria' => 'Does the message lack detail about the sender, such as contact information?'
                    ],
                    [
                        'name' => 'humanitarian-appeals',
                        'label' => 'Humanitarian Appeals',
                        'criteria' => 'Does the message make an appeal to help others in need?'
                    ]
                ]
            ],
            [
                'type' => 'Common tactic',
                'color' => '#10B981',
                'cues' => [
                    [
                        'name' => 'too-good-to-be-true',
                        'label' => 'Too Good to Be True',
                        'criteria' => 'Does the message offer anything that is too good to be true, such as winning a contest, lottery, free vacation and so on?'
                    ],
                    [
                        'name' => 'youre-special',
                        'label' => 'You\'re Special',
                        'criteria' => 'Does the email offer anything just for you, such as a valentine e-card from a secret admirer?'
                    ],
                    [
                        'name' => 'limited-time-offer',
                        'label' => 'Limited Time Offer',
                        'criteria' => 'Does the email offer anything that won\'t last long or for a limited length of time?'
                    ],
                    [
                        'name' => 'mimics-business-process',
                        'label' => 'Mimics Business Process',
                        'criteria' => 'Does the message appear to be a work or business-related process, such as a new voicemail, package delivery, order confirmation, notice to reset credentials and so on?'
                    ],
                    [
                        'name' => 'poses-as-authority',
                        'label' => 'Poses as Authority',
                        'criteria' => 'Does the message appear to be from a friend, colleague, boss or other authority entity?'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get difficulty level definitions
     */
    public static function getDifficultyLevels(): array {
        return [
            [
                'value' => 'least',
                'label' => 'Least Difficult',
                'description' => 'Multiple obvious red flags, amateur mistakes, very easy to detect'
            ],
            [
                'value' => 'moderately',
                'label' => 'Moderately Difficult',
                'description' => 'Some red flags but requires closer inspection, decent attempt'
            ],
            [
                'value' => 'very',
                'label' => 'Very Difficult',
                'description' => 'Sophisticated, few obvious indicators, requires expert knowledge to detect'
            ]
        ];
    }

    /**
     * Get cue types formatted for Claude API prompts (without colors/labels, matching the original JSON structure)
     */
    public static function getCueTypesForPrompt(): array {
        $cueTypes = self::getCueTypes();
        $promptTypes = [];

        foreach ($cueTypes as $category) {
            $cues = [];
            foreach ($category['cues'] as $cue) {
                $cues[] = [
                    'name' => $cue['name'],
                    'criteria' => $cue['criteria']
                ];
            }
            $promptTypes[] = [
                'type' => $category['type'],
                'cues' => $cues
            ];
        }

        return $promptTypes;
    }

    /**
     * Get a flat list of all valid cue names
     */
    public static function getAllCueNames(): array {
        $names = [];
        foreach (self::getCueTypes() as $category) {
            foreach ($category['cues'] as $cue) {
                $names[] = $cue['name'];
            }
        }
        return $names;
    }
}
