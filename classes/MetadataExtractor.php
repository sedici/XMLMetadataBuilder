<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\decision\Decision;
use PKP\submission\PKPSubmission;
use PKP\submissionFile\SubmissionFile;

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
     *
     * Se trabaja siempre sobre la publicación recibida (la dueña del JATS) y no
     * sobre la publicación actual del envío: al crear una versión nueva, el JATS
     * de esa versión se enriquece antes de que sea la publicación actual.
     */
    public function extract(PKPSubmission $submission, Context $context, Publication $publication, $parentFileId = null): array
    {
        $authors = $this->extractAuthors($publication);
        $affiliations = $this->processAffiliations($authors);

        $result = [
            'journal'     => $this->extractJournal($context),
            'article'     => $this->extractArticle($submission, $publication, $context),
            'authors'     => $authors,
            'affiliations'=> $affiliations,
            'sections'    => $this->extractSections($publication),
            'pubDates'    => $this->extractPublicationDates($submission, $publication),
            'permissions' => $this->extractPermissions($publication),
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
    protected function extractArticle(PKPSubmission $submission, Publication $publication, Context $context): array
    {
        $issueId = $publication->getData('issueId');
        
        // Extract volume/issue information if available
        $volume = null;
        $issue = null;
        $issueYear = null;
        
        if ($issueId) {
            $issueObj = Repo::issue()->get($issueId);
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
            $request = Application::get()->getRequest();
            if ($request) {
                $dispatcher = $request->getDispatcher();
                $articleUrl = $dispatcher->url(
                    $request,
                    PKPApplication::ROUTE_PAGE,
                    $context->getPath(),
                    'article',
                    'view',
                    [$publication->getData('urlPath') ?: $submission->getId()],
                    urlLocaleForPage: ''
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
            'keywords'    => $this->extractKeywords($publication),         // Array multilingüe: ['es' => ['kw1', 'kw2'], 'en' => ['kw1', 'kw2']]
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
     * Palabras clave por idioma.
     *
     * En OJS 3.5 cada palabra clave es un objeto {name, source, identifier}
     * (vocabularios controlados); en 3.4 era un string.
     */
    protected function extractKeywords(Publication $publication): array
    {
        $result = [];
        foreach ((array) $publication->getData('keywords') as $locale => $keywords) {
            $result[$locale] = array_values(array_filter(array_map(
                fn ($keyword) => is_array($keyword) ? ($keyword['name'] ?? null) : $keyword,
                (array) $keywords
            )));
        }
        return $result;
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
     * En OJS 3.5 un autor puede tener varias afiliaciones (con ROR). Cada autor
     * recibe 'affiliationIds' (todas) y 'affiliationId' (la primera, por compatibilidad).
     * El país sigue siendo un dato del autor, así que se asocia a la afiliación
     * con el país del primer autor que la tiene.
     *
     * @param array $authors Referencia al array de autores para inyectar los IDs
     * @return array Lista de afiliaciones únicas con ID
     */
    protected function processAffiliations(array &$authors): array
    {
        $affiliationsMap = []; // 'Nombre de la afiliación' => 'aff1'
        $affiliationsList = []; // 'aff1' => ['id' => 'aff1', 'name' => ..., 'ror' => ..., 'country' => ...]
        $nextId = 1;

        foreach ($authors as &$author) {
            $author['affiliationIds'] = [];

            foreach ($author['affiliations'] ?? [] as $affiliation) {
                $name = trim($affiliation['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                if (!isset($affiliationsMap[$name])) {
                    $id = 'aff' . $nextId++;
                    $affiliationsMap[$name] = $id;
                    $affiliationsList[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'ror' => $affiliation['ror'] ?? null,
                        'country' => null,
                    ];
                }

                $id = $affiliationsMap[$name];
                if (empty($affiliationsList[$id]['country']) && !empty($author['country'])) {
                    $affiliationsList[$id]['country'] = $author['country'];
                }
                if (!in_array($id, $author['affiliationIds'])) {
                    $author['affiliationIds'][] = $id;
                }
            }

            $author['affiliationId'] = $author['affiliationIds'][0] ?? null;
            $author['affiliation'] = implode('; ', array_column($author['affiliations'] ?? [], 'name'));
        }
        unset($author);

        return array_values($affiliationsList);
    }

    /**
     * Extrae los autores de la publicación
     */
    protected function extractAuthors(Publication $publication): array
    {
        $authors = $publication->getData('authors') ?? [];
        $locale = $publication->getData('locale');
        $result = [];

        foreach ($authors as $author) {
            $affiliations = [];
            foreach ($author->getAffiliations() as $affiliation) {
                $affiliations[] = [
                    'name' => $this->utf8ize($affiliation->getLocalizedName($locale)),
                    'ror' => $affiliation->getRor(),
                ];
            }

            $result[] = [
                'given'     => $this->utf8ize($author->getGivenName($locale) ?: $author->getLocalizedGivenName()),
                'surname'   => $this->utf8ize($author->getFamilyName($locale) ?: $author->getLocalizedFamilyName()),
                'email'     => $author->getEmail(), // Email is usually ASCII
                'orcid'     => $author->getOrcid(),
                'affiliations' => $affiliations,
                'country'     => $author->getCountry(),
                'sequence'    => $author->getSequence(),
                'isPrimary'   => (int) $publication->getData('primaryContactId') === (int) $author->getId(), // For author-notes (corresponding author)
                'biography'   => $this->utf8ize($author->getBiography($locale)), // For <bio> element in <contrib>
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
    protected function extractSections(Publication $publication): array
    {
        $sectionId = $publication->getData('sectionId');
        if (!$sectionId) return ['title' => null, 'abbrev' => null];

        $section = Repo::section()->get($sectionId);

        return [
            'title'     => $section ? $section->getLocalizedTitle() : null,
            'abbrev'    => $section ? $section->getLocalizedAbbrev() : null,
        ];
    }

    /**
     * Fechas importantes del artículo
     */
    protected function extractPublicationDates(PKPSubmission $submission, Publication $pub): array
    {
        // Get accepted date from editorial decisions
        $acceptedDate = null;
        $decisions = Repo::decision()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();

        foreach ($decisions as $decision) {
            $stageId = $decision->getData('stageId');
            $decisionType = $decision->getData('decision');
            $dateDecided = $decision->getData('dateDecided');
                        
            // Aceptación en la etapa de revisión externa
            if ($stageId == WORKFLOW_STAGE_ID_EXTERNAL_REVIEW && $decisionType == Decision::ACCEPT) {
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
    protected function extractPermissions(Publication $pub): array
    {
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
        $materials = [];
        
        // Get all submission files for this publication
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->getMany();
        
        foreach ($submissionFiles as $file) {
            $assocType = $file->getData('assocType');
            $assocId = $file->getData('assocId');

            // If a parent file is specified, prioritize files associated with it.
            // However, we also allow files associated with the representation (galley).
            // The main goal here is to avoid picking up files from *other* XML versions.
            if ($parentFileId && $assocType == Application::ASSOC_TYPE_SUBMISSION_FILE && $assocId != $parentFileId) {
                continue;
            }

            // Only include files associated with the current publication or specific submission file
            if ($assocType == Application::ASSOC_TYPE_SUBMISSION_FILE ||
                $assocType == Application::ASSOC_TYPE_REPRESENTATION) {

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
                    'href' => $originalName,
                    'filename' => $originalName,
                ];
            }
        }

        return array_values($materials);
    }
}

