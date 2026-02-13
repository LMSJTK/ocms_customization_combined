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
                            <button onclick="setEditorMode('edit')" id="mode-edit-btn" class="px-3 py-1.5 rounded-lg text-sm font-medium border bg-white hover:bg-gray-50"><i class="fa-solid fa-pen mr-1"></i>Edit</button>
                            <button onclick="setEditorMode('preview')" id="mode-preview-btn" class="px-3 py-1.5 rounded-lg text-sm font-medium border bg-white hover:bg-gray-50"><i class="fa-regular fa-eye mr-1"></i>Preview</button>
                            <div class="w-px h-6 bg-gray-200 mx-1"></div>
                            <button onclick="saveCustomization('draft')" class="px-4 py-1.5 rounded-lg text-sm font-semibold border bg-white hover:bg-gray-50">Save Draft</button>
                            <button onclick="saveCustomization('published')" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fa-solid fa-rocket mr-1"></i>Publish</button>
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

                        <!-- Element Editing Section -->
                        <div id="editing-state" class="hidden">
                            <h3 class="font-semibold text-sm mb-3">Editing Element</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Background Color</label>
                                    <div class="flex items-center border bg-white rounded-md p-1">
                                        <input type="color" id="edit-bg-color" value="#ffffff" class="w-6 h-6 rounded cursor-pointer p-0">
                                        <input type="text" id="edit-bg-hex" class="ml-2 text-xs border-none focus:ring-0 w-full font-mono" value="#FFFFFF">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Text Color</label>
                                    <div class="flex items-center border bg-white rounded-md p-1">
                                        <input type="color" id="edit-text-color" value="#000000" class="w-6 h-6 rounded cursor-pointer p-0">
                                        <input type="text" id="edit-text-hex" class="ml-2 text-xs border-none focus:ring-0 w-full font-mono" value="#000000">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Font Size</label>
                                        <select id="edit-font-size" class="w-full p-1.5 border rounded text-xs">
                                            <option>12px</option><option>14px</option><option>16px</option><option>18px</option><option>20px</option><option>24px</option><option>28px</option><option>32px</option><option>48px</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Font Weight</label>
                                        <select id="edit-font-weight" class="w-full p-1.5 border rounded text-xs">
                                            <option value="400">Regular</option><option value="500">Medium</option><option value="600">Semibold</option><option value="700">Bold</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Text Alignment</label>
                                    <div class="grid grid-cols-3 gap-1 bg-gray-200 p-1 rounded-lg">
                                        <button data-align="left" class="text-align-btn bg-white rounded py-1 text-sm"><i class="fas fa-align-left"></i></button>
                                        <button data-align="center" class="text-align-btn rounded py-1 text-sm"><i class="fas fa-align-center"></i></button>
                                        <button data-align="right" class="text-align-btn rounded py-1 text-sm"><i class="fas fa-align-right"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom: HTML Editor Toggle -->
                    <button onclick="toggleHtmlEditor()" class="w-full border border-gray-300 py-2 rounded-lg font-semibold hover:bg-gray-100 text-xs mt-3">
                        <i class="fa-solid fa-code mr-1"></i>Advanced HTML Editor
                    </button>
                </div>

                <!-- Preview Pane -->
                <div class="email-preview-container flex flex-col">
                    <div id="preview-pane" class="flex-1 p-6 flex justify-center overflow-y-auto">
                        <iframe id="content-iframe" class="bg-white rounded-lg shadow-lg w-full max-w-2xl" style="border:none; min-height:600px;" sandbox="allow-same-origin"></iframe>
                    </div>
                    <!-- HTML Editor (hidden by default) -->
                    <div id="html-editor-panel" class="hidden border-t border-gray-300 bg-white" style="height:300px;">
                        <div class="flex items-center justify-between px-3 py-1.5 bg-gray-100 border-b">
                            <span class="text-xs font-semibold text-gray-600">HTML Source</span>
                            <button onclick="applyHtmlEdits()" class="text-xs bg-blue-600 text-white px-3 py-1 rounded font-semibold">Apply Changes</button>
                        </div>
                        <textarea id="html-editor-textarea" class="w-full h-full p-3 text-xs font-mono border-none focus:ring-0 resize-none" style="height: calc(100% - 36px);"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    (function() {
        'use strict';

        // ── Config ──────────────────────────────────────────────
        const API = <?php echo json_encode($apiBase); ?>;
        const COMPANY_ID = <?php echo json_encode($companyId); ?>;
        const TOKEN = ''; // Set bearer token if auth is enabled
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
        let editorMode = 'edit';
        let selectedElement = null;
        let editHistory = [];
        let autoSaveTimer = null;

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
            ['portal', 'brand-kit', 'editor'].forEach(v => {
                document.getElementById(v + '-view').classList.toggle('hidden', v !== view);
            });
            currentView = view;
            window.scrollTo(0, 0);

            if (view === 'portal') loadTemplates();
            if (view === 'brand-kit') loadBrandKit();
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
        // STORY 4.3: Content Editor
        // ═════════════════════════════════════════════════════════
        const iframe = document.getElementById('content-iframe');

        window.openEditor = async function(contentId) {
            currentContentId = contentId;
            currentCustomizationId = null;
            currentCustomization = null;
            editHistory = [];
            selectedElement = null;

            showView('editor');
            document.getElementById('brand-kit-available-state').classList.remove('hidden');
            document.getElementById('brand-kit-applied-state').classList.add('hidden');
            document.getElementById('editing-state').classList.add('hidden');

            try {
                // Load base content HTML
                const data = await api('list-content.php?id=' + contentId);
                const content = (data.content || [])[0];
                if (!content) throw new Error('Content not found');

                const html = content.entry_body_html || content.email_body_html || '';
                originalHtml = html;
                document.getElementById('editor-title').value = content.title || 'Untitled';
                updateEditorStatus('draft');
                loadPreview(html);

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
                loadPreview(originalHtml);

                // Show applied state if brand kit was used
                const brandApplied = cust.customization_data?.brand_kit_applied;
                document.getElementById('brand-kit-available-state').classList.toggle('hidden', brandApplied);
                document.getElementById('brand-kit-applied-state').classList.toggle('hidden', !brandApplied);
                document.getElementById('editing-state').classList.add('hidden');
            } catch (e) {
                toast('Failed to load customization: ' + e.message, 'error');
            }
        };

        function loadPreview(html) {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();

            // Wait for content to render, then set up editing
            setTimeout(() => {
                setupIframeEditing();
                resizeIframe();
            }, 100);
        }

        function resizeIframe() {
            try {
                const doc = iframe.contentDocument;
                if (doc && doc.body) {
                    iframe.style.height = Math.max(600, doc.body.scrollHeight + 40) + 'px';
                }
            } catch(e) {}
        }

        function setupIframeEditing() {
            if (editorMode !== 'edit') return;
            const doc = iframe.contentDocument;
            if (!doc) return;

            // Add edit-mode styles
            let style = doc.getElementById('ocms-edit-styles');
            if (!style) {
                style = doc.createElement('style');
                style.id = 'ocms-edit-styles';
                doc.head.appendChild(style);
            }
            style.textContent = `
                [data-ocms-editable]:hover { outline: 2px dashed #93C5FD; cursor: pointer; }
                [data-ocms-editable].ocms-selected { outline: 2px solid #EF4444 !important; box-shadow: 0 0 8px rgba(239,68,68,0.4); }
            `;

            // Mark editable elements
            const editables = doc.querySelectorAll('h1,h2,h3,h4,p,a,button,td,th,div,span,img');
            editables.forEach(el => el.setAttribute('data-ocms-editable', ''));

            // Click handler
            doc.addEventListener('click', onIframeClick);
        }

        function onIframeClick(e) {
            e.preventDefault();
            const doc = iframe.contentDocument;
            const target = e.target.closest('[data-ocms-editable]');

            // Deselect previous
            if (selectedElement) selectedElement.classList.remove('ocms-selected');

            if (!target) {
                selectedElement = null;
                document.getElementById('editing-state').classList.add('hidden');
                document.getElementById('sidebar-brand-kit').classList.remove('hidden');
                return;
            }

            selectedElement = target;
            target.classList.add('ocms-selected');
            document.getElementById('sidebar-brand-kit').classList.add('hidden');
            document.getElementById('editing-state').classList.remove('hidden');
            populateEditControls(target);
        }

        function populateEditControls(el) {
            const style = iframe.contentWindow.getComputedStyle(el);
            const rgbToHex = (rgb) => {
                const m = rgb.match(/\d+/g);
                if (!m) return '#FFFFFF';
                return '#' + ((1 << 24) + (parseInt(m[0]) << 16) + (parseInt(m[1]) << 8) + parseInt(m[2])).toString(16).slice(1);
            };
            const bg = rgbToHex(style.backgroundColor);
            const color = rgbToHex(style.color);

            document.getElementById('edit-bg-color').value = bg;
            document.getElementById('edit-bg-hex').value = bg.toUpperCase();
            document.getElementById('edit-text-color').value = color;
            document.getElementById('edit-text-hex').value = color.toUpperCase();
            document.getElementById('edit-font-size').value = style.fontSize;
            document.getElementById('edit-font-weight').value = style.fontWeight;

            document.querySelectorAll('.text-align-btn').forEach(b => {
                b.classList.toggle('bg-white', b.dataset.align === style.textAlign);
            });
        }

        // Wire edit controls
        function wireControl(id, prop, transform) {
            document.getElementById(id).addEventListener('input', e => {
                if (!selectedElement) return;
                const val = transform ? transform(e.target.value) : e.target.value;
                selectedElement.style[prop] = val;
                trackEdit(prop, val);
                scheduleAutoSave();
            });
        }

        wireControl('edit-bg-color', 'backgroundColor');
        wireControl('edit-text-color', 'color');
        wireControl('edit-font-size', 'fontSize');
        wireControl('edit-font-weight', 'fontWeight');

        // Sync hex inputs
        document.getElementById('edit-bg-color').addEventListener('input', e => { document.getElementById('edit-bg-hex').value = e.target.value.toUpperCase(); });
        document.getElementById('edit-bg-hex').addEventListener('change', e => { document.getElementById('edit-bg-color').value = e.target.value; if (selectedElement) { selectedElement.style.backgroundColor = e.target.value; scheduleAutoSave(); } });
        document.getElementById('edit-text-color').addEventListener('input', e => { document.getElementById('edit-text-hex').value = e.target.value.toUpperCase(); });
        document.getElementById('edit-text-hex').addEventListener('change', e => { document.getElementById('edit-text-color').value = e.target.value; if (selectedElement) { selectedElement.style.color = e.target.value; scheduleAutoSave(); } });

        // Text alignment
        document.querySelectorAll('.text-align-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (selectedElement) {
                    selectedElement.style.textAlign = btn.dataset.align;
                    document.querySelectorAll('.text-align-btn').forEach(b => b.classList.toggle('bg-white', b === btn));
                    trackEdit('textAlign', btn.dataset.align);
                    scheduleAutoSave();
                }
            });
        });

        function trackEdit(property, value) {
            editHistory.push({ property, value, timestamp: new Date().toISOString() });
        }

        // Editor mode toggle
        window.setEditorMode = function(mode) {
            editorMode = mode;
            document.getElementById('mode-edit-btn').classList.toggle('bg-blue-50', mode === 'edit');
            document.getElementById('mode-edit-btn').classList.toggle('text-blue-700', mode === 'edit');
            document.getElementById('mode-preview-btn').classList.toggle('bg-blue-50', mode === 'preview');
            document.getElementById('mode-preview-btn').classList.toggle('text-blue-700', mode === 'preview');

            const doc = iframe.contentDocument;
            if (!doc) return;

            if (mode === 'preview') {
                if (selectedElement) selectedElement.classList.remove('ocms-selected');
                selectedElement = null;
                document.getElementById('editing-state').classList.add('hidden');
                document.getElementById('sidebar-brand-kit').classList.remove('hidden');
                const style = doc.getElementById('ocms-edit-styles');
                if (style) style.textContent = '';
            } else {
                setupIframeEditing();
            }
        };

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

        // HTML Editor
        window.toggleHtmlEditor = function() {
            const panel = document.getElementById('html-editor-panel');
            const isHidden = panel.classList.contains('hidden');
            panel.classList.toggle('hidden');
            if (isHidden) {
                document.getElementById('html-editor-textarea').value = getIframeHtml();
            }
        };

        window.applyHtmlEdits = function() {
            const html = document.getElementById('html-editor-textarea').value;
            loadPreview(html);
            toast('HTML changes applied');
        };

        function getIframeHtml() {
            const doc = iframe.contentDocument;
            if (!doc) return '';
            // Remove editing artifacts before serializing
            const clone = doc.documentElement.cloneNode(true);
            const editStyle = clone.querySelector('#ocms-edit-styles');
            if (editStyle) editStyle.remove();
            clone.querySelectorAll('[data-ocms-editable]').forEach(el => {
                el.removeAttribute('data-ocms-editable');
                el.classList.remove('ocms-selected');
            });
            return '<!DOCTYPE html>\n<html>' + clone.innerHTML + '</html>';
        }

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
