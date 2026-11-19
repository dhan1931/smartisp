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
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('e2e-precio-a','Precio E2E Alfa',0,1,'Redes','E2E-PRA'),('e2e-precio-b','Precio E2E Beta',0,1,'Redes','E2E-PRB')");
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('e2e-switch','Switch E2E 8 puertos',34.5,1,'Redes','E2E-SW')");
await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku,image_url) VALUES ('e2e-foto-a','Foto E2E Alfa',10,1,'Redes','E2E-FA',''),('e2e-foto-b','Foto E2E Beta',10,1,'Redes','E2E-FB',''),('e2e-foto-c','Foto E2E Gamma',10,1,'Redes','E2E-FC','/api/auth/serve-upload?category=products&file=e2e-gamma.webp')");
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
  cwd: root, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4', SMARTISP_UPLOADS_DIR: uploads }, stdio: 'ignore',
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
const shot = async (page, name) => { if (process.env.E2E_SHOTS) await page.screenshot({ path: `${process.env.E2E_SHOTS}/${name}.png`, fullPage: true }); };

try {
  const { context, page } = await session();

  for (const screen of ['admin', 'pedidos', 'clientes', 'cotizaciones', 'inventario', 'estadisticas', 'reportes', 'categorias', 'configuracion', 'campanas', 'portada', 'editor-catalogo', 'editor-landing', 'equipo', 'actividad', 'fotos', 'precios', 'resenas']) {
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
  const [[hiddenCount]] = await db.query('SELECT COUNT(*) n FROM products_rows WHERE visible = 0');
  assert.equal(await page.textContent('#statProductsHidden'), String(hiddenCount.n), 'la tarjeta de ocultos cuenta los productos ocultos');
  assert.match(await page.locator('#filterMacroCat option[value="Redes"]').textContent(), /^Redes \(\d+\)$/, 'cada categoría del filtro muestra cuántos productos tiene');
  const scopedLoad = page.waitForResponse(res => res.url().includes('action=admin-products&') && res.url().includes('category=Redes'));
  await page.selectOption('#filterMacroCat', 'Redes');
  await scopedLoad;
  await page.waitForSelector('#scopeSummary:not([hidden])');
  assert.match(await page.textContent('#scopeSummary'), /Redes\s*\d+ productos?/, 'filtrar por categoría muestra cuántos productos tiene, visibles y ocultos');
  await shot(page, 'productos');
  const hiddenLoad = page.waitForResponse(res => res.url().includes('action=admin-products&') && res.url().includes('visible=hidden'));
  await page.click('#scopeSummary [data-scope-value="hidden"]');
  await hiddenLoad;
  assert.equal(await page.inputValue('#filterVisibility'), 'hidden', 'la cifra de ocultos del resumen filtra los ocultos');

  await page.evaluate(() => { for (const id of ['importPriceMargin', 'importPriceMargin2']) { const input = document.getElementById(id); input.value = id === 'importPriceMargin' ? '25' : '15'; input.dispatchEvent(new Event('input', { bubbles: true })); } });
  assert.ok((await page.textContent('#importPriceFormula')).includes('143,75'), 'el importador aplica 25 % de ganancia y luego 15 % de IVA (× 1,4375)');
  await db.execute("UPDATE products_rows SET cost = 100, price = 34.5 WHERE id = 'e2e-switch'");
  const preview = await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch', 'e2e-router'], dry_run: true }));
  assert.deepEqual(preview.changes.map(c => [c.id, c.after]), [['e2e-switch', 143.75]], 'recalcular desde el costo: $100 → $143,75 y omite lo que no tiene costo');
  await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch'] }));
  await page.evaluate(() => SmartAdmin.post('admin-products-bulk-update', { op: 'price', mode: 'from_cost', value: 0, ids: ['e2e-switch'] }));
  const [[repriced]] = await db.query("SELECT price FROM products_rows WHERE id = 'e2e-switch'");
  assert.equal(Number(repriced.price), 143.75, 'aplicarlo dos veces no vuelve a subir el precio');

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
  await shot(page, 'categorias');
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
  // Portada: las 7 primeras categorías destacadas se ven siempre y las demás se despliegan con «Más categorías».
  const featuredIds = Array.from({ length: 9 }, (_, i) => `e2e-mc-${i + 1}`);
  await db.execute("DELETE FROM categories_rows WHERE id LIKE 'e2e-mc-%'");
  await db.execute(`INSERT INTO categories_rows (id,name,subcategories,macro_group_id) VALUES ${featuredIds.map((id, i) => `('${id}','E2E Destacada ${i + 1}','[]','redes')`).join(',')}`);
  await db.execute(`INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ${featuredIds.map((id, i) => `('${id}-p','Producto ${id}',10,1,'E2E Destacada ${i + 1}','E2E-MC${i}')`).join(',')}`);
  const [[savedFeatured]] = await db.query("SELECT setting_value FROM settings_rows WHERE setting_key = 'store_featured_category_ids'");
  await db.execute("INSERT INTO settings_rows (setting_key, setting_value) VALUES ('store_featured_category_ids', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [JSON.stringify(featuredIds)]);
  const store = await session();
  await open(store.page, '/tienda.html');
  await store.page.waitForSelector('#miniCategories.is-visible .mini-category-more');
  const visibleMini = () => store.page.$$eval('#miniCategoriesGrid [data-mini-key]', cards => cards.filter(card => card.offsetParent !== null).map(card => card.dataset.miniKey));
  assert.deepEqual(await visibleMini(), featuredIds.slice(0, 7), 'se ven las 7 primeras en el orden elegido');
  await store.page.click('.mini-category-more');
  assert.deepEqual(await visibleMini(), featuredIds, 'al tocar «Más categorías» se despliegan las demás');
  assert.equal(await store.page.getAttribute('.mini-category-more', 'aria-expanded'), 'true');
  assert.equal((await store.page.textContent('.mini-category-more')).trim(), 'Menos categorías');
  await store.page.click('.mini-category-more');
  assert.equal((await visibleMini()).length, 7, 'y se vuelven a plegar');
  await store.context.close();
  await db.execute("DELETE FROM products_rows WHERE id LIKE 'e2e-mc-%'");
  await db.execute("DELETE FROM categories_rows WHERE id LIKE 'e2e-mc-%'");
  if (savedFeatured) await db.execute("UPDATE settings_rows SET setting_value = ? WHERE setting_key = 'store_featured_category_ids'", [savedFeatured.setting_value]);
  else await db.execute("DELETE FROM settings_rows WHERE setting_key = 'store_featured_category_ids'");

  await db.execute("DELETE FROM product_reviews WHERE product_id LIKE 'e2e-%'");
  await db.execute("INSERT INTO products_rows (id,name,price,visible,category,sku) VALUES ('e2e-resena','Antena E2E Opiniones',25,1,'Redes','E2E-RES')");
  await open(page, '/producto/e2e-resena');
  await page.waitForFunction(() => document.querySelector('#btnWriteReview')?.getAttribute('role') === 'button');
  await page.click('#btnWriteReview');
  await page.click('label[for="rs4"]');
  await page.fill('#reviewBody', 'Buena antena, llegó rápido y bien empacada.');
  await page.click('#reviewForm [type="submit"]');
  await page.waitForFunction(() => document.querySelector('#reviewMsg')?.textContent.includes('cuando la revisemos'));
  await shot(page, 'resena-ficha');
  await open(page, '/resenas.html');
  const pendingCard = page.locator('.review-card', { hasText: 'Buena antena' });
  await pendingCard.waitFor();
  assert.ok((await pendingCard.textContent()).includes('Sin compra registrada'), 'sin compra la reseña espera en el panel');
  await shot(page, 'resenas');
  await pendingCard.locator('[data-op="publish"]').click();
  await page.waitForFunction(() => !document.querySelector('#list')?.textContent.includes('Buena antena'));
  await open(page, '/producto/e2e-resena');
  assert.ok((await page.textContent('#reviewsList')).includes('Buena antena'), 'publicada, se ve en la ficha');
  assert.ok((await page.textContent('.rating-summary')).includes('4,0'), 'el promedio aparece junto al título');
  assert.equal((await page.textContent('#btnWriteReview')).trim(), 'Editar tu reseña', 'quien ya opinó puede editar la suya');
  await shot(page, 'resena-publicada');
  await db.execute("DELETE FROM product_reviews WHERE product_id = 'e2e-resena'");
  await db.execute("DELETE FROM products_rows WHERE id = 'e2e-resena'");

  await open(page, '/precios.html');
  await page.fill('#search', 'Precio E2E');
  await page.waitForFunction(() => document.querySelectorAll('#rows tr').length === 2);
  assert.ok((await page.textContent('#formula')).includes('1,25 × 1,15'), 'muestra la fórmula: ' + await page.textContent('#formula'));
  await shot(page, 'precios');
  const priceForm = page.locator('form.price-set[data-id="e2e-precio-b"]');
  await priceForm.locator('input[name="cost"]').fill('100');
  assert.equal(await priceForm.locator('input[name="price"]').inputValue(), '143.75', 'el costo calcula el precio con la fórmula');
  await priceForm.locator('button[type="submit"]').click();
  await page.waitForFunction(() => document.querySelectorAll('#rows tr').length === 1);
  const [[priceB]] = await db.query("SELECT price, cost FROM products_rows WHERE id = 'e2e-precio-b'");
  assert.deepEqual([Number(priceB.price), Number(priceB.cost)], [143.75, 100], 'guarda el precio y el costo');
  const priceDownload = page.waitForEvent('download');
  await page.click('#exportCsv');
  const priceFile = await priceDownload;
  assert.equal(priceFile.suggestedFilename(), 'precios-sin-precio.csv', 'exporta la lista de la pestaña a CSV');
  const priceCsv = fs.readFileSync(await priceFile.path(), 'utf8').trim().split(/\r?\n/);
  assert.ok(priceCsv[0].endsWith('costo_nuevo;precio_nuevo'), 'el CSV trae las columnas para completar: ' + priceCsv[0]);
  assert.deepEqual(priceCsv.slice(1).map(l => l.split(';')[2]), ['Precio E2E Alfa'], 'y lo que se ve con la búsqueda');
  await page.setInputFiles('#importFile', { name: 'intcomex.csv', mimeType: 'text/csv', buffer: Buffer.from('Lista de Precios;;;;;;\nCategoría;Nombre;Marca;Precio;Disponibilidad;No. de Parte;SKU\nPuntos de Acceso;Ubiquiti Alfa;Ubiquiti;116;3;U7-Lite;E2E-PRA\nPuntos de Acceso;Otro;Ubiquiti;188;0;U7-LR;NO-EXISTE-E2E\n') });
  await page.waitForSelector('#importBox:not([hidden]) table');
  const priceImport = await page.textContent('#importBox');
  assert.ok(priceImport.includes('Sin precio: se pone') && priceImport.includes('No está en la tienda') && priceImport.includes('166,75'), 'la vista previa de la lista de Intcomex: ' + priceImport.slice(0, 400));
  assert.ok(await page.isVisible('#genericAs'), 'pregunta si la columna «Precio» es costo o precio final');
  assert.equal((await page.textContent('#importApply')).trim(), 'Guardar 1 precio', 'solo ofrece guardar lo que está en la tienda');
  await shot(page, 'precios-importar');
  await page.click('#importApply');
  await page.waitForFunction(() => document.querySelector('#importBox')?.textContent.includes('Guardado'));
  const [[priceA]] = await db.query("SELECT price, cost FROM products_rows WHERE id = 'e2e-precio-a'");
  assert.deepEqual([Number(priceA.price), Number(priceA.cost)], [166.75, 116], 'la lista del proveedor pone precio = costo × 1,25 × 1,15');

  await open(page, '/fotos.html?status=sin-foto');
  await page.fill('#search', 'Foto E2E');
  await page.locator('#grid .photo-card').nth(1).waitFor();
  await page.click('#quickMode');
  const quickName = () => page.textContent('#qCard h3');
  assert.equal(await quickName(), 'Foto E2E Alfa');
  await page.evaluate(async () => {
    window.openedTabs = []; window.open = url => { window.openedTabs.push(url); return null; };
    const canvas = Object.assign(document.createElement('canvas'), { width: 320, height: 320 });
    const g = canvas.getContext('2d'); g.fillStyle = '#c33'; g.fillRect(0, 0, 320, 320);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    const data = new DataTransfer(); data.items.add(new File([blob], 'foto.png', { type: 'image/png' }));
    document.querySelector('#qUrl').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
  });
  await page.waitForFunction(() => document.querySelector('#qCard h3')?.textContent === 'Foto E2E Beta');
  assert.ok(decodeURIComponent((await page.evaluate(() => window.openedTabs))[0] || '').toLowerCase().includes('beta'), 'al pegar abre la búsqueda del siguiente producto');
  await page.waitForFunction(() => document.querySelector('#qStatus')?.textContent.includes('Guardadas: 1'));
  const [[pasted]] = await db.query("SELECT image_url FROM products_rows WHERE id = 'e2e-foto-a'");
  assert.match(pasted.image_url, /^\/api\/auth\/serve-upload\?category=products&file=/, 'pegar la imagen la guarda en el servidor');
  await page.evaluate(() => {
    const data = new DataTransfer(); data.setData('text/plain', 'hola');
    document.querySelector('#qUrl').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
  });
  await page.waitForFunction(() => document.querySelector('#qMsg')?.textContent.includes('no es una imagen'));
  assert.equal(await quickName(), 'Foto E2E Beta', 'si lo pegado no sirve, se queda en el mismo producto');
  assert.equal(await page.inputValue('#qUrl'), '', 'y la casilla queda vacía para pegar de nuevo');
  await page.keyboard.press('Escape');
  const csvDownload = page.waitForEvent('download');
  await page.click('#exportCsv');
  const csvFile = await csvDownload;
  assert.equal(csvFile.suggestedFilename(), 'fotos-sin-foto.csv', 'exporta la lista de la pestaña a CSV');
  const csvText = fs.readFileSync(await csvFile.path(), 'utf8');
  assert.deepEqual(csvText.trim().split(/\r?\n/).slice(1).map(l => l.split(';')[2]), ['Foto E2E Beta'], 'el CSV trae lo que se ve con la búsqueda y la columna para completar: ' + csvText);
  await page.setInputFiles('#importFile', { name: 'fotos.csv', mimeType: 'text/csv', buffer: Buffer.from('id;sku;foto_nueva\ne2e-foto-b;;https://example.test/beta.jpg\n;NO-EXISTE;https://example.test/x.jpg\n') });
  await page.waitForSelector('#importBox:not([hidden]) table');
  const importText = await page.textContent('#importBox');
  assert.ok(importText.includes('Sin foto: se pone') && importText.includes('Ningún producto con ese SKU o id'), 'la vista previa dice qué pasa con cada fila: ' + importText.slice(0, 300));
  assert.equal((await page.textContent('#importApply')).trim(), 'Guardar 1 foto', 'solo ofrece guardar las que se pueden poner');
  if (process.env.E2E_SHOTS) await page.screenshot({ path: `${process.env.E2E_SHOTS}/fotos-importar.png`, fullPage: true });
  await page.click('#importClose');
  await page.click('[data-status="una-foto"]');
  await page.fill('#search', 'Foto E2E Gamma');
  await page.waitForFunction(() => [...document.querySelectorAll('#grid .photo-card h3')].map(h => h.textContent).join() === 'Foto E2E Gamma');
  assert.ok(await page.isVisible('#grid [data-add="e2e-foto-c"]'), 'en «Una sola foto» cada producto ofrece añadir otra foto');
  const extraDownload = page.waitForEvent('download');
  await page.click('#exportCsv');
  const extraCsv = fs.readFileSync(await (await extraDownload).path(), 'utf8');
  assert.ok(extraCsv.split(/\r?\n/)[0].endsWith('foto_extra'), 'la lista de «Una sola foto» se exporta con la columna foto_extra: ' + extraCsv.slice(0, 200));
  await page.setInputFiles('#importFile', { name: 'extra.csv', mimeType: 'text/csv', buffer: Buffer.from('id;foto_extra\ne2e-foto-c;https://example.test/gamma-2.jpg|https://example.test/gamma-3.jpg\n') });
  await page.waitForSelector('#importBox:not([hidden]) table');
  assert.ok(await page.isChecked('#importExtra'), 'un archivo con foto_extra se importa como fotos extra');
  const extraText = await page.textContent('#importBox');
  assert.ok(extraText.includes('Se añaden 2 fotos (tiene 1)'), 'la vista previa dice cuántas fotos se suman: ' + extraText.slice(0, 300));
  assert.equal((await page.textContent('#importApply')).trim(), 'Añadir fotos a 1 producto', 'el botón dice a cuántos productos se añaden fotos');
  if (process.env.E2E_SHOTS) await page.screenshot({ path: `${process.env.E2E_SHOTS}/fotos-extra.png`, fullPage: true });
  await page.click('#importClose');
  const hiddenOnly = page.waitForResponse(res => res.url().includes('action=admin-photos') && res.url().includes('visible=0'));
  await page.selectOption('#visibility', '0');
  await hiddenOnly;
  await page.waitForSelector('#empty:not([hidden])');
  assert.ok((await page.textContent('#empty')).includes('Ningún producto coincide'), 'con «Solo ocultos» no aparecen los productos visibles');
  await open(page, '/fotos.html');
  assert.equal(await page.inputValue('#visibility'), '0', 'recuerda la visibilidad elegida');
  await page.selectOption('#visibility', '');
  await context.close();

  const mobile = await session({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  for (const screen of ['admin', 'pedidos', 'precios']) {
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
