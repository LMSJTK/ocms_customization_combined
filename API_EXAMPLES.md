# API Usage Examples

This document provides CURL examples for uploading different content types to the OCMS API.

## Base URL

Replace `http://localhost` with actual base URL:

```bash
API_BASE="http://localhost/api"
```

## Authentication

Some API endpoints require bearer token authentication (configured in `config/config.php`). Set token:

```bash
BEARER_TOKEN="secure_random_token_here"
```

**Protected Endpoints (require bearer token + VPN):**
- `/upload.php` - Content uploads
- `/list-content.php` - Content listing
- `/launch-link.php` - Tracking link generation
- `/brand-kits.php` - Brand kit CRUD
- `/brand-kit-upload.php` - Brand kit asset upload
- `/customizations.php` - Customization CRUD + preview links
- `/apply-brand-kit.php` - Brand kit preview transform
- `/translate-content.php` - AI content translation
- `/inject-threats.php` - AI threat indicator injection

**Public Endpoints (no authentication):**
- `/record-score.php` - Score recording (called by end users)
- `/track-interaction.php` - Interaction tracking (called by end users)
- `/track-view.php` - View tracking (called by end users)
- `/sns-messages.php` - SNS webhook receiver
- `/threat-taxonomy.php` - NIST Phish Scale reference data

For protected endpoints, include the Authorization header:

```bash
-H "Authorization: Bearer ${BEARER_TOKEN}"
```

## Upload Landing Page

Landing pages are HTML content designated for use as landing pages (e.g., after clicking email links).

### Example 1: Simple Landing Page

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=landing" \
  -F "title=Welcome Landing Page" \
  -F "description=Landing page for campaign" \
  -F "company_id=acme-corp" \
  -F "html_content=<!DOCTYPE html>
<html>
<head>
    <title>Welcome</title>
</head>
<body>
    <h1>Welcome to our security training!</h1>
    <p>You successfully identified the phishing attempt.</p>
</body>
</html>"
```

### Example 2: Landing Page from File

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=landing" \
  -F "title=Training Complete" \
  -F "description=Success landing page" \
  -F "company_id=acme-corp" \
  -F "html_content=<landing.html"
```

### Response Format

```json
{
  "success": true,
  "content_id": "a1b2c3d4e5f6...",
  "message": "Landing page processed successfully",
  "tags": ["security", "training"],
  "path": "a1b2c3d4e5f6.../index.php",
  "preview_url": "http://localhost/public/launch.php?tid=preview_tracking_id"
}
```

## Upload Email (Phishing Training)

### Example 1: Email without Attachment

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=email" \
  -F "title=Phishing Test - Fake Invoice" \
  -F "description=Invoice phishing simulation" \
  -F "company_id=acme-corp" \
  -F "email_subject=Urgent: Invoice Payment Required" \
  -F "email_from=billing@fake-company.com" \
  -F "email_html=<!DOCTYPE html>
<html>
<head><title>Invoice</title></head>
<body>
    <p>Dear Customer,</p>
    <p>Please click here to view your invoice: <a href='http://malicious.com'>View Invoice</a></p>
</body>
</html>"
```

### Example 2: Email with Attachment

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=email" \
  -F "title=Phishing Test - Fake Invoice with PDF" \
  -F "description=Invoice phishing simulation with malicious attachment" \
  -F "company_id=acme-corp" \
  -F "email_subject=Urgent: Invoice Payment Required" \
  -F "email_from=billing@fake-company.com" \
  -F "email_html=<!DOCTYPE html>
<html>
<head><title>Invoice</title></head>
<body>
    <p>Dear Customer,</p>
    <p>Please see the attached invoice for payment.</p>
    <p>Click here to download: <a href='http://malicious.com'>Download Invoice</a></p>
</body>
</html>" \
  -F "attachment=@/path/to/fake_invoice.pdf"
```

### Response Format

```json
{
  "success": true,
  "content_id": "a1b2c3d4e5f6...",
  "message": "Email content processed successfully",
  "cues": ["urgent-language", "suspicious-link", "unknown-sender"],
  "difficulty": 2,
  "path": "a1b2c3d4e5f6.../index.php",
  "preview_url": "http://localhost/public/launch.php?tid=preview_tracking_id",
  "attachment_filename": "fake_invoice.pdf",
  "attachment_size": 45678
}
```

## Upload SCORM Package

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=scorm" \
  -F "title=Security Awareness Training" \
  -F "description=Interactive SCORM course" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/scorm-package.zip"
```

## Upload HTML Package

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=html" \
  -F "title=Interactive HTML Training" \
  -F "description=HTML5 based training module" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/html-package.zip"
```

