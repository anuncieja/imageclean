"""Bounded runner for requested quantities. Only WordPress acknowledgements count as publications."""
import json
import os
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import uuid

from engine import Blocked, Document, SafeHTTP, Yield, collect_work
from protocol import Cancelled, Client, TransportError, key_bytes

SOURCES = {"mangalivre.blog", "www.mangalivre.blog", "mangalivre.to", "www.mangalivre.to"}
CHAPTERS = 120
IMAGES = 2400
MAX_CHECKPOINT = 950000
ITEMS_PER_RUN = 12


class BatchClient(Client):
    resource = "batches"
    maximum = 1572864

    def send(self, kind, **fields):
        data = dict(kind=kind, generation=self.generation, attempt=self.attempt,
                    event_id=str(uuid.uuid4()), **fields)
        for attempt in range(3):
            try:
                state = self.request("POST", "/events", data)
            except (urllib.error.URLError, OSError, TimeoutError, TransportError):
                if attempt == 2:
                    raise
                time.sleep(0.5 * (2 ** attempt))
                continue
            if state.get("id") != self.job_id or state.get("generation") != self.generation:
                raise Cancelled("generation_changed")
            return state
        raise AssertionError("unreachable")


def catalogue_urls(html, host, page):
    doc = Document(html)
    works, more = {}, False
    base = "https://" + host + "/manga/"
    for n in doc.nodes:
        if n["tag"] != "a" or not n["attrs"].get("href"):
            continue
        value = urllib.parse.urlsplit(urllib.parse.urljoin(base, n["attrs"]["href"]))
        if (value.scheme != "https" or value.hostname != host or value.query or value.fragment
                or value.username or value.password or value.port is not None):
            continue
        paging = re.fullmatch(r"/manga/page/([1-9][0-9]*)/?", value.path)
        if paging:
            more = more or int(paging[1]) > page
            continue
        if re.fullmatch(r"/manga/[^/]+/?", value.path):
            url = "https://" + host + value.path.rstrip("/") + "/"
            if len(url) <= 2048:
                works[url] = None
    if len(works) > 80:
        raise Blocked("catalogue_page_too_large")
    return list(works), more


def main(make_client=BatchClient, make_http=SafeHTTP):
    job_id, generation = os.environ["MBI2_JOB_ID"], os.environ["MBI2_GENERATION"]
    uuid.UUID(job_id)
    uuid.UUID(generation)
    client = make_client(os.environ["MBI2_WORDPRESS_ENDPOINT"], key_bytes(os.environ["MBI2_HMAC_KEY_B64"]), job_id)
    client.generation = generation
    start = client.request("GET")
    if start.get("generation") != generation or start.get("state") != "dispatching":
        raise Cancelled("stale_before_claim")
    host = start["origin"]
    if host not in SOURCES:
        raise TransportError("invalid_catalogue_origin")
    view = client.send("claim")
    candidate_checkpoint = start.get("checkpoint", {})
    media = {v.strip().lower() for v in os.environ.get("MBI2_ALLOWED_MEDIA_HOSTS", "").split(",") if v.strip()} | {host}
    last_contact = [time.monotonic()]

    def send(kind, **fields):
        response = client.send(kind, **fields)
        last_contact[0] = time.monotonic()
        return response

    def heartbeat():
        if time.monotonic() - last_contact[0] > 55:
            send("heartbeat")

    http = make_http({host}, media, heartbeat=heartbeat)
    processed = 0
    while view.get("state") == "running":
        url = view.get("candidate", "")
        if processed >= ITEMS_PER_RUN:
            view = send("yield", retry=False)
            break
        if not url:
            page = view["page"]
            catalog = f"https://{host}/manga/" + (f"page/{page}/" if page > 1 else "")
            try:
                urls, more = catalogue_urls(http.get(catalog).decode("utf-8", "replace"), host, page)
            except Blocked as exc:
                if str(exc) == "source_http_404" and page > 1:
                    urls, more = [], False
                else:
                    view = send("yield", retry=True)
                    break
            except (Yield, TimeoutError, ConnectionError, socket.gaierror, OSError) as exc:
                view = send("yield", retry=str(exc) != "request_or_time_budget")
                break
            view = send("page", page=page, urls=urls, more=more)
            candidate_checkpoint = {}
            continue

        def checkpoint(value):
            if len(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()) > MAX_CHECKPOINT:
                raise Blocked("work_payload_limit")
            send("checkpoint", candidate=url, checkpoint=value)

        try:
            result = collect_work(url, http, candidate_checkpoint, checkpoint, True, CHAPTERS, IMAGES)
            if len(json.dumps(result, ensure_ascii=False, separators=(",", ":")).encode()) > MAX_CHECKPOINT:
                raise Blocked("work_payload_limit")
        except (Yield, TimeoutError, ConnectionError, socket.gaierror, OSError) as exc:
            view = send("yield", retry=str(exc) != "request_or_time_budget")
            break
        except (Blocked, ValueError, KeyError) as exc:
            view = send("reject", candidate=url, reason=str(exc) if isinstance(exc, Blocked) else "source_structure_error")
        else:
            view = send("ready", candidate=url, checkpoint=result)
        candidate_checkpoint = {}
        processed += 1
    print(json.dumps({"state": view.get("state"), "slices": view.get("slices"), "published": view.get("published"),
                      "target": view.get("target"), "origin_http": http.requests, "worker_http": 0}))


if __name__ == "__main__":
    try:
        main()
    except Cancelled:
        print('{"state":"stale_or_stopped"}')
    except (TransportError, OSError, ValueError, KeyError):
        print('{"state":"transport_or_configuration_failure"}')
        sys.exit(1)
