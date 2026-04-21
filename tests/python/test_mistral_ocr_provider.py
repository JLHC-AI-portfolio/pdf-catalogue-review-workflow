from catalogue_extractor.parser import parse_catalogue_text
from catalogue_extractor.providers.mistral_ocr import ocr_response_to_text


MISTRAL_OCR_RESPONSE = {
    "model": "mistral-ocr-latest",
    "pages": [
        {
            "index": 0,
            "markdown": "\n".join(
                [
                    "WORKSHOP CATALOGUE SHEET - Layout A",
                    "Item | Name | Category | Size/Spec | Image | Notes",
                    "1 | Safety glasses refill | safety | mixed lens colors | images/safety-glasses.png | note=lens tint unclear",
                ]
            ),
            "tables": [],
            "confidence_scores": {"average_page_confidence_score": 0.87},
        }
    ],
    "usage_info": {"pages_processed": 1},
}


def test_mistral_ocr_response_is_converted_to_parser_text():
    text = ocr_response_to_text(MISTRAL_OCR_RESPONSE)

    assert text.startswith("--- PAGE 1 ---")
    assert "Safety glasses refill" in text

    payload = parse_catalogue_text(text, source_file="unit-fixtures/mistral-layout-a.md")

    assert payload["layout_id"] == "workshop-layout-a"
    assert payload["items"][0]["name"] == "Safety glasses refill"
    warning_codes = {warning["code"] for warning in payload["items"][0]["warnings"]}
    assert "AMBIGUOUS_SPEC" in warning_codes
    assert "SOURCE_NOTE_REQUIRES_REVIEW" in warning_codes
