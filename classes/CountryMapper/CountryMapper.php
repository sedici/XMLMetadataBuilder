<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes\CountryMapper;

/**
 * Service class to map country names to ISO 3166-1 alpha-2 codes
 * 
 * Supports multiple languages and fuzzy matching for country name variations.
 */
class CountryMapper
{
    /** @var array Spanish country names to ISO codes */
    private $spanishMap = [];
    
    /** @var array English country names to ISO codes */
    private $englishMap = [];
    
    /** @var array Combined map for all languages */
    private $combinedMap = [];
    
    public function __construct()
    {
        $this->loadMappings();
    }
    
    /**
     * Load country name mappings from data files
     */
    private function loadMappings()
    {
        $baseDir = __DIR__ . '/data';
        
        // Load Spanish mappings
        $spanishFile = $baseDir . '/country_codes_es.php';
        if (file_exists($spanishFile)) {
            $this->spanishMap = require $spanishFile;
        }
        
        // Load English mappings
        $englishFile = $baseDir . '/country_codes_en.php';
        if (file_exists($englishFile)) {
            $this->englishMap = require $englishFile;
        }
        
        // Combine all mappings for general lookup
        $this->combinedMap = array_merge($this->spanishMap, $this->englishMap);
    }
    
    /**
     * Get ISO code for a country name
     * 
     * @param string $countryName Country name in any supported language
     * @param string|null $language Optional language hint ('es', 'en', or null for auto-detect)
     * @return string|null ISO 3166-1 alpha-2 code or null if not found
     */
    public function getCountryCode($countryName, $language = null)
    {
        if (empty($countryName)) {
            return null;
        }
        
        // Normalize the country name
        $normalized = $this->normalize($countryName);
        
        // Try direct lookup based on language hint
        if ($language === 'es' && isset($this->spanishMap[$normalized])) {
            return $this->spanishMap[$normalized];
        }
        
        if ($language === 'en' && isset($this->englishMap[$normalized])) {
            return $this->englishMap[$normalized];
        }
        
        // Try combined map (all languages)
        if (isset($this->combinedMap[$normalized])) {
            return $this->combinedMap[$normalized];
        }
        
        // Try fuzzy matching
        return $this->fuzzyMatch($normalized, $language);
    }
    
    /**
     * Normalize country name for matching
     * - Convert to lowercase
     * - Remove accents and diacritics
     * - Trim whitespace
     * - Replace multiple spaces with single space
     * 
     * @param string $name Country name
     * @return string Normalized name
     */
    private function normalize($name)
    {
        // Convert to lowercase
        $name = mb_strtolower($name, 'UTF-8');
        
        // Remove accents and diacritics
        $name = $this->removeAccents($name);
        
        // Trim and normalize whitespace
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        
        return $name;
    }
    
    /**
     * Remove accents and diacritics from text
     * 
     * @param string $text Text with accents
     * @return string Text without accents
     */
    private function removeAccents($text)
    {
        // Common accent replacements
        $accents = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
            'ç' => 'c',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ñ' => 'N',
            'Ç' => 'C',
        ];
        
        return strtr($text, $accents);
    }
    
    /**
     * Attempt fuzzy matching for country names
     * Tries partial matches and common variations
     * 
     * @param string $normalized Normalized country name
     * @param string|null $language Language hint
     * @return string|null ISO code or null
     */
    private function fuzzyMatch($normalized, $language = null)
    {
        // Determine which map(s) to search
        $mapsToSearch = [];
        if ($language === 'es') {
            $mapsToSearch[] = $this->spanishMap;
        } elseif ($language === 'en') {
            $mapsToSearch[] = $this->englishMap;
        } else {
            // Search both maps
            $mapsToSearch[] = $this->spanishMap;
            $mapsToSearch[] = $this->englishMap;
        }
        
        // Try to find partial matches
        foreach ($mapsToSearch as $map) {
            foreach ($map as $countryName => $isoCode) {
                // Check if the normalized name contains the country name or vice versa
                if (strpos($normalized, $countryName) !== false || strpos($countryName, $normalized) !== false) {
                    // Ensure it's a significant match (at least 4 characters or 50% of the shorter string)
                    $minLength = min(strlen($normalized), strlen($countryName));
                    if ($minLength >= 4 || $minLength / max(strlen($normalized), strlen($countryName)) >= 0.5) {
                        return $isoCode;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Check if a country code is valid
     * 
     * @param string $code ISO code to check
     * @return bool True if valid
     */
    public function isValidCode($code)
    {
        if (empty($code)) {
            return false;
        }
        
        $code = strtoupper($code);
        return in_array($code, $this->combinedMap, true);
    }
    
    
    /**
     * Get country name from ISO code (reverse lookup)
     * 
     * @param string $isoCode ISO 3166-1 alpha-2 code
     * @param string $language Language for the country name ('es' or 'en', defaults to 'es')
     * @return string|null Country name in the requested language or null if not found
     */
    public function getCountryName($isoCode, $language = 'es')
    {
        if (empty($isoCode)) {
            return null;
        }
        
        // Normalize ISO code to uppercase
        $isoCode = strtoupper(trim($isoCode));
        
        // Select the appropriate map based on language
        $map = $language === 'en' ? $this->englishMap : $this->spanishMap;
        
        // Search for the ISO code in the map
        $countryName = array_search($isoCode, $map, true);
        
        // If found, return the first key that matches
        // Note: array_search returns the key (country name) for the given value (ISO code)
        if ($countryName !== false) {
            return $countryName;
        }
        
        // Try the other language map as fallback
        $fallbackMap = $language === 'en' ? $this->spanishMap : $this->englishMap;
        $countryName = array_search($isoCode, $fallbackMap, true);
        
        return $countryName !== false ? $countryName : null;
    }
    
    /**
     * Get all supported country codes
     * 
     * @return array Array of unique ISO codes
     */
    public function getAllCodes()
    {
        return array_unique(array_values($this->combinedMap));
    }
}
