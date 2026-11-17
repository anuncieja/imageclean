"""Read-only HTML inventory: no assumed REST routes and no WordPress writes."""
from __future__ import annotations

from dataclasses import dataclass, field
from html.parser import HTMLParser
from ipaddress import ip_address
from typing import Optional
from urllib.parse import parse_qs, urljoin, urlsplit, urlunsplit
import re

MAX_URL_LENGTH = 2048
CONTAINER_CLASSES = {"chapters-list", "manga-chapters", "wp-manga-chapter", "listing-chapters_wrap"}
COVER_CLASSES = {"manga-cover", "manga-cover-image", "summary_image", "tab-thumb"}
IMAGE_CLASSES = {"chapter-images", "reading-content", "page-break", "chapter-content", "wp-manga-chapter-img", "reader-images"}
MANGA_PATH = re.compile(r"^/manga/([^/]+)/?$", re.I)
CHAPTER_PATH = re.compile(r"^/capitulo/[^/]+/?$", re.I)
CHAPTER_NUMBER = re.compile(r"(?:cap[ií]tulo|chapter|cap[.\s:-]*|ch[.\s:-]*)\s*(\d+(?:[.,]\d+)?)", re.I)
COUNT = re.compile(r"(\d[\d.,]*)\s*cap[ií]tulo", re.I)
PAGE_PATH = re.compile(r"^(.+)/page/([1-9]\d*)/?$", re.I)
PAGE_PARAMS = {"page", "paged", "chapter_page", "chapters_page", "cpage"}
SELF_CLOSING = {"meta", "link", "br", "hr", "img", "input", "source", "area", "base", "embed", "param", "wbr"}


def safe_url(value: str, base: str = "", host: str = "") -> Optional[str]:
    if not isinstance(value, str) or not value.strip() or len(value) > MAX_URL_LENGTH:
        return None
    try:
        parsed = urlsplit(urljoin(base, value.strip()))
        hostname = (parsed.hostname or "").lower().rstrip(".")
        if (parsed.scheme != "https" or not hostname or parsed.username or parsed.password
                or parsed.port not in (None, 443) or any(c.isspace() for c in value)):
            return None
        try:
            ip_address(hostname)
            return None
        except ValueError:
            pass
        if hostname == "localhost" or hostname.endswith((".localhost", ".local", ".internal")):
            return None
        if host and hostname != host.lower().rstrip("."):
            return None
        return urlunsplit(("https", parsed.netloc.lower(), parsed.path or "/", parsed.query, ""))
    except ValueError:
        return None


def is_chapter_url(url: str, manga_url: str) -> bool:
    try:
        path = urlsplit(url).path
        root = MANGA_PATH.match(urlsplit(manga_url).path)
        if CHAPTER_PATH.fullmatch(path):
            return True
        return bool(root and re.fullmatch(r"/manga/" + re.escape(root.group(1)) + r"/capitulo-[^/]+/?", path, re.I))
    except (ValueError, AttributeError):
        return False


def is_pagination_url(url: str, manga_url: str) -> bool:
    """A page must identify the same manga, never a guessed page or external API."""
    path = urlsplit(url).path.rstrip("/")
    root = urlsplit(manga_url).path.rstrip("/")
    if PAGE_PATH.fullmatch(path):
        return PAGE_PATH.fullmatch(path).group(1).rstrip("/") == root
    if path != root:
        return False
    params = parse_qs(urlsplit(url).query, keep_blank_values=True)
    if len(params) != 1:
        return False
    key, values = next(iter(params.items()))
    return key in PAGE_PARAMS and len(values) == 1 and bool(re.fullmatch(r"[1-9]\d*", values[0]))


def chapter_number(label: str, url: str) -> Optional[str]:
    hit = CHAPTER_NUMBER.search(label or "")
    if not hit:
        hit = re.search(r"cap[ií]tulo[-_/](\d+(?:[.,]\d+)?)", url, re.I)
    if not hit:
        return None
    value = hit.group(1).replace(",", ".")
    if len(value) > 16:
        return None
    return value


def image_attr(attrs: dict) -> Optional[str]:
    for key in ("data-lazy-src", "data-src", "data-original", "data-url", "src"):
        value = attrs.get(key, "").strip()
        if value and not value.startswith(("data:", "blob:")):
            return value
    for key in ("data-srcset", "srcset"):
        parts = attrs.get(key, "").split(",")
        if parts and parts[0].strip():
            return parts[0].strip().split()[0]
    return None


@dataclass
class PageSnapshot:
    title: str = ""
    cover_url: str = ""
    reported_count: int = 0
    chapter_links: list[dict] = field(default_factory=list)
    chapter_images: list[str] = field(default_factory=list)
    next_pages: list[str] = field(default_factory=list)
    dynamic_loader: bool = False


