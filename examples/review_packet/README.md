# Review Packet Guide

This folder contains the review surface a coordinator should inspect after an import. Start with `index.html`: it explains the source file, detected layout, draft count, warning count, draft records, and one evidence-to-decision example without requiring code knowledge.

When reading in GitHub, treat this guide as the first view because GitHub displays the checked-in HTML as source markup. After cloning, open `index.html` in a browser, or run the non-destructive command below and open the fresh `/tmp/.../index.html` packet.

## Fast Reader Route

1. Open `index.html` locally and confirm the source, layout, six draft items, and fifteen warnings in the summary.
2. Skim the draft table for `needs_human_review` rows before opening any JSON.
3. Use the evidence-to-decision example to check whether each warning points to a clear source excerpt and a concrete review action.

## Backing Files

- `index.html`: primary human-readable review packet.
- `draft_items.json`: structured draft records, saved IDs, source excerpts, confidence, attributes, and review status.
- `draft_items.csv`: table view for spreadsheet review.
- `warnings.json`: warning list for fields that should not move forward without a human check.

Public commands in this repo set `--database` to a `/tmp` scratch path. If that flag is omitted, the CLI defaults to `var/catalogue_imports.sqlite`; either way, the SQLite database is runtime state rather than the first file to review.

## How To Read The Packet

Use `index.html` first. Rows marked `ready_for_review` had no validation warnings in the run that produced the packet. That status is not approval; it means the row can be reviewed without an automated warning attached. Rows marked `needs_human_review` should be checked against the source excerpt before any acceptance decision.

Then open `warnings.json` if you need the exact technical reason for a flag. Common warning codes include:

- `AMBIGUOUS_SPEC`: the size or specification uses approximate language.
- `SOURCE_NOTE_REQUIRES_REVIEW`: the source note says part of the row is unclear.
- `MALFORMED_IMAGE_REF`: the image reference does not match the expected path pattern.
- `LOW_CONFIDENCE`: the extractor confidence is below the threshold.
- `POSSIBLE_DUPLICATE`: another row in the same import looks like the same item.

## Regenerating

For a non-destructive verification, write a fresh packet outside the repo and compare the CLI summary with the files from that same run:

```bash
OUT=/tmp/community-catalogue-review
DB=/tmp/community-catalogue-review.sqlite
php artisan catalogue:import-contract examples/input_contract/workshop-layout-a.json \
  --output "$OUT" \
  --database "$DB"
```

The checked-in fallback contract should report six draft items and fifteen warnings in that scratch packet. The checked-in PDF packet was generated from the same Mistral OCR-normalized item set, so the fallback path should agree with the headline count.

## Decision Examples

- Accept for later approval: a `ready_for_review` row whose source excerpt matches the name, category, specification, and image reference in the table.
- Correct first: a row such as `Cut-resistant shop gloves`, where mixed sizing and an unclear source note mean the draft needs a person to choose the final size language.
- Merge or reject: a possible duplicate such as the second gloves row, after deciding whether it is a duplicate or a valid size variant.

```bash
OUT=/tmp/community-catalogue-pdf-review
DB=/tmp/community-catalogue-pdf-review.sqlite
php artisan catalogue:import examples/catalogues/workshop-layout-a.pdf \
  --output "$OUT" \
  --database "$DB" \
  --python .venv/bin/python
printf 'Review packet: %s\n' "$OUT/index.html"
```

Fallback without PDF extraction:

```bash
OUT=/tmp/community-catalogue-review
DB=/tmp/community-catalogue-review.sqlite
php artisan catalogue:import-contract examples/input_contract/workshop-layout-a.json \
  --output "$OUT" \
  --database "$DB"
printf 'Review packet: %s\n' "$OUT/index.html"
```

These examples use scratch output paths so routine verification does not replace the checked-in packet. To intentionally refresh this folder, rerun the chosen import with this folder as the output path and the local runtime database path.
