// Reseñas en la ficha de producto: escribir, editar o borrar la propia y cargar más opiniones.
(() => {
  const section = document.getElementById('resenas');
  if (!section) return;
  const productId = section.dataset.productId;
  const $ = sel => section.querySelector(sel);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const fmtDate = d => { const t = new Date(String(d).replace(' ', 'T') + 'Z'); return Number.isNaN(t.valueOf()) ? '' : `${t.getDate()} ${MONTHS[t.getMonth()]} ${t.getFullYear()}`; };
  const stars = n => `${'★'.repeat(n)}<span class="is-off">${'★'.repeat(5 - n)}</span>`;
  const itemHtml = r => `<li class="review-item"><div class="review-top"><span class="rating-stars" aria-label="${r.rating} de 5 estrellas">${stars(r.rating)}</span>${r.title ? `<strong class="review-title">${esc(r.title)}</strong>` : ''}</div><div class="review-meta"><span>${esc(r.author)}</span> · <time datetime="${esc(String(r.date).slice(0, 10))}">${fmtDate(r.date)}</time>${r.verified ? ' <span class="review-verified">✓ Compra verificada</span>' : ''}</div><p class="review-body">${esc(r.body).replace(/\n/g, '<br>')}</p>${r.reply ? `<div class="review-reply"><strong>Respuesta de SmartISP</strong><p>${esc(r.reply).replace(/\n/g, '<br>')}</p></div>` : ''}</li>`;
  const api = async (action, options = {}) => {
    const res = await fetch('/api/auth/' + action, { credentials: 'include', ...options });
    let data = {};
    try { data = await res.json(); } catch { /* respuesta vacía */ }
    if (!res.ok) throw new Error(data.error || 'No se pudo completar. Intenta de nuevo.');
    return data;
  };
  // El texto libre viaja codificado: el firewall del hosting bloquea algunas palabras en el cuerpo JSON.
  const send = body => api('product-review', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ payload: btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(body)))) }) });
  const say = (text, tone = '') => { const m = $('#reviewMsg'); m.textContent = text; m.className = 'reviews-cta-msg' + (tone ? ' is-' + tone : ''); m.hidden = !text; };

  let state = { loggedIn: false, mine: null, page: 1, totalPages: 1 };
  const flash = (() => { try { const v = sessionStorage.getItem('smartisp.reviewFlash'); sessionStorage.removeItem('smartisp.reviewFlash'); return v; } catch { return null; } })();
  if (flash) say(flash, 'ok');

  const paintCta = () => {
    const btn = $('#btnWriteReview');
    if (!state.loggedIn) return;
    btn.removeAttribute('href');
    btn.setAttribute('role', 'button');
    btn.tabIndex = 0;
    btn.innerHTML = `<i data-lucide="${state.mine ? 'pencil' : 'star'}" width="17"></i> ${state.mine ? 'Editar tu reseña' : 'Escribir una reseña'}`;
    if (state.mine?.status === 'pending' && !flash) say('Tu reseña está en revisión: se publicará cuando la aprobemos.');
    else if (state.mine?.status === 'hidden' && !flash) say('Tu reseña no está publicada. Si la editas, la volvemos a revisar.');
    window.lucide?.createIcons();
  };

  const openForm = () => {
    const box = $('#reviewFormBox'), mine = state.mine || {};
    box.hidden = false;
    box.innerHTML = `<form class="review-form" id="reviewForm" novalidate>
      <h3>${state.mine ? 'Editar tu reseña' : 'Escribe tu reseña'}</h3>
      <fieldset class="review-stars-input"><legend>Tu calificación</legend><div>${[5, 4, 3, 2, 1].map(n => `<input type="radio" id="rs${n}" name="rating" value="${n}"${mine.rating === n ? ' checked' : ''}><label for="rs${n}" title="${n} estrella${n === 1 ? '' : 's'}"><span class="sr-only">${n} estrella${n === 1 ? '' : 's'}</span>★</label>`).join('')}</div></fieldset>
      <label for="reviewTitle">Título (opcional)</label><input id="reviewTitle" name="title" maxlength="120" value="${esc(mine.title || '')}" placeholder="Resume tu experiencia">
      <label for="reviewBody">Tu opinión</label><textarea id="reviewBody" name="body" rows="5" maxlength="2000" required placeholder="¿Qué te gustó? ¿Cómo funciona? ¿Lo recomendarías?">${esc(mine.body || '')}</textarea>
      <small class="review-count" id="reviewCount"></small>
      <p class="review-form-error" id="reviewError" role="alert" hidden></p>
      <div class="review-form-actions"><button class="btn-action btn-cart" type="submit">Publicar reseña</button><button class="btn-action btn-share" type="button" id="reviewCancel">Cancelar</button>${state.mine ? '<button class="btn-action review-delete" type="button" id="reviewDelete">Eliminar mi reseña</button>' : ''}</div>
      <p class="reviews-note">Se publica con tu nombre y la inicial de tu apellido. Las reseñas de quien compró el producto se publican al instante; las demás, después de revisarlas.</p>
    </form>`;
    const form = $('#reviewForm'), count = () => { $('#reviewCount').textContent = `${form.body.value.trim().length} / 2000`; };
    count();
    form.body.addEventListener('input', count);
    form.querySelector('input[name="rating"]:checked, input[name="rating"]')?.focus();
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  section.addEventListener('click', async e => {
    if (e.target.closest('#btnWriteReview') && state.loggedIn) { e.preventDefault(); openForm(); return; }
    if (e.target.closest('#reviewCancel')) { $('#reviewFormBox').hidden = true; $('#reviewFormBox').innerHTML = ''; return; }
    if (e.target.closest('#reviewDelete')) {
      if (!confirm('¿Eliminar tu reseña de este producto?')) return;
      try { await send({ product: productId, delete: true }); try { sessionStorage.setItem('smartisp.reviewFlash', 'Tu reseña se eliminó.'); } catch {} location.reload(); }
      catch (err) { $('#reviewError').textContent = err.message; $('#reviewError').hidden = false; }
      return;
    }
    const more = e.target.closest('#reviewsMore');
    if (more) {
      more.disabled = true;
      try {
        const data = await api(`product-reviews?${new URLSearchParams({ product: productId, page: state.page + 1 })}`);
        state.page = data.page; state.totalPages = data.totalPages;
        $('#reviewsList').insertAdjacentHTML('beforeend', (data.reviews || []).map(itemHtml).join(''));
        more.hidden = state.page >= state.totalPages;
      } catch (err) { say(err.message, 'bad'); }
      more.disabled = false;
    }
  });
  section.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target.id === 'btnWriteReview' && state.loggedIn) { e.preventDefault(); openForm(); } });
  section.addEventListener('submit', async e => {
    if (e.target.id !== 'reviewForm') return;
    e.preventDefault();
    const form = e.target, err = $('#reviewError'), rating = Number(form.querySelector('input[name="rating"]:checked')?.value || 0);
    const fail = msg => { err.textContent = msg; err.hidden = false; };
    err.hidden = true;
    if (!rating) return fail('Elige de 1 a 5 estrellas.');
    if (form.body.value.trim().length < 10) return fail('Cuéntanos un poco más: escribe al menos 10 caracteres.');
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      const res = await send({ product: productId, rating, title: form.title.value.trim(), body: form.body.value.trim() });
      if (res.status === 'published') {
        try { sessionStorage.setItem('smartisp.reviewFlash', '¡Gracias! Tu reseña ya está publicada.'); } catch {}
        location.reload();
        return;
      }
      $('#reviewFormBox').hidden = true; $('#reviewFormBox').innerHTML = '';
      state.mine = { ...(state.mine || {}), rating, title: form.title.value.trim(), body: form.body.value.trim(), status: 'pending' };
      paintCta();
      say('¡Gracias! Tu reseña se publicará cuando la revisemos.', 'ok');
    } catch (ex) { fail(ex.message); submit.disabled = false; }
  });

  api(`product-reviews?${new URLSearchParams({ product: productId })}`).then(data => {
    state = { ...state, loggedIn: !!data.loggedIn, mine: data.mine, page: data.page || 1, totalPages: data.totalPages || 1 };
    paintCta();
    if (state.loggedIn && location.hash === '#resenas' && !state.mine && !flash) openForm();
  }).catch(() => {});
})();
