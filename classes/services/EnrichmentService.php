<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes\services;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\XMLMetadataBuilder\classes\XMLMetadataProcessor;
use PKP\submissionFile\SubmissionFile;
use PKP\publication\Publication;

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
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
            ->getMany();
        
        // Filter only XML files
        $xmlFiles = [];
        foreach ($submissionFiles as $submissionFile) {
            $mimeType = $submissionFile->getData('mimetype');
            // Check mimetype OR extension to be safe
            if (in_array($mimeType, ['application/xml', 'text/xml']) || 
                preg_match('/\.xml$/i', $submissionFile->getData('originalFileName'))) {
                $xmlFiles[] = $submissionFile;
            }
        }
        
        return $xmlFiles;
    }

    /**
     * Enrich an XML file with metadata from OJS
     *
     * @param int|array $fileId Single file ID or array of file IDs (multilingual)
     * @param Publication $publication The publication containing the metadata
     * @param array $options Options for enrichment: ['suffix' => string, 'overwrite' => bool, 'createGalley' => bool]
     * @return void
     * @throws \Exception
     */
    public function enrich($fileId, $publication, array $options = [])
    {
        $suffix = $options['suffix'] ?? self::DEFAULT_SUFFIX;
        $overwrite = $options['overwrite'] ?? false;
        $createGalley = $options['createGalley'] ?? true;
                
        // Handle multilingual file IDs
        if (is_array($fileId)) {
            foreach ($fileId as $localeKey => $id) {
                if (empty($id)) {
                    continue;
                }
                
                $this->enrichSingleFile($id, $publication, $suffix, $overwrite, $createGalley);
            }
        } else {
            // Single file ID
            $this->enrichSingleFile($fileId, $publication, $suffix, $overwrite, $createGalley);
        }
    }
    
    /**
     * Enrich a single XML file
     *
     * @param int $fileId
     * @param Publication $publication
     * @param string $suffix
     * @param bool $overwrite
     * @param bool $createGalley
     * @return void
     * @throws \Exception
     */
    protected function enrichSingleFile($fileId, $publication, $suffix, $overwrite, $createGalley = true)
    {
        
        // Get the submission file
        $file = Repo::submissionFile()->get($fileId);
        if (!$file) {
            throw new \Exception('Submission file not found: ' . $fileId);
        }
        
        $submissionId = $file->getData('submissionId');
        $submission = Repo::submission()->get($submissionId);
        
        if (!$submission) {
            throw new \Exception('Submission not found for file: ' . $fileId);
        }
        
        $contents = $this->readFileContent($file);
        
        // Get dependent files BEFORE enrichment so we can copy them to new files
        $dependentFiles = $this->getDependentFiles($fileId);
        $dependentFileCount = count($dependentFiles);
        
        // Enrich the XML using PluginMetadataProcessor
        $newXml = XMLMetadataProcessor::enrichFront($contents, $submission, $publication, null, $fileId);
        
        // Create temporary file
        $tempFilePath = tempnam(sys_get_temp_dir(), 'xml_enricher_');
        try {
            file_put_contents($tempFilePath, $newXml);
            
            if ($overwrite) {
                // CASE 1: Overwrite Source File in Production
                $targetFile = $file;
                $newFilename = $file->getLocalizedData('name');
                
                // Set uploader
                $request = Application::get()->getRequest();
                $targetFile->setUploaderUserId($request->getUser()->getId());
                
                // Upload new content to storage
                $destinationPath = $file->getData('path');
                $dir = dirname($destinationPath);
                $newPath = $dir . '/' . $newFilename;
                
                
                $fileService = \APP\core\Services::get('file');
                $uploadedFileId = $fileService->add($tempFilePath, $newPath);
                
                $targetFile->setData('fileId', $uploadedFileId);
                $targetFile->setData('path', $newPath);
                
                Repo::submissionFile()->edit($targetFile, ['fileId', 'path', 'uploaderUserId']);
                
                // If createGalley is also enabled, create a separate copy in Galeradas
                if ($createGalley) {
                    
                    $locale = $submission->getLocale() ?: $publication->getData('locale') ?: 'en';
                    $originalName = $file->getLocalizedData('name');
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $galleyFilename = $baseName . $suffix . '.xml';
                    
                    $now = \PKP\core\Core::getCurrentDate();
                    
                    $proofFileId = $this->createEnrichedFile(
                        $submissionId,
                        $publication,
                        $file,
                        $galleyFilename,
                        $locale,
                        $tempFilePath,
                        SubmissionFile::SUBMISSION_FILE_PROOF,
                        $request->getUser()->getId(),
                        $now,
                        'galerada'
                    );
                    
                    // Copy dependent files to the PROOF file
                    if (!empty($dependentFiles)) {
                        foreach ($dependentFiles as $dependentFile) {
                            $this->copyDependentFile($dependentFile, $proofFileId, $request->getUser()->getId());
                        }
                    }
                    
                    // Create Galley for the PROOF file (Galerada)
                    if ($publication) {
                        $this->createGalley($proofFileId, $publication, $suffix);
                    }
                    
                }
            } else {
                // CASE 2: Create New Independent Files
                
                $locale = $submission->getLocale() ?: $publication->getData('locale') ?: 'en';
                $originalName = $file->getLocalizedData('name');
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $newFilename = $baseName . $suffix . '.xml';
                
                $request = Application::get()->getRequest();
                $now = \PKP\core\Core::getCurrentDate();
                
                // Create PRODUCTION file
                $productionFileId = $this->createEnrichedFile(
                    $submissionId,
                    $publication,
                    $file,
                    $newFilename,
                    $locale,
                    $tempFilePath,
                    SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
                    $request->getUser()->getId(),
                    $now,
                    'produccion'
                );
                
                // Copy dependent files to the PRODUCTION file
                if (!empty($dependentFiles)) {
                    foreach ($dependentFiles as $dependentFile) {
                        $this->copyDependentFile($dependentFile, $productionFileId, $request->getUser()->getId());
                    }
                }
                
                $proofFileId = null;
                
                // Create GALERADA (PROOF) file ONLY if requested
                if ($createGalley) {
                    $proofFileId = $this->createEnrichedFile(
                        $submissionId,
                        $publication,
                        $file,
                        $newFilename,
                        $locale,
                        $tempFilePath,
                        SubmissionFile::SUBMISSION_FILE_PROOF,
                        $request->getUser()->getId(),
                        $now,
                        'galerada'
                    );
                    
                    // Copy dependent files to the PROOF file
                    if (!empty($dependentFiles)) {
                        foreach ($dependentFiles as $dependentFile) {
                            $this->copyDependentFile($dependentFile, $proofFileId, $request->getUser()->getId());
                        }
                    }
                    
                    // Create Galley for the PROOF file (Galerada)
                    if ($publication) {
                        $this->createGalley($proofFileId, $publication, $suffix);
                    }
                    
                } 
            }
            
        } finally {
            // Cleanup temp file
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }
    
    /**
     * Create a new enriched file in the specified stage
     *
     * @param int $submissionId
     * @param Publication $publication
     * @param SubmissionFile $sourceFile
     * @param string $filename
     * @param string $locale
     * @param string $tempFilePath Path to temporary file with enriched content
     * @param int $fileStage
     * @param int $uploaderUserId
     * @param string $timestamp
     * @param string $logLabel Label for logging purposes
     * @return int The created file ID
     */
    protected function createEnrichedFile(
        $submissionId,
        $publication,
        $sourceFile,
        $filename,
        $locale,
        $tempFilePath,
        $fileStage,
        $uploaderUserId,
        $timestamp,
        $logLabel
    ) {
        // Create new file object
        $newFile = Repo::submissionFile()->newDataObject();
        $newFile->setData('submissionId', $submissionId);
        $newFile->setFileStage($fileStage);
        $newFile->setGenreId($sourceFile->getGenreId());
        $newFile->setData('name', $filename, $locale);
        $newFile->setData('mimetype', $sourceFile->getData('mimetype'));
        // Do NOT copy assocType/assocId to ensure independence from source Galley
        // $newFile->setData('assocType', $sourceFile->getData('assocType'));
        // $newFile->setData('assocId', $sourceFile->getData('assocId'));
        $newFile->setUploaderUserId($uploaderUserId);
        $newFile->setData('createdAt', $timestamp);
        $newFile->setData('updatedAt', $timestamp);
        
        if ($publication) {
            $newFile->setData('publicationId', $publication->getId());
        }
        
        // Generate unique path for this file
        $destinationPath = $sourceFile->getData('path');
        $dir = dirname($destinationPath);
        
        // Sanitize filename to prevent path traversal
        // Strip any directory separators and dangerous characters, keep only safe chars
        $safeBasename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
        if (empty($safeBasename)) {
            $safeBasename = 'enriched_' . time();
        }
        // Make the filename unique by appending stage identifier
        $stageLabel = ($fileStage === SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY) ? 'production' : 'proof';
        $uniqueFilename = $safeBasename . '-' . $stageLabel . '.xml';
        $newPath = $dir . '/' . $uniqueFilename;
                
        // Upload file to storage
        $fileService = \APP\core\Services::get('file');
        $uploadedFileId = $fileService->add($tempFilePath, $newPath);
        
        $newFile->setData('fileId', $uploadedFileId);
        $newFile->setData('path', $newPath);
        
        // Save to database
        $savedFile = Repo::submissionFile()->add($newFile, null);
        $savedFileId = is_numeric($savedFile) ? $savedFile : $savedFile->getId();
                
        return $savedFileId;
    }
    
    /**
     * Create a galley for an enriched file
     *
     * @param int $submissionFileId
     * @param Publication $publication
     * @param string $suffix
     * @return void
     */
    protected function createGalley($submissionFileId, $publication, $suffix)
    {        
        // Create galley
        $newGalley = Repo::galley()->newDataObject();
        $newGalley->setData('publicationId', $publication->getId());
        $newGalley->setData('label', 'XML Enriched');
        $newGalley->setData('locale', $publication->getData('locale') ?: 'en');
        $newGalley->setData('submissionFileId', $submissionFileId);

        
        $galleyId = Repo::galley()->add($newGalley);
    }
    
    /**
     * Delete all galleys associated with a submission file
     * This should be called before deleting the file to avoid foreign key constraint errors
     *
     * @param int $submissionFileId
     * @return void
     */
    public function deleteGalleys($submissionFileId)
    {
        
        // Get the submission file to find its submission
        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        if (!$submissionFile) {
            return;
        }
        
        $submissionId = $submissionFile->getData('submissionId');
        if (!$submissionId) {
            return;
        }
        
        // Get the submission to access all its publications
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return;
        }
        
        // Get all publications for this submission
        $publications = Repo::publication()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->getMany();
        
        $deletedCount = 0;
        
        // Search through all publications to find galleys referencing this file
        foreach ($publications as $publication) {
            $galleys = Repo::galley()
                ->getCollector()
                ->filterByPublicationIds([$publication->getId()])
                ->getMany();
            
            // Find and delete galleys that reference this submission file
            foreach ($galleys as $galley) {
                if ($galley->getData('submissionFileId') == $submissionFileId) {
                    Repo::galley()->delete($galley);
                    $deletedCount++;
                }
            }
        }
    }
    
    /**
     * Get the physical file path from a SubmissionFile object
     *
     * @param SubmissionFile $file
     * @return string|null
     */
    protected function getFilePath($file)
    {
        $filesDir = \PKP\config\Config::getVar('files', 'files_dir');
        $path = null;
        
        if (method_exists($file, 'getFilePath')) {
            $path = $file->getFilePath();
        } elseif (method_exists($file, 'getPath')) {
            $path = $file->getPath();
        } else {
            // Fallback for OJS 3.4+ where file path might be stored differently
            $path = $file->getData('path');
            if ($path && $filesDir && strpos($path, '/') !== 0) {
                // Ensure we don't double-slash if files_dir ends with /
                $path = rtrim($filesDir, '/') . '/' . ltrim($path, '/');
            }
        }

        return $path;
    }
    
    /**
     * Read the contents of a SubmissionFile, supporting both Flysystem (OJS 3.4+)
     * and direct physical file paths as fallback.
     *
     * @param SubmissionFile $file
     * @return string
     * @throws \Exception
     */
    public function readFileContent($file)
    {
        $fileService = \APP\core\Services::get('file');
        $contents = null;
        
        try {
            if (method_exists($fileService, 'read')) {
                // Read via Flysystem (OJS 3.4+ S3 compatibility)
                $contents = $fileService->read($file->getData('path'));
            }
        } catch (\Exception $e) {
            // Silently fall back to legacy method if Flysystem read fails
        }

        if ($contents === null) {
            $path = $this->getFilePath($file);
            if (!$path || !file_exists($path)) {
                throw new \Exception('No se pudo acceder al archivo en el almacenamiento: ' . $file->getId());
            }
            // Fallback to direct physical read
            $contents = file_get_contents($path);
        }
        
        return $contents;
    }
    
    /**
     * Get enriched XML content without saving to file
     * 
     * @param int $fileId ID of the XML file to enrich
     * @return string The enriched XML content
     * @throws \Exception
     */
    public function getEnrichedXmlContent($fileId)
    {
        // Get the submission file
        $file = Repo::submissionFile()->get($fileId);
        if (!$file) {
            throw new \Exception('Archivo no encontrado: ' . $fileId);
        }
        
        $submissionId = $file->getData('submissionId');
        $submission = Repo::submission()->get($submissionId);
        
        if (!$submission) {
            throw new \Exception('Submission no encontrado para el archivo: ' . $fileId);
        }
        
        // Get current publication
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            throw new \Exception('Publicación no encontrada para el submission: ' . $submissionId);
        }
        
        // Read original XML content
        $contents = $this->readFileContent($file);
        
        // Enrich the XML using XMLMetadataProcessor
        $enrichedXml = XMLMetadataProcessor::enrichFront($contents, $submission, $publication, null, $fileId);
        
        return $enrichedXml;
    }
    
    /**
     * Extract the front element from an XML file
     *
     * @param int $fileId ID of the XML file
     * @return string The front element as XML string
     * @throws \Exception
     */
    public function extractFrontElement($fileId)
    {
        
        // Get the submission file
        $file = Repo::submissionFile()->get($fileId);
        if (!$file) {
            throw new \Exception('Archivo no encontrado: ' . $fileId);
        }
        
        // Read XML content
        $contents = $this->readFileContent($file);
        
        // Parse XML and extract front element
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
        
        // Suppress warnings for malformed XML
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($contents);
        libxml_clear_errors();
        
        if (!$loaded) {
            throw new \Exception('Error al parsear el archivo XML');
        }
        
        // Find front element
        $frontNodes = $dom->getElementsByTagName('front');
        if ($frontNodes->length === 0) {
            throw new \Exception('No se encontró el elemento <front> en el XML');
        }
        
        $frontNode = $frontNodes->item(0);
        
        // Export front element to string
        $frontXml = $dom->saveXML($frontNode);
        
        return $frontXml;
    }
    
    /**
     * Get all dependent files associated with a parent submission file
     * 
     * Dependent files in OJS are files that cannot exist independently and are
     * linked to a main file (e.g., images, stylesheets for an XML/HTML file).
     * They have fileStage = SUBMISSION_FILE_DEPENDENT (17) and are linked to
     * their parent via assocType = ASSOC_TYPE_SUBMISSION_FILE and assocId = parent file ID.
     *
     * @param int $parentFileId ID of the parent submission file
     * @return array Array of SubmissionFile objects that are dependent files of this specific parent
     */
    protected function getDependentFiles($parentFileId)
    {
        $parentFile = Repo::submissionFile()->get($parentFileId);
        if (!$parentFile) {
            return [];
        }
        
        $submissionId = $parentFile->getData('submissionId');
        
        // Get all dependent files for this submission
        $allDependentFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->getMany();
        
        // Filter to get ONLY the dependent files that belong to this specific parent
        // Check assocType = ASSOC_TYPE_SUBMISSION_FILE (515) and assocId = parentFileId
        $filteredDependents = [];
        foreach ($allDependentFiles as $dependentFile) {
            $assocType = $dependentFile->getData('assocType');
            $assocId = $dependentFile->getData('assocId');
            
            // Check if this dependent file is linked to our parent file
            if ($assocType == 0x0000203 && $assocId == $parentFileId) {
                $filteredDependents[] = $dependentFile;
            }
        }
        
        return $filteredDependents;
    }
    
    /**
     * Copy a dependent file and link it to a new parent file
     *
     * @param SubmissionFile $sourceDependentFile The original dependent file to copy
     * @param int $newParentFileId ID of the new parent file (enriched XML)
     * @param int $uploaderUserId ID of the user performing the copy
     * @return int|null The new dependent file ID, or null on failure
     */
    protected function copyDependentFile($sourceDependentFile, $newParentFileId, $uploaderUserId)
    {
        try {
            // Get the new parent file to extract necessary information
            $newParentFile = Repo::submissionFile()->get($newParentFileId);
            if (!$newParentFile) {
                return null;
            }
            
            // Read content from source to ensure Flysystem compatibility
            $sourceContents = $this->readFileContent($sourceDependentFile);
            if ($sourceContents === null) {
                return null;
            }
            
            // Create new SubmissionFile object for the dependent file
            $newDependentFile = Repo::submissionFile()->newDataObject();
            $newDependentFile->setData('submissionId', $newParentFile->getData('submissionId'));
            $newDependentFile->setFileStage(SubmissionFile::SUBMISSION_FILE_DEPENDENT);
            $newDependentFile->setGenreId($sourceDependentFile->getGenreId());
            $newDependentFile->setData('mimetype', $sourceDependentFile->getData('mimetype'));
            $newDependentFile->setUploaderUserId($uploaderUserId);
            
            // Copy localized name from source
            $locale = $sourceDependentFile->getData('locale') ?: 'en';
            $originalName = $sourceDependentFile->getLocalizedData('name');
            $newDependentFile->setData('name', $originalName, $locale);
            
            // Set timestamps
            $now = \PKP\core\Core::getCurrentDate();
            $newDependentFile->setData('createdAt', $now);
            $newDependentFile->setData('updatedAt', $now);
            
            // Link to the same publication as the parent
            $publicationId = $newParentFile->getData('publicationId');
            if ($publicationId) {
                $newDependentFile->setData('publicationId', $publicationId);
            }
            
            // CRITICAL: Link this dependent file to its parent file
            // This is what makes OJS recognize it as a dependent of the parent
            // assocType = ASSOC_TYPE_SUBMISSION_FILE (0x0000203 = 515 in decimal)
            // assocId = ID of the parent file
            $newDependentFile->setData('assocType', 0x0000203); // ASSOC_TYPE_SUBMISSION_FILE
            $newDependentFile->setData('assocId', $newParentFileId);
            
            // Generate unique path for the dependent file
            $dir = dirname($newParentFile->getData('path'));

            // Sanitize filename to prevent path traversal
            $safeBasename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            if (empty($safeBasename)) {
                $safeBasename = 'dependent_' . time();
            }
            $extension = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($originalName, PATHINFO_EXTENSION));
            $uniqueName = $safeBasename . '-' . time() . ($extension ? '.' . $extension : '');
            $newPath = $dir . '/' . $uniqueName;
            
            // Copy the file to storage (use temporary file for Flysystem compatibility)
            $tempFilePath = tempnam(sys_get_temp_dir(), 'xml_dep_');
            file_put_contents($tempFilePath, $sourceContents);
            
            try {
                $fileService = \APP\core\Services::get('file');
                $uploadedFileId = $fileService->add($tempFilePath, $newPath);
            } finally {
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            }
            
            $newDependentFile->setData('fileId', $uploadedFileId);
            $newDependentFile->setData('path', $newPath);
            
            // Save to database
            $savedFile = Repo::submissionFile()->add($newDependentFile, null);
            $savedFileId = is_numeric($savedFile) ? $savedFile : $savedFile->getId();
            
            return $savedFileId;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Public wrapper for getDependentFiles - used by download handler
     * 
     * @param int $parentFileId ID of the parent submission file
     * @return array Array of SubmissionFile objects that are dependent files
     */
    public function getDependentFilesPublic($parentFileId)
    {
        return $this->getDependentFiles($parentFileId);
    }
    
    /**
     * Public wrapper for getFilePath - used by download handler
     * 
     * @param SubmissionFile $file
     * @return string|null File path
     */
    public function getFilePathPublic($file)
    {
        return $this->getFilePath($file);
    }
}


