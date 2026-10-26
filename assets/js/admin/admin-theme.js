/*
 * Preferencias visuales del panel. Se carga SIN defer en el <head> para aplicar tema, densidad y
 * el hueco de la barra lateral antes del primer pintado (sin parpadeo ni saltos de layout).
 * <html data-theme-lock="light"> fija el tema claro en pantallas que previsualizan la tienda.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  var KEYS = { theme: 'smartisp.adminTheme', density: 'smartisp.adminDensity', collapsed: 'smartisp.adminSidebarCollapsed' };

  function read(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
  function write(key, value) { try { if (value == null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch (e) { /* almacenamiento bloqueado */ } }

  function applyTheme(value) {
    var lock = root.getAttribute('data-theme-lock');
    var theme = lock || value;
    if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
    else root.removeAttribute('data-theme');
  }
  function applyDensity(value) {
    if (value === 'compact') root.setAttribute('data-density', 'compact');
    else root.removeAttribute('data-density');
  }

  applyTheme(read(KEYS.theme));
  applyDensity(read(KEYS.density));
  root.classList.add('admin-layout');
  if (read(KEYS.collapsed) === '1') root.classList.add('admin-collapsed');

  function effectiveTheme() {
    var t = root.getAttribute('data-theme');
    if (t) return t;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  window.SmartPrefs = {
    theme: function () { return read(KEYS.theme) || 'auto'; },
    effectiveTheme: effectiveTheme,
    locked: function () { return !!root.getAttribute('data-theme-lock'); },
    setTheme: function (value) {
      write(KEYS.theme, value === 'light' || value === 'dark' ? value : null);
      applyTheme(value);
      document.dispatchEvent(new CustomEvent('smartisp:theme', { detail: { theme: effectiveTheme() } }));
    },
    density: function () { return read(KEYS.density) === 'compact' ? 'compact' : 'comfortable'; },
    setDensity: function (value) {
      write(KEYS.density, value === 'compact' ? 'compact' : null);
      applyDensity(value);
    },
    collapsed: function () { return root.classList.contains('admin-collapsed'); },
    setCollapsed: function (value) {
      root.classList.toggle('admin-collapsed', !!value);
      write(KEYS.collapsed, value ? '1' : '0');
    },
    get: read,
    set: write
  };
})();
