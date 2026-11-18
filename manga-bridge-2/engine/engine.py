"""Bounded, source-only collector. No access to WordPress DB or public image Worker."""
import base64
import hashlib
import http.client
import ipaddress
import json
import re
import socket
import ssl
import struct
import time
import urllib.parse
import sys
from pathlib import Path
from html.parser import HTMLParser

# Use the same chapter-list parser that passed the phase-2 source tests.
# This process only reads source HTML; publishing is not implemented here.
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from extractor.source import parse_html, chapter_number

PROXY_HOST = "img.lermangaonline.com.br"

class Blocked(Exception):
    pass

class Yield(Exception):
    pass

class Document(HTMLParser):
    VOID = {"img", "input", "meta", "link", "br", "hr", "source", "area", "embed", "wbr"}
    def __init__(self, text):
        super().__init__(convert_charrefs=True)
        self.nodes, self.stack = [], []
        self.raw = text
        self.feed(text)
    def handle_starttag(self, tag, attrs):
        node = {"tag": tag, "attrs": dict(attrs), "parents": self.stack[:], "text": ""}
        self.nodes.append(node)
        if tag not in self.VOID:
            self.stack.append(node)
    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if self.stack and self.stack[-1]["tag"] == tag:
            self.stack.pop()
    def handle_endtag(self, tag):
        for n in range(len(self.stack) - 1, -1, -1):
            if self.stack[n]["tag"] == tag:
                del self.stack[n:]
                break
    def handle_data(self, data):
        for node in self.stack:
            node["text"] += data
    def classes(self, node):
        return set(node["attrs"].get("class", "").split())
    def in_class(self, node, classes):
        return any(self.classes(parent) & classes for parent in node["parents"])
    def meta(self, name):
        return next((n["attrs"].get("content", "") for n in self.nodes
                     if n["tag"] == "meta" and (n["attrs"].get("property", "") or n["attrs"].get("name", "")).lower() == name), "")

def clean(text):
    return re.sub(r"\s+", " ", text).strip()

def source_link(base, href, kind):
    value = urllib.parse.urljoin(base, href)
    p, origin = urllib.parse.urlsplit(value), urllib.parse.urlsplit(base)
    if p.scheme != "https" or p.hostname != origin.hostname or p.username or p.password or p.query or p.fragment or p.port not in (None, 443):
        raise Blocked("foreign_source_link")
    if not re.fullmatch("/" + kind + "/[^/]+/", p.path):
        raise Blocked("invalid_source_path")
    return value

def content_image(url):
    p = urllib.parse.urlsplit(url)
    if p.scheme != "https" or not p.hostname or p.username or p.password or p.hostname.lower() == PROXY_HOST:
        return False
    path = urllib.parse.unquote(p.path).lower()
    return not re.search(r"(?:no[-_]?cover|no[-_]?image|placeholder|sem[-_]?capa|transparent|blank|favicon|emoji|avatar)", path)

def image_url(node, base):
    a = node["attrs"]
    return urllib.parse.urljoin(base, next((a.get(k) for k in ("data-src", "data-lazy-src", "data-original", "data-cfsrc", "src") if a.get(k) and not a[k].startswith("data:")), ""))

def chapter_links(doc, base):
    urls = []
    for n in doc.nodes:
        if n["tag"] != "a" or not n["attrs"].get("href"):
            continue
        if not (doc.in_class(n, {"chapters-list", "listing-chapters_wrap"}) or "chapter-link" in doc.classes(n)):
            continue
        value = source_link(base, n["attrs"]["href"], "capitulo")
        if value not in urls:
            urls.append(value)
    shared = [item["url"] for item in parse_html(doc.raw, base, base).chapter_links]
    if urls != shared:
        raise Blocked("chapter_parser_disagreement")
    return shared

