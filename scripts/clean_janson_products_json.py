#!/usr/bin/env python3
"""Clean and validate the Janson product JSON source.

The supplied JSON is already useful, but the original PDF layout causes a few
systematic problems: page/column category carry-over, page 18 multi-column
colour rows, and some combined cells. This script creates a cleaned source file
without overwriting the original JSON.
"""

from __future__ import annotations

import argparse
import copy
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


DEFAULT_INPUT = Path(r"C:\Users\Abela\Desktop\Khan\janson_products.json")
DEFAULT_PDF = Path(r"C:\Users\Abela\Desktop\Khan\JANSON PRODUCT LIST Dec'25.pdf")
DEFAULT_OUTPUT = Path(r"C:\Users\Abela\Desktop\Khan\janson_products.cleaned.json")
DEFAULT_REPORT = Path(r"storage\app\reports\janson-json-cleaning-report.json")


CATEGORY_RENAMES = {
    "AFIRCA BLACK .": ("AFRICA BLACK SOAPS", "category_spelling_corrected"),
    "ALIZA ACCESSORIES OFFER": ("ALIZA ACCESSORIES", "category_offer_marker_removed"),
    "CRAZY COLOR BACK IN STOCK": ("CRAZY COLOR", "category_back_in_stock_marker_removed"),
    "FAIR AND WHITE MIX BRIGHTENING NEW": ("FAIR AND WHITE MIX BRIGHTENING", "category_new_marker_removed"),
    "HOLLY WOOD": ("HOLLYWOOD", "category_spelling_corrected"),
    "JOHNSONS": ("JOHNSON'S", "category_spelling_corrected"),
    "MIELLE POMEGRANTE & HONEY": ("MIELLE POMEGRANATE & HONEY", "category_spelling_corrected"),
    "ORS OILIVE OIL GIRLS": ("ORS OLIVE OIL GIRLS", "category_spelling_corrected"),
    "RAW EXTRA VIRGIN OILS - SPECIAL OFFER 0.99P": ("RAW EXTRA VIRGIN OILS", "category_price_offer_marker_removed"),
}


SPECIAL_NOTES = {
    "ALIZA ACCESSORIES OFFER": "OFFER",
    "CRAZY COLOR BACK IN STOCK": "BACK IN STOCK",
    "RAW EXTRA VIRGIN OILS - SPECIAL OFFER 0.99P": "SPECIAL OFFER 0.99P",
}


CATEGORY_LEVEL_NEW = {
    "BATANA OILS",
    "COLOUR CULTURE",
    "DEXE",
    "FAIR AND WHITE MIX BRIGHTENING",
    "NATSKIN",
}


PAGE_SECTION_NEW = {1}


TRAILING_NEW_RE = re.compile(r"\s*(?:[-–]\s*)?(?:\*{0,3}\s*NEW\s*\*{0,3})\s*$", re.IGNORECASE)


# Corrections where the extractor carried the category from the adjacent column
# or previous section. These are deliberately keyed by both prefix and wrong
# category to avoid flattening legitimate sub-lines.
PREFIX_CATEGORY_CORRECTIONS = {
    ("ABK", "XX"): "AFRICA'S BEST KIDS",
    ("APM", "AFRICA'S BEST KIDS"): "A PRIDE MOISTURE MIRACLE",
    ("ATO", "A PRIDE MOISTURE MIRACLE"): "ATONE",
    ("BM", "ATONE"): "BLUE MAGIC",
    ("CPH", "BLUE MAGIC"): "CREME OF NATURE PURE HONEY",
    ("CUK", "CREME OF NATURE PURE HONEY"): "CURLY KIDS",
    ("DAX", "CURLY KIDS"): "DAX",
    ("DRM", "DABUR VATIKA"): "DR MIRACLE",
    ("FAA", "DR MIRACLE"): "FANTASIA IC",
    ("FAN", "DR MIRACLE"): "FANTASIA IC",
    ("GUM", "FANTASIA IC"): "GUMMY GEL",
    ("JML", "GUMMY GEL"): "JAMAICAN MANGO & LIME",
    ("COC", "JAMAICAN MANGO & LIME"): "KERACARE",
    ("MOG", "KERACARE"): "MORGANS",
    ("MOT", "MORGANS"): "MOTIONS",
    ("ORK", "MOTIONS"): "ORS OLIVE OIL GIRLS",
    ("SSF", "REVLON"): "STA SOF FRO",
    ("SUN", "STA SOF FRO"): "SUNNY ISLES JBCO",
    ("BBS", "SHEA MOISTURE KIDS"): "MISCELLANEOUS",
    ("CAL", "XXX"): "CARO LIGHT",
    ("DNR", "CARO LIGHT"): "DAGGET & RAMSDELL",
    ("FNC", "DAGGET & RAMSDELL"): "FAIR AND WHITE CARROT",
    ("JER", "FAIR AND WHITE CARROT"): "JERGENS",
    ("MKN", "JERGENS"): "MAKARI NATURALLE",
    ("POO", "MAKARI NATURALLE"): "PALMERS OLIVE OIL",
    ("VAS", "PALMERS OLIVE OIL"): "VASELINE",
    ("CND", "SILKA SOAP"): "CREME OF NATURE ARGAN COLOR",
    ("WCA", "STA SOF FRO DYE"): "CLIPPER ATTACHMENTS",
}


