// Genera assets/vendor/lucide-icons.min.js solo con los íconos que aparecen en el código.
// Correr `npm run build:icons` al usar un ícono nuevo. Si una página pide uno que no está,
// el paquete carga la librería completa (assets/vendor/lucide.min.js).
import fs from 'node:fs';
import path from 'node:path';
import { build } from 'esbuild';
import * as lucide from 'lucide';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const out = path.join(root, 'assets/vendor/lucide-icons.min.js');
const skip = /node_modules|[\\/](dist|vendor|ops|docs|tests|legacy|\.git)([\\/]|$)|server\.js|src[\\/]lib/;
const pascal = name => name.replace(/(^|-)([a-z0-9])/g, (_, __, c) => c.toUpperCase());

// Nombres que se piden en el código pero ya no existen en Lucide 1.x (íconos de marcas):
// no deben disparar la carga de la librería completa.
const KNOWN_MISSING = ['facebook', 'instagram', 'linkedin'];

const files = [];
const walk = dir => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (skip.test(file)) continue;
    if (entry.isDirectory()) walk(file);
    else if (/\.(html|js|php|mjs)$/.test(entry.name)) files.push(file);
  }
};
walk(root);

const used = new Set();
for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  for (const [, name] of source.matchAll(/["'`]([a-z][a-z0-9]*(?:-[a-z0-9]+)*)["'`]/g)) {
    const key = pascal(name);
    if (lucide.icons[key]) used.add(key);
  }
}
const names = [...used].sort();

const entry = `
import { createIcons, ${names.join(', ')} } from 'lucide';
const icons = { ${names.join(', ')} };
const KNOWN_MISSING = new Set(${JSON.stringify(KNOWN_MISSING)});
const FULL_URL = '/assets/vendor/lucide.min.js?v=1.55.0';
let loadingFull = null;
const pendingIcons = () => [...document.querySelectorAll('[data-lucide]')]
  .filter(el => el.tagName.toLowerCase() !== 'svg' && !KNOWN_MISSING.has(el.getAttribute('data-lucide')));
function loadFull(options) {
  if (!loadingFull) {
    loadingFull = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = FULL_URL;
      script.onload = () => resolve(window.lucide); // la librería completa reemplaza a window.lucide
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }
  loadingFull.then(full => full.createIcons(options)).catch(() => {});
}
window.lucide = {
  icons,
  createIcons(options = {}) {
    createIcons({ ...options, icons: { ...icons, ...(options.icons || {}) } });
    if (pendingIcons().length) loadFull(options);
  },
};
`;

await build({
  stdin: { contents: entry, resolveDir: root, loader: 'js' },
  bundle: true,
  minify: true,
  format: 'iife',
  target: 'es2019',
  outfile: out,
  banner: { js: `/* Lucide 1.55.0 (ISC) · ${names.length} íconos usados por SmartISP · generado por scripts/build-icons.mjs */` },
  logLevel: 'warning',
});
console.log(`lucide-icons.min.js: ${names.length} íconos, ${(fs.statSync(out).size / 1024).toFixed(1)} KB`);
