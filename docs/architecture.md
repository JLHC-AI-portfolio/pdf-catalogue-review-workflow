# Architecture

This document explains how the importer is split across Python, PHP, and SQLite. A non-technical reviewer should take away that PDF extraction is separate from record approval: the system drafts records, validates them, stores them locally, and produces a review packet before anything is treated as final.

## Components

- `python/catalogue_extractor/`: extracts text from PDFs and normalizes public fixture layouts into JSON.
- `python/catalogue_extractor/providers/`: optional OCR providers, including a Mistral OCR adapter.
- `artisan`: a small command entry point with Laravel-style command names.
- `src/CatalogueImporter/`: PHP application code for command routing, validation, persistence, and review packet generation.
- `database/migrations/`: SQLite schema used by the PHP layer.
- `examples/catalogues/`: checked-in public PDF fixtures.
- `examples/input_contract/`: fallback JSON contract fixture.
- `examples/review_packet/`: generated review files for human inspection.

## Data Flow

1. A reviewer runs `php artisan catalogue:import <pdf> --output <review-packet-dir>`.
2. PHP calls the Python extractor as a subprocess with `PYTHONPATH=python`.
3. Python uses the default `local_text` provider or an optional provider such as `mistral_ocr`, then emits normalized JSON with source evidence and preliminary warnings, including common Layout B label variants.
4. PHP validates the contract and adds deterministic warnings.
5. PHP migrates and writes to SQLite.
6. PHP writes `index.html`, `draft_items.json`, `draft_items.csv`, and `warnings.json`.

The fallback command starts at step 4 by reading a checked-in JSON contract generated from the same public item set as the main PDF fixture. The optional Mistral OCR path still enters the same downstream validation, SQLite, and review-packet flow after OCR.

## Why The Boundary Is Useful

The Python side owns PDF text extraction and layout parsing because Python has mature PDF tooling and concise text processing. The PHP side owns validation, persistence, and review output because it represents the application boundary a Laravel codebase would normally control.

This split keeps the contract visible:

- Python can be tested against text fixtures and provider response fixtures.
- PHP can be tested without PDF dependencies.
- The JSON shape is stable enough to replace the extractor later without rewriting persistence.

## Laravel Compatibility

The command names follow Laravel's `php artisan domain:action` convention:

- `catalogue:import`
- `catalogue:import-contract`

The implementation is intentionally lightweight rather than a full Laravel installation. It keeps the public repo easy to run while preserving the important application boundaries: command entry point, validation service, migration files, persistence, and generated review output.

In a production Laravel application, these classes would usually move behind an actual Artisan command class, service container bindings, configured storage disks, and application database connections.

## Persistence Schema

SQLite tables:

- `import_runs`: import metadata and counts.
- `draft_products`: draft item records and review status.
- `image_references`: extracted image references and path-format status.
- `import_warnings`: run-level and item-level warnings.

The schema is intentionally close to the review workflow. It stores warning evidence alongside drafts so later approval work does not lose the reason a field was flagged.

## Failure Modes

- If `pypdf` is missing, the PDF command exits with instructions to install Python requirements or run the JSON fallback.
- If `mistral_ocr` is selected without `MISTRAL_API_KEY`, the extractor fails before any database write.
- If Mistral OCR returns empty pages or an HTTP error, the provider failure stays isolated from PHP validation.
- If a PDF layout is unknown, Python emits a blocking warning instead of guessing.
- If the JSON contract is malformed, PHP fails before persistence.
- If fields are uncertain but structurally valid, PHP saves the draft and marks it for human review.
