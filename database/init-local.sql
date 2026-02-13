-- Local development database initialization
-- Creates the 'global' schema and all tables needed to run OCMS locally.
-- Includes training/training_tracking tables that are normally managed by
-- an external system in production.

CREATE SCHEMA IF NOT EXISTS global;

-- =========================================================================
-- Core content tables (from schema.sql)
-- =========================================================================

CREATE TABLE IF NOT EXISTS global.content
(
    id text PRIMARY KEY,
    company_id text,
    title text,
    description text,
    content_type text,
    content_preview text,
    content_url text,
    email_from_address text,
    email_subject text,
    email_body_html text,
    entry_body_html text,
    email_attachment_filename text,
    email_attachment_content bytea,
    thumbnail_filename text,
    thumbnail_content bytea,
    languages text DEFAULT 'en',
    legacy_id text,
    tags text,
    difficulty text,
    content_domain text,
    scorable boolean DEFAULT false,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE TABLE IF NOT EXISTS global.content_tags
(
    id SERIAL PRIMARY KEY,
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    tag_name text NOT NULL,
    tag_type text,
    confidence_score numeric(3,2),
    created_at timestamp DEFAULT now(),
    UNIQUE(content_id, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_content_tags_content_id ON global.content_tags(content_id);
CREATE INDEX IF NOT EXISTS idx_content_tags_tag_name ON global.content_tags(tag_name);

CREATE TABLE IF NOT EXISTS global.recipient_tag_scores
(
    id SERIAL PRIMARY KEY,
    recipient_id text NOT NULL,
    tag_name text NOT NULL,
    score_count integer DEFAULT 0,
    total_attempts integer DEFAULT 0,
    last_updated timestamp DEFAULT now(),
    UNIQUE(recipient_id, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_recipient_tag_scores_recipient_id ON global.recipient_tag_scores(recipient_id);

CREATE TABLE IF NOT EXISTS global.oms_tracking_links
(
    id text PRIMARY KEY,
    recipient_id text NOT NULL,
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    launch_url text NOT NULL,
    status text DEFAULT 'pending',
    score integer,
    viewed_at timestamp,
    completed_at timestamp,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tracking_links_recipient_id ON global.oms_tracking_links(recipient_id);
CREATE INDEX IF NOT EXISTS idx_tracking_links_content_id ON global.oms_tracking_links(content_id);
CREATE INDEX IF NOT EXISTS idx_tracking_links_status ON global.oms_tracking_links(status);

CREATE TABLE IF NOT EXISTS global.content_interactions
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL REFERENCES global.oms_tracking_links(id) ON DELETE CASCADE,
    tag_name text NOT NULL,
    interaction_type text,
    interaction_value text,
    success boolean,
    interaction_data jsonb,
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_content_interactions_tracking_link_id ON global.content_interactions(tracking_link_id);
CREATE INDEX IF NOT EXISTS idx_content_interactions_tag_name ON global.content_interactions(tag_name);

CREATE TABLE IF NOT EXISTS global.sns_message_queue
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL REFERENCES global.oms_tracking_links(id) ON DELETE CASCADE,
    message_data jsonb NOT NULL,
    sent boolean DEFAULT false,
    sent_at timestamp,
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sns_queue_tracking_link_id ON global.sns_message_queue(tracking_link_id);
CREATE INDEX IF NOT EXISTS idx_sns_queue_sent ON global.sns_message_queue(sent);

-- =========================================================================
-- Training tables (normally managed by external system)
-- Stubbed here for local development so launch.php and preview links work.
-- =========================================================================

CREATE TABLE IF NOT EXISTS global.training
(
    id text PRIMARY KEY,
    company_id text,
    name text,
    description text,
    training_type text,
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

-- =========================================================================
-- Customization tables
-- =========================================================================

CREATE TABLE IF NOT EXISTS global.brand_kits
(
    id text PRIMARY KEY,
    company_id text NOT NULL,
    name text NOT NULL DEFAULT 'Default',
    logo_url text,
    logo_filename text,
    primary_color text,
    secondary_color text,
    accent_color text,
    saved_colors jsonb DEFAULT '[]'::jsonb,
    primary_font text,
    secondary_font text,
    custom_font_urls jsonb DEFAULT '[]'::jsonb,
    is_default boolean DEFAULT false,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now(),
    UNIQUE(company_id, name)
);

CREATE INDEX IF NOT EXISTS idx_brand_kits_company_id ON global.brand_kits(company_id);

CREATE TABLE IF NOT EXISTS global.brand_kit_assets
(
    id text PRIMARY KEY,
    brand_kit_id text NOT NULL REFERENCES global.brand_kits(id) ON DELETE CASCADE,
    asset_type text NOT NULL,
    filename text NOT NULL,
    s3_url text NOT NULL,
    mime_type text,
    file_size integer,
    created_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_brand_kit_assets_brand_kit_id ON global.brand_kit_assets(brand_kit_id);

CREATE TABLE IF NOT EXISTS global.content_customizations
(
    id text PRIMARY KEY,
    company_id text NOT NULL,
    base_content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    brand_kit_id text REFERENCES global.brand_kits(id) ON DELETE SET NULL,
    title text,
    customized_html text,
    customization_data jsonb,
    status text DEFAULT 'draft',
    created_by text,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_content_customizations_company_id ON global.content_customizations(company_id);
CREATE INDEX IF NOT EXISTS idx_content_customizations_base_content ON global.content_customizations(base_content_id);
CREATE INDEX IF NOT EXISTS idx_content_customizations_status ON global.content_customizations(status);
CREATE INDEX IF NOT EXISTS idx_content_customizations_company_content ON global.content_customizations(company_id, base_content_id);

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

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'update_content_updated_at') THEN
        CREATE TRIGGER update_content_updated_at BEFORE UPDATE ON global.content
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'update_training_updated_at') THEN
        CREATE TRIGGER update_training_updated_at BEFORE UPDATE ON global.training
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'update_training_tracking_updated_at') THEN
        CREATE TRIGGER update_training_tracking_updated_at BEFORE UPDATE ON global.training_tracking
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'update_brand_kits_updated_at') THEN
        CREATE TRIGGER update_brand_kits_updated_at BEFORE UPDATE ON global.brand_kits
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'update_content_customizations_updated_at') THEN
        CREATE TRIGGER update_content_customizations_updated_at BEFORE UPDATE ON global.content_customizations
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    END IF;
END;
$$;

-- =========================================================================
-- Set search_path so unqualified queries resolve to the global schema
-- =========================================================================
ALTER DATABASE ocms SET search_path TO global, public;
