<?php
/**
 * Quick test to verify DOI and History dates appear in enriched XML
 */

// Mock necessary OJS classes
namespace PKP\submission {
    class PKPSubmission {
        private $id;
        private $publication;
        public function __construct($id, $publication) { 
            $this->id = $id; 
            $this->publication = $publication; 
        }
        public function getId() { return $this->id; }
        public function getCurrentPublication() { return $this->publication; }
        public function getData($key) { 
            if ($key === 'dateSubmitted') return '2023-12-20 10:00:00';
            if ($key === 'lastModified') return '2024-05-29 14:30:00';
            if ($key === 'dateAccepted') return '2024-10-09 09:15:00';
            return null; 
        }
    }
    class Genre {}
}

namespace PKP\context {
    class Context {
        private $id = 1;
        public function getId() { return $this->id; }
        public function getLocalizedName() { return 'Test Journal'; }
        public function getLocalizedAcronym() { return 'TJ'; }
        public function getData($key) {
            $data = [
                'publisherInstitution' => 'Test Publisher',
                'onlineIssn' => '1234-5678',
                'printIssn' => '8765-4321',
                'urlPath' => 'http://example.com/journal',
            ];
            return $data[$key] ?? null;
        }
    }
}

namespace PKP\submissionFile {
    class SubmissionFile {}
}

namespace PKP\services {
    class PKPAuthorService {}
}

namespace APP\facades {
    class Repo {
        public static function section() { return new SectionRepo(); }
    }
    class SectionRepo {
        public function get($id) { return new Section(); }
    }
    class Section {
        public function getLocalizedTitle() { return 'Articles'; }
        public function getLocalizedAbbrev() { return 'ART'; }
    }
}

namespace APP\core {
    class Application {
        public static function get() { return new Application(); }
        public function getRequest() { return null; }
    }
}

namespace { // Global namespace
    use APP\plugins\generic\XMLMetadataBuilder\classes\XMLMetadataProcessor;
    use PKP\submission\PKPSubmission;
    use PKP\context\Context;

    // Mock Publication and Author
    class MockPublication {
        private $data;
        public function __construct($data) { $this->data = $data; }
        public function getLocalizedTitle() { return $this->data['title']; }
        public function getLocalizedData($key) { return $this->data[$key] ?? null; }
        public function getData($key) { return $this->data[$key] ?? null; }
        public function getDoi() { return $this->data['doi'] ?? null; }
    }

    class MockAuthor {
        private $data;
        public function __construct($data) { $this->data = $data; }
        public function getLocalizedGivenName() { return $this->data['given']; }
        public function getLocalizedFamilyName() { return $this->data['family']; }
        public function getEmail() { return $this->data['email']; }
        public function getOrcid() { return $this->data['orcid'] ?? null; }
        public function getLocalizedAffiliation() { return $this->data['affiliation'] ?? null; }
        public function getCountry() { return 'US'; }
        public function getSequence() { return 1; }
        public function getPrimaryContact() { return true; }
    }

    // Load classes
    require_once __DIR__ . '/../classes/JATSBuilder.php';
    require_once __DIR__ . '/../classes/MetadataExtractor.php';
    require_once __DIR__ . '/../classes/XMLMetadataProcessor.php';

    // Create test data
    $author1 = new MockAuthor([
        'given' => 'John', 
        'family' => 'Doe', 
        'email' => 'john@example.com', 
        'affiliation' => 'University of Test'
    ]);

    $publicationData = [
        'title' => 'Test Article Title',
        'subtitle' => 'A Subtitle',
        'abstract' => 'This is a test abstract.',
        'doi' => '10.1234/test.v1i1.1',
        'datePublished' => '2024-11-15 12:00:00',
        'dateAccepted' => '2024-10-09 09:15:00',
        'copyrightHolder' => 'John Doe',
        'copyrightYear' => '2024',
        'licenseUrl' => 'http://creativecommons.org/licenses/by/4.0/',
        'rights' => 'CC BY 4.0',
        'authors' => [$author1],
        'sectionId' => 1,
        'issueId' => 1,
        'locale' => 'en_US',
        'keywords' => ['test', 'xml', 'enrichment']
    ];

    $publication = new MockPublication($publicationData);
    $submission = new PKPSubmission(100, $publication);
    $context = new Context();

    // Input XML
    $xmlInput = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<article xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mml="http://www.w3.org/1998/Math/MathML" dtd-version="1.2" article-type="research-article" xml:lang="en">
    <front>
        <article-meta>
            <title-group>
                <article-title>Original Title</article-title>
            </title-group>
        </article-meta>
    </front>
    <body>
        <p>This is the body text.</p>
    </body>
    <back>
        <ref-list>
            <ref>Reference 1</ref>
        </ref-list>
    </back>
</article>
XML;

    echo "Running DOI and History Test...\n\n";

    // Run processor
    try {
        $enrichedXml = XMLMetadataProcessor::enrichFront($xmlInput, $submission, $publication, $context);
        
        // Write output
        file_put_contents(__DIR__ . '/../output_test_history.txt', $enrichedXml);
        
        // Verify DOI
        if (strpos($enrichedXml, '10.1234/test.v1i1.1') !== false) {
            echo "[PASS] DOI found in output\n";
        } else {
            echo "[FAIL] DOI NOT found in output\n";
        }
        
        // Verify History element
        if (strpos($enrichedXml, '<history>') !== false) {
            echo "[PASS] <history> element found\n";
        } else {
            echo "[FAIL] <history> element NOT found\n";
        }
        
        // Verify received date
        if (strpos($enrichedXml, 'date-type="received"') !== false) {
            echo "[PASS] received date found\n";
        } else {
            echo "[FAIL] received date NOT found\n";
        }
        
        // Verify rev-recd date
        if (strpos($enrichedXml, 'date-type="rev-recd"') !== false) {
            echo "[PASS] rev-recd date found\n";
        } else {
            echo "[FAIL] rev-recd date NOT found\n";
        }

        // Verify accepted date in history
        if (strpos($enrichedXml, 'date-type="accepted"') !== false) {
            echo "[PASS] accepted date found\n";
        } else {
            echo "[FAIL] accepted date NOT found\n";
        }
        
        echo "\n✓ Test complete. Output saved to output_test_history.txt\n";
        
    } catch (Throwable $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}
