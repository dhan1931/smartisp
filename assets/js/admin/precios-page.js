document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, post, toast, icons, money, state: stateHtml } = SmartAdmin;
  const STATS = [['sin-precio', 'circle-dollar-sign', 'Visibles sin precio'], ['sin-costo', 'calculator', 'Con precio y sin costo'], ['ocultos', 'eye-off', 'Ocultos sin precio']];
  const params = new URLSearchParams(location.search);
  const st = { status: STATS.some(s => s[0] === params.get('status')) ? params.get('status') : 'sin-precio', q: '', category: '', brand: params.get('brand') || '', supplier: params.get('supplier') || '', page: 1, totalPages: 1 };
  let formula = { increments: window.SmartPricing.DEFAULT_INCREMENTS }, products = [], loadSeq = 0, running = false, hasSupplier = false;
  const filterParams = () => ({ status: st.status, q: st.q, category: st.category, brand: st.brand, supplier: st.supplier });
  const supplierLabel = name => name === '-' || !name ? 'Sin proveedor' : name;
  const fromCost = cost => window.SmartPricing.applyIncrements(cost, formula.increments);
  const factorText = v => (1 + v / 100).toLocaleString('es-EC', { maximumFractionDigits: 4 });
  const formulaText = () => `costo × ${formula.increments.map(factorText).join(' × ')} (ganancia ${formula.increments[0]} % + IVA ${formula.increments[1] ?? 0} %)`;
  // Montos de listas en formato 1,234.56, 1.234,56 o 1234,56.
  const parseMoney = v => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : null;
    let s = String(v ?? '').replace(/[^\d.,-]/g, '');
    if (!/\d/.test(s)) return null;
    const c = s.lastIndexOf(','), d = s.lastIndexOf('.');
    if (c > -1 && d > -1) s = c > d ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    else if (c > -1) s = /^\d{1,3}(,\d{3})+$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');
    else if (/^\d{1,3}(\.\d{3}){2,}$/.test(s)) s = s.replace(/\./g, '');
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
  };
  const thumb = url => url ? `<img src="${esc(url)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<i data-lucide="image-off" aria-hidden="true"></i>';
  const priceCell = p => p.price > 0 ? money(p.price) : '<span class="badge warn">A cotizar</span>';
  const row = p => `<tr><td class="price-thumb">${thumb(p.image)}</td><td><strong>${esc(p.name)}</strong><small>${esc([p.sku, p.category, p.supplier].filter(Boolean).join(' · '))}${p.visible ? '' : ' · oculto'}</small></td><td class="num">${priceCell(p)}</td><td class="num">${p.cost ? money(p.cost) : '—'}</td><td><form class="price-set" data-id="${esc(p.id)}"><input class="input" name="cost" inputmode="decimal" autocomplete="off" placeholder="Costo sin IVA" aria-label="Costo sin IVA de ${esc(p.name)}"><input class="input" name="price" inputmode="decimal" autocomplete="off" placeholder="Precio final" aria-label="Precio final con IVA de ${esc(p.name)}">${p.visible ? '' : `<label class="price-check"><input type="checkbox" name="show" checked> Mostrar</label>`}<button class="button small primary" type="submit">Guardar</button></form></td></tr>`;

  const load = async () => {
    const seq = ++loadSeq;
    try {
      const data = await api(`admin-prices?${new URLSearchParams({ ...filterParams(), page: st.page, limit: 50 })}`);
      if (seq !== loadSeq) return;
      formula = data.formula || formula; products = data.products || []; st.totalPages = data.totalPages || 1;
      $('#formula').textContent = formulaText();
      const c = data.counts || {};
      $('#stats').innerHTML = STATS.map(([key, icon, label]) => `<button type="button" class="stat ${key === st.status ? 'is-active' : ''} ${key === 'sin-precio' && c[key] ? 'is-alert' : ''}" data-status="${key}" aria-pressed="${key === st.status}"><span><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong>${c[key] || 0}</strong></button>`).join('');
      const cats = data.categories || [];
      if (st.category && !cats.some(cat => cat.name === st.category)) cats.unshift({ name: st.category, count: 0 });
      $('#category').innerHTML = '<option value="">Todas las categorías</option>' + cats.map(cat => `<option value="${esc(cat.name)}"${cat.name === st.category ? ' selected' : ''}>${esc(cat.name)} (${cat.count})</option>`).join('');
      if (st.brand && data.brand) st.brand = data.brand;
      const brands = data.brands || [];
      if (st.brand && !brands.some(b => b.name === st.brand)) brands.unshift({ name: st.brand, count: 0 });
      $('#brand').innerHTML = '<option value="">Todas las marcas</option>' + brands.map(b => `<option value="${esc(b.name)}"${b.name === st.brand ? ' selected' : ''}>${esc(b.name)} (${b.count})</option>`).join('');
      hasSupplier = !!data.hasSupplier;
      $('#supplier').hidden = !hasSupplier;
      const sups = (data.suppliers || []).map(s => ({ value: s.name || '-', count: s.count }));
      if (st.supplier && !sups.some(s => s.value === st.supplier)) sups.unshift({ value: st.supplier, count: 0 });
      $('#supplier').innerHTML = '<option value="">Todos los proveedores</option>' + sups.map(s => `<option value="${esc(s.value)}"${s.value === st.supplier ? ' selected' : ''}>${esc(supplierLabel(s.value))} (${s.count})</option>`).join('');
      $('#supplierOptions').innerHTML = [...new Set(['Intcomex', 'Siglo21', ...sups.map(s => s.value).filter(v => v !== '-')])].map(v => `<option value="${esc(v)}"></option>`).join('');
      $('#count').textContent = `${data.total || 0} producto${data.total === 1 ? '' : 's'}`;
      $('#pageInfo').textContent = `Página ${data.page || 1} de ${st.totalPages}`;
      $('#prev').disabled = st.page <= 1; $('#next').disabled = st.page >= st.totalPages;
      $('#rows').innerHTML = products.map(row).join('');
      $('#rows').querySelectorAll('img').forEach(img => img.addEventListener('error', () => { img.replaceWith(Object.assign(document.createElement('i'), { className: 'price-noimg' })); }));
      $('#empty').hidden = products.length !== 0;
      if (!products.length) $('#empty').innerHTML = st.q || st.category || st.brand || st.supplier ? stateHtml('search', 'Ningún producto coincide', 'Prueba con otra búsqueda, categoría, marca o proveedor.') : stateHtml('empty', 'Nada pendiente aquí', '', 'circle-check');
      icons();
    } catch (e) {
      if (seq !== loadSeq) return;
      $('#rows').innerHTML = ''; $('#empty').hidden = false; $('#empty').innerHTML = stateHtml('error', 'No se pudo cargar la lista', e.message); icons();
    }
  };

  // Poner el precio de un producto: el costo calcula el precio con la fórmula; un precio escrito a mano manda.
  $('#rows').addEventListener('input', e => {
    const form = e.target.closest('.price-set');
    if (!form) return;
    if (e.target.name === 'cost') { const cost = parseMoney(e.target.value); if (form.price.dataset.manual !== '1') form.price.value = cost > 0 ? fromCost(cost).toFixed(2) : ''; }
    else if (e.target.name === 'price') form.price.dataset.manual = e.target.value ? '1' : '';
  });
  $('#rows').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target, price = parseMoney(form.price.value), cost = parseMoney(form.cost.value);
    if (!(price > 0)) { toast('Escribe el costo o el precio final.', true); form.cost.focus(); return; }
    form.querySelector('button').disabled = true;
    try {
      const res = await post('admin-price-import', { apply: true, rows: [{ id: form.dataset.id, price, cost: cost > 0 ? cost : null, show: !!form.show?.checked }] });
      if (res.results?.[0]?.status !== 'saved') throw new Error('No se pudo guardar el precio.');
      toast(`Precio guardado: ${money(res.results[0].price)}.`);
      load();
    } catch (err) { toast(err.message, true); form.querySelector('button').disabled = false; }
  });

  // Exportar la lista actual para completarla y volver a subirla.
  const EXPORT_HEAD = ['id', 'sku', 'nombre', 'categoria', 'visible', 'precio_actual', 'costo_actual', 'costo_nuevo', 'precio_nuevo', 'proveedor'];
  let xlsxReady = null;
  const loadXlsx = () => xlsxReady || (xlsxReady = new Promise((resolve, reject) => {
    if (window.XLSX) { resolve(window.XLSX); return; }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
    s.integrity = 'sha512-r22gChDnGvBylk90+2e/ycr3RVrDi8DIOkIGNhJlKfuyQM4tIRAI062MaV8sfjQKYVGjOBaZBOA87z+IhZE9DA==';
    s.crossOrigin = 'anonymous'; s.referrerPolicy = 'no-referrer';
    s.onload = () => resolve(window.XLSX);
    s.onerror = () => { xlsxReady = null; reject(new Error('No se pudo cargar el lector de Excel. Revisa la conexión o usa CSV.')); };
    document.head.append(s);
  }));
  const slug = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-');
  const exportList = async xlsx => {
    try {
      const [X, data] = await Promise.all([xlsx ? loadXlsx() : null, api(`admin-prices?${new URLSearchParams({ ...filterParams(), q: $('#search').value.trim(), all: 1 })}`)]);
      const list = data.products || [];
      if (!list.length) { toast('No hay productos en esta lista.', true); return; }
      const num = v => v > 0 ? (xlsx ? v : String(v).replace('.', ',')) : '';
      const rows = list.map(p => [p.id, p.sku || '', p.name, p.category || '', p.visible ? 'Sí' : 'No', num(p.price), num(p.cost), '', '', p.supplier || '']);
      const name = `precios-${st.status}${[st.category, st.brand, st.supplier && supplierLabel(st.supplier)].filter(Boolean).map(v => '-' + slug(v)).join('')}`;
      if (xlsx) {
        const ws = X.utils.aoa_to_sheet([EXPORT_HEAD, ...rows]);
        ws['!cols'] = [18, 22, 60, 26, 8, 13, 13, 13, 13, 14].map(wch => ({ wch }));
        const wb = X.utils.book_new(); X.utils.book_append_sheet(wb, ws, 'Precios'); X.writeFile(wb, name + '.xlsx');
      } else SmartAdmin.csv(name + '.csv', EXPORT_HEAD, rows);
      toast(`${rows.length} producto${rows.length === 1 ? '' : 's'} exportado${rows.length === 1 ? '' : 's'}.`);
    } catch (e) { toast(e.message, true); }
  };
  $('#exportCsv').addEventListener('click', () => exportList(false));
  $('#exportXlsx').addEventListener('click', () => exportList(true));

  // Leer listas de proveedores (Excel o CSV): el encabezado puede no estar en la primera fila.
  const parseCsv = text => {
    text = text.replace(/^﻿/, '');
    const first = text.split(/\r?\n/, 1)[0];
    const sep = [';', ',', '\t'].reduce((best, c) => first.split(c).length > first.split(best).length ? c : best, ';');
    const rows = []; let r = [], cell = '', quoted = false;
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (quoted) { if (ch !== '"') cell += ch; else if (text[i + 1] === '"') { cell += '"'; i++; } else quoted = false; }
      else if (ch === '"') quoted = true;
      else if (ch === sep) { r.push(cell); cell = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; r.push(cell); rows.push(r); r = []; cell = ''; }
      else cell += ch;
    }
    if (cell || r.length) { r.push(cell); rows.push(r); }
    return rows;
  };
  const readTable = async file => {
    if (!/\.xlsx?$/i.test(file.name)) return parseCsv(await file.text());
    const X = await loadXlsx(), wb = X.read(await file.arrayBuffer(), { type: 'array' });
    return X.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, defval: '', raw: true });
  };
  const colName = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  const cellText = v => String(v ?? '').trim().replace(/^'/, '');
  const COLS = {
    id: /^id$/, sku: /^(sku|codigo|cod|codigo_producto|item_?code|sku_proveedor|codigo_proveedor|referencia)$/,
    part: /^(no_de_parte|numero_de_parte|n_de_parte|nro_de_parte|num_parte|parte|part_?number|part_no|pn|mpn|modelo)$/,
    name: /^(nombre|producto|descripcion|name|item_?name)$/, stock: /^(disponibilidad|stock|existencia|existencias|cantidad|inventario)$/, supplier: /^(proveedor|supplier)$/,
    price: /^(precio_nuevo|precio_final|precio_con_iva|pvp|precio_venta|precio_de_venta)$/,
    cost: /^(costo_nuevo|costo|costo_sin_iva|precio_sin_iva|neto|valor_neto|cost)$/,
    generic: /^(precio|price|precio_lista|precio_distribuidor|precio_unitario|valor)$/
  };
  const toImportRows = (table, file, genericAs) => {
    for (let h = 0; h < Math.min(table.length, 15); h++) {
      const head = table[h].map(colName), col = k => head.findIndex(x => COLS[k].test(x));
      const c = Object.fromEntries(Object.keys(COLS).map(k => [k, col(k)]));
      if (![c.id, c.sku, c.part].some(i => i >= 0) || ![c.price, c.cost, c.generic].some(i => i >= 0)) continue;
      const costCol = c.cost >= 0 ? c.cost : (genericAs === 'cost' ? c.generic : -1), priceCol = c.price >= 0 ? c.price : (genericAs === 'price' ? c.generic : -1);
      const get = (r, i) => i >= 0 ? r[i] : '';
      const rows = table.slice(h + 1).map((r, i) => ({ row: h + i + 2, file, id: cellText(get(r, c.id)), sku: cellText(get(r, c.sku)), part: cellText(get(r, c.part)), name: cellText(get(r, c.name)), stock: cellText(get(r, c.stock)), supplier: cellText(get(r, c.supplier)), cost: parseMoney(get(r, costCol)), price: parseMoney(get(r, priceCol)) }))
        .filter(r => (r.id || r.sku || r.part) && (r.cost > 0 || r.price > 0));
      return { rows, generic: c.cost < 0 && c.price < 0 && c.generic >= 0 ? String(table[h][c.generic]).trim() : '' };
    }
    return null;
  };

  const STATUS = {
    quote: ['ok', 'Sin precio: se pone'], up: ['info', 'Sube'], down: ['info', 'Baja'], cost: ['ok', 'Mismo precio: se guarda el costo'],
    same: ['', 'Ya tiene ese precio'], suspicious: ['warn', 'Cambio muy grande: revísalo'], no_price: ['bad', 'Sin precio en el archivo'],
    not_found: ['bad', 'No está en la tienda'], saved: ['ok', 'Guardado'], invalid: ['bad', 'Precio no válido']
  };
  const WRITABLE = ['quote', 'up', 'down', 'cost', 'suspicious'];
  const FILTERS = [['all', 'Todas'], ['checked', 'Marcadas para guardar'], ['quote', 'Sin precio'], ['up', 'Suben'], ['down', 'Bajan'], ['suspicious', 'Cambio muy grande'], ['not_found', 'No están en la tienda'], ['same', 'Ya tienen ese precio']];
  // importSupplier: proveedor que se guarda en los productos con precio nuevo (lo detectado o lo que escriba el usuario).
  let files = [], importRows = [], genericCol = '', genericAs = 'cost', filter = 'all', importSupplier = '';
  const writable = r => WRITABLE.includes(r.status) && !r.done;
  const noStock = r => /^(0+([.,]0+)?|agotado|sin stock|no disponible)$/i.test(String(r.stock || '').trim());
  const shown = () => importRows.map((r, i) => [r, i]).filter(([r]) => filter === 'all' || (filter === 'checked' ? r.checked && writable(r) : r.status === filter));
  const change = r => r.oldPrice > 0 && r.newPrice > 0 && ['up', 'down', 'suspicious'].includes(r.status) ? ` ${r.newPrice > r.oldPrice ? '+' : ''}${(Math.round((r.newPrice / r.oldPrice - 1) * 1000) / 10).toLocaleString('es-EC')} %` : '';
  const say = text => { $('#runStatus').textContent = text; $('#runBox').hidden = !text; };
  const renderImport = () => {
    const box = $('#importBox'), counts = {};
    importRows.forEach(r => { counts[r.status] = (counts[r.status] || 0) + 1; });
    const todo = importRows.filter(r => r.checked && writable(r)).length, stockOff = importRows.filter(r => r.checked && writable(r) && noStock(r)).length;
    const missing = importRows.filter(r => r.status === 'not_found').length, list = shown(), cut = list.slice(0, 800);
    box.hidden = false;
    box.innerHTML = `<div class="price-import-summary"><strong>${importRows.length} fila${importRows.length === 1 ? '' : 's'}</strong>${Object.entries(counts).map(([k, n]) => `<span class="badge ${STATUS[k]?.[0] || ''}">${n} · ${esc(STATUS[k]?.[1] || k)}</span>`).join('')}</div>
      <p class="price-hint">Precio nuevo = ${esc(formulaText())} cuando el archivo trae el costo del proveedor.</p>
      ${hasSupplier ? `<label class="price-generic">Proveedor que se guarda en estos productos <input class="input" id="importSupplier" list="supplierOptions" maxlength="60" value="${esc(importSupplier)}" placeholder="No cambiar" autocomplete="off"></label>` : ''}
      ${genericCol ? `<label class="price-generic">La columna «${esc(genericCol)}» es <select class="select" id="genericAs"><option value="cost"${genericAs === 'cost' ? ' selected' : ''}>el costo del proveedor sin IVA (se aplica la fórmula)</option><option value="price"${genericAs === 'price' ? ' selected' : ''}>el precio final con IVA</option></select></label>` : ''}
      <div class="price-actions"><button class="button primary" type="button" id="importApply"${todo && !running ? '' : ' disabled'}><i data-lucide="check" aria-hidden="true"></i> Guardar ${todo} precio${todo === 1 ? '' : 's'}</button>${stockOff ? `<button class="button" type="button" id="importNoStock">Desmarcar los que el proveedor no tiene en stock (${stockOff})</button>` : ''}<button class="button" type="button" id="importReport"><i data-lucide="file-down" aria-hidden="true"></i> Descargar resultado (CSV)</button>${missing ? `<button class="button" type="button" id="importMissing"><i data-lucide="file-down" aria-hidden="true"></i> Los que no están en la tienda (${missing})</button>` : ''}<button class="button" type="button" id="importClose">Cerrar</button></div>
      <label class="price-filter">Mostrar <select class="select" id="importFilter">${FILTERS.map(([k, label]) => `<option value="${k}"${k === filter ? ' selected' : ''}>${label}</option>`).join('')}</select></label>
      <div class="table-wrap price-import-table"><table><thead><tr><th><input type="checkbox" id="importAll" aria-label="Marcar o desmarcar todas las de esta vista"${running ? ' disabled' : ''}></th><th>En el archivo</th><th>En la tienda</th><th class="num">Precio actual</th><th class="num">Precio nuevo</th><th>Stock proveedor</th><th>Qué pasa</th></tr></thead><tbody>${cut.map(([r, i]) => {
        const [tone, label] = STATUS[r.status] || ['', r.status], can = writable(r);
        const flags = (r.how === 'nombre' ? '<span class="badge warn">Revisar: coincide por el nombre</span>' : '') + (r.multi ? '<span class="badge warn">Varios productos con ese código</span>' : '');
        return `<tr><td><input type="checkbox" data-import-row="${i}" aria-label="Guardar el precio de la fila ${r.row}"${r.checked && can ? ' checked' : ''}${can && !running ? '' : ' disabled'}></td><td><small>${esc(r.file)} · fila ${r.row}</small><strong>${esc(r.key)}</strong>${r.supplierName ? `<small>${esc(r.supplierName)}</small>` : ''}</td><td>${r.name ? `<strong>${esc(r.name)}</strong><small>${esc([r.sku, r.category, r.supplier].filter(Boolean).join(' · '))}${r.visible ? '' : ' · oculto'}</small>${flags}` : '—'}</td><td class="num">${r.name ? (r.oldPrice > 0 ? money(r.oldPrice) : 'A cotizar') : '—'}${r.oldCost ? `<small>costo ${money(r.oldCost)}</small>` : ''}</td><td class="num"><strong>${r.newPrice > 0 ? money(r.newPrice) : '—'}</strong>${r.newCost ? `<small>costo ${money(r.newCost)}</small>` : ''}</td><td>${esc(r.stock || '—')}${noStock(r) ? ' <span class="badge warn">sin stock</span>' : ''}</td><td><span class="badge ${tone}">${esc(label)}${esc(change(r))}</span></td></tr>`;
      }).join('')}</tbody></table></div>${list.length > cut.length ? `<p class="price-hint">Se muestran ${cut.length} de ${list.length}: usa «Mostrar» para ver el resto.</p>` : ''}`;
    icons();
  };
  const runPreview = async () => {
    const all = [], guesses = new Set();
    genericCol = '';
    for (const f of files) {
      const parsed = toImportRows(f.table, f.name, genericAs);
      if (!parsed) { toast(`${f.name}: no encontré las columnas. Necesito SKU, No. de Parte o id, y Precio o costo.`, true); continue; }
      genericCol = genericCol || parsed.generic;
      guesses.add(window.SmartPricing.guessSupplier(parsed.rows.map(r => r.sku)));
      all.push(...parsed.rows);
    }
    importSupplier = guesses.size === 1 ? [...guesses][0] : '';
    if (!all.length) { toast('Los archivos no tienen filas con código y precio.', true); return; }
    if (all.length > 20000) { toast('Máximo 20000 filas a la vez.', true); return; }
    say(`Buscando ${all.length} filas en la tienda…`);
    const results = [];
    try {
      for (let i = 0; i < all.length; i += 1000) {
        const res = await post('admin-price-import', { rows: all.slice(i, i + 1000).map(({ supplier, ...r }) => ({ ...r, fileSupplier: supplier })) });
        formula = res.formula || formula;
        results.push(...(res.results || []));
      }
    } catch (e) { say(''); toast(e.message, true); return; }
    say('');
    importRows = results.map(r => ({ ...r, checked: ['quote', 'up', 'down', 'cost'].includes(r.status) && !r.multi && r.how !== 'nombre' }));
    filter = 'all';
    renderImport();
  };
  const previewImport = async list => {
    if (!list.length || running) return;
    try {
      files = [];
      for (const file of list) files.push({ name: file.name, table: await readTable(file) });
      genericAs = 'cost';
      await runPreview();
    } catch (e) { toast(e.message, true); }
    $('#importFile').value = '';
  };
  const applyImport = async () => {
    const todo = importRows.filter(r => r.checked && writable(r));
    if (!todo.length || running) return;
    const show = $('#importShow').checked;
    running = true; $('#stop').hidden = false; renderImport();
    let saved = 0;
    try {
      while (running && todo.some(r => !r.done)) {
        const batch = todo.filter(r => !r.done).slice(0, 100);
        const { results = [] } = await post('admin-price-import', { apply: true, rows: batch.map(r => ({ id: r.id, price: r.newPrice, cost: r.newCost, show, supplier: r.fileSupplier || importSupplier })) });
        batch.forEach((r, i) => { const out = results[i] || {}; Object.assign(r, { status: out.status === 'saved' ? 'saved' : (out.status || 'invalid'), done: true }); if (out.status === 'saved') saved++; });
        say(`Precios guardados: ${saved} · faltan ${todo.filter(r => !r.done).length}`);
        renderImport();
      }
    } catch (e) { toast(e.message, true); }
    running = false; $('#stop').hidden = true; renderImport(); load();
    if (saved) toast(`${saved} precio${saved === 1 ? '' : 's'} guardado${saved === 1 ? '' : 's'}.`);
  };
  $('#importFile').addEventListener('change', e => previewImport([...e.target.files]));
  $('#stop').addEventListener('click', () => { running = false; say('Pausando al terminar la tanda en curso…'); });
  $('#importBox').addEventListener('change', e => {
    const cb = e.target.closest('[data-import-row]');
    if (cb) { importRows[Number(cb.dataset.importRow)].checked = cb.checked; renderImport(); }
    else if (e.target.id === 'importAll') { shown().forEach(([r]) => { if (writable(r)) r.checked = e.target.checked; }); renderImport(); }
    else if (e.target.id === 'importFilter') { filter = e.target.value; renderImport(); }
    else if (e.target.id === 'genericAs') { genericAs = e.target.value; runPreview(); }
  });
  $('#importBox').addEventListener('input', e => { if (e.target.id === 'importSupplier') importSupplier = e.target.value.trim(); });
  $('#importBox').addEventListener('click', e => {
    if (e.target.closest('#importApply')) applyImport();
    else if (e.target.closest('#importNoStock')) { importRows.forEach(r => { if (noStock(r)) r.checked = false; }); renderImport(); }
    else if (e.target.closest('#importClose')) { importRows = []; files = []; $('#importBox').hidden = true; $('#importBox').innerHTML = ''; }
    else if (e.target.closest('#importReport')) SmartAdmin.csv('precios-importacion-resultado.csv', ['archivo', 'fila', 'codigo', 'producto_proveedor', 'id', 'producto_tienda', 'precio_actual', 'precio_nuevo', 'costo_nuevo', 'stock_proveedor', 'resultado'], importRows.map(r => [r.file, r.row, r.key, r.supplierName || '', r.id || '', r.name || '', r.oldPrice > 0 ? String(r.oldPrice).replace('.', ',') : '', r.newPrice > 0 ? String(r.newPrice).replace('.', ',') : '', r.newCost > 0 ? String(r.newCost).replace('.', ',') : '', r.stock || '', STATUS[r.status]?.[1] || r.status]));
    else if (e.target.closest('#importMissing')) SmartAdmin.csv('precios-no-estan-en-la-tienda.csv', ['archivo', 'fila', 'codigo', 'numero_de_parte', 'nombre', 'costo', 'precio_calculado', 'stock_proveedor'], importRows.filter(r => r.status === 'not_found').map(r => [r.file, r.row, r.key, r.part || '', r.supplierName || '', r.newCost > 0 ? String(r.newCost).replace('.', ',') : '', r.newPrice > 0 ? String(r.newPrice).replace('.', ',') : '', r.stock || '']));
  });

  $('#stats').addEventListener('click', e => { const b = e.target.closest('[data-status]'); if (b && b.dataset.status !== st.status) { st.status = b.dataset.status; st.page = 1; load(); } });
  let debounce; $('#search').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); });
  $('#category').addEventListener('change', e => { st.category = e.target.value; st.page = 1; load(); });
  $('#brand').addEventListener('change', e => { st.brand = e.target.value; st.page = 1; load(); });
  $('#supplier').addEventListener('change', e => { st.supplier = e.target.value; st.page = 1; load(); });
  $('#refresh').addEventListener('click', load);
  $('#prev').addEventListener('click', () => { if (st.page > 1) { st.page--; load(); } });
  $('#next').addEventListener('click', () => { if (st.page < st.totalPages) { st.page++; load(); } });
  $('#stop').hidden = true;
  await load();
}));
