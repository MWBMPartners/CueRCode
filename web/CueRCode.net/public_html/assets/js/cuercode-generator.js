/**
 * ==============================================================================
 * 🎯 CueRCode - QR Code Generator Page JavaScript
 * ==============================================================================
 *
 * Main interactive JavaScript for the CueRCode QR code generator page.
 * Handles dynamic form rendering, customisation grid interactions, live AJAX
 * preview, logo upload, frame controls, and download functionality.
 *
 * Dependencies:
 *   - jQuery 3.x (loaded before this file)
 *   - Bootstrap 5.3 JS bundle (loaded before this file)
 *   - CueRCode global object from cuercode-common.js (loaded before this file)
 *   - CueRCodeConfig global object set by PHP in index.php
 *
 * @package     CueRCode
 * @subpackage  JavaScript
 * @version     1.0.0
 * @since       Phase 1
 * @see         cuercode-common.js
 * @see         index.php
 */

/* global jQuery, bootstrap, CueRCode, CueRCodeConfig */

(function ($) {
    'use strict';

    // ==========================================================================
    // 📦 Module State
    // ==========================================================================
    // Private state variables used throughout the generator module.
    // ==========================================================================

    /**
     * 📌 Currently selected QR code type name (e.g. 'url', 'text', 'email')
     * @type {string}
     */
    var selectedType = 'url';

    /**
     * 📌 Base64-encoded custom logo data (set when user uploads a logo)
     * @type {string|null}
     */
    var uploadedLogoBase64 = null;

    /**
     * 📌 MIME type of the uploaded logo file
     * @type {string|null}
     */
    var uploadedLogoMimeType = null;

    /**
     * 📌 Reference to the current preview AJAX request (for aborting stale calls)
     * @type {jqXHR|null}
     */
    var currentPreviewXHR = null;

    /**
     * 📌 Flag indicating whether a valid QR code has been generated for download
     * @type {boolean}
     */
    var previewReady = false;

    /**
     * 📌 Allowed file extensions for logo uploads
     * @type {Array<string>}
     */
    var allowedLogoTypes = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/svg+xml',
        'image/webp'
    ];

    /**
     * 📌 Frame styles that support a text label
     * @type {Array<string>}
     */
    var framesWithLabel = ['bottom-label', 'top-label', 'banner-bottom', 'pill'];

    /**
     * 📌 Frame styles that support only a colour (no label)
     * @type {Array<string>}
     */
    var framesWithColorOnly = ['simple', 'rounded'];

    // ==========================================================================
    // 🔧 Helper Functions
    // ==========================================================================

    /**
     * 🔧 Collect all current dynamic form field values into a key-value object
     *
     * Iterates over every input, select, and textarea inside #qr-form-fields
     * and collects their values. Checkbox fields are collected as booleans.
     *
     * @returns {Object} An object mapping field names to their current values
     *
     * @see https://api.jquery.com/serializeArray/
     */
    function collectFormData() {
        var data = {};

        /* 📌 Process each form element inside the dynamic fields container */
        $('#qr-form-fields').find('input, select, textarea').each(function () {
            var $el = $(this);
            var name = $el.attr('name');

            /* 📌 Skip elements without a name attribute */
            if (!name) {
                return;
            }

            /* 📌 Handle checkboxes as boolean values */
            if ($el.attr('type') === 'checkbox') {
                data[name] = $el.is(':checked');
            } else {
                data[name] = $el.val();
            }
        });

        return data;
    }

    /**
     * 🔧 Collect all customisation options into a structured object
     *
     * Reads values from all colour pickers, design grids, logo settings,
     * frame settings, and output options to build the customisation payload
     * sent to the API.
     *
     * @returns {Object} The customisation options object
     */
    function collectCustomization() {
        var markerColorVal = $('#opt-marker-color-hex').val().trim();
        var markerColor;
        if (markerColorVal) {
            markerColor = markerColorVal;
        } else {
            markerColor = '';
        }

        var customization = {
            /* 🎨 Colour options */
            fg_color:     $('#opt-fg-color').val(),
            bg_color:     $('#opt-bg-color').val(),
            marker_color: markerColor,
            transparent:  $('#opt-transparent').is(':checked'),

            /* 🔲 Design options */
            pattern:       $('#opt-pattern').val(),
            marker_border: $('#opt-marker-border').val(),
            marker_center: $('#opt-marker-center').val(),

            /* 🖼️ Logo options */
            logo:         $('#opt-logo').val(),
            logo_size_pct: parseInt($('#opt-logo-size').val(), 10),

            /* 📐 Frame options */
            frame_style: $('#opt-frame-style').val(),
            frame_label: $('#opt-frame-label').val(),
            frame_color: $('#opt-frame-color').val(),

            /* ⚙️ Output options */
            format:     $('#opt-format').val(),
            size:       parseInt($('#opt-size').val(), 10),
            ecc:        $('input[name="ecc_level"]:checked').val(),
            quiet_zone: $('#opt-quiet-zone').is(':checked')
        };

        /* 🔢 QR version (B-055): only send a pinned version when the user picks
           a numeric value; 'auto' (the default) is omitted so the API/library
           auto-selects the smallest version that fits the payload. */
        var versionVal = $('#opt-version').val();
        if (versionVal && versionVal !== 'auto') {
            customization.version = parseInt(versionVal, 10);
        }

        /* 📌 Include uploaded logo base64 data if present */
        if (uploadedLogoBase64 && customization.logo === 'upload') {
            customization.logo_data = uploadedLogoBase64;
            customization.logo_mime = uploadedLogoMimeType;
        }

        return customization;
    }

    /**
     * 🔧 Render dynamic form fields from a JSON field definitions array
     *
     * Clears the #qr-form-fields container and builds Bootstrap 5 form
     * elements for each field definition. Supports text, url, email, tel,
     * textarea, number, select, password, checkbox, and datetime-local types.
     *
     * @param {Array<Object>} fields - Array of field definition objects
     *
     * @see https://getbootstrap.com/docs/5.3/forms/overview/
     */
    function renderFormFields(fields) {
        var $container = $('#qr-form-fields');
        $container.empty();

        /* 📌 Guard: if no fields provided, show a helpful message */
        if (!fields || !fields.length) {
            $container.html(
                '<p class="text-body-secondary mb-0">'
                + '<i class="fa-solid fa-circle-info me-1"></i>'
                + 'No additional input required for this QR type.'
                + '</p>'
            );
            return;
        }

        /* 📌 Iterate over each field definition and build the HTML */
        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            var fieldId = 'field-' + CueRCode.escapeHtml(field.name);
            var fieldName = CueRCode.escapeHtml(field.name);

            var fieldLabelRaw;
            if (field.label) {
                fieldLabelRaw = field.label;
            } else {
                fieldLabelRaw = field.name;
            }
            var fieldLabel = CueRCode.escapeHtml(fieldLabelRaw);

            var fieldType;
            if (field.type) {
                fieldType = field.type;
            } else {
                fieldType = 'text';
            }

            var isRequired = field.required === true;

            var placeholder;
            if (field.placeholder) {
                placeholder = field.placeholder;
            } else {
                placeholder = '';
            }

            var maxlength;
            if (field.maxlength) {
                maxlength = field.maxlength;
            } else {
                maxlength = '';
            }

            var hint;
            if (field.hint) {
                hint = field.hint;
            } else {
                hint = '';
            }

            var defaultValue;
            if (field.default) {
                defaultValue = field.default;
            } else {
                defaultValue = '';
            }

            var html = '';

            /* 📌 Build different HTML based on the field type */
            if (fieldType === 'checkbox') {
                /* ☑️ Checkbox: rendered as a Bootstrap switch */
                html += '<div class="mb-3">';
                html += '<div class="form-check form-switch">';
                html += '<input class="form-check-input qr-field" type="checkbox" role="switch"';
                html += ' id="' + fieldId + '" name="' + fieldName + '"';
                if (defaultValue === true || defaultValue === 'true' || defaultValue === '1') {
                    html += ' checked';
                }
                html += '>';
                html += '<label class="form-check-label" for="' + fieldId + '">' + fieldLabel + '</label>';
                html += '</div>';
                if (hint) {
                    html += '<div class="form-text">' + CueRCode.escapeHtml(hint) + '</div>';
                }
                html += '</div>';

            } else if (fieldType === 'textarea') {
                /* 📝 Textarea */
                var rows;
                if (field.rows) {
                    rows = field.rows;
                } else {
                    rows = 4;
                }
                html += '<div class="mb-3">';
                html += '<label for="' + fieldId + '" class="form-label">' + fieldLabel + '</label>';
                html += '<textarea class="form-control qr-field" id="' + fieldId + '"';
                html += ' name="' + fieldName + '" rows="' + rows + '"';
                if (placeholder) {
                    html += ' placeholder="' + CueRCode.escapeHtml(placeholder) + '"';
                }
                if (maxlength) {
                    html += ' maxlength="' + parseInt(maxlength, 10) + '"';
                }
                if (isRequired) {
                    html += ' required';
                }
                html += '>' + CueRCode.escapeHtml(String(defaultValue)) + '</textarea>';
                html += '<div class="invalid-feedback">Please fill in this field.</div>';
                if (hint) {
                    html += '<div class="form-text">' + CueRCode.escapeHtml(hint) + '</div>';
                }
                html += '</div>';

            } else if (fieldType === 'select') {
                /* 📋 Select dropdown */
                var options;
                if (field.options) {
                    options = field.options;
                } else {
                    options = [];
                }
                html += '<div class="mb-3">';
                html += '<label for="' + fieldId + '" class="form-label">' + fieldLabel + '</label>';
                html += '<select class="form-select qr-field" id="' + fieldId + '"';
                html += ' name="' + fieldName + '"';
                if (isRequired) {
                    html += ' required';
                }
                html += '>';
                for (var j = 0; j < options.length; j++) {
                    var optVal = options[j];
                    var optLabel = optVal;

                    /* 📌 Support options as objects with value/label or plain strings */
                    if (typeof optVal === 'object' && optVal !== null) {
                        var optLabelRaw;
                        if (optVal.label) {
                            optLabelRaw = optVal.label;
                        } else {
                            optLabelRaw = optVal.value;
                        }
                        optLabel = CueRCode.escapeHtml(optLabelRaw);
                        optVal = optVal.value;
                    }
                    var isSelected;
                    if (String(optVal) === String(defaultValue)) {
                        isSelected = ' selected';
                    } else {
                        isSelected = '';
                    }
                    html += '<option value="' + CueRCode.escapeHtml(String(optVal)) + '"' + isSelected + '>';
                    html += CueRCode.escapeHtml(String(optLabel));
                    html += '</option>';
                }
                html += '</select>';
                html += '<div class="invalid-feedback">Please select an option.</div>';
                if (hint) {
                    html += '<div class="form-text">' + CueRCode.escapeHtml(hint) + '</div>';
                }
                html += '</div>';

            } else {
                /* 📝 Standard input: text, url, email, tel, password, number, datetime-local */
                html += '<div class="mb-3">';
                html += '<label for="' + fieldId + '" class="form-label">' + fieldLabel + '</label>';
                html += '<input type="' + CueRCode.escapeHtml(fieldType) + '"';
                html += ' class="form-control qr-field" id="' + fieldId + '"';
                html += ' name="' + fieldName + '"';
                if (placeholder) {
                    html += ' placeholder="' + CueRCode.escapeHtml(placeholder) + '"';
                }
                if (maxlength) {
                    html += ' maxlength="' + parseInt(maxlength, 10) + '"';
                }
                if (isRequired) {
                    html += ' required';
                }
                if (defaultValue !== '') {
                    html += ' value="' + CueRCode.escapeHtml(String(defaultValue)) + '"';
                }

                /* 📌 Add autocomplete attribute for recognised input types */
                if (fieldType === 'email') {
                    html += ' autocomplete="email"';
                } else if (fieldType === 'tel') {
                    html += ' autocomplete="tel"';
                } else if (fieldType === 'url') {
                    html += ' autocomplete="url"';
                }

                /* 📌 Add number-specific attributes if present */
                if (fieldType === 'number') {
                    if (typeof field.min !== 'undefined' && field.min !== '') {
                        html += ' min="' + CueRCode.escapeHtml(String(field.min)) + '"';
                    }
                    if (typeof field.max !== 'undefined' && field.max !== '') {
                        html += ' max="' + CueRCode.escapeHtml(String(field.max)) + '"';
                    }
                    if (typeof field.step !== 'undefined' && field.step !== '') {
                        html += ' step="' + CueRCode.escapeHtml(String(field.step)) + '"';
                    }
                }

                html += '>';

                /* 📌 Add appropriate validation feedback message */
                var feedbackMsg = 'Please fill in this field.';
                if (field.validation === 'url' || fieldType === 'url') {
                    feedbackMsg = 'Please enter a valid URL (e.g. https://example.com).';
                } else if (field.validation === 'email' || fieldType === 'email') {
                    feedbackMsg = 'Please enter a valid email address.';
                } else if (field.validation === 'phone' || fieldType === 'tel') {
                    feedbackMsg = 'Please enter a valid phone number.';
                }
                html += '<div class="invalid-feedback">' + CueRCode.escapeHtml(feedbackMsg) + '</div>';

                if (hint) {
                    html += '<div class="form-text">' + CueRCode.escapeHtml(hint) + '</div>';
                }
                html += '</div>';
            }

            /* 📌 Append the constructed HTML to the fields container */
            $container.append(html);
        }
    }

    /**
     * 🔧 Trigger a file download from data
     *
     * Creates a temporary anchor element with a download attribute to prompt
     * the browser to save the file. Supports both base64 data URIs and
     * raw blob data for SVG downloads.
     *
     * @param {string} data     - The file data (base64 data URI or raw content)
     * @param {string} filename - The suggested download filename
     * @param {string} mimeType - The MIME type of the file
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLAnchorElement/download
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Blob
     */
    function downloadFile(data, filename, mimeType) {
        var link = document.createElement('a');
        link.style.display = 'none';

        /* 📌 Handle SVG as a blob download */
        if (mimeType === 'image/svg+xml' && data.indexOf('data:') !== 0) {
            var blob = new Blob([data], { type: mimeType });
            var blobUrl = URL.createObjectURL(blob);
            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();

            /* 📌 Clean up the blob URL after a short delay */
            setTimeout(function () {
                URL.revokeObjectURL(blobUrl);
                document.body.removeChild(link);
            }, 100);
            return;
        }

        /* 📌 Handle base64 data URI download */
        if (data.indexOf('data:') === 0) {
            link.href = data;
        } else {
            /* 📌 Construct data URI from raw base64 */
            link.href = 'data:' + mimeType + ';base64,' + data;
        }

        link.download = filename;
        document.body.appendChild(link);
        link.click();

        /* 📌 Clean up the temporary anchor element */
        setTimeout(function () {
            document.body.removeChild(link);
        }, 100);
    }

    /**
     * 🔧 Get the file extension string for a given format value
     *
     * Maps the output format select value to the appropriate file extension.
     *
     * @param {string} format - The format value (png, svg, jpg, webp, gif, pdf)
     * @returns {string} The file extension including the dot (e.g. '.png')
     */
    function getFileExtension(format) {
        var extMap = {
            'png':  '.png',
            'svg':  '.svg',
            'jpg':  '.jpg',
            'jpeg': '.jpg',
            'webp': '.webp',
            'gif':  '.gif',
            'pdf':  '.pdf'
        };
        if (extMap[format]) {
            return extMap[format];
        } else {
            return '.png';
        }
    }

    /**
     * 🔧 Get the MIME type string for a given format value
     *
     * @param {string} format - The format value (png, svg, jpg, webp, gif, pdf)
     * @returns {string} The MIME type string
     */
    function getMimeType(format) {
        var mimeMap = {
            'png':  'image/png',
            'svg':  'image/svg+xml',
            'jpg':  'image/jpeg',
            'jpeg': 'image/jpeg',
            'webp': 'image/webp',
            'gif':  'image/gif',
            'pdf':  'application/pdf'
        };
        if (mimeMap[format]) {
            return mimeMap[format];
        } else {
            return 'image/png';
        }
    }

    /**
     * 🔧 Validate the content form fields before making a preview/generate call
     *
     * Checks that at least one content field has a non-empty value. Uses
     * native HTML5 constraint validation for fields that have validation
     * attributes (required, type=url, type=email, etc.).
     *
     * @returns {boolean} True if the form content is valid enough for a preview
     */
    function validateFormContent() {
        var hasContent = false;
        var $fields = $('#qr-form-fields').find('input, select, textarea');

        $fields.each(function () {
            var $el = $(this);
            var val = $el.val();

            /* 📌 Check if any field has a non-empty value */
            if ($el.attr('type') === 'checkbox') {
                hasContent = true; /* checkboxes always contribute */
            } else if (val && val.trim().length > 0) {
                hasContent = true;
            }
        });

        return hasContent;
    }

    // ==========================================================================
    // 👁️ Live AJAX Preview
    // ==========================================================================

    /**
     * 👁️ Execute the live preview AJAX call
     *
     * Sends the current form data and customisation options to the preview
     * API endpoint. Manages the loading/success/error states of the preview
     * panel. Aborts any in-flight preview request before starting a new one.
     *
     * @see CueRCode.ajax()
     * @see https://api.jquery.com/jQuery.ajax/
     */
    function executePreview() {
        /* 📌 Require at least some content before requesting a preview */
        if (!validateFormContent()) {
            showPreviewPlaceholder();
            return;
        }

        /* 📌 Abort any in-flight preview request to avoid race conditions */
        if (currentPreviewXHR && typeof currentPreviewXHR.abort === 'function') {
            currentPreviewXHR.abort();
            currentPreviewXHR = null;
        }

        /* 📌 Show loading state */
        showPreviewLoading();

        /* 📌 Build the request payload */
        var payload = {
            type:          selectedType,
            input:         collectFormData(),
            customization: collectCustomization()
        };

        /* 📌 Make the preview AJAX call */
        currentPreviewXHR = CueRCode.ajax({
            url: CueRCodeConfig.apiBase + '/preview',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            beforeSend: function () {
                /* 📌 Update status badge to show generating state */
                $('#preview-status').removeClass('d-none bg-success bg-danger bg-secondary')
                    .addClass('bg-secondary')
                    .text('Generating...');
            },
            success: function (response) {
                currentPreviewXHR = null;

                if (response.success && response.data) {
                    /* 📌 Show the generated preview image */
                    showPreviewImage(response.data);
                    previewReady = true;

                    /* 📌 Enable the download button */
                    $('#btn-download').prop('disabled', false);

                    /* 📌 Update status badge to success */
                    $('#preview-status').removeClass('bg-secondary bg-danger')
                        .addClass('bg-success')
                        .text('Ready');

                    /* 📌 Hide status badge after a short delay */
                    setTimeout(function () {
                        $('#preview-status').addClass('d-none');
                    }, 2000);
                } else {
                    /* 📌 API returned an error in the response */
                    var errorMsg;
                    if (response && response.error) {
                        errorMsg = response.error;
                    } else {
                        errorMsg = 'Failed to generate preview.';
                    }
                    showPreviewError(errorMsg);
                    previewReady = false;
                    $('#btn-download').prop('disabled', true);
                }
            },
            error: function (xhr, status) {
                currentPreviewXHR = null;

                /* 📌 Ignore aborted requests (they are intentional) */
                if (status === 'abort') {
                    return;
                }

                /* 📌 Show error state in preview panel */
                var errorMsg = 'Failed to generate preview. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                showPreviewError(errorMsg);
                previewReady = false;
                $('#btn-download').prop('disabled', true);

                /* 📌 Update status badge to error */
                $('#preview-status').removeClass('d-none bg-secondary bg-success')
                    .addClass('bg-danger')
                    .text('Error');
            }
        });
    }

    /**
     * 👁️ Debounced preview trigger (500ms delay)
     *
     * Wraps executePreview with CueRCode.debounce() so that rapid changes
     * (e.g. typing in a text field) only fire one preview request after
     * the user stops making changes for 500ms.
     *
     * @type {Function}
     * @see CueRCode.debounce()
     */
    var triggerPreview = CueRCode.debounce(function () {
        executePreview();
    }, 500);

    // ==========================================================================
    // 👁️ Preview Panel State Management
    // ==========================================================================

    /**
     * 👁️ Show the placeholder state (no QR generated yet)
     *
     * Hides the loading spinner, image, and error, then shows the
     * placeholder icon and message.
     */
    function showPreviewPlaceholder() {
        $('#qr-preview-placeholder').removeClass('d-none');
        $('#qr-preview-loading').addClass('d-none');
        $('#qr-preview-image').addClass('d-none');
        $('#qr-preview-error').addClass('d-none');
        $('#preview-status').addClass('d-none');
        $('#btn-download').prop('disabled', true);
        previewReady = false;
    }

    /**
     * 👁️ Show the loading spinner state
     *
     * Hides the placeholder, image, and error, then shows the
     * loading spinner animation.
     */
    function showPreviewLoading() {
        $('#qr-preview-placeholder').addClass('d-none');
        $('#qr-preview-loading').removeClass('d-none');
        $('#qr-preview-image').addClass('d-none');
        $('#qr-preview-error').addClass('d-none');
        $('#preview-status').removeClass('d-none');
    }

    /**
     * 👁️ Show the generated QR code preview image
     *
     * Hides the placeholder, loading spinner, and error, then displays
     * the generated QR code image using the provided data URI or URL.
     *
     * @param {string} imageSrc - The image source (data URI or URL)
     */
    function showPreviewImage(imageSrc) {
        $('#qr-preview-placeholder').addClass('d-none');
        $('#qr-preview-loading').addClass('d-none');
        $('#qr-preview-error').addClass('d-none');
        $('#qr-preview-image')
            .attr('src', imageSrc)
            .attr('alt', 'Generated QR code preview')
            .removeClass('d-none');
    }

    /**
     * 👁️ Show an error message in the preview panel
     *
     * Hides the placeholder, loading spinner, and image, then displays
     * the error icon and message text.
     *
     * @param {string} message - The error message to display
     */
    function showPreviewError(message) {
        $('#qr-preview-placeholder').addClass('d-none');
        $('#qr-preview-loading').addClass('d-none');
        $('#qr-preview-image').addClass('d-none');
        $('#qr-preview-error').removeClass('d-none').attr('role', 'alert');
        $('#qr-preview-error-text').text(message);

        /* 📌 Update status badge to error state */
        $('#preview-status').removeClass('d-none bg-secondary bg-success')
            .addClass('bg-danger')
            .text('Error');
    }

    // ==========================================================================
    // 📱 QR Type Switching
    // ==========================================================================

    /**
     * 📱 Handle QR code type button click
     *
     * Updates the active state on the type selector grid, parses the
     * field definitions from the button's data-fields attribute, renders
     * the dynamic form fields, and triggers a preview update.
     *
     * @param {jQuery.Event} e - The click event
     *
     * @see renderFormFields()
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON/parse
     */
    function handleTypeChange(e) {
        var $btn = $(e.currentTarget);

        /* 📌 Update active state and aria-pressed on all type buttons */
        $('#qr-type-grid .qr-type-btn').removeClass('active').attr('aria-pressed', 'false');
        $btn.addClass('active').attr('aria-pressed', 'true');

        /* 📌 Update the hidden input and module state */
        selectedType = $btn.data('type');
        $('#qr-type-input').val(selectedType);

        /* 📌 Parse field definitions from the data-fields attribute */
        var fieldsJSON;
        if ($btn.attr('data-fields')) {
            fieldsJSON = $btn.attr('data-fields');
        } else {
            fieldsJSON = '[]';
        }
        var fields = [];
        try {
            fields = JSON.parse(fieldsJSON);
        } catch (parseError) {
            /* 📌 Log parse error but continue with empty fields */
            if (typeof console !== 'undefined' && console.error) {
                console.error('CueRCode: Failed to parse form fields JSON:', parseError);
            }
            fields = [];
        }

        /* 📌 Render the new form fields */
        renderFormFields(fields);

        /* 📌 Update the content card header label */
        var typeLabel;
        if ($btn.find('span').text()) {
            typeLabel = $btn.find('span').text();
        } else {
            typeLabel = selectedType;
        }
        $('#content-header-label').text(typeLabel + ' Content');

        /* 📌 Trigger preview update for the new type */
        triggerPreview();
    }

    // ==========================================================================
    // 🎨 Colour Synchronisation
    // ==========================================================================

    /**
     * 🎨 Synchronise a colour picker with its paired hex text input
     *
     * When the colour picker value changes, updates the hex text input.
     * When the hex text input changes, validates and updates the colour picker.
     * Also triggers a preview update on any colour change.
     *
     * @param {string} pickerId - The colour picker element ID (with #)
     * @param {string} hexId    - The hex text input element ID (with #)
     */
    function setupColourSync(pickerId, hexId) {
        /* 📌 Colour picker -> hex text input */
        $(pickerId).on('input change', function () {
            $(hexId).val($(this).val());
            triggerPreview();
        });

        /* 📌 Hex text input -> colour picker */
        $(hexId).on('change', function () {
            var hex = $(this).val().trim();

            /* 📌 Add # prefix if missing */
            if (hex.length > 0 && hex.charAt(0) !== '#') {
                hex = '#' + hex;
                $(this).val(hex);
            }

            /* 📌 Only update the picker if the hex is valid */
            if (CueRCode.isValidHexColor(hex)) {
                $(pickerId).val(hex);
                triggerPreview();
            }
        });
    }

    // ==========================================================================
    // 🔲 Design Grid Interactions
    // ==========================================================================

    /**
     * 🔲 Set up a selection grid (pattern, marker, logo, or frame)
     *
     * Binds click handlers to all buttons within the grid container.
     * When a button is clicked, removes .active from siblings, adds
     * .active to the clicked button, updates the hidden input, and
     * triggers a preview update.
     *
     * @param {string} gridSelector   - CSS selector for the grid container
     * @param {string} btnSelector    - CSS selector for buttons within the grid
     * @param {string} dataAttr       - The data attribute name (e.g. 'pattern', 'marker-border')
     * @param {string} hiddenInputId  - The hidden input element ID (with #)
     * @param {Function} [callback]   - Optional callback after selection changes
     */
    function setupSelectionGrid(gridSelector, btnSelector, dataAttr, hiddenInputId, callback) {
        $(gridSelector).on('click', btnSelector, function () {
            var $btn = $(this);

            /* 📌 Update active state and aria-pressed */
            $(gridSelector).find(btnSelector).removeClass('active').attr('aria-pressed', 'false');
            $btn.addClass('active').attr('aria-pressed', 'true');

            /* 📌 Update the corresponding hidden input */
            var value = $btn.data(dataAttr);
            var inputVal;
            if (value !== undefined) {
                inputVal = value;
            } else {
                inputVal = '';
            }
            $(hiddenInputId).val(inputVal);

            /* 📌 Execute optional callback */
            if (typeof callback === 'function') {
                callback(value, $btn);
            }

            /* 📌 Trigger preview update */
            triggerPreview();
        });
    }

    // ==========================================================================
    // 📐 Frame Controls Visibility
    // ==========================================================================

    /**
     * 📐 Update frame label and colour group visibility based on selected style
     *
     * - "none": hide both label and colour groups
     * - "simple" or "rounded": show colour group only, hide label group
     * - Frames with labels (bottom-label, top-label, banner-bottom, pill):
     *   show both label and colour groups
     *
     * @param {string} frameStyle - The selected frame style value
     */
    function updateFrameControls(frameStyle) {
        var $labelGroup = $('#frame-label-group');
        var $colorGroup = $('#frame-color-group');

        if (frameStyle === 'none') {
            /* 📌 No frame: hide all frame-specific controls */
            $labelGroup.slideUp(200);
            $colorGroup.slideUp(200);
        } else if (framesWithColorOnly.indexOf(frameStyle) !== -1) {
            /* 📌 Simple/Rounded: show colour only */
            $labelGroup.slideUp(200);
            $colorGroup.slideDown(200);
        } else if (framesWithLabel.indexOf(frameStyle) !== -1) {
            /* 📌 Label frames: show both label and colour */
            $labelGroup.slideDown(200);
            $colorGroup.slideDown(200);
        } else {
            /* 📌 Fallback: show colour only for unknown frame types */
            $labelGroup.slideUp(200);
            $colorGroup.slideDown(200);
        }
    }

    // ==========================================================================
    // 🖼️ Logo Upload Handling
    // ==========================================================================

    /**
     * 🖼️ Handle custom logo file upload
     *
     * Validates the selected file type and size, reads it as a base64
     * data URI using FileReader, displays a preview, and stores the
     * base64 data for inclusion in API requests.
     *
     * @param {jQuery.Event} e - The change event from the file input
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/FileReader
     * @see https://developer.mozilla.org/en-US/docs/Web/API/FileReader/readAsDataURL
     */
    function handleLogoUpload(e) {
        var fileInput = e.target;
        var file;
        if (fileInput.files) {
            file = fileInput.files[0];
        } else {
            file = null;
        }

        /* 📌 No file selected - do nothing */
        if (!file) {
            return;
        }

        /* 📌 Validate file type */
        if (allowedLogoTypes.indexOf(file.type) === -1) {
            CueRCode.notify(
                'Invalid file type. Please upload a PNG, JPG, GIF, SVG, or WEBP image.',
                'danger'
            );
            /* 📌 Reset the file input */
            $(fileInput).val('');
            return;
        }

        /* 📌 Validate file size */
        var maxSize;
        if (CueRCodeConfig.defaults.max_logo_size) {
            maxSize = CueRCodeConfig.defaults.max_logo_size;
        } else {
            maxSize = 5242880;
        }
        if (file.size > maxSize) {
            CueRCode.notify(
                'File too large. Maximum size is ' + CueRCode.formatFileSize(maxSize) + '.',
                'danger'
            );
            $(fileInput).val('');
            return;
        }

        /* 📌 Read file as data URI using FileReader */
        /* @see https://developer.mozilla.org/en-US/docs/Web/API/FileReader/readAsDataURL */
        var reader = new FileReader();

        reader.onload = function (readerEvent) {
            var dataUrl = readerEvent.target.result;

            /* 📌 Store the base64 data and MIME type */
            uploadedLogoBase64 = dataUrl;
            uploadedLogoMimeType = file.type;

            /* 📌 Show the upload preview with descriptive alt text */
            $('#logo-upload-img').attr('src', dataUrl).attr('alt', 'Preview of uploaded logo: ' + file.name);
            $('#logo-upload-preview').removeClass('d-none');

            /* 📌 Deselect any predefined logo */
            $('#predefined-logo-grid .logo-btn').removeClass('active');
            /* 📌 Re-activate "None" button is not appropriate here - we have an upload */

            /* 📌 Set the hidden logo input to indicate an uploaded logo */
            $('#opt-logo').val('upload');

            /* 📌 Trigger preview update with the new logo */
            triggerPreview();
        };

        reader.onerror = function () {
            CueRCode.notify('Failed to read the logo file. Please try again.', 'danger');
        };

        reader.readAsDataURL(file);
    }

    /**
     * 🖼️ Remove the uploaded custom logo
     *
     * Clears the file input, hides the upload preview, resets the logo
     * hidden input to empty, clears stored base64 data, and re-selects
     * the "None" predefined logo button.
     */
    function removeUploadedLogo() {
        /* 📌 Clear the file input */
        $('#opt-logo-upload').val('');

        /* 📌 Hide the upload preview */
        $('#logo-upload-preview').addClass('d-none');
        $('#logo-upload-img').attr('src', '');

        /* 📌 Clear stored logo data */
        uploadedLogoBase64 = null;
        uploadedLogoMimeType = null;

        /* 📌 Reset logo selection to "None" */
        $('#opt-logo').val('');
        $('#predefined-logo-grid .logo-btn').removeClass('active');
        $('#predefined-logo-grid .logo-btn[data-logo=""]').addClass('active');

        /* 📌 Trigger preview update */
        triggerPreview();
    }

    /**
     * 🖼️ Handle predefined logo selection
     *
     * When a predefined logo button is clicked, clears any uploaded logo,
     * updates the hidden input with the selected logo ID, and triggers
     * a preview update.
     *
     * @param {string} logoValue - The selected logo ID (or empty for "None")
     * @param {jQuery}  $btn     - The clicked button element
     */
    function handlePredefinedLogoSelect(logoValue, $btn) {
        /* 📌 Clear any uploaded logo when selecting a predefined one */
        if (uploadedLogoBase64) {
            $('#opt-logo-upload').val('');
            $('#logo-upload-preview').addClass('d-none');
            $('#logo-upload-img').attr('src', '');
            uploadedLogoBase64 = null;
            uploadedLogoMimeType = null;
        }

        /* 📌 The hidden input and active state are handled by setupSelectionGrid */
        /* No additional action needed here beyond clearing the upload */
    }

    // ==========================================================================
    // 📥 Download Functionality
    // ==========================================================================

    /**
     * 📥 Handle download button click
     *
     * Sends the current form data and customisation options to the generate
     * API endpoint to produce the full-quality QR code for download. Uses
     * the selected output format and size rather than the preview resolution.
     *
     * @see CueRCode.ajax()
     * @see downloadFile()
     */
    function handleDownload() {
        /* 📌 Require a valid preview before allowing download */
        if (!previewReady) {
            CueRCode.notify('Please generate a preview first.', 'warning');
            return;
        }

        /* 📌 Disable the button to prevent double-clicks */
        var $btn = $('#btn-download');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html(
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
            + 'Generating...'
        );

        /* 📌 Build the generate request payload */
        var payload = {
            type:          selectedType,
            input:         collectFormData(),
            customization: collectCustomization()
        };

        var format = $('#opt-format').val();

        /* 📌 Make the generate AJAX call */
        CueRCode.ajax({
            url: CueRCodeConfig.apiBase + '/generate',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (response) {
                if (response.success && response.data) {
                    /* 📌 Build the download filename */
                    var filename = 'cuercode-' + selectedType + '-' + Date.now() + getFileExtension(format);
                    var mimeType = getMimeType(format);

                    /* 📌 Trigger the file download */
                    downloadFile(response.data, filename, mimeType);

                    CueRCode.notify('QR code downloaded successfully!', 'success');
                } else {
                    /* 📌 API returned an error */
                    var errorMsg;
                    if (response && response.error) {
                        errorMsg = response.error;
                    } else {
                        errorMsg = 'Failed to generate QR code.';
                    }
                    CueRCode.notify(errorMsg, 'danger');
                }
            },
            error: function (xhr, status) {
                /* 📌 Ignore aborted requests */
                if (status === 'abort') {
                    return;
                }

                var errorMsg = 'Failed to generate QR code. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                CueRCode.notify(errorMsg, 'danger');
            },
            complete: function () {
                /* 📌 Restore the download button state */
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    }

    // ==========================================================================
    // 📏 Range Slider Updates
    // ==========================================================================

    /**
     * 📏 Handle output size slider changes
     *
     * Updates the size display label and the download info label
     * when the user adjusts the output size slider.
     */
    function handleSizeChange() {
        var size = $('#opt-size').val();
        $('#size-value').text(size);
        $('#download-size-label').text(size + 'px');
        triggerPreview();
    }

    /**
     * 📏 Handle logo size slider changes
     *
     * Updates the logo size percentage display label.
     */
    function handleLogoSizeChange() {
        var logoSize = $('#opt-logo-size').val();
        $('#logo-size-value').text(logoSize);
        triggerPreview();
    }

    /**
     * 📏 Handle output format dropdown changes
     *
     * Updates the download format label display.
     */
    function handleFormatChange() {
        var format = $('#opt-format').val();
        var formatLabel = $('#opt-format option:selected').text().split(' -')[0].trim();
        var formatDisplay;
        if (formatLabel) {
            formatDisplay = formatLabel;
        } else {
            formatDisplay = format.toUpperCase();
        }
        $('#download-format-label').text(formatDisplay);
        triggerPreview();
    }

    // ==========================================================================
    // 🚀 Initialisation
    // ==========================================================================

    /**
     * 🚀 Initialise all generator functionality when DOM is ready
     *
     * Sets up event handlers for type selection, form fields, colour pickers,
     * design grids, logo upload, frame controls, range sliders, and the
     * download button. Also initialises the default type's form fields.
     *
     * @see https://api.jquery.com/ready/
     */
    // ==========================================================================
    // 📦 Bulk Generation Panel (FG-003)
    // ==========================================================================

    /**
     * 📦 Count the items implied by the current bulk textarea contents.
     *
     * For CSV mode this is the number of non-blank lines minus the header row.
     * For JSON mode it is the length of the parsed `items` array. Returns -1
     * when the input cannot be interpreted (so callers can show a hint without
     * claiming a misleading count).
     *
     * @param {string} mode - 'csv' or 'json'
     * @param {string} text - The raw textarea contents
     * @returns {number} The item count, or -1 if indeterminate
     */
    function bulkCountItems(mode, text) {
        var trimmed = (text || '').trim();
        if (trimmed === '') {
            return 0;
        }

        if (mode === 'json') {
            try {
                var parsed = JSON.parse(trimmed);
                if (parsed && Array.isArray(parsed.items)) {
                    return parsed.items.length;
                }
                return -1;
            } catch (e) {
                return -1;
            }
        }

        /* 📌 CSV mode: count non-blank lines, minus the header row. */
        var lines = trimmed.split(/\r\n|\r|\n/);
        var nonBlank = 0;
        for (var i = 0; i < lines.length; i++) {
            if (lines[i].trim() !== '') {
                nonBlank++;
            }
        }
        return Math.max(0, nonBlank - 1);
    }

    /**
     * 🔢 Refresh the live "items detected" count preview for the bulk panel.
     */
    function updateBulkCount() {
        var mode = $('#bulk-input-mode').val();
        var text = $('#bulk-data').val();
        var count = bulkCountItems(mode, text);
        var $count = $('#bulk-count');

        if (count === -1) {
            $count.text('Could not read the data — check it matches the selected format.');
            return;
        }
        if (count === 0) {
            $count.text('No items detected yet.');
            return;
        }
        if (count === 1) {
            $count.text('1 item detected.');
            return;
        }
        $count.text(count + ' items detected.');
    }

    /**
     * 📦 Build the JSON request body for the bulk endpoint from the panel.
     *
     * @returns {Object|null} The request body object, or null on a JSON parse
     *                        error (an error message is shown to the user).
     */
    function buildBulkPayload() {
        var mode = $('#bulk-input-mode').val();
        var text = $('#bulk-data').val();
        var sharedFormat = $('#bulk-format').val();

        var body = {};

        if (mode === 'json') {
            try {
                var parsed = JSON.parse((text || '').trim());
                if (parsed && typeof parsed === 'object') {
                    body = parsed;
                } else {
                    showBulkError('JSON body must be an object with an "items" array.');
                    return null;
                }
            } catch (e) {
                showBulkError('Invalid JSON: ' + e.message);
                return null;
            }
        } else {
            body.csv = text;
        }

        /* 📌 Apply the shared output format (when chosen) without clobbering an
              existing customization object the user may have supplied in JSON. */
        if (sharedFormat) {
            if (!body.customization || typeof body.customization !== 'object') {
                body.customization = {};
            }
            body.customization.format = sharedFormat;
        }

        return body;
    }

    /**
     * ❌ Show a bulk-panel error message (and hide any success message).
     *
     * @param {string} message - The error text to display
     */
    function showBulkError(message) {
        $('#bulk-success').addClass('d-none').text('');
        $('#bulk-error').removeClass('d-none').text(message);
    }

    /**
     * ✅ Show a bulk-panel success message (and hide any error message).
     *
     * @param {string} message - The success text to display
     */
    function showBulkSuccess(message) {
        $('#bulk-error').addClass('d-none').text('');
        $('#bulk-success').removeClass('d-none').text(message);
    }

    /**
     * 📦 Submit the bulk request and trigger a ZIP download.
     *
     * Uses the native fetch() API because the response is a binary ZIP blob
     * (jQuery's JSON dataType cannot handle it). The CSRF token is sent in the
     * X-CSRF-Token header so session-based (web UI) requests authenticate, even
     * though the endpoint is anonymous-capable. On a non-OK response the JSON
     * error body is parsed and surfaced.
     */
    function submitBulk() {
        var payload = buildBulkPayload();
        if (payload === null) {
            return;
        }

        var count = bulkCountItems($('#bulk-input-mode').val(), $('#bulk-data').val());
        if (count === 0) {
            showBulkError('Enter at least one item before generating.');
            return;
        }

        var $btn = $('#bulk-submit');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html(
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Generating...'
        );
        $('#bulk-error').addClass('d-none').text('');
        $('#bulk-success').addClass('d-none').text('');

        var headers = {
            'Content-Type': 'application/json'
        };
        if (CueRCode && typeof CueRCode.getCSRFToken === 'function') {
            headers['X-CSRF-Token'] = CueRCode.getCSRFToken();
        }

        fetch(CueRCodeConfig.apiBase + '/bulk-generate', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload)
        }).then(function (response) {
            var contentType = response.headers.get('Content-Type') || '';

            if (response.ok && contentType.indexOf('application/zip') === 0) {
                /* 📌 Success: download the ZIP blob. */
                var succeeded = response.headers.get('X-Bulk-Succeeded');
                var failed = response.headers.get('X-Bulk-Failed');
                return response.blob().then(function (blob) {
                    var blobUrl = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = blobUrl;
                    link.download = 'cuercode-bulk.zip';
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    setTimeout(function () {
                        URL.revokeObjectURL(blobUrl);
                        document.body.removeChild(link);
                    }, 100);

                    var msg = 'ZIP downloaded.';
                    if (succeeded !== null) {
                        msg = 'ZIP downloaded — ' + succeeded + ' generated';
                        if (failed !== null && parseInt(failed, 10) > 0) {
                            msg += ', ' + failed + ' failed (see manifest.txt inside the ZIP)';
                        }
                        msg += '.';
                    }
                    showBulkSuccess(msg);
                });
            }

            /* 📌 Error: try to parse a JSON error body for a useful message. */
            return response.json().then(function (data) {
                var message = 'Bulk generation failed (HTTP ' + response.status + ').';
                if (data && data.message) {
                    message = data.message;
                }
                showBulkError(message);
            }).catch(function () {
                showBulkError('Bulk generation failed (HTTP ' + response.status + ').');
            });
        }).catch(function () {
            showBulkError('Network error — could not reach the bulk endpoint.');
        }).then(function () {
            $btn.prop('disabled', false).html(originalHtml);
        });
    }

    /**
     * 📂 Read an uploaded file into the bulk textarea and auto-detect the mode.
     *
     * @param {Event} e - The file input change event
     */
    function handleBulkFileUpload(e) {
        var input = e.target;
        if (!input.files || input.files.length === 0) {
            return;
        }

        var file = input.files[0];
        var name = (file.name || '').toLowerCase();

        /* 📌 Auto-select the input mode from the file extension. */
        if (name.lastIndexOf('.json') === name.length - 5) {
            $('#bulk-input-mode').val('json');
        } else if (name.lastIndexOf('.csv') === name.length - 4) {
            $('#bulk-input-mode').val('csv');
        }

        var reader = new FileReader();
        reader.onload = function (readerEvent) {
            $('#bulk-data').val(readerEvent.target.result);
            updateBulkCount();
        };
        reader.onerror = function () {
            showBulkError('Could not read the selected file.');
        };
        reader.readAsText(file);
    }

    $(document).ready(function () {

        // ======================================================================
        // 📦 Bulk Generate Panel Events (FG-003)
        // ======================================================================

        /* 📌 Live count preview on data / mode changes. */
        $('#bulk-data').on('input', updateBulkCount);
        $('#bulk-input-mode').on('change', updateBulkCount);

        /* 📌 File upload fills the textarea. */
        $('#bulk-file').on('change', handleBulkFileUpload);

        /* 📌 Submit + clear. */
        $('#bulk-submit').on('click', submitBulk);
        $('#bulk-clear').on('click', function () {
            $('#bulk-data').val('');
            $('#bulk-file').val('');
            $('#bulk-error').addClass('d-none').text('');
            $('#bulk-success').addClass('d-none').text('');
            updateBulkCount();
        });

        // ======================================================================
        // 📱 QR Type Selector
        // ======================================================================

        /* 📌 Bind click handler to QR type buttons */
        $('#qr-type-grid').on('click', '.qr-type-btn', handleTypeChange);

        // ======================================================================
        // 📝 Dynamic Form Field Change Events (delegated)
        // ======================================================================

        /* 📌 Trigger preview on any change within the dynamic form fields */
        /* Using delegated events since fields are dynamically rendered */
        /* @see https://api.jquery.com/on/#direct-and-delegated-events */
        $('#qr-form-fields').on('input', 'input, textarea', function () {
            triggerPreview();
        });
        $('#qr-form-fields').on('change', 'select, input[type="checkbox"]', function () {
            triggerPreview();
        });

        // ======================================================================
        // 🎨 Colour Picker Synchronisation
        // ======================================================================

        /* 📌 Set up two-way sync for each colour picker / hex input pair */
        setupColourSync('#opt-fg-color', '#opt-fg-color-hex');
        setupColourSync('#opt-bg-color', '#opt-bg-color-hex');
        setupColourSync('#opt-marker-color', '#opt-marker-color-hex');
        setupColourSync('#opt-frame-color', '#opt-frame-color-hex');

        /* 📌 Transparent background toggle triggers preview */
        $('#opt-transparent').on('change', function () {
            triggerPreview();
        });

        // ======================================================================
        // 🔲 Design Tab: Pattern Grid
        // ======================================================================

        /* 📌 Set up pattern selection grid */
        setupSelectionGrid(
            '#pattern-grid',
            '.pattern-btn',
            'pattern',
            '#opt-pattern'
        );

        // ======================================================================
        // 🔲 Design Tab: Marker Border Grid
        // ======================================================================

        /* 📌 Set up marker border selection grid */
        setupSelectionGrid(
            '#marker-border-grid',
            '.marker-btn',
            'marker-border',
            '#opt-marker-border'
        );

        // ======================================================================
        // 🔲 Design Tab: Marker Centre Grid
        // ======================================================================

        /* 📌 Set up marker centre selection grid */
        setupSelectionGrid(
            '#marker-center-grid',
            '.marker-btn',
            'marker-center',
            '#opt-marker-center'
        );

        // ======================================================================
        // 🖼️ Logo Tab: Upload
        // ======================================================================

        /* 📌 Logo file input change handler */
        $('#opt-logo-upload').on('change', handleLogoUpload);

        /* 📌 Remove uploaded logo button */
        $('#btn-remove-logo').on('click', removeUploadedLogo);

        // ======================================================================
        // 🖼️ Logo Tab: Predefined Logo Grid
        // ======================================================================

        /* 📌 Set up predefined logo selection grid with custom callback */
        setupSelectionGrid(
            '#predefined-logo-grid',
            '.logo-btn',
            'logo',
            '#opt-logo',
            handlePredefinedLogoSelect
        );

        // ======================================================================
        // 📐 Frame Tab: Frame Style Grid
        // ======================================================================

        /* 📌 Set up frame style selection grid with visibility callback */
        setupSelectionGrid(
            '#frame-grid',
            '.frame-btn',
            'frame',
            '#opt-frame-style',
            function (frameStyle) {
                updateFrameControls(frameStyle);
            }
        );

        /* 📌 Frame label and colour changes trigger preview */
        $('#opt-frame-label').on('input', function () {
            triggerPreview();
        });

        /* 📌 Initialise frame controls visibility based on default state */
        updateFrameControls($('#opt-frame-style').val());

        // ======================================================================
        // ⚙️ Options Tab: Format, Size, ECC, Quiet Zone
        // ======================================================================

        /* 📌 Output format change */
        $('#opt-format').on('change', handleFormatChange);

        /* 📌 Output size slider */
        $('#opt-size').on('input', handleSizeChange);

        /* 📌 Error correction level radio buttons */
        $('input[name="ecc_level"]').on('change', function () {
            triggerPreview();
        });

        /* 📌 Quiet zone checkbox */
        $('#opt-quiet-zone').on('change', function () {
            triggerPreview();
        });

        /* 📌 Logo size slider */
        $('#opt-logo-size').on('input', handleLogoSizeChange);

        // ======================================================================
        // 📥 Download Button
        // ======================================================================

        /* 📌 Download button click handler */
        $('#btn-download').on('click', handleDownload);

        // ======================================================================
        // 🔄 Initialise Default State
        // ======================================================================

        /* 📌 Load the default type's form fields (first active type or 'url') */
        var $defaultBtn = $('#qr-type-grid .qr-type-btn.active');
        if ($defaultBtn.length > 0) {
            if ($defaultBtn.data('type')) {
                selectedType = $defaultBtn.data('type');
            } else {
                selectedType = 'url';
            }
            var defaultFieldsJSON;
            if ($defaultBtn.attr('data-fields')) {
                defaultFieldsJSON = $defaultBtn.attr('data-fields');
            } else {
                defaultFieldsJSON = '[]';
            }
            try {
                var defaultFields = JSON.parse(defaultFieldsJSON);
                renderFormFields(defaultFields);
            } catch (e) {
                /* 📌 Keep the server-rendered fields if JSON parsing fails */
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('CueRCode: Using server-rendered default fields.');
                }
            }

            /* 📌 Update the content header label */
            var defaultLabel;
            if ($defaultBtn.find('span').text()) {
                defaultLabel = $defaultBtn.find('span').text();
            } else {
                defaultLabel = selectedType;
            }
            $('#content-header-label').text(defaultLabel + ' Content');
        }

        /* 📌 Set initial display values for sliders and format */
        $('#size-value').text($('#opt-size').val());
        $('#download-size-label').text($('#opt-size').val() + 'px');
        $('#logo-size-value').text($('#opt-logo-size').val());
        var initFormat = $('#opt-format option:selected').text().split(' -')[0].trim();
        var initFormatDisplay;
        if (initFormat) {
            initFormatDisplay = initFormat;
        } else {
            initFormatDisplay = 'PNG';
        }
        $('#download-format-label').text(initFormatDisplay);
    });

    // ==========================================================================
    // 📤 Exported Functions (CueRCode Namespace)
    // ==========================================================================
    // Expose key functions on the CueRCode namespace for external use or debugging.
    // ==========================================================================

    /**
     * 📤 Force a preview refresh from external code
     *
     * Can be called as CueRCode.refreshPreview() to manually trigger
     * a preview update from other scripts or the browser console.
     */
    CueRCode.refreshPreview = function () {
        triggerPreview();
    };

    /**
     * 📤 Collect current form data (exposed for debugging/external use)
     *
     * @returns {Object} Current form field values
     */
    CueRCode.getFormData = function () {
        return collectFormData();
    };

    /**
     * 📤 Collect current customisation options (exposed for debugging/external use)
     *
     * @returns {Object} Current customisation settings
     */
    CueRCode.getCustomization = function () {
        return collectCustomization();
    };

})(jQuery);
