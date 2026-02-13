# API Documentation

## Base URL

All API endpoints are relative to `/api/`

## Authentication

Protected endpoints require a Bearer token (configured in `config/config.php`) and VPN access:

```
Authorization: Bearer <token>
```

**Protected Endpoints (Bearer token + VPN):**
- Upload, list content, launch link generation
- Brand kit CRUD, brand kit asset upload
- Customization CRUD, apply brand kit, preview links
- Content translation, threat injection

**Public Endpoints (no authentication):**
- `/api/record-score.php`, `/api/track-interaction.php`, `/api/track-view.php`
- `/api/threat-taxonomy.php` (reference data)

## Endpoints

### 1. Upload Content

**Endpoint:** `POST /api/upload.php`

**Description:** Upload and process content (SCORM, HTML, Email, Video)

**Content Types:**
- `scorm` - SCORM package (ZIP)
- `html` - HTML package (ZIP)
- `raw_html` - Raw HTML content
- `email` - Phishing training email
- `video` - Video file (MP4, WebM, OGG)

#### SCORM/HTML Upload

**Request (multipart/form-data):**
```
content_type: "scorm" | "html"
title: string
description: string (optional)
company_id: string (optional, default: "default")
language: string (optional, default: "en")
file: ZIP file
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Content uploaded and processed successfully",
  "tags": ["ransomware", "phishing", "password-security"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Raw HTML Upload

**Request (multipart/form-data):**
```
content_type: "raw_html"
title: string
description: string (optional)
company_id: string (optional)
language: string (optional, default: "en")
html_content: string (HTML content)
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "HTML content processed successfully",
  "tags": ["topic1", "topic2"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Email Upload

**Request (multipart/form-data):**
```
content_type: "email"
title: string
email_subject: string
email_from: string
email_html: string (HTML content)
language: string (optional, default: "en")
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Email content processed successfully",
  "cues": ["visual:logo-issue", "language:urgency", "technical:domain-spoofing"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Video Upload

**Request (multipart/form-data):**
```
content_type: "video"
title: string
description: string (optional)
language: string (optional, default: "en")
file: Video file (MP4/WebM/OGG)
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Video uploaded successfully",
  "path": "a1b2c3d4.../video.mp4"
}
```

---

### 2. List Content

**Endpoint:** `GET /api/list-content.php`

**Description:** Get list of all uploaded content

**Response:**
```json
{
  "success": true,
  "content": [
    {
      "id": "a1b2c3d4...",
      "company_id": "default",
      "title": "Security Awareness Training",
      "description": "Basic security awareness",
      "content_type": "scorm",
      "content_url": "a1b2c3d4.../index.php",
      "created_at": "2025-11-04 10:30:00",
      "tags": ["ransomware", "phishing"]
    }
  ]
}
```

---

### 3. Generate Launch Link

**Endpoint:** `POST /api/launch-link.php`

**Description:** Create a tracking link for content launch

**Request (application/json):**
```json
{
  "recipient_id": "user-123",
  "content_id": "a1b2c3d4...",
  "email": "user@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "company_id": "acme-corp"
}
```

**Response:**
```json
{
  "success": true,
  "tracking_link_id": "x9y8z7w6...",
  "launch_url": "/launch.php?tid=x9y8z7w6...",
  "content": {
    "id": "a1b2c3d4...",
    "title": "Security Awareness Training",
    "type": "scorm"
  }
}
```

---

### 4. Track View

**Endpoint:** `POST /api/track-view.php`

**Description:** Record when content is viewed (automatically called by launch.php)

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6..."
}
```

**Response:**
```json
{
  "success": true,
  "message": "View tracked successfully"
}
```

---

### 5. Track Interaction

**Endpoint:** `POST /api/track-interaction.php`

**Description:** Record interaction with tagged element (automatically called by tracking script)

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6...",
  "tag_name": "ransomware",
  "interaction_type": "click",
  "interaction_value": "answer_b",
  "success": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Interaction tracked successfully"
}
```

---

### 6. Record Score

**Endpoint:** `POST /api/record-score.php`

**Description:** Record final test score and publish to SNS

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6...",
  "score": 100,
  "interactions": [
    {
      "tag": "ransomware",
      "type": "click",
      "value": "correct",
      "timestamp": "2025-11-04T10:30:00Z"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "score": 100,
  "content_type": "training",
  "message": "Score recorded successfully"
}
```