## Upload Raw HTML

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=raw_html" \
  -F "title=Simple HTML Page" \
  -F "description=Basic HTML content" \
  -F "company_id=acme-corp" \
  -F "html_content=<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body><h1>Hello World</h1></body>
</html>"
```

## Upload Video

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=video" \
  -F "title=Security Training Video" \
  -F "description=Introduction to cybersecurity" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/video.mp4"
```

## Generate Launch Link

After uploading content, create a launch link for a recipient:

```bash
curl -X POST "${API_BASE}/launch-link.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient_id": "user123",
    "content_id": "a1b2c3d4e5f6...",
    "company_id": "acme-corp",
    "email": "user@company.com",
    "first_name": "John",
    "last_name": "Doe"
  }'
```

### Response Format

```json
{
  "success": true,
  "tracking_link_id": "tracking123...",
  "launch_url": "http://localhost/public/launch.php?tid=tracking123...",
  "content": {
    "id": "a1b2c3d4e5f6...",
    "title": "Welcome Landing Page",
    "type": "landing"
  }
}
```

## List All Content

```bash
curl -X GET "${API_BASE}/list-content.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Response Format

```json
{
  "success": true,
  "content": [
    {
      "id": "a1b2c3d4e5f6...",
      "company_id": "acme-corp",
      "title": "Welcome Landing Page",
      "description": "Landing page for campaign",
      "content_type": "landing",
      "content_preview": "http://localhost/public/launch.php?tid=preview_tracking_id",
      "content_url": "a1b2c3d4e5f6.../index.php",
      "tags": ["security", "training"],
      "difficulty": null,
      "created_at": "2025-01-15 10:30:00",
      "updated_at": "2025-01-15 10:30:00"
    }
  ]
}
```

## Track Interaction

```bash
curl -X POST "${API_BASE}/track-interaction.php" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_link_id": "tracking123...",
    "tag_name": "phishing",
    "interaction_type": "click",
    "success": true,
    "interaction_value": "suspicious-link"
  }'
```

## Record Score

```bash
curl -X POST "${API_BASE}/record-score.php" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_link_id": "tracking123...",
    "score": 85,
    "content_id": "a1b2c3d4e5f6..."
  }'
```

### Response Format

```json
{
  "success": true,
  "score": 85,
  "content_type": "training",
  "message": "Score recorded successfully"
}
```

**Notes:**
- `content_id` is optional but recommended to distinguish training vs follow-on content
- `content_type` will be `"training"` or `"follow_on"`
- First score is preserved; re-takes return existing score with `"already_recorded": true`

## Content Types Summary

| Type | Description | Input Format | Use Case |
|------|-------------|--------------|----------|
| `landing` | Landing page HTML | `html_content` (form field) | Post-click landing pages |
| `email` | Phishing training email | `email_html`, `email_subject`, `email_from` | Phishing simulations |
| `scorm` | SCORM package | `file` (ZIP upload) | E-learning courses |
| `html` | HTML package | `file` (ZIP upload) | Interactive HTML content |
| `raw_html` | Simple HTML | `html_content` (form field) | Basic HTML pages |
| `video` | Video file | `file` (MP4/WEBM/OGG) | Training videos |

---

## Brand Kit Management

### Create a Brand Kit

```bash
curl -X POST "${API_BASE}/brand-kits.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": "acme-corp",
    "name": "Corporate Brand",
    "primary_color": "#4F46E5",
    "secondary_color": "#10B981",
    "accent_color": "#F59E0B",
    "saved_colors": ["#4F46E5", "#10B981", "#F59E0B"],
    "primary_font": "Roboto",
    "is_default": true
  }'
```

### List Brand Kits for a Company

```bash
curl -X GET "${API_BASE}/brand-kits.php?company_id=acme-corp" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Get Default Brand Kit

```bash
curl -X GET "${API_BASE}/brand-kits.php?company_id=acme-corp&default=true" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Get Single Brand Kit (with Assets)

```bash
curl -X GET "${API_BASE}/brand-kits.php?id=BRAND_KIT_ID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Update a Brand Kit

```bash
curl -X PUT "${API_BASE}/brand-kits.php?id=BRAND_KIT_ID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "primary_color": "#7C3AED",
    "primary_font": "Open Sans"
  }'
```

### Delete a Brand Kit

```bash
curl -X DELETE "${API_BASE}/brand-kits.php?id=BRAND_KIT_ID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

---

## Brand Kit Asset Upload

### Upload a Logo

```bash
curl -X POST "${API_BASE}/brand-kit-upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "brand_kit_id=BRAND_KIT_ID" \
  -F "asset_type=logo" \
  -F "file=@/path/to/company-logo.png"
```

### Upload a Custom Font

```bash
curl -X POST "${API_BASE}/brand-kit-upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "brand_kit_id=BRAND_KIT_ID" \
  -F "asset_type=font" \
  -F "file=@/path/to/custom-font.woff2"
