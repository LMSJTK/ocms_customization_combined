# Customization Portal - Stories & Tasks

## Overview

Build out the customization portal allowing companies to manage brand kits (logos, fonts, colors) and create customized versions of content templates. Customized content is stored in the DB following the existing `entry_body_html` pattern and served through the existing `launch.php` pipeline.

**Database schema**: Already committed in `database/schema.sql` and `database/schema.mysql.sql` (tables: `brand_kits`, `brand_kit_assets`, `content_customizations`).

**Reference**: `customization/customization_portal_basic_editing_and_appearance_example.php` and `customization/CustomizationPortal.pdf`

---

## Epic 1: Brand Kit Backend

### Story 1.1: Brand Kit CRUD API

**As** an API consumer, **I want** to create, read, update, and delete brand kits for a company **so that** brand assets can be managed programmatically.

**File to create**: `public/api/brand-kits.php`

**Acceptance Criteria:**
- `GET /api/brand-kits.php?company_id=X` — returns all brand kits for a company as `{"success": true, "brand_kits": [...]}`
- `GET /api/brand-kits.php?id=X` — returns a single brand kit with its assets
- `POST /api/brand-kits.php` — creates a new brand kit (JSON body: `company_id`, `name`, `primary_color`, `secondary_color`, `accent_color`, `saved_colors`, `primary_font`, `secondary_font`). Generates UUID, inserts into `brand_kits`, returns the created record
- `PUT /api/brand-kits.php?id=X` — updates brand kit fields (partial update)
- `DELETE /api/brand-kits.php?id=X` — deletes brand kit (cascades to `brand_kit_assets`), also deletes S3 assets if S3 is enabled
- Auth: `validateBearerToken()` + `validateVpnAccess()` on all methods
- Input: JSON body via `php://input` for POST/PUT, query params for GET/DELETE
- Validation: `company_id` required on create, hex color format validated (`/^#[a-f0-9]{6}$/i`), `name` unique per company
- Error responses follow existing pattern: `{"success": false, "error": "message"}`
- Uses `bootstrap.php` for DB, config, and utility access

**Technical Notes:**
- Follow pattern from `delete-content.php` for transactions on delete
- Follow pattern from `list-content.php` for GET responses
- UUID generation via `generateUUID4()` from bootstrap

---

### Story 1.2: Brand Kit Asset Upload API

**As** an API consumer, **I want** to upload logo and font files to a brand kit **so that** they are stored in S3 and associated with the brand kit record.

**File to create**: `public/api/brand-kit-upload.php`

**Acceptance Criteria:**
- `POST /api/brand-kit-upload.php` — accepts multipart form upload with fields: `brand_kit_id`, `asset_type` (`logo`, `font`)
- Logo validation: JPG, JPEG, PNG, GIF, WebP; max 10MB; validated with `getimagesize()`
- Font validation: WOFF, WOFF2, TTF, OTF; max 10MB; validated by extension
- If S3 enabled: uploads to `s3://bucket/brand-kits/{company_id}/{brand_kit_id}/{asset_type}s/{filename}` using `S3Client::uploadFile()`
- If S3 disabled: stores in `/var/www/html/content/brand-kits/{company_id}/{brand_kit_id}/{asset_type}s/{filename}`
- Creates `brand_kit_assets` record with S3 URL, mime type, file size
- For logos: also updates `brand_kits.logo_url` and `brand_kits.logo_filename`
- For fonts: appends the S3 URL to `brand_kits.custom_font_urls` JSONB array
- Returns `{"success": true, "asset": {id, s3_url, filename, ...}}`
- Auth: `validateBearerToken()` + `validateVpnAccess()`

**Technical Notes:**
- Follow thumbnail upload pattern from `upload.php:165-249` for file handling
- S3 path uses a new `brand-kits/` prefix separate from `content/`
- `S3Client::uploadFile()` already supports arbitrary paths and content types

---

### Story 1.3: Brand Kit Defaults & Enforcement

**As** a system, **I want** to ensure each company has at most one default brand kit **so that** the system knows which brand kit to auto-apply.

**Acceptance Criteria:**
- When creating a brand kit with `is_default: true`, set all other kits for that `company_id` to `is_default: false`
- When no default exists for a company, the first created kit becomes default
- `GET /api/brand-kits.php?company_id=X&default=true` returns only the default kit
- The default kit is used by `launch.php` for logo replacement (Story 3.1)

**Technical Notes:**
- Wrap the default-swap in a transaction to avoid race conditions
- This logic lives inside the POST/PUT handler in `brand-kits.php`

---

## Epic 2: Content Customization Backend

### Story 2.1: Apply Brand Kit to HTML (Preview/Transform)

**As** an API consumer, **I want** to apply a brand kit's colors, logo, and fonts to a piece of content HTML and get back the transformed result **so that** I can preview what the branded content looks like without saving.

**File to create**: `public/api/apply-brand-kit.php`

