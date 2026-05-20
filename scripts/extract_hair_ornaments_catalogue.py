import argparse
import json
import re
import subprocess
import tempfile
from pathlib import Path

import pypdfium2 as pdfium
from PIL import Image

TESSERACT_PATH = Path(r"C:\Program Files\Tesseract-OCR\tesseract.exe")

COMMON_HEADER_FIXES = {
    "COLLECLION": "COLLECTION",
    "COLLEOTION": "COLLECTION",
    "COLLECTION CAPS": "COLLECTION CAPS",
    "BULK WIG CAPT": "BULK WIG CAPS",
    "BURW IG CAP": "BULK WIG CAP",
    "BURQ IY CAPS": "BULK WIG CAPS",
    "BURQ WIG CAPS": "BULK WIG CAPS",
    "WIGCAD": "WIG CAP",
    "WIGCAp": "WIG CAP",
    "WIGCAP": "WIG CAP",
    "SLEEPCAP": "SLEEP CAP",
    "SKULLCAP": "SKULL CAP",
    "DOMECAP": "DOME CAP",
    "MESHDOME": "MESH DOME",
    "STOCKIN(": "STOCKING",
    "STOCWNG": "STOCKING",
    "STOCKNG": "STOCKING",
    "STOGING": "STOCKING",
    "BLOMDE": "BLONDE",
    "MURRY ARGAN + OLIVEG OLLI CTION": "MURRY ARGAN + OLIVE COLLECTION",
    "MAGIC COLLECTION BURQ IY CAPS": "MAGIC COLLECTION BULK WIG CAPS",
    "MAGIC COLLECTION BURQ WIG CAPS": "MAGIC COLLECTION BULK WIG CAPS",
}

CODE_STOPWORDS = {
    "COLLECTION",
    "MAGIC",
    "BANDANA",
    "BANDANAS",
    "WIGCAP",
    "WIG",
    "CAP",
    "CAPS",
    "SKULLCAP",
    "DOMECAP",
    "STOCKING",
    "SLEEP",
    "BAND",
    "BLACK",
    "BROWN",
    "BLONDE",
    "WHITE",
    "RED",
    "NAVY",
    "ASSORTED",
    "PAGE",
    "PCS",
    "OZ",
    "ML",
    "SIZE",
    "LINE",
    "SILICON",
    "BULK",
}

VARIANT_MAP = {
    "BLA": "BLACK",
    "BLK": "BLACK",
    "BRO": "BROWN",
    "BRN": "BROWN",
    "BLO": "BLONDE",
    "AST": "ASSORTED",
    "NAT": "NATURAL",
    "RED": "RED",
    "NAV": "NAVY",
    "WHI": "WHITE",
    "WHT": "WHITE",
    "PUR": "PURPLE",
    "PNK": "PINK",
    "SIL": "SILVER",
    "GLD": "GOLD",
    "GRY": "GREY",
    "GRE": "GREY",
    "ORG": "ORANGE",
    "BLU": "BLUE",
    "SKY": "SKY BLUE",
    "BUR": "BURGUNDY",
    "LEV": "LEAF",
    "MIL": "MILITARY",
    "CAM": "CAMOUFLAGE",
    "JAM": "JAMAICAN FLAG",
    "MON": "DOLLAR",
    "FLA": "US FLAG",
    "HAI": "HAITI FLAG",
    "RBLU": "ROYAL BLUE",
    "LPUR": "LILAC PURPLE",
    "LGRY": "LIGHT GREY",
    "WHIP": "WHITE PLAIN",
    "BLKP": "BLACK PLAIN",
    "GRYP": "GREY PLAIN",
    "REDP": "RED PLAIN",
    "PURP": "PURPLE PLAIN",
    "YEL": "YELLOW",
    "ARMY": "ARMY",
}

HEADER_BRAND_RULES = [
    ("MAGIC", "MAGIC COLLECTION"),
    ("MURRY", "MURRY"),
    ("BANDANA", "BANDANAS"),
    ("BANDANAS", "BANDANAS"),
]

