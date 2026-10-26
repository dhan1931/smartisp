#!/bin/sh
# CI local con Docker: MariaDB desechable + imagen PHP 8.3/Node 22. Uso: sh scripts/ci-docker.sh
set -e
DB="${CI_DB_NAME:-smartisp-ci-db}"
cd "$(dirname "$0")/.."
SRC=$(pwd -W 2>/dev/null || pwd)
IMAGE="smartisp-ci:$(cksum scripts/ci/Dockerfile | cut -d" " -f1)"
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" scripts/ci >/dev/null
docker rm -f $DB >/dev/null 2>&1 || true
docker run -d --name $DB -e MARIADB_DATABASE=smartisp_ci -e MARIADB_USER=smartisp_ci \
  -e MARIADB_PASSWORD=ci-only-password -e MARIADB_ROOT_PASSWORD=ci-only-root-password mariadb:11.8 >/dev/null
trap 'docker rm -f "$DB" >/dev/null 2>&1' EXIT
for i in $(seq 1 40); do docker exec $DB healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break; sleep 2; done
MSYS_NO_PATHCONV=1 docker run --rm --network container:$DB -v "$SRC:/src:ro" \
  -e MYSQL_HOST=127.0.0.1 -e MYSQL_PORT=3306 -e MYSQL_DATABASE=smartisp_ci -e MYSQL_USER=smartisp_ci \
  -e MYSQL_PASSWORD=ci-only-password -e SESSION_SECRET=ci-only-session-secret-not-for-production \
  "$IMAGE" sh /src/scripts/ci/run.sh
