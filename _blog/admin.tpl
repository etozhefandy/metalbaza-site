<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Блог — админка</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400&display=swap">
<style>
:root {
  --bg: #fff; --soft: #f5f6f7; --ink: #0c1011; --ink-2: #3d434a; --muted: #6b7178;
  --line: #e3e5e8; --line-2: #cfd3d8; --blue: #1769ff; --blue-h: #0f57e0;
  --ok: #0f8a4f; --warn: #b26a00; --bad: #c62828; --r: 6px;
  --font: "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --mono: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, monospace;
}
* { box-sizing: border-box; }
body { margin: 0; font: 15px/1.5 var(--font); color: var(--ink); background: var(--soft); }
button, input, select, textarea { font: inherit; color: inherit; }
.top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 12px; padding: 12px 20px; background: #fff; border-bottom: 1px solid var(--line); }
.top h1 { font-size: 16px; margin: 0; font-weight: 600; }
.top h1 span { font-family: var(--mono); font-size: 12px; color: var(--muted); font-weight: 400; margin-left: 8px; }
@media (max-width: 700px) { .top { flex-wrap: wrap; } .top h1 { width: 100%; } .top h1 span { display: none; } }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border: 1px solid var(--line-2); border-radius: var(--r); background: #fff; color: var(--ink); cursor: pointer; font-weight: 500; font-size: 14px; white-space: nowrap; text-decoration: none; }
.btn:hover { border-color: var(--ink); }
.btn-p { background: var(--blue); border-color: var(--blue); color: #fff; }
.btn-p:hover { background: var(--blue-h); border-color: var(--blue-h); }
.btn-d { color: var(--bad); }
.btn-d:hover { border-color: var(--bad); }
.btn[disabled] { opacity: .55; cursor: wait; }
.layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); min-height: calc(100vh - 57px); }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
.side { background: #fff; border-right: 1px solid var(--line); padding: 16px; }
.side .btn { width: 100%; justify-content: center; margin-bottom: 12px; }
.filters { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
.filters button { border: 1px solid var(--line); background: #fff; border-radius: 99px; padding: 3px 10px; font-size: 13px; cursor: pointer; color: var(--ink-2); }
.filters button.on { border-color: var(--ink); color: var(--ink); }
.list { list-style: none; margin: 0; padding: 0; }
.list li { padding: 10px 12px; border-radius: var(--r); cursor: pointer; border: 1px solid transparent; }
.list li:hover { background: var(--soft); }
.list li.on { border-color: var(--blue); background: #f2f6ff; }
.list .t { font-weight: 500; line-height: 1.35; }
.list .m { display: flex; gap: 8px; align-items: center; margin-top: 4px; font-size: 12px; color: var(--muted); font-family: var(--mono); }
.empty { color: var(--muted); font-size: 14px; padding: 12px; }
.badge { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 11px; font-family: var(--mono); text-transform: uppercase; letter-spacing: .03em; }
.b-draft { background: var(--soft); color: var(--muted); }
.b-scheduled { background: #fff4e0; color: var(--warn); }
.b-live { background: #e5f6ed; color: var(--ok); }
.main { padding: 20px; display: grid; gap: 16px; align-content: start; max-width: 1180px; }
.card { background: #fff; border: 1px solid var(--line); border-radius: var(--r); padding: 20px; }
.card h2 { font-size: 15px; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
.card h2 small { font-weight: 400; color: var(--muted); font-size: 13px; }
.grid { display: grid; gap: 14px; }
.g2 { grid-template-columns: 1fr 1fr; }
.g3 { grid-template-columns: 2fr 1fr 1fr; }
@media (max-width: 700px) { .g2, .g3 { grid-template-columns: 1fr; } }
label.f { display: grid; gap: 5px; font-size: 13px; color: var(--ink-2); font-weight: 500; }
label.f .hint { font-weight: 400; color: var(--muted); }
.cnt { font-family: var(--mono); font-size: 11px; color: var(--muted); float: right; font-weight: 400; }
.cnt.bad { color: var(--bad); } .cnt.ok { color: var(--ok); }
input[type=text], input[type=password], input[type=number], input[type=datetime-local], select, textarea {
  width: 100%; padding: 9px 11px; border: 1px solid var(--line-2); border-radius: var(--r); background: #fff; font-size: 14px;
}
input:focus, select:focus, textarea:focus { outline: 2px solid var(--blue); outline-offset: -1px; border-color: var(--blue); }
textarea { resize: vertical; line-height: 1.55; }
textarea.body { min-height: 520px; font-family: var(--mono); font-size: 13px; }
.row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ai { border-color: #c9d9ff; background: #f7faff; }
.ai-status { font-size: 13px; color: var(--muted); }
.faq-item { display: grid; grid-template-columns: 1fr 2fr auto; gap: 8px; margin-bottom: 8px; }
@media (max-width: 700px) { .faq-item { grid-template-columns: 1fr; } }
.x { border: 0; background: none; color: var(--muted); cursor: pointer; font-size: 18px; padding: 0 6px; }
.x:hover { color: var(--bad); }
.rel { display: grid; gap: 4px; max-height: 180px; overflow: auto; }
.rel label { display: flex; gap: 8px; font-size: 14px; align-items: center; }
.checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 14px; }
.checks li::before { display: inline-block; width: 20px; font-weight: 700; }
.checks li.ok::before { content: "✓"; color: var(--ok); }
.checks li.warn::before { content: "!"; color: var(--warn); }
.checks li.bad::before { content: "✕"; color: var(--bad); }
.tabs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
@media (max-width: 900px) { .tabs { grid-template-columns: 1fr 1fr; } }
.tabs button { text-align: left; border: 1px solid var(--line-2); background: #fff; border-radius: var(--r); padding: 10px 12px; cursor: pointer; display: grid; gap: 2px; }
.tabs button b { font-weight: 600; font-size: 14px; }
.tabs button span { font-size: 12px; color: var(--muted); }
.tabs button:hover { border-color: var(--ink); }
.tabs button.on { border-color: var(--blue); background: #f2f6ff; box-shadow: inset 0 0 0 1px var(--blue); }
.pane { display: none; }
.pane.on { display: block; }
.new-foot { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line); gap: 12px; }
label.f.inline { display: flex; align-items: center; gap: 8px; font-weight: 500; }
label.f.inline select { width: auto; }
.tabs-top { display: flex; gap: 4px; margin-right: auto; }
.tabs-top button { border: 0; background: none; padding: 8px 12px; border-radius: var(--r); font: inherit; font-size: 14px; font-weight: 500; cursor: pointer; color: var(--ink-2); }
.tabs-top button:hover { background: var(--soft); }
.tabs-top button.on { background: #f2f6ff; color: var(--blue); box-shadow: inset 0 0 0 1px var(--blue); }
.tabs-top #nav-count { font-family: var(--mono); font-size: 12px; color: var(--muted); }
.tabs-top button { white-space: nowrap; }
@media (max-width: 1250px) { .tabs-top #nav-count { display: none; } }
body.view-edit .tabs-top, body.view-settings .tabs-top { visibility: hidden; }
body.home-list #new-card, body.home-list #seo-card, body.home-new #list-card, body.home-new #seo-card,
body.home-seo #list-card, body.home-seo #new-card, body.home-seo #keys-warn { display: none; }
#leads-card { display: none; }
body.home-leads #leads-card { display: block; }
body.home-leads #list-card, body.home-leads #new-card, body.home-leads #seo-card, body.home-leads #keys-warn { display: none; }
.leads td { vertical-align: top; } .leads td small { color: var(--muted); display: block; }
.kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.kpi { border: 1px solid var(--line); border-radius: var(--r); padding: 12px 14px; }
.kpi b { display: block; font-size: 22px; font-weight: 600; }
.kpi span { font-size: 12px; color: var(--muted); }
.kpi i { font-style: normal; font-size: 12px; font-family: var(--mono); }
.up { color: var(--ok); } .down { color: var(--bad); }
.seo-sec { margin-top: 18px; }
.seo-sec h3 { font-size: 14px; margin: 0 0 8px; }
.seo-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 14px; }
.seo-list li { padding: 8px 10px; border: 1px solid var(--line); border-radius: var(--r); }
.seo-list small { color: var(--muted); font-family: var(--mono); font-size: 12px; }
.geo-row { display: flex; gap: 10px; align-items: flex-start; }
.geo-row .tag { flex: none; font-family: var(--mono); font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--soft); color: var(--muted); }
.geo-row .tag.hit { background: #e5f6ed; color: var(--ok); }
.geo-row q { display: block; color: var(--ink-2); font-size: 13px; margin-top: 4px; }
ol.steps { margin: 8px 0; padding-left: 20px; display: grid; gap: 6px; font-size: 14px; }
@media (max-width: 700px) { .kpis { grid-template-columns: 1fr 1fr 1fr; gap: 6px; } .kpi { padding: 8px; } .kpi b { font-size: 17px; } }
@media (max-width: 700px) { .tabs-top { order: 5; width: 100%; } .tabs-top button { flex: 1; } .tabs-top #nav-count { display: none; } }
.seg { display: inline-flex; border: 1px solid var(--line-2); border-radius: var(--r); overflow: hidden; margin-bottom: 14px; }
.seg button { border: 0; background: #fff; padding: 8px 14px; font-size: 14px; cursor: pointer; color: var(--ink-2); }
.seg button + button { border-left: 1px solid var(--line-2); }
.seg button.on { background: var(--blue); color: #fff; }
@media (max-width: 700px) { .seg { display: flex; } .seg button { flex: 1; } }
.tu-card { border: 1px solid var(--line); border-radius: var(--r); padding: 14px; margin-top: 12px; background: #fff; }
.tu-card h4 { margin: 0 0 4px; font-size: 15px; }
.tu-card .why { font-size: 14px; color: var(--ink-2); margin: 0 0 8px; }
.tu-card label.ch { display: flex; gap: 8px; align-items: flex-start; font-size: 14px; padding: 6px 0; border-top: 1px dashed var(--line); }
.tu-card label.ch input { margin-top: 3px; flex: none; }
.tu-card .old { color: var(--muted); text-decoration: line-through; }
.tu-card details { font-size: 13px; color: var(--ink-2); white-space: pre-wrap; }
.tu-card.done { opacity: .6; }
.t-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 14px; }
@media (max-width: 700px) { .t-cols { grid-template-columns: 1fr; } }
.t-h { font-size: 14px; margin: 0 0 8px; }
.t-sum { margin: 0; padding-left: 18px; display: grid; gap: 4px; font-size: 14px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chips button { border: 1px solid var(--line); border-radius: 4px; padding: 3px 9px; font-size: 13px; background: #fff; cursor: pointer; }
.chips button:hover { border-color: var(--blue); color: var(--blue); }
.idea .acts { display: flex; flex-direction: column; gap: 6px; flex: none; }
.idea .src { font-family: var(--mono); font-size: 11px; color: var(--muted); }
@media (max-width: 700px) { .idea .acts { flex-direction: row; width: 100%; } .idea .acts .btn { flex: 1; justify-content: center; } }
.chips button.both, .both { color: var(--blue); border-color: #c9d9ff; }
.ideas { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 8px; }
.idea { display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; padding: 12px 14px; border: 1px solid var(--line); border-radius: var(--r); }
.idea b { display: block; margin-bottom: 4px; }
.idea.used { opacity: .55; }

.idea .btn { flex: none; }
.btn-go { background: #178a4c; border-color: #178a4c; color: #fff; }
.btn-go:hover { background: #12723e; }
.cover-prev { max-width: 220px; border-radius: var(--r); border: 1px solid var(--line); display: block; margin-top: 8px; }
.toast { position: fixed; right: 20px; bottom: 20px; max-width: 460px; padding: 12px 16px; border-radius: var(--r); background: var(--ink); color: #fff; font-size: 14px; white-space: pre-line; box-shadow: 0 8px 30px rgba(0,0,0,.2); z-index: 10; }
.toast.err { background: var(--bad); }
.hidden { display: none !important; }
/* два экрана: главный (статьи, идеи, ИИ) и редактор */
.page { max-width: 1280px; margin: 0 auto; padding: 20px; }
.home, .editor { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-content: start; }
.settings { display: none; gap: 16px; align-content: start; }
body.view-edit .home, body:not(.view-edit) .editor, body.view-settings .home { display: none; }
body.view-settings .settings { display: grid; }
.warn-card { background: #fff8e6; border-color: #f0d9a8; font-size: 14px; }
.acards { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
.acard { border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; cursor: pointer; background: #fff; display: flex; flex-direction: column; transition: border-color .15s; }
.acard:hover { border-color: var(--blue); }
.acard .th { aspect-ratio: 3 / 2; background: var(--soft); object-fit: cover; width: 100%; display: block; }
.acard .th.none { display: grid; place-items: center; color: var(--muted); font-size: 13px; }
.acard .b { padding: 12px 14px 14px; display: grid; gap: 8px; }
.acard .t { font-weight: 600; line-height: 1.35; }
.acard .m { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12px; color: var(--muted); font-family: var(--mono); }
.acard .edit { color: var(--blue); font-weight: 500; font-size: 13px; }
.acards .empty { grid-column: 1 / -1; }
.ebar { position: sticky; top: 57px; z-index: 4; display: flex; gap: 8px; align-items: center; padding: 10px 14px; background: #fff; border: 1px solid var(--line); border-radius: var(--r); box-shadow: 0 4px 16px rgba(0,0,0,.05); }
.ebar-t { display: flex; gap: 10px; align-items: center; min-width: 0; }
.ebar-t { flex: 1; }
.ebar-t b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ecols { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr) 360px; align-items: start; }
@media (max-width: 1000px) { .ecols { grid-template-columns: 1fr; } .ebar { flex-wrap: wrap; } }
.ecol-main, .ecol-side { display: grid; gap: 16px; align-content: start; }
input.big { font-size: 20px; font-weight: 600; padding: 12px 14px; }
.cover-box { border: 1px dashed var(--line-2); border-radius: var(--r); overflow: hidden; background: var(--soft); }
.cover-box .cover-prev { max-width: none; width: 100%; margin: 0; border: 0; border-radius: 0; aspect-ratio: 3 / 2; object-fit: cover; }
.cover-empty { aspect-ratio: 3 / 2; display: grid; place-items: center; color: var(--muted); font-size: 14px; }
details.more summary { cursor: pointer; font-size: 13px; color: var(--ink-2); }
details.more input { margin-top: 8px; }

.spacer { flex: 1; }

/* ---------- телефон: одна колонка, крупные поля, кнопки действий внизу под большим пальцем */
@media (max-width: 700px) {
  body { font-size: 16px; }
  input[type=text], input[type=password], input[type=number], input[type=datetime-local], select, textarea { font-size: 16px; }
  .top { padding: 10px 12px; gap: 8px; }
  .top h1 { font-size: 15px; }
  .top .btn { flex: 1; justify-content: center; padding: 8px 10px; }
  .page { padding: 12px; }
  .card { padding: 16px 14px; }
  .acards { grid-template-columns: 1fr; }
  .idea { flex-direction: column; gap: 10px; }
  .idea .btn { width: 100%; justify-content: center; }
  .row > .btn, .row > label.btn { flex: 1; justify-content: center; }
  textarea.body { min-height: 60vh; }
  .ebar { top: 0; position: static; flex-wrap: wrap; box-shadow: none; }
  .ebar-t { order: -1; width: 100%; flex-basis: 100%; }
  .ebar #b-back { flex: 1; justify-content: center; }
  .ebar .spacer, .ebar #b-save, .ebar #b-now { display: none; }
  .ebar #b-preview { flex: 1; justify-content: center; }
  .mbar { display: flex !important; }
  body.view-edit .page { padding-bottom: 88px; }
  .toast { left: 12px; right: 12px; bottom: 84px; max-width: none; }
}
.mbar { display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 6; gap: 8px; padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
  background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -4px 16px rgba(0,0,0,.06); }
.mbar .btn { flex: 1; justify-content: center; padding: 12px; font-size: 15px; }
body:not(.view-edit) .mbar { display: none !important; }
</style>
</head>
<body>
<header class="top">
  <h1>Блог <span id="now"></span></h1>
  <nav class="tabs-top" id="home-nav">
    <button type="button" data-v="list">📝 Статьи <span id="nav-count"></span></button>
    <button type="button" data-v="new">✨ Новая статья</button>
    <button type="button" data-v="seo">📈 SEO</button>
    <button type="button" data-v="leads">📥 Заявки</button>
  </nav>
  <a class="btn" href="/blog/" target="_blank">Открыть блог</a>
  <button class="btn" id="b-settings">Настройки</button>
  <button class="btn" id="b-logout">Выйти</button>
</header>
<div class="page">

  <!-- ===== ГЛАВНЫЙ ЭКРАН: статьи, идеи, ИИ ===== -->
  <div class="home">
    <section class="card warn-card hidden" id="keys-warn">
      ИИ пока выключен: вставьте ключи Claude и OpenAI в <a href="#" id="keys-link">Настройках</a>. Писать и публиковать статьи вручную можно и без них.
    </section>
    <!-- Новая статья: сначала выбираем источник темы, потом пишем -->
    <section class="card ai" id="new-card">
      <h2>Новая статья <small id="model"></small></h2>
      <p class="hint" style="margin:-6px 0 12px">Откуда берём тему?</p>
      <div class="tabs" id="src-tabs" role="tablist">
        <button type="button" data-tab="post" role="tab"><b>📎 Из поста или ссылки</b><span>новость, статья, пост в Instagram</span></button>
        <button type="button" data-tab="demand" role="tab"><b>🔎 Из запросов</b><span>что ищут в Google и Яндексе</span></button>
        <button type="button" data-tab="own" role="tab"><b>✍️ Своя тема</b><span>знаю, о чём написать</span></button>
        <button type="button" data-tab="auto" role="tab"><b>✨ Придумай сам</b><span>ИИ выберет тему по спросу</span></button>
      </div>

      <div class="pane" data-pane="post">
        <div class="grid">
          <label class="f">Ссылка <span class="hint">— статья на сайте или пост в Instagram</span>
            <input type="text" id="s-url" inputmode="url" autocapitalize="off" spellcheck="false" placeholder="https://www.instagram.com/p/… или https://…">
          </label>
          <div class="row" style="gap:8px">
            <label class="btn">📎 Скриншоты<input type="file" id="s-files" accept="image/*" multiple hidden></label>
            <span class="hint" id="s-files-info">для карусели или если ссылка не открывается — до 6 шт.</span>
            <button class="x hidden" type="button" id="s-files-clear" title="Убрать скриншоты">×</button>
          </div>
          <details class="more"><summary>Вставить текст вручную</summary>
            <textarea id="s-text" rows="4" style="margin-top:8px" placeholder="Текст поста или статьи, если ссылка не открывается"></textarea>
          </details>
          <label class="f">Пожелания <span class="hint">— необязательно: акцент, для кого, какой товар показать</span>
            <input type="text" id="s-notes" placeholder="Например: для владельцев салонов, показать, что делать после нового правила">
          </label>
          <div class="row"><span class="spacer"></span><button class="btn btn-p" id="b-src">Написать статью по источнику</button></div>
        </div>
      </div>

      <div class="pane" data-pane="demand">
        <div class="seg" id="dm-mode">
          <button type="button" data-m="new" class="on">Новые темы</button>
          <button type="button" data-m="tune">Подтянуть статьи под запросы</button>
        </div>
        <div id="mode-tune" class="hidden">
          <p class="hint" style="margin:0 0 10px">Google Search Console → «Эффективность» → период «28 дней» или «3 месяца» → «Экспортировать» → «Скачать файл Excel». Загрузите файл сюда — Claude предложит, как подтянуть уже вышедшие статьи под запросы, по которым Google их показывает. Применяете вы, по галочкам.</p>
          <div class="row" style="gap:8px"><label class="btn btn-p">📄 Загрузить выгрузку<input type="file" id="tu-file" accept=".xlsx,.xls,.csv" hidden></label><span class="hint" id="tu-info"></span></div>
          <p class="ai-status" id="tu-status" style="margin:8px 0 0"></p>
          <div id="tu-result"></div>
        </div>
        <div id="mode-new">
        <div class="row" style="gap:8px">
          <input type="text" id="t-vector" placeholder="Направление (необязательно): например, забор из профнастила" style="flex:1">
          <button class="btn btn-p" id="b-trends">Найти темы</button>
        </div>
        <p class="hint" style="margin:8px 0 0">Пусто — темы по всему каталогу. С нишей — что ищут именно по ней. Частотности нет: это подсказки поисковиков, то есть то, что люди набирают.</p>
        <p class="ai-status" id="t-status" style="margin:8px 0 0"></p>
        <div id="t-result" class="hidden">
          <div class="t-cols" id="t-cols">
            <div><h3 class="t-h">Что ищут</h3><ul class="t-sum" id="t-summary"></ul></div>
            <div><h3 class="t-h">Реальные запросы <span class="hint">· нажмите, чтобы написать статью по запросу · <b class="both">●</b> есть и в Google, и в Яндексе</span></h3><div class="chips" id="t-queries"></div></div>
          </div>
        </div>
        <div class="row" style="margin-top:18px;gap:8px">
          <h3 class="t-h" style="margin:0">Темы статей</h3>
          <div class="filters" id="t-filter" style="margin:0">
            <button data-f="new" class="on">Новые</button><button data-f="rejected">Отклонённые</button><button data-f="used">Написаны</button>
          </div>
        </div>
        <p class="hint" style="margin:6px 0 0">Здесь копятся все темы, что предлагал ИИ. Отклонённые он больше не предлагает, повторы по смыслу убирает сам.
          <a href="#" id="t-dedupe">Проверить на повторы сейчас</a></p>
        <ul class="ideas" id="t-ideas"></ul>
        </div>
      </div>

      <div class="pane" data-pane="own">
        <div class="grid">
          <label class="f">Тема статьи
            <input type="text" id="g-topic" placeholder="Сколько весит арматура 12 мм и сколько метров в тонне">
          </label>
          <div class="grid g2">
            <label class="f">Поисковые запросы <span class="hint">— необязательно; через запятую, первый — главный</span>
              <textarea id="g-keys" rows="3" placeholder="вес арматуры 12, арматура 12 мм цена за тонну, сколько метров арматуры в тонне"></textarea>
            </label>
            <label class="f">Пожелания <span class="hint">— аудитория, акценты, что обязательно упомянуть</span>
              <textarea id="g-notes" rows="3" placeholder="Для владельцев кофеен и салонов. Показать пример расчёта бюджета."></textarea>
            </label>
          </div>
          <div class="row"><span class="spacer"></span><button class="btn btn-p" id="b-gen">Написать статью</button></div>
        </div>
      </div>

      <div class="pane" data-pane="auto">
        <p style="margin:0 0 12px">ИИ посмотрит, что ищут в Google и Яндексе по каталогу и что уже есть в блоге, выберет самую нужную тему и напишет статью — она откроется черновиком.</p>
        <div class="row"><span class="hint" id="auto-note"></span><span class="spacer"></span><button class="btn btn-p" id="b-auto">Придумай тему и напиши</button></div>
      </div>

      <div class="row new-foot">
        <label class="f inline">Объём<select id="g-words"><option value="1000">~1000 слов</option><option value="1500" selected>~1500 слов</option><option value="2000">~2000 слов</option><option value="2500">~2500 слов</option></select></label>
        <label class="f inline">Ритм<select id="i-week"><option value="1">1 в неделю (вт)</option><option value="2" selected>2 в неделю (вт, чт)</option><option value="3">3 в неделю (пн, ср, пт)</option></select></label>
        <span class="spacer"></span>
        <span class="ai-status" id="g-status"></span>
      </div>
    </section>

    <section class="card" id="list-card">
      <div class="row" style="margin-bottom:14px">
        <h2 style="margin:0">Статьи</h2>
        <div class="filters" id="filters">
          <button data-f="all" class="on">Все</button>
          <button data-f="draft">Черновики</button>
          <button data-f="scheduled">Запланированы</button>
          <button data-f="live">На сайте</button>
        </div>
        <span class="spacer"></span>
        <button class="btn" id="b-new">+ Вручную, без ИИ</button>
      </div>
      <ul class="acards" id="list"></ul>
    </section>

    <!-- SEO-автопилот: раз в неделю сам (без кнопок), отчёт приходит в Telegram -->
    <section class="card" id="leads-card">
      <h2>Заявки с сайта <small id="leads-count"></small></h2>
      <div class="row" style="gap:8px;margin-bottom:12px">
        <select id="leads-month"></select><span class="spacer"></span>
        <a class="btn" id="leads-csv" href="/admin/api/leads.csv">Скачать CSV (Excel)</a>
      </div>
      <div style="overflow-x:auto"><table class="leads" style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr><th align="left">Дата</th><th align="left">Телефон</th><th align="left">Что нужно</th><th align="left">Город</th><th align="left">Страница</th></tr></thead>
        <tbody id="leads-rows"></tbody></table></div>
      <p class="hint" style="margin:12px 0 0">Журнал хранится на хостинге вне сайта. Каждая заявка приходит и в Telegram; здесь — для учёта и расчётов с поставщиками.</p>
    </section>
    <section class="card" id="seo-card">
      <div class="row" style="margin-bottom:6px">
        <h2 style="margin:0">SEO-автопилот</h2>
        <span class="spacer"></span>
        <button class="btn btn-p" id="seo-run">Проверить сейчас</button>
      </div>
      <p class="hint" id="seo-status" style="margin:0 0 6px"></p>
      <p class="hint" style="margin:0 0 14px">Раз в неделю сайт сам: смотрит позиции в Google (Search Console), готовит правки статей, ставит ссылки между статьями,
        спрашивает ChatGPT и Claude, советуют ли они сайт, — и присылает отчёт в Telegram с кнопкой «Применить правки».</p>
      <div class="card warn-card hidden" id="seo-connect" style="margin-bottom:14px">
        <b>Подключите Search Console</b> — без неё не будет позиций, просадок и правок статей (проверка ИИ-ответов и ссылки работают и так).
        <a href="#" id="seo-connect-open">Как подключить →</a>
      </div>
      <div id="seo-body"></div>
      <div class="seo-sec" id="seo-tune-sec">
        <div class="row" style="margin-bottom:8px"><h3 style="margin:0">Правки статей <span class="hint">· по данным Search Console; применяете по галочкам (или кнопкой в Telegram)</span></h3>
          <span class="spacer"></span><button class="btn btn-go hidden" id="seo-apply-all" type="button">Применить всё</button></div>
        <div id="seo-tune"></div>
      </div>
      <details class="more seo-sec" id="seo-settings">
        <summary><b>Настройки автопроверки</b></summary>
        <div class="grid" style="margin-top:12px">
          <div>
            <h3 class="t-h">Search Console <span class="hint" id="seo-gsc-state"></span></h3>
            <ol class="steps" id="seo-steps">
              <li>Откройте <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com</a> под тем же Google-аккаунтом, что и Search Console → «Создать проект», имя любое (например, site-seo).</li>
              <li>Включите <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener">Google Search Console API</a> → «Включить».</li>
              <li><a href="https://console.cloud.google.com/iam-admin/serviceaccounts/create" target="_blank" rel="noopener">Создайте сервисный аккаунт</a>: имя site-seo → «Готово» (роли не нужны).</li>
              <li>Откройте его → «Ключи» → «Добавить ключ» → «Создать новый ключ» → JSON — файл скачается.</li>
              <li>Скопируйте адрес аккаунта (…@….iam.gserviceaccount.com) → <a href="https://search.google.com/search-console/users?" target="_blank" rel="noopener">Search Console → Пользователи</a> → «Добавить пользователя», разрешение «Ограниченный доступ».</li>
              <li>Загрузите скачанный файл сюда. Он хранится на хостинге вне сайта; в чат и почту его не пересылайте.</li>
            </ol>
            <div class="row" style="gap:8px">
              <label class="btn btn-p">🔑 Загрузить ключ (JSON)<input type="file" id="seo-key" accept=".json,application/json" hidden></label>
              <button class="btn" id="seo-check" type="button">Проверить доступ</button>
              <button class="btn btn-d hidden" id="seo-gsc-off" type="button">Отключить</button>
            </div>
          </div>
          <label class="f">Вопросы для проверки ИИ-ответов <span class="hint">— по одному в строке, как спросил бы ваш заказчик; пусто — стандартные</span>
            <textarea id="seo-prompts" rows="6"></textarea></label>
          <label class="f inline"><input type="checkbox" id="seo-auto"> Применять правки статей автоматически, без моей кнопки</label>
          <div class="row"><span class="spacer"></span><button class="btn btn-p" id="seo-save" type="button">Сохранить</button></div>
        </div>
      </details>
    </section>
  </div>

  <!-- ===== НАСТРОЙКИ ===== -->
  <div class="settings">
    <div class="ebar"><button class="btn" id="s-back">← Назад</button><div class="ebar-t"><b>Настройки</b></div></div>
    <section class="card">
      <h2>Ключи ИИ <small>хранятся на хостинге вне сайта, в браузер не возвращаются</small></h2>
      <div class="grid">
        <label class="f">Ключ Claude (статьи и идеи тем) <span class="hint" id="s-ant-cur"></span>
          <input type="password" id="s-ant" placeholder="sk-ant-…" autocomplete="off" spellcheck="false"></label>
        <label class="f">Ключ OpenAI (картинки) <span class="hint" id="s-oai-cur"></span>
          <input type="password" id="s-oai" placeholder="sk-…" autocomplete="off" spellcheck="false"></label>
        <p class="hint">Пустое поле — ключ не меняется. Ключи берутся на console.anthropic.com и platform.openai.com → API keys.</p>
      </div>
    </section>
    <section class="card">
      <h2>Заявки в Telegram </h2>
      <p id="tg-status" style="margin:0 0 12px">…</p>
      <div class="row" style="gap:8px">
        <button class="btn btn-p" id="tg-pin">Получить PIN в Telegram</button>
        <button class="btn hidden" id="tg-unlink">Отвязать группу</button>
      </div>
      <p class="hint" style="margin:12px 0 0">Бот общий с alfimov.kz. Привязать группу для заявок с этого сайта: 1) нажмите «Получить PIN» — бот пришлёт его вам в личку (или в боте: «🔗 Привязать группу» → metalbaza.kz); 2) добавьте бота в группу; 3) отправьте в группе PIN. У каждого сайта своя группа — заявки на продвижение сюда не попадут. Заявки всегда приходят вам в личку, а с привязанной группой — ещё и туда.</p>
    </section>
    <section class="card">
      <h2>Пароль <small id="s-login"></small></h2>
      <div class="grid g2">
        <label class="f">Текущий пароль<input type="password" id="s-pass-cur" autocomplete="current-password"></label>
        <label class="f">Новый пароль (от 10 символов)<input type="password" id="s-pass-new" autocomplete="new-password"></label>
      </div>
    </section>
    <div class="row"><button class="btn btn-p" id="s-save">Сохранить настройки</button><span class="spacer"></span>
      <a class="btn" href="/admin/api/export">Скачать копию всех статей</a></div>
  </div>

  <!-- ===== РЕДАКТОР СТАТЬИ ===== -->
  <div class="editor">
    <div class="ebar">
      <button class="btn" id="b-back">← Все статьи</button>
      <div class="ebar-t"><span class="badge" id="ed-badge"></span><b id="ed-title">Новая статья</b></div>
      <span class="spacer"></span>
      <button class="btn" id="b-preview">Предпросмотр</button>
      <button class="btn btn-p" id="b-save">Сохранить</button>
      <button class="btn btn-go" id="b-now">Опубликовать сейчас</button>
    </div>

    <div class="ecols">
      <div class="ecol-main">
        <section class="card">
          <label class="f">Заголовок статьи (h1) <span class="cnt" id="c-title"></span><input type="text" id="f-title" class="big"></label>
          <label class="f" style="margin-top:14px">Лид — подзаголовок под заголовком<textarea id="f-lead" rows="2"></textarea></label>
          <label class="f" style="margin-top:14px">«Коротко» — суть статьи для читателя<textarea id="f-summary" rows="3"></textarea></label>
        </section>

        <section class="card">
          <h2>Текст статьи <small>## раздел · ### подраздел · - список · | таблица | · **жирный** · [ссылка](/smm/)</small></h2>
          <textarea class="body" id="f-body_md"></textarea>
          <div class="row" style="margin-top:10px;gap:8px;align-items:center">
            <button class="btn" type="button" id="b-inline-img">🖼 Иллюстрация в текст (ИИ)</button>
            <span class="hint">Картинка вставится туда, где стоит курсор. ~40–80 с.</span>
          </div>
        </section>

        <section class="card">
          <h2>Вопросы и ответы (FAQ) <small>попадут на страницу и в разметку для Google</small></h2>
          <div id="faq"></div>
          <button class="btn" id="b-faq" type="button">+ Вопрос</button>
        </section>

        <section class="card">
          <h2>Перелинковка <small>блок «Читайте также»</small></h2>
          <div class="rel" id="related"></div>
        </section>

        <div class="row" style="padding:4px 0 24px"><span class="spacer"></span><button class="btn btn-d" id="b-del">Удалить статью</button></div>
      </div>

      <aside class="ecol-side">
        <section class="card">
          <h2>Публикация</h2>
          <div class="grid">
            <label class="f">Статус
              <select id="f-status"><option value="draft">Черновик</option><option value="scheduled">Запланировано</option></select>
            </label>
            <label class="f">Дата выхода <span class="hint">(Алматы)</span><input type="datetime-local" id="f-publish_at"></label>
          </div>
          <p class="ai-status" id="pub-note" style="margin:12px 0 0"></p>
        </section>

        <section class="card">
          <h2>Обложка</h2>
          <div class="cover-box" id="cover-box">
            <img class="cover-prev hidden" id="cover-prev" alt="">
            <div class="cover-empty" id="cover-empty">Обложки нет</div>
          </div>
          <input type="text" id="img-idea" placeholder="Идея картинки (необязательно — иначе ИИ возьмёт главную мысль статьи)" style="margin-top:10px">
          <p class="hint" id="img-note" style="margin:8px 0 0"></p>
          <select id="img-mode" style="margin-top:8px" title="Сюжет картинки"><option value="auto">Сюжет: авто (иногда с людьми)</option><option value="people">Сюжет: с людьми</option><option value="abstract">Сюжет: абстракция, без людей</option></select>
          <div class="row" style="margin-top:8px">
            <button class="btn btn-p" type="button" id="b-cover-ai">Сгенерировать (ИИ)</button>
            <label class="btn">Загрузить свою<input type="file" id="f-file" accept="image/jpeg,image/png,image/webp" hidden></label>
          </div>
          <details class="more"><summary>Путь к файлу</summary><input type="text" id="f-cover_path" placeholder="/blog/media/…"></details>
        </section>

        <section class="card">
          <h2>SEO-проверка</h2>
          <ul class="checks" id="checks"></ul>
        </section>

        <section class="card">
          <details class="more" open>
            <summary><b>SEO и адрес страницы</b></summary>
            <div class="grid" style="margin-top:12px">
              <label class="f">Адрес: /blog/…/ <span class="hint">— латиница и дефис</span>
                <div class="row"><input type="text" id="f-slug" style="flex:1"><button class="btn" id="b-slug" type="button" title="Из заголовка">↻</button></div>
              </label>
              <label class="f">SEO title (&lt;title&gt;) <span class="cnt" id="c-seo"></span><input type="text" id="f-seo_title" placeholder="пусто — возьмём заголовок"></label>
              <label class="f">Описание для поиска <span class="cnt" id="c-desc"></span><textarea id="f-description" rows="3"></textarea></label>
              <label class="f">Поисковые запросы<input type="text" id="f-keywords" placeholder="через запятую"></label>
              <label class="f">Раздел<input type="text" id="f-category" list="cats" placeholder="Арматура, Кровля, Расчёты…"><datalist id="cats"></datalist></label>
              <div class="grid g2">
                <label class="f">Автор<input type="text" id="f-author" placeholder="редакция"></label>
                <label class="f">Минут чтения<input type="number" id="f-read_min" min="1" placeholder="авто"></label>
              </div>
            </div>
          </details>
        </section>
      </aside>
    </div>
  </div>
</div>
<div class="mbar"><button class="btn btn-p" id="m-save">Сохранить</button><button class="btn btn-go" id="m-now">Опубликовать сейчас</button></div>
<div class="toast hidden" id="toast"></div>

<script>
const $ = id => document.getElementById(id);
const FIELDS = ["title", "slug", "seo_title", "description", "lead", "summary", "category", "body_md", "cover_path", "author", "read_min", "status", "publish_at"];
let articles = [], current = null, filter = "all", dirty = false, pendingIdea = null;

const TR = {а:"a",б:"b",в:"v",г:"g",д:"d",е:"e",ё:"e",ж:"zh",з:"z",и:"i",й:"y",к:"k",л:"l",м:"m",н:"n",о:"o",п:"p",р:"r",с:"s",т:"t",у:"u",ф:"f",х:"h",ц:"ts",ч:"ch",ш:"sh",щ:"sch",ъ:"",ы:"y",ь:"",э:"e",ю:"yu",я:"ya",ә:"a",ғ:"g",қ:"k",ң:"n",ө:"o",ұ:"u",ү:"u",һ:"h",і:"i"};
const slugify = s => [...String(s).toLowerCase()].map(ch => TR[ch] ?? ch).join("").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 80).replace(/-$/, "");
const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const STATE = {draft: ["Черновик", "b-draft"], scheduled: ["Запланирована", "b-scheduled"], live: ["На сайте", "b-live"]};

function toast(msg, err) {
  const t = $("toast"); t.textContent = msg; t.className = "toast" + (err ? " err" : "");
  clearTimeout(t._h); t._h = setTimeout(() => t.classList.add("hidden"), err ? 9000 : 4000);
}
async function api(path, opts = {}) {
  const r = await fetch(path, {headers: {"Content-Type": "application/json", "X-Admin": "1"}, credentials: "same-origin", ...opts});
  if (r.status === 401) { dirty = false; location.reload(); throw new Error("Сессия закончилась — войдите снова"); }
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(data.error || ("Ошибка " + r.status));
  return data;
}
// Долгие операции ИИ: создать задачу → запустить → опрашивать, пока не готово.
// Если запуск оборвётся по сети (телефон, туннель), задача всё равно доработает на хостинге.
async function runJob(path, body) {
  const {job} = await api(path, {method: "POST", body: JSON.stringify(body)});
  let done = null;
  api("/admin/api/run", {method: "POST", body: JSON.stringify({job})}).then(d => { done = d; }).catch(() => {});
  const t0 = Date.now();
  while (Date.now() - t0 < 11 * 60 * 1000) {
    await new Promise(r => setTimeout(r, 3000));
    const d = done || await api("/admin/api/job?id=" + job).catch(() => null);
    if (d && d.status === "done") return d.result;
    if (d && d.status === "error") throw new Error(d.error);
  }
  throw new Error("Нет ответа больше 10 минут — попробуйте ещё раз");
}
function busy(btn, on, label) {
  if (on) { btn._t = btn.textContent; btn.disabled = true; if (label) btn.textContent = label; }
  else { btn.disabled = false; btn.textContent = btn._t || btn.textContent; }
}
const fmtDate = iso => iso ? new Date(iso.length === 16 ? iso + ":00+05:00" : iso).toLocaleString("ru-RU", {day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Asia/Almaty"}) : "";

// ---------- список
async function load(selectSlug) {
  const d = await api("/admin/api/articles");
  articles = d.articles;
  $("now").textContent = "сейчас в Алматы: " + fmtDate(d.now);
  $("model").textContent = "· " + d.writer_model;
  $("cats").innerHTML = [...new Set(articles.map(a => a.category).filter(Boolean))].map(c => `<option value="${esc(c)}">`).join("");
  renderList();
  if (selectSlug) open(articles.find(a => a.slug === selectSlug) || null);
  else if (selectSlug === null) showHome();
}
function renderList() {
  const n = s => articles.filter(a => a.state === s).length;
  $("nav-count").textContent = articles.length ? `${n("draft")} черн. · ${n("scheduled")} в плане · ${n("live")} на сайте` : "";
  const items = articles.filter(a => filter === "all" || a.state === filter);
  $("list").innerHTML = items.length ? items.map(a => {
    const [label, cls] = STATE[a.state];
    const th = a.cover_path ? `<img class="th" src="${esc(a.cover_path)}" alt="" loading="lazy">` : `<div class="th none">без обложки</div>`;
    return `<li class="acard" data-slug="${esc(a.slug)}">${th}<div class="b">
      <div class="m"><span class="badge ${cls}">${label}</span>${a.publish_at ? esc(fmtDate(a.publish_at)) : ""}</div>
      <div class="t">${esc(a.title)}</div><span class="edit">Редактировать →</span></div></li>`;
  }).join("") : `<li class="empty">Статей пока нет — предложите темы или напишите статью с ИИ ниже.</li>`;
}
// Главный экран — две вкладки: «Статьи» (черновики, запланированные, вышедшие) и «Новая статья».
function setHomeView(v) {
  if (!["list", "new", "seo", "leads"].includes(v)) v = "list";
  document.body.classList.toggle("home-list", v === "list");
  document.body.classList.toggle("home-new", v === "new");
  document.body.classList.toggle("home-seo", v === "seo");
  document.body.classList.toggle("home-leads", v === "leads");
  if (v === "seo" && typeof loadSeo === "function") loadSeo();
  if (v === "leads") loadLeads();
  [...$("home-nav").children].forEach(b => b.classList.toggle("on", b.dataset.v === v));
  try { localStorage.setItem("home_view", v); } catch (e) {}
}
$("home-nav").onclick = e => {
  const b = e.target.closest("[data-v]"); if (!b) return;
  if (document.body.classList.contains("view-edit") || document.body.classList.contains("view-settings")) return;
  setHomeView(b.dataset.v); window.scrollTo(0, 0);
};
// ---------- журнал заявок
async function loadLeads(month) {
  const d = await api("/admin/api/leads" + (month ? "?m=" + encodeURIComponent(month) : ""));
  const sel = $("leads-month"), months = d.months.length ? d.months : [d.month];
  sel.innerHTML = months.map(m => `<option${m === d.month ? " selected" : ""}>${esc(m)}</option>`).join("");
  sel.onchange = () => loadLeads(sel.value);
  $("leads-csv").href = "/admin/api/leads.csv?m=" + encodeURIComponent(d.month);
  $("leads-count").textContent = `· ${d.leads.length} за ${d.month}`;
  $("leads-rows").innerHTML = d.leads.length ? d.leads.map(l => `<tr style="border-top:1px solid var(--line)">
    <td>${esc(l.at.slice(0, 16).replace("T", " "))}<small>№ ${esc(l.id)}</small></td>
    <td><a href="tel:${esc(l.phone.replace(/[^+\d]/g, ""))}">${esc(l.phone)}</a>${l.name ? `<small>${esc(l.name)}</small>` : ""}</td>
    <td>${esc(l.product || "—")}${l.message ? `<small>${esc(l.message)}</small>` : ""}</td>
    <td>${esc(l.city || "—")}</td><td><small>${esc(l.source)}</small></td></tr>`).join("")
    : `<tr><td colspan="5" class="hint" style="padding:12px 0">Заявок за этот месяц пока нет.</td></tr>`;
}
const START_VIEW = location.hash === "#seo" ? "seo" : location.hash === "#leads" ? "leads" : (() => { try { return localStorage.getItem("home_view"); } catch (e) {} })() || "list";
function showHome(view) {
  current = null; dirty = false;
  document.body.classList.remove("view-edit", "view-settings"); renderList();
  if (view) setHomeView(view);
  window.scrollTo(0, 0);
}
function showEditor() { document.body.classList.remove("view-settings"); document.body.classList.add("view-edit"); window.scrollTo(0, 0); }
$("list").onclick = e => {
  const li = e.target.closest("li[data-slug]"); if (!li) return;
  open(articles.find(a => a.slug === li.dataset.slug));
};
$("filters").onclick = e => {
  const b = e.target.closest("button"); if (!b) return;
  filter = b.dataset.f; [...$("filters").children].forEach(x => x.classList.toggle("on", x === b)); renderList();
};

// ---------- редактор
function open(a) {
  current = a;
  const v = a || {status: "draft"};
  FIELDS.forEach(f => { $("f-" + f).value = v[f] ?? ""; });
  $("f-publish_at").value = (v.publish_at || "").slice(0, 16);
  $("f-keywords").value = (v.keywords || []).join(", ");
  renderFaq(v.faq || []);
  renderRelated(v.related || []);
  $("ed-title").textContent = a ? a.title : "Новая статья";
  const [label, cls] = STATE[a ? a.state : "draft"];
  $("ed-badge").textContent = label; $("ed-badge").className = "badge " + cls;
  $("b-now").textContent = $("m-now").textContent = a && a.state === "live" ? "Сохранить на сайте" : "Опубликовать сейчас";
  $("b-del").classList.toggle("hidden", !a);
  $("img-note").textContent = "";
  showEditor();
  if (!a) { $("g-topic").value = ""; $("g-keys").value = ""; $("g-notes").value = ""; }
  dirty = false; renderList(); refresh();
}
function collect() {
  const a = {};
  FIELDS.forEach(f => { a[f] = $("f-" + f).value; });
  a.read_min = a.read_min ? Number(a.read_min) : null;
  a.keywords = $("f-keywords").value.split(/[,\n;]+/).map(s => s.trim()).filter(Boolean);
  a.faq = [...$("faq").children].map(r => [r.querySelector(".q").value.trim(), r.querySelector(".a").value.trim()]).filter(([q, x]) => q && x);
  a.related = [...$("related").querySelectorAll("input:checked")].map(i => i.value);
  return a;
}
function fill(a) {
  ["title", "slug", "seo_title", "description", "lead", "summary", "category", "body_md"].forEach(f => { if (a[f] != null) $("f-" + f).value = a[f]; });
  $("f-keywords").value = (a.keywords || []).join(", ");
  renderFaq(a.faq || []);
  renderRelated(a.related || []);
  dirty = true; refresh();
}
function renderFaq(items) {
  $("faq").innerHTML = "";
  items.forEach(([q, a]) => addFaq(q, a));
}
function addFaq(q = "", a = "") {
  const row = document.createElement("div"); row.className = "faq-item";
  row.innerHTML = `<input type="text" class="q" placeholder="Вопрос"><textarea class="a" rows="2" placeholder="Ответ"></textarea><button class="x" type="button" title="Убрать">×</button>`;
  row.querySelector(".q").value = q; row.querySelector(".a").value = a;
  row.querySelector(".x").onclick = () => { row.remove(); dirty = true; refresh(); };
  $("faq").appendChild(row);
}
function renderRelated(sel) {
  const own = current ? current.slug : $("f-slug").value;
  const others = articles.filter(a => a.slug !== own);
  $("related").innerHTML = others.length ? others.map(a => `<label><input type="checkbox" value="${esc(a.slug)}" ${sel.includes(a.slug) ? "checked" : ""}>${esc(a.title)} <span class="badge ${STATE[a.state][1]}">${STATE[a.state][0]}</span></label>`).join("")
    : `<span class="empty" style="padding:0">Других статей пока нет. Если ничего не отметить, на странице покажутся свежие статьи.</span>`;
}

// ---------- счётчики и SEO-проверка
function counter(el, text, min, max) {
  const n = text.length; el.textContent = n + (max ? ` / ${min}–${max}` : "");
  el.className = "cnt" + (max ? (n >= min && n <= max ? " ok" : n ? " bad" : "") : "");
}
function refresh() {
  const a = collect();
  counter($("c-title"), a.title, 0, 0);
  counter($("c-seo"), a.seo_title || a.title, 30, 60);
  counter($("c-desc"), a.description, 140, 160);
  const body = a.body_md, lower = (s) => s.toLowerCase();
  const words = (body.match(/[\p{L}\d]+/gu) || []).length;
  const h2 = (body.match(/^##\s+/gm) || []).length;
  const links = (body.match(/\]\(\/[^)]*\)/g) || []).length;
  const main = (a.keywords[0] || "").toLowerCase();
  const title = a.seo_title || a.title;
  const firstPara = (body.split(/\n\s*\n/).find(p => p.trim() && !p.trim().startsWith("#")) || "");
  const checks = [
    [title.length >= 30 && title.length <= 60 ? "ok" : "warn", `Title ${title.length} симв. (лучше 30–60)`],
    [a.description.length >= 140 && a.description.length <= 160 ? "ok" : (a.description ? "warn" : "bad"), `Description ${a.description.length} симв. (лучше 140–160)`],
    [main ? "ok" : "warn", main ? `Главный запрос: «${main}»` : "Не указан главный поисковый запрос"],
    ...(main ? [
      [lower(title).includes(main) ? "ok" : "warn", "Главный запрос в title"],
      [lower(firstPara).includes(main) ? "ok" : "warn", "Главный запрос в первом абзаце"],
    ] : []),
    [words >= 800 ? "ok" : words ? "warn" : "bad", `Объём: ${words} слов (лучше от 800)`],
    [h2 >= 4 ? "ok" : "warn", `Разделов ##: ${h2} (лучше от 4)`],
    [links >= 2 ? "ok" : "warn", `Внутренних ссылок: ${links} (лучше 2–4)`],
    [a.faq.length >= 3 ? "ok" : "warn", `FAQ: ${a.faq.length} вопр.`],
    [a.summary ? "ok" : "warn", "Блок «Коротко» заполнен"],
    [/^[a-z0-9]+(-[a-z0-9]+)*$/.test(a.slug) ? "ok" : "bad", "Адрес корректный"],
  ];
  $("checks").innerHTML = checks.map(([c, t]) => `<li class="${c}">${esc(t)}</li>`).join("");
  const note = $("pub-note");
  if (a.status === "draft") note.textContent = "Черновик на сайт не попадает.";
  else if (!a.publish_at) note.textContent = "Укажите дату выхода — или нажмите «Опубликовать сейчас» внизу.";
  else {
    const due = new Date(a.publish_at + ":00+05:00") <= new Date();
    note.textContent = due ? "Дата наступила — после «Сохранить» статья сразу на сайте; правки опубликованной статьи видны сразу после сохранения."
      : `Появится на сайте сама ${fmtDate(a.publish_at)} — ничего нажимать не нужно, кроме «Сохранить».`;
  }
  const cp = $("f-cover_path").value, img = $("cover-prev");
  img.classList.toggle("hidden", !cp); if (cp) img.src = cp;
  $("cover-empty").classList.toggle("hidden", !!cp);
}
document.querySelector(".editor").addEventListener("input", e => {
  if (e.target.closest(".ai")) return;
  dirty = true;
  if (e.target.id === "f-title" && !current && !$("f-slug").dataset.touched) $("f-slug").value = slugify(e.target.value);
  if (e.target.id === "f-slug") $("f-slug").dataset.touched = "1";
  refresh();
});
document.querySelector(".editor").addEventListener("change", () => refresh());
// Черновик → «Запланировано» без даты: ставим ближайший свободный слот по ритму публикаций
// (дни из «Ритма», 10:00 Алматы, день не занят другой статьёй).
$("f-status").addEventListener("change", async () => {
  if ($("f-status").value !== "scheduled" || $("f-publish_at").value) return;
  try {
    const d = await api(`/admin/api/next-slot?per_week=${$("i-week").value}&slug=${encodeURIComponent(current ? current.slug : "")}`);
    $("f-publish_at").value = d.slot; dirty = true; refresh();
    toast(`Дата выхода — ближайший свободный слот: ${fmtDate(d.slot)}. Можно поменять; сохраните статью.`);
  } catch (e) { toast(e.message, true); }
});
$("b-slug").onclick = () => { $("f-slug").value = slugify($("f-title").value); dirty = true; refresh(); };
$("b-faq").onclick = () => { addFaq(); dirty = true; };
$("b-new").onclick = () => { if (dirty && !confirm("Есть несохранённые изменения. Начать новую статью?")) return; delete $("f-slug").dataset.touched; open(null); };
window.addEventListener("beforeunload", e => { if (dirty) { e.preventDefault(); e.returnValue = ""; } });

// ---------- действия
async function save() {
  const a = collect();
  if (a.status === "scheduled" && a.publish_at) a.publish_at = a.publish_at.slice(0, 16);
  const d = await api("/admin/api/articles", {method: "POST", body: JSON.stringify({article: a, old_slug: current ? current.slug : null})});
  dirty = false;
  await load(d.article.slug);
  return d.article;
}
async function onSave(b) {
  busy(b, true, "Сохраняю…");
  try {
    const a = await save();
    loadTopics();  // тема с ключом этой статьи переходит в «Написаны»
    toast(a.state === "scheduled" ? `Сохранено · выйдет сама ${fmtDate(a.publish_at)}`
      : a.state === "live" ? `Сохранено · на сайте: ${location.host}${a.path}` : "Сохранено · черновик, на сайте не виден");
  } catch (e) { toast(e.message, true); }
  busy(b, false);
}
$("b-save").onclick = () => onSave($("b-save"));
$("m-save").onclick = () => onSave($("m-save"));
// Одной кнопкой: статус «Запланировано» + дата «сейчас» → сохранить (статья сразу на сайте).
const almatyNow = () => new Date(Date.now() - 60000).toLocaleString("sv-SE", {timeZone: "Asia/Almaty"}).slice(0, 16).replace(" ", "T");
async function onNow(b) {
  const title = $("f-title").value.trim() || "без заголовка";
  const isLive = current && current.state === "live";
  if (!confirm(isLive ? `Сохранить правки статьи «${title}» на сайте?` : `Опубликовать статью «${title}» на сайте прямо сейчас?`)) return;
  busy(b, true, "Публикую…");
  try {
    if (!isLive) { $("f-status").value = "scheduled"; $("f-publish_at").value = almatyNow(); }
    dirty = true;
    const a = await save();
    toast(`Опубликовано: ${location.host}${a.path}`);
  } catch (e) { toast(e.message, true); }
  busy(b, false);
}
$("b-now").onclick = () => onNow($("b-now"));
$("m-now").onclick = () => onNow($("m-now"));
$("b-back").onclick = () => {
  if (dirty && !confirm("Есть несохранённые изменения. Выйти без сохранения?")) return;
  showHome();
};
$("b-preview").onclick = async () => {
  const w = window.open("about:blank", "_blank");
  try { const a = dirty || !current ? await save() : current; w.location = "/admin/preview/" + a.slug + "/"; }
  catch (e) { w.close(); toast(e.message, true); }
};
$("b-del").onclick = async () => {
  if (!current || !confirm(`Удалить статью «${current.title}»? Если она на сайте — сразу пропадёт оттуда. Отменить нельзя.`)) return;
  try { await api("/admin/api/articles/" + current.slug, {method: "DELETE"}); dirty = false; await load(null); toast("Удалено"); }
  catch (e) { toast(e.message, true); }
};
// ИИ всегда пишет НОВУЮ статью: сохранённую (тем более опубликованную) он не перезаписывает.
async function generate(body, btn, label, date) {
  if (current || dirty) {
    if (dirty && !confirm(current ? `В редакторе открыта статья «${current.title}» с несохранёнными правками. Они пропадут, а ИИ напишет новую статью. Продолжить?`
      : "В редакторе есть несохранённая статья. Она пропадёт, а ИИ напишет новую. Продолжить?")) return false;
    delete $("f-slug").dataset.touched; showHome();
  }
  const st = $("g-status");
  busy(btn, true, label);
  const t0 = Date.now(), tick = setInterval(() => { st.textContent = `Claude пишет статью… ${Math.round((Date.now() - t0) / 1000)} с (обычно 1–3 мин)`; }, 1000);
  try {
    const d = await runJob("/admin/api/generate", {words: $("g-words").value, ...body});
    open(null);
    fill(d.article);
    showEditor();
    if (date) {
      $("f-status").value = "scheduled"; $("f-publish_at").value = date; refresh();
      toast(`Готово — новая статья, дата выхода ${fmtDate(date)}. Проверьте текст и нажмите «Сохранить» — выйдет сама.`);
    } else toast("Готово — это новая статья. Проверьте текст и нажмите «Сохранить»: она сохранится черновиком.");
    return true;
  } catch (e) { toast(e.message, true); return false; }
  finally { clearInterval(tick); st.textContent = ""; busy(btn, false); }
}
// ---------- вкладки «откуда тема»
function showTab(t) {
  [...$("src-tabs").children].forEach(b => b.classList.toggle("on", b.dataset.tab === t));
  document.querySelectorAll("#new-card .pane").forEach(p => p.classList.toggle("on", p.dataset.pane === t));
  try { localStorage.setItem("src_tab", t); } catch (e) {}
}
$("src-tabs").onclick = e => { const b = e.target.closest("[data-tab]"); if (b) showTab(b.dataset.tab); };
showTab((() => { try { return localStorage.getItem("src_tab"); } catch (e) {} })() || "post");
// ---------- своя тема
$("b-gen").onclick = () => {
  const topic = $("g-topic").value.trim();
  if (!topic) { toast("Впишите тему статьи", true); $("g-topic").focus(); return; }
  generate({topic, keywords: $("g-keys").value, notes: $("g-notes").value}, $("b-gen"), "Пишу…")
    .then(ok => { if (ok) { $("g-topic").value = ""; $("g-keys").value = ""; $("g-notes").value = ""; } });
};
// ---------- из поста или ссылки
let srcShots = [];
const fileB64 = file => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result.split(",")[1]); r.onerror = rej; r.readAsDataURL(file); });
function renderShots() {
  $("s-files-info").textContent = srcShots.length ? `прикреплено скриншотов: ${srcShots.length}` : "для карусели или если ссылка не открывается — до 6 шт.";
  $("s-files-clear").classList.toggle("hidden", !srcShots.length);
}
$("s-files").onchange = async e => {
  const files = [...e.target.files].slice(0, 6 - srcShots.length);
  for (const f of files) {
    if (f.size > 20 * 1024 * 1024) { toast(`${f.name}: больше 20 МБ`, true); continue; }
    srcShots.push(await fileB64(f));
  }
  e.target.value = ""; renderShots();
};
$("s-files-clear").onclick = () => { srcShots = []; renderShots(); };
$("b-src").onclick = async () => {
  const url = $("s-url").value.trim(), text = $("s-text").value.trim();
  if (!url && !text && !srcShots.length) { toast("Вставьте ссылку, текст или скриншоты", true); $("s-url").focus(); return; }
  if (url && !/^https?:\/\//i.test(url)) { toast("Ссылка должна начинаться с https://", true); return; }
  const ok = await generate({source_url: url, source_text: text, source_images: srcShots, notes: $("s-notes").value}, $("b-src"), "Читаю источник…");
  if (ok) { $("s-url").value = ""; $("s-text").value = ""; $("s-notes").value = ""; srcShots = []; renderShots(); }
};
$("f-file").onchange = async e => {
  const file = e.target.files[0]; if (!file) return;
  const data = await new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result.split(",")[1]); r.readAsDataURL(file); });
  if (file.size > 20 * 1024 * 1024) { toast("Файл больше 20 МБ", true); e.target.value = ""; return; }
  toast("Загружаю…");
  try { const d = await api("/admin/api/upload", {method: "POST", body: JSON.stringify({filename: file.name, data})}); $("f-cover_path").value = d.path; dirty = true; refresh(); toast("Обложка загружена — не забудьте сохранить статью"); }
  catch (err) { toast(err.message, true); }
  e.target.value = "";
};
async function aiImage(kind, idea, btn, label) {
  busy(btn, true, label);
  const t0 = Date.now(), tick = setInterval(() => { btn.textContent = `${label} ${Math.round((Date.now() - t0) / 1000)} с`; }, 1000);
  try {
    const r = await runJob("/admin/api/image", {
      kind, idea, mode: $("img-mode").value, title: $("f-title").value, lead: $("f-lead").value, slug: $("f-slug").value,
      summary: $("f-summary").value, body: $("f-body_md").value.slice(0, 6000)});
    if (kind === "cover") $("img-note").textContent = r.main_idea ? "Мысль картинки: " + r.main_idea : "";
    return r.path;
  } catch (e) { toast(e.message, true); return null; }
  finally { clearInterval(tick); busy(btn, false); }
}
$("b-cover-ai").onclick = async () => {
  const path = await aiImage("cover", $("img-idea").value, $("b-cover-ai"), "Рисую обложку…");
  if (path) { $("f-cover_path").value = path; dirty = true; refresh(); toast("Обложка готова — не забудьте сохранить статью"); }
};
$("b-inline-img").onclick = async () => {
  const ta = $("f-body_md"), pos = ta.selectionStart ?? ta.value.length;
  const idea = prompt("Что должно быть на картинке? Это же станет подписью под ней.");
  if (!idea || !idea.trim()) return;
  const path = await aiImage("inline", idea.trim(), $("b-inline-img"), "Рисую…");
  if (!path) return;
  const md = `\n\n![${idea.trim().replace(/[\[\]]/g, "")}](${path})\n\n`;
  ta.value = ta.value.slice(0, pos) + md + ta.value.slice(pos);
  dirty = true; refresh(); toast("Иллюстрация вставлена в текст");
};
const SERVICE_NAMES = {"armatura": "Арматура", "ugolok": "Уголок металлический", "shveller": "Швеллер", "balka": "Балка двутавровая", "krug": "Круг стальной", "kvadrat": "Квадрат стальной", "polosa": "Полоса стальная", "katanka": "Катанка", "truba-profilnaya": "Труба профильная", "truba-vgp": "Труба ВГП", "truba-elektrosvarnaya": "Труба электросварная", "list-goryachekatanyj": "Лист горячекатаный", "list-holodnokatanyj": "Лист холоднокатаный", "list-ocinkovannyj": "Лист оцинкованный", "list-riflenyj": "Лист рифлёный", "list-pvl": "Лист просечно-вытяжной (ПВЛ)", "profnastil": "Профнастил", "metallocherepica": "Металлочерепица", "setka-kladochnaya": "Сетка кладочная", "provoloka-vyazalnaya": "Проволока вязальная"};  // build.py: slug → название товара
// ---------- темы: общий список всех тем, что предлагал ИИ (написать / отклонить / вернуть)
let topics = [], topicFilter = "new";
const topicState = t => t.used ? "used" : (t.status === "rejected" ? "rejected" : "new");
const kw = t => [t.main_keyword, ...t.keywords.filter(k => k !== t.main_keyword)].join("\n");
function renderTopics() {
  const counts = {new: 0, rejected: 0, used: 0};
  topics.forEach(t => counts[topicState(t)]++);
  [...$("t-filter").children].forEach(b => { b.classList.toggle("on", b.dataset.f === topicFilter);
    b.textContent = {new: "Новые", rejected: "Отклонённые", used: "Написаны"}[b.dataset.f] + ` (${counts[b.dataset.f]})`; });
  const items = topics.filter(t => topicState(t) === topicFilter);
  $("t-ideas").innerHTML = items.length ? items.map(t => {
    const st = topicState(t);
    const acts = st === "new" ? `<button class="btn btn-p" data-write="${t.id}">Написать статью</button><button class="btn" data-reject="${t.id}">Отклонить</button>`
      : st === "rejected" ? `<button class="btn" data-restore="${t.id}">Вернуть</button>` : "";
    return `<li class="idea${st !== "new" ? " used" : ""}">
    <div><b>${esc(t.topic)}</b>
      ${st === "used" ? '<div class="hint">✓ по этой теме уже есть статья</div>' : st === "rejected" ? '<div class="hint">' + (t.reason ? "отклонена автоматически: " + esc(t.reason) : "отклонена") + '</div>' : ""}
      <div class="hint">🔑 ${esc(t.main_keyword)} · ${esc(t.intent)}${t.service ? " · → " + esc(SERVICE_NAMES[t.service] || t.service) : ""}</div>
      <div class="hint">${esc(t.why)}</div>
      <div class="hint">Запросы: ${esc(t.keywords.join(", "))}</div>
      <div class="src">${esc(t.source || "")} · ${esc(t.created || "")}</div></div>
    <div class="acts">${acts}</div></li>`;
  }).join("") : `<li class="empty">${topicFilter === "new" ? "Новых тем нет — нажмите «Найти темы»." : "Пусто."}</li>`;
}
const week = () => $("i-week").value;
const loadTopics = () => api("/admin/api/topics?per_week=" + week()).then(d => { topics = d.topics || []; renderTopics(); }).catch(() => {});
$("t-dedupe").onclick = async e => {
  e.preventDefault();
  const a = $("t-dedupe"); a.textContent = "Проверяю…";
  try { const d = await api("/admin/api/topics/dedupe?per_week=" + week(), {method: "POST"}); topics = d.topics || []; renderTopics();
    toast(d.removed ? `Убрано повторов: ${d.removed} — они в «Отклонённых» с пометкой, на что похожи` : "Повторов не нашлось"); }
  catch (err) { toast(err.message, true); }
  a.textContent = "Проверить на повторы сейчас";
};
$("t-filter").onclick = e => { const b = e.target.closest("[data-f]"); if (b) { topicFilter = b.dataset.f; renderTopics(); } };
$("t-ideas").onclick = async e => {
  const w = e.target.closest("[data-write]"), r = e.target.closest("[data-reject]"), back = e.target.closest("[data-restore]");
  const id = (w || r || back || {}).dataset?.write || (r && r.dataset.reject) || (back && back.dataset.restore);
  const t = topics.find(x => x.id === id); if (!t) return;
  if (w) { if (await generate({topic: t.topic, keywords: kw(t)}, w, "Пишу…")) loadTopics(); return; }
  try { await api("/admin/api/topics/" + t.id, {method: "POST", body: JSON.stringify({status: r ? "rejected" : "new"})});
    t.status = r ? "rejected" : "new"; renderTopics(); toast(r ? "Тема отклонена — ИИ не будет её предлагать" : "Тема вернулась в новые"); }
  catch (err) { toast(err.message, true); }
};
// ---------- последний поиск по нише: что ищут и реальные запросы (кликабельные)
function renderDemand(d, label) {
  if (!d || !d.created) return;
  $("t-result").classList.remove("hidden");
  const niche = !!d.vector;
  if (niche && !$("t-vector").value) $("t-vector").value = d.vector;
  $("t-cols").classList.toggle("hidden", !niche);
  if (niche) {
    $("t-summary").innerHTML = (d.summary || []).map(x => `<li>${esc(x)}</li>`).join("");
    $("t-queries").innerHTML = (d.top_queries || []).map(q => `<button type="button" class="${q.both ? "both" : ""}" data-q="${esc(q.q)}">${esc(q.q)}</button>`).join("");
  }
  $("t-status").textContent = (niche ? `«${d.vector}» · ` : "По всему каталогу · ") + `${d.queries} реальных запросов · ${d.created}`;
}
$("t-queries").onclick = e => {
  const b = e.target.closest("[data-q]"); if (!b) return;
  const q = b.dataset.q;
  if (!confirm(`Написать статью по запросу «${q}»? ИИ сам подберёт заголовок.`)) return;
  generate({topic: q, keywords: q}, b, "…");
};
async function findTopics(vector) {
  const b = $("b-trends"); busy(b, true, "Ищу…");
  const t0 = Date.now(), tick = setInterval(() => { $("t-status").textContent = `Собираю запросы Google и Яндекса и анализирую… ${Math.round((Date.now() - t0) / 1000)} с (обычно до минуты)`; }, 1000);
  try {
    const d = vector ? await runJob("/admin/api/trends?per_week=" + week(), {vector}) : await runJob("/admin/api/ideas?per_week=" + week(), {focus: ""});
    clearInterval(tick); renderDemand(d);
    topicFilter = "new"; await loadTopics();
    toast(`Готово: новые темы добавлены в список «Темы статей» (${(d.ideas || []).length})`);
  } catch (e) { clearInterval(tick); $("t-status").textContent = ""; toast(e.message, true); }
  busy(b, false);
}
$("b-trends").onclick = () => {
  const v = $("t-vector").value.trim();
  if (v && v.length < 3) { toast("Опишите нишу подробнее", true); return; }
  findTopics(v);
};
// ---------- режим «Подтянуть статьи под запросы» (выгрузка Search Console)
$("dm-mode").onclick = e => {
  const b = e.target.closest("[data-m]"); if (!b) return;
  [...$("dm-mode").children].forEach(x => x.classList.toggle("on", x === b));
  $("mode-tune").classList.toggle("hidden", b.dataset.m !== "tune");
  $("mode-new").classList.toggle("hidden", b.dataset.m === "tune");
};
let XLSXlib = null;
function loadXlsx() {
  return XLSXlib ? Promise.resolve(XLSXlib) : new Promise((res, rej) => {
    const sc = document.createElement("script");
    sc.src = "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js";
    sc.onload = () => res(XLSXlib = window.XLSX); sc.onerror = () => rej(new Error("Не загрузился модуль чтения Excel — проверьте интернет"));
    document.head.appendChild(sc);
  });
}
// листы «Запросы» и «Страницы»: [текст, показы, позиция] — колонки ищем по заголовкам (рус/англ)
function gscRows(rows) {
  const head = (rows[0] || []).map(h => String(h || "").toLowerCase());
  const col = re => head.findIndex(h => re.test(h));
  const imp = col(/показ|impression/), pos = col(/позиц|position/);
  return rows.slice(1).filter(r => r && r[0]).map(r => [String(r[0]), +r[imp] || 0, +r[pos] || 0]);
}
$("tu-file").onchange = async e => {
  const file = e.target.files[0]; e.target.value = ""; if (!file) return;
  $("tu-info").textContent = file.name;
  try {
    const X = await loadXlsx();
    const wb = X.read(await file.arrayBuffer(), {type: "array"});
    let queries = [], pages = [];
    wb.SheetNames.forEach(n => {
      const rows = X.utils.sheet_to_json(wb.Sheets[n], {header: 1});
      const h = String((rows[0] || [])[0] || "").toLowerCase();
      if (/запрос|quer/.test(h)) queries = gscRows(rows);
      else if (/страниц|page/.test(h)) pages = gscRows(rows);
    });
    if (!queries.length) throw new Error("В файле не нашлось листа с запросами — нужна выгрузка «Эффективность» из Search Console");
    const t0 = Date.now(), tick = setInterval(() => { $("tu-status").textContent = `Claude разбирает ${queries.length} запросов и статьи… ${Math.round((Date.now() - t0) / 1000)} с (обычно 1–2 мин)`; }, 1000);
    try { renderTune(await runJob("/admin/api/tune", {queries, pages})); }
    finally { clearInterval(tick); }
    loadTopics();
  } catch (err) { $("tu-status").textContent = ""; toast(err.message, true); }
};
let tune = null;
function renderTune(d) {
  tune = d;
  if (!d || !d.articles) return;
  const pending = d.articles.filter(a => !a.applied).length;
  $("seo-tune").innerHTML = "";
  $("tu-status").textContent = d.created ? `Разбор от ${d.created}: ${d.queries} запросов · статей к доработке: ${d.articles.length}` + (d.new_topics ? ` · новых тем в «Темах статей»: ${d.new_topics}` : "") : "";
  $("tu-result").innerHTML = d.articles.length ? d.articles.map((a, i) => {
    const ch = [];
    if (a.seo_title) ch.push(`<label class="ch"><input type="checkbox" data-k="seo_title" checked><span><b>SEO title:</b> <span class="old">${esc(a.old.seo_title)}</span> → ${esc(a.seo_title)} <small class="cnt">${a.seo_title.length} симв.</small></span></label>`);
    if (a.description) ch.push(`<label class="ch"><input type="checkbox" data-k="description" checked><span><b>Описание:</b> ${esc(a.description)}</span></label>`);
    if (a.add_keywords.length) ch.push(`<label class="ch"><input type="checkbox" data-k="add_keywords" checked><span><b>Запросы:</b> ${esc(a.add_keywords.join(", "))}</span></label>`);
    a.sections.forEach((x, j) => ch.push(`<label class="ch"><input type="checkbox" data-k="sections" data-j="${j}" checked><span><b>Новый раздел:</b> ${esc(x.h2)}<details><summary>текст</summary>${esc(x.text)}</details></span></label>`));
    a.faq.forEach((x, j) => ch.push(`<label class="ch"><input type="checkbox" data-k="faq" data-j="${j}" checked><span><b>FAQ:</b> ${esc(x.q)}<details><summary>ответ</summary>${esc(x.a)}</details></span></label>`));
    return `<div class="tu-card${a.applied ? " done" : ""}" data-i="${i}"><h4><a href="${esc(a.path)}" target="_blank">${esc(a.title)}</a></h4>
      <p class="why">${esc(a.why)}</p><div class="hint">Запросы: ${esc(a.queries.slice(0, 12).join(", "))}</div>${ch.join("")}
      <div class="row" style="margin-top:10px"><span class="spacer"></span>${a.applied ? `<button class="btn" disabled>✓ Применено</button>` : `<button class="btn btn-p" data-apply="${i}">Применить выбранное</button>`}</div></div>`;
  }).join("") : `<p class="empty">Подтягивать пока нечего — статьи ещё далеко от первой страницы. Попробуйте выгрузку за 3 месяца через пару недель.</p>`;
  $("seo-tune").innerHTML = d.articles.length ? $("tu-result").innerHTML : `<p class="empty" style="padding:0">Пока нет — появятся после проверки с подключённой Search Console.</p>`;
  $("seo-apply-all").classList.toggle("hidden", !pending);
  $("seo-apply-all").textContent = `Применить всё (${pending})`;
  $("seo-tune-sec").querySelector(".hint").textContent = pending ? ` · ждут применения: ${pending}` : " · по данным Search Console; применяете по галочкам (или кнопкой в Telegram)";
}
// Отмеченные галочками правки одной статьи → на сайт. → false, если ничего не отмечено.
async function applyCard(card, a) {
  const on = k => [...card.querySelectorAll(`input[data-k="${k}"]:checked`)];
  const changes = {
    seo_title: on("seo_title").length ? a.seo_title : "", description: on("description").length ? a.description : "",
    add_keywords: on("add_keywords").length ? a.add_keywords : [],
    sections: on("sections").map(x => a.sections[+x.dataset.j]), faq: on("faq").map(x => a.faq[+x.dataset.j]),
  };
  if (!changes.seo_title && !changes.description && !changes.add_keywords.length && !changes.sections.length && !changes.faq.length) return false;
  await api("/admin/api/tune/apply", {method: "POST", body: JSON.stringify({slug: a.slug, changes})});
  a.applied = true;
  return true;
}
$("tu-result").onclick = $("seo-tune").onclick = async e => {
  const b = e.target.closest("[data-apply]"); if (!b) return;
  busy(b, true, "Применяю…");
  try {
    if (!await applyCard(b.closest(".tu-card"), tune.articles[+b.dataset.apply])) { busy(b, false); toast("Ничего не отмечено", true); return; }
    renderTune(tune); load(undefined).catch(() => {});
    toast("Статья обновлена на сайте. Bing и Яндекс уже уведомлены; в Search Console можно «Запросить индексирование».");
  } catch (err) { busy(b, false); toast(err.message, true); }
};
// «Применить всё»: все статьи сразу, с учётом снятых галочек.
$("seo-apply-all").onclick = async () => {
  const b = $("seo-apply-all"); busy(b, true, "Применяю…");
  let n = 0;
  try {
    for (const card of $("seo-tune").querySelectorAll(".tu-card:not(.done)")) {
      const a = tune.articles[+card.dataset.i];
      if (await applyCard(card, a)) n++;
    }
    toast(n ? `Правки применены в ${n} стат. — уже на сайте, Bing и Яндекс уведомлены.` : "Ничего не отмечено");
  } catch (err) { toast(err.message, true); }
  busy(b, false); renderTune(tune); load(undefined).catch(() => {});
};
api("/admin/api/tune").then(d => { if (d && d.created) renderTune(d); }).catch(() => {});
// ---------- придумай сам: первая новая тема из списка (нет — ищем по всему каталогу) → сразу пишем
$("b-auto").onclick = async () => {
  const b = $("b-auto");
  await loadTopics();
  let t = topics.find(x => topicState(x) === "new");
  if (!t) {
    busy(b, true, "Выбираю тему…");
    $("auto-note").textContent = "Смотрю спрос в Google и Яндексе… обычно до минуты";
    try { renderDemand(await runJob("/admin/api/ideas?per_week=" + week(), {focus: ""})); await loadTopics(); }
    catch (e) { busy(b, false); $("auto-note").textContent = ""; toast(e.message, true); return; }
    busy(b, false);
    t = topics.find(x => topicState(x) === "new");
  }
  if (!t) { $("auto-note").textContent = ""; toast("Свободных тем не нашлось — попробуйте «Из запросов» с нишей", true); return; }
  $("auto-note").textContent = "Тема: " + t.topic;
  if (await generate({topic: t.topic, keywords: kw(t)}, b, "Пишу…")) loadTopics();
};
try { $("i-week").value = localStorage.getItem("per_week") || "2"; } catch (e) {}
$("i-week").onchange = () => { try { localStorage.setItem("per_week", week()); } catch (e) {} loadTopics(); };
api("/admin/api/trends?per_week=" + week()).then(renderDemand).catch(() => {});
loadTopics();
// ---------- SEO-автопилот
let seo = null, seoPoll = null;
const fmtN = n => Number(n || 0).toLocaleString("ru-RU");
const pathLink = p => `<a href="${esc(p)}" target="_blank">${esc(p)}</a>`;
function stepLabel(st) {
  const [k, a, i] = st.split(":");
  if (k === "gsc") return "забираю данные Search Console";
  if (k === "tune") return "Claude готовит правки статей (1–2 мин)";
  if (k === "links") return "расставляю ссылки между статьями";
  if (k === "geo") return `спрашиваю ${a === "claude" ? "Claude" : "ChatGPT"}: «${(seo.geo_prompts || [])[+i] || ""}»`;
  if (k === "report") return "собираю отчёт";
  return st;
}
function kpi(label, now, was, better, fmt = fmtN) {
  let d = "";
  if (was !== undefined && was !== null && now !== was) {
    const good = better === "up" ? now > was : now < was;
    d = `<i class="${good ? "up" : "down"}">${now > was ? "▲" : "▼"} было ${fmt(was)}</i>`;
  }
  return `<div class="kpi"><span>${label}</span><b>${fmt(now)}</b>${d}</div>`;
}
async function loadSeo() {
  try { seo = await api("/admin/api/seo"); renderSeo(); }
  catch (e) { $("seo-status").textContent = e.message; }
}
function renderSeo() {
  const r = seo.report, an = r && r.analysis;
  $("seo-status").textContent = seo.running
    ? `⏳ Идёт проверка: шаг ${seo.running.i + 1} из ${seo.running.n} — ${stepLabel(seo.running.step)}. Можно закрыть страницу: доработает сама.`
    : seo.last ? `Последняя проверка: ${seo.last}. Следующая — сама, ${seo.next}.` + (seo.telegram ? " Отчёт приходит в Telegram." : "")
    : "Первая проверка запустится сама в ближайшие минуты — или нажмите «Проверить сейчас».";
  $("seo-run").disabled = !!seo.running;
  if (!tune || !tune.articles) $("seo-tune").innerHTML = `<p class="empty" style="padding:0">Пока нет — появятся после проверки с подключённой Search Console.</p>`;
  $("seo-connect").classList.toggle("hidden", !!seo.gsc);
  $("seo-gsc-state").textContent = seo.gsc ? `· подключена: ${seo.gsc}` : "· не подключена";
  $("seo-gsc-off").classList.toggle("hidden", !seo.gsc);
  $("seo-steps").classList.toggle("hidden", !!seo.gsc);
  if (document.activeElement !== $("seo-prompts")) $("seo-prompts").value = seo.geo_custom ? seo.geo_prompts.join("\n") : "";
  $("seo-prompts").placeholder = seo.geo_prompts.join("\n");
  $("seo-auto").checked = !!seo.auto_apply;
  if (seo.running && !seoPoll) seoPoll = setInterval(loadSeo, 5000);
  if (!seo.running && seoPoll) { clearInterval(seoPoll); seoPoll = null; api("/admin/api/tune").then(renderTune).catch(() => {}); }
  const out = [];
  if (an) {
    const [c, p] = [an.totals.cur, an.totals.prev];
    out.push(`<p class="hint" style="margin:0 0 8px">Google, ${an.period[0]} — ${an.period[1]} (28 дней), в скобках — 28 дней до этого</p>
      <div class="kpis">${kpi("Клики", c.clicks, p.impr ? p.clicks : null, "up")}${kpi("Показы", c.impr, p.impr ? p.impr : null, "up")}${kpi("Средняя позиция", c.pos, p.impr ? p.pos : null, "down", x => String(x))}</div>`);
    const sec = (title, hint, items, row) => items && items.length ? `<div class="seo-sec"><h3>${title} <span class="hint">· ${hint}</span></h3><ul class="seo-list">${items.map(row).join("")}</ul></div>` : "";
    out.push(sec("🎯 Близко к топу", "4–20 место: подтянуть статью — и пойдут клики", an.quick, q => `<li>«${esc(q.q)}» <small>${q.pos} место · ${fmtN(q.impr)} показов</small><br>${pathLink(q.page)}</li>`));
    out.push(sec("📉 Просели", "показы или клики упали за месяц", an.decay, d => `<li>${pathLink(d.page)} <small>показы ${d.impr[0]} → ${d.impr[1]} · клики ${d.clicks[0]} → ${d.clicks[1]}</small></li>`));
    out.push(sec("⚔️ Мешают друг другу", "по одному запросу Google показывает две страницы и не может выбрать", an.cannibal, c2 => `<li>«${esc(c2.q)}»<br>${c2.pages.map(x => `${pathLink(x[0])} <small>${x[1]} показов, ${x[2]} место</small>`).join("<br>")}</li>`));
    out.push(sec("👀 Показы есть, кликов мало", "заголовок и описание не цепляют", an.low_ctr, l => `<li>${pathLink(l.page)} <small>${l.pos} место · CTR ${l.ctr}% · ${fmtN(l.impr)} показов</small></li>`));
  } else if (r) {
    out.push(`<p class="hint">Данных Google в отчёте нет — подключите Search Console.</p>`);
  }
  if (r && r.geo && r.geo.length) {
    const byPrompt = {};
    r.geo.forEach(g => (byPrompt[g.prompt] = byPrompt[g.prompt] || []).push(g));
    out.push(`<div class="seo-sec"><h3>🤖 ИИ-ответы <span class="hint">· сайт упомянули в ${r.geo_hits} из ${r.geo.length} ответов</span></h3><ul class="seo-list">` +
      Object.entries(byPrompt).map(([q, gs]) => `<li>${esc(q)}<div class="row" style="gap:6px;margin-top:6px">` +
        gs.map(g => `<span class="geo-row"><span class="tag${g.mentioned || g.cited ? " hit" : ""}">${g.engine === "claude" ? "Claude" : "ChatGPT"}: ${g.cited ? "ссылается на сайт" : g.mentioned ? "упоминает" : "нет"}</span></span>`).join("") +
        `</div>${gs.filter(g => g.excerpt).map(g => `<q>${esc(g.excerpt)}</q>`).join("")}</li>`).join("") + `</ul>` +
      (Object.keys(r.geo_sites || {}).length ? `<p class="hint" style="margin:8px 0 0">Чаще всего ИИ ссылается на: ${Object.entries(r.geo_sites).map(([h, n]) => `${esc(h)} (${n})`).join(", ")}. Попасть на эти сайты (каталоги, рейтинги, статьи) — самый прямой путь в ИИ-ответы.</p>` : "") + `</div>`);
  }
  if (seo.links.length) out.push(`<div class="seo-sec"><h3>🔗 Ссылки между статьями <span class="hint">· ставятся сами, текст статей не меняется</span></h3><ul class="seo-list">` +
    seo.links.map(l => `<li>${esc(l.from)} → <b>${esc(l.to)}</b> <small>по фразе «${esc(l.anchor)}»</small></li>`).join("") + `</ul></div>`);
  if (r && r.lint && r.lint.length) out.push(`<div class="seo-sec"><h3>🧰 Замечания по статьям</h3><ul class="seo-list">` +
    r.lint.map(x => `<li><a href="#" data-open="${esc(x.slug)}">${esc(x.title)}</a><br><small>${esc(x.issues.join(" · "))}</small></li>`).join("") + `</ul></div>`);
  if (r && r.errors && r.errors.length) out.push(`<p class="hint" style="margin-top:14px">⚠️ Не получилось: ${esc(r.errors.join("; "))}</p>`);
  $("seo-body").innerHTML = out.join("");
}
$("seo-body").onclick = e => {
  const a = e.target.closest("[data-open]"); if (!a) return;
  e.preventDefault(); open(articles.find(x => x.slug === a.dataset.open) || null);
};
$("seo-run").onclick = async () => {
  try { seo = await api("/admin/api/seo/run", {method: "POST"}); renderSeo(); toast("Проверка запущена. Займёт 5–15 минут; отчёт придёт в Telegram и появится здесь."); }
  catch (e) { toast(e.message, true); }
};
$("seo-connect-open").onclick = e => { e.preventDefault(); $("seo-settings").open = true; $("seo-settings").scrollIntoView({behavior: "smooth"}); };
async function seoSettings(body, okMsg) {
  try { const d = await api("/admin/api/seo/settings", {method: "POST", body: JSON.stringify(body)}); seo = d; renderSeo(); toast(okMsg || d.message); }
  catch (e) { toast(e.message, true); }
}
$("seo-key").onchange = async e => {
  const f = e.target.files[0]; e.target.value = ""; if (!f) return;
  if (f.size > 20000) { toast("Это не похоже на ключ — нужен небольшой JSON-файл", true); return; }
  await seoSettings({gsc_key: await f.text()});
};
$("seo-check").onclick = async () => {
  const b = $("seo-check"); busy(b, true, "Проверяю…");
  try { toast((await api("/admin/api/seo/gsc-check", {method: "POST"})).message); } catch (e) { toast(e.message, true); }
  busy(b, false);
};
$("seo-gsc-off").onclick = () => { if (confirm("Отключить Search Console? Ключ удалится с хостинга.")) seoSettings({gsc_remove: true}, "Search Console отключена"); };
$("seo-save").onclick = () => seoSettings({geo_prompts: $("seo-prompts").value, auto_apply: $("seo-auto").checked}, "Настройки сохранены");
setHomeView(START_VIEW);

// ---------- настройки и выход
async function showSettings() {
  if (dirty && !confirm("Есть несохранённые изменения. Уйти без сохранения?")) return;
  dirty = false;
  document.body.classList.remove("view-edit"); document.body.classList.add("view-settings"); window.scrollTo(0, 0);
  try {
    const s = await api("/admin/api/settings");
    $("s-ant-cur").textContent = s.anthropic_key ? `— сейчас ${s.anthropic_key}` : "— не задан";
    $("s-oai-cur").textContent = s.openai_key ? `— сейчас ${s.openai_key}` : "— не задан";
    $("s-login").textContent = "логин: " + s.login;
  } catch (e) { toast(e.message, true); }
}
async function tgStatus() {
  try {
    const t = await api("/admin/api/telegram");
    $("tg-status").textContent = !t.ready ? "Бот не настроен на хостинге." : t.group ? `Заявки приходят вам в личку и в группу «${t.title}».` : "Заявки приходят вам в личку. Можно добавить группу — тогда будут приходить и туда.";
    $("tg-unlink").classList.toggle("hidden", !t.group);
  } catch (e) { $("tg-status").textContent = e.message; }
}
$("tg-pin").onclick = async () => {
  const b = $("tg-pin"); busy(b, true, "Отправляю…");
  try { await api("/admin/api/telegram/pin", {method: "POST"}); toast("PIN отправлен вам в Telegram. Добавьте бота в группу и напишите там /link PIN"); }
  catch (e) { toast(e.message, true); }
  busy(b, false);
};
$("tg-unlink").onclick = async () => {
  if (!confirm("Отвязать группу? Заявки останутся у вас в личке.")) return;
  try { await api("/admin/api/telegram/unlink", {method: "POST"}); toast("Группа отвязана — заявки только в личку"); tgStatus(); } catch (e) { toast(e.message, true); }
};
$("b-settings").onclick = () => { showSettings(); tgStatus(); };
$("keys-link").onclick = e => { e.preventDefault(); showSettings(); tgStatus(); };
$("s-back").onclick = () => { document.body.classList.remove("view-settings"); checkKeys(); };
$("s-save").onclick = async () => {
  const b = $("s-save"); busy(b, true, "Сохраняю…");
  try {
    const d = await api("/admin/api/settings", {method: "POST", body: JSON.stringify({
      anthropic_key: $("s-ant").value, openai_key: $("s-oai").value,
      password_current: $("s-pass-cur").value, password_new: $("s-pass-new").value})});
    ["s-ant", "s-oai", "s-pass-cur", "s-pass-new"].forEach(id => { $(id).value = ""; });
    toast(d.changed.length ? "Сохранено: " + d.changed.join(", ") : "Ничего не изменилось");
    await showSettings();
  } catch (e) { toast(e.message, true); }
  busy(b, false);
};
$("b-logout").onclick = async () => {
  if (dirty && !confirm("Есть несохранённые изменения. Выйти?")) return;
  dirty = false;
  await api("/admin/api/logout", {method: "POST"}).catch(() => {});
  location.reload();
};
async function checkKeys() {
  try { const s = await api("/admin/api/settings"); $("keys-warn").classList.toggle("hidden", !!(s.anthropic_key && s.openai_key)); } catch (e) {}
}
checkKeys();

load(null).catch(e => toast(e.message, true));
</script>
</body>
</html>
