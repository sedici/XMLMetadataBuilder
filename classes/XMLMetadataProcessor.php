<?php
namespace APP\plugins\generic\XMLMetadataBuilder\classes;

use DOMDocument;
use APP\plugins\generic\XMLMetadataBuilder\classes\JATSBuilder;
use APP\plugins\generic\XMLMetadataBuilder\classes\MetadataExtractor;
use APP\core\Application;

class XMLMetadataProcessor
{
    /**
     * Enrich front section of a JATS XML.
     * Accepts string XML or DOMDocument and returns XML string.
     */
    public static function enrichFront($xmlOrDom, $submission = null, $publication = null, $context = null, $parentFileId = null)
    {
        // Load DOM
        if ($xmlOrDom instanceof DOMDocument) {
            $dom = $xmlOrDom;
        } else {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xmlOrDom);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            
            if (!$loaded) {
                $errorMsg = isset($errors[0]) ? trim($errors[0]->message) : 'XML malformado';
                throw new \Exception('No se pudo parsear el archivo XML original: ' . $errorMsg);
            }
        }

        // Get context (if available and not provided)
        if (!$context) {
            try {
                $request = Application::get()->getRequest();
                $context = $request ? $request->getContext() : null;
            } catch (\Throwable $e) {
                // Application::get() might fail in CLI/Test mode without bootstrap
            }
        }

        // Extract metadata from OJS using existing extractor
        $extractor = new MetadataExtractor();
        $meta = $extractor->extract($submission, $context, $parentFileId);

        // Map extractor output to JATSBuilder format
        $builderMeta = [
            'journal' => [
                'id' => $meta['journal']['id'] ?? null,
                'names' => [$meta['journal']['title'] ?? ''],
                'abbrev' => $meta['journal']['abbrev'] ?? null,
                'onlineIssn' => $meta['journal']['onlineIssn'] ?? null,  // Electronic ISSN
                'printIssn' => $meta['journal']['printIssn'] ?? null,    // Print ISSN
                'publisher' => $meta['journal']['publisher'] ?? null,
                'publisherEmail' => $meta['journal']['publisherEmail'] ?? null, // Publisher contact email
            ],
            'articleId' => $meta['article']['submissionId'] ?? null,
            'doi' => $meta['article']['doi'] ?? null,
            'title' => $meta['article']['title'] ?? [],              // Array multilingüe completo
            'subtitle' => $meta['article']['subtitle'] ?? [],        // Array multilingüe
            'primaryLocale' => $meta['article']['primaryLocale'] ?? 'es', // Idioma principal
            'abstract' => $meta['article']['abstract'] ?? [],        // Array multilingüe completo
            'keywords' => $meta['article']['keywords'] ?? [],
            'languages' => $meta['article']['languages'] ?? 'en', // For xml:lang attribute
            'volume' => $meta['article']['volume'] ?? null,
            'issue' => $meta['article']['issue'] ?? null,
            'firstPage' => $meta['article']['firstPage'] ?? null,
            'lastPage' => $meta['article']['lastPage'] ?? null,
            'elocationId' => $meta['article']['elocationId'] ?? null,
            'articleUrl' => $meta['article']['articleUrl'] ?? null,  // For self-uri
            'dates' => $meta['pubDates'] ?? [],
            'history' => [
                'submitted' => $meta['pubDates']['submitted'] ?? null,
                'revised' => $meta['pubDates']['revised'] ?? null,
                'accepted' => $meta['pubDates']['accepted'] ?? null,
            ],
            'affiliations' => $meta['affiliations'] ?? [], // Pass unique affiliations list here
            'authors' => array_map(function($a){
                return [
                    'surname' => $a['surname'] ?? '',
                    'given' => $a['given'] ?? '',
                    'email' => $a['email'] ?? null,
                    'orcid' => $a['orcid'] ?? null,
                    'affiliation' => $a['affiliation'] ?? null, // Fallback string
                    'affiliationId' => $a['affiliationId'] ?? null, // ID for xref
                    'sequence' => $a['sequence'] ?? null,
                    'isPrimary' => $a['isPrimary'] ?? false,
                    'biography' => $a['biography'] ?? null, // For <bio> element in <contrib>
                    // JATSBuilder expects 'affiliations' key for legacy reasons? 
                    // No, JATSBuilder now looks for 'affiliationId' OR 'affiliation'. 
                    // But previous code (lines 79) mapped 'affiliations' => [string].
                    // We must ensure JATSBuilder receives what it expects.
                    // New JATSBuilder expects 'affiliationId' for xref.
                ];
            }, $meta['authors'] ?? []),
            'license' => [
                'url' => $meta['permissions']['licenseUrl'] ?? null,
                'text' => is_array($meta['permissions']['rights'] ?? null) ? reset($meta['permissions']['rights']) : ($meta['permissions']['rights'] ?? null),
                'copyrightYear' => $meta['permissions']['copyrightYear'] ?? null,
                'copyrightHolder' => $meta['permissions']['copyrightHolder'] ?? null,
                'lang' => $meta['permissions']['locale'] ?? $meta['article']['languages'] ?? 'en', // For xml:lang in license
            ],
            'custom' => [
                'section-title' => $meta['sections']['title'] ?? null,
                'section-abbrev' => $meta['sections']['abbrev'] ?? null
            ],
            'supplementaryMaterials' => $meta['supplementaryMaterials'] ?? [],
        ];
        
