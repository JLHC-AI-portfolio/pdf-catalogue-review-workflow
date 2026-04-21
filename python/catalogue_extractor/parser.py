"""Deterministic catalogue text parser for the public fixtures."""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any


CATEGORY_ALIASES = {
    "safety": "safety",
    "spill response": "safety",
    "hand tools": "hand-tools",
    "hand-tools": "hand-tools",
    "tools": "hand-tools",
    "tooling": "hand-tools",
    "paint": "paint-labeling",
    "labeling": "paint-labeling",
    "paint labeling": "paint-labeling",
    "paint-labeling": "paint-labeling",
    "paint labels": "paint-labeling",
    "storage": "storage",
    "storage bins": "storage",
    "inventory storage": "storage",
    "fasteners": "fasteners",
    "cleaning": "cleaning",
    "other": "other",
}

AMBIGUOUS_SPEC_PATTERN = re.compile(r"\b(assorted|approx|about|mixed|various|varies|unclear|unknown|tbd|see chart|see matrix|see footnote|depends)\b", re.I)
NOTE_REQUIRES_REVIEW_PATTERN = re.compile(r"\b(unclear|handwritten|shadow|smudged|cropped|estimated|low contrast|verify|rounded|footnote|missing image|partially)\b", re.I)
CROSS_REFERENCE_PATTERN = re.compile(r"\b(see chart|see matrix|see footnote|continued|next page|shared|family|matrix|footnote)\b", re.I)
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
CARD_KEY_ALIASES = {
    "title": "name",
    "category": "category",
    "specs": "spec",
    "spec": "spec",
    "image asset": "image",
    "image": "image",
    "attributes": "attributes",
    "review cue": "note",
    "note": "note",
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
    elif layout_id == "municipal-maintenance-linecard":
        rows = parse_linecard_layout(text)
    elif layout_id == "workshop-equipment-cards":
        rows = parse_card_layout(text)
    elif layout_id == "storage-family-matrix":
        rows = parse_matrix_layout(text)
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
    if "municipal maintenance linecard" in lowered and "sku | item | family" in lowered:
        return "municipal-maintenance-linecard"
    if "workshop equipment card catalog" in lowered and "product card:" in lowered:
        return "workshop-equipment-cards"
    if "storage family matrix" in lowered and "family matrix:" in lowered:
        return "storage-family-matrix"
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


def parse_linecard_layout(text: str) -> list[ParsedRow]:
    """Parse a dense synthetic linecard with SKU-led table rows."""

    rows: list[ParsedRow] = []
    for page_number, record in iter_pipe_records(text, r"^[A-Z]{2}-\d{3}\s*\|"):
        cleaned = normalize_spacing(record)
        parts = [part.strip() for part in cleaned.split("|")]
        if len(parts) < 6:
            continue
        sku, name, category, size_or_spec, image_ref, notes = parts[:6]
        rows.append(
            build_row(
                name=name,
                category=category,
                size_or_spec=size_or_spec,
                image_ref=image_ref,
                notes=notes,
                source_page=page_number,
                source_excerpt=cleaned,
                layout_confidence=0.9,
                extra_attributes={"sku": sku},
            )
        )

    return rows


def parse_card_layout(text: str) -> list[ParsedRow]:
    """Parse a product-card grid where fields are repeated inside cards."""

    rows: list[ParsedRow] = []
    current: dict[str, str] = {}
    current_page = 1
    current_excerpt: list[str] = []
    current_code = ""

    def flush() -> None:
        nonlocal current, current_page, current_excerpt, current_code
        if not current:
            return
        attributes = parse_notes(current.get("attributes", ""))
        if current_code:
            attributes["code"] = current_code
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
                extra_attributes=attributes,
            )
        )
        current = {}
        current_excerpt = []
        current_code = ""

    for page_number, line in iter_page_lines(text):
        cleaned = normalize_spacing(line)
        if not cleaned:
            continue
        lowered = cleaned.lower()
        if lowered.startswith("product card:"):
            flush()
            current_page = page_number
            current_code = cleaned.split(":", 1)[1].strip()
            current_excerpt = [cleaned]
            continue
        if ":" not in cleaned:
            continue
        key, value = [part.strip() for part in cleaned.split(":", 1)]
        mapped = CARD_KEY_ALIASES.get(key.lower())
        if mapped:
            current[mapped] = value if mapped != "note" or not current.get(mapped) else current[mapped] + "; " + value
            current_excerpt.append(cleaned)

    flush()
    return rows


