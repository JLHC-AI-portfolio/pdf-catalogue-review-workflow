"""Command line entry point for PDF-to-contract extraction."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from catalogue_extractor.parser import parse_catalogue_text
from catalogue_extractor.pdf_text import PdfExtractionUnavailable, extract_pdf_text


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Extract a workshop catalogue PDF into normalized JSON.")
    parser.add_argument("--input", required=True, help="Path to a PDF catalogue fixture.")
    parser.add_argument(
        "--provider",
        choices=["local_text", "mistral_ocr"],
        default=None,
        help="PDF extraction provider. Defaults to CATALOGUE_EXTRACTOR_PROVIDER or local_text.",
    )
    args = parser.parse_args(argv)

    pdf_path = Path(args.input)
    try:
        text = extract_pdf_text(pdf_path, provider=args.provider)
        payload = parse_catalogue_text(text, source_file=str(pdf_path))
    except PdfExtractionUnavailable as exc:
        print(str(exc), file=sys.stderr)
        return 2
    except Exception as exc:  # pragma: no cover - CLI failure path
        print(f"Extraction failed: {exc}", file=sys.stderr)
        return 1

    json.dump(payload, sys.stdout, indent=2)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
