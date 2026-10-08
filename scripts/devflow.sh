#!/usr/bin/env bash
# Ejecuta DevFlow con el token MCP guardado por `npm run devflow:login`.
#   bash scripts/devflow.sh stellar-capabilities --target .
#   STELLAR_ENV=dev bash scripts/devflow.sh stellar-capabilities --target .   (token de la API dev)
set -euo pipefail

archivo="${DEVFLOW_HOME:-$HOME/.devflow}/stellar${STELLAR_ENV:+-$STELLAR_ENV}.env"
if [ ! -f "$archivo" ]; then
  echo "No hay token guardado (${archivo})." >&2
  echo "Ejecuta primero:  npm run devflow:login${STELLAR_ENV:+ -- --$STELLAR_ENV}" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
. "$archivo"
set +a

if [ -n "${STELLAR_MCP_TOKEN_EXPIRES:-}" ]; then
  ahora=$(date -u +%s)
  vence=$(date -u -d "$STELLAR_MCP_TOKEN_EXPIRES" +%s 2>/dev/null || echo 0)
  if [ "$vence" -gt 0 ] && [ "$ahora" -ge "$vence" ]; then
    echo "El token guardado venció ($STELLAR_MCP_TOKEN_EXPIRES). Ejecuta: npm run devflow:login${STELLAR_ENV:+ -- --$STELLAR_ENV}" >&2
    exit 1
  fi
fi

export STELLARCODE_TOKEN="${STELLARCODE_TOKEN:-${STELLAR_MCP_TOKEN:-}}"
exec devflow "$@"
