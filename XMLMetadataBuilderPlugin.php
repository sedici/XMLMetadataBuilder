<?php
/**
 * @file plugins/generic/XMLMetadataBuilder/XMLMetadataBuilderPlugin.php
 */

namespace APP\plugins\generic\XMLMetadataBuilder;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use APP\core\Application;
use APP\template\TemplateManager;
use APP\facades\Repo;
use APP\plugins\generic\XMLMetadataBuilder\classes\XMLMetadataProcessor;


class XMLMetadataBuilderPlugin extends GenericPlugin
{
    /**
    * Registrar plugin y hooks
    */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {

                // Hook into the Publication Workflow to add the tab
                Hook::add('Template::Workflow::Publication', [$this, 'addToPublicationForms']);

                // Hook to extend publication schema
                Hook::add('Schema::get::publication', [$this, 'addToSchema']);
                
                // Hook to validate form submission BEFORE editing
                Hook::add('Publication::validate', [$this, 'validatePublicationEdit']);
                
                // Hook to handle form submission
                Hook::add('Publication::edit', [$this, 'handlePublicationEdit']);
                
                // Hook to handle file deletion (cleanup galleys first)
                Hook::add('SubmissionFile::delete::before', [$this, 'handleFileDelete']);
                
                // Hook to handle custom AJAX requests
                Hook::add('LoadHandler', [$this, 'setupHandler']);
            
            }
            return true;
        }
        return false;
    }


    public function getDisplayName()
    {
        return __('plugins.generic.XMLMetadataBuilder.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.XMLMetadataBuilder.description');
    }

    public function isSitePlugin()
    {
        return true;
    }

    /**
     * Get the plugin URL
     *
     * @param Request $request
     * @return string
     */
    public function getPluginUrl($request)
    {
        return $request->getBaseUrl() . '/' . $this->getPluginPath();
    }


    /**
     * Add XML Enricher tab to Publication Workflow
     * Hook: Template::Workflow::Publication
     */
    public function addToPublicationForms($hookName, $params)
    {   
        $smartyParams = $params[0];
        $output = &$params[2];

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

        $submission = $templateMgr->getTemplateVars('submission');
        $publication = $templateMgr->getTemplateVars('publication');

        if (!$submission) {
            $submissionId = $request->getUserVar('submissionId');
            if ($submissionId) {
                $submission = Repo::submission()->get((int) $submissionId);
            }
        }

        if ($submission && !$publication) {
            $publication = $submission->getCurrentPublication();
        }

        if (!$submission || !$publication) {
            return false;
        }

        // Get production-ready XML files using the centralized Service
        $xmlFiles = \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::getProductionXmlFiles($submission->getId());

        // Get context for API URL
        $context = $request->getContext();
        $supportedLocales = $context->getSupportedFormLocaleNames();
        $locales = [];
        foreach ($supportedLocales as $key => $label) {
            $locales[] = ['key' => $key, 'label' => $label];
        }

        // Instantiate the Form Component
        require_once($this->getPluginPath() . '/classes/components/EnrichmentForm.php');
        $form = new \APP\plugins\generic\XMLMetadataBuilder\classes\components\EnrichmentForm(
            $request->getDispatcher()->url(
                $request,
                Application::ROUTE_API,
                $context->getPath(),
                'submissions/' . $submission->getId() . '/publications/' . $publication->getId()
            ),
            $locales,
            $xmlFiles,
            $publication  // Pass publication to read current values
        );

        // Inject Config into Vue State
        $componentId = 'xmlEnricherForm';
        $formConfig = $form->getConfig();
        
        // Provide server-generated URLs for auxiliary actions (preview/download)
        // This prevents broken links when JS guesses the contextPath.
        $dispatcher = $request->getDispatcher();
        
        $actionArgs = [
            'submissionId' => $submission->getId(),
            'stageId' => 5 // WORKFLOW_STAGE_ID_PRODUCTION
        ];

        $templateMgr->assign('xmlEnricherShowFrontUrl', $dispatcher->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'XMLMetadataBuilder',
            'showFront',
            null,
            $actionArgs
        ));
        $templateMgr->assign('xmlEnricherDownloadUrl', $dispatcher->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'XMLMetadataBuilder',
            'download',
            null,
            $actionArgs
        ));
                
        // Assign config to template for JS fallback
        $templateMgr->assign('xmlEnricherConfig', $formConfig);

        // Inyectar en UI (JATSParser pattern)
        $state = $templateMgr->getTemplateVars('state');
        $state['components'][$componentId] = $formConfig;
        
        // Add to publicationFormIds if not already present
        if (!isset($state['publicationFormIds'])) {
            $state['publicationFormIds'] = [];
        }
        if (!in_array($componentId, $state['publicationFormIds'])) {
            $state['publicationFormIds'][] = $componentId;
        }

        $templateMgr->assign('state', $state);
        
        // Render the Tab Template and append to output
        $output .= $templateMgr->fetch('file:' . __DIR__ . '/templates/enricherForm.tpl');

        return false;
    }


    /**
     * Extend the publication schema to add custom properties
     * Hook: Schema::get::publication
     */
    public function addToSchema($hookName, $args)
    {
        $schema = $args[0];
        
        // Add custom property for XML file ID (multilingual to support different files per locale)
        $schema->properties->{\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID} = (object) [
            'type' => 'integer',
            'multilingual' => false,
            'apiSummary' => true,
            'validation' => ['nullable']
        ];
        
        // Add custom property for suffix
        $schema->properties->{\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_SUFFIX} = (object) [
            'type' => 'string',
            'multilingual' => false,
            'apiSummary' => true,
            'validation' => ['nullable']
        ];
        
        // Add custom property for overwrite flag
        $schema->properties->{\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_OVERWRITE} = (object) [
            'type' => 'boolean',
            'multilingual' => false,
            'apiSummary' => true,
            'validation' => ['nullable']
        ];
        
        // Add custom property for createGalley flag
        $schema->properties->{\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_CREATE_GALLEY} = (object) [
            'type' => 'boolean',
            'multilingual' => false,
            'apiSummary' => true,
            'validation' => ['nullable']
        ];
        
        return false;
    }

    /**
     * Validate publication edit to ensure XML file is selected
     * Hook: Publication::validate
     */
    public function validatePublicationEdit($hookName, $args)
    {
        $errors = &$args[0];
        $props = $args[2];
        
        // Only validate if our custom field is being submitted
        if (!array_key_exists(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID, $props)) {
            return false;
        }
        
        $xmlFileId = $props[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID] ?? null;
        
        // Check if xmlFileId is empty
        if (empty($xmlFileId) || $xmlFileId === null) {
            
            // Add error to the errors array
            if (!isset($errors[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID])) {
                $errors[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID] = [];
            }
            $errors[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID][] = __('plugins.generic.XMLMetadataBuilder.noFileSelected');
        }
        
        return false;
    }

    /**
     * Handle publication edit to process selected XML file
     * Hook: Publication::edit
     */
    public function handlePublicationEdit($hookName, $args)
    {
        $newPublication = $args[0];
        $params = $args[2];
        
        // Check if our custom fields are present in the params
        if (!array_key_exists(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID, $params)) {
            return false;
        }
        
        // Get the file ID, suffix, overwrite flag, and createGalley flag
        $xmlFileId = $params[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID] ?? null;
        $suffix = $params[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_SUFFIX] ?? \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::DEFAULT_SUFFIX;
        $overwrite = $params[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_OVERWRITE] ?? false;
        $createGalley = $params[\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_CREATE_GALLEY] ?? true;
        
        // Handle suffix if it comes as array (multilingual field)
        if (is_array($suffix)) {
            $suffix = reset($suffix) ?: \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::DEFAULT_SUFFIX;
        }
        // Handle empty string
        if (empty($suffix) || trim($suffix) === '') {
            $suffix = \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::DEFAULT_SUFFIX;
        }

        // Skip if no file is selected (validation already done in validate hook)
        if (empty($xmlFileId) || $xmlFileId === null) {
            return false;
        }
        
        // Delegate to EnrichmentService
        $service = new \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService();
        
        try {
            $service->enrich(
                $xmlFileId,
                $newPublication,
                [
                    'suffix' => $suffix,
                    'overwrite' => $overwrite,
                    'createGalley' => $createGalley
                ]
            );
        } catch (\Exception $e) {
            error_log('[XMLMetadataBuilder] Error al enriquecer XML durante la edición de la publicación: ' . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Handle file deletion by removing associated galleys first
     * Hook: SubmissionFile::delete::before
     */
    public function handleFileDelete($hookName, $args)
    {
        $submissionFile = $args[0];
        
        if (!$submissionFile) {
            return false;
        }
        
        // First, delete associated galleys
        $service = new \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService();
        $service->deleteGalleys($submissionFile->getId());
        
        // Return false to allow OJS to continue with the file deletion
        return false;
    }
    
    /**
     * Setup custom handler for AJAX requests
     * Hook: LoadHandler
     */
    public function setupHandler($hookName, $args)
    {
        $page = $args[0];
        $op = $args[1];
        
        if ($page === 'XMLMetadataBuilder' && in_array($op, ['showFront', 'download'])) {
            define('HANDLER_CLASS', 'XMLMetadataBuilderHandler');
            define('XML_METADATA_BUILDER_PLUGIN_NAME', $this->getName());
            require_once($this->getPluginPath() . '/XMLMetadataBuilderHandler.php');
            return true;
        }

        return false;
    }

}

/* Backwards compatibility (OJS < 3.4) */
if (!defined('PKP_STRICT_MODE') || !PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\XMLMetadataBuilder\XMLMetadataBuilderPlugin', '\XMLMetadataBuilderPlugin');
}