**Acceptance Criteria:**
- `POST /api/apply-brand-kit.php` — JSON body: `content_id`, `brand_kit_id`
- Fetches `entry_body_html` from `content` table for the given content
- Fetches brand kit from `brand_kits` table
- Applies transformations to the HTML:
  - Replace `class="logo"` img src with `brand_kits.logo_url` (same logic as `launch.php:428-442`)
  - Replace primary-colored elements: `<*>` with `style="background-color: #2563eb"` or similar blue defaults get `primary_color` applied
  - Replace CTA button background colors with `primary_color`
  - Replace header background colors with `primary_color`
  - Replace font-family declarations with `primary_font`
  - Inject `@font-face` rules for `custom_font_urls` if present
- Returns `{"success": true, "html": "<transformed html>", "transformations": [{selector, property, old_value, new_value}, ...]}`
- The `transformations` array records each change made (for the structured `customization_data` JSONB)
- Does NOT save anything — this is a preview/transform endpoint
- Auth: `validateBearerToken()` + `validateVpnAccess()`

**Technical Notes:**
- The transformation logic should be extracted into a reusable function (or new class method on `ContentProcessor`) since it's also needed by Story 2.2
- Start simple: target known CSS patterns (inline `background-color`, `color`, `font-family` in style attributes). The example template uses inline styles throughout
- The `transformations` array enables the "Undo Changes" feature from the example UI

---

### Story 2.2: Content Customization CRUD API

**As** an API consumer, **I want** to create, read, update, and delete customized versions of content **so that** companies can save their branded/edited templates.

**File to create**: `public/api/customizations.php`

**Acceptance Criteria:**
- `GET /api/customizations.php?company_id=X` — list all customizations for a company
- `GET /api/customizations.php?company_id=X&base_content_id=Y` — list customizations of a specific template
- `GET /api/customizations.php?id=X` — get a single customization with its full HTML
- `POST /api/customizations.php` — create customization. JSON body:
  - `company_id` (required)
  - `base_content_id` (required) — must exist in `content` table
  - `brand_kit_id` (optional) — if provided, auto-apply brand kit to base HTML
  - `title` (optional) — defaults to base content title
  - `customized_html` (optional) — if not provided and `brand_kit_id` is set, generate by applying brand kit to base `entry_body_html`
  - `customization_data` (optional) — JSON of structured edits
  - `status` (optional) — `draft` (default) or `published`
  - `created_by` (optional)
- `PUT /api/customizations.php?id=X` — update customization fields (partial update). Accepts `customized_html`, `customization_data`, `title`, `status`, `brand_kit_id`
- `DELETE /api/customizations.php?id=X` — delete customization
- Auth: `validateBearerToken()` + `validateVpnAccess()`
- Validation: `base_content_id` must reference existing content; `brand_kit_id` must reference existing brand kit if provided

**Technical Notes:**
- On POST with `brand_kit_id` but no `customized_html`: call the apply-brand-kit transform logic from Story 2.1 to generate the initial `customized_html`
- The `customization_data` JSONB stores the edit history in a format like:
  ```json
  {
    "brand_kit_applied": true,
    "brand_kit_id": "uuid",
    "element_edits": [
      {"selector": "#email-header", "property": "background-color", "value": "#4F46E5"},
      {"selector": "#email-button", "property": "background-color", "value": "#4F46E5"},
      {"selector": ".logo img", "property": "src", "value": "https://s3.../logo.png"}
    ]
  }
  ```

---

### Story 2.3: Customization Preview Link

**As** an API consumer, **I want** to generate a preview link for a customization **so that** I can see how the customized content looks in the launch player.

**Acceptance Criteria:**
- `POST /api/customizations.php?action=preview&id=X` — generates a preview link for the customization
- Creates a temporary `training` + `training_tracking` record (same as the existing preview link pattern in `upload.php:97-158`)
- Returns `{"success": true, "preview_url": "https://...launch.php/contentid/trackingid"}`
- The preview link uses the `customization_id` to serve `customized_html` instead of `entry_body_html`

**Technical Notes:**
- Requires Story 3.1 (launch integration) to actually serve the customized content
- Follow the existing `generatePreviewLink()` function pattern in `upload.php`
- May need to add `customization_id` column to `training_tracking` or `training` table, or pass it via a query parameter on the launch URL

---

## Epic 3: Launch Pipeline Integration

### Story 3.1: Serve Customized Content at Launch Time

**As** the system, **I want** `launch.php` to serve customized HTML when a published customization exists **so that** end users see the branded version of content.

**File to modify**: `public/launch.php`

**Acceptance Criteria:**
- After loading the content record (line ~92-100), check if a published `content_customization` exists for this `(content_id, company_id)` pair
- The `company_id` is determined from the `training` record (which has `company_id`)
- If a published customization exists, use `customized_html` from `content_customizations` instead of `entry_body_html` from `content`
- All existing runtime placeholder replacement continues to work (year, email, logo, tracking, etc.) — the customized HTML still contains the same placeholder spans
- All existing tracking injection continues to work (meta tags, tracker script)
- If no customization exists, behavior is unchanged (existing fallback chain)
- Log which source was used: `error_log("Using customized content {$customizationId} for content {$contentId}")`

