<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes;

use PKP\submission\PKPSubmission;
use PKP\context\Context;
use PKP\submission\Genre;
use PKP\submissionFile\SubmissionFile;
use PKP\services\PKPAuthorService;

/**
 * MetadataExtractor
 * 
 * Extrae y normaliza todos los metadatos necesarios del Submission
 * para construir la sección <front> del JATS/XML.
 */
class MetadataExtractor
{

    /**
     * Punto de entrada principal
     */
    public function extract(PKPSubmission $submission, Context $context, $parentFileId = null): array
    {        
        $authors = $this->extractAuthors($submission);
        $affiliations = $this->processAffiliations($authors);
        
        $result = [
            'journal'     => $this->extractJournal($context),
            'article'     => $this->extractArticle($submission),
            'authors'     => $authors,
            'affiliations'=> $affiliations,
            'sections'    => $this->extractSections($submission, $context),
            'pubDates'    => $this->extractPublicationDates($submission),
            'permissions' => $this->extractPermissions($submission),
            'supplementaryMaterials' => $this->extractSupplementaryMaterials($submission, $parentFileId),
        ];

        return $result;
    }

    /**
     * Datos de la revista
     */
    protected function extractJournal(Context $context): array
    {
        return [
            'id'          => $context->getData('urlPath'),  // Use journal path (e.g., "resaa") not numeric ID
            'title'       => $context->getLocalizedName(),
            'abbrev'      => $context->getLocalizedAcronym(),
            'publisher'   => $context->getData('publisherInstitution'),
            'publisherEmail' => $context->getData('contactEmail'),  // Publisher contact email for <publisher-loc>
            'onlineIssn'  => $context->getData('onlineIssn'),  // Electronic ISSN (epub)
            'printIssn'   => $context->getData('printIssn'),   // Print ISSN (ppub)
            'url'         => $context->getData('urlPath'),
        ];
    }

    /**
     * Datos del artículo
     */
    protected function extractArticle(PKPSubmission $submission): array
    {
        $publication = $submission->getCurrentPublication();
        $issueId = $publication->getData('issueId');
        
        // Extract volume/issue information if available
        $volume = null;
        $issue = null;
        $issueYear = null;
        
        if ($issueId) {
            $issueObj = \APP\facades\Repo::issue()->get($issueId);
            if ($issueObj) {
                $volume = $issueObj->getVolume();
                $issue = $issueObj->getNumber();
                $issueYear = $issueObj->getYear();
            }
        }
        
        // Extract page information
        // JATS 1.4: Support both traditional page ranges (fpage/lpage) and electronic location identifiers (elocation-id)
        $pages = $publication->getData('pages');
        $firstPage = null;
        $lastPage = null;
        $elocationId = null;
        
        if ($pages && is_string($pages)) {
            $trimmedPages = trim($pages);
            
            // Check for traditional numeric page ranges like "123-145" or "123"
            if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/', $trimmedPages, $matches)) {
                // Range of pages
                $firstPage = $matches[1];
                $lastPage = $matches[2];
            } elseif (preg_match('/^(\d+)$/', $trimmedPages, $matches)) {
                // Single page
                $firstPage = $matches[1];
                $lastPage = $matches[1];
            } else {
                // Check for electronic location identifier (e.g., "e180", "E70", "e12345")
                // JATS 1.4: elocation-id is used for electronic-only articles without traditional page numbers
                // Pattern: letter(s) followed by digits, or any alphanumeric identifier that's not purely numeric
                if (preg_match('/^[a-zA-Z]\d+$/i', $trimmedPages) || 
                    (!preg_match('/^\d+$/', $trimmedPages) && !empty($trimmedPages))) {
                    $elocationId = $trimmedPages;
                }
            }
        }
        
        // Construct article public URL for self-uri element
        $articleUrl = null;
        try {
            $request = \APP\core\Application::get()->getRequest();
            if ($request) {
                $dispatcher = $request->getDispatcher();
                $articleUrl = $dispatcher->url(
                    $request,
                    \PKP\core\PKPApplication::ROUTE_PAGE,
                    null,
                    'article',
                    'view',
                    [$submission->getBestId()]
                );
            }
        } catch (\Throwable $e) {
            // Fallback: construct URL from DOI if available
            if ($publication->getDoi()) {
                $articleUrl = 'https://doi.org/' . $publication->getDoi();
            }
        }
        
