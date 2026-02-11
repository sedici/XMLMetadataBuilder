/**
 * XML Enricher Form JavaScript Module
 * Handles Vue config injection, XML listing, and enrichment operations
 */
(function () {
    'use strict';
    console.log('[XML Enricher] Loading module v3...');

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
                    console.log('[XML Enricher] Injected into pkp.const.components');
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
                            console.log('[XML Enricher] Injected config into Vue instance');
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

                    console.log('[XML Enricher] Converted Suffix field to fieldset structure');
                    clearInterval(interval);
                }
            }

            if (attempts >= maxAttempts) {
                console.log('[XML Enricher] Max attempts reached for suffix field conversion');
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

                console.log('[XML Enricher] Suffix field linked to overwrite checkbox');
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

        console.log('[XML Enricher] Starting button setup...');

        var interval = setInterval(function () {
            attempts++;

            var showFrontBtn = document.getElementById('showFrontButton');

            // Log what we find to debug
            if (attempts % 10 === 0) {
                var buttons = document.querySelectorAll('button');
                console.log('[XML Enricher] Attempt ' + attempts + '. Found ' + buttons.length + ' buttons.');
                buttons.forEach(function (b) {
                    if (b.textContent.includes('Generar') || b.textContent.includes('Generate')) {
                        console.log('[XML Enricher] Found Generate button candidate:', b.className, b);
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
                    console.log('[XML Enricher] Initializing click handler...');

                    showFrontBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        console.log('[XML Enricher] Button clicked!');

                        // Get selected XML file
                        var selectedFile = document.querySelector('input[name*="xmlFileId"]:checked');
                        if (!selectedFile) {
                            // Show notification only - don't set form errors
                            pkp.eventBus.$emit('notify', 'Por favor, seleccione un XML primero', 'warning');
                            return;
                        }

                        var xmlFileId = selectedFile.value;

                        console.log('[XML Enricher] Show Front - File:', xmlFileId);

                        // Show loading state
                        var originalText = showFrontBtn.textContent;
                        showFrontBtn.textContent = 'Cargando...';
                        showFrontBtn.disabled = true;

                        // Construct URL dynamically
                        var contextPath = 'publicknowledge'; // Fallback
                        if (typeof pkp !== 'undefined' && pkp.context && pkp.context.path) {
                            contextPath = pkp.context.path;
                        } else {
                            var pathParts = window.location.pathname.split('/');
                            var indexIdx = pathParts.indexOf('index.php');
                            if (indexIdx !== -1 && pathParts.length > indexIdx + 1) {
                                contextPath = pathParts[indexIdx + 1];
                            }
                        }

                        var url = '/index.php/' + contextPath + '/XMLMetadataBuilder/showFront';
                        console.log('[XML Enricher] Requesting:', url);

                        $.ajax({
                            url: url,
                            type: 'POST',
                            data: {
                                xmlFileId: xmlFileId,
                                csrfToken: (typeof pkp !== 'undefined' && pkp.currentUser) ? pkp.currentUser.csrfToken : null
                            },
                            success: function (response) {
                                console.log('[XML Enricher] Success response received');
                                showFrontModal(response);
                                showFrontBtn.textContent = originalText;
                                showFrontBtn.disabled = false;
                            },
                            error: function (xhr, status, error) {
                                console.error('[XML Enricher] Error:', xhr);
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
                        console.log('[XML Enricher] Successfully moved button to footer');
                        clearInterval(interval); // Stop checking once moved
                    } catch (err) {
                        console.error('[XML Enricher] Error moving button:', err);
                    }
                }
            }

            if (attempts >= maxAttempts) {
                console.log('[XML Enricher] Max attempts reached. Button status:', showFrontBtn ? 'Found' : 'Not Found');
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
        console.log('[XML Enricher] Injected CSS to hide footer error text');
    }

    // Export functions to global scope
    window.XMLEnricherForm = {
        injectVueConfig: injectVueConfig,
        convertSuffixToFieldset: convertSuffixToFieldset,
        linkOverwriteToSuffix: linkOverwriteToSuffix,
        setupShowFrontButton: setupShowFrontButton,
        hideFooterErrorText: hideFooterErrorText
    };
})();
