/**
 * XML Enricher Form JavaScript Module
 * Handles Vue config injection, XML listing, and enrichment operations
 */
(function () {
    'use strict';

    /**
     * Inject Vue component configuration into OJS
     * @param {string} componentId - The component identifier
     * @param {Object} formConfig - The form configuration object
     * @param {number} maxAttempts - Maximum number of retry attempts
     */
    function injectVueConfig(componentId, formConfig, maxAttempts) {
        maxAttempts = maxAttempts || 50;
        var attempts = 0;

        function attemptInject() {
            attempts++;

            // Try to update global constants
            if (typeof pkp !== 'undefined' && pkp.const) {
                if (!pkp.const.components) pkp.const.components = {};

                if (!pkp.const.components[componentId]) {
                    pkp.const.components[componentId] = formConfig;
                }

                if (!pkp.const.publicationFormIds) pkp.const.publicationFormIds = [];
                if (!pkp.const.publicationFormIds.includes(componentId)) {
                    pkp.const.publicationFormIds.push(componentId);
                }
            }

            // Try to inject into active Vue instances
            if (typeof pkp !== 'undefined' && pkp.registry && pkp.registry._instances) {
                Object.values(pkp.registry._instances).forEach(function (instance) {
                    if (instance.components) {
                        if (!instance.components[componentId]) {
                            instance.$set(instance.components, componentId, formConfig);
                        }

                        if (instance.publicationFormIds && !instance.publicationFormIds.includes(componentId)) {
                            instance.publicationFormIds.push(componentId);
                        }
                    }
                });
            }

            if (attempts < maxAttempts) {
                setTimeout(attemptInject, 10);
            }
        }

        attemptInject();
    }

    /**
     * Convert suffix input field to fieldset structure matching OJS styling
     */
    function convertSuffixToFieldset() {
        var attempts = 0;
        var maxAttempts = 20;
        var interval = setInterval(function () {
            attempts++;

            var input = document.querySelector('input[name*="suffix"], input[id*="suffix"]');
            if (input) {
                var wrapper = input.closest('.pkpFormField');
                if (wrapper && !wrapper.classList.contains('fieldset-converted')) {
                    wrapper.classList.add('fieldset-converted');

                    var label = wrapper.querySelector('.pkpFormField__label label, label');
                    var description = wrapper.querySelector('.pkpFormFieldDescription, .pkp_form_description, .pkpFormField__description, .-help');

                    // Create fieldset structure
                    var fieldset = document.createElement('fieldset');
                    fieldset.className = 'pkpFormField--options';
                    fieldset.id = 'suffix-fieldset';

                    var legend = document.createElement('legend');
                    legend.textContent = label ? label.textContent : 'Sufijo';
                    legend.style.fontWeight = 'bold';
                    fieldset.appendChild(legend);

                    // Add description
                    if (description) {
                        var descClone = description.cloneNode(true);
                        descClone.className = 'pkpFormField__description -help';
                        fieldset.appendChild(descClone);
                    } else {
                        var newDesc = document.createElement('div');
                        newDesc.className = 'pkpFormField__description -help';
                        newDesc.textContent = "Sufijo que se agregará al nombre del nuevo archivo XML enriquecido (por ejemplo, con '-enriched' el archivo 'articulo.xml' se guardará como 'articulo-enriched.xml')";
                        fieldset.appendChild(newDesc);
                    }

                    // Add input field
                    var innerDiv = document.createElement('div');
                    innerDiv.className = 'pkpFormField__control';
                    innerDiv.appendChild(input);
                    fieldset.appendChild(innerDiv);

                    wrapper.innerHTML = '';
                    wrapper.appendChild(fieldset);

                    clearInterval(interval);
                }
            }

            if (attempts >= maxAttempts) {
                clearInterval(interval);
            }
        }, 500);
    }

    /**
     * Link overwrite checkbox to suffix field state
     * Disables suffix when overwrite is checked
     */
    function linkOverwriteToSuffix() {
        var checkInterval = setInterval(function () {
            var overwriteCheckbox = document.querySelector('input[name*="overwrite"], input[id*="overwrite"]');
            var suffixInput = document.querySelector('input[name*="suffix"], input[id*="suffix"]');
            var suffixFieldset = document.getElementById('suffix-fieldset');

            if (overwriteCheckbox && suffixInput) {
                function updateSuffixState() {
                    var isChecked = overwriteCheckbox.checked;
                    suffixInput.disabled = isChecked;
                    if (suffixFieldset) {
                        suffixFieldset.style.opacity = isChecked ? '0.5' : '1';
                    }
                }

                updateSuffixState();
                overwriteCheckbox.addEventListener('change', updateSuffixState);

                clearInterval(checkInterval);
            }
        }, 500);
    }

    /**
     * Setup Show Front button to display original XML front element
     */
    function setupShowFrontButton() {
        var attempts = 0;
        var maxAttempts = 60; // 30 seconds


        var interval = setInterval(function () {
            attempts++;

            var showFrontBtn = document.getElementById('showFrontButton');

            // Log what we find to debug
            if (attempts % 10 === 0) {
                var buttons = document.querySelectorAll('button');
                buttons.forEach(function (b) {
                    if (b.textContent.includes('Generar') || b.textContent.includes('Generate')) {
                    }
                });
            }

            // Try to find the submit button (Generate XML)
            // Strategy: Look for button with specific label if class fails
            var submitBtn = document.querySelector('.pkp_form button.pkpButton--isPrimary');

            if (!submitBtn) {
                // Try finding by text content as last resort
                var allButtons = document.querySelectorAll('button');
                for (var i = 0; i < allButtons.length; i++) {
                    if (allButtons[i].textContent.trim().includes('Generar') ||
                        allButtons[i].textContent.trim().includes('Generate')) {
                        submitBtn = allButtons[i];
                        break;
                    }
                }
            }

            var submitBtnContainer = submitBtn ? submitBtn.parentNode : document.querySelector('.pkpForm__footer');

            if (showFrontBtn) {
                // Initialize click handler immediately, don't wait for move
                if (!showFrontBtn.dataset.initialized) {
                    showFrontBtn.dataset.initialized = 'true';

                    showFrontBtn.addEventListener('click', function (e) {
                        e.preventDefault();

                        // Get selected XML file
                        var selectedFile = document.querySelector('input[name*="xmlFileId"]:checked');
                        if (!selectedFile) {
                            // Show notification only - don't set form errors
                            pkp.eventBus.$emit('notify', 'Por favor, seleccione un XML primero', 'warning');
                            return;
                        }

                        var xmlFileId = selectedFile.value;


                        // Show loading state
                        var originalText = showFrontBtn.textContent;
                        showFrontBtn.textContent = 'Cargando...';
                        showFrontBtn.disabled = true;

                        // Prefer server-generated URL to avoid wrong contextPath
                        var url = (window.XMLMetadataBuilder && window.XMLMetadataBuilder.urls && window.XMLMetadataBuilder.urls.showFront)
                            ? window.XMLMetadataBuilder.urls.showFront
                            : null;

                        // Backwards-compatible fallback (no hardcoded context)
                        if (!url) {
                            var contextPath = null;
                            if (typeof pkp !== 'undefined' && pkp.context && pkp.context.path) {
                                contextPath = pkp.context.path;
                            } else {
                                var pathParts = window.location.pathname.split('/');
                                var indexIdx = pathParts.indexOf('index.php');
                                if (indexIdx !== -1 && pathParts.length > indexIdx + 1) {
                                    contextPath = pathParts[indexIdx + 1];
                                }
                            }

                            if (!contextPath) {
                                alert('Error: no se pudo determinar la revista (contextPath) para previsualizar.');
                                showFrontBtn.textContent = originalText;
                                showFrontBtn.disabled = false;
                                return;
                            }

                            url = '/index.php/' + contextPath + '/XMLMetadataBuilder/showFront';
                        }

                        $.ajax({
                            url: url,
                            type: 'POST',
                            data: {
                                xmlFileId: xmlFileId,
                                csrfToken: (typeof pkp !== 'undefined' && pkp.currentUser) ? pkp.currentUser.csrfToken : null
                            },
                            success: function (response) {
                                showFrontModal(response);
                                showFrontBtn.textContent = originalText;
                                showFrontBtn.disabled = false;
                            },
                            error: function (xhr, status, error) {
                                var msg = 'Error al obtener el Front del XML';
                                if (xhr.status === 404) {
                                    msg += ': Endpoint no encontrado (' + url + ')';
                                } else {
                                    msg += ': ' + error;
                                }
                                alert(msg);
                                showFrontBtn.textContent = originalText;
                                showFrontBtn.disabled = false;
                            }
                        });
                    });
                }

                // Try to move it
                if (submitBtnContainer && showFrontBtn.parentNode !== submitBtnContainer) {
                    try {
                        // Remove from original location
                        if (showFrontBtn.parentNode) {
                            showFrontBtn.parentNode.removeChild(showFrontBtn);
                        }

                        // Add margin
                        showFrontBtn.style.marginRight = '10px';

                        // Insert
                        submitBtnContainer.insertBefore(showFrontBtn, submitBtnContainer.firstChild);
                        showFrontBtn.style.display = ''; // Show it now that it is moved
                        clearInterval(interval); // Stop checking once moved
                    } catch (err) {
                    }
                }
            }

            if (attempts >= maxAttempts) {
                clearInterval(interval);
            }
        }, 500);
    }

    /**
     * Show modal with XML front element
     */
    function showFrontModal(xmlContent) {
        // Create modal overlay
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';

        // Create modal content
        var modal = document.createElement('div');
        modal.style.cssText = 'background: white; padding: 30px; border-radius: 8px; max-width: 900px; width: 90%; max-height: 80vh; overflow: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);';

        var title = document.createElement('h2');
        title.textContent = 'Front del XML';
        title.style.marginTop = '0';
        modal.appendChild(title);

        var content = document.createElement('pre');
        content.style.cssText = 'background: #f5f5f5; padding: 15px; border-radius: 4px; overflow: auto; max-height: 60vh; white-space: pre-wrap; word-wrap: break-word; font-family: monospace; font-size: 12px;';
        content.textContent = xmlContent;
        modal.appendChild(content);

        var closeBtn = document.createElement('button');
        closeBtn.textContent = 'Cerrar';
        closeBtn.className = 'pkpButton';
        closeBtn.style.cssText = 'margin-top: 20px; background: #007ab2; color: white; padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer;';
        closeBtn.onclick = function () {
            document.body.removeChild(overlay);
        };
        modal.appendChild(closeBtn);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        // Close on overlay click
        overlay.onclick = function (e) {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
            }
        };
    }

    /**
     * Setup Download button to download original XML
     */
    function setupDownloadButton() {
        var attempts = 0;
        var maxAttempts = 60; // 30 seconds


        var interval = setInterval(function () {
            attempts++;

            var downloadBtn = document.getElementById('downloadXmlButton');
            var submitBtn = document.querySelector('.pkp_form button.pkpButton--isPrimary');

            if (!submitBtn) {
                var allButtons = document.querySelectorAll('button');
                for (var i = 0; i < allButtons.length; i++) {
                    if (allButtons[i].textContent.trim().includes('Generar') ||
                        allButtons[i].textContent.trim().includes('Generate')) {
                        submitBtn = allButtons[i];
                        break;
                    }
                }
            }

            var submitBtnContainer = submitBtn ? submitBtn.parentNode : document.querySelector('.pkpForm__footer');

            if (downloadBtn) {
                if (!downloadBtn.dataset.initialized) {
                    downloadBtn.dataset.initialized = 'true';

                    downloadBtn.addEventListener('click', function (e) {
                        e.preventDefault();

                        var selectedFile = document.querySelector('input[name*="xmlFileId"]:checked');
                        if (!selectedFile) {
                            pkp.eventBus.$emit('notify', 'Por favor, seleccione un XML primero', 'warning');
                            return;
                        }

                        var xmlFileId = selectedFile.value;

                        // Prefer server-generated URL to avoid wrong contextPath
                        var baseUrl = (window.XMLMetadataBuilder && window.XMLMetadataBuilder.urls && window.XMLMetadataBuilder.urls.download)
                            ? window.XMLMetadataBuilder.urls.download
                            : null;

                        // Backwards-compatible fallback (no hardcoded context)
                        if (!baseUrl) {
                            var contextPath = null;
                            if (typeof pkp !== 'undefined' && pkp.context && pkp.context.path) {
                                contextPath = pkp.context.path;
                            } else {
                                var pathParts = window.location.pathname.split('/');
                                var indexIdx = pathParts.indexOf('index.php');
                                if (indexIdx !== -1 && pathParts.length > indexIdx + 1) {
                                    contextPath = pathParts[indexIdx + 1];
                                }
                            }

                            if (!contextPath) {
                                alert('Error: no se pudo determinar la revista (contextPath) para descargar.');
                                return;
                            }

                            baseUrl = '/index.php/' + contextPath + '/XMLMetadataBuilder/download';
                        }

                        var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';
                        var url = baseUrl + separator + 'xmlFileId=' + encodeURIComponent(xmlFileId);
                        window.location.href = url;
                    });
                }

                if (submitBtnContainer && downloadBtn.parentNode !== submitBtnContainer) {
                    try {
                        if (downloadBtn.parentNode) {
                            downloadBtn.parentNode.removeChild(downloadBtn);
                        }

                        downloadBtn.style.marginRight = '10px';

                        // Insert before submit button to ensure it is between Show Front (if present) and Submit
                        if (submitBtn) {
                            submitBtnContainer.insertBefore(downloadBtn, submitBtn);
                        } else {
                            submitBtnContainer.insertBefore(downloadBtn, submitBtnContainer.firstChild);
                        }
                        downloadBtn.style.display = '';
                        clearInterval(interval);
                    } catch (err) {
                    }
                }
            }

            if (attempts >= maxAttempts) {
                clearInterval(interval);
            }
        }, 500);
    }

    /**
     * Hide the footer error text that appears to the left of the submit button
     */
    function hideFooterErrorText() {
        var style = document.createElement('style');
        style.type = 'text/css';
        style.innerHTML = `
            #xmlEnricherForm .pkpForm__footer .pkpForm__error,
            #xmlEnricherForm .pkpForm__footer .pkp_form_error {
                display: none !important;
            }
        `;
        document.head.appendChild(style);
    }

    // Export functions to global scope
    window.XMLEnricherForm = {
        injectVueConfig: injectVueConfig,
        convertSuffixToFieldset: convertSuffixToFieldset,
        linkOverwriteToSuffix: linkOverwriteToSuffix,
        linkOverwriteToSuffix: linkOverwriteToSuffix,
        setupShowFrontButton: setupShowFrontButton,
        setupDownloadButton: setupDownloadButton,
        hideFooterErrorText: hideFooterErrorText
    };
})();
