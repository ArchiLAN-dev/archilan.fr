#!/usr/bin/env python3
"""Build the transparent still of each video avatar frame (story 30.47).

A video frame is light filmed on black, shown over the avatar with `mix-blend-mode: screen`. That blend only sees its
own stacking context: under a parent with a z-index, a transform or an opacity (common in lists), the black shows as
a dark box. The still shown everywhere off the profile page therefore carries real transparency instead: alpha is the
brightness of each pixel (max of R, G, B) and the colour is divided by it, so the still over any background looks
like the screen blend over a dark one (slightly more saturated: screen also lets the background's hue through),
with no blend at all.

Input : frontend/public/avatar-frames/<frame>-poster.webp (opaque, on black, 512 px, story 30.46 geometry)
Output: frontend/public/avatar-frames/<frame>-still.webp (RGBA, 256 px: off the profile page an avatar is at most
        64 px, so its overflowing effect never exceeds ~110 px on screen)

Usage: python scripts/avatar-frame-stills.py   (needs Pillow)
"""

from pathlib import Path

from PIL import Image

FRAMES_DIR = Path(__file__).resolve().parent.parent / "frontend" / "public" / "avatar-frames"
SIZE = 256


def transparent_still(poster: Image.Image) -> Image.Image:
    rgb = poster.convert("RGB").resize((SIZE, SIZE), Image.LANCZOS)
    src = rgb.tobytes()
    out = bytearray(len(src) // 3 * 4)
    for i in range(len(src) // 3):
        r, g, b = src[3 * i], src[3 * i + 1], src[3 * i + 2]
        alpha = max(r, g, b)
        if alpha:
            out[4 * i : 4 * i + 4] = bytes((r * 255 // alpha, g * 255 // alpha, b * 255 // alpha, alpha))
    return Image.frombytes("RGBA", rgb.size, bytes(out))


def main() -> None:
    posters = sorted(FRAMES_DIR.glob("*-poster.webp"))
    if not posters:
        raise SystemExit(f"no poster found in {FRAMES_DIR}")
    for poster_path in posters:
        still_path = poster_path.with_name(poster_path.name.replace("-poster.webp", "-still.webp"))
        transparent_still(Image.open(poster_path)).save(still_path, "WEBP", quality=85, alpha_quality=90, method=6)
        print(f"{still_path.name}: {still_path.stat().st_size // 1024} Ko")


if __name__ == "__main__":
    main()
