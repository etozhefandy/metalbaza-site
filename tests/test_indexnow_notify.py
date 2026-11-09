import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import build  # noqa: E402  (кладёт engine/ в sys.path)
import indexnow_notify as inn  # noqa: E402


class IndexNowNotifyTest(unittest.TestCase):
    def test_changed_new_page_and_unchanged(self):
        lastmod = {"/a/": {"hash": "h1", "date": "2026-01-01"}, "/b/": {"hash": "h2", "date": "2026-01-01"}}
        sent = {"/a/": "h1"}   # /a/ уже отправлена с тем же хешем — не меняется
        self.assertEqual(inn.changed(lastmod, sent), {"/b/": "h2"})

    def test_changed_hash_differs(self):
        lastmod = {"/a/": {"hash": "h1-new", "date": "2026-02-01"}}
        sent = {"/a/": "h1-old"}
        self.assertEqual(inn.changed(lastmod, sent), {"/a/": "h1-new"})

    def test_nothing_changed_skips_network(self):
        lastmod = {"/a/": {"hash": "h1", "date": "2026-01-01"}}
        sent = {"/a/": "h1"}
        calls = []
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "sitemap-lastmod.json").write_text(json.dumps(lastmod), encoding="utf-8")
            (root / "indexnow-sent.json").write_text(json.dumps(sent), encoding="utf-8")
            inn.run(root, "https://example.kz", "KEY", post=lambda *a: calls.append(a) or True, git=False)
        self.assertEqual(calls, [])

    def test_changed_sends_only_diff_and_persists_on_success(self):
        lastmod = {"/a/": {"hash": "h1", "date": "2026-01-01"}, "/b/": {"hash": "h2-new", "date": "2026-02-01"}}
        sent = {"/a/": "h1", "/b/": "h2-old"}
        calls = []
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "sitemap-lastmod.json").write_text(json.dumps(lastmod), encoding="utf-8")
            (root / "indexnow-sent.json").write_text(json.dumps(sent), encoding="utf-8")
            inn.run(root, "https://example.kz", "KEY", post=lambda *a: calls.append(a) or True, git=False)
            saved = json.loads((root / "indexnow-sent.json").read_text(encoding="utf-8"))
        self.assertEqual(len(calls), 1)
        host, key, key_loc, urls = calls[0]
        self.assertEqual((host, key, key_loc), ("example.kz", "KEY", "https://example.kz/KEY.txt"))
        self.assertEqual(urls, ["https://example.kz/b/"])   # только изменившаяся страница
        self.assertEqual(saved, {"/a/": "h1", "/b/": "h2-new"})

    def test_failed_notify_does_not_persist_state(self):
        lastmod = {"/a/": {"hash": "h1-new", "date": "2026-02-01"}}
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "sitemap-lastmod.json").write_text(json.dumps(lastmod), encoding="utf-8")
            inn.run(root, "https://example.kz", "KEY", post=lambda *a: False, git=False)
            self.assertFalse((root / "indexnow-sent.json").is_file())

    def test_no_lastmod_file_is_a_noop(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertEqual(inn.run(Path(tmp), "https://example.kz", "KEY", post=lambda *a: True, git=False), 0)


if __name__ == "__main__":
    unittest.main()
