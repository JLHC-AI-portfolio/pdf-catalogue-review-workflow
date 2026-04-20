<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

final class ContractValidator
{
    private const ALLOWED_CATEGORIES = [
        'safety',
        'hand-tools',
        'paint-labeling',
        'storage',
        'fasteners',
        'cleaning',
        'other',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array{source_file: string, layout_id: string, warnings: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>}
     */
    public function normalize(array $payload): array
    {
        $sourceFile = $this->requiredString($payload, 'source_file');
        $layoutId = $this->requiredString($payload, 'layout_id');
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Contract field items must be an array.');
        }

        $topLevelWarnings = [];
        foreach (($payload['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $topLevelWarnings[] = $this->normalizeWarning($warning);
            }
        }

        $normalizedItems = [];
        $seenKeys = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Item {$index} must be an object.");
            }

            $normalized = [
                'name' => trim((string) ($item['name'] ?? '')),
                'category' => $this->normalizeCategory((string) ($item['category'] ?? '')),
                'size_or_spec' => trim((string) ($item['size_or_spec'] ?? '')),
                'image_ref' => trim((string) ($item['image_ref'] ?? '')),
                'attributes' => is_array($item['attributes'] ?? null) ? $item['attributes'] : [],
                'confidence' => $this->normalizeConfidence($item['confidence'] ?? null),
                'warnings' => [],
                'source_page' => max(1, (int) ($item['source_page'] ?? 1)),
                'source_excerpt' => trim((string) ($item['source_excerpt'] ?? '')),
            ];

            foreach (($item['warnings'] ?? []) as $warning) {
                if (is_array($warning)) {
                    $this->addWarning($normalized, $this->normalizeWarning($warning));
                }
            }

            if ($normalized['name'] === '') {
                $this->addWarning($normalized, [
                    'code' => 'MISSING_NAME',
                    'severity' => 'blocker',
                    'message' => 'The item name is missing and must be supplied before approval.',
                    'source_field' => 'name',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }

            if (!in_array($normalized['category'], self::ALLOWED_CATEGORIES, true)) {
                $this->addWarning($normalized, [
                    'code' => 'UNKNOWN_CATEGORY',
                    'severity' => 'review',
                    'message' => 'The category is outside the supported public inventory list.',
                    'source_field' => 'category',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }

            if ($normalized['size_or_spec'] === '' || preg_match('/\b(assorted|approx|about|mixed|various|unclear|unknown|tbd)\b/i', $normalized['size_or_spec']) === 1) {
                $this->addWarning($normalized, [
                    'code' => 'AMBIGUOUS_SPEC',
                    'severity' => 'review',
                    'message' => 'The size or specification is ambiguous and needs a human check.',
                    'source_field' => 'size_or_spec',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }

            if ($normalized['image_ref'] !== '' && preg_match('/^images\/[a-z0-9][a-z0-9-]*\.(png|jpg|jpeg)$/', $normalized['image_ref']) !== 1) {
                $this->addWarning($normalized, [
                    'code' => 'MALFORMED_IMAGE_REF',
                    'severity' => 'review',
                    'message' => 'The image reference should look like images/example-name.png or images/example-name.jpg.',
                    'source_field' => 'image_ref',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }

            if ($normalized['confidence'] < 0.75) {
                $this->addWarning($normalized, [
                    'code' => 'LOW_CONFIDENCE',
                    'severity' => 'review',
                    'message' => 'The extractor marked this item below the confidence threshold.',
                    'source_field' => 'confidence',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }

            $duplicateKey = $this->duplicateKey($normalized['name'], $normalized['category']);
            if ($duplicateKey !== '' && isset($seenKeys[$duplicateKey])) {
                $this->addWarning($normalized, [
                    'code' => 'POSSIBLE_DUPLICATE',
                    'severity' => 'review',
                    'message' => 'This draft looks like a duplicate of an earlier item in the same import run.',
                    'source_field' => 'name',
                    'source_excerpt' => $normalized['source_excerpt'],
                ]);
            }
            if ($duplicateKey !== '') {
                $seenKeys[$duplicateKey] = true;
            }

            if ($normalized['source_excerpt'] === '') {
                $normalized['source_excerpt'] = $normalized['name'] !== '' ? $normalized['name'] : 'No source excerpt supplied.';
            }

            $normalizedItems[] = $normalized;
        }

        return [
            'source_file' => $sourceFile,
            'layout_id' => $layoutId,
            'warnings' => $topLevelWarnings,
            'items' => $normalizedItems,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string) ($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("Contract field {$field} is required.");
        }

        return $value;
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $category = str_replace(['_', ' '], '-', $category);

        return match ($category) {
            'hand-tool', 'hand-tools', 'tools', 'small-tools' => 'hand-tools',
            'paint', 'labeling', 'paint-and-labeling', 'paint-labels', 'paint-labeling' => 'paint-labeling',
            'storage-bin', 'storage-bins', 'organizers' => 'storage',
            default => $category,
        };
    }

    private function normalizeConfidence(mixed $confidence): float
    {
        if (!is_numeric($confidence)) {
            return 0.5;
        }

        return max(0.0, min(1.0, round((float) $confidence, 3)));
    }

    /**
     * @param array<string, mixed> $warning
     * @return array<string, mixed>
     */
    private function normalizeWarning(array $warning): array
    {
        return [
            'code' => strtoupper(trim((string) ($warning['code'] ?? 'EXTRACTION_WARNING'))) ?: 'EXTRACTION_WARNING',
            'severity' => trim((string) ($warning['severity'] ?? 'review')) ?: 'review',
            'message' => trim((string) ($warning['message'] ?? 'Review this extracted field.')) ?: 'Review this extracted field.',
            'source_field' => isset($warning['source_field']) ? trim((string) $warning['source_field']) : null,
            'source_excerpt' => isset($warning['source_excerpt']) ? trim((string) $warning['source_excerpt']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $warning
     */
    private function addWarning(array &$item, array $warning): void
    {
        $fingerprint = implode('|', [
            $warning['code'] ?? '',
            $warning['source_field'] ?? '',
        ]);

        foreach ($item['warnings'] as $existing) {
            $existingFingerprint = implode('|', [
                $existing['code'] ?? '',
                $existing['source_field'] ?? '',
            ]);
            if ($existingFingerprint === $fingerprint) {
                return;
            }
        }

        $item['warnings'][] = $warning;
    }

    private function duplicateKey(string $name, string $category): string
    {
        $name = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $name) ?? ''));
        if ($name === '') {
            return '';
        }

        return $category . ':' . $name;
    }
}