        return [
            'title'       => $this->cleanTitleData($publication->getData('title') ?? []),        // Array multilingüe: ['es_ES' => 'Título', 'en_US' => 'Title']
            'subtitle'    => $this->cleanTitleData($publication->getData('subtitle') ?? []),     // Array multilingüe
            'primaryLocale' => $publication->getData('locale'),           // Idioma principal de la publicación
            'doi'         => $publication->getDoi(),
            'abstract'    => $publication->getData('abstract') ?? [],     // Array multilingüe
            'keywords'    => $publication->getData('keywords') ?? [],     // Array multilingüe: ['es_ES' => ['kw1', 'kw2'], 'en_US' => ['kw1', 'kw2']]
            'pages'       => $pages,
            'firstPage'   => $firstPage,
            'lastPage'    => $lastPage,
            'elocationId' => $elocationId,
            'volume'      => $volume,
            'issue'       => $issue,
            'issueYear'   => $issueYear,
            'articleUrl'  => $articleUrl,  // For self-uri element
            'languages'   => $publication->getData('locale'),
            'issueId'     => $issueId,
            'submissionId'=> $submission->getId(),
        ];
    }

    /**
     * Limpia etiquetas HTML (especialmente etiquetas <i>, <em>, etc.) de títulos y subtítulos.
     */
    protected function cleanTitleData($data)
    {
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $val) {
                $cleaned[$key] = $this->cleanTitleData($val);
            }
            return $cleaned;
        }
        if (is_string($data)) {
            return trim(strip_tags($data));
        }
        return $data;
    }



    /**
     * Procesa las afiliaciones de los autores para extraer únicas y asignar IDs
     * 
     * @param array $authors Referencia al array de autores para inyectar affiliationId
     * @return array Lista de afiliaciones únicas con ID
     */
    protected function processAffiliations(array &$authors): array
    {
        $affiliationsMap = []; // 'Affiliation String' => 'aff1'
        $affiliationsList = []; // [['id' => 'aff1', 'name' => 'Affiliation String', 'country' => 'Country']]
        $nextId = 1;
        
        // First pass: build affiliations map with country info
        $affCountryMap = []; // Track country for each affiliation string
        foreach ($authors as $author) {
            $affString = $author['affiliation'] ?? '';
            $country = $author['country'] ?? '';
            
            if (!empty($affString) && trim($affString) !== '') {
                // Associate country with this affiliation if we have it
                if (!isset($affCountryMap[$affString]) && !empty($country)) {
                    $affCountryMap[$affString] = $country;
                }
            }
        }
        
        // Second pass: assign IDs and build affiliations list
        foreach ($authors as &$author) {
            $affString = $author['affiliation'] ?? '';
            
            // Only process non-empty affiliations
            if (!empty($affString) && trim($affString) !== '') {
                if (!isset($affiliationsMap[$affString])) {
                    $id = 'aff' . $nextId++;
                    $affiliationsMap[$affString] = $id;
                    $affiliationsList[] = [
                        'id' => $id, 
                        'name' => $affString,
                        'country' => $affCountryMap[$affString] ?? null, // Add country if available
                    ];
                }
                $author['affiliationId'] = $affiliationsMap[$affString];
            } else {
                $author['affiliationId'] = null;
            }
        }
        
        return $affiliationsList;
    }

    /**
     * Extrae autores del submission
     */
    protected function extractAuthors(PKPSubmission $submission): array
    {
        $publication = $submission->getCurrentPublication();
        $authors = $publication->getData('authors') ?? [];
        $locale = $publication->getData('locale');
        $result = [];

        foreach ($authors as $author) {
            $given = $author->getGivenName($locale);
            $surname = $author->getFamilyName($locale);
            $affiliation = $author->getAffiliation($locale);

            // Diagnostic: Check if data exists in "best match" locale if missing in current locale
            if (empty($surname)) {
                $check = $author->getLocalizedFamilyName();
            }
            if (empty($affiliation)) {
                $check = $author->getLocalizedAffiliation();
            }

            $given = $this->utf8ize($author->getGivenName($locale));
            $surname = $this->utf8ize($author->getFamilyName($locale));
            $affiliation = $this->utf8ize($author->getAffiliation($locale));
            $biography = $this->utf8ize($author->getBiography($locale));

            $result[] = [
                'given'     => $given,
                'surname'   => $surname,
                'email'     => $author->getEmail(), // Email is usually ASCII
                'orcid'     => $author->getOrcid(),
                'affiliation' => $affiliation ?? '',
                'country'     => $author->getCountry(),
                'sequence'    => $author->getSequence(),
                'isPrimary'   => $author->getPrimaryContact(), // For author-notes (corresponding author)
                'biography'   => $biography, // For <bio> element in <contrib>
            ];
        }

        return $result;
    }

    /**
     * Ensure string is UTF-8 encoded
     */
    private function utf8ize($str) {
        if (is_null($str)) return null;
        if (is_array($str)) {
            $str = reset($str);
            if (!is_string($str)) return '';
        }
        
        // If it's already valid UTF-8, return it
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        
        // Otherwise convert from likely ISO-8859-1
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Extrae la sección (ej: Artículos, Reseñas, Comunicaciones, etc.)
     */
    protected function extractSections(PKPSubmission $submission, Context $context): array
    {
        $sectionId = $submission->getCurrentPublication()->getData('sectionId');
        if (!$sectionId) return ['title' => null, 'abbrev' => null];

        $section = \APP\facades\Repo::section()->get($sectionId);

        return [
            'title'     => $section ? $section->getLocalizedTitle() : null,
            'abbrev'    => $section ? $section->getLocalizedAbbrev() : null,
        ];
    }

    /**
     * Fechas importantes del artículo
     */
    protected function extractPublicationDates(PKPSubmission $submission): array
    {
        $pub = $submission->getCurrentPublication();

        // Get accepted date from editorial decisions
        $acceptedDate = null;
        $decisions = \APP\facades\Repo::decision()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();

        foreach ($decisions as $decision) {
            $stageId = $decision->getData('stageId');
            $decisionType = $decision->getData('decision');
            $dateDecided = $decision->getData('dateDecided');
                        
            // Review stage (stageId=3) and accepted decision (decision=2 in OJS 3.4)
            if ($stageId == 3 && $decisionType == 2) {
                $acceptedDate = $dateDecided;
                break; // Use first acceptance decision found
            }
        }

        return [
            'published' => $pub->getData('datePublished'),
            'submitted' => $submission->getData('dateSubmitted'),
            'accepted'  => $acceptedDate,
            'revised'   => $submission->getData('lastModified'),
        ];
    }

    /**
     * Datos de copyright / licencia
     */
    protected function extractPermissions(PKPSubmission $submission): array
    {
        $pub = $submission->getCurrentPublication();

        return [
            'copyrightHolder' => $pub->getLocalizedData('copyrightHolder'),
            'copyrightYear'   => $pub->getData('copyrightYear'),
            'licenseUrl'      => $pub->getData('licenseUrl'),
            'rights'          => $pub->getLocalizedData('rights'),
            'locale'          => $pub->getData('locale'), // For xml:lang in license
        ];
    }

    /**
     * Extract supplementary/dependent files from submission
     * These are additional files attached to the article (datasets, code, images, etc.)
     */
    protected function extractSupplementaryMaterials(PKPSubmission $submission, $parentFileId = null): array
    {
        $publication = $submission->getCurrentPublication();
        $materials = [];
        
        // Get all submission files for this publication
        $submissionFiles = \APP\facades\Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([\PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->getMany();
        
        foreach ($submissionFiles as $file) {
            $assocType = $file->getData('assocType');
            $assocId = $file->getData('assocId');

            // If a parent file is specified, prioritize files associated with it.
            // However, we also allow files associated with the representation (galley).
            // The main goal here is to avoid picking up files from *other* XML versions.
            if ($parentFileId && $assocType == \APP\core\Application::ASSOC_TYPE_SUBMISSION_FILE && $assocId != $parentFileId) {
                continue;
            }

            // Only include files associated with the current publication or specific submission file
            if ($assocType == \APP\core\Application::ASSOC_TYPE_SUBMISSION_FILE ||
                $assocType == \APP\core\Application::ASSOC_TYPE_REPRESENTATION) {

                $label = $file->getLocalizedData('name') ?: 'Supplementary File ' . $file->getId();
                
                // Deduplicate by label to avoid repeating the same file multiple times 
                // (common when enrichment is run multiple times without cleanup)
                if (isset($materials[$label])) {
                    continue;
                }

                $originalName = $file->getLocalizedData('name') ?: ($file->getData('path') ? basename($file->getData('path')) : null);

                $materials[$label] = [
                    'id' => 'supp' . $file->getId(),
                    'label' => $label,
                    'caption' => $file->getLocalizedData('description'),
                    'mimetype' => $file->getData('mimetype'),
                    'href' => $originalName ? (strpos($originalName, ' ') !== false ? str_replace(' ', '%20', $originalName) : $originalName) : null,
                    'filename' => $originalName,
                ];
            }
        }

        return array_values($materials);
    }
}

