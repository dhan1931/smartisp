# SmartISP e-commerce — cómo trabajamos

Proyecto de **cliente externo** (`ownership: external_client`), mantenimiento. Metodología **DevFlow** (`.devflow.yml`, `ops/`) conectada a StellarCode: proyecto **3** (`smartisp-ecomerce`). Stack: Node/Express (`server.js`) + API PHP (`api/`) + MySQL/Postgres; front estático.

Flujo paso a paso de sesión a sesión (login, elegir tarea, cronómetro, commits, cierre): [docs/FLUJO-DEVFLOW.md](docs/FLUJO-DEVFLOW.md).

## DevFlow ↔ StellarCode (MCP)
- Sesión: `npm run devflow:login` (aprobación en el navegador, token de 24 h máx., se guarda en `~/.devflow/`, fuera del repo).
- Comandos: `npm run devflow -- <comando> --target .` (carga el token guardado). Requiere Python con `mcp>=1.13,<2`.
- Estado: `npm run devflow -- kanban --target .`, `... stellar-status --target .`, `... time summary --target .`.
- Planificar: `npm run devflow -- planificar "<título>" --stellar ...` crea la tarea en StellarCode y en `ops/BACKLOG.md` con el mismo ID. No crearla a mano en los dos lados.
- Cerrar: `devflow traza --target .` y `devflow stellar-sync --target .`.
- Las escrituras del MCP en producción están apagadas por defecto (Admin → MCP → Acceso y escrituras). La IA usa su propio token de vida corta, no el de la CLI.

## Tiempo y commits
- Cada tarea que se trabaja lleva **timer**: `npm run devflow -- time start --task-id <id> --description "..." --target .` al empezar y `... time stop --target .` al terminar.
- Commits con la clave de la tarea (`fix(auth): ... (DEV-20261005-003)`); varias tareas: `(DEV-..., DEV-...)`. Aviso si falta: `git config core.hooksPath scripts/hooks` (ya activado en este clon).

## Reglas
- Prioridad del backlog: las tareas **críticas de seguridad** (SEC-001…SEC-014: credenciales, bypass `X-Admin-Email`, contraseña universal, claves de firma) van antes que cualquier mejora.
- Secretos fuera del repo (`.env*`, `*.token`, `scripts/dev-db*.env` ignorados). Nunca imprimir ni pegar claves.
- Es un sitio de un cliente: no cambiar precios, textos ni catálogo sin pedirlo; no desplegar a producción sin confirmación del usuario.
- Los volcados de base de datos (`*.sql`) nunca se versionan.
