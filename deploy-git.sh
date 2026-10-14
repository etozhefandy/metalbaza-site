#!/bin/bash
# Собирает сайт и публикует готовый dist/ в ветку `deploy` на GitHub.
# Коммиты добавляются поверх текущей ветки (без force-push), чтобы Plesk Git
# (ветка deploy → /httpdocs) спокойно подтягивал их кнопкой «Получить сейчас».
set -euo pipefail
cd "$(dirname "$0")"
REPO="$PWD"
HOST=$(python3 -c "import sys; sys.path.insert(0, 'src'); import siteconf; print(siteconf.HOST)")
python3 build.py
# PHP-блог и админка: синтаксис и тесты (если php есть на этом компьютере).
if command -v php >/dev/null; then
  for f in $(find dist -name '*.php'); do php -l "$f" >/dev/null || { echo "Ошибка PHP в $f"; exit 1; }; done
  for t in tests/php/test_*.php; do php "$t" >/dev/null || { php "$t" | grep FAIL; echo "Выкладка остановлена: $t"; exit 1; }; done
fi
# Критичные SEO-ошибки (битые ссылки, дубли title, нет H1) — не выкладываем.
python3 seo_audit.py >/dev/null || { python3 seo_audit.py | grep '\[critical\]'; echo 'Выкладка остановлена: исправьте critical-ошибки SEO-аудита'; exit 1; }
REMOTE="$(git remote get-url origin)"
SRC_SHA="$(git rev-parse --short HEAD)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git clone -q --branch deploy --single-branch "$REMOTE" "$TMP"
find "$TMP" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R dist/. "$TMP"/
cd "$TMP"
git add -A
if git diff --cached --quiet; then
  echo "Ветка deploy уже актуальна"
  exit 0
fi
git -c user.name="deploy" -c user.email="deploy@$HOST" commit -q -m "build from $SRC_SHA"
git push -q origin deploy
echo "Ветка deploy обновлена (сборка из $SRC_SHA)"
# Страховка: если Plesk пропустил уведомление GitHub (бывает), через минуту отправляем его повторно.
(
  sleep 60
  if [ "$(curl -s -m 10 https://$HOST/version.txt)" != "$SRC_SHA" ] && command -v gh >/dev/null; then
    REPO_GH=$(git -C "$REPO" remote get-url origin | sed -E 's#.*github.com[:/]##; s#\.git$##')
    HOOK=$(gh api "repos/$REPO_GH/hooks" --jq '.[0].id' 2>/dev/null)
    ID=$(gh api "repos/$REPO_GH/hooks/$HOOK/deliveries" --jq '.[0].id' 2>/dev/null)
    [ -n "$ID" ] && gh api -X POST "repos/$REPO_GH/hooks/$HOOK/deliveries/$ID/attempts" >/dev/null 2>&1
  fi
) >/dev/null 2>&1 &
# IndexNow: сообщить Bing (поиск ChatGPT/Copilot) и Яндексу об обновлённых страницах. Plesk забирает
# сборку ~10 секунд — пингуем в фоне чуть позже, чтобы роботы пришли уже на новую версию.
KEY=$(python3 -c "import sys; sys.path.insert(0, '$REPO'); import build; print(build.INDEXNOW_KEY)" 2>/dev/null || true)
if [ -n "$KEY" ]; then
  URLS=$(grep -o '<loc>[^<]*</loc>' "$REPO/dist/_blog/sitemap.tpl" | sed 's/<[^>]*>//g' | python3 -c "import sys, json; print(json.dumps([l.strip() for l in sys.stdin if l.strip()]))")
  ( sleep 20; curl -s -o /dev/null -m 10 -X POST https://api.indexnow.org/indexnow -H 'Content-Type: application/json; charset=utf-8' \
      -d "{\"host\":\"$HOST\",\"key\":\"$KEY\",\"keyLocation\":\"https://$HOST/$KEY.txt\",\"urlList\":$URLS}" ) >/dev/null 2>&1 &
fi
