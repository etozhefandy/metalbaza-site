#!/usr/bin/env python3
"""Генератор статического сайта (без зависимостей, только stdlib). Движок — от alfimov.kz.

    python3 build.py            # собирает сайт в dist/
    python3 build.py --serve    # собирает и поднимает http://localhost:8000 (только статика, без PHP)

Настройки сайта — src/siteconf.py, каталог и города — src/catalog.py, структура — docs/STRUCTURE.md.
Блог, админка и форма заявки — PHP на хостинге (static/_blog/, static/admin/, static/api/).
"""
import hashlib
import html
import json
import shutil
import subprocess
import sys
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent / "src"))
sys.path.insert(0, str(Path(__file__).parent / "engine"))
import catalog as cat  # noqa: E402
import enginekit  # noqa: E402  (общий движок сайтов — git submodule engine/)
import seo_audit  # noqa: E402  (из движка)
import siteconf as cfg  # noqa: E402

ROOT = Path(__file__).parent
DIST = ROOT / "dist"
STATIC = ROOT / "static"

SITE_URL = cfg.SITE_URL
FONT_URL = (
    "https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@600;700"
    "&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap"
)
# Microsoft Clarity — тепловые карты кликов и прокрутки, записи визитов (clarity.microsoft.com). Пусто — не ставится.
# Поля ввода Clarity по умолчанию скрывает (телефоны в записи не попадают). Источники Clarity разрешены в CSP (static/.htaccess).
CLARITY_ID = "z0hfr76pkp"
CLARITY_TAG = (
    '<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};'
    't=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];'
    f'y.parentNode.insertBefore(t,y)}})(window,document,"clarity","script","{CLARITY_ID}");</script>'
) if CLARITY_ID else ""

ASSET_VER = hashlib.md5(
    b"".join((STATIC / "assets" / f).read_bytes() for f in ("style.css", "main.js"))
).hexdigest()[:8]

e = html.escape

UI = {
    "cta": "Получить цены",
    "breadcrumbs_home": "Главная",
    "more": "Подробнее",
    "faq_title": "Частые вопросы",
    "form_name": "Имя (необязательно)",
    "form_phone": "Телефон",
    "form_phone_err": "Проверьте номер: он введён не полностью или такого номера нет",
    "form_product": "Что нужно",
    "form_city": "Город",
    "form_city_other": "Другой город",
    "form_message": "Размеры, количество, доставка",
    "form_message_ph": "Например: арматура А500С 12 мм, 3 тонны, с доставкой в Каскелен",
    "form_submit": "Получить цены",
    "form_sending": "Отправляем…",
    "form_ok": "Заявка принята. Поставщики свяжутся с вами — обычно в течение рабочего дня.",
    "form_err": "Не получилось отправить. Напишите нам в WhatsApp — ответим там.",
    "form_consent_err": "Нужно согласие на передачу контактов поставщикам — иначе мы не сможем прислать цены.",
    "consent": "Согласен на передачу моего телефона и заявки поставщикам металлопроката для расчёта цены и связи со мной.",
    "consent_link": "Политика конфиденциальности",
    "not_found_title": "Страница не найдена",
    "not_found_text": "Такой страницы нет или она переехала. Начните с каталога или оставьте заявку — поставщики пришлют цены.",
    "not_found_btn": "На главную",
}

FORM_TITLE = "Получите цены от поставщиков"
FORM_LEAD = ("Опишите, что нужно, — заявку получат металлобазы вашего города и пришлют цены, наличие и сроки доставки. "
             "Для покупателя это бесплатно.")

HOW_STEPS = [
    ("Заявка", "Что нужно, размеры, объём и город. Можно списком — сразу всё на объект."),
    ("Поставщики", "Заявку получают металлобазы и производители вашего города, которые работают с нами."),
    ("Цены", "Поставщики связываются с вами и присылают цены, наличие и сроки доставки."),
    ("Выбор", "Вы сравниваете предложения и покупаете напрямую у поставщика. Сайт денег не берёт."),
]

HOME_FAQ = [
    ("Сколько стоит заявка для покупателя?", "Нисколько. Вы платите только поставщику за металл и доставку, если выберете его предложение. Сайт зарабатывает на поставщиках, а не на покупателях."),
    ("Сайт продаёт металл сам?", "Нет. Металбаза передаёт вашу заявку металлобазам и производителям вашего города. Цены, наличие и доставку называют они, договор и оплата — напрямую с ними."),
    ("Как быстро ответят?", "Обычно в течение рабочего дня. Если нужно срочно, напишите это в заявке — поставщики увидят."),
    ("Кому передаются мои контакты?", "Только поставщикам металлопроката, которые могут выполнить заявку, и только после вашего согласия в форме. Подробно — в политике конфиденциальности."),
    ("Можно отправить сразу список на объект?", "Да, и это удобнее: впишите все позиции с размерами и количеством в одну заявку. Поставщики посчитают весь список и одну доставку."),
    ("Почему на сайте нет цен?", "Цены на металл меняются каждую неделю и зависят от объёма, размера и доставки. Честная цена — та, что поставщик назовёт под вашу заявку, поэтому мы не публикуем устаревшие прайсы."),
]

# ---------------------------------------------------------------- icons (только функциональные)

ICONS = {
    "arrow": '<path d="M5 12h14M13 6l6 6-6 6"/>',
    "plus": '<path d="M12 5v14M5 12h14"/>',
    "phone": '<path d="M5 4h3.5l1.5 4.5-2 1.5a11 11 0 0 0 6 6l1.5-2 4.5 1.5V19a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
    "whatsapp": '<path d="M4 20l1.2-4A8 8 0 1 1 8 18.8Z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8c-1-.5-2-1.5-2.3-2.3l.8-1-1-2-1 .5Z"/>',
    "menu": '<path d="M4 8h16M4 16h16"/>',
    "pin": '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
}


def icon(name, cls="ic"):
    return (
        f'<svg class="{cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        f'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{ICONS[name]}</svg>'
    )


