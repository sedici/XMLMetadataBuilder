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
    public static function enrichFront($xmlOrDom, $submission = null, $publication = null, $context = null)
    {
        // Load DOM
        if ($xmlOrDom instanceof DOMDocument) {
            $dom = $xmlOrDom;
        } else {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (!$dom->loadXML($xmlOrDom)) {
                return $xmlOrDom; // cannot parse
            }
        }

        // Get context (if available and not provided)
        if (!$context) {
            try {
                $request = Application::get()->getRequest();
                $context = $request ? $request->getContext() : null;
            } catch (\Throwable $e) {
                // Application::get() might fail in CLI/Test mode without bootstrap
                error_log('[PluginMetadataProcessor::enrichFront] Warning: Could not fetch context from Application: ' . $e->getMessage());
            }
        }

        // Extract metadata from OJS using existing extractor
        $extractor = new MetadataExtractor();
        $meta = $extractor->extract($submission, $context);

        // Map extractor output to JATSBuilder format
        $builderMeta = [
            'journal' => [
                'id' => $meta['journal']['id'] ?? null,
                'names' => [$meta['journal']['title'] ?? ''],
                'abbrev' => $meta['journal']['abbrev'] ?? null,
                'issn' => $meta['journal']['issn'] ?? null,
                'publisher' => $meta['journal']['publisher'] ?? null,
            ],
            'articleId' => $meta['article']['submissionId'] ?? null,
            'doi' => $meta['article']['doi'] ?? null,
            'title' => is_array($meta['article']['title']) ? reset($meta['article']['title']) : $meta['article']['title'] ?? '',
            'abstract' => is_array($meta['article']['abstract']) ? reset($meta['article']['abstract']) : $meta['article']['abstract'] ?? null,
            'keywords' => $meta['article']['keywords'] ?? [],
            'languages' => $meta['article']['languages'] ?? 'en', // For xml:lang attribute
            'volume' => $meta['article']['volume'] ?? null,
            'issue' => $meta['article']['issue'] ?? null,
            'firstPage' => $meta['article']['firstPage'] ?? null,
            'lastPage' => $meta['article']['lastPage'] ?? null,
            'elocationId' => $meta['article']['elocationId'] ?? null,
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
            ]
        ];
        
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
}