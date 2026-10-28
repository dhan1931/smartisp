// Flujos de punta a punta contra MariaDB CI: pedidos, stock, pagos, sesión, exportaciones, SEO e imágenes.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
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
for (const f of fs.readdirSync(os.tmpdir())) if (/^(login|login_email|pwd_reset|register|change_pwd|orders|orders_email|quotes)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });
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
await db.execute("DELETE FROM orders_rows WHERE customer_email LIKE 'smk-%'");
await db.execute("DELETE FROM products_rows WHERE id IN ('smk-prod','smk-prod2','smk-free')");
await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES (?,?,?,?, 'admin')", [admin.id, admin.email, adminHash, 'Admin']);
await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES (?,?,?,?, 'customer')", [customer.id, customer.email, await bcrypt.hash(customer.password, 10), 'Cliente']);
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('smk-prod','Nombre Real',10,1,'General','SKU-REAL')");
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('smk-free','Producto a cotizar',0,1,'General','SKU-FREE')");

const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, env: process.env, stdio: ['ignore', 'ignore', 'pipe'] });
for (let i = 0; i < 40; i++) { try { await fetch(`${baseUrl}/api/router.php?action=me`); break; } catch { await new Promise(r => setTimeout(r, 150)); } }

try {
  // --- Admin: galería, admin-content (SMTP a mail_settings), admin-products con specs
  const ac = await login(admin.email, admin.password);
  const gal = await req('product-images', { method: 'POST', cookie: ac, body: { product_id: 'smk-prod', image_url: 'https://example.test/a.webp' } });
  assert.equal(gal.status, 200, `galería: ${JSON.stringify(gal.data)}`);

  const imgToken = Buffer.from('http://127.0.0.1:1/x.jpg').toString('base64url');
  const tokenImg = await fetch(`${baseUrl}/api/router.php?action=product-image&id=smk-prod&t=${imgToken}`);
  assert.equal(tokenImg.status, 200);
  assert.match(tokenImg.headers.get('content-type') || '', /^image\/svg\+xml/, 'URL interna: placeholder');
  assert.equal(tokenImg.headers.get('set-cookie'), null, 'la imagen con token se sirve sin abrir sesión');
  const idImg = await fetch(`${baseUrl}/api/router.php?action=product-image&id=smk-prod`);
  assert.equal(idImg.status, 200);
  assert.match(idImg.headers.get('content-type') || '', /^image\//, 'sin token resuelve por id en la base');

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
  const toggle = await req('admin-products', { method: 'POST', cookie: ac, body: { id: 'smk-prod2', name: 'Prod 2', price: 5, visible: 0 } });
  assert.equal(toggle.status, 200, JSON.stringify(toggle.data));
  const [[p2b]] = await db.query("SELECT specs_json, warranty_text, visible FROM products_rows WHERE id = 'smk-prod2'");
  assert.ok(p2b.specs_json.includes('Puertos') && p2b.warranty_text === '1 año' && Number(p2b.visible) === 0, 'un guardado parcial no debe borrar specs ni garantía');
  const negative = await req('admin-products', { method: 'POST', cookie: ac, body: { id: 'smk-prod2', name: 'Prod 2', price: -1 } });
  assert.equal(negative.status, 400, 'precio negativo debe rechazarse');

  // --- Acciones destructivas solo por POST
  for (const action of ['admin-products-clear', 'categories-reset', 'landing-content-reset', 'test-email', 'logout']) {
    const viaGet = await req(action, { cookie: ac });
    assert.equal(viaGet.status, 405, `${action} por GET debe dar 405`);
  }
  const [[stillThere]] = await db.query("SELECT COUNT(*) n FROM products_rows WHERE id = 'smk-prod'");
  assert.equal(Number(stillThere.n), 1, 'el GET rechazado no debe borrar productos');

  // --- Login con el hash bcrypt como contraseña no debe entrar
  const hashLogin = await req('login', { method: 'POST', body: { email: admin.email, password: adminHash } });
  assert.equal(hashLogin.status, 401, 'el hash bcrypt no debe servir como contraseña');

  // --- Pedidos
  let cc = await login(customer.email, customer.password);
  const bad = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'C', customerEmail: 'no-es-correo', items: [{ id: 'smk-prod', quantity: 1 }] } });
  assert.equal(bad.status, 400, 'correo inválido debe dar 400');
  const ok = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-prod', name: 'FALSO', price: 0.01, quantity: 2 }] } });
  assert.equal(ok.status, 200, JSON.stringify(ok.data));
  const [[order]] = await db.query('SELECT items,total,user_id FROM orders_rows WHERE id = ?', [ok.data.orderId]);
  const items = JSON.parse(order.items);
  assert.equal(items[0].name, 'Nombre Real'); assert.equal(items[0].sku, 'SKU-REAL');
  assert.equal(Number(order.total), 20); assert.equal(order.user_id, customer.id);
  assert.deepEqual(ok.data.order, { id: ok.data.orderId, status: 'pending', total: 20, items: [{ id: 'smk-prod', name: 'Nombre Real', sku: 'SKU-REAL', price: 10, quantity: 2 }] }, 'la respuesta trae el pedido armado en el servidor');

  const missing = await req('orders', { method: 'POST', body: { customerName: 'Otro', customerEmail: 'smk-otro@example.test', items: [{ id: 'smk-no-existe', quantity: 1 }, { id: 'smk-prod', quantity: 1 }] } });
  assert.equal(missing.status, 400);
  assert.deepEqual(missing.data.invalidItems, ['smk-no-existe'], 'el 400 indica qué artículos quitar');
  const mixed = await req('orders', { method: 'POST', body: { customerName: 'Otro', customerEmail: 'smk-otro@example.test', items: [{ id: 'smk-prod', quantity: 1 }, { id: 'smk-free', quantity: 1 }] } });
  assert.equal(mixed.status, 200, JSON.stringify(mixed.data));
  assert.equal(mixed.data.isQuote, true, 'un carrito con un artículo sin precio es cotización');
  assert.equal(mixed.data.order.status, 'quote_requested');

  // --- Idempotencia: el mismo intento de compra repetido no crea un segundo pedido
  const idemKey = `smk-${Date.now()}-idem`;
  const idemBody = { customerName: 'Idem', customerEmail: 'smk-idem@example.test', items: [{ id: 'smk-prod', quantity: 1 }], idempotencyKey: idemKey };
  const idem1 = await req('customer-orders', { method: 'POST', headers: { 'Idempotency-Key': idemKey }, body: idemBody });
  assert.equal(idem1.status, 200, JSON.stringify(idem1.data));
  const idem2 = await req('customer-orders', { method: 'POST', headers: { 'Idempotency-Key': idemKey }, body: idemBody });
  assert.equal(idem2.status, 200, JSON.stringify(idem2.data));
  assert.deepEqual(idem2.data, idem1.data, 'el reintento devuelve la misma respuesta');
  const [[idemCount]] = await db.query("SELECT COUNT(*) n FROM orders_rows WHERE customer_email = 'smk-idem@example.test'");
  assert.equal(Number(idemCount.n), 1, 'dos envíos con la misma clave crean un solo pedido');
  const idemOther = await req('customer-orders', { method: 'POST', body: { ...idemBody, customerEmail: 'smk-idem2@example.test' } });
  assert.equal(idemOther.status, 200, JSON.stringify(idemOther.data));
  assert.notEqual(idemOther.data.orderId, idem1.data.orderId, 'la clave de otro cliente no devuelve su pedido');

  const search = await req(`admin-orders?q=${encodeURIComponent(ok.data.orderId)}`, { cookie: ac });
  assert.equal(search.status, 200, `búsqueda de pedidos: ${JSON.stringify(search.data)}`);
  assert.ok(search.data.orders.some(o => o.id === ok.data.orderId), 'la búsqueda encuentra el pedido');
  const quoteSearch = await req('admin-service-quotes?q=algo', { cookie: ac });
  assert.equal(quoteSearch.status, 200, `búsqueda de cotizaciones: ${JSON.stringify(quoteSearch.data)}`);

  await db.execute("INSERT INTO orders_rows (id,total,status,customer_email,created_at) VALUES ('smk-guest',1,'pending',?, NOW())", [customer.email]);
  const mine = await req('orders', { cookie: cc });
  const ids = mine.data.orders.map(o => o.id);
  assert.ok(ids.includes(ok.data.orderId), 'debe ver su pedido');
  assert.ok(!ids.includes('smk-guest'), 'no debe ver pedidos de invitado por coincidir el correo');

  // --- Directorio de clientes: mismos campos en listado y ficha; cada pedido cuenta una vez
  const customerFields = ['customer_id', 'customer_name', 'customer_email', 'customer_phone', 'joined_at', 'source', 'order_count', 'order_value', 'last_order'];
  const directory = await req('admin-customers?limit=100&q=smk-', { cookie: ac });
  assert.equal(directory.status, 200, JSON.stringify(directory.data));
  for (const c of directory.data.customers) assert.deepEqual(Object.keys(c), customerFields, 'campos del listado de clientes');
  assert.deepEqual(Object.keys(directory.data.stats), ['customers', 'accounts', 'guests', 'orders', 'order_value']);
  assert.equal(directory.data.total, directory.data.customers.length);
  const acct = directory.data.customers.find(c => c.customer_id === customer.id);
  assert.ok(acct && acct.source === 'account', 'la cuenta del cliente aparece');
  assert.equal(Number(acct.order_count), 2, 'su pedido y el de invitado con su correo');
  assert.equal(Number(acct.order_value), 21);
  assert.ok(!directory.data.customers.some(c => c.customer_id === `guest:${customer.email}`), 'el correo de una cuenta no aparece como invitado');
  const guestRow = directory.data.customers.find(c => c.customer_id === 'guest:smk-idem@example.test');
  assert.ok(guestRow && guestRow.source === 'guest' && Number(guestRow.order_count) === 1, 'comprador invitado');
  assert.ok(!directory.data.customers.some(c => c.customer_id === admin.id), 'los admin no salen en el directorio');
  const guestsOnly = await req('admin-customers?type=guest&q=smk-', { cookie: ac });
  assert.ok(guestsOnly.data.customers.length > 0 && guestsOnly.data.customers.every(c => c.source === 'guest'));
  assert.equal(guestsOnly.data.stats.accounts, 0);
  const acctDetail = await req(`admin-customers?id=${customer.id}`, { cookie: ac });
  assert.equal(acctDetail.status, 200, JSON.stringify(acctDetail.data));
  assert.deepEqual(acctDetail.data.customer, acct, 'la ficha coincide con la fila del listado');
  const guestDetail = await req(`admin-customers?id=${encodeURIComponent('guest:smk-idem@example.test')}`, { cookie: ac });
  assert.deepEqual(guestDetail.data.customer, guestRow);
  assert.equal((await req(`admin-customers?id=${admin.id}`, { cookie: ac })).status, 404);
  assert.equal((await req(`admin-customers?id=${encodeURIComponent('guest:' + customer.email)}`, { cookie: ac })).status, 404);
  const farPage = await req('admin-customers?limit=10&page=999&q=smk-', { cookie: ac });
  assert.equal(farPage.data.total, directory.data.total, 'una página vacía conserva el total');

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
  const otherSession = await login(customer.email, customer.password);
  const good = await req('change-password', { method: 'POST', cookie: cc, body: { currentPassword: customer.password, newPassword: 'nueva-123' } });
  assert.equal(good.status, 200, JSON.stringify(good.data));
  cc = good.cookie;
  assert.equal((await req('me', { cookie: cc })).status, 200, 'la sesión que cambió la contraseña sigue abierta');
  assert.equal((await req('me', { cookie: otherSession })).status, 401, 'cambiar la contraseña cierra las demás sesiones');
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

  const stockOf = async () => (await db.query("SELECT quantity_available a, quantity_reserved r FROM product_inventory WHERE product_id = 'smk-stock'"))[0][0];
  const forged = await req('orders', { method: 'POST', body: { customerName: 'Otro', customerEmail: 'smk-otro@example.test', items: [{ id: 'smk-prod', productId: 'smk-stock', stockTracked: true, quantity: 1 }] } });
  assert.equal(forged.status, 200, JSON.stringify(forged.data));
  assert.deepEqual(await stockOf(), { a: 3, r: 0 }, 'un productId inventado no debe reservar stock de otro producto');
  const [[forgedRow]] = await db.query('SELECT items FROM orders_rows WHERE id = ?', [forged.data.orderId]);
  const forgedItem = JSON.parse(forgedRow.items)[0];
  assert.equal(forgedItem.productId, 'smk-prod'); assert.equal(forgedItem.stockTracked, undefined);

  const stockOrder = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-stock', quantity: 1 }, { id: 'smk-stock', quantity: 1 }] } });
  assert.equal(stockOrder.status, 200, JSON.stringify(stockOrder.data));
  assert.deepEqual(await stockOf(), { a: 3, r: 2 }, 'el pedido debe reservar 2');
  const tooMany = await req('orders', { method: 'POST', cookie: cc, body: { customerName: 'Cliente', customerEmail: customer.email, items: [{ id: 'smk-stock', quantity: 2 }] } });
  assert.equal(tooMany.status, 400, 'solo queda 1 libre');
  assert.deepEqual(tooMany.data.invalidItems, ['smk-stock']);
  assert.deepEqual(await stockOf(), { a: 3, r: 2 }, 'un pedido rechazado no toca el stock');

  const toPaid = await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'paid' } });
  assert.equal(toPaid.status, 200, JSON.stringify(toPaid.data));
  assert.equal(toPaid.data.emailQueued, true, 'el correo de estado sale después de responder');
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
  const untrackBusy = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-stock', track: false } });
  assert.equal(untrackBusy.status, 409, 'no se deja de controlar un producto con unidades reservadas');
  await db.execute("UPDATE product_inventory SET quantity_available = 1 WHERE product_id = 'smk-stock'");
  await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'cancelled' } });
  const reopen = await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: stockOrder.data.orderId, status: 'pending' } });
  assert.equal(reopen.status, 409, 'reabrir sin stock libre suficiente se rechaza');
  assert.deepEqual(await stockOf(), { a: 1, r: 0 }, 'el rechazo no toca el inventario');
  const untrack = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-stock', track: false } });
  assert.equal(untrack.data.item.tracked, false);

  // --- Estadísticas y exportaciones
  const stats = await req('admin-analytics?days=30', { cookie: ac });
  assert.equal(stats.status, 200, JSON.stringify(stats.data));
  assert.equal(stats.data.daily.length, 30);
  assert.ok(stats.data.totals.active_orders >= 2 && stats.data.top_products.some(p => p.name === 'Nombre Real'));
  const general = stats.data.categories.find(c => c.category === 'General');
  // ok (2) + mixed (2) + idempotencia (1 + 1) + forged (1), todos de productos de General.
  assert.ok(general && general.units >= 7, `estadísticas por categoría: ${JSON.stringify(stats.data.categories)}`);
  const statsRange = await req('admin-analytics?from=2026-01-01&to=2026-01-31', { cookie: ac });
  assert.equal(statsRange.data.daily.length, 31);
  for (const type of ['orders', 'products', 'customers', 'inventory']) {
    const res = await fetch(`${baseUrl}/api/router.php?action=admin-export&type=${type}&days=30`, { headers: { Cookie: ac } });
    assert.equal(res.status, 200, `export ${type}: ${res.status} ${await res.clone().text()}`);
    assert.match(res.headers.get('content-type'), /text\/csv/);
    const buf = Buffer.from(await res.arrayBuffer());
    assert.deepEqual([...buf.subarray(0, 3)], [0xef, 0xbb, 0xbf], 'CSV con BOM');
    if (type === 'orders') {
      assert.ok(buf.toString().includes(ok.data.orderId), 'el CSV de pedidos incluye el pedido');
      const [[localDate]] = await db.query("SELECT DATE_FORMAT(created_at - INTERVAL 5 HOUR, '%Y-%m-%d %H:%i:%s') d FROM orders_rows WHERE id = ?", [ok.data.orderId]);
      assert.ok(buf.toString().includes(`${ok.data.orderId};"${localDate.d}"`) || buf.toString().includes(`${ok.data.orderId};${localDate.d}`), 'la fecha del CSV va en hora de Ecuador');
    }
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
  assert.equal((await fetch(imgUrl.replace(/file=[^&]+/, 'file=router.php'))).status, 404, 'serve-upload solo sirve extensiones de imagen');

  // --- Proxy de imágenes: una IP interna no se descarga (aunque sea una imagen real) y un SVG no pasa
  for (const target of [imgUrl, 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd']) {
    const proxied = await fetch(`${baseUrl}/api/router.php?action=proxy-image&url=${encodeURIComponent(target)}`);
    const body = await proxied.text();
    assert.equal(proxied.status, 200);
    assert.match(proxied.headers.get('content-type'), /^image\/svg\+xml/, `${target} debe dar el placeholder`);
    assert.ok(body.includes('>SmartISP</text>'), `${target} no debe descargarse`);
    assert.equal(proxied.headers.get('x-content-type-options'), 'nosniff');
  }
  const detected = execFileSync('php', ['-r', "require 'api/lib/image-proxy.php'; echo json_encode([smartispDetectImageType('<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>'), smartispDetectImageType(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))]);"], { cwd: root }).toString();
  assert.deepEqual(JSON.parse(detected), [null, 'image/png'], 'el proxy solo acepta imágenes rasterizadas verificadas');

  // --- Contraseña heredada MD5: entra y se migra a bcrypt
  const legacyMd5 = createHash('md5').update('legacy-pass').digest('hex');
  await db.execute("DELETE FROM users_rows WHERE id = 'smk-legacy'");
  await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES ('smk-legacy','smk-legacy@example.test',?,'Legacy','customer')", [legacyMd5]);
  const passTheHash = await req('login', { method: 'POST', body: { email: 'smk-legacy@example.test', password: legacyMd5 } });
  assert.equal(passTheHash.status, 401, 'el hash MD5 guardado no debe servir como contraseña');
  await login('smk-legacy@example.test', 'legacy-pass');
  const [[legacy]] = await db.query("SELECT password_hash FROM users_rows WHERE id = 'smk-legacy'");
  assert.ok(legacy.password_hash.startsWith('$2y$'), 'el hash MD5 debe migrarse a bcrypt');

  // --- reset-password con token válido; luego entra con la nueva
  await db.execute("DELETE FROM password_resets WHERE email = 'smk-legacy@example.test'");
  // En la base solo se guarda el SHA-256 del token; el hash en sí no sirve como token.
  const tokenHash = createHash('sha256').update('smk-token').digest('hex');
  await db.execute("INSERT INTO password_resets (email, token, expires_at) VALUES ('smk-legacy@example.test', ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))", [tokenHash]);
  const byHash = await req('reset-password', { method: 'POST', body: { token: tokenHash, password: 'otra-123', confirmation: 'otra-123' } });
  assert.equal(byHash.status, 400, 'el hash guardado no debe servir como token');
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

  // --- Costo, acciones masivas, historial de inventario y aviso de stock (migraciones 041 y 042)
  {
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await db.execute("DELETE FROM products_rows WHERE id IN ('smk-cost','smk-bulk1','smk-bulk2')");
    await db.execute("DELETE FROM product_inventory WHERE product_id = 'smk-cost'");
    await db.execute("DELETE FROM inventory_movements WHERE product_id = 'smk-cost'");
    await db.execute("DELETE FROM settings_rows WHERE setting_key = 'stock_alert:smk-cost'");

    const costSave = await req('admin-products', { method: 'POST', cookie: ac, body: { id: 'smk-cost', name: 'Producto Costo', price: 20, cost: 12.34, category: 'General', sku: 'SKU-COST' } });
    assert.equal(costSave.status, 200, JSON.stringify(costSave.data));
    const [[costRow]] = await db.query("SELECT cost FROM products_rows WHERE id = 'smk-cost'");
    assert.equal(Number(costRow.cost), 12.34, 'el costo se guarda');
    const adminCost = await req('admin-products?q=SKU-COST', { cookie: ac });
    assert.equal(adminCost.data.products.find(p => p.id === 'smk-cost')?.cost, 12.34, 'el panel ve el costo');
    for (const action of ['catalog?q=SKU-COST', 'catalog?q=smk-cost&details=1']) {
      const pub = await req(action);
      assert.equal(pub.status, 200);
      assert.ok(pub.data.products.some(p => p.id === 'smk-cost'), `${action} encuentra el producto`);
      assert.ok(pub.data.products.every(p => !('cost' in p)) && !JSON.stringify(pub.data).includes('12.34'), `${action} no debe exponer el costo`);
    }
    const publicPage = await (await fetch(`${baseUrl}/producto.php?slug=smk-cost`)).text();
    assert.ok(!publicPage.includes('12.34') && !publicPage.includes('12,34'), 'la ficha pública no debe mostrar el costo');

    await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('smk-bulk1','Bulk 1',100,1,'General','SKU-B1'),('smk-bulk2','Bulk 2',0,1,'General','SKU-B2')");
    const bulkIds = ['smk-bulk1', 'smk-bulk2'];
    const anonBulk = await req('admin-products-bulk-update', { method: 'POST', body: { op: 'visibility', visible: false, ids: bulkIds } });
    assert.equal(anonBulk.status, 401, 'las acciones masivas exigen sesión de admin');
    assert.equal((await req('admin-products-bulk-update', { cookie: ac })).status, 405, 'acciones masivas solo por POST');
    assert.equal((await req('admin-products-bulk-update', { method: 'POST', cookie: ac, body: { op: 'delete', ids: bulkIds } })).status, 400, 'operación fuera de la lista blanca');
    assert.equal((await req('admin-products-bulk-update', { method: 'POST', cookie: ac, body: { op: 'visibility', visible: false, ids: Array.from({ length: 501 }, (_, i) => `x${i}`) } })).status, 400, 'límite de ids por llamada');
    const hide = await req('admin-products-bulk-update', { method: 'POST', cookie: ac, body: { op: 'visibility', visible: false, ids: [...bulkIds, 'smk-no-existe'] } });
    assert.equal(hide.status, 200, JSON.stringify(hide.data));
    assert.equal(hide.data.updated, 2);
    assert.equal(hide.data.skipped.length, 1, 'el id inexistente se informa como omitido');
    const [hidden] = await db.query("SELECT visible FROM products_rows WHERE id IN ('smk-bulk1','smk-bulk2')");
    assert.ok(hidden.every(r => Number(r.visible) === 0), 'ocultar en bloque');
    const preview = await req('admin-products-bulk-update', { method: 'POST', cookie: ac, body: { op: 'price', mode: 'percent', value: 10, dry_run: true, ids: bulkIds } });
    assert.equal(preview.status, 200, JSON.stringify(preview.data));
    assert.deepEqual(preview.data.changes, [{ id: 'smk-bulk1', name: 'Bulk 1', before: 100, after: 110 }], 'vista previa del antes/después');
    const [[notYet]] = await db.query("SELECT price FROM products_rows WHERE id = 'smk-bulk1'");
    assert.equal(Number(notYet.price), 100, 'la vista previa no guarda');
    const raise = await req('admin-products-bulk-update', { method: 'POST', cookie: ac, body: { op: 'price', mode: 'percent', value: 10, ids: bulkIds } });
    assert.equal(raise.status, 200, JSON.stringify(raise.data));
    const [prices] = await db.query("SELECT id, price, visible FROM products_rows WHERE id IN ('smk-bulk1','smk-bulk2') ORDER BY id");
    assert.deepEqual(prices.map(r => [r.id, Number(r.price), Number(r.visible)]), [['smk-bulk1', 110, 0], ['smk-bulk2', 0, 0]], 'subir 10% solo toca el precio y no a los productos a cotizar');

    const [[mailBefore]] = await db.query('SELECT admin_email FROM mail_settings WHERE id = 1');
    await db.execute("UPDATE mail_settings SET admin_email = 'smk-ops@example.test' WHERE id = 1");
    const set6 = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-cost', available: 6, note: 'Compra inicial' } });
    assert.equal(set6.status, 200, JSON.stringify(set6.data));
    for (const f of fs.readdirSync(os.tmpdir())) if (/^(orders|orders_email)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });
    const movOrder = await req('orders', { method: 'POST', body: { customerName: 'Mov', customerEmail: 'smk-mov@example.test', items: [{ id: 'smk-cost', quantity: 2 }] } });
    assert.equal(movOrder.status, 200, JSON.stringify(movOrder.data));
    const [moves] = await db.query("SELECT reason, delta_available, delta_reserved, order_id, note FROM inventory_movements WHERE product_id = 'smk-cost' ORDER BY id");
    assert.deepEqual(moves.map(m => [m.reason, Number(m.delta_available), Number(m.delta_reserved)]), [['manual', 6, 0], ['order_reserved', 0, 2]], 'ajuste manual y reserva del pedido quedan en el historial');
    assert.equal(moves[1].order_id, movOrder.data.orderId);
    assert.equal(moves[0].note, 'Compra inicial');
    const history = await req('admin-inventory-movements?product_id=smk-cost', { cookie: ac });
    assert.equal(history.status, 200, JSON.stringify(history.data));
    assert.equal(history.data.total, 2);
    assert.equal(history.data.items[0].reason, 'order_reserved', 'el historial va del más reciente al más antiguo');
    assert.equal((await req('admin-inventory-movements?product_id=smk-cost')).status, 401, 'el historial exige sesión de admin');

    // Libre 6 -> 4 cruza el umbral de stock bajo: deja la marca del aviso (se envía tras responder).
    let mark;
    for (let i = 0; i < 40 && !mark; i++) { [[mark]] = await db.query("SELECT setting_value FROM settings_rows WHERE setting_key = 'stock_alert:smk-cost'"); if (!mark) await sleep(150); }
    assert.ok(mark, 'cruzar el umbral de stock bajo encola un aviso');
    const toZero = await req('admin-inventory', { method: 'POST', cookie: ac, body: { product_id: 'smk-cost', delta: -4 } });
    assert.equal(toZero.status, 200, JSON.stringify(toZero.data));
    assert.equal(toZero.data.item.free, 0);
    await sleep(600);
    const [[mark2]] = await db.query("SELECT setting_value FROM settings_rows WHERE setting_key = 'stock_alert:smk-cost'");
    assert.equal(mark2.setting_value, mark.setting_value, 'no más de un aviso por producto cada 24 h');
    await db.execute('UPDATE mail_settings SET admin_email = ? WHERE id = 1', [mailBefore?.admin_email ?? null]);
  }


  // --- Migración 043: notas internas de pedidos, conversión de cotizaciones y chips con fechas
  {
    const noteText = `smk nota interna ${Date.now()}`;
    const noted = await req('admin-order-note', { method: 'POST', cookie: ac, body: { id: ok.data.orderId, note: noteText } });
    assert.equal(noted.status, 200, JSON.stringify(noted.data));
    assert.equal((await req('admin-order-note', { method: 'POST', cookie: cc, body: { id: ok.data.orderId, note: 'x' } })).status, 403, 'un cliente no puede anotar pedidos');
    assert.equal((await req('admin-order-note', { method: 'POST', cookie: ac, body: { id: 'smk-no-existe', note: 'x' } })).status, 404);
    const noteDetail = await req(`admin-order?id=${encodeURIComponent(ok.data.orderId)}`, { cookie: ac });
    assert.ok(noteDetail.data.activity.some(a => a.kind === 'note' && a.body === noteText && a.actor === admin.email), 'el admin ve la nota con su autor');
    const customerView = await req('orders', { cookie: cc });
    assert.equal(customerView.status, 200, JSON.stringify(customerView.data));
    assert.ok(customerView.data.orders.some(o => o.id === ok.data.orderId), 'el cliente sigue viendo su pedido');
    assert.ok(!JSON.stringify(customerView.data).includes(noteText), 'el cliente no ve las notas internas');
    assert.equal((await req(`admin-order?id=${encodeURIComponent(ok.data.orderId)}`, { cookie: cc })).status, 403, 'el detalle con actividad es solo de admin');

    await db.execute("DELETE FROM service_quote_requests WHERE id = 'smk-quote'");
    await db.execute("INSERT INTO service_quote_requests (id, client_type, service_interest, description, company_name, email, phone) VALUES ('smk-quote', 'Empresa', 'Cableado estructurado', '20 puntos de red', 'Smk SA', 'smk-quote@example.test', '0991234567')");
    const inventory = async () => (await db.query('SELECT product_id, quantity_available, quantity_reserved FROM product_inventory ORDER BY product_id'))[0];
    const invBefore = await inventory();
    const noPrice = await req('admin-service-quote-convert', { method: 'POST', cookie: ac, body: { id: 'smk-quote', description: 'Instalación', price: 0, status: 'pending' } });
    assert.equal(noPrice.status, 400, 'un pedido pendiente de pago sin precio se rechaza');
    assert.equal((await req('admin-service-quote-convert', { method: 'POST', cookie: cc, body: { id: 'smk-quote', description: 'x', price: 1 } })).status, 403);
    const conv = await req('admin-service-quote-convert', { method: 'POST', cookie: ac, body: { id: 'smk-quote', description: 'Instalación de 20 puntos', price: 150.5, quantity: 2, status: 'pending', productId: 'smk-stock', stockTracked: true } });
    assert.equal(conv.status, 200, JSON.stringify(conv.data));
    assert.equal(conv.data.total, 301);
    const [[convRow]] = await db.query('SELECT items, status, total, customer_email FROM orders_rows WHERE id = ?', [conv.data.orderId]);
    const convItems = JSON.parse(convRow.items);
    assert.equal(convItems.length, 1);
    assert.deepEqual([convItems[0].productId, convItems[0].name, convItems[0].stockTracked], ['', 'Instalación de 20 puntos', undefined], 'la línea libre no apunta a un producto');
    assert.deepEqual([convRow.status, Number(convRow.total), convRow.customer_email], ['pending', 301, 'smk-quote@example.test']);
    for (const status of ['paid', 'shipped', 'cancelled']) {
      const moved = await req('admin-order-status', { method: 'POST', cookie: ac, body: { id: conv.data.orderId, status } });
      assert.equal(moved.status, 200, `${status}: ${JSON.stringify(moved.data)}`);
    }
    assert.deepEqual(await inventory(), invBefore, 'convertir y mover el pedido no toca el inventario');
    const convAgain = await req('admin-service-quote-convert', { method: 'POST', cookie: ac, body: { id: 'smk-quote', description: 'Otra', price: 1, status: 'pending' } });
    assert.equal(convAgain.status, 409, 'una cotización se convierte una sola vez');
    const [[quoteRow]] = await db.query("SELECT order_id, status FROM service_quote_requests WHERE id = 'smk-quote'");
    assert.deepEqual([quoteRow.order_id, quoteRow.status], [conv.data.orderId, 'contactado']);
    const convDetail = await req(`admin-order?id=${encodeURIComponent(conv.data.orderId)}`, { cookie: ac });
    assert.equal(convDetail.data.events[0].to_status, 'pending', 'la creación queda en el historial de estados');
    assert.ok(convDetail.data.activity.some(a => a.kind === 'system' && a.body.includes('smk-quote')), 'la línea de tiempo enlaza la cotización');
    const followup = await req('admin-service-quote-followup', { method: 'POST', cookie: ac, body: { id: 'smk-quote', next_action: 'Llamar al cliente', next_action_at: '2026-11-03T09:30' } });
    assert.equal(followup.status, 200, JSON.stringify(followup.data));
    const [[followRow]] = await db.query("SELECT next_action, DATE_FORMAT(next_action_at, '%Y-%m-%d %H:%i:%s') at FROM service_quote_requests WHERE id = 'smk-quote'");
    assert.deepEqual([followRow.next_action, followRow.at], ['Llamar al cliente', '2026-11-03 14:30:00'], 'la próxima acción se guarda en UTC');
    assert.equal((await req('admin-service-quote-note', { method: 'POST', cookie: ac, body: { id: 'smk-quote', note: 'Pidió propuesta formal' } })).status, 200);
    const quoteDetail = await req('admin-service-quote?id=smk-quote', { cookie: ac });
    assert.equal(quoteDetail.status, 200, JSON.stringify(quoteDetail.data));
    assert.equal(quoteDetail.data.order.id, conv.data.orderId);
    assert.deepEqual(quoteDetail.data.notes.map(n => n.body), [`Convertida en el pedido ${conv.data.orderId}.`, 'Pidió propuesta formal']);

    const chips = [
      { label: 'Smk vigente', icon: 'tag', target_url: '/tienda.html#catalogo', is_active: true, starts_at: '2020-01-01T08:00', ends_at: '2099-12-31T20:00' },
      { label: 'Smk vencido', icon: 'star', target_url: '/tienda.html', is_active: true, starts_at: '2020-01-01T08:00', ends_at: '2020-02-01T08:00' },
    ];
    // La portada del CI no tiene banners: se guarda inactiva para poder publicar solo los chips.
    const campaign = { name: 'Portada de la tienda', is_active: false };
    const inverted = await req('admin-store-campaigns', { method: 'POST', cookie: ac, body: { zone: 'store-home', campaign, chips: [{ ...chips[0], ends_at: '2019-01-01T00:00' }] } });
    assert.equal(inverted.status, 400, 'un chip con "hasta" antes de "desde" se rechaza');
    assert.match(inverted.data.error, /posterior/);
    const savedChips = await req('admin-store-campaigns', { method: 'POST', cookie: ac, body: { zone: 'store-home', campaign, chips } });
    assert.equal(savedChips.status, 200, JSON.stringify(savedChips.data));
    const chipDates = async () => (await db.query("SELECT label, DATE_FORMAT(starts_at, '%Y-%m-%d %H:%i') s, DATE_FORMAT(ends_at, '%Y-%m-%d %H:%i') e FROM storefront_promo_chips ORDER BY sort_order"))[0];
    const expectedChips = [{ label: 'Smk vigente', s: '2020-01-01 13:00', e: '2100-01-01 01:00' }, { label: 'Smk vencido', s: '2020-01-01 13:00', e: '2020-02-01 13:00' }];
    assert.deepEqual(await chipDates(), expectedChips, 'las fechas de los chips se guardan en UTC');
    const adminChips = (await req('admin-store-campaigns?zone=store-home', { cookie: ac })).data.chips;
    assert.deepEqual(adminChips.map(c => [c.starts_at, c.ends_at]), [['2020-01-01 13:00:00', '2100-01-01 01:00:00'], ['2020-01-01 13:00:00', '2020-02-01 13:00:00']], 'el GET admin devuelve las fechas de los chips');
    // Igual que el editor: UTC de la base -> datetime-local de Ecuador -> guardar de nuevo.
    const toLocal = value => new Date(new Date(`${value.replace(' ', 'T')}Z`).getTime() - 5 * 3600 * 1000).toISOString().slice(0, 16);
    const resaved = await req('admin-store-campaigns', { method: 'POST', cookie: ac, body: { zone: 'store-home', campaign, chips: adminChips.map(c => ({ label: c.label, icon: c.icon, target_url: c.target_url, is_active: String(c.is_active) === '1', starts_at: toLocal(c.starts_at), ends_at: toLocal(c.ends_at) })) } });
    assert.equal(resaved.status, 200, JSON.stringify(resaved.data));
    assert.deepEqual(await chipDates(), expectedChips, 'las fechas sobreviven a un segundo guardado');
    const publicChips = (await req('store-campaigns?zone=store-home')).data.chips.map(c => c.label);
    assert.ok(publicChips.includes('Smk vigente') && !publicChips.includes('Smk vencido'), `la tienda solo muestra chips vigentes: ${JSON.stringify(publicChips)}`);
  }

  // --- Categorías: migración 045, subcategorías reales y filtro por categoría principal
  await db.execute("DELETE FROM categories_rows WHERE id LIKE 'smk-cat-%' OR name IN ('Smk Etiqueta', 'Smk Patch')");
  await db.execute("DELETE FROM products_rows WHERE id LIKE 'smk-cat-%'");
  await db.execute(`INSERT INTO categories_rows (id, name, subcategories, macro_group_id) VALUES
    ('smk-cat-camaras', 'Smk Cámaras IP', '[]', NULL), ('smk-cat-tv', 'TV', '[]', NULL), ('smk-cat-manual', 'Smk Router manual', '[]', 'gaming'),
    ('smk-cat-rara', 'Smk Zzz', '[]', ''), ('smk-cat-padre', 'Smk Redes padre', '["Smk Etiqueta"]', 'redes'), ('smk-cat-hija', 'Smk Switch hija', '[]', 'gaming')`);
  await db.execute("DELETE FROM schema_migrations WHERE migration = '045_familias_y_subcategorias.sql'");
  execFileSync('php', ['scripts/migrate.php'], { cwd: root, env: process.env });
  assert.match(execFileSync('php', ['scripts/migrate.php'], { cwd: root, env: process.env }).toString(), /Sin migraciones pendientes/);
  const familyOf = async () => Object.fromEntries((await db.query("SELECT id, macro_group_id FROM categories_rows WHERE id LIKE 'smk-cat-%'"))[0].map(r => [r.id, r.macro_group_id]));
  const migrated = await familyOf();
  assert.equal(migrated['smk-cat-camaras'], 'seguridad', 'la migración 045 ubica por palabra clave contenida');
  assert.equal(migrated['smk-cat-tv'], 'monitores', 'la migración 045 ubica por palabra clave exacta');
  assert.equal(migrated['smk-cat-manual'], 'gaming', 'la migración 045 no pisa la familia elegida en el panel');
  assert.equal(migrated['smk-cat-rara'], 'otros', 'sin coincidencias la categoría va a Otras Soluciones IT');
  await db.execute(`INSERT INTO products_rows (id, name, price, visible, category, subcategory) VALUES
    ('smk-cat-p1', 'Smk switch', 10, 1, 'Smk Switch hija', ''), ('smk-cat-p2', 'Smk patch', 10, 1, 'Smk Redes padre', 'Smk Patch'), ('smk-cat-p3', 'Smk router', 10, 1, 'Smk Redes padre', '')`);
  const linkChild = await req('admin-category-children', { method: 'POST', cookie: ac, body: { parentId: 'smk-cat-padre', add: ['smk-cat-hija'], remove: [] } });
  assert.equal(linkChild.status, 200, JSON.stringify(linkChild.data));
  assert.equal((await familyOf())['smk-cat-hija'], 'redes', 'la subcategoría hereda la familia de su principal');
  assert.equal((await req('admin-category-children', { method: 'POST', cookie: ac, body: { parentId: 'smk-cat-hija', add: ['smk-cat-padre'] } })).status, 400, 'un ciclo se rechaza');
  assert.equal((await req('admin-category-children', { method: 'POST', cookie: ac, body: { parentId: 'smk-cat-hija', add: ['smk-cat-manual'] } })).status, 400, 'un tercer nivel se rechaza');
  assert.equal((await req('admin-category-children', { method: 'POST', body: {} })).status, 401, 'las subcategorías requieren sesión de administrador');
  const beforeConvert = await req('categories', { cookie: ac });
  assert.ok(beforeConvert.data.tagConversion.pending >= 2, `hay etiquetas por convertir: ${JSON.stringify(beforeConvert.data.tagConversion)}`);
  const converted = await req('admin-category-convert-tags', { method: 'POST', cookie: ac, body: {} });
  assert.equal(converted.status, 200, JSON.stringify(converted.data));
  assert.ok(converted.data.created.includes('Smk Etiqueta → Smk Redes padre') && converted.data.created.includes('Smk Patch → Smk Redes padre'), JSON.stringify(converted.data.created));
  const again = await req('admin-category-convert-tags', { method: 'POST', cookie: ac, body: {} });
  assert.deepEqual([again.data.created.length, again.data.linked.length, again.data.pending], [0, 0, 0], 'convertir otra vez no cambia nada');
  const [[dupes]] = await db.query("SELECT COUNT(*) n FROM categories_rows WHERE name IN ('Smk Etiqueta', 'Smk Patch')");
  assert.equal(Number(dupes.n), 2, 'las etiquetas convertidas no se duplican');
  const parentView = await req(`catalog?category=${encodeURIComponent('Smk Redes padre')}&limit=50`);
  assert.deepEqual(parentView.data.products.map(p => p.id).sort(), ['smk-cat-p1', 'smk-cat-p2', 'smk-cat-p3'], 'filtrar por la principal incluye sus subcategorías');
  const childView = await req(`catalog?category=${encodeURIComponent('Smk Patch')}&limit=50`);
  assert.deepEqual(childView.data.products.map(p => p.id), ['smk-cat-p2'], 'filtrar por la subcategoría incluye los productos de la principal marcados con ella');
  const pairView = await req(`catalog?category=${encodeURIComponent('Smk Redes padre')}&subcategory=${encodeURIComponent('Smk Switch hija')}&limit=50`);
  assert.deepEqual(pairView.data.products.map(p => p.id), ['smk-cat-p1'], 'categoría + subcategoría real (categorias-destacadas) muestra los productos de la subcategoría');
  const listView = await req(`catalog?category_list=${encodeURIComponent(JSON.stringify(['Smk Redes padre']))}&limit=50`);
  assert.equal(listView.data.total, 3, 'la lista de categorías de una familia también incluye subcategorías');
  assert.ok(parentView.data.macroGroups.some(g => g.id === 'redes' && g.keywords.includes('router')), 'el catálogo trae las familias con sus palabras clave');
  const tree = Object.fromEntries(parentView.data.categories.filter(c => c.name.startsWith('Smk')).map(c => [c.name, c]));
  assert.equal(tree['Smk Switch hija'].parentCategoryId, 'smk-cat-padre');
  assert.equal(tree['Smk Redes padre'].totalCount, 3, 'el conteo de la principal suma sus subcategorías');
  assert.equal(tree['Smk Patch'].totalCount, 1, 'el conteo de la subcategoría cuenta los productos marcados en la principal');
  const parentSearch = await req(`admin-categories-search?q=${encodeURIComponent('smk')}&for=smk-cat-hija&mode=parent`, { cookie: ac });
  assert.ok(!parentSearch.data.categories.some(c => c.parentCategoryId), 'como principal solo se ofrecen categorías principales');
  const categoryPage = await (await fetch(`${baseUrl}/categoria.php?slug=smk-redes-padre`)).text();
  assert.ok(categoryPage.includes('Smk switch') && categoryPage.includes('Smk router'), 'la página de la categoría principal muestra los productos de sus subcategorías');
  await db.execute("DELETE FROM products_rows WHERE id LIKE 'smk-cat-%'");
  await db.execute("DELETE FROM categories_rows WHERE id LIKE 'smk-cat-%' OR name IN ('Smk Etiqueta', 'Smk Patch')");

  // --- Equipo y actividad: pantallas, cuenta propia y registro de escrituras
  for (const f of fs.readdirSync(os.tmpdir())) if (/^(login|login_email|change_pwd)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });
  for (const page of ['/equipo.html', '/actividad.html', '/assets/js/admin/equipo-page.js', '/assets/js/admin/actividad-page.js']) {
    assert.equal((await fetch(baseUrl + page)).status, 200, `${page} debe servirse`);
  }
  const tc = await login(admin.email, admin.password);
  const myAccount = await req('admin-my-account', { cookie: tc });
  assert.equal(myAccount.status, 200, JSON.stringify(myAccount.data));
  assert.ok(myAccount.data.canManageTeam && myAccount.data.user.lastLoginAt, 'Mi cuenta debe mostrar el último acceso');
  assert.ok(myAccount.data.recent.some(r => r.action === 'login'), 'Mi cuenta debe listar los accesos recientes');
  const profile = await req('update-profile', { method: 'POST', cookie: tc, body: { name: 'Admin Humo', surname: 'CI' } });
  assert.equal(profile.status, 200, `update-profile: ${JSON.stringify(profile.data)}`);
  const other = await req('login', { method: 'POST', body: { email: admin.email, password: admin.password } });
  const others = await req('revoke-other-sessions', { method: 'POST', cookie: tc });
  assert.equal(others.status, 200);
  assert.equal((await req('me', { cookie: others.cookie })).status, 200, 'La sesión que cierra las demás sigue abierta (con la cookie regenerada)');
  assert.equal((await req('me', { cookie: other.cookie })).status, 401, 'Las demás sesiones quedan cerradas');
  const [[profileAudit]] = await db.query("SELECT summary, status FROM admin_audit_log WHERE user_id = ? AND action = 'update-profile' ORDER BY id DESC LIMIT 1", [admin.id]);
  assert.ok(profileAudit && profileAudit.status === 200 && profileAudit.summary.includes('Admin Humo'), 'update-profile del admin debe quedar registrado');
  const [[custAudit]] = await db.query("SELECT COUNT(*) n FROM admin_audit_log WHERE user_id = ?", [customer.id]);
  assert.equal(Number(custAudit.n), 0, 'Las acciones de clientes no se registran como actividad del equipo');
  await db.execute('DELETE FROM admin_audit_log WHERE user_id = ?', [admin.id]);

  console.log('SMOKE-OK');
} finally {
  php.kill();
  await db.end();
}
