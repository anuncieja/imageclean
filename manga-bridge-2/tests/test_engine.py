import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "engine"))
from engine import Document, SafeHTTP, Blocked, Yield, root_data, chapter_links, image_dimensions, collect_work
from protocol import signature, key_bytes

BASE = "https://mangalivre.blog/manga/demo/"
C1 = "https://mangalivre.blog/capitulo/demo-1/"
C2 = "https://mangalivre.blog/capitulo/demo-2/"
COVER = "https://mangalivre.blog/wp-content/uploads/cover.jpg"
PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 8 + struct.pack(">II", 900, 1300)
def root(missing=False, partial=False, nonce="test"):
    links = '<li><a class="chapter-link" href="/capitulo/demo-1/">1</a></li>'
    if not partial:
        links += '<li><a class="chapter-link" href="/capitulo/demo-2/">2</a></li>'
    code = json.dumps({"rest": "https://mangalivre.blog/wp-json/slimeread/v1/", "nonce": nonce})
    loader = ('<button id="sr-load-chapters" data-manga="12" data-offset="1" data-total="2"></button>'
              '<script src="data:text/javascript;base64,' + base64.b64encode(("var srPerformance = " + code + ";").encode()).decode() + '"></script>') if partial else ""
    return ('<h1 class="manga-title">Demo</h1><meta name="description" content="A description long enough to validate this manga without inventing source metadata.">'
            '<img class="manga-cover-image" src="' + ("https://mangalivre.blog/no-cover.jpg" if missing else COVER) + '">'
            '<span class="manga-chapters-count">2 capítulos</span><ul class="chapters-list">' + links + '</ul>' + loader)

class FakeHTTP:
    def __init__(self, bodies):
        self.bodies, self.calls = bodies, []
    def get(self, url, image=False, nonce=""):
        self.calls.append((url, image, nonce))
        if url not in self.bodies:
            raise AssertionError("unexpected network target")
        value = self.bodies[url]
        if isinstance(value, Exception):
            raise value
        return value

