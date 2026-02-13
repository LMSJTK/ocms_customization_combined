<?php
// Start the session to store brand color data.
session_start();

// Handle POST requests to save the brand color.
// This acts as a mini-API endpoint for our JavaScript.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['brand_color'])) {
    // Sanitize the input just in case, expecting a hex color code.
    $color = filter_var($_POST['brand_color'], FILTER_SANITIZE_STRING);
    if (preg_match('/^#[a-f0-9]{6}$/i', $color)) {
        $_SESSION['brand_color'] = $color;
    }
    // Send a JSON response back to the client.
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'color' => $_SESSION['brand_color']]);
    exit; // Stop further execution.
}

// Retrieve the brand color from the session for use in the page.
// If it's not set, provide a default color.
$brandColor = $_SESSION['brand_color'] ?? '#4F46E5';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cofense Customisation Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }
        .main-gradient {
            background-image: linear-gradient(to bottom, rgba(230, 240, 255, 0.5), rgba(248, 249, 250, 1));
        }
        .tab-active {
            background-color: white;
            color: #3B82F6; /* blue-500 */
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            border-radius: 9999px;
        }
        .filter-dropdown {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            cursor: pointer;
        }
        .file-drop-zone {
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            padding: 2.5rem;
            text-align: center;
            background-color: #f9fafb;
        }
        .color-picker {
            width: 250px;
            height: 150px;
            cursor: crosshair;
            position: relative;
            background-image: linear-gradient(to top, rgba(0,0,0,1), transparent), linear-gradient(to right, white, transparent);
        }
        .color-picker-handle {
             width: 1rem;
            height: 1rem;
            border-radius: 9999px;
            border: 2px solid white;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.5);
            position: absolute;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }
        .hue-slider {
             height: 1rem;
             margin-top: 1rem;
             cursor: pointer;
             position: relative;
             border-radius: 9999px;
             background: linear-gradient(to right, hsl(0, 100%, 50%), hsl(60, 100%, 50%), hsl(120, 100%, 50%), hsl(180, 100%, 50%), hsl(240, 100%, 50%), hsl(300, 100%, 50%), hsl(360, 100%, 50%));
        }
         .hue-slider-handle {
            position: absolute;
            width: 1.25rem;
            height: 1.25rem;
            background-color: white;
            border: 2px solid #e5e7eb;
            border-radius: 9999px;
            top: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        .editor-sidebar {
            width: 320px;
            background-color: #f3f4f6;
        }
        .email-preview-container {
            flex: 1;
            background-color: #e5e7eb;
            overflow-y: auto;
        }
        .editable-element {
            cursor: pointer;
            transition: outline 0.2s ease-in-out;
        }
        .editable-element.selected {
            outline: 2px solid #ef4444; /* red-500 */
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
        }
    </style>
</head>
<body class="text-gray-800">

    <!-- Header -->
    <header class="bg-white/80 backdrop-blur-md border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-2">
                    <svg class="h-8 w-auto" viewBox="0 0 35 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M34.9999 13.9131C34.9999 13.6218 34.953 13.3306 34.8593 13.0393C34.5783 12.1656 32.7471 11.2919 29.3647 11.2919C25.9824 11.2919 24.1512 12.1656 23.8701 13.0393C23.7764 13.3306 23.7296 13.6218 23.7296 13.9131C23.7296 14.2044 23.7764 14.4956 23.8701 14.7869C24.1512 15.6606 25.9824 16.5344 29.3647 16.5344C32.7471 16.5344 34.5783 15.6606 34.8593 14.7869C34.953 14.4956 34.9999 14.2044 34.9999 13.9131ZM17.4999 27.8262C27.1633 27.8262 34.9999 21.5878 34.9999 13.9131C34.9999 6.23842 27.1633 0 17.4999 0C7.83655 0 0 6.23842 0 13.9131C0 21.5878 7.83655 27.8262 17.4999 27.8262ZM11.2701 13.9131C11.2701 13.6218 11.2233 13.3306 11.1296 13.0393C10.8485 12.1656 9.01732 11.2919 5.63497 11.2919C2.25263 11.2919 0.421404 12.1656 0.140282 13.0393C0.0465563 13.3306 0 13.6218 0 13.9131C0 14.2044 0.0465563 14.4956 0.140282 14.7869C0.421404 15.6606 2.25263 16.5344 5.63497 16.5344C9.01732 16.5344 10.8485 15.6606 11.1296 14.7869C11.2233 14.4956 11.2701 14.2044 11.2701 13.9131Z" fill="#0052CC"></path></svg>
                    <span class="text-sm font-semibold text-gray-500">COMMAND CENTER</span>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="home-nav" class="text-gray-600 hover:text-blue-600">
                        <i class="fa-solid fa-home mr-2"></i> Home
                    </button>
                    <button id="brand-kit-nav" class="text-gray-600 hover:text-blue-600">
                        <i class="fa-solid fa-palette mr-2"></i> Brand Kit
                    </button>
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold text-sm">JD</div>
                        <div>
                            <div class="font-semibold text-sm">John Doe</div>
                            <div class="text-xs text-gray-500">UI Designer</div>
                        </div>
                        <i class="fa-solid fa-chevron-down text-xs text-gray-500"></i>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main>
        <!-- View: Main Customization Portal -->
        <div id="portal-view">
            <div class="main-gradient pt-16 pb-24">
                <div class="text-center max-w-4xl mx-auto px-4">
                    <div class="inline-block p-4 bg-blue-500/10 rounded-full mb-4">
                        <div class="p-2 bg-blue-500 text-white rounded-full">
                           <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 00-.867.502l-2.834 5.667A1 1 0 007.166 11h5.668a1 1 0 00.867-1.831L10.867 3.502A1 1 0 0010 3zM3 10a1 1 0 00-1 .867l-1.5 5.833A1 1 0 001.367 18h17.266a1 1 0 00.867-1.299l-1.5-5.833A1 1 0 0017 10H3z" clip-rule="evenodd" /></svg>
                        </div>
                    </div>
                    <h1 class="text-4xl md:text-6xl font-bold tracking-tight text-gray-900">Cofense <br> Customisation Portal</h1>
                    <p class="mt-6 text-lg text-gray-600">Create, customise, and manage email, education, and e-learning templates that engage your team and strengthen security awareness.</p>
                </div>
            </div>

            <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 -mt-12">
                <!-- Tabs -->
                <div class="bg-gray-100 p-2 rounded-full flex items-center justify-center space-x-2 max-w-3xl mx-auto">
                    <button class="px-4 py-2 text-sm font-medium flex items-center tab-active"><i class="fa-regular fa-envelope mr-2"></i> Emails</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-600 flex items-center"><i class="fa-regular fa-newspaper mr-2"></i> Newsletters</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-600 flex items-center"><i class="fa-solid fa-graduation-cap mr-2"></i> Education</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-600 flex items-center"><i class="fa-solid fa-chart-pie mr-2"></i> Infographics</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-600 flex items-center"><i class="fa-solid fa-video mr-2"></i> Videos</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-600 flex items-center"><i class="fa-solid fa-desktop mr-2"></i> E-Learning</button>
                </div>
                
                <!-- Search & Filters -->
                <div class="bg-white p-6 rounded-lg shadow-sm mt-8">
                    <div class="flex space-x-4">
                        <div class="relative flex-grow">
                            <i class="fa-solid fa-magnifying-glass absolute top-1/2 left-4 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" placeholder="Search thousands of email templates..." class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button class="bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700">Search</button>
                    </div>
                    <div class="flex items-center justify-between mt-4">
                        <div class="flex items-center space-x-2">
                           <div class="filter-dropdown">SEG Misses <i class="fa-solid fa-chevron-down text-xs ml-2"></i></div>
                           <div class="filter-dropdown">Theme <i class="fa-solid fa-chevron-down text-xs ml-2"></i></div>
                           <div class="filter-dropdown">Languages <i class="fa-solid fa-chevron-down text-xs ml-2"></i></div>
                           <div class="filter-dropdown">Industry <i class="fa-solid fa-chevron-down text-xs ml-2"></i></div>
                           <div class="filter-dropdown">More Filters <i class="fa-solid fa-chevron-down text-xs ml-2"></i></div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="flex items-center space-x-2">
                                <i class="fa-regular fa-bookmark text-gray-600"></i>
                                <span class="text-sm font-medium">Bookmarked</span>
                            </div>
                            <div class="flex items-center">
                                <label for="my-content-toggle" class="flex items-center cursor-pointer">
                                    <div class="relative">
                                      <input type="checkbox" id="my-content-toggle" class="sr-only" checked>
                                      <div class="block bg-gray-200 w-12 h-6 rounded-full"></div>
                                      <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition"></div>
                                    </div>
                                    <div class="ml-3 text-sm font-medium">My Content</div>
                                </label>
                                <style>
                                    input:checked ~ .dot {
                                        transform: translateX(100%);
                                        left: 7px;
                                    }
                                    input:checked ~ .block {
                                        background-color: #3b82f6;
                                    }
                                </style>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Template Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6 mt-8">
                    <!-- Template Card -->
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group cursor-pointer template-card">
                        <div class="h-40 bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-regular fa-envelope text-4xl"></i></div>
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-800">Security Awareness Email</h3>
                            <p class="text-sm text-gray-500 mt-1">Basic template for alerts.</p>
                        </div>
                    </div>
                    <!-- More placeholder cards -->
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group cursor-pointer template-card"><div class="h-40 bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-solid fa-shield-virus text-4xl"></i></div><div class="p-4"><h3 class="font-semibold text-gray-800">Phishing Alert</h3><p class="text-sm text-gray-500 mt-1">Template for phishing threats.</p></div></div>
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group cursor-pointer template-card"><div class="h-40 bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-regular fa-newspaper text-4xl"></i></div><div class="p-4"><h3 class="font-semibold text-gray-800">Weekly Security News</h3><p class="text-sm text-gray-500 mt-1">Newsletter format.</p></div></div>
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group cursor-pointer template-card"><div class="h-40 bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-solid fa-key text-4xl"></i></div><div class="p-4"><h3 class="font-semibold text-gray-800">Password Policy Update</h3><p class="text-sm text-gray-500 mt-1">Official company communication.</p></div></div>
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group cursor-pointer template-card"><div class="h-40 bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-solid fa-mobile-screen-button text-4xl"></i></div><div class="p-4"><h3 class="font-semibold text-gray-800">MFA Rollout</h3><p class="text-sm text-gray-500 mt-1">Announcement template.</p></div></div>
                </div>
            </div>
        </div>

        <!-- View: Brand Kit Manager -->
        <div id="brand-kit-view" class="hidden">
            <div class="max-w-5xl mx-auto p-8">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold">Brand Kit Manager</h1>
                        <p class="text-gray-600 mt-1">Manage your brand assets and apply them across all templates.</p>
                    </div>
                    <div>
                        <button class="text-sm text-gray-600 hover:text-gray-900 mr-4"><i class="fa-solid fa-arrow-rotate-left mr-2"></i>Reset to Default</button>
                        <button class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-700">Save Brand Kit</button>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-lg shadow-sm grid grid-cols-1 md:grid-cols-2 gap-12">
                    <!-- Left Column -->
                    <div>
                        <h2 class="text-lg font-semibold">Logo Upload</h2>
                        <div class="mt-4 file-drop-zone">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-gray-400"></i>
                            <p class="mt-2 font-semibold">Upload a file or drag and drop</p>
                            <p class="text-sm text-gray-500">JPG, JPEG, PNG, GIF, WebP up to 10MB</p>
                        </div>
                        
                        <h2 class="text-lg font-semibold mt-8">Brand Fonts</h2>
                        <p class="text-sm text-gray-500">Select fonts that represent your brand or upload a custom one.</p>
                        <select class="mt-4 w-full p-2 border border-gray-300 rounded-lg">
                            <option>Inter</option>
                            <option>Poppins</option>
                            <option>Roboto</option>
                        </select>

                        <h2 class="text-lg font-semibold mt-8">Upload fonts</h2>
                         <div class="mt-4 file-drop-zone">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-gray-400"></i>
                            <p class="mt-2 font-semibold">Upload a file or drag and drop</p>
                            <p class="text-sm text-gray-500">WOFF, WOFF2, TTF, OTF up to 10MB</p>
                        </div>
                    </div>
                    <!-- Right Column -->
                    <div>
                        <h2 class="text-lg font-semibold">Brand Colours</h2>
                        <p class="text-sm text-gray-500">Choose colours that will be applied to buttons, highlights, and headers throughout the training.</p>
                        <div class="flex items-center space-x-2 mt-4">
                            <div id="color-swatch" class="w-10 h-10 rounded-lg cursor-pointer border-2 border-blue-700" style="background-color: <?php echo $brandColor; ?>;"></div>
                            <div class="w-10 h-10 rounded-lg bg-red-500 cursor-pointer"></div>
                            <div class="w-10 h-10 rounded-lg bg-green-500 cursor-pointer"></div>
                            <div class="w-10 h-10 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 cursor-pointer"><i class="fa-solid fa-plus"></i></div>
                        </div>
                        <div class="mt-4 relative">
                            <div id="color-picker" class="color-picker rounded-lg">
                                <div id="color-picker-handle" class="color-picker-handle"></div>
                            </div>
                        </div>
                        <div id="hue-slider" class="hue-slider">
                            <div id="hue-slider-handle" class="hue-slider-handle"></div>
                        </div>
                        
                        <div class="flex items-center space-x-2 mt-4 text-sm">
                            <div class="font-semibold">Hex</div>
                            <input type="text" id="hex-input" value="<?php echo $brandColor; ?>" class="w-24 p-1 border rounded">
                        </div>
                        
                        <div class="mt-4">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold text-sm">Saved colors:</h3>
                                <button class="text-sm text-blue-600 font-semibold">+ Add</button>
                            </div>
                            <div class="grid grid-cols-8 gap-2 mt-2">
                                <div class="w-7 h-7 rounded-full bg-red-400"></div><div class="w-7 h-7 rounded-full bg-orange-400"></div><div class="w-7 h-7 rounded-full bg-yellow-400"></div><div class="w-7 h-7 rounded-full bg-lime-400"></div><div class="w-7 h-7 rounded-full bg-green-400"></div><div class="w-7 h-7 rounded-full bg-teal-400"></div><div class="w-7 h-7 rounded-full bg-cyan-400"></div><div class="w-7 h-7 rounded-full bg-blue-400 border-2 border-blue-600"></div><div class="w-7 h-7 rounded-full bg-indigo-400"></div><div class="w-7 h-7 rounded-full bg-purple-400"></div><div class="w-7 h-7 rounded-full bg-fuchsia-400"></div><div class="w-7 h-7 rounded-full bg-pink-400"></div><div class="w-7 h-7 rounded-full bg-rose-400"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- View: Email Editor -->
        <div id="editor-view" class="hidden">
             <div class="bg-white border-b border-gray-200">
                <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-16">
                        <div>
                            <div class="flex items-center">
                                <h1 class="text-lg font-semibold">Security Awareness Email</h1>
                                <button class="ml-2 text-gray-500 hover:text-gray-800"><i class="fa-solid fa-ellipsis"></i></button>
                            </div>
                            <p class="text-xs text-gray-500">Last edited 2 hours ago - Auto-saved</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button class="text-sm px-4 py-2 rounded-lg hover:bg-gray-100 flex items-center"><i class="fa-regular fa-comment-dots mr-2"></i></button>
                            <button class="px-4 py-2 rounded-lg text-sm font-semibold border bg-white hover:bg-gray-50 flex items-center"><i class="fa-solid fa-pen mr-2"></i>Edit</button>
                            <button class="px-4 py-2 rounded-lg text-sm font-semibold border bg-white hover:bg-gray-50 flex items-center"><i class="fa-regular fa-eye mr-2"></i>Preview</button>
                            <button class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-700">Save</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex" style="height: calc(100vh - 128px);">
                <!-- Editor Sidebar -->
                <div class="editor-sidebar p-6 border-r border-gray-200 flex flex-col justify-between">
                    <div id="sidebar-content">
                        <!-- Initial State -->
                        <div id="brand-kit-available-state">
                             <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center">
                                    <div class="bg-blue-100 text-blue-600 rounded-full h-8 w-8 flex items-center justify-center">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    </div>
                                    <h3 class="font-semibold ml-3">Brand Kit Available</h3>
                                </div>
                                <p class="text-sm text-gray-600 mt-2">Apply your company's logo and colours to this template automatically.</p>
                                <button id="apply-brand-kit-btn" class="w-full bg-blue-600 text-white mt-4 py-2 rounded-lg font-semibold hover:bg-blue-700">Apply Brand Kit</button>
                            </div>
                        </div>

                        <!-- Applied State -->
                        <div id="brand-kit-applied-state" class="hidden">
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <div class="flex items-center">
                                     <div class="bg-green-100 text-green-600 rounded-full h-8 w-8 flex items-center justify-center">
                                        <i class="fa-solid fa-check"></i>
                                    </div>
                                    <h3 class="font-semibold ml-3">Brand Kit Applied</h3>
                                </div>
                                <p class="text-sm text-gray-600 mt-2">Your template has been updated with your brand colours.</p>
                                <button id="undo-changes-btn" class="w-full bg-white border border-gray-300 mt-4 py-2 rounded-lg font-semibold hover:bg-gray-50">
                                    <i class="fa-solid fa-arrow-rotate-left mr-2"></i>Undo Changes
                                </button>
                            </div>
                        </div>

                        <!-- Editing State -->
                        <div id="editing-state" class="hidden">
                            <h3 class="font-semibold mb-4 text-lg">Editing Element</h3>
                            <!-- Colour Section -->
                            <div>
                                <h4 class="font-semibold text-sm mb-2">Colours</h4>
                                <div class="space-y-2">
                                    <label class="block text-xs text-gray-600">Background Colour</label>
                                    <div class="flex items-center border bg-white rounded-md p-1">
                                        <input type="color" id="bg-color-picker" value="#2563eb" class="w-6 h-6 rounded border-none cursor-pointer p-0" style="background-color: transparent;">
                                        <input type="text" id="bg-color-hex" class="ml-2 text-sm border-none focus:ring-0 w-full" value="#2563EB">
                                    </div>
                                    <label class="block text-xs text-gray-600">Text Colour</label>
                                    <div class="flex items-center border bg-white rounded-md p-1">
                                        <input type="color" id="text-color-picker" value="#ffffff" class="w-6 h-6 rounded border-none cursor-pointer p-0" style="background-color: transparent;">
                                        <input type="text" id="text-color-hex" class="ml-2 text-sm border-none focus:ring-0 w-full" value="#FFFFFF">
                                    </div>
                                </div>
                            </div>
                             <!-- Typography Section -->
                            <div class="mt-6">
                                <h4 class="font-semibold text-sm mb-2">Typography</h4>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-600">Font Size & Weight</label>
                                        <select id="font-size-select" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                                            <option>16px</option><option>18px</option><option>24px</option><option>32px</option><option>48px</option>
                                        </select>
                                    </div>
                                     <div>
                                        <label class="block text-xs text-gray-600">&nbsp;</label>
                                        <select id="font-weight-select" class="w-full p-2 border border-gray-300 rounded-lg text-sm">
                                            <option value="400">Regular</option><option value="500">Medium</option><option value="600">Semibold</option><option value="700">Bold</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                             <!-- Text Alignment Section -->
                             <div class="mt-6">
                                <h4 class="font-semibold text-sm mb-2">Text Alignment</h4>
                                <div class="grid grid-cols-3 gap-1 bg-gray-200 p-1 rounded-lg">
                                    <button data-align="left" class="text-align-btn bg-white rounded py-1"><i class="fas fa-align-left"></i></button>
                                    <button data-align="center" class="text-align-btn rounded py-1"><i class="fas fa-align-center"></i></button>
                                    <button data-align="right" class="text-align-btn rounded py-1"><i class="fas fa-align-right"></i></button>
                                </div>
                             </div>
                        </div>
                    </div>
                    
                    <button class="w-full border border-gray-300 py-2 rounded-lg font-semibold hover:bg-gray-100 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-code mr-2"></i>Advanced HTML Editor
                    </button>
                </div>
                
                <!-- Email Preview -->
                <div class="email-preview-container p-8 flex justify-center">
                    <div id="email-preview" class="bg-white rounded-lg shadow-lg w-full max-w-2xl overflow-hidden p-12 text-center" style="font-family: Arial, sans-serif; color: #333;">
                        <div id="email-header" class="editable-element p-12 rounded-t-lg" style="background-color: #2563eb; color: white;">
                            <h1 class="text-4xl font-bold editable-element">Stay Secure Online</h1>
                            <p class="mt-2 text-lg editable-element">Essential Tips to protect your digital identity</p>
                        </div>
                        <div class="p-10 text-left">
                            <h2 class="text-xl font-bold mb-2 editable-element">Recognize Phishing Attempts</h2>
                            <p class="text-base mb-6 editable-element" style="color: #4b5563;">Cybercriminals often use deceptive emails to trick users into revealing sensitive information. Always verify the sender's email address, look for grammatical errors, and be cautious of urgent requests for personal data.</p>
                            <h2 class="text-xl font-bold mb-2 editable-element">Use Strong Passwords</h2>
                            <p class="text-base mb-8 editable-element" style="color: #4b5563;">Create unique passwords for each account using a combination of uppercase and lowercase letters, numbers, and special characters. Consider using a password manager to keep track of your credentials securely.</p>
                            <a href="#" id="email-button" class="editable-element inline-block text-white font-bold py-3 px-8 rounded-lg text-base" style="background-color: #2563eb; text-decoration: none;">Learn More About Security</a>
                        </div>
                         <div class="text-xs text-gray-500 mt-12 pb-4">
                            <p>&copy; 2025 Cofense. All rights reserved.</p>
                            <p>If you have questions, contact security@company.com</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- PHP Data Injection ---
            const initialBrandColor = '<?php echo $brandColor; ?>';

            // --- View Navigation ---
            const portalView = document.getElementById('portal-view');
            const brandKitView = document.getElementById('brand-kit-view');
            const editorView = document.getElementById('editor-view');
            const homeNav = document.getElementById('home-nav');
            const brandKitNav = document.getElementById('brand-kit-nav');
            const templateCards = document.querySelectorAll('.template-card');
            
            function showView(view) {
                portalView.classList.add('hidden');
                brandKitView.classList.add('hidden');
                editorView.classList.add('hidden');
                view.classList.remove('hidden');
                window.scrollTo(0, 0);
            }

            homeNav.addEventListener('click', () => showView(portalView));
            brandKitNav.addEventListener('click', () => showView(brandKitView));
            templateCards.forEach(card => card.addEventListener('click', () => showView(editorView)));
            
            // --- Brand Kit Color Picker Logic ---
            const colorPicker = document.getElementById('color-picker');
            const pickerHandle = document.getElementById('color-picker-handle');
            const hueSlider = document.getElementById('hue-slider');
            const hueHandle = document.getElementById('hue-slider-handle');
            const hexInput = document.getElementById('hex-input');
            const colorSwatch = document.getElementById('color-swatch');

            let currentHue = 0, currentSat = 100, currentLight = 50;

            function updateColorUI(source) {
                const hsv = hslToHsv(currentHue, currentSat, currentLight);
                const rgb = hsvToRgb(hsv.h, hsv.s, hsv.v);
                const hex = rgbToHex(rgb.r, rgb.g, rgb.b);

                hexInput.value = hex.toUpperCase();
                colorSwatch.style.backgroundColor = hex;
                
                if(source !== 'input') {
                   debouncedSaveColor(hex);
                }
            }

            // Color Conversion Utilities
            function hslToHsv(h, s, l) {
                s /= 100; l /= 100;
                const v = s * Math.min(l, 1 - l) + l;
                const sv = v ? 2 * (1 - l / v) : 0;
                return { h: h, s: sv * 100, v: v * 100 };
            }
            function hsvToRgb(h, s, v) {
                s /= 100; v /= 100;
                let f = (n, k = (n + h / 60) % 6) => v - v * s * Math.max(Math.min(k, 4 - k, 1), 0);
                return { r: Math.round(f(5) * 255), g: Math.round(f(3) * 255), b: Math.round(f(1) * 255) };
            }
            function rgbToHex(r, g, b) {
                return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
            }
            function hexToRgb(hex) {
                let result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
                return result ? { r: parseInt(result[1], 16), g: parseInt(result[2], 16), b: parseInt(result[3], 16) } : null;
            }
            function rgbToHsl(r, g, b) {
                r /= 255, g /= 255, b /= 255;
                let max = Math.max(r, g, b), min = Math.min(r, g, b);
                let h, s, l = (max + min) / 2;
                if (max == min) { h = s = 0; } 
                else {
                    let d = max - min;
                    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                    switch (max) {
                        case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                        case g: h = (b - r) / d + 2; break;
                        case b: h = (r - g) / d + 4; break;
                    }
                    h /= 6;
                }
                return { h: h * 360, s: s * 100, l: l * 100 };
            }

            function setupPicker(target, handle, onDrag) {
                let isDragging = false;
                const onMouseMove = (e) => {
                    if (!isDragging) return;
                    e.preventDefault();
                    const rect = target.getBoundingClientRect();
                    const x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
                    const y = Math.max(0, Math.min(e.clientY - rect.top, rect.height));
                    onDrag({ x, y, width: rect.width, height: rect.height });
                };
                const onMouseUp = () => {
                    isDragging = false;
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };
                target.addEventListener('mousedown', (e) => {
                    isDragging = true;
                    onMouseMove(e); // Handle click as well
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            }

            setupPicker(hueSlider, hueHandle, ({ x, width }) => {
                currentHue = (x / width) * 360;
                colorPicker.style.backgroundColor = `hsl(${currentHue}, 100%, 50%)`;
                hueHandle.style.left = `${(currentHue / 360) * 100}%`;
                updateColorUI();
            });

            setupPicker(colorPicker, pickerHandle, ({ x, y, width, height }) => {
                const s = (x / width) * 100;
                const l_v_mix = 1 - (y / height); // This is a mix of Lightness and Value
                currentLight = l_v_mix * 50; // Approximation for HSL's Lightness
                currentSat = s;
                pickerHandle.style.left = `${s}%`;
                pickerHandle.style.top = `${y}px`;
                updateColorUI();
            });
            
            hexInput.addEventListener('change', (e) => {
                const rgb = hexToRgb(e.target.value);
                if(rgb) {
                    const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
                    setPickerFromColor(e.target.value);
                    debouncedSaveColor(e.target.value);
                }
            });

            function setPickerFromColor(hex) {
                 const rgb = hexToRgb(hex);
                if (!rgb) return;
                const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
                
                currentHue = hsl.h;
                currentSat = hsl.s;
                currentLight = hsl.l;
                
                colorPicker.style.backgroundColor = `hsl(${currentHue}, 100%, 50%)`;
                hueHandle.style.left = `${(currentHue / 360) * 100}%`;
                
                const hsv = hslToHsv(currentHue, currentSat, currentLight);
                const pickerX = hsv.s;
                const pickerY = 100 - hsv.v;

                pickerHandle.style.left = `${pickerX}%`;
                pickerHandle.style.top = `${pickerY}%`;
                
                updateColorUI('input');
            }

            // Initialize picker with color from PHP
            setPickerFromColor(initialBrandColor);

            // Debounce function to avoid too many server requests
            function debounce(func, delay) {
                let timeout;
                return function(...args) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), delay);
                };
            }

            // Function to save color to server
            function saveColorToServer(hexColor) {
                const formData = new FormData();
                formData.append('brand_color', hexColor);
                fetch('index.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => console.log('Color saved:', data.color))
                    .catch(err => console.error('Save failed:', err));
            }
            const debouncedSaveColor = debounce(saveColorToServer, 300);

            // --- Editor Logic ---
            const applyBtn = document.getElementById('apply-brand-kit-btn');
            const undoBtn = document.getElementById('undo-changes-btn');
            const availableState = document.getElementById('brand-kit-available-state');
            const appliedState = document.getElementById('brand-kit-applied-state');
            const editingState = document.getElementById('editing-state');
            
            const emailPreview = document.getElementById('email-preview');
            const editableElements = emailPreview.querySelectorAll('.editable-element');
            let selectedElement = null;

            const brandKitColors = {
                primary: initialBrandColor,
                text: '#FFFFFF' // Default to white text on colored background
            };

            const originalStyles = {};
            
            function saveOriginalStyles() {
                originalStyles.headerBG = emailPreview.querySelector('#email-header').style.backgroundColor;
                originalStyles.headerColor = emailPreview.querySelector('#email-header').style.color;
                originalStyles.buttonBG = emailPreview.querySelector('#email-button').style.backgroundColor;
            }
            saveOriginalStyles();

            applyBtn.addEventListener('click', () => {
                emailPreview.querySelector('#email-header').style.backgroundColor = brandKitColors.primary;
                emailPreview.querySelector('#email-header').style.color = brandKitColors.text;
                emailPreview.querySelectorAll('#email-header .editable-element').forEach(el => el.style.color = brandKitColors.text);
                emailPreview.querySelector('#email-button').style.backgroundColor = brandKitColors.primary;
                emailPreview.querySelector('#email-button').style.color = brandKitColors.text;

                availableState.classList.add('hidden');
                appliedState.classList.remove('hidden');
            });

            undoBtn.addEventListener('click', () => {
                emailPreview.querySelector('#email-header').style.backgroundColor = originalStyles.headerBG;
                emailPreview.querySelector('#email-header').style.color = originalStyles.headerColor;
                emailPreview.querySelectorAll('#email-header .editable-element').forEach(el => el.style.color = originalStyles.headerColor);
                emailPreview.querySelector('#email-button').style.backgroundColor = originalStyles.buttonBG;
                emailPreview.querySelector('#email-button').style.color = originalStyles.headerColor;

                appliedState.classList.add('hidden');
                availableState.classList.remove('hidden');
            });

            // --- Element Editing ---
            const bgColorPicker = document.getElementById('bg-color-picker');
            const bgColorHex = document.getElementById('bg-color-hex');
            const textColorPicker = document.getElementById('text-color-picker');
            const textColorHex = document.getElementById('text-color-hex');
            const fontSizeSelect = document.getElementById('font-size-select');
            const fontWeightSelect = document.getElementById('font-weight-select');
            const textAlignBtns = document.querySelectorAll('.text-align-btn');

            function updateSidebarControls(element) {
                const styles = window.getComputedStyle(element);
                const rgbBg = styles.backgroundColor;
                const rgbBgArray = rgbBg.match(/\d+/g);
                const hexBg = rgbBgArray ? rgbToHex(parseInt(rgbBgArray[0]), parseInt(rgbBgArray[1]), parseInt(rgbBgArray[2])) : '#FFFFFF';
                bgColorPicker.value = hexBg;
                bgColorHex.value = hexBg.toUpperCase();

                const rgbText = styles.color;
                const rgbTextArray = rgbText.match(/\d+/g);
                const hexText = rgbTextArray ? rgbToHex(parseInt(rgbTextArray[0]), parseInt(rgbTextArray[1]), parseInt(rgbTextArray[2])) : '#000000';
                textColorPicker.value = hexText;
                textColorHex.value = hexText.toUpperCase();

                fontSizeSelect.value = styles.fontSize;
                fontWeightSelect.value = styles.fontWeight;
                textAlignBtns.forEach(btn => {
                    btn.classList.toggle('bg-white', btn.dataset.align === styles.textAlign);
                });
            }

            editableElements.forEach(el => {
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (selectedElement) selectedElement.classList.remove('selected');
                    selectedElement = el;
                    selectedElement.classList.add('selected');
                    availableState.classList.add('hidden');
                    appliedState.classList.add('hidden');
                    editingState.classList.remove('hidden');
                    updateSidebarControls(selectedElement);
                });
            });

            bgColorPicker.addEventListener('input', (e) => { if(selectedElement) selectedElement.style.backgroundColor = e.target.value; bgColorHex.value = e.target.value.toUpperCase(); });
            bgColorHex.addEventListener('input', (e) => { if(selectedElement) selectedElement.style.backgroundColor = e.target.value; bgColorPicker.value = e.target.value; });
            textColorPicker.addEventListener('input', (e) => { if(selectedElement) selectedElement.style.color = e.target.value; textColorHex.value = e.target.value.toUpperCase(); });
            textColorHex.addEventListener('input', (e) => { if(selectedElement) selectedElement.style.color = e.target.value; textColorPicker.value = e.target.value; });
            fontSizeSelect.addEventListener('change', e => { if(selectedElement) selectedElement.style.fontSize = e.target.value; });
            fontWeightSelect.addEventListener('change', e => { if(selectedElement) selectedElement.style.fontWeight = e.target.value; });
            textAlignBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    if(selectedElement) selectedElement.style.textAlign = btn.dataset.align;
                    textAlignBtns.forEach(b => b.classList.remove('bg-white'));
                    btn.classList.add('bg-white');
                });
            });

            document.body.addEventListener('click', (e) => {
                if(selectedElement && !emailPreview.contains(e.target) && !document.getElementById('sidebar-content').contains(e.target)) {
                    selectedElement.classList.remove('selected');
                    selectedElement = null;
                    editingState.classList.add('hidden');
                    if (undoBtn.parentElement.classList.contains('hidden')) {
                        availableState.classList.remove('hidden');
                    } else {
                        appliedState.classList.remove('hidden');
                    }
                }
            });
        });
    </script>
</body>
</html>
