<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CommunityCatalogue\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use CommunityCatalogue\CatalogueImporter\ImportService;

$root = dirname(__DIR__, 2);
$workspace = sys_get_temp_dir() . '/catalogue_importer_test_' . bin2hex(random_bytes(4));
if (!mkdir($workspace, 0775, true) && !is_dir($workspace)) {
    throw new RuntimeException("Unable to create temp workspace {$workspace}");
}

$service = new ImportService($root);

$fixtures = [
    'municipal-maintenance-linecard' => [
        'contract' => $root . '/examples/input_contract/municipal-maintenance-linecard.json',
        'items' => 12,
        'warnings' => 24,
        'codes' => ['AMBIGUOUS_SPEC', 'SOURCE_NOTE_REQUIRES_REVIEW', 'MALFORMED_IMAGE_REF', 'LOW_CONFIDENCE', 'POSSIBLE_DUPLICATE', 'CROSS_PAGE_REFERENCE'],
    ],
    'workshop-equipment-cards' => [
        'contract' => $root . '/examples/input_contract/workshop-equipment-cards.json',
        'items' => 8,
        'warnings' => 12,
        'codes' => ['AMBIGUOUS_SPEC', 'SOURCE_NOTE_REQUIRES_REVIEW', 'MISSING_IMAGE_REF', 'MALFORMED_IMAGE_REF'],
    ],
    'storage-family-matrix' => [
        'contract' => $root . '/examples/input_contract/storage-family-matrix.json',
        'items' => 9,
        'warnings' => 29,
        'codes' => ['AMBIGUOUS_SPEC', 'SOURCE_NOTE_REQUIRES_REVIEW', 'CROSS_PAGE_REFERENCE', 'LOW_CONFIDENCE'],
    ],
];

foreach ($fixtures as $name => $fixture) {
    $summary = $service->importContractFile(
        $fixture['contract'],
        $workspace . '/' . $name . '/review_packet',
        $workspace . '/' . $name . '/catalogue_imports.sqlite'
    );

    assertTrue($summary['draft_item_count'] === $fixture['items'], "Unexpected draft item count for {$name}.");
    assertTrue($summary['warning_count'] === $fixture['warnings'], "Unexpected warning count for {$name}.");
    assertTrue(is_file($workspace . '/' . $name . '/review_packet/index.html'), "Expected review packet HTML for {$name}.");
    assertTrue(is_file($workspace . '/' . $name . '/review_packet/draft_items.json'), "Expected draft_items.json for {$name}.");
    assertTrue(is_file($workspace . '/' . $name . '/review_packet/warnings.json'), "Expected warnings.json for {$name}.");

    $warnings = json_decode((string) file_get_contents($workspace . '/' . $name . '/review_packet/warnings.json'), true);
    $codes = array_map(static fn (array $warning): string => $warning['code'], $warnings['warnings']);
    foreach ($fixture['codes'] as $code) {
        assertTrue(in_array($code, $codes, true), "Expected {$code} warning for {$name}.");
    }

    $checkedInPacket = $root . '/examples/review_packets/' . $name;
    assertTrue(is_file($checkedInPacket . '/index.html'), "Expected checked-in review packet HTML for {$name}.");
    assertTrue(is_file($checkedInPacket . '/draft_items.json'), "Expected checked-in draft_items.json for {$name}.");
    assertTrue(is_file($checkedInPacket . '/draft_items.csv'), "Expected checked-in draft_items.csv for {$name}.");
    assertTrue(is_file($checkedInPacket . '/warnings.json'), "Expected checked-in warnings.json for {$name}.");

    $checkedInDrafts = json_decode((string) file_get_contents($checkedInPacket . '/draft_items.json'), true);
    $checkedInWarnings = json_decode((string) file_get_contents($checkedInPacket . '/warnings.json'), true);
    assertTrue($checkedInDrafts['run']['extraction_path'] === 'fallback_contract', "Expected fallback extraction path for {$name}.");
    assertTrue($checkedInDrafts['run']['extractor_provider'] === 'not_used', "Expected no extractor provider for {$name}.");
    assertTrue(count($checkedInDrafts['items']) === $fixture['items'], "Unexpected checked-in packet item count for {$name}.");
    assertTrue(count($checkedInWarnings['warnings']) === $fixture['warnings'], "Unexpected checked-in packet warning count for {$name}.");
}

$livePacket = $root . '/examples/review_packets/live-mistral-ocr-linecard';
assertTrue(is_file($livePacket . '/index.html'), 'Expected checked-in live Mistral OCR packet HTML.');
assertTrue(is_file($livePacket . '/draft_items.json'), 'Expected checked-in live Mistral draft_items.json.');
assertTrue(is_file($livePacket . '/draft_items.csv'), 'Expected checked-in live Mistral draft_items.csv.');
assertTrue(is_file($livePacket . '/warnings.json'), 'Expected checked-in live Mistral warnings.json.');

$liveDrafts = json_decode((string) file_get_contents($livePacket . '/draft_items.json'), true);
$liveWarnings = json_decode((string) file_get_contents($livePacket . '/warnings.json'), true);
assertTrue($liveDrafts['run']['extraction_path'] === 'live_ocr', 'Expected live OCR extraction path.');
assertTrue($liveDrafts['run']['extractor_provider'] === 'mistral_ocr', 'Expected Mistral OCR extractor provider.');
assertTrue(count($liveDrafts['items']) === 12, 'Unexpected live Mistral packet item count.');
assertTrue(count($liveWarnings['warnings']) === 32, 'Unexpected live Mistral packet warning count.');

fwrite(STDOUT, "PHP import contract test passed\n");

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