def logo():
    # Текстовый логотип: квадрат-«профиль» + название шрифтом заголовков.
    return ('<a class="logo" href="/" aria-label="Металбаза — на главную">'
            '<span class="logo-mark" aria-hidden="true"></span><span class="logo-word">МЕТАЛ<b>БАЗА</b></span></a>')


def p_url(slug):
    return f"/{slug}/"


# ---------------------------------------------------------------- schema.org

def org_schema():
    return {
        "@type": "Organization",
        "@id": f"{SITE_URL}/#org",
        "name": cfg.BRAND,
        "alternateName": cfg.BRAND_UPPER,
        "url": f"{SITE_URL}/",
        "logo": f"{SITE_URL}/favicon.svg",
        "image": f"{SITE_URL}/assets/og.png",
        "telephone": cfg.PHONE_TEL,
        "description": cfg.NICHE["about"],
        "areaServed": {"@type": "Country", "name": "Kazakhstan"},
        "contactPoint": {"@type": "ContactPoint", "telephone": cfg.PHONE_TEL, "contactType": "customer service",
                         "areaServed": "KZ", "availableLanguage": ["ru"]},
    }


def faq_schema(items):
    return {
        "@type": "FAQPage",
        "mainEntity": [
            {"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}}
            for q, a in items
        ],
    }


def breadcrumbs_schema(trail):
    return {
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": i + 1, "name": name, "item": SITE_URL + path}
            for i, (name, path) in enumerate(trail)
        ],
    }


def itemlist_schema(name, items):
    return {"@type": "ItemList", "name": name, "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": n, "url": SITE_URL + u} for i, (n, u) in enumerate(items)]}


def eyebrow(num, text):
    return f'<p class="eyebrow"><span>{num}</span>{e(text)}</p>'


def crumbs(trail):
    """trail: [(название, путь|None), …]; последний — текущая страница, без ссылки."""
    out = [f'<a href="/">{e(UI["breadcrumbs_home"])}</a>']
    for name, path in trail:
        out.append("<span>/</span>")
        out.append(f'<a href="{path}">{e(name)}</a>' if path else f"<span>{e(name)}</span>")
    return f'<nav class="crumbs rv" aria-label="Хлебные крошки">{"".join(out)}</nav>'


# ---------------------------------------------------------------- части страниц

def header():
    cats = "".join(f'<li><a href="{p_url(c["slug"])}">{e(c["name"])}</a></li>' for c in cat.CATEGORIES)
    cities = "".join(f'<li><a href="{p_url(c["slug"])}">{e(c["name"])}</a></li>' for c in cat.CITIES)
    return f"""
<header class="hdr">
  <div class="wrap hdr-in">
    {logo()}
    <nav class="nav" id="nav" aria-label="Меню">
      <div class="dd">
        <a href="/#katalog">Каталог</a>
        <ul class="dd-menu">{cats}</ul>
      </div>
      <div class="dd">
        <a href="/#goroda">Города</a>
        <ul class="dd-menu">{cities}</ul>
      </div>
      <a href="/kak-rabotaet/">Как это работает</a>
      <a href="{BLOG_PATH}">{e(BLOG_UI['nav'])}</a>
      <a class="nav-tel" href="tel:{cfg.PHONE_TEL}">{cfg.PHONE_DISPLAY}</a>
      <a class="btn btn-sm" href="#lead">{e(UI['cta'])}</a>
    </nav>
    <button class="burger" type="button" aria-controls="nav" aria-expanded="false" aria-label="Меню">{icon('menu')}</button>
  </div>
</header>"""


def contact_links():
    return (
        f'<a class="clink" href="tel:{cfg.PHONE_TEL}">{icon("phone")}{cfg.PHONE_DISPLAY}</a>'
        f'<a class="clink" href="{cfg.WHATSAPP_URL}" target="_blank" rel="noopener">{icon("whatsapp")}WhatsApp</a>'
    )


def lead_form(source, title=None, lead=None, product="", city="", supplier=False):
    """Форма заявки. product/city подставлены со страницы товара или города — их можно поменять."""
    title = title or FORM_TITLE
    lead = lead or FORM_LEAD
    options = "".join(
        f'<option value="{e(c["name"])}"{" selected" if c["name"] == city else ""}>{e(c["name"])}</option>' for c in cat.CITIES
    )
    products = "".join(f'<option value="{e(p["name"])}"></option>' for p in cat.PRODUCTS)
    if supplier:
        fields = """
      <label class="fld"><span>Компания и город</span><input type="text" name="product" required maxlength="80" placeholder="Например: металлобаза, Астана"></label>
      <label class="fld"><span>Что продаёте</span><textarea name="message" rows="2" maxlength="1000" placeholder="Арматура, профтруба, лист, доставка по городу…"></textarea></label>"""
        consent = "Согласен на обработку контактных данных для связи по сотрудничеству."
    else:
        fields = f"""
      <div class="fld-row">
        <label class="fld"><span>{e(UI['form_product'])}</span><input type="text" name="product" maxlength="80" list="products" value="{e(product)}" placeholder="Арматура, профнастил…" autocomplete="off"></label>
        <label class="fld"><span>{e(UI['form_city'])}</span><select name="city"><option value="">—</option>{options}<option value="{e(UI['form_city_other'])}">{e(UI['form_city_other'])}</option></select></label>
      </div>
      <datalist id="products">{products}</datalist>
      <label class="fld"><span>{e(UI['form_message'])}</span><textarea name="message" rows="2" maxlength="1000" placeholder="{e(UI['form_message_ph'])}"></textarea></label>"""
        consent = UI["consent"]
    return f"""
<section class="final" id="lead">
  <div class="wrap final-in">
    <div class="final-copy rv">
      <h2>{e(title)}</h2>
      <p>{e(lead)}</p>
      <div class="clinks">{contact_links()}</div>
    </div>
    <form class="form rv" action="/api/send.php" method="post" data-ok="{e(UI['form_ok'])}" data-err="{e(UI['form_err'])}" data-sending="{e(UI['form_sending'])}" data-consent-err="{e(UI['form_consent_err'])}">
      <input type="hidden" name="source" value="{e(source)}">
      <input type="hidden" name="ts" value="">
      <div class="hp" aria-hidden="true"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>{fields}
      <div class="fld-row">
        <label class="fld"><span>{e(UI['form_phone'])}</span><input type="tel" name="contact" required maxlength="18" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" data-phone data-err="{e(UI['form_phone_err'])}"></label>
        <label class="fld"><span>{e(UI['form_name'])}</span><input type="text" name="name" maxlength="80" autocomplete="name"></label>
      </div>
      <label class="consent"><input type="checkbox" name="consent" value="1" required> <span>{e(consent)} <a href="/politika-konfidencialnosti/">{e(UI['consent_link'])}</a>.</span></label>
      <button class="btn btn-lg" type="submit">{e('Отправить' if supplier else UI['form_submit'])} {icon('arrow', 'ic ic-sm')}</button>
      <p class="form-status" role="status" aria-live="polite"></p>
    </form>
  </div>
</section>"""


