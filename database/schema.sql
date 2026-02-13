-- Headless PHP Content Platform Database Schema
-- PostgreSQL Database Schema

-- =========================================================================
-- Core Tables
-- =========================================================================

-- Content table - stores all uploaded content (emails, education, landing, SCORM, video)
CREATE TABLE IF NOT EXISTS global.content
(
    id text PRIMARY KEY,
    company_id text,
    title text,
    description text,
    content_type text, -- 'scorm', 'html', 'raw_html', 'video', 'email', 'training', 'landing'
    content_preview text,
    content_url text, -- Path where content files are stored
    email_from_address text,
    email_subject text,
    email_body_html text,
    entry_body_html text, -- Processed entry point HTML with absolute URLs (for non-email content)
    email_attachment_filename text,
    email_attachment_content bytea,
    thumbnail_filename text,
    thumbnail_content bytea,
    languages text DEFAULT 'en', -- Language code(s) for this content
    legacy_id text, -- ID from legacy pm_* tables (for imported content)
    domain_id text, -- FK to domains table (optional, for phishing content)
    tags text, -- Comma-separated list of tags for quick display
    difficulty text, -- NIST Phish Scales difficulty score (1-5)
    content_domain text, -- Phishing domain for email content (from pm_phishing_domain)
    scorable boolean DEFAULT false, -- Whether content has scoring capability (RecordTest calls or quiz)
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

-- Content tags table - stores tags identified by Claude API
CREATE TABLE IF NOT EXISTS global.content_tags
(
    id text PRIMARY KEY, -- Generated as contentId + random hex bytes
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    tag_name text NOT NULL, -- e.g., 'ransomware', 'phishing', 'social-engineering'
    tag_type text, -- 'interaction', 'topic', 'phish-cue'
    confidence_score numeric(3,2), -- 0.00 to 1.00
    created_at timestamp DEFAULT now(),
    UNIQUE(content_id, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_content_tags_content_id ON global.content_tags(content_id);
CREATE INDEX IF NOT EXISTS idx_content_tags_tag_name ON global.content_tags(tag_name);

-- Recipient tag scores - cumulative scores per tag per recipient
CREATE TABLE IF NOT EXISTS global.recipient_tag_scores
(
    id SERIAL PRIMARY KEY,
    recipient_id text NOT NULL,
    tag_name text NOT NULL,
    score_count integer DEFAULT 0, -- Number of times they passed content with this tag
    total_attempts integer DEFAULT 0, -- Total attempts on content with this tag
    last_updated timestamp DEFAULT now(),
    UNIQUE(recipient_id, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_recipient_tag_scores_recipient_id ON global.recipient_tag_scores(recipient_id);

-- =========================================================================
-- Phishing Domains (referenced by upload.php, suggest-domain.php, bootstrap.php)
-- =========================================================================

CREATE TABLE IF NOT EXISTS global.domains
(
    id SERIAL PRIMARY KEY,
    domain_url text NOT NULL,
    name text NOT NULL,
    description text,
    is_active boolean DEFAULT true,
    created_at timestamp DEFAULT now()
);

-- Legacy phishing domain table (used by bootstrap.php CORS check, suggest-domain.php)
CREATE TABLE IF NOT EXISTS global.pm_phishing_domain
(
    id SERIAL PRIMARY KEY,
    tag text, -- Domain category tag (e.g., 'banking', 'social')
    domain text NOT NULL, -- Actual domain name
    is_hidden boolean DEFAULT false,
    created_at timestamp DEFAULT now()
);

-- Legacy email template table (used by launch.php for FROM_FRIENDLY_NAME lookup)
CREATE TABLE IF NOT EXISTS global.pm_email_template
(
    id text PRIMARY KEY,
    from_name text,
    from_address text,
    subject text,
    body text,
    body_type text,
    is_active boolean DEFAULT true,
    deleted_at timestamp,
    urgency text,
    language_code text,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

-- =========================================================================
-- Training & Tracking Tables (managed by external system in production)
-- =========================================================================

CREATE TABLE IF NOT EXISTS global.training
(
    id text PRIMARY KEY,
    company_id text,
    name text,
    description text,
    training_type text, -- 'preview', 'campaign', etc.
    landing_content_id text,
    training_content_id text,
    follow_on_content_id text,
    training_email_content_id text,
    status text DEFAULT 'active',
    scheduled_at timestamp,
    ends_at timestamp,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE TABLE IF NOT EXISTS global.training_tracking
(
    id text PRIMARY KEY,
    training_id text NOT NULL REFERENCES global.training(id) ON DELETE CASCADE,
    recipient_id text,
    recipient_email_address text,
    unique_tracking_id text UNIQUE,
    status text DEFAULT 'pending',
    url_clicked_at timestamp,
    training_viewed_at timestamp,
    training_completed_at timestamp,
    training_score integer,
    training_reported_at timestamp,
    follow_on_viewed_at timestamp,
    follow_on_completed_at timestamp,
    follow_on_score integer,
    data_entered_at timestamp,
    last_action_at timestamp,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_training_tracking_training_id ON global.training_tracking(training_id);
CREATE INDEX IF NOT EXISTS idx_training_tracking_unique_id ON global.training_tracking(unique_tracking_id);

-- Legacy tracking links table (kept for backwards compatibility)
CREATE TABLE IF NOT EXISTS global.oms_tracking_links
(
    id text PRIMARY KEY,
    recipient_id text NOT NULL,
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    launch_url text NOT NULL,
    status text DEFAULT 'pending', -- 'pending', 'viewed', 'completed', 'passed', 'failed'
    score integer,
    viewed_at timestamp,
    completed_at timestamp,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tracking_links_recipient_id ON global.oms_tracking_links(recipient_id);
CREATE INDEX IF NOT EXISTS idx_tracking_links_content_id ON global.oms_tracking_links(content_id);
CREATE INDEX IF NOT EXISTS idx_tracking_links_status ON global.oms_tracking_links(status);

-- Content interactions table - tracks individual tagged interactions
-- tracking_link_id references training_tracking.unique_tracking_id (no FK constraint
-- because training_tracking may be managed externally)
CREATE TABLE IF NOT EXISTS global.content_interactions
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL,
    tag_name text NOT NULL,
    interaction_type text, -- 'click', 'input', 'submit', 'focus', 'blur'
    interaction_value text, -- The value if applicable (e.g., input value)
    success boolean, -- Whether the interaction was correct/successful
    interaction_data jsonb, -- Additional metadata about the interaction
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_content_interactions_tracking_link_id ON global.content_interactions(tracking_link_id);
CREATE INDEX IF NOT EXISTS idx_content_interactions_tag_name ON global.content_interactions(tag_name);

-- SNS message queue table - temporary storage before sending to SNS
CREATE TABLE IF NOT EXISTS global.sns_message_queue
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL,
    message_data jsonb NOT NULL,
    sent boolean DEFAULT false,
    sent_at timestamp,
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sns_queue_tracking_link_id ON global.sns_message_queue(tracking_link_id);
CREATE INDEX IF NOT EXISTS idx_sns_queue_sent ON global.sns_message_queue(sent);

-- =========================================================================
-- Customization Tables
-- =========================================================================

-- Brand kits - stores per-company brand assets (logo, fonts, colors)
CREATE TABLE IF NOT EXISTS global.brand_kits
(
    id text PRIMARY KEY,
    company_id text NOT NULL,
    name text NOT NULL DEFAULT 'Default',
    logo_url text,             -- S3 URL for uploaded logo
    logo_filename text,        -- Original filename for display
    primary_color text,        -- Hex color, e.g. '#4F46E5'
    secondary_color text,
    accent_color text,
    saved_colors jsonb DEFAULT '[]'::jsonb,  -- Array of additional hex colors
    primary_font text,         -- Font family name, e.g. 'Inter'
    secondary_font text,
    custom_font_urls jsonb DEFAULT '[]'::jsonb, -- Array of S3 URLs for uploaded font files
    is_default boolean DEFAULT false,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now(),
    UNIQUE(company_id, name)
);

CREATE INDEX IF NOT EXISTS idx_brand_kits_company_id ON global.brand_kits(company_id);

-- Brand kit assets - stores uploaded files (logos, fonts) metadata
CREATE TABLE IF NOT EXISTS global.brand_kit_assets
(
    id text PRIMARY KEY,
    brand_kit_id text NOT NULL REFERENCES global.brand_kits(id) ON DELETE CASCADE,
    asset_type text NOT NULL,  -- 'logo', 'font', 'icon'
    filename text NOT NULL,
    s3_url text NOT NULL,
    mime_type text,
    file_size integer,
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_brand_kit_assets_brand_kit_id ON global.brand_kit_assets(brand_kit_id);

-- Content customizations - stores customized versions of base content templates
CREATE TABLE IF NOT EXISTS global.content_customizations
(
    id text PRIMARY KEY,
    company_id text NOT NULL,
    base_content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    brand_kit_id text REFERENCES global.brand_kits(id) ON DELETE SET NULL,
    title text,                -- Customized title (falls back to base content title)
    customized_html text,      -- The modified HTML after edits (served instead of entry_body_html)
    customization_data jsonb,  -- Structured record of edits: element selectors → style overrides
    status text DEFAULT 'draft', -- 'draft', 'published'
    created_by text,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_content_customizations_company_id ON global.content_customizations(company_id);
CREATE INDEX IF NOT EXISTS idx_content_customizations_base_content ON global.content_customizations(base_content_id);
CREATE INDEX IF NOT EXISTS idx_content_customizations_status ON global.content_customizations(status);
CREATE INDEX IF NOT EXISTS idx_content_customizations_company_content ON global.content_customizations(company_id, base_content_id);

-- Content translations - links translated content to source content
CREATE TABLE IF NOT EXISTS global.content_translations
(
    id text PRIMARY KEY,
    source_content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    translated_content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    source_language text NOT NULL DEFAULT 'en',
    target_language text NOT NULL,
    created_at timestamp DEFAULT now(),
    UNIQUE(source_content_id, target_language)
);

-- =========================================================================
-- Triggers
-- =========================================================================

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Triggers for updated_at
CREATE TRIGGER update_content_updated_at BEFORE UPDATE ON global.content
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tracking_links_updated_at BEFORE UPDATE ON global.oms_tracking_links
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_training_updated_at BEFORE UPDATE ON global.training
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_training_tracking_updated_at BEFORE UPDATE ON global.training_tracking
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_brand_kits_updated_at BEFORE UPDATE ON global.brand_kits
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_content_customizations_updated_at BEFORE UPDATE ON global.content_customizations
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
