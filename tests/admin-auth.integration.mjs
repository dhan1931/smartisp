import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import net from 'node:net';
import { readFile } from 'node:fs/promises';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import bcrypt from 'bcryptjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const host = process.env.MYSQL_HOST || '127.0.0.1';
const database = process.env.MYSQL_DATABASE || '';
if (!/(^|_)ci$/i.test(database) || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  throw new Error('La prueba de autenticación solo se ejecuta contra una MariaDB local cuyo nombre termina en _ci.');
}

const db = await mysql.createConnection({
  host,
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || '',
  password: process.env.MYSQL_PASSWORD || '',
  database,
});
const fixtures = {
  admin: { id: 'ci-auth-admin', email: 'ci-admin-auth@example.test', password: 'ci-admin-pass-2026', role: 'Administrator' },
  customer: { id: 'ci-auth-customer', email: 'ci-customer-auth@example.test', password: 'ci-customer-pass-2026', role: 'customer' },
};
const orderIds = ['ci-auth-pending', 'ci-auth-paid', 'ci-auth-cancelled', 'ci-auth-refunded', 'ci-auth-legacy', 'ci-auth-paid-yesterday', 'ci-auth-cancelled-delete'];
const settingKey = 'ci_auth_integration';
const storefrontColumnsKey = 'storefront_product_columns';
const secretSetting = 'smtp_pass';
const secretValue = 'ci-secret-must-not-be-public';
const team = {
  second: { id: 'ci-auth-admin-2', email: 'ci-admin2-auth@example.test', password: 'ci-admin2-pass-2026' },
  created: { id: '', email: 'ci-team-created@example.test' },
};
const otherAdminIds = [];
let php;
let cookie = '';

async function availablePort() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

async function request(action, { method = 'GET', body, session = cookie } = {}) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (session) headers.Cookie = session;
  const [name, query] = action.split('?', 2);
  const url = `${baseUrl}/api/router.php?action=${encodeURIComponent(name)}${query ? `&${query}` : ''}`;
  const response = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const setCookie = response.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';', 1)[0];
  const text = await response.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch { throw new Error(`${action} respondió contenido no JSON: ${text.slice(0, 160)}`); }
  return { status: response.status, data };
}

async function login(user, adminOnly = false) {
  const response = await request('login', { method: 'POST', body: { email: user.email, password: user.password, adminOnly }, session: '' });
  assert.equal(response.status, 200, `Login de ${user.role} debió aceptar la contraseña válida`);
  return response.data.user;
}

const port = await availablePort();
const baseUrl = `http://127.0.0.1:${port}`;

