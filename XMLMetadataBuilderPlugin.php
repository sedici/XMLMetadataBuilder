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
 *  3. Publication::version     Core copia el XML a la versión nueva pero no sus
 *                              dependientes (imágenes): el plugin los copia.
 *  4. Publication::edit,       Cambian los metadatos o los autores: se enriquece al
 *     Author::add/edit/delete  final de la petición, cuando los datos ya se guardaron.
 */

namespace APP\plugins\generic\XMLMetadataBuilder;

use APP\plugins\generic\XMLMetadataBuilder\classes\JatsEnrichmentService;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class XMLMetadataBuilderPlugin extends GenericPlugin
{
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
        $newPublication = $args[0];

        $this->getService()->enrichPublication($newPublication, 'publish');

        return Hook::CONTINUE;
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
