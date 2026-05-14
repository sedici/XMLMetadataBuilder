<?php

import('lib.pkp.classes.submission.SubmissionFile');
import('classes.core.Application');

/**
 * EnrichmentService
 * 
 * Centralizes all XML enrichment business logic.
 * Handles file enrichment, galley creation, and cleanup operations.
 */
class EnrichmentService
{
    // Constants for Plugin Settings
    public const SETTING_XML_FILE_ID = 'XMLMetadataBuilder::xmlFileId';
    public const SETTING_SUFFIX = 'XMLMetadataBuilder::suffix';
    public const SETTING_OVERWRITE = 'XMLMetadataBuilder::overwrite';
    public const SETTING_CREATE_GALLEY = 'XMLMetadataBuilder::createGalley';
    public const DEFAULT_SUFFIX = '-enriched';

    /**
     * Get all production-ready XML files for a submission
     * 
     * @param int $submissionId
     * @return array List of SubmissionFile objects
     */
    public static function getProductionXmlFiles($submissionId)
    {
        // Get production-ready files
        $submissionFiles = \Services::get('submissionFile')->getMany([
            'submissionIds' => [$submissionId],
            'fileStages' => [SUBMISSION_FILE_PRODUCTION_READY]
        ]);
        
        // Filter only XML files
        $xmlFiles = [];
        foreach ($submissionFiles as $submissionFile) {
            $mimeType = $submissionFile->getData('mimetype');
            // Check mimetype OR extension to be safe
            if (in_array($mimeType, ['application/xml', 'text/xml']) || 
                preg_match('/\.xml$/i', $submissionFile->getData('originalFileName') ?: $submissionFile->getLocalizedData('name'))) {
                $xmlFiles[] = $submissionFile;
            }
        }
        
        return $xmlFiles;
    }

    /**
     * Enrich an XML file with metadata from OJS
     */
    public function enrich($fileId, $publication, array $options = [])
    {
        $suffix = $options['suffix'] ?? self::DEFAULT_SUFFIX;
        $overwrite = $options['overwrite'] ?? false;
        $createGalley = $options['createGalley'] ?? true;
                
        if (is_array($fileId)) {
            foreach ($fileId as $localeKey => $id) {
                if (empty($id)) continue;
                $this->enrichSingleFile($id, $publication, $suffix, $overwrite, $createGalley);
            }
        } else {
            $this->enrichSingleFile($fileId, $publication, $suffix, $overwrite, $createGalley);
        }
    }
    