**Technical Notes:**
- This is approximately a 15-line change. Insert between the content fetch (line ~92) and the `entry_body_html` check (line ~219):
  ```php
  // Check for published customization
  $companyId = $training['company_id'] ?? null; // need to add company_id to the training SELECT
  if ($companyId) {
      $customization = $db->fetchOne(
          'SELECT customized_html FROM content_customizations WHERE base_content_id = :cid AND company_id = :company AND status = :status',
          [':cid' => $contentId, ':company' => $companyId, ':status' => 'published']
      );
      if ($customization && !empty($customization['customized_html'])) {
          $htmlContent = $customization['customized_html'];
          $servedFromDB = true;
      }
  }
  ```
- Update the training SELECT at line ~108 to also fetch `company_id`

---

### Story 3.2: Brand Kit Logo Replacement at Launch Time

**As** the system, **I want** `launch.php` to use the company's brand kit logo instead of the hardcoded default **so that** content shows the correct company logo.

**File to modify**: `public/launch.php` (lines 420-442)

**Acceptance Criteria:**
- Before the logo replacement block, look up the company's default brand kit: `SELECT logo_url FROM brand_kits WHERE company_id = :company AND is_default = true`
- If a brand kit logo exists, use it as `$defaultLogoUrl` instead of `CofenseLogo2026.png`
- If no brand kit exists, fall back to the existing hardcoded logo (current behavior)
- Works for both customized and non-customized content

**Technical Notes:**
- The `company_id` is already available from Story 3.1's training query
- Simple conditional: ~5 lines of code before the existing `preg_replace_callback`

---

## Epic 4: Customization Portal Frontend

### Story 4.1: Portal Home - Template Gallery

**As** a portal user, **I want** to browse available content templates by category **so that** I can select one to customize.

**File to create**: `public/customization-portal.php`

**Acceptance Criteria:**
- Page structure based on the example `customization/customization_portal_basic_editing_and_appearance_example.php` Portal View
- Header with navigation (Home, Brand Kit, user info)
- Category tabs: Emails, Newsletters, Education, Infographics, Videos, E-Learning
- Search bar and filter dropdowns (SEG Misses, Theme, Languages, Industry, More Filters)
- Template grid that calls `GET /api/list-content.php?type={category}` and displays real content records
- Each card shows: thumbnail (from `content.thumbnail_filename`), title, description snippet
- Clicking a template card navigates to the editor (Story 4.3) with that `content_id`
- "My Content" toggle filters to customizations for the current company (`GET /api/customizations.php?company_id=X`)
- Bookmarked filter (can be a future story, UI placeholder is fine for now)
- Responsive grid (1-5 columns based on viewport)

**Technical Notes:**
- `company_id` can come from a URL param, session, or config for now (auth story is separate)
- Reuse existing Tailwind CSS approach from example
- Thumbnails can use the existing `/api/thumbnail.php?id=X` endpoint

---

### Story 4.2: Brand Kit Manager Page

**As** a portal user, **I want** to manage my company's brand kit (logo, fonts, colors) **so that** I can apply consistent branding across all templates.

**Integrated into**: `public/customization-portal.php` (Brand Kit view)

**Acceptance Criteria:**
- Brand Kit Manager view (based on example Brand Kit View)
- **Logo Upload**: Drag-and-drop zone, calls `POST /api/brand-kit-upload.php` with `asset_type=logo`. Shows preview of uploaded logo
- **Brand Fonts**: Dropdown populated from brand kit record. Font upload zone calls `POST /api/brand-kit-upload.php` with `asset_type=font`
- **Brand Colours**: Color picker (HSL/HSV), hex input, saved color palette — all wired to brand kit API
  - Clicking a saved color swatch sets it as active
  - "+ Add" button saves current color to `saved_colors` array
  - Color swatches for primary, secondary, accent with visual indicator of which is being edited
- **Save Brand Kit**: calls `PUT /api/brand-kits.php?id=X` with all current values
- **Reset to Default**: reverts to last-saved state (re-fetches from API)
- On page load: `GET /api/brand-kits.php?company_id=X&default=true` to load existing brand kit, or show empty state if none exists

**Technical Notes:**
- Color picker JS logic from the example is production-ready (HSL/HSV conversions, drag handlers, debounced save)
- Replace the `fetch('index.php', ...)` session-save call with real API calls
- File upload uses `FormData` + `fetch()` to `brand-kit-upload.php`

---

### Story 4.3: Content Editor - Brand Kit Application & Element Editing

**As** a portal user, **I want** to edit a content template by applying my brand kit and adjusting individual elements **so that** I can create a customized version of the content.

**Integrated into**: `public/customization-portal.php` (Editor view)

**Acceptance Criteria:**
- Editor view (based on example Editor View) with left sidebar + right preview pane
- On load: fetch base content HTML via `GET /api/list-content.php` (or a new single-content endpoint) and render in preview iframe/container
- **Brand Kit Available state**: "Apply Brand Kit" button calls `POST /api/apply-brand-kit.php` with current `content_id` + default `brand_kit_id`. Replaces preview HTML with the result
- **Brand Kit Applied state**: Shows green confirmation. "Undo Changes" reverts to original base HTML
- **Element Editing state**: Clicking an editable element in the preview:
  - Highlights it with a red outline (`selected` class)
  - Shows sidebar controls populated with the element's current styles
  - Background Color picker: updates element's `background-color` inline style
  - Text Color picker: updates element's `color` inline style
  - Font Size dropdown: updates `font-size`
  - Font Weight dropdown: updates `font-weight`
  - Text Alignment buttons: updates `text-align`
