# Flujo DevFlow en SmartISP (proyecto 3 en StellarCode)

Sesión. Corre `npm run devflow:login` y aprueba en el navegador. El token dura 24 h. Si `npm run devflow -- stellar-status --target .` dice "venció", repite el login.
Elige tarea. Mira `npm run devflow -- kanban --target .` y empieza por las críticas, que ya están asignadas: DEV-20261005-001 a 007. Se hacen en ese orden, salvo que 002 va antes que 003 (primero se migra el admin a sesión y luego se quita la cabecera X-Admin-Email).

> Estado al 2026-10-05: DEV-001 (credenciales MySQL), DEV-003 (bypass X-Admin-Email del backend) y DEV-004 (contraseña universal) ya están `completed`. **DEV-002 sigue `pending`**: el front (`admin.html`, `editor-catalogo.html`, `tienda.html`) todavía envía la cabecera `X-Admin-Email`; quitarla es la siguiente tarea de seguridad pendiente, antes de seguir con 005 (clave de firma) y 006 (hash de contraseñas) — ver `docs/DIAGNOSTICO.md`.
Empieza. Pasa la tarea a curso con `npm run devflow -- kanban move --task-id <id> --status in_progress --target .` y arranca el cronómetro con `npm run devflow -- time start --task-id <id> --description "..." --category seguridad --target .`. Solo hay un cronómetro activo por usuario.
Trabaja. Haz commits pequeños con el ID al final, por ejemplo `fix(auth): ... (DEV-20261005-003)`. Una tarea puede tener varios commits, y un commit puede cubrir varias tareas.
Termina. Detén el cronómetro con `npm run devflow -- time stop --target .`. Después corre `npm run devflow -- traza --target .` y `... stellar-sync --target .`. Mueve la tarea a review o completed solo cuando esté verificada.
Seguridad de los datos. No imprimas ni pegues secretos y no los subas al repo. Las credenciales viven en `.env`, que git ignora.
