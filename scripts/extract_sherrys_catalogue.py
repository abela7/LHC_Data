import argparse
import json
import math
import re
from pathlib import Path
from urllib.error import URLError
from urllib.request import Request, urlopen

import pdfplumber

CODE_RE = re.compile(r"^(?=.*\d)[A-Z][A-Z0-9]{2,}$")
SIZE_RE = re.compile(r"\b\d+(?:\.\d+)?\s?(?:OZ|ML|G|KG|L|PCS|PACK|INCH|INCHES|CM|MM|')\b", re.IGNORECASE)
GENERIC_HEADER_RE = re.compile(
    r"(HOW TO ORDER|PLEASE NOTE|CATALOGUE IS SORTED|SHERRYS WHOLESALE|UNIT \d|TEL:|EMAIL:)",
    re.IGNORECASE,
)
# Page merchandising (e.g. **DOZEN PRICE**) must never appear in product_name — not product identity.
MERCH_FROM_NAME_RE = re.compile(
    r"(?:\*{1,2}\s*)?DOZEN\s+PRICE(?:\s*\*{1,2})?",
    re.IGNORECASE,
)
MANUAL_CODE_TOKENS = {
    "AJBH",
    "EDGEBRUSH",
}
MANUAL_PREFIX_BRANDS = {
    "AB": "AFRICA'S BEST",
    "AF": "AFRICAN PRIDE",
    "AJ": "AUNT JACKIE'S",
    "ALLO": "ALLORED",
    "ASIA": "AS I AM",
    "BI": "BIGEN",
    "BM": "BLUE MAGIC",
    "BP": "BUMP PATROL",
    "BS": "HIGH TIME",
}

MANUAL_PAGE_BRANDS = {
    151: "CREME OF NATURE",
    152: "CREME OF NATURE",
    153: "CREME OF NATURE",
    154: "CREME OF NATURE",
    155: "CREME OF NATURE",
    156: "CREME OF NATURE",
    157: "CREME OF NATURE",
    158: "CREME OF NATURE",
    159: "CREME OF NATURE",
    160: "CREME OF NATURE",
    161: "CREME OF NATURE",
    162: "CREME OF NATURE",
    163: "CREME OF NATURE",
    164: "CREME OF NATURE",
    165: "CREME OF NATURE",
    166: "CREME OF NATURE",
    167: "CREME OF NATURE",
    168: "CREME OF NATURE",
    169: "CREME OF NATURE",
    170: "CREME OF NATURE",
    171: "CREME OF NATURE",
    172: "CREME OF NATURE",
    174: "CAMILLE ROSE",
    175: "CAMILLE ROSE",
    176: "CAMILLE ROSE",
    177: "CAMILLE ROSE",
    178: "CAMILLE ROSE",
    179: "CAMILLE ROSE",
    180: "CAMILLE ROSE",
    181: "CAMILLE ROSE",
    182: "CAMILLE ROSE",
    183: "CAMILLE ROSE",
    184: "CAMILLE ROSE",
    186: "COVER YOUR GRAY",
    187: "COVER YOUR GRAY",
    188: "COVER YOUR GRAY",
    189: "DABUR",
    190: "DABUR",
    191: "DABUR",
    192: "VATIKA",
    194: "VATIKA",
    195: "VATIKA",
    196: "VATIKA",
    197: "VATIKA",
    198: "VATIKA",
    199: "VATIKA",
    200: "VATIKA",
    201: "VATIKA",
    202: "VATIKA",
    203: "VATIKA",
    204: "VATIKA",
    205: "VATIKA",
    206: "VATIKA",
    207: "VATIKA",
    208: "DAX",
    209: "DAX",
    210: "DAX",
    211: "DAX",
    214: "DETTOL",
    215: "DEXE BLK",
    276: "IRISH SPRING",
    288: "JAMAICAN MANGO & LIME",
    289: "JAMAICAN MANGO & LIME",
    295: "KERACARE",
    307: "LUSTER'S PINK",
    308: "LUSTER'S PINK KIDS",
    320: "MIELLE ORGANICS",
    339: "NYXON",
    340: "NYXON",
    360: "PALMER'S",
    400: "SHINE N JAM",
    407: "SHEA MOISTURE",
    415: "SHEA MOISTURE",
    433: "STA-SOF-FRO",
    440: "SUNNY ISLE",
    441: "SUNNY ISLE",
    461: "VASELINE",
}

MANUAL_PAGE_BRANDS.update({
    226: "DR. MIRACLE'S",
    228: "E45",
    229: "ECO STYLE",
    230: "ECO STYLE",
    231: "ECO STYLE",
    232: "ECO STYLE",
    233: "ECO STYLE",
    234: "ECO STYLE",
    235: "ECO STYLE",
    236: "ELASTA QP",
    237: "ELASTA QP",
    239: "GODREJ EXPERT",
    242: "FAIR & WHITE",
    243: "FAIR & WHITE",
    244: "FAIR & WHITE",
    251: "BLOOM",
    252: "FASHION JEWELRY",
    253: "FASHION JEWELRY",
    254: "FASHION JEWELRY",
    255: "FASHION JEWELRY",
    256: "FASHION JEWELRY",
    257: "FASHION JEWELRY",
    258: "SHERRYS HAIR ACCESSORIES",
    259: "SHERRYS HAIR ACCESSORIES",
    260: "SHERRYS HAIR ACCESSORIES",
    265: "FANTASIA 1C",
    266: "FANTASIA 1C",
    267: "FANTASIA 1C",
    268: "FANTASIA 1C",
    269: "FANTASIA 1C",
    270: "FANTASIA 1C",
    271: "FANTASIA 1C",
    272: "FANTASIA 1C",
    273: "FANTASIA 1C",
    274: "FANTASIA 1C",
    275: "FANTASIA 1C",
    278: "JUST FOR MEN",
    279: "JOHNSON'S BABY",
    280: "JUST FOR ME",
    281: "JUST FOR ME",
    282: "JUST FOR ME",
    283: "JUST FOR ME",
    284: "JAMAICAN MANGO & LIME",
    285: "JAMAICAN MANGO & LIME",
    286: "JAMAICAN MANGO & LIME",
    287: "JAMAICAN MANGO & LIME",
    290: "JAMAICAN MANGO & LIME",
    291: "JAMAICAN MANGO & LIME",
    292: "KERACARE",
    293: "KERACARE",
    294: "KERACARE",
    296: "KERACARE",
    297: "KERACARE",
    298: "KERACARE",
    299: "KERACARE",
    300: "KUZA",
    302: "HOT LIPS",
    303: "LET'S JAM",
    304: "LUSTER'S PINK",
    305: "LUSTER'S PINK",
    306: "LUSTER'S PINK",
    309: "LUSTER'S PINK",
    310: "LUSTER'S PINK",
    311: "SCURL",
    312: "SCURL",
    313: "MAGIC",
    314: "MIELLE ORGANICS",
    315: "MIELLE ORGANICS",
    316: "MIELLE ORGANICS",
    317: "MIELLE ORGANICS",
    318: "MIELLE ORGANICS",
    319: "MIELLE ORGANICS",
    321: "MIELLE ORGANICS",
    322: "MIELLE ORGANICS",
    323: "MIELLE ORGANICS",
    324: "MIELLE ORGANICS",
    325: "MIELLE ORGANICS",
    326: "MIELLE ORGANICS",
    327: "MORGAN'S POMADE",
    328: "MOTIONS",
    329: "MOTIONS",
    330: "MOTIONS",
    331: "MURRAY'S",
    333: "NIHARTI",
    336: "NIHARTI",
    337: "NIHARTI",
    338: "NIHARTI",
    341: "ORGANIC HAIR ENERGIZER",
    342: "ORS",
    343: "ORS",
    344: "ORS",
    345: "ORS",
    346: "ORS",
    347: "ORS",
    348: "ORS",
    349: "ORS",
    350: "ORS",
    351: "ORS",
    352: "ORS",
    353: "ORS",
    354: "ORS",
    355: "ORS",
    356: "ORS",
    357: "PALMER'S",
    358: "PALMER'S",
    359: "PALMER'S",
    361: "PALMER'S",
    362: "PALMER'S",
    363: "PALMER'S",
    364: "PALMER'S",
    365: "PALMER'S",
    366: "PALMER'S",
    367: "PALMER'S",
    368: "PALMER'S",
    369: "PALMER'S",
    370: "PALMER'S",
    371: "PALMER'S",
    372: "PALMER'S",
    373: "PALMER'S",
    374: "PALMER'S",
    375: "PARACHUTE",
    376: "PARACHUTE",
    377: "PARACHUTE",
    378: "PARACHUTE",
    379: "PARACHUTE",
    380: "PROFECTIV",
    381: "PROFECTIV",
    382: "PROFECTIV",
    383: "QUEEN ELIZABETH",
    384: "QUEEN HELENE",
    386: "TUB O' TOWELS",
    387: "TUB O' TOWELS",
    394: "RICO",
    397: "SOFT & BEAUTIFUL",
    403: "STYLE MY EDGES",
    404: "STYLE MY EDGES",
    405: "STYLE MY EDGES",
    406: "SHEA MOISTURE",
    408: "SHEA MOISTURE",
    409: "SHEA MOISTURE",
    410: "SHEA MOISTURE",
    411: "SHEA MOISTURE",
    412: "SHEA MOISTURE",
    413: "SHEA MOISTURE",
    414: "SHEA MOISTURE",
    416: "SHEA MOISTURE",
    417: "SHEA MOISTURE",
    418: "SHEA MOISTURE",
    419: "SHEA MOISTURE",
    420: "SOFN'FREE",
    421: "SOFN'FREE",
    422: "SOFN'FREE",
    423: "SOFN'FREE",
    424: "SOFN'FREE",
    425: "SOFN'FREE N' PRETTY",
    426: "SOFN'FREE",
    427: "SOFN'FREE",
    428: "SALON PRO",
    434: "STA-SOF-FRO",
    435: "STA-SOF-FRO",
    436: "STA-SOF-FRO",
    437: "STA-SOF-FRO",
    438: "SULFUR8",
    439: "SUNNY ISLE",
    442: "SUNNY ISLE",
    443: "SUNNY ISLE",
    444: "SUNNY ISLE",
    445: "SUNNY ISLE",
    446: "SUNNY ISLE",
    447: "SUNNY ISLE",
    448: "SUNNY ISLE",
    449: "SUNNY ISLE",
    450: "SUNNY ISLE",
    453: "TOP-OP",
    466: "VITALE",
    467: "WAHL",
    468: "WAHL",
    469: "WAHL",
    470: "WAHL",
    471: "WAHL",
    472: "WAHL",
    473: "WAHL",
    474: "WAHL",
    475: "WAHL",
    476: "WAHL",
    477: "WAHL",
    478: "WAHL",
    479: "WAHL",
    480: "WAHL",
    481: "WAHL",
    482: "WAHL",
    483: "WAHL",
    484: "WAHL",
    485: "WAHL",
    486: "WAHL",
    487: "WAHL",
    488: "WAHL",
    489: "WAHL",
    490: "WAHL",
    491: "WAHL",
    492: "WAHL",
    493: "WAHL",
    494: "WAHL",
    495: "WAHL",
    497: "WONDER GRO",
    498: "X-PRESSION",
    499: "X-PRESSION",
    500: "X-PRESSION",
})

