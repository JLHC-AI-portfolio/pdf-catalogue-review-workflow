# Methodology

This document explains why the importer is designed as a review-first workflow instead of a silent data loader. A non-technical reviewer should take away that uncertain catalogue fields are preserved, flagged, and sent to human review before they can become accepted item records.

## Assumptions

The public fixtures model synthetic supplier-style catalogue PDFs used by a community workshop. They are text-readable so the default path stays reproducible, but they include operationally realistic friction: dense linecards, product-card grids, family matrices, repeated headers, page breaks, shared images, cross-page references, mixed size language, source-quality cues, malformed image references, and likely duplicates. That keeps the reference runnable without OCR while still exercising review behavior beyond a perfect table.

The workflow assumes:

- each catalogue contains a bounded but non-trivial set of item candidates,
- layouts may vary between linecard tables, card grids, and family/variant matrices,
- source text can include approximate terms or source-quality notes,
- image references may be present but not always well formed,
- draft records are useful only when paired with source evidence and warnings.

## Text Extraction And Preprocessing

The default PDF boundary is deliberately narrow. Python uses `pypdf` to extract text from each page and inserts page markers before parsing. That keeps page evidence available downstream and makes dependency failure easy to separate from validation failure.

For a live provider check, the optional `mistral_ocr` provider sends the PDF to Mistral OCR, receives page-level markdown, and then inserts the same page markers. That path is useful for complex catalogues where local text extraction loses table structure, but it remains a preprocessing boundary: validation and approval rules do not change just because an external OCR service was used.

Preprocessing normalizes whitespace but does not rewrite the source excerpt. The saved excerpt should remain close enough to the source text for a reviewer to understand where the field came from.

## Layout Detection

The parser uses deterministic layout detection because the public fixtures are constrained:

- `municipal-maintenance-linecard` is detected by a `SKU | Item | Family` linecard header.
- `workshop-equipment-cards` is detected by repeated `Product Card:` blocks.
- `storage-family-matrix` is detected by `Family Matrix:` sections with inherited category and image fields.

This is intentionally conservative. Known label variants inside card layouts are normalized, matrix rows inherit explicit family-level context, and unknown layouts return an empty item list plus a blocking warning instead of guessing.

## Row Grouping And Field Normalization

Linecards are parsed by grouping wrapped pipe-delimited rows. Product cards are parsed by collecting repeated key-value blocks. Family matrices are parsed by collecting variant rows that inherit category and shared image references from the surrounding family section. All layouts emit the same JSON contract:

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

Python warning rules catch approximate size language, source notes that mention unclear, handwritten, shadowed, smudged, cropped, or estimated fields, cross-page references, and missing image references. PHP warning rules then catch:

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
