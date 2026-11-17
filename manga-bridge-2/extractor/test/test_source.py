import tempfile
import unittest
from pathlib import Path

from extractor.source import (
    chapter_number, is_chapter_url, is_pagination_url, parse_html, safe_url
)
from extractor.crawler import (
    BoundedFetcher, CrawlState, FetchFailure, InventoryCrawler,
    load_state, save_state
)

ROOT = "https://example.org/manga/demo/"
PAGE2 = "https://example.org/manga/demo/page/2/"
CAP1 = "https://example.org/capitulo/demo-capitulo-1/"
CAP2 = "https://example.org/capitulo/demo-capitulo-2/"
CAP3 = "https://example.org/capitulo/demo-capitulo-3/"


def html(chapters, declared=3, page_url=None, loader=False, title="Demo"):
    links = "".join(
        f'<li><a class="chapter-link" href="{url}">Capítulo {num}: exemplo</a></li>'
        for num, url in chapters
    )
    pager = (f'<a class="pagination" rel="next" href="{page_url}">Próxima</a>'
             if page_url else "")
    btn = '<button class="load-more">Carregar mais capítulos</button>' if loader else ""
    return (f'<html><head><meta property="og:image" content="https://cdn.example.org/capa.webp"></head>'
            f'<body><h1 class="manga-title">{title}</h1><div class="manga-chapters-count">'
            f'{declared} capítulos</div><ul class="chapters-list">{links}</ul>'
            f'{pager}{btn}<a href="https://example.org/capitulo/anuncio-capitulo-22/">Publicidade</a>'
            f'</body></html>')


class FakeFetcher:
    def __init__(self, pages):
        self.pages = pages
        self.calls = []

    def __call__(self, url):
        self.calls.append(url)
        if url not in self.pages:
            raise FetchFailure("HTTP_404")
        return self.pages[url]