def root_data(text, base):
    doc = Document(text)
    title = next((clean(n["text"]) for n in doc.nodes if n["tag"] == "h1" and "manga-title" in doc.classes(n)), "") or clean(doc.meta("og:title"))
    synopsis = next((clean(n["text"]) for n in doc.nodes if "synopsis-content" in doc.classes(n)), "") or clean(doc.meta("description"))
    covers = [image_url(n, base) for n in doc.nodes if n["tag"] == "img" and (
        "manga-cover-image" in doc.classes(n) or doc.in_class(n, {"manga-cover", "summary_image"}))]
    covers += [urllib.parse.urljoin(base, doc.meta("og:image"))] if doc.meta("og:image") else []
    cover = next((u for u in covers if content_image(u)), "")
    counts = [n for n in doc.nodes if "manga-chapters-count" in doc.classes(n)]
    count = re.search(r"([0-9][0-9.,]*)\s*cap[ií]tulos?", clean(counts[0]["text"]), re.I) if len(counts) == 1 else None
    if not title or len(title) > 300 or len(synopsis) < 40:
        raise Blocked("invalid_metadata")
    if not cover:
        raise Blocked("missing_real_cover")
    if not count:
        raise Blocked("unreliable_chapter_count")
    expected = int(re.sub(r"\D", "", count.group(1)))
    urls = chapter_links(doc, base)
    buttons = [n for n in doc.nodes if n["attrs"].get("id") == "sr-load-chapters"]
    loader = None
    if buttons:
        if len(buttons) != 1:
            raise Blocked("ambiguous_loader")
        attrs = buttons[0]["attrs"]
        config = None
        for n in doc.nodes:
            if n["tag"] != "script":
                continue
            code = n["text"]
            src = n["attrs"].get("src", "")
            if src.startswith("data:"):
                header, encoded = src.split(",", 1)
                code = base64.b64decode(encoded, validate=True).decode() if ";base64" in header else urllib.parse.unquote(encoded)
            found = re.search(r"(?:var|let|const)\s+srPerformance\s*=\s*(\{)", code)
            if found:
                config = json.JSONDecoder().raw_decode(code[found.start(1):])[0]
                break
        if not isinstance(config, dict):
            raise Blocked("unsupported_loader")
        rest = urllib.parse.urljoin(base, config.get("rest", ""))
        p = urllib.parse.urlsplit(rest)
        if p.scheme != "https" or p.hostname != urllib.parse.urlsplit(base).hostname or p.path.rstrip("/") != "/wp-json/slimeread/v1" or p.query:
            raise Blocked("unsafe_rest_endpoint")
        try:
            ident, offset, total = (int(attrs[k]) for k in ("data-manga", "data-offset", "data-total"))
        except (KeyError, ValueError):
            raise Blocked("invalid_loader") from None
        if ident < 1 or offset != len(urls) or total != expected:
            raise Blocked("loader_count_mismatch")
        loader = {"endpoint": rest.rstrip("/") + "/chapters/" + str(ident), "offset": offset, "nonce": str(config.get("nonce", ""))}
    if expected < 1 or len(urls) > expected:
        raise Blocked("invalid_inventory_count")
    return {"source_url": base, "title": title, "synopsis": synopsis, "cover_url": cover,
            "expected_chapters": expected, "chapter_urls": urls, "loader": loader}

