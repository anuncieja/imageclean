"""Run a bounded live source inventory, never WordPress publication."""
from __future__ import annotations
import argparse
import json
import sys
from pathlib import Path

from .crawler import BoundedFetcher, InventoryCrawler
from .source import safe_url


def main(argv=None):
    ap = argparse.ArgumentParser(description="Manga Bridge 2.0 read-only inventory (no WordPress writes)")
    ap.add_argument("--url", required=True)
    ap.add_argument("--allow-host", required=True, help="Exact approved source host")
    ap.add_argument("--live", action="store_true", help="Explicit opt-in: perform bounded external GET requests")
    ap.add_argument("--report", required=True)
    ap.add_argument("--checkpoint", default="")
    ap.add_argument("--max-pages", type=int, default=3)
    ap.add_argument("--max-requests", type=int, default=4)
    ap.add_argument("--sample-chapters", type=int, default=0, choices=[0, 1, 2])
    ap.add_argument("--timeout", type=float, default=7)
    ap.add_argument("--min-gap", type=float, default=1.5)
    args = ap.parse_args(argv)
    if not args.live:
        ap.error("--live must be set to explicitly authorize requests; tests use in-memory fixtures")
    normalized = safe_url(args.url, host=args.allow_host)
    if not normalized:
        ap.error("Origin URL must be HTTPS and match --allow-host exactly")
    if args.allow_host.lower() in {"img.lermangaonline.com.br", "lermangaonline.com.br"}:
        ap.error("WordPress site and image Worker are forbidden as extraction origins")
    if args.max_requests > 20 or args.max_pages > 20 or args.sample_chapters > 2:
        ap.error("Safety limits exceeded")
    fetcher = BoundedFetcher(args.allow_host, args.max_requests, args.timeout, min_gap=args.min_gap)
    crawler = InventoryCrawler(fetcher.fetch_html, max_pages=args.max_pages,
                               checkpoint=Path(args.checkpoint) if args.checkpoint else None)
    result = crawler.crawl(normalized, sample_chapters=args.sample_chapters)
    dest = Path(args.report)
    dest.parent.mkdir(parents=True, exist_ok=True)
    with dest.open("x", encoding="utf-8") as fp:
        json.dump(result, fp, ensure_ascii=False, indent=2)
        fp.write("\n")
    # Log only aggregate diagnostics. No chapters, image URLs or secrets in CI logs.
    print(json.dumps({k: result[k] for k in (
        "status", "declaredChapterCount", "discoveredChapterCount",
        "pagesFetched", "pendingPageCount", "dynamicLoaderSeen", "allowPublish"
    )}, ensure_ascii=False))
    return 0 if result["status"] == "inventory_complete" else 3


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError) as exc:
        print(f"Extractor error: {exc}", file=sys.stderr)
        sys.exit(2)