def footer():
    cats = "".join(f'<li><a href="{p_url(c["slug"])}">{e(c["name"])}</a></li>' for c in cat.CATEGORIES)
    cities = "".join(f'<li><a href="{p_url(c["slug"])}">Металлопрокат {e(c["in"])}</a></li>' for c in cat.CITIES)
    return f"""
<footer class="ftr">
  <div class="wrap ftr-in">
    <div class="ftr-brand">
      {logo()}
      <p>{e(cfg.NICHE['about'])}</p>
      <p><a href="tel:{cfg.PHONE_TEL}">{cfg.PHONE_DISPLAY}</a> · <a href="{cfg.WHATSAPP_URL}" target="_blank" rel="noopener">WhatsApp</a></p>
    </div>
    <div><h3>Каталог</h3><ul>{cats}</ul></div>
    <div><h3>Города</h3><ul>{cities}</ul></div>
    <div><h3>Сервис</h3><ul>
      <li><a href="/kak-rabotaet/">Как это работает</a></li>
      <li><a href="/postavshchikam/">Поставщикам</a></li>
      <li><a href="{BLOG_PATH}">{e(BLOG_UI['nav'])}</a></li>
      <li><a href="/kontakty/">Контакты</a></li>
      <li><a href="/politika-konfidencialnosti/">Политика конфиденциальности</a></li>
    </ul></div>
  </div>
  <div class="wrap ftr-bottom"><span>© {date.today().year} {cfg.BRAND_UPPER}</span><span>Цены и наличие называют поставщики. Сайт не продаёт металл.</span></div>
</footer>"""


def page(*, path, title, desc, body, schema, noindex=False, og_type="website", image=None):
    graph = {"@context": "https://schema.org", "@graph": [org_schema(), *schema]}
    robots = '<meta name="robots" content="noindex">' if noindex else ""
    return f"""<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{CLARITY_TAG}
<title>{e(title)}</title>
<meta name="description" content="{e(desc)}">
{robots}
<link rel="canonical" href="{SITE_URL}{path}">
<meta property="og:type" content="{og_type}">
<meta property="og:site_name" content="{cfg.BRAND}">
<meta property="og:locale" content="ru_KZ">
<meta property="og:title" content="{e(title)}">
<meta property="og:description" content="{e(desc)}">
<meta property="og:url" content="{SITE_URL}{path}">
<meta property="og:image" content="{SITE_URL}{image or '/assets/og.png'}">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="{FONT_URL}">
<link rel="stylesheet" href="/assets/style.css?v={ASSET_VER}">
<script>document.documentElement.classList.add('js')</script>
<script type="application/ld+json">{json.dumps(graph, ensure_ascii=False)}</script>
</head>
<body>
{header()}
<main>
{body}
</main>
{footer()}
<script src="/assets/main.js?v={ASSET_VER}" defer></script>
</body>
</html>
"""


def faq_block(items, num, title=None):
    qs = "".join(
        f'<details class="qa"><summary>{e(q)}{icon("plus", "ic qa-ic")}</summary><p>{e(a)}</p></details>'
        for q, a in items
    )
    return f"""
<section class="sec" id="faq">
  <div class="wrap split">
    <div class="split-head rv">{eyebrow(num, 'Вопросы')}<h2>{e(title or UI['faq_title'])}</h2></div>
    <div class="faq rv">{qs}</div>
  </div>
</section>"""


def flow(steps):
    items = "".join(
        f'<li style="--i:{i}"><span class="flow-n">{i + 1:02d}</span><h3>{e(t)}</h3><p>{e(d)}</p></li>'
        for i, (t, d) in enumerate(steps)
    )
    return f'<div class="flow rv" style="--n:{len(steps)}"><ol>{items}</ol></div>'


def rows(items):
    """Строки-ссылки: [(название, описание, адрес, метка справа)]."""
    out = []
    for n, (name, desc, href, tag) in enumerate(items, 1):
        out.append(
            f'<a class="row-link" href="{href}"><span class="row-n">{n:02d}</span>'
            f'<span class="row-t">{e(name)}</span><span class="row-d">{e(desc)}</span>'
            f'<span class="row-p">{e(tag)}</span>{icon("arrow", "ic row-arrow")}</a>'
        )
    return f'<div class="rows rv">{"".join(out)}</div>'


def product_rows(products):
    return rows([(p["name"], p["short"], p_url(p["slug"]), cat.category(p["cat"])["name"]) for p in products])


def category_rows(categories):
    return rows([(c["name"], c["short"], p_url(c["slug"]), "") for c in categories])


