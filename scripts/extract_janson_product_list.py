#!/usr/bin/env python3
"""Layout-aware extractor for JANSON PRODUCT LIST Dec'25.pdf."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

import fitz


MIDPOINT_X = 294
SKIP_CODES = {
    "PRICE",
    "QTY",
    "NAME",
    "DATE",
    "WEB",
    "EMAIL",
    "FAX",
    "2025",
    "1000ML",
    "500ML",
    "250ML",
    "200ML",
    "150ML",
    "100ML",
    "12OZ",
    "16OZ",
    "8OZ",
    "4OZ",
    "5OZ",
    "2OZ",
}


def is_code(token: str) -> bool:
    value = token.strip().strip(":")
    return (
        4 <= len(value) <= 14
        and value not in SKIP_CODES
        and re.search(r"\d", value) is not None
        and re.match(r"^[A-Z][A-Z0-9]*$", value) is not None
        and re.search(r"[A-Z]", value) is not None
    )


def is_price(token: str) -> bool:
    value = token.strip().replace("Ł", "").replace("£", "")
    return re.match(r"^\d+[,.]\d{2}$", value) is not None


def line_groups(page: fitz.Page) -> list[dict]:
    words = [
        word
        for word in page.get_text("words")
        if 100 <= word[1] <= 800 and word[4].strip()
    ]
    groups: list[dict] = []

    for word in sorted(words, key=lambda item: (item[1], item[0])):
        y_center = (word[1] + word[3]) / 2

        for group in groups:
            if abs(group["y_center"] - y_center) <= 2.2:
                group["words"].append(word)
                count = len(group["words"])
                group["y_center"] = ((group["y_center"] * (count - 1)) + y_center) / count
                break
        else:
            groups.append({"y_center": y_center, "words": [word]})

    return sorted(groups, key=lambda group: group["y_center"])


def segment_words(words: list[tuple], side: str) -> list[tuple]:
    if side == "L":
        return [word for word in words if 15 <= word[0] < MIDPOINT_X]

    return [word for word in words if MIDPOINT_X <= word[0] < 580]


def parse_segment(words: list[tuple]) -> dict | None:
    tokens = [word[4] for word in sorted(words, key=lambda item: item[0])]
    if not tokens:
        return None

    code_index = None
    for index, token in enumerate(tokens[:4]):
        if is_code(token):
            code_index = index
            break

    if code_index is None:
        return {"kind": "heading", "text": " ".join(tokens)}

    code = tokens[code_index]
    description: list[str] = []
    prices: list[str] = []
    quantity_markers: list[str] = []
    flags: list[str] = []

    for token in tokens[code_index + 1 :]:
        if token in {"XXX", "XX", "X"}:
            quantity_markers.append(token)
            continue
        if is_price(token) or token in {"£", "Ł"}:
            prices.append(token)
            continue
        if token.replace("*", "").upper() == "NEW":
            flags.append(token)
            continue
        description.append(token)

    return {
        "kind": "product",
        "code": code,
        "description": " ".join(description).strip(),
        "price": " ".join(prices).replace("Ł", "£").strip(),
        "quantity_marker": " ".join(quantity_markers).strip(),
        "flags": " ".join(flags).strip(),
        "raw": " ".join(tokens),
    }


def clean_heading(value: str) -> str:
    value = re.sub(r"\s+", " ", value).strip()
    value = re.sub(r"\bPrice\s+QTY\b", "", value, flags=re.I).strip()
    return value


def should_accept_heading(value: str) -> bool:
    if not value:
        return False

    upper = value.upper()
    if upper in {"PRICE QTY", "PRICE", "QTY"} or upper.startswith("SOME PRODUCTS"):
        return False

    tokens = value.split()
    if any(is_code(token) for token in tokens):
        return False
    if any(is_price(token) for token in tokens):
        return False

    return True


def extract(path: Path) -> dict:
    document = fitz.open(str(path))
    records: list[dict] = []
    headings: list[dict] = []

    for page_number, page in enumerate(document, start=1):
        current_heading = {"L": "", "R": ""}

        for group in line_groups(page):
            for side in ("L", "R"):
                parsed = parse_segment(segment_words(group["words"], side))
                if not parsed:
                    continue

                if parsed["kind"] == "heading":
                    heading = clean_heading(parsed["text"])
                    if should_accept_heading(heading):
                        current_heading[side] = heading
                        headings.append(
                            {
                                "page": page_number,
                                "side": side,
                                "heading": heading,
                            }
                        )
                    continue

                records.append(
                    {
                        "page": page_number,
                        "side": side,
                        "family_heading": current_heading[side],
                        "code": parsed["code"],
                        "description": parsed["description"],
                        "price": parsed["price"],
                        "quantity_marker": parsed["quantity_marker"],
                        "flags": parsed["flags"],
                        "raw": parsed["raw"],
                    }
                )

    return {
        "source_name": path.name,
        "source_path": str(path),
        "page_count": len(document),
        "records": records,
        "headings": headings,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true", help="Emit JSON only.")
    parser.add_argument("pdf", type=Path)
    args = parser.parse_args()

    payload = extract(args.pdf)

    if args.json:
        print(json.dumps(payload, ensure_ascii=True))
        return

    print(f"pages: {payload['page_count']}")
    print(f"records: {len(payload['records'])}")
    print(f"headings: {len(payload['headings'])}")


if __name__ == "__main__":
    main()