**Notes:**
- `content_type` is either `"training"` or `"follow_on"` depending on which content was completed
- First score recorded is preserved; subsequent attempts return the existing score with `"already_recorded": true`
- Scores of 0 can be overwritten (handles SCORM packages that initialize with 0)
```

---

### 7. Brand Kit CRUD

**Endpoint:** `/api/brand-kits.php`

**Authentication:** Bearer token + VPN

#### List brand kits for a company

`GET /api/brand-kits.php?company_id=X`

**Response:**
```json
{
  "success": true,
  "brand_kits": [
    {
      "id": "uuid",
      "company_id": "acme-corp",
      "name": "Default",
      "logo_url": "https://...",
      "primary_color": "#4F46E5",
      "secondary_color": "#10B981",
      "accent_color": "#F59E0B",
      "saved_colors": ["#4F46E5", "#10B981"],
      "primary_font": "Roboto",
      "secondary_font": null,
      "custom_font_urls": [],
      "is_default": true,
      "created_at": "2025-01-15 10:30:00"
    }
  ],
  "count": 1
}
```

#### Get default brand kit

`GET /api/brand-kits.php?company_id=X&default=true`

#### Get single brand kit (with assets)

`GET /api/brand-kits.php?id=X`

**Response** includes an `assets` array with uploaded logos and fonts.

#### Create brand kit

`POST /api/brand-kits.php`

**Request (application/json):**
```json
{
  "company_id": "acme-corp",
  "name": "Corporate Brand",
  "primary_color": "#4F46E5",
  "secondary_color": "#10B981",
  "accent_color": "#F59E0B",
  "saved_colors": ["#4F46E5", "#10B981"],
  "primary_font": "Roboto",
  "is_default": true
}
```

**Notes:**
- `company_id` is required; `name` defaults to "Default"
- Color fields must be valid hex (e.g. `#4F46E5`)
- First kit for a company is automatically set as default
- Setting `is_default: true` clears existing defaults

#### Update brand kit

`PUT /api/brand-kits.php?id=X`

**Request (application/json):** Partial update — include only fields to change.

#### Delete brand kit

`DELETE /api/brand-kits.php?id=X`

Deletes the brand kit, its associated assets, and cleans up S3 storage.

---

### 8. Brand Kit Asset Upload

**Endpoint:** `POST /api/brand-kit-upload.php`

**Authentication:** Bearer token + VPN

**Request (multipart/form-data):**
```
brand_kit_id: string (required)
asset_type: "logo" | "font" (required)
file: file upload (required)
```

**Allowed file types:**
- Logo: JPG, JPEG, PNG, GIF, WebP (max 10MB, validated with `getimagesize`)
- Font: WOFF, WOFF2, TTF, OTF (max 10MB)

**Response (201):**
```json
{
  "success": true,
  "asset": {
    "id": "uuid",
    "brand_kit_id": "uuid",
    "asset_type": "logo",
    "filename": "logo.png",
    "s3_url": "https://...",
    "mime_type": "image/png",
    "file_size": 45678
  }
}
```

**Side effects:**
- Logo uploads update `brand_kits.logo_url` and `brand_kits.logo_filename`
- Font uploads append to `brand_kits.custom_font_urls` JSONB array

---

### 9. Content Customizations CRUD

**Endpoint:** `/api/customizations.php`

**Authentication:** Bearer token + VPN

#### List customizations

`GET /api/customizations.php?company_id=X`
`GET /api/customizations.php?company_id=X&base_content_id=Y`
`GET /api/customizations.php?company_id=X&status=published`

**Response:**
```json
{
  "success": true,
  "customizations": [
    {
      "id": "uuid",
      "company_id": "acme-corp",
      "base_content_id": "uuid",
      "brand_kit_id": "uuid",
      "title": "Customized Phishing Email",
      "status": "draft",
      "created_by": "admin",
      "created_at": "2025-01-15 10:30:00",
      "updated_at": "2025-01-15 10:35:00",
      "content_type": "email",
      "thumbnail_filename": "/images/email_default.png"
    }
  ],
  "count": 1
}
```

#### Get single customization

`GET /api/customizations.php?id=X`

Returns full customization record including `customized_html` and `customization_data`.

#### Generate preview link

`GET /api/customizations.php?id=X&action=preview`

Creates a preview training session and returns a launch URL. Works for both draft and published customizations.

**Response:**
```json
{
  "success": true,
  "preview_url": "https://external.example.com/launch.php/abc123/def456?customization_id=uuid",
  "customization_id": "uuid",
  "status": "draft"
}
```

#### Create customization

`POST /api/customizations.php`