def catalog_grid():
    cards = []
    for c in cat.CATEGORIES:
        links = "".join(f'<li><a href="{p_url(p["slug"])}">{e(p["name"])}</a></li>' for p in cat.products_in(c["slug"]))
        cards.append(
            f'<div class="cat rv"><h3><a href="{p_url(c["slug"])}">{e(c["name"])}</a></h3>'
            f'<p>{e(c["short"])}</p><ul>{links}</ul></div>'
        )
    return f'<div class="cats">{"".join(cards)}</div>'


def city_chips(current=None):
    chips = "".join(
        f'<a class="chip" href="{p_url(c["slug"])}">{icon("pin", "ic ic-sm")}{e(c["name"])}</a>'
        for c in cat.CITIES if c["slug"] != current
    )
    return f'<div class="chips rv">{chips}</div>'


def data_table(t):
    head = "".join(f"<th>{e(h)}</th>" for h in t["head"])
    body = "".join("<tr>" + "".join(f"<td>{e(c)}</td>" for c in r) + "</tr>" for r in t["rows"])
    return (f'<div class="tbl rv"><table><thead><tr>{head}</tr></thead>'
            f'<tbody>{body}</tbody></table></div><p class="tbl-note rv">{e(t["note"])}</p>')


def hero(trail, h1, lead, kicker=None, cta=True):
    k = f'<p class="hero-kicker rv">{e(kicker)}</p>' if kicker else ""
    c = (f'<div class="hero-cta rv"><a class="btn btn-lg" href="#lead">{e(UI["cta"])} {icon("arrow", "ic ic-sm")}</a>'
         f'<span class="hero-note">Бесплатно · ответ обычно в течение дня</span></div>') if cta else ""
    return f"""
<section class="hero hero-svc">
  <div class="wrap">
    {crumbs(trail)}{k}
    <h1 class="rv">{e(h1)}</h1>
    <div class="hero-row">
      <p class="hero-lead rv">{e(lead)}</p>
      {c}
    </div>
  </div>
</section>"""


def section_split(num, label, title, inner, soft=False, note=""):
    t = f"<h2>{e(title)}</h2>" if title else ""
    n = f'<p class="split-note">{e(note)}</p>' if note else ""
    return f"""
<section class="sec{' sec-soft' if soft else ''}">
  <div class="wrap split">
    <div class="split-head rv">{eyebrow(num, label)}{t}{n}</div>
    {inner}
  </div>
</section>"""


def section_head(num, label, title, inner, soft=False, lead="", sid=""):
    p = f"<p>{e(lead)}</p>" if lead else ""
    i = f' id="{sid}"' if sid else ""
    return f"""
<section class="sec{' sec-soft' if soft else ''}"{i}>
  <div class="wrap">
    <div class="sec-head rv">{eyebrow(num, label)}<h2>{e(title)}</h2>{p}</div>
    {inner}
  </div>
</section>"""


def prose(paragraphs):
    return '<div class="prose rv">' + "".join(f"<p>{e(x)}</p>" for x in paragraphs) + "</div>"


def dash(items):
    return '<ul class="dash rv">' + "".join(f"<li>{e(x)}</li>" for x in items) + "</ul>"


def incl(items):
    return '<ul class="incl">' + "".join(
        f'<li class="rv"><span class="inc-n">{i:02d}</span><div><h3>{e(t)}</h3><p>{e(d)}</p></div></li>'
        for i, (t, d) in enumerate(items, 1)) + "</ul>"


# ---------------------------------------------------------------- страницы

def build_home():
    facts = "".join(f'<li><b>{e(a)}</b><span>{e(b)}</span></li>' for a, b in (
        ("Одна заявка", "получают несколько поставщиков вашего города"),
        ("Бесплатно", "для покупателя — платите только за металл"),
        (f"{len(cat.PRODUCTS)} видов проката", f"и {len(cat.CITIES)} крупных городов Казахстана"),
    ))
    popular = [p for p in cat.PRODUCTS if p.get("popular")]
    n = iter(f"{i:02d}" for i in range(1, 20))
    body = f"""
<section class="hero">
  <div class="wrap">
    <p class="hero-kicker rv">Металлопрокат · Казахстан</p>
    <h1 class="rv">Металлопрокат: цены нескольких металлобаз <em>по одной заявке</em></h1>
    <div class="hero-row">
      <p class="hero-lead rv">Арматура, профнастил, трубы, лист, балка и швеллер. Напишите, что нужно, — поставщики вашего города пришлют цены, наличие и сроки доставки. Сравните и купите напрямую.</p>
      <div class="hero-cta rv">
        <a class="btn btn-lg" href="#lead">{e(UI['cta'])} {icon('arrow', 'ic ic-sm')}</a>
        <a class="tlink" href="#katalog">Каталог</a>
      </div>
    </div>
    <ul class="facts rv">{facts}</ul>
  </div>
</section>
{section_head(next(n), 'Каталог', 'Что можно заказать', catalog_grid(), sid='katalog',
              lead='На каждой странице — размеры и марки по ГОСТ, таблица массы, как выбрать и от чего зависит цена.')}
{section_head(next(n), 'Как это работает', 'Вместо обзвона пяти баз — одна заявка', flow(HOW_STEPS), soft=True, sid='kak',
              lead='Цены на металл у разных поставщиков отличаются, а обзванивать всех долго. Мы передаём заявку тем, кто может её выполнить, — они сами присылают предложения.')}
{section_head(next(n), 'Чаще всего заказывают', 'Популярные позиции', product_rows(popular))}
{section_head(next(n), 'Города', 'Поставщики в вашем городе', city_chips(), soft=True, sid='goroda',
              lead='Заявку получают металлобазы вашего города — так доставка дешевле и быстрее.')}
<!--BLOG-HOME:{next(n)}-->
{faq_block(HOME_FAQ, next(n))}
{lead_form('home')}
"""
    return "/", page(
        path="/", title="Металлопрокат в Казахстане — цены от поставщиков | Металбаза",
        desc="Арматура, профнастил, трубы, лист и балка: одна заявка — цены, наличие и доставка от нескольких металлобаз вашего города. Бесплатно для покупателя.",
        body=body,
        schema=[
            {"@type": "WebSite", "@id": f"{SITE_URL}/#site", "url": f"{SITE_URL}/", "name": cfg.BRAND, "inLanguage": "ru"},
            faq_schema(HOME_FAQ),
        ],
    )


