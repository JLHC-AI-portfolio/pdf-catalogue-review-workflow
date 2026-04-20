<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $rootPath,
        private readonly string $databasePath
    ) {
    }

    public function migrate(): void
    {
        $pdo = $this->connection();
        foreach (glob($this->rootPath . '/database/migrations/*.sql') ?: [] as $migrationPath) {
            $sql = file_get_contents($migrationPath);
            if ($sql === false) {
                throw new \RuntimeException("Unable to read migration {$migrationPath}.");
            }
            $pdo->exec($sql);
        }
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $directory = dirname($this->databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create database directory {$directory}.");
        }

        $this->pdo = new PDO('sqlite:' . $this->databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        return $this->pdo;
    }

    /**
     * @param array{
     *   source_file: string,
     *   layout_id: string,
     *   warnings: array<int, array<string, mixed>>,
     *   items: array<int, array<string, mixed>>
     * } $payload
     * @return array<string, mixed>
     */
    public function storeImport(array $payload, string $sourceKind): array
    {
        $this->migrate();
        $pdo = $this->connection();
        $createdAt = gmdate('c');
        $warningCount = count($payload['warnings']);
        foreach ($payload['items'] as $item) {
            $warningCount += count($item['warnings']);
        }

        $pdo->beginTransaction();

        $runStatement = $pdo->prepare(
            'INSERT INTO import_runs (source_file, source_kind, layout_id, draft_item_count, warning_count, created_at)
             VALUES (:source_file, :source_kind, :layout_id, :draft_item_count, :warning_count, :created_at)'
        );
        $runStatement->execute([
            ':source_file' => $payload['source_file'],
            ':source_kind' => $sourceKind,
            ':layout_id' => $payload['layout_id'],
            ':draft_item_count' => count($payload['items']),
            ':warning_count' => $warningCount,
            ':created_at' => $createdAt,
        ]);
        $runId = (int) $pdo->lastInsertId();

        $storedItems = [];
        $storedWarnings = [];
        foreach ($payload['warnings'] as $warning) {
            $storedWarnings[] = $this->insertWarning($pdo, $runId, null, $warning, $createdAt);
        }

        $productStatement = $pdo->prepare(
            'INSERT INTO draft_products
                (import_run_id, row_number, name, category, size_or_spec, image_ref, attributes_json, confidence, review_status, source_page, source_excerpt, created_at)
             VALUES
                (:import_run_id, :row_number, :name, :category, :size_or_spec, :image_ref, :attributes_json, :confidence, :review_status, :source_page, :source_excerpt, :created_at)'
        );
        $imageStatement = $pdo->prepare(
            'INSERT INTO image_references (draft_product_id, image_ref, status, created_at)
             VALUES (:draft_product_id, :image_ref, :status, :created_at)'
        );

        foreach ($payload['items'] as $index => $item) {
            $reviewStatus = count($item['warnings']) > 0 ? 'needs_human_review' : 'ready_for_review';
            $productStatement->execute([
                ':import_run_id' => $runId,
                ':row_number' => $index + 1,
                ':name' => $item['name'],
                ':category' => $item['category'],
                ':size_or_spec' => $item['size_or_spec'],
                ':image_ref' => $item['image_ref'] ?: null,
                ':attributes_json' => json_encode($item['attributes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ':confidence' => $item['confidence'],
                ':review_status' => $reviewStatus,
                ':source_page' => $item['source_page'],
                ':source_excerpt' => $item['source_excerpt'],
                ':created_at' => $createdAt,
            ]);

            $draftProductId = (int) $pdo->lastInsertId();
            if ($item['image_ref'] !== '') {
                $imageStatement->execute([
                    ':draft_product_id' => $draftProductId,
                    ':image_ref' => $item['image_ref'],
                    ':status' => preg_match('/^images\/[a-z0-9][a-z0-9-]*\.(png|jpg|jpeg)$/', $item['image_ref']) === 1 ? 'well_formed' : 'needs_review',
                    ':created_at' => $createdAt,
                ]);
            }

            $itemWarnings = [];
            foreach ($item['warnings'] as $warning) {
                $storedWarning = $this->insertWarning($pdo, $runId, $draftProductId, $warning, $createdAt);
                $storedWarnings[] = $storedWarning;
                $itemWarnings[] = $storedWarning;
            }

            $storedItems[] = $item + [
                'id' => $draftProductId,
                'row_number' => $index + 1,
                'review_status' => $reviewStatus,
                'stored_warnings' => $itemWarnings,
            ];
        }

        $pdo->commit();

        return [
            'run' => [
                'id' => $runId,
                'source_file' => $payload['source_file'],
                'source_kind' => $sourceKind,
                'layout_id' => $payload['layout_id'],
                'draft_item_count' => count($storedItems),
                'warning_count' => count($storedWarnings),
                'created_at' => $createdAt,
                'database' => $this->databasePath,
            ],
            'items' => $storedItems,
            'warnings' => $storedWarnings,
        ];
    }

    /**
     * @param array<string, mixed> $warning
     * @return array<string, mixed>
     */
    private function insertWarning(PDO $pdo, int $runId, ?int $draftProductId, array $warning, string $createdAt): array
    {
        $statement = $pdo->prepare(
            'INSERT INTO import_warnings
                (import_run_id, draft_product_id, code, severity, message, source_field, source_excerpt, created_at)
             VALUES
                (:import_run_id, :draft_product_id, :code, :severity, :message, :source_field, :source_excerpt, :created_at)'
        );
        $statement->execute([
            ':import_run_id' => $runId,
            ':draft_product_id' => $draftProductId,
            ':code' => (string) ($warning['code'] ?? 'UNSPECIFIED_WARNING'),
            ':severity' => (string) ($warning['severity'] ?? 'review'),
            ':message' => (string) ($warning['message'] ?? 'Review this extracted field.'),
            ':source_field' => isset($warning['source_field']) ? (string) $warning['source_field'] : null,
            ':source_excerpt' => isset($warning['source_excerpt']) ? (string) $warning['source_excerpt'] : null,
            ':created_at' => $createdAt,
        ]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'draft_product_id' => $draftProductId,
            'code' => (string) ($warning['code'] ?? 'UNSPECIFIED_WARNING'),
            'severity' => (string) ($warning['severity'] ?? 'review'),
            'message' => (string) ($warning['message'] ?? 'Review this extracted field.'),
            'source_field' => isset($warning['source_field']) ? (string) $warning['source_field'] : null,
            'source_excerpt' => isset($warning['source_excerpt']) ? (string) $warning['source_excerpt'] : null,
        ];
    }
}
