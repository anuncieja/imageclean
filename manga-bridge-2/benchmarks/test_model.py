import unittest
from model import CASES, checkpoint_size, model_case, run

class BenchmarkModelTests(unittest.TestCase):
    def test_case_counts(self):
        data = run(None)
        self.assertEqual(len(data["cases"]), 3)
        for c in data["cases"]:
            self.assertEqual(c["total_chapters"], 10 * c["chapters_per_work"])
            self.assertEqual(c["total_image_urls"], 10 * c["chapters_per_work"] * c["pages_per_chapter"])
            self.assertFalse(c["scenarios"]["base"]["measured_only"])
    def test_large_case_is_not_falsely_marked_complete(self):
        large = next(c for c in run(None)["cases"] if c["label"] == "muitos")
        self.assertFalse(large["can_finish_under_current_request_caps"])
        self.assertTrue(large["checkpoint_may_exceed_limit"])
    def test_monotonic(self):
        data = run(None)["cases"]
        self.assertLess(data[0]["requests_all_10_lower_bound"], data[1]["requests_all_10_lower_bound"])
        self.assertLess(data[1]["requests_all_10_lower_bound"], data[2]["requests_all_10_lower_bound"])

if __name__ == "__main__":
    unittest.main()
