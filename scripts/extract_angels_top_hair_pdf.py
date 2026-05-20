#!/usr/bin/env python3
"""Extract TOP Hair Fashion / Angels PDF product rows as JSON.

The source PDF is an Excel-generated catalogue where each product cell has:
title row, optional image row, code/price row, and colour row.  This extractor
only returns visible table text. It does not OCR or infer hidden data.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

try:
    import pdfplumber
except ImportError as exc:  # pragma: no cover - local dependency check
    raise SystemExit("pdfplumber is required to extract this PDF") from exc


CODE_RE = re.compile(r"\b(?:WZ|WA|WE|WW|WD|WS|WH)\d{3}(?:NA)?\b")
PRICE_RE = re.compile(r"(\d+(?:\.\d{2}))")


def clean(value: Any) -> str:
    text = "" if value is None else str(value)
    text = text.replace("\n", " ")
    return re.sub(r"\s+", " ", text).strip()


def has_code_or_price(value: str) -> bool:
    return bool(CODE_RE.search(value) or PRICE_RE.search(value))


def extract_records(pdf_path: Path) -> list[dict[str, Any]]:
    table_settings = {
        "vertical_strategy": "lines",
        "horizontal_strategy": "lines",
        "intersection_tolerance": 8,
        "snap_tolerance": 4,
        "join_tolerance": 4,
        "text_x_tolerance": 2,
        "text_y_tolerance": 3,
    }

    records: list[dict[str, Any]] = []

    with pdfplumber.open(str(pdf_path)) as pdf:
        for page_index, page in enumerate(pdf.pages, start=1):
            tables = page.extract_tables(table_settings=table_settings) or []

            for table_index, table in enumerate(tables):
                max_cols = max((len(row) for row in table), default=0)
                if max_cols < 2:
                    continue

                # Page 1 has a separate contact/header table above the products.
                if page_index == 1 and table_index == 0:
                    continue

                for col_index in range(0, min(max_cols, 6), 2):
                    text_col = [
                        clean(row[col_index] if col_index < len(row) else "")
                        for row in table
                    ]
                    price_col = [
                        clean(row[col_index + 1] if col_index + 1 < len(row) else "")
                        for row in table
                    ]

                    for row_index, cell in enumerate(text_col):
                        codes = CODE_RE.findall(cell)
                        if not codes:
                            continue

                        price_match = PRICE_RE.search(price_col[row_index]) or PRICE_RE.search(cell)
                        if not price_match:
                            continue

                        title = ""
                        for previous_index in range(row_index - 1, -1, -1):
                            candidate = text_col[previous_index]
                            if candidate and not has_code_or_price(candidate):
                                title = candidate
                                break

                        colours = ""
                        for next_index in range(row_index + 1, len(text_col)):
                            candidate = text_col[next_index]
                            if not candidate:
                                continue
                            if has_code_or_price(candidate):
                                break
                            colours = candidate
                            break

                        records.append(
                            {
                                "page": page_index,
                                "column": (col_index // 2) + 1,
                                "title": title,
                                "codes": codes,
                                "price": price_match.group(1),
                                "colours": colours,
                            }
                        )

    return records


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", help="Path to Product List PDF")
    parser.add_argument("--json", action="store_true", help="Emit JSON records")
    args = parser.parse_args()

    pdf_path = Path(args.pdf)
    if not pdf_path.exists():
        parser.error(f"PDF not found: {pdf_path}")

    records = extract_records(pdf_path)

    if args.json:
        print(json.dumps(records, ensure_ascii=True, indent=2))
    else:
        for record in records:
            print(
                f"p{record['page']:02d} c{record['column']} | "
                f"{','.join(record['codes'])} | GBP {record['price']} | "
                f"{record['title']} | {record['colours']}"
            )

    return 0


if __name__ == "__main__":
    sys.exit(main())
