# PDF/OCR Catalogue Review Workflow

Prepare a workshop catalogue import for review: the checked-in packet set shows three catalogue layouts plus one live OCR sample, with the source evidence a person should check before accepting any item.

Start with the packet index: [examples/review_packets/README.md](examples/review_packets/README.md). For the quickest rendered proof surface after cloning, open [examples/review_packets/municipal-maintenance-linecard/index.html](examples/review_packets/municipal-maintenance-linecard/index.html). Each packet shows the source fixture, extraction path, extractor provider, detected layout, draft count, warning count, draft table, coordinator decision cues, and one evidence-to-decision example before any raw JSON needs to be opened.

One quick decision cue:

| Source evidence | Warning surfaced | Review cue |
| --- | --- | --- |
| `S/M/L mixed carton` | ambiguous size and low confidence | confirm the size mix before accepting the draft |
| `see chart C-2 for adhesive` | cross-page reference | open the referenced chart before approving the record |
| `floor-tape-set.png` | malformed image reference | correct the asset path before importing |

When browsing on GitHub, use this README first; GitHub shows checked-in HTML as source markup. After cloning, open the packet locally or run the 60-second check below to render the same review surface from scratch.

## The Situation

A community workshop or tool library receives supplier-style catalogues before shared item records exist. Some pages look like dense linecards, others look like product cards or family matrices. They are readable by people, but they are inconsistent enough that copying them straight into a shared catalogue log would create review debt.

## The Problem

Names, categories, sizes, image references, and notes can be missing, approximate, duplicated, or partly unclear. Automation can draft records faster than a person can retype them, but the workflow should not silently approve uncertain data.

## Why Build This

This repo demonstrates a bounded review-first workflow. A coordinator can see what was extracted, why a warning was attached, and which rows need correction, merge, rejection, or later approval before any shared catalogue update.

## What Goes In

Inputs:

- Checked-in synthetic PDF fixtures in [examples/catalogues](examples/catalogues): a dense linecard, an equipment-card catalogue, and a family/variant matrix.
- Fallback normalized JSON contracts in [examples/input_contract](examples/input_contract), one per public catalogue format.
- Optional live OCR settings from [.env.example](.env.example) when a reviewer wants to process a PDF through Mistral OCR with their own API key.

## What Comes Out

The main output is [examples/review_packets](examples/review_packets), with one review packet per public layout and one captured live OCR sample. Each packet is backed by:

- `draft_items.json`: structured saved draft records with evidence.
- `draft_items.csv`: spreadsheet-friendly draft review table.
- `warnings.json`: validation and extraction warnings.
- a local SQLite database generated only when a command is run with `--database`; the public commands below use `/tmp` scratch paths so routine review does not modify the repo.

## Where AI Helps

The optional `mistral_ocr` path can send a PDF to Mistral OCR and receive page-level markdown for the same parser and validation pipeline. That live path is useful when a document-processing boundary needs to be exercised, but it is still only preprocessing. OCR output remains draft evidence, not an approval decision.

The contract packets are generated from deterministic fallback contracts so they remain reproducible without secrets. They prove the normalization contract, PHP validation, SQLite persistence, warning logic, and packet generation. The checked-in live OCR sample is generated from a synthetic public fixture with Mistral OCR; it proves the external OCR boundary can feed the same downstream workflow without publishing secrets or private data.

## Where Rules And Review Remain

Python extracts text or OCR markdown and normalizes it into a JSON contract. PHP validates that contract, persists draft records, records warnings, and writes the review packet. Deterministic checks flag missing names, unknown categories, ambiguous sizes or specs, cross-page references, likely duplicates, missing or malformed image references, low confidence, and extraction warnings.

Any `needs_human_review` row should be checked before an acceptance decision. A `ready_for_review` row only means the deterministic checks found no warning in that run; it is still a draft, not an approved item record. The source excerpt stays with each row so the reviewer can compare evidence to the saved draft.

## Scope Limits

Fixtures are intentionally harmless and synthetic, but they are shaped to resemble real catalogue problems: multi-page linecards, product-card grids, family matrices, shared images, continuation notes, footnotes, mixed units, and uncertain source quality. SQLite files created during review are local runtime state, not production data stores. The workflow does not include authentication, queues, deployment, non-public input feeds, image asset storage, role-based approval, live-provider cost controls, or large-scale accuracy tuning.

## Problem Class And Stack Fit

The plain-language problem class is converting varied, semi-structured catalogue-like documents into structured draft records while keeping ambiguous data visible for review. This pattern fits teams that need import preparation, traceability, and review queues more than they need fully automatic acceptance.

