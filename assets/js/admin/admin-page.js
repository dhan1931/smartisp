/**
 * Base compartida de las pantallas del panel. Cada página repetía su propio cliente de API, la
 * puerta de login y los formateadores; las pantallas nuevas usan esto en su lugar.
 *
 * Uso:
 *   <main class="shell" id="app" hidden>...</main>
 *   <script src="/assets/js/admin/admin-page.js" defer></script>
 *   <script>
 *     // Los scripts defer corren antes de DOMContentLoaded: ahí SmartAdmin ya existe.
 *     document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => { ... }));
 *   </script>
 *
 * boot() comprueba la sesión (/api/auth/me). Si no hay sesión lleva a /login.html; si la cuenta
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

  function rememberToken(result) {
    if (result && result.token) {
      try { localStorage.setItem('smartisp.adminToken', result.token); } catch (e) { /* almacenamiento bloqueado */ }
    }
  }

  function url(action) {
    var parts = action.split('?');
    return '/api/router.php?action=' + encodeURIComponent(parts[0]) + (parts[1] ? '&' + parts[1] : '');
  }

  function base64Utf8(text) {
    var bytes = new TextEncoder().encode(text);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
  }

  // El WAF de producción bloquea algunos JSON en claro: router.php desempaqueta {payload: base64(JSON)}.
  function wafSafe(body, method) {
    if (typeof body !== 'string' || (method !== 'POST' && method !== 'PUT')) return body;
    try {
      var parsed = JSON.parse(body);
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || parsed.payload) return body;
      return JSON.stringify({ payload: base64Utf8(body) });
    } catch (e) { return body; }
  }

  function request(action, options) {
    options = options || {};
    var headers = Object.assign({}, options.headers || {});
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    var t = token();
    if (t) { headers.Authorization = 'Bearer ' + t; headers['X-Admin-Token'] = t; }
    var method = String(options.method || 'GET').toUpperCase();
    return fetch(url(action), Object.assign({ credentials: 'include' }, options, { headers: headers, body: wafSafe(options.body, method) }));
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
    rememberToken(result);
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

  // ---------- Componentes compartidos (HTML) ----------
  function state(kind, title, text, icon) {
    var ico = icon || ({ empty: 'inbox', error: 'circle-alert', search: 'search-x' }[kind] || 'inbox');
    return '<div class="state' + (kind === 'error' ? ' is-error' : '') + '" role="' + (kind === 'error' ? 'alert' : 'status') + '">' +
      '<i data-lucide="' + ico + '" aria-hidden="true"></i><strong>' + esc(title) + '</strong>' + (text ? '<span>' + esc(text) + '</span>' : '') + '</div>';
  }

  function skeletonRows(cols, rows) {
    var cells = '';
    for (var c = 0; c < cols; c++) cells += '<td><span class="sk sk-line" style="width:' + (40 + ((c * 37) % 50)) + '%"></span></td>';
    var out = '';
    for (var r = 0; r < (rows || 5); r++) out += '<tr aria-hidden="true">' + cells + '</tr>';
    return out;
  }

  function pct(now, before) {
    now = Number(now || 0); before = Number(before || 0);
    if (!before) return now ? null : 0;
    return Math.round(((now - before) / before) * 1000) / 10;
  }

  // Delta coloreado; invert=true cuando subir es malo (cancelaciones).
  function delta(now, before, opts) {
    opts = opts || {};
    var p = pct(now, before);
    if (p === null) return '<span class="delta up" title="Sin datos en el periodo anterior">Nuevo</span>';
    if (p === 0) return '<span class="delta" title="Igual que el periodo anterior">= 0%</span>';
    var good = opts.invert ? p < 0 : p > 0;
    return '<span class="delta ' + (good ? 'up' : 'down') + '"><i data-lucide="' + (p > 0 ? 'arrow-up-right' : 'arrow-down-right') + '" aria-hidden="true"></i>' +
      (p > 0 ? '+' : '') + String(Math.abs(p) >= 100 ? Math.round(p) : p).replace('.', ',') + '%<span class="sr-only"> frente al periodo anterior</span></span>';
  }

  // Fechas de la BD vienen en UTC sin zona: se interpretan como UTC para mostrarlas en hora local.
  function utc(value) {
    if (!value) return null;
    var s = String(value);
    return parseDate(/[zZ]$|[+-]\d\d:?\d\d$/.test(s) ? s : s.replace(' ', 'T') + 'Z');
  }

  function relTime(value) {
    var d = utc(value);
    if (!d) return '—';
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'hace un momento';
    if (diff < 3600) return 'hace ' + Math.round(diff / 60) + ' min';
    if (diff < 86400) return 'hace ' + Math.round(diff / 3600) + ' h';
    if (diff < 86400 * 30) { var days = Math.round(diff / 86400); return 'hace ' + days + (days === 1 ? ' día' : ' días'); }
    return d.toLocaleDateString('es-EC', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  // ---------- CSV ----------
  function csvCell(v) {
    var s = v == null ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  // Excel en es-EC abre bien ; como separador y BOM UTF-8 (igual que admin-export).
  function csv(filename, headers, rows) {
    var text = '﻿' + [headers].concat(rows).map(function (r) { return r.map(csvCell).join(';'); }).join('\r\n');
    var link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
  }

  function tableCsv(table, filename) {
    var cols = [];
    var headers = [];
    Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th, i) {
      if (th.hasAttribute('data-no-export')) return;
      cols.push(i);
      headers.push(th.textContent.trim());
    });
    var rows = Array.prototype.filter.call(table.querySelectorAll('tbody tr'), function (tr) { return !tr.hasAttribute('aria-hidden') && tr.children.length > 1; })
      .map(function (tr) { return cols.map(function (i) { var td = tr.children[i]; return td ? (td.getAttribute('data-export') || td.innerText.replace(/\s+/g, ' ').trim()) : ''; }); });
    if (!rows.length) { toast('No hay filas para exportar.', true); return; }
    csv(filename || 'smartisp-tabla.csv', headers, rows);
    toast(rows.length + ' fila' + (rows.length === 1 ? '' : 's') + ' exportada' + (rows.length === 1 ? '' : 's') + '.');
  }

  // <table data-export="nombre"> recibe un botón "Exportar CSV" con las filas que se ven en pantalla.
  // data-export-host="id" indica dónde poner el botón (si no, va encima de la tabla).
  function enhanceTables(root) {
    (root || document).querySelectorAll('table[data-export]:not([data-export-ready])').forEach(function (table) {
      table.setAttribute('data-export-ready', '');
      var host = document.getElementById(table.getAttribute('data-export-host') || '');
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'button small';
      button.innerHTML = '<i data-lucide="download" aria-hidden="true"></i> Exportar CSV';
      button.title = 'Descargar en CSV las filas que se ven en pantalla';
      button.addEventListener('click', function () {
        tableCsv(table, 'smartisp-' + table.getAttribute('data-export') + '-' + new Date().toISOString().slice(0, 10) + '.csv');
      });
      if (host) { host.appendChild(button); return; }
      var wrap = table.closest('.table-wrap') || table;
      var bar = document.createElement('div');
      bar.className = 'table-actions';
      bar.appendChild(button);
      wrap.parentNode.insertBefore(bar, wrap);
    });
    icons();
  }

  // ---------- Vistas guardadas / filtros persistentes ----------
  function remembered(key, fallback) {
    try { var v = JSON.parse(localStorage.getItem('smartisp.view.' + key) || 'null'); return v == null ? fallback : v; } catch (e) { return fallback; }
  }
  function remember(key, value) {
    try { localStorage.setItem('smartisp.view.' + key, JSON.stringify(value)); } catch (e) { /* almacenamiento bloqueado */ }
  }

  // ---------- Modal y drawer accesibles ----------
  function overlay(kind, opts) {
    var opener = document.activeElement;
    var el = document.createElement('div');
    el.className = kind === 'drawer' ? 'sa-drawer' : 'sa-modal';
    var id = 'sa-' + kind + '-' + Date.now();
    el.innerHTML = '<section class="panel" role="dialog" aria-modal="true" aria-labelledby="' + id + '">' +
      '<header class="panel-head"><div><h2 id="' + id + '">' + esc(opts.title || '') + '</h2>' + (opts.subtitle ? '<p>' + esc(opts.subtitle) + '</p>' : '') + '</div>' +
      '<button type="button" class="icon-button" data-close aria-label="Cerrar"><i data-lucide="x" aria-hidden="true"></i></button></header>' +
      '<div class="panel-body"></div>' + (opts.actions ? '<footer class="panel-foot"></footer>' : '') + '</section>';
    var body = el.querySelector('.panel-body');
    if (typeof opts.body === 'string') body.innerHTML = opts.body; else if (opts.body) body.appendChild(opts.body);
    var onKey;
    var close = function (result) {
      el.remove();
      document.removeEventListener('keydown', onKey, true);
      if (opener && opener.focus) opener.focus();
      if (opts.onClose) opts.onClose(result);
    };
    onKey = function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); close(false); return; }
      if (e.key !== 'Tab') return;
      var f = el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    (opts.actions || []).forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'button' + (a.kind ? ' ' + a.kind : '');
      b.textContent = a.label;
      b.addEventListener('click', function () { if (a.onClick && a.onClick(el) === false) return; close(a.value); });
      el.querySelector('.panel-foot').appendChild(b);
    });
    el.addEventListener('mousedown', function (e) { if (e.target === el) close(false); });
    el.querySelector('[data-close]').addEventListener('click', function () { close(false); });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(el);
    icons();
    (el.querySelector('[autofocus]') || el.querySelector('.panel-foot .primary') || el.querySelector('[data-close]')).focus();
    return { el: el, body: body, close: close };
  }
  function modal(opts) { return overlay('modal', opts); }
  function drawer(opts) { return overlay('drawer', opts); }
  function confirmDialog(title, text, okLabel) {
    return new Promise(function (resolve) {
      modal({ title: title, body: '<p style="margin:0;color:var(--text-2);line-height:1.5">' + esc(text) + '</p>', onClose: resolve,
        actions: [{ label: 'Cancelar', value: false }, { label: okLabel || 'Confirmar', kind: 'primary', value: true }] });
    });
  }

  function isAdmin(user) {
    return !!user && ['admin', 'administrator', 'administrador'].indexOf(String(user.role || '').trim().toLowerCase()) !== -1;
  }

  // Sin sesión se va al login central (el mismo que usa el resto del panel), que vuelve aquí.
  function goToLogin() {
    window.location.replace('/login.html?admin=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search));
  }

  async function switchAccount() {
    try { await post('logout'); } catch (e) { /* la sesión ya no existía */ }
    try {
      ['smartisp.adminToken', 'smartisp.accountUser', 'smartisp.accountRemembered'].forEach(function (k) {
        localStorage.removeItem(k); sessionStorage.removeItem(k);
      });
    } catch (e) { /* almacenamiento bloqueado */ }
    goToLogin();
  }

  function deniedHtml(email) {
    return '<section class="notice" id="smartadmin-denied">' +
      '<h2>Acceso restringido</h2>' +
      '<p>Iniciaste sesión como <strong>' + esc(email || 'cliente') + '</strong>. Esta sección está disponible únicamente para administradores.</p>' +
      '<div class="head-actions"><a class="button" href="/tienda.html">Ir a la tienda</a>' +
      '<button class="button primary" type="button" id="smartadmin-switch">Cambiar de cuenta</button></div>' +
    '</section>';
  }

  // Comprueba la sesión y solo con un administrador muestra #app y llama a onReady.
  function boot(onReady) {
    var start = async function () {
      var app = $('#app');
      try {
        var me = await api('me');
        if (!isAdmin(me.user)) {
          // Se quita el panel del DOM (no solo se oculta), como hacía admin.html (QA-027).
          app.remove();
          document.body.insertAdjacentHTML('beforeend', deniedHtml(me.user && me.user.email));
          $('#smartadmin-switch').addEventListener('click', switchAccount);
          return;
        }
        app.hidden = false;
        icons();
        await onReady(me.user);
        enhanceTables();
        icons();
      } catch (error) {
        if (error.status === 401) { goToLogin(); return; }
        app.hidden = false;
        toast(error.message, true);
      }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
  }

  window.SmartAdmin = {
    $: $, esc: esc, money: money, compact: compact, number: number, date: date, parseDate: parseDate,
    api: api, post: post, download: download, toast: toast, icons: icons, boot: boot,
    state: state, skeletonRows: skeletonRows, pct: pct, delta: delta, relTime: relTime, utc: utc,
    csv: csv, tableCsv: tableCsv, enhanceTables: enhanceTables, remembered: remembered, remember: remember,
    modal: modal, drawer: drawer, confirm: confirmDialog
  };
})();