DRB_DRS_RE = re.compile(r"\s+(DRS\d{2})\s*-\s*(.+)$", re.IGNORECASE)


def product_prefix(code: str) -> str:
    match = re.match(r"^[A-Z]+", code or "")
    return match.group(0) if match else ""


def clean_name(name: str, is_new: bool, flags: list[str]) -> tuple[str, bool]:
    original = name or ""
    cleaned = original.strip()

    # Keep "New Growth" and "New GTX Blade" because they are part of the sellable
    # product name. Remove only standalone/trailing NEW badges.
    if TRAILING_NEW_RE.search(cleaned) and not re.search(r"\bNew Growth\b|\bNew GTX Blade\b", cleaned, re.IGNORECASE):
        cleaned = TRAILING_NEW_RE.sub("", cleaned).strip()
        is_new = True
        flags.append("trailing_new_marker_removed")

    return cleaned, is_new


def apply_category_cleanup(row: dict[str, Any], flags: list[str]) -> None:
    source_category = (row.get("source_category") or row.get("category") or "").strip()
    category = source_category
    special_note = row.get("special_note")

    if category in CATEGORY_RENAMES:
        category, flag = CATEGORY_RENAMES[category]
        flags.append(flag)
        special_note = special_note or SPECIAL_NOTES.get(source_category)

    prefix = product_prefix(row.get("code", ""))
    corrected = PREFIX_CATEGORY_CORRECTIONS.get((prefix, category))
    if corrected and corrected != category:
        category = corrected
        flags.append("category_corrected_from_pdf_layout")

    row["category"] = category
    row["special_note"] = special_note


