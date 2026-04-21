# Catalogue Fixtures

These PDFs are synthetic public catalogues for a community workshop item-record workflow. A non-technical reviewer should treat them as supplier-style documents that arrive before shared records exist: the importer reads them, drafts records, and asks for review where the source is unclear.

The files are not real supplier catalogues. They use harmless workshop supplies, invented SKUs, embedded synthetic thumbnails, and original wording so the workflow can be reviewed without sensitive or commercial data.

## What To Inspect

- `municipal-maintenance-linecard.pdf`: a dense multi-page linecard with SKU rows, repeated headers, continuation notes, mixed units, approximate specs, malformed image references, and duplicate risk.
- `workshop-equipment-cards.pdf`: a product-card catalogue with two-column cards, thumbnail panels, supplier-specific attributes, missing image references, low-contrast review cues, and card-level notes.
- `storage-family-matrix.pdf`: a family/variant matrix with shared images, inherited categories, accessory rows, footnotes, cross-page references, and variants that should not be merged automatically.

## Review Cues

Look for rows where the source uses words such as `assorted`, `approx`, `about`, `unknown`, `cropped`, `low contrast`, `see chart`, `see footnote`, `shared image`, or `continued`. Those phrases should become warnings in the review packet rather than silently approved fields.

Image references are also checked. A well-formed reference looks like `images/work-gloves.png`. A value such as `floor-tape-set.png` is still saved, but it is flagged for review because it would not resolve cleanly in a stricter catalogue workflow. A blank image reference is also saved with a warning rather than invented.

## Technical Notes

The Python extractor uses `pypdf` to read text from these checked-in PDFs. Layout detection is deterministic:

- `municipal-maintenance-linecard` is detected from `MUNICIPAL MAINTENANCE LINECARD` plus a `SKU | Item | Family` header.
- `workshop-equipment-cards` is detected from `WORKSHOP EQUIPMENT CARD CATALOG` plus repeated `Product Card:` blocks.
- `storage-family-matrix` is detected from `STORAGE FAMILY MATRIX` plus repeated `Family Matrix:` sections.

Each fixture has a matching normalized JSON fallback contract in `examples/input_contract/`. The parser and fallback contracts emit the same normalized JSON shape consumed by the PHP validation, SQLite persistence, and review-packet writer.
