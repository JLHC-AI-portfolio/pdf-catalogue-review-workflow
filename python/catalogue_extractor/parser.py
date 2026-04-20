"""Deterministic catalogue text parser for the public fixtures."""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any


CATEGORY_ALIASES = {
    "safety": "safety",
    "hand tools": "hand-tools",
    "hand-tools": "hand-tools",
    "tools": "hand-tools",
    "paint": "paint-labeling",
    "labeling": "paint-labeling",
    "paint labeling": "paint-labeling",
    "paint-labeling": "paint-labeling",
    "storage": "storage",
    "storage bins": "storage",
    "fasteners": "fasteners",
    "cleaning": "cleaning",
    "other": "other",
}

AMBIGUOUS_SPEC_PATTERN = re.compile(r"\b(assorted|approx|about|mixed|various|unclear|unknown|tbd)\b", re.I)
NOTE_REQUIRES_REVIEW_PATTERN = re.compile(r"\b(unclear|handwritten|shadow|smudged|cropped|estimated)\b", re.I)
LAYOUT_B_KEY_ALIASES = {
    "name": "name",
    "item name": "name",
    "category": "category",
    "category hint": "category",
    "spec": "spec",
    "size/spec": "spec",
    "size spec": "spec",
    "image": "image",
    "image ref": "image",
    "photo ref": "image",
    "note": "note",
    "notes": "note",
    "review note": "note",
}


@dataclass
class ParsedRow:
    name: str
    category: str
    size_or_spec: str
    image_ref: str
    attributes: dict[str, str]
    source_page: int
    source_excerpt: str
    confidence: float
    warnings: list[dict[str, Any]]


def parse_catalogue_text(text: str, source_file: str) -> dict[str, Any]:
    layout_id = detect_layout(text)
    if layout_id == "workshop-layout-a":
        rows = parse_layout_a(text)
    elif layout_id == "workshop-layout-b":
        rows = parse_layout_b(text)
    else:
        rows = []

    warnings: list[dict[str, Any]] = []
    if not rows:
        warnings.append(
            warning(
                "NO_ROWS_DETECTED",
                "No catalogue rows were detected in the extracted PDF text.",
                source_field="items",
                source_excerpt=text[:180],
                severity="blocker",
            )
        )

    return {
        "source_file": source_file,
        "layout_id": layout_id,
        "warnings": warnings,
        "items": [row_to_contract(row) for row in rows],
    }


def detect_layout(text: str) -> str:
    lowered = text.lower()
    if "layout a" in lowered and "item | name | category" in lowered:
        return "workshop-layout-a"
    if "layout b" in lowered and ("record:" in lowered or "item card:" in lowered):
        return "workshop-layout-b"
    return "unknown-layout"


def parse_layout_a(text: str) -> list[ParsedRow]:
    rows: list[ParsedRow] = []
    for page_number, record in iter_layout_a_records(text):
        cleaned = normalize_spacing(record)
        if " | " not in cleaned:
            continue
        parts = [part.strip() for part in cleaned.split("|")]
        if len(parts) < 6 or not parts[0].isdigit():
            continue

        name, category, size_or_spec, image_ref, notes = parts[1:6]
        rows.append(
            build_row(
                name=name,
                category=category,
                size_or_spec=size_or_spec,
                image_ref=image_ref,
                notes=notes,
                source_page=page_number,
                source_excerpt=cleaned,
                layout_confidence=0.92,
            )
        )

    return rows


def iter_layout_a_records(text: str):
    """Yield logical table rows even when PDF extraction wraps a row across lines."""

    current_page: int | None = None
    current_record = ""

    for page_number, line in iter_page_lines(text):
        if current_record and current_page is not None and page_number != current_page:
            yield current_page, current_record
            current_page = None
            current_record = ""

        raw = line.rstrip()
        cleaned = normalize_spacing(raw)
        if not cleaned:
            continue
        if is_layout_a_page_heading(cleaned):
            if current_record and current_page is not None:
                yield current_page, current_record
                current_page = None
                current_record = ""
            continue
        if cleaned.lower().startswith("item | name | category"):
            continue
        if re.match(r"^\d+\s*\|", cleaned):
            if current_record and current_page is not None:
                yield current_page, current_record
            current_page = page_number
            current_record = cleaned
            continue
        if current_record:
            current_record = append_wrapped_line(current_record, raw)

    if current_record and current_page is not None:
        yield current_page, current_record


def append_wrapped_line(current: str, raw_line: str) -> str:
    """Append one physical PDF line using a small heuristic for mid-word wraps."""

    piece = raw_line.rstrip()
    if not piece.strip():
        return current
    if piece[0].isspace() or current.endswith((",", ";", ":", "|")):
        return current + " " + piece.strip()
    return current + piece.strip()


