<tab id="xmlEnricher" label="{translate key='plugins.generic.XMLMetadataBuilder.publication.jats.fulltext'}">
    <!-- Load JavaScript Module (Moved to top for faster injection) -->
    <script type="text/javascript" src="{$baseUrl}/plugins/generic/XMLMetadataBuilder/templates/js/enricherForm.js?v=3"></script>

    <script type="text/javascript">
        // Server-generated URLs to avoid guessing contextPath in JS
        window.XMLMetadataBuilder = window.XMLMetadataBuilder || {};
        window.XMLMetadataBuilder.urls = {
            showFront: {$xmlEnricherShowFrontUrl|json_encode},
            download: {$xmlEnricherDownloadUrl|json_encode}
        };
    </script>
    
    <script type="text/javascript">
        // Initialize the form using the external module
        (function() {
            var formConfig = {$xmlEnricherConfig|json_encode};
            var componentId = 'xmlEnricherForm';
            
            console.log('[XML Enricher] Initializing with config for:', componentId);
            
            // Use the exported functions from XMLEnricherForm module
            if (typeof XMLEnricherForm !== 'undefined') {
                XMLEnricherForm.injectVueConfig(componentId, formConfig, 50);
                XMLEnricherForm.convertSuffixToFieldset();
                XMLEnricherForm.linkOverwriteToSuffix();
                XMLEnricherForm.setupShowFrontButton();
                XMLEnricherForm.setupDownloadButton();
                XMLEnricherForm.hideFooterErrorText();
            } else {
                console.error('[XML Enricher] XMLEnricherForm module not loaded');
            }
        })();
    </script>

    <!-- Vue Component Binding -->
    <div v-if="components.xmlEnricherForm">
        <!-- Custom Action Buttons (Moved Up) -->
        <button 
            id="showFrontButton" 
            class="pkpButton pkpButton--isPrimary" 
            type="button"
            style="display: none;">
            {translate key='plugins.generic.XMLMetadataBuilder.publication.jats.showFront'}
        </button>

        <button 
            id="downloadXmlButton" 
            class="pkpButton" 
            type="button"
            style="display: none;">
            {translate key='plugins.generic.XMLMetadataBuilder.publication.jats.download'}
        </button>

        <pkp-form v-bind="components.xmlEnricherForm" @set="set" />
        
        <!-- Debug: Show config if form is present -->
        <div style="margin-top: 20px; background: #f0f0f0; padding: 10px; display: none;">
            <h4>Form Config (Debug):</h4>
            <pre>{{ components.xmlEnricherForm }}</pre>
        </div>
    </div>
    
    <!-- Loading / Error State -->
    <div v-else id="xmlEnricherLoading" style="padding: 20px;">
        <p class="pkp_help">{translate key='plugins.generic.XMLMetadataBuilder.publication.jats.description'}</p>
        <div style="display: flex; align-items: center; gap: 10px; color: #666;">
            <span class="pkp_spinner"></span>
            <span>Cargando formulario...</span>
        </div>
    </div>
</tab>