def split_cover_your_gray(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    output: list[dict[str, Any]] = []

    for row in rows:
        match = DRB_DRS_RE.search(row.get("name", ""))
        if not (row.get("code", "").startswith("DRB") and match):
            output.append(row)
            continue

        stick_code = match.group(1).upper()
        stick_name = match.group(2).strip()

        row = copy.deepcopy(row)
        row["name"] = DRB_DRS_RE.sub("", row["name"]).strip()
        row["source_name"] = row.get("source_name") or row["name"]
        row["category"] = "COVER YOUR GRAY BRUSH / STICK"
        row["review_flags"] = sorted(set(row.get("review_flags", []) + [
            "split_combined_cover_your_gray_cell",
            "category_corrected_from_pdf_layout",
        ]))
        output.append(row)

        stick = copy.deepcopy(row)
        stick["code"] = stick_code
        stick["source_code"] = stick_code
        stick["name"] = stick_name
        stick["source_name"] = f"{stick_code}- {stick_name}"
        stick["review_flags"] = sorted(set(stick.get("review_flags", []) + [
            "created_from_split_combined_cover_your_gray_cell",
        ]))
        output.append(stick)

    return output


def extract_adore_rows(pdf_path: Path) -> list[dict[str, Any]]:
    if not pdf_path.exists():
        return []

    try:
        import pdfplumber  # type: ignore
    except Exception:
        return []

    rows: list[dict[str, Any]] = []
    with pdfplumber.open(str(pdf_path)) as pdf:
        if len(pdf.pages) < 18:
            return []

        tables = pdf.pages[17].extract_tables()
        if not tables:
            return []

        table = tables[0]
        for table_row_index, table_row in enumerate(table, start=1):
            for cell_index in (0, 3, 7):
                if cell_index >= len(table_row):
                    continue
                cell = (table_row[cell_index] or "").strip()
                match = re.match(r"^(\d{2,3})\s+(.+)$", cell)
                if not match:
                    continue
                shade, shade_name = match.groups()
                rows.append({
                    "code": f"ADO{shade}",
                    "source_code": shade,
                    "name": shade_name.strip(),
                    "source_name": cell,
                    "category": "ADORE COLOURS",
                    "source_category": "ADORE COLOURS",
                    "price_gbp": None,
                    "flags": [],
                    "is_new": False,
                    "page": 18,
                    "page_row": table_row_index,
                    "special_note": None,
                    "review_flags": ["added_missing_adore_colour_from_pdf_table", "generated_code_from_adore_shade"],
                })

    return rows


def add_source_ids(rows: list[dict[str, Any]]) -> None:
    per_page: dict[int, int] = defaultdict(int)
    seen_source_ids: Counter[str] = Counter()

    for index, row in enumerate(rows, start=1):
        page = int(row.get("page") or 0)
        if not row.get("page_row"):
            per_page[page] += 1
            row["page_row"] = per_page[page]

        base = f"JANSON-P{page:02d}-R{int(row['page_row']):03d}-{row.get('code', 'NO-CODE')}"
        seen_source_ids[base] += 1
        row["source_row_id"] = base if seen_source_ids[base] == 1 else f"{base}-{seen_source_ids[base]}"
        row["row_index"] = index


def mark_duplicate_codes(rows: list[dict[str, Any]]) -> dict[str, int]:
    counts = Counter(row.get("code", "") for row in rows)
    duplicates = {code: count for code, count in counts.items() if code and count > 1}

    for row in rows:
        code = row.get("code")
        if code in duplicates:
            row["review_flags"] = sorted(set(row.get("review_flags", []) + ["duplicate_code_in_source"]))

    return duplicates


def clean_payload(payload: dict[str, Any], pdf_path: Path | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
    source_rows = payload.get("products") or []
    rows: list[dict[str, Any]] = []
    correction_counter: Counter[str] = Counter()

    for original in source_rows:
        row = copy.deepcopy(original)
        flags = list(row.get("review_flags") or [])

        row["source_category"] = row.get("category", "")
        row["source_name"] = row.get("name", "")
        row["source_code"] = row.get("code", "")
        row["special_note"] = None

        name, is_new = clean_name(row.get("name", ""), bool(row.get("is_new")), flags)
        row["name"] = name
        row["is_new"] = is_new

        apply_category_cleanup(row, flags)

        if row["category"] in CATEGORY_LEVEL_NEW:
            row["is_new"] = True
            flags.append("category_marked_new_in_pdf")

        if int(row.get("page") or 0) in PAGE_SECTION_NEW:
            # Page 1 is headed "NEW IN STOCK". Preserve this as source metadata.
            flags.append("source_section_new_in_stock")

        if not (row.get("name") or "").strip():
            flags.append("blank_name_bare_code_reference")

        row["review_flags"] = sorted(set(flags))
        rows.append(row)

    rows = split_cover_your_gray(rows)

    added_rows: list[dict[str, Any]] = []
    if pdf_path:
        existing_codes = {row.get("code") for row in rows}
        for row in extract_adore_rows(pdf_path):
            if row["code"] not in existing_codes:
                added_rows.append(row)
                existing_codes.add(row["code"])

    rows.extend(added_rows)

    duplicates = mark_duplicate_codes(rows)
    add_source_ids(rows)

    for row in rows:
        for flag in row.get("review_flags", []):
            correction_counter[flag] += 1

    categories = Counter(row.get("category", "") for row in rows)
    pages = Counter(int(row.get("page") or 0) for row in rows)

    cleaned = copy.deepcopy(payload)
    cleaned["original_file"] = str(DEFAULT_INPUT)
    cleaned["cleaned_at"] = datetime.now(timezone.utc).isoformat()
    cleaned["cleaning_method"] = (
        "JSON-preserving cleanup with PDF-layout category corrections, page-18 missing colour recovery, "
        "combined Cover Your Gray row splitting, and explicit review flags."
    )
    cleaned["products"] = rows
    cleaned["stats"] = {
        "total_products": len(rows),
        "unique_codes": len({row.get("code") for row in rows if row.get("code")}),
        "duplicate_codes": duplicates,
        "categories": len(categories),
        "items_with_listed_price": sum(1 for row in rows if row.get("price_gbp") is not None),
        "items_marked_new": sum(1 for row in rows if row.get("is_new")),
        "items_outside_eu_only": sum(1 for row in rows if "XXX" in (row.get("flags") or []) or "XX" in (row.get("flags") or [])),
        "blank_name_bare_code_references": sum(1 for row in rows if "blank_name_bare_code_reference" in row.get("review_flags", [])),
        "added_missing_adore_colour_rows": len(added_rows),
        "source_rows_before_cleaning": len(source_rows),
    }
    cleaned["cleaning_report_summary"] = {
        "correction_flags": dict(sorted(correction_counter.items())),
        "top_categories": dict(categories.most_common(30)),
        "page_counts": dict(sorted(pages.items())),
    }

    report = {
        "input_products": len(source_rows),
        "output_products": len(rows),
        "added_rows": len(rows) - len(source_rows),
        "duplicate_codes": duplicates,
        "correction_flags": dict(sorted(correction_counter.items())),
        "category_count": len(categories),
        "categories": dict(sorted(categories.items())),
        "page_counts": dict(sorted(pages.items())),
        "review_rows": [
            {
                "source_row_id": row.get("source_row_id"),
                "page": row.get("page"),
                "code": row.get("code"),
                "source_category": row.get("source_category"),
                "category": row.get("category"),
                "source_name": row.get("source_name"),
                "name": row.get("name"),
                "review_flags": row.get("review_flags"),
            }
            for row in rows
            if row.get("review_flags")
        ],
    }

    return cleaned, report


def main() -> int:
    parser = argparse.ArgumentParser(description="Clean Janson JSON source data.")
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--pdf", type=Path, default=DEFAULT_PDF)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    args = parser.parse_args()

    payload = json.loads(args.input.read_text(encoding="utf-8"))
    cleaned, report = clean_payload(payload, args.pdf)
    cleaned["original_file"] = str(args.input)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(cleaned, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    print(f"Input products: {report['input_products']}")
    print(f"Output products: {report['output_products']}")
    print(f"Added rows: {report['added_rows']}")
    print(f"Duplicate code groups: {len(report['duplicate_codes'])}")
    print(f"Cleaned JSON: {args.output}")
    print(f"Report: {args.report}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
