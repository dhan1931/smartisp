// Panel admin en un navegador real (Chromium) contra MariaDB CI: carga de pantallas sin errores,
// dashboard, búsqueda global, cambios sin guardar y guardados parciales.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import bcrypt from 'bcryptjs';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
if (!/(^|_)ci$/i.test(process.env.MYSQL_DATABASE || '')) {
  throw new Error('La prueba e2e solo se ejecuta contra una MariaDB cuyo nombre termina en _ci.');
}
const db = await mysql.createConnection({
  host: '127.0.0.1', port: 3306, user: process.env.MYSQL_USER, password: process.env.MYSQL_PASSWORD, database: process.env.MYSQL_DATABASE,
});
for (const f of fs.readdirSync(os.tmpdir())) if (/^(login|login_email)_[0-9a-f]{32}\.json$/.test(f)) fs.rmSync(path.join(os.tmpdir(), f), { force: true });

const admin = { id: 'e2e-admin', email: 'e2e-admin@example.test', password: 'e2e-admin-pass' };
await db.execute("DELETE FROM users_rows WHERE id = ? OR email = ?", [admin.id, admin.email]);
await db.execute("INSERT INTO users_rows (id,email,password_hash,name,role) VALUES (?,?,?,?, 'admin')", [admin.id, admin.email, await bcrypt.hash(admin.password, 10), 'Admin E2E']);
await db.execute("DELETE FROM products_rows WHERE id LIKE 'e2e-%'");
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku,specs_json,warranty_text,content_text,external_url) VALUES ('e2e-router','Router E2E Mikrotik',89.9,1,'Redes','E2E-RTR','[{\"label\":\"Puertos\",\"value\":\"5\"}]','1 año','Contenido','https://example.test/router')");
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('e2e-switch','Switch E2E 8 puertos',34.5,1,'Redes','E2E-SW')");
await db.execute('ALTER TABLE orders_rows ADD COLUMN IF NOT EXISTS customer_email VARCHAR(255) NULL');
await db.execute("DELETE FROM orders_rows WHERE id LIKE 'E2E-%'");
const items = JSON.stringify([{ id: 'e2e-router', productId: 'e2e-router', name: 'Router E2E Mikrotik', sku: 'E2E-RTR', price: 89.9, quantity: 1 }]);
for (const [i, status] of ['pending', 'paid', 'shipped', 'delivered', 'cancelled'].entries()) {
  await db.execute(
    "INSERT INTO orders_rows (id, customer_email, items, total, status, created_at) VALUES (?, 'cliente-e2e@example.test', ?, 89.9, ?, UTC_TIMESTAMP() - INTERVAL ? DAY)",
    [`E2E-${i}`, items, status, i * 2],
  );
}

const port = 8941;
const baseUrl = `http://127.0.0.1:${port}`;
const uploads = fs.mkdtempSync(path.join(os.tmpdir(), 'smartisp-e2e-'));
const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(root, 'scripts/dev-router.php')], {
  cwd: root, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4', SMARTISP_UPLOADS_DIR: uploads }, stdio: ['ignore', 'ignore', 'pipe'],
});
for (let i = 0; i < 40; i++) { try { await fetch(`${baseUrl}/api/router.php?action=me`); break; } catch { await new Promise(r => setTimeout(r, 150)); } }

const browser = await chromium.launch();
const problems = [];
async function session(options = {}) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
  const page = await context.newPage();
  page.on('pageerror', error => problems.push(`JS ${new URL(page.url()).pathname}: ${error.message}`));
  page.on('response', res => {
    if (res.url().includes('/api/') && res.status() >= 500) problems.push(`HTTP ${res.status()} ${new URL(res.url()).search}`);
  });
  page.on('dialog', dialog => dialog.accept());
  const login = await page.request.post(`${baseUrl}/api/router.php?action=login`, { data: { email: admin.email, password: admin.password } });
  assert.equal(login.status(), 200, 'login admin');
  return { context, page };
}
const open = async (page, url) => { await page.goto(baseUrl + url, { waitUntil: 'networkidle' }); await page.waitForTimeout(400); };