- **Save button**: Serializes the current preview HTML + builds `customization_data` JSON from all edits, calls `POST /api/customizations.php`
- **Edit/Preview toggle**: Edit mode enables element selection, Preview mode shows clean output
- **Advanced HTML Editor** button (bottom of sidebar): Opens a textarea/code editor with raw HTML for direct editing (can be a basic `<textarea>` initially)
- Toolbar: template name (editable), last-saved timestamp, Save button
- Clicking outside the preview deselects the element and returns to brand kit state

**Technical Notes:**
- The example's editor JS (element selection, style reading/writing, state management) is largely ready to use
- The preview should use a sandboxed `<iframe>` with `srcdoc` for proper isolation (the example uses a `<div>` which works for the demo but iframe is more robust for production since content may have its own CSS that conflicts)
- Track each edit in an array to build `customization_data` on save
- Auto-save with debounce (e.g., PUT every 30 seconds if changes exist)

---

### Story 4.4: Customization Status & Publishing

**As** a portal user, **I want** to save drafts and publish customizations **so that** I can work iteratively and only serve finalized content.

**Acceptance Criteria:**
- New customizations start as `draft` status
- "Save" button saves as draft (PUT to `customizations.php`)
- "Publish" button changes status to `published` (PUT with `status: published`)
- Only `published` customizations are served by `launch.php` (Story 3.1)
- "My Content" grid on the portal shows both drafts and published with visual status badges
- Clicking a draft opens it in the editor for continued editing
- Clicking a published customization opens it in the editor with a warning that changes won't go live until re-published

**Technical Notes:**
- Status transitions: `draft` → `published`, `published` → `draft` (unpublish)
- Consider: only one published customization per `(company_id, base_content_id)` pair? Or allow multiple and pick the most recent? Recommend: one published per pair, enforced on publish.

---

## Epic 5: Supporting Infrastructure

### Story 5.1: S3 Path Extension for Brand Kit Assets

**As** the system, **I want** `S3Client` to support brand kit asset paths **so that** logos and fonts are stored in an organized S3 structure.

**File to modify**: `lib/S3Client.php`

**Acceptance Criteria:**
- Add method `uploadBrandKitAsset($companyId, $brandKitId, $assetType, $filename, $localPath, $contentType)` — uploads to `s3://bucket/brand-kits/{companyId}/{brandKitId}/{assetType}s/{filename}`
- Add method `deleteBrandKitAssets($companyId, $brandKitId)` — deletes `s3://bucket/brand-kits/{companyId}/{brandKitId}/` recursively
- Add method `getBrandKitAssetUrl($companyId, $brandKitId, $assetType, $filename)` — returns CDN or S3 URL
- All methods use `escapeshellarg()` for input sanitization (match existing pattern)

**Technical Notes:**
- These are thin wrappers around the existing upload/delete/URL methods with a different path prefix
- Alternatively, the existing `uploadFile()` could be used directly with a constructed path — in that case, this story is just the path-building helper methods

---

### Story 5.2: Database Migration Script

**As** a developer, **I want** a migration script that adds the customization tables to an existing database **so that** existing deployments can be upgraded.

**File to create**: `scripts/migrate-add-customization-tables.php`

**Acceptance Criteria:**
- Reads the new table DDL from `schema.sql` (or has it inline)
- Creates `brand_kits`, `brand_kit_assets`, `content_customizations` tables if they don't exist
- Creates indexes and triggers
- Supports both PostgreSQL and MySQL (checks `$db->getDbType()`)
- Idempotent: safe to run multiple times (uses `IF NOT EXISTS`)
- Logs progress and results
- Can be run from the scripts UI (`scripts.html`) or CLI

**Technical Notes:**
- Follow the pattern of existing scripts in `scripts/`
- Use `bootstrap.php` for DB connection

---

### Story 5.3: API Documentation Update

**As** a developer, **I want** the API documentation updated with the new customization endpoints **so that** consumers know how to use them.

**Files to modify**: `API_DOCUMENTATION.md`, `API_EXAMPLES.md`

**Acceptance Criteria:**
- Document all new endpoints (brand-kits, brand-kit-upload, customizations, apply-brand-kit)
- Include request/response examples for each method
- Document the `customization_data` JSON schema
- Add curl examples in `API_EXAMPLES.md`

---

## Epic 6: Content Translation (Claude-Powered)

### Story 6.1: Translation API Endpoint

**As** an API consumer, **I want** to translate content HTML into another language using Claude **so that** companies can deliver training in their employees' native languages.

**File to create**: `public/api/translate-content.php`
**File to modify**: `lib/ClaudeAPI.php` (add `translateContent()` method)

