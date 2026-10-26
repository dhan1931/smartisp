/* Actividad (actividad.html): registro de auditoría del equipo (admin_audit_log), en hora de Ecuador. */
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, download, toast, icons, relTime, utc, state: stateHtml, skeletonRows } = SmartAdmin;
  const LABELS = {
    login: 'Inicio de sesión', 'login-failed': 'Acceso fallido', 'login-blocked': 'Acceso con cuenta desactivada', 'login-alert': 'Alerta: accesos fallidos seguidos',
    'change-password': 'Cambio de contraseña', 'change-password-failed': 'Contraseña actual incorrecta', 'reset-password': 'Contraseña restablecida por correo', 'revoke-other-sessions': 'Cerró sus otras sesiones',
    'update-profile': 'Editó su perfil', 'admin-team-create': 'Creó una cuenta de equipo', 'admin-team-role': 'Cambió un rol', 'admin-team-status': 'Activó o desactivó una cuenta', 'admin-team-revoke': 'Cerró las sesiones de una cuenta',
    'admin-products': 'Guardó un producto', 'admin-products-bulk': 'Cambio masivo de productos', 'admin-products-clear': 'Vació el catálogo', 'save-products-batch': 'Guardó productos en lote', 'import-products': 'Importó productos', 'import-excel': 'Importó un Excel', 'import-catalog': 'Importó el catálogo',
    'upload-image': 'Subió una imagen', 'upload-merch-image': 'Subió una imagen', 'upload-campaign-image': 'Subió una imagen de campaña', 'product-images': 'Galería de producto', 'product-image': 'Imagen de producto',
    categories: 'Categorías', 'categories-reassign': 'Reasignó categorías', 'categories-reset': 'Restableció categorías', 'macro-groups': 'Familias de categorías', brands: 'Marcas', 'brand-image': 'Imagen de marca',
    'admin-order-status': 'Cambió el estado de un pedido', 'admin-order-delete': 'Eliminó un pedido', 'admin-order': 'Editó un pedido', 'admin-service-quote-status': 'Cotización de servicio',
    'admin-inventory': 'Inventario', 'admin-store-campaigns': 'Campañas y banners', 'admin-content': 'Contenido y configuración', 'landing-content': 'Página de presentación', 'landing-content-reset': 'Restableció la presentación',
    'admin-insights-goal': 'Meta mensual', 'test-email': 'Correo de prueba'
  };
  const label = a => LABELS[a] || a;
  const params = new URLSearchParams(location.search);
  const isDay = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '');
  const state = { page: 1, limit: 50, totalPages: 1, q: params.get('q') || '', user: params.get('user') || '', act: params.get('act') || '', from: isDay(params.get('from')) ? params.get('from') : '', to: isDay(params.get('to')) ? params.get('to') : '' };
  $('#q').value = state.q; $('#from').value = state.from; $('#to').value = state.to;

  const ec = v => { const d = utc(v); return d ? d.toLocaleString('es-EC', { timeZone: 'America/Guayaquil', dateStyle: 'medium', timeStyle: 'medium' }) : '—'; };
  const browser = ua => { const s = String(ua || ''); const b = /Edg\//.test(s) ? 'Edge' : /OPR\//.test(s) ? 'Opera' : /Chrome\//.test(s) ? 'Chrome' : /Firefox\//.test(s) ? 'Firefox' : /Safari\//.test(s) ? 'Safari' : s ? 'Otro cliente' : ''; const os = /Windows/.test(s) ? 'Windows' : /Android/.test(s) ? 'Android' : /iPhone|iPad/.test(s) ? 'iOS' : /Mac OS/.test(s) ? 'macOS' : /Linux/.test(s) ? 'Linux' : ''; return [b, os].filter(Boolean).join(' en '); };
  const result = s => s == null ? '<span class="muted">—</span>' : s < 300 ? '<span class="badge ok">Correcto</span>' : s < 500 ? `<span class="badge warn" title="Código HTTP ${s}">Rechazado · ${s}</span>` : `<span class="badge bad" title="Código HTTP ${s}">Error · ${s}</span>`;
  const query = extra => { const p = new URLSearchParams(); ['q', 'user', 'act', 'from', 'to'].forEach(k => { if (state[k]) p.set(k, state[k]); }); Object.entries(extra || {}).forEach(([k, v]) => p.set(k, v)); return p.toString(); };
  const syncUrl = () => { const qs = query(); history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '')); };

  const fillSelect = (select, options, current, keep) => {
    const values = new Set(options.map(o => o.value));
    if (current && !values.has(current)) options = options.concat([{ value: current, text: current }]);
    select.innerHTML = keep + options.map(o => `<option value="${esc(o.value)}">${esc(o.text)}</option>`).join('');
    select.value = current;
  };

  const rowHtml = e => {
    const bad = /^login-(failed|blocked|alert)$/.test(e.action) || (e.status && e.status >= 400);
    return `<tr class="${bad ? 'is-bad' : ''}">
      <td class="when" data-label="Fecha"><span>${esc(ec(e.created_at))}</span><small>${esc(relTime(e.created_at))}</small></td>
      <td data-label="Persona">${e.user_email ? `<a href="?user=${encodeURIComponent(e.user_id || e.user_email)}" class="who" data-user="${esc(e.user_id || e.user_email)}">${esc(e.user_email)}</a>` : '<span class="muted">—</span>'}</td>
      <td data-label="Acción"><span class="act-label">${esc(label(e.action))}</span><code class="act-code">${esc((e.method && e.method !== 'POST' ? e.method + ' ' : '') + e.action)}</code></td>
      <td class="detail">${e.target ? `<span class="target">Objetivo: <code>${esc(e.target)}</code></span>` : ''}${e.summary ? `<span class="summary">${esc(e.summary)}</span>` : (e.target ? '' : '<span class="muted">—</span>')}</td>
      <td data-label="Resultado">${result(e.status)}</td>
      <td class="origin" data-label="Origen"><span>${esc(e.ip || '—')}</span><small title="${esc(e.user_agent || '')}">${esc(browser(e.user_agent))}</small></td>
    </tr>`;
  };

  let seq = 0;
  const load = async () => {
    const mine = ++seq;
    $('#tableWrap').hidden = false;
    $('#empty').hidden = true;
    $('#rows').innerHTML = skeletonRows(6, 8);
    syncUrl();
    try {
      const data = await api('admin-audit-log?' + query({ page: state.page, limit: state.limit }));
      if (mine !== seq) return;
      $('#migrationNote').hidden = data.available !== false;
      state.totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.limit || state.limit)));
      fillSelect($('#user'), (data.users || []).map(u => ({ value: u.id, text: u.email || u.id })), state.user, '<option value="">Todas las personas</option>');
      fillSelect($('#act'), (data.actions || []).map(a => ({ value: a, text: label(a) })).sort((a, b) => a.text.localeCompare(b.text, 'es')), state.act, '<option value="">Todas las acciones</option><option value="login-*">Solo accesos al panel</option>');
      $('#count').textContent = `${(data.total || 0).toLocaleString('es-EC')} registro${data.total === 1 ? '' : 's'}`;
      $('#pageInfo').textContent = `Página ${state.page} de ${state.totalPages}`;
      $('#prev').disabled = state.page <= 1;
      $('#next').disabled = state.page >= state.totalPages;
      const entries = data.entries || [];
      $('#rows').innerHTML = entries.map(rowHtml).join('');
      $('#tableWrap').hidden = !entries.length;
      $('#empty').hidden = !!entries.length;
      if (!entries.length) $('#empty').innerHTML = query() ? stateHtml('search', 'No hay actividad que coincida', 'Prueba con otro filtro o un rango de fechas más amplio.') : stateHtml('empty', 'Todavía no hay actividad registrada', 'Aparecerá aquí en cuanto el equipo inicie sesión o haga cambios.', 'history');
      icons();
    } catch (e) {
      if (mine !== seq) return;
      $('#tableWrap').hidden = true;
      $('#empty').hidden = false;
      $('#empty').innerHTML = stateHtml('error', 'No se pudo cargar la actividad', e.message);
      $('#pageInfo').textContent = '';
      icons();
    }
  };

  const reset = () => { state.page = 1; load(); };
  let debounce;
  $('#q').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { state.q = e.target.value.trim(); reset(); }, 300); });
  $('#user').addEventListener('change', e => { state.user = e.target.value; reset(); });
  $('#act').addEventListener('change', e => { state.act = e.target.value; reset(); });
  ['from', 'to'].forEach(k => $('#' + k).addEventListener('change', e => {
    state[k] = isDay(e.target.value) ? e.target.value : '';
    if (state.from && state.to && state.from > state.to) { toast('La fecha inicial es posterior a la final.', true); return; }
    reset();
  }));
  $('#filters').addEventListener('submit', e => e.preventDefault());
  $('#clear').addEventListener('click', () => { Object.assign(state, { q: '', user: '', act: '', from: '', to: '' }); $('#q').value = ''; $('#from').value = ''; $('#to').value = ''; reset(); });
  $('#rows').addEventListener('click', e => { const a = e.target.closest('[data-user]'); if (!a) return; e.preventDefault(); state.user = a.dataset.user; reset(); });
  $('#refresh').addEventListener('click', load);
  $('#prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
  $('#next').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load(); } });
  $('#exportCsv').addEventListener('click', async e => {
    const button = e.currentTarget;
    button.disabled = true;
    try { await download('admin-audit-log?' + query({ format: 'csv' })); toast('Actividad exportada.'); } catch (err) { toast(err.message, true); } finally { button.disabled = false; }
  });
  await load();
}));