def parse_matrix_layout(text: str) -> list[ParsedRow]:
    """Parse family matrix rows that inherit category and image at family level."""

    rows: list[ParsedRow] = []
    family = ""
    category = ""
    shared_image = ""
    current_record = ""
    current_page = 1
    current_family = ""
    current_category = ""
    current_image = ""

    def flush() -> None:
        nonlocal current_record, current_page, current_family, current_category, current_image
        if not current_record:
            return
        cleaned_record = normalize_spacing(current_record)
        parts = [part.strip() for part in cleaned_record.split("|")]
        if len(parts) >= 5 and re.match(r"^[A-Z]{2}-[A-Z0-9]+$", parts[0]):
            variant, name, size_or_spec, attributes, review_cue = parts[:5]
            excerpt = (
                f"Family Matrix: {current_family}; Category: {current_category}; "
                f"Shared image: {current_image}; {cleaned_record}"
            )
            rows.append(
                build_row(
                    name=name,
                    category=current_category,
                    size_or_spec=size_or_spec,
                    image_ref=current_image,
                    notes=f"{attributes}; note={review_cue}; family={current_family}",
                    source_page=current_page,
                    source_excerpt=excerpt,
                    layout_confidence=0.86,
                    extra_attributes={"variant": variant, "family": current_family},
                )
            )
        current_record = ""

    for page_number, line in iter_page_lines(text):
        cleaned = normalize_spacing(line)
        if not cleaned:
            continue
        lowered = cleaned.lower()
        if lowered.startswith("family matrix:"):
            flush()
            family = cleaned.split(":", 1)[1].strip()
            continue
        if lowered.startswith("category:"):
            category = cleaned.split(":", 1)[1].strip()
            continue
        if lowered.startswith("shared image:"):
            shared_image = cleaned.split(":", 1)[1].strip()
            continue
        if cleaned.lower().startswith("variant |"):
            continue
        if re.match(r"^[A-Z]{2}-[A-Z0-9]+\s*\|", cleaned):
            flush()
            current_page = page_number
            current_family = family
            current_category = category
            current_image = shared_image
            current_record = cleaned
            continue
        if current_record and "|" in cleaned:
            current_record = append_wrapped_line(current_record, line)

    flush()
    return rows


def iter_pipe_records(text: str, start_pattern: str):
    """Yield wrapped pipe-delimited records that begin with a known token pattern."""

    current_page: int | None = None
    current_record = ""
    start_re = re.compile(start_pattern)

    for page_number, line in iter_page_lines(text):
        cleaned = normalize_spacing(line)
        if not cleaned:
            continue
        if cleaned.lower().startswith(("municipal maintenance linecard", "dense table", "sku |")):
            continue
        if start_re.match(cleaned):
            if current_record and current_page is not None:
                yield current_page, current_record
            current_page = page_number
            current_record = cleaned
            continue
        if current_record and "|" in cleaned:
            current_record = append_wrapped_line(current_record, line)

    if current_record and current_page is not None:
        yield current_page, current_record


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
    extra_attributes: dict[str, str] | None = None,
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

    if CROSS_REFERENCE_PATTERN.search(size_or_spec + " " + notes):
        confidence -= 0.06
        warnings.append(
            warning(
                "CROSS_PAGE_REFERENCE",
                "The row depends on a chart, footnote, shared image, or another page.",
                source_field="source_excerpt",
                source_excerpt=source_excerpt,
            )
        )

    if image_ref.strip() == "":
        confidence -= 0.07
        warnings.append(
            warning(
                "MISSING_IMAGE_REF",
                "The source card did not provide a standalone image reference.",
                source_field="image_ref",
                source_excerpt=source_excerpt,
            )
        )

    if normalized_category == category.strip().lower() and normalized_category not in CATEGORY_ALIASES.values():
        confidence -= 0.1

    attributes = parse_notes(notes)
    if extra_attributes:
        attributes.update({key: value for key, value in extra_attributes.items() if value})

    return ParsedRow(
        name=name.strip(),
        category=normalized_category,
        size_or_spec=size_or_spec.strip(),
        image_ref=image_ref.strip(),
        attributes=attributes,
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
