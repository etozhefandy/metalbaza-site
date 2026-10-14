# metalbaza.kz

SEO-сайт заявок на металлопрокат: покупатель оставляет одну заявку — её получают металлобазы его города.
Движок взят у alfimov.kz: статика собирается на Python без зависимостей, блог, админка и форма заявки — PHP на хостинге.

## Как устроено

| Что | Где |
|---|---|
| Домен, бренд, контакты, ниша для ИИ | `src/siteconf.py` (одна настройка; PHP получает её как `dist/_blog/site.php`) |
| Каталог: категории, товары, города | `src/catalog.py` |
| Структура сайта для SEO | `docs/STRUCTURE.md` |
| Шаблоны, schema.org, sitemap, robots, llms.txt | `build.py` |
| Стили и скрипты | `static/assets/` |
| Форма заявки → журнал + Telegram | `static/api/send.php`, журнал — `static/_blog/leads.php` |
| Бот заявок (привязка группы по PIN) | `static/api/_tg.php`, `tg-hook.php` |
| Блог, ИИ-автор, SEO-автопилот | `static/_blog/` (`lib.php`, `ai.php`, `seo.php`) |
| Админка | https://metalbaza.kz/admin/ — `static/admin/`, `static/_blog/admin.tpl` |
| Редиректы и закрытые адреса | `static/.htaccess` |

Страницы: главная, 5 категорий, 20 товаров, 5 городов, «Как это работает», «Поставщикам», контакты,
политика конфиденциальности. Блог — `/blog/` (статьи пишутся в админке).

## Данные на хостинге

Вне сайта, рядом с `httpdocs` (Plesk Git их не трогает): `/var/www/vhosts/alfimov.kz/metalbaza.kz/`

- `metalbaza-data/` — статьи, картинки, настройки админки, SEO-автопилот, `leads/ГГГГ-ММ.jsonl` — журнал заявок;
- `tg-config.php` — токен бота и ваш chat_id: `<?php return ['tg_token' => '…', 'tg_chat' => '…'];`

Журнал заявок — вкладка «📥 Заявки» в админке, там же выгрузка CSV для расчётов с поставщиками.
Заявка без согласия на передачу контактов не принимается (закон РК о персональных данных).

Первая настройка админки — по одноразовому коду из файла `SETUP-CODE.txt` (он лежит только на этом компьютере,
в git не попадает). Забыли пароль — удалить `metalbaza-data/settings.json` в «Файлах» Plesk и пройти настройку заново.

## Локально

```
python3 build.py                                                               # сборка в dist/ + SEO-аудит
ALFIMOV_DATA=/tmp/mb-data php -S 127.0.0.1:8092 -t dist tools/dev_router.php   # сайт с PHP
python3 -m pytest tests                                                        # тесты (и PHP, если есть php)
```

## Деплой

```
./deploy-git.sh
```

Собирает сайт, проверяет PHP и SEO-аудит, кладёт `dist/` коммитом в ветку `deploy`. Вебхук GitHub дёргает
Plesk Git (ветка `deploy` → `metalbaza.kz/httpdocs`, режим «Автоматически»; вебхук настроен в GitHub), через ~10 секунд версия на сайте:
https://metalbaza.kz/version.txt.

## Второй сайт на этом движке

Скопировать репозиторий, заменить `src/siteconf.py` и `src/catalog.py`, тексты служебных страниц в `build.py`
и цвета в `static/assets/style.css`. PHP-часть менять не нужно.
