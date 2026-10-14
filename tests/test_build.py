"""Сборка: оформление для PHP-блога и правила хостинга. PHP-часть — tests/php/test_blog.php (запускается отсюда)."""
import json
import shutil
import subprocess
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
        for f in build.DIST.rglob("*"):
            # api/_tg.php — общий с alfimov.kz файл бота (бот один на оба сайта), там домен главного сайта по умолчанию
            if f.suffix in (".html", ".php", ".json", ".tpl", ".txt", ".js", ".css") and f.name != "_tg.php":
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
        for t in ("test_blog.php", "test_tg.php", "test_seo.php", "test_leads.php"):
            r = subprocess.run(["php", str(ROOT / "tests" / "php" / t)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == "__main__":
    unittest.main()
