# metalbaza-site — заметки для ИИ-агента

Сайт = свои страницы и тексты + общий движок (`engine/`, git submodule → `~/Desktop/site-engine`).
Своего пульта/hub тут нет — он только в alfimov-site, metalbaza.kz просто показан в нём как одна из карточек.

## ⚠️ Где чьё

| Что | Где править |
|---|---|
| Главная, категории, товары, города, SEO-разметка этих страниц (canonical/OG/JSON-LD/sitemap/robots — уже всё есть) | `build.py` (этот репо) |
| Домен/бренд/контакты/ниша для движка | `src/siteconf.py` → `site_consts` в `build.py` |
| Каталог: категории/товары/города | `src/catalog.py` |
| Структура сайта для SEO | `docs/STRUCTURE.md` |
| Тексты ниши для ИИ-автора (кто пишет, стиль обложек, затравки) | `site/prompts.php` (этот репо) |
| Блог, админка, ИИ-автор статей, очередь/автопилот, SEO-автопилот, CRM | **`~/Desktop/site-engine`** —
  правь там, не здесь. `engine/` тут — только синхронизированная копия (submodule). См. его
  [AGENTS.md](https://github.com/etozhefandy/site-engine/blob/main/AGENTS.md) |

Если задача про блог/админку/ИИ/SEO-автопилот — иди в `~/Desktop/site-engine`, делай там, потом синкай сюда
(ниже). Не правь `engine/static/...` прямо в этом репо напрямую — убьётся при следующем синке.

## Деплой (после правки своего или синка движка)

```
git -C engine fetch origin main -q && git -C engine checkout -B main origin/main -q   # если обновлял движок
python3 build.py
for f in engine/tests/php/test_*.php tests/php/test_*.php; do php "$f" . ; done        # все зелёные — обязательно
git add -A && git commit -m "…" && git push
./deploy-git.sh
```

**Грабли**: `git checkout main` в `engine/` тихо не обновляет его на стухшей локальной ветке — всегда
`fetch` + `checkout -B main origin/main` (см. выше). Тесты гоняй против собранного `dist/` (`python3 build.py`
первым делом), не против исходников движка.

## Прочее

- `SETUP-CODE.txt` — одноразовый код первой настройки админки, живёт только на этом компьютере, в git не
  попадает (есть в `.gitignore`).
- Репозиторий публичный — никаких токенов/ключей/диагностических эндпоинтов в коде.

Подробности и таблица файлов сайта — [README.md](README.md).
