<tab id="xmlEnricher" label="{translate key='plugins.generic.XMLMetadataBuilder.publication.jats.fulltext'}">
    <div v-if="components.xmlEnricherForm">
        <pkp-form v-bind="components.xmlEnricherForm" @set="set" />

        <!-- Download Action Card -->
        <div id="xmlEnricherDownloadCard" class="pkp_form_actions" style="margin-top: 25px; padding: 16px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; display: flex; align-items: center; justify-content: space-between; gap: 15px;">
            <div>
                <strong style="font-size: 14px; color: #1e293b; display: block; margin-bottom: 4px;">
                    <span class="fa fa-download" style="margin-right: 6px; color: #007ab2;"></span>
                    {translate key="plugins.generic.XMLMetadataBuilder.publication.jats.download"}
                </strong>
                <span style="font-size: 13px; color: #64748b;">
                    Descargue el archivo XML enriquecido actual (o un archivo ZIP si contiene imágenes y dependencias multimedia).
                </span>
            </div>
            <button 
                id="xmlEnricherCardDownloadBtn" 
                class="pkpButton" 
                type="button"
                onclick="window.XMLMetadataBuilder.download()"
                style="padding: 0.5rem 1.25rem; font-weight: 600; white-space: nowrap; cursor: pointer;">
                <span class="fa fa-download" style="margin-right: 5px;"></span>
                {translate key='plugins.generic.XMLMetadataBuilder.publication.jats.download'}
            </button>
        </div>
    </div>

    <script type="text/javascript">
        (function() {
            var formConfig = {$xmlEnricherConfig|json_encode};

            function registerXmlEnricherForm(app) {
                if (!app || !app.components) return;
                if (!app.components.xmlEnricherForm && formConfig) {
                    if (typeof app.$set === 'function') {
                        app.$set(app.components, 'xmlEnricherForm', formConfig);
                    } else {
                        app.components.xmlEnricherForm = formConfig;
                    }
                }
                if (app.publicationFormIds && !app.publicationFormIds.includes('xmlEnricherForm')) {
                    app.publicationFormIds.push('xmlEnricherForm');
                }
                if (typeof app.setPublicationForms === 'function' && app.workingPublication) {
                    app.setPublicationForms(app.workingPublication);
                }
            }

            // 1. If app instance is already initialized in registry
            if (typeof pkp !== 'undefined' && pkp.registry && pkp.registry._instances && pkp.registry._instances['app']) {
                registerXmlEnricherForm(pkp.registry._instances['app']);
            }

            // 2. When Vue root mounts
            if (typeof pkp !== 'undefined' && pkp.eventBus) {
                pkp.eventBus.$on('root:mounted', function(id, instance) {
                    if (id === 'app') {
                        registerXmlEnricherForm(instance);
                    }
                });
            }
        })();

        window.XMLMetadataBuilder = window.XMLMetadataBuilder || {};
        window.XMLMetadataBuilder.urls = {
            download: {$xmlEnricherDownloadUrl|json_encode}
        };
        window.XMLMetadataBuilder.fieldName = {$xmlFileIdFieldName|json_encode};

        window.XMLMetadataBuilder.getSelectedXmlFileId = function() {
            // 1. Check checked radio input in DOM
            var input = document.querySelector('#xmlEnricher input[name*="xmlFileId"]:checked, input[name*="xmlFileId"]:checked');
            if (input && input.value) {
                return input.value;
            }
            // 2. Check Vue reactive component state
            if (typeof pkp !== 'undefined' && pkp.registry && pkp.registry._instances && pkp.registry._instances['app']) {
                var app = pkp.registry._instances['app'];
                var fields = app.components && app.components.xmlEnricherForm && app.components.xmlEnricherForm.fields;
                if (Array.isArray(fields)) {
                    var f = fields.find(function(item) { return item.name && item.name.includes('xmlFileId'); });
                    if (f && f.value) {
                        return f.value;
                    }
                }
            }
            return null;
        };

        window.XMLMetadataBuilder.showNoFileSelectedError = function() {
            var noFileMsg = {translate|json_encode key='plugins.generic.XMLMetadataBuilder.noFileSelected'};
            if (typeof pkp !== 'undefined' && pkp.eventBus) {
                pkp.eventBus.$emit('notify', noFileMsg, 'warning');
            } else {
                alert(noFileMsg);
            }
        };

        window.XMLMetadataBuilder.download = function() {
            var fileId = window.XMLMetadataBuilder.getSelectedXmlFileId();
            if (!fileId) {
                window.XMLMetadataBuilder.showNoFileSelectedError();
                return;
            }

            var url = window.XMLMetadataBuilder.urls.download;
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'xmlFileId=' + encodeURIComponent(fileId);
            window.location.href = url;
        };

        // DOM helper: Injects the Download button directly into the form's footer next to the submit button
        function syncFormUi() {
            var tab = document.querySelector('#xmlEnricher');
            if (!tab) return;

            // 1. Look for the submit button ("Generar XML Enriquecido")
            var submitBtn = tab.querySelector('button.pkpButton--isPrimary, button[type="submit"]');
            if (!submitBtn) {
                var allButtons = tab.querySelectorAll('button');
                for (var i = 0; i < allButtons.length; i++) {
                    var txt = allButtons[i].textContent.trim();
                    if (txt.includes('Generar') || txt.includes('Generate')) {
                        submitBtn = allButtons[i];
                        break;
                    }
                }
            }

            if (submitBtn) {
                // Intercept clicks on the Generar XML button if no XML is selected
                if (!submitBtn.dataset.xmlValidationAttached) {
                    submitBtn.dataset.xmlValidationAttached = 'true';
                    submitBtn.addEventListener('click', function(e) {
                        var fileId = window.XMLMetadataBuilder.getSelectedXmlFileId();
                        if (!fileId) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            window.XMLMetadataBuilder.showNoFileSelectedError();
                            return false;
                        }
                    }, true);
                }

                var form = submitBtn.closest('form') || tab.querySelector('form');
                if (form && !form.dataset.xmlValidationAttached) {
                    form.dataset.xmlValidationAttached = 'true';
                    form.addEventListener('submit', function(e) {
                        var fileId = window.XMLMetadataBuilder.getSelectedXmlFileId();
                        if (!fileId) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            window.XMLMetadataBuilder.showNoFileSelectedError();
                            return false;
                        }
                    }, true);
                }
            }

            if (submitBtn && submitBtn.parentNode) {
                var footer = submitBtn.parentNode;
                if (!document.getElementById('xmlEnricherFooterDownloadBtn')) {
                    var footerBtn = document.createElement('button');
                    footerBtn.id = 'xmlEnricherFooterDownloadBtn';
                    footerBtn.className = 'pkpButton';
                    footerBtn.type = 'button';
                    footerBtn.style.marginRight = '12px';
                    footerBtn.style.cursor = 'pointer';
                    footerBtn.innerHTML = '<span class="fa fa-download" style="margin-right: 5px;"></span>' + {translate|json_encode key='plugins.generic.XMLMetadataBuilder.publication.jats.download'};
                    footerBtn.onclick = function(e) {
                        e.preventDefault();
                        window.XMLMetadataBuilder.download();
                    };
                    footer.insertBefore(footerBtn, submitBtn);
                }
            }

            // 2. Link fileAction state to suffix field (disable / dim suffix when overwrite is selected)
            var overwriteInput = tab.querySelector('input[name*="fileAction"][value="overwrite"], input[name*="overwrite"]');
            var suffixInput = tab.querySelector('input[name*="suffix"]');
            var anyActionChecked = tab.querySelector('input[name*="fileAction"]:checked');
            if (!anyActionChecked) {
                var defaultActionRadio = tab.querySelector('input[name*="fileAction"][value="suffix"]');
                if (defaultActionRadio) {
                    defaultActionRadio.checked = true;
                }
            }
            if (overwriteInput && suffixInput) {
                var isOverwrite = overwriteInput.checked;
                suffixInput.disabled = isOverwrite;
                var suffixGroup = suffixInput.closest('.pkpFormField');
                if (suffixGroup) {
                    suffixGroup.style.opacity = isOverwrite ? '0.45' : '1';
                }
            }
        }

        setInterval(syncFormUi, 300);
    </script>
</tab>
