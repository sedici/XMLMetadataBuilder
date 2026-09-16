<?php

/**
 * @file plugins/generic/XMLMetadataBuilder/XMLMetadataBuilderHandler.php
 *
 * @class XMLMetadataBuilderHandler
 * @ingroup plugins_generic_XMLMetadataBuilder
 *
 * @brief Handle page requests for the XML Metadata Builder plugin.
 *
 * Authorization is handled automatically by OJS via WorkflowStageAccessPolicy
 * and addRoleAssignment(), following the same pattern used by Texture and
 * DocxConverter plugins. No manual role-checking is needed in action methods.
 */

namespace APP\plugins\generic\XMLMetadataBuilder;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use PKP\core\JSONMessage;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\WorkflowStageAccessPolicy;
use PKP\security\Role;

class XMLMetadataBuilderHandler extends Handler
{
    /** @var bool Mark as backend page so OJS applies the right auth context */
    public $_isBackendPage = true;

    /** @var XMLMetadataBuilderPlugin The plugin instance */
    protected XMLMetadataBuilderPlugin $_plugin;

    /**
     * Constructor.
     *
     * Declares which roles are allowed to call which actions.
     * OJS enforces this automatically before the action method runs.
     */
    public function __construct()
    {
        parent::__construct();

        $this->_plugin = PluginRegistry::getPlugin('generic', XML_METADATA_BUILDER_PLUGIN_NAME);

        // Grant access to managers, sub-editors (section editors), and assistants
        // (copyeditors, layout editors, etc.). Authors are intentionally excluded
        // because these actions serve editorial/production workflows.
        $this->addRoleAssignment(
            [
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ],
            ['showFront', 'download']
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     *
     * Uses WorkflowStageAccessPolicy to verify that the authenticated user
     * has the declared role AND is allowed to access the given submissionId
     * at the given stageId. This replaces all manual userCanAccessSubmission()
     * logic from the previous implementation.
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new WorkflowStageAccessPolicy(
            $request,
            $args,
            $roleAssignments,
            'submissionId',
            (int) $request->getUserVar('stageId')
        ));

        return parent::authorize($request, $args, $roleAssignments);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Return the enriched XML <front> element as preview content in a modal.
     *
     * By the time this method is reached the framework has already verified
     * authentication and role-based access via authorize(). No further
     * security checks are required here.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     * @return JSONMessage
     */
    public function showFront($args, $request): JSONMessage
    {
        $xmlFileId = (int) $request->getUserVar('xmlFileId');
        if ($xmlFileId <= 0) {
            return new JSONMessage(false, __('plugins.generic.XMLMetadataBuilder.noFileSelected'));
        }

        $file = Repo::submissionFile()->get($xmlFileId);
        if (!$file) {
            return new JSONMessage(false, __('plugins.generic.XMLMetadataBuilder.fileNotFound'));
        }

        // Security check: Ensure the requested file belongs to the authorized submission
        $authorizedSubmission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        if (!$authorizedSubmission || $file->getData('submissionId') !== $authorizedSubmission->getId()) {
            return new JSONMessage(false, __('common.permissionDenied'));
        }

        try {
            $service = new \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService();
            $frontXml = $service->extractFrontElement($xmlFileId);

            $html = '<div class="pkp_xml_front_preview" style="padding: 10px;">' .
                '<pre style="max-height: 550px; overflow: auto; padding: 15px; background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 4px; font-family: monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all;">' .
                htmlspecialchars($frontXml, ENT_QUOTES, 'UTF-8') .
                '</pre>' .
                '</div>';

            return new JSONMessage(true, $html);
        } catch (\Exception $e) {
            error_log('[XMLMetadataBuilder] showFront error: ' . $e->getMessage());
            return new JSONMessage(false, __('plugins.generic.XMLMetadataBuilder.enrichmentError'));
        }
    }

    /**
     * Download the enriched XML (and dependent files as ZIP if present).
     *
     * Same security guarantee as showFront(): authorization is enforced by
     * OJS before this method is invoked.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function download($args, $request): void
    {
        $xmlFileId = (int) $request->getUserVar('xmlFileId');
        if ($xmlFileId <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Error: ID de archivo inválido';
            exit;
        }

        try {
            $file = Repo::submissionFile()->get($xmlFileId);
            if (!$file) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Error: Archivo no encontrado';
                exit;
            }

            // Security check: Ensure the requested file belongs to the authorized submission
            $authorizedSubmission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
            if (!$authorizedSubmission || $file->getData('submissionId') !== $authorizedSubmission->getId()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Error: Acceso denegado al archivo';
                exit;
            }

            $service = new \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService();
            $enrichedXml    = $service->getEnrichedXmlContent($xmlFileId);
            $dependentFiles = $service->getDependentFilesPublic($xmlFileId);

            $originalFilename = $file->getLocalizedData('name');
            $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);

            if (empty($dependentFiles)) {
                // Plain XML download
                $filename = $baseName . '-enriched.xml';
                header('Content-Description: File Transfer');
                header('Content-Type: application/xml');
                header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . strlen($enrichedXml));
                echo $enrichedXml;
            } else {
                // ZIP with XML + dependent files
                $zipFilename  = $baseName . '-enriched.zip';
                $tempZipPath  = tempnam(sys_get_temp_dir(), 'xml_download_');

                $zip = new \ZipArchive();
                if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    throw new \Exception('No se pudo crear el archivo ZIP');
                }

                $zip->addFromString($baseName . '-enriched.xml', $enrichedXml);

                foreach ($dependentFiles as $dependentFile) {
                    try {
                        $fileContents = $service->readFileContent($dependentFile);
                        if ($fileContents) {
                            $zip->addFromString($dependentFile->getLocalizedData('name'), $fileContents);
                        }
                    } catch (\Exception $e) {
                        // Skip file if it can't be read
                        error_log('[XMLMetadataBuilder] download error (dependent file): ' . $e->getMessage());
                    }
                }

                $zip->close();

                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($zipFilename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($tempZipPath));
                readfile($tempZipPath);
                unlink($tempZipPath);
            }
        } catch (\Exception $e) {
            header('Content-Type: text/plain; charset=utf-8');
            error_log('[XMLMetadataBuilder] download error: ' . $e->getMessage());
            echo 'Error: No se pudo procesar la descarga';
        }

        exit;
    }
}

/* Backwards compatibility (OJS < 3.4) */
if (!defined('PKP_STRICT_MODE') || !PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\XMLMetadataBuilder\XMLMetadataBuilderHandler', '\XMLMetadataBuilderHandler');
}