    protected function enrichSingleFile($fileId, $publication, $suffix, $overwrite, $createGalley = true)
    {
        $file = \Services::get('submissionFile')->get($fileId);
        if (!$file) {
            throw new \Exception('Submission file not found: ' . $fileId);
        }
        
        $submissionId = $file->getData('submissionId');
        $submission = \Services::get('submission')->get($submissionId);
        
        if (!$submission) {
            throw new \Exception('Submission not found for file: ' . $fileId);
        }
        
        $contents = $this->readFileContent($file);
        $dependentFiles = $this->getDependentFiles($fileId);
        
        require_once dirname(__FILE__) . '/../XMLMetadataProcessor.php';
        $newXml = XMLMetadataProcessor::enrichFront($contents, $submission, $publication, null, $fileId);
        
        $tempFilePath = tempnam(sys_get_temp_dir(), 'xml_enricher_');
        try {
            file_put_contents($tempFilePath, $newXml);
            $request = Application::get()->getRequest();
            
            if ($overwrite) {
                import('lib.pkp.classes.file.FileManager');
                $fileManager = new \FileManager();
                $fileManager->copyFile($tempFilePath, $this->getFilePath($file));
                
                $file->setUploaderUserId($request->getUser()->getId());
                $file->setData('updatedAt', Core::getCurrentDate());
                \DAORegistry::getDAO('SubmissionFileDAO')->updateObject($file);
                
                if ($createGalley) {
                    $locale = $submission->getLocale() ?: $publication->getData('locale') ?: 'en';
                    $originalName = $file->getLocalizedData('name');
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $galleyFilename = $baseName . $suffix . '.xml';
                    
                    $proofFileId = $this->createEnrichedFile(
                        $submissionId, $publication, $file, $galleyFilename, $locale, $tempFilePath,
                        SUBMISSION_FILE_PROOF, $request->getUser()->getId()
                    );
                    
                    if (!empty($dependentFiles)) {
                        foreach ($dependentFiles as $dependentFile) {
                            $this->copyDependentFile($dependentFile, $proofFileId, $request->getUser()->getId());
                        }
                    }
                    
                    if ($publication) {
                        $this->createGalley($proofFileId, $publication, $suffix);
                    }
                }
            } else {
                $locale = $submission->getLocale() ?: $publication->getData('locale') ?: 'en';
                $originalName = $file->getLocalizedData('name');
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $newFilename = $baseName . $suffix . '.xml';
                
                $productionFileId = $this->createEnrichedFile(
                    $submissionId, $publication, $file, $newFilename, $locale, $tempFilePath,
                    SUBMISSION_FILE_PRODUCTION_READY, $request->getUser()->getId()
                );
                
                if (!empty($dependentFiles)) {
                    foreach ($dependentFiles as $dependentFile) {
                        $this->copyDependentFile($dependentFile, $productionFileId, $request->getUser()->getId());
                    }
                }
                
                if ($createGalley) {
                    $proofFileId = $this->createEnrichedFile(
                        $submissionId, $publication, $file, $newFilename, $locale, $tempFilePath,
                        SUBMISSION_FILE_PROOF, $request->getUser()->getId()
                    );
                    
                    if (!empty($dependentFiles)) {
                        foreach ($dependentFiles as $dependentFile) {
                            $this->copyDependentFile($dependentFile, $proofFileId, $request->getUser()->getId());
                        }
                    }
                    
                    if ($publication) {
                        $this->createGalley($proofFileId, $publication, $suffix);
                    }
                } 
            }
        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }
    
    protected function createEnrichedFile(
        $submissionId, $publication, $sourceFile, $filename, $locale, $tempFilePath,
        $fileStage, $uploaderUserId
    ) {
        $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
        $newFile = $submissionFileDao->newDataObject();
        $newFile->setData('submissionId', $submissionId);
        $newFile->setFileStage($fileStage);
        $newFile->setGenreId($sourceFile->getGenreId());
        $newFile->setData('name', $filename, $locale);
        $newFile->setData('mimetype', $sourceFile->getData('mimetype'));
        $newFile->setUploaderUserId($uploaderUserId);
        $now = Core::getCurrentDate();
        $newFile->setData('createdAt', $now);
        $newFile->setData('updatedAt', $now);
        
        if ($publication) {
            $newFile->setData('publicationId', $publication->getId());
        }
        
        // OJS 3.3 compatibility:
        // 1. Determine destination path
        $submission = \Services::get('submission')->get($submissionId);
        $submissionDir = \Services::get('submissionFile')->getSubmissionDir($submission->getData('contextId'), $submissionId);
        
        // 2. Add to physical storage and get fileId
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'xml';
        $destPath = $submissionDir . '/' . uniqid() . '.' . $extension;
        $fileId = \Services::get('file')->add($tempFilePath, $destPath);
        $newFile->setData('fileId', $fileId);
        
        // 3. Add submission file entry
        $request = \Application::get()->getRequest();
        $savedFile = \Services::get('submissionFile')->add($newFile, $request);
        
        return is_numeric($savedFile) ? $savedFile : $savedFile->getId();
    }
    
    protected function createGalley($submissionFileId, $publication, $suffix)
    {        
        $galleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
        $newGalley = $galleyDao->newDataObject();
        $newGalley->setData('publicationId', $publication->getId());
        $newGalley->setData('label', 'XML Enriched');
        $newGalley->setData('locale', $publication->getData('locale') ?: 'en');
        $newGalley->setData('submissionFileId', $submissionFileId);
        $galleyDao->insertObject($newGalley);
    }
    
    public function deleteGalleys($submissionFileId)
    {
        $submissionFile = \Services::get('submissionFile')->get($submissionFileId);
        if (!$submissionFile) return;
        
        $submissionId = $submissionFile->getData('submissionId');
        if (!$submissionId) return;
        
        $publications = \Services::get('publication')->getMany(['submissionIds' => [$submissionId]]);
        $galleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
        
        foreach ($publications as $publication) {
            $galleys = $galleyDao->getByPublicationId($publication->getId())->toArray();
            foreach ($galleys as $galley) {
                if ($galley->getData('submissionFileId') == $submissionFileId) {
                    $galleyDao->deleteObject($galley);
                }
            }
        }
    }
    
    protected function getFilePath($file)
    {
        if (method_exists($file, 'getFilePath')) {
            return $file->getFilePath();
        } elseif (method_exists($file, 'getPath')) {
            return $file->getPath();
        }
        
        // OJS 3.3 fallback
        $path = $file->getData('path');
        if ($path) {
            import('lib.pkp.classes.config.Config');
            return \Config::getVar('files', 'files_dir') . '/' . $path;
        }
        
        return null;
    }
    
    public function readFileContent($file)
    {
        $path = $this->getFilePath($file);
        if (!$path || !file_exists($path)) {
            throw new \Exception('No se pudo acceder al archivo en el almacenamiento: ' . $file->getId());
        }
        return file_get_contents($path);
    }
    
    public function getEnrichedXmlContent($fileId)
    {
        $file = \Services::get('submissionFile')->get($fileId);
        if (!$file) throw new \Exception('Archivo no encontrado: ' . $fileId);
        
        $submissionId = $file->getData('submissionId');
        $submission = \Services::get('submission')->get($submissionId);
        if (!$submission) throw new \Exception('Submission no encontrado para el archivo: ' . $fileId);
        
        $publication = $submission->getCurrentPublication();
        if (!$publication) throw new \Exception('Publicación no encontrada para el submission: ' . $submissionId);
        
        $contents = $this->readFileContent($file);
        
        require_once dirname(__FILE__) . '/../XMLMetadataProcessor.php';
        return XMLMetadataProcessor::enrichFront($contents, $submission, $publication, null, $fileId);
    }
    
    public function extractFrontElement($fileId)
    {
        $file = \Services::get('submissionFile')->get($fileId);
        if (!$file) throw new \Exception('Archivo no encontrado: ' . $fileId);
        
        $contents = $this->readFileContent($file);
        
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
        
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($contents);
        libxml_clear_errors();
        
        if (!$loaded) throw new \Exception('Error al parsear el archivo XML');
        
        $frontNodes = $dom->getElementsByTagName('front');
        if ($frontNodes->length === 0) throw new \Exception('No se encontró el elemento <front> en el XML');
        
        return $dom->saveXML($frontNodes->item(0));
    }
    
    protected function getDependentFiles($parentFileId)
    {
        $parentFile = \Services::get('submissionFile')->get($parentFileId);
        if (!$parentFile) return [];
        
        $submissionId = $parentFile->getData('submissionId');
        
        $allDependentFiles = \Services::get('submissionFile')->getMany([
            'submissionIds' => [$submissionId],
            'fileStages' => [SUBMISSION_FILE_DEPENDENT]
        ]);
        
        $filteredDependents = [];
        foreach ($allDependentFiles as $dependentFile) {
            $assocType = $dependentFile->getData('assocType');
            $assocId = $dependentFile->getData('assocId');
            
            // assocType = ASSOC_TYPE_SUBMISSION_FILE (515)
            if ($assocType == 515 && $assocId == $parentFileId) {
                $filteredDependents[] = $dependentFile;
            }
        }
        
        return $filteredDependents;
    }
    
    protected function copyDependentFile($sourceDependentFile, $newParentFileId, $uploaderUserId)
    {
        try {
            $newParentFile = \Services::get('submissionFile')->get($newParentFileId);
            if (!$newParentFile) return null;
            
            $sourceContents = $this->readFileContent($sourceDependentFile);
            if ($sourceContents === null) return null;
            
            $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
            $newDependentFile = $submissionFileDao->newDataObject();
            $newDependentFile->setData('submissionId', $newParentFile->getData('submissionId'));
            $newDependentFile->setFileStage(SUBMISSION_FILE_DEPENDENT);
            $newDependentFile->setGenreId($sourceDependentFile->getGenreId());
            $newDependentFile->setData('mimetype', $sourceDependentFile->getData('mimetype'));
            $newDependentFile->setUploaderUserId($uploaderUserId);
            
            $locale = $sourceDependentFile->getData('locale') ?: 'en';
            $originalName = $sourceDependentFile->getLocalizedData('name');
            $newDependentFile->setData('name', $originalName, $locale);
            
            $now = Core::getCurrentDate();
            $newDependentFile->setData('createdAt', $now);
            $newDependentFile->setData('updatedAt', $now);
            
            $publicationId = $newParentFile->getData('publicationId');
            if ($publicationId) {
                $newDependentFile->setData('publicationId', $publicationId);
            }
            
            $newDependentFile->setData('assocType', 515); // ASSOC_TYPE_SUBMISSION_FILE
            $newDependentFile->setData('assocId', $newParentFileId);
            
            $tempFilePath = tempnam(sys_get_temp_dir(), 'xml_dep_');
            file_put_contents($tempFilePath, $sourceContents);
            
            try {
                // OJS 3.3 compatibility
                $submission = \Services::get('submission')->get($newParentFile->getData('submissionId'));
                $submissionDir = \Services::get('submissionFile')->getSubmissionDir($submission->getData('contextId'), $submission->getId());
                
                $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'dat';
                $destPath = $submissionDir . '/' . uniqid() . '.' . $extension;
                
                $fileId = \Services::get('file')->add($tempFilePath, $destPath);
                $newDependentFile->setData('fileId', $fileId);
                
                $request = \Application::get()->getRequest();
                $savedFile = \Services::get('submissionFile')->add($newDependentFile, $request);
                
                return is_numeric($savedFile) ? $savedFile : $savedFile->getId();
            } finally {
                if (file_exists($tempFilePath)) unlink($tempFilePath);
            }
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function getDependentFilesPublic($parentFileId)
    {
        return $this->getDependentFiles($parentFileId);
    }
    
    public function getFilePathPublic($file)
    {
        return $this->getFilePath($file);
    }
}
