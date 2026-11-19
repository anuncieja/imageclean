"""Run the real engine and signed client against disposable WordPress and a synthetic origin."""
import json
import os
from pathlib import Path
import struct
import sys
import urllib.error

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src" / "engine"))
from protocol import Client, Cancelled
import run

data = json.loads(Path(os.environ["MBI54_JOB_FILE"]).read_text())
source = data["source"]
slug = data["slug"]
urls = [f"https://mangalivre.blog/capitulo/{slug}-{n}/" for n in (1, 2)]
cover = f"https://mangalivre.blog/media/{slug}-cover.png"
page = f"https://mangalivre.blog/media/{slug}-shared-page.png"
second = f"https://mangalivre.blog/media/{slug}-second.png"
loader = "https://mangalivre.blog/wp-json/slimeread/v1/chapters/314?offset=1&search="
os.environ["MBI2_JOB_ID"] = data["id"]
os.environ["MBI2_GENERATION"] = data["old"] if data["scenario"] == "wrong_generation" else data["generation"]
os.environ["MBI2_WORDPRESS_ENDPOINT"] = "http://127.0.0.1:8085/wp-json/mbi2/v1"

def link(num):
    return f'<li><a class="chapter-link" href="{urls[num - 1]}">Capítulo {num}</a></li>'

html = f"""
<meta name="description" content="Uma sinopse sintética completa que exercita a integração e a publicação de um mangá inédito pelo WordPress.">
<h1 class="manga-title">Manga Bridge CI {slug}</h1>
<div class="manga-cover"><img data-src="{cover}" src="https://mangalivre.blog/placeholder.png"></div>
<span class="manga-chapters-count">2 capítulos</span>
<ul class="chapters-list">{link(1)}</ul>
<button id="sr-load-chapters" data-manga="314" data-offset="1" data-total="2">Mais</button>
<script>const srPerformance = {{"rest": "https://mangalivre.blog/wp-json/slimeread/v1", "nonce": "ci"}};</script>
"""
png = b"\x89PNG\r\n\x1a\n" + b"\x00\x00\x00\rIHDR" + struct.pack(">II", 64, 64) + b"\x08\x02\x00\x00\x00"
network = []
callbacks = []

class FixtureHTTP:
    def __init__(self, *args, **kwargs):
        self.requests = 0

    def get(self, url, image=False, nonce=""):
        self.requests += 1
        network.append((url, image))
        if image and url in (cover, page, second):
            return png
        if not image and url == source:
            return html.encode()
        if not image and url == loader:
            assert nonce == "ci"
            return json.dumps({"total": 2, "offset": 2, "list": link(2)}).encode()
        if not image and url in urls:
            chapter = urls.index(url) + 1
            count = 3 if chapter == 2 and data["scenario"] == "incomplete" else chapter
            images = f'<img data-src="{page}">' + (f'<img src="{second}">' if chapter == 2 else "")
            return f'<meta property="og:title" content="CI Capítulo {chapter}"><div class="reading-content" data-page-count="{count}">{images}</div>'.encode()
        raise AssertionError("Unexpected network request")

class CountingClient(Client):
    def __init__(self, endpoint, key, jid):
        super().__init__(endpoint, key, jid, allow_loopback=True)

    def request(self, method, suffix="", data=None):
        callbacks.append(method)
        return super().request(method, suffix, data)

if data["scenario"] == "success":
    try:
        CountingClient(os.environ["MBI2_WORDPRESS_ENDPOINT"], bytes(32), data["id"]).get()
    except run.TransportError as exc:
        assert str(exc) == "callback_http_401"
    else:
        raise AssertionError("invalid signature was accepted")
    callbacks.clear()

try:
    run.main(CountingClient, FixtureHTTP)
except Cancelled:
    assert data["scenario"] == "wrong_generation"
    assert not network
    print("PASS stale runner stopped before any source request")
else:
    assert data["scenario"] != "wrong_generation"
    assert callbacks == ["GET", "POST", "POST"], callbacks
    assert (loader, False) in network
    assert network.count((page, True)) == 1
    assert all("img.lermangaonline.com.br" not in url for url, _ in network)
    print(f"PASS dynamic chapters, shared image checked once, zero Worker calls; {len(network)} origin HTTP and {len(callbacks)} WordPress HTTP")
