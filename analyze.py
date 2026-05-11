#!/usr/bin/env python3
"""
analyze.py - first-pass photo analysis for a BeReal export.

Walks every .webp under <export>/Photos, marks near-black frames, and runs
a Haar-cascade face detector on the rest. Writes results into
<export>/cache.json keyed by the relative path. Reruns are incremental:
already-analyzed entries are skipped unless --force is given.

Usage:
    python3 analyze.py              # full pass, resumable
    python3 analyze.py --limit 100  # only the first 100 images
    python3 analyze.py --force      # re-analyze everything

The data root (the folder containing Photos/ and user.json) is discovered
by walking up from this script's location; you can override with --root.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

import cv2
import numpy as np

# --- Tunables -----------------------------------------------------------------

DARK_MEAN_THRESHOLD = 22       # mean grayscale below this = "almost black"
DARK_STD_THRESHOLD  = 10       # very low variance too = uniformly dark
FACE_SCALE          = 1.15
FACE_MIN_NEIGHBORS  = 5
FACE_MIN_SIZE_RATIO = 0.06     # face must cover at least 6% of the short side

# -----------------------------------------------------------------------------


def find_data_root(start: Path) -> Path:
    """Walk upward from `start` looking for a folder that contains both
    `Photos/` and `user.json`. Raises if nothing is found within 5 levels."""
    cur = start.resolve()
    for _ in range(5):
        if (cur / "Photos").is_dir() and (cur / "user.json").is_file():
            return cur
        if cur.parent == cur:
            break
        cur = cur.parent
    raise SystemExit(
        "Could not locate the BeReal export root (a folder containing both "
        "Photos/ and user.json). Pass --root explicitly or place this script "
        "inside (or beside) your unzipped export."
    )


def load_cascades() -> tuple[cv2.CascadeClassifier, cv2.CascadeClassifier]:
    frontal = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
    profile = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_profileface.xml")
    if frontal.empty() or profile.empty():
        raise SystemExit("Could not load OpenCV Haar cascades — is opencv installed correctly?")
    return frontal, profile


def analyze(path: Path, frontal: cv2.CascadeClassifier, profile: cv2.CascadeClassifier) -> dict | None:
    img = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if img is None:
        return None

    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    mean = float(gray.mean())
    std  = float(gray.std())
    dark = mean < DARK_MEAN_THRESHOLD and std < DARK_STD_THRESHOLD * 2

    faces: list[list[int]] = []
    if not dark:
        min_side = int(min(h, w) * FACE_MIN_SIZE_RATIO)
        eq = cv2.equalizeHist(gray)
        detected = frontal.detectMultiScale(
            eq, scaleFactor=FACE_SCALE, minNeighbors=FACE_MIN_NEIGHBORS, minSize=(min_side, min_side),
        )
        for (x, y, fw, fh) in detected:
            faces.append([int(x), int(y), int(fw), int(fh)])

        if not faces:
            # Side-lit selfies often miss the frontal cascade; profile pass picks them up.
            detected = profile.detectMultiScale(
                eq, scaleFactor=FACE_SCALE, minNeighbors=FACE_MIN_NEIGHBORS, minSize=(min_side, min_side),
            )
            for (x, y, fw, fh) in detected:
                faces.append([int(x), int(y), int(fw), int(fh)])

    return {
        "w":     w,
        "h":     h,
        "mean":  round(mean, 2),
        "std":   round(std, 2),
        "dark":  bool(dark),
        "faces": faces,
    }


def collect_targets(photos_dir: Path) -> list[Path]:
    targets: list[Path] = []
    for sub in ("post", "profile", "realmoji"):
        base = photos_dir / sub
        if not base.is_dir():
            continue
        for p in sorted(base.iterdir()):
            if p.suffix.lower() == ".webp":
                targets.append(p)
    return targets


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--root",  type=Path, default=None, help="BeReal export root (defaults to auto-detect)")
    ap.add_argument("--limit", type=int,  default=None, help="process only the first N images")
    ap.add_argument("--force", action="store_true",     help="re-analyze even if a cache entry exists")
    args = ap.parse_args()

    root = (args.root.resolve() if args.root else find_data_root(Path(__file__).parent))
    photos_dir = root / "Photos"
    cache_path = root / "cache.json"

    print(f"Data root : {root}")
    print(f"Photos    : {photos_dir}")
    print(f"Cache     : {cache_path}")

    cache: dict[str, dict] = {}
    if cache_path.exists():
        try:
            cache = json.loads(cache_path.read_text())
        except Exception:
            print(f"warning: could not parse existing {cache_path.name}, starting fresh")
            cache = {}

    targets = collect_targets(photos_dir)
    if args.limit:
        targets = targets[: args.limit]
    total = len(targets)
    print(f"Found {total} images; {len(cache)} already cached.")
    if total == 0:
        return 0

    frontal, profile = load_cascades()

    t0 = time.time()
    processed = 0
    skipped = 0

    try:
        for i, path in enumerate(targets, 1):
            rel = str(path.relative_to(root))
            if not args.force and rel in cache:
                skipped += 1
                continue
            result = analyze(path, frontal, profile)
            cache[rel] = result if result is not None else {"error": "could not decode"}
            processed += 1
            if processed % 100 == 0:
                elapsed = time.time() - t0
                rate = processed / elapsed if elapsed else 0
                eta  = (total - i) / rate if rate else 0
                print(f"  {i}/{total}  ({rate:4.1f} img/s, eta {eta:4.0f}s)")
                cache_path.write_text(json.dumps(cache))
    finally:
        cache_path.write_text(json.dumps(cache))

    elapsed = time.time() - t0
    print(
        f"Done. processed={processed}, skipped(cached)={skipped}, "
        f"total_indexed={len(cache)}, time={elapsed:.1f}s -> {cache_path}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
