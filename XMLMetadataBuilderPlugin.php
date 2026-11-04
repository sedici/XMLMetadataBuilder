<?php

/**
 * @file plugins/generic/XMLMetadataBuilder/XMLMetadataBuilderPlugin.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class XMLMetadataBuilderPlugin
 *
 * @brief Enriquece automáticamente el JATS de la publicación (OJS 3.5) con los
 *  metadatos de OJS, sin interfaz de usuario.
 *
 * Momentos en los que se enriquece:
 *  1. SubmissionFile::add      Llega un XML al JATS de la publicación (subida en el
 *                              panel JATS o copia de core al crear una versión).
 *  2. Publication::publish     Se programa o publica la publicación: pasada final con
 *                              los metadatos definitivos (fecha, número, licencia, DOI).
 *                              Si la revista lo tiene activado (por defecto sí), se
 *                              genera o actualiza la galerada XML desde ese JATS.
 *  3. Publication::version     Core copia el XML a la versión nueva pero no sus
 *                              dependientes (imágenes): el plugin los copia.
 *  4. Publication::edit,       Cambian los metadatos o los autores: se enriquece al
 *     Author::add/edit/delete  final de la petición, cuando los datos ya se guardaron.
 */

namespace APP\plugins\generic\XMLMetadataBuilder;

use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\XMLMetadataBuilder\classes\JatsEnrichmentService;
use APP\plugins\generic\XMLMetadataBuilder\classes\XmlGalleyService;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class XMLMetadataBuilderPlugin extends GenericPlugin
{
    /** Setting: generate the XML galley from the publication JATS on publication */
    public const SETTING_CREATE_XML_GALLEY = 'createXmlGalley';

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('SubmissionFile::add', $this->onSubmissionFileAdd(...));
            Hook::add('Publication::publish', $this->onPublish(...));
            Hook::add('Publication::version', $this->onVersion(...));
            Hook::add('Publication::edit', $this->onPublicationEdit(...));
            Hook::add('Author::add', $this->onAuthorChange(...));
            Hook::add('Author::edit', $this->onAuthorChange(...));
            Hook::add('Author::delete', $this->onAuthorChange(...));
        }

        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.XMLMetadataBuilder.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.XMLMetadataBuilder.description');
    }

    /**
     * Enriquecer un XML apenas se agrega como JATS de una publicación.
     *
     * @hook SubmissionFile::add [[$submissionFile]]
     */
    public function onSubmissionFileAdd(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];

        if (JatsEnrichmentService::isPublicationJatsFile($submissionFile)) {
            $this->getService()->enrichJatsFile($submissionFile, 'upload');
        }

        return Hook::CONTINUE;
    }

    /**
     * Pasada final al programar o publicar, con los metadatos definitivos.
     *
     * Se usa el hook posterior (y no Publication::publish::before) para que la
     * publicación ya esté guardada con fecha, licencia y estado definitivos.
     *
     * @hook Publication::publish [[&$newPublication, $publication, $submission]]
     */
    public function onPublish(string $hookName, array $args): bool
    {
        [$newPublication, , $submission] = $args;

        $this->getService()->enrichPublication($newPublication, 'publish');

        if ($this->isXmlGalleyEnabled((int) $submission->getData('contextId'))) {
            (new XmlGalleyService())->syncXmlGalley(
                Repo::publication()->get($newPublication->getId()) ?? $newPublication
            );
        }

        return Hook::CONTINUE;
    }

    /**
     * Whether the journal wants the XML galley generated on publication.
     * Enabled by default, as in OJS 3.4.
     */
    public function isXmlGalleyEnabled(int $contextId): bool
    {
        $setting = $this->getSetting($contextId, self::SETTING_CREATE_XML_GALLEY);

        return $setting === null ? true : (bool) $setting;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal($url, $this->getDisplayName()),
            __('manager.plugins.settings')
        ));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new XMLMetadataBuilderSettingsForm($this, $request->getContext()->getId());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * Copiar las imágenes del JATS de la versión anterior a la nueva.
     *
     * Cuando corre este hook, core ya copió el XML (lo que disparó onSubmissionFileAdd
     * y lo enriqueció), pero no sus archivos dependientes.
     *
     * @hook Publication::version [[&$newPublication, $publication]]
     */
    public function onVersion(string $hookName, array $args): bool
    {
        [$newPublication, $oldPublication] = $args;

        $this->getService()->copyJatsDependentFiles($oldPublication, $newPublication);

        return Hook::CONTINUE;
    }

    /**
     * Mantener el JATS al día cuando cambian los metadatos de la publicación.
     *
     * El hook corre antes de guardar en la base, así que el enriquecimiento se
     * programa para el final de la petición.
     *
     * @hook Publication::edit [[&$newPublication, $publication, $params, $request]]
     */
    public function onPublicationEdit(string $hookName, array $args): bool
    {
        $newPublication = $args[0];

        JatsEnrichmentService::scheduleEnrichment((int) $newPublication->getId(), 'edit');

        return Hook::CONTINUE;
    }

    /**
     * Mantener el JATS al día cuando cambian los autores o sus afiliaciones,
     * que en OJS 3.5 se guardan aparte de la publicación.
     *
     * Author::edit corre antes de guardar y las afiliaciones se guardan dentro
     * del update del autor, así que el enriquecimiento se programa para el final
     * de la petición.
     *
     * @hook Author::add [[$author]]
     * @hook Author::edit [[$newAuthor, $author, $params]]
     * @hook Author::delete [[$author]]
     */
    public function onAuthorChange(string $hookName, array $args): bool
    {
        $author = $args[0];

        if ($publicationId = (int) $author->getData('publicationId')) {
            JatsEnrichmentService::scheduleEnrichment($publicationId, 'edit');
        }

        return Hook::CONTINUE;
    }

    protected function getService(): JatsEnrichmentService
    {
        return new JatsEnrichmentService();
    }
}

// For backwards compatibility -- expect this to be removed approx. OJS/OMP/OPS 3.6
if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\XMLMetadataBuilder\XMLMetadataBuilderPlugin', '\XMLMetadataBuilderPlugin');
}
