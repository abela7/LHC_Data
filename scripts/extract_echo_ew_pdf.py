#!/usr/bin/env python3
"""Extract Echo Collection EW stock-list rows from the 3-block PDF table.

Each row in the PDF has three repeated blocks:
ITEM | COLOR | QTY   ITEM | COLOR | QTY   ITEM | COLOR | QTY

This script emits one JSON record per visible ITEM/COLOR pair and preserves
colour text exactly apart from whitespace cleanup.
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


ITEM_RE = re.compile(r"^EW\s+(\d+)\"$")


def clean(value: Any) -> str:
    text = "" if value is None else str(value)
    text = text.replace("\n", " ")
    return re.sub(r"\s+", " ", text).strip()


def extract_records(pdf_path: Path) -> list[dict[str, Any]]:
    table_settings = {
        "vertical_strategy": "lines",
        "horizontal_strategy": "lines",
        "intersection_tolerance": 5,
        "snap_tolerance": 3,
        "join_tolerance": 3,
        "text_x_tolerance": 1,
        "text_y_tolerance": 3,
    }

    records: list[dict[str, Any]] = []

    with pdfplumber.open(str(pdf_path)) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            tables = page.extract_tables(table_settings=table_settings) or []

            for table_number, table in enumerate(tables, start=1):
                for row_number, row in enumerate(table, start=1):
                    for block_number, column_index in enumerate((0, 3, 6), start=1):
                        item = clean(row[column_index] if column_index < len(row) else "")
                        colour = clean(row[column_index + 1] if column_index + 1 < len(row) else "")
                        qty = clean(row[column_index + 2] if column_index + 2 < len(row) else "")

                        if item == "ITEM" or not item or not colour:
                            continue

                        item_match = ITEM_RE.match(item)
                        if not item_match:
                            continue

                        records.append(
                            {
                                "page": page_number,
                                "table": table_number,
                                "row": row_number,
                                "block": block_number,
                                "item": item,
                                "length": f'{item_match.group(1)}"',
                                "colour": colour,
                                "qty": qty,
                            }
                        )

    return records


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", help="Path to ECHO EW LIST PDF")
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
                f"p{record['page']:02d} r{record['row']:02d} b{record['block']} | "
                f"{record['item']} | {record['colour']} | {record['qty']}"
            )

    return 0


if __name__ == "__main__":
    sys.exit(main())
