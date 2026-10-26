/* Equipo (equipo.html): cuentas del panel, roles, estado y sesiones. Las reglas viven en api/handlers/team.php. */
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, api, post, toast, icons, modal, confirm, relTime, utc, state: stateHtml, skeletonRows, remembered, remember } = SmartAdmin;
  const saved = remembered('equipo', {});
  const state = { q: '', status: ['all', 'active', 'inactive'].includes(saved.status) ? saved.status : 'all', data: null };

  const ec = v => { const d = utc(v); return d ? d.toLocaleString('es-EC', { timeZone: 'America/Guayaquil', dateStyle: 'medium', timeStyle: 'short' }) : ''; };
  const when = v => v ? `<span title="${esc(ec(v))} (hora de Ecuador)">${esc(relTime(v))}</span>` : '<span class="muted">Sin registro</span>';
  const fullName = m => [m.name, m.surname].filter(Boolean).join(' ') || 'Sin nombre';
  const syncStatus = () => $('#statusFilter').querySelectorAll('[data-status]').forEach(b => { const on = b.dataset.status === state.status; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });
  const roleOptions = (current, teamOnly) => (state.data?.roles || []).filter(r => !teamOnly || r.team).map(r => `<option value="${esc(r.value)}"${r.value === current ? ' selected' : ''}>${esc(r.label)}${r.team ? '' : ' (sin acceso al panel)'}</option>`).join('');

  // El token admin guardado deja de valer al cerrar las propias sesiones: se reemplaza donde estaba.
  const replaceToken = token => { if (!token) return; try { ['localStorage', 'sessionStorage'].forEach(k => { if (window[k].getItem('smartisp.adminToken')) window[k].setItem('smartisp.adminToken', token); }); } catch (e) { /* almacenamiento bloqueado */ } };

  const rowHtml = m => {
    const actions = [
      `<button class="button small" type="button" data-act="role" title="Cambiar el rol"><i data-lucide="user-cog" aria-hidden="true"></i> Rol</button>`,
      state.data.schema.active ? (m.active
        ? `<button class="button small" type="button" data-act="disable"${m.isSelf ? ' disabled title="No puedes desactivar tu propia cuenta"' : ' title="Bloquea el acceso sin borrar la cuenta"'}><i data-lucide="user-x" aria-hidden="true"></i> Desactivar</button>`
        : `<button class="button small" type="button" data-act="enable"><i data-lucide="user-check" aria-hidden="true"></i> Reactivar</button>`) : '',
      state.data.schema.sessions ? `<button class="button small" type="button" data-act="revoke" title="Obliga a volver a iniciar sesión en todos sus dispositivos"><i data-lucide="log-out" aria-hidden="true"></i> Cerrar sesiones</button>` : '',
      `<a class="button small ghost" href="/actividad.html?user=${encodeURIComponent(m.id)}" title="Ver su actividad"><i data-lucide="history" aria-hidden="true"></i><span class="sr-only">Actividad de ${esc(fullName(m))}</span></a>`
    ].join('');
    return `<tr data-id="${esc(m.id)}" class="${m.active ? '' : 'is-inactive'}">
      <td data-export="${esc(fullName(m) + ' <' + m.email + '>')}"><div class="member"><span class="member-avatar" aria-hidden="true">${esc(fullName(m).charAt(0).toUpperCase())}</span><span><span class="member-name">${esc(fullName(m))}${m.isSelf ? ' <span class="badge info">Tú</span>' : ''}</span><a class="member-email" href="mailto:${esc(m.email)}">${esc(m.email)}</a></span></div></td>
      <td data-label="Rol"><span class="badge violet">${esc(m.roleLabel)}</span></td>
      <td data-label="Estado">${m.active ? '<span class="badge ok">Activa</span>' : '<span class="badge bad">Desactivada</span>'}</td>
      <td data-label="Último acceso" data-export="${esc(ec(m.lastLoginAt))}">${when(m.lastLoginAt)}</td>
      <td data-label="Última actividad" data-export="${esc(ec(m.lastActivityAt))}">${when(m.lastActivityAt)}</td>
      <td class="actions"><div class="row-actions">${actions}</div></td>
    </tr>`;
  };

  const paint = () => {
    const members = state.data?.members || [];
    const week = Date.now() - 7 * 86400000;
    const active = members.filter(m => m.active).length;
    $('#stats').innerHTML = [['users-round', 'Cuentas del equipo', members.length], ['user-check', 'Activas', active], ['user-x', 'Desactivadas', members.length - active],
      ['log-in', 'Entraron en 7 días', members.filter(m => (utc(m.lastLoginAt)?.getTime() || 0) >= week).length]]
      .map(([icon, label, value]) => `<article class="stat"><span><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong>${value}</strong></article>`).join('');
    const q = state.q.toLowerCase();
    const list = members.filter(m => (state.status === 'all' || (state.status === 'active') === m.active) && (!q || (fullName(m) + ' ' + m.email).toLowerCase().includes(q)));
    $('#count').textContent = `${list.length} cuenta${list.length === 1 ? '' : 's'}`;
    $('#rows').innerHTML = list.map(rowHtml).join('');
    $('#tableWrap').hidden = !list.length;
    $('#empty').hidden = !!list.length;
    if (!list.length) $('#empty').innerHTML = members.length ? stateHtml('search', 'No hay cuentas que coincidan', 'Prueba con otro nombre, correo o estado.') : stateHtml('empty', 'Todavía no hay cuentas de equipo', 'Crea la primera con «Nueva cuenta».', 'users-round');
    icons();
  };

  const load = async () => {
    $('#tableWrap').hidden = false;
    $('#empty').hidden = true;
    $('#rows').innerHTML = skeletonRows(6, 4);
    try {
      state.data = await api('admin-team');
      $('#migrationNote').hidden = !!(state.data.schema.active && state.data.schema.audit);
      paint();
    } catch (e) {
      $('#tableWrap').hidden = true;
      $('#empty').hidden = false;
      $('#empty').innerHTML = stateHtml('error', e.status === 403 ? 'Sin permiso para gestionar el equipo' : 'No se pudo cargar el equipo', e.message);
      icons();
    }
  };

  const member = id => (state.data?.members || []).find(m => m.id === id);

  // La contraseña temporal se muestra una sola vez: el servidor no la guarda en claro ni en el registro.
  const showPassword = (m, password) => {
    const body = document.createElement('div');
    body.innerHTML = `<p class="team-modal-text">Comparte esta contraseña temporal con <strong>${esc(fullName(m))}</strong> por un canal seguro. <strong>No se volverá a mostrar.</strong> Pídele que la cambie desde «Mi cuenta» al entrar.</p>
      <div class="temp-password"><code id="tempPassword">${esc(password)}</code><button class="button small" type="button" id="copyPassword"><i data-lucide="copy" aria-hidden="true"></i> Copiar</button></div>
      <p class="team-modal-text muted">Usuario: ${esc(m.email)} · Panel: ${esc(location.origin)}/login.html?admin=1</p>`;
    modal({ title: 'Cuenta creada', subtitle: m.email, body, actions: [{ label: 'Ya la guardé', kind: 'primary', value: true }] });
    body.querySelector('#copyPassword').addEventListener('click', () => {
      (navigator.clipboard ? navigator.clipboard.writeText(password) : Promise.reject()).then(() => toast('Contraseña copiada.'), () => toast('Selecciona y copia la contraseña manualmente.', true));
    });
  };

  const openCreate = () => {
    const form = document.createElement('form');
    form.noValidate = true;
    form.innerHTML = `
      <div class="form-grid">
        <div class="form-row"><label for="newName">Nombre</label><input class="field" id="newName" maxlength="120" required autocomplete="off" autofocus></div>
        <div class="form-row"><label for="newSurname">Apellido</label><input class="field" id="newSurname" maxlength="120" autocomplete="off"></div>
      </div>
      <div class="form-row"><label for="newEmail">Correo</label><input class="field" id="newEmail" type="email" maxlength="191" required autocomplete="off"></div>
      <div class="form-row"><label for="newRole">Rol</label><select class="select" id="newRole">${roleOptions('admin', true)}</select><span class="hint">Administrador: acceso completo al panel, incluida la gestión del equipo.</span></div>
      <fieldset class="form-row access-mode"><legend>Acceso inicial</legend>
        <label><input type="radio" name="mode" value="password" checked> <span><strong>Generar una contraseña temporal</strong><small>Se muestra una sola vez para que se la compartas.</small></span></label>
        <label><input type="radio" name="mode" value="invite"> <span><strong>Enviar un enlace por correo</strong><small>La persona define su contraseña con el enlace (válido 72 horas).</small></span></label>
      </fieldset>`;
    const dialog = modal({ title: 'Nueva cuenta de equipo', subtitle: 'Tendrá acceso al panel administrativo.', body: form, actions: [{ label: 'Cancelar', value: false }, { label: 'Crear cuenta', kind: 'primary', onClick: () => { submit(); return false; } }] });
    const submit = async (promoteExisting = false) => {
      const payload = { name: form.querySelector('#newName').value.trim(), surname: form.querySelector('#newSurname').value.trim(), email: form.querySelector('#newEmail').value.trim().toLowerCase(), role: form.querySelector('#newRole').value, mode: form.querySelector('[name="mode"]:checked').value, promoteExisting };
      if (!payload.name) { toast('Escribe el nombre.', true); form.querySelector('#newName').focus(); return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email)) { toast('Escribe un correo válido.', true); form.querySelector('#newEmail').focus(); return; }
      const button = dialog.el.querySelector('.panel-foot .primary');
      button.disabled = true;
      try {
        const res = await post('admin-team-create', payload);
        dialog.close(true);
        if (res.promoted) toast(`${res.member.email} ya puede entrar al panel con su contraseña actual.`);
        else if (res.temporaryPassword) showPassword(res.member, res.temporaryPassword);
        else toast(res.resetSent ? `Cuenta creada. Enviamos el enlace a ${res.member.email}.` : 'Cuenta creada, pero no se pudo enviar el correo. Revisa la configuración SMTP o genera una contraseña temporal.', !res.resetSent);
        await load();
      } catch (e) {
        button.disabled = false;
        if (e.status === 409 && /cliente/.test(e.message) && !promoteExisting) {
          if (await confirm('La cuenta ya existe', `${payload.email} ya es un cliente registrado. ¿Darle acceso al panel como ${form.querySelector('#newRole').selectedOptions[0].textContent}? Conservará su contraseña actual.`, 'Dar acceso')) submit(true);
          return;
        }
        toast(e.message, true);
      }
    };
    form.addEventListener('submit', e => { e.preventDefault(); submit(); });
  };

  const changeRole = m => {
    const body = document.createElement('div');
    body.innerHTML = `<div class="form-row"><label for="roleSelect">Rol de ${esc(fullName(m))}</label><select class="select" id="roleSelect" autofocus>${roleOptions(m.role, false)}</select>
      <span class="hint">Pasar a «Cliente» le quita el acceso al panel en su próxima petición.${m.isSelf ? ' Si te lo quitas a ti, saldrás del panel.' : ''}</span></div>`;
    const dialog = modal({ title: 'Cambiar rol', subtitle: m.email, body, actions: [{ label: 'Cancelar', value: false }, { label: 'Guardar rol', kind: 'primary', onClick: () => { save(); return false; } }] });
    const save = async () => {
      const role = body.querySelector('#roleSelect').value;
      if (role === m.role) { dialog.close(false); return; }
      try {
        const res = await post('admin-team-role', { id: m.id, role });
        dialog.close(true);
        if (res.selfDemoted) { toast('Ya no tienes acceso al panel.'); setTimeout(() => location.assign('/tienda.html'), 1200); return; }
        toast(`Rol actualizado: ${res.roleLabel}.`);
        await load();
      } catch (e) { toast(e.message, true); }
    };
  };

  const run = async (m, act) => {
    if (act === 'role') { changeRole(m); return; }
    const texts = {
      disable: ['¿Desactivar la cuenta?', `${fullName(m)} no podrá entrar y sus sesiones abiertas se cerrarán. Puedes reactivarla después.`, 'Desactivar'],
      enable: ['¿Reactivar la cuenta?', `${fullName(m)} podrá volver a iniciar sesión con su contraseña.`, 'Reactivar'],
      revoke: ['¿Cerrar todas sus sesiones?', m.isSelf ? 'Se cerrarán tus sesiones en otros dispositivos; esta pestaña seguirá abierta.' : `${fullName(m)} tendrá que volver a iniciar sesión en todos sus dispositivos.`, 'Cerrar sesiones']
    }[act];
    if (!texts || !(await confirm(...texts))) return;
    try {
      if (act === 'revoke') { const res = await post('admin-team-revoke', { id: m.id }); replaceToken(res.token); toast('Sesiones cerradas.'); }
      else { await post('admin-team-status', { id: m.id, active: act === 'enable' }); toast(act === 'enable' ? 'Cuenta reactivada.' : 'Cuenta desactivada.'); }
      await load();
    } catch (e) { toast(e.message, true); }
  };

  $('#rows').addEventListener('click', e => { const b = e.target.closest('[data-act]'); const m = b && member(b.closest('tr').dataset.id); if (m) run(m, b.dataset.act); });
  let debounce;
  $('#search').addEventListener('input', e => { clearTimeout(debounce); debounce = setTimeout(() => { state.q = e.target.value.trim(); paint(); }, 200); });
  $('#statusFilter').addEventListener('click', e => { const b = e.target.closest('[data-status]'); if (!b || b.dataset.status === state.status) return; state.status = b.dataset.status; remember('equipo', { status: state.status }); syncStatus(); paint(); });
  $('#refresh').addEventListener('click', load);
  $('#create').addEventListener('click', openCreate);
  syncStatus();
  await load();
  if (new URLSearchParams(location.search).get('new') === '1' && state.data) openCreate();
}));