ITEM_PATTERNS = [
    ("LINE SILICON WIG BAND", "LINE SILICON WIG BAND"),
    ("SILICON WIG BAND", "SILICON WIG BAND"),
    ("DRAWSTRING HAIR CAP", "DRAWSTRING HAIR CAP"),
    ("STOCKING WAVE CAP", "STOCKING WAVE CAP"),
    ("WAVE CAP", "WAVE CAP"),
    ("STRETCHABLE SPANDEX CAP", "STRETCHABLE SPANDEX CAP"),
    ("SPANDEX CAP", "SPANDEX CAP"),
    ("SPANDEX DOME CAP", "SPANDEX DOME CAP"),
    ("MESH DOME CAP", "MESH DOME CAP"),
    ("DOME CAP", "DOME CAP"),
    ("SKULL CAP", "SKULL CAP"),
    ("STOCKING WIG CAP", "STOCKING WIG CAP"),
    ("WIG CAP", "WIG CAP"),
    ("SETTING NET", "SETTING NET"),
    ("WRAP BAND", "WRAP BAND"),
    ("HEAD WRAP", "HEAD WRAP"),
    ("HEADBAND", "HEADBAND"),
    ("HAIR BAND", "HAIR BAND"),
    ("PONYTAIL HOLDER", "PONYTAIL HOLDER"),
    ("BONNET", "BONNET"),
    ("BANDANA", "BANDANA"),
    ("SCARF", "SCARF"),
    ("NET", "NET"),
    ("CAP", "CAP"),
]


def normalize_space(text: str) -> str:
    cleaned = (text or "").replace("\x00", " ")
    cleaned = re.sub(r"[\u200b\u200c\u200d\ufeff]", " ", cleaned)
    cleaned = cleaned.replace("â€™", "'").replace("`", "'")
    cleaned = re.sub(r"\s+", " ", cleaned)
    return cleaned.strip()


def normalize_line(text: str) -> str:
    line = normalize_space(text).upper()
    line = line.replace("â€œ", '"').replace("â€", '"')
    line = line.replace("â€”", "-").replace("â€“", "-")
    line = re.sub(r"[|_~]+", " ", line)
    line = re.sub(r"[^A-Z0-9#&+\-/'().%\s]", " ", line)
    line = re.sub(r"#\s+([A-Z0-9])", r"#\1", line)
    line = re.sub(r"\s+", " ", line)
    for bad, good in COMMON_HEADER_FIXES.items():
        line = line.replace(bad, good)
    return line.strip()


def ocr_lines(image_path: Path, psm: int) -> list[str]:
    result = subprocess.run(
        [str(TESSERACT_PATH), str(image_path), "stdout", "--psm", str(psm)],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )
    lines = []
    for raw_line in result.stdout.splitlines():
        line = normalize_line(raw_line)
        if line:
            lines.append(line)
    return lines


def ocr_header_lines(image_path: Path) -> list[str]:
    image = Image.open(image_path)
    width, height = image.size
    crop = image.crop((0, 0, width, max(120, int(height * 0.22))))
    header_path = image_path.with_name(f"{image_path.stem}-header{image_path.suffix}")
    crop.save(header_path)

    lines = []
    for psm in (6, 11):
        lines.extend(ocr_lines(header_path, psm))
    deduped = []
    seen = set()
    for line in lines:
        if line not in seen:
            deduped.append(line)
            seen.add(line)
    return deduped


def is_probable_code(
    token: str,
    active_alpha_prefixes: set[str] | None = None,
    allow_plain_alpha: bool = False,
) -> bool:
    token = token.strip().upper().lstrip("#")
    token = re.sub(r"[^A-Z0-9]", "", token)
    if len(token) < 4 or len(token) > 16:
        return False
    if token.isdigit():
        return False
    if token in CODE_STOPWORDS:
        return False
    if token.endswith(("PCS", "OZ", "ML", "CM")):
        return False
    if any(token.startswith(word) for word in ("COLLECTION", "MAGIC", "BANDANA", "STOCKING")):
        return False

    has_digit = any(ch.isdigit() for ch in token)
    if has_digit:
        if not re.fullmatch(r"[A-Z]{0,5}\d{2,6}[A-Z]{0,5}", token):
            return False
        return True

    if allow_plain_alpha and active_alpha_prefixes and len(token) >= 6 and token[:3] in active_alpha_prefixes:
        return True

    return False


def normalize_code(
    token: str,
    active_alpha_prefixes: set[str] | None = None,
    allow_plain_alpha: bool = False,
) -> str | None:
    token = normalize_line(token)
    token = token.replace(" ", "")
    token = token.lstrip("#")
    token = re.sub(r"[^A-Z0-9]", "", token)
    if not is_probable_code(token, active_alpha_prefixes, allow_plain_alpha):
        return None
    return token


def infer_active_alpha_prefixes(lines: list[str]) -> set[str]:
    counts: dict[str, int] = {}
    for line in lines:
        for raw in re.findall(r"#?[A-Z0-9]{4,16}", line):
            token = normalize_line(raw).lstrip("#")
            token = re.sub(r"[^A-Z0-9]", "", token)
            if len(token) < 6 or len(token) > 12:
                continue
            if any(ch.isdigit() for ch in token):
                continue
            if token in CODE_STOPWORDS:
                continue
            prefix = token[:3]
            counts[prefix] = counts.get(prefix, 0) + 1
    return {prefix for prefix, count in counts.items() if count >= 3}


