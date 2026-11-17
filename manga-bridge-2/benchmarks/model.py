#!/usr/bin/env python3
"""Estimate serial requests with explicit assumptions; merge real disposable-WP write measurements.

No production API / site / image Worker requests are made here.
All RTT parameters below are assumptions, not measured real origin latency.
"""
import argparse
import json
import math
from pathlib import Path

CASES = [
    ("poucos", 10, 10, 12),
    ("medios", 10, 60, 20),
    ("muitos", 10, 200, 25),
]
MAX_REQUESTS_PER_RUN = 300
MAX_RUNS_PER_JOB = 4
RUN_SECONDS = 420  # collector's soft budget, workflow hard limit is 8 minutes
PACING_SECONDS = 0.15
RUNNER_STARTUP_ASSUMPTION = 18
# Synthetic RTTs for fast/base/slow; no origins measured.
LATENCIES = {"rapido": 0.15, "base": 0.45, "lento": 0.9}


def checkpoint_size(chapters, pages):
    """Approximate complete checkpoint JSON using current engine state structure."""
    base = "https://mangalivre.blog"
    chapter_urls = [f"{base}/capitulo/obra-demo-capitulo-{i}/" for i in range(1, chapters + 1)]
    cover = f"{base}/wp-content/uploads/2026/11/demo-cover.webp"
    image_urls = [
        [f"https://cdn.exemplo.org/wp-content/uploads/2026/11/obra-capitulo-{ch:04d}/pagina-{i:03d}.webp"
         for i in range(1, pages + 1)]
        for ch in range(1, chapters + 1)
    ]
    images = {img: {"sha256_prefix": "a" * 64, "width": 900, "height": 1300,
                    "mode": "origin_range_header"} for batch in image_urls for img in batch}
    state = {
        "inventory_hash": "f" * 64,
        "work": {
            "source_url": f"{base}/manga/obra-demo/", "title": "Obra ficticia",
            "synopsis": "Benchmark de inventario e carga do executor externo.",
            "cover_url": cover, "expected_chapters": chapters,
            "chapter_urls": chapter_urls,
        },
        "chapters": [{"url": u, "pages": arr} for u, arr in zip(chapter_urls, image_urls)],
        "images": images,
        "complete": True,
    }
    return len(json.dumps(state, ensure_ascii=False, separators=(",", ":")).encode())


def model_case(label, works, chapters, pages, measured_write):
    requests_one = 2 + chapters * (pages + 1)  # work HTML + cover + chapters HTML + image ranges
    checkpoint_bytes = checkpoint_size(chapters, pages)
    runs_floor = math.ceil(requests_one / MAX_REQUESTS_PER_RUN)
    data = {
        "label": label, "works": works, "chapters_per_work": chapters,
        "pages_per_chapter": pages,
        "total_chapters": works * chapters, "total_image_urls": works * chapters * pages,
        "requests_per_work_lower_bound": requests_one,
        "requests_all_10_lower_bound": works * requests_one,
        "workflow_runs_per_work_lower_bound": runs_floor,
        "max_runs_per_job": MAX_RUNS_PER_JOB,
        "max_requests_per_run": MAX_REQUESTS_PER_RUN,
        "estimated_final_checkpoint_bytes": checkpoint_bytes,
        "max_checkpoint_bytes": 220000,
        "checkpoint_may_exceed_limit": checkpoint_bytes > 220000,
        "can_finish_under_current_request_caps": runs_floor <= MAX_RUNS_PER_JOB,
        "measured_wp_synthetic_write_seconds": measured_write,
        "scenarios": {},
    }
    for category, latency in LATENCIES.items():
        seconds_per_request = max(latency, PACING_SECONDS)
        bound_per_run = min(MAX_REQUESTS_PER_RUN, math.floor(RUN_SECONDS / seconds_per_request))
        needed = math.ceil(requests_one / bound_per_run)
        network_total = works * requests_one * seconds_per_request
        starts_total = works * needed * RUNNER_STARTUP_ASSUMPTION
        data["scenarios"][category] = {
            "assumed_request_rtt_seconds": latency,
            "requests_per_workflow_under_soft_time_budget": bound_per_run,
            "required_runs_for_10": works * needed,
            "fits_4_attempts_each": needed <= MAX_RUNS_PER_JOB and checkpoint_bytes <= 220000,
            "hypothetical_seconds_if_caps_and_checkpoint_fixed":
                round(network_total + starts_total + (measured_write or 0)),
            "measured_only": False,
        }
    return data


def run(measured):
    by_label = {x["label"]: x for x in (measured or {}).get("cases", [])}
    report = {
        "title": "10 manga benchmark: synthetic WordPress writes plus modeled origin overhead",
        "status": "NOT an end-to-end production benchmark",
        "source": {
            "github_ci_wp_writes": "measured in disposable WordPress/MariaDB if supplied",
            "origin_request_rtt": "synthetic assumed 0.15/0.45/0.9 seconds",
            "source_pages_and_images": "synthetic counts, full origin image validation not executed",
            "worker": "not called",
            "external_publication": "not implemented",
        },
        "assumptions": {
            "sequential_one_manga_at_a_time": True,
            "workflow_startup_seconds_assumed": RUNNER_STARTUP_ASSUMPTION,
            "max_image_probe_requests_per_page": 1,
            "network_retries_and_429": "excluded; actual duration can be longer",
            "runner_and_wp_overhead": "WP write measured if provided; startup assumed",
            "confidence": "capacity estimates only; cannot assert wall-clock for site",
        },
        "cases": [model_case(label, n, ch, pages,
                            by_label.get(label, {}).get("write_elapsed_seconds"))
                  for label, n, ch, pages in CASES]
    }
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--wp-report", default="")
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    measured = json.loads(Path(args.wp_report).read_text()) if args.wp_report else {}
    report = run(measured)
    dest = Path(args.out)
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    for case in report["cases"]:
        status = "within-request-cap" if case["can_finish_under_current_request_caps"] else "BLOCKED BY 4-RUN CAP"
        if case["checkpoint_may_exceed_limit"]:
            status += "; CHECKPOINT > 220KB"
        print(f'{case["label"]}: 10 x {case["chapters_per_work"]} ch / {case["pages_per_chapter"]} pages, '
              f'{case["requests_all_10_lower_bound"]} requests; '
              f'WP synthetic write {case["measured_wp_synthetic_write_seconds"]}s; {status}')
        for name, scenario in case["scenarios"].items():
            print(f'  {name}: hypothetical {scenario["hypothetical_seconds_if_caps_and_checkpoint_fixed"]}s; '
                  f'{scenario["required_runs_for_10"]} workflows; fits={scenario["fits_4_attempts_each"]}')


if __name__ == "__main__":
    main()
