"""Collect a finite catalogue in resumable, signed packets without work-size quotas."""
import collections
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import sys
import time
import urllib.parse
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "release55" / "src" / "engine"))
from batch import BatchClient, SOURCES
from engine import (Blocked, Document, SafeHTTP, Yield, chapter_links, chapter_number, clean,
                    content_image, image_dimensions, image_url, root_data)
from protocol import Cancelled, TransportError, key_bytes

PAGE_PACKET = 32
IMAGE_PACKET = 64
WORKS_PER_SLICE = 12
TRANSIENT = (Yield, TimeoutError, ConnectionError, socket.gaierror, OSError)


def digest(values):
    return hashlib.sha256(json.dumps(values, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def catalogue(html, base, page):
    doc = Document(html)
    host = urllib.parse.urlsplit(base).hostname
    anchors, more = {}, False
    for n in doc.nodes:
        href = n["attrs"].get("href", "") if n["tag"] == "a" else ""
        if not href:
            continue
        try:
            dst = urllib.parse.urlsplit(urllib.parse.urljoin(base, href))
            if (dst.hostname != host or dst.scheme != "https" or dst.port is not None or dst.username or dst.password
                    or dst.query or dst.fragment):
                continue
        except ValueError:
            continue
        paging = re.fullmatch(r"/manga/page/([1-9][0-9]{0,18})/?", dst.path)
        if paging:
            more = more or int(paging[1]) > page
        elif re.fullmatch(r"/manga/[^/]+/?", dst.path):
            url = "https://" + host + dst.path.rstrip("/") + "/"
            if len(url) <= 2048:
                anchors[url] = None
    return list(anchors), more


def reader(html, url):
    doc = Document(html)
    wrappers = {id(n): n for n in doc.nodes if "chapter-image-container" in doc.classes(n)
                and "data-page" in n["attrs"]}
    by_wrapper = {k: ([], []) for k in wrappers}
    counts = []
    for n in doc.nodes:
        classes = doc.classes(n)
        if "data-page-count" in n["attrs"] and classes & {"reading-content", "reader-pages", "chapter-content"}:
            counts.append(n["attrs"]["data-page-count"])
        if wrappers:
            k = 0 if n["tag"] == "img" and "chapter-image" in classes else 1 if "page-number" in classes else None
            if k is not None:
                for parent in n["parents"]:
                    if id(parent) in by_wrapper:
                        by_wrapper[id(parent)][k].append(n)
    if doc.meta("manga:page_count"):
        counts.append(doc.meta("manga:page_count"))
    if wrappers:
        pages = {}
        for key, wrapper in wrappers.items():
            imgs, labels = by_wrapper[key]
            n = wrapper["attrs"]["data-page"]
            if len(imgs) != 1 or len(labels) != 1 or not re.fullmatch(r"[1-9][0-9]{0,18}", n or ""):
                raise Blocked("unproven_reader_page_count")
            m = re.fullmatch(r"([1-9][0-9]{0,18})\s*/\s*([1-9][0-9]{0,18})", clean(labels[0]["text"]))
            if not m or m[1] != n or int(n) in pages:
                raise Blocked("unproven_reader_page_count")
            counts.append(m[2])
            pages[int(n)] = image_url(imgs[0], url)
        if len(pages) != max(pages):
            raise Blocked("unproven_reader_page_count")
        links = [pages[k] for k in sorted(pages)]
    else:
        links = []
        for n in doc.nodes:
            if n["tag"] == "img" and (doc.in_class(n, {"reading-content", "reader-pages", "chapter-content", "page-break"})
                                      or doc.classes(n) & {"reader-page", "chapter-page"}):
                links.append(image_url(n, url))
    if (not links or not counts or len(set(links)) != len(links)
            or any(not re.fullmatch(r"[1-9][0-9]{0,18}", x or "") or int(x) != len(links) for x in counts)):
        raise Blocked("unproven_reader_page_count")
    title = doc.meta("og:title") or next((clean(n["text"]) for n in doc.nodes if n["tag"] == "h1"), "")
    number = chapter_number(title, url)
    if not number:
        raise Blocked("unproven_chapter_number")
    return links, number


class WorkClosed(Exception):
    pass


class Runner:
    def __init__(self, client, start, make_http):
        self.client = client
        self.view = start
        self.contact = time.monotonic()
        self.host = start["origin"]
        if self.host not in SOURCES:
            raise TransportError("invalid_catalogue_origin")
        media = SOURCES | {x.strip().lower() for x in os.environ.get("MBI2_ALLOWED_MEDIA_HOSTS", "").split(",") if x.strip()}
        self.http = make_http({self.host}, media, heartbeat=self.heartbeat)
        self.catalogue_cache = None
        self.image_cache = collections.OrderedDict()

    def send(self, action, **fields):
        self.view = self.client.send(action, **fields)
        self.contact = time.monotonic()
        return self.view

    def heartbeat(self):
        if time.monotonic() - self.contact > 55:
            self.send("heartbeat")

    def candidate_event(self, name, url, **data):
        view = self.send(name, candidate=url, **data)
        if view["state"] != "running" or view.get("candidate") != url:
            raise WorkClosed()

    def proof(self, url):
        if not content_image(url):
            raise Blocked("invalid_content_image")
        if url in self.image_cache:
            self.image_cache.move_to_end(url)
            return self.image_cache[url]
        raw = self.http.get(url, image=True)
        width, height = image_dimensions(raw)
        if min(width, height) < 1 or max(width, height) < 40:
            raise Blocked("invalid_image_dimensions")
        result = {"sha256_prefix": hashlib.sha256(raw).hexdigest(), "width": width, "height": height,
                  "mode": "origin_range_header"}
        self.image_cache[url] = result
        if len(self.image_cache) > 1024:
            self.image_cache.popitem(last=False)
        return result

    def discover(self):
        page, offset = self.view["page"], self.view["page_offset"]
        base = "https://" + self.host + "/manga/"
        if self.catalogue_cache is None or self.catalogue_cache[0] != page:
            try:
                urls, more = catalogue(self.http.get(base + (f"page/{page}/" if page > 1 else "")).decode("utf-8", "replace"), base, page)
            except Blocked as exc:
                if str(exc) != "source_http_404" or page < 2:
                    raise
                urls, more = [], False
            self.catalogue_cache = (page, urls, more, digest(urls))
        _, urls, more, signature = self.catalogue_cache
        packet = urls[offset:offset + PAGE_PACKET]
        self.send("page", page=page, offset=offset, signature=signature, urls=packet,
                  more=more, final=offset + len(packet) >= len(urls))

    def inventory(self, url):
        saved = self.view.get("stream")
        if saved and saved["inventory"] == saved["expected"]:
            return
        root = root_data(self.http.get(url).decode("utf-8", "replace"), url)
        if not saved:
            work = {key: root[key] for key in ("source_url", "title", "synopsis", "cover_url", "expected_chapters")}
            cover = root["cover_url"]
            self.candidate_event("stream_open", url, work=work, proofs={cover: self.proof(cover)})
            saved = self.view["stream"]
        if saved["expected"] != root["expected_chapters"] or saved["cover"] != root["cover_url"]:
            raise Blocked("inventory_changed_during_collection")
        initial = root["chapter_urls"]
        saved_offset = saved["inventory"]
        if saved_offset and saved_offset <= len(initial) and initial[saved_offset - 1] != saved["tip"]:
            raise Blocked("inventory_changed_during_collection")
        pending = []
        while self.view["stream"]["inventory"] < root["expected_chapters"]:
            offset = self.view["stream"]["inventory"]
            if not pending and offset < len(initial):
                pending = initial[offset:]
            if not pending:
                loader = root["loader"]
                if not loader:
                    raise Blocked("incomplete_inventory")
                # Read an overlapping item on resume outside the initial template.
                # The saved boundary proves the origin did not shift the accepted window.
                overlap = offset == saved_offset and offset > len(initial)
                requested = offset - 1 if overlap else offset
                endpoint = loader["endpoint"] + "?" + urllib.parse.urlencode({"offset": requested, "search": ""})
                data = json.loads(self.http.get(endpoint, nonce=loader["nonce"]))
                if (not isinstance(data, dict) or data.get("total") != root["expected_chapters"]
                        or not isinstance(data.get("offset"), int) or not isinstance(data.get("list"), str)):
                    raise Blocked("invalid_rest_response")
                pending = chapter_links(Document('<ul class="chapters-list">' + data["list"] + '</ul>'), url)
                if not pending or data["offset"] != requested + len(pending) or data["offset"] > root["expected_chapters"]:
                    raise Blocked("nonadvancing_or_duplicate_inventory")
                if overlap:
                    if pending[0] != saved["tip"]:
                        raise Blocked("inventory_changed_during_collection")
                    pending = pending[1:]
                    saved_offset = -1
                    if not pending:
                        continue
            self.candidate_event("stream_inventory", url, offset=offset, urls=pending[:PAGE_PACKET])
            pending = pending[PAGE_PACKET:]

    def pages(self, candidate):
        while self.view["stream"]["checked"] < self.view["stream"]["expected"]:
            start = self.view["stream"]["checked"]
            urls = self.view.get("next_urls", [])
            if not urls:
                raise TransportError("missing_inventory_packet")
            parts, proofs, filled = [], {}, 0

            def flush():
                nonlocal parts, proofs, filled
                if not parts:
                    return
                self.candidate_event("stream_pages", candidate, parts=parts, proofs=proofs)
                parts, proofs, filled = [], {}, 0

            try:
                for idx, url in enumerate(urls, start):
                    pages, number = reader(self.http.get(url).decode("utf-8", "replace"), url)
                    identity = digest([number, pages])
                    partial = self.view["stream"].get("reader") if idx == self.view["stream"]["checked"] else None
                    if partial and partial["identity"] != identity:
                        raise Blocked("reader_changed_during_collection")
                    offset = partial["have"] if partial else 0
                    block = None
                    for i in range(offset, len(pages)):
                        if filled >= IMAGE_PACKET:
                            flush()
                            block = None
                        image = pages[i]
                        receipt = self.proof(image)
                        if block is None:
                            block = {"index": idx, "url": url, "number": number, "total": len(pages),
                                     "identity": identity, "offset": i, "pages": []}
                            parts.append(block)
                        proofs[image] = receipt
                        block["pages"].append(image)
                        filled += 1
                flush()
            except TRANSIENT:
                flush()
                raise

    def run(self):
        self.send("claim")
        works = 0
        while self.view["state"] == "running":
            candidate = self.view.get("candidate", "")
            if works >= WORKS_PER_SLICE:
                self.send("yield", retry=False)
                break
            try:
                if not candidate:
                    self.discover()
                    continue
                self.inventory(candidate)
                self.pages(candidate)
                self.send("stream_seal", candidate=candidate)
            except WorkClosed:
                pass
            except TRANSIENT as exc:
                self.send("yield", retry=str(exc) != "request_or_time_budget")
                break
            except (Blocked, ValueError, KeyError) as exc:
                if not candidate:
                    self.send("yield", retry=True)
                    break
                self.send("reject", candidate=candidate, reason=str(exc) if isinstance(exc, Blocked) else "source_structure_error")
            works += 1
        print(json.dumps({"state": self.view["state"], "slices": self.view.get("slices"), "published": self.view.get("published"),
                          "target": self.view.get("target"), "origin_http": self.http.requests, "worker_http": 0}))


def main(make_client=BatchClient, make_http=SafeHTTP):
    job, generation = os.environ["MBI2_JOB_ID"], os.environ["MBI2_GENERATION"]
    uuid.UUID(job)
    uuid.UUID(generation)
    client = make_client(os.environ["MBI2_WORDPRESS_ENDPOINT"], key_bytes(os.environ["MBI2_HMAC_KEY_B64"]), job)
    client.generation = generation
    start = client.request("GET")
    if start.get("generation") != generation or start.get("state") != "dispatching":
        raise Cancelled("stale_before_claim")
    if "page_offset" not in start:
        raise TransportError("unexpected_batch_protocol")
    Runner(client, start, make_http).run()


if __name__ == "__main__":
    try:
        main()
    except Cancelled:
        print('{"state":"stale_or_stopped"}')
    except (TransportError, OSError, ValueError, KeyError):
        print('{"state":"transport_or_configuration_failure"}')
        sys.exit(1)
