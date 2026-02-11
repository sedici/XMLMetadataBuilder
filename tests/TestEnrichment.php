<?php
// Mock OJS Classes
namespace PKP\submission {
    class PKPSubmission {
        private $id;
        private $publication;
        public function __construct($id, $publication) { $this->id = $id; $this->publication = $publication; }
        public function getId() { return $this->id; }
        public function getCurrentPublication() { return $this->publication; }
        public function getData($key) { 
            if ($key === 'dateSubmitted') return '2023-01-01';
            return null; 
        }
    }
    class Genre {}
}

namespace PKP\context {
    class Context {
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
        public function getRequest() { return null; } // Should not be called if context is passed
    }
}

namespace { // Global namespace
    use APP\plugins\generic\pluginTemplate\classes\PluginMetadataProcessor;
    use PKP\submission\PKPSubmission;
    use PKP\context\Context;

    require_once __DIR__ . '/MockClasses.php';

    function logTest($msg) {
        file_put_contents(__DIR__ . '/test_output.log', $msg . "\n", FILE_APPEND);
    }

    logTest("Loading JATSBuilder...");
    require_once __DIR__ . '/../classes/JATSBuilder.php';
    logTest("Loading MetadataExtractor...");
    require_once __DIR__ . '/../classes/MetadataExtractor.php';
    logTest("Loading PluginMetadataProcessor...");
    require_once __DIR__ . '/../classes/PluginMetadataProcessor.php';
    logTest("Classes loaded.");

    // --- TEST SETUP ---
    logTest("Setting up mocks...");

    try {
        logTest("MockAuthor exists: " . (class_exists('MockAuthor') ? 'Yes' : 'No'));

        // 1. Create Mock Data
        logTest("Creating Author...");
        $author1 = new MockAuthor(['given' => 'John', 'family' => 'Doe', 'email' => 'john@example.com', 'affiliation' => 'University of Test']);
        
        $publicationData = [
            'title' => 'Test Article Title',
            'subtitle' => 'A Subtitle',
            'abstract' => 'This is a test abstract.',
            'doi' => '10.1234/test.v1i1.1',
            'datePublished' => '2023-05-20',
            'dateAccepted' => '2023-04-15',
            'copyrightHolder' => 'John Doe',
            'copyrightYear' => '2023',
            'licenseUrl' => 'http://creativecommons.org/licenses/by/4.0/',
            'rights' => 'CC BY 4.0',
            'authors' => [$author1],
            'sectionId' => 1,
            'issueId' => 1,
            'locale' => 'en_US',
            'keywords' => ['test', 'xml', 'enrichment']
        ];
        
        logTest("Creating Publication...");
        $publication = new MockPublication($publicationData);
        
        logTest("Creating Submission...");
        $submission = new PKPSubmission(100, $publication);
        
        logTest("Creating Context...");
        $context = new Context();
        
        logTest("Mocks created.");

    } catch (Throwable $e) {
        logTest("[ERROR SETUP] " . $e->getMessage());
        logTest($e->getTraceAsString());
        exit(1);
    }

    // 2. Sample XML Input
    $xmlInput = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN" "JATS-journalpublishing1.dtd">
<article>
    <front>
        <article-meta>
            <title-group>
                <article-title>Old Title</article-title>
            </title-group>
        </article-meta>
    </front>
    <body>
        <p>Some content.</p>
    </body>
</article>
XML;

    logTest("Running XML Enrichment Test...");

    // 3. Run Processor
    try {
        $enrichedXml = PluginMetadataProcessor::enrichFront($xmlInput, $submission, $publication, $context);
        
        // 4. Verify Output
        if (strpos($enrichedXml, 'Test Article Title') !== false) {
            logTest("[PASS] Title updated correctly.");
        } else {
            logTest("[FAIL] Title not updated.");
        }

        if (strpos($enrichedXml, 'John') !== false && strpos($enrichedXml, 'Doe') !== false) {
            logTest("[PASS] Author found.");
        } else {
            logTest("[FAIL] Author not found.");
        }

        if (strpos($enrichedXml, '10.1234/test.v1i1.1') !== false) {
            logTest("[PASS] DOI found.");
        } else {
            logTest("[FAIL] DOI not found.");
        }

        if (strpos($enrichedXml, 'Test Journal') !== false) {
            logTest("[PASS] Journal title found.");
        } else {
            logTest("[FAIL] Journal title not found.");
        }

        logTest("\n--- Enriched XML Output (Snippet) ---");
        $lines = explode("\n", $enrichedXml);
        // Show first 30 lines
        logTest(implode("\n", array_slice($lines, 0, 30)));

    } catch (Throwable $e) {
        logTest("[ERROR] " . $e->getMessage());
        logTest($e->getTraceAsString());
    }
}
