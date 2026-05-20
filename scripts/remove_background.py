import argparse
import json
import sys
from pathlib import Path


def hex_to_rgb(value):
    value = value.strip().lstrip("#")
    if len(value) != 6:
        return (255, 255, 255)
    return tuple(int(value[index:index + 2], 16) for index in (0, 2, 4))


def main():
    parser = argparse.ArgumentParser(description="Remove image background and place product on a flat background.")
    parser.add_argument("input_path")
    parser.add_argument("output_path")
    parser.add_argument("--background", default="#ffffff")
    args = parser.parse_args()

    input_path = Path(args.input_path)
    output_path = Path(args.output_path)

    if not input_path.exists():
        print(json.dumps({"ok": False, "error": "Input file does not exist."}), file=sys.stderr)
        return 2

    try:
        from PIL import Image, ImageOps
        from rembg import remove
    except Exception as exc:
        print(json.dumps({
            "ok": False,
            "error": "Python package rembg is not installed or could not be loaded.",
            "details": str(exc),
        }), file=sys.stderr)
        return 10

    try:
        image = Image.open(input_path)
        image = ImageOps.exif_transpose(image).convert("RGBA")
        cutout = remove(image)

        if cutout.mode != "RGBA":
            cutout = cutout.convert("RGBA")

        background = Image.new("RGBA", cutout.size, hex_to_rgb(args.background) + (255,))
        background.alpha_composite(cutout)
        final = background.convert("RGB")

        output_path.parent.mkdir(parents=True, exist_ok=True)
        final.save(output_path, "JPEG", quality=92, optimize=True)

        print(json.dumps({
            "ok": True,
            "width": final.width,
            "height": final.height,
            "output": str(output_path),
        }))
        return 0
    except Exception as exc:
        print(json.dumps({
            "ok": False,
            "error": "Background removal failed.",
            "details": str(exc),
        }), file=sys.stderr)
        return 20


if __name__ == "__main__":
    raise SystemExit(main())
