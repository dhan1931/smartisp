document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, post, toast, icons, state: stateHtml, modal } = SmartAdmin;
  const STATS = [['rotas', 'image-off', 'Rotas'], ['sin-foto', 'image-minus', 'Sin foto'], ['por-revisar', 'hourglass', 'Por revisar'], ['propias', 'hard-drive', 'En tu servidor']];
  const SOURCE = { icecat: 'Fabricante', archivo: 'Internet Archive', web: 'Web', manual: 'Elegida a mano' };
  const params = new URLSearchParams(location.search);
  const st = { status: STATS.some(s => s[0] === params.get('status')) ? params.get('status') : 'rotas', q: '', page: 1, totalPages: 1 };
  let webSearch = false, loadSeq = 0, running = false, products = [];

  const thumb = p => p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '<i data-lucide="image-off" aria-hidden="true"></i>';
  const card = p => `<article class="photo-card"><div class="photo-thumb">${thumb(p)}</div><div><h3>${esc(p.name)}</h3><div class="meta">${p.sku ? `<span>${esc(p.sku)}</span>` : ''}${p.visible ? '' : '<span class="badge">Oculto</span>'}${p.autoTried && !p.image ? '<span class="badge warn">Sin coincidencia automática</span>' : ''}</div></div><button class="button small${p.image ? '' : ' primary'}" type="button" data-pick="${esc(p.id)}"><i data-lucide="image-plus" aria-hidden="true"></i> ${p.image ? 'Cambiar foto' : 'Elegir foto'}</button></article>`;

  const load = async () => {
    const seq = ++loadSeq;
    try {
      const data = await api(`admin-photos?${new URLSearchParams({ status: st.status, q: st.q, page: st.page, limit: 40 })}`);
      if (seq !== loadSeq) return;
      webSearch = !!data.webSearch; products = data.products || []; st.totalPages = data.totalPages || 1;
      const c = data.counts || {};
      $('#stats').innerHTML = STATS.map(([key, icon, label]) => `<button type="button" class="stat ${key === st.status ? 'is-active' : ''} ${key !== 'propias' && key !== 'por-revisar' && c[key] ? 'is-alert' : ''}" data-status="${key}" aria-pressed="${key === st.status}"><span><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong>${c[key] || 0}</strong></button>`).join('');
      $('#count').textContent = `${data.total || 0} producto${data.total === 1 ? '' : 's'}`;
      $('#pageInfo').textContent = `Página ${data.page || 1} de ${st.totalPages}`;
      $('#prev').disabled = st.page <= 1; $('#next').disabled = st.page >= st.totalPages;
      $('#grid').innerHTML = products.map(card).join('');
      $('#empty').hidden = products.length !== 0;
      if (!products.length) $('#empty').innerHTML = st.q ? stateHtml('search', 'Ningún producto coincide', 'Prueba con otra búsqueda.') : stateHtml('empty', st.status === 'propias' ? 'Todavía no hay fotos en tu servidor' : 'Nada pendiente aquí', st.status === 'por-revisar' ? 'Todas las fotos del proveedor ya fueron revisadas.' : '', 'circle-check');
      icons();
    } catch (e) {
      if (seq !== loadSeq) return;
      $('#grid').innerHTML = ''; $('#empty').hidden = false; $('#empty').innerHTML = stateHtml('error', 'No se pudo cargar la lista', e.message); icons();
    }
  };

  const setRunning = on => { running = on; $('#runBox').hidden = !on && !$('#runStatus').textContent; $('#stop').hidden = !on; ['checkAll', 'autoFix', 'autoRetry'].forEach(id => { $('#' + id).disabled = on; }); };
  const say = text => { $('#runStatus').textContent = text; $('#runBox').hidden = false; };

  const addFixed = items => {
    if (!items.length) return;
    $('#fixedBox').hidden = false;
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
      }
    } catch (e) { toast(e.message, true); }
    setRunning(false); load();
  });

  const autoFix = async retry => {
    setRunning(true);
    let fixed = 0, checked = 0, first = true;
    try {
      while (running) {
        const res = await post('admin-photo-auto', { limit: 3, retry: first && retry });
        first = false; fixed += (res.fixed || []).length; checked += res.checked || 0;
        addFixed(res.fixed || []);
        say(`Reparadas: ${fixed} de ${checked} revisadas · faltan ${res.remaining}`);
        if (!res.remaining || !res.checked) break;
        load();
      }
      $('#autoRetry').hidden = false;
    } catch (e) { toast(e.message, true); }
    setRunning(false); load();
  };
  $('#autoFix').addEventListener('click', () => autoFix(false));
  $('#autoRetry').addEventListener('click', () => autoFix(true));
  $('#stop').addEventListener('click', () => { running = false; say('Pausando al terminar la tanda en curso…'); });

  const openPicker = (id, name) => {
    let busy = false;
    const m = modal({ title: 'Elegir foto', subtitle: name, body: `<form class="pick-search" id="pickForm"><label class="sr-only" for="pickQ">Buscar</label><input class="input" id="pickQ" value="${esc(name)}"><button class="button" type="submit"><i data-lucide="search" aria-hidden="true"></i> Buscar</button></form><div class="cand-grid" id="pickGrid"></div><div class="pick-extra"><label for="pickUrl">Pegar la dirección de una imagen</label><div class="pick-row"><input class="input" id="pickUrl" type="url" placeholder="https://…/foto.jpg"><button class="button" type="button" id="pickUseUrl">Usar</button></div><label for="pickFile">O subir un archivo desde tu equipo</label><input id="pickFile" type="file" accept="image/png,image/jpeg,image/webp"><p class="pick-note">${webSearch ? 'Se busca en el fabricante (Icecat), en el Internet Archive y en la web.' : 'Se busca en el fabricante (Icecat) y en el Internet Archive. Para buscar también en toda la web, agrega SMARTISP_BRAVE_SEARCH_KEY al .env del servidor.'}</p></div>` });
    const grid = m.body.querySelector('#pickGrid');
    const lock = on => { busy = on; m.body.querySelectorAll('button,input').forEach(el => { el.disabled = on; }); };
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
    search('');
  };

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    const p = products.find(x => x.id === b.dataset.pick);
    openPicker(b.dataset.pick, p ? p.name : (b.dataset.name || ''));
  });
  $('#stats').addEventListener('click', e => { const b = e.target.closest('[data-status]'); if (b && b.dataset.status !== st.status) { st.status = b.dataset.status; st.page = 1; load(); } });
  let debounce; $('#search').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); });
  $('#refresh').addEventListener('click', load);
  $('#prev').addEventListener('click', () => { if (st.page > 1) { st.page--; load(); } });
  $('#next').addEventListener('click', () => { if (st.page < st.totalPages) { st.page++; load(); } });
  await load();
}));
