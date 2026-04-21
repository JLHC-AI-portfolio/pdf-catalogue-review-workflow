# Per-Layout Review Packets

This folder contains the checked-in review packets for the public catalogue workflow. A non-technical reviewer can open any `index.html` locally and see the same kind of decision aid: source, detected layout, draft count, warning count, draft records, and review cues.

The three layout packets are generated from fallback JSON contracts. That makes the public proof surface reproducible without requiring a Mistral account. The live packet is a captured Mistral OCR sample generated from the synthetic linecard PDF; it is included to show the authenticated OCR branch without publishing secrets or private data.

Each packet's top summary includes `Extraction path` and `Extractor provider` so a reviewer can tell fallback-contract output from live OCR output before reading the detailed rows.

## Packet Index

| Packet | Source PDF | Path | Draft items | Warnings |
| --- | --- | --- | ---: | ---: |
| `municipal-maintenance-linecard/index.html` | `examples/catalogues/municipal-maintenance-linecard.pdf` | fallback contract | 12 | 24 |
| `workshop-equipment-cards/index.html` | `examples/catalogues/workshop-equipment-cards.pdf` | fallback contract | 8 | 12 |
| `storage-family-matrix/index.html` | `examples/catalogues/storage-family-matrix.pdf` | fallback contract | 9 | 29 |
| `live-mistral-ocr-linecard/index.html` | `examples/catalogues/municipal-maintenance-linecard.pdf` | live Mistral OCR capture | 12 | 32 |

## Regenerate Contract Packets

Use scratch SQLite databases so the repo does not publish runtime state:

```bash
php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json --output examples/review_packets/municipal-maintenance-linecard --database /tmp/community-catalogue-packet-linecard.sqlite
php artisan catalogue:import-contract examples/input_contract/workshop-equipment-cards.json --output examples/review_packets/workshop-equipment-cards --database /tmp/community-catalogue-packet-cards.sqlite
php artisan catalogue:import-contract examples/input_contract/storage-family-matrix.json --output examples/review_packets/storage-family-matrix --database /tmp/community-catalogue-packet-matrix.sqlite
```

## Regenerate Live Sample

Use this only when intentionally refreshing the checked-in live sample. It sends the synthetic linecard PDF to Mistral OCR and writes only the normalized review packet:

```bash
test -n "${MISTRAL_API_KEY:-}" || { printf 'Set MISTRAL_API_KEY first\n' >&2; exit 1; }
php artisan catalogue:import examples/catalogues/municipal-maintenance-linecard.pdf \
  --output examples/review_packets/live-mistral-ocr-linecard \
  --database /tmp/community-catalogue-live-mistral-linecard.sqlite \
  --python .venv/bin/python \
  --extractor-provider mistral_ocr
```

After regenerating the live sample, search the output for secret markers before publishing.
