"""PDF text extraction boundary.

The parser deliberately sits behind this small module so the PHP workflow can
distinguish a PDF dependency failure from a contract or persistence failure.
"""

from __future__ import annotations

import os
from pathlib import Path


class PdfExtractionUnavailable(RuntimeError):
    """Raised when the local PDF dependency is not installed."""


def extract_pdf_text(pdf_path: Path, provider: str | None = None) -> str:
    provider_name = (provider or os.getenv("CATALOGUE_EXTRACTOR_PROVIDER") or "local_text").strip().lower()
    if provider_name in {"local", "local_text", "pypdf"}:
        return extract_pdf_text_local(pdf_path)
    if provider_name in {"mistral", "mistral_ocr"}:
        from catalogue_extractor.providers.mistral_ocr import extract_pdf_text_with_mistral

        return extract_pdf_text_with_mistral(pdf_path)

    raise PdfExtractionUnavailable(
        f"Unknown extractor provider {provider_name!r}. Use local_text or mistral_ocr."
    )


def extract_pdf_text_local(pdf_path: Path) -> str:
    try:
        from pypdf import PdfReader
    except ModuleNotFoundError as exc:  # pragma: no cover - depends on environment
        raise PdfExtractionUnavailable(
            "PDF extraction requires pypdf. Install with `python -m pip install -r requirements.txt`, "
            "or run the fallback with scratch paths: "
            "`OUT=/tmp/community-catalogue-review DB=/tmp/community-catalogue-review.sqlite "
            "php artisan catalogue:import-contract examples/input_contract/municipal-maintenance-linecard.json "
            '--output "$OUT" --database "$DB"`.'
        ) from exc

    if not pdf_path.is_file():
        raise FileNotFoundError(f"PDF not found: {pdf_path}")

    reader = PdfReader(str(pdf_path))
    pages: list[str] = []
    for page_number, page in enumerate(reader.pages, start=1):
        page_text = page.extract_text() or ""
        pages.append(f"--- PAGE {page_number} ---\n{page_text}")

    text = "\n".join(pages).strip()
    if not text:
        raise ValueError(f"No extractable text found in {pdf_path}")

    return text
