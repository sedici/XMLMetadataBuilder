<?php

require_once dirname(__FILE__) . '/CountryMapper/CountryMapper.php';

class JATSBuilder
{
    /** @var DOMDocument */
    private $doc;

    /** @var CountryMapper */
    private $countryMapper;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $this->countryMapper = new CountryMapper();
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

        // ISSN - Support both print and electronic types
        // SciELO/JATS requires separate <issn> elements for each type
        
        // Print ISSN (ppub) - typically listed first
        if (!empty($journal['printIssn'])) {
            $journalMeta->appendChild(
                $this->elAttr('issn', ['pub-type' => 'ppub'], $journal['printIssn'])
            );
        }
        
        // Electronic/Online ISSN (epub)
        if (!empty($journal['onlineIssn'])) {
            $journalMeta->appendChild(
                $this->elAttr('issn', ['pub-type' => 'epub'], $journal['onlineIssn'])
            );
        }

        // publisher
        if (!empty($journal['publisher'])) {
            $publisher = $this->el('publisher');
            $publisher->appendChild($this->el('publisher-name', $journal['publisher']));
            
            // Add publisher-loc if we have email or other location data
            // JATS allows publisher-loc to contain: addr-line, city, state, postal-code, country, phone, fax, email
            if (!empty($journal['publisherEmail'])) {
                $publisherLoc = $this->el('publisher-loc');
                $publisherLoc->appendChild($this->el('email', $journal['publisherEmail']));
                $publisher->appendChild($publisherLoc);
            }
            
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

        // 3. title-group (with multilingual support)
        $articleMeta->appendChild($this->buildTitleGroup($metadata));

        // 4. contrib-group (authors - SPS REQUIRES this element even if empty)
        // Always generate contrib-group for SPS compliance, even with empty array
        $authors = $metadata['authors'] ?? [];
        if (!empty($authors)) {
            $articleMeta->appendChild(
                $this->buildContribGroup($authors)
            );
        } else {
            // SPS best practice: include empty contrib-group rather than omitting it
            $emptyContribGroup = $this->el('contrib-group');
            $articleMeta->appendChild($emptyContribGroup);
        }

        // 4.5. aff (affiliations - placed OUTSIDE contrib-group per JATS best practices)
        // Affiliations are referenced from contrib elements via xref with rid
        $affiliations = $metadata['affiliations'] ?? [];
        foreach ($affiliations as $affData) {
            $affEl = $this->elAttr('aff', ['id' => $affData['id']]);
            
            // DTD REQUIRED ORDER: label must come BEFORE institution
            // Add label with affiliation number (extract number from ID like 'aff1' -> '1')
            $affNumber = preg_replace('/\D/', '', $affData['id']); // Remove non-digits
            if (!empty($affNumber)) {
                $affEl->appendChild($this->el('label', $affNumber));
            }
            
            // Add institution with content-type="original" (SPS requirement)
            $affEl->appendChild(
                $this->elAttr('institution', ['content-type' => 'original'], $affData['name'])
            );
            
            // Add addr-line element (empty, for JATS structural compliance)
            // JATS allows addr-line to preserve address formatting
            $affEl->appendChild($this->el('addr-line'));
            
            // Add country if available (JATS standard element)
            // SciELO requires country attribute with ISO 3166-1 alpha-2 code
            // NOTE: OJS's $author->getCountry() returns ISO code, not country name
            if (!empty($affData['country'])) {
                $countryValue = trim($affData['country']);
                
                // Check if the value is already an ISO code (2 uppercase letters)
                // OJS stores country as ISO code by default
                if (preg_match('/^[A-Z]{2}$/i', $countryValue)) {
                    // Value is ISO code - use it for the attribute
                    $isoCode = strtoupper($countryValue);
                    
                    // Get country name for element content (Spanish by default)
                    $countryName = $this->countryMapper->getCountryName($isoCode, 'es');
                    
                    // If name not found in Spanish, try English
                    if (!$countryName) {
                        $countryName = $this->countryMapper->getCountryName($isoCode, 'en');
                    }
                    
                    // Use country name if found, otherwise use ISO code as fallback
                    $countryText = $countryName ?: $isoCode;
                    
                    // Create country element with ISO code attribute
                    $countryEl = $this->elAttr('country', ['country' => $isoCode], $countryText);
                } else {
                    // Value is a country name - try to get ISO code
                    $isoCode = $this->countryMapper->getCountryCode($countryValue, 'es');
                    if (!$isoCode) {
                        $isoCode = $this->countryMapper->getCountryCode($countryValue);
                    }
                    
                    if ($isoCode) {
                        $countryEl = $this->elAttr('country', ['country' => $isoCode], $countryValue);
                    } else {
                        // Fallback: create country element without attribute if ISO code not found
                        $countryEl = $this->el('country', $countryValue);
                    }
                }
                
                $affEl->appendChild($countryEl);
            }
            
            $articleMeta->appendChild($affEl);
        }

        // 5. author-notes (MUST come after contrib-group and before pub-date per JATS DTD)
        // Identify corresponding author from isPrimary flag
        if (!empty($authors)) {
            $correspondingAuthor = null;
            foreach ($authors as $author) {
                if (!empty($author['isPrimary'])) {
                    $correspondingAuthor = $author;
                    break;
                }
            }
            
            // Generate author-notes if we have a corresponding author with email
            if ($correspondingAuthor && !empty($correspondingAuthor['email'])) {
                $authorNotes = $this->el('author-notes');
                $corresp = $this->el('corresp');
                
                // Build correspondence text
                $correspText = 'Correspondence: ';
                if (!empty($correspondingAuthor['given']) && !empty($correspondingAuthor['surname'])) {
                    $correspText .= $correspondingAuthor['given'] . ' ' . $correspondingAuthor['surname'] . '. ';
                }
                $correspText .= 'Email: ';
                
                // Create text node and email element
                $corresp->appendChild($this->doc->createTextNode($correspText));
                $corresp->appendChild($this->el('email', $correspondingAuthor['email']));
                
                $authorNotes->appendChild($corresp);
                $articleMeta->appendChild($authorNotes);
            }
        }

        // 6. pub-date (MUST come after author-notes and before volume/issue per JATS spec)
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

        // 7. volume
        if (!empty($metadata['volume'])) {
            $articleMeta->appendChild($this->el('volume', $metadata['volume']));
        }

        // 8. issue
        if (!empty($metadata['issue'])) {
            $articleMeta->appendChild($this->el('issue', $metadata['issue']));
        }

        // 9. Page information 
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
        
        // 9.5. supplementary-material (MUST come after pages and before history per JATS DTD)
        // Include any supplementary files (datasets, code, additional images, etc.)
        if (!empty($metadata['supplementaryMaterials'])) {
            foreach ($metadata['supplementaryMaterials'] as $material) {
                // SciELO requires mimetype and mime-subtype as separate attributes
                $suppMatAttrs = ['id' => $material['id']];
                
                // Add mimetype and mime-subtype to supplementary-material element
                if (!empty($material['mimetype'])) {
                    $mimeparts = $this->splitMimeType($material['mimetype']);
                    if (!empty($mimeparts['mimetype'])) {
                        $suppMatAttrs['mimetype'] = $mimeparts['mimetype'];
                    }
                    if (!empty($mimeparts['mime-subtype'])) {
                        $suppMatAttrs['mime-subtype'] = $mimeparts['mime-subtype'];
                    }
                }
                
                $suppMat = $this->elAttr('supplementary-material', $suppMatAttrs);
                
                // Add label if available
                if (!empty($material['label'])) {
                    $suppMat->appendChild($this->el('label', $material['label']));
                }
                
                // Add caption/description if available
                if (!empty($material['caption'])) {
                    $caption = $this->el('caption');
                    $caption->appendChild($this->el('p', strip_tags($material['caption'])));
                    $suppMat->appendChild($caption);
                }
                
                // Add media element with file reference
                if (!empty($material['filename']) && !empty($material['mimetype'])) {
                    $mimeparts = $this->splitMimeType($material['mimetype']);
                    $mediaAttrs = ['xlink:href' => $material['filename']];
                    
                    // SciELO requires mimetype and mime-subtype as separate attributes
                    if (!empty($mimeparts['mimetype'])) {
                        $mediaAttrs['mimetype'] = $mimeparts['mimetype'];
                    }
                    if (!empty($mimeparts['mime-subtype'])) {
                        $mediaAttrs['mime-subtype'] = $mimeparts['mime-subtype'];
                    }
                    
                    $suppMat->appendChild($this->elAttr('media', $mediaAttrs));
                }
                
                $articleMeta->appendChild($suppMat);
            }
        }

        // 10. history (editorial dates - MUST come after pages and before permissions per JATS spec)
        // Check if there's at least one non-null date in the history array
        if (!empty($metadata['history']) && array_filter($metadata['history'])) {
            $articleMeta->appendChild(
                $this->buildHistory($metadata['history'])
            );
        }

        // 11. permissions (MUST come after history and before abstract per JATS spec)
        if (!empty($metadata['license'])) {
            $articleMeta->appendChild(
                $this->buildPermissions($metadata['license'])
            );
        }

        // 11.5. self-uri (article URL - MUST come after permissions and before abstract)
        // JATS: self-uri contains the URI/URL of the article itself
        if (!empty($metadata['articleUrl'])) {
            $articleMeta->appendChild(
                $this->elAttr('self-uri', ['xlink:href' => $metadata['articleUrl']])
            );
        }

        // 12. abstract (comes AFTER history/permissions per SPS DTD)
        // Handle multilingual abstracts
        $primaryLocale = $metadata['primaryLocale'] ?? $metadata['languages'] ?? 'es';
        $abstracts = is_array($metadata['abstract']) ? $metadata['abstract'] : [$primaryLocale => $metadata['abstract']];
        
        // Primary abstract
        if (!empty($abstracts[$primaryLocale])) {
            $abstract = $this->el('abstract');
            // Wrap abstract in <p> tag as per SPS requirements
            $p = $this->el('p', strip_tags($abstracts[$primaryLocale]));
            $abstract->appendChild($p);
            $articleMeta->appendChild($abstract);
        }
        
        // Translated abstracts (trans-abstract for each additional language)
        foreach ($abstracts as $locale => $abstractText) {
            // Skip primary locale and empty abstracts
            if ($locale === $primaryLocale || empty($abstractText)) {
                continue;
            }
            
            $jatsLang = $this->convertLocaleToJatsLang($locale);
            $transAbstract = $this->elAttr('trans-abstract', ['xml:lang' => $jatsLang]);
            $p = $this->el('p', strip_tags($abstractText));
            $transAbstract->appendChild($p);
            $articleMeta->appendChild($transAbstract);
        }

        // 13. kwd-group (comes AFTER abstract per SPS DTD)
        // Handle multilingual keywords
        $primaryLocale = $metadata['primaryLocale'] ?? $metadata['languages'] ?? 'es';
        $keywordsData = is_array($metadata['keywords']) ? $metadata['keywords'] : [];
        
        // Generate kwd-group for each language that has keywords
        foreach ($keywordsData as $locale => $keywords) {
            // Skip if no keywords for this locale
            if (empty($keywords) || !is_array($keywords)) {
                continue;
            }
            
            $jatsLang = $this->convertLocaleToJatsLang($locale);
            $kwdAttrs = [
                'kwd-group-type' => 'author',
                'xml:lang' => $jatsLang
            ];
            
            $kwdGroup = $this->elAttr('kwd-group', $kwdAttrs);
            foreach ($keywords as $kw) {
                if (!empty($kw)) {
                    $kwdGroup->appendChild($this->el('kwd', $kw));
                }
            }
            $articleMeta->appendChild($kwdGroup);
        }

        // 14. counts (MUST come before custom-meta-group)
        if (isset($metadata['counts'])) {
            $counts = $this->el('counts');
            $counts->appendChild($this->elAttr('fig-count', ['count' => (string) $metadata['counts']['fig-count']]));
            $counts->appendChild($this->elAttr('table-count', ['count' => (string) $metadata['counts']['table-count']]));
            $counts->appendChild($this->elAttr('equation-count', ['count' => (string) $metadata['counts']['equation-count']]));
            $counts->appendChild($this->elAttr('ref-count', ['count' => (string) $metadata['counts']['ref-count']]));
            if (isset($metadata['counts']['page-count'])) {
                $counts->appendChild($this->elAttr('page-count', ['count' => (string) $metadata['counts']['page-count']]));
            }
            $articleMeta->appendChild($counts);
        }

        // 16. custom-meta-group (last)
        if (!empty($metadata['custom'])) {
            $articleMeta->appendChild(
                $this->buildCustomMetaGroup($metadata['custom'])
            );
        }

        return $articleMeta;
    }

