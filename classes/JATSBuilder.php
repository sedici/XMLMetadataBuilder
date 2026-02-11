<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes;

use DOMDocument;
use DOMElement;

class JATSBuilder
{
    /** @var DOMDocument */
    private $doc;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
    }

    /**
     * @return DOMDocument
     */
    public function getDocument()
    {
        return $this->doc;
    }

    /**
     * Crear nodo de forma segura.
     */
    private function el(string $name, ?string $text = null): DOMElement
    {
        $el = $this->doc->createElement($name);
        if ($text !== null) {
            $el->appendChild($this->doc->createTextNode($text));
        }
        return $el;
    }

    /**
     * Crear nodo con atributos.
     */
    private function elAttr(string $name, array $attrs, ?string $text = null): DOMElement
    {
        $el = $this->el($name, $text);
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, $v);
        }
        return $el;
    }

    /**
     * Construye el nodo <front>.
     */
    public function buildFront(array $metadata): DOMElement
    {
        $front = $this->el('front');

        $front->appendChild($this->buildJournalMeta($metadata['journal']));
        $front->appendChild($this->buildArticleMeta($metadata));

        return $front;
    }

    /***********************************************************************
     * JOURNAL-META
     **********************************************************************/
    private function buildJournalMeta(array $journal): DOMElement
    {
        $journalMeta = $this->el('journal-meta');

        // SPS REQUIRED: journal-id with publisher-id type
        // Should use journal path (e.g., "resaa"), not numeric ID
        if (!empty($journal['id'])) {
            $journalMeta->appendChild(
                $this->elAttr('journal-id', ['journal-id-type' => 'publisher-id'], $journal['id'])
            );
        }

        // journal titles with abbrev (SPS required)
        foreach ($journal['names'] as $name) {
            $journalTitleGroup = $this->el('journal-title-group');
            $journalTitleGroup->appendChild($this->el('journal-title', $name));
            
            // SPS REQUIRED: abbrev-journal-title
            if (!empty($journal['abbrev'])) {
                $journalTitleGroup->appendChild(
                    $this->elAttr('abbrev-journal-title', ['abbrev-type' => 'publisher'], $journal['abbrev'])
                );
            }
            
            $journalMeta->appendChild($journalTitleGroup);
        }

        // ISSN
        if (!empty($journal['issn'])) {
            $journalMeta->appendChild(
                $this->elAttr('issn', ['pub-type' => 'epub'], $journal['issn'])
            );
        }

        // publisher
        if (!empty($journal['publisher'])) {
            $publisher = $this->el('publisher');
            $publisher->appendChild($this->el('publisher-name', $journal['publisher']));
            $journalMeta->appendChild($publisher);
        }

        return $journalMeta;
    }

    /***********************************************************************
     * ARTICLE-META
     **********************************************************************/
    private function buildArticleMeta(array $metadata): DOMElement
    {
        $articleMeta = $this->el('article-meta');

        // 1. article-id (IDs first)
        // NOTE: SciELO standard only uses DOI for article-id, not publisher's internal ID
        // Commenting out publisher-id to match SciELO requirements
        /*
        if (!empty($metadata['articleId'])) {
            $articleMeta->appendChild(
                $this->elAttr('article-id', ['pub-id-type' => 'publisher-id'], $metadata['articleId'])
            );
        }
        */

        // DOI - REQUIRED by SciELO
        if (!empty($metadata['doi'])) {
            $articleMeta->appendChild(
                $this->elAttr('article-id', ['pub-id-type' => 'doi'], $metadata['doi'])
            );
        }

        // 2. article-categories (SPS REQUIRED)
        if (!empty($metadata['custom']['section-title'])) {
            $articleCategories = $this->el('article-categories');
            $subjGroup = $this->elAttr('subj-group', ['subj-group-type' => 'heading']);
            $subjGroup->appendChild($this->el('subject', $metadata['custom']['section-title']));
            $articleCategories->appendChild($subjGroup);
            $articleMeta->appendChild($articleCategories);
        }

        // 3. title-group
        $titleGroup = $this->el('title-group');
        $titleGroup->appendChild($this->el('article-title', $metadata['title']));
        $articleMeta->appendChild($titleGroup);

        // 4. contrib-group (authors - SPS REQUIRES this element even if empty)
        // Always generate contrib-group for SPS compliance, even with empty array
        $authors = $metadata['authors'] ?? [];
        if (!empty($authors)) {
            $articleMeta->appendChild(
                $this->buildContribGroup($authors, $metadata['affiliations'] ?? [])
            );
        } else {
            // SPS best practice: include empty contrib-group rather than omitting it
            $emptyContribGroup = $this->el('contrib-group');
            $articleMeta->appendChild($emptyContribGroup);
        }

        // 5. pub-date (MUST come after contrib-group and before volume/issue per JATS spec)
        // JATS 1.4: pub-date should only contain publication date, NOT editorial history dates
        if (!empty($metadata['dates'])) {
            foreach ($metadata['dates'] as $type => $dateYmd) {
                if (empty($dateYmd)) continue;
                
                // Only include 'published' date in pub-date
                if (!in_array($type, ['published', 'pub'])) {
                    continue;
                }
                
                $articleMeta->appendChild(
                    $this->buildPubDate($type, $dateYmd)
                );
            }
        }

        // 6. volume
        if (!empty($metadata['volume'])) {
            $articleMeta->appendChild($this->el('volume', $metadata['volume']));
        }

        // 7. issue
        if (!empty($metadata['issue'])) {
            $articleMeta->appendChild($this->el('issue', $metadata['issue']));
        }

        // 8. Page information 
        // JATS 1.4: Use either traditional page range (fpage/lpage) OR electronic location identifier (elocation-id)
        // elocation-id is used for electronic-only articles without traditional page numbers (e.g., "e180", "E70")
        // These are mutually exclusive - use elocation-id when present, otherwise use fpage/lpage
        if (!empty($metadata['elocationId'])) {
            // Electronic location identifier (for electronic-only articles)
            $articleMeta->appendChild($this->el('elocation-id', $metadata['elocationId']));
        } else {
            // Traditional page range
            if (!empty($metadata['firstPage'])) {
                $articleMeta->appendChild($this->el('fpage', $metadata['firstPage']));
            }
            if (!empty($metadata['lastPage'])) {
                $articleMeta->appendChild($this->el('lpage', $metadata['lastPage']));
            }
        }

        // 9. history (editorial dates - MUST come after pages and before permissions per JATS spec)
        // Check if there's at least one non-null date in the history array
        if (!empty($metadata['history']) && array_filter($metadata['history'])) {
            $articleMeta->appendChild(
                $this->buildHistory($metadata['history'])
            );
        }

        // 10. permissions (MUST come after history and before abstract per JATS spec)
        if (!empty($metadata['license'])) {
            $articleMeta->appendChild(
                $this->buildPermissions($metadata['license'])
            );
        }

        // 11. abstract (comes AFTER history/permissions per SPS DTD)
        if (!empty($metadata['abstract'])) {
            $abstract = $this->el('abstract');
            // Wrap abstract in <p> tag as per SPS requirements
            $p = $this->el('p', strip_tags($metadata['abstract']));
            $abstract->appendChild($p);
            $articleMeta->appendChild($abstract);
        }

        // 12. kwd-group (comes AFTER abstract per SPS DTD)
        if (!empty($metadata['keywords'])) {
            $kwdAttrs = ['kwd-group-type' => 'author'];
            
            // SPS REQUIRED: xml:lang attribute
            if (!empty($metadata['languages'])) {
                $kwdAttrs['xml:lang'] = $metadata['languages'];
            }
            
            $kwdGroup = $this->elAttr('kwd-group', $kwdAttrs);
            foreach ($metadata['keywords'] as $kw) {
                $kwdGroup->appendChild($this->el('kwd', $kw));
            }
            $articleMeta->appendChild($kwdGroup);
        }

        // 13. custom-meta-group (last)
        if (!empty($metadata['custom'])) {
            $articleMeta->appendChild(
                $this->buildCustomMetaGroup($metadata['custom'])
            );
        }

        return $articleMeta;
    }

    /***********************************************************************
     * CONTRIB-GROUP
     **********************************************************************/
    private function buildContribGroup(array $authors, array $affiliations = []): DOMElement
    {
        $group = $this->el('contrib-group');

        foreach ($authors as $a) {
            $contrib = $this->elAttr('contrib', ['contrib-type' => 'author']);

            // DTD REQUIRED ORDER: contrib-id must come BEFORE name
            // Add ORCID if available (must be first)
            if (!empty($a['orcid'])) {
                $contrib->appendChild(
                    $this->elAttr('contrib-id', [
                        'contrib-id-type' => 'orcid'
                    ], $a['orcid'])
                );
            }

            // Now add name element
            $name = $this->el('name');
            $name->appendChild($this->el('surname', $a['surname']));
            $name->appendChild($this->el('given-names', $a['given']));
            $contrib->appendChild($name);

            // Use xref for affiliation if available
            if (!empty($a['affiliationId'])) {
                $contrib->appendChild(
                    $this->elAttr('xref', [
                        'ref-type' => 'aff',
                        'rid'      => $a['affiliationId']
                    ])
                );
            }

            if (!empty($a['email'])) {
                $contrib->appendChild($this->el('email', $a['email']));
            }

            $group->appendChild($contrib);
        }

        // Add affiliation elements at the end of contrib-group
        foreach ($affiliations as $affData) {
            $affEl = $this->elAttr('aff', ['id' => $affData['id']]);
            
            // Add institution with content-type="original" (SPS requirement)
            $affEl->appendChild(
                $this->elAttr('institution', ['content-type' => 'original'], $affData['name'])
            );
            
            // Add country if available
            if (!empty($affData['country'])) {
                $affEl->appendChild($this->el('country', $affData['country']));
            }
            
            $group->appendChild($affEl);
        }

        return $group;
    }

    /***********************************************************************
     * PUB-DATE
     **********************************************************************/
    private function buildPubDate(string $type, string $dateYmd): DOMElement
    {
        
        // Parse date components
        $dateParts = explode('-', $dateYmd);
        $y = $dateParts[0] ?? '';
        $m = $dateParts[1] ?? '';
        $d = $dateParts[2] ?? '';
        
        // Clean up day in case it has time component (e.g., "27 14:53:16")
        if (strpos($d, ' ') !== false) {
            $d = explode(' ', $d)[0];
        }

        // SPS/JATS 1.1+ attributes
        $attrs = [];
        
        // Map internal type names to JATS date-type values
        // SPS only accepts: pub, accepted (NOT received in pub-date)
        $dateTypeMap = [
            'published' => 'pub',
            'accepted' => 'accepted',
            'pub' => 'pub'
        ];
        
        $attrs['date-type'] = $dateTypeMap[$type] ?? $type;
        $attrs['publication-format'] = 'electronic';
        
        $pubDate = $this->elAttr('pub-date', $attrs);
        
        // SPS requires order: day, month, year (not year, month, day)
        if (!empty($d)) {
            $pubDate->appendChild($this->el('day', str_pad($d, 2, '0', STR_PAD_LEFT)));
        }
        if (!empty($m)) {
            $pubDate->appendChild($this->el('month', str_pad($m, 2, '0', STR_PAD_LEFT)));
        }
        if (!empty($y)) {
            $pubDate->appendChild($this->el('year', $y));
        }

        return $pubDate;
    }

    /***********************************************************************
     * HISTORY
     **********************************************************************/
    private function buildHistory(array $dates): DOMElement
    {

        $history = $this->el('history');

        // Map internal date types to JATS date-type values
        $dateTypeMap = [
            'submitted' => 'received',     // OJS submitted date = JATS received
            'revised'   => 'rev-recd',     // OJS lastModified = JATS revision received
            'accepted'  => 'accepted',     // Direct mapping
        ];

        foreach ($dates as $internalType => $dateYmd) {
            if (empty($dateYmd)) continue;
            
            // Get the JATS date-type
            $jatsDateType = $dateTypeMap[$internalType] ?? null;
            if (!$jatsDateType) {
                continue;
            }

            // Parse date components
            $dateParts = explode('-', $dateYmd);
            $y = $dateParts[0] ?? '';
            $m = $dateParts[1] ?? '';
            $d = $dateParts[2] ?? '';
            
            // Clean up day in case it has time component (e.g., "27 14:53:16")
            if (strpos($d, ' ') !== false) {
                $d = explode(' ', $d)[0];
            }

            // Only add date if we have at least a year
            if (empty($y)) {
                continue;
            }

            // Create date element with date-type attribute
            $dateEl = $this->elAttr('date', ['date-type' => $jatsDateType]);
            
            // JATS requires order: day, month, year (not calendar order)
            if (!empty($d)) {
                $dateEl->appendChild($this->el('day', str_pad($d, 2, '0', STR_PAD_LEFT)));
            }
            if (!empty($m)) {
                $dateEl->appendChild($this->el('month', str_pad($m, 2, '0', STR_PAD_LEFT)));
            }
            if (!empty($y)) {
                $dateEl->appendChild($this->el('year', $y));
            }

            $history->appendChild($dateEl);
        }

        return $history;
    }

    /***********************************************************************
     * PERMISSIONS
     **********************************************************************/
    private function buildPermissions(array $license): DOMElement
    {
        $permissions = $this->el('permissions');

        // DTD REQUIRED ORDER: copyright-statement, copyright-year, copyright-holder, license
        
        // 1. Copyright statement (MUST come FIRST)
        if (!empty($license['copyrightYear']) && !empty($license['copyrightHolder'])) {
            $statement = "Copyright © {$license['copyrightYear']} {$license['copyrightHolder']}";
            $permissions->appendChild(
                $this->el('copyright-statement', $statement)
            );
        }

        // 2. Copyright year (after statement)
        if (!empty($license['copyrightYear'])) {
            $permissions->appendChild(
                $this->el('copyright-year', $license['copyrightYear'])
            );
        }

        // 3. Copyright holder (after year)
        if (!empty($license['copyrightHolder'])) {
            $permissions->appendChild(
                $this->el('copyright-holder', $license['copyrightHolder'])
            );
        }

        // 4. License (LAST - SPS REQUIRED: License element with license-p child and xml:lang)
        if (!empty($license['url'])) {
            $licenseAttrs = [
                'license-type' => 'open-access',
                'xlink:href' => $license['url']
            ];
            
            // SPS REQUIRED: xml:lang attribute
            if (!empty($license['lang'])) {
                $licenseAttrs['xml:lang'] = $license['lang'];
            }
            
            $licenseEl = $this->elAttr('license', $licenseAttrs);
            
            // SPS REQUIRED: license element MUST have content (license-p)
            $licenseText = !empty($license['text']) ? $license['text'] : 'This is an open-access article distributed under the terms of the Creative Commons license.';
            $licenseEl->appendChild(
                $this->el('license-p', $licenseText)
            );
            
            $permissions->appendChild($licenseEl);
        }

        return $permissions;
    }

    /***********************************************************************
     * CUSTOM META GROUP
     **********************************************************************/
    private function buildCustomMetaGroup(array $items): DOMElement
    {
        $group = $this->el('custom-meta-group');

        foreach ($items as $name => $val) {
            $meta = $this->el('custom-meta');

            $meta->appendChild($this->el('meta-name', $name));
            $meta->appendChild($this->el('meta-value', $val));

            $group->appendChild($meta);
        }

        return $group;
    }
}
