# Output Reading Guide

This guide explains what each generated output means and what a reviewer should do with it. The main takeaway for a non-technical reviewer is simple: the HTML packet is the decision aid, while the JSON, CSV, warnings, and database are evidence files for deeper review.

## Start With The HTML Packet

Open:

```text
examples/review_packets/municipal-maintenance-linecard/index.html
```

That file is the fastest first read. The complete multi-format set lives in `examples/review_packets/`, with contract-generated packet directories for each public layout and one captured live OCR sample.

If you are reading on GitHub, clone the repo or run the 60-second review command from the README before opening the packet; GitHub shows the checked-in HTML as source markup instead of rendering the review view.

The top summary answers four questions:

- Which source PDF was imported?
- Which extraction path produced the packet?
- Which extractor provider was used, if any?
- Which layout was detected?
- How many draft records were created?
- How many warnings need review?

The table is the review queue. A row marked `ready_for_review` only means no automated warning was attached in that run; it is still a draft. A row marked `needs_human_review` should not be accepted until the warning is resolved against the source excerpt.

Use the status as a decision cue, not as the decision itself:

- Accept for later approval when the row is `ready_for_review` and the source excerpt matches the saved fields.
- Correct before approval when a row has ambiguous size, low confidence, unclear notes, a cross-page reference, or a missing/malformed image reference.
- Merge or reject when a row is flagged as a possible duplicate and the source evidence shows it is not a useful separate variant.

## Read The Evidence Example

The `Evidence to Decision to Saved Draft` section connects:

- source evidence from the PDF text,
- the field decision made by the automation,
- the saved draft record that remains editable.

This is the fastest way to judge whether the workflow is making traceable decisions rather than simply producing a table.

## JSON Files

`draft_items.json` is the structured record of what was saved. It includes:

- import run metadata,
- saved draft IDs (`draft_product_id` in the technical files),
- normalized categories,
- image references,
- attributes,
- confidence,
- source page and excerpt,
- review status,
- item-level warnings.

`warnings.json` is the warning register. It is useful when a reviewer wants to filter by warning code or severity.

## CSV File

`draft_items.csv` is intentionally flat. It is useful for a quick spreadsheet pass, but it should not be treated as the source of truth because nested attributes and warning details are richer in JSON and SQLite.

## SQLite Database

The public commands write the local database to a scratch path selected with `--database`, for example:

```text
/tmp/community-catalogue-review.sqlite
```

If `--database` is omitted, the CLI uses `var/catalogue_imports.sqlite` as a local runtime default. Treat either database location as scratch evidence from a run, not as a file to publish or review before the HTML packet.

The schema has four tables:

- `import_runs`: one row per import attempt.
- `draft_products`: saved draft item records.
- `image_references`: image references extracted from drafts.
- `import_warnings`: validation and extraction warnings tied to a run or item.

The database is runtime state, so it is not checked in. The migration SQL is checked in under `database/migrations/`.

## Review Checklist

- Confirm the detected layout matches the source PDF shape.
- Check the warning count in the HTML against `warnings.json` from the same output directory before inspecting individual rows.
- For each warning, compare `source_excerpt` to the saved field.
- Confirm duplicate warnings are plausible before merging or rejecting rows.
- Treat low-confidence and ambiguous-size rows as unresolved until a person chooses the final value.
- Open referenced charts, footnotes, or family sections before accepting rows with `CROSS_PAGE_REFERENCE`.
