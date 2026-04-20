<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

final class ReviewPacketWriter
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param array{run: array<string, mixed>, items: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} $stored
     */
    public function write(array $stored, string $outputPath): void
    {
        if (!is_dir($outputPath) && !mkdir($outputPath, 0775, true) && !is_dir($outputPath)) {
            throw new \RuntimeException("Unable to create output directory {$outputPath}.");
        }

        $publicRun = $stored['run'];
        $publicRun['database'] = $this->relativeToRoot((string) $publicRun['database']);
        $this->writeJson($outputPath . '/draft_items.json', [
            'run' => $publicRun,
            'items' => $stored['items'],
        ]);
        $this->writeJson($outputPath . '/warnings.json', [
            'run_id' => $stored['run']['id'],
            'warning_count' => count($stored['warnings']),
            'warnings' => $stored['warnings'],
        ]);
        $this->writeCsv($outputPath . '/draft_items.csv', $stored['items']);
        file_put_contents($outputPath . '/index.html', $this->renderHtml($stored));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function writeCsv(string $path, array $items): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Unable to write CSV {$path}.");
        }

        fputcsv($handle, [
            'row_number',
            'name',
            'category',
            'size_or_spec',
            'image_ref',
            'confidence',
            'review_status',
            'warning_codes',
            'source_page',
            'source_excerpt',
        ], ',', '"', '');

        foreach ($items as $item) {
            fputcsv($handle, [
                $item['row_number'],
                $item['name'],
                $item['category'],
                $item['size_or_spec'],
                $item['image_ref'],
                $item['confidence'],
                $item['review_status'],
                implode(';', array_map(static fn (array $warning): string => (string) $warning['code'], $item['stored_warnings'])),
                $item['source_page'],
                $item['source_excerpt'],
            ], ',', '"', '');
        }

        fclose($handle);
    }

    /**
     * @param array{run: array<string, mixed>, items: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} $stored
     */
    private function renderHtml(array $stored): string
    {
        $run = $stored['run'];
        $items = $stored['items'];
        $warnings = $stored['warnings'];
        $example = $this->selectExample($items);
        $databasePath = $this->relativeToRoot((string) $run['database']);

        $rows = '';
        foreach ($items as $item) {
            $warningCodes = implode(', ', array_map(static fn (array $warning): string => (string) $warning['code'], $item['stored_warnings']));
            $rows .= '<tr>';
            $rows .= '<td>' . $this->e((string) $item['row_number']) . '</td>';
            $rows .= '<td>' . $this->e((string) $item['name']) . '</td>';
            $rows .= '<td>' . $this->e((string) $item['category']) . '</td>';
            $rows .= '<td>' . $this->e((string) $item['size_or_spec']) . '</td>';
            $rows .= '<td>' . $this->e((string) $item['image_ref']) . '</td>';
            $rows .= '<td>' . $this->e(number_format((float) $item['confidence'], 2)) . '</td>';
            $rows .= '<td><span class="status ' . $this->e((string) $item['review_status']) . '">' . $this->e((string) $item['review_status']) . '</span></td>';
            $rows .= '<td>' . $this->e($warningCodes === '' ? 'none' : $warningCodes) . '</td>';
            $rows .= '</tr>';
        }

        $warningRows = '';
        foreach ($warnings as $warning) {
            $warningRows .= '<li><strong>' . $this->e((string) $warning['code']) . '</strong>: ' .
                $this->e((string) $warning['message']) .
                ($warning['source_field'] ? ' <span>(' . $this->e((string) $warning['source_field']) . ')</span>' : '') .
                '</li>';
        }
        if ($warningRows === '') {
            $warningRows = '<li>No warnings were generated for this import.</li>';
        }

        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Workshop Catalogue Import Review Packet</title>
  <style>
    :root { color-scheme: light; --ink: #1e293b; --muted: #526070; --line: #d8dee8; --panel: #f6f8fb; --accent: #0f766e; --warn: #9a3412; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: var(--ink); background: #ffffff; line-height: 1.5; }
    main { max-width: 1120px; margin: 0 auto; padding: 32px 20px 56px; }
    h1 { font-size: 32px; margin: 0 0 8px; letter-spacing: 0; }
    h2 { margin-top: 32px; font-size: 21px; }
    p { max-width: 860px; }
    .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 22px 0; }
    .metric { border: 1px solid var(--line); border-radius: 8px; padding: 14px; background: var(--panel); }
    .metric strong { display: block; font-size: 24px; color: var(--accent); }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
    th, td { border-bottom: 1px solid var(--line); padding: 10px; text-align: left; vertical-align: top; }
    th { background: #eef3f7; font-weight: 700; }
    code { background: #eef3f7; padding: 2px 5px; border-radius: 4px; }
    .status { display: inline-block; border-radius: 6px; padding: 2px 6px; font-size: 12px; font-weight: 700; }
    .ready_for_review { color: #14532d; background: #dcfce7; }
    .needs_human_review { color: var(--warn); background: #ffedd5; }
    .callout { border-left: 4px solid var(--accent); background: #f0fdfa; padding: 14px 16px; margin: 18px 0; }
    .warning-list { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 14px 18px; }
    .warning-list li { margin: 7px 0; }
    .links li { margin: 6px 0; }
    .muted { color: var(--muted); }
  </style>
</head>
<body>
<main>
  <h1>Workshop Catalogue Import Review Packet</h1>
  <p>This packet shows what the importer drafted from a small catalogue sheet before any record is accepted into inventory. It is meant for a coordinator to review names, categories, specifications, images, warnings, and the saved draft evidence.</p>

  <section class="summary" aria-label="Import run summary">
    <div class="metric"><span>Source PDF</span><strong>' . $this->e(basename((string) $run['source_file'])) . '</strong></div>
    <div class="metric"><span>Detected layout</span><strong>' . $this->e((string) $run['layout_id']) . '</strong></div>
    <div class="metric"><span>Draft items</span><strong>' . $this->e((string) $run['draft_item_count']) . '</strong></div>
    <div class="metric"><span>Warnings</span><strong>' . $this->e((string) $run['warning_count']) . '</strong></div>
  </section>

  <section class="callout">
    <h2>Evidence to Decision to Saved Draft</h2>
    <p><strong>Source evidence:</strong> ' . $this->e($example['evidence']) . '</p>
    <p><strong>Field decision:</strong> ' . $this->e($example['decision']) . '</p>
    <p><strong>Saved output:</strong> ' . $this->e($example['output']) . '</p>
  </section>

  <section class="callout">
    <h2>Coordinator Decision Cues</h2>
    <p><strong>Accept for later approval:</strong> a <code>ready_for_review</code> row whose source excerpt matches the draft fields.</p>
    <p><strong>Correct before approval:</strong> a row with ambiguous size, low confidence, unclear notes, or malformed image references.</p>
    <p><strong>Merge or reject:</strong> a possible duplicate after comparing the source excerpt and intended inventory use.</p>
  </section>

  <h2>Draft Inventory Records</h2>
  <table>
    <thead>
      <tr>
        <th>#</th><th>Name</th><th>Category</th><th>Size or spec</th><th>Image ref</th><th>Confidence</th><th>Review cue</th><th>Warnings</th>
      </tr>
    </thead>
    <tbody>' . $rows . '</tbody>
  </table>

  <h2>Warnings for Human Review</h2>
  <ul class="warning-list">' . $warningRows . '</ul>

  <h2>Supporting Files</h2>
  <ul class="links">
    <li><a href="draft_items.json">draft_items.json</a> contains the structured saved draft records and technical evidence.</li>
    <li><a href="draft_items.csv">draft_items.csv</a> is a spreadsheet-friendly view for quick review.</li>
    <li><a href="warnings.json">warnings.json</a> lists all validation and extraction warnings.</li>
    <li><code>' . $this->e($databasePath) . '</code> is the local SQLite database created by the command when the workflow is run.</li>
  </ul>

  <p class="muted">A human reviewer should resolve warnings before approving any draft record for an inventory system.</p>
</main>
</body>
</html>
';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{evidence: string, decision: string, output: string}
     */
    private function selectExample(array $items): array
    {
        $item = $items[0] ?? [];
        foreach ($items as $candidate) {
            if (count($candidate['stored_warnings']) > 0) {
                $item = $candidate;
                break;
            }
        }

        $warningCodes = implode(', ', array_map(static fn (array $warning): string => (string) $warning['code'], $item['stored_warnings'] ?? []));
        $warningText = $warningCodes === '' ? 'no validation warning was needed' : "the draft was marked with {$warningCodes}";

        return [
            'evidence' => (string) ($item['source_excerpt'] ?? 'No source excerpt available.'),
            'decision' => "The importer kept the visible name and category, normalized the category to {$item['category']}, and {$warningText}.",
            'output' => "draft_products#{$item['id']} was saved with review_status={$item['review_status']} and remains editable before approval.",
        ];
    }

    private function relativeToRoot(string $path): string
    {
        $prefix = rtrim($this->rootPath, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
