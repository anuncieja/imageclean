"""Bounded, resumable, read-only inventory. Reports never authorize WordPress writes."""
from __future__ import annotations
from dataclasses import asdict, dataclass, field
from ipaddress import ip_address
from pathlib import Path
import json
import os
import socket
import tempfile
import time
from typing import Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from .source import MANGA_PATH, is_chapter_url, is_pagination_url, parse_html, safe_url


class FetchFailure(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class BoundedFetcher:
    """One request at a time: no binary downloads and no Cloudflare Worker calls."""
    def __init__(self, allow_host: str, max_requests: int = 4, timeout: float = 7.0,
                 max_bytes: int = 1_500_000, min_gap: float = 1.5):
        if not allow_host or safe_url(f"https://{allow_host}/", host=allow_host) is None:
            raise ValueError("INVALID_ALLOWED_HOST")
        if not 1 <= max_requests <= 20 or not 1 <= timeout <= 15 or not 20000 <= max_bytes <= 2_000_000 or not 0 <= min_gap <= 10:
            raise ValueError("INVALID_NETWORK_LIMITS")
        self.allow_host = allow_host.lower()
        self.max_requests = max_requests
        self.timeout = timeout
        self.max_bytes = max_bytes
        self.min_gap = min_gap
        self.count = 0
        self.last_at = 0.0
        self.opener = build_opener(NoRedirect())

    def _public_dns(self):
        try:
            results = socket.getaddrinfo(self.allow_host, 443, type=socket.SOCK_STREAM)
            if not results:
                raise FetchFailure("DNS_NO_ADDRESSES")
            for row in results:
                addr = ip_address(row[4][0])
                if not addr.is_global:
                    raise FetchFailure("DNS_NON_PUBLIC_ADDRESS")
        except FetchFailure:
            raise
        except OSError:
            raise FetchFailure("DNS_FAILED")

    def fetch_html(self, url: str) -> str:
        safe = safe_url(url, host=self.allow_host)
        if not safe or urlsplit(safe).hostname != self.allow_host:
            raise FetchFailure("HOST_NOT_ALLOWED")
        if self.count >= self.max_requests:
            raise FetchFailure("REQUEST_BUDGET_EXHAUSTED")
        # Resolve before issuing the request; never follow redirects.
        self._public_dns()
        delay = self.min_gap - (time.monotonic() - self.last_at)
        if delay > 0:
            time.sleep(delay)
        self.count += 1
        self.last_at = time.monotonic()
        req = Request(safe, headers={
            "User-Agent": "MangaBridge2/0.2 (bounded read-only inventory)",
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Encoding": "identity"
        })
        try:
            with self.opener.open(req, timeout=self.timeout) as response:
                status = response.getcode()
                if status != 200:
                    raise FetchFailure(f"HTTP_{status}")
                ctype = response.headers.get("Content-Type", "").lower()
                if ctype and "text/html" not in ctype and "application/xhtml+xml" not in ctype:
                    raise FetchFailure("UNEXPECTED_CONTENT_TYPE")
                body = response.read(self.max_bytes + 1)
                if len(body) > self.max_bytes:
                    raise FetchFailure("HTML_TOO_LARGE")
                charset = response.headers.get_content_charset() or "utf-8"
                return body.decode(charset, errors="replace")
        except FetchFailure:
            raise
        except HTTPError as exc:
            raise FetchFailure(f"HTTP_{exc.code}")
        except (URLError, TimeoutError, OSError):
            raise FetchFailure("NETWORK_UNAVAILABLE")


@dataclass
class CrawlState:
    schema_version: int
    manga_url: str
    queue: list[str] = field(default_factory=list)
    seen_pages: list[str] = field(default_factory=list)
    chapters: list[dict] = field(default_factory=list)
    declared_counts: list[int] = field(default_factory=list)
    title: str = ""
    cover_url: str = ""
    dynamic_loader: bool = False
    issues: list[str] = field(default_factory=list)
    chapter_sample: list[dict] = field(default_factory=list)


def load_state(path: Path, manga_url: str) -> CrawlState:
    if not path.exists():
        return CrawlState(1, manga_url, queue=[manga_url])
    if path.stat().st_size > 3_000_000:
        raise ValueError("CHECKPOINT_TOO_LARGE")
    raw = json.loads(path.read_text(encoding="utf-8"))
    if raw.get("schema_version") != 1 or raw.get("manga_url") != manga_url:
        raise ValueError("CHECKPOINT_MISMATCH")
    # Validate untrusted persisted queue before any request.
    if not isinstance(raw.get("queue"), list) or len(raw.get("queue")) > 1000:
        raise ValueError("CHECKPOINT_INVALID")
    for entry in raw["queue"] + raw.get("seen_pages", []):
        if not safe_url(entry, host=(urlsplit(manga_url).hostname or "")) or not (
            entry == manga_url or is_pagination_url(entry, manga_url)
        ):
            raise ValueError("CHECKPOINT_UNSAFE_URL")
    return CrawlState(**raw)


def save_state(path: Path, state: CrawlState):
    path.parent.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(asdict(state), ensure_ascii=False, separators=(",", ":")) + "\n"
    if len(raw) > 3_000_000:
        raise ValueError("CHECKPOINT_TOO_LARGE")
    fd, temp_path = tempfile.mkstemp(prefix=".mb2-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fp:
            fp.write(raw)
            fp.flush()
            os.fsync(fp.fileno())
        os.replace(temp_path, path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)


class InventoryCrawler:
    def __init__(self, fetch_html: Callable[[str], str], max_pages: int = 4, max_chapters: int = 1000,
                 checkpoint: Path | None = None):
        if not 1 <= max_pages <= 20 or not 1 <= max_chapters <= 1000:
            raise ValueError("CRAWL_LIMIT_INVALID")
        self.fetch_html = fetch_html
        self.max_pages = max_pages
        self.max_chapters = max_chapters
        self.checkpoint = checkpoint

    def crawl(self, manga_url: str, sample_chapters: int = 0) -> dict:
        manga_url = safe_url(manga_url)
        if not manga_url or not MANGA_PATH.fullmatch(urlsplit(manga_url).path):
            raise ValueError("MANGA_URL_INVALID")
        if not 0 <= sample_chapters <= 2:
            raise ValueError("SAMPLE_LIMIT_INVALID")
        state = load_state(self.checkpoint, manga_url) if self.checkpoint else CrawlState(1, manga_url, queue=[manga_url])
        seen_urls = {c["url"] for c in state.chapters}
        status = "inventory_partial"
        while state.queue and len(state.seen_pages) < self.max_pages:
            page_url = state.queue[0]
            if page_url in state.seen_pages:
                state.queue.pop(0)
                continue
            try:
                html = self.fetch_html(page_url)
            except FetchFailure as exc:
                if exc.code not in state.issues:
                    state.issues.append(exc.code)
                status = "source_unavailable"
                break
            snapshot = parse_html(html, page_url, manga_url)
            if not state.title and snapshot.title:
                state.title = snapshot.title
            if not state.cover_url and snapshot.cover_url:
                state.cover_url = snapshot.cover_url
            if snapshot.reported_count:
                if snapshot.reported_count not in state.declared_counts:
                    state.declared_counts.append(snapshot.reported_count)
            if snapshot.dynamic_loader:
                state.dynamic_loader = True
            for chapter in snapshot.chapter_links:
                if chapter["url"] not in seen_urls and len(state.chapters) < self.max_chapters:
                    state.chapters.append(chapter)
                    seen_urls.add(chapter["url"])
            for next_page in snapshot.next_pages:
                if next_page not in state.seen_pages and next_page not in state.queue:
                    state.queue.append(next_page)
            state.seen_pages.append(page_url)
            state.queue.pop(0)
            if self.checkpoint:
                save_state(self.checkpoint, state)
        # Sample up to two chapter pages, parse image URLs, no image HEAD/GET.
        if status != "source_unavailable" and sample_chapters > 0 and state.chapters:
            sampled = {x["chapter_url"] for x in state.chapter_sample}
            for chapter in state.chapters[:sample_chapters]:
                if chapter["url"] in sampled:
                    continue
                try:
                    html = self.fetch_html(chapter["url"])
                    snapshot = parse_html(html, chapter["url"], manga_url)
                    state.chapter_sample.append({"chapter_url": chapter["url"],
                                                 "image_count": len(snapshot.chapter_images),
                                                 "images_structurally_valid": len(snapshot.chapter_images) > 0})
                except FetchFailure as exc:
                    state.issues.append("SAMPLE_" + exc.code)
                    break
                if self.checkpoint:
                    save_state(self.checkpoint, state)
        declared = state.declared_counts[0] if len(state.declared_counts) == 1 else None
        count = len(state.chapters)
        number_counts = {}
        for chapter in state.chapters:
            if chapter["number"] is not None:
                number_counts[chapter["number"]] = number_counts.get(chapter["number"], 0) + 1
        duplicate_numbers = sorted([num for num, tally in number_counts.items() if tally > 1])
        unknown_numbers = sum(1 for chapter in state.chapters if chapter["number"] is None)
        if len(state.declared_counts) > 1:
            status = "blocked_conflicting_counts"
        elif status != "source_unavailable":
            if not count:
                status = "blocked_empty_list"
            elif not declared:
                status = "blocked_no_authoritative_count"
            elif state.queue:
                status = "blocked_pagination_budget"
            elif count != declared:
                status = "blocked_dynamic_loader" if state.dynamic_loader else "blocked_partial_list"
            elif any(c["number"] is None for c in state.chapters):
                status = "blocked_unknown_chapter_numbers"
            elif len({c["number"] for c in state.chapters}) != count:
                status = "blocked_duplicate_chapter_numbers"
            else:
                status = "inventory_complete"
        if count >= self.max_chapters and (not declared or count < declared):
            status = "blocked_chapter_limit"
        return {
            "schemaVersion": 1,
            "stage": "read_only_source_inventory",
            "mangaUrl": manga_url,
            "title": state.title,
            "coverUrl": state.cover_url,
            "declaredChapterCount": declared,
            "discoveredChapterCount": count,
            "pagesFetched": len(state.seen_pages),
            "pendingPageCount": len(state.queue),
            "sampledChapterPages": state.chapter_sample,
            "dynamicLoaderSeen": state.dynamic_loader,
            "unknownChapterNumbers": unknown_numbers,
            "duplicateChapterNumbers": len(duplicate_numbers),
            "duplicateNumberSamples": duplicate_numbers[:5],
            "status": status,
            "issues": state.issues,
            "allowPublish": False,
            "chapterLinks": state.chapters,
        }
