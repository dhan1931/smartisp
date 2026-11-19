document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, post, toast, icons, state: stateHtml, modal, confirm, relTime } = SmartAdmin;
  const STATS = [['pending', 'hourglass', 'Por revisar'], ['published', 'circle-check', 'Publicadas'], ['hidden', 'eye-off', 'Ocultas']];
  const params = new URLSearchParams(location.search);
  const st = { status: STATS.some(s => s[0] === params.get('status')) ? params.get('status') : 'pending', q: '', page: 1, totalPages: 1 };
  let reviews = [], loadSeq = 0;
  const stars = n => `${'★'.repeat(n)}<span class="is-off">${'★'.repeat(5 - n)}</span>`;
  const card = r => `<article class="review-card" data-id="${r.id}"><header><span class="review-stars" aria-label="${r.rating} de 5 estrellas">${stars(r.rating)}</span>${r.title ? `<strong>${esc(r.title)}</strong>` : ''}${r.verified ? '<span class="badge ok">Compra verificada</span>' : '<span class="badge">Sin compra registrada</span>'}</header><p class="review-text">${esc(r.body)}</p><div class="review-info"><span><i data-lucide="user" aria-hidden="true"></i> ${esc(r.author)}${r.email ? ` · ${esc(r.email)}` : ''}</span><span title="${esc(r.date)}">${esc(relTime(r.updated || r.date))}</span>${r.productName ? `<a href="${esc(r.productUrl)}#resenas" target="_blank" rel="noopener"><i data-lucide="external-link" aria-hidden="true"></i> ${esc(r.productName)}</a>` : '<span>Producto eliminado</span>'}</div>${r.reply ? `<div class="review-reply"><strong>Tu respuesta</strong><p>${esc(r.reply)}</p></div>` : ''}<div class="review-actions">${r.status !== 'published' ? '<button class="button small primary" type="button" data-op="publish"><i data-lucide="check" aria-hidden="true"></i> Publicar</button>' : ''}${r.status !== 'hidden' ? '<button class="button small" type="button" data-op="hide"><i data-lucide="eye-off" aria-hidden="true"></i> Ocultar</button>' : ''}<button class="button small" type="button" data-op="reply"><i data-lucide="pencil" aria-hidden="true"></i> ${r.reply ? 'Editar respuesta' : 'Responder'}</button><button class="button small review-delete" type="button" data-op="delete"><i data-lucide="trash-2" aria-hidden="true"></i> Eliminar</button></div></article>`;

  const load = async () => {
    const seq = ++loadSeq;
    try {
      const data = await api(`admin-reviews?${new URLSearchParams({ status: st.status, q: st.q, page: st.page })}`);
      if (seq !== loadSeq) return;
      reviews = data.reviews || []; st.totalPages = data.totalPages || 1;
      const c = data.counts || {};
      $('#stats').innerHTML = STATS.map(([key, icon, label]) => `<button type="button" class="stat ${key === st.status ? 'is-active' : ''} ${key === 'pending' && c[key] ? 'is-alert' : ''}" data-status="${key}" aria-pressed="${key === st.status}"><span><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong>${c[key] || 0}</strong></button>`).join('');
      $('#count').textContent = `${data.total || 0} reseña${data.total === 1 ? '' : 's'}`;
      $('#pageInfo').textContent = `Página ${data.page || 1} de ${st.totalPages}`;
      $('#prev').disabled = st.page <= 1; $('#next').disabled = st.page >= st.totalPages;
      $('#list').innerHTML = reviews.map(card).join('');
      $('#empty').hidden = reviews.length !== 0;
      if (!reviews.length) $('#empty').innerHTML = st.q ? stateHtml('search', 'Ninguna reseña coincide', 'Prueba con otra búsqueda.') : stateHtml('empty', st.status === 'pending' ? 'No hay reseñas por revisar' : 'Todavía no hay reseñas aquí', '', 'circle-check');
      icons();
    } catch (e) {
      if (seq !== loadSeq) return;
      $('#list').innerHTML = ''; $('#empty').hidden = false; $('#empty').innerHTML = stateHtml('error', 'No se pudieron cargar las reseñas', e.message); icons();
    }
  };

  const update = async (id, op, extra = {}) => {
    try {
      await post('admin-review-update', { id, op, ...extra });
      toast({ publish: 'Reseña publicada.', hide: 'Reseña ocultada: ya no se ve en la tienda.', reply: 'Respuesta guardada.', delete: 'Reseña eliminada.' }[op]);
      load();
    } catch (e) { toast(e.message, true); }
  };
  const openReply = r => {
    const body = document.createElement('form');
    body.className = 'review-reply-form';
    body.innerHTML = `<p class="review-reply-quote">«${esc(r.body.length > 220 ? r.body.slice(0, 220) + '…' : r.body)}»</p><label for="replyText">Respuesta pública de SmartISP</label><textarea class="input" id="replyText" rows="5" maxlength="1000" placeholder="Gracias por tu opinión…">${esc(r.reply || '')}</textarea><small>Se muestra debajo de la reseña en la ficha del producto. Déjala vacía para quitarla.</small>`;
    const m = modal({ title: 'Responder reseña', subtitle: r.productName || '', body, actions: [{ label: 'Cancelar', value: false }, { label: 'Guardar respuesta', kind: 'primary', onClick: () => { update(r.id, 'reply', { reply: body.querySelector('#replyText').value.trim() }); m.close(true); return false; } }] });
    body.addEventListener('submit', e => e.preventDefault());
    setTimeout(() => body.querySelector('#replyText').focus(), 0);
  };

  $('#list').addEventListener('click', async e => {
    const b = e.target.closest('[data-op]');
    if (!b) return;
    const r = reviews.find(x => String(x.id) === b.closest('[data-id]').dataset.id);
    if (!r) return;
    const op = b.dataset.op;
    if (op === 'reply') { openReply(r); return; }
    if (op === 'delete' && !(await confirm('Eliminar reseña', `Se borra la reseña de ${r.author} y deja de contar en el promedio. Si solo quieres que no se vea, usa «Ocultar».`, 'Eliminar'))) return;
    update(r.id, op);
  });
  $('#stats').addEventListener('click', e => { const b = e.target.closest('[data-status]'); if (b && b.dataset.status !== st.status) { st.status = b.dataset.status; st.page = 1; load(); } });
  let debounce; $('#search').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); });
  $('#refresh').addEventListener('click', load);
  $('#prev').addEventListener('click', () => { if (st.page > 1) { st.page--; load(); } });
  $('#next').addEventListener('click', () => { if (st.page < st.totalPages) { st.page++; load(); } });
  await load();
}));
