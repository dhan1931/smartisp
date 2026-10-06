#!/usr/bin/env node
/**
 * Conecta DevFlow con StellarCode y guarda un token MCP de vida corta en ~/.devflow/ (permisos 600, fuera del repo).
 *
 *   npm run devflow:login                       producción; abre el navegador para aprobar (recomendado)
 *   npm run devflow:login -- --dev              API dev por túnel (primero: npm run dev:remote → http://localhost:3010)
 *   npm run devflow:login -- --password         pide correo y contraseña aquí en la terminal (sin navegador)
 *   opciones: --api <url>  --web <url>  --horas <1-24>  --nombre "DevFlow en mi PC"  --no-open
 *             --email <correo> (solo con --password)  --project-id <id> (hace stellar-bind al terminar)
 *
 * Modo navegador (por defecto): la terminal muestra un código y un enlace. Si ya tienes sesión en la web ves
 * "Continuar como <tú>"; si no, inicias sesión ahí (con tu MFA) y vuelves a aprobar. La contraseña nunca pasa por
 * la terminal. Modo --password: login con usuario real (y MFA) directamente aquí. En ambos casos la contraseña no se guarda.
 * Después se usa DevFlow con:  bash scripts/devflow.sh <comando>   (carga el token guardado).
 */
const readline = require('readline');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const argv = process.argv.slice(2);
const flag = (n) => argv.includes(`--${n}`);
const valor = (n, def) => { const i = argv.indexOf(`--${n}`); return i >= 0 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : def; };

const dev = flag('dev');
const API = String(valor('api', process.env.STELLAR_API_URL || (dev ? 'http://localhost:3010' : 'https://api.stellarcodelabs.lat'))).replace(/\/+$/, '');
const WEB = String(valor('web', process.env.STELLAR_WEB_URL || (dev ? 'http://localhost:3011' : 'https://app.stellarcodelabs.lat'))).replace(/\/+$/, '');
const horas = Math.min(Math.max(parseInt(valor('horas', '24'), 10) || 24, 1), 24);
const nombre = valor('nombre', `DevFlow en ${os.hostname()}`);
const sufijo = dev ? '-dev' : '';
const espera = (ms) => new Promise((r) => setTimeout(r, ms));

function preguntar(texto, { oculto = false } = {}) {
  return new Promise((resolve) => {
    if (!process.stdin.isTTY) {
      // Entrada por tubería (automatización): se lee todo de una vez y se responde una línea por pregunta.
      if (!preguntar.cola) preguntar.cola = fs.readFileSync(0, 'utf8').split(/\r?\n/);
      process.stdout.write(`${texto}\n`);
      resolve((preguntar.cola.shift() || '').trim());
      return;
    }
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout, terminal: true });
    if (oculto) {
      rl._writeToOutput = (s) => { if (s.includes(texto)) process.stdout.write(s); };
    }
    rl.question(texto, (r) => { rl.close(); if (oculto) process.stdout.write('\n'); resolve(r.trim()); });
  });
}