def build_category(c):
    path = p_url(c["slug"])
    items = cat.products_in(c["slug"])
    others = [x for x in cat.CATEGORIES if x["slug"] != c["slug"]]
    body = f"""
{hero([(c['name'], None)], c['name'], c['lead'])}
{section_split('01', c['name'], '', prose(c['intro']))}
{section_head('02', 'Позиции', f"{c['name']}: что выбрать", rows([(p['name'], p['short'], p_url(p['slug']), 'Подробнее') for p in items]), soft=True)}
{lead_form('cat:' + c['slug'])}
{section_head('03', 'Каталог', 'Другие разделы', category_rows(others))}
"""
    schema = [
        breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), (c["name"], path)]),
        itemlist_schema(c["name"], [(p["name"], p_url(p["slug"])) for p in items]),
    ]
    return path, page(path=path, title=c["meta_title"], desc=c["meta_desc"], body=body, schema=schema)


def build_product(p):
    path = p_url(p["slug"])
    c = cat.category(p["cat"])
    siblings = [x for x in cat.products_in(p["cat"]) if x["slug"] != p["slug"]]
    num = iter(f"{i:02d}" for i in range(1, 20))
    soft = iter([False, True] * 10)  # чередование фона секций
    parts = [
        hero([(c["name"], p_url(c["slug"])), (p["name"], None)], p["name"], p["lead"]),
        section_split(next(num), "Что это", "", prose(p["intro"]), soft=next(soft)),
        section_head(next(num), "Марки и виды", f"Какой бывает {p['name'].lower()}", incl(p["grades"]), soft=next(soft)),
    ]
    if p["table"]:
        parts.append(section_head(next(num), "Размеры и масса", p["table"]["title"], data_table(p["table"]), soft=next(soft), sid="razmery"))
    parts += [
        section_split(next(num), "Как выбрать", "На что смотреть при заказе", dash(p["choose"]), soft=next(soft)),
        section_split(next(num), "Цена", "От чего зависит цена", dash(p["price"]), soft=next(soft),
                      note="Цены меняются каждую неделю. Актуальную цену под ваш объём и доставку назовут поставщики — оставьте заявку."),
        section_head(next(num), "Города", f"{p['name']} в вашем городе", city_chips(), soft=next(soft),
                     lead="Выберите город — там же можно оставить заявку для поставщиков этого города."),
        faq_block(p["faq"], next(num), f"{p['name']}: частые вопросы"),
        lead_form("product:" + p["slug"], title=f"{p['name']}: цены от поставщиков", product=p["name"]),
        section_head(next(num), c["name"], "Ещё в разделе" if siblings else "Другие разделы",
                     product_rows(siblings) if siblings else category_rows([x for x in cat.CATEGORIES if x["slug"] != c["slug"]])),
    ]
    schema = [
        breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), (c["name"], p_url(c["slug"])), (p["name"], path)]),
        faq_schema(p["faq"]),
    ]
    return path, page(path=path, title=p["meta_title"], desc=p["meta_desc"], body="\n".join(parts), schema=schema)


def city_faq(c):
    return [
        (f"Есть ли доставка металла {c['in']}?",
         f"Да, доставку по городу и области считают сами поставщики. Укажите в заявке адрес объекта и нужна ли разгрузка — {c['name']} и пригороды они знают лучше нас."),
        (f"Можно ли купить металл {c['in']} с резкой в размер?",
         "Большинство металлобаз режут арматуру, трубу, профиль и лист в размер. Напишите размеры в заявке, и поставщики посчитают резку сразу."),
        (f"Почему цены {c['in']} у разных баз отличаются?",
         "У каждой базы свой производитель, закупка и склад, а ещё разная доставка. Поэтому одна заявка сразу нескольким поставщикам — самый быстрый способ найти выгодное предложение."),
        ("Можно ли заказать металл без визита на базу?",
         "Да. Поставщик пришлёт цену и счёт, а металл привезут на объект. Расплачиваетесь напрямую с поставщиком."),
    ]


def build_city(c):
    path = p_url(c["slug"])
    popular = [cat.product(s) for s in c["popular"]]
    faq = city_faq(c)
    body = f"""
{hero([(c['name'], None)], f"Металлопрокат {c['in']}", c['lead'], kicker=c['region'])}
{section_split('01', c['name'], '', prose(c['intro']))}
{section_head('02', 'Важно знать', f"Что учесть при покупке металла {c['in']}", incl(c['notes']), soft=True)}
{section_head('03', 'Чаще всего заказывают', f"Популярное {c['in']}", product_rows(popular))}
{section_head('04', 'Каталог', 'Весь каталог', catalog_grid(), soft=True)}
{faq_block(faq, '05', f"Металл {c['in']}: вопросы")}
{lead_form('city:' + c['slug'], title=f"Цены от металлобаз: {c['name']}", city=c['name'])}
{section_head('06', 'Другие города', 'Другие города', city_chips(current=c['slug']))}
"""
    schema = [breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), (c["name"], path)]), faq_schema(faq)]
    return path, page(path=path, title=c["meta_title"], desc=c["meta_desc"], body=body, schema=schema)