def extract_hash_codes(lines: list[str]) -> list[dict]:
    results = []
    seen = set()
    for line_index, line in enumerate(lines):
        for raw in re.findall(r"#([A-Z0-9]{4,16})", line):
            code = normalize_code(raw)
            if code and code not in seen:
                seen.add(code)
                results.append({"code": code, "line_index": line_index, "raw": raw, "line_text": line})
    return results


def extract_plain_codes(lines: list[str], active_alpha_prefixes: set[str]) -> list[dict]:
    results = []
    seen = set()
    for line_index, line in enumerate(lines):
        for raw in re.findall(r"\b[A-Z0-9]{4,16}\b", line):
            code = normalize_code(raw, active_alpha_prefixes, allow_plain_alpha=True)
            if code and code not in seen:
                seen.add(code)
                results.append({"code": code, "line_index": line_index, "raw": raw, "line_text": line})
    return results


def merge_code_lists(primary: list[dict], secondary: list[dict]) -> list[dict]:
    merged = []
    seen = set()
    for entry in primary + secondary:
        if entry["code"] in seen:
            continue
        seen.add(entry["code"])
        merged.append(entry)
    return merged


def extract_codes(lines6: list[str], lines11: list[str]) -> list[dict]:
    hash_codes = merge_code_lists(extract_hash_codes(lines6), extract_hash_codes(lines11))
    if len(hash_codes) >= 3:
        return hash_codes

    active_alpha_prefixes = infer_active_alpha_prefixes(lines6 + lines11)
    plain_codes = merge_code_lists(
        extract_plain_codes(lines6, active_alpha_prefixes),
        extract_plain_codes(lines11, active_alpha_prefixes),
    )
    return merge_code_lists(hash_codes, plain_codes)


def header_score(line: str) -> int:
    if not line:
        return -10
    score = 0
    if any(ch.isalpha() for ch in line):
        score += 4
    if 6 <= len(line) <= 90:
        score += 3
    if any(word in line for word in ("COLLECTION", "CAP", "BANDANA", "BANDANAS", "WIG", "MAGIC", "MURRY")):
        score += 8
    if "#" in line:
        score -= 8
    if line.count("PCS") > 0:
        score -= 5
    if re.fullmatch(r"[A-Z0-9\s#]+", line):
        score += 1
    return score


def normalize_header_text(header_text: str, lines: list[str], codes: list[dict]) -> str:
    haystack = " ".join([header_text] + lines[:12])
    code_values = [entry["code"] for entry in codes]

    if any(code.startswith("BAN") for code in code_values) or "BANDANA" in haystack:
        return "100% COTTON BANDANA COLLECTION"
    if "MURRY" in haystack and "ARGAN" in haystack and "OLIVE" in haystack:
        return "MURRY ARGAN + OLIVE COLLECTION"
    if (
        any(code.startswith("0140") for code in code_values)
        or any(code.startswith("2291") for code in code_values)
        or "LINE SILICON WIG" in haystack
    ) and "CAP" in haystack:
        return "MAGIC COLLECTION BULK WIG CAPS"
    if "MAGIC" in haystack and "BULK" in haystack and "WIG" in haystack and "CAP" in haystack:
        return "MAGIC COLLECTION BULK WIG CAPS"
    if "MAGIC" in haystack and "COLLECTION" in haystack and "CAP" in haystack:
        return "MAGIC COLLECTION CAPS"

    header_text = normalize_line(header_text)
    for bad, good in COMMON_HEADER_FIXES.items():
        header_text = header_text.replace(bad, good)
    return header_text or "Unknown Family"


def derive_header(
    lines6: list[str],
    lines11: list[str],
    header_lines: list[str],
    first_code_index: int | None,
    codes: list[dict],
) -> str:
    candidates = []
    candidates.extend(header_lines)

    search_lines = lines6[:]
    if first_code_index is not None:
        search_lines = lines6[: max(1, min(first_code_index, 8))]
    candidates.extend([line for line in search_lines if line and "#" not in line])
    candidates.extend([line for line in lines11[:8] if line and "#" not in line])

    if not candidates:
        return normalize_header_text("Unknown Family", lines6 + lines11, codes)

    candidates = sorted(dict.fromkeys(candidates), key=header_score, reverse=True)
    return normalize_header_text(candidates[0], header_lines + lines6 + lines11, codes)