Technical pattern:

- Python handles local PDF text extraction, optional OCR-provider preprocessing, layout parsing, normalization, and source evidence capture.
- Mistral OCR is a first-class optional live boundary configured with environment variables; the fallback JSON contract is the reproducible no-secret path.
- PHP provides a Laravel-compatible command boundary, deterministic validation layer, SQLite persistence, and review-packet generation.
- The human-readable packet is the primary proof surface; JSON, CSV, warnings, and SQLite are supporting evidence.
- The import boundary is shaped so the same contract could feed a fuller Laravel application later.
- Extension points include new document layouts, new warning rules, different OCR providers, stricter category maps, or a different import target.
- This pattern is a poor fit for very poor scans, highly image-heavy catalogues, private catalogues that cannot leave a controlled environment, domain-specific acceptance rules that are not yet agreed, or production deployment work that needs security and operations design.

## Evidence to Decision to Output Example

One fixture row says:

```text
LC-104 | Bolt label refill cards | paint labeling | 120 cards; see chart C-2 for adhesive | images/bolt-labels.png | chart_ref=C-2; note=adhesive family continued on next page
```

The importer keeps the visible name and category, preserves the chart reference, and flags the row because part of the decision lives outside the row itself. PHP also preserves the warning and stores the draft in SQLite and in the review packet with `review_status=needs_human_review`.

For a coordinator, the decision path is:

- accept a `ready_for_review` row only after the source excerpt matches the draft fields;
- correct rows with unclear size, low confidence, or malformed image references before approval;
- merge or reject possible duplicates after comparing whether they are true duplicates or legitimate variants.

## 60-Second Review

For a quick, non-destructive verification without installing Python PDF dependencies:

```bash
OUT=/tmp/community-catalogue-review
DB=/tmp/community-catalogue-review.sqlite
php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json --output "$OUT" --database "$DB"
printf 'Review packet: %s\n' "$OUT/index.html"
```

Expected summary for the checked-in fallback contract: `Draft items: 12` and `Warnings: 24`. The matching checked-in packet is `examples/review_packets/municipal-maintenance-linecard/index.html`. The card and matrix contracts have their own checked-in packets under `examples/review_packets/`.

That command uses the same PHP validation, SQLite persistence, warning logic, and review-packet generation as the PDF import. It does not test PDF text extraction or authenticated OCR; it tests the import contract after extraction without overwriting the checked-in review packet.

## Install

PHP dependencies use Composer. The project has no third-party PHP packages, but Composer still provides the standard autoload path:

```bash
composer install
```

Python dependencies use a virtual environment. Python 3.11 is recommended when available because it avoids surprises from very new interpreter releases:

```bash
python3.11 -m venv .venv
.venv/bin/python -m pip install --disable-pip-version-check -r requirements.txt
```

The local-only `.venv/`, `vendor/`, and `var/` paths are excluded through `.git/info/exclude`, not through a tracked `.gitignore`. The commands below use `/tmp` output and database paths so a reviewer does not need to prepare repo-local ignores before trying the workflow.

## Run

Smoke-check the SQLite schema in a scratch database. The import commands also migrate their selected database automatically, so this command is only a bounded schema check:

```bash
DB=/tmp/community-catalogue-migrate.sqlite
php artisan migrate --database "$DB"
```

Run the primary PDF import path:

```bash
OUT=/tmp/community-catalogue-pdf-review
DB=/tmp/community-catalogue-pdf-review.sqlite
php artisan catalogue:import examples/catalogues/municipal-maintenance-linecard.pdf \
  --output "$OUT" \
  --database "$DB" \
  --python .venv/bin/python
printf 'Review packet: %s\n' "$OUT/index.html"
```

If that PDF command reports that `pypdf` is missing, use the fallback contract command below with both `--output` and `--database` set to scratch paths. Routine checks should not point fallback output at `examples/review_packets` unless you are intentionally refreshing tracked artifacts.

The other public catalogue formats use the same command shape:

```bash
php artisan catalogue:import examples/catalogues/workshop-equipment-cards.pdf --output /tmp/community-catalogue-cards-review --database /tmp/community-catalogue-cards.sqlite --python .venv/bin/python
php artisan catalogue:import examples/catalogues/storage-family-matrix.pdf --output /tmp/community-catalogue-matrix-review --database /tmp/community-catalogue-matrix.sqlite --python .venv/bin/python
```

Run the optional Mistral OCR path with your own key:

```bash
OUT=/tmp/community-catalogue-mistral-review
DB=/tmp/community-catalogue-mistral-review.sqlite
test -n "${MISTRAL_API_KEY:-}" || { printf 'Set MISTRAL_API_KEY first\n' >&2; exit 1; }
export CATALOGUE_EXTRACTOR_PROVIDER=mistral_ocr
export MISTRAL_OCR_ENDPOINT=https://api.mistral.ai/v1/ocr
export MISTRAL_OCR_MODEL=mistral-ocr-latest
export MISTRAL_OCR_TABLE_FORMAT=markdown
export MISTRAL_OCR_CONFIDENCE=page

php artisan catalogue:import examples/catalogues/municipal-maintenance-linecard.pdf \
  --output "$OUT" \
  --database "$DB" \
  --python .venv/bin/python \
  --extractor-provider mistral_ocr
printf 'Review packet: %s\n' "$OUT/index.html"
```

That live path sends the PDF to Mistral's OCR API and then reuses the same normalization, validation, SQLite, and review-packet code as the local path. Use it only with documents you are allowed to send to the provider.

Run the fallback contract path:

```bash
OUT=/tmp/community-catalogue-review
DB=/tmp/community-catalogue-review.sqlite
php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json \
  --output "$OUT" \
  --database "$DB"
printf 'Review packet: %s\n' "$OUT/index.html"
```

Those commands use scratch output paths so routine verification does not overwrite the checked-in packets. Refreshing public packets is a maintenance action: rerun the chosen imports with the checked-in packet folders and explicit local runtime database paths only when you intend to update tracked review artifacts.

Fallback contracts are also available for the card and matrix fixtures:

```bash
php artisan catalogue:import-contract examples/input_contract/workshop-equipment-cards.json --output /tmp/community-catalogue-cards-review --database /tmp/community-catalogue-cards.sqlite
php artisan catalogue:import-contract examples/input_contract/storage-family-matrix.json --output /tmp/community-catalogue-matrix-review --database /tmp/community-catalogue-matrix.sqlite
```

Checked-in per-layout packets are regenerated from the fallback contracts with the same command shape:

```bash
php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json --output examples/review_packets/municipal-maintenance-linecard --database /tmp/community-catalogue-packet-linecard.sqlite
php artisan catalogue:import-contract examples/input_contract/workshop-equipment-cards.json --output examples/review_packets/workshop-equipment-cards --database /tmp/community-catalogue-packet-cards.sqlite
php artisan catalogue:import-contract examples/input_contract/storage-family-matrix.json --output examples/review_packets/storage-family-matrix --database /tmp/community-catalogue-packet-matrix.sqlite
```

The checked-in live OCR sample is regenerated only when a reviewer has a valid Mistral secret and intends to refresh the published sample:

```bash
test -n "${MISTRAL_API_KEY:-}" || { printf 'Set MISTRAL_API_KEY first\n' >&2; exit 1; }
php artisan catalogue:import examples/catalogues/municipal-maintenance-linecard.pdf \
  --output examples/review_packets/live-mistral-ocr-linecard \
  --database /tmp/community-catalogue-live-mistral-linecard.sqlite \
  --python .venv/bin/python \
  --extractor-provider mistral_ocr
```

## Test

Python parser tests:

```bash
PYTHONPATH=python .venv/bin/python -m pytest tests/python
```

PHP import contract test:

```bash
php tests/php/ImportContractTest.php
```

## Technical Pointers

- [docs/architecture.md](docs/architecture.md) explains the PHP/Python/SQLite boundaries.
- [docs/methodology.md](docs/methodology.md) explains extraction, validation, confidence, warnings, and review checkpoints.
- [docs/output-reading-guide.md](docs/output-reading-guide.md) explains how to read the HTML, JSON, CSV, warnings, and database.
- [docs/live-and-fallback-paths.md](docs/live-and-fallback-paths.md) explains what the PDF path proves and what the JSON fallback proves.
- [examples/catalogues/README.md](examples/catalogues/README.md) explains the fixture inputs.
- [examples/review_packets/README.md](examples/review_packets/README.md) indexes the generated contract packets and the live OCR sample.

## Rights

- Author: Juan Luis Herrera Cortijo
- Contact: juan.luis.herrera.cortijo@gmail.com
- GitHub: https://github.com/JLHerreraCortijo
- Copyright (c) 2026 Juan Luis Herrera Cortijo. All rights reserved.
- License: Portfolio Review License
- Third-party dependencies retain their own licenses.