**Acceptance Criteria:**
- `POST /api/translate-content.php` — JSON body: `content_id`, `target_language` (ISO 639-1 code, e.g. `es`, `fr`, `de`, `pt-br`, `ar`), `source_language` (optional, defaults to `en`)
- Fetches content HTML (`entry_body_html` for training/education, `email_body_html` for email)
- Calls new `ClaudeAPI::translateContent($html, $targetLang, $sourceLang)` method
- Claude translates **only visible text content** — preserves all HTML structure, attributes, `data-cue`/`data-tag` attributes, inline styles, `src`/`href` URLs, scripts, and placeholders (e.g. `RECIPIENT_EMAIL_ADDRESS`)
- Uses the same file-reference tokenization pattern from `tagHTMLContent()` (lines 455-490) to protect URLs/paths from AI corruption
- Returns `{"success": true, "html": "<translated html>", "source_language": "en", "target_language": "es", "content_id": "..."}`
- Does NOT save — this is a preview/transform endpoint (saving is done via Story 6.2 or Story 2.2)
- Auth: `validateBearerToken()` + `validateVpnAccess()`

**Technical Notes:**
- `ClaudeAPI::translateContent()` system prompt should emphasize:
  - Preserve ALL HTML tags, attributes, structure
  - Preserve ALL placeholder tokens (`__ASSET_REF_XXXX__`)
  - Translate only human-readable text nodes and `alt` attributes
  - Maintain the tone and formality level (phishing emails should still read like phishing emails)
  - For RTL languages (Arabic, Hebrew): add `dir="rtl"` to root element
- The existing `$this->protectFileReferences()` / `$this->restoreFileReferences()` pattern in `tagHTMLContent()` is directly reusable
- Max content size check: same 50KB limit used by other Claude methods
- Language code validation against a known set (the `languages` column already uses these codes)

---

### Story 6.2: Save Translated Content as New Content Record

**As** an API consumer, **I want** to save a translation as a new content record linked to the original **so that** translated versions appear in the content library and can be assigned independently.

**Acceptance Criteria:**
- `POST /api/translate-content.php?action=save` — JSON body: `content_id` (source), `target_language`, `translated_html` (from Story 6.1 preview), `title` (optional, defaults to `"{original_title} ({language})"`)
- Creates a **new** record in the `content` table with:
  - Same `content_type`, `company_id`, `tags`, `difficulty` as the source
  - `languages` set to `target_language`
  - `entry_body_html` / `email_body_html` set to the translated HTML
  - `title` appended with language name (e.g. "Invoice Scam (Spanish)")
  - New UUID, new `created_at`
- Stores the parent-child relationship: add `source_content_id` column to `content` table, or use a `content_translations` join table (see Technical Notes)
- Returns `{"success": true, "content_id": "new-uuid", "title": "...", "language": "es"}`
- Auth: `validateBearerToken()` + `validateVpnAccess()`

**Technical Notes:**
- Recommend a lightweight `content_translations` table:
  ```sql
  CREATE TABLE content_translations (
      id SERIAL PRIMARY KEY,
      source_content_id TEXT NOT NULL REFERENCES content(id) ON DELETE CASCADE,
      translated_content_id TEXT NOT NULL REFERENCES content(id) ON DELETE CASCADE,
      source_language VARCHAR(10) NOT NULL DEFAULT 'en',
      target_language VARCHAR(10) NOT NULL,
      created_at TIMESTAMP DEFAULT now(),
      UNIQUE(source_content_id, target_language)
  );
  ```
- The UNIQUE constraint prevents duplicate translations of the same content into the same language
- This approach keeps translations as first-class content records (they can have their own quizzes, customizations, tracking, etc.)
- Schema addition goes into the migration script (Story 5.2)

---

### Story 6.3: Translation UI in Customization Portal

**As** a portal user, **I want** to translate a selected template into another language from the editor **so that** I can produce localized content without leaving the portal.

**Integrated into**: `public/customization-portal.php` (Editor view, new sidebar section)

**Acceptance Criteria:**
- New "Translate" section in the editor sidebar (below Brand Kit, above Advanced HTML Editor)
- Language dropdown populated with supported languages: English, Spanish, French, German, Portuguese (Brazil), Arabic, Japanese, Korean, Italian, Dutch, Chinese (Simplified), Chinese (Traditional) — extensible
- "Translate" button calls `POST /api/translate-content.php` with current content HTML + selected language
- Shows loading spinner during Claude API call (may take 5-15 seconds for large content)
- Preview pane updates with translated HTML
- "Save as New Template" button calls Story 6.2's save endpoint
- "Apply to Current" replaces the current customization HTML with the translated version (for saving as a customization, not a new content record)
- Existing translations shown as chips/badges below the dropdown (fetched from `content_translations` table)
- For RTL languages, preview pane should respect `dir="rtl"` on the rendered content

**Technical Notes:**
- Translation + brand kit can be combined: translate first, then apply brand kit (or vice versa)
- Consider caching the last translation in browser `sessionStorage` to avoid re-calling Claude if user toggles between views
- The language dropdown values match the ISO codes used in `content.languages`

