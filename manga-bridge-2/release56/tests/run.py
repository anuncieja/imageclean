"""Exercise every runner with live signed WordPress requests and bounded synthetic HTTPS fixtures."""
import json
import os
from pathlib import Path
import re
import struct
import sys
from urllib.error import HTTPError
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src" / "engine"))
from stream import BatchClient, Cancelled, Yield, main
from urllib.parse import parse_qs, urlsplit
from protocol import TransportError

state = json.loads(Path(os.environ["MBI56_TEST_STATE"]).read_text())
metrics_file = Path(os.environ["MBI56_TEST_METRICS"])
metrics = json.loads(metrics_file.read_text()) if metrics_file.exists() else {
    "slices": 0, "http": 0, "worker": 0, "callbacks": 0, "renews": 0, "images": {}, "failure": False, "inventory_failure": False, "inventory_requests": 0,
}
os.environ["MBI2_JOB_ID"] = state["id"]
os.environ["MBI2_GENERATION"] = state["generation"]
base = "https://mangalivre.blog"
png = b"\x89PNG\r\n\x1a\n" + b"\x00\x00\x00\rIHDR" + struct.pack(">II", 64, 64) + b"\x08\x02\x00\x00\x00"


class LocalClient(BatchClient):
    def __init__(self, endpoint, key, job_id):
        super().__init__(endpoint, key, job_id, allow_loopback=True)

    def request(self, method, suffix="", data=None):
        metrics["callbacks"] += 1
        return super().request(method, suffix, data)

    def send(self, kind, **fields):
        answer = super().send(kind, **fields)
        if kind == "yield":
            metrics["renews"] += 1
        return answer


class FixtureHTTP:
    def __init__(self, source_hosts, media_hosts, heartbeat=None):
        assert source_hosts == {"mangalivre.blog"}
        self.requests = 0

    def get(self, url, image=False, nonce=""):
        if self.requests >= 300:
            raise Yield("request_or_time_budget")
        self.requests += 1
        metrics["http"] += 1
        assert url.startswith(base + "/") and "img.lermangaonline.com.br" not in url, url
        if image:
            assert re.search(r"/media/work-\d+-(cover|page-\d+-\d+)\.png$", url), url
            metrics["images"][url] = metrics["images"].get(url, 0) + 1
            return png
        if url == base + "/manga/":
            return ("<a href='/manga/mbi-existing/'>Existing</a><a href='/manga/mbi-bad/'>Bad</a>" +
                    "".join(f"<a href='/manga/mbi-work-{i}/'>Work</a>" for i in range(1, 97)) +
                    "<a href='/manga/page/2/'>2</a>").encode()
        if url == base + "/manga/page/2/":
            return "".join(f"<a href='/manga/mbi-work-{i}/'>Work</a>" for i in range(97, 109)).encode()
        if url == base + "/manga/mbi-existing/":
            raise AssertionError("Already-published title was collected")
        match = re.fullmatch(base + r"/manga/mbi-work-(\d+)/", url)
        if match or url.endswith("/mbi-bad/"):
            work = int(match[1]) if match else 0
            count = {4:176, 8:48, 32:22}.get(work, 1 + work % 3)
            cover = f"{base}/media/work-{work}-cover.png" if work else base + "/media/no-cover.png"
            def ch(n):
                return f'<li><a class="chapter-link" href="{base}/capitulo/mbi-{work}-chapter-{n}/">Capítulo {n}</a></li>'
            end = 29 if work == 4 else count
            chapters = "".join(ch(n) for n in range(1, end+1))
            loader = ""
            if work == 4:
                loader = (f'<button id="sr-load-chapters" data-manga="404" data-offset="{end}" data-total="{count}">Mais</button>'
                          f'<script>const srPerformance = {{"rest":"{base}/wp-json/slimeread/v1","nonce":"ci"}};</script>')
            return (f'<meta name="description" content="Uma sinopse sintética completa para validar a publicação por lote no catálogo.">'
                    f'<h1 class="manga-title">Manga Bridge volume fixture {work}</h1>'
                    f'<div class="manga-cover"><img data-src="{cover}"></div>'
                    f'<span class="manga-chapters-count">{count} capítulos</span><ul class="chapters-list">{chapters}</ul>{loader}').encode()
        if url.startswith(base + "/wp-json/slimeread/v1/chapters/404?"):
            assert nonce=="ci"
            offset=int(parse_qs(urlsplit(url).query)["offset"][0])
            metrics["inventory_requests"] += 1
            if not metrics["inventory_failure"] and offset>40:
                metrics["inventory_failure"]=True
                raise Yield("temporary_source_failure")
            stop=min(offset+24,176)
            links="".join(f'<li><a class="chapter-link" href="{base}/capitulo/mbi-4-chapter-{n}/">Capítulo {n}</a></li>' for n in range(offset+1,stop+1))
            return json.dumps({"total":176,"offset":stop,"list":links}).encode()
        match = re.fullmatch(base + r"/capitulo/mbi-(\d+)-chapter-(\d+)/", url)
        if match:
            work, chapter = map(int, match.groups())
            if work == 4 and chapter == 12 and not metrics["failure"]:
                metrics["failure"] = True
                raise Yield("temporary_source_failure")
            total = (130 if chapter == 1 else 15) if work == 4 else 3
            pages = "".join(f'<div class="chapter-image-container" data-page="{n}"><img class="chapter-image" '
                            f'data-src="{base}/media/work-{work}-page-{chapter}-{n}.png">'
                            f'<span class="page-number">{n}/{total}</span></div>' for n in range(1,total+1))
            return f'<meta property="og:title" content="Volume fixture Capítulo {chapter}"><div class="chapter-content">{pages}</div>'.encode()
        raise AssertionError("Unexpected origin request: " + url)


if metrics["slices"] == 0:
    try:
        LocalClient(os.environ["MBI2_WORDPRESS_ENDPOINT"], bytes(32), state["id"]).request("GET")
    except TransportError as exc:
        assert str(exc) == "callback_http_401"
    else:
        raise AssertionError("Bad signature accepted")
    before = metrics["http"]
    os.environ["MBI2_GENERATION"] = str(uuid.uuid4())
    try:
        main(LocalClient, FixtureHTTP)
    except Cancelled:
        assert before == metrics["http"]
    else:
        raise AssertionError("Stale generation ran")
    os.environ["MBI2_GENERATION"] = state["generation"]

main(LocalClient, FixtureHTTP)
metrics["slices"] += 1
metrics_file.write_text(json.dumps(metrics))
