/**
 * Base compartida de las pantallas del panel. Cada página repetía su propio cliente de API, la
 * puerta de login y los formateadores; las pantallas nuevas usan esto en su lugar.
 *
 * Uso:
 *   <main class="shell" id="app" hidden>...</main>
 *   <script src="/assets/js/admin-page.js" defer></script>
 *   <script>
 *     // Los scripts defer corren antes de DOMContentLoaded: ahí SmartAdmin ya existe.
 *     document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => { ... }));
 *   </script>
 *
 * boot() comprueba la sesión (/api/auth/me). Si no hay sesión muestra un login; si la cuenta
 * no es administradora, un aviso. Solo con un admin muestra #app y llama a onReady.
 */
(function () {
  'use strict';

  var $ = function (selector, root) { return (root || document).querySelector(selector); };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  var moneyFormat = new Intl.NumberFormat('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function money(value) { return '$' + moneyFormat.format(Number(value || 0)); }

  var compactFormat = new Intl.NumberFormat('es-EC', { notation: 'compact', maximumFractionDigits: 1 });
  function compact(value) { return compactFormat.format(Number(value || 0)); }

  function number(value) { return Number(value || 0).toLocaleString('es-EC'); }

  function parseDate(value) {
    if (!value) return null;
    var d = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.valueOf()) ? null : d;
  }

  function date(value, fallback) {
    var d = parseDate(value);
    return d ? d.toLocaleDateString('es-EC', { year: 'numeric', month: 'short', day: 'numeric' }) : (fallback || '—');
  }

  function token() {
    try { return localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken'); } catch (e) { return null; }
  }

  function remember(result) {
    if (result && result.token) {
      try { localStorage.setItem('smartisp.adminToken', result.token); } catch (e) { /* almacenamiento bloqueado */ }
    }
  }

  function url(action) {
    var parts = action.split('?');
    return '/api/router.php?action=' + encodeURIComponent(parts[0]) + (parts[1] ? '&' + parts[1] : '');
  }

  function request(action, options) {
    options = options || {};
    var headers = Object.assign({}, options.headers || {});
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    var t = token();
    if (t) { headers.Authorization = 'Bearer ' + t; headers['X-Admin-Token'] = t; }
    return fetch(url(action), Object.assign({ credentials: 'include' }, options, { headers: headers }));
  }

  // JSON: lanza un Error con .status si la respuesta no es 2xx.
  async function api(action, options) {
    var response = await request(action, options);
    var text = await response.text();
    var result = {};
    try { result = text ? JSON.parse(text) : {}; } catch (e) { /* respuesta no JSON */ }
    if (!response.ok) {
      var error = new Error(result.error || 'No se pudo completar la operación.');
      error.status = response.status;
      throw error;
    }
    remember(result);
    return result;
  }

  function post(action, body) {
    return api(action, { method: 'POST', body: JSON.stringify(body || {}) });
  }

  // Descarga un archivo (CSV) respetando el nombre que manda el servidor.
  async function download(action) {
    var response = await request(action);
    if (!response.ok) {
      var data = {};
      try { data = await response.json(); } catch (e) { /* sin cuerpo */ }
      throw new Error(data.error || 'No se pudo generar el archivo.');
    }
    var disposition = response.headers.get('Content-Disposition') || '';
    var match = /filename="([^"]+)"/.exec(disposition);
    var blob = await response.blob();
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match ? match[1] : 'reporte.csv';
    document.body.appendChild(link);
    link.click();
    setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
  }

  var toastTimer;
  function toast(message, isError) {
    var el = $('#smartadmin-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'smartadmin-toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3200);
  }

  function icons() { if (window.lucide) window.lucide.createIcons(); }

  function isAdmin(user) {
    return !!user && ['admin', 'administrator', 'administrador'].indexOf(String(user.role || '').trim().toLowerCase()) !== -1;
  }

  function gateHtml() {
    return '' +
      '<section class="notice" id="smartadmin-login" hidden>' +
        '<h2>Acceso administrativo</h2><p>Inicia sesión con una cuenta administradora para continuar.</p>' +
        '<form id="smartadmin-login-form">' +
          '<label>Correo electrónico<input name="email" type="email" required autocomplete="username"></label>' +
          '<label>Contraseña<input name="password" type="password" required autocomplete="current-password"></label>' +
          '<button class="button primary" type="submit">Entrar</button><div role="status"></div>' +
        '</form>' +
      '</section>' +
      '<section class="notice" id="smartadmin-denied" hidden>' +
        '<h2>Acceso restringido</h2><p>Esta sección está disponible únicamente para administradores.</p>' +
        '<a class="button" href="/tienda.html">Volver a la tienda</a>' +
      '</section>';
  }

  function boot(onReady) {
    var start = async function () {
      document.body.insertAdjacentHTML('beforeend', gateHtml());
      var app = $('#app');
      var view = function (name) {
        app.hidden = name !== 'app';
        $('#smartadmin-login').hidden = name !== 'login';
        $('#smartadmin-denied').hidden = name !== 'denied';
      };
      var ready = async function () { view('app'); icons(); await onReady(); icons(); };

      $('#smartadmin-login-form').addEventListener('submit', async function (event) {
        event.preventDefault();
        var status = event.currentTarget.querySelector('[role=status]');
        status.textContent = '';
        try {
          var data = Object.fromEntries(new FormData(event.currentTarget));
          data.adminOnly = true;
          var result = await post('login', data);
          if (!isAdmin(result.user)) { view('denied'); return; }
          await ready();
        } catch (error) {
          status.textContent = error.message;
        }
      });

      try {
        var me = await api('me');
        if (!isAdmin(me.user)) { view('denied'); return; }
        await ready();
      } catch (error) {
        if (error.status === 401) { view('login'); return; }
        view('app');
        toast(error.message, true);
      }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
  }

  window.SmartAdmin = {
    $: $, esc: esc, money: money, compact: compact, number: number, date: date, parseDate: parseDate,
    api: api, post: post, download: download, toast: toast, icons: icons, boot: boot
  };
})();
