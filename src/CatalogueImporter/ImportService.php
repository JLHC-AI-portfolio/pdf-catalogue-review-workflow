<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

final class ImportService
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @return array{run_id: int, source_file: string, layout_id: string, draft_item_count: int, warning_count: int, review_packet: string, database: string}
     */
    public function importPdf(
        string $pdfPath,
        string $outputPath,
        string $databasePath,
        ?string $pythonBinary = null,
        ?string $provider = null
    ): array
    {
        $payload = (new PythonExtractor($this->rootPath))->extract($pdfPath, $pythonBinary, $provider);
        $payload['source_file'] = $this->relativeToRoot($pdfPath);

        return $this->persistAndWrite($payload, 'pdf', $outputPath, $databasePath);
    }

    /**
     * @return array{run_id: int, source_file: string, layout_id: string, draft_item_count: int, warning_count: int, review_packet: string, database: string}
     */
    public function importContractFile(string $jsonPath, string $outputPath, string $databasePath): array
    {
        if (!is_file($jsonPath)) {
            throw new \InvalidArgumentException("Contract file not found: {$jsonPath}");
        }

        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new \RuntimeException("Unable to read contract file: {$jsonPath}");
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException("Contract file is not valid JSON: {$jsonPath}");
        }

        return $this->persistAndWrite($payload, 'contract_json', $outputPath, $databasePath);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{run_id: int, source_file: string, layout_id: string, draft_item_count: int, warning_count: int, review_packet: string, database: string}
     */
    private function persistAndWrite(array $payload, string $sourceKind, string $outputPath, string $databasePath): array
    {
        $normalized = (new ContractValidator())->normalize($payload);
        $stored = (new Database($this->rootPath, $databasePath))->storeImport($normalized, $sourceKind);
        (new ReviewPacketWriter($this->rootPath))->write($stored, $outputPath);

        return [
            'run_id' => (int) $stored['run']['id'],
            'source_file' => $this->rootPath . '/' . ltrim((string) $stored['run']['source_file'], '/'),
            'layout_id' => (string) $stored['run']['layout_id'],
            'draft_item_count' => (int) $stored['run']['draft_item_count'],
            'warning_count' => (int) $stored['run']['warning_count'],
            'review_packet' => $outputPath,
            'database' => $databasePath,
        ];
    }

    private function relativeToRoot(string $path): string
    {
        $prefix = rtrim($this->rootPath, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
