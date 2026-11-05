document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, post, toast, icons, state: stateHtml, modal } = SmartAdmin;
  const STATS = [['rotas', 'image-off', 'Rotas'], ['sin-foto', 'image-minus', 'Sin foto'], ['por-revisar', 'hourglass', 'Por revisar'], ['web', 'globe', 'Desde la web: revisar'], ['propias', 'hard-drive', 'En tu servidor']];
  const SOURCE = { icecat: 'Fabricante', archivo: 'Internet Archive', web: 'Web', manual: 'Elegida a mano', lote: 'Subida en lote' };
  const params = new URLSearchParams(location.search);
  const st = { status: STATS.some(s => s[0] === params.get('status')) ? params.get('status') : 'rotas', q: '', page: 1, totalPages: 1 };
  let webSearch = false, loadSeq = 0, running = false, products = [];

  const thumb = p => p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '<i data-lucide="image-off" aria-hidden="true"></i>';
  const card = p => `<article class="photo-card"><div class="photo-thumb">${thumb(p)}</div><div><h3>${esc(p.name)}</h3><div class="meta">${p.sku ? `<span>${esc(p.sku)}</span>` : ''}${p.visible ? '' : '<span class="badge">Oculto</span>'}${p.autoTried && !p.image ? '<span class="badge warn">Sin coincidencia automática</span>' : ''}</div></div><div class="photo-card-actions">${p.fromWeb && p.image ? `<button class="button small primary" type="button" data-ok="${esc(p.id)}"><i data-lucide="check" aria-hidden="true"></i> Está bien</button>` : ''}<button class="button small${p.image ? '' : ' primary'}" type="button" data-pick="${esc(p.id)}"><i data-lucide="image-plus" aria-hidden="true"></i> ${p.image ? 'Cambiar foto' : 'Elegir foto'}</button></div></article>`;

  const load = async () => {
    const seq = ++loadSeq;
    try {
      const data = await api(`admin-photos?${new URLSearchParams({ status: st.status, q: st.q, page: st.page, limit: 40 })}`);
      if (seq !== loadSeq) return;
      webSearch = !!data.webSearch; products = data.products || []; st.totalPages = data.totalPages || 1; st.total = data.total || 0;
      const c = data.counts || {};
      $('#stats').innerHTML = STATS.map(([key, icon, label]) => `<button type="button" class="stat ${key === st.status ? 'is-active' : ''} ${(key === 'rotas' || key === 'sin-foto') && c[key] ? 'is-alert' : ''}" data-status="${key}" aria-pressed="${key === st.status}"><span><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong>${c[key] || 0}</strong></button>`).join('');
      $('#count').textContent = `${data.total || 0} producto${data.total === 1 ? '' : 's'}`;
      $('#pageInfo').textContent = `Página ${data.page || 1} de ${st.totalPages}`;
      $('#prev').disabled = st.page <= 1; $('#next').disabled = st.page >= st.totalPages;
      $('#grid').innerHTML = products.map(card).join('');
      $('#approvePage').hidden = st.status !== 'web' || !products.length;
      $('#quickMode').hidden = !['rotas', 'sin-foto'].includes(st.status) || !products.length;
      $('#webFix').disabled = running || !webSearch; $('#webHint').hidden = webSearch;
      $('#empty').hidden = products.length !== 0;
      if (!products.length) $('#empty').innerHTML = st.q ? stateHtml('search', 'Ningún producto coincide', 'Prueba con otra búsqueda.') : stateHtml('empty', st.status === 'propias' ? 'Todavía no hay fotos en tu servidor' : 'Nada pendiente aquí', st.status === 'por-revisar' ? 'Todas las fotos del proveedor ya fueron revisadas.' : '', 'circle-check');
      icons();
    } catch (e) {
      if (seq !== loadSeq) return;
      $('#grid').innerHTML = ''; $('#empty').hidden = false; $('#empty').innerHTML = stateHtml('error', 'No se pudo cargar la lista', e.message); icons();
    }
  };

  const setRunning = on => { running = on; $('#runBox').hidden = !on && !$('#runStatus').textContent; $('#stop').hidden = !on; ['checkAll', 'autoFix', 'autoRetry'].forEach(id => { $('#' + id).disabled = on; }); $('#webFix').disabled = on || !webSearch; };
  const say = text => { $('#runStatus').textContent = text; $('#runBox').hidden = false; };

  const addFixed = items => {
    if (!items.length) return;
    $('#fixedBox').hidden = false;
    items.forEach(f => $('#fixedList').querySelectorAll('[data-pick]').forEach(el => { if (el.dataset.pick === String(f.id)) el.remove(); }));
    $('#fixedList').insertAdjacentHTML('afterbegin', items.map(f => `<button type="button" class="photo-fixed-item" data-pick="${esc(f.id)}" data-name="${esc(f.name)}" title="Cambiar la foto de ${esc(f.name)}"><img src="${esc(f.url)}" alt="" loading="lazy"><span>${esc(f.name)}</span><small>${esc(SOURCE[f.source] || f.source)}</small></button>`).join(''));
  };

  $('#checkAll').addEventListener('click', async () => {
    setRunning(true);
    let saved = 0, broken = 0;
    try {
      while (running) {
        const res = await post('admin-archive-images', { limit: 15 });
        saved += res.saved || 0; broken += (res.failed || []).length;
        say(`Guardadas en tu servidor: ${saved} · rotas: ${broken} · faltan revisar: ${res.pending}`);
        if (!res.pending || (!res.saved && !(res.failed || []).length)) break;
        await load();
      }
    } catch (e) { toast(e.message, true); }
    setRunning(false); load();
  });

  const autoFix = async (retry, web = false) => {
    setRunning(true);
    let fixed = 0, checked = 0, first = true;
    try {
      while (running) {
        const res = await post('admin-photo-auto', { limit: web ? 6 : 3, retry: first && retry, web });
        first = false; fixed += (res.fixed || []).length; checked += res.checked || 0;
        addFixed(res.fixed || []);
        say(`Reparadas: ${fixed} de ${checked} revisadas · faltan ${res.remaining}`);
        if (!res.remaining || !res.checked) break;
        await load();
      }
      if (!web) $('#autoRetry').hidden = false;
      if (web && fixed) toast('Revisa las fotos puestas desde la web en «Desde la web: revisar».');
    } catch (e) { toast(e.message, true); }
    setRunning(false); load();
  };
  $('#autoFix').addEventListener('click', () => autoFix(false));
  $('#autoRetry').addEventListener('click', () => autoFix(true));
  $('#webFix').addEventListener('click', () => autoFix(false, true));
  const approve = async ids => {
    if (!ids.length) return;
    try { await post('admin-photo-ok', { ids }); toast(ids.length === 1 ? 'Foto confirmada.' : `${ids.length} fotos confirmadas.`); load(); }
    catch (e) { toast(e.message, true); }
  };
  $('#approvePage').addEventListener('click', () => approve(products.filter(p => p.fromWeb && p.image).map(p => p.id)));
  $('#stop').addEventListener('click', () => { running = false; say('Pausando al terminar la tanda en curso…'); });

  const uploadFile = async file => { const fd = new FormData(); fd.append('image', file); return (await api('upload-image', { method: 'POST', body: fd })).url; };

  // Subida en lote: el nombre de cada archivo es el SKU del producto.
  const bulkUpload = async files => {
    files = [...files].filter(f => /^image\/(png|jpeg|webp)$/.test(f.type));
    if (!files.length || running) return;
    setRunning(true);
    const status = $('#bulkStatus'), miss = $('#bulkMiss');
    miss.hidden = true; miss.innerHTML = '';
    let done = 0, failed = [];
    try {
      const { matches = [] } = await post('admin-photo-match', { names: files.map(f => f.name) });
      const byName = new Map(matches.map(m => [m.name, m]));
      const todo = files.filter(f => (byName.get(f.name)?.ids || []).length);
      failed = files.filter(f => !todo.includes(f)).map(f => `${f.name}: ningún producto tiene ese SKU`);
      for (const file of todo) {
        if (!running) break;
        const m = byName.get(file.name);
        status.textContent = `Subiendo ${done + 1} de ${todo.length}: ${m.product}`;
        try {
          if (file.size > 8 * 1024 * 1024) throw new Error('pesa más de 8 MB');
          const url = await uploadFile(file);
          for (const id of m.ids) { const res = await post('admin-photo-replace', { id, url }); addFixed([{ id, name: m.product, source: 'lote', url: res.url }]); }
          done++;
        } catch (e) { failed.push(`${file.name}: ${e.message}`); }
      }
      status.textContent = `Fotos asignadas: ${done} de ${files.length}.`;
      if (failed.length) { miss.hidden = false; miss.innerHTML = failed.map(t => `<li>${esc(t)}</li>`).join(''); }
    } catch (e) { toast(e.message, true); }
    setRunning(false); $('#bulkFiles').value = ''; load();
  };
  $('#bulkFiles').addEventListener('change', e => bulkUpload(e.target.files));
  const drop = $('#bulkDrop');
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', e => bulkUpload(e.dataTransfer.files));

  const googleUrl = p => 'https://www.google.com/search?tbm=isch&q=' + encodeURIComponent(p.query || p.name);
  // Lo pegado puede ser la imagen misma (clic derecho → «Copiar imagen») o la miniatura de Google en data: URL.
  const pastedBlob = (clip, text) => {
    const file = [...(clip?.files || [])].find(f => f.type.startsWith('image/'));
    if (file || !/^data:image\/[\w+.-]+;base64,/i.test(text)) return file || null;
    return new Blob([Uint8Array.from(atob(text.slice(text.indexOf(',') + 1)), ch => ch.charCodeAt(0))], { type: text.slice(5, text.indexOf(';')) });
  };
  // Se achica en el navegador: sube más rápido y no choca con el límite de 8 MB.
  const shrink = async blob => {
    const bmp = await createImageBitmap(blob).catch(() => null);
    if (!bmp) throw new Error('Lo pegado no es una imagen válida.');
    const side = Math.max(bmp.width, bmp.height);
    if (side < 200) throw new Error('La imagen es muy pequeña (es la miniatura). Ábrela en grande y cópiala otra vez.');
    const scale = Math.min(1, 1600 / side);
    if (scale === 1 && blob.size < 1.5e6 && /^image\/(png|jpeg|webp)$/.test(blob.type)) return new File([blob], 'foto.' + blob.type.slice(6), { type: blob.type });
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale); canvas.height = Math.round(bmp.height * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    const out = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', 0.9));
    return new File([out], 'foto.' + out.type.slice(6), { type: out.type });
  };

  // Modo rápido: lo pegado se guarda en segundo plano y se pasa al siguiente sin esperar al servidor.
  $('#quickMode').addEventListener('click', () => {
    let queue = products.filter(p => !p.image), current = null, saved = 0, working = false, autoOpen = SmartAdmin.remembered('fotos.autoGoogle', true);
    const total = st.total, skipped = new Set(), sent = new Set(), jobs = [], failed = [];
    const onPaste = e => { if (!current || e.target.id === 'qFile') return; e.preventDefault(); accept(e.clipboardData, (e.clipboardData?.getData('text') || '').trim(), true); };
    const m = modal({ title: 'Modo rápido', subtitle: 'En Google: clic derecho sobre la foto → «Copiar imagen». Aquí: Ctrl+V. Se guarda sola y pasa al siguiente.', body: '<div class="quick-status" id="qStatus" role="status" aria-live="polite"></div><div id="qCard"></div><div class="quick-failed" id="qFailed" hidden></div>', onClose: () => { document.removeEventListener('paste', onPaste); load(); } });
    const $q = sel => m.body.querySelector(sel);
    const paint = () => {
      if (!m.body.isConnected) return;
      const left = ` · quedan ${Math.max(0, total - sent.size - skipped.size)}`;
      $q('#qStatus').classList.toggle('is-busy', jobs.length > 0);
      $q('#qStatus').innerHTML = jobs.length ? `<i data-lucide="refresh-cw" aria-hidden="true"></i> Guardando en tu servidor (${jobs.length})… puedes seguir con el siguiente${left}` : `<i data-lucide="circle-check" aria-hidden="true"></i> Guardadas: ${saved}${failed.length ? ` · con error: ${failed.length}` : ''}${left}`;
      $q('#qFailed').hidden = !failed.length;
      $q('#qFailed').innerHTML = failed.length ? `<p><i data-lucide="triangle-alert" aria-hidden="true"></i> No se pudieron guardar:</p><ul>${failed.map((f, i) => `<li><span>${esc(f.p.name)} — ${esc(f.error)}</span><button class="button small" type="button" data-retry="${i}">Elegir otra</button></li>`).join('')}</ul>` : '';
      icons();
    };
    const work = async () => {
      if (working) return;
      working = true;
      while (jobs.length) {
        paint();
        const job = jobs[0];
        try {
          const url = job.file ? await uploadFile(job.file) : job.url;
          const res = await post('admin-photo-replace', { id: job.p.id, url });
          saved++; addFixed([{ id: job.p.id, name: job.p.name, source: 'manual', url: res.url }]);
        } catch (e) {
          sent.delete(job.p.id); failed.push({ p: job.p, error: e.message });
          if (!m.body.isConnected) toast(`${job.p.name}: ${e.message}`, true);
        }
        jobs.shift();
      }
      working = false; paint();
      if (!m.body.isConnected) load();
    };
    const refill = async () => {
      for (let page = 1, pages = 1; page <= pages && !queue.length; page++) {
        const data = await api(`admin-photos?${new URLSearchParams({ status: st.status, q: st.q, page, limit: 100 })}`);
        pages = data.totalPages || 1;
        queue = (data.products || []).filter(p => !p.image && !skipped.has(p.id) && !sent.has(p.id) && !failed.some(f => f.p.id === p.id));
      }
    };
    const show = async (p, openSearch) => {
      if (!p) { if (!queue.length) await refill().catch(() => { queue = []; }); p = queue.shift(); }
      current = p || null;
      paint();
      if (!current) { $q('#qCard').innerHTML = stateHtml('empty', 'No quedan productos en esta lista', '', 'circle-check'); icons(); return; }
      if (openSearch && autoOpen) window.open(googleUrl(current), '_blank', 'noopener');
      $q('#qCard').innerHTML = `<div class="quick-head"><h3>${esc(current.name)}</h3><p>${esc(current.sku || '')}${current.visible ? '' : ' · oculto'}</p></div><a class="button primary" id="qGoogle" href="${esc(googleUrl(current))}" target="_blank" rel="noopener"><i data-lucide="external-link" aria-hidden="true"></i> Buscar en Google Imágenes</a><label class="quick-auto"><input type="checkbox" id="qAuto"${autoOpen ? ' checked' : ''}> Al pegar, abrir sola la búsqueda del siguiente</label><div class="pick-extra"><label for="qUrl">Pega aquí la foto con Ctrl+V (también sirve su dirección)</label><div class="pick-row"><input class="input" id="qUrl" placeholder="Ctrl+V" autocomplete="off"><button class="button" type="button" id="qUse">Guardar</button></div><label for="qFile">O sube el archivo</label><input id="qFile" type="file" accept="image/png,image/jpeg,image/webp"><p class="quick-msg" id="qMsg" role="alert"></p></div><div class="quick-actions"><button class="button" type="button" id="qSkip">Saltar</button></div>`;
      icons();
      $q('#qUrl').focus();
    };
    const accept = async (clip, text, fromPaste) => {
      const p = current, msg = $q('#qMsg');
      msg.textContent = '';
      let job;
      try {
        const blob = pastedBlob(clip, text);
        if (blob) job = { p, file: await shrink(blob) };
        else if (/^https?:\/\/\S+$/i.test(text)) job = { p, url: text };
        else throw new Error(text ? 'Eso no es una imagen ni la dirección de una imagen.' : 'No hay nada que guardar: en Google, clic derecho sobre la foto → «Copiar imagen», y aquí Ctrl+V.');
      } catch (e) { msg.textContent = e.message; $q('#qUrl').value = ''; return; }
      if (p !== current) return;
      sent.add(p.id); jobs.push(job); work();
      show(null, fromPaste);
    };
    m.body.addEventListener('click', e => {
      const retry = e.target.closest('[data-retry]');
      if (retry) { const [f] = failed.splice(Number(retry.dataset.retry), 1); if (current) queue.unshift(current); show(f.p); return; }
      if (!current) return;
      if (e.target.closest('#qSkip')) { skipped.add(current.id); show(); }
      else if (e.target.closest('#qUse')) accept(null, $q('#qUrl').value.trim(), false);
    });
    m.body.addEventListener('change', e => {
      if (e.target.id === 'qAuto') { autoOpen = e.target.checked; SmartAdmin.remember('fotos.autoGoogle', autoOpen); }
      else if (e.target.id === 'qFile' && current && e.target.files.length) accept({ files: e.target.files }, '', false);
    });
    m.body.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.id === 'qUrl') { e.preventDefault(); accept(null, e.target.value.trim(), false); } });
    document.addEventListener('paste', onPaste);
    show();
  });

  const openPicker = (id, name, query) => {
    let busy = false;
    const google = 'https://www.google.com/search?tbm=isch&q=' + encodeURIComponent(query || name);
    const m = modal({ title: 'Elegir foto', subtitle: name, body: `<form class="pick-search" id="pickForm"><label class="sr-only" for="pickQ">Buscar</label><input class="input" id="pickQ" value="${esc(query || name)}"><button class="button" type="submit"><i data-lucide="search" aria-hidden="true"></i> Buscar</button></form><div class="cand-grid" id="pickGrid"></div><div class="pick-extra"><a class="button small" href="${esc(google)}" target="_blank" rel="noopener"><i data-lucide="external-link" aria-hidden="true"></i> Buscar en Google Imágenes</a><p class="pick-note">Abre la foto que corresponda, clic derecho → «Copiar imagen» y pégala aquí con Ctrl+V (o «Copiar dirección de imagen» y pégala abajo).</p><label for="pickUrl">Pegar la dirección de una imagen</label><div class="pick-row"><input class="input" id="pickUrl" type="url" placeholder="https://…/foto.jpg"><button class="button" type="button" id="pickUseUrl">Usar</button></div><label for="pickFile">O subir un archivo desde tu equipo</label><input id="pickFile" type="file" accept="image/png,image/jpeg,image/webp"><p class="pick-note">${webSearch ? 'Se busca en el fabricante (Icecat), en el Internet Archive y en la web.' : 'Se busca en el fabricante (Icecat) y en el Internet Archive. Para buscar también en toda la web, agrega SMARTISP_BRAVE_SEARCH_KEY al .env del servidor.'}</p></div><p class="quick-status is-busy pick-busy" id="pickBusy" role="status" hidden><i data-lucide="refresh-cw" aria-hidden="true"></i> Guardando en tu servidor…</p>` });
    const grid = m.body.querySelector('#pickGrid');
    const lock = on => { busy = on; m.body.querySelectorAll('button,input').forEach(el => { el.disabled = on; }); m.body.querySelector('#pickBusy').hidden = !on; };
    const search = async q => {
      grid.innerHTML = '<p class="pick-note">Buscando fotos…</p>';
      try {
        const res = await api(`admin-photo-candidates?${new URLSearchParams(q ? { id, q } : { id })}`);
        const list = res.candidates || [];
        grid.innerHTML = list.length ? list.map((c, i) => `<button type="button" class="cand" data-cand="${i}" aria-label="Usar esta foto (${esc(SOURCE[c.source] || c.source)})"><img src="${esc(c.thumb || c.url)}" alt="" loading="lazy" referrerpolicy="no-referrer"><small>${esc(SOURCE[c.source] || c.source)}${c.label ? ' · ' + esc(c.label) : ''}</small></button>`).join('') : '<p class="pick-note">No se encontraron fotos. Prueba escribiendo marca y modelo (por ejemplo «Logitech M170»), pega una dirección o sube el archivo.</p>';
        grid.querySelectorAll('img').forEach(img => img.addEventListener('error', () => img.closest('.cand')?.remove()));
        grid._list = list;
      } catch (e) { grid.innerHTML = `<p class="pick-note">${esc(e.message)}</p>`; }
    };
    const choose = async url => {
      if (busy || !url) return;
      lock(true);
      try {
        const res = await post('admin-photo-replace', { id, url });
        toast('Foto guardada en tu servidor.');
        addFixed([{ id, name, source: 'manual', url: res.url }]);
        m.close(true); load();
      } catch (e) { toast(e.message, true); lock(false); }
    };
    m.body.querySelector('#pickForm').addEventListener('submit', e => { e.preventDefault(); search(m.body.querySelector('#pickQ').value.trim()); });
    grid.addEventListener('click', e => { const b = e.target.closest('[data-cand]'); if (b) choose((grid._list || [])[Number(b.dataset.cand)]?.url); });
    m.body.querySelector('#pickUseUrl').addEventListener('click', () => choose(m.body.querySelector('#pickUrl').value.trim()));
    m.body.querySelector('#pickFile').addEventListener('change', async e => {
      const file = e.target.files[0];
      if (!file || busy) return;
      const fd = new FormData(); fd.append('image', file);
      lock(true);
      try { const up = await api('upload-image', { method: 'POST', body: fd }); lock(false); choose(up.url); }
      catch (err) { toast(err.message, true); lock(false); }
    });
    m.body.addEventListener('paste', async e => {
      let blob;
      try { blob = pastedBlob(e.clipboardData, (e.clipboardData?.getData('text') || '').trim()); } catch { return; }
      if (!blob || busy) return;
      e.preventDefault();
      lock(true);
      try { const url = await uploadFile(await shrink(blob)); lock(false); choose(url); }
      catch (err) { toast(err.message, true); lock(false); }
    });
    search('');
  };

  document.addEventListener('click', e => {
    const ok = e.target.closest('[data-ok]');
    if (ok) { approve([ok.dataset.ok]); return; }
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    const p = products.find(x => x.id === b.dataset.pick);
    openPicker(b.dataset.pick, p ? p.name : (b.dataset.name || ''), p ? p.query : '');
  });
  $('#stats').addEventListener('click', e => { const b = e.target.closest('[data-status]'); if (b && b.dataset.status !== st.status) { st.status = b.dataset.status; st.page = 1; load(); } });
  let debounce; $('#search').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); });
  $('#refresh').addEventListener('click', load);
  $('#prev').addEventListener('click', () => { if (st.page > 1) { st.page--; load(); } });
  $('#next').addEventListener('click', () => { if (st.page < st.totalPages) { st.page++; load(); } });
  await load();
}));