**Request (application/json):**
```json
{
  "company_id": "acme-corp",
  "base_content_id": "uuid",
  "brand_kit_id": "uuid",
  "title": "Custom Email v2",
  "customized_html": "<html>...</html>",
  "customization_data": {
    "brand_kit_applied": true,
    "brand_kit_id": "uuid",
    "element_edits": []
  },
  "status": "draft",
  "created_by": "admin"
}
```

**Notes:**
- `company_id` and `base_content_id` are required
- If `brand_kit_id` is provided but no `customized_html`, brand kit is auto-applied to base content HTML
- Status can be `"draft"` or `"published"` (default: `"draft"`)
- Publishing enforces one published customization per (company_id, base_content_id) pair; existing published versions are demoted to draft

#### Update customization

`PUT /api/customizations.php?id=X`

**Request (application/json):** Partial update — include only fields to change.

#### Delete customization

`DELETE /api/customizations.php?id=X`

---

### 10. Apply Brand Kit (Preview)

**Endpoint:** `POST /api/apply-brand-kit.php`

**Authentication:** Bearer token + VPN

**Description:** Apply a brand kit to content HTML and return the transformed result (preview only, does not persist).

**Request (application/json):**
```json
{
  "content_id": "uuid",
  "brand_kit_id": "uuid"
}
```

**Response:**
```json
{
  "success": true,
  "html": "<html>...transformed...</html>",
  "transformations": [
    {
      "selector": "img.logo",
      "property": "src",
      "old_value": "/images/old-logo.png",
      "new_value": "https://s3.../new-logo.png"
    },
    {
      "selector": "inline-style",
      "property": "background-color",
      "old_value": "#333333",
      "new_value": "#4F46E5"
    }
  ]
}
```

**Transformations applied:**
1. Logo replacement (img tags with class containing "logo")
2. Primary color on buttons, headers, table cells (background-color)
3. Font-family prepending with primary font
4. @font-face injection for custom font URLs

---

### 11. Translate Content

**Endpoint:** `POST /api/translate-content.php`

**Authentication:** Bearer token + VPN

**Description:** Translate content HTML using Claude AI. Supports preview (translate only) and save (create translated content record).

#### Preview translation

**Request (application/json):**
```json
{
  "action": "translate",
  "content_id": "uuid",
  "target_language": "es"
}
```

**Response:**
```json
{
  "success": true,
  "translated_html": "<html>...translated...</html>",
  "source_language": "en",
  "target_language": "es",
  "target_language_name": "Spanish"
}
```

#### Save translation

**Request (application/json):**
```json
{
  "action": "save",
  "content_id": "uuid",
  "target_language": "fr",
  "translated_html": "<html>...translated...</html>"
}
```

Creates a new content record and a `content_translations` linking record.

**Supported languages:** en, es, fr, de, it, pt, nl, pl, sv, da, no, fi, ja, ko, zh, ar, hi, th, vi, id, ms, tl, tr, ru

---

### 12. Inject Threat Indicators

**Endpoint:** `POST /api/inject-threats.php`

**Authentication:** Bearer token + VPN

**Description:** Use Claude AI to inject phishing threat indicators into email HTML.

**Request (application/json):**
```json
{
  "html": "<html>...email...</html>",
  "threat_types": ["urgency-tactic", "suspicious-link", "spoofed-email-address"],
  "intensity": "subtle"
}
```

Or reference existing content:
```json
{
  "content_id": "uuid",
  "threat_types": ["urgency-tactic"],
  "intensity": "obvious"
}
```

**Parameters:**
- `html` or `content_id` (required): the email HTML to modify
- `threat_types` (required): array of cue names from the threat taxonomy
- `intensity` (optional): `"subtle"` (default) or `"obvious"`

**Response:**
```json
{
  "success": true,
  "html": "<html>...modified...</html>",
  "injected_cues": ["urgency-tactic", "suspicious-link"],
  "difficulty": 3
}
```

---

### 13. Threat Taxonomy (Reference Data)

**Endpoint:** `GET /api/threat-taxonomy.php`

**Authentication:** None required (public reference data)

**Description:** Returns the full NIST Phish Scale threat taxonomy including cue categories and difficulty levels.

**Response:**
```json
{
  "success": true,
  "cue_types": {
    "Error": [
      { "name": "spelling-error", "label": "Spelling error", "criteria": "...", "color": "#..." }
    ],
    "Technical indicator": [...],
    "Visual presentation": [...],
    "Language and content": [...],
    "Common tactic": [...]
  },
  "difficulty_levels": [
    { "level": 1, "label": "Very easy to detect", "description": "..." }
  ]
}
```

