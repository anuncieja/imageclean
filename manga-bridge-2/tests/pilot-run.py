"""Run the real Actions engine and HMAC client with a synthetic origin."""
import json
import os
from pathlib import Path
import struct
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "engine"))
from protocol import Client
import run

data = json.loads(Path("/tmp/mbi2-pilot-job.json").read_text())
source = data["source"]
chapter = "https://mangalivre.blog/capitulo/" + data["slug"] + "-1/"
cover = "https://mangalivre.blog/media/" + data["slug"] + "-cover.png"
page = "https://mangalivre.blog/media/" + data["slug"] + "-page.png"
partial = data["mode"] == "incomplete"
html = f"""
<meta name="description" content="Uma sinopse sintética completa para comprovar a passagem de coleta e validação até a publicação pelo WordPress.">
<h1 class="manga-title">Pilot GitHub Serial</h1>
<div class="manga-cover"><img src="{cover}"></div>
<span class="manga-chapters-count">1 capítulo</span>
<ul class="chapters-list"><li><a class="chapter-link" href="{chapter}">Capítulo 1</a></li></ul>
"""
reader = f"""
<meta property="og:title" content="Pilot GitHub Serial Capítulo 1">
<div class="reading-content" data-page-count="{2 if partial else 1}"><img src="{page}"></div>
"""
# The collector validates the image header from the range response, not full image decoding.
png = b"\x89PNG\r\n\x1a\n" + b"\x00\x00\x00\rIHDR" + struct.pack(">II",64,64) + b"\x08\x02\x00\x00\x00"

class FixtureHTTP:
    def __init__(self, *args, **kwargs):
        self.requests = 0

    def get(self, url, image=False, nonce=""):
        self.requests += 1
        if image and url in (cover,page):
            return png
        if not image and url == source:
            return html.encode()
        if not image and url == chapter:
            return reader.encode()
        raise AssertionError("Unexpected outbound fixture request")

os.environ["MBI2_JOB_ID"]=data["id"]
os.environ["MBI2_WORDPRESS_ENDPOINT"]="http://127.0.0.1:8085/wp-json/mbi2/v1"
run.main(lambda url,key,jid: Client(url,key,jid,allow_loopback=True), FixtureHTTP)