        // Calculate counts dynamically from the document instead of preserving old ones
        $counts = [
            'fig-count' => $dom->getElementsByTagName('fig')->length,
            'table-count' => $dom->getElementsByTagName('table-wrap')->length,
            'equation-count' => $dom->getElementsByTagName('disp-formula')->length,
            'ref-count' => $dom->getElementsByTagName('ref')->length,
        ];

        // An article is electronic if it has an elocation-id.
        // Electronic-only articles do not have a page-count.
        // Otherwise, page-count is calculated as the difference (inclusive) between lastPage and firstPage.
        $isElectronic = !empty($builderMeta['elocationId']);
        if (!$isElectronic) {
            $firstPage = $builderMeta['firstPage'];
            $lastPage = $builderMeta['lastPage'];
            if ($firstPage !== null && $lastPage !== null && is_numeric($firstPage) && is_numeric($lastPage)) {
                $counts['page-count'] = (int)$lastPage - (int)$firstPage + 1;
            }
        }

        $builderMeta['counts'] = $counts;
        
        // Build <front> with JATSBuilder
        $builder = new JATSBuilder();
        $frontNode = $builder->buildFront($builderMeta); // returns DOMElement
        
        // Replace or insert <front>
        $oldFronts = $dom->getElementsByTagName('front');
        if ($oldFronts->length) {
            $old = $oldFronts->item(0);
            $old->parentNode->replaceChild($dom->importNode($frontNode, true), $old);
        } else {
            // Insert logic: Front -> Body -> Back
            $root = $dom->documentElement;
            $body = $dom->getElementsByTagName('body')->item(0);
            $back = $dom->getElementsByTagName('back')->item(0);
            
            if ($body) {
                // If body exists, insert before body
                $root->insertBefore($dom->importNode($frontNode, true), $body);
            } elseif ($back) {
                // If no body but back exists, insert before back
                $root->insertBefore($dom->importNode($frontNode, true), $back);
            } else {
                // If neither exists, insert as first child
                if ($root->hasChildNodes()) {
                    $root->insertBefore($dom->importNode($frontNode, true), $root->firstChild);
                } else {
                    $root->appendChild($dom->importNode($frontNode, true));
                }
            }
        }

        $result = $dom->saveXML();
        return $result;
    }

    /**
     * Normalizes URIs in href and xlink:href attributes across the XML document.
     * Replaces spaces with %20 to ensure compatibility with LensGalley.
     *
     * LensGalley resolves images by applying rawurlencode() to the DB file name
     * and searching for that pattern inside the raw XML string. If the xlink:href
     * has a literal space, rawurlencode("figura 1.jpg") = "figura%201.jpg" won't
     * match "figura 1.jpg" in the XML. Encoding the href to %20 fixes this.
     *
     * This method must be called ONLY when generating the galley (PROOF) XML,
     * NOT for the production file (which Texture edits). Texture compares
     * basename(xlink:href) literally with basename(name in DB); if both keep
     * the original space the match works fine without any encoding.
     *
     * @param DOMDocument $dom The document to normalize in-place
     * @return void
     */
    public static function normalizeUris(DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        $hrefAttributes = $xpath->query('//@*[local-name()="href"]');
        if ($hrefAttributes) {
            foreach ($hrefAttributes as $attr) {
                $val = trim($attr->nodeValue);
                if ($val !== '' && strpos($val, ' ') !== false) {
                    $attr->nodeValue = str_replace(' ', '%20', $val);
                }
            }
        }
    }

    /**
     * Convenience wrapper: applies normalizeUris() to an XML string and returns
     * the normalized XML string. Intended for use when generating galley files.
     *
     * @param string $xmlString Raw XML content
     * @return string Normalized XML content
     */
    public static function normalizeUrisInXmlString(string $xmlString): string
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        libxml_use_internal_errors(true);
        $dom->loadXML($xmlString);
        libxml_use_internal_errors(false);
        self::normalizeUris($dom);
        return $dom->saveXML();
    }
}