try {
  await db.execute('DELETE FROM orders_rows WHERE id IN (?)', [orderIds]);
  await db.execute('DELETE FROM users_rows WHERE id IN (?, ?)', [fixtures.admin.id, fixtures.customer.id]);
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [settingKey]);
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [storefrontColumnsKey]);
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [secretSetting]);
  await db.execute('INSERT INTO settings_rows (setting_key, setting_value) VALUES (?, ?)', [secretSetting, secretValue]);
  await db.execute('INSERT INTO settings_rows (setting_key, setting_value) VALUES (?, ?)', [storefrontColumnsKey, '6']);
  for (const user of Object.values(fixtures)) {
    await db.execute('INSERT INTO users_rows (id, email, password_hash, name, role) VALUES (?, ?, ?, ?, ?)', [
      user.id, user.email, await bcrypt.hash(user.password, 10), user.email.split('@')[0], user.role,
    ]);
  }
  const orders = [
    [orderIds[0], 'pending', 'pending', 100],
    [orderIds[1], 'paid', 'confirmed', 50],
    [orderIds[2], 'cancelled', 'confirmed', 900],
    [orderIds[3], 'delivered', 'refunded', 800],
    [orderIds[4], 'delivered', null, 25],
    [orderIds[6], 'cancelled', 'pending', 4],
  ];
  for (const [id, status, payment, total] of orders) {
    await db.execute('INSERT INTO orders_rows (id, total, status, payment_status) VALUES (?, ?, ?, ?)', [id, total, status, payment]);
  }
  await db.execute("INSERT INTO orders_rows (id, total, status, payment_status, created_at) VALUES (?, 5, 'paid', 'confirmed', DATE_SUB(NOW(), INTERVAL 1 DAY))", [orderIds[5]]);
  await db.execute('UPDATE orders_rows SET items = ? WHERE id = ?', [JSON.stringify([{ productId: 'ci-dashboard-router', name: 'Router de prueba', quantity: 2 }]), orderIds[0]]);

  php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, env: process.env, stdio: ['ignore', 'ignore', 'pipe'] });
  let startupError = '';
  php.stderr.setEncoding('utf8').on('data', chunk => { startupError += chunk; });
  let ready = false;
  for (let attempt = 0; attempt < 40 && !ready; attempt++) {
    try {
      const response = await fetch(`${baseUrl}/api/router.php?action=me`);
      ready = response.status === 401 || response.status === 200;
    } catch { await new Promise(resolve => setTimeout(resolve, 150)); }
  }
  assert.ok(ready, `PHP server no inició. ${startupError}`);

  const unauth = await request('admin-orders-stats', { session: '' });
  assert.equal(unauth.status, 401, 'Una petición anónima debe recibir 401');
  const publicCatalog = await request('catalog', { session: '' });
  assert.equal(publicCatalog.status, 200, 'El catálogo público debe estar disponible');
  assert.ok(!JSON.stringify(publicCatalog.data.content).includes(secretValue), 'El catálogo público no debe filtrar secretos SMTP');
  const publicSiteContent = await request('site-content', { session: '' });
  assert.equal(publicSiteContent.status, 200, 'Los ajustes de marca públicos deben estar disponibles');
  assert.equal(publicSiteContent.data.content.find(item => item.key === storefrontColumnsKey)?.value, '6', 'Las categorías públicas deben recibir la densidad elegida desde el admin');
  assert.ok(!JSON.stringify(publicSiteContent.data.content).includes(secretValue), 'Los ajustes públicos no deben filtrar secretos SMTP');
  const publicPage = await request('catalog?page=1&limit=2', { session: '' });
  assert.equal(publicPage.status, 200, 'El catálogo público debe aceptar paginación SQL');
  assert.ok(publicPage.data.products.length <= 2, 'El catálogo público debe respetar el límite de página');
  assert.equal(typeof publicPage.data.total, 'number', 'El catálogo público debe exponer el total para paginar');

  const customerAdminLogin = await request('login', { method: 'POST', body: { email: fixtures.customer.email, password: fixtures.customer.password, adminOnly: true }, session: '' });
  assert.equal(customerAdminLogin.status, 403, 'El login administrativo debe rechazar clientes antes de crear una sesión');
  assert.equal(customerAdminLogin.data.user, undefined);
  assert.equal((await request('me')).status, 401, 'El intento adminOnly de un cliente no debe autenticarlo');

  const admin = await login(fixtures.admin, true);
  const categoryFixture = {id:'ci-http-category',name:'CI HTTP categoría',macroGroupId:'componentes',iconImageUrl:'https://example.test/icon.webp',bannerImageUrl:'https://example.test/banner.webp'};
  const categorySave = await request('categories', {method:'POST',body:{category:categoryFixture}});
  assert.equal(categorySave.status,200,'El guardado individual debe aceptar imagen y banner separados');
  await db.execute("INSERT INTO products_rows (id,name,price,visible,category) VALUES ('ci-http-product','CI HTTP producto',1,1,?)",[categoryFixture.name]);
  const categoryRead = await request('categories');
  const savedCategory = categoryRead.data.categories.find(category=>category.id===categoryFixture.id);
  assert.equal(savedCategory.macroGroupId,'componentes');
  assert.equal(savedCategory.iconImageUrl,categoryFixture.iconImageUrl);
  assert.equal(savedCategory.bannerImageUrl,categoryFixture.bannerImageUrl);
  const familyProducts = await request('admin-products?family=componentes&category='+encodeURIComponent(categoryFixture.name));
  assert.equal(familyProducts.status,200,`El filtro de familia falló: ${JSON.stringify(familyProducts.data)}\n${startupError}`);
  assert.equal(familyProducts.data.products.length,1,'La familia debe incluir los productos de su categoría');
  const otherFamily = await request('admin-products?family=redes&category='+encodeURIComponent(categoryFixture.name));
  assert.equal(otherFamily.data.products.length,0,'La familia no debe incluir productos de otra familia');
  assert.equal((await request('categories?id='+categoryFixture.id,{method:'DELETE'})).status,409,'No se debe borrar una categoría con productos');
  const imageBody = new FormData();
  imageBody.append('image',new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6wXAAAAAASUVORK5CYII=','base64')],{type:'image/png'}),'icon.png');
  const imageResponse=await fetch(`${baseUrl}/api/router.php?action=upload-merch-image&category=categories`,{method:'POST',headers:{Cookie:cookie},body:imageBody});
  assert.equal(imageResponse.status,200,'La subida de imagen de categoría debe aceptar un PNG real');
  const uploadedImage=await imageResponse.json();
  const servedImage=await fetch(baseUrl+uploadedImage.url.replace('/api/auth/serve-upload?','/api/router.php?action=serve-upload&'));
  assert.equal(servedImage.status,200,'La imagen subida debe poder mostrarse sin sesión');
  assert.equal(admin.role, 'admin', 'Los roles admin equivalentes deben normalizarse');
  for (const action of ['admin-orders-stats', 'admin-orders', 'admin-products', 'categories', 'admin-dashboard-stats', 'admin-dashboard-insights']) {
    const result = await request(action);
    assert.equal(result.status, 200, `Admin debe poder consultar ${action}: ${result.data.error || ''}`);
  }
  const productPage = await request('admin-products?page=1&limit=2');
  assert.equal(productPage.status, 200, 'Admin debe cargar la primera página del catálogo');
  assert.ok(Array.isArray(productPage.data.products), 'El catálogo debe devolver una lista de productos');
  assert.equal(typeof productPage.data.total, 'number', 'El catálogo debe devolver el total para el paginador');
  assert.ok(productPage.data.products.length <= 2, 'El catálogo debe respetar el límite de página');
  const stats = await request('admin-orders-stats');
  assert.equal(stats.data.revenue, 55, 'Ingresos solo suman pagos marcados como confirmados; excluyen pendientes, cancelados, reembolsados y filas legacy sin confirmación');
  assert.ok(stats.data.daily.length >= 2, 'Las métricas deben ofrecer evolución cuando hay pedidos en varios días');
  assert.ok(stats.data.by_status.some((row) => row.status === 'pending' && row.total >= 1), 'El dashboard debe recibir conteos reales agrupados por estado actual');
  assert.ok(stats.data.by_status.some((row) => row.status === 'paid' && row.total >= 1), 'El dashboard debe incluir cada estado presente en la base');
  const insights = await request('admin-dashboard-insights');
  assert.equal(insights.data.top_products[0].name, 'Router de prueba');
  assert.equal(insights.data.top_products[0].units, 2, 'El ranking debe leer articulos del JSON legado');
  assert.equal(insights.data.top_products[0].orders, 1);
  assert.equal(insights.data.inventory_configured, false, 'La ausencia de inventario registrado no debe mostrarse como stock cero');
  const changedOrder = await request('admin-order-status', { method: 'POST', body: { id: orderIds[0], status: 'shipped' } });
  assert.equal(changedOrder.status, 200, 'Admin debe actualizar el estado operativo del pedido');
  const [[stillPendingPayment]] = await db.query('SELECT payment_status FROM orders_rows WHERE id = ?', [orderIds[0]]);
  assert.equal(stillPendingPayment.payment_status, 'pending', 'Cambiar el estado operativo no debe fingir una confirmación de pago');
  const repeatedStatus = await request('admin-order-status', { method: 'POST', body: { id: orderIds[2], status: 'cancelled' } });
  assert.equal(repeatedStatus.status, 409, 'No debe registrar dos veces consecutivas el mismo estado cancelado');
  const deleteActiveOrder = await request('admin-order-delete', { method: 'POST', body: { id: orderIds[0] } });
  assert.equal(deleteActiveOrder.status, 409, 'No se pueden eliminar pedidos que no estén cancelados');
  const deletePaidCancelledOrder = await request('admin-order-delete', { method: 'POST', body: { id: orderIds[2] } });
  assert.equal(deletePaidCancelledOrder.status, 409, 'No se deben eliminar pedidos con pago/registro financiero que conservar');
  const deleteCancelledOrder = await request('admin-order-delete', { method: 'POST', body: { id: orderIds[6] } });
  assert.equal(deleteCancelledOrder.status, 200, 'Admin puede eliminar un pedido cancelado sin pago registrado');
  const [[deletedCount]] = await db.query('SELECT COUNT(*) AS total FROM orders_rows WHERE id = ?', [orderIds[6]]);
  assert.equal(Number(deletedCount.total), 0, 'La eliminacion confirmada debe retirar el pedido cancelado');

  const saved = await request('admin-content', { method: 'POST', body: { content: [{ key: settingKey, value: 'admin-ok' }] } });
  assert.equal(saved.status, 200, 'Admin debe completar una operación administrativa autorizada');

  const customer = await login(fixtures.customer);
  assert.equal(customer.role, 'customer');
  const forbidden = await request('admin-orders-stats');
  assert.equal(forbidden.status, 403, 'Un cliente autenticado debe recibir 403');
  const forbiddenDelete = await request('admin-order-delete', { method: 'POST', body: { id: orderIds[0] } });
  assert.equal(forbiddenDelete.status, 403, 'Un cliente no puede eliminar pedidos');
  const meBefore = await request('me');
  assert.equal(meBefore.status, 200);
  assert.equal(meBefore.data.user.role, 'customer');

  await db.execute('UPDATE users_rows SET role = ? WHERE id = ?', ['Administrador', fixtures.customer.id]);
  const upgradedSession = await request('me');
  assert.equal(upgradedSession.data.user.role, 'admin', 'La sesión debe reflejar un cambio vigente de permisos en la BD');
  assert.equal((await request('admin-dashboard-stats')).status, 200);
  await db.execute('UPDATE users_rows SET role = ? WHERE id = ?', ['customer', fixtures.customer.id]);
  assert.equal((await request('admin-orders-stats')).status, 403, 'La sesión debe perder permisos al revocarse el rol en la BD');

  const pages = ['admin.html', 'pedidos.html', 'categorias.html', 'configuracion.html', 'editor-catalogo.html'];
  // Paginas cuyo <script> inline se extrajo a un archivo externo (DEV-20261015): las revisiones de
  // contenido de abajo deben mirar tambien ese archivo, no solo el HTML que quedo.
  const extractedScripts = { 'editor-catalogo.html': 'assets/js/admin/editor-catalogo-page.js' };
  for (const page of pages) {
    let source = await readFile(path.join(root, page), 'utf8');
    if (extractedScripts[page]) {
      source += await readFile(path.join(root, extractedScripts[page]), 'utf8');
    }
    assert.ok(!source.includes('acercado28@gmail.com'), `${page} no debe contener identidad admin fija`);
    assert.ok(!source.includes('X-Admin-Email'), `${page} no debe enviar identidad privilegiada del navegador`);
    assert.ok(!source.includes('getStoredAdminUser'), `${page} no debe inventar sesión local`);
    if (page === 'admin.html') {
      const ids = new Set([...source.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]));
      for (const match of source.matchAll(/\$\('#([^']+)'\)\.addEventListener/g)) {
        assert.ok(ids.has(match[1]), `admin.html registra un listener directo para #${match[1]}, pero ese elemento no existe`);
      }
    }
    if (page === 'editor-catalogo.html') {
      assert.ok(!source.includes('adminAuthModal'), 'El editor no debe volver a incrustar el login administrativo');
      assert.ok(source.includes('/login.html?admin=1&redirect='), 'El editor debe enviar al login administrativo dedicado');
    }
  }

  // --- Equipo y registro de actividad (migración 044)
  for (const f of fs.readdirSync(os.tmpdir())) if (/^(login|login_email|change_pwd)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });
  const [otherAdmins] = await db.query("SELECT id FROM users_rows WHERE LOWER(TRIM(role)) IN ('admin','administrator','administrador') AND active = 1 AND id NOT IN (?, ?)", [fixtures.admin.id, team.second.id]);
  otherAdminIds.push(...otherAdmins.map(row => row.id));
  if (otherAdminIds.length) await db.query('UPDATE users_rows SET active = 0 WHERE id IN (?)', [otherAdminIds]);
  await db.query('DELETE FROM users_rows WHERE id = ? OR email IN (?, ?)', [team.second.id, team.second.email, team.created.email]);
  await db.execute('INSERT INTO users_rows (id, email, password_hash, name, role) VALUES (?, ?, ?, ?, ?)', [team.second.id, team.second.email, await bcrypt.hash(team.second.password, 10), 'Segundo admin', 'admin']);
  const sessionOf = async user => { const r = await request('login', { method: 'POST', body: { email: user.email, password: user.password, adminOnly: true }, session: '' }); assert.equal(r.status, 200, `login de ${user.email}: ${JSON.stringify(r.data)}`); return { cookie, token: r.data.token }; };

  const failed = await request('login', { method: 'POST', body: { email: fixtures.admin.email, password: 'contraseña-incorrecta', adminOnly: true }, session: '' });
  assert.equal(failed.status, 401);
  const adminSession = (await sessionOf(fixtures.admin)).cookie;
  const [[loginRows]] = await db.query("SELECT SUM(action = 'login') AS ok, SUM(action = 'login-failed') AS failed FROM admin_audit_log WHERE user_id = ?", [fixtures.admin.id]);
  assert.ok(Number(loginRows.ok) >= 1 && Number(loginRows.failed) >= 1, 'Los inicios de sesión correctos y fallidos del admin deben quedar registrados');

  const auditSecret = 'ci-audit-secret-value';
  const wafBody = { payload: Buffer.from(JSON.stringify({ content: [{ key: settingKey, value: 'audit-ok' }], password: auditSecret, api_key: auditSecret, token: auditSecret })).toString('base64') };
  assert.equal((await request('admin-content', { method: 'POST', body: wafBody, session: adminSession })).status, 200, 'El payload base64 del WAF debe seguir funcionando');
  const [[auditRow]] = await db.query("SELECT * FROM admin_audit_log WHERE user_id = ? AND action = 'admin-content' ORDER BY id DESC LIMIT 1", [fixtures.admin.id]);
  assert.ok(auditRow, 'Un POST admin debe quedar en el registro de actividad');
  assert.equal(auditRow.status, 200, 'El registro debe guardar el código HTTP final');
  assert.equal(auditRow.method, 'POST');
  const auditText = JSON.stringify(auditRow);
  assert.ok(!auditText.includes(auditSecret) && !auditText.includes(wafBody.payload.slice(0, 40)), 'El registro no debe guardar contraseñas, tokens, claves ni el payload');
  assert.ok(auditRow.summary.includes('password=(oculto)') && auditRow.summary.includes(settingKey), `Resumen inesperado: ${auditRow.summary}`);

  const customerSession = (await (async () => { await login(fixtures.customer); return cookie; })());
  for (const [action, method, body] of [['admin-team', 'GET'], ['admin-audit-log', 'GET'], ['admin-team-create', 'POST', { name: 'X', email: 'ci-intruso@example.test', role: 'admin' }], ['admin-team-role', 'POST', { id: fixtures.customer.id, role: 'admin' }], ['admin-team-revoke', 'POST', { id: fixtures.admin.id }]]) {
    assert.equal((await request(action, { method, body, session: customerSession })).status, 403, `Un cliente no puede usar ${action}`);
  }
  const [[intruder]] = await db.query("SELECT COUNT(*) AS n FROM users_rows WHERE email = 'ci-intruso@example.test' OR (id = ? AND role <> 'customer')", [fixtures.customer.id]);
  assert.equal(Number(intruder.n), 0, 'Un cliente no debe poder crear cuentas ni subirse el rol');

  const teamList = await request('admin-team', { session: adminSession });
  assert.equal(teamList.status, 200);
  assert.ok(teamList.data.members.some(m => m.id === fixtures.admin.id && m.isSelf) && teamList.data.members.some(m => m.id === team.second.id), 'La lista del equipo debe incluir a los admins');
  assert.ok(!teamList.data.members.some(m => m.id === fixtures.customer.id), 'Los clientes no son parte del equipo');
  assert.equal((await request('admin-team-role', { method: 'POST', body: { id: team.second.id, role: 'superadmin' }, session: adminSession })).status, 400, 'No se aceptan roles fuera de la jerarquía');

  const created = await request('admin-team-create', { method: 'POST', body: { name: 'Nuevo', email: team.created.email, role: 'admin', mode: 'password' }, session: adminSession });
  assert.equal(created.status, 200, JSON.stringify(created.data));
  assert.ok(created.data.temporaryPassword && created.data.temporaryPassword.length >= 12, 'Debe devolverse una contraseña temporal');
  team.created.id = created.data.member.id;
  const [[createdAudit]] = await db.query("SELECT summary FROM admin_audit_log WHERE action = 'admin-team-create' AND target = ? ORDER BY id DESC LIMIT 1", [team.created.id]);
  assert.ok(createdAudit && !createdAudit.summary.includes(created.data.temporaryPassword), 'La contraseña temporal no debe quedar en el registro');
  assert.equal((await request('admin-team-create', { method: 'POST', body: { name: 'Otro', email: team.created.email.toUpperCase(), role: 'admin' }, session: adminSession })).status, 409, 'El correo del equipo debe ser único');
  assert.equal((await request('admin-team-create', { method: 'POST', body: { name: 'Otro', email: fixtures.customer.email, role: 'admin' }, session: adminSession })).status, 409, 'Un cliente existente no se convierte en admin sin confirmación');
  await sessionOf({ email: team.created.email, password: created.data.temporaryPassword });

  // El último admin activo no puede quitarse el rol ni desactivarse.
  const secondSession = await sessionOf(team.second);
  assert.equal((await request('admin-team-status', { method: 'POST', body: { id: team.created.id, active: false }, session: adminSession })).status, 200);
  assert.equal((await request('admin-team-status', { method: 'POST', body: { id: team.second.id, active: false }, session: adminSession })).status, 200, 'Un admin puede desactivar a otro admin');
  assert.equal((await request('me', { session: secondSession.cookie })).status, 401, 'Desactivar una cuenta invalida su sesión abierta');
  const disabledLogin = await request('login', { method: 'POST', body: { email: team.second.email, password: team.second.password }, session: '' });
  assert.equal(disabledLogin.status, 403, 'Una cuenta desactivada no puede iniciar sesión');
  assert.equal(disabledLogin.data.user, undefined);
  assert.equal((await request('admin-team-status', { method: 'POST', body: { id: fixtures.admin.id, active: false }, session: adminSession })).status, 409, 'Nadie puede desactivarse a sí mismo');
  const lastDemote = await request('admin-team-role', { method: 'POST', body: { id: fixtures.admin.id, role: 'customer' }, session: adminSession });
  assert.equal(lastDemote.status, 409, `El último admin activo no puede quitarse el rol: ${JSON.stringify(lastDemote.data)}`);
  const [[stillAdmin]] = await db.query('SELECT role, active FROM users_rows WHERE id = ?', [fixtures.admin.id]);
  assert.equal(stillAdmin.role, 'Administrator', 'El rol del último admin no debe cambiar');
  assert.equal(Number(stillAdmin.active), 1);

  // "Cerrar sesiones" invalida la sesión y el token emitidos antes.
  assert.equal((await request('admin-team-status', { method: 'POST', body: { id: team.second.id, active: true }, session: adminSession })).status, 200);
  const revoked = await sessionOf(team.second);
  assert.equal((await request('me', { session: revoked.cookie })).status, 200);
  assert.equal((await request('admin-team-revoke', { method: 'POST', body: { id: team.second.id }, session: adminSession })).status, 200);
  assert.equal((await request('me', { session: revoked.cookie })).status, 401, 'La sesión previa debe quedar cerrada');
  const byToken = await fetch(`${baseUrl}/api/router.php?action=me`, { headers: { Authorization: `Bearer ${revoked.token}` } });
  assert.equal(byToken.status, 401, 'El token previo también debe quedar invalidado');

  const log = await request('admin-audit-log?act=admin-team-*&limit=20', { session: adminSession });
  assert.equal(log.status, 200);
  assert.ok(log.data.entries.some(e => e.action === 'admin-team-revoke' && e.target === team.second.id), 'La actividad del equipo debe poder consultarse');
  const csv = await fetch(`${baseUrl}/api/router.php?action=admin-audit-log&format=csv&user=${encodeURIComponent(fixtures.admin.id)}`, { headers: { Cookie: adminSession } });
  assert.equal(csv.status, 200);
  assert.match(csv.headers.get('content-type') || '', /text\/csv/);
  assert.ok(!(await csv.text()).includes(auditSecret), 'El CSV no debe exponer secretos');
  const alerts = await request('admin-insights-alerts', { session: adminSession });
  assert.equal(alerts.status, 200);
  assert.equal(typeof alerts.data.admin_login_failures, 'number', 'Los avisos deben incluir los accesos fallidos al panel');

  console.log('Autenticación admin/customer, revocación de sesión, endpoints y métricas verificadas contra MariaDB CI.');
} finally {
  if (otherAdminIds.length) await db.query('UPDATE users_rows SET active = 1 WHERE id IN (?)', [otherAdminIds]).catch(() => {});
  await db.query('DELETE FROM users_rows WHERE id = ? OR email = ?', [team.second.id, team.created.email]).catch(() => {});
  await db.query('DELETE FROM admin_audit_log WHERE user_id IN (?)', [[fixtures.admin.id, fixtures.customer.id, team.second.id, team.created.id || '-']]).catch(() => {});
  if (php && php.exitCode === null) {
    php.kill();
    await new Promise(resolve => php.once('exit', resolve));
  }
  await db.execute('DELETE FROM orders_rows WHERE id IN (?)', [orderIds]).catch(() => {});
  await db.execute("DELETE FROM products_rows WHERE id='ci-http-product'").catch(()=>{});
  await db.execute("DELETE FROM categories_rows WHERE id='ci-http-category'").catch(()=>{});
  await db.execute('DELETE FROM users_rows WHERE id IN (?, ?)', [fixtures.admin.id, fixtures.customer.id]).catch(() => {});
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [settingKey]).catch(() => {});
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [storefrontColumnsKey]).catch(() => {});
  await db.execute('DELETE FROM settings_rows WHERE setting_key = ?', [secretSetting]).catch(() => {});
  await db.end();
}