---

### `customization_data` JSON Schema

The `customization_data` field on content customizations stores a JSON object tracking what changes were made:

```json
{
  "brand_kit_applied": true,
  "brand_kit_id": "uuid",
  "element_edits": [
    {
      "selector": "img.logo",
      "property": "src",
      "old_value": "/images/old-logo.png",
      "new_value": "https://s3.../logo.png"
    },
    {
      "selector": "inline-style",
      "property": "background-color",
      "old_value": "#333333",
      "new_value": "#4F46E5"
    },
    {
      "selector": "inline-style",
      "property": "font-family",
      "old_value": "Arial, sans-serif",
      "new_value": "'Roboto', Arial, sans-serif"
    }
  ]
}
```

---

## Error Responses

All endpoints may return error responses in this format:

```json
{
  "error": "Error title",
  "message": "Detailed error message"
}
```

Common HTTP status codes:
- `200` - Success
- `400` - Bad Request (missing/invalid parameters)
- `404` - Not Found (content/tracking link not found)
- `405` - Method Not Allowed
- `500` - Internal Server Error

---

## Content Launch Flow

1. **Upload Content** → POST to `/api/upload.php`
   - Returns `content_id`
   - Content is processed and tagged by Claude API
   - Tags stored in database

2. **Generate Launch Link** → POST to `/api/launch-link.php`
   - Provide `recipient_id` and `content_id`
   - Returns `launch_url`

3. **User Opens Launch Link** → GET `/launch.php?tid={tracking_link_id}`
   - Automatically tracks view
   - Displays content with embedded tracking

4. **User Interacts with Content**
   - Tracking script automatically calls `/api/track-interaction.php`
   - Each tagged element interaction is recorded

5. **User Completes Content**
   - `RecordTest(score)` function is called
   - Triggers POST to `/api/record-score.php`
   - Score recorded, tag scores updated
   - Event published to SNS

---

## SNS Message Format

When content is completed, an SNS message is published:

```json
{
  "tracking_link_id": "x9y8z7w6...",
  "recipient_id": "user-123",
  "content_id": "a1b2c3d4...",
  "events": [
    {
      "event": "viewed",
      "timestamp": "2025-11-04T10:30:00Z"
    }
  ],
  "interactions": [
    {
      "tag": "ransomware",
      "type": "click",
      "value": "correct",
      "success": true,
      "timestamp": "2025-11-04T10:31:00Z"
    }
  ],
  "final_score": 100,
  "content_type": "training",
  "completed_at": "2025-11-04T10:32:00Z"
}
```

---

## Database Schema

See `database/schema.sql` for complete schema.

### Key Tables:

- **content** - Uploaded content
- **content_tags** - Tags associated with content
- **training_tracking** - Training session tracking (in global schema, managed by external system)
- **content_interactions** - Individual tagged interactions
- **recipient_tag_scores** - Cumulative tag scores per recipient
- **brand_kits** - Company brand kits (colors, fonts, logo)
- **brand_kit_assets** - Uploaded brand kit files (logos, fonts)
- **content_customizations** - Company-specific content customizations (draft/published)
- **content_translations** - Links translated content to source content

**Note:** Tracking state is determined by datetime fields rather than status flags:
- `training_viewed_at` - User viewed training content
- `training_completed_at` - User completed training
- `training_reported_at` - User reported email as phishing
- `follow_on_viewed_at` / `follow_on_completed_at` - Follow-on content tracking
- `data_entered_at` - User entered data in phishing form

---

## SCORM Integration

The platform hijacks the standard SCORM `RecordTest()` function:

```javascript
// In SCORM content:
RecordTest(100); // Automatically recorded and sent to API
```

The tracking script injected into content provides:
- `RecordTest(score)` - Record final score
- Automatic interaction tracking on tagged elements

---

## Tagging System

### Educational Content (SCORM/HTML)

Claude API adds `data-tag` attributes:

```html
<input type="text" data-tag="password-security" />
<button data-tag="ransomware">Submit</button>
```

### Email Content (Phishing)

Claude API adds `data-cue` attributes based on NIST Phish Scales:

```html
<a href="..." data-cue="technical:domain-spoofing">Click here</a>
<span data-cue="language:urgency-tactic">Act now!</span>
<img data-cue="visual:logo-issue" src="..." />
```

---

## Rate Limits

No rate limits currently implemented. Should be added for production.

## CORS

CORS is enabled for all origins (`Access-Control-Allow-Origin: *`).
Restrict this in production.