---

## Epic 7: Quiz Generation Portal Exposure

### Story 7.1: Quiz Generation UI in Editor

**As** a portal user, **I want** to generate and preview quiz questions for a content template from the editor **so that** I can add assessments without using the raw API.

**Integrated into**: `public/customization-portal.php` (Editor view, new sidebar section or modal)

**Acceptance Criteria:**
- New "Quiz" section in the editor sidebar or a "Generate Quiz" button in the toolbar
- Controls:
  - Number of questions slider/dropdown (2-5, default 3)
  - "Generate Quiz" button
- Calls existing `POST /api/generate-questions.php` with `content_id` and `num_questions`
- Shows loading state during Claude API call
- **Quiz Preview Panel** (modal or inline below preview pane):
  - Renders each generated question with its options, correct answer highlighted in green, and explanation
  - Questions shown in a read-friendly format (not the raw JSON)
  - "Regenerate" button to re-call the API for new questions
  - "Regenerate Question" button per-question to replace just one question (calls API with `num_questions=1` and splices into the array)
- **Inject Quiz** button: calls `POST /api/generate-questions.php` with `inject: true`, updates preview pane with the quiz appended to content
- **Remove Quiz** button (if quiz already injected): strips the `#ocms-quiz-section` div and the quiz script tag from the HTML, updates `scorable` to false
- After injection, the preview pane shows the full content with the quiz section visible at the bottom
- Auth inherited from portal session

**Technical Notes:**
- The existing `generate-questions.php` endpoint is already fully functional — this story is purely UI
- Quiz HTML from the API response (`quiz_html` field) can be rendered directly in the preview
- For the "Remove Quiz" feature: simple DOM operation — remove `#ocms-quiz-section` and the `ocms-quiz.js` script tag
- If working with a customization (not the base content), inject into `customized_html` rather than the base content. This means the inject flow should go through the customization CRUD (PUT to `customizations.php`) rather than directly modifying the base content
- Question preview should parse the `questions` JSON array, not render the `quiz_html` (which is for end users)

---

### Story 7.2: Quiz Management for Customizations

**As** a portal user, **I want** quizzes generated for customizations to be stored with the customization **so that** each company's branded version can have its own quiz.

**Acceptance Criteria:**
- When "Inject Quiz" is clicked on a customization (not a base template), the quiz HTML is appended to `customized_html` in the `content_customizations` record — NOT the base content
- `customization_data` JSONB is updated with a `quiz` key:
  ```json
  {
    "quiz": {
      "injected": true,
      "num_questions": 3,
      "questions": [...],
      "generated_at": "2026-02-13T..."
    }
  }
  ```
- "Remove Quiz" on a customization removes it from `customized_html` and sets `quiz.injected: false` in `customization_data`
- Base content `scorable` flag is NOT modified when working on customizations
- For base templates (no customization context), the existing `generate-questions.php` inject behavior is used as-is

**Technical Notes:**
- This requires the editor to know whether it's editing a base template or a customization, and route the inject call accordingly
- Depends on Story 2.2 (customization CRUD) being complete

---

## Epic 8: Threat Indicator Viewer & Editor

### Story 8.1: Data-Cue Visualization in Email Preview

**As** a portal user, **I want** to see all phishing indicators (data-cues) highlighted and explained in the email preview **so that** I can understand what threats are present and how they're classified.

**Integrated into**: `public/customization-portal.php` (Editor view, new mode)