async function http(ruta, { method = 'POST', body, token } = {}) {
  let res;
  try {
    res = await fetch(`${API}${ruta}`, {
      method,
      headers: { 'content-type': 'application/json', ...(token ? { authorization: `Bearer ${token}` } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new Error(`No se pudo conectar con ${API} (${e.cause?.code || e.message}).${dev ? ' ¿Está corriendo "npm run dev:remote"?' : ''}`);
  }
  const txt = await res.text();
  let json; try { json = JSON.parse(txt); } catch { json = { raw: txt.slice(0, 200) }; }
  if (!res.ok) throw Object.assign(new Error(json.error || `HTTP ${res.status}`), { status: res.status });
  return json;
}

function abrirNavegador(url) {
  try {
    const [cmd, args] = process.platform === 'win32' ? ['cmd', ['/c', 'start', '""', url]]
      : process.platform === 'darwin' ? ['open', [url]] : ['xdg-open', [url]];
    spawn(cmd, args, { stdio: 'ignore', detached: true, shell: false }).on('error', () => {}).unref();
  } catch { /* si no se puede abrir, el enlace ya está impreso */ }
}

/** Flujo de aprobación en la web: la terminal espera mientras apruebas en el navegador. */
async function porNavegador() {
  const ini = await http('/api/mcp-connect/iniciar', { body: { nombre, horas } });
  const url = `${WEB}${ini.verification_path}`;
  console.log('\nAprueba la conexión en el navegador:');
  console.log(`  ${url}`);
  console.log(`\nCódigo de confirmación: ${ini.user_code}   (debe coincidir con el que ves en la web)`);
  if (!flag('no-open')) { abrirNavegador(url); console.log('(Se abrió el navegador; si no, copia el enlace.)'); }
  console.log(`Esperando tu aprobación… (vence en ${Math.round(ini.expires_in / 60)} min, Ctrl+C para cancelar)`);

  const limite = Date.now() + ini.expires_in * 1000;
  while (Date.now() < limite) {
    await espera((ini.interval || 2) * 1000);
    const r = await http('/api/mcp-connect/token', { body: { device_code: ini.device_code } });
    if (r.estado === 'aprobada') return r;
  }
  throw new Error('La solicitud venció sin aprobarse. Vuelve a ejecutar el comando.');
}

/** Login con usuario y contraseña directamente en la terminal (con MFA si corresponde). */
async function porContrasena() {
  const email = valor('email') || await preguntar('Correo o usuario: ');
  const password = await preguntar('Contraseña: ', { oculto: true });
  if (!email || !password) throw new Error('Faltan correo o contraseña');
  let sesion = await http('/api/auth/login', { body: { identifier: email, password } });
  if (sesion.mfaRequired) {
    const codigo = await preguntar('Código MFA (6 dígitos o de recuperación): ');
    sesion = await http('/api/auth/mfa/verify', { body: { mfaToken: sesion.mfaToken, code: codigo } });
  }
  if (!sesion.accessToken) throw new Error('El servidor no devolvió una sesión');
  const yo = await http('/api/auth/me', { method: 'GET', token: sesion.accessToken });
  const u = yo.user || sesion.user || {};
  console.log(`Sesión verificada: ${[u.first_name, u.last_name].filter(Boolean).join(' ')} <${u.email}> · rol ${u.role}`);
  if (!['admin', 'superadmin'].includes(u.role)) throw new Error('Solo un administrador puede emitir tokens MCP. Pídele a un admin que te ayude.');
  return http('/api/mcp-token', { token: sesion.accessToken, body: { horas, nombre, origen: 'cli' } });
}

(async () => {
  console.log(`StellarCode · ${API}${dev ? '  (dev)' : ''}`);
  const t = flag('password') ? await porContrasena() : await porNavegador();
  const expira = new Date(Date.now() + t.expires_in_hours * 3600 * 1000).toISOString();

  const dir = process.env.DEVFLOW_HOME || path.join(os.homedir(), '.devflow');
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const archivo = path.join(dir, `stellar${sufijo}.env`);
  fs.writeFileSync(archivo,
    `# Generado por scripts/devflow-login.cjs. No lo subas a ningún repositorio.\nexport STELLAR_MCP_TOKEN='${t.token}'\nexport STELLAR_MCP_URL='${t.mcp_url}'\nexport STELLAR_MCP_TOKEN_EXPIRES='${expira}'\n`,
    { mode: 0o600 });
  try { fs.chmodSync(archivo, 0o600); } catch { /* Windows */ }

  console.log(`\nConectado. Token MCP de ${t.expires_in_hours} h (vence ${expira}). Escrituras: ${t.writes_enabled ? 'habilitadas' : 'solo lectura'}.`);
  console.log(`Guardado en ${archivo}`);
  console.log(`MCP URL: ${t.mcp_url}`);

  const pid = valor('project-id');
  if (pid) {
    console.log(`\nVinculando DevFlow al proyecto ${pid}…`);
    const r = spawnSync('devflow', ['stellar-bind', '--target', process.cwd(), '--project-id', String(pid), '--url', t.mcp_url, '--token-env', 'STELLAR_MCP_TOKEN'], { stdio: 'inherit', shell: true, env: { ...process.env, STELLAR_MCP_TOKEN: t.token } });
    if (r.status !== 0) console.log('No se pudo vincular; hazlo luego con: bash scripts/devflow.sh stellar-bind --project-id <ID> --url <URL> --token-env STELLAR_MCP_TOKEN');
  } else {
    console.log(`\nSiguiente paso (una vez por proyecto):\n  ${dev ? 'STELLAR_ENV=dev ' : ''}bash scripts/devflow.sh stellar-bind --project-id <ID> --url ${t.mcp_url} --token-env STELLAR_MCP_TOKEN`);
    console.log(`Comprobar:\n  ${dev ? 'STELLAR_ENV=dev ' : ''}bash scripts/devflow.sh stellar-capabilities --target .`);
  }
})().catch((e) => { console.error(`\nError: ${e.message}`); process.exit(1); });