```

---

## Content Customizations

### Create a Customization (auto-apply brand kit)

```bash
curl -X POST "${API_BASE}/customizations.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": "acme-corp",
    "base_content_id": "CONTENT_UUID",
    "brand_kit_id": "BRAND_KIT_UUID",
    "title": "Acme Custom Email v1",
    "status": "draft"
  }'
```

When `brand_kit_id` is provided but no `customized_html`, the brand kit is automatically applied to the base content.

### Create a Customization (with explicit HTML)

```bash
curl -X POST "${API_BASE}/customizations.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": "acme-corp",
    "base_content_id": "CONTENT_UUID",
    "customized_html": "<html>...custom content...</html>",
    "title": "Acme Custom Email v2",
    "status": "draft",
    "customization_data": {
      "brand_kit_applied": false,
      "element_edits": []
    }
  }'
```

### List Customizations for a Company

```bash
curl -X GET "${API_BASE}/customizations.php?company_id=acme-corp" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### List Customizations for a Specific Template

```bash
curl -X GET "${API_BASE}/customizations.php?company_id=acme-corp&base_content_id=CONTENT_UUID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Get Single Customization

```bash
curl -X GET "${API_BASE}/customizations.php?id=CUSTOMIZATION_UUID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Generate Preview Link

```bash
curl -X GET "${API_BASE}/customizations.php?id=CUSTOMIZATION_UUID&action=preview" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

Returns a `preview_url` that can be opened in a browser to view the customization. Works for both draft and published customizations.

### Update a Customization

```bash
curl -X PUT "${API_BASE}/customizations.php?id=CUSTOMIZATION_UUID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "customized_html": "<html>...updated...</html>",
    "status": "published"
  }'
```

Publishing enforces one published customization per (company, base content) pair.

### Delete a Customization

```bash
curl -X DELETE "${API_BASE}/customizations.php?id=CUSTOMIZATION_UUID" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

---

## Apply Brand Kit (Preview)

Preview how a brand kit would transform content (does not persist changes):

```bash
curl -X POST "${API_BASE}/apply-brand-kit.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "content_id": "CONTENT_UUID",
    "brand_kit_id": "BRAND_KIT_UUID"
  }'
```

### Response Format

```json
{
  "success": true,
  "html": "<html>...transformed...</html>",
  "transformations": [
    {
      "selector": "img.logo",
      "property": "src",
      "old_value": "/images/old-logo.png",
      "new_value": "https://s3.../logo.png"
    }
  ]
}
```

---

## Content Translation

### Preview Translation

```bash
curl -X POST "${API_BASE}/translate-content.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "translate",
    "content_id": "CONTENT_UUID",
    "target_language": "es"
  }'
```

### Save Translation (Creates New Content Record)

```bash
curl -X POST "${API_BASE}/translate-content.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "save",
    "content_id": "CONTENT_UUID",
    "target_language": "fr",
    "translated_html": "<html>...translated HTML...</html>"
  }'
```

Supported language codes: en, es, fr, de, it, pt, nl, pl, sv, da, no, fi, ja, ko, zh, ar, hi, th, vi, id, ms, tl, tr, ru

---

## Threat Taxonomy (Reference Data)

```bash
curl -X GET "${API_BASE}/threat-taxonomy.php"
```

No authentication required. Returns NIST Phish Scale cue categories and difficulty levels.

---

## Inject Threat Indicators

### Inject Using Direct HTML

```bash
curl -X POST "${API_BASE}/inject-threats.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "html": "<html><body><p>Please click here to verify your account.</p></body></html>",
    "threat_types": ["urgency-tactic", "suspicious-link"],
    "intensity": "subtle"
  }'
```

### Inject Using Content ID

```bash
curl -X POST "${API_BASE}/inject-threats.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "content_id": "CONTENT_UUID",
    "threat_types": ["spoofed-email-address", "too-good-to-be-true", "impersonation"],
    "intensity": "obvious"
  }'
```

### Response Format

```json
{
  "success": true,
  "html": "<html>...modified with threat indicators...</html>",
  "injected_cues": ["urgency-tactic", "suspicious-link"],
  "difficulty": 3
}
```

---

## Notes

- All uploads automatically generate a preview link (uses recipient_id="preview")
- Content IDs are automatically generated (32-character hex string)
- Preview links don't affect tracking statistics
- Landing pages use the same processing as raw HTML but are designated as 'landing' type for organizational purposes
- Brand kit operations require the customization tables to be migrated (see `scripts/migrate-add-customization-tables.php`)
- Customization preview links support both draft and published statuses via the `customization_id` query parameter on launch.php