**Acceptance Criteria:**
- New "Threat View" toggle button in the editor toolbar (alongside Edit/Preview)
- When active, the preview renders the email with all `data-cue` elements visually highlighted:
  - Each `data-cue` element gets a colored border/background based on cue category:
    - Error: red (#EF4444)
    - Technical indicator: orange (#F59E0B)
    - Visual presentation: purple (#8B5CF6)
    - Language and content: blue (#3B82F6)
    - Common tactic: green (#10B981)
  - Hovering over a highlighted element shows a tooltip with:
    - Cue name (human-readable, e.g. "Sense of Urgency" from `sense-of-urgency`)
    - Category (e.g. "Language and content")
    - Criteria description (from the NIST taxonomy: "Does the message contain time pressure...")
- **Threat Summary Panel** in the sidebar:
  - Lists all detected cues grouped by category
  - Count per category
  - NIST difficulty rating badge (from `content.difficulty`: "Least", "Moderately", "Very" difficult)
  - Each cue is clickable — scrolls to and highlights the corresponding element in the preview
- Works for email content only (data-cues are email-specific). For non-email content, the Threat View button is hidden/disabled

**Technical Notes:**
- The cue taxonomy is defined in `ClaudeAPI::tagPhishingEmail()` (lines 539-590) — the 5 categories and 23 cues with their criteria. This data needs to be available client-side as a JS object
- Extract the cue taxonomy into a shared location (e.g. a static JSON file or a dedicated API endpoint) so both the PHP backend and JS frontend reference the same source of truth
- Client-side implementation: parse `data-cue` attributes from the preview HTML using `querySelectorAll('[data-cue]')`, then overlay highlight styles and build the summary panel
- The highlight overlay should be CSS-only (add classes, not modify the content HTML) so it can be toggled on/off cleanly
- Content's `difficulty` value comes from the content record (already fetched for the editor)

---

### Story 8.2: Threat Editor — Add/Remove/Modify Cues

**As** a portal user, **I want** to manually add, remove, or modify phishing indicators on email elements **so that** I can fine-tune the threat profile of a phishing template.

**Integrated into**: `public/customization-portal.php` (Editor view, within Threat View mode)

**Acceptance Criteria:**
- In Threat View mode, clicking an element opens a **Threat Edit Panel** in the sidebar:
  - If element already has a `data-cue`: shows current cue with option to change or remove
  - If element has no `data-cue`: shows "Add Threat Indicator" with a dropdown of all 23 cues grouped by category
- **Add Cue**: Select cue from categorized dropdown → `data-cue` attribute added to element in preview HTML → highlight appears
- **Remove Cue**: Click "Remove" on a cue → `data-cue` attribute removed from element → highlight removed
- **Change Cue**: Dropdown changes the `data-cue` value on the element
- All edits are tracked in `customization_data` JSONB under a `cue_edits` key:
  ```json
  {
    "cue_edits": [
      {"action": "add", "element_selector": "a[href*='verify']", "cue": "domain-spoofing"},
      {"action": "remove", "element_selector": "span.urgency", "cue": "sense-of-urgency"},
      {"action": "change", "element_selector": "img.logo", "old_cue": "no-minimal-branding", "new_cue": "logo-imitation-outdated"}
    ]
  }
  ```
- **Recalculate Difficulty** button: sends the current HTML (with cue edits applied) to a new API endpoint that re-evaluates difficulty based on the cue count and types, without re-running the full Claude tagging
- Changes are saved via the customization CRUD (PUT to `customizations.php`), so cue edits persist as part of the customization

**Technical Notes:**
- This is manual editing — no Claude call needed for add/remove/change operations
- The difficulty recalculation can be a simple heuristic based on NIST guidelines:
  - 0-2 cues → "least" difficult (easy to spot as phishing)
  - 3-5 cues → "moderately" difficult
  - 6+ cues → "very" difficult (hard to spot — many realistic elements)
  - Weight by category: Technical indicators and Common tactics make detection harder
- Alternatively, the "Recalculate Difficulty" can call Claude for a more nuanced assessment (using the HTML + current cue list)
- Element selectors for the edit log should use a stable identifier (element index, id, or XPath) since CSS selectors may not be unique

---

### Story 8.3: AI-Assisted Threat Injection

**As** a portal user, **I want** to ask Claude to add specific types of threats to an email **so that** I can increase the difficulty or add training scenarios for specific attack techniques.

**File to modify**: `lib/ClaudeAPI.php` (add `injectThreats()` method)
**File to create**: `public/api/inject-threats.php`

**Acceptance Criteria:**
- `POST /api/inject-threats.php` — JSON body:
  - `content_id` or `html` (the email HTML to modify)
  - `threat_types` — array of cue names to inject (e.g. `["sense-of-urgency", "domain-spoofing", "mimics-business-process"]`)
  - `intensity` — optional: `subtle` (hard to detect) or `obvious` (easy to detect), defaults to `subtle`
- New `ClaudeAPI::injectThreats($html, $threatTypes, $intensity)` method:
  - System prompt instructs Claude to modify the email to introduce the requested threat indicators
  - Claude rewrites/adds text or modifies elements to create the threat, then wraps them in `data-cue` attributes
  - For `subtle` intensity: threats should be well-disguised (e.g. a typo in a domain, slightly urgent but professional tone)
  - For `obvious` intensity: threats should be more apparent (e.g. blatant urgency, clearly fake domain)
  - Uses file-reference tokenization to protect existing URLs/paths
- Returns `{"success": true, "html": "<modified html>", "injected_cues": ["sense-of-urgency", ...], "difficulty": "moderately"}`
- The response also includes the new difficulty rating
- Auth: `validateBearerToken()` + `validateVpnAccess()`

**UI Integration** (in the Threat View mode):
- "Add Threats with AI" button opens a modal:
  - Checkboxes for each cue category with individual cues underneath
  - Intensity toggle: Subtle / Obvious
  - "Generate" button calls the API
  - Preview of changes before accepting
  - "Accept" applies changes to preview, "Cancel" discards
- After accepting, changes are reflected in the preview HTML and the Threat Summary Panel updates

**Technical Notes:**
- The `injectThreats()` prompt should include the full cue taxonomy (same as `tagPhishingEmail()`)
- Key difference from `tagPhishingEmail()`: that method _finds_ existing indicators; this method _creates_ new ones
- The system prompt should emphasize: modify text content only where needed, preserve HTML structure, keep changes realistic and contextually appropriate
- Consider sending back a diff or changelog so the UI can highlight what changed
- This is the most "creative" Claude usage — set temperature higher if supported (currently not in the API wrapper, but can be added)

---

### Story 8.4: Threat Taxonomy API

**As** a frontend consumer, **I want** to fetch the full NIST Phish Scale cue taxonomy from an API **so that** the threat viewer and editor can render categories and descriptions without hardcoding them.

**File to create**: `public/api/threat-taxonomy.php`

**Acceptance Criteria:**
- `GET /api/threat-taxonomy.php` — returns the full taxonomy as JSON:
  ```json
  {
    "success": true,
    "taxonomy": [
      {
        "type": "Error",
        "color": "#EF4444",
        "cues": [
          {"name": "spelling-grammar", "label": "Spelling & Grammar", "criteria": "Does the message contain inaccurate spelling..."},
          {"name": "inconsistency", "label": "Inconsistency", "criteria": "Are there inconsistencies..."}
        ]
      },
      ...
    ],
    "difficulty_levels": [
      {"value": "least", "label": "Least Difficult", "description": "Multiple obvious red flags..."},
      {"value": "moderately", "label": "Moderately Difficult", "description": "Some red flags but requires closer inspection..."},
      {"value": "very", "label": "Very Difficult", "description": "Sophisticated, few obvious indicators..."}
    ]
  }
  ```
- No auth required (this is reference data, not sensitive)
- The JSON is the single source of truth — `ClaudeAPI::tagPhishingEmail()` should also read from this (or both read from a shared PHP constant/file)

**Technical Notes:**
- Extract the cue taxonomy from the hardcoded JSON in `ClaudeAPI::tagPhishingEmail()` (lines 539-590) into a shared location:
  - Option A: A PHP constant file `lib/ThreatTaxonomy.php` that both the API endpoint and `ClaudeAPI` import
  - Option B: A static JSON file `config/threat-taxonomy.json` loaded by both
- The `label` field is a human-readable version of the `name` (e.g. `sense-of-urgency` → `Sense of Urgency`)
- The `color` field per category enables consistent UI highlighting

---

## Dependency Graph

```
Story 5.2 (migration script + new tables)
  └─→ Story 1.1 (brand kit CRUD)
       ├─→ Story 1.2 (brand kit asset upload)  ←── Story 5.1 (S3 paths)
       ├─→ Story 1.3 (brand kit defaults)
       └─→ Story 2.1 (apply brand kit transform)
            └─→ Story 2.2 (customization CRUD)
                 ├─→ Story 2.3 (customization preview)
                 ├─→ Story 3.1 (launch integration)  ←── Story 3.2 (logo replacement)
                 ├─→ Story 4.3 (editor frontend)
                 ├─→ Story 7.2 (quiz for customizations)
                 └─→ Story 8.2 (threat editor saves to customization)

  Story 4.1 (portal gallery) ── parallel, uses existing list-content API
  Story 4.2 (brand kit manager) ── depends on Story 1.1 + 1.2
  Story 4.4 (status/publishing) ── depends on Story 2.2 + 3.1

  Story 6.1 (translation API) ── standalone, only needs ClaudeAPI
  Story 6.2 (save translations) ── depends on Story 6.1
  Story 6.3 (translation UI) ── depends on Story 6.1 + 6.2 + Story 4.3 (editor)

  Story 7.1 (quiz UI in editor) ── depends on existing generate-questions.php + Story 4.3 (editor)
  Story 7.2 (quiz for customizations) ── depends on Story 7.1 + Story 2.2

  Story 8.4 (threat taxonomy API) ── standalone, no dependencies
  Story 8.1 (threat viewer) ── depends on Story 8.4 + Story 4.3 (editor)
  Story 8.2 (threat editor) ── depends on Story 8.1 + Story 2.2
  Story 8.3 (AI threat injection) ── depends on Story 8.1 + ClaudeAPI

  Story 5.3 (docs) ── after all API stories complete
```

## Suggested Sprint Plan

**Sprint 1 — Foundation:**
- Story 5.2 (migration script — include `content_translations` table)
- Story 1.1 (brand kit CRUD API)
- Story 5.1 (S3 path extension)
- Story 1.2 (brand kit asset upload)
- Story 1.3 (brand kit defaults)
- Story 8.4 (threat taxonomy API — standalone, quick win)

**Sprint 2 — Customization & Translation Engine:**
- Story 2.1 (apply brand kit transform)
- Story 2.2 (customization CRUD API)
- Story 3.1 (launch integration)
- Story 3.2 (brand kit logo replacement)
- Story 6.1 (translation API)
- Story 6.2 (save translated content)

**Sprint 3 — Frontend Core:**
- Story 4.1 (portal gallery)
- Story 4.2 (brand kit manager page)
- Story 4.3 (content editor)
- Story 4.4 (status & publishing)

**Sprint 4 — AI Features Frontend:**
- Story 6.3 (translation UI in editor)
- Story 7.1 (quiz generation UI)
- Story 7.2 (quiz for customizations)
- Story 8.1 (threat indicator viewer)

**Sprint 5 — Threat Editor & Polish:**
- Story 8.2 (manual threat editing)
- Story 8.3 (AI-assisted threat injection)
- Story 2.3 (customization preview links)
- Story 5.3 (API documentation — all endpoints)
- Bug fixes, edge cases, testing