def infer_brand(header_text: str, lines: list[str], codes: list[dict]) -> tuple[str, str]:
    haystack = " ".join([header_text] + lines[:10])
    for token, brand in HEADER_BRAND_RULES:
        if token in haystack:
            return brand, "ocr_header"
    if any(fragment in haystack for fragment in ("AAGIC", "AFAYIC", "COLICTHON")):
        return "MAGIC COLLECTION", "ocr_header_fuzzy"
    if any(entry["code"].startswith("BAN") for entry in codes):
        return "BANDANAS", "code_family"
    return "Unknown", "ocr_header"


def extract_quantity(text: str) -> str | None:
    for pattern in (r"\b\d+\s*[Xx]\s*\d+\s*PCS\b", r"\b\d+\s*PCS\b"):
        match = re.search(pattern, text)
        if match:
            return re.sub(r"\s+", "", match.group(0).upper())
    return None


def strip_codes(text: str) -> str:
    text = re.sub(r"#?[A-Z]{0,5}\d{2,6}[A-Z]{0,5}", " ", text)
    text = re.sub(r"\b[A-Z]{6,12}\b", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip(" -|")


def descriptor_from_line(line: str, code: str) -> str:
    code_pattern = re.escape(code)
    line = normalize_line(line)
    match = re.search(code_pattern, line)
    if not match:
        return ""
    tail = line[match.end():]
    tail = re.split(r"#?[A-Z]{0,5}\d{2,6}[A-Z]{0,5}|\b[A-Z]{6,12}\b", tail)[0]
    tail = strip_codes(tail)
    return tail


def extract_variant(code: str, descriptor: str, context: str) -> str | None:
    descriptor_upper = descriptor.upper()
    context_upper = context.upper()
    suffix_variant = None
    for suffix, variant in sorted(VARIANT_MAP.items(), key=lambda item: len(item[0]), reverse=True):
        if code.endswith(suffix):
            suffix_variant = variant
            break

    descriptor_variant = None
    for phrase in (
        "JAMAICAN FLAG",
        "US FLAG",
        "HAITI FLAG",
        "LIGHT GREY",
        "LILAC PURPLE",
        "CAMOUFLAGE",
        "MILITARY",
        "DOLLAR",
        "ASSORTED",
        "PLAIN",
        "PRINTED",
        "ARMY",
        "BLACK",
        "BROWN",
        "BLONDE",
        "YELLOW",
        "RED",
        "NAVY",
        "WHITE",
        "PURPLE",
        "PINK",
        "SILVER",
        "GOLD",
        "GREY",
        "ORANGE",
        "BLUE",
    ):
        if phrase in descriptor_upper:
            descriptor_variant = phrase
            break

    if (
        suffix_variant
        and descriptor_variant in {"PLAIN", "PRINTED"}
        and suffix_variant != "ASSORTED"
        and not suffix_variant.endswith(descriptor_variant)
    ):
        return f"{suffix_variant} {descriptor_variant}"
    if descriptor_variant and descriptor_variant not in {"PLAIN", "PRINTED"}:
        return descriptor_variant
    if suffix_variant:
        return suffix_variant

    for phrase in (
        "JAMAICAN FLAG",
        "US FLAG",
        "HAITI FLAG",
        "LIGHT GREY",
        "LILAC PURPLE",
        "CAMOUFLAGE",
        "MILITARY",
        "DOLLAR",
        "ASSORTED",
        "PLAIN",
        "PRINTED",
        "ARMY",
        "BLACK",
        "BROWN",
        "BLONDE",
        "YELLOW",
        "RED",
        "NAVY",
        "WHITE",
        "PURPLE",
        "PINK",
        "SILVER",
        "GOLD",
        "GREY",
        "ORANGE",
        "BLUE",
    ):
        if phrase in context_upper:
            return phrase
    return None


def infer_item_type(family: str, descriptor: str, context: str) -> str | None:
    haystack = f"{family} {descriptor} {context}".upper()
    for needle, label in ITEM_PATTERNS:
        if needle in haystack:
            return label
    return None


def build_context(lines: list[str], line_index: int) -> str:
    start = max(0, line_index - 2)
    end = min(len(lines), line_index + 3)
    context_parts = []
    for line in lines[start:end]:
        cleaned = strip_codes(line)
        if cleaned and any(ch.isalpha() for ch in cleaned):
            context_parts.append(cleaned)
    return " | ".join(dict.fromkeys(context_parts))


def clean_family_for_name(family: str) -> str:
    family = normalize_line(family)
    family = family.replace("100 COTTON", "100% COTTON")
    family = re.sub(r"\s+", " ", family)
    return family.strip(" -")


def build_product_name(
    family: str,
    item_type: str | None,
    variant: str | None,
    quantity: str | None,
    code: str,
    descriptor: str,
) -> str:
    parts = []
    family = clean_family_for_name(family)
    if family and family != "Unknown Family":
        parts.append(family)
    if item_type and item_type not in parts:
        parts.append(item_type)
    elif descriptor and descriptor not in parts:
        cleaned_descriptor = strip_codes(descriptor)
        if cleaned_descriptor and len(cleaned_descriptor) <= 80:
            parts.append(cleaned_descriptor)
    if variant and variant not in parts:
        parts.append(variant)
    if quantity and quantity not in parts:
        parts.append(quantity)
    if not parts:
        parts.append(f"ITEM {code}")
    name = " - ".join(dict.fromkeys(parts))
    name = re.sub(r"\s+", " ", name).strip(" -")
    return name[:255]


def confidence_for_product(
    brand: str,
    family: str,
    item_type: str | None,
    variant: str | None,
    quantity: str | None,
    descriptor: str,
) -> tuple[str, str]:
    if brand != "Unknown" and family != "Unknown Family" and (item_type or variant or quantity or descriptor):
        return "A", "OCR header and local code context clearly identify the product family and variant."
    if brand != "Unknown" and family != "Unknown Family":
        return "B", "OCR identifies the page family and brand clearly, but the item-level descriptor is lighter."
    if family != "Unknown Family":
        return "C", "Code is clear and the page family is identifiable, but the manufacturer brand is not reliable enough to confirm."
    return "D", "Code was detected, but the page context is too weak to trust the product family."


def extract_page(pdf: pdfium.PdfDocument, page_number: int, workdir: Path) -> dict:
    page = pdf[page_number - 1]
    image_path = workdir / f"page-{page_number}.png"
    bitmap = page.render(scale=3.2)
    bitmap.to_pil().save(image_path)

    lines6 = ocr_lines(image_path, 6)
    lines11 = ocr_lines(image_path, 11)
    header_lines = ocr_header_lines(image_path)

    codes = extract_codes(lines6, lines11)
    first_code_index = min((entry["line_index"] for entry in codes), default=None)
    header_text = derive_header(lines6, lines11, header_lines, first_code_index, codes)
    brand, brand_source = infer_brand(header_text, header_lines + lines6 + lines11, codes)

    products = []
    for sort_order, entry in enumerate(codes, start=1):
        code = entry["code"]
        line_index = entry["line_index"]
        descriptor = descriptor_from_line(entry.get("line_text", ""), code)
        context = build_context(lines6 + lines11, line_index)
        item_type = infer_item_type(header_text, descriptor, context)
        variant = extract_variant(code, descriptor, context)
        quantity = extract_quantity(f"{descriptor} {context}")
        product_name = build_product_name(header_text, item_type, variant, quantity, code, descriptor)
        confidence, confidence_reason = confidence_for_product(
            brand, header_text, item_type, variant, quantity, descriptor
        )

        products.append({
            "sort_order": sort_order,
            "brand": brand,
            "brand_source": brand_source,
            "product_code": code,
            "product_name": product_name,
            "confidence": confidence,
            "confidence_reason": confidence_reason,
            "raw_name_text": descriptor or context or product_name,
        })

    return {
        "page_number": page_number,
        "header_text": header_text,
        "brand_context": brand,
        "brand_context_source": brand_source,
        "raw_text": "\n".join(lines6),
        "products": products,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--path", required=True)
    parser.add_argument("--from", dest="from_page", type=int, default=1)
    parser.add_argument("--to", dest="to_page", type=int, default=None)
    args = parser.parse_args()

    if not TESSERACT_PATH.is_file():
        raise RuntimeError(f"Tesseract was not found at {TESSERACT_PATH}")

    source_path = str(Path(args.path).resolve())
    source_name = Path(source_path).name

    pdf = pdfium.PdfDocument(source_path)
    last_page = len(pdf)
    from_page = max(1, int(args.from_page or 1))
    to_page = min(last_page, int(args.to_page or last_page))

    pages = []
    with tempfile.TemporaryDirectory(prefix="hair-ornaments-ocr-") as temp_dir:
        workdir = Path(temp_dir)
        for page_number in range(from_page, to_page + 1):
            pages.append(extract_page(pdf, page_number, workdir))

    print(json.dumps({
        "source_name": source_name,
        "source_path": source_path,
        "pages": pages,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