def build_how():
    path = "/kak-rabotaet/"
    why = prose([
        "Можно и самому. Но цены на один и тот же металл у разных баз отличаются, наличие меняется каждый день, а доставка у всех считается по-своему. Чтобы найти выгодное предложение, приходится обзванивать несколько баз и каждой объяснять, что нужно.",
        "Одна заявка на сайте заменяет этот обзвон: её получают сразу несколько поставщиков, и они сами связываются с вами. Вы сравниваете готовые предложения и покупаете напрямую — договор, оплата и доставка с поставщиком, без посредника в цепочке денег.",
        "Для покупателя сервис бесплатный. Платят поставщики — за то, что получают заявки от реальных покупателей.",
    ])
    tips = dash([
        "Что именно: вид проката, класс или марка стали, размер. Например: «арматура А500С 12 мм».",
        "Сколько: тонны, метры, штуки или листы. Если не знаете — размеры объекта, поставщик посчитает.",
        "Нужна ли резка в размер, доставка и разгрузка; адрес или район объекта.",
        "Когда нужно: срочно или к определённой дате.",
    ])
    body = f"""
{hero([('Как это работает', None)], 'Как работает Металбаза', 'Мы не склад и не магазин. Мы передаём вашу заявку металлобазам и производителям вашего города — они присылают цены и сроки, а вы выбираете лучшее предложение.')}
{section_head('01', 'Шаги', 'От заявки до металла на объекте', flow(HOW_STEPS), soft=True)}
{section_split('02', 'Зачем это нужно', 'Почему не позвонить на базу самому', why)}
{section_split('03', 'Как написать заявку', 'Чтобы цены пришли точные', tips, soft=True)}
{faq_block(HOME_FAQ, '04')}
{lead_form('how')}
"""
    schema = [breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), ("Как это работает", path)]), faq_schema(HOME_FAQ)]
    return path, page(path=path, title="Как работает Металбаза: одна заявка — цены нескольких баз",
                      desc="Как получить цены на металлопрокат от нескольких поставщиков по одной заявке: шаги, как написать заявку, кто платит и как передаются контакты.",
                      body=body, schema=schema)


def build_suppliers():
    path = "/postavshchikam/"
    what = dash([
        "Телефон покупателя, что нужно, объём и город — покупатель сам оставил заявку и согласился на передачу контактов.",
        "Заявки по вашему городу и вашим позициям: не возите — не получаете.",
        "Заявки приходят сразу в Telegram — в личку или в группу отдела продаж.",
        "Условия — за заявку или процент от сделки, обсуждаем под ваш объём.",
    ])
    where = prose([
        "Покупатели находят сайт в поиске по запросам вроде «арматура цена», «профнастил купить» и «металлопрокат в Алматы». На каждой странице — понятная информация о прокате и форма заявки.",
        "Мы не перепродаём металл и не вмешиваемся в сделку: покупатель договаривается с вами напрямую.",
    ])
    body = f"""
{hero([('Поставщикам', None)], 'Заявки на металлопрокат для металлобаз', 'Получайте заявки от покупателей вашего города: арматура, трубы, лист, профнастил, балка. Платите за заявки, а не за клики и показы рекламы.', cta=False)}
{section_split('01', 'Что вы получаете', 'Готовые заявки', what)}
{section_split('02', 'Откуда заявки', 'Поиск Google и Яндекса', where, soft=True)}
{lead_form('supplier', title='Подключиться к заявкам', lead='Оставьте контакты — расскажем об условиях и с какого города можем начать.', supplier=True)}
"""
    schema = [breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), ("Поставщикам", path)])]
    return path, page(path=path, title="Заявки на металлопрокат для металлобаз | Металбаза",
                      desc="Металлобазам и производителям: заявки покупателей металлопроката вашего города в Telegram. Оплата за заявку или процент от сделки.",
                      body=body, schema=schema)


def build_contacts():
    path = "/kontakty/"
    body = f"""
<section class="hero hero-svc">
  <div class="wrap">
    {crumbs([('Контакты', None)])}
    <h1 class="rv">Контакты</h1>
    <p class="hero-lead rv">Вопросы о сервисе, заявках и сотрудничестве — звоните или пишите в WhatsApp. Цены на металл быстрее получить через заявку: её сразу увидят поставщики.</p>
    <div class="clinks clinks-lg rv">{contact_links()}</div>
  </div>
</section>
{section_split('01', 'География', 'Где работаем', prose(['Алматы, Астана, Шымкент, Караганда, Актобе и их области. Заявки из других городов тоже принимаем — передадим поставщикам, которые туда возят.']))}
{lead_form('contacts')}
"""
    schema = [breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), ("Контакты", path)])]
    return path, page(path=path, title="Контакты | Металбаза",
                      desc="Контакты сервиса Металбаза: телефон и WhatsApp. Заявки на металлопрокат по Алматы, Астане, Шымкенту, Караганде, Актобе и другим городам.",
                      body=body, schema=schema)


def build_policy():
    path = "/politika-konfidencialnosti/"
    op = cfg.OPERATOR or f"владелец сайта {cfg.HOST}"
    sections = [
        ("Кто обрабатывает данные", f"Оператор персональных данных — {op}. Связаться: {cfg.PHONE_DISPLAY}. Политика составлена с учётом Закона Республики Казахстан «О персональных данных и их защите»."),
        ("Какие данные собираем", "Номер телефона, имя (если вы его указали), содержание заявки: товар, город, объём и комментарий, а также адрес страницы, с которой отправлена заявка. Мы не просим и не храним паспортные и платёжные данные."),
        ("Зачем", "Чтобы передать заявку поставщикам металлопроката, которые могут её выполнить, — для расчёта цены и связи с вами; чтобы вести учёт заявок и отвечать на ваши вопросы о сервисе."),
        ("Кому передаём", "Только поставщикам металлопроката (металлобазам, производителям) вашего города или региона и только после вашего согласия, которое вы даёте галочкой в форме. Поставщики используют ваши данные для ответа на заявку."),
        ("Сколько храним", "Заявки хранятся не дольше трёх лет — для учёта и расчётов с поставщиками. По вашему запросу удалим их раньше."),
        ("Ваши права", "Вы можете узнать, какие ваши данные у нас есть, исправить их, отозвать согласие и попросить удалить данные — позвоните или напишите в WhatsApp. После отзыва согласия мы перестанем передавать ваши данные; поставщикам, которые уже получили заявку, сообщим об отзыве."),
        ("Защита", "Заявки хранятся на сервере в Казахстане вне публичной части сайта, доступ — только у оператора. Соединение с сайтом защищено (HTTPS)."),
        ("Файлы cookie", "Сайт использует только технические cookie, нужные для работы. Если мы подключим сервисы веб-аналитики, это будет указано здесь."),
    ]
    text = "".join(f"<h2>{e(t)}</h2><p>{e(x)}</p>" for t, x in sections)
    body = f"""
<section class="hero hero-svc">
  <div class="wrap">
    {crumbs([('Политика конфиденциальности', None)])}
    <h1 class="rv">Политика обработки персональных данных</h1>
    <p class="hero-lead rv">Как сайт {cfg.HOST} собирает, хранит и передаёт данные из заявок.</p>
  </div>
</section>
<section class="sec">
  <div class="wrap"><div class="md md-page rv">{text}</div></div>
</section>
"""
    schema = [breadcrumbs_schema([(UI["breadcrumbs_home"], "/"), ("Политика конфиденциальности", path)])]
    return path, page(path=path, title="Политика конфиденциальности | Металбаза",
                      desc="Политика обработки персональных данных сайта metalbaza.kz: какие данные из заявок собираем, кому и зачем передаём, как отозвать согласие.",
                      body=body, schema=schema)


