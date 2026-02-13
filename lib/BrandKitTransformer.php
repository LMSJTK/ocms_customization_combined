<?php
/**
 * Brand Kit Transformer
 *
 * Applies brand kit colors, logos, and fonts to content HTML.
 * Used by:
 *   - public/api/apply-brand-kit.php (preview/transform)
 *   - public/api/customizations.php (auto-apply on create)
 */

class BrandKitTransformer {

    /**
     * Apply a brand kit to HTML content
     *
     * @param string $html The source HTML
     * @param array $brandKit Brand kit record from DB (with decoded JSONB fields)
     * @return array ['html' => transformed HTML, 'transformations' => array of changes]
     */
    public static function apply(string $html, array $brandKit): array {
        $transformations = [];

        // 1. Replace logo: <img class="...logo..."> src → brand kit logo
        if (!empty($brandKit['logo_url'])) {
            $logoUrl = $brandKit['logo_url'];
            $html = preg_replace_callback(
                '/<img([^>]*class=["\'][^"\']*\blogo\b[^"\']*["\'][^>]*)>/i',
                function ($matches) use ($logoUrl, &$transformations) {
                    $imgTag = $matches[1];
                    $oldSrc = '';
                    if (preg_match('/src=["\']([^"\']*)["\']/', $imgTag, $srcMatch)) {
                        $oldSrc = $srcMatch[1];
                    }
                    if (preg_match('/src=["\'][^"\']*["\']/i', $imgTag)) {
                        $imgTag = preg_replace('/src=["\'][^"\']*["\']/i', 'src="' . $logoUrl . '"', $imgTag);
                    } else {
                        $imgTag .= ' src="' . $logoUrl . '"';
                    }
                    $transformations[] = [
                        'selector' => 'img.logo',
                        'property' => 'src',
                        'old_value' => $oldSrc,
                        'new_value' => $logoUrl
                    ];
                    return '<img' . $imgTag . '>';
                },
                $html
            );
        }

        // 2. Replace primary color on known patterns:
        //    - CTA buttons (background-color in inline style)
        //    - Header backgrounds
        //    - Elements with common primary-color patterns
        if (!empty($brandKit['primary_color'])) {
            $primaryColor = $brandKit['primary_color'];

            // Replace background-color in inline styles on buttons, CTAs, headers
            // Targets: <a>, <button>, <td>, <th>, <div> with inline background-color
            $html = preg_replace_callback(
                '/(<(?:a|button|td|th|div|span)[^>]*style=["\'][^"\']*)(background-color:\s*)(#[a-f0-9]{3,6})/i',
                function ($matches) use ($primaryColor, &$transformations) {
                    $transformations[] = [
                        'selector' => 'inline-style',
                        'property' => 'background-color',
                        'old_value' => $matches[3],
                        'new_value' => $primaryColor
                    ];
                    return $matches[1] . $matches[2] . $primaryColor;
                },
                $html
            );

            // Replace background-color on table cells commonly used as headers
            // (inline styles with background and text that's likely a header)
            $html = preg_replace_callback(
                '/(<t[dh][^>]*style=["\'][^"\']*)(background:\s*)(#[a-f0-9]{3,6})/i',
                function ($matches) use ($primaryColor, &$transformations) {
                    $transformations[] = [
                        'selector' => 'table-cell',
                        'property' => 'background',
                        'old_value' => $matches[3],
                        'new_value' => $primaryColor
                    ];
                    return $matches[1] . $matches[2] . $primaryColor;
                },
                $html
            );
        }

        // 3. Replace font-family declarations with primary_font
        if (!empty($brandKit['primary_font'])) {
            $primaryFont = $brandKit['primary_font'];

            $html = preg_replace_callback(
                '/(font-family:\s*)([^;}"\']+)/i',
                function ($matches) use ($primaryFont, &$transformations) {
                    $oldFont = trim($matches[2]);
                    $transformations[] = [
                        'selector' => 'inline-style',
                        'property' => 'font-family',
                        'old_value' => $oldFont,
                        'new_value' => $primaryFont
                    ];
                    return $matches[1] . "'" . $primaryFont . "', " . $oldFont;
                },
                $html
            );
        }

        // 4. Inject @font-face rules for custom font URLs
        $customFontUrls = $brandKit['custom_font_urls'] ?? [];
        if (is_string($customFontUrls)) {
            $customFontUrls = json_decode($customFontUrls, true) ?? [];
        }

        if (!empty($customFontUrls) && !empty($brandKit['primary_font'])) {
            $fontFaceRules = '';
            foreach ($customFontUrls as $fontUrl) {
                $ext = strtolower(pathinfo(parse_url($fontUrl, PHP_URL_PATH) ?: $fontUrl, PATHINFO_EXTENSION));
                $format = [
                    'woff2' => 'woff2',
                    'woff' => 'woff',
                    'ttf' => 'truetype',
                    'otf' => 'opentype'
                ][$ext] ?? 'woff2';

                $fontFaceRules .= "@font-face { font-family: '{$brandKit['primary_font']}'; src: url('{$fontUrl}') format('{$format}'); }\n";
            }

            // Inject into <head> or prepend to HTML
            $styleTag = '<style>' . $fontFaceRules . '</style>';
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $styleTag . "\n</head>", $html);
            } else {
                $html = $styleTag . "\n" . $html;
            }

            $transformations[] = [
                'selector' => 'head',
                'property' => '@font-face',
                'old_value' => null,
                'new_value' => $brandKit['primary_font'] . ' (' . count($customFontUrls) . ' files)'
            ];
        }

        return [
            'html' => $html,
            'transformations' => $transformations
        ];
    }
}
