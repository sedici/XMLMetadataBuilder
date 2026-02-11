<?php
file_put_contents(__DIR__ . '/test_output.log', "MockClasses loaded.\n", FILE_APPEND);

// Mock Publication and Author (mimicking OJS structure)
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