def build_404():
    body = f"""
<section class="hero hero-svc">
  <div class="wrap">
    <p class="hero-kicker">404</p>
    <h1>{e(UI['not_found_title'])}</h1>
    <p class="hero-lead">{e(UI['not_found_text'])}</p>
    <div class="hero-cta"><a class="btn btn-lg" href="/">{e(UI['not_found_btn'])}</a></div>
  </div>
</section>
<section class="sec"><div class="wrap">{catalog_grid()}</div></section>
"""
    return page(path="/404.html", title=f"404 — {cfg.BRAND}", desc=UI["not_found_text"], body=body, schema=[], noindex=True)


# ---------------------------------------------------------------- блог

BLOG_PATH = "/blog/"
BLOG_UI = {
    "nav": "Статьи",
    "home_title": "Статьи",
    "title": "Статьи о металлопрокате",
    "meta_title": "Статьи о металлопрокате: выбор, расчёт, цены | Металбаза",
    "meta_desc": "Как выбрать арматуру, профнастил и трубы, как посчитать вес и количество металла, из чего складывается цена — практические статьи для стройки.",
    "lead": "Как выбрать прокат, посчитать вес и количество и не переплатить: практические разборы для стройки.",
    "toc": "Содержание",
    "summary": "Коротко",
    "read": "мин чтения",
    "related": "Читайте также",
    "all": "Все статьи",
    "cta_title": "Нужен металл? Получите цены",
    "cta_lead": "Опишите, что нужно, — поставщики вашего города пришлют цены, наличие и сроки доставки.",
}


def blog_parts():
    """Детали оформления для PHP-блога (_blog/parts.json): каркас страницы с метками %%…%%,
    форма заявки, FAQ, иконки и подписи. Так шапка, подвал и стили блога всегда совпадают с сайтом."""
    shell = page(path="%%PATH%%", title="%%TITLE%%", desc="%%DESC%%", body="%%BODY%%", schema=["__SCHEMA__"],
                 og_type="%%OGTYPE%%", image="%%IMAGE%%")
    faq_item = f'<details class="qa"><summary>%%Q%%{icon("plus", "ic qa-ic")}</summary><p>%%A%%</p></details>'
    faq_wrap = faq_block([], "%%NUM%%")
    assert '<div class="faq rv"></div>' in faq_wrap
    return {
        "site_url": SITE_URL,
        "blog_path": BLOG_PATH,
        "shell": shell,
        "lead_form": lead_form("%%SOURCE%%", BLOG_UI["cta_title"], BLOG_UI["cta_lead"]),
        "faq_item": faq_item,
        "faq_wrap": faq_wrap.replace('<div class="faq rv"></div>', '<div class="faq rv">%%ITEMS%%</div>'),
        "icon_arrow_sm": icon("arrow", "ic ic-sm"),
        "ui": {**BLOG_UI, "breadcrumbs_home": UI["breadcrumbs_home"], "more": UI["more"]},
        # «услуги» движка = страницы каталога, на которые ведут статьи
        "services": [{"slug": p["slug"], "name": p["name"], "short": p["short"], "price": "цена по запросу у поставщиков"}
                     for p in cat.PRODUCTS],
    }


# ---------------------------------------------------------------- служебные файлы

def write(path, content):
    target = DIST / path.lstrip("/")
    if path.endswith("/"):
        target = target / "index.html"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding="utf-8")


def sitemap(paths):
    """Страницы сайта. Статьи блога в карту добавляет PHP на хостинге (_blog/sitemap.tpl)."""
    today = date.today().isoformat()
    rows_ = [f"<url><loc>{SITE_URL}{p}</loc><lastmod>{today}</lastmod></url>" for p in paths]
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + "\n".join(rows_) + "\n</urlset>\n")


# Роботы ИИ-поиска (ChatGPT, Perplexity, Claude, Gemini, Apple, Яндекс) — пускаем явно: на сайт
# ссылаются в ответах нейросетей только если их робот может его прочитать.
AI_BOTS = ["OAI-SearchBot", "ChatGPT-User", "GPTBot", "PerplexityBot", "Perplexity-User", "ClaudeBot",
           "Claude-SearchBot", "Claude-User", "Google-Extended", "Applebot-Extended", "YandexAdditional", "Bingbot"]
ROBOTS_RULES = "Allow: /\nDisallow: /api/\nDisallow: /admin/\nDisallow: /_blog/\n"
# IndexNow: мгновенное уведомление Bing (→ ChatGPT, Copilot) и Яндекса о новых и изменённых страницах.
INDEXNOW_KEY = "5d8e2b7a91c64f03a7e1b9c2d4f6a8e0"