    /***********************************************************************
     * TITLE-GROUP with multilingual support
     **********************************************************************/
    private function buildTitleGroup(array $metadata): DOMElement
    {
        $titleGroup = $this->el('title-group');
        
        // Extract primary locale and titles
        $primaryLocale = $metadata['primaryLocale'] ?? $metadata['languages'] ?? 'es';
        $titles = is_array($metadata['title']) ? $metadata['title'] : [$primaryLocale => $metadata['title']];
        $subtitles = is_array($metadata['subtitle']) ? $metadata['subtitle'] : [];
        
        // Primary title (required)
        $primaryTitle = $titles[$primaryLocale] ?? reset($titles) ?? '';
        $titleGroup->appendChild($this->el('article-title', $primaryTitle));
        
        // Primary subtitle (optional)
        if (!empty($subtitles[$primaryLocale])) {
            $titleGroup->appendChild($this->el('subtitle', $subtitles[$primaryLocale]));
        }
        
        // Translated titles (trans-title-group for each additional language)
        foreach ($titles as $locale => $title) {
            // Skip primary locale
            if ($locale === $primaryLocale) {
                continue;
            }
            
            // Skip empty titles
            if (empty($title)) {
                continue;
            }
            
            // Convert OJS locale format (es_ES) to JATS format (es)
            $jatsLang = $this->convertLocaleToJatsLang($locale);
            
            // Create trans-title-group with xml:lang attribute
            $transGroup = $this->elAttr('trans-title-group', ['xml:lang' => $jatsLang]);
            $transGroup->appendChild($this->el('trans-title', $title));
            
            // Add translated subtitle if available
            if (!empty($subtitles[$locale])) {
                $transGroup->appendChild($this->el('trans-subtitle', $subtitles[$locale]));
            }
            
            $titleGroup->appendChild($transGroup);
        }
        
        return $titleGroup;
    }

