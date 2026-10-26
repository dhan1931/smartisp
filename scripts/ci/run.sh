#!/bin/sh
# Pasos del workflow de GitHub Actions, dentro del contenedor (lo lanza scripts/ci-docker.sh).
set -e
rm -rf /work && git config --global --add safe.directory '*' && cp -r /src /work && cd /work && rm -rf node_modules dist
npm ci --silent >/dev/null 2>&1
echo "== lint";          npm run lint
echo "== roles";         php tests/assert-auth-roles.php
echo "== build";         npm test 2>&1 | tail -5
echo "== bootstrap";     php tests/bootstrap-mariadb.php
echo "== migrate";       php scripts/migrate.php | tail -3
echo "== assert-mig";    php tests/assert-migrations.php
echo "== assert-cat";    php tests/assert-category-admin.php
echo "== integration";   node tests/admin-auth.integration.mjs
echo "== e2e";           node tests/e2e/panel.e2e.mjs
echo "== migrate again"; php scripts/migrate.php | tail -2
echo "== smoke";         node tests/smoke.integration.mjs
echo ALL-OK