def is_layout_a_page_heading(cleaned_line: str) -> bool:
    """Identify page-level headings that OCR may place between table rows."""

    lowered = cleaned_line.lower()
    return lowered.startswith("workshop catalogue sheet") or lowered.startswith("community workshop intake batch")


def parse_layout_b(text: str) -> list[ParsedRow]:
    rows: list[ParsedRow] = []
    current: dict[str, str] = {}
    current_page = 1
    current_excerpt: list[str] = []

    def flush() -> None:
        nonlocal current, current_page, current_excerpt
        if not current:
            return
        rows.append(
            build_row(
                name=current.get("name", ""),
                category=current.get("category", ""),
                size_or_spec=current.get("spec", ""),
                image_ref=current.get("image", ""),
                notes=current.get("note", ""),
                source_page=current_page,
                source_excerpt="; ".join(current_excerpt),
                layout_confidence=0.88,
            )
        )
        current = {}
        current_excerpt = []

    for page_number, line in iter_page_lines(text):
        cleaned = normalize_spacing(line)
        lowered = cleaned.lower()
        if lowered.startswith("record:") or lowered.startswith("item card:"):
            flush()
            current_page = page_number
            current_excerpt.append(cleaned)
            continue
        if ":" not in cleaned:
            continue
        key, value = [part.strip() for part in cleaned.split(":", 1)]
        key = LAYOUT_B_KEY_ALIASES.get(key.lower())
        if key:
            if key == "note" and current.get(key):
                current[key] = current[key] + "; " + value
            else:
                current[key] = value
            current_excerpt.append(cleaned)

    flush()
    return rows


def build_row(
    *,
    name: str,
    category: str,
    size_or_spec: str,
    image_ref: str,
    notes: str,
    source_page: int,
    source_excerpt: str,
    layout_confidence: float,
) -> ParsedRow:
    warnings: list[dict[str, Any]] = []
    normalized_category = normalize_category(category)
    confidence = layout_confidence

    if AMBIGUOUS_SPEC_PATTERN.search(size_or_spec):
        confidence -= 0.14
        warnings.append(
            warning(
                "AMBIGUOUS_SPEC",
                "The source text uses approximate or mixed sizing language.",
                source_field="size_or_spec",
                source_excerpt=source_excerpt,
            )
        )

    if NOTE_REQUIRES_REVIEW_PATTERN.search(notes):
        confidence -= 0.08
        warnings.append(
            warning(
                "SOURCE_NOTE_REQUIRES_REVIEW",
                "The source note says part of the row requires review.",
                source_field="notes",
                source_excerpt=source_excerpt,
            )
        )

    if normalized_category == category.strip().lower() and normalized_category not in CATEGORY_ALIASES.values():
        confidence -= 0.1

    return ParsedRow(
        name=name.strip(),
        category=normalized_category,
        size_or_spec=size_or_spec.strip(),
        image_ref=image_ref.strip(),
        attributes=parse_notes(notes),
        source_page=source_page,
        source_excerpt=source_excerpt,
        confidence=round(max(0.1, min(0.99, confidence)), 3),
        warnings=warnings,
    )


def row_to_contract(row: ParsedRow) -> dict[str, Any]:
    return {
        "name": row.name,
        "category": row.category,
        "size_or_spec": row.size_or_spec,
        "image_ref": row.image_ref,
        "attributes": row.attributes,
        "confidence": row.confidence,
        "warnings": row.warnings,
        "source_page": row.source_page,
        "source_excerpt": row.source_excerpt,
    }


def parse_notes(notes: str) -> dict[str, str]:
    attributes: dict[str, str] = {}
    for part in notes.split(";"):
        if "=" in part:
            key, value = [token.strip() for token in part.split("=", 1)]
            if key and value:
                attributes[slug_key(key)] = value
    if notes.strip() and not attributes:
        attributes["note"] = notes.strip()
    return attributes


def normalize_category(category: str) -> str:
    key = category.strip().lower().replace("_", " ").replace("-", " ")
    key = re.sub(r"\s+", " ", key)
    return CATEGORY_ALIASES.get(key, key.replace(" ", "-"))


def normalize_spacing(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def slug_key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.strip().lower()).strip("_")


def iter_page_lines(text: str):
    page_number = 1
    for raw_line in text.splitlines():
        marker = re.match(r"--- PAGE (\d+) ---", raw_line.strip())
        if marker:
            page_number = int(marker.group(1))
            continue
        yield page_number, raw_line


def warning(
    code: str,
    message: str,
    *,
    source_field: str,
    source_excerpt: str,
    severity: str = "review",
) -> dict[str, Any]:
    return {
        "code": code,
        "severity": severity,
        "message": message,
        "source_field": source_field,
        "source_excerpt": source_excerpt,
    }
