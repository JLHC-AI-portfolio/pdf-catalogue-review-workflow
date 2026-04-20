"""Mistral OCR provider for turning PDF pages into markdown-like text."""

from __future__ import annotations

import base64
import json
import os
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

from catalogue_extractor.pdf_text import PdfExtractionUnavailable


DEFAULT_ENDPOINT = "https://api.mistral.ai/v1/ocr"
DEFAULT_MODEL = "mistral-ocr-latest"


def extract_pdf_text_with_mistral(pdf_path: Path) -> str:
    """Run Mistral OCR on a PDF and return page-marked markdown text."""

    if not pdf_path.is_file():
        raise FileNotFoundError(f"PDF not found: {pdf_path}")

    api_key = os.getenv("MISTRAL_API_KEY", "").strip()
    if not api_key:
        raise PdfExtractionUnavailable(
            "MISTRAL_API_KEY is required when CATALOGUE_EXTRACTOR_PROVIDER=mistral_ocr."
        )

    payload = build_ocr_request(pdf_path)
    endpoint = os.getenv("MISTRAL_OCR_ENDPOINT", DEFAULT_ENDPOINT)
    response = post_ocr_request(endpoint=endpoint, api_key=api_key, payload=payload)
    return ocr_response_to_text(response)


def build_ocr_request(pdf_path: Path) -> dict[str, Any]:
    """Build the REST payload documented by Mistral's OCR API."""

    encoded_pdf = base64.b64encode(pdf_path.read_bytes()).decode("ascii")
    request: dict[str, Any] = {
        "model": os.getenv("MISTRAL_OCR_MODEL", DEFAULT_MODEL),
        "document": {
            "type": "document_url",
            "document_url": f"data:application/pdf;base64,{encoded_pdf}",
        },
        "include_image_base64": False,
    }

    table_format = os.getenv("MISTRAL_OCR_TABLE_FORMAT", "markdown").strip()
    if table_format:
        request["table_format"] = table_format

    confidence_granularity = os.getenv("MISTRAL_OCR_CONFIDENCE", "page").strip()
    if confidence_granularity:
        request["confidence_scores_granularity"] = confidence_granularity

    return request


def post_ocr_request(*, endpoint: str, api_key: str, payload: dict[str, Any]) -> dict[str, Any]:
    """POST the OCR request with stdlib HTTP so no SDK dependency is required."""

    body = json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        endpoint,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            raw = response.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")[:600]
        raise PdfExtractionUnavailable(f"Mistral OCR request failed with HTTP {exc.code}: {detail}") from exc
    except urllib.error.URLError as exc:
        raise PdfExtractionUnavailable(f"Mistral OCR request failed: {exc.reason}") from exc

    parsed = json.loads(raw)
    if not isinstance(parsed, dict):
        raise PdfExtractionUnavailable("Mistral OCR response was not a JSON object.")
    return parsed


def ocr_response_to_text(response: dict[str, Any]) -> str:
    """Convert a Mistral OCR JSON response into parser-friendly page text."""

    pages = response.get("pages")
    if not isinstance(pages, list) or not pages:
        raise PdfExtractionUnavailable("Mistral OCR response did not contain pages.")

    page_texts: list[str] = []
    for fallback_index, page in enumerate(pages, start=1):
        if not isinstance(page, dict):
            continue
        page_number = coerce_page_number(page.get("index"), fallback_index)
        parts = []
        header = page.get("header")
        markdown = page.get("markdown")
        footer = page.get("footer")
        if isinstance(header, str) and header.strip():
            parts.append(header.strip())
        if isinstance(markdown, str) and markdown.strip():
            parts.append(markdown.strip())
        parts.extend(extract_table_text(page.get("tables")))
        if isinstance(footer, str) and footer.strip():
            parts.append(footer.strip())
        page_texts.append(f"--- PAGE {page_number} ---\n" + "\n\n".join(parts).strip())

    text = "\n".join(page_texts).strip()
    if not text:
        raise PdfExtractionUnavailable("Mistral OCR response pages were empty.")
    return text


def coerce_page_number(index: Any, fallback_index: int) -> int:
    """Treat 0-based or 1-based provider indexes as human page numbers."""

    if isinstance(index, int):
        return index + 1 if index == 0 else index
    return fallback_index


def extract_table_text(tables: Any) -> list[str]:
    """Return readable table content from optional OCR table objects."""

    if not isinstance(tables, list):
        return []

    result = []
    for table in tables:
        if isinstance(table, str) and table.strip():
            result.append(table.strip())
            continue
        if isinstance(table, dict):
            for key in ("markdown", "html", "content"):
                value = table.get(key)
                if isinstance(value, str) and value.strip():
                    result.append(value.strip())
                    break
    return result
