<?php

declare(strict_types=1);

namespace CommunityCatalogue\CatalogueImporter;

final class PythonExtractor
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(string $pdfPath, ?string $pythonBinary = null, ?string $provider = null): array
    {
        if (!is_file($pdfPath)) {
            throw new \InvalidArgumentException("PDF not found: {$pdfPath}");
        }

        $pythonBinary = $pythonBinary ?: $this->defaultPythonBinary();
        $command = implode(' ', [
            escapeshellarg($pythonBinary),
            '-m',
            'catalogue_extractor.cli',
            '--input',
            escapeshellarg($pdfPath),
        ]);
        if ($provider !== null && trim($provider) !== '') {
            $command .= ' --provider ' . escapeshellarg($provider);
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->processEnvironment();
        $env['PYTHONPATH'] = $this->rootPath . '/python' . PATH_SEPARATOR . ($env['PYTHONPATH'] ?? '');
        $process = proc_open($command, $descriptors, $pipes, $this->rootPath, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start Python extractor.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($stderr) ?: 'Python extractor failed.');
        }

        $payload = json_decode((string) $stdout, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Python extractor did not emit valid JSON.');
        }

        return $payload;
    }

    private function defaultPythonBinary(): string
    {
        $venvPython = $this->rootPath . '/.venv/bin/python';
        if (is_file($venvPython)) {
            return $venvPython;
        }

        return getenv('PYTHON') ?: 'python3';
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $env = array_merge($_SERVER, $_ENV);
        foreach ([
            'CATALOGUE_EXTRACTOR_PROVIDER',
            'MISTRAL_API_KEY',
            'MISTRAL_OCR_MODEL',
            'MISTRAL_OCR_ENDPOINT',
            'MISTRAL_OCR_TABLE_FORMAT',
            'MISTRAL_OCR_CONFIDENCE',
        ] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        $result = [];
        foreach ($env as $name => $value) {
            if (is_scalar($value)) {
                $result[(string) $name] = (string) $value;
            }
        }

        return $result;
    }
}
