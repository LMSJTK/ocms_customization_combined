<?php
/**
 * Customization Portal
 *
 * Single-page application for managing brand kits, customizing content templates,
 * and publishing customized versions. Covers Stories 4.1-4.4.
 *
 * URL params:
 *   ?company_id=X         — required, identifies the company
 *   ?content_id=X         — optional, opens directly in editor for this content
 *   ?customization_id=X   — optional, opens an existing customization for editing
 */

require_once '/var/www/html/public/api/bootstrap.php';

$companyId = $_GET['company_id'] ?? '';
$openContentId = $_GET['content_id'] ?? '';
$openCustomizationId = $_GET['customization_id'] ?? '';
$apiBase = rtrim($config['app']['base_url'], '/') . '/api';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cofense Customization Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.ckeditor.com/ckeditor5/47.5.0/ckeditor5.css" crossorigin>
    <link rel="stylesheet" href="https://cdn.ckeditor.com/ckeditor5-premium-features/47.5.0/ckeditor5-premium-features.css" crossorigin>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .main-gradient { background-image: linear-gradient(to bottom, rgba(230,240,255,0.5), rgba(248,249,250,1)); }
        .tab-active { background-color: white; color: #3B82F6; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 9999px; }
        .filter-dropdown { background: white; border: 1px solid #e5e7eb; border-radius: .5rem; padding: .5rem 1rem; cursor: pointer; font-size: .875rem; }
        .file-drop-zone { border: 2px dashed #d1d5db; border-radius: .5rem; padding: 2.5rem; text-align: center; background: #f9fafb; transition: border-color .2s; }
        .file-drop-zone.dragover { border-color: #3B82F6; background: #eff6ff; }
        .editor-sidebar { width: 340px; background: #f3f4f6; overflow-y: auto; }
        .email-preview-container { flex: 1; background: #e5e7eb; overflow-y: auto; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .status-draft { background: #FEF3C7; color: #92400E; }
        .status-published { background: #D1FAE5; color: #065F46; }
        .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999; padding: .75rem 1.5rem; border-radius: .5rem; color: white; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,.15); transform: translateY(100px); opacity: 0; transition: all .3s ease; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background: #059669; }
        .toast.error { background: #DC2626; }
        .spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* CKEditor 5 Document Editor Styles */
        :root {
            --ck-sidebar-width: 270px;
            --ck-editor-height: 100%;
        }
        .ckeditor-main-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }
        .editor-container_document-editor {
            border: 1px solid var(--ck-color-base-border);
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .editor-container_document-editor .editor-container__toolbar {
            display: flex;
            position: relative;
            box-shadow: 0 2px 3px hsla(0, 0%, 0%, 0.078);
        }
        .editor-container_document-editor .editor-container__toolbar > .ck.ck-toolbar {
            flex-grow: 1;
            width: 0;
            border-bottom-right-radius: 0;
            border-bottom-left-radius: 0;
            border-top: 0;
            border-left: 0;
            border-right: 0;
        }
        .editor-container_document-editor .editor-container__menu-bar > .ck.ck-menu-bar {
            border-bottom-right-radius: 0;
            border-bottom-left-radius: 0;
            border-top: 0;
            border-left: 0;
            border-right: 0;
        }
        .editor-container_document-editor .editor-container__editor-wrapper {
            overflow-y: scroll;
            background: var(--ck-color-base-foreground);
            flex: 1;
        }
        .editor-container_document-editor .editor-container__editor {
            margin-top: 28px;
            margin-bottom: 28px;
            height: 100%;
        }
        .editor-container_document-editor .editor-container__editor .ck.ck-editor__editable {
            box-sizing: border-box;
            min-width: calc(210mm + 2px);
            max-width: calc(210mm + 2px);
            min-height: 297mm;
            height: fit-content;
            padding: 20mm 12mm;
            border: 1px hsl(0, 0%, 82.7%) solid;
            background: hsl(0, 0%, 100%);
            box-shadow: 0 2px 3px hsla(0, 0%, 0%, 0.078);
            flex: 1 1 auto;
            margin-left: 72px;
            margin-right: 72px;
        }
        .editor-container__sidebar {
            min-width: var(--ck-sidebar-width);
            max-width: var(--ck-sidebar-width);
            margin-top: 28px;
            margin-left: 10px;
            margin-right: 10px;
        }
        .editor-container__editable-wrapper {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            flex: 1;
            overflow: hidden;
        }
        .editor-container__editor-wrapper {
            display: flex;
            width: fit-content;
        }
        @media print {
            body { margin: 0 !important; }
        }
    </style>
</head>
<body class="text-gray-800">
    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <!-- Header -->
    <header class="bg-white/80 backdrop-blur-md border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-2 cursor-pointer" onclick="showView('portal')">
                    <svg class="h-8 w-auto" viewBox="0 0 35 28" fill="none"><path d="M34.9999 13.9131C34.9999 13.6218 34.953 13.3306 34.8593 13.0393C34.5783 12.1656 32.7471 11.2919 29.3647 11.2919C25.9824 11.2919 24.1512 12.1656 23.8701 13.0393C23.7764 13.3306 23.7296 13.6218 23.7296 13.9131C23.7296 14.2044 23.7764 14.4956 23.8701 14.7869C24.1512 15.6606 25.9824 16.5344 29.3647 16.5344C32.7471 16.5344 34.5783 15.6606 34.8593 14.7869C34.953 14.4956 34.9999 14.2044 34.9999 13.9131ZM17.4999 27.8262C27.1633 27.8262 34.9999 21.5878 34.9999 13.9131C34.9999 6.23842 27.1633 0 17.4999 0C7.83655 0 0 6.23842 0 13.9131C0 21.5878 7.83655 27.8262 17.4999 27.8262ZM11.2701 13.9131C11.2701 13.6218 11.2233 13.3306 11.1296 13.0393C10.8485 12.1656 9.01732 11.2919 5.63497 11.2919C2.25263 11.2919 0.421404 12.1656 0.140282 13.0393C0.0465563 13.3306 0 13.6218 0 13.9131C0 14.2044 0.0465563 14.4956 0.140282 14.7869C0.421404 15.6606 2.25263 16.5344 5.63497 16.5344C9.01732 16.5344 10.8485 15.6606 11.1296 14.7869C11.2233 14.4956 11.2701 14.2044 11.2701 13.9131Z" fill="#0052CC"/></svg>
                    <span class="text-sm font-semibold text-gray-500">COMMAND CENTER</span>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="showView('portal')" class="text-gray-600 hover:text-blue-600 text-sm"><i class="fa-solid fa-home mr-1"></i> Home</button>
                    <button onclick="showView('upload')" class="text-gray-600 hover:text-blue-600 text-sm"><i class="fa-solid fa-cloud-arrow-up mr-1"></i> Upload</button>
                    <button onclick="showView('brand-kit')" class="text-gray-600 hover:text-blue-600 text-sm"><i class="fa-solid fa-palette mr-1"></i> Brand Kit</button>
                </div>
            </div>
        </div>
    </header>

    <main>
        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- VIEW: Portal Gallery (Story 4.1)                          -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div id="portal-view">
            <div class="main-gradient pt-12 pb-20">
                <div class="text-center max-w-4xl mx-auto px-4">
                    <h1 class="text-4xl md:text-5xl font-bold tracking-tight text-gray-900">Customization Portal</h1>
                    <p class="mt-4 text-lg text-gray-600">Browse templates, apply your brand kit, and publish customized content.</p>
                </div>
            </div>

            <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 -mt-10">
                <!-- Category tabs -->
                <div class="bg-gray-100 p-1.5 rounded-full flex items-center justify-center space-x-1 max-w-3xl mx-auto">
                    <button class="category-tab px-4 py-2 text-sm font-medium tab-active" data-type="email"><i class="fa-regular fa-envelope mr-1.5"></i>Emails</button>
                    <button class="category-tab px-4 py-2 text-sm font-medium text-gray-600" data-type="training"><i class="fa-solid fa-graduation-cap mr-1.5"></i>Education</button>
                    <button class="category-tab px-4 py-2 text-sm font-medium text-gray-600" data-type="landing"><i class="fa-solid fa-file-lines mr-1.5"></i>Landing Pages</button>
                    <button class="category-tab px-4 py-2 text-sm font-medium text-gray-600" data-type="html"><i class="fa-solid fa-code mr-1.5"></i>HTML</button>
                </div>

                <!-- Search & Filters -->
                <div class="bg-white p-5 rounded-lg shadow-sm mt-6">
                    <div class="flex space-x-3">
                        <div class="relative flex-grow">
                            <i class="fa-solid fa-magnifying-glass absolute top-1/2 left-3 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" id="search-input" placeholder="Search templates..." class="w-full pl-9 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button onclick="loadTemplates()" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-semibold text-sm hover:bg-blue-700">Search</button>
                    </div>
                    <div class="flex items-center justify-between mt-3">
                        <div class="flex items-center space-x-2" id="filter-bar"></div>
                        <div class="flex items-center space-x-4">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" id="my-content-toggle" class="sr-only peer">
                                <div class="relative w-10 h-5 bg-gray-200 peer-checked:bg-blue-600 rounded-full transition-colors">
                                    <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                                </div>
                                <span class="ml-2 text-sm font-medium">My Content</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Template grid -->
                <div id="template-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5 mt-6 pb-12">
                    <div class="col-span-full text-center py-12 text-gray-400"><div class="spinner" style="border-color: #d1d5db; border-top-color: #6b7280; width: 2rem; height: 2rem;"></div><p class="mt-3">Loading templates...</p></div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- VIEW: Brand Kit Manager (Story 4.2)                       -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div id="brand-kit-view" class="hidden">
            <div class="max-w-5xl mx-auto p-8">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold">Brand Kit Manager</h1>
                        <p class="text-gray-600 mt-1">Manage your brand assets and apply them across all templates.</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button onclick="resetBrandKit()" class="text-sm text-gray-600 hover:text-gray-900"><i class="fa-solid fa-arrow-rotate-left mr-1"></i>Reset</button>
                        <button onclick="saveBrandKit()" id="save-brand-kit-btn" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold text-sm hover:bg-blue-700">Save Brand Kit</button>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-lg shadow-sm grid grid-cols-1 md:grid-cols-2 gap-10">
                    <!-- Left Column: Logo & Fonts -->
                    <div>
                        <h2 class="text-lg font-semibold">Logo Upload</h2>
                        <div id="logo-preview" class="mt-3 hidden"><img id="logo-preview-img" class="max-h-20 rounded" src="" alt="Logo"></div>
                        <div id="logo-drop-zone" class="mt-3 file-drop-zone">
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-gray-400"></i>
                            <p class="mt-2 font-semibold text-sm">Upload a file or drag and drop</p>
                            <p class="text-xs text-gray-500">JPG, PNG, GIF, WebP up to 10MB</p>
                            <input type="file" id="logo-file-input" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                        </div>

                        <h2 class="text-lg font-semibold mt-8">Primary Font</h2>
                        <select id="primary-font-select" class="mt-2 w-full p-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">-- Select Font --</option>
                            <option value="Inter">Inter</option>
                            <option value="Roboto">Roboto</option>
                            <option value="Poppins">Poppins</option>
                            <option value="Open Sans">Open Sans</option>
                            <option value="Lato">Lato</option>
                            <option value="Arial">Arial</option>
                        </select>

                        <h2 class="text-lg font-semibold mt-8">Upload Custom Font</h2>
                        <div id="font-drop-zone" class="mt-3 file-drop-zone">
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-gray-400"></i>
                            <p class="mt-2 font-semibold text-sm">Upload a file or drag and drop</p>
                            <p class="text-xs text-gray-500">WOFF, WOFF2, TTF, OTF up to 10MB</p>
                            <input type="file" id="font-file-input" accept=".woff,.woff2,.ttf,.otf" class="hidden">
                        </div>
                    </div>

                    <!-- Right Column: Colors -->
                    <div>
                        <h2 class="text-lg font-semibold">Brand Colors</h2>
                        <p class="text-sm text-gray-500">Applied to buttons, headers, and highlights.</p>

                        <div class="mt-4 space-y-3">
                            <div class="flex items-center space-x-3">
                                <label class="text-sm font-medium w-24">Primary</label>
                                <input type="color" id="primary-color-input" value="#4F46E5" class="w-10 h-10 rounded cursor-pointer border">
                                <input type="text" id="primary-color-hex" value="#4F46E5" class="w-24 p-1.5 border rounded text-sm font-mono">
                            </div>
                            <div class="flex items-center space-x-3">
                                <label class="text-sm font-medium w-24">Secondary</label>
                                <input type="color" id="secondary-color-input" value="#6B7280" class="w-10 h-10 rounded cursor-pointer border">
                                <input type="text" id="secondary-color-hex" value="#6B7280" class="w-24 p-1.5 border rounded text-sm font-mono">
                            </div>
                            <div class="flex items-center space-x-3">
                                <label class="text-sm font-medium w-24">Accent</label>
                                <input type="color" id="accent-color-input" value="#10B981" class="w-10 h-10 rounded cursor-pointer border">
                                <input type="text" id="accent-color-hex" value="#10B981" class="w-24 p-1.5 border rounded text-sm font-mono">
                            </div>
                        </div>

                        <div class="mt-6">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold text-sm">Saved Colors</h3>
                                <button onclick="addSavedColor()" class="text-sm text-blue-600 font-semibold">+ Add Current</button>
                            </div>
                            <div id="saved-colors-grid" class="flex flex-wrap gap-2 mt-2"></div>
                        </div>

                        <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                            <h3 class="font-semibold text-sm mb-2">Preview</h3>
                            <div id="brand-preview" class="rounded-lg overflow-hidden">
                                <div id="brand-preview-header" class="p-4 text-white text-center" style="background:#4F46E5;">
                                    <p class="font-bold">Header Preview</p>
                                </div>
                                <div class="p-4 bg-white">
                                    <p class="text-sm text-gray-600">Body text preview</p>
                                    <button id="brand-preview-btn" class="mt-2 text-white text-sm px-4 py-1.5 rounded" style="background:#4F46E5;">Button</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- VIEW: Upload Content                                       -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div id="upload-view" class="hidden">
            <div class="max-w-3xl mx-auto p-8">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold">Upload Content</h1>
                        <p class="text-gray-600 mt-1">Upload new templates to the content library.</p>
                    </div>
                    <button onclick="showView('portal')" class="text-sm text-gray-600 hover:text-gray-900"><i class="fa-solid fa-arrow-left mr-1"></i>Back to Gallery</button>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-8">
                    <!-- Upload alert -->
                    <div id="upload-alert" class="hidden rounded-lg p-4 mb-6 text-sm font-medium"></div>

                    <!-- Content type selector -->
                    <div class="mb-6">
                        <label class="block text-sm font-semibold mb-2">Content Type</label>
                        <select id="upload-type-selector" onchange="showUploadForm()" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select content type...</option>
                            <option value="email">Email (Phishing Training)</option>
                            <option value="training">Education / Raw HTML</option>
                            <option value="landing">Landing Page</option>
                            <option value="scorm">SCORM Package (ZIP)</option>
                            <option value="html">HTML Package (ZIP)</option>
                            <option value="video">Video (MP4)</option>
                        </select>
                    </div>

                    <!-- ── Email Upload Form ───────────────────── -->
                    <form id="upload-form-email" class="hidden space-y-5" onsubmit="submitUpload(event)">
                        <input type="hidden" name="content_type" value="email">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Email Subject <span class="text-red-500">*</span></label>
                            <input type="text" name="email_subject" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Email From Address <span class="text-red-500">*</span></label>
                            <input type="email" name="email_from" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm" placeholder="sender@example.com">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Email HTML Content <span class="text-red-500">*</span></label>
                            <textarea name="email_html" required rows="10" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm font-mono" placeholder="Paste the email HTML here..."></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Thumbnail (Optional)</label>
                            <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Attachment (Optional)</label>
                            <input type="file" name="attachment" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                            <p class="text-xs text-gray-500 mt-1">PDF, DOC, XLS, etc.</p>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload &amp; Process</button>
                    </form>

                    <!-- ── Raw HTML / Education Form ───────────── -->
                    <form id="upload-form-training" class="hidden space-y-5" onsubmit="submitUpload(event)">
                        <input type="hidden" name="content_type" value="training">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Description</label>
                            <textarea name="description" rows="2" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">HTML Content <span class="text-red-500">*</span></label>
                            <textarea name="html_content" required rows="10" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm font-mono" placeholder="Paste your HTML content here..."></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Thumbnail (Optional)</label>
                            <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload &amp; Process</button>
                    </form>

                    <!-- ── Landing Page Form ───────────────────── -->
                    <form id="upload-form-landing" class="hidden space-y-5" onsubmit="submitUpload(event)">
                        <input type="hidden" name="content_type" value="landing">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Description</label>
                            <textarea name="description" rows="2" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">HTML Content <span class="text-red-500">*</span></label>
                            <textarea name="html_content" required rows="10" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm font-mono" placeholder="Paste your landing page HTML here..."></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Thumbnail (Optional)</label>
                            <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload &amp; Process</button>
                    </form>

                    <!-- ── SCORM / HTML ZIP Form ───────────────── -->
                    <form id="upload-form-zip" class="hidden space-y-5" onsubmit="submitUpload(event)">
                        <input type="hidden" name="content_type" value="">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Description</label>
                            <textarea name="description" rows="2" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">ZIP File <span class="text-red-500">*</span></label>
                            <input type="file" name="file" accept=".zip" required class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Thumbnail (Optional)</label>
                            <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload &amp; Process</button>
                    </form>

                    <!-- ── Video Form ──────────────────────────── -->
                    <form id="upload-form-video" class="hidden space-y-5" onsubmit="submitUpload(event)">
                        <input type="hidden" name="content_type" value="video">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full p-2.5 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Description</label>
                            <textarea name="description" rows="2" class="w-full p-2.5 border border-gray-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Video File <span class="text-red-500">*</span></label>
                            <input type="file" name="file" accept=".mp4,.webm,.ogg" required class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Thumbnail (Optional)</label>
                            <input type="file" name="thumbnail" accept="image/*" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload &amp; Process</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- VIEW: Content Editor (Stories 4.3 + 4.4)                  -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div id="editor-view" class="hidden">
            <!-- Editor Toolbar -->
            <div class="bg-white border-b border-gray-200">
                <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-14">
                        <div>
                            <div class="flex items-center">
                                <button onclick="showView('portal')" class="text-gray-400 hover:text-gray-600 mr-3"><i class="fa-solid fa-arrow-left"></i></button>
                                <input type="text" id="editor-title" class="text-lg font-semibold border-none focus:ring-0 p-0 bg-transparent" value="Untitled">
                                <span id="editor-status-badge" class="status-badge status-draft ml-3">Draft</span>
                            </div>
                            <p class="text-xs text-gray-500 ml-8" id="editor-save-status">Not saved yet</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="saveCustomization('draft')" class="px-4 py-1.5 rounded-lg text-sm font-semibold border bg-white hover:bg-gray-50">Save Draft</button>
                            <button onclick="saveCustomization('published')" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fa-solid fa-rocket mr-1"></i>Publish</button>
                            <button onclick="generatePreviewLink()" id="preview-link-btn" class="px-3 py-1.5 rounded-lg text-sm font-medium border bg-white hover:bg-gray-50" title="Generate preview link"><i class="fa-solid fa-link mr-1"></i>Preview Link</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex" style="height: calc(100vh - 120px);">
                <!-- Sidebar -->
                <div class="editor-sidebar p-5 border-r border-gray-200 flex flex-col">
                    <div id="sidebar-content" class="flex-1 overflow-y-auto space-y-4">
                        <!-- Brand Kit Section -->
                        <div id="sidebar-brand-kit">
                            <div id="brand-kit-available-state" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="bg-blue-100 text-blue-600 rounded-full h-8 w-8 flex items-center justify-center"><i class="fa-solid fa-wand-magic-sparkles text-sm"></i></div>
                                    <h3 class="font-semibold ml-3 text-sm">Brand Kit Available</h3>
                                </div>
                                <p class="text-xs text-gray-600 mt-2">Apply your company's logo and colors automatically.</p>
                                <button onclick="applyBrandKit()" id="apply-brand-kit-btn" class="w-full bg-blue-600 text-white mt-3 py-2 rounded-lg font-semibold text-sm hover:bg-blue-700">Apply Brand Kit</button>
                            </div>

                            <div id="brand-kit-applied-state" class="hidden bg-green-50 border border-green-200 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="bg-green-100 text-green-600 rounded-full h-8 w-8 flex items-center justify-center"><i class="fa-solid fa-check text-sm"></i></div>
                                    <h3 class="font-semibold ml-3 text-sm">Brand Kit Applied</h3>
                                </div>
                                <p class="text-xs text-gray-600 mt-2">Your brand colors and logo have been applied.</p>
                                <button onclick="undoBrandKit()" class="w-full bg-white border border-gray-300 mt-3 py-2 rounded-lg font-semibold text-sm hover:bg-gray-50"><i class="fa-solid fa-arrow-rotate-left mr-1"></i>Undo Changes</button>
                            </div>
                        </div>

                        <!-- Translation Section (Story 6.3) -->
                        <div id="sidebar-translate" class="border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-center mb-2">
                                <i class="fa-solid fa-language text-blue-500 mr-2"></i>
                                <h3 class="font-semibold text-sm">Translate</h3>
                            </div>
                            <select id="translate-lang" class="w-full p-1.5 border rounded text-xs">
                                <option value="">Select language...</option>
                                <option value="es">Spanish</option><option value="fr">French</option><option value="de">German</option>
                                <option value="pt-br">Portuguese (Brazil)</option><option value="ar">Arabic</option>
                                <option value="ja">Japanese</option><option value="ko">Korean</option><option value="it">Italian</option>
                                <option value="nl">Dutch</option><option value="zh">Chinese (Simplified)</option>
                                <option value="zh-tw">Chinese (Traditional)</option>
                            </select>
                            <button onclick="translateContent()" id="translate-btn" class="w-full bg-blue-600 text-white mt-2 py-1.5 rounded text-xs font-semibold hover:bg-blue-700">Translate</button>
                            <div id="translate-actions" class="hidden mt-2 space-y-1">
                                <button onclick="saveTranslationAsNew()" class="w-full border border-gray-300 py-1.5 rounded text-xs font-semibold hover:bg-gray-50">Save as New Template</button>
                                <button onclick="applyTranslation()" class="w-full border border-gray-300 py-1.5 rounded text-xs font-semibold hover:bg-gray-50">Apply to Current</button>
                            </div>
                        </div>

                        <!-- Quiz Section (Story 7.1 + 7.2) -->
                        <div id="sidebar-quiz" class="border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-center mb-2">
                                <i class="fa-solid fa-circle-question text-green-500 mr-2"></i>
                                <h3 class="font-semibold text-sm">Quiz</h3>
                            </div>
                            <div class="flex items-center space-x-2 mb-2">
                                <label class="text-xs text-gray-600">Questions:</label>
                                <select id="quiz-num-questions" class="p-1 border rounded text-xs flex-1">
                                    <option>2</option><option selected>3</option><option>4</option><option>5</option>
                                </select>
                            </div>
                            <button onclick="generateQuiz()" id="generate-quiz-btn" class="w-full bg-green-600 text-white py-1.5 rounded text-xs font-semibold hover:bg-green-700">Generate Quiz</button>
                            <div id="quiz-preview-panel" class="hidden mt-3 max-h-60 overflow-y-auto text-xs space-y-2"></div>
                            <div id="quiz-actions" class="hidden mt-2 space-y-1">
                                <button onclick="injectQuiz()" class="w-full bg-green-600 text-white py-1.5 rounded text-xs font-semibold hover:bg-green-700"><i class="fa-solid fa-plus mr-1"></i>Inject Quiz</button>
                                <button onclick="removeQuiz()" class="w-full border border-red-300 text-red-600 py-1.5 rounded text-xs font-semibold hover:bg-red-50"><i class="fa-solid fa-trash mr-1"></i>Remove Quiz</button>
                                <button onclick="generateQuiz()" class="w-full border border-gray-300 py-1.5 rounded text-xs font-semibold hover:bg-gray-50"><i class="fa-solid fa-rotate mr-1"></i>Regenerate</button>
                            </div>
                        </div>

                        <!-- Threat Viewer & Editor (Stories 8.1, 8.2, 8.3) — email only -->
                        <div id="sidebar-threats" class="hidden border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fa-solid fa-shield-halved text-orange-500 mr-2"></i>
                                    <h3 class="font-semibold text-sm">Threat Indicators</h3>
                                </div>
                                <label class="flex items-center cursor-pointer">
                                    <input type="checkbox" id="threat-view-toggle" class="sr-only peer" onchange="toggleThreatView()">
                                    <div class="relative w-8 h-4 bg-gray-200 peer-checked:bg-orange-500 rounded-full transition-colors">
                                        <div class="absolute left-0.5 top-0.5 w-3 h-3 bg-white rounded-full transition-transform peer-checked:translate-x-4"></div>
                                    </div>
                                </label>
                            </div>

                            <!-- Difficulty badge -->
                            <div id="threat-difficulty" class="mb-2"></div>

                            <!-- Cue summary by category -->
                            <div id="threat-summary" class="space-y-1 max-h-48 overflow-y-auto text-xs"></div>

                            <!-- Threat Edit Panel (Story 8.2) -->
                            <div id="threat-edit-panel" class="hidden mt-3 border-t pt-3">
                                <h4 class="font-semibold text-xs mb-2">Edit Cue</h4>
                                <select id="threat-cue-select" class="w-full p-1.5 border rounded text-xs mb-2"></select>
                                <div class="flex space-x-1">
                                    <button onclick="addCueToElement()" class="flex-1 bg-orange-500 text-white py-1 rounded text-xs font-semibold hover:bg-orange-600">Set Cue</button>
                                    <button onclick="removeCueFromElement()" class="flex-1 border border-red-300 text-red-600 py-1 rounded text-xs font-semibold hover:bg-red-50">Remove</button>
                                </div>
                            </div>

                            <!-- AI Threat Injection (Story 8.3) -->
                            <div class="mt-3 border-t pt-3">
                                <button onclick="showThreatInjectionModal()" class="w-full bg-orange-500 text-white py-1.5 rounded text-xs font-semibold hover:bg-orange-600"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Add Threats with AI</button>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- CKEditor 5 Container -->
                <div class="ckeditor-main-container">
                    <div class="editor-container editor-container_document-editor editor-container_contains-wrapper">
                        <div class="editor-container__menu-bar" id="editor-menu-bar"></div>
                        <div class="editor-container__toolbar" id="editor-toolbar"></div>
                        <div class="editor-container__editable-wrapper">
                            <div class="editor-container__editor-wrapper">
                                <div class="editor-container__editor"><div id="editor"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Threat Injection Modal (Story 8.3) -->
    <div id="threat-injection-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold">AI-Assisted Threat Injection</h2>
                <button onclick="closeThreatModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <p class="text-sm text-gray-600 mb-4">Select threat types to inject into the email. Claude will modify the content to include these indicators.</p>
            <div id="threat-checklist" class="space-y-3 max-h-64 overflow-y-auto mb-4"></div>
            <div class="flex items-center space-x-3 mb-4">
                <label class="text-sm font-medium">Intensity:</label>
                <select id="threat-intensity" class="p-1.5 border rounded text-sm">
                    <option value="subtle">Subtle (hard to detect)</option>
                    <option value="obvious">Obvious (easy to detect)</option>
                </select>
            </div>
            <div class="flex justify-end space-x-2">
                <button onclick="closeThreatModal()" class="px-4 py-2 border rounded-lg text-sm font-semibold hover:bg-gray-50">Cancel</button>
                <button onclick="executeThreatInjection()" id="inject-threats-btn" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600">Generate</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.ckeditor.com/ckeditor5/47.5.0/ckeditor5.umd.js" crossorigin></script>
    <script src="https://cdn.ckeditor.com/ckeditor5-premium-features/47.5.0/ckeditor5-premium-features.umd.js" crossorigin></script>
    <script>
    (function() {
        'use strict';

        // ── Config ──────────────────────────────────────────────
        const API = <?php echo json_encode($apiBase); ?>;
        const COMPANY_ID = <?php echo json_encode($companyId); ?>;
        const TOKEN = <?php echo json_encode($config['api']['bearer_token'] ?? ''); ?>;
        const headers = () => {
            const h = { 'Content-Type': 'application/json' };
            if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
            return h;
        };

        // ── State ───────────────────────────────────────────────
        let currentView = 'portal';
        let currentCategory = 'email';
        let brandKit = null;
        let brandKitOriginal = null;
        let currentContentId = null;
        let currentCustomizationId = null;
        let currentCustomization = null;
        let originalHtml = '';
        let editHistory = [];
        let autoSaveTimer = null;
        let currentContentType = null;
        let threatTaxonomy = null;
        let lastTranslatedHtml = null;
        let lastQuizData = null;
        let editorInstance = null;

        // ── Toast ───────────────────────────────────────────────
        function toast(msg, type = 'success') {
            const el = document.getElementById('toast');
            el.textContent = msg;
            el.className = 'toast ' + type + ' show';
            setTimeout(() => el.classList.remove('show'), 3000);
        }

        // ── API helpers ─────────────────────────────────────────
        async function api(endpoint, opts = {}) {
            const url = API + '/' + endpoint;
            const res = await fetch(url, { headers: headers(), ...opts });
            const data = await res.json();
            if (!res.ok || data.success === false) throw new Error(data.error || data.message || 'API error');
            return data;
        }

        // ── View Navigation ─────────────────────────────────────
        window.showView = function(view) {
            // Destroy CKEditor when leaving editor view
            if (currentView === 'editor' && view !== 'editor') {
                destroyCKEditor();
                // Remove threat highlight styles if active
                const threatStyle = document.getElementById('ocms-threat-styles');
                if (threatStyle) threatStyle.remove();
            }

            ['portal', 'brand-kit', 'upload', 'editor'].forEach(v => {
                document.getElementById(v + '-view').classList.toggle('hidden', v !== view);
            });
            currentView = view;
            window.scrollTo(0, 0);

            if (view === 'portal') loadTemplates();
            if (view === 'brand-kit') loadBrandKit();
        };

        // ═════════════════════════════════════════════════════════
        // UPLOAD CONTENT
        // ═════════════════════════════════════════════════════════
        const uploadForms = ['email', 'training', 'landing', 'zip', 'video'];

        window.showUploadForm = function() {
            const type = document.getElementById('upload-type-selector').value;
            uploadForms.forEach(f => document.getElementById('upload-form-' + f).classList.add('hidden'));
            hideUploadAlert();

            if (!type) return;

            if (type === 'scorm' || type === 'html') {
                const form = document.getElementById('upload-form-zip');
                form.querySelector('input[name="content_type"]').value = type;
                form.classList.remove('hidden');
            } else {
                const form = document.getElementById('upload-form-' + type);
                if (form) form.classList.remove('hidden');
            }
        };

        function showUploadAlert(type, message) {
            const el = document.getElementById('upload-alert');
            el.className = 'rounded-lg p-4 mb-6 text-sm font-medium ' +
                (type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200');
            el.innerHTML = message;
            el.classList.remove('hidden');
        }

        function hideUploadAlert() {
            document.getElementById('upload-alert').classList.add('hidden');
        }

        window.submitUpload = async function(event) {
            event.preventDefault();
            const form = event.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const origLabel = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Uploading...';
            hideUploadAlert();

            try {
                const formData = new FormData(form);

                const res = await fetch(API + '/upload.php', {
                    method: 'POST',
                    headers: TOKEN ? { 'Authorization': 'Bearer ' + TOKEN } : {},
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    let msg = '<strong>Uploaded successfully!</strong> Content ID: <code class="bg-green-100 px-1 rounded">' + esc(data.content_id) + '</code>';
                    if (data.tags && data.tags.length) msg += '<br>Tags: ' + data.tags.map(t => '<span class="inline-block bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full mr-1">' + esc(t) + '</span>').join('');
                    if (data.cues && data.cues.length) msg += '<br>Cues: ' + data.cues.map(c => '<span class="inline-block bg-amber-100 text-amber-700 text-xs px-2 py-0.5 rounded-full mr-1">' + esc(c) + '</span>').join('');
                    if (data.difficulty) { const labels = {1:'Least Difficult',2:'Moderately Difficult',3:'Very Difficult'}; msg += '<br>Difficulty: ' + (labels[data.difficulty] || data.difficulty); }
                    if (data.preview_url) msg += '<br><a href="' + esc(data.preview_url) + '" target="_blank" class="text-blue-600 underline">Preview Content</a>';
                    showUploadAlert('success', msg);
                    form.reset();
                    toast('Content uploaded!');
                } else {
                    showUploadAlert('error', esc(data.error || data.message || 'Upload failed'));
                }
            } catch (e) {
                showUploadAlert('error', 'Upload failed: ' + esc(e.message));
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = origLabel;
            }
        };

        // ═════════════════════════════════════════════════════════
        // STORY 4.1: Template Gallery
        // ═════════════════════════════════════════════════════════
        const templateGrid = document.getElementById('template-grid');
        const searchInput = document.getElementById('search-input');
        const myContentToggle = document.getElementById('my-content-toggle');

        // Category tab switching
        document.querySelectorAll('.category-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.category-tab').forEach(t => { t.classList.remove('tab-active'); t.classList.add('text-gray-600'); });
                tab.classList.add('tab-active');
                tab.classList.remove('text-gray-600');
                currentCategory = tab.dataset.type;
                loadTemplates();
            });
        });

        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') loadTemplates(); });
        myContentToggle.addEventListener('change', loadTemplates);

        async function loadTemplates() {
            templateGrid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-400"><div class="spinner" style="border-color:#d1d5db;border-top-color:#6b7280;width:2rem;height:2rem;"></div></div>';

            try {
                if (myContentToggle.checked && COMPANY_ID) {
                    // Load customizations ("My Content")
                    const data = await api('customizations.php?company_id=' + encodeURIComponent(COMPANY_ID));
                    renderCustomizationCards(data.customizations || []);
                } else {
                    // Load base templates
                    const search = searchInput.value ? '&search=' + encodeURIComponent(searchInput.value) : '';
                    const data = await api('list-content.php?type=' + currentCategory + search);
                    renderTemplateCards(data.content || []);
                }
            } catch (e) {
                templateGrid.innerHTML = '<div class="col-span-full text-center py-12 text-red-500">Failed to load: ' + e.message + '</div>';
            }
        }

        function renderTemplateCards(items) {
            if (!items.length) {
                templateGrid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-400"><i class="fa-regular fa-folder-open text-4xl mb-3"></i><p>No templates found</p></div>';
                return;
            }
            templateGrid.innerHTML = items.map(item => {
                const thumbUrl = item.thumbnail_filename ? API + '/thumbnail.php?id=' + item.id : '';
                const thumbHtml = thumbUrl
                    ? '<img src="' + thumbUrl + '" class="w-full h-full object-cover" alt="">'
                    : '<i class="fa-regular fa-file-lines text-4xl text-gray-300"></i>';
                return '<div class="bg-white rounded-lg shadow-sm overflow-hidden cursor-pointer hover:shadow-md transition-shadow" onclick="openEditor(\'' + item.id + '\')">' +
                    '<div class="h-36 bg-gray-100 flex items-center justify-center overflow-hidden">' + thumbHtml + '</div>' +
                    '<div class="p-3"><h3 class="font-semibold text-sm text-gray-800 truncate">' + esc(item.title || 'Untitled') + '</h3>' +
                    '<p class="text-xs text-gray-500 mt-1">' + esc(item.content_type || '') + '</p></div></div>';
            }).join('');
        }

        function renderCustomizationCards(items) {
            if (!items.length) {
                templateGrid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-400"><i class="fa-regular fa-folder-open text-4xl mb-3"></i><p>No customizations yet. Select a template to get started.</p></div>';
                return;
            }
            templateGrid.innerHTML = items.map(item => {
                const statusClass = item.status === 'published' ? 'status-published' : 'status-draft';
                const thumbUrl = item.thumbnail_filename ? API + '/thumbnail.php?id=' + item.base_content_id : '';
                const thumbHtml = thumbUrl
                    ? '<img src="' + thumbUrl + '" class="w-full h-full object-cover" alt="">'
                    : '<i class="fa-regular fa-file-lines text-4xl text-gray-300"></i>';
                return '<div class="bg-white rounded-lg shadow-sm overflow-hidden cursor-pointer hover:shadow-md transition-shadow" onclick="openCustomization(\'' + item.id + '\')">' +
                    '<div class="h-36 bg-gray-100 flex items-center justify-center overflow-hidden">' + thumbHtml + '</div>' +
                    '<div class="p-3"><div class="flex items-center justify-between"><h3 class="font-semibold text-sm text-gray-800 truncate">' + esc(item.title || 'Untitled') + '</h3>' +
                    '<span class="status-badge ' + statusClass + '">' + esc(item.status) + '</span></div>' +
                    '<p class="text-xs text-gray-500 mt-1">' + esc(item.content_type || '') + ' &middot; ' + formatDate(item.updated_at) + '</p></div></div>';
            }).join('');
        }

        // ═════════════════════════════════════════════════════════
        // STORY 4.2: Brand Kit Manager
        // ═════════════════════════════════════════════════════════
        async function loadBrandKit() {
            if (!COMPANY_ID) { toast('No company_id set', 'error'); return; }
            try {
                const data = await api('brand-kits.php?company_id=' + COMPANY_ID + '&default=true');
                if (data.brand_kit) {
                    brandKit = data.brand_kit;
                    brandKitOriginal = JSON.parse(JSON.stringify(brandKit));
                    populateBrandKitForm(brandKit);
                } else {
                    brandKit = null;
                    brandKitOriginal = null;
                }
            } catch (e) {
                console.error('Load brand kit failed:', e);
            }
        }

        function populateBrandKitForm(kit) {
            if (kit.logo_url) {
                document.getElementById('logo-preview').classList.remove('hidden');
                document.getElementById('logo-preview-img').src = kit.logo_url;
            }
            if (kit.primary_font) document.getElementById('primary-font-select').value = kit.primary_font;
            setColorInputs('primary', kit.primary_color || '#4F46E5');
            setColorInputs('secondary', kit.secondary_color || '#6B7280');
            setColorInputs('accent', kit.accent_color || '#10B981');
            renderSavedColors(kit.saved_colors || []);
            updateBrandPreview();
        }

        function setColorInputs(name, hex) {
            document.getElementById(name + '-color-input').value = hex;
            document.getElementById(name + '-color-hex').value = hex.toUpperCase();
        }

        function getColorValue(name) { return document.getElementById(name + '-color-input').value; }

        // Color input sync
        ['primary', 'secondary', 'accent'].forEach(name => {
            document.getElementById(name + '-color-input').addEventListener('input', e => {
                document.getElementById(name + '-color-hex').value = e.target.value.toUpperCase();
                updateBrandPreview();
            });
            document.getElementById(name + '-color-hex').addEventListener('change', e => {
                if (/^#[a-f0-9]{6}$/i.test(e.target.value)) {
                    document.getElementById(name + '-color-input').value = e.target.value;
                    updateBrandPreview();
                }
            });
        });

        function updateBrandPreview() {
            const primary = getColorValue('primary');
            document.getElementById('brand-preview-header').style.backgroundColor = primary;
            document.getElementById('brand-preview-btn').style.backgroundColor = primary;
        }

        function renderSavedColors(colors) {
            const grid = document.getElementById('saved-colors-grid');
            grid.innerHTML = (colors || []).map((c, i) =>
                '<div class="w-7 h-7 rounded-full cursor-pointer border border-gray-300 hover:scale-110 transition-transform" style="background:' + esc(c) + ';" onclick="applySavedColor(\'' + esc(c) + '\')" title="' + esc(c) + '"></div>'
            ).join('');
        }

        window.applySavedColor = function(hex) {
            setColorInputs('primary', hex);
            updateBrandPreview();
        };

        window.addSavedColor = function() {
            const color = getColorValue('primary');
            if (!brandKit) brandKit = {};
            if (!brandKit.saved_colors) brandKit.saved_colors = [];
            if (!brandKit.saved_colors.includes(color)) {
                brandKit.saved_colors.push(color);
                renderSavedColors(brandKit.saved_colors);
            }
        };

        window.saveBrandKit = async function() {
            if (!COMPANY_ID) { toast('No company_id set', 'error'); return; }
            const kitData = {
                company_id: COMPANY_ID,
                name: 'Default',
                primary_color: getColorValue('primary'),
                secondary_color: getColorValue('secondary'),
                accent_color: getColorValue('accent'),
                primary_font: document.getElementById('primary-font-select').value || null,
                saved_colors: brandKit?.saved_colors || [],
                is_default: true
            };

            try {
                if (brandKit && brandKit.id) {
                    await api('brand-kits.php?id=' + brandKit.id, { method: 'PUT', body: JSON.stringify(kitData) });
                } else {
                    const data = await api('brand-kits.php', { method: 'POST', body: JSON.stringify(kitData) });
                    brandKit = data.brand_kit;
                }
                toast('Brand kit saved');
                brandKitOriginal = JSON.parse(JSON.stringify(brandKit));
            } catch (e) {
                toast('Save failed: ' + e.message, 'error');
            }
        };

        window.resetBrandKit = function() {
            if (brandKitOriginal) populateBrandKitForm(brandKitOriginal);
        };

        // File upload handlers
        function setupDropZone(zoneId, inputId, assetType) {
            const zone = document.getElementById(zoneId);
            const input = document.getElementById(inputId);

            zone.addEventListener('click', () => input.click());
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
            zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragover'); if (e.dataTransfer.files.length) uploadAsset(e.dataTransfer.files[0], assetType); });
            input.addEventListener('change', () => { if (input.files.length) uploadAsset(input.files[0], assetType); });
        }

        async function uploadAsset(file, assetType) {
            if (!brandKit || !brandKit.id) {
                // Create brand kit first
                await saveBrandKit();
                if (!brandKit || !brandKit.id) { toast('Create a brand kit first', 'error'); return; }
            }

            const fd = new FormData();
            fd.append('brand_kit_id', brandKit.id);
            fd.append('asset_type', assetType);
            fd.append('file', file);

            try {
                const h = {};
                if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
                const res = await fetch(API + '/brand-kit-upload.php', { method: 'POST', headers: h, body: fd });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                toast(assetType + ' uploaded');
                if (assetType === 'logo') {
                    document.getElementById('logo-preview').classList.remove('hidden');
                    document.getElementById('logo-preview-img').src = data.asset.s3_url;
                    brandKit.logo_url = data.asset.s3_url;
                }
            } catch (e) {
                toast('Upload failed: ' + e.message, 'error');
            }
        }

        setupDropZone('logo-drop-zone', 'logo-file-input', 'logo');
        setupDropZone('font-drop-zone', 'font-file-input', 'font');

        // ═════════════════════════════════════════════════════════
        // STORY 4.3: Content Editor (CKEditor 5 Integration)
        // ═════════════════════════════════════════════════════════

        // ── CKEditor 5 Setup ─────────────────────────────────────
        const {
            DecoupledEditor, Autosave, Essentials, Paragraph, CloudServices,
            Autoformat, TextTransformation, LinkImage, Link, ImageBlock,
            ImageToolbar, BlockQuote, Bold, Bookmark, CKBox, ImageUpload,
            ImageInsert, ImageInsertViaUrl, AutoImage, PictureEditing,
            CKBoxImageEdit, CodeBlock, TableColumnResize, Table, TableToolbar,
            Emoji, Mention, PasteFromOffice, FindAndReplace, FontBackgroundColor,
            FontColor, FontFamily, FontSize, Fullscreen, Heading, HorizontalLine,
            ImageCaption, ImageResize, ImageStyle, Indent, IndentBlock, Code,
            Italic, AutoLink, ListProperties, List, MediaEmbed, RemoveFormat,
            SpecialCharactersArrows, SpecialCharacters, SpecialCharactersCurrency,
            SpecialCharactersEssentials, SpecialCharactersLatin,
            SpecialCharactersMathematical, SpecialCharactersText, Strikethrough,
            Subscript, Superscript, TableCaption, TableCellProperties,
            TableProperties, Alignment, TodoList, Underline, ShowBlocks,
            GeneralHtmlSupport, HtmlEmbed, HtmlComment, FullPage, BalloonToolbar
        } = window.CKEDITOR;
        const {
            AIAssistant, OpenAITextAdapter, PasteFromOfficeEnhanced, FormatPainter,
            LineHeight, SlashCommand, SourceEditingEnhanced, EmailConfigurationHelper
        } = window.CKEDITOR_PREMIUM_FEATURES;

        const CK_LICENSE_KEY = 'eyJhbGciOiJFUzI1NiJ9.eyJleHAiOjE3NzIyMzY3OTksImp0aSI6IjIxZTQ1MmI0LTM0YTQtNGE0OC1hZTlkLWU4MWUwYjc2Mzc4ZSIsInVzYWdlRW5kcG9pbnQiOiJodHRwczovL3Byb3h5LWV2ZW50LmNrZWRpdG9yLmNvbSIsImRpc3RyaWJ1dGlvbkNoYW5uZWwiOlsiY2xvdWQiLCJkcnVwYWwiLCJzaCJdLCJ3aGl0ZUxhYmVsIjp0cnVlLCJsaWNlbnNlVHlwZSI6InRyaWFsIiwiZmVhdHVyZXMiOlsiKiJdLCJ2YyI6IjcyMDRjYmFiIn0.CBOtUlCMTxAYX0D-L452hvTJfdlSflqyINhXRfFDo8JLQFEqsY46_wJJ6RwAcM5WsLVUrZ-OmUexNgry09fvYw';
        const CK_TOKEN_URL = 'https://6g4uh9w4eo7q.cke-cs.com/token/dev/2bdd793dab06e28b02c8afcc1afa97db747c0641696eac3842344e89de51?limit=10';
        const DEFAULT_HEX_COLORS = [
            { color: '#000000', label: 'Black' }, { color: '#4D4D4D', label: 'Dim grey' },
            { color: '#999999', label: 'Grey' }, { color: '#E6E6E6', label: 'Light grey' },
            { color: '#FFFFFF', label: 'White', hasBorder: true }, { color: '#E65C5C', label: 'Red' },
            { color: '#E69C5C', label: 'Orange' }, { color: '#E6E65C', label: 'Yellow' },
            { color: '#C2E65C', label: 'Light green' }, { color: '#5CE65C', label: 'Green' },
            { color: '#5CE6A6', label: 'Aquamarine' }, { color: '#5CE6E6', label: 'Turquoise' },
            { color: '#5CA6E6', label: 'Light blue' }, { color: '#5C5CE6', label: 'Blue' },
            { color: '#A65CE6', label: 'Purple' }
        ];

        function getCKEditorConfig() {
            return {
                toolbar: {
                    items: [
                        'undo', 'redo', '|',
                        'aiAssistant', '|',
                        'sourceEditingEnhanced', 'showBlocks', 'formatPainter', 'findAndReplace', 'fullscreen', '|',
                        'heading', '|',
                        'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', '|',
                        'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', 'code', 'removeFormat', '|',
                        'emoji', 'specialCharacters', 'horizontalLine', 'link', 'bookmark',
                        'insertImage', 'insertImageViaUrl', 'ckbox', 'mediaEmbed', 'insertTable', 'blockQuote', 'codeBlock', 'htmlEmbed', '|',
                        'alignment', 'lineHeight', '|',
                        'bulletedList', 'numberedList', 'todoList', 'outdent', 'indent'
                    ],
                    shouldNotGroupWhenFull: false
                },
                plugins: [
                    AIAssistant, OpenAITextAdapter,
                    Alignment, Autoformat, AutoImage, AutoLink, Autosave, BalloonToolbar,
                    BlockQuote, Bold, Bookmark, CKBox, CKBoxImageEdit, CloudServices,
                    Code, CodeBlock, EmailConfigurationHelper, Emoji, Essentials,
                    FindAndReplace, FontBackgroundColor, FontColor, FontFamily, FontSize,
                    FormatPainter, FullPage, Fullscreen, GeneralHtmlSupport, Heading,
                    HorizontalLine, HtmlComment, HtmlEmbed, ImageBlock, ImageCaption,
                    ImageInsert, ImageInsertViaUrl, ImageResize, ImageStyle, ImageToolbar,
                    ImageUpload, Indent, IndentBlock, Italic, LineHeight, Link, LinkImage,
                    List, ListProperties, MediaEmbed, Mention, Paragraph, PasteFromOffice,
                    PasteFromOfficeEnhanced, PictureEditing, RemoveFormat, ShowBlocks,
                    SlashCommand, SourceEditingEnhanced, SpecialCharacters,
                    SpecialCharactersArrows, SpecialCharactersCurrency,
                    SpecialCharactersEssentials, SpecialCharactersLatin,
                    SpecialCharactersMathematical, SpecialCharactersText, Strikethrough,
                    Subscript, Superscript, Table, TableCaption, TableCellProperties,
                    TableColumnResize, TableProperties, TableToolbar, TextTransformation,
                    TodoList, Underline
                ],
                ai: {
                    openAI: {
                        apiUrl: API + '/ckeditor-ai-adapter.php',
                        requestHeaders: {
                            Authorization: 'Bearer ' + TOKEN
                        },
                        requestParameters: {
                            model: 'claude',
                            max_tokens: 4096,
                            stream: true
                        }
                    }
                },
                balloonToolbar: ['bold', 'italic', '|', 'link', 'insertImage', '|', 'bulletedList', 'numberedList'],
                cloudServices: { tokenUrl: CK_TOKEN_URL },
                fontBackgroundColor: { colorPicker: { format: 'hex' }, colors: DEFAULT_HEX_COLORS },
                fontColor: { colorPicker: { format: 'hex' }, colors: DEFAULT_HEX_COLORS },
                fontFamily: { supportAllValues: true },
                fontSize: { options: [10, 12, 14, 'default', 18, 20, 22], supportAllValues: true },
                fullscreen: {
                    onEnterCallback: container => container.classList.add(
                        'editor-container', 'editor-container_document-editor',
                        'editor-container_contains-wrapper', 'ckeditor-main-container'
                    )
                },
                heading: {
                    options: [
                        { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                        { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                        { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                        { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                        { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' },
                        { model: 'heading5', view: 'h5', title: 'Heading 5', class: 'ck-heading_heading5' },
                        { model: 'heading6', view: 'h6', title: 'Heading 6', class: 'ck-heading_heading6' }
                    ]
                },
                htmlSupport: {
                    allow: [{ name: /.*/, styles: true, attributes: true, classes: true }]
                },
                image: {
                    toolbar: ['toggleImageCaption', '|', 'imageStyle:alignBlockLeft', 'imageStyle:block', 'imageStyle:alignBlockRight', '|', 'resizeImage', '|', 'ckboxImageEdit'],
                    styles: { options: ['alignBlockLeft', 'block', 'alignBlockRight'] }
                },
                licenseKey: CK_LICENSE_KEY,
                lineHeight: { supportAllValues: true },
                link: {
                    addTargetToExternalLinks: true,
                    defaultProtocol: 'https://',
                    decorators: { toggleDownloadable: { mode: 'manual', label: 'Downloadable', attributes: { download: 'file' } } }
                },
                list: { properties: { styles: true, startIndex: true, reversed: false } },
                mention: { feeds: [{ marker: '@', feed: [] }] },
                placeholder: 'Content will be loaded here...',
                table: {
                    contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells', 'tableProperties', 'tableCellProperties'],
                    tableProperties: { borderColors: DEFAULT_HEX_COLORS, backgroundColors: DEFAULT_HEX_COLORS },
                    tableCellProperties: { borderColors: DEFAULT_HEX_COLORS, backgroundColors: DEFAULT_HEX_COLORS }
                }
            };
        }

        async function initCKEditor() {
            // Destroy previous instance if exists
            if (editorInstance) {
                await editorInstance.destroy();
                editorInstance = null;
            }
            // Clear toolbar/menubar containers (CKEditor appends children)
            document.getElementById('editor-toolbar').innerHTML = '';
            document.getElementById('editor-menu-bar').innerHTML = '';

            const config = getCKEditorConfig();
            const editor = await DecoupledEditor.create(document.querySelector('#editor'), config);
            document.querySelector('#editor-toolbar').appendChild(editor.ui.view.toolbar.element);
            document.querySelector('#editor-menu-bar').appendChild(editor.ui.view.menuBarView.element);
            editorInstance = editor;
            return editor;
        }

        async function destroyCKEditor() {
            if (editorInstance) {
                await editorInstance.destroy();
                editorInstance = null;
            }
        }

        // ── Editor open/close ────────────────────────────────────
        window.openEditor = async function(contentId) {
            currentContentId = contentId;
            currentCustomizationId = null;
            currentCustomization = null;
            editHistory = [];

            showView('editor');
            document.getElementById('brand-kit-available-state').classList.remove('hidden');
            document.getElementById('brand-kit-applied-state').classList.add('hidden');

            try {
                const data = await api('list-content.php?id=' + contentId);
                const content = (data.content || [])[0];
                if (!content) throw new Error('Content not found');

                currentContentType = content.content_type;
                const html = content.entry_body_html || content.email_body_html || '';
                originalHtml = html;
                document.getElementById('editor-title').value = content.title || 'Untitled';
                updateEditorStatus('draft');

                // Initialize CKEditor and load content
                await initCKEditor();
                editorInstance.setData(html);

                // Show threat sidebar for email content
                document.getElementById('sidebar-threats').classList.toggle('hidden', currentContentType !== 'email');
                if (currentContentType === 'email') loadThreatTaxonomy();

                // Load brand kit
                if (COMPANY_ID) {
                    try {
                        const bk = await api('brand-kits.php?company_id=' + COMPANY_ID + '&default=true');
                        brandKit = bk.brand_kit;
                    } catch (e) { /* no brand kit */ }
                }
                document.getElementById('brand-kit-available-state').classList.toggle('hidden', !brandKit);
            } catch (e) {
                toast('Failed to load content: ' + e.message, 'error');
            }
        };

        window.openCustomization = async function(customizationId) {
            try {
                const data = await api('customizations.php?id=' + customizationId);
                const cust = data.customization;
                currentCustomizationId = cust.id;
                currentContentId = cust.base_content_id;
                currentCustomization = cust;
                editHistory = (cust.customization_data?.element_edits) || [];

                showView('editor');
                document.getElementById('editor-title').value = cust.title || 'Untitled';
                updateEditorStatus(cust.status);

                originalHtml = cust.customized_html || '';

                // Initialize CKEditor and load content
                await initCKEditor();
                editorInstance.setData(originalHtml);

                // Show applied state if brand kit was used
                const brandApplied = cust.customization_data?.brand_kit_applied;
                document.getElementById('brand-kit-available-state').classList.toggle('hidden', brandApplied);
                document.getElementById('brand-kit-applied-state').classList.toggle('hidden', !brandApplied);
            } catch (e) {
                toast('Failed to load customization: ' + e.message, 'error');
            }
        };

        function loadPreview(html) {
            if (editorInstance) {
                editorInstance.setData(html);
            }
        }

        function getEditorHtml() {
            if (editorInstance) {
                return editorInstance.getData();
            }
            return '';
        }

        // Keep getIframeHtml as alias for backward compat within this file
        function getIframeHtml() { return getEditorHtml(); }

        // Brand Kit apply/undo
        window.applyBrandKit = async function() {
            if (!brandKit || !brandKit.id || !currentContentId) return;
            const btn = document.getElementById('apply-brand-kit-btn');
            btn.innerHTML = '<span class="spinner"></span> Applying...';
            btn.disabled = true;

            try {
                const data = await api('apply-brand-kit.php', {
                    method: 'POST',
                    body: JSON.stringify({ content_id: currentContentId, brand_kit_id: brandKit.id })
                });
                loadPreview(data.html);
                document.getElementById('brand-kit-available-state').classList.add('hidden');
                document.getElementById('brand-kit-applied-state').classList.remove('hidden');
                toast('Brand kit applied');
            } catch (e) {
                toast('Apply failed: ' + e.message, 'error');
            } finally {
                btn.innerHTML = 'Apply Brand Kit';
                btn.disabled = false;
            }
        };

        window.undoBrandKit = function() {
            loadPreview(originalHtml);
            document.getElementById('brand-kit-applied-state').classList.add('hidden');
            document.getElementById('brand-kit-available-state').classList.remove('hidden');
        };

        // ═════════════════════════════════════════════════════════
        // STORY 4.4: Save / Publish
        // ═════════════════════════════════════════════════════════
        window.saveCustomization = async function(status) {
            if (!COMPANY_ID || !currentContentId) {
                toast('Missing company_id or content_id', 'error');
                return;
            }

            const html = getIframeHtml();
            const title = document.getElementById('editor-title').value || 'Untitled';
            const custData = {
                brand_kit_applied: !document.getElementById('brand-kit-available-state').classList.contains('hidden') === false,
                brand_kit_id: brandKit?.id || null,
                element_edits: editHistory
            };

            try {
                if (currentCustomizationId) {
                    // Update existing
                    await api('customizations.php?id=' + currentCustomizationId, {
                        method: 'PUT',
                        body: JSON.stringify({ customized_html: html, title, status, customization_data: custData })
                    });
                } else {
                    // Create new
                    const data = await api('customizations.php', {
                        method: 'POST',
                        body: JSON.stringify({
                            company_id: COMPANY_ID,
                            base_content_id: currentContentId,
                            brand_kit_id: brandKit?.id || null,
                            customized_html: html,
                            title,
                            status,
                            customization_data: custData
                        })
                    });
                    currentCustomizationId = data.customization.id;
                    currentCustomization = data.customization;
                }

                updateEditorStatus(status);
                document.getElementById('editor-save-status').textContent = 'Saved ' + new Date().toLocaleTimeString();
                toast(status === 'published' ? 'Published!' : 'Draft saved');
            } catch (e) {
                toast('Save failed: ' + e.message, 'error');
            }
        };

        function updateEditorStatus(status) {
            const badge = document.getElementById('editor-status-badge');
            badge.textContent = status === 'published' ? 'Published' : 'Draft';
            badge.className = 'status-badge ml-3 ' + (status === 'published' ? 'status-published' : 'status-draft');
        }

        // ── Story 2.3: Preview Link Generation ─────────────────
        window.generatePreviewLink = async function() {
            if (!currentCustomizationId) {
                // Must save first to get a customization ID
                toast('Please save as draft first', 'error');
                return;
            }
            const btn = document.getElementById('preview-link-btn');
            btn.innerHTML = '<span class="spinner"></span>';
            btn.disabled = true;
            try {
                const data = await api('customizations.php?id=' + currentCustomizationId + '&action=preview');
                if (data.preview_url) {
                    await navigator.clipboard.writeText(data.preview_url);
                    toast('Preview link copied to clipboard!');
                    window.open(data.preview_url, '_blank');
                }
            } catch (e) {
                toast('Failed to generate preview link: ' + e.message, 'error');
            } finally {
                btn.innerHTML = '<i class="fa-solid fa-link mr-1"></i>Preview Link';
                btn.disabled = false;
            }
        };

        // Auto-save
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                if (currentCustomizationId) {
                    saveCustomization('draft');
                }
            }, 30000); // 30 seconds
        }

        // ── Utilities ───────────────────────────────────────────
        function esc(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }
        function formatDate(d) { if (!d) return ''; try { return new Date(d).toLocaleDateString(); } catch(e) { return d; } }

        // ═════════════════════════════════════════════════════════
        // STORY 6.3: Translation UI
        // ═════════════════════════════════════════════════════════
        window.translateContent = async function() {
            const lang = document.getElementById('translate-lang').value;
            if (!lang) { toast('Select a language', 'error'); return; }
            if (!currentContentId) return;

            const btn = document.getElementById('translate-btn');
            btn.innerHTML = '<span class="spinner"></span> Translating...';
            btn.disabled = true;

            try {
                const data = await api('translate-content.php', {
                    method: 'POST',
                    body: JSON.stringify({ content_id: currentContentId, target_language: lang })
                });
                lastTranslatedHtml = data.html;
                loadPreview(data.html);
                document.getElementById('translate-actions').classList.remove('hidden');
                toast('Translated to ' + lang);
            } catch (e) {
                toast('Translation failed: ' + e.message, 'error');
            } finally {
                btn.innerHTML = 'Translate';
                btn.disabled = false;
            }
        };

        window.saveTranslationAsNew = async function() {
            if (!lastTranslatedHtml || !currentContentId) return;
            const lang = document.getElementById('translate-lang').value;
            try {
                const data = await api('translate-content.php?action=save', {
                    method: 'POST',
                    body: JSON.stringify({
                        content_id: currentContentId,
                        target_language: lang,
                        translated_html: lastTranslatedHtml
                    })
                });
                toast('Saved as new template: ' + data.title);
                document.getElementById('translate-actions').classList.add('hidden');
            } catch (e) {
                toast('Save failed: ' + e.message, 'error');
            }
        };

        window.applyTranslation = function() {
            if (lastTranslatedHtml) {
                // Keep the translated HTML as the current working version
                document.getElementById('translate-actions').classList.add('hidden');
                toast('Translation applied to current editor');
                scheduleAutoSave();
            }
        };

        // ═════════════════════════════════════════════════════════
        // STORIES 7.1 + 7.2: Quiz Generation UI
        // ═════════════════════════════════════════════════════════
        window.generateQuiz = async function() {
            if (!currentContentId) return;
            const numQ = document.getElementById('quiz-num-questions').value;
            const btn = document.getElementById('generate-quiz-btn');
            btn.innerHTML = '<span class="spinner"></span> Generating...';
            btn.disabled = true;

            try {
                const data = await api('generate-questions.php', {
                    method: 'POST',
                    body: JSON.stringify({ content_id: currentContentId, num_questions: parseInt(numQ) })
                });
                lastQuizData = data;
                renderQuizPreview(data.questions || []);
                document.getElementById('quiz-actions').classList.remove('hidden');
                toast('Quiz generated (' + (data.questions || []).length + ' questions)');
            } catch (e) {
                toast('Quiz generation failed: ' + e.message, 'error');
            } finally {
                btn.innerHTML = 'Generate Quiz';
                btn.disabled = false;
            }
        };

        function renderQuizPreview(questions) {
            const panel = document.getElementById('quiz-preview-panel');
            panel.classList.remove('hidden');
            panel.innerHTML = questions.map((q, i) => {
                const options = (q.options || []).map((opt, j) => {
                    const isCorrect = j === q.correct_index || opt === q.correct_answer;
                    return '<div class="pl-3 py-0.5 ' + (isCorrect ? 'text-green-700 font-semibold' : '') + '">' +
                        String.fromCharCode(65 + j) + '. ' + esc(opt) + (isCorrect ? ' <i class="fa-solid fa-check"></i>' : '') + '</div>';
                }).join('');
                return '<div class="border rounded p-2 bg-gray-50"><div class="font-semibold">' + (i+1) + '. ' + esc(q.question) + '</div>' + options + '</div>';
            }).join('');
        }

        window.injectQuiz = async function() {
            if (!lastQuizData || !lastQuizData.quiz_html) { toast('Generate a quiz first', 'error'); return; }
            if (!editorInstance) return;

            // Get current HTML, manipulate via DOMParser, set back
            let html = getEditorHtml();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Remove existing quiz if present
            const existing = doc.getElementById('ocms-quiz-section');
            if (existing) existing.remove();

            // Inject quiz HTML before closing body
            const quizDiv = doc.createElement('div');
            quizDiv.id = 'ocms-quiz-section';
            quizDiv.innerHTML = lastQuizData.quiz_html;
            doc.body.appendChild(quizDiv);

            editorInstance.setData('<!DOCTYPE html>\n' + doc.documentElement.outerHTML);
            toast('Quiz injected');
            scheduleAutoSave();
        };

        window.removeQuiz = function() {
            if (!editorInstance) return;

            let html = getEditorHtml();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const quizSection = doc.getElementById('ocms-quiz-section');
            if (quizSection) quizSection.remove();

            editorInstance.setData('<!DOCTYPE html>\n' + doc.documentElement.outerHTML);
            toast('Quiz removed');
            scheduleAutoSave();
        };

        // ═════════════════════════════════════════════════════════
        // STORY 8.1: Threat Indicator Viewer
        // ═════════════════════════════════════════════════════════
        async function loadThreatTaxonomy() {
            if (threatTaxonomy) return;
            try {
                const data = await api('threat-taxonomy.php');
                threatTaxonomy = data.taxonomy;
                populateThreatCueSelect();
                populateThreatChecklist();
            } catch (e) {
                console.error('Failed to load threat taxonomy:', e);
            }
        }

        function populateThreatCueSelect() {
            const sel = document.getElementById('threat-cue-select');
            sel.innerHTML = '<option value="">Select cue...</option>';
            (threatTaxonomy || []).forEach(cat => {
                const group = document.createElement('optgroup');
                group.label = cat.type;
                cat.cues.forEach(cue => {
                    const opt = document.createElement('option');
                    opt.value = cue.name;
                    opt.textContent = cue.label;
                    group.appendChild(opt);
                });
                sel.appendChild(group);
            });
        }

        window.toggleThreatView = function() {
            const active = document.getElementById('threat-view-toggle').checked;

            if (active) {
                // Inject CSS into page targeting CKEditor's editable area
                let style = document.getElementById('ocms-threat-styles');
                if (!style) {
                    style = document.createElement('style');
                    style.id = 'ocms-threat-styles';
                    document.head.appendChild(style);
                }
                const colorMap = {};
                (threatTaxonomy || []).forEach(cat => cat.cues.forEach(c => colorMap[c.name] = cat.color));

                let css = '';
                Object.entries(colorMap).forEach(([name, color]) => {
                    css += '.ck-editor__editable [data-cue="' + name + '"] { outline: 2px solid ' + color + ' !important; background-color: ' + color + '20 !important; cursor: pointer; }\n';
                });
                style.textContent = css;

                // Build summary by parsing editor HTML
                buildThreatSummary();

                // Click handler on CKEditor's editing root for threat editing (Story 8.2)
                if (editorInstance) {
                    const editingRoot = editorInstance.editing.view.getDomRoot();
                    if (editingRoot) editingRoot.addEventListener('click', onThreatElementClick);
                }
            } else {
                const style = document.getElementById('ocms-threat-styles');
                if (style) style.remove();
                document.getElementById('threat-summary').innerHTML = '';
                document.getElementById('threat-difficulty').innerHTML = '';
                document.getElementById('threat-edit-panel').classList.add('hidden');
                if (editorInstance) {
                    const editingRoot = editorInstance.editing.view.getDomRoot();
                    if (editingRoot) editingRoot.removeEventListener('click', onThreatElementClick);
                }
            }
        };

        function buildThreatSummary() {
            // Parse editor HTML for data-cue attributes
            const html = getEditorHtml();
            const parser = new DOMParser();
            const parsedDoc = parser.parseFromString(html, 'text/html');
            const cueEls = parsedDoc.querySelectorAll('[data-cue]');
            const cueCount = {};
            cueEls.forEach(el => {
                const cue = el.getAttribute('data-cue');
                cueCount[cue] = (cueCount[cue] || 0) + 1;
            });

            const totalCues = Object.keys(cueCount).length;
            let difficulty = 'least';
            if (totalCues >= 6) difficulty = 'very';
            else if (totalCues >= 3) difficulty = 'moderately';

            const diffColors = { least: '#EF4444', moderately: '#F59E0B', very: '#10B981' };
            document.getElementById('threat-difficulty').innerHTML =
                '<span class="status-badge" style="background:' + diffColors[difficulty] + '20;color:' + diffColors[difficulty] + ';">' +
                difficulty.charAt(0).toUpperCase() + difficulty.slice(1) + ' Difficult (' + totalCues + ' cues)</span>';

            let summaryHtml = '';
            (threatTaxonomy || []).forEach(cat => {
                const catCues = cat.cues.filter(c => cueCount[c.name]);
                if (!catCues.length) return;
                summaryHtml += '<div class="border-l-2 pl-2 mb-1" style="border-color:' + cat.color + ';">' +
                    '<div class="font-semibold" style="color:' + cat.color + ';">' + esc(cat.type) + ' (' + catCues.length + ')</div>';
                catCues.forEach(c => {
                    summaryHtml += '<div class="text-gray-600 cursor-pointer hover:text-blue-600" onclick="scrollToCue(\'' + c.name + '\')">' +
                        esc(c.label) + (cueCount[c.name] > 1 ? ' x' + cueCount[c.name] : '') + '</div>';
                });
                summaryHtml += '</div>';
            });
            document.getElementById('threat-summary').innerHTML = summaryHtml || '<p class="text-gray-400">No threat indicators found</p>';
        }

        window.scrollToCue = function(cueName) {
            if (!editorInstance) return;
            const editingRoot = editorInstance.editing.view.getDomRoot();
            if (!editingRoot) return;
            const el = editingRoot.querySelector('[data-cue="' + cueName + '"]');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };

        // ═════════════════════════════════════════════════════════
        // STORY 8.2: Manual Threat Editor
        // ═════════════════════════════════════════════════════════
        let threatSelectedElement = null;

        function onThreatElementClick(e) {
            const el = e.target.closest('[data-cue]') || e.target;
            threatSelectedElement = el;

            const panel = document.getElementById('threat-edit-panel');
            panel.classList.remove('hidden');

            const currentCue = el.getAttribute('data-cue') || '';
            document.getElementById('threat-cue-select').value = currentCue;
        }

        window.addCueToElement = function() {
            if (!threatSelectedElement) return;
            const cue = document.getElementById('threat-cue-select').value;
            if (!cue) { toast('Select a cue type', 'error'); return; }

            threatSelectedElement.setAttribute('data-cue', cue);
            editHistory.push({ action: 'add_cue', cue, timestamp: new Date().toISOString() });

            buildThreatSummary();
            toast('Cue set: ' + cue);
            scheduleAutoSave();
        };

        window.removeCueFromElement = function() {
            if (!threatSelectedElement) return;
            const oldCue = threatSelectedElement.getAttribute('data-cue');
            threatSelectedElement.removeAttribute('data-cue');
            document.getElementById('threat-edit-panel').classList.add('hidden');

            if (oldCue) editHistory.push({ action: 'remove_cue', cue: oldCue, timestamp: new Date().toISOString() });

            buildThreatSummary();
            toast('Cue removed');
            scheduleAutoSave();
        };

        // ═════════════════════════════════════════════════════════
        // STORY 8.3: AI Threat Injection
        // ═════════════════════════════════════════════════════════
        function populateThreatChecklist() {
            const container = document.getElementById('threat-checklist');
            container.innerHTML = (threatTaxonomy || []).map(cat =>
                '<div><div class="font-semibold text-sm mb-1" style="color:' + cat.color + ';">' + esc(cat.type) + '</div>' +
                cat.cues.map(c =>
                    '<label class="flex items-center space-x-2 text-sm py-0.5 cursor-pointer">' +
                    '<input type="checkbox" class="threat-check rounded" value="' + c.name + '">' +
                    '<span>' + esc(c.label) + '</span></label>'
                ).join('') + '</div>'
            ).join('');
        }

        window.showThreatInjectionModal = function() {
            if (!threatTaxonomy) { toast('Taxonomy not loaded', 'error'); return; }
            document.getElementById('threat-injection-modal').classList.remove('hidden');
        };

        window.closeThreatModal = function() {
            document.getElementById('threat-injection-modal').classList.add('hidden');
        };

        window.executeThreatInjection = async function() {
            const selected = Array.from(document.querySelectorAll('.threat-check:checked')).map(cb => cb.value);
            if (!selected.length) { toast('Select at least one threat type', 'error'); return; }

            const intensity = document.getElementById('threat-intensity').value;
            const btn = document.getElementById('inject-threats-btn');
            btn.innerHTML = '<span class="spinner"></span> Generating...';
            btn.disabled = true;

            try {
                const html = getIframeHtml();
                const data = await api('inject-threats.php', {
                    method: 'POST',
                    body: JSON.stringify({ html, threat_types: selected, intensity })
                });

                loadPreview(data.html);
                closeThreatModal();

                // Re-enable threat view if it was active
                if (document.getElementById('threat-view-toggle').checked) {
                    setTimeout(() => toggleThreatView(), 200);
                }
                toast('Threats injected: ' + (data.injected_cues || selected).join(', '));
            } catch (e) {
                toast('Injection failed: ' + e.message, 'error');
            } finally {
                btn.innerHTML = 'Generate';
                btn.disabled = false;
            }
        };

        // ── Init ────────────────────────────────────────────────
        const openContent = <?php echo json_encode($openContentId); ?>;
        const openCust = <?php echo json_encode($openCustomizationId); ?>;

        if (openCust) {
            openCustomization(openCust);
        } else if (openContent) {
            openEditor(openContent);
        } else {
            loadTemplates();
        }
    })();
    </script>
</body>
</html>
