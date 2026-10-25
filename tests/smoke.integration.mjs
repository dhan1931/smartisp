// Flujos de punta a punta contra MariaDB CI: pedidos, stock, pagos, sesión, exportaciones, SEO e imágenes.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import bcrypt from 'bcryptjs';
import fs from 'node:fs';
import os from 'node:os';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
if (!/(^|_)ci$/i.test(process.env.MYSQL_DATABASE || '')) {
  throw new Error('La prueba de humo solo se ejecuta contra una MariaDB cuyo nombre termina en _ci.');
}
const db = await mysql.createConnection({
  host: '127.0.0.1', port: 3306, user: process.env.MYSQL_USER, password: process.env.MYSQL_PASSWORD, database: process.env.MYSQL_DATABASE,
});
// Las pruebas anteriores del CI ya iniciaron sesión desde 127.0.0.1: se limpia el límite de intentos.
for (const f of fs.readdirSync(os.tmpdir())) if (/^(login|pwd_reset)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });
const port = 8931;
const baseUrl = `http://127.0.0.1:${port}`;

async function req(action, { method = 'GET', body, cookie = '', headers = {} } = {}) {
  const h = { ...headers };
  if (body !== undefined) h['Content-Type'] = 'application/json';
  if (cookie) h.Cookie = cookie;
  const [name, query] = action.split('?', 2);
  const res = await fetch(`${baseUrl}/api/router.php?action=${encodeURIComponent(name)}${query ? `&${query}` : ''}`, { method, headers: h, body: body === undefined ? undefined : JSON.stringify(body) });
  const setCookie = res.headers.get('set-cookie');
  const text = await res.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch { throw new Error(`${action} no JSON: ${text.slice(0, 200)}`); }
  return { status: res.status, data, cookie: setCookie ? setCookie.split(';', 1)[0] : cookie };
}
async function login(email, password) {
  const r = await req('login', { method: 'POST', body: { email, password } });
  assert.equal(r.status, 200, `login ${email}: ${JSON.stringify(r.data)}`);
  return r.cookie;
}

const admin = { id: 'smk-admin', email: 'smk-admin@example.test', password: 'smk-admin-pass' };
const customer = { id: 'smk-cust', email: 'smk-cust@example.test', password: 'smk-cust-pass' };
const adminHash = await bcrypt.hash(admin.password, 10);
await db.execute("DELETE FROM users_rows WHERE id IN ('smk-admin','smk-cust') OR email LIKE 'smk-%'");
// En producción orders_rows tiene customer_email (ensureAuxiliaryTables); el fixture de CI no.
await db.execute('ALTER TABLE orders_rows ADD COLUMN IF NOT EXISTS customer_email VARCHAR(255) NULL');
await db.execute("DELETE FROM orders_rows WHERE customer_email = ? OR user_id = ?", [customer.email, customer.id]);
await db.execute("DELETE FROM products_rows WHERE id IN ('smk-prod','smk-prod2')");
await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES (?,?,?,?, 'admin')", [admin.id, admin.email, adminHash, 'Admin']);
await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES (?,?,?,?, 'customer')", [customer.id, customer.email, await bcrypt.hash(customer.password, 10), 'Cliente']);
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('smk-prod','Nombre Real',10,1,'General','SKU-REAL')");

const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, env: process.env, stdio: ['ignore', 'ignore', 'pipe'] });
for (let i = 0; i < 40; i++) { try { await fetch(`${baseUrl}/api/router.php?action=me`); break; } catch { await new Promise(r => setTimeout(r, 150)); } }

