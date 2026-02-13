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

## Dependency Graph

```
Story 5.2 (migration script)
  └─→ Story 1.1 (brand kit CRUD)
       ├─→ Story 1.2 (brand kit asset upload)  ←── Story 5.1 (S3 paths)
       ├─→ Story 1.3 (brand kit defaults)
       └─→ Story 2.1 (apply brand kit transform)
            └─→ Story 2.2 (customization CRUD)
                 ├─→ Story 2.3 (customization preview)
                 ├─→ Story 3.1 (launch integration)  ←── Story 3.2 (logo replacement)
                 └─→ Story 4.3 (editor frontend)
  Story 4.1 (portal gallery) ── can start in parallel, uses existing list-content API
  Story 4.2 (brand kit manager) ── depends on Story 1.1 + 1.2
  Story 4.4 (status/publishing) ── depends on Story 2.2 + 3.1
  Story 5.3 (docs) ── after all API stories complete
```

## Suggested Sprint Plan

**Sprint 1 — Foundation:**
- Story 5.2 (migration script)
- Story 1.1 (brand kit CRUD API)
- Story 5.1 (S3 path extension)
- Story 1.2 (brand kit asset upload)
- Story 1.3 (brand kit defaults)

**Sprint 2 — Customization Engine:**
- Story 2.1 (apply brand kit transform)
- Story 2.2 (customization CRUD API)
- Story 3.1 (launch integration)
- Story 3.2 (brand kit logo replacement)

**Sprint 3 — Frontend:**
- Story 4.1 (portal gallery)
- Story 4.2 (brand kit manager page)
- Story 4.3 (content editor)
- Story 4.4 (status & publishing)

**Sprint 4 — Polish:**
- Story 2.3 (customization preview links)
- Story 5.3 (API documentation)
- Bug fixes, edge cases, testing
