#!/usr/bin/env bash
# Runs every check: PHP syntax, extension JavaScript syntax, unit tests, browser tests of the extension (when
# Playwright is installed), and a render of every settings screen.
set -u
cd "$(dirname "$0")/.."
status=0

echo "== PHP syntax"
while IFS= read -r f; do
  php -l "$f" > /dev/null || status=1
done < <(find givsen-supplier tests -name '*.php')

echo "== Extension syntax"
for f in givsen-supplier-extension/*.js; do node --check "$f" || status=1; done
python3 -c "import json; json.load(open('givsen-supplier-extension/manifest.json'))" || status=1

echo "== Tests"
for t in tests/test-*.php; do
  out=$(php "$t" 2>&1); code=$?
  if [ $code -ne 0 ] || ! grep -q "ALL PASSED" <<< "$out"; then
    echo "FAILED: $t"; echo "$out" | grep -v '^PASS'; status=1
  else
    echo "ok  $t ($(grep -c '^PASS' <<< "$out") checks)"
  fi
done

echo "== Browser tests (extension on saved AliExpress pages)"
export NODE_PATH="${NODE_PATH:-}:$(npm root -g 2>/dev/null)"
if node -e "require('playwright')" 2>/dev/null; then
  for t in tests/browser/*.test.js; do
    out=$(node "$t" 2>&1); code=$?
    if [ $code -ne 0 ] || ! grep -q "ALL PASSED" <<< "$out"; then
      echo "FAILED: $t"; echo "$out" | grep -v '^PASS'; status=1
    else
      echo "ok  $t ($(grep -c '^PASS' <<< "$out") checks)"
    fi
  done
elif [ -n "${CI:-}" ]; then
  echo "FAILED: Playwright isn't installed"; status=1
else
  echo "skipped (install with: npm i -g playwright && npx playwright install chromium)"
fi

echo "== Settings screens render"
for s in overview aliexpress ordering pricing sync ai countries extension reports; do
  err=$(php tests/render-settings.php "$s" 2>&1 >/dev/null)
  if [ -n "$err" ]; then echo "FAILED: settings/$s"; echo "$err" | head -5; status=1; else echo "ok  settings/$s"; fi
done

[ $status -eq 0 ] && echo "All checks passed." || echo "Some checks failed."
exit $status
