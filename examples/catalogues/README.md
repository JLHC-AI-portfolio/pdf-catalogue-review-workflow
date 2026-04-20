# Catalogue Fixtures

These PDFs are small, original examples for a community workshop item-record workflow. A non-technical reviewer should treat them as sample sheets that arrive before shared records exist: the importer reads them, drafts records, and asks for review where the source is unclear.

## What To Inspect

- `workshop-layout-a.pdf`: a two-page table-style catalogue with repeated headers, mixed size language, a malformed image reference, scan-style notes, and a likely duplicate.
- `workshop-layout-b.pdf`: a block-style catalogue with normal `Record:` entries plus an alternate `Item Card:` label set that mimics a less consistent intake sheet.

The files are public fixtures, not real source documents. They use harmless workshop supplies and synthetic wording so the workflow can be reviewed without sensitive or commercial data.

## Review Cues

Look for rows where the source uses words such as `assorted`, `approx`, `mixed`, `handwritten`, `shadow`, or `unclear`. Those phrases should become warnings in the review packet rather than silently approved fields.

Image references are also checked. A well-formed reference looks like `images/work-gloves.png`. A value such as `labels-starter.png` is still saved, but it is flagged for review because it would not resolve cleanly in a stricter catalogue workflow.

## Technical Notes

The Python extractor uses `pypdf` to read text from these checked-in PDFs. Layout detection is deterministic:

- Layout A is detected from a table header containing `Item | Name | Category`.
- Layout B is detected from repeated `Record:` or `Item Card:` blocks, then normalizes common label variants such as `Item name`, `Category hint`, `Size/spec`, and `Photo ref`.

The parser emits the same normalized JSON shape consumed by the fallback contract fixture in `examples/input_contract/`.