def robots_txt():
    groups = ["User-agent: *\n" + ROBOTS_RULES] + [f"User-agent: {b}\n" + ROBOTS_RULES for b in AI_BOTS]
    return "\n".join(groups) + f"\nSitemap: {SITE_URL}/sitemap.xml\nHost: {SITE_URL}\n"


def llms_txt():
    """/llms.txt — краткая справка о сайте для нейросетей (формат llmstxt.org). Статьи дописывает PHP."""
    cats = []
    for c in cat.CATEGORIES:
        items = "\n".join(f"- [{p['name']}]({SITE_URL}{p_url(p['slug'])}): {p['short']}" for p in cat.products_in(c["slug"]))
        cats.append(f"### [{c['name']}]({SITE_URL}{p_url(c['slug'])})\n\n{items}")
    cities = "\n".join(f"- [Металлопрокат {c['in']}]({SITE_URL}{p_url(c['slug'])})" for c in cat.CITIES)
    return (
        f"# {cfg.BRAND_UPPER} — подбор поставщиков металлопроката в Казахстане\n\n"
        f"> {cfg.NICHE['about']} Для покупателя бесплатно.\n\n"
        f"Как это работает: {SITE_URL}/kak-rabotaet/. Заявка — форма на любой странице. "
        f"Телефон и WhatsApp {cfg.PHONE_DISPLAY}.\n\n"
        "## Каталог\n\n" + "\n\n".join(cats) + f"\n\n## Города\n\n{cities}\n\n## Статьи\n\n%%ARTICLES%%\n"
    )


# Код первой настройки админки: сам код — только в SETUP-CODE.txt на компьютере владельца, здесь — его sha256.
SETUP_CODE_SHA256 = "908187d200bded85829353397938f3ea5057a06fb129da178d94a6c08f31f091"


def site_consts():
    """Настройки сайта для общего движка (engine/, enginekit.REQUIRED и DEFAULTS) — из src/siteconf.py."""
    n = cfg.NICHE
    return {
        "SITE_CLARITY": CLARITY_ID,   # пульт берёт отсюда ссылку на проект Clarity
        "SITE_HOST": cfg.HOST, "SITE_URL": cfg.SITE_URL, "SITE_BRAND": cfg.BRAND, "SITE_BRAND_UPPER": cfg.BRAND_UPPER,
        "SITE_DATA": cfg.DATA_DIR_NAME, "SITE_INDEXNOW_KEY": INDEXNOW_KEY, "SITE_SETUP_SHA256": SETUP_CODE_SHA256,
        "SITE_COOKIE": "mb_admin", "SITE_PHONE": cfg.PHONE_DISPLAY, "SITE_TG_LABEL": cfg.TG_LABEL, "SITE_TG_HUB_UP": cfg.TG_HUB_UP,
        "SITE_LEAD_NAME": False, "SITE_LEAD_CONSENT": True,
        # для текстов ниши (site/prompts.php)
        "NICHE_ABOUT": n["about"], "NICHE_READER": n["reader"], "NICHE_CTA": n["cta"], "NICHE_AUTHOR": n["author"],
        "NICHE_SEEDS": n["seeds"], "NICHE_SEED_PATTERNS": n["patterns"], "NICHE_GEO_QUESTIONS": n["geo_questions"],
        "NICHE_BRAND_RE": n["brand_re"], "NICHE_IMAGE_STYLE": n["image_style"],
    }


def build():
    if DIST.exists():
        shutil.rmtree(DIST)
    enginekit.install(DIST)                              # общий движок: блог, ИИ, очередь статей, SEO, админка, заявки, бот
    shutil.copytree(STATIC, DIST, dirs_exist_ok=True)    # файлы сайта поверх движка

    pages = [build_home(), *(build_category(c) for c in cat.CATEGORIES), *(build_product(p) for p in cat.PRODUCTS),
             *(build_city(c) for c in cat.CITIES), build_how(), build_suppliers(), build_contacts(), build_policy()]
    paths = []
    for p, doc in pages:
        write(p, doc)
        paths.append(p)
    write("/404.html", build_404())

    enginekit.write_site_php(DIST, site_consts())
    enginekit.write_prompts(DIST, ROOT / "site" / "prompts.php")
    missing = enginekit.check_htaccess(DIST)
    if missing:
        raise SystemExit("В static/.htaccess нет правил движка: " + "; ".join(missing))
    (DIST / "_blog" / "parts.json").write_text(json.dumps(blog_parts(), ensure_ascii=False), encoding="utf-8")
    (DIST / "_blog" / "sitemap.tpl").write_text(sitemap(paths), encoding="utf-8")
    seed = ROOT / "content" / "articles"
    if seed.is_dir() and any(seed.glob("*.json")):
        shutil.copytree(seed, DIST / "_blog" / "seed")
    (DIST / "robots.txt").write_text(robots_txt(), encoding="utf-8")
    (DIST / "_blog" / "llms.tpl").write_text(llms_txt(), encoding="utf-8")
    (DIST / f"{INDEXNOW_KEY}.txt").write_text(INDEXNOW_KEY, encoding="utf-8")
    sha = subprocess.run(["git", "rev-parse", "--short", "HEAD"], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    (DIST / "version.txt").write_text(f"{sha or 'local'}\n", encoding="utf-8")
    # Токен бота — только на хостинге в tg-config.php рядом с httpdocs; в сборке — пустая заглушка.
    (DIST / "api" / "config.php").write_text("<?php\nreturn ['tg_token' => '', 'tg_chat' => ''];\n", encoding="utf-8")
    print(f"Built {len(paths)} pages → {DIST}")
    issues = seo_audit.audit(DIST)
    for i in issues:
        if i.severity != "info":
            print(f"  {i}")
    print(f"  {seo_audit.summary(issues)}")


if __name__ == "__main__":
    build()
    if "--serve" in sys.argv:
        import functools
        import http.server

        handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(DIST))
        print("http://localhost:8000")
        http.server.ThreadingHTTPServer(("127.0.0.1", 8000), handler).serve_forever()
