"""Сборка: оформление для PHP-блога и правила хостинга. PHP-часть — tests/php/test_blog.php (запускается отсюда)."""
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import build

ROOT = Path(__file__).resolve().parent.parent


class BuildTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        build.build()

    def test_parts_for_php_blog(self):
        p = json.loads((build.DIST / "_blog" / "parts.json").read_text(encoding="utf-8"))
        for mark in ("%%PATH%%", "%%TITLE%%", "%%DESC%%", "%%BODY%%", "%%IMAGE%%", "%%OGTYPE%%", '"__SCHEMA__"'):
            self.assertIn(mark, p["shell"])
        self.assertIn('href="/blog/"', p["shell"])  # «Блог» в меню
        self.assertIn("%%SOURCE%%", p["lead_form"])
        self.assertIn("%%ITEMS%%", p["faq_wrap"])
        self.assertTrue(p["services"])

    def test_home_marker_and_no_static_blog(self):
        self.assertRegex((build.DIST / "index.html").read_text(encoding="utf-8"), r"<!--BLOG-HOME:\d\d-->")
        self.assertFalse((build.DIST / "blog").exists())
        self.assertFalse((build.DIST / "sitemap.xml").exists())  # её отдаёт PHP вместе со статьями
        self.assertIn("</urlset>", (build.DIST / "_blog" / "sitemap.tpl").read_text(encoding="utf-8"))

    def test_sitemap_lastmod_only_moves_when_a_page_actually_changes(self):
        # sitemap-lastmod.json подменяем на время теста — иначе page_lastmod() перезаписал бы реальный
        # файл сайта только тестовыми путями и стёр бы накопленную историю остальных страниц.
        real_file = build.LASTMOD_FILE
        with tempfile.TemporaryDirectory() as tmp:
            build.LASTMOD_FILE = Path(tmp) / "sitemap-lastmod.json"
            try:
                (build.DIST / "t-unchanged").mkdir(parents=True, exist_ok=True)
                (build.DIST / "t-unchanged" / "index.html").write_text("контент не меняется", encoding="utf-8")
                (build.DIST / "t-changed").mkdir(parents=True, exist_ok=True)
                (build.DIST / "t-changed" / "index.html").write_text("версия A", encoding="utf-8")
                import hashlib
                build.LASTMOD_FILE.write_text(json.dumps({
                    "/t-unchanged/": {"hash": hashlib.sha256("контент не меняется".encode()).hexdigest(), "date": "2020-01-01"},
                    "/t-changed/": {"hash": hashlib.sha256("версия A".encode()).hexdigest(), "date": "2020-01-01"},
                }), encoding="utf-8")

                (build.DIST / "t-changed" / "index.html").write_text("версия B", encoding="utf-8")
                result = build.page_lastmod(["/t-unchanged/", "/t-changed/"])

                self.assertEqual(result["/t-unchanged/"], "2020-01-01", "контент не менялся — старая дата из карты сохраняется, не «сегодня»")
                self.assertNotEqual(result["/t-changed/"], "2020-01-01", "контент поменялся — дата переехала на сегодня")
            finally:
                build.LASTMOD_FILE = real_file
                shutil.rmtree(build.DIST / "t-unchanged", ignore_errors=True)
                shutil.rmtree(build.DIST / "t-changed", ignore_errors=True)

    def test_hosting_rules(self):
        ht = (build.DIST / ".htaccess").read_text(encoding="utf-8")
        for rule in ("_blog/router.php?route=home", "route=article&slug=$1", "admin/api.php?r=$1/$2"):
            self.assertIn(rule, ht)
        self.assertIn("RewriteCond %{THE_REQUEST} \\s/+_blog/", ht)
        # «Require all denied» пишет отказы в лог ошибок → fail2ban хостинга банит IP посетителя.
        self.assertFalse((build.DIST / "_blog" / ".htaccess").exists())
        self.assertFalse((build.DIST / "admin" / ".htaccess").exists())
        self.assertNotIn("Require all denied", ht)
        robots = (build.DIST / "robots.txt").read_text(encoding="utf-8")
        self.assertIn("Disallow: /admin/", robots)
        for bot in ("OAI-SearchBot", "PerplexityBot", "ClaudeBot", "Google-Extended"):
            self.assertIn(f"User-agent: {bot}\nAllow: /", robots)
        self.assertIn("route=llms", ht)
        self.assertTrue((build.DIST / f"{build.INDEXNOW_KEY}.txt").is_file())

    def test_catalog_pages_and_sitemap(self):
        sm = (build.DIST / "_blog" / "sitemap.tpl").read_text(encoding="utf-8")
        for slug in [c["slug"] for c in build.cat.CATEGORIES] + [p["slug"] for p in build.cat.PRODUCTS] + [c["slug"] for c in build.cat.CITIES]:
            self.assertTrue((build.DIST / slug / "index.html").is_file(), slug)
            self.assertIn(f"https://metalbaza.kz/{slug}/</loc>", sm)
        self.assertEqual(len({c["slug"] for c in build.cat.CATEGORIES} & {p["slug"] for p in build.cat.PRODUCTS}), 0)

    def test_form_consent_and_prefill(self):
        doc = (build.DIST / "armatura" / "index.html").read_text(encoding="utf-8")
        self.assertIn('name="consent" value="1" required', doc)
        self.assertIn('name="product" maxlength="80" list="products" value="Арматура"', doc)
        city = (build.DIST / "astana" / "index.html").read_text(encoding="utf-8")
        self.assertIn('<option value="Астана" selected>', city)
        self.assertIn('"@type": "BreadcrumbList"', doc)
        self.assertIn('"@type": "FAQPage"', doc)

    def test_no_agency_leftovers(self):
        # Движок взят у alfimov.kz — в собранном сайте не должно остаться агентских адресов и текстов.
        # Исключения — комментарии о по-настоящему общей инфраструктуре хостинга, не копипаста контента:
        # _tg.php/prompts.php — общий бот (своя группа у каждого сайта); admin/index.php/auth.php/_csp.php/
        # _hub.php/_net.php/_crm.php/_foreign_leads.php — единый пульт admin.alfimov.kz (движок один на все сайты хостинга).
        shared_infra = ("_tg.php", "admin.tpl", "prompts.php", "prompts.example.php",
                         "index.php", "auth.php", "_csp.php", "_hub.php", "_net.php", "_crm.php",
                         "_foreign_leads.php")
        for f in build.DIST.rglob("*"):
            if f.suffix in (".html", ".php", ".json", ".tpl", ".txt", ".js", ".css") and f.name not in shared_infra:
                t = f.read_text(encoding="utf-8")
                self.assertNotIn("alfimov.kz", t, str(f))
                self.assertNotIn("агентств", t.lower(), str(f))

    def test_site_php_for_engine(self):
        php = (build.DIST / "_blog" / "site.php").read_text(encoding="utf-8")
        for k in ("SITE_HOST', 'metalbaza.kz'", "SITE_DATA', 'metalbaza-data'", "NICHE_SEEDS", "NICHE_GEO_QUESTIONS", "SITE_INDEXNOW_KEY"):
            self.assertIn(k, php)

    @unittest.skipUnless(shutil.which("php"), "нужен php")
    def test_php_suite(self):
        for f in sorted((build.DIST).rglob("*.php")):
            r = subprocess.run(["php", "-l", str(f)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        for t in build.enginekit.php_tests():   # тесты общего движка — на сборке этого сайта
            r = subprocess.run(["php", str(t), str(ROOT)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, t.name + "\n" + r.stdout + r.stderr)


if __name__ == "__main__":
    unittest.main()
