<?php
/**
 * ==============================================================================
 * 🏠 CueRCode - Main QR Code Generator Page
 * ==============================================================================
 *
 * Path: public_html/index.php
 *
 * The primary page for the CueRCode service. Provides a responsive two-column
 * layout with QR type selection, dynamic content form, customization tabs
 * (Colors, Design, Logo, Frame, Options), and a live AJAX preview panel.
 *
 * Layout:
 *   Desktop (lg+): Two columns - left (form/customization), right (preview)
 *   Tablet/Mobile:  Stacked - form first, preview below
 *
 * JavaScript Enhancement:
 *   With JS: AJAX live preview, dynamic form fields, interactive customization
 *   Without JS: Standard form POST, server-side generation, result page
 *
 * @package     CueRCode
 * @subpackage  Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://getbootstrap.com/docs/5.3/layout/grid/
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================

require_once __DIR__ . '/../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load QR Code Generation Functions
// ==============================================================================
// These are loaded separately from bootstrap.php as they are page-specific.
// @see fn_qr_payloads.php, fn_qr_options.php, fn_qr_generate.php, etc.
// ==============================================================================

$qrFunctionFiles = [
    'fn_qr_payloads.php',
    'fn_qr_options.php',
    'fn_qr_generate.php',
    'fn_qr_logo.php',
    'fn_qr_frames.php',
];

foreach ($qrFunctionFiles as $qrFile) {
    $qrFilePath = CUERCODE_FUNCTIONS_DIR . $qrFile;
    if (file_exists($qrFilePath)) {
        require_once $qrFilePath;
    }
}

// ==============================================================================
// 📊 Fetch QR Code Types from Database
// ==============================================================================
// Load all active QR types with their form field schemas for the type selector
// and JavaScript dynamic form rendering.
// @see https://www.php.net/manual/en/mysqli-result.fetch-all.php
// ==============================================================================

$qrTypes = [];

try {
    $stmt = dbQuery(
        "SELECT `typeID`, `typeName`, `typeLabel`, `typeIcon`, `typeDescription`, "
        . "`typeCategory`, `payloadTemplate`, `formFieldsJSON`, `sortOrder` "
        . "FROM `tblQRCodeTypes` WHERE `isActive` = 1 ORDER BY `sortOrder` ASC"
    );
    $result = $stmt->get_result();
    $qrTypes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    // ⚠️ Failed to load QR types - log error, continue with empty array
    if (function_exists('logError')) {
        logError('error', 'Failed to load QR code types', $e->getMessage());
    }
}

// ==============================================================================
// 🖼️ Fetch Predefined Logos from Database
// ==============================================================================

$predefinedLogos = [];

try {
    $stmt = dbQuery(
        "SELECT `logoID`, `logoName`, `logoLabel`, `logoFileName`, `logoCategory`, `sortOrder` "
        . "FROM `tblPredefinedLogos` WHERE `isActive` = 1 ORDER BY `sortOrder` ASC"
    );
    $result = $stmt->get_result();
    $predefinedLogos = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    if (function_exists('logError')) {
        logError('warning', 'Failed to load predefined logos', $e->getMessage());
    }
}

// ==============================================================================
// ⚙️ Load Default Settings
// ==============================================================================

if (function_exists('getConfig')) {
    $defaultSize = (int) getConfig('default_qr_size', 800);
} else {
    $defaultSize = 800;
}

if (function_exists('getConfig')) {
    $defaultEcc = getConfig('default_qr_ecc', 'H');
} else {
    $defaultEcc = 'H';
}

if (function_exists('getConfig')) {
    $defaultFormat = getConfig('default_qr_format', 'png');
} else {
    $defaultFormat = 'png';
}

if (function_exists('getConfig')) {
    $defaultFgColor = getConfig('default_fg_color', '#000000');
} else {
    $defaultFgColor = '#000000';
}

if (function_exists('getConfig')) {
    $defaultBgColor = getConfig('default_bg_color', '#ffffff');
} else {
    $defaultBgColor = '#ffffff';
}

if (function_exists('getConfig')) {
    $defaultTransparent = (bool) getConfig('default_transparent_bg', false);
} else {
    $defaultTransparent = false;
}

if (function_exists('getConfig')) {
    $defaultPattern = getConfig('default_pattern', 'square');
} else {
    $defaultPattern = 'square';
}

if (function_exists('getConfig')) {
    $defaultMarkerBorder = getConfig('default_marker_border', 'square');
} else {
    $defaultMarkerBorder = 'square';
}

if (function_exists('getConfig')) {
    $defaultMarkerCenter = getConfig('default_marker_center', 'square');
} else {
    $defaultMarkerCenter = 'square';
}

if (function_exists('getConfig')) {
    $defaultFrameStyle = getConfig('default_frame_style', 'none');
} else {
    $defaultFrameStyle = 'none';
}

if (function_exists('getConfig')) {
    $defaultLogoSize = (int) getConfig('default_logo_size', 20);
} else {
    $defaultLogoSize = 20;
}

if (function_exists('getConfig')) {
    $defaultMaxSize = (int) getConfig('max_qr_size', 4000);
} else {
    $defaultMaxSize = 4000;
}

if (function_exists('getConfig')) {
    $defaultMaxLogoSize = (int) getConfig('max_logo_upload_size', 5242880);
} else {
    $defaultMaxLogoSize = 5242880;
}

$defaults = [
    'size'           => $defaultSize,
    'ecc'            => $defaultEcc,
    'format'         => $defaultFormat,
    'fg_color'       => $defaultFgColor,
    'bg_color'       => $defaultBgColor,
    'transparent'    => $defaultTransparent,
    'pattern'        => $defaultPattern,
    'marker_border'  => $defaultMarkerBorder,
    'marker_center'  => $defaultMarkerCenter,
    'frame_style'    => $defaultFrameStyle,
    'logo_size'      => $defaultLogoSize,
    'max_size'       => $defaultMaxSize,
    'max_logo_size'  => $defaultMaxLogoSize,
];

// ==============================================================================
// 📤 Handle Non-JS Form POST (Graceful Degradation)
// ==============================================================================

$generatedResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['qr_type'])) {
    // 🛡️ Validate CSRF token
    if (isset($_POST['csrf_token'])) {
        $csrfTokenValue = $_POST['csrf_token'];
    } else {
        $csrfTokenValue = '';
    }
    if (function_exists('validateCSRFToken')) {
        $csrfValid = validateCSRFToken($csrfTokenValue);
    } else {
        $csrfValid = true;
    }

    if ($csrfValid) {
        if (isset($_POST['qr_type'])) {
            $postType = trim($_POST['qr_type']);
        } else {
            $postType = 'url';
        }
        if (isset($_POST['output_format'])) {
            $postFormat = trim($_POST['output_format']);
        } else {
            $postFormat = $defaults['format'];
        }

        // 📌 Collect input data from POST (all fields except internal ones)
        $internalFields = [
            'qr_type', 'csrf_token', 'output_format', 'output_size',
            'ecc_level', 'fg_color', 'bg_color', 'marker_color',
            'pattern', 'marker_border', 'marker_center', 'logo',
            'logo_upload', 'logo_size', 'frame_style', 'frame_label',
            'frame_color', 'transparent_bg',
        ];

        $inputData = [];
        foreach ($_POST as $key => $value) {
            if (!in_array($key, $internalFields, true)) {
                $inputData[$key] = $value;
            }
        }

        // 📌 Build customization array from POST data
        if (isset($_POST['output_size'])) {
            $custSize = (int) $_POST['output_size'];
        } else {
            $custSize = $defaults['size'];
        }
        if (isset($_POST['ecc_level'])) {
            $custEcc = $_POST['ecc_level'];
        } else {
            $custEcc = $defaults['ecc'];
        }
        if (isset($_POST['fg_color'])) {
            $custFgColor = $_POST['fg_color'];
        } else {
            $custFgColor = $defaults['fg_color'];
        }
        if (isset($_POST['bg_color'])) {
            $custBgColor = $_POST['bg_color'];
        } else {
            $custBgColor = $defaults['bg_color'];
        }
        if (isset($_POST['marker_color'])) {
            $custMarkerColor = $_POST['marker_color'];
        } else {
            $custMarkerColor = '';
        }
        if (isset($_POST['pattern'])) {
            $custPattern = $_POST['pattern'];
        } else {
            $custPattern = $defaults['pattern'];
        }
        if (isset($_POST['frame_style'])) {
            $custFrameStyle = $_POST['frame_style'];
        } else {
            $custFrameStyle = 'none';
        }
        if (isset($_POST['frame_label'])) {
            $custFrameLabel = $_POST['frame_label'];
        } else {
            $custFrameLabel = '';
        }
        if (isset($_POST['frame_color'])) {
            $custFrameColor = $_POST['frame_color'];
        } else {
            $custFrameColor = '';
        }
        $customization = [
            'format'       => $postFormat,
            'size'         => $custSize,
            'ecc'          => $custEcc,
            'fg_color'     => $custFgColor,
            'bg_color'     => $custBgColor,
            'marker_color' => $custMarkerColor,
            'pattern'      => $custPattern,
            'transparent'  => isset($_POST['transparent_bg']),
            'frame_style'  => $custFrameStyle,
            'frame_label'  => $custFrameLabel,
            'frame_color'  => $custFrameColor,
        ];

        // 📌 Handle logo from POST
        if (!empty($_POST['logo'])) {
            $customization['logo'] = $_POST['logo'];
            if (isset($_POST['logo_size'])) {
                $customization['logo_size_pct'] = (int) $_POST['logo_size'];
            } else {
                $customization['logo_size_pct'] = $defaults['logo_size'];
            }
        }

        // 🎯 Generate the QR code
        if (function_exists('generateQRCode')) {
            $generatedResult = generateQRCode($postType, $inputData, $customization);
        }
    }
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

$pageTitle       = 'QR Code Generator';
$pageDescription = 'Create beautiful, customisable QR codes for URLs, text, email, phone, vCard, Wi-Fi, and more. Free online QR code generator with logo, colours, and frame customisation.';
$bodyClass       = 'page-generator';
$pageCSS         = ['/assets/css/cuercode.css'];
$pageJS          = ['/assets/js/cuercode-generator.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🎯 QR Code Generator - Hero Section
         ==================================================================== -->
    <section class="py-4">
        <div class="container">
            <div class="text-center mb-4">
                <h1 class="h3 fw-bold">
                    <i class="fa-solid fa-qrcode me-2" style="color: var(--cuercode-primary);" aria-hidden="true"></i>QR Code Generator
                </h1>
                <p class="text-body-secondary mb-0">
                    Create beautiful, customisable QR codes for any purpose
                </p>
            </div>

            <!-- ============================================================
                 📱 Two-Column Layout (Desktop) / Stacked (Mobile)
                 @see https://getbootstrap.com/docs/5.3/layout/grid/
                 ============================================================ -->
            <div class="row g-4">

                <!-- ========================================================
                     📝 Left Column: Type + Content + Customisation
                     ======================================================== -->
                <div class="col-lg-7">

                    <!-- ====================================================
                         📱 QR Code Type Selector
                         ==================================================== -->
                    <div class="card mb-4 shadow-sm" id="qr-type-card">
                        <div class="card-header bg-transparent">
                            <h2 class="h6 mb-0">
                                <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> QR Code Type
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="qr-type-grid" id="qr-type-grid">
                                <?php foreach ($qrTypes as $type) { ?>
                                <button type="button"
                                        class="qr-type-btn<?php if ($type['typeName'] === 'url') { echo ' active'; } ?>"
                                        data-type="<?php echo htmlspecialchars($type['typeName'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-fields="<?php
                                        if (isset($type['formFieldsJSON'])) {
                                            echo htmlspecialchars($type['formFieldsJSON'], ENT_QUOTES, 'UTF-8');
                                        } else {
                                            echo htmlspecialchars('[]', ENT_QUOTES, 'UTF-8');
                                        }
                                        ?>"
                                        title="<?php
                                        if (isset($type['typeDescription'])) {
                                            echo htmlspecialchars($type['typeDescription'], ENT_QUOTES, 'UTF-8');
                                        }
                                        ?>">
                                    <i class="<?php
                                    if (isset($type['typeIcon'])) {
                                        echo htmlspecialchars($type['typeIcon'], ENT_QUOTES, 'UTF-8');
                                    } else {
                                        echo htmlspecialchars('fa-solid fa-qrcode', ENT_QUOTES, 'UTF-8');
                                    }
                                    ?>" aria-hidden="true"></i>
                                    <span><?php
                                    if (isset($type['typeLabel'])) {
                                        echo htmlspecialchars($type['typeLabel'], ENT_QUOTES, 'UTF-8');
                                    } else {
                                        echo htmlspecialchars($type['typeName'], ENT_QUOTES, 'UTF-8');
                                    }
                                    ?></span>
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                    <!-- ====================================================
                         📝 Content Form (Dynamic Fields)
                         ==================================================== -->
                    <div class="card mb-4 shadow-sm" id="qr-content-card">
                        <div class="card-header bg-transparent">
                            <h2 class="h6 mb-0">
                                <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>
                                <span id="content-header-label">Content</span>
                            </h2>
                        </div>
                        <div class="card-body">
                            <form id="qr-form" method="POST" action="/" enctype="multipart/form-data">
                                <?php if (function_exists('csrfField')) { echo csrfField(); } ?>
                                <input type="hidden" name="qr_type" id="qr-type-input" value="url">

                                <!-- 📌 Dynamic form fields container -->
                                <div id="qr-form-fields">
                                    <!-- Default: URL type fields (rendered server-side for no-JS) -->
                                    <div class="mb-3">
                                        <label for="field-url" class="form-label">Website URL</label>
                                        <input type="url"
                                               class="form-control"
                                               id="field-url"
                                               name="url"
                                               placeholder="https://example.com"
                                               maxlength="2048"
                                               required>
                                        <div class="invalid-feedback">Please enter a valid URL.</div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ====================================================
                         🎨 Customisation Tabs
                         @see https://getbootstrap.com/docs/5.3/components/navs-tabs/
                         ==================================================== -->
                    <div class="card shadow-sm" id="qr-customize-card">
                        <div class="card-header bg-transparent p-0">
                            <ul class="nav nav-tabs card-header-tabs px-2 pt-2" id="customize-tabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-colors-btn" data-bs-toggle="tab"
                                            data-bs-target="#tab-colors" type="button" role="tab"
                                            aria-controls="tab-colors" aria-selected="true"
                                            aria-label="Colors">
                                        <i class="fa-solid fa-palette me-1" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Colors</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-design-btn" data-bs-toggle="tab"
                                            data-bs-target="#tab-design" type="button" role="tab"
                                            aria-controls="tab-design" aria-selected="false"
                                            aria-label="Design">
                                        <i class="fa-solid fa-shapes me-1" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Design</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-logo-btn" data-bs-toggle="tab"
                                            data-bs-target="#tab-logo" type="button" role="tab"
                                            aria-controls="tab-logo" aria-selected="false"
                                            aria-label="Logo">
                                        <i class="fa-solid fa-image me-1" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Logo</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-frame-btn" data-bs-toggle="tab"
                                            data-bs-target="#tab-frame" type="button" role="tab"
                                            aria-controls="tab-frame" aria-selected="false"
                                            aria-label="Frame">
                                        <i class="fa-solid fa-border-all me-1" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Frame</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-options-btn" data-bs-toggle="tab"
                                            data-bs-target="#tab-options" type="button" role="tab"
                                            aria-controls="tab-options" aria-selected="false"
                                            aria-label="Options">
                                        <i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Options</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="customize-tab-content">

                                <!-- ============================================
                                     🎨 Colors Tab
                                     ============================================ -->
                                <div class="tab-pane fade show active" id="tab-colors" role="tabpanel" aria-labelledby="tab-colors-btn">
                                    <!-- Foreground Colour -->
                                    <div class="mb-3">
                                        <label for="opt-fg-color" class="form-label">
                                            <i class="fa-solid fa-fill-drip me-1" aria-hidden="true"></i> Foreground Colour
                                        </label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color"
                                                   id="opt-fg-color" name="fg_color"
                                                   value="<?php echo htmlspecialchars($defaults['fg_color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   title="Choose foreground colour">
                                            <input type="text" class="form-control color-hex-input"
                                                   id="opt-fg-color-hex" value="<?php echo htmlspecialchars($defaults['fg_color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                                                   maxlength="7" placeholder="#000000"
                                                   aria-label="Foreground colour hex value">
                                        </div>
                                    </div>

                                    <!-- Background Colour -->
                                    <div class="mb-3">
                                        <label for="opt-bg-color" class="form-label">
                                            <i class="fa-solid fa-fill me-1" aria-hidden="true"></i> Background Colour
                                        </label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color"
                                                   id="opt-bg-color" name="bg_color"
                                                   value="<?php echo htmlspecialchars($defaults['bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   title="Choose background colour">
                                            <input type="text" class="form-control color-hex-input"
                                                   id="opt-bg-color-hex" value="<?php echo htmlspecialchars($defaults['bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                                                   maxlength="7" placeholder="#ffffff"
                                                   aria-label="Background colour hex value">
                                        </div>
                                    </div>

                                    <!-- Marker (Finder Pattern) Colour -->
                                    <div class="mb-3">
                                        <label for="opt-marker-color" class="form-label">
                                            <i class="fa-solid fa-vector-square me-1" aria-hidden="true"></i> Marker Colour
                                            <small class="text-body-secondary">(optional, uses foreground if empty)</small>
                                        </label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color"
                                                   id="opt-marker-color" name="marker_color"
                                                   value="<?php echo htmlspecialchars($defaults['fg_color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   title="Choose marker colour">
                                            <input type="text" class="form-control color-hex-input"
                                                   id="opt-marker-color-hex" value=""
                                                   pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                                                   maxlength="7" placeholder="Same as foreground"
                                                   aria-label="Marker colour hex value">
                                        </div>
                                    </div>

                                    <!-- Transparent Background Toggle -->
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="opt-transparent" name="transparent_bg"
                                               <?php if ($defaults['transparent']) { echo 'checked'; } ?>>
                                        <label class="form-check-label" for="opt-transparent">
                                            Transparent Background
                                        </label>
                                    </div>
                                </div>

                                <!-- ============================================
                                     🔲 Design Tab (Patterns & Markers)
                                     ============================================ -->
                                <div class="tab-pane fade" id="tab-design" role="tabpanel" aria-labelledby="tab-design-btn">
                                    <!-- Module Pattern -->
                                    <div class="mb-4">
                                        <span class="form-label d-block fw-semibold">
                                            <i class="fa-solid fa-border-all me-1" aria-hidden="true"></i> Module Pattern
                                        </span>
                                        <div class="pattern-grid" id="pattern-grid">
                                            <button type="button" class="pattern-btn active" data-pattern="square" title="Square">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><rect x="4" y="4" width="32" height="32" rx="0" fill="currentColor"/></svg>
                                                <span>Square</span>
                                            </button>
                                            <button type="button" class="pattern-btn" data-pattern="round" title="Round">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><circle cx="20" cy="20" r="16" fill="currentColor"/></svg>
                                                <span>Round</span>
                                            </button>
                                            <button type="button" class="pattern-btn" data-pattern="dot" title="Dot">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><circle cx="20" cy="20" r="12" fill="currentColor"/></svg>
                                                <span>Dot</span>
                                            </button>
                                            <button type="button" class="pattern-btn" data-pattern="diamond" title="Diamond (SVG only)">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><polygon points="20,4 36,20 20,36 4,20" fill="currentColor"/></svg>
                                                <span>Diamond</span>
                                            </button>
                                            <button type="button" class="pattern-btn" data-pattern="star" title="Star (SVG only)">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><polygon points="20,4 24,16 36,16 26,24 30,36 20,28 10,36 14,24 4,16 16,16" fill="currentColor"/></svg>
                                                <span>Star</span>
                                            </button>
                                            <button type="button" class="pattern-btn" data-pattern="heart" title="Heart (SVG only)">
                                                <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false"><path d="M20 34 C8 22 4 16 10 10 C14 6 18 8 20 12 C22 8 26 6 30 10 C36 16 32 22 20 34Z" fill="currentColor"/></svg>
                                                <span>Heart</span>
                                            </button>
                                        </div>
                                        <small class="text-body-secondary mt-1 d-block">
                                            <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                                            Diamond, Star, and Heart patterns are available for SVG output only.
                                        </small>
                                        <input type="hidden" name="pattern" id="opt-pattern" value="<?php echo htmlspecialchars($defaults['pattern'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <!-- Marker Border Shape -->
                                    <div class="mb-4">
                                        <span class="form-label d-block fw-semibold">
                                            <i class="fa-solid fa-vector-square me-1" aria-hidden="true"></i> Marker Border
                                        </span>
                                        <div class="marker-grid" id="marker-border-grid">
                                            <button type="button" class="marker-btn active" data-marker-border="square" title="Square">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <rect x="2" y="2" width="46" height="46" rx="0" fill="none" stroke="currentColor" stroke-width="6"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-border="round" title="Rounded">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <rect x="2" y="2" width="46" height="46" rx="10" fill="none" stroke="currentColor" stroke-width="6"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-border="circle" title="Circle">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <circle cx="25" cy="25" r="22" fill="none" stroke="currentColor" stroke-width="6"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-border="extra-rounded" title="Extra Rounded">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <rect x="2" y="2" width="46" height="46" rx="18" fill="none" stroke="currentColor" stroke-width="6"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <input type="hidden" name="marker_border" id="opt-marker-border" value="<?php echo htmlspecialchars($defaults['marker_border'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <!-- Marker Centre Shape -->
                                    <div class="mb-3">
                                        <span class="form-label d-block fw-semibold">
                                            <i class="fa-solid fa-circle me-1" aria-hidden="true"></i> Marker Centre
                                        </span>
                                        <div class="marker-grid" id="marker-center-grid">
                                            <button type="button" class="marker-btn active" data-marker-center="square" title="Square">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <rect x="12" y="12" width="26" height="26" rx="0" fill="currentColor"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-center="round" title="Round">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <rect x="12" y="12" width="26" height="26" rx="6" fill="currentColor"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-center="circle" title="Circle">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <circle cx="25" cy="25" r="13" fill="currentColor"/>
                                                </svg>
                                            </button>
                                            <button type="button" class="marker-btn" data-marker-center="diamond" title="Diamond">
                                                <svg viewBox="0 0 50 50" width="40" height="40" aria-hidden="true" focusable="false">
                                                    <polygon points="25,10 40,25 25,40 10,25" fill="currentColor"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <input type="hidden" name="marker_center" id="opt-marker-center" value="<?php echo htmlspecialchars($defaults['marker_center'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>

                                <!-- ============================================
                                     🖼️ Logo Tab
                                     ============================================ -->
                                <div class="tab-pane fade" id="tab-logo" role="tabpanel" aria-labelledby="tab-logo-btn">
                                    <!-- Logo Upload -->
                                    <div class="mb-4">
                                        <label for="opt-logo-upload" class="form-label fw-semibold">
                                            <i class="fa-solid fa-upload me-1" aria-hidden="true"></i> Upload Custom Logo
                                        </label>
                                        <input type="file" class="form-control" id="opt-logo-upload"
                                               name="logo_upload" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp">
                                        <small class="text-body-secondary">
                                            PNG, JPG, GIF, SVG, or WEBP. Max <?php echo number_format($defaults['max_logo_size'] / 1048576, 0); ?>MB.
                                        </small>
                                        <!-- Logo Preview -->
                                        <div id="logo-upload-preview" class="mt-2 d-none">
                                            <img id="logo-upload-img" src="" alt="Logo preview" class="img-thumbnail" style="max-height: 80px;">
                                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="btn-remove-logo">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i> Remove
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Logo Size -->
                                    <div class="mb-4">
                                        <label for="opt-logo-size" class="form-label">
                                            Logo Size: <span id="logo-size-value"><?php echo $defaults['logo_size']; ?></span>%
                                        </label>
                                        <input type="range" class="form-range" id="opt-logo-size"
                                               name="logo_size" min="5" max="40"
                                               value="<?php echo $defaults['logo_size']; ?>">
                                    </div>

                                    <!-- Predefined Logos Separator -->
                                    <hr>
                                    <span class="form-label d-block fw-semibold mb-3">
                                        <i class="fa-solid fa-icons me-1" aria-hidden="true"></i> Or Choose a Predefined Logo
                                    </span>

                                    <!-- Predefined Logo Grid -->
                                    <div class="logo-grid" id="predefined-logo-grid">
                                        <!-- No Logo Option -->
                                        <button type="button" class="logo-btn active" data-logo="" title="No Logo">
                                            <i class="fa-solid fa-ban fa-lg text-body-secondary" aria-hidden="true"></i>
                                            <span>None</span>
                                        </button>
                                        <?php foreach ($predefinedLogos as $logo) { ?>
                                        <button type="button" class="logo-btn"
                                                data-logo="<?php echo (int) $logo['logoID']; ?>"
                                                title="<?php echo htmlspecialchars($logo['logoLabel'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <img src="/assets/img/logos/<?php echo htmlspecialchars($logo['logoFileName'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="<?php echo htmlspecialchars($logo['logoLabel'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 loading="lazy" width="32" height="32">
                                            <span><?php echo htmlspecialchars($logo['logoLabel'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                        <?php } ?>
                                    </div>
                                    <input type="hidden" name="logo" id="opt-logo" value="">
                                </div>

                                <!-- ============================================
                                     🖼️ Frame Tab
                                     ============================================ -->
                                <div class="tab-pane fade" id="tab-frame" role="tabpanel" aria-labelledby="tab-frame-btn">
                                    <!-- Frame Style -->
                                    <div class="mb-4">
                                        <span class="form-label d-block fw-semibold">
                                            <i class="fa-solid fa-border-all me-1" aria-hidden="true"></i> Frame Style
                                        </span>
                                        <div class="frame-grid" id="frame-grid">
                                            <button type="button" class="frame-btn active" data-frame="none" title="No Frame">
                                                <div class="frame-preview frame-none">
                                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                                </div>
                                                <span>None</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="simple" title="Simple Border">
                                                <div class="frame-preview frame-simple">
                                                    <div class="frame-qr-placeholder"></div>
                                                </div>
                                                <span>Simple</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="rounded" title="Rounded Border">
                                                <div class="frame-preview frame-rounded">
                                                    <div class="frame-qr-placeholder"></div>
                                                </div>
                                                <span>Rounded</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="bottom-label" title="Bottom Label">
                                                <div class="frame-preview frame-bottom-label">
                                                    <div class="frame-qr-placeholder"></div>
                                                    <div class="frame-label-bar"></div>
                                                </div>
                                                <span>Bottom Label</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="top-label" title="Top Label">
                                                <div class="frame-preview frame-top-label">
                                                    <div class="frame-label-bar"></div>
                                                    <div class="frame-qr-placeholder"></div>
                                                </div>
                                                <span>Top Label</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="banner-bottom" title="Banner Bottom">
                                                <div class="frame-preview frame-banner-bottom">
                                                    <div class="frame-qr-placeholder"></div>
                                                    <div class="frame-banner-bar"></div>
                                                </div>
                                                <span>Banner</span>
                                            </button>
                                            <button type="button" class="frame-btn" data-frame="pill" title="Pill Label">
                                                <div class="frame-preview frame-pill">
                                                    <div class="frame-qr-placeholder"></div>
                                                    <div class="frame-pill-bar"></div>
                                                </div>
                                                <span>Pill</span>
                                            </button>
                                        </div>
                                        <input type="hidden" name="frame_style" id="opt-frame-style" value="none">
                                    </div>

                                    <!-- Frame Label Text (shown when frame has label) -->
                                    <div class="mb-3" id="frame-label-group" style="display: none;">
                                        <label for="opt-frame-label" class="form-label">
                                            <i class="fa-solid fa-tag me-1" aria-hidden="true"></i> Frame Label Text
                                        </label>
                                        <input type="text" class="form-control" id="opt-frame-label"
                                               name="frame_label" placeholder="Scan Me!" maxlength="30">
                                    </div>

                                    <!-- Frame Colour (shown when frame selected) -->
                                    <div class="mb-3" id="frame-color-group" style="display: none;">
                                        <label for="opt-frame-color" class="form-label">
                                            <i class="fa-solid fa-palette me-1" aria-hidden="true"></i> Frame Colour
                                        </label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color"
                                                   id="opt-frame-color" name="frame_color" value="#2c7a7b"
                                                   title="Choose frame colour">
                                            <input type="text" class="form-control color-hex-input"
                                                   id="opt-frame-color-hex" value="#2c7a7b"
                                                   pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                                                   maxlength="7" placeholder="#2c7a7b"
                                                   aria-label="Frame colour hex value">
                                        </div>
                                    </div>
                                </div>

                                <!-- ============================================
                                     ⚙️ Options Tab (Format, Size, ECC)
                                     ============================================ -->
                                <div class="tab-pane fade" id="tab-options" role="tabpanel" aria-labelledby="tab-options-btn">
                                    <!-- Output Format -->
                                    <div class="mb-4">
                                        <label for="opt-format" class="form-label fw-semibold">
                                            <i class="fa-solid fa-file-image me-1" aria-hidden="true"></i> Output Format
                                        </label>
                                        <select class="form-select" id="opt-format" name="output_format">
                                            <option value="png" <?php if ($defaults['format'] === 'png') { echo 'selected'; } ?>>PNG - Best for web &amp; print</option>
                                            <option value="svg" <?php if ($defaults['format'] === 'svg') { echo 'selected'; } ?>>SVG - Scalable vector (best quality)</option>
                                            <option value="jpg" <?php if ($defaults['format'] === 'jpg') { echo 'selected'; } ?>>JPG - Compressed raster</option>
                                            <option value="webp" <?php if ($defaults['format'] === 'webp') { echo 'selected'; } ?>>WebP - Modern web format</option>
                                            <option value="gif" <?php if ($defaults['format'] === 'gif') { echo 'selected'; } ?>>GIF - Legacy compatibility</option>
                                            <option value="pdf" <?php if ($defaults['format'] === 'pdf') { echo 'selected'; } ?>>PDF - Print-ready document</option>
                                        </select>
                                    </div>

                                    <!-- Output Size -->
                                    <div class="mb-4">
                                        <label for="opt-size" class="form-label fw-semibold">
                                            <i class="fa-solid fa-expand me-1" aria-hidden="true"></i> Output Size:
                                            <span id="size-value"><?php echo $defaults['size']; ?></span>px
                                        </label>
                                        <input type="range" class="form-range" id="opt-size"
                                               name="output_size" min="200" max="<?php echo $defaults['max_size']; ?>"
                                               step="100" value="<?php echo $defaults['size']; ?>">
                                        <div class="d-flex justify-content-between">
                                            <small class="text-body-secondary">200px</small>
                                            <small class="text-body-secondary"><?php echo number_format($defaults['max_size']); ?>px</small>
                                        </div>
                                    </div>

                                    <!-- Error Correction Level -->
                                    <div class="mb-4">
                                        <span class="form-label d-block fw-semibold" id="ecc-level-label">
                                            <i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i> Error Correction Level
                                        </span>
                                        <div class="btn-group w-100" role="group" aria-labelledby="ecc-level-label">
                                            <input type="radio" class="btn-check" name="ecc_level" id="ecc-L" value="L" autocomplete="off"
                                                   <?php if ($defaults['ecc'] === 'L') { echo 'checked'; } ?>>
                                            <label class="btn btn-outline-secondary" for="ecc-L" title="7% recovery">L (7%)</label>

                                            <input type="radio" class="btn-check" name="ecc_level" id="ecc-M" value="M" autocomplete="off"
                                                   <?php if ($defaults['ecc'] === 'M') { echo 'checked'; } ?>>
                                            <label class="btn btn-outline-secondary" for="ecc-M" title="15% recovery">M (15%)</label>

                                            <input type="radio" class="btn-check" name="ecc_level" id="ecc-Q" value="Q" autocomplete="off"
                                                   <?php if ($defaults['ecc'] === 'Q') { echo 'checked'; } ?>>
                                            <label class="btn btn-outline-secondary" for="ecc-Q" title="25% recovery">Q (25%)</label>

                                            <input type="radio" class="btn-check" name="ecc_level" id="ecc-H" value="H" autocomplete="off"
                                                   <?php if ($defaults['ecc'] === 'H') { echo 'checked'; } ?>>
                                            <label class="btn btn-outline-secondary" for="ecc-H" title="30% recovery - Best for logos">H (30%)</label>
                                        </div>
                                        <small class="text-body-secondary mt-1 d-block">
                                            Higher levels allow more damage/obstruction. Use <strong>H</strong> when adding a logo.
                                        </small>
                                    </div>

                                    <!-- Quiet Zone -->
                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="opt-quiet-zone" name="quiet_zone" checked>
                                        <label class="form-check-label" for="opt-quiet-zone">
                                            Include Quiet Zone (recommended for scanning)
                                        </label>
                                    </div>

                                    <!-- QR Version (module grid size) — B-055 -->
                                    <div class="mb-4">
                                        <label for="opt-version" class="form-label fw-semibold">
                                            <i class="fa-solid fa-table-cells me-1" aria-hidden="true"></i> QR Version
                                        </label>
                                        <select class="form-select" id="opt-version" name="qr_version">
                                            <option value="auto" selected>Auto (fit to data)</option>
                                            <?php
                                            // 🔢 Versions 1-40 set a fixed module grid (modules = 4 * v + 17).
                                            for ($qrVersionOption = 1; $qrVersionOption <= 40; $qrVersionOption++) {
                                                echo '<option value="' . $qrVersionOption . '">'
                                                    . 'Version ' . $qrVersionOption
                                                    . ' (' . ((4 * $qrVersionOption) + 17) . '&times;'
                                                    . ((4 * $qrVersionOption) + 17) . ')'
                                                    . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <small class="text-body-secondary mt-1 d-block">
                                            Leave on <strong>Auto</strong> unless you need a fixed size. A version too small for the data will be rejected.
                                        </small>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

                <!-- ========================================================
                     👁️ Right Column: Preview + Download
                     ======================================================== -->
                <div class="col-lg-5">
                    <div class="card shadow-sm sticky-lg-top" id="preview-card" style="top: 80px;">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h2 class="h6 mb-0">
                                    <i class="fa-solid fa-eye me-1" aria-hidden="true"></i> Preview
                                </h2>
                                <span class="badge bg-secondary d-none" id="preview-status">Generating...</span>
                            </div>
                        </div>
                        <div class="card-body text-center p-4">
                            <!-- QR Preview Container -->
                            <div id="qr-preview-container" class="qr-preview-container" aria-live="polite" role="status">
                                <!-- Placeholder (shown before generation) -->
                                <div id="qr-preview-placeholder">
                                    <i class="fa-solid fa-qrcode fa-5x text-body-secondary opacity-25" aria-hidden="true"></i>
                                    <p class="mt-3 text-body-secondary mb-0">
                                        Enter content to generate<br>your QR code
                                    </p>
                                </div>

                                <!-- Loading Spinner -->
                                <div id="qr-preview-loading" class="d-none">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Generating...</span>
                                    </div>
                                    <p class="mt-2 text-body-secondary mb-0">Generating preview...</p>
                                </div>

                                <!-- Generated QR Image -->
                                <img id="qr-preview-image" src="" alt="QR Code Preview"
                                     class="img-fluid d-none" style="max-width: 300px;">

                                <!-- Error Message -->
                                <div id="qr-preview-error" class="d-none">
                                    <i class="fa-solid fa-triangle-exclamation fa-3x text-warning" aria-hidden="true"></i>
                                    <p class="mt-2 text-danger mb-0" id="qr-preview-error-text"></p>
                                </div>
                            </div>

                            <?php if ($generatedResult !== null && $generatedResult['success']) { ?>
                            <!-- Server-side generated result (no-JS fallback) -->
                            <div id="qr-server-result" class="mt-3">
                                <img src="<?php echo htmlspecialchars($generatedResult['data'], ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="Generated QR Code" class="img-fluid" style="max-width: 300px;">
                            </div>
                            <?php } elseif ($generatedResult !== null && !$generatedResult['success']) { ?>
                            <div class="alert alert-danger mt-3">
                                <i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>
                                <?php
                                if (isset($generatedResult['error'])) {
                                    echo htmlspecialchars($generatedResult['error'], ENT_QUOTES, 'UTF-8');
                                } else {
                                    echo htmlspecialchars('Generation failed.', ENT_QUOTES, 'UTF-8');
                                }
                                ?>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-footer bg-transparent">
                            <!-- Download Controls -->
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary btn-lg" id="btn-download" disabled>
                                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Download QR Code
                                </button>
                            </div>
                            <div class="text-center mt-2">
                                <small class="text-body-secondary">
                                    Format: <span id="download-format-label">PNG</span>
                                    &middot; Size: <span id="download-size-label"><?php echo htmlspecialchars($defaults['size'], ENT_QUOTES, 'UTF-8'); ?>px</span>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ====================================================================
         📦 Bulk Generate Panel (FG-003) - CSV / JSON -> ZIP download
         ====================================================================
         A simple, accessible panel for generating many QR codes at once.
         Paste a CSV (header row + data rows) or a JSON body, see a live count
         preview, then submit to download a ZIP. The endpoint is synchronous and
         anonymous-capable (POST /api/v1/bulk-generate). Without JavaScript the
         panel degrades to an explanatory note plus a documentation link.
         ==================================================================== -->
    <section class="py-4 border-top" id="bulk-section" aria-labelledby="bulk-heading">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <div class="card shadow-sm">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0" id="bulk-heading">
                                <i class="fa-solid fa-layer-group me-2" style="color: var(--cuercode-primary);" aria-hidden="true"></i>
                                Bulk generate
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="text-body-secondary">
                                Generate many QR codes at once and download them as a single ZIP file.
                                Paste a <strong>CSV</strong> (a header row with a <code>type</code> column
                                plus one or more input columns) or a <strong>JSON</strong> body with an
                                <code>items</code> array.
                            </p>

                            <!-- ⛔ No-JS fallback: explain the API directly. -->
                            <noscript>
                                <div class="alert alert-info" role="note">
                                    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                                    Bulk generation needs JavaScript in this panel. You can still use it
                                    directly by sending a <code>POST</code> request to
                                    <code>/api/v1/bulk-generate</code> with a JSON body
                                    (<code>Content-Type: application/json</code>). See the API
                                    documentation for the request format.
                                </div>
                            </noscript>

                            <!-- 📥 Input mode + file upload row -->
                            <div class="row g-3 align-items-end mb-3">
                                <div class="col-sm-4">
                                    <label for="bulk-input-mode" class="form-label">
                                        <i class="fa-solid fa-table-list me-1" aria-hidden="true"></i> Input format
                                    </label>
                                    <select class="form-select" id="bulk-input-mode" aria-describedby="bulk-input-mode-help">
                                        <option value="csv" selected>CSV (header + rows)</option>
                                        <option value="json">JSON (items array)</option>
                                    </select>
                                    <div id="bulk-input-mode-help" class="form-text">
                                        Choose how your pasted data is interpreted.
                                    </div>
                                </div>
                                <div class="col-sm-5">
                                    <label for="bulk-file" class="form-label">
                                        <i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i> Upload a file (optional)
                                    </label>
                                    <input type="file" class="form-control" id="bulk-file"
                                           accept=".csv,.json,.txt,text/csv,application/json,text/plain"
                                           aria-describedby="bulk-file-help">
                                    <div id="bulk-file-help" class="form-text">
                                        A <code>.csv</code> or <code>.json</code> file fills the box below.
                                    </div>
                                </div>
                                <div class="col-sm-3">
                                    <label for="bulk-format" class="form-label">
                                        <i class="fa-solid fa-image me-1" aria-hidden="true"></i> Output format
                                    </label>
                                    <select class="form-select" id="bulk-format" aria-label="Shared output format for all items">
                                        <option value="" selected>Per-item / default</option>
                                        <option value="png">PNG</option>
                                        <option value="jpg">JPG</option>
                                        <option value="webp">WebP</option>
                                        <option value="gif">GIF</option>
                                        <option value="svg">SVG</option>
                                        <option value="pdf">PDF</option>
                                    </select>
                                </div>
                            </div>

                            <!-- 📝 Data textarea -->
                            <div class="mb-2">
                                <label for="bulk-data" class="form-label">
                                    <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i> Bulk data
                                </label>
                                <textarea class="form-control font-monospace" id="bulk-data" rows="8"
                                          spellcheck="false"
                                          aria-describedby="bulk-data-help bulk-count"
                                          placeholder="type,input.url&#10;url,https://example.com&#10;url,https://example.org"></textarea>
                                <div id="bulk-data-help" class="form-text">
                                    CSV example: a <code>type</code> column plus <code>input.&lt;field&gt;</code>
                                    columns (e.g. <code>input.url</code>). JSON example:
                                    <code>{ "items": [ { "type": "url", "input": { "url": "https://example.com" } } ] }</code>.
                                </div>
                            </div>

                            <!-- 🔢 Count preview + status (polite live region) -->
                            <div id="bulk-count" class="small text-body-secondary mb-3" aria-live="polite" role="status">
                                No items detected yet.
                            </div>

                            <div id="bulk-error" class="alert alert-danger d-none" role="alert"></div>
                            <div id="bulk-success" class="alert alert-success d-none" role="status"></div>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" id="bulk-submit">
                                    <i class="fa-solid fa-file-zipper me-1" aria-hidden="true"></i>
                                    Generate ZIP
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="bulk-clear">
                                    <i class="fa-solid fa-eraser me-1" aria-hidden="true"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ====================================================================
         📊 QR Types Data (passed to JavaScript)
         ==================================================================== -->
    <script>
        /**
         * 📊 QR Code Types Data - Loaded from database
         * Used by cuercode-generator.js for dynamic form field rendering.
         * @see tblQRCodeTypes
         */
        var CueRCodeConfig = {
            types: <?php echo json_encode($qrTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            logos: <?php echo json_encode($predefinedLogos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            defaults: <?php echo json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            apiBase: '/api/v1',
            csrfToken: <?php
            if (function_exists('generateCSRFToken')) {
                echo json_encode(generateCSRFToken());
            } else {
                echo json_encode('');
            }
            ?>
        };
    </script>

<?php
// ==============================================================================
// 🦶 Include Footer
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
