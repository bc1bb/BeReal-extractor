#!/usr/bin/env python3
"""
cluster_faces.py - face recognition + identity clustering for the BeReal export.

Pipeline
--------
1. For every non-dark post image (per cache.json from analyze.py), run
   InsightFace's `buffalo_l` model — both detection and embedding.
2. Stash detections + 512-d embeddings in faces_raw.npz (resumable across runs).
3. Cluster all embeddings with DBSCAN under cosine distance.
4. Emit faces.json containing every face with its cluster id, plus a
   representative crop per cluster, ready for the PHP UI.

The data root (the folder containing Photos/ and user.json) is discovered
by walking up from this script's location; you can override with --root.

Usage:
    python3 cluster_faces.py                  # full embed + cluster
    python3 cluster_faces.py --cluster-only   # re-cluster from saved embeddings
    python3 cluster_faces.py --eps 0.4 --min-samples 3
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

DET_SIZE          = 640
MIN_SCORE         = 0.55     # detection-confidence threshold
DEFAULT_EPS       = 0.42     # DBSCAN cosine-distance epsilon (tuned for buffalo_l)
DEFAULT_MIN_S     = 4        # need at least N appearances to form a cluster
MIN_FACE_PIXELS   = 24       # discard tiny boxes (noise from background faces)
SAVE_EVERY        = 50
TOP_MEMBERS_KEPT  = 40       # cap members per cluster in faces.json to keep it slim

# -----------------------------------------------------------------------------


def find_data_root(start: Path) -> Path:
    cur = start.resolve()
    for _ in range(5):
        if (cur / "Photos").is_dir() and (cur / "user.json").is_file():
            return cur
        if cur.parent == cur:
            break
        cur = cur.parent
    raise SystemExit(
        "Could not locate the BeReal export root (a folder containing both "
        "Photos/ and user.json). Pass --root explicitly."
    )


def load_cache(root: Path) -> dict:
    p = root / "cache.json"
    if not p.exists():
        return {}
    try:
        return json.loads(p.read_text())
    except Exception:
        return {}


def collect_candidates(root: Path, cache: dict) -> list[tuple[str, Path]]:
    candidates: list[tuple[str, Path]] = []
    base = root / "Photos" / "post"
    if not base.is_dir():
        return candidates
    for p in sorted(base.iterdir()):
        if p.suffix.lower() != ".webp":
            continue
        rel = str(p.relative_to(root))
        if cache.get(rel, {}).get("dark"):  # skip near-black frames
            continue
        candidates.append((rel, p))
    return candidates


def detect_pass(root: Path, raw_path: Path, force: bool, limit: int | None) -> None:
    from insightface.app import FaceAnalysis

    cache = load_cache(root)
    if not cache:
        print("warning: cache.json not found — running analyze.py first improves speed and skips dark frames.")

    candidates = collect_candidates(root, cache)
    if limit:
        candidates = candidates[:limit]

    rels: list[str]  = []
    embs: list[np.ndarray] = []
    boxes: list[list[int]] = []
    sides: list[dict] = []
    done: set[str] = set()

    if raw_path.exists() and not force:
        with np.load(raw_path, allow_pickle=True) as z:
            rels  = z["rels"].tolist()
            embs  = list(z["embs"].tolist())
            boxes = z["boxes"].tolist()
            sides = z["sides_meta"].tolist()
            done  = set(rels)

    print(f"Embedding pass: {len(candidates)} candidate images, {len(done)} already done.")
    if not candidates:
        return

    app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    app.prepare(ctx_id=0, det_size=(DET_SIZE, DET_SIZE))

    t0 = time.time()
    n_new = 0
    last_save = 0

    def save_progress() -> None:
        np.savez(
            raw_path,
            rels=np.array(rels, dtype=object),
            embs=np.array(embs, dtype=object),
            boxes=np.array(boxes, dtype=object),
            sides_meta=np.array(sides, dtype=object),
        )

    try:
        for i, (rel, path) in enumerate(candidates, 1):
            if rel in done:
                continue
            img = cv2.imread(str(path))
            if img is None:
                continue
            h, w = img.shape[:2]
            faces = app.get(img)
            for f in faces:
                if f.det_score < MIN_SCORE:
                    continue
                x1, y1, x2, y2 = [int(v) for v in f.bbox]
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(w, x2), min(h, y2)
                if x2 - x1 < MIN_FACE_PIXELS or y2 - y1 < MIN_FACE_PIXELS:
                    continue
                rels.append(rel)
                embs.append(np.asarray(f.normed_embedding, dtype=np.float32))
                boxes.append([x1, y1, x2 - x1, y2 - y1])
                sides.append({
                    "score":  float(f.det_score),
                    "gender": int(f.sex == "M") if hasattr(f, "sex") else -1,
                    "age":    int(f.age) if hasattr(f, "age") else -1,
                    "img_w":  int(w),
                    "img_h":  int(h),
                })
            done.add(rel)
            n_new += 1
            if n_new - last_save >= SAVE_EVERY:
                elapsed = time.time() - t0
                rate = n_new / elapsed if elapsed else 0
                eta  = (len(candidates) - i) / rate if rate else 0
                print(f"  {i}/{len(candidates)} (new {n_new}, {rate:.1f} img/s, eta {eta:.0f}s)")
                save_progress()
                last_save = n_new
    finally:
        save_progress()

    unique_imgs = len(set(rels))
    print(f"Embeddings: {len(embs)} faces over {unique_imgs} images.")


def cluster_pass(root: Path, raw_path: Path, out_path: Path, eps: float, min_samples: int) -> None:
    from sklearn.cluster import DBSCAN

    if not raw_path.exists():
        print(f"error: {raw_path.name} not found — run the embedding pass first.")
        return

    with np.load(raw_path, allow_pickle=True) as z:
        rels  = z["rels"].tolist()
        embs  = np.array([np.asarray(e, dtype=np.float32) for e in z["embs"]])
        boxes = z["boxes"].tolist()
        sides = z["sides_meta"].tolist()

    if len(embs) == 0:
        print("No faces to cluster.")
        out_path.write_text(json.dumps({"by_image": {}, "clusters": []}))
        return

    print(f"Clustering {len(embs)} embeddings with DBSCAN(eps={eps}, min_samples={min_samples})…")
    db = DBSCAN(eps=eps, min_samples=min_samples, metric="cosine", n_jobs=-1)
    labels = db.fit_predict(embs)

    by_image: dict[str, list[dict]] = {}
    clusters: dict[int, dict] = {}

    for idx, lab in enumerate(labels):
        lab = int(lab)
        rel = rels[idx]
        box = boxes[idx]
        sm  = sides[idx]
        entry = {"box": box, "cluster": lab, "score": sm["score"], "age": sm["age"], "gender": sm["gender"]}
        by_image.setdefault(rel, []).append(entry)
        if lab == -1:                           # DBSCAN noise
            continue
        c = clusters.setdefault(lab, {
            "id": lab, "count": 0,
            "ages": [], "genders": [],
            "rep_image": None, "rep_box": None, "rep_score": -1.0,
            "members": [],
        })
        c["count"] += 1
        if sm["age"] >= 0:
            c["ages"].append(sm["age"])
        c["genders"].append(sm["gender"])
        if sm["score"] > c["rep_score"]:
            c["rep_score"] = sm["score"]
            c["rep_image"] = rel
            c["rep_box"]   = box
        c["members"].append({"rel": rel, "box": box, "score": sm["score"]})

    summary = []
    for cid, c in clusters.items():
        ages = c["ages"]
        avg_age = round(sum(ages) / len(ages), 1) if ages else None
        male   = sum(1 for g in c["genders"] if g == 1)
        female = sum(1 for g in c["genders"] if g == 0)
        c["members"].sort(key=lambda m: -m["score"])
        summary.append({
            "id":         cid,
            "count":      c["count"],
            "rep_image":  c["rep_image"],
            "rep_box":    c["rep_box"],
            "avg_age":    avg_age,
            "male":       male,
            "female":     female,
            "members":    c["members"][:TOP_MEMBERS_KEPT],
        })
    summary.sort(key=lambda c: -c["count"])

    noise = int(np.sum(labels == -1))
    print(f"Clusters: {len(summary)}, noise faces: {noise}")
    out_path.write_text(json.dumps({"by_image": by_image, "clusters": summary}))
    print(f"Wrote {out_path}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--root",         type=Path, default=None, help="BeReal export root (defaults to auto-detect)")
    ap.add_argument("--limit",        type=int,  default=None, help="process only the first N images")
    ap.add_argument("--force",        action="store_true",     help="re-embed every image, ignoring faces_raw.npz")
    ap.add_argument("--cluster-only", action="store_true",     help="skip embedding; re-cluster from faces_raw.npz")
    ap.add_argument("--eps",          type=float, default=DEFAULT_EPS,   help=f"DBSCAN cosine epsilon (default {DEFAULT_EPS})")
    ap.add_argument("--min-samples",  type=int,   default=DEFAULT_MIN_S, help=f"DBSCAN min_samples (default {DEFAULT_MIN_S})")
    args = ap.parse_args()

    root = (args.root.resolve() if args.root else find_data_root(Path(__file__).parent))
    raw_path = root / "faces_raw.npz"
    out_path = root / "faces.json"

    print(f"Data root : {root}")
    print(f"Embeddings: {raw_path}")
    print(f"Output    : {out_path}")

    if not args.cluster_only:
        detect_pass(root, raw_path, force=args.force, limit=args.limit)
    cluster_pass(root, raw_path, out_path, eps=args.eps, min_samples=args.min_samples)
    return 0


if __name__ == "__main__":
    sys.exit(main())
