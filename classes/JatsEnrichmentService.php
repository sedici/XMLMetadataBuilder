<?php

/**
 * @file plugins/generic/XMLMetadataBuilder/classes/JatsEnrichmentService.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class JatsEnrichmentService
 *
 * @brief Enriquece el archivo JATS de una publicación (SUBMISSION_FILE_JATS) y lo
 *  mantiene al día entre versiones.
 *
 * El XML se reescribe en el mismo SubmissionFile (nueva revisión con
 * Repo::submissionFile()->edit), así el JATS de la publicación sigue siendo uno
 * solo y la versión anterior queda en el historial de revisiones del archivo.
 */

namespace APP\plugins\generic\XMLMetadataBuilder\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use PKP\config\Config;
use PKP\core\Core;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\security\Validation;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class JatsEnrichmentService
{
    /** Log prefix */
    protected const LOG = '[XMLMetadataBuilder] ';

    /** Files being enriched in this request, to avoid re-entrant enrichment */
    protected static array $inProgress = [];

    /**
     * Whether a submission file is the JATS file of a publication.
     */
    public static function isPublicationJatsFile(SubmissionFile $submissionFile): bool
    {
        return (int) $submissionFile->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_JATS
            && (int) $submissionFile->getData('assocType') === Application::ASSOC_TYPE_PUBLICATION;
    }

    /**
     * Get the JATS file stored for a publication, or null when the publication
     * only has the default content generated on the fly by core (jatsTemplate).
     */
    public function getPublicationJatsFile(Publication $publication): ?SubmissionFile
    {
        return Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$publication->getData('submissionId')])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_JATS])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION, [$publication->getId()])
            ->getMany()
            ->first();
    }

    /**
     * Enrich the JATS file of a publication, if it has one.
     */
    public function enrichPublication(Publication $publication, string $reason): bool
    {
        $jatsFile = $this->getPublicationJatsFile($publication);
        if (!$jatsFile) {
            return false;
        }

        // Reload the publication so the enrichment sees the data as saved
        $publication = Repo::publication()->get($publication->getId()) ?? $publication;

        return $this->enrichJatsFile($jatsFile, $reason, $publication);
    }

    /**
     * Replace the <front> of a publication JATS file with the OJS metadata.
     *
     * Never throws: an enrichment error must not break the upload or the
     * publication. Errors are logged and the file is left untouched.
     *
     * @param string $reason upload|publish|version, used for logging
     *
     * @return bool Whether the file was rewritten
     */
    public function enrichJatsFile(SubmissionFile $jatsFile, string $reason, ?Publication $publication = null): bool
    {
        $fileId = $jatsFile->getId();
        if (isset(self::$inProgress[$fileId])) {
            return false;
        }
        self::$inProgress[$fileId] = true;

        try {
            $publication ??= Repo::publication()->get((int) $jatsFile->getData('assocId'));
            if (!$publication) {
                throw new \Exception('No se encontró la publicación ' . $jatsFile->getData('assocId'));
            }

            $submission = Repo::submission()->get((int) $jatsFile->getData('submissionId'));
            $context = app()->get('context')->get($submission->getData('contextId'));

            $originalXml = Repo::submissionFile()->getSubmissionFileContent($jatsFile);
            $enrichedXml = XMLMetadataProcessor::enrichFront($originalXml, $submission, $publication, $context, $fileId);

            if ($this->normalize($enrichedXml) === $this->normalize($originalXml)) {
                return false;
            }

            $this->replaceFileContent($jatsFile, $enrichedXml);
            $this->logEnrichment($jatsFile, $reason);

            return true;
        } catch (Throwable $e) {
            error_log(self::LOG . "No se pudo enriquecer el JATS {$fileId} ({$reason}): " . $e->getMessage());
            return false;
        } finally {
            unset(self::$inProgress[$fileId]);
        }
    }

    /**
     * Copy the dependent files (images) of the old publication JATS to the new
     * one, and enrich the new one again so its <front> lists them.
     *
     * Core copies the JATS file when a new version is created
     * (versionSubmissionFile) but not its dependent files.
     */
    public function copyJatsDependentFiles(Publication $oldPublication, Publication $newPublication): void
    {
        try {
            $oldJatsFile = $this->getPublicationJatsFile($oldPublication);
            $newJatsFile = $this->getPublicationJatsFile($newPublication);
            if (!$oldJatsFile || !$newJatsFile) {
                return;
            }

            // Do not duplicate them if they were already copied
            if ($this->getDependentFiles($newJatsFile)->isNotEmpty()) {
                return;
            }

            $dependentFiles = $this->getDependentFiles($oldJatsFile);
            if ($dependentFiles->isEmpty()) {
                return;
            }

            foreach ($dependentFiles as $dependentFile) {
                $this->copyDependentFile($dependentFile, $newJatsFile);
            }

            $this->enrichJatsFile($newJatsFile, 'version', $newPublication);
        } catch (Throwable $e) {
            error_log(self::LOG . 'No se pudieron copiar los archivos dependientes a la publicación ' . $newPublication->getId() . ': ' . $e->getMessage());
        }
    }

    /**
     * Dependent files (images, etc.) of a submission file.
     */
    protected function getDependentFiles(SubmissionFile $parentFile): \Illuminate\Support\LazyCollection
    {
        return Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$parentFile->getData('submissionId')])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->filterByAssoc(Application::ASSOC_TYPE_SUBMISSION_FILE, [$parentFile->getId()])
            ->getMany();
    }

    /**
     * Copy a dependent file and attach it to another parent file.
     * Same approach as core's Repository::versionSubmissionFile().
     */
    protected function copyDependentFile(SubmissionFile $dependentFile, SubmissionFile $newParentFile): void
    {
        $fileService = app()->get('file');
        $oldFile = $fileService->get($dependentFile->getData('fileId'));
        $extension = pathinfo($oldFile->path, PATHINFO_EXTENSION);

        $newFileId = $fileService->add(
            Config::getVar('files', 'files_dir') . '/' . $oldFile->path,
            $this->getSubmissionDir($newParentFile) . '/' . uniqid() . ($extension ? '.' . $extension : '')
        );

        $newDependentFile = clone $dependentFile;
        $newDependentFile->setData('id', null);
        $newDependentFile->setData('fileId', $newFileId);
        $newDependentFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
        $newDependentFile->setData('assocId', $newParentFile->getId());

        Repo::submissionFile()->add($newDependentFile);
    }

    /**
     * Store new content for a submission file as a new revision of it.
     */
    protected function replaceFileContent(SubmissionFile $submissionFile, string $content): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'xmb_');
        try {
            file_put_contents($tempPath, $content);
            $newFileId = app()->get('file')->add(
                $tempPath,
                $this->getSubmissionDir($submissionFile) . '/' . uniqid() . '.xml'
            );
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        Repo::submissionFile()->edit($submissionFile, ['fileId' => $newFileId]);
    }

    protected function getSubmissionDir(SubmissionFile $submissionFile): string
    {
        $submission = Repo::submission()->get((int) $submissionFile->getData('submissionId'));

        return Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
    }

    /**
     * Leave a trace in the submission activity log.
     */
    protected function logEnrichment(SubmissionFile $jatsFile, string $reason): void
    {
        $logEntry = Repo::eventLog()->newDataObject([
            'assocType' => Application::ASSOC_TYPE_SUBMISSION,
            'assocId' => $jatsFile->getData('submissionId'),
            'eventType' => PKPSubmissionEventLogEntry::SUBMISSION_LOG_METADATA_UPDATE,
            'userId' => Validation::loggedInAs() ?? Application::get()->getRequest()->getUser()?->getId(),
            'message' => 'plugins.generic.XMLMetadataBuilder.log.enriched.' . $reason,
            'isTranslated' => false,
            'dateLogged' => Core::getCurrentDate(),
        ]);
        Repo::eventLog()->add($logEntry);
    }

    /**
     * Normalize XML to compare documents ignoring formatting.
     */
    protected function normalize(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            return $xml;
        }
        return $dom->C14N() ?: $xml;
    }
}