MANUAL_PRODUCT_OVERRIDES = {
    "AB12": {
        "brand": "AFRICA'S BEST",
        "product_name": "ORG CLOVE & OLIVE THERAPY 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against Pak Cosmetics retailer evidence.",
    },
    "AB13": {
        "brand": "AFRICA'S BEST",
        "product_name": "CARROT TEA TREE OIL THERAPY 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against Pak Cosmetics retailer evidence.",
    },
    "AFPM02A": {
        "brand": "AFRICAN PRIDE",
        "product_name": "Moisture Miracle HONEY & COCONUT SHAMPOO 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM03": {
        "brand": "AFRICAN PRIDE",
        "product_name": "Moisture Miracle ALOE & COCO EDGE STYLING WAX 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM06A": {
        "brand": "AFRICAN PRIDE",
        "product_name": "Moisture MIRACLE HONEY, CHOC & COCONUT COND 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM07": {
        "brand": "AFRICAN PRIDE",
        "product_name": "M/MIRACLE COCO & BAOBAB LEAVE IN CRM 15oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM08": {
        "brand": "AFRICAN PRIDE",
        "product_name": "M/MIRACLE SHEABUTT & FLEX CURLING CRM 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM09": {
        "brand": "AFRICAN PRIDE",
        "product_name": "M/MIRACLE SHEABUTT & CLAY MASQUE 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPM13": {
        "brand": "AFRICAN PRIDE",
        "product_name": "Moisture Miracle Curl Mousse",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPS02": {
        "brand": "AFRICAN PRIDE",
        "product_name": "SHEA MIRACLE DETANGLING SHAMPOO 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AFPS03": {
        "brand": "AFRICAN PRIDE",
        "product_name": "SHEA MIRACLE CURL LEAVE IN DEEP CONDITIONER 15oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJ01": {
        "brand": "AUNT JACKIE'S",
        "product_name": "Oh, so clean! Moisturizing & Softening Shampoo 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Aunt Jackie's product naming.",
    },
    "AJ02": {
        "brand": "AUNT JACKIE'S",
        "product_name": "Knot on my watch Instant Detangling Therapy 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Aunt Jackie's product naming.",
    },
    "AJ04": {
        "brand": "AUNT JACKIE'S",
        "product_name": "In control Moisturizing & Softening Conditioner 15oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Aunt Jackie's product naming.",
    },
    "AJ07": {
        "brand": "AUNT JACKIE'S",
        "product_name": "NAT GROWTH OIL GRAPESEED & AVOCADO 118ml #BALANCE",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJ08": {
        "brand": "AUNT JACKIE'S",
        "product_name": "NAT GROWTH OIL COCONUT & ALMOND 118ml #FRIZZ REBEL",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJ09": {
        "brand": "AUNT JACKIE'S",
        "product_name": "NAT GROWTH OIL FLAXSEED & MONOI 118ml #NOURISH",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJ10": {
        "brand": "AUNT JACKIE'S",
        "product_name": "NAT GROWTH OIL ARGAN 118ml #REPAIR MY HAIR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY HOLD TIGHT BRAID & TWIST X-TRA FIRM GEL 7.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH1": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY SMOOTH & SWIRL EDGE GEL 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH2": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY READY, SET, HOLD BRAID FOAM 8.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH3": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY OH SO STRONG SHINE BOOSTING MOIST 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH4": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY SCENT-SATIONAL HAIR PERFUME 4OZ",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH5": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY SCRATCH FREE ZONE ITCH SERUM 4OZ",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJBH6": {
        "brand": "AUNT JACKIE'S",
        "product_name": "BIOTIN HONEY DRY CLEAN 6OZ",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJE01": {
        "brand": "AUNT JACKIE'S",
        "product_name": "ELIXIR BIOTIN ROSEMARY MINT OIL 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Aunt Jackie's product page.",
    },
    "AJE02": {
        "brand": "AUNT JACKIE'S",
        "product_name": "ELIXIR SAW PALMETTO 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJE03": {
        "brand": "AUNT JACKIE'S",
        "product_name": "ELIXIR COLLAGEN 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AJE04": {
        "brand": "AUNT JACKIE'S",
        "product_name": "ELIXIR BATANA 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Aunt Jackie's product page.",
    },
    "ALLOR1": {
        "brand": "ALLORED",
        "product_name": "COCONUT MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLO1A": {
        "brand": "ALLORED",
        "product_name": "ARGAN MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR1B": {
        "brand": "ALLORED",
        "product_name": "JAMAICAN MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR1C": {
        "brand": "ALLORED",
        "product_name": "OLIVE MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR1D": {
        "brand": "ALLORED",
        "product_name": "ROSEMARY MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR1E": {
        "brand": "ALLORED",
        "product_name": "MANUKA HONEY W AVOCADO MOUSSE 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR7": {
        "brand": "ALLORED",
        "product_name": "ARGAN OIL 7 IN 1 LEAVE IN 250ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ALLOR7A": {
        "brand": "ALLORED",
        "product_name": "COCONUT OIL 7 IN 1 LEAVE IN 250ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ASIAM03": {
        "brand": "AS I AM",
        "product_name": "DETANGLING CONDITIONER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ASIAM09": {
        "brand": "AS I AM",
        "product_name": "LEAVE IN CONDITIONER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "ASIAML01": {
        "brand": "AS I AM",
        "product_name": "STRENGHTENING SHAMPOO 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and As I Am Long & Luxe Strengthening Shampoo.",
    },
    "ASIAML02": {
        "brand": "AS I AM",
        "product_name": "CONDITIONER 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and As I Am Long & Luxe Conditioner.",
    },
    "ASIAML06": {
        "brand": "AS I AM",
        "product_name": "GRO EDGES 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "AT2": {
        "brand": "ATONE WITH NATURE",
        "product_name": "SUPERGRO HAIR & SCALP CONDITIONER 5.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "COMB2407": {
        "brand": "STELLA COLLECTION",
        "product_name": "LONG METAL PIK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2409": {
        "brand": "STELLA COLLECTION",
        "product_name": "PLASTIC AFRO PIK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2410": {
        "brand": "STELLA COLLECTION",
        "product_name": "METAL AFRO PIK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2411": {
        "brand": "STELLA COLLECTION",
        "product_name": "LONG METAL AFRO FAN PIK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2412": {
        "brand": "STELLA COLLECTION",
        "product_name": "METAL AFRO FAN PIK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2413": {
        "brand": "STELLA COLLECTION",
        "product_name": "PIN TAIL COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2419": {
        "brand": "STELLA COLLECTION",
        "product_name": "BONE TAIL COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2420": {
        "brand": "STELLA COLLECTION",
        "product_name": "LONG BONE TAIL COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2421": {
        "brand": "STELLA COLLECTION",
        "product_name": "8.5INCH STYLING COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2422": {
        "brand": "STELLA COLLECTION",
        "product_name": "HANDLE STYLING COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB24260": {
        "brand": "STELLA COLLECTION",
        "product_name": "10PCS COMB SET",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2434": {
        "brand": "STELLA COLLECTION",
        "product_name": "STYLING GRADE COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2435": {
        "brand": "STELLA COLLECTION",
        "product_name": "7INCH STYLING GRADE COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2437": {
        "brand": "STELLA COLLECTION",
        "product_name": "6INCH FLUFF COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2441": {
        "brand": "STELLA COLLECTION",
        "product_name": "10INCH RAKE HANDLE COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2442": {
        "brand": "STELLA COLLECTION",
        "product_name": "JUMBO RAKE HANDLE COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2444": {
        "brand": "STELLA COLLECTION",
        "product_name": "7.25INCH BARBER COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2446": {
        "brand": "STELLA COLLECTION",
        "product_name": "5INCH POCKET STYLING COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2448": {
        "brand": "STELLA COLLECTION",
        "product_name": "9INCH WIDE COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2449": {
        "brand": "STELLA COLLECTION",
        "product_name": "FLAWLESS DETANGLING COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2450": {
        "brand": "STELLA COLLECTION",
        "product_name": "DRESSING COMB 9INCH",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2451": {
        "brand": "STELLA COLLECTION",
        "product_name": "TAPERED RAT TAIL STYLING COMB",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "COMB2452": {
        "brand": "STELLA COLLECTION",
        "product_name": "7INCH STYLING COMB TAPERED",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "EDGEBRUSH2": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC EDGE COMB & BRUSH (12pcs PER PACK) #CB001",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "EDGEBRUSH3": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC EDGE COMB & BRUSH (24pcs PER JAR) #CB001",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CANT01": {
        "brand": "CANTU",
        "product_name": "MOISTURISING TWIST & LOCK GEL 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT02": {
        "brand": "CANTU",
        "product_name": "THERMAL SHIELD HEAT PROTECTANT 5.10oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT03": {
        "brand": "CANTU",
        "product_name": "HYDRATING L-I-C MIST 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT04": {
        "brand": "CANTU",
        "product_name": "SMOOTHING L-I-C LOTION 10oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT07": {
        "brand": "CANTU",
        "product_name": "COMEBACK CURL NEXT DAY REVITALIZER 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT08": {
        "brand": "CANTU",
        "product_name": "WAVE WHIP CURLING MOUSSE 8.4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT11": {
        "brand": "CANTU",
        "product_name": "GROW STRONG TREATMENT 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT12": {
        "brand": "CANTU",
        "product_name": "MOISTURISING CURL ACTIVATOR CREAM 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT13": {
        "brand": "CANTU",
        "product_name": "COCONUT OIL SHINE & HOLD MIST 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT14": {
        "brand": "CANTU",
        "product_name": "HAIR DRESSING POMADE 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT16": {
        "brand": "CANTU",
        "product_name": "MOISTURISING CREAM SHAMPOO 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT17": {
        "brand": "CANTU",
        "product_name": "MOISTURISING RINSE OUT CONDITIONER 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT18": {
        "brand": "CANTU",
        "product_name": "TEA TREE & JOJOBA HAIR & SCALP OIL 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT19": {
        "brand": "CANTU",
        "product_name": "DEEP TREATMENT MASQUE 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT20": {
        "brand": "CANTU",
        "product_name": "LEAVE IN CONDITIONING REPAIR CREAM 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT21": {
        "brand": "CANTU",
        "product_name": "ARGAN OIL LEAVE IN REPAIR CREAM 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT22": {
        "brand": "CANTU",
        "product_name": "DAILY OIL MOISTURISER 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANT23": {
        "brand": "CANTU",
        "product_name": "OIL SHEEN SPRAY 10oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA01": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING SHAMPOO 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA02": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING CONDITIONER 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA03": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING REPAIR LEAVE-IN 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA04": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING CURLING CREAM 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA05": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING CURL ACTIVATOR 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA07": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING MOUSSE 8.4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA08": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING REFRESHER SPRAY 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTA09": {
        "brand": "CANTU",
        "product_name": "AVOCADO HYDRATING HAIR MILK 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTB01": {
        "brand": "CANTU",
        "product_name": "BIOTIN INFUSED HAIR & SCALP OIL 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTB02": {
        "brand": "CANTU",
        "product_name": "ROSEMARY & MINT + BIOTIN HAIR & SCALP OIL 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; bottle label distinguishes this oil from CANTB01.",
    },
    "CANTF04": {
        "brand": "CANTU",
        "product_name": "FLAXSEED SMOOTHING CONDITIONER 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTF05": {
        "brand": "CANTU",
        "product_name": "FLAXSEED SMOOTHING OIL 3.4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG01": {
        "brand": "CANTU",
        "product_name": "GUAVA & GINGER PRE-CLEANSING TREATMENT 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG02": {
        "brand": "CANTU",
        "product_name": "GUAVA & GINGER HAIR LOTION 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG03": {
        "brand": "CANTU",
        "product_name": "GUAVA & GINGER TREATMENT SERUM 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG04": {
        "brand": "CANTU",
        "product_name": "GUAVA & GINGER CONDITIONER 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG05": {
        "brand": "CANTU",
        "product_name": "GUAVA & GINGER CREAM GEL 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTG06": {
        "brand": "CANTU",
        "product_name": "GUAVA SCALP RELIEF /ANTI DANDRUFF SHAMPOO 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK01": {
        "brand": "CANTU",
        "product_name": "KIDS CURLING CREAM 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK02": {
        "brand": "CANTU",
        "product_name": "KIDS STYLING CUSTARD 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK03": {
        "brand": "CANTU",
        "product_name": "KIDS DETANGLER 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK04": {
        "brand": "CANTU",
        "product_name": "KIDS CONDITIONER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK05": {
        "brand": "CANTU",
        "product_name": "KIDS SHAMPOO 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK06": {
        "brand": "CANTU",
        "product_name": "KIDS LEAVE-IN CONDITIONER 10oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK08": {
        "brand": "CANTU",
        "product_name": "KIDS CURL REFRESHER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK09": {
        "brand": "CANTU",
        "product_name": "KIDS DRY SHAMPOO FOAM 5.8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK10": {
        "brand": "CANTU",
        "product_name": "KIDS CARE STYLING GEL 2.25oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTK11": {
        "brand": "CANTU",
        "product_name": "KIDS HAIR AND SCALP OIL 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTM01": {
        "brand": "CANTU",
        "product_name": "MENS 2 in 1 HAIR & BODYWASH 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTM02": {
        "brand": "CANTU",
        "product_name": "MENS SB LEAVE IN/RINSE OUT COND 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTM03": {
        "brand": "CANTU",
        "product_name": "MENS HAIR & BEARD OIL 3.4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTM04": {
        "brand": "CANTU",
        "product_name": "MENS STYLING POMADE 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN03": {
        "brand": "CANTU",
        "product_name": "LEAVE IN COND CRM 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN04": {
        "brand": "CANTU",
        "product_name": "DEFINE & SHINE CUSTARD 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN05": {
        "brand": "CANTU",
        "product_name": "COCONUT CURLING CREAM 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN05A": {
        "brand": "CANTU",
        "product_name": "COCONUT CURLING CREAM 25oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN06": {
        "brand": "CANTU",
        "product_name": "SULFATE-FREE CLEANSING CREAM SHAMPOO 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN06A": {
        "brand": "CANTU",
        "product_name": "SULFATE-FREE CLEANSING CREAM SHAMPOO 25oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN07": {
        "brand": "CANTU",
        "product_name": "SULFATE-FREE HYDRATING CREAM CONDITIONER 13.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN07A": {
        "brand": "CANTU",
        "product_name": "SULFATE-FREE HYDRATING CREAM CONDITIONER 25oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN08": {
        "brand": "CANTU",
        "product_name": "CONDITIONING CREAMY HAIR LOTION 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN09": {
        "brand": "CANTU",
        "product_name": "COMPLETE CONDITIONING CO-WASH 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTN10": {
        "brand": "CANTU",
        "product_name": "COIL CALM DETANGLER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP01": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE SCALP OIL DROPS 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP02": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE HAIR BATH 10oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP03": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE HAIR FRESHENER 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP04": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE BRAIDING & TWISTING GEL 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP05": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE SET & REFRESH FOAM 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CANTP06": {
        "brand": "CANTU",
        "product_name": "PROTECTIVE CONDITIONING DETANGLER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page logo.",
    },
    "CAP1515": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WAVE CAP BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP1515A": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WAVE CAP ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2016": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC MESH WRAP BAND BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2017": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC EXTRA-FIRM MESH WRAP BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2018": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE-FOAM MESH WRAP BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2028": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC WEAVING NETS BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2061": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SATIN BONNET BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2061AST": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SATIN BONNET ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2078": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SATIN BONNET ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; pack header and page title match the product name.",
    },
    "CAP2085": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC RESPONSE TIE IT SATIN WRAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2092": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC HEAT-LOCK CONDITIONING CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2122": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC BREATHABLE SATIN SLEEP CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2122A": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC BREATHABLE SATIN SLEEP CAP ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2147": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC TURBAN HAT BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2147A": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC TURBAN HAT ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2160": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC WATER-PROOF SHOWER CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2191": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC BREATHABLE SATIN SLEEP CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2207": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC CONDITIONING HEAT-PROCESS CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2214": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC WATER-PROOF SHOWER CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2225": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WIG CAP BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2225BLO": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WIG CAP BLONDE (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2225BLO.",
    },
    "CAP2225BRO": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WIG CAP BROWN (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2225BRO.",
    },
    "CAP2225J": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WIG CAP JUMBO (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2225JBLA.",
    },
    "CAP2231": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC INVISIBLE FRENCH-MESH HAIR NET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2232": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC INVISIBLE HEAVY-WEIGHT WAVE NET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2238": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SATIN SLEEP CAP JUMBO (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2240": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE WEAVING NET BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2240STR": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STRETCHABLE WEAVING NET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2240STR.",
    },
    "CAP2242": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPER JUMBO WAVE CAP UNISEX BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2251": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SPANDEX DOME CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2260": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC BREATHABLE SPANDEX DURAG (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2266": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC CLOSE-TOP WEAVING CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP2268": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC RESPONSE INTERLOCKING NET BLACK (1X12pcs PACK)",
        "confidence": "B",
        "confidence_reason": "Page title indicates Interlocking Net, but the pack also contains mixed shower-cap wording; product family is clear, exact merchandising label should be reviewed if this line becomes important.",
    },
    "CAP4702": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STRETCHABLE SPANDEX CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4703": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC BREATHABLE MESH DOME CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4769": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ADJUSTABLE TIE-DOWN DURAG (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4769AST": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ADJUSTIBLE TIE-DOWN DURAG ASSORTED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4800": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE TIE-DOWN DURAG (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4801": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4801G": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG GOLD (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4801GOL.",
    },
    "CAP4801N": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG NAVY (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4801O": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG ORANGE (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4801ORA.",
    },
    "CAP4801R": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG RED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4801AST.",
    },
    "CAP4801S": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DELUXE SILKY SATIN-DURAG SILVER (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4801SIL.",
    },
    "CAP4802G": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DEEP WAVE SILKY METALLIC DURAG GOLD (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4802S": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DEEP WAVE SILKY METALLIC DURAG SILVER (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4803": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG BLACK (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4803G": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG GRAY (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4803GRA.",
    },
    "CAP4803N": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DEEP WAVE SILKY METALLIC DURAG NAVY (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page title and product family are clear.",
    },
    "CAP4803O": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG ORANGE (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4803P": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DEEP WAVE SILKY METALLIC DURAG PINK (1X12pcs PACK)",
        "confidence": "B",
        "confidence_reason": "Page title shows Deep Wave Silky Metallic Durag Pink, but the code sequence overlaps the Superstar line; product family is clear from the page image and should stay reviewable.",
    },
    "CAP4803PU": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG PURPLE (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4803R": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG RED (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP4803W": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC SUPERSTAR VELVET DEEP DURAG WHITE (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; printed pack code is 4803WHI.",
    },
    "CAPARG3000": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL STOCKING WIG CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3001": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL WIDE BAND SLEEPCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3002": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL EXTRA-LARGE SLEEPCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3003": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL WIDE-BAND BONNET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3004": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL EXTRA-LARGE BONNET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3005": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL DOUBLE-LAYERED MESH WRAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3006": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL FOAM-WRAP MESH WRAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3012": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL FLEXI-WINGS WEAVING CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3013": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL CLOSE-TOP WEAVING CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3014": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL DELUXE WEAVING NET (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3015": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL SUPER-JUMBO DAY&NIGHT CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPARG3016": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC ORGANIC ARGAN OIL SPANDEX DOME CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY001": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF DOME STYLE MESH WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY002": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF CENTER PARTING U-PART WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY003": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF SIDE PARTING U-PART WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY004": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF CENTER-PARTING U-PART WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY005": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF SIDE-PARTING U-PART WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY006": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF SPANDEX DOME CAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAPDIY008": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC DO IT YOURSELF CROCHET WIGCAP (1X12pcs PACK)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CAP01401": {
        "brand": "MAGIC COLLECTION",
        "product_name": "MAGIC STOCKING WIG CAP (200pcs BOX)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
    },
    "CC76": {
        "brand": "CRAZY COLOR",
        "product_name": "ANARCHY UV 100ML",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page header and on the bottle.",
    },
    "CC77": {
        "brand": "CRAZY COLOR",
        "product_name": "CAUTION UV 100ML",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page header and on the bottle.",
    },
    "CC78": {
        "brand": "CRAZY COLOR",
        "product_name": "REBEL UV 100ML",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page header and on the bottle.",
    },
    "CC79": {
        "brand": "CRAZY COLOR",
        "product_name": "TOXIC UV 100ML",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; brand appears in the page header and on the bottle.",
    },
    "CONA10": {
        "brand": "CREME OF NATURE",
        "product_name": "Argan Oil Moisture & Shine Shampoo 20oz",
        "confidence": "A",
        "confidence_reason": "Hybrid OCR/PDF pass: OCR and the page image confirm the 20oz Moisture & Shine Shampoo bottle.",
    },
    "CONA11": {
        "brand": "CREME OF NATURE",
        "product_name": "Argan Oil Strength & Shine Leave In Conditioner 8.45oz",
        "confidence": "A",
        "confidence_reason": "Hybrid OCR/PDF pass: OCR and the page image separate this caption cleanly from the neighboring 20oz shampoo row.",
    },
    "CONA30": {
        "brand": "CREME OF NATURE",
        "product_name": "Argan Oil Moisture & Shine Curl Activator Crème 12oz",
        "confidence": "A",
        "confidence_reason": "Hybrid OCR/PDF pass: OCR and the page image confirm the missing 12oz size on the caption.",
    },
    "CONG05": {
        "brand": "CREME OF NATURE",
        "product_name": "Argan Oil Exotic Shine Color Medium Warm Brown 7.3",
        "confidence": "A",
        "confidence_reason": "Hybrid OCR/PDF pass: OCR and the page image confirm the 7.3 shade suffix on the carton caption.",
    },
    "CONG06": {
        "brand": "CREME OF NATURE",
        "product_name": "Argan Oil Exotic Shine Color Intensive Red 7.6",
        "confidence": "A",
        "confidence_reason": "Hybrid OCR/PDF pass: OCR and the page image separate the 7.6 shade from the neighboring 7.3 row.",
    },
    "BEADS03": {
        "brand": "HAIR BEADS",
        "product_name": "HAIR BEADS CRYSTAL 200pcs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BEADS04": {
        "brand": "HAIR BEADS",
        "product_name": "HAIR BEADS BLACK, WHITE & CRYSTAL 200pcs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BLADE01": {
        "brand": "WILKINSON SWORD",
        "product_name": "WILKINSON SWORD BLADES **100PCS BLADES PER PACK**",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BLADE02": {
        "brand": "TREET",
        "product_name": "TREET DOUBLE EDGED BLADE 100 PILLAR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BLADE03": {
        "brand": "DERBY",
        "product_name": "DERBY GREEN SINGLE EDGE BLADE",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BLADE04": {
        "brand": "LASER",
        "product_name": "LASER SUPER STAINLESS DOUBLE EDGE BLADES (PILLAR PK 100'S)",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BIM01": {
        "brand": "BIGEN",
        "product_name": "101 NATURAL BLACK BEARD COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Bigen Men's Speedy references.",
    },
    "BIM02": {
        "brand": "BIGEN",
        "product_name": "101 NATURAL BLACK HAIR COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Bigen Men's Speedy references.",
    },
    "BIM03": {
        "brand": "BIGEN",
        "product_name": "102 BROWN BLACK HAIR COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Bigen Men's Speedy references.",
    },
    "BIM04": {
        "brand": "BIGEN",
        "product_name": "103 DARK BROWN HAIR COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Bigen Men's Speedy references.",
    },
    "BIM07": {
        "brand": "BIGEN",
        "product_name": "102 BROWN BLACK BEARD COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image and Bigen Men's Speedy references.",
    },
    "BM01": {
        "brand": "BLUE MAGIC",
        "product_name": "Conditioner Hair Dress 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM02": {
        "brand": "BLUE MAGIC",
        "product_name": "Bergamot Hair & Scalp Conditioner 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM05": {
        "brand": "BLUE MAGIC",
        "product_name": "Coconut Oil Hair Conditioner 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM06": {
        "brand": "BLUE MAGIC",
        "product_name": "Super Sure Gro 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM07": {
        "brand": "BLUE MAGIC",
        "product_name": "Hair Food 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM08": {
        "brand": "BLUE MAGIC",
        "product_name": "Castor Oil 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM09": {
        "brand": "BLUE MAGIC",
        "product_name": "Indian Hemp 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM10": {
        "brand": "BLUE MAGIC",
        "product_name": "Carrot Oil 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM11": {
        "brand": "BLUE MAGIC",
        "product_name": "Olive Oil 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM12": {
        "brand": "BLUE MAGIC",
        "product_name": "Tea Tree Oil 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BM15": {
        "brand": "BLUE MAGIC",
        "product_name": "Shea Butter 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BP01": {
        "brand": "BUMP PATROL",
        "product_name": "AFTERSHAVE TREATMENT 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BP02": {
        "brand": "BUMP PATROL",
        "product_name": "A/SHAVE SENSITIVE 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BP06": {
        "brand": "BUMP PATROL",
        "product_name": "GROOMING POWDERS 14oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "BS01": {
        "brand": "HIGH TIME",
        "product_name": "Bump Stopper-2 Double Strength Treatment 0.5oz",
        "confidence": "A",
        "confidence_reason": "Page 81 is image-heavy; code and size match the PDF page image. Product naming confirmed against High Time Bump Stopper-2 retailer listings.",
    },
    "BOBBYPIN10": {
        "brand": "BOBBY PINS",
        "product_name": "120pcs REGULAR BOBBY PINS",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "CR04": {
        "brand": "CAMILLE ROSE",
        "product_name": "JANSYN'S MOISTURE MAX CONDITIONER 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "CR20": {
        "brand": "CAMILLE ROSE",
        "product_name": "COCONUT WATER CURL CLEANSE SULFATE-FREE HYDRATING SHAMPOO 8oz",
        "confidence": "A",
        "confidence_reason": "High-resolution PDF page image confirmed the Coconut Water line; official Camille Rose search terms support the Curl Cleanse naming.",
    },
    "CR26": {
        "brand": "CAMILLE ROSE",
        "product_name": "REJUVA DROPS GROW BACK 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "CR27": {
        "brand": "CAMILLE ROSE",
        "product_name": "ROSEMARY OIL STRENGTHENING HAIR + SCALP DROPS 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "CR43": {
        "brand": "CAMILLE ROSE",
        "product_name": "ROSEMARY WATER STRENGTHENING MIST 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "CR47": {
        "brand": "CAMILLE ROSE",
        "product_name": "ROSE OIL STRENGTHENING DROPS 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB01": {
        "brand": "DABUR",
        "product_name": "AMLA HAIR OIL 100ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB02": {
        "brand": "DABUR",
        "product_name": "AMLA HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB03": {
        "brand": "DABUR",
        "product_name": "AMLA HAIR OIL 300ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB04": {
        "brand": "DABUR",
        "product_name": "AMLA GOLD HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB05": {
        "brand": "DABUR",
        "product_name": "AMLA GOLD HAIR OIL 300ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB09": {
        "brand": "DABUR",
        "product_name": "AMLA ANTI-DANDRUFF HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB10": {
        "brand": "DABUR",
        "product_name": "AMLA COOLING HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB11": {
        "brand": "DABUR",
        "product_name": "AMLA JASMINE HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB12": {
        "brand": "DABUR",
        "product_name": "PREMIUM ROSE WATER 250ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAB23": {
        "brand": "DABUR",
        "product_name": "AMLA MIRACLE OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABV01": {
        "brand": "VATIKA",
        "product_name": "COCONUT HAIR OIL 150ml ORIGINAL",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABV06": {
        "brand": "VATIKA",
        "product_name": "MOROCCAN ARGAN HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABV07": {
        "brand": "VATIKA",
        "product_name": "SHIKAKAI HAIR OIL 200ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABVN06": {
        "brand": "VATIKA",
        "product_name": "NATURALS RITUAL OIL COCONUT ARGAN 100ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABVN07": {
        "brand": "VATIKA",
        "product_name": "NATURALS RITUAL OIL HIBISCUS LAVENDER 100ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DABVN08": {
        "brand": "VATIKA",
        "product_name": "NATURALS RITUAL OIL HIBISCUS OLIVE 100ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAX16": {
        "brand": "DAX",
        "product_name": "BERGAMOT POMADE 3.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAX17": {
        "brand": "DAX",
        "product_name": "BERGAMOT POMADE 7.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DAX31": {
        "brand": "DAX",
        "product_name": "SUPERGRO 7oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image and DAX official product naming.",
    },
    "DET02": {
        "brand": "DETTOL",
        "product_name": "ANTISEPTIC LIQUID 500ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DET03": {
        "brand": "DETTOL",
        "product_name": "ANTISEPTIC LIQUID 750ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "DET04": {
        "brand": "DETTOL",
        "product_name": "SOAP UK 100g",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "GT01": {
        "brand": "GENTLE TREATMENT",
        "product_name": "REGULAR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "GT02": {
        "brand": "GENTLE TREATMENT",
        "product_name": "SUPER",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "GT05": {
        "brand": "GENTLE TREATMENT",
        "product_name": "GRAY KIT",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "IRISH04": {
        "brand": "IRISH SPRING",
        "product_name": "SOAP ALOE MIST",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "JMLB03": {
        "brand": "JAMAICAN MANGO & LIME",
        "product_name": "JAMAICAN BLACK CASTOR OIL XTRA DARK 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "JMLB04": {
        "brand": "JAMAICAN MANGO & LIME",
        "product_name": "JAMAICAN BLACK CASTOR OIL XTRA DARK 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "JMLB08": {
        "brand": "JAMAICAN MANGO & LIME",
        "product_name": "JAMAICAN BLACK CASTOR OIL COCONUT 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "JMLB09": {
        "brand": "JAMAICAN MANGO & LIME",
        "product_name": "JAMAICAN BLACK CASTOR OIL COCONUT 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "JMLB13": {
        "brand": "JAMAICAN MANGO & LIME",
        "product_name": "JAMAICAN BLACK CASTOR OIL LEMONGRASS 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "KC27": {
        "brand": "KERACARE",
        "product_name": "SILKEN SEAL LIQUID SHEEN 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "LP30": {
        "brand": "LUSTER'S PINK",
        "product_name": "CASTOR OIL 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "CONDITIONER1": {
        "brand": "LUSTER'S PINK KIDS",
        "product_name": "AWESOME NOURISHING CONDITIONER 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "MOPH02": {
        "brand": "MIELLE ORGANICS",
        "product_name": "POMEGRANATE & HONEY CURL SMOOTHIE 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "NYX1": {
        "brand": "NYXON",
        "product_name": "FREEZE GEL 250ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "NYX": {
        "brand": "NYXON",
        "product_name": "FREEZE GEL 100ml",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "NYX3": {
        "brand": "NYXON",
        "product_name": "FREEZE GEL 1 LITRE",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "NYX5": {
        "brand": "NYXON",
        "product_name": "MOUSSE BLUE/BLACK",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "PAL21": {
        "brand": "PALMER'S",
        "product_name": "COCOA BUTTER LIP BALM 4g",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SJ04B": {
        "brand": "SHINE N JAM",
        "product_name": "SHINE N JAM GEL SUPREME HOLD 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SJ04C": {
        "brand": "SHINE N JAM",
        "product_name": "SHINE N JAM GEL SUPREME HOLD 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SMCAN02": {
        "brand": "SHEA MOISTURE",
        "product_name": "CANNABIS & GINSENG CONDITIONER 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SMCAN03": {
        "brand": "SHEA MOISTURE",
        "product_name": "CANNABIS & GINSENG LEAVE-IN 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SMMH03": {
        "brand": "SHEA MOISTURE",
        "product_name": "MANUKA HONEY & MAFURA OIL INTENSIVE HYDRATION CONDITIONER 13oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SSF08": {
        "brand": "STA-SOF-FRO",
        "product_name": "BLACK 1X18",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SSF11": {
        "brand": "STA-SOF-FRO",
        "product_name": "AUBURN 1X18",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SUNNY09": {
        "brand": "SUNNY ISLE",
        "product_name": "BLACK SEED OIL 4oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "SUNNY24": {
        "brand": "SUNNY ISLE",
        "product_name": "EDGE CONTROL 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "VAS09": {
        "brand": "VASELINE",
        "product_name": "PURE PETROLEUM JELLY ORIGINAL 50G",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "VAS10": {
        "brand": "VASELINE",
        "product_name": "PURE PETROLEUM JELLY ORIGINAL 100G",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
    "VAS11": {
        "brand": "VASELINE",
        "product_name": "PURE PETROLEUM JELLY ORIGINAL 250G",
        "confidence": "A",
        "confidence_reason": "Confirmed against the high-resolution PDF page image.",
    },
}

MANUAL_PRODUCT_OVERRIDES.update({
    "DR22": {
        "brand": "DAGGETT & RAMSDELL",
        "product_name": "Moisturising Lightening Soap 3.5oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; this row is a mixed-brand item on a Dr. Miracle's page.",
    },
    "ECO01": {
        "brand": "ECO STYLE",
        "product_name": "Argan Oil Styling Gel 5lbs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO02": {
        "brand": "ECO STYLE",
        "product_name": "Argan Oil Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO04": {
        "brand": "ECO STYLE",
        "product_name": "Argan Oil Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO05": {
        "brand": "ECO STYLE",
        "product_name": "Argan Oil Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO06": {
        "brand": "ECO STYLE",
        "product_name": "Avocado & Black Castor Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; Eco Style branding is visible and the caption carries the flavour and size.",
    },
    "ECO07": {
        "brand": "ECO STYLE",
        "product_name": "Avocado & Black Castor Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; Eco Style branding is visible and the caption carries the flavour and size.",
    },
    "ECO08": {
        "brand": "ECO STYLE",
        "product_name": "Black Castor Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; Eco Style branding is visible and the caption carries the flavour and size.",
    },
    "ECO09": {
        "brand": "ECO STYLE",
        "product_name": "Black Castor & Flaxseed Oil Styling Gel 5lbs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; the product pack clearly shows the Black Castor & Flaxseed Oil line.",
    },
    "ECO10": {
        "brand": "ECO STYLE",
        "product_name": "Black Castor & Flaxseed Oil Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; the product pack clearly shows the Black Castor & Flaxseed Oil line.",
    },
    "ECO11": {
        "brand": "ECO STYLE",
        "product_name": "Black Castor & Flaxseed Oil Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; the product pack clearly shows the Black Castor & Flaxseed Oil line.",
    },
    "ECO12": {
        "brand": "ECO STYLE",
        "product_name": "Black Castor & Flaxseed Oil Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; the product pack clearly shows the Black Castor & Flaxseed Oil line.",
    },
    "ECO16": {
        "brand": "ECO STYLE",
        "product_name": "Coconut Oil Styling Gel 5lbs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO17": {
        "brand": "ECO STYLE",
        "product_name": "Coconut Oil Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO18": {
        "brand": "ECO STYLE",
        "product_name": "Coconut Oil Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO19": {
        "brand": "ECO STYLE",
        "product_name": "Coconut Oil Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO33": {
        "brand": "ECO STYLE",
        "product_name": "Krystal Styling Gel 5lbs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO34": {
        "brand": "ECO STYLE",
        "product_name": "Krystal Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO35": {
        "brand": "ECO STYLE",
        "product_name": "Krystal Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO36": {
        "brand": "ECO STYLE",
        "product_name": "Krystal Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO37": {
        "brand": "ECO STYLE",
        "product_name": "Olive Oil Styling Gel 5lbs",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO38": {
        "brand": "ECO STYLE",
        "product_name": "Olive Oil Styling Gel 32oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO40": {
        "brand": "ECO STYLE",
        "product_name": "Olive Oil Styling Gel 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "ECO41": {
        "brand": "ECO STYLE",
        "product_name": "Olive Oil Styling Gel 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding and product caption both show Eco Style styling gel.",
    },
    "MOHG03": {
        "brand": "MIELLE ORGANICS",
        "product_name": "Hawaiian Ginger Moisturising Overnight Conditioner 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; page branding is Mielle Organics and the caption clearly reads Overnight Conditioner 12oz.",
    },
    "SUNNY03": {
        "brand": "SUNNY ISLE",
        "product_name": "Original Jamaican Black Castor Oil 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY05": {
        "brand": "SUNNY ISLE",
        "product_name": "Extra Dark Jamaican Black Castor Oil 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY49": {
        "brand": "SUNNY ISLE",
        "product_name": "JBCO Rosemary Moisturising Shampoo 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50": {
        "brand": "SUNNY ISLE",
        "product_name": "JBCO Rosemary Moisturising Conditioner 8oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50A": {
        "brand": "SUNNY ISLE",
        "product_name": "Rosemary Mint Hair Roots Oil 3oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50B": {
        "brand": "SUNNY ISLE",
        "product_name": "Rosemary Mint Pure Butter 2oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50C": {
        "brand": "SUNNY ISLE",
        "product_name": "Rosemary Mint Shampoo 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50D": {
        "brand": "SUNNY ISLE",
        "product_name": "Rosemary Mint Conditioner 12oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "SUNNY50E": {
        "brand": "SUNNY ISLE",
        "product_name": "Rosemary Mint Masque 16oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "WAHLCT06": {
        "brand": "WAHL",
        "product_name": "WAHL CURLING TONG PRO SHINE BLACK 13MM ZY114",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "WAHLCT07": {
        "brand": "WAHL",
        "product_name": "WAHL CURLING TONG PRO SHINE BLACK 16MM ZY115",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
    "WAHLCT08": {
        "brand": "WAHL",
        "product_name": "WAHL CURLING TONG PRO SHINE BLACK 19MM ZY081",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
})

MANUAL_PRODUCT_OVERRIDES.update({
    "ICF20": {
        "brand": "FANTASIA 1C",
        "product_name": "ARGAN OIL HAIR POLISHER SMOOTHING SERUM 6oz",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image; the Fantasia IC pack and caption both show Argan Oil Hair Polisher Smoothing Serum 6oz.",
    },
    "SSF15": {
        "brand": "STA-SOF-FRO",
        "product_name": "MENS JET BLACK HAIR COLOUR",
        "confidence": "A",
        "confidence_reason": "Confirmed against the PDF page image.",
    },
})

MANUAL_PAGE_PRODUCTS = {
    67: [
        {
            "product_code": "P67-BANDANA-BLK",
            "brand": "BANDANA",
            "product_name": "BANDANNA BLK (1x12PCS)",
            "confidence": "B",
            "confidence_reason": "Product is clear from the PDF page image, but no printed product code is shown on the page.",
        },
        {
            "product_code": "P67-BANDANA-NAVY",
            "brand": "BANDANA",
            "product_name": "BANDANNA NAVY (1x12PCS)",
            "confidence": "B",
            "confidence_reason": "Product is clear from the PDF page image, but no printed product code is shown on the page.",
        },
        {
            "product_code": "P67-BANDANA-RED",
            "brand": "BANDANA",
            "product_name": "BANDANNA RED (1x12PCS)",
            "confidence": "B",
            "confidence_reason": "Product is clear from the PDF page image, but no printed product code is shown on the page.",
        },
        {
            "product_code": "P67-BANDANA-WHITE",
            "brand": "BANDANA",
            "product_name": "BANDANNA WHITE (1x12PCS)",
            "confidence": "B",
            "confidence_reason": "Product is clear from the PDF page image, but no printed product code is shown on the page.",
        },
        {
            "product_code": "P67-BATH-NET-LARGE",
            "brand": "BATH",
            "product_name": "BATH NET (COLOURED) LARGE",
            "confidence": "B",
            "confidence_reason": "Product is clear from the PDF page image, but no printed product code is shown on the page.",
        },
    ],
    75: [
        {
            "product_code": "BM06",
            "brand": "BLUE MAGIC",
            "product_name": "Super Sure Gro 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
    ],
    76: [
        {
            "product_code": "BM09",
            "brand": "BLUE MAGIC",
            "product_name": "Indian Hemp 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "BM10",
            "brand": "BLUE MAGIC",
            "product_name": "Carrot Oil 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
    ],
}

# Entire product list for a page when column layout breaks generic parsing (confirmed on PDF image).
# Brand = manufacturer / trade name only. Category lines on the PDF (e.g. "BRAIDING CORD") belong in
# product_name, not brand—use "Unknown" when no maker is visible in text or after a quick web check.
_PAGE_82_CONFIDENCE_REASON = (
    "Four-column layout merged in extraction; codes and colours confirmed on the PDF page image. "
    "No manufacturer brand in extractable text; quick web lookup did not identify one—brand Unknown."
)

MANUAL_FULL_PAGE_PRODUCTS = {
    82: [
        {
            "product_code": "BRAIDING",
            "brand": "Unknown",
            "product_name": "Braiding cord, brown assorted",
            "confidence": "C",
            "confidence_reason": _PAGE_82_CONFIDENCE_REASON,
        },
        {
            "product_code": "BRAIDING2",
            "brand": "Unknown",
            "product_name": "Braiding cord, gold",
            "confidence": "C",
            "confidence_reason": _PAGE_82_CONFIDENCE_REASON,
        },
        {
            "product_code": "BRAIDING1",
            "brand": "Unknown",
            "product_name": "Braiding cord, silver",
            "confidence": "C",
            "confidence_reason": _PAGE_82_CONFIDENCE_REASON,
        },
        {
            "product_code": "BRAIDING3",
            "brand": "Unknown",
            "product_name": "Braiding cord, red assorted",
            "confidence": "C",
            "confidence_reason": _PAGE_82_CONFIDENCE_REASON,
        },
    ],
    89: [
        {
            "product_code": "BRUSH7744H",
            "brand": "MINI CLUB",
            "product_name": "MINI CLUB BRUSH HARD (3DZ)",
            "confidence": "B",
            "confidence_reason": "Code and product family are clear on the PDF page image; HARD is visible on the tub label, but the product name is reconstructed from the page image rather than a full text line.",
        },
        {
            "product_code": "BRUSH7744S",
            "brand": "MINI CLUB",
            "product_name": "MINI CLUB BRUSH SOFT (3DZ)",
            "confidence": "B",
            "confidence_reason": "Code and product family are clear on the PDF page image; SOFT is visible on the tub label, but the product name is reconstructed from the page image rather than a full text line.",
        },
    ],
    99: [
        {
            "product_code": "EDGEBRUSH",
            "brand": "MAGIC COLLECTION",
            "product_name": "MAGIC 3 in 1 EDGE COMB & BRUSH (12pcs PER PACK)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
        },
        {
            "product_code": "EDGEBRUSH1",
            "brand": "MAGIC COLLECTION",
            "product_name": "MAGIC 3 in 1 EDGE COMB & BRUSH (24pcs PER JAR)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
        },
    ],
    125: [
        {
            "product_code": "CAP2214",
            "brand": "MAGIC COLLECTION",
            "product_name": "MAGIC WATER-PROOF SHOWER CAP (1X12pcs PACK)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; brand appears on the product pack.",
        },
        {
            "product_code": "CAP2225",
            "brand": "MAGIC COLLECTION",
            "product_name": "MAGIC STOCKING WIG CAP BLACK (1X12pcs PACK)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2225BLA.",
        },
        {
            "product_code": "CAP2225BLO",
            "brand": "MAGIC COLLECTION",
            "product_name": "MAGIC STOCKING WIG CAP BLONDE (1X12pcs PACK)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; printed pack code is 2225BLO.",
        },
    ],
    146: [
        {
            "product_code": "CIN01",
            "brand": "CINTHOL",
            "product_name": "Cologne Soap 100g",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the visible CINTHOL brand on pack artwork, while the PDF text confirmed the product codes and names.",
        },
        {
            "product_code": "CIN02",
            "brand": "CINTHOL",
            "product_name": "Sport Soap 100g",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the visible CINTHOL brand on pack artwork, while the PDF text confirmed the product codes and names.",
        },
        {
            "product_code": "CIN03",
            "brand": "CINTHOL",
            "product_name": "Lime Fresh Soap 100g",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the visible CINTHOL brand on pack artwork, while the PDF text confirmed the product codes and names.",
        },
        {
            "product_code": "CIN04",
            "brand": "CINTHOL",
            "product_name": "Sport Soap 100g",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the visible CINTHOL brand on pack artwork, while the PDF text confirmed the product codes and names.",
        },
    ],
    147: [
        {
            "product_code": "CLE",
            "brand": "CLERE",
            "product_name": "Pure Glycerine 100ml",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR surfaced the missing CLE item and the visible CLERE brand, while the page image confirmed the product caption.",
        },
        {
            "product_code": "CLE1",
            "brand": "CLERE",
            "product_name": "Pure Glycerine 200ml",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR surfaced the visible CLERE brand, while the PDF text confirmed the code and product caption.",
        },
        {
            "product_code": "CLE2",
            "brand": "CLERE",
            "product_name": "Skin Oil 100ml",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR surfaced the visible CLERE brand, while the PDF text confirmed the code and product caption.",
        },
        {
            "product_code": "CLE3",
            "brand": "CLERE",
            "product_name": "Cocoa Butter Oil Gel 200mL",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR surfaced the visible CLERE brand, while the PDF text confirmed the code and product caption.",
        },
    ],
    148: [
        {
            "product_code": "CON01",
            "brand": "CREME OF NATURE",
            "product_name": "Mango & Shea Butter Ultra Moisturising Shampoo 12oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE master brand from the page header, while the PDF text confirmed the codes and product captions.",
        },
        {
            "product_code": "CON02",
            "brand": "CREME OF NATURE",
            "product_name": "Mango & Shea Butter Ultra Moisturising Conditioner 12oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE master brand from the page header, while the PDF text confirmed the codes and product captions.",
        },
        {
            "product_code": "CON03",
            "brand": "CREME OF NATURE",
            "product_name": "Mango & Shea Butter Ultra Moisturising Leave-In Conditioner 12oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE master brand from the page header, while the PDF text confirmed the codes and product captions.",
        },
    ],
    149: [
        {
            "product_code": "CON07",
            "brand": "CREME OF NATURE",
            "product_name": "Professional Ultra Moisturizing Shampoo 32oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE brand from the page header, while the PDF text confirmed the product codes and captions.",
        },
        {
            "product_code": "CON08",
            "brand": "CREME OF NATURE",
            "product_name": "Professional Detangling & Conditioning Shampoo 32oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE brand from the page header, while the PDF text confirmed the product codes and captions.",
        },
    ],
    150: [
        {
            "product_code": "CONA01",
            "brand": "CREME OF NATURE",
            "product_name": "Argan Oil Style & Shine Foaming Mousse 7oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE brand from the page header, while the PDF text confirmed the product codes and captions.",
        },
        {
            "product_code": "CONA02",
            "brand": "CREME OF NATURE",
            "product_name": "Argan Oil Intensive Conditioning Treatment Sachet 1.75oz (1x36pcs)",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE brand from the page header, while the PDF text confirmed the product codes and captions.",
        },
        {
            "product_code": "CONA03",
            "brand": "CREME OF NATURE",
            "product_name": "Argan Oil Anti-Humidity Gloss & Shine Mist 4oz",
            "confidence": "A",
            "confidence_reason": "Hybrid OCR/PDF pass: OCR identified the CREME OF NATURE brand from the page header, while the PDF text confirmed the product codes and captions.",
        },
    ],
    171: [
        {
            "product_code": "CONL01",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C10 JET BLACK",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL02",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C11 NATURAL BLACK",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL03",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C20 LT GOLDEN BROWN",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL04",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C21 RICH BROWN",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL05",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C30 RED BURGUNDY",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL06",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C31 VIVID RED",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL07",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C32 SPICED RED",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL08",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C41 HONEY BLONDE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL09",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C42 LIGHT GOLDEN BLONDE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
        {
            "product_code": "CONL10",
            "brand": "CREME OF NATURE",
            "product_name": "MOISTURE-RICH HAIR COLOR - C43 LIGHTEST BLONDE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Creme of Nature shade code and shade name on the pack front.",
        },
    ],
    173: [
        {
            "product_code": "CORN",
            "brand": "Unknown",
            "product_name": "BCP-8002 THIN (1X12)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
        {
            "product_code": "CORN1",
            "brand": "Unknown",
            "product_name": "BCP-8002 THICK (1X12)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
        {
            "product_code": "CORN2",
            "brand": "Unknown",
            "product_name": "BCP-8001 STAINLESS STEEL (1X12)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
    ],
    185: [
        {
            "product_code": "CUT",
            "brand": "Unknown",
            "product_name": "TRT72J-CUTICLE TRIMMERS CHROME",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
        {
            "product_code": "CUT1",
            "brand": "Unknown",
            "product_name": "CHROME TRT1",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
        {
            "product_code": "TRIMMER",
            "brand": "Unknown",
            "product_name": "COMB RAZOR HAIR CUTTER 12320",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
        {
            "product_code": "TWEEZ6",
            "brand": "Unknown",
            "product_name": "TWEEZER PLAIN TW72J- (1X72PCS JAR)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the high-resolution PDF page image, but no manufacturer brand is visible, so brand is stored as Unknown.",
        },
    ],
    208: [
        {
            "product_code": "DAX01",
            "brand": "DAX",
            "product_name": "NEAT WAVES HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX02",
            "brand": "DAX",
            "product_name": "SUPER-NEAT HAIR CREME",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX03",
            "brand": "DAX",
            "product_name": "SHORT AND NEAT LIGHT HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX04",
            "brand": "DAX",
            "product_name": "WAVE AND GROOM HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX05",
            "brand": "DAX",
            "product_name": "GREEN AND GOLD HAIR WAX",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX06",
            "brand": "DAX",
            "product_name": "HAIR WAX WASHABLE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX07",
            "brand": "DAX",
            "product_name": "HAIR SHAPER HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX08",
            "brand": "DAX",
            "product_name": "HIGH & TIGHT EXTREME SHINE HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
        {
            "product_code": "DAX09",
            "brand": "DAX",
            "product_name": "HIGH & TIGHT AWESOME HOLD HAIR DRESS",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the DAX pack names; official DAX product naming was used where the page relied on pack art rather than captions.",
        },
    ],
    212: [
        {
            "product_code": "DES",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO SHAMPOO 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES1",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO DETANGLING CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES2",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO LEAVE IN CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES3",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO CURLING CUSTARD 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
    ],
    213: [
        {
            "product_code": "DES4",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO CURLING CREME 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES5",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "COCONUT & MONOI GELEE 12oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES6",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO MOUSSE 10oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
        {
            "product_code": "DES7",
            "brand": "DESIGN ESSENTIALS",
            "product_name": "ALMOND & AVOCADO HONEY & SHEA EDGE TAMER 3.7oz",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the products, and official Design Essentials search results were used to expand the abbreviated line naming.",
        },
    ],
    216: [
        {
            "product_code": "DG01",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK LOTION 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG02",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK SHAMPOO 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG03",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK CONDITIONER 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG04",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK INTENSE REPAIR 16oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG06",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK LEAVE-IN GRO STRENGTHENER 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG07",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK HAIR VITALIZER 4oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG08",
            "brand": "DOO GRO",
            "product_name": "MEGA THICK HAIR OIL 4.5oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG09",
            "brand": "DOO GRO",
            "product_name": "STIMULATING HAIR OIL 4.5oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG10",
            "brand": "DOO GRO",
            "product_name": "ANTI-ITCH HAIR OIL 4.5oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
    ],
    217: [
        {
            "product_code": "DG15",
            "brand": "DOO GRO",
            "product_name": "TRIPLE STRENGTH ANTI-BREAKAGE GROWTH LOTION 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG17",
            "brand": "DOO GRO",
            "product_name": "TRIPLE STRENGTH HAIR VITALIZER 4oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG23",
            "brand": "DOO GRO",
            "product_name": "LEAVE IN GROWTH TREATMENT 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG24",
            "brand": "DOO GRO",
            "product_name": "TINGLING GRO SHAMPOO 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG25",
            "brand": "DOO GRO",
            "product_name": "MEGA THERAPY ARGAN OIL TREATMENT 3.5oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG26",
            "brand": "DOO GRO",
            "product_name": "MEGA STYLE EDGE GEL 2.25oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG27",
            "brand": "DOO GRO",
            "product_name": "JBCO CO-WASH 10oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG28",
            "brand": "DOO GRO",
            "product_name": "JBCO CASTOR OIL 4oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
        {
            "product_code": "DG29",
            "brand": "DOO GRO",
            "product_name": "JBCO COCONUT OIL 4oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the high-resolution PDF page image; DOO GRO branding is visible on pack.",
        },
    ],
    218: [
        {
            "product_code": "DL02",
            "brand": "DARK & LOVELY",
            "product_name": "RELAXER KIT REGULAR",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
        {
            "product_code": "DL03",
            "brand": "DARK & LOVELY",
            "product_name": "RELAXER KIT SUPER",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
        {
            "product_code": "DL04",
            "brand": "DARK & LOVELY",
            "product_name": "3 IN 1 SHAMPOO 250ml",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
        {
            "product_code": "DL11",
            "brand": "DARK & LOVELY",
            "product_name": "OIL MOISTURISER SPRAY 250ml",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
        {
            "product_code": "DL12",
            "brand": "DARK & LOVELY",
            "product_name": "ULTRA CHOLESTEROL CREAM 250ml",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
        {
            "product_code": "DL13",
            "brand": "DARK & LOVELY",
            "product_name": "BRAID SOFTENER SPRAY 250ml",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely line and product captions.",
        },
    ],
    219: [
        {
            "product_code": "DLBB01",
            "brand": "DARK & LOVELY",
            "product_name": "BEAUTIFUL BEGINNINGS CHILD KIT - PINK",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Beautiful Beginnings line; official SoftSheen-Carson search terms support the corrected line spelling.",
        },
        {
            "product_code": "DLBB02",
            "brand": "DARK & LOVELY",
            "product_name": "BEAUTIFUL BEGINNINGS CHILD KIT - PURPLE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Beautiful Beginnings line; official SoftSheen-Carson search terms support the corrected line spelling.",
        },
        {
            "product_code": "DLBB07",
            "brand": "DARK & LOVELY",
            "product_name": "BEAUTIFUL BEGINNINGS CUDDLING OIL MOISTURIZER 250ml",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Beautiful Beginnings line; official SoftSheen-Carson search terms support the corrected product naming.",
        },
    ],
    220: [
        {
            "product_code": "DLHD01",
            "brand": "DARK & LOVELY",
            "product_name": "FADE RESIST HAIR DYE 326 BERRY BURGUNDY",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely Fade Resist line; official SoftSheen-Carson search terms support the line naming.",
        },
        {
            "product_code": "DLHD06",
            "brand": "DARK & LOVELY",
            "product_name": "FADE RESIST HAIR DYE 371 JET BLACK",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely Fade Resist line; official SoftSheen-Carson search terms support the line naming.",
        },
        {
            "product_code": "DLHD07",
            "brand": "DARK & LOVELY",
            "product_name": "FADE RESIST HAIR DYE 372 NATURAL BLACK",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely Fade Resist line; official SoftSheen-Carson search terms support the line naming.",
        },
        {
            "product_code": "DLHD08",
            "brand": "DARK & LOVELY",
            "product_name": "FADE RESIST HAIR DYE 373 BROWN SABLE",
            "confidence": "A",
            "confidence_reason": "High-resolution PDF page image confirmed the Dark & Lovely Fade Resist line; official SoftSheen-Carson search terms support the line naming.",
        },
    ],
}

CRAZY_COLOR_COLLECTION_URL = (
    "https://www.crazycolor.co.uk/collections/semi-permanent-hair-dye/products.json"
    "?limit=250&page=1"
)
CRAZY_COLOR_PAGE_144_REASON = (
    "Official Crazy Color semi-permanent collection family linked to PDF page 144, "
    "which acts as the Crazy Color brand divider page."
)
_CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE = None


def build_crazy_color_page_144_products():
    global _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE

    if _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE is not None:
        return _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE

    request = Request(
        CRAZY_COLOR_COLLECTION_URL,
        headers={"User-Agent": "Mozilla/5.0"},
    )

    try:
        with urlopen(request, timeout=30) as response:
            payload = json.load(response)
    except (OSError, URLError, TimeoutError, ValueError, json.JSONDecodeError):
        _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE = []
        return _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE

    products = []

    for product in payload.get("products", []):
        title = normalize_space((product.get("title") or "").replace("’", "'"))
        handle = normalize_space(product.get("handle") or "")
        variants = product.get("variants") or []

        if title == "":
            continue

        anchor_sku = ""
        for variant in variants:
            anchor_sku = normalize_space(variant.get("sku") or "")
            if anchor_sku != "":
                break

        if anchor_sku == "":
            anchor_sku = f"CCOFF-{handle.upper()}" if handle else f"CCOFF-{len(products) + 1}"

        product_name = f"Crazy Color Semi-Permanent Hair Dye - {title}"

        products.append({
            "product_code": anchor_sku,
            "brand": "CRAZY COLOR",
            "product_name": product_name,
            "confidence": "A",
            "confidence_reason": CRAZY_COLOR_PAGE_144_REASON,
        })

    _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE = products
    return _CRAZY_COLOR_PAGE_144_PRODUCTS_CACHE


def get_manual_full_page_products(page_number):
    if page_number == 144:
        crazy_color_products = build_crazy_color_page_144_products()
        if crazy_color_products:
            return crazy_color_products

    return MANUAL_FULL_PAGE_PRODUCTS.get(page_number)

MANUAL_FULL_PAGE_PRODUCTS.update({
    241: [
        {
            "product_code": "EYELINER",
            "brand": "COCO",
            "product_name": "BLACK EYELINER WITH SHARPNER",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; this page contains mixed brands and requires manual row mapping.",
        },
        {
            "product_code": "EYELINER1",
            "brand": "COCO",
            "product_name": "DARK BROWN EYELINER WITH SHARPNER",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; this page contains mixed brands and requires manual row mapping.",
        },
        {
            "product_code": "FLO1",
            "brand": "MURRAY & LANMAN",
            "product_name": "Florida Water 7.5oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; this page contains mixed brands and requires manual row mapping.",
        },
        {
            "product_code": "FL02",
            "brand": "MURRAY & LANMAN",
            "product_name": "FLORIDA WATER MEDIUM 16OZ",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; this page contains mixed brands and requires manual row mapping.",
        },
        {
            "product_code": "FL03",
            "brand": "MURRAY & LANMAN",
            "product_name": "FLORIDA WATER SPRAY LIMITED ED. 12OZ",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; this page contains mixed brands and requires manual row mapping.",
        },
    ],
    277: [
        {
            "product_code": "IG01",
            "brand": "Unknown",
            "product_name": "Advanced Sculpting Gel 250ml",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
        {
            "product_code": "IG02",
            "brand": "Unknown",
            "product_name": "Advanced Sculpting Gel 500ml",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
        {
            "product_code": "IG04",
            "brand": "Unknown",
            "product_name": "Freeze & Shine Icing Gel 250ml",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
        {
            "product_code": "IG05",
            "brand": "Unknown",
            "product_name": "Freeze & Shine Icing Gel 500ml",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
    ],
    334: [
        {
            "product_code": "NAILPOL",
            "brand": "CLASSICS",
            "product_name": "NAIL POLISH REMOVER 400ML",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; both the Classics brand and product caption are visible.",
        },
        {
            "product_code": "NAILPOL01",
            "brand": "CLASSICS",
            "product_name": "NAIL POLISH REMOVER ACETONE FREE 250ML",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; both the Classics brand and product caption are visible.",
        },
    ],
    335: [
        {
            "product_code": "NED1",
            "brand": "Unknown",
            "product_name": "JUMBO CROCHET NEEDLE (1X12PACK)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
        {
            "product_code": "NECK",
            "brand": "Unknown",
            "product_name": "DISPOSABLE NECK ROLLS (1X5 ROLLS PER PACK)",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but the manufacturer brand is not visible enough to confirm safely.",
        },
    ],
    341: [
        {
            "product_code": "OHB",
            "brand": "ORGANIC HAIR ENERGIZER",
            "product_name": "ORGANIC HAIR BOOSTER 6oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "OHB1",
            "brand": "ORGANIC HAIR ENERGIZER",
            "product_name": "5IN1 REJUVENATING SHAMPOO 13oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "OHB2",
            "brand": "ORGANIC HAIR ENERGIZER",
            "product_name": "5IN1 REJUVENATING CONDITIONER 13oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "OHB3",
            "brand": "ORGANIC HAIR ENERGIZER",
            "product_name": "ROOT & SCALP TONIC 50ml",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
    ],
    398: [
        {
            "product_code": "SCIS",
            "brand": "Unknown",
            "product_name": "PROFESSIONAL SCISSOR (GOLD CASE) **SINGLES**",
            "confidence": "C",
            "confidence_reason": "Product identity is clear on the PDF page image, but no reliable manufacturer brand is visible on the left-hand item.",
        },
        {
            "product_code": "SCIS2",
            "brand": "SHERRYS HAIR & BEAUTY",
            "product_name": "THINNING SCISSOR 6.5INCH (1X12PCS)",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image; Sherrys Hair & Beauty branding is visible on the product card.",
        },
    ],
    497: [
        {
            "product_code": "WG01",
            "brand": "WONDER GRO",
            "product_name": "GREEN BERGAMOT HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG02",
            "brand": "WONDER GRO",
            "product_name": "BLUE BERGAMOT HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG03",
            "brand": "WONDER GRO",
            "product_name": "INDIAN HEMP HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG04",
            "brand": "WONDER GRO",
            "product_name": "COCONUT OIL HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG05",
            "brand": "WONDER GRO",
            "product_name": "JAMAICAN HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG06",
            "brand": "WONDER GRO",
            "product_name": "COCONUT & TAR HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "WG07",
            "brand": "WONDER GRO",
            "product_name": "ARGAN OIL HAIR & SCALP CONDITIONER 12oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
    ],
})

MANUAL_FULL_PAGE_PRODUCTS.update({
    410: [
        {
            "product_code": "SMCOC",
            "brand": "SHEA MOISTURE",
            "product_name": "VIRGIN COCONUT SHAMPOO 13oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "SMCOC1",
            "brand": "SHEA MOISTURE",
            "product_name": "VIRGIN COCONUT CONDITIONER 13oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
        {
            "product_code": "SMCOC2",
            "brand": "SHEA MOISTURE",
            "product_name": "VIRGIN COCONUT LEAVE IN CONDITIONER 8oz",
            "confidence": "A",
            "confidence_reason": "Confirmed against the PDF page image.",
        },
    ],
})


def is_code_token(text, page_number):
    text = normalize_space(text)
    normalized = text.upper()
    if normalized == "":
        return False
    if normalized in MANUAL_CODE_TOKENS:
        return True
    if normalized.isdigit():
        return False
    if normalized == str(page_number):
        return False
    return bool(CODE_RE.match(normalized))


def normalize_space(text):
    cleaned = (text or "").replace("\x00", " ")
    cleaned = re.sub(r"[\u200b\u200c\u200d\ufeff]", " ", cleaned)
    return re.sub(r"\s+", " ", cleaned).strip()


def group_lines(words, tolerance=3.5):
    lines = []
    sorted_words = sorted(words, key=lambda word: (round(word["top"], 1), word["x0"]))

    for word in sorted_words:
        placed = False

        for line in lines:
            if abs(line["top"] - word["top"]) <= tolerance:
                line["words"].append(word)
                line["top"] = min(line["top"], word["top"])
                line["bottom"] = max(line["bottom"], word["bottom"])
                placed = True
                break

        if not placed:
            lines.append({
                "top": word["top"],
                "bottom": word["bottom"],
                "words": [word],
            })

    for line in lines:
        line["words"] = sorted(line["words"], key=lambda word: word["x0"])
        line["text"] = normalize_space(" ".join(word["text"] for word in line["words"]))

    return sorted(lines, key=lambda line: (line["top"], line["words"][0]["x0"]))


def simplify_header(header_text):
    header_text = normalize_space(header_text.replace("’", "'"))
    if header_text == "" or GENERIC_HEADER_RE.search(header_text):
        return None, None

    cleaned = re.sub(r"\bRANGE\b", "", header_text, flags=re.IGNORECASE)
    cleaned = normalize_space(cleaned)

    if cleaned == "":
        return None, None

    tokens = cleaned.split()

    if len(tokens) >= 3 and tokens[1].upper() in {"N", "&", "AND"}:
        primary_brand = " ".join(tokens[:3])
    elif len(tokens) >= 2:
        primary_brand = " ".join(tokens[:2])
    else:
        primary_brand = tokens[0]

    return primary_brand, cleaned


def code_prefix_candidates(code):
    compact = re.sub(r"[^A-Z0-9]", "", code.upper())
    prefixes = []

    for length in (4, 3, 2):
        if len(compact) >= length:
            prefixes.append(compact[:length])

    return prefixes


def fallback_brand_from_code(code):
    compact = re.sub(r"[^A-Z0-9]", "", code.upper())
    if len(compact) >= 2:
        return compact[:2]
    return compact or "Unknown"


def manual_brand_from_code(code):
    for prefix in code_prefix_candidates(code):
        brand = MANUAL_PREFIX_BRANDS.get(prefix)
        if brand:
            return brand

    compact = re.sub(r"[^A-Z0-9]", "", code.upper())
    if len(compact) >= 2:
        return MANUAL_PREFIX_BRANDS.get(compact[:2])

    return None


def cluster_columns(code_words, page_width):
    if not code_words:
        return []

    threshold = max(90.0, page_width * 0.12)
    sorted_codes = sorted(code_words, key=lambda item: item["x0"])
    clusters = [[sorted_codes[0]]]

    for code in sorted_codes[1:]:
        last_cluster = clusters[-1]
        last_center = sum(word["x0"] for word in last_cluster) / len(last_cluster)
        if abs(code["x0"] - last_center) <= threshold:
            last_cluster.append(code)
        else:
            clusters.append([code])

    descriptors = []
    centers = [sum(word["x0"] for word in cluster) / len(cluster) for cluster in clusters]

    for index, cluster in enumerate(clusters):
        center = centers[index]
        left = 0.0 if index == 0 else (centers[index - 1] + center) / 2.0
        right = page_width if index == len(clusters) - 1 else (center + centers[index + 1]) / 2.0
        descriptors.append({
            "center": center,
            "left": left,
            "right": right,
            "codes": sorted(cluster, key=lambda item: (item["top"], item["x0"])),
        })

    return descriptors


def lines_from_words(words, tolerance=3.0):
    if not words:
        return []

    return group_lines(words, tolerance=tolerance)


def cleanup_product_name(words, page_number):
    line_texts = []
    for line in lines_from_words(words):
        text = normalize_space(line["text"])
        if text == "" or text == str(page_number):
            continue
        if is_code_token(text, page_number):
            continue
        line_texts.append(text)

    name = normalize_space(" ".join(line_texts))
    name = re.sub(r"\s+([.,])", r"\1", name)
    name = re.sub(r"^[–—-]+\s*", "", name)
    name = normalize_space(MERCH_FROM_NAME_RE.sub(" ", name))
    return name


def infer_page_brand(page_lines, code_words, prefix_brand_map, page_number):
    first_code_top = min(word["top"] for word in code_words) if code_words else math.inf
    header_lines = [
        line["text"]
        for line in page_lines
        if line["bottom"] < (first_code_top - 3) and line["text"] != str(page_number)
    ]
    header_text = normalize_space(" ".join(header_lines))
    explicit_brand, cleaned_header = simplify_header(header_text)

    manual_brand = MANUAL_PAGE_BRANDS.get(page_number)
    if manual_brand:
        return manual_brand, "manual_page", cleaned_header

    if explicit_brand:
        return explicit_brand, "explicit_header", cleaned_header

    code_prefix_brand = None
    for code_word in code_words:
        for prefix in code_prefix_candidates(code_word["text"]):
            if prefix in prefix_brand_map:
                code_prefix_brand = prefix_brand_map[prefix]
                break
        if code_prefix_brand:
            break

    if code_prefix_brand:
        return code_prefix_brand, "prefix_context", cleaned_header

    return None, None, cleaned_header


def confidence_for_product(page_brand_source, product_name, product_words):
    if product_name == "":
        return "D", "Product text could not be paired confidently with the code."

    word_count = len(re.findall(r"[A-Za-z0-9]+", product_name))
    has_size = bool(SIZE_RE.search(product_name))
    starts_with_connector = bool(re.match(r"^[&/+-]", product_name))
    glued_size = bool(re.search(r"[A-Za-z](?:\d+(?:\.\d+)?)(?:oz|ml|g|kg|l)\b", product_name, re.IGNORECASE))
    uppercase_ratio = 0.0
    letters = re.findall(r"[A-Za-z]", product_name)
    if letters:
        uppercase_ratio = sum(1 for char in letters if char.isupper()) / len(letters)

    if starts_with_connector:
        return "D", "The extracted name starts mid-phrase and needs manual review."

    if glued_size:
        return "C", "The product name is readable, but compressed text needs manual review."

    if word_count >= 2 and (has_size or len(product_name) >= 12):
        if page_brand_source in {"explicit_header", "prefix_context", "code_family", "name_context", "manual_prefix", "manual_page"}:
            return "A", "Code-to-name pairing is clear on the page."
        return "B", "Product name is clear, but brand context is weaker."

    if word_count >= 2 and uppercase_ratio >= 0.5:
        return "B", "Product name is readable, but the page layout is slightly compressed."

    if word_count >= 1:
        return "C", "Product name is partially readable, but the pairing is compressed or incomplete."

    return "D", "Product text is too weak to trust without manual review."


def infer_brand_from_product_names(products):
    if len(products) < 2:
        return None

    token_lists = []

    for product in products:
        tokens = re.findall(r"[A-Za-z']+", product["product_name"].replace("’", "'"))
        if len(tokens) < 2:
            return None
        token_lists.append(tokens)

    common = []
    for group in zip(*token_lists):
        if len(set(token.upper() for token in group)) == 1:
            common.append(group[0].upper())
        else:
            break

    if len(common) >= 3 and common[1] in {"N", "&", "AND"}:
        return " ".join(common[:3]).replace(" '", "'")

    if len(common) >= 2:
        return " ".join(common[:2]).replace(" '", "'")

    return None


def apply_manual_override(product):
    override = MANUAL_PRODUCT_OVERRIDES.get(product["product_code"])
    if not override:
        return product

    merged = dict(product)
    merged["brand"] = override.get("brand", product["brand"])
    merged["brand_source"] = "manual_override"
    merged["product_name"] = override.get("product_name", product["product_name"])
    merged["confidence"] = override.get("confidence", product["confidence"])
    merged["confidence_reason"] = override.get("confidence_reason", product["confidence_reason"])
    merged["raw_name_text"] = override.get("product_name", product["raw_name_text"])

    return merged


def append_manual_page_products(products, page_number):
    manual_products = MANUAL_PAGE_PRODUCTS.get(page_number, [])
    if not manual_products:
        return products

    existing_codes = {product["product_code"] for product in products}
    merged = list(products)

    for manual_product in manual_products:
        if manual_product["product_code"] in existing_codes:
            continue

        merged.append({
            "sort_order": len(merged) + 1,
            "brand": manual_product["brand"],
            "brand_source": "manual_page",
            "product_code": manual_product["product_code"],
            "product_name": manual_product["product_name"],
            "confidence": manual_product["confidence"],
            "confidence_reason": manual_product["confidence_reason"],
            "raw_name_text": manual_product["product_name"],
        })

    return merged


def extract_products(page, page_number, prefix_brand_map):
    page_words = page.extract_words(use_text_flow=False, keep_blank_chars=False)
    page_words = [word for word in page_words if normalize_space(word.get("text", "")) != ""]
    page_lines = group_lines(page_words)
    raw_text = normalize_space(page.extract_text() or "")

    manual_full = get_manual_full_page_products(page_number)
    if manual_full is not None:
        products = []
        for index, spec in enumerate(manual_full):
            product = {
                "sort_order": index + 1,
                "brand": spec["brand"],
                "brand_source": "manual_page",
                "product_code": spec["product_code"],
                "product_name": spec["product_name"],
                "confidence": spec["confidence"],
                "confidence_reason": spec["confidence_reason"],
                "raw_name_text": spec["product_name"],
            }
            products.append(apply_manual_override(product))

        brand_context = products[0]["brand"] if products else None

        return {
            "page_number": page_number,
            "header_text": None,
            "brand_context": brand_context,
            "brand_context_source": "manual_page",
            "raw_text": raw_text,
            "products": products,
        }

    code_words = [
        {
            **word,
            "text": normalize_space(word["text"]).replace("–", "-").upper(),
        }
        for word in page_words
        if is_code_token(normalize_space(word["text"]).replace("–", "-"), page_number)
    ]

    if not code_words:
        manual_products = append_manual_page_products([], page_number)
        return {
            "page_number": page_number,
            "header_text": None,
            "brand_context": None,
            "brand_context_source": None,
            "raw_text": raw_text,
            "products": manual_products,
        }

    page_brand, page_brand_source, header_text = infer_page_brand(page_lines, code_words, prefix_brand_map, page_number)
    columns = cluster_columns(code_words, page.width)
    products = []
    product_index = 0

    for column in columns:
        column_words = [
            word for word in page_words
            if ((word["x0"] + word["x1"]) / 2.0) >= column["left"]
            and ((word["x0"] + word["x1"]) / 2.0) < column["right"]
        ]
        code_entries = column["codes"]

        for idx, code_word in enumerate(code_entries):
            next_code = code_entries[idx + 1] if idx + 1 < len(code_entries) else None
            candidate_words = []

            for word in column_words:
                same_line = abs(word["top"] - code_word["top"]) <= 3.0
                above_current = word["bottom"] <= code_word["top"] and not same_line
                if above_current:
                    continue

                if same_line and word["x0"] <= code_word["x1"] + 1:
                    continue

                if next_code and word["top"] >= (next_code["top"] - 2.0):
                    continue

                if is_code_token(normalize_space(word["text"]), page_number):
                    continue

                candidate_words.append(word)

            raw_name_text = cleanup_product_name(candidate_words, page_number)
            if raw_name_text == "":
                continue

            brand = manual_brand_from_code(code_word["text"])
            brand_source = "manual_prefix" if brand else page_brand_source

            if not brand:
                brand = page_brand

            if not brand:
                brand = fallback_brand_from_code(code_word["text"])
                brand_source = "code_family"

            confidence, confidence_reason = confidence_for_product(brand_source, raw_name_text, candidate_words)

            product = {
                "sort_order": product_index + 1,
                "brand": brand,
                "brand_source": brand_source,
                "product_code": code_word["text"],
                "product_name": raw_name_text,
                "confidence": confidence,
                "confidence_reason": confidence_reason,
                "raw_name_text": raw_name_text,
            }
            products.append(apply_manual_override(product))
            product_index += 1

    if not page_brand and products:
        inferred_brand = infer_brand_from_product_names(products)
        if inferred_brand:
            page_brand = inferred_brand
            page_brand_source = "name_context"
            for product in products:
                if product["brand_source"] == "code_family":
                    product["brand"] = inferred_brand
                    product["brand_source"] = "name_context"
                    confidence, reason = confidence_for_product(
                        product["brand_source"],
                        product["product_name"],
                        product["raw_name_text"],
                    )
                    product["confidence"] = confidence
                    product["confidence_reason"] = reason

    products = append_manual_page_products(products, page_number)

    return {
        "page_number": page_number,
        "header_text": header_text,
        "brand_context": page_brand or (products[0]["brand"] if products else None),
        "brand_context_source": page_brand_source or (products[0]["brand_source"] if products else None),
        "raw_text": normalize_space(page.extract_text() or ""),
        "products": products,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--path", required=True)
    parser.add_argument("--from", dest="from_page", type=int, default=1)
    parser.add_argument("--to", dest="to_page", type=int, default=None)
    args = parser.parse_args()

    pdf_path = Path(args.path)
    if not pdf_path.exists():
        raise SystemExit(f"PDF not found: {pdf_path}")

    with pdfplumber.open(str(pdf_path)) as pdf:
        start_page = max(1, args.from_page or 1)
        end_page = min(len(pdf.pages), args.to_page or len(pdf.pages))

        prefix_brand_map = {}

        for page_number in range(start_page, end_page + 1):
            page = pdf.pages[page_number - 1]
            page_words = page.extract_words(use_text_flow=False, keep_blank_chars=False)
            page_words = [word for word in page_words if normalize_space(word.get("text", "")) != ""]
            code_words = [
                {
                    **word,
                    "text": normalize_space(word["text"]).replace("–", "-").upper(),
                }
                for word in page_words
                if is_code_token(normalize_space(word["text"]).replace("–", "-"), page_number)
            ]

            if not code_words:
                continue

            page_lines = group_lines(page_words)
            first_code_top = min(word["top"] for word in code_words)
            header_lines = [
                line["text"]
                for line in page_lines
                if line["bottom"] < (first_code_top - 3) and line["text"] != str(page_number)
            ]
            explicit_brand, _ = simplify_header(normalize_space(" ".join(header_lines)))

            if not explicit_brand:
                continue

            for code_word in code_words:
                for prefix in code_prefix_candidates(code_word["text"]):
                    prefix_brand_map[prefix] = explicit_brand

        pages_payload = []

        for page_number in range(start_page, end_page + 1):
            page = pdf.pages[page_number - 1]
            payload = extract_products(page, page_number, prefix_brand_map)

            pages_payload.append(payload)

    print(json.dumps({
        "source_name": pdf_path.name,
        "source_path": str(pdf_path),
        "pages": pages_payload,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