class ExtractorTests(unittest.TestCase):
    def test_https_only_and_ssrf_urls_blocked(self):
        for u in ["http://example.org/x", "https://127.0.0.1/x",
                  "https://localhost/x", "https://user:pass@example.org/a",
                  "file:///etc/passwd", "javascript:alert(1)"]:
            self.assertIsNone(safe_url(u))
        self.assertIsNone(safe_url("https://another.org/a", host="example.org"))

    def test_chapter_scope_ignores_advertisements(self):
        p = parse_html(html([("1", CAP1)]), ROOT, ROOT)
        self.assertEqual([x["url"] for x in p.chapter_links], [CAP1])

    def test_count_title_and_cover(self):
        p = parse_html(html([("1", CAP1)], 142, title="Meu Mangá"), ROOT, ROOT)
        self.assertEqual(p.reported_count, 142)
        self.assertEqual(p.title, "Meu Mangá")
        self.assertEqual(p.cover_url, "https://cdn.example.org/capa.webp")

    def test_real_style_fractional_chapters_are_distinct(self):
        self.assertEqual(chapter_number("Capítulo 23: Capítulo 23.5", CAP1), "23.5")
        self.assertEqual(chapter_number("Capítulo 23: Capítulo 23.2", CAP1), "23.2")
        self.assertEqual(chapter_number("Capítulo 23: Capítulo 23", CAP1), "23")
        self.assertIsNone(chapter_number("Capítulo 23: Capítulo 24", CAP1))
        f = FakeFetcher({ROOT: html([
            ("23: Capítulo 23.5", CAP1),
            ("23: Capítulo 23.2", CAP2),
            ("23: Capítulo 23", CAP3)
        ], 3)})
        r = InventoryCrawler(f).crawl(ROOT)
        self.assertEqual(r["status"], "inventory_complete")
        self.assertEqual(r["duplicateChapterNumbers"], 0)

    def test_page_detection_strict(self):
        self.assertTrue(is_pagination_url(PAGE2, ROOT))
        self.assertTrue(is_pagination_url(ROOT + "?paged=3", ROOT))
        for url in [ROOT + "random/2/", "https://bad.org/manga/demo/page/3/",
                    "https://example.org/manga/other/page/2/", ROOT + "?page=2&admin=1"]:
            self.assertFalse(is_pagination_url(url, ROOT))

    def test_no_invented_rest_url(self):
        snap = parse_html(html([("1", CAP1)], loader=True), ROOT, ROOT)
        self.assertTrue(snap.dynamic_loader)
        self.assertEqual(snap.next_pages, [])

    def test_explicit_data_endpoint_allowed_only_as_documented_pagination(self):
        page = '<button class="load-more" data-next-url="/manga/demo/page/2/">Mais</button>'
        snap = parse_html(html([("1", CAP1)]) + page, ROOT, ROOT)
        self.assertIn(PAGE2, snap.next_pages)
        bad = '<button class="load-more" data-next-url="/wp-json/secret/">Mais</button>'
        snap2 = parse_html(html([("1", CAP1)]) + bad, ROOT, ROOT)
        self.assertEqual(snap2.next_pages, [])

    def test_one_manga_paginated_to_completion(self):
        f = FakeFetcher({ROOT: html([("1", CAP1)], 3, PAGE2),
                         PAGE2: html([("2", CAP2), ("3", CAP3)], 3)})
        result = InventoryCrawler(f).crawl(ROOT)
        self.assertEqual(result["status"], "inventory_complete")
        self.assertEqual(result["discoveredChapterCount"], 3)
        self.assertEqual(f.calls, [ROOT, PAGE2])
        self.assertFalse(result["allowPublish"])

    def test_30_visible_vs_100_declared_always_partial(self):
        records = [(str(i), f"https://example.org/capitulo/demo-capitulo-{i}/") for i in range(30)]
        result = InventoryCrawler(FakeFetcher({ROOT: html(records, 100)})).crawl(ROOT)
        self.assertEqual(result["status"], "blocked_partial_list")
        self.assertEqual(result["discoveredChapterCount"], 30)

    def test_dynamic_without_endpoint_reports_blocked(self):
        result = InventoryCrawler(FakeFetcher({ROOT: html([("1", CAP1)], 2, loader=True)})).crawl(ROOT)
        self.assertEqual(result["status"], "blocked_dynamic_loader")

    def test_unknown_count_never_approves(self):
        page = html([("1", CAP1)]).replace("3 capítulos", "capítulos indisponíveis")
        self.assertEqual(InventoryCrawler(FakeFetcher({ROOT: page})).crawl(ROOT)["status"], "blocked_no_authoritative_count")

    def test_duplicate_urls_not_counted_twice(self):
        page = html([("1", CAP1), ("1", CAP1)], 2)
        self.assertEqual(InventoryCrawler(FakeFetcher({ROOT: page})).crawl(ROOT)["status"], "blocked_partial_list")

    def test_duplicate_numbers_are_not_ready(self):
        page = html([("1", CAP1), ("1", CAP2)], 2)
        self.assertEqual(InventoryCrawler(FakeFetcher({ROOT: page})).crawl(ROOT)["status"], "blocked_duplicate_chapter_numbers")

    def test_pagination_budget_keeps_pending(self):
        f = FakeFetcher({ROOT: html([("1", CAP1)], 2, PAGE2), PAGE2: html([("2", CAP2)], 2)})
        result = InventoryCrawler(f, max_pages=1).crawl(ROOT)
        self.assertEqual(result["status"], "blocked_pagination_budget")
        self.assertEqual(result["pendingPageCount"], 1)

    def test_checkpoint_resumes_without_refetching_previous_pages(self):
        f = FakeFetcher({ROOT: html([("1", CAP1)], 2, PAGE2), PAGE2: html([("2", CAP2)], 2)})
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "state.json"
            first = InventoryCrawler(f, max_pages=1, checkpoint=path).crawl(ROOT)
            self.assertEqual(first["status"], "blocked_pagination_budget")
            second = InventoryCrawler(f, max_pages=2, checkpoint=path).crawl(ROOT)
            self.assertEqual(second["status"], "inventory_complete")
            self.assertEqual(f.calls, [ROOT, PAGE2])

    def test_checkpoint_rejects_foreign_manga(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "state.json"
            save_state(path, CrawlState(1, ROOT, queue=[ROOT]))
            with self.assertRaisesRegex(ValueError, "CHECKPOINT_MISMATCH"):
                load_state(path, "https://example.org/manga/other/")

    def test_source_failure_does_not_delete_checkpoint_or_publish(self):
        f = FakeFetcher({ROOT: html([("1", CAP1)], 2, PAGE2)})
        with tempfile.TemporaryDirectory() as temp:
            p = Path(temp) / "saved.json"
            result = InventoryCrawler(f, checkpoint=p).crawl(ROOT)
            self.assertEqual(result["status"], "source_unavailable")
            self.assertFalse(result["allowPublish"])
            self.assertEqual(load_state(p, ROOT).queue, [PAGE2])

    def test_image_extraction_is_read_only(self):
        chapter = ('<div class="reading-content">'
                   '<img data-src="https://img.example.org/001.webp" src="data:image/gif;base64,xx">'
                   '<img data-lazy-src="https://img.example.org/002.webp">'
                   '<img data-src="https://img.example.org/001.webp"></div>')
        snap = parse_html(chapter, CAP1, ROOT)
        self.assertEqual(snap.chapter_images, ["https://img.example.org/001.webp",
                                              "https://img.example.org/002.webp"])

    def test_sample_one_chapter_uses_one_extra_html_request_not_image_proxy(self):
        f = FakeFetcher({ROOT: html([("1", CAP1)], 1),
                         CAP1: '<div class="reading-content"><img src="https://cdn.example.org/1.jpg"></div>'})
        r = InventoryCrawler(f).crawl(ROOT, sample_chapters=1)
        self.assertEqual(r["sampledChapterPages"][0]["image_count"], 1)
        self.assertEqual(f.calls, [ROOT, CAP1])
        self.assertFalse(r["allowPublish"])

    def test_network_safety_limits(self):
        with self.assertRaises(ValueError):
            BoundedFetcher("localhost")
        with self.assertRaises(ValueError):
            BoundedFetcher("example.org", max_requests=500)
        with self.assertRaises(FetchFailure):
            BoundedFetcher("example.org").fetch_html("https://other.org/a")

    def test_no_unsafe_cross_manga_chapter_injection(self):
        alt = "https://example.org/manga/outro/capitulo-1/"
        self.assertFalse(is_chapter_url(alt, ROOT))
        self.assertTrue(is_chapter_url("https://example.org/manga/demo/capitulo-1/", ROOT))
        self.assertFalse(is_chapter_url("https://bad.org/capitulo/demo-capitulo-1/", ROOT))
        self.assertEqual(chapter_number("Capítulo 120.5", CAP1), "120.5")


if __name__ == "__main__":
    unittest.main()
