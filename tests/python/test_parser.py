from catalogue_extractor.parser import parse_catalogue_text


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
    payload = parse_catalogue_text(LAYOUT_A_TEXT, source_file="examples/catalogues/workshop-layout-a.pdf")

    assert payload["layout_id"] == "workshop-layout-a"
    assert len(payload["items"]) == 2
    assert payload["items"][1]["category"] == "paint-labeling"
    assert payload["items"][1]["source_page"] == 1
    warning_codes = {warning["code"] for warning in payload["items"][1]["warnings"]}
    assert "AMBIGUOUS_SPEC" in warning_codes


def test_layout_b_parser_supports_block_layout():
    payload = parse_catalogue_text(LAYOUT_B_TEXT, source_file="examples/catalogues/workshop-layout-b.pdf")

    assert payload["layout_id"] == "workshop-layout-b"
    assert payload["items"][0]["name"] == "Stackable parts bin"
    assert payload["items"][0]["category"] == "storage"
    assert payload["items"][0]["attributes"]["material"] == "polypropylene"
    assert payload["items"][1]["name"] == "Mixed screw sample bag"
    assert payload["items"][1]["category"] == "fasteners"
    warning_codes = {warning["code"] for warning in payload["items"][1]["warnings"]}
    assert "SOURCE_NOTE_REQUIRES_REVIEW" in warning_codes