def image_dimensions(data):
    if data.startswith(b"\x89PNG\r\n\x1a\n") and len(data) >= 24:
        return struct.unpack(">II", data[16:24])
    if data[:6] in (b"GIF87a", b"GIF89a") and len(data) >= 10:
        return struct.unpack("<HH", data[6:10])
    if data[:2] == b"\xff\xd8":
        pos = 2
        while pos + 4 <= len(data):
            if data[pos] != 255:
                pos += 1
                continue
            marker = data[pos+1]
            pos += 2
            if marker in (0xD8, 0xD9) or 0xD0 <= marker <= 0xD7:
                continue
            length = int.from_bytes(data[pos:pos+2], "big")
            if length < 2:
                break
            if marker in (0xC0, 0xC1, 0xC2, 0xC3) and pos + 7 <= len(data):
                h, w = struct.unpack(">HH", data[pos+3:pos+7])
                return w, h
            pos += length
    if len(data) >= 30 and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        if data[12:16] == b"VP8X":
            return 1 + int.from_bytes(data[24:27], "little"), 1 + int.from_bytes(data[27:30], "little")
        if data[12:16] == b"VP8L" and data[20] == 0x2F:
            bits = int.from_bytes(data[21:25], "little")
            return (bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1
        if data[12:16] == b"VP8 " and data[23:26] == b"\x9d\x01\x2a":
            return int.from_bytes(data[26:28], "little") & 0x3FFF, int.from_bytes(data[28:30], "little") & 0x3FFF
    raise Blocked("unsupported_or_invalid_image_header")

class SafeHTTP:
    def __init__(self, source_hosts, media_hosts, max_requests=300, seconds=420, heartbeat=None, min_gap=0.15):
        if (not isinstance(max_requests, int) or not 0 <= max_requests <= 300 or
                not 30 <= seconds <= 480 or not 0 <= min_gap <= 2):
            raise ValueError("invalid_network_budget")
        self.source_hosts, self.media_hosts = set(source_hosts), set(media_hosts)
        self.requests = 0
        self.max_requests = max_requests
        self.deadline = time.monotonic() + seconds
        self.heartbeat = heartbeat
        self.last_heartbeat = time.monotonic()
        self.min_gap = min_gap
        self.last_request = 0.0
    def get(self, url, image=False, nonce=""):
        p = urllib.parse.urlsplit(url)
        allowed = self.media_hosts if image else self.source_hosts
        if p.scheme != "https" or not p.hostname or p.hostname.lower() not in allowed or p.hostname.lower() == PROXY_HOST or p.username or p.password or p.port not in (None, 443) or p.fragment:
            raise Blocked("url_not_allowed")
        if self.requests >= self.max_requests or time.monotonic() + 12 >= self.deadline:
            raise Yield("request_or_time_budget")
        if self.heartbeat and time.monotonic() - self.last_heartbeat >= 20:
            self.heartbeat()
            self.last_heartbeat = time.monotonic()
        answers = socket.getaddrinfo(p.hostname, 443, type=socket.SOCK_STREAM)
        addresses = list(dict.fromkeys(item[4][0] for item in answers))
        if not addresses or any(not ipaddress.ip_address(ip).is_global for ip in addresses):
            raise Blocked("non_public_address")
        # Gentle pacing; never increase concurrency to compensate for slow origins.
        if self.last_request:
            delay = self.min_gap - (time.monotonic() - self.last_request)
            if delay > 0:
                if time.monotonic() + delay + 12 >= self.deadline:
                    raise Yield("request_or_time_budget")
                time.sleep(delay)
        self.last_request = time.monotonic()
        self.requests += 1
        conn = http.client.HTTPSConnection(p.hostname, timeout=10, context=ssl.create_default_context())
        # Pin the checked address while preserving SNI/certificate hostname verification.
        conn._create_connection = lambda address, timeout=None, source_address=None: socket.create_connection((addresses[0], 443), timeout, source_address)
        headers = {"User-Agent": "MangaBridge2-Staging/0.1", "Accept": "image/*" if image else "text/html,application/json"}
        if image:
            headers["Range"] = "bytes=0-65535"
        if nonce:
            headers["X-WP-Nonce"] = nonce
        try:
            conn.request("GET", urllib.parse.urlunsplit(("", "", p.path or "/", p.query, "")), headers=headers)
            response = conn.getresponse()
            if response.status in (408, 425, 429) or response.status >= 500:
                raise Yield("temporary_source_failure")
            if response.status not in ((200, 206) if image else (200,)):
                raise Blocked("source_http_" + str(response.status))
            maximum = 65536 if image else 2 * 1024 * 1024
            raw = response.read(maximum + (0 if image else 1))
            if not image and len(raw) > maximum:
                raise Blocked("source_body_too_large")
            if image and not response.getheader("Content-Type", "").lower().startswith("image/"):
                raise Blocked("non_image_response")
            return raw
        finally:
            conn.close()

def publication_chapter(doc, url, pages):
    # Publication requires a source-declared page count, not just the links
    # we happened to see. The analysis-only mode remains more permissive.
    counts = []
    for node in doc.nodes:
        if node["attrs"].get("data-page-count") and doc.classes(node) & {"reading-content", "reader-pages", "chapter-content"}:
            counts.append(node["attrs"]["data-page-count"])
    meta = doc.meta("manga:page_count")
    if meta:
        counts.append(meta)
    if not counts or any(not re.fullmatch(r"[1-9][0-9]{0,2}", n) or int(n) != len(pages) for n in counts):
        raise Blocked("unproven_reader_page_count")
    label = doc.meta("og:title") or next((clean(n["text"]) for n in doc.nodes if n["tag"] == "h1"), "")
    number = chapter_number(label, url)
    if not number:
        raise Blocked("unproven_chapter_number")
    return {"number": number, "expected_pages": len(pages)}

def collect_work(url, http, checkpoint=None, progress=None, publication=False):
    state = checkpoint if isinstance(checkpoint, dict) else {}
    work = root_data(http.get(url).decode("utf-8", "replace"), url)
    if publication and work["expected_chapters"] > 20:
        raise Blocked("pilot_chapter_limit")
    while len(work["chapter_urls"]) < work["expected_chapters"]:
        loader = work["loader"]
        if not loader:
            raise Blocked("incomplete_inventory")
        old = loader["offset"]
        endpoint = loader["endpoint"] + "?" + urllib.parse.urlencode({"offset": old, "search": ""})
        data = json.loads(http.get(endpoint, nonce=loader["nonce"]))
        if not isinstance(data, dict) or data.get("total") != work["expected_chapters"] or not isinstance(data.get("offset"), int) or not isinstance(data.get("list"), str):
            raise Blocked("invalid_rest_response")
        links = chapter_links(Document('<ul class="chapters-list">' + data["list"] + '</ul>'), url)
        merged = list(dict.fromkeys(work["chapter_urls"] + links))
        if data["offset"] <= old or data["offset"] != len(merged) or len(merged) - len(work["chapter_urls"]) != data["offset"] - old:
            raise Blocked("nonadvancing_or_duplicate_inventory")
        work["chapter_urls"] = merged; loader["offset"] = data["offset"]
    identity = hashlib.sha256(json.dumps([url, work["cover_url"], work["chapter_urls"]], separators=(",", ":")).encode()).hexdigest()
    if state.get("inventory_hash") != identity or bool(state.get("publication")) != publication:
        state = {"inventory_hash": identity, "chapters": [], "images": {}, "publication": publication}
    state["work"] = {k: v for k, v in work.items() if k != "loader"}
    last_progress = [time.monotonic()]
    def save(force=False):
        if progress and (force or time.monotonic() - last_progress[0] >= 20):
            progress(state)
            last_progress[0] = time.monotonic()
    def probe(image):
        if not content_image(image):
            raise Blocked("invalid_content_image")
        if image not in state["images"]:
            if publication and len(state["images"]) >= 250:
                raise Blocked("pilot_image_limit")
            raw = http.get(image, image=True)
            width, height = image_dimensions(raw)
            if min(width, height) <= 0 or max(width, height) < 40:
                raise Blocked("invalid_image_dimensions")
            state["images"][image] = {"sha256_prefix": hashlib.sha256(raw).hexdigest(), "width": width, "height": height, "mode": "origin_range_header"}
        save()
    try:
        probe(work["cover_url"])
        completed = {c["url"] for c in state["chapters"]}
        for chapter_url in work["chapter_urls"]:
            if chapter_url in completed:
                continue
            doc = Document(http.get(chapter_url).decode("utf-8", "replace"))
            pages = []
            for node in doc.nodes:
                if node["tag"] == "img" and (doc.in_class(node, {"reading-content", "reader-pages", "chapter-content", "page-break"}) or doc.classes(node) & {"reader-page", "chapter-page"}):
                    candidate = image_url(node, chapter_url)
                    if candidate not in pages:
                        pages.append(candidate)
            if not pages:
                raise Blocked("no_reader_images")
            if publication and len(pages) > 100:
                raise Blocked("pilot_chapter_page_limit")
            extra = publication_chapter(doc, chapter_url, pages) if publication else {}
            if extra and any(c.get("number") == extra["number"] for c in state["chapters"]):
                raise Blocked("duplicate_chapter_number")
            for page in pages:
                probe(page)
            state["chapters"].append({"url": chapter_url, "pages": pages, **extra})
            completed.add(chapter_url)
            save(True)
        if len(state["chapters"]) != work["expected_chapters"]:
            raise Blocked("chapter_count_mismatch")
        state["complete"] = True
        return state
    except (Yield, TimeoutError, ConnectionError, OSError):
        save(True)
        raise
