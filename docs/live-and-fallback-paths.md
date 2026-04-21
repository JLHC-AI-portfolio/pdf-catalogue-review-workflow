# Live And Fallback Paths

This document explains what each runnable path proves. A non-technical reviewer should take away that the PDF path exercises the whole workflow, while the JSON fallback keeps review and persistence reproducible on machines that cannot run the PDF dependency.

## Primary PDF Path

Command:

```bash
OUT=/tmp/community-catalogue-pdf-review
DB=/tmp/community-catalogue-pdf-review.sqlite
php artisan catalogue:import examples/catalogues/municipal-maintenance-linecard.pdf \
  --output "$OUT" \
  --database "$DB" \
  --python .venv/bin/python
printf 'Review packet: %s\n' "$OUT/index.html"
```

What it proves:

- the PDF fixture can be read locally,
- text extraction reaches the Python parser,
- layout detection works for the fixture,
- Python emits the documented JSON contract,
- PHP validates and persists the draft records,
- the review packet is generated from saved data.

The local text path is deterministic and exercises a dense linecard fixture with continuation notes, duplicate risk, malformed image references, and cross-page cues. Warning counts can differ on provider-backed OCR runs because OCR may split or preserve source text differently; use the fallback contract when you want to reproduce the checked-in public output without a secret.

If this command fails because `pypdf` is not installed, use the contract fallback command below. For routine checks, keep both `--output` and `--database` on scratch paths such as `/tmp` so the checked-in packet and repo-local runtime state are not changed by accident.

Runtime requirements:

- PHP with `pdo_sqlite`,
- Composer autoload or the built-in fallback autoload,
- Python environment with `pypdf`,
- local write access to the chosen scratch output and SQLite paths.

## Optional Mistral OCR Path

This path is for reviewers who want to test a live OCR provider with their own API key. It sends the selected PDF to Mistral OCR, receives page-level markdown, and then uses the same parser, PHP validation, SQLite persistence, and review-packet generation as the local path.

Environment:

```dotenv
CATALOGUE_EXTRACTOR_PROVIDER=mistral_ocr
MISTRAL_API_KEY=replace-with-your-key
MISTRAL_OCR_ENDPOINT=https://api.mistral.ai/v1/ocr
MISTRAL_OCR_MODEL=mistral-ocr-latest
MISTRAL_OCR_TABLE_FORMAT=markdown
MISTRAL_OCR_CONFIDENCE=page
```

The same names are shown in `.env.example`. The reviewer supplies `MISTRAL_API_KEY` from their own Mistral account or secret manager. `MISTRAL_OCR_ENDPOINT` defaults to `https://api.mistral.ai/v1/ocr` in code, and `MISTRAL_OCR_MODEL`, `MISTRAL_OCR_TABLE_FORMAT`, and `MISTRAL_OCR_CONFIDENCE` are passed as the OCR request's model, table-format, and confidence-granularity configuration. A reviewer can override those values later if their provider account or current provider documentation requires a different supported value.

Command:

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

What it proves:

- a real external OCR service can read the PDF boundary,
- OCR markdown can enter the same normalized contract path,
- provider failures stay separate from PHP validation and persistence failures.

What it does not prove:

- that the provider should be used for every document,
- that OCR output is correct without human review,
- that provider cost, latency, or data-handling policy fits a production environment.

Use this path only with documents you are allowed to send to Mistral. The checked-in fixtures remain synthetic and public. When `MISTRAL_API_KEY` is unavailable, this repo can still validate fallback ingestion, persistence, warnings, and packet generation, but the live OCR boundary remains unexecuted until a reviewer runs the command above with a real key.

## Contract Fallback Path

Non-destructive quick command:

```bash
OUT=/tmp/community-catalogue-review
DB=/tmp/community-catalogue-review.sqlite
php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json \
  --output "$OUT" \
  --database "$DB"
```

The current primary fallback contract should produce twelve draft items and twenty-four warnings in that scratch packet. The fallback contract is generated from the same synthetic linecard item set as `examples/review_packets/municipal-maintenance-linecard/`, so the headline count should match the checked-in proof surface. If you intentionally want to refresh a checked-in packet, replace the scratch output and database paths with the selected packet folder and an explicit scratch database path.

Do not use `examples/review_packets` subfolders as fallback output paths during normal verification. Those folders are the checked-in review surface and should only be overwritten during an intentional artifact refresh.

What it proves:

- the JSON contract shape is accepted,
- PHP validation catches uncertain fields,
- drafts and warnings persist to SQLite,
- HTML, JSON, CSV, and warning outputs are generated.

What it does not prove:

- PDF text extraction,
- layout detection from PDF text,
- dependency readiness for `pypdf`.

Use this path for quick review or for machines where Python PDF dependencies are not installed.

## Checked-In Packet Set

The checked-in packet artifacts include reproducible contract-generated packets and one captured live OCR sample. The contract packets keep the public repo reviewable without asking a reviewer for a secret. The live sample demonstrates that the external OCR boundary can feed the same workflow when a reviewer supplies a Mistral key.

- `examples/review_packets/municipal-maintenance-linecard/`: linecard packet, 12 draft items and 24 warnings.
- `examples/review_packets/workshop-equipment-cards/`: product-card packet, 8 draft items and 12 warnings.
- `examples/review_packets/storage-family-matrix/`: family-matrix packet, 9 draft items and 29 warnings.
- `examples/review_packets/live-mistral-ocr-linecard/`: captured Mistral OCR sample from the same synthetic linecard PDF, 12 draft items and 32 warnings.

Routine live Mistral runs should use `/tmp` output paths. Refreshing the checked-in live sample is a maintenance action and should be followed by a scan for secret markers before publication.

## External Providers

No external OCR, database, or AI provider is required for the deterministic review path. The Mistral OCR path is optional and uses the reviewer's own secret when they want to exercise a live document-processing boundary. The fallback walkthrough should not be read as evidence that provider authentication, provider latency, billing, or OCR quality has been accepted.

Any provider-backed OCR or model-assisted extractor should remain a separate adapter that:

- uses environment variables for secrets,
- emits the same JSON contract,
- has contract tests that run without secrets,
- documents the exact live command and what data may leave the local machine,
- verifies any provider model or API constant against an authoritative source before hard-coding it.

The deterministic fallback should remain available even when a provider adapter exists, but it should not be used as proof that the provider boundary works.
