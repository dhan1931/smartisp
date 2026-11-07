#!/bin/sh
# Pasos del workflow de GitHub Actions, dentro del contenedor (lo lanza scripts/ci-docker.sh).
set -e
rm -rf /work && git config --global --add safe.directory '*' && cp -r /src /work && cd /work && rm -rf node_modules dist
npm ci --silent >/dev/null 2>&1
# Recorta la salida sin perder el error: con «| tail» un paso roto seguía hasta ALL-OK (sh no tiene pipefail).
quiet() { n=$1; shift; "$@" > /tmp/step.log 2>&1 || { cat /tmp/step.log; exit 1; }; tail -n "$n" /tmp/step.log; }
echo "== lint";          npm run lint
echo "== roles";         php tests/assert-auth-roles.php
echo "== títulos";       php tests/assert-product-title.php
echo "== precios";       php tests/assert-pricing.php && node tests/pricing.test.mjs
echo "== build";         quiet 5 npm test
echo "== bootstrap";     php tests/bootstrap-mariadb.php
echo "== migrate";       quiet 3 php scripts/migrate.php
echo "== assert-mig";    php tests/assert-migrations.php
echo "== assert-cat";    php tests/assert-category-admin.php
echo "== integration";   node tests/admin-auth.integration.mjs
echo "== e2e";           node tests/e2e/panel.e2e.mjs
echo "== migrate again"; quiet 2 php scripts/migrate.php
echo "== smoke";         node tests/smoke.integration.mjs
echo ALL-OK
