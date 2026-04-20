# Methodology

This document explains why the importer is designed as a review-first workflow instead of a silent data loader. A non-technical reviewer should take away that uncertain catalogue fields are preserved, flagged, and sent to human review before they can become accepted item records.

## Assumptions

The public fixtures model small catalogue PDFs used by a community workshop. They are text-readable, but they include operationally realistic friction such as repeated headers, page breaks, mixed size language, handwritten-note cues, scan-shadow notes, malformed image references, and likely duplicates. That keeps the reference runnable without OCR while still exercising review behavior beyond a perfect table.

The workflow assumes:

- each sheet contains a small number of item rows,
- layouts may vary between table-style, block-style, and mildly inconsistent label variants,
- source text can include approximate terms or source-quality notes,
- image references may be present but not always well formed,
- draft records are useful only when paired with source evidence and warnings.

## Text Extraction And Preprocessing

The default PDF boundary is deliberately narrow. Python uses `pypdf` to extract text from each page and inserts page markers before parsing. That keeps page evidence available downstream and makes dependency failure easy to separate from validation failure.

For a live provider check, the optional `mistral_ocr` provider sends the PDF to Mistral OCR, receives page-level markdown, and then inserts the same page markers. That path is useful for complex catalogues where local text extraction loses table structure, but it remains a preprocessing boundary: validation and approval rules do not change just because an external OCR service was used.

Preprocessing normalizes whitespace but does not rewrite the source excerpt. The saved excerpt should remain close enough to the source text for a reviewer to understand where the field came from.

## Layout Detection

The parser uses deterministic layout detection because the public fixtures are constrained:

- Layout A is detected by a table header containing `Item | Name | Category`.
- Layout B is detected by repeated `Record:` or `Item Card:` blocks.

This is intentionally conservative. Known label variants inside Layout B are normalized, but unknown layouts return an empty item list plus a blocking warning instead of guessing.

## Row Grouping And Field Normalization

Layout A is parsed by splitting table rows on `|`. Layout B is parsed by collecting key-value blocks. Both layouts emit the same JSON contract:

- `source_file`
- `layout_id`
- `items`
- `name`
- `category`
- `size_or_spec`
- `image_ref`
- `attributes`
- `confidence`
- `warnings`
- `source_page`
- `source_excerpt`

Categories are normalized into a small public item list: `safety`, `hand-tools`, `paint-labeling`, `storage`, `fasteners`, `cleaning`, and `other`. Unknown categories are not discarded; they are saved with a warning so a person can decide whether the category list should expand.

## Confidence And Warning Logic

Confidence is a review signal, not an approval score. The Python extractor lowers confidence when source notes or specifications indicate uncertainty. PHP then applies deterministic validation before persistence.

Python warning rules catch approximate size language and source notes that mention unclear, handwritten, shadowed, smudged, cropped, or estimated fields. PHP warning rules then catch:

- missing names,
- unknown categories,
- ambiguous sizes or specifications,
- likely duplicates within the import run,
- malformed image references,
- low confidence,
- warnings already emitted by extraction.

Warnings stay attached to the draft record and are also written to `warnings.json` and `import_warnings`.

## Duplicate Detection

Duplicate detection uses a conservative key based on normalized name and category within a single import run. It does not merge rows automatically. The warning exists to help a reviewer decide whether two rows are truly duplicates, size variants, or separate supplies.

## Human Review Checkpoints

The workflow has three review checkpoints:

1. The HTML packet gives the coordinator a first-pass review queue.
2. JSON and CSV outputs let a reviewer inspect structured evidence.
3. SQLite persistence shows what would be available to a Laravel application before any final acceptance decision.

The automation drafts and flags; a person resolves warnings and makes the final acceptance decision.

## Limits Of This Public Slice

This reference does not include production authentication, role-based approval, queue workers, private application databases, production OCR tuning for scanned or image-heavy PDFs, image downloads, non-public input feeds, or accuracy tuning against a large document set.

Those concerns are intentionally left outside the public slice because they depend on the real operating environment, data quality, approval policy, and stakeholder tolerance for review workload.