class EngineTests(unittest.TestCase):
    def test_root_and_inventory(self):
        data = root_data(root(), BASE)
        self.assertEqual(data["chapter_urls"], [C1, C2])
        self.assertEqual(data["expected_chapters"], 2)
    def test_missing_cover_not_replaced_with_recommendation(self):
        with self.assertRaisesRegex(Blocked, "missing_real_cover"):
            root_data(root(missing=True) + '<img src="https://cdn.test/recommendation.jpg">', BASE)
    def test_loader_and_rotating_nonce(self):
        data = root_data(root(partial=True, nonce="rotated"), BASE)
        self.assertEqual(data["loader"]["offset"], 1)
        self.assertEqual(data["loader"]["nonce"], "rotated")
    def test_foreign_rest_refused(self):
        bad = root(partial=True).replace(base64.b64encode(b'var srPerformance = {"rest": "https://mangalivre.blog/wp-json/slimeread/v1/", "nonce": "test"};').decode(),
            base64.b64encode(b'var srPerformance = {"rest": "https://evil.test/wp-json/slimeread/v1/"};').decode())
        with self.assertRaises(Blocked):
            root_data(bad, BASE)
    def test_duplicate_chapter_offset_refused(self):
        page = root(partial=True)
        endpoint = "https://mangalivre.blog/wp-json/slimeread/v1/chapters/12?offset=1&search="
        http = FakeHTTP({BASE: page.encode(), endpoint: json.dumps({"offset": 2, "total": 2, "list": '<a class="chapter-link" href="/capitulo/demo-1/">1</a>'}).encode()})
        with self.assertRaisesRegex(Blocked, "duplicate_inventory"):
            collect_work(BASE, http)
    def test_all_images_validated_once_without_worker(self):
        pages = {"https://mangalivre.blog/wp-content/uploads/1.png": PNG, "https://mangalivre.blog/wp-content/uploads/2.png": PNG}
        http = FakeHTTP({BASE: root().encode(), COVER: PNG,
            C1: b'<div class="reading-content"><img src="/wp-content/uploads/1.png"></div>',
            C2: b'<div class="reading-content"><img src="/wp-content/uploads/2.png"></div>', **pages})
        saved = []
        result = collect_work(BASE, http, progress=lambda state: saved.append(json.loads(json.dumps(state))))
        self.assertTrue(result["complete"])
        self.assertEqual(len(result["chapters"]), 2)
        self.assertEqual(len([c for c in http.calls if c[1]]), 3)
        self.assertTrue(all("img.lermangaonline" not in c[0] for c in http.calls))
        self.assertGreaterEqual(len(saved), 2)
        again = FakeHTTP({BASE: root().encode()})
        self.assertTrue(collect_work(BASE, again, result)["complete"])
        self.assertEqual(len(again.calls), 1)
    def test_dynamic_inventory_completes_with_fresh_nonce(self):
        endpoint = "https://mangalivre.blog/wp-json/slimeread/v1/chapters/12?offset=1&search="
        image = "https://mangalivre.blog/wp-content/uploads/shared.png"
        http = FakeHTTP({BASE: root(partial=True, nonce="fresh").encode(), COVER: PNG,
            endpoint: json.dumps({"offset": 2, "total": 2, "list": '<a class="chapter-link" href="/capitulo/demo-2/">2</a>'}).encode(),
            C1: ('<div class="reading-content"><img src="' + image + '"></div>').encode(),
            C2: ('<div class="reading-content"><img src="' + image + '"></div>').encode(), image: PNG})
        result = collect_work(BASE, http)
        self.assertTrue(result["complete"])
        self.assertIn((endpoint, False, "fresh"), http.calls)
        self.assertEqual(sum(c[0] == image for c in http.calls), 1)
    def test_temporary_failure_retains_validated_images(self):
        image = "https://mangalivre.blog/wp-content/uploads/resumable.png"
        chapter = ('<div class="reading-content"><img src="' + image + '"></div>').encode()
        first = FakeHTTP({BASE: root().encode(), COVER: PNG, C1: chapter, image: PNG, C2: TimeoutError()})
        saved = []
        with self.assertRaises(TimeoutError):
            collect_work(BASE, first, progress=lambda state: saved.append(json.loads(json.dumps(state))))
        self.assertEqual(len(saved[-1]["chapters"]), 1)
        resumed = FakeHTTP({BASE: root().encode(), C2: chapter})
        result = collect_work(BASE, resumed, saved[-1])
        self.assertTrue(result["complete"])
        self.assertEqual([c[0] for c in resumed.calls], [BASE, C2])
    def test_header_dimensions(self):
        self.assertEqual(image_dimensions(PNG), (900, 1300))
        with self.assertRaises(Blocked):
            image_dimensions(b"<html>error</html>")
    def test_proxy_refused_before_dns(self):
        with patch.object(socket, "getaddrinfo") as dns:
            with self.assertRaises(Blocked):
                SafeHTTP({"img.lermangaonline.com.br"}, {"img.lermangaonline.com.br"}).get("https://img.lermangaonline.com.br/i/token", image=True)
            dns.assert_not_called()
    def test_private_address_refused_before_connection(self):
        with patch.object(socket, "getaddrinfo", return_value=[(2, 1, 6, "", ("127.0.0.1", 443))]):
            with self.assertRaisesRegex(Blocked, "non_public"):
                SafeHTTP({"mangalivre.blog"}, set()).get(BASE)
    def test_zero_budget_does_not_open_connection(self):
        with patch.object(socket, "getaddrinfo") as dns:
            with self.assertRaises(Yield):
                SafeHTTP({"mangalivre.blog"}, set(), max_requests=0).get(BASE)
            dns.assert_not_called()
    def test_hmac_matches_php_raw_body(self):
        vector = os.environ.get("MBI2_VECTOR_OUTPUT")
        if not vector:
            self.skipTest("cross-language vector not configured")
        expected = json.loads(Path(vector).read_text())
        actual = signature(b"k" * 32, "POST", "jobs/11111111-1111-4111-8111-111111111111/events",
                           "1800000000", "a" * 32, expected["body"].encode())
        self.assertEqual(actual, expected["signature"])
    def test_invalid_key(self):
        with self.assertRaises(ValueError):
            key_bytes(base64.b64encode(b"short"))

if __name__ == "__main__":
    unittest.main()
