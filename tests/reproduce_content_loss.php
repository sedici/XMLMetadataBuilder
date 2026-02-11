<?php
// Define mocks BEFORE requiring the processor
namespace APP\core;
class Application {
    public static function get() { return new self(); }
    public function getRequest() { return null; }
}

namespace PluginTemplate\Services;
class MetadataExtractor {
    public function extract($s, $c) {
        return [
            'journal' => ['title' => 'Test Journal'],
            'article' => ['title' => 'New Title'],
            'authors' => [],
            'pubDates' => [],
            'permissions' => [],
            'sections' => []
        ];
    }
}

namespace APP\plugins\generic\pluginTemplate\classes;

// Load classes
require_once __DIR__ . '/../classes/JATSBuilder.php';
require_once __DIR__ . '/../classes/PluginMetadataProcessor.php';

// Mock objects
class MockSubmission { public function getId() { return 1; } }
class MockPublication { public function getData($k) { return null; } }
class MockContext { public function getLocalizedName() { return 'Journal'; } public function getData($k) { return null; } }


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

echo "--- Original XML ---\n";
echo $xmlInput . "\n\n";

$processor = new PluginMetadataProcessor();
$output = $processor->enrichFront($xmlInput, new MockSubmission(), new MockPublication(), new MockContext());

echo "--- Enriched XML ---\n";
echo $output . "\n\n";

if (strpos($output, 'This is the body text') !== false) {
    echo "PASS: Body preserved.\n";
} else {
    echo "FAIL: Body LOST.\n";
}

if (strpos($output, 'Reference 1') !== false) {
    echo "PASS: Back preserved.\n";
} else {
    echo "FAIL: Back LOST.\n";
}

if (strpos($output, 'New Title') !== false) {
    echo "PASS: Title updated.\n";
} else {
    echo "FAIL: Title NOT updated.\n";
}
