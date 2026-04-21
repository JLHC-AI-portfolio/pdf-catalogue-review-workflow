from pathlib import Path

from catalogue_extractor.parser import parse_catalogue_text
from catalogue_extractor.pdf_text import extract_pdf_text


LAYOUT_A_TEXT = """--- PAGE 1 ---
WORKSHOP CATALOGUE SHEET - Layout A
Item | Name | Category | Size/Spec | Image | Notes
1 | Cut-resistant shop gloves | safety | S/M/L mixed carton, 24 pair | images/cut-resistant-gloves.png | material=aramid knit; pack=24 pair; note=size ratio unclear
2 | Floor tape starter set | paint labeling | 6 rolls, 48 mm x about 25 m | floor-tape-set.png | color=yellow/black; note=roll length estimated
"""


LAYOUT_B_TEXT = """--- PAGE 1 ---
COMMUNITY WORKSHOP IMPORT SHEET - Layout B
Record:
Name: Stackable parts bin
Category: storage
Spec: 10 liter clear bin
Image: images/parts-bin.png
Note: material=polypropylene
Item Card:
Item name: Mixed screw sample bag
Category hint: fasteners
Size/spec: mixed M3-M5, about 80 pieces
Photo ref: screw-sample-bag.png
Review note: thread pitch unclear
"""


def test_layout_a_parser_flags_ambiguous_spec_and_keeps_evidence():
    payload = parse_catalogue_text(LAYOUT_A_TEXT, source_file="unit-fixtures/layout-a.txt")

    assert payload["layout_id"] == "workshop-layout-a"
    assert len(payload["items"]) == 2
    assert payload["items"][1]["category"] == "paint-labeling"
    assert payload["items"][1]["source_page"] == 1
    warning_codes = {warning["code"] for warning in payload["items"][1]["warnings"]}
    assert "AMBIGUOUS_SPEC" in warning_codes


def test_layout_b_parser_supports_block_layout():
    payload = parse_catalogue_text(LAYOUT_B_TEXT, source_file="unit-fixtures/layout-b.txt")

    assert payload["layout_id"] == "workshop-layout-b"
    assert payload["items"][0]["name"] == "Stackable parts bin"
    assert payload["items"][0]["category"] == "storage"
    assert payload["items"][0]["attributes"]["material"] == "polypropylene"
    assert payload["items"][1]["name"] == "Mixed screw sample bag"
    assert payload["items"][1]["category"] == "fasteners"
    warning_codes = {warning["code"] for warning in payload["items"][1]["warnings"]}
    assert "SOURCE_NOTE_REQUIRES_REVIEW" in warning_codes


def test_complex_public_pdf_fixtures_parse_with_distinct_layouts():
    repo_root = Path(__file__).resolve().parents[2]
    expected = {
        "municipal-maintenance-linecard.pdf": ("municipal-maintenance-linecard", 12, 12),
        "workshop-equipment-cards.pdf": ("workshop-equipment-cards", 8, 6),
        "storage-family-matrix.pdf": ("storage-family-matrix", 9, 10),
    }

    for filename, (layout_id, item_count, min_warning_count) in expected.items():
        pdf_path = repo_root / "examples" / "catalogues" / filename
        text = extract_pdf_text(pdf_path)
        payload = parse_catalogue_text(text, source_file=str(pdf_path))
        warning_count = sum(len(item["warnings"]) for item in payload["items"])

        assert payload["layout_id"] == layout_id
        assert len(payload["items"]) == item_count
        assert warning_count >= min_warning_count


def test_family_matrix_inherits_shared_image_and_flags_cross_references():
    repo_root = Path(__file__).resolve().parents[2]
    pdf_path = repo_root / "examples" / "catalogues" / "storage-family-matrix.pdf"
    payload = parse_catalogue_text(extract_pdf_text(pdf_path), source_file=str(pdf_path))

    names = {item["name"] for item in payload["items"]}
    assert "Clear bin tower - 12 bin" in names
    assert "Station label replacement set" in names

    matrix_item = next(item for item in payload["items"] if item["name"] == "Clear bin tower - 12 bin")
    assert matrix_item["image_ref"] == "images/clear-bin-tower-family.png"
    warning_codes = {warning["code"] for warning in matrix_item["warnings"]}
    assert "CROSS_PAGE_REFERENCE" in warning_codes