try {
  const { context, page } = await session();

  for (const screen of ['admin', 'pedidos', 'clientes', 'cotizaciones', 'inventario', 'estadisticas', 'reportes', 'categorias', 'configuracion', 'campanas', 'portada', 'editor-catalogo', 'editor-landing', 'equipo', 'actividad']) {
    await open(page, `/${screen}.html`);
    assert.ok(new URL(page.url()).pathname.endsWith(`/${screen}.html`), `${screen}.html no debe redirigir: ${page.url()}`);
  }

  await open(page, '/comprobante.html?id=E2E-1');
  assert.ok((await page.locator('body').innerText()).includes('E2E-1'), 'el comprobante muestra el pedido');

  await open(page, '/admin.html');
  await page.waitForSelector('#kpis .kpi');
  assert.equal(await page.locator('#kpis .kpi').count(), 6, 'el dashboard muestra 6 KPIs');

  await page.keyboard.press('Control+k');
  await page.keyboard.type('Router E2E');
  await page.waitForTimeout(1200);
  assert.ok((await page.locator('body').innerText()).includes('Router E2E Mikrotik'), 'Ctrl+K encuentra productos');
  await page.keyboard.press('Escape');

  await open(page, '/pedidos.html');
  const search = page.waitForResponse(res => res.url().includes('admin-orders') && res.url().includes('q='));
  await page.fill('#searchInput', 'E2E');
  assert.equal((await search).status(), 200, 'búsqueda de pedidos');

  await open(page, '/editor-catalogo.html');
  await page.evaluate(async () => { await window.toggleProductVisibility('e2e-router', false); await window.toggleProductVisibility('e2e-router', true); });
  const [[product]] = await db.query("SELECT specs_json, warranty_text, content_text, external_url, visible FROM products_rows WHERE id = 'e2e-router'");
  assert.equal(product.warranty_text, '1 año', 'el interruptor de visibilidad no borra la garantía');
  assert.ok(String(product.specs_json).includes('Puertos'), 'ni las specs');
  assert.equal(product.external_url, 'https://example.test/router', 'ni el enlace');
  assert.equal(Number(product.visible), 1);

  await page.evaluate(() => { for (const id of ['importPriceMargin', 'importPriceMargin2']) { const input = document.getElementById(id); input.value = '15'; input.dispatchEvent(new Event('input', { bubbles: true })); } });
  assert.ok((await page.textContent('#importPriceFormula')).includes('132,25'), 'el importador aplica 15 % y luego 15 % (× 1,3225)');
  await db.execute("UPDATE products_rows SET cost = 100, price = 34.5 WHERE id = 'e2e-switch'");
  const preview = await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch', 'e2e-router'], dry_run: true }));
  assert.deepEqual(preview.changes.map(c => [c.id, c.after]), [['e2e-switch', 132.25]], 'recalcular desde el costo: $100 → $132,25 y omite lo que no tiene costo');
  await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch'] }));
  await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch'] }));
  const [[repriced]] = await db.query("SELECT price FROM products_rows WHERE id = 'e2e-switch'");
  assert.equal(Number(repriced.price), 132.25, 'aplicarlo dos veces no vuelve a subir el precio');

  await open(page, '/configuracion.html');
  const draftLabel = () => page.textContent('.settings-draft');
  assert.equal(await draftLabel(), 'Sin cambios pendientes');
  await page.click('[data-settings-tab="contact"]');
  const email = page.locator('[name="footer_email"]');
  const original = await email.inputValue();
  await email.fill('otro@example.test');
  assert.equal(await draftLabel(), 'Cambios sin guardar');
  await email.fill(original);
  assert.equal(await draftLabel(), 'Sin cambios pendientes', 'revertir un campo quita el aviso');

  await open(page, '/campanas.html');
  const draftKey = 'smartisp.store-campaign-draft.v2.store-home';
  const hasDraft = () => page.evaluate(key => !!localStorage.getItem(key), draftKey);
  assert.equal(await page.locator('dialog[open]').count(), 0, 'sin diálogo de borrador al abrir');
  const name = await page.inputValue('#campaignName');
  await page.fill('#campaignName', `${name} X`);
  await page.waitForTimeout(1000);
  assert.equal(await hasDraft(), true, 'un cambio real crea borrador');
  await page.fill('#campaignName', name);
  await page.waitForTimeout(1000);
  assert.equal(await hasDraft(), false, 'revertir borra el borrador');

  await open(page, '/editor-landing.html');
  assert.equal((await page.textContent('#statusMessageText')).trim(), 'Todos los cambios guardados');

  await db.execute("DELETE FROM categories_rows WHERE id IN ('e2e-cat-padre','e2e-cat-hija')");
  await db.execute("INSERT INTO categories_rows (id,name,subcategories,macro_group_id) VALUES ('e2e-cat-padre','E2E Principal','[]','redes'),('e2e-cat-hija','E2E Hija','[]','gaming')");
  await open(page, '/categorias.html');
  await page.fill('#search', 'E2E Principal');
  await page.click('[data-edit="e2e-cat-padre"]');
  await page.fill('#childSearch', 'E2E Hija');
  await page.click('[data-pick-child="e2e-cat-hija"]');
  assert.ok((await page.textContent('#childList')).includes('E2E Hija'), 'la hija aparece en la lista antes de guardar');
  await page.click('#saveCategory');
  await page.waitForFunction(() => !document.querySelector('#categoryDialog').open);
  const [[hija]] = await db.query("SELECT parent_category_id, macro_group_id FROM categories_rows WHERE id = 'e2e-cat-hija'");
  assert.deepEqual([hija.parent_category_id, hija.macro_group_id], ['e2e-cat-padre', 'redes'], 'la subcategoría se guarda con la familia de su principal');
  await page.fill('#search', 'E2E');
  await page.locator('.tree-children [data-category-id="e2e-cat-hija"]').waitFor({ timeout: 5000 }).catch(() => {});
  assert.equal(await page.locator('.tree-children [data-category-id="e2e-cat-hija"]').count(), 1, 'el árbol muestra la hija bajo su principal');
  await db.execute("DELETE FROM categories_rows WHERE id IN ('e2e-cat-padre','e2e-cat-hija')");
  await context.close();

  const mobile = await session({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  for (const screen of ['admin', 'pedidos']) {
    await open(mobile.page, `/${screen}.html`);
    assert.equal(await mobile.page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0, `${screen} sin scroll horizontal en móvil`);
  }
  await mobile.context.close();

  assert.deepEqual(problems, [], `errores en el navegador:\n${problems.join('\n')}`);
  console.log('E2E-OK');
} finally {
  await browser.close();
  php.kill();
  await db.end();
  fs.rmSync(uploads, { recursive: true, force: true });
}