class SourceHTML(HTMLParser):
    """Scope chapter anchors to chapter lists to avoid ads and recommendations."""
    def __init__(self, source_url: str, manga_url: str):
        super().__init__(convert_charrefs=True)
        self.source_url = source_url
        self.manga_url = manga_url
        self.host = (urlsplit(manga_url).hostname or "").lower()
        self.result = PageSnapshot()
        self.stack: list[tuple[str, set[str]]] = []
        self.anchor: Optional[dict] = None
        self.heading = False
        self.counter_depth = 0
        self.counter_parts: list[str] = []
        self.h1_parts: list[str] = []
        self._page_seen = set()
        self._chapter_seen = set()
        self._image_seen = set()
        self._og_title = ""
        self._og_image = ""

    def _in(self, classes: set[str]) -> bool:
        return any(bool(klass & classes) for _, klass in self.stack)

    def _push_next(self, value: str):
        url = safe_url(value, self.source_url, self.host)
        if url and is_pagination_url(url, self.manga_url) and url != self.source_url and url not in self._page_seen:
            self.result.next_pages.append(url)
            self._page_seen.add(url)

    def handle_starttag(self, tag: str, attrs_list: list[tuple[str, Optional[str]]]):
        tag = tag.lower()
        attrs = {key.lower(): (value or "") for key, value in attrs_list}
        classes = set(attrs.get("class", "").lower().split())
        parent_container = self._in(CONTAINER_CLASSES)
        parent_images = self._in(IMAGE_CLASSES)
        parent_cover = self._in(COVER_CLASSES)
        if tag == "meta":
            k = (attrs.get("property") or attrs.get("name") or "").lower()
            if k == "og:title":
                self._og_title = attrs.get("content", "")
            if k == "og:image":
                self._og_image = attrs.get("content", "")
        if tag == "h1" and ("manga-title" in classes or not self.result.title):
            self.heading = True
            self.h1_parts.clear()
        if "manga-chapters-count" in classes:
            self.counter_depth = len(self.stack) + 1
            self.counter_parts.clear()
        if tag == "a":
            raw = attrs.get("href", "")
            url = safe_url(raw, self.source_url, self.host)
            in_list = parent_container or bool(classes & CONTAINER_CLASSES) or "chapter-link" in classes
            self.anchor = {"url": url if in_list else None, "text": []}
            if url:
                if "next" in attrs.get("rel", "").lower().split() or ("pagination" in classes) or ("page-numbers" in classes):
                    self._push_next(raw)
                elif is_pagination_url(url, self.manga_url) and self._in({"pagination", "chapter-pagination", "page-numbers"}):
                    self._push_next(raw)
        if tag in {"button", "a"}:
            text = " ".join([attrs.get("aria-label", ""), attrs.get("title", ""), attrs.get("class", "")]).lower()
            if "load-more" in text or "carregar mais" in text or "show-more" in text or "loadmore" in text:
                self.result.dynamic_loader = True
            for key in ("data-next-url", "data-next-page-url", "data-load-url"):
                if attrs.get(key):
                    self._push_next(attrs[key])
        if tag in {"img", "source"}:
            raw = image_attr(attrs)
            url = safe_url(raw or "", self.source_url)
            if tag == "img" and url and (parent_cover or bool(classes & COVER_CLASSES)) and not self.result.cover_url:
                self.result.cover_url = url
            if url and (parent_images or bool(classes & IMAGE_CLASSES)) and url not in self._image_seen:
                self.result.chapter_images.append(url)
                self._image_seen.add(url)
        if tag not in SELF_CLOSING:
            self.stack.append((tag, classes))

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_data(self, data: str):
        if self.heading:
            self.h1_parts.append(data)
        if self.counter_depth:
            self.counter_parts.append(data)
        if self.anchor is not None:
            self.anchor["text"].append(data)
        if re.search(r"carregar\s+mais\s+cap[ií]tulos", data, re.I):
            self.result.dynamic_loader = True

    def handle_endtag(self, tag: str):
        tag = tag.lower()
        if tag == "a" and self.anchor:
            url = self.anchor["url"]
            text = " ".join(self.anchor["text"]).strip()
            if url and is_chapter_url(url, self.manga_url) and url not in self._chapter_seen:
                self.result.chapter_links.append({"url": url, "number": chapter_number(text, url), "label": text[:160]})
                self._chapter_seen.add(url)
            self.anchor = None
        if tag == "h1" and self.heading:
            label = " ".join(self.h1_parts).strip()
            if label and not self.result.title:
                self.result.title = re.sub(r"\s+", " ", label)[:250]
            self.heading = False
        # Finish count at container close, not when its nested children close.
        if self.counter_depth and len(self.stack) == self.counter_depth and self.stack[-1][0] == tag:
            count = COUNT.search(" ".join(self.counter_parts))
            if count:
                try:
                    self.result.reported_count = int(re.sub(r"\D", "", count.group(1)))
                except ValueError:
                    pass
            self.counter_depth = 0
        if self.stack:
            # Tolerate malformed upstream HTML without allowing it to expand scope forever.
            for i in range(len(self.stack) - 1, -1, -1):
                if self.stack[i][0] == tag:
                    del self.stack[i:]
                    break

    def snapshot(self) -> PageSnapshot:
        if not self.result.title and self._og_title:
            self.result.title = re.sub(r"\s*[|–-]\s*Manga\s*Livre.*$", "", self._og_title, flags=re.I).strip()[:250]
        if not self.result.cover_url:
            self.result.cover_url = safe_url(self._og_image, self.source_url) or ""
        return self.result


def parse_html(html: str, source_url: str, manga_url: str) -> PageSnapshot:
    parser = SourceHTML(source_url, manga_url)
    parser.feed(html)
    parser.close()
    return parser.snapshot()
