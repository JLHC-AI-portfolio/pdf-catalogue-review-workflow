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
$summary = $service->importContractFile(
    $root . '/examples/input_contract/workshop-layout-a.json',
    $workspace . '/review_packet',
    $workspace . '/catalogue_imports.sqlite'
);

assertTrue($summary['draft_item_count'] === 6, 'Expected six draft items.');
assertTrue($summary['warning_count'] === 15, 'Expected fifteen warnings from the Mistral-normalized fallback contract.');
assertTrue(is_file($workspace . '/review_packet/index.html'), 'Expected review packet HTML.');
assertTrue(is_file($workspace . '/review_packet/draft_items.json'), 'Expected draft_items.json.');
assertTrue(is_file($workspace . '/review_packet/warnings.json'), 'Expected warnings.json.');

$warnings = json_decode((string) file_get_contents($workspace . '/review_packet/warnings.json'), true);
$codes = array_map(static fn (array $warning): string => $warning['code'], $warnings['warnings']);
assertTrue(in_array('AMBIGUOUS_SPEC', $codes, true), 'Expected ambiguous spec warning.');
assertTrue(in_array('SOURCE_NOTE_REQUIRES_REVIEW', $codes, true), 'Expected source-note review warning.');
assertTrue(in_array('MALFORMED_IMAGE_REF', $codes, true), 'Expected malformed image warning.');
assertTrue(in_array('LOW_CONFIDENCE', $codes, true), 'Expected low-confidence warning.');
assertTrue(in_array('POSSIBLE_DUPLICATE', $codes, true), 'Expected duplicate warning.');

fwrite(STDOUT, "PHP import contract test passed\n");

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