try {
  // --- Admin: galería, admin-content (SMTP a mail_settings), admin-products con specs
  const ac = await login(admin.email, admin.password);
  const gal = await req('product-images', { method: 'POST', cookie: ac, body: { product_id: 'smk-prod', image_url: 'https://example.test/a.webp' } });
  assert.equal(gal.status, 200, `galería: ${JSON.stringify(gal.data)}`);

  const save = await req('admin-content', { method: 'POST', cookie: ac, body: { content: [{ key: 'smtp_host', value: 'smtp.smoke.test' }, { key: 'smk_setting', value: 'x' }] } });
  assert.equal(save.status, 200);
  const [[mail]] = await db.query('SELECT smtp_host FROM mail_settings WHERE id = 1');
  assert.equal(mail.smtp_host, 'smtp.smoke.test', 'SMTP debe guardarse en mail_settings');
  const [[stray]] = await db.query("SELECT COUNT(*) n FROM settings_rows WHERE setting_key = 'smtp_host'");
  assert.equal(Number(stray.n), 0, 'SMTP ya no debe ir a settings_rows');
  const one = await req('admin-content?key=smk_setting', { cookie: ac });
  assert.equal(one.data.value, 'x', 'admin-content?key= debe devolver un valor suelto');

  const prod = await req('admin-products', { method: 'POST', cookie: ac, body: { id: 'smk-prod2', name: 'Prod 2', price: 5, specs: [{ label: 'Puertos', value: '8' }], warranty_text: '1 año' } });
  assert.equal(prod.status, 200, JSON.stringify(prod.data));
  const [[p2]] = await db.query("SELECT specs_json, warranty_text FROM products_rows WHERE id = 'smk-prod2'");
  assert.ok(p2.specs_json.includes('Puertos') && p2.warranty_text === '1 año', 'admin-products POST debe ser la versión con specs');
  const list = await req('admin-products?limit=5', { cookie: ac });
  assert.equal(list.status, 200); assert.equal(typeof list.data.total, 'number');

  // --- Login con el hash bcrypt como contraseña no debe entrar
  const hashLogin = await req('login', { method: 'POST', body: { email: admin.email, password: adminHash } });
  assert.equal(hashLogin.status, 401, 'el hash bcrypt no debe servir como contraseña');

  // --- Pedidos
  const cc = await login(customer.email, customer.password);
  const bad = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'C', customerEmail: 'no-es-correo', items: [{ id: 'smk-prod', quantity: 1 }] } });
  assert.equal(bad.status, 400, 'correo inválido debe dar 400');
  const ok = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-prod', name: 'FALSO', price: 0.01, quantity: 2 }] } });
  assert.equal(ok.status, 200, JSON.stringify(ok.data));
  const [[order]] = await db.query('SELECT items,total,user_id FROM orders_rows WHERE id = ?', [ok.data.orderId]);
  const items = JSON.parse(order.items);
  assert.equal(items[0].name, 'Nombre Real'); assert.equal(items[0].sku, 'SKU-REAL');
  assert.equal(Number(order.total), 20); assert.equal(order.user_id, customer.id);

  await db.execute("INSERT INTO orders_rows (id,total,status,customer_email,created_at) VALUES ('smk-guest',1,'pending',?, NOW())", [customer.email]);
  const mine = await req('orders', { cookie: cc });
  const ids = mine.data.orders.map(o => o.id);
  assert.ok(ids.includes(ok.data.orderId), 'debe ver su pedido');
  assert.ok(!ids.includes('smk-guest'), 'no debe ver pedidos de invitado por coincidir el correo');

  // --- Registro
  const regBad = await req('register', { method: 'POST', body: { email: 'nope', password: '123456' } });
  assert.equal(regBad.status, 400);
  const regDup = await req('register', { method: 'POST', body: { email: customer.email, password: '123456' } });
  assert.equal(regDup.status, 409); assert.ok(!JSON.stringify(regDup.data).includes('Duplicate'));

  // --- change-password
  const anon = await req('change-password', { method: 'POST', body: { email: customer.email, currentPassword: customer.password, newPassword: 'nueva-123' } });
  assert.equal(anon.status, 401, 'sin sesión debe dar 401 aunque mande email');
  const wrong = await req('change-password', { method: 'POST', cookie: cc, body: { currentPassword: 'mala', newPassword: 'nueva-123' } });
  assert.equal(wrong.status, 401);
  const good = await req('change-password', { method: 'POST', cookie: cc, body: { currentPassword: customer.password, newPassword: 'nueva-123' } });
  assert.equal(good.status, 200, JSON.stringify(good.data));
  await login(customer.email, 'nueva-123');

  // --- Inventario: stock controlado, reserva, consumo y liberación por estado del pedido
  await db.execute("DELETE FROM products_rows WHERE id = 'smk-stock'");
  await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('smk-stock','Producto Stock',5,1,'Redes','SKU-STK')");
  const inv0 = await req('admin-inventory?filter=untracked&q=smk-stock', { cookie: ac });
  assert.equal(inv0.status, 200, JSON.stringify(inv0.data));
  assert.equal(inv0.data.stockByOrders, true, 'con la migración 037 los pedidos deben apartar stock');
  assert.ok(inv0.data.items.some(i => i.id === 'smk-stock' && !i.tracked));
  const setStock = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-stock', available: 3 } });
  assert.equal(setStock.status, 200, JSON.stringify(setStock.data));
  assert.deepEqual([setStock.data.item.available, setStock.data.item.free], [3, 3]);
  const badStock = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-stock', available: -1 } });
  assert.equal(badStock.status, 400);

  const stockOrder = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-stock', quantity: 1 }, { id: 'smk-stock', quantity: 1 }] } });
  assert.equal(stockOrder.status, 200, JSON.stringify(stockOrder.data));
  const stockOf = async () => (await db.query("SELECT quantity_available a, quantity_reserved r FROM product_inventory WHERE product_id = 'smk-stock'"))[0][0];
  assert.deepEqual(await stockOf(), { a: 3, r: 2 }, 'el pedido debe reservar 2');
  const tooMany = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-stock', quantity: 2 }] } });
  assert.equal(tooMany.status, 400, 'solo queda 1 libre');
  assert.deepEqual(await stockOf(), { a: 3, r: 2 }, 'un pedido rechazado no toca el stock');

  const toPaid = await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'paid' } });
  assert.equal(toPaid.status, 200, JSON.stringify(toPaid.data));
  const [[paidRow]] = await db.query('SELECT payment_status, stock_state FROM orders_rows WHERE id = ?', [stockOrder.data.orderId]);
  assert.equal(paidRow.payment_status, 'paid', '"Pago confirmado" debe registrar el cobro');
  assert.equal(paidRow.stock_state, 'reserved');
  await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'shipped' } });
  assert.deepEqual(await stockOf(), { a: 1, r: 0 }, 'enviado descuenta del disponible');
  await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'cancelled' } });
  assert.deepEqual(await stockOf(), { a: 3, r: 0 }, 'cancelar un enviado lo devuelve al inventario');
  await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'pending' } });
  assert.deepEqual(await stockOf(), { a: 3, r: 2 }, 'reactivar vuelve a reservar');
  const low = await req('admin-inventory?filter=low&low=5&q=smk-stock', { cookie: ac });
  assert.ok(low.data.items.some(i => i.id === 'smk-stock' && i.free === 1), 'libre 1 debe salir como stock bajo');
  const untrack = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-stock', track: false } });
  assert.equal(untrack.data.item.tracked, false);

  // --- Estadísticas y exportaciones
  const stats = await req('admin-analytics?days=30', { cookie: ac });
  assert.equal(stats.status, 200, JSON.stringify(stats.data));
  assert.equal(stats.data.daily.length, 30);
  assert.ok(stats.data.totals.active_orders >= 2 && stats.data.top_products.some(p => p.name === 'Nombre Real'));
  const statsRange = await req('admin-analytics?from=2026-01-01&to=2026-01-31', { cookie: ac });
  assert.equal(statsRange.data.daily.length, 31);
  for (const type of ['orders', 'products', 'customers', 'inventory']) {
    const res = await fetch(`${baseUrl}/api/router.php?action=admin-export&type=${type}&days=30`, { headers: { Cookie: ac } });
    assert.equal(res.status, 200, `export ${type}: ${res.status} ${await res.clone().text()}`);
    assert.match(res.headers.get('content-type'), /text\/csv/);
    const buf = Buffer.from(await res.arrayBuffer());
    assert.deepEqual([...buf.subarray(0, 3)], [0xef, 0xbb, 0xbf], 'CSV con BOM');
    if (type === 'orders') assert.ok(buf.toString().includes(ok.data.orderId), 'el CSV de pedidos incluye el pedido');
  }
  const anonExport = await req('admin-export?type=orders');
  assert.equal(anonExport.status, 401, 'exportar exige sesión admin');

  // --- Sesión de solo lectura en GET: la wishlist (POST) persiste y el GET la lee
  const wPost = await req('customer-wishlist', { method: 'POST', cookie: cc, body: { item: { productId: 'smk-prod', name: 'Nombre Real' } } });
  assert.equal(wPost.status, 200);
  const wGet = await req('customer-wishlist', { cookie: cc });
  assert.ok(wGet.data.wishlist.some(i => i.productId === 'smk-prod'), 'la wishlist debe persistir en la sesión');
  const meAdmin = await req('me', { cookie: ac });
  assert.equal(meAdmin.status, 200, 'GET con sesión de solo lectura debe seguir autenticando');

  // --- Sesión pública: 200 sin sesión, con usuario si la hay
  const anonSession = await req('session');
  assert.equal(anonSession.status, 200); assert.equal(anonSession.data.user, null);
  const custSession = await req('session', { cookie: cc });
  assert.equal(custSession.data.user?.id, customer.id);
  const llms = await fetch(`${baseUrl}/llms.php`);
  const llmsText = await llms.text();
  assert.equal(llms.status, 200); assert.match(llmsText, /^# SmartISP Ecuador/); assert.match(llmsText, /categoria\//);
  assert.equal((await fetch(`${baseUrl}/api/router.php?action=indexnow-key&key=abcdefgh1234`)).status, 404, 'sin INDEXNOW_KEY no se publica clave');

  // --- Imágenes subidas: sin sesión ni base, con ETag y 304
  const fd = new FormData();
  fd.append('image', new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64')], { type: 'image/png' }), 'p.png');
  const up = await fetch(`${baseUrl}/api/router.php?action=upload-campaign-image`, { method: 'POST', headers: { Cookie: ac }, body: fd });
  const upData = await up.json();
  assert.equal(up.status, 200, JSON.stringify(upData));
  const imgUrl = baseUrl + upData.url.replace('/api/auth/serve-upload?', '/api/router.php?action=serve-upload&');
  const img1 = await fetch(imgUrl);
  assert.equal(img1.status, 200);
  assert.match(img1.headers.get('cache-control'), /immutable/);
  assert.equal(img1.headers.get('set-cookie'), null, 'servir una imagen no debe abrir sesión');
  const etag = img1.headers.get('etag');
  const img2 = await fetch(imgUrl, { headers: { 'If-None-Match': etag } });
  assert.equal(img2.status, 304, 'con ETag igual debe responder 304');
  assert.equal((await fetch(imgUrl.replace(/file=[^&]+/, 'file=no-existe.png'))).status, 404);

  // --- Contraseña heredada MD5: entra y se migra a bcrypt
  const { createHash } = await import('node:crypto');
  await db.execute("DELETE FROM users_rows WHERE id = 'smk-legacy'");
  await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES ('smk-legacy','smk-legacy@example.test',?,'Legacy','customer')", [createHash('md5').update('legacy-pass').digest('hex')]);
  await login('smk-legacy@example.test', 'legacy-pass');
  const [[legacy]] = await db.query("SELECT password_hash FROM users_rows WHERE id = 'smk-legacy'");
  assert.ok(legacy.password_hash.startsWith('$2y$'), 'el hash MD5 debe migrarse a bcrypt');

  // --- reset-password con token válido; luego entra con la nueva
  await db.execute("DELETE FROM password_resets WHERE email = 'smk-legacy@example.test'");
  await db.execute("INSERT INTO password_resets (email, token, expires_at) VALUES ('smk-legacy@example.test','smk-token', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
  const reset = await req('reset-password', { method: 'POST', body: { token: 'smk-token', password: 'otra-123', confirmation: 'otra-123' } });
  assert.equal(reset.status, 200, JSON.stringify(reset.data));
  await login('smk-legacy@example.test', 'otra-123');
  const reuse = await req('reset-password', { method: 'POST', body: { token: 'smk-token', password: 'x-123456', confirmation: 'x-123456' } });
  assert.equal(reuse.status, 400, 'el token no debe reutilizarse');

  // --- Solicitud de reset: correo desconocido responde igual; el límite corta en la 4a
  const unknown = await req('request-password-reset', { method: 'POST', body: { email: 'nadie@example.test' } });
  assert.equal(unknown.status, 200, JSON.stringify(unknown.data));
  await req('request-password-reset', { method: 'POST', body: { email: 'nadie@example.test' } });
  await req('request-password-reset', { method: 'POST', body: { email: 'nadie@example.test' } });
  const limited = await req('request-password-reset', { method: 'POST', headers: { 'X-Forwarded-For': '9.9.9.9' }, body: { email: 'nadie@example.test' } });
  assert.equal(limited.status, 429, 'el reset debe limitarse a 3 por IP');

  // --- Páginas públicas PHP siguen respondiendo
  for (const page of ['/categoria.php?slug=general', '/producto.php?slug=smk-prod', '/productos-destacados.php']) {
    const res = await fetch(baseUrl + page);
    assert.ok(res.status < 500, `${page} respondió ${res.status}`);
  }

  // --- Límite de login: cambiar X-Forwarded-For no lo salta
  let last;
  for (let i = 0; i < 10; i++) {
    last = await req('login', { method: 'POST', headers: { 'X-Forwarded-For': `10.0.0.${i}`, 'CF-Connecting-IP': `10.1.0.${i}` }, body: { email: admin.email, password: 'x' } });
  }
  assert.equal(last.status, 429, 'el límite debe aplicarse aunque cambie X-Forwarded-For');

  console.log('SMOKE-OK');
} finally {
  php.kill();
  await db.end();
}
