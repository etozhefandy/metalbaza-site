#!/usr/bin/env python3
"""SEO-аудит собранного сайта (dist/) — правила по мотивам аудита OpenSEO (every-app/open-seo).

    python3 seo_audit.py        # проверить dist/, выход 1 при critical (deploy-git.sh не выложит сайт)

Запускается и в конце build.py (печатает сводку). Проверки: title/description (есть, длина,
дубли), ровно один H1, уровни заголовков без пропусков, битые внутренние ссылки,
страницы без входящих ссылок, картинки без alt, объём текста.
"""
import collections
import html
import re
import sys
from dataclasses import dataclass
from pathlib import Path

TITLE_MAX, TITLE_MIN = 60, 25
DESC_MAX, DESC_MIN = 160, 70
THIN_WORDS = 300
# Страницы, где мало текста — норма (список статей, контакты).
THIN_OK = re.compile(r"^/(kontakty/|blog/)$")
SEVERITY_ORDER = {"critical": 0, "warning": 1, "info": 2}


@dataclass
class Issue:
    severity: str
    title: str
    page: str
    detail: str = ""

    def __str__(self):
        return f"[{self.severity}] {self.title}: {self.page}" + (f" — {self.detail}" if self.detail else "")


def _pages(dist):
    out = {}
    for f in sorted(dist.rglob("*.html")):
        rel = "/" + str(f.relative_to(dist)).replace("\\", "/")
        if rel == "/404.html" or re.match(r"^/yandex_[0-9a-f]+\.html$", rel) or rel.startswith(("/admin/", "/_blog/")):
            continue
        out[rel.removesuffix("index.html")] = f.read_text(encoding="utf-8")
    return out


def _main_html(doc):
    m = re.search(r"<main>(.*)</main>", doc, re.S)
    return m.group(1) if m else doc


# Блог собирает PHP на хостинге — в dist/ этих страниц нет, ссылки на них не битые.
DYNAMIC = re.compile(r"^/blog/([a-z0-9-]+/)?$")


def _exists(dist, href):
    if DYNAMIC.match(href):
        return True
    target = dist / href.lstrip("/")
    return target.is_file() or (target / "index.html").is_file()


def audit(dist):
    dist = Path(dist)
    pages = _pages(dist)
    issues = []
    titles, descs = collections.defaultdict(list), collections.defaultdict(list)
    inbound = collections.Counter()

    for path, doc in pages.items():
        noindex = 'name="robots" content="noindex' in doc
        m = re.search(r"<title>(.*?)</title>", doc, re.S)
        title = html.unescape(m.group(1).strip()) if m else ""
        m = re.search(r'<meta name="description" content="([^"]*)"', doc)
        desc = html.unescape(m.group(1).strip()) if m else ""

        if not title:
            issues.append(Issue("critical", "Нет title", path))
        else:
            titles[title].append(path)
            if len(title) > TITLE_MAX:
                issues.append(Issue("warning", "Title длиннее 60 символов (Google обрежет)", path, f"{len(title)}: {title}"))
            elif len(title) < TITLE_MIN:
                issues.append(Issue("info", "Title короткий", path, f"{len(title)}: {title}"))
        if not desc:
            issues.append(Issue("warning", "Нет meta description", path))
        else:
            descs[desc].append(path)
            if len(desc) > DESC_MAX:
                issues.append(Issue("warning", "Description длиннее 160 символов", path, str(len(desc))))
            elif len(desc) < DESC_MIN:
                issues.append(Issue("info", "Description короткий", path, str(len(desc))))

        body = _main_html(doc)
        h1 = len(re.findall(r"<h1[\s>]", doc))
        if h1 != 1:
            issues.append(Issue("critical", "На странице не один H1", path, str(h1)))
        levels = [int(x) for x in re.findall(r"<h([1-6])[\s>]", doc)]
        for a, b in zip(levels, levels[1:]):
            if b > a + 1:
                issues.append(Issue("warning", "Пропущен уровень заголовка", path, f"h{a} → h{b}"))
                break

        for img in re.findall(r"<img\b[^>]*>", body):
            alt = re.search(r'\balt="([^"]*)"', img)
            decorative = 'aria-hidden="true"' in img or 'role="presentation"' in img or "post-img" in img
            if (alt is None or not alt.group(1).strip()) and not decorative:
                src = (re.search(r'\bsrc="([^"]*)"', img) or [None, "?"])[1]
                issues.append(Issue("warning", "Картинка без alt", path, src))

        words = len(re.sub(r"<[^>]+>", " ", re.sub(r"<(script|style)\b.*?</\1>", " ", body, flags=re.S)).split())
        if words < THIN_WORDS and not THIN_OK.match(path) and not noindex:
            issues.append(Issue("info", "Мало текста", path, f"{words} слов"))

        for href in set(re.findall(r'href="(/[^"#?]*)', doc)):
            if not _exists(dist, href):
                issues.append(Issue("critical", "Битая внутренняя ссылка", path, href))
            elif href != path:
                inbound[href] += 1

    for title, ps in titles.items():
        if len(ps) > 1:
            issues.append(Issue("critical", "Одинаковый title", ", ".join(ps), title))
    for desc, ps in descs.items():
        if len(ps) > 1:
            issues.append(Issue("warning", "Одинаковый description", ", ".join(ps)))
    for path in pages:
        if path != "/" and inbound[path] == 0:
            issues.append(Issue("warning", "Нет входящих ссылок (страница-сирота)", path))

    issues.sort(key=lambda i: (SEVERITY_ORDER[i.severity], i.title, i.page))
    return issues


def summary(issues):
    c = collections.Counter(i.severity for i in issues)
    return f"SEO-аудит: critical {c['critical']}, warning {c['warning']}, info {c['info']}"


def main():
    dist = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).parent / "dist"
    issues = audit(dist)
    for i in issues:
        print(i)
    print(summary(issues))
    sys.exit(1 if any(i.severity == "critical" for i in issues) else 0)


if __name__ == "__main__":
    main()
