<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

final class ConsoleApplication
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'help', 'list' => $this->showHelp(),
                'migrate' => $this->migrate($args),
                'catalogue:import' => $this->importPdf($args),
                'catalogue:import-contract' => $this->importContract($args),
                default => $this->unknownCommand($command),
            };
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function showHelp(): int
    {
        $help = <<<TEXT
Community Catalogue Importer

Commands:
  php artisan migrate [--database var/catalogue_imports.sqlite]
  php artisan catalogue:import <pdf-path> --output <output-dir> [--database <sqlite-path>] [--python <python-bin>] [--extractor-provider local_text|mistral_ocr]
  php artisan catalogue:import-contract <json-path> --output <output-dir> [--database <sqlite-path>]

The PDF command calls the Python extractor, then uses the same PHP validation,
SQLite persistence, and review-packet generation path as the JSON contract command.

TEXT;
        fwrite(STDOUT, $help);
        return 0;
    }

    private function migrate(array $args): int
    {
        [, $options] = $this->parseArguments($args);
        $databasePath = $this->resolvePath($options['database'] ?? 'var/catalogue_imports.sqlite');
        (new Database($this->rootPath, $databasePath))->migrate();
        fwrite(STDOUT, "Migrated SQLite database: {$this->relativeToRoot($databasePath)}" . PHP_EOL);
        return 0;
    }

    private function importPdf(array $args): int
    {
        [$positionals, $options] = $this->parseArguments($args);
        $pdfPath = $positionals[0] ?? null;
        if ($pdfPath === null) {
            throw new \InvalidArgumentException('catalogue:import requires a PDF path.');
        }

        $outputPath = $this->requiredOptionPath($options, 'output');
        $databasePath = $this->resolvePath($options['database'] ?? 'var/catalogue_imports.sqlite');
        $python = $options['python'] ?? null;
        $provider = $options['extractor-provider'] ?? null;

        $service = new ImportService($this->rootPath);
        $summary = $service->importPdf(
            $this->resolvePath($pdfPath),
            $outputPath,
            $databasePath,
            $python,
            $provider
        );

        $this->printSummary($summary);
        return 0;
    }

    private function importContract(array $args): int
    {
        [$positionals, $options] = $this->parseArguments($args);
        $jsonPath = $positionals[0] ?? null;
        if ($jsonPath === null) {
            throw new \InvalidArgumentException('catalogue:import-contract requires a JSON path.');
        }

        $service = new ImportService($this->rootPath);
        $summary = $service->importContractFile(
            $this->resolvePath($jsonPath),
            $this->requiredOptionPath($options, 'output'),
            $this->resolvePath($options['database'] ?? 'var/catalogue_imports.sqlite')
        );

        $this->printSummary($summary);
        return 0;
    }

    /**
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function parseArguments(array $args): array
    {
        $positionals = [];
        $options = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $nameAndValue = substr($arg, 2);
                if (str_contains($nameAndValue, '=')) {
                    [$name, $value] = explode('=', $nameAndValue, 2);
                } else {
                    $name = $nameAndValue;
                    $value = $args[$i + 1] ?? null;
                    if ($value === null || str_starts_with($value, '--')) {
                        throw new \InvalidArgumentException("Option --{$name} requires a value.");
                    }
                    $i++;
                }
                $options[$name] = $value;
                continue;
            }

            $positionals[] = $arg;
        }

        return [$positionals, $options];
    }

    /**
     * @param array<string, string> $options
     */
    private function requiredOptionPath(array $options, string $name): string
    {
        if (!isset($options[$name]) || trim($options[$name]) === '') {
            throw new \InvalidArgumentException("Option --{$name} is required.");
        }

        return $this->resolvePath($options[$name]);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->rootPath . '/' . $path;
    }

    private function relativeToRoot(string $path): string
    {
        $prefix = rtrim($this->rootPath, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * @param array{run_id: int, source_file: string, layout_id: string, draft_item_count: int, warning_count: int, review_packet: string, database: string} $summary
     */
    private function printSummary(array $summary): void
    {
        fwrite(STDOUT, "Import run {$summary['run_id']} completed" . PHP_EOL);
        fwrite(STDOUT, "Source: {$this->relativeToRoot($summary['source_file'])}" . PHP_EOL);
        fwrite(STDOUT, "Layout: {$summary['layout_id']}" . PHP_EOL);
        fwrite(STDOUT, "Draft items: {$summary['draft_item_count']}" . PHP_EOL);
        fwrite(STDOUT, "Warnings: {$summary['warning_count']}" . PHP_EOL);
        fwrite(STDOUT, "Review packet: {$this->relativeToRoot($summary['review_packet'])}/index.html" . PHP_EOL);
        fwrite(STDOUT, "SQLite database: {$this->relativeToRoot($summary['database'])}" . PHP_EOL);
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}" . PHP_EOL . PHP_EOL);
        $this->showHelp();
        return 1;
    }
}