    /**
     * Convert OJS locale format to JATS xml:lang format
     * Examples: es_ES -> es, en_US -> en, pt_BR -> pt
     */
    private function convertLocaleToJatsLang(string $locale): string
    {
        // Extract language code before underscore
        $parts = explode('_', $locale);
        return strtolower($parts[0]);
    }

    /***********************************************************************
     * CONTRIB-GROUP
     **********************************************************************/
    private function buildContribGroup(array $authors): DOMElement
    {
        $group = $this->el('contrib-group');

        foreach ($authors as $a) {
            $contribAttrs = ['contrib-type' => 'author'];
            
            // Add corresp="yes" for corresponding author (SPS requirement)
            if (!empty($a['isPrimary'])) {
                $contribAttrs['corresp'] = 'yes';
            }
            
            $contrib = $this->elAttr('contrib', $contribAttrs);

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
                $xref = $this->elAttr('xref', [
                    'ref-type' => 'aff',
                    'rid'      => $a['affiliationId']
                ]);
                
                // Add <sup> with affiliation number (extract number from affiliation ID like 'aff1' -> '1')
                $affNumber = preg_replace('/\D/', '', $a['affiliationId']); // Remove non-digits
                if (!empty($affNumber)) {
                    $xref->appendChild($this->el('sup', $affNumber));
                }
                
                $contrib->appendChild($xref);
            }

            if (!empty($a['email'])) {
                $contrib->appendChild($this->el('email', $a['email']));
            }
            
            // Add bio element if biography is available
            // JATS: bio element contains biographical information about the contributor
            // Must come after email and before the contrib is closed
            if (!empty($a['biography'])) {
                $biography = $a['biography'];
                // Skip if biography is empty after stripping HTML tags
                if (trim(strip_tags($biography)) !== '') {
                    $bio = $this->el('bio');
                    
                    // Add biography as paragraph (strip HTML tags for plain text)
                    // JATS allows multiple paragraphs, sections, etc. in bio
                    // For simplicity, we use a single <p> element
                    $bioText = strip_tags($biography);
                    $bio->appendChild($this->el('p', $bioText));
                    
                    $contrib->appendChild($bio);
                }
            }

            $group->appendChild($contrib);
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

    /***********************************************************************
     * HELPER METHODS
     **********************************************************************/
    
    /**
     * Split mimetype into mimetype and mime-subtype components
     * Example: "application/pdf" => ["mimetype" => "application", "mime-subtype" => "pdf"]
     */
    private function splitMimeType($fullMimetype)
    {
        if (empty($fullMimetype)) {
            return ['mimetype' => '', 'mime-subtype' => ''];
        }
        
        $parts = explode('/', $fullMimetype, 2);
        return [
            'mimetype' => $parts[0] ?? '',
            'mime-subtype' => $parts[1] ?? ''
        ];
    }
}
