import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import build  # noqa: E402
import seo_audit  # noqa: E402

GOOD_DESC = "Описание страницы нормальной длины для поисковой выдачи, чтобы проверка не ругалась на слишком короткий текст."


def page(title, h1=1, desc=GOOD_DESC, body="", links=()):
    heads = "".join("<h1>Заголовок</h1>" for _ in range(h1))
    a = "".join(f'<a href="{x}">x</a>' for x in links)
    words = " ".join(["слово"] * 350)
    return (f'<html><head><title>{title}</title><meta name="description" content="{desc}"></head>'
            f"<body><main>{heads}{body}<p>{words}</p>{a}</main></body></html>")


class AuditRulesTest(unittest.TestCase):
    def make(self, files):
        d = Path(tempfile.mkdtemp())
        for rel, html in files.items():
            f = d / rel
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text(html, encoding="utf-8")
        return {(i.severity, i.title) for i in seo_audit.audit(d)}, seo_audit.audit(d)

    def test_clean_pair(self):
        found, _ = self.make({"index.html": page("Главная страница агентства", links=["/a/"]),
                              "a/index.html": page("Страница услуги агентства", links=["/"], desc=GOOD_DESC + " Услуга.")})
        self.assertEqual(found, set())

    def test_detects_problems(self):
        found, _ = self.make({
            "index.html": page("Одинаковый заголовок страницы", h1=2, links=["/nope/", "/b/"],
                               body='<h2>a</h2><h4>b</h4><img src="/x.png">'),
            "b/index.html": page("Одинаковый заголовок страницы", h1=0, desc="Коротко"),
            "c/index.html": page("Очень длинный заголовок страницы, который точно не влезет в выдачу Google"),
        })
        for expected in [("critical", "Битая внутренняя ссылка"), ("critical", "Одинаковый title"),
                         ("critical", "На странице не один H1"), ("warning", "Пропущен уровень заголовка"),
                         ("warning", "Картинка без alt"), ("warning", "Нет входящих ссылок (страница-сирота)"),
                         ("warning", "Title длиннее 60 символов (Google обрежет)"), ("info", "Description короткий")]:
            self.assertIn(expected, found)


class RealSiteTest(unittest.TestCase):
    """Статическая часть сайта не должна давать critical и warning (ссылки на /blog/ — живые, PHP)."""

    def test_site_is_clean(self):
        tmp = Path(tempfile.mkdtemp())
        orig = build.DIST
        build.DIST = tmp / "dist"
        try:
            build.build()
            bad = [str(i) for i in seo_audit.audit(build.DIST) if i.severity != "info"]
        finally:
            build.DIST = orig
        self.assertEqual(bad, [])


if __name__ == "__main__":
    unittest.main()
