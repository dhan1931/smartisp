(function () {
  'use strict';

  const accountLinks = document.querySelectorAll('a[href*="openAccount=1"]');
  if (!accountLinks.length) return;

  // tienda.html guarda el nombre del usuario autenticado en localStorage/sessionStorage
  // ('smartisp.accountUser') al iniciar sesion; aqui solo lo leemos para que el boton
  // "Mi cuenta" de las demas paginas publicas muestre el mismo nombre en vez de quedarse
  // siempre en el texto generico.
  try {
    const raw = localStorage.getItem('smartisp.accountUser') || sessionStorage.getItem('smartisp.accountUser');
    const user = raw ? JSON.parse(raw) : null;
    if (user?.name) {
      accountLinks.forEach(link => {
        const span = link.querySelector('span');
        if (span) span.textContent = user.name;
      });
    }
  } catch {}

  const dialog = document.createElement('dialog');
  dialog.className = 'public-account-dialog';
  dialog.setAttribute('aria-label', 'Mi cuenta');

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'public-account-close';
  closeButton.textContent = 'Cerrar';
  closeButton.addEventListener('click', () => dialog.close());

  const loading = document.createElement('p');
  loading.className = 'public-account-loading';
  loading.textContent = 'Cargando tu cuenta…';

  // El iframe carga la tienda completa: se pide al abrir por primera vez, no en cada página.
  const frame = document.createElement('iframe');
  frame.title = 'Acceso y perfil de Mi cuenta';
  const loadFrame = () => { if (!frame.src) frame.src = '/tienda.html?openAccount=1&embedAccount=1'; };
  dialog.append(closeButton, loading, frame);
  document.body.append(dialog);

  const styles = document.createElement('style');
  styles.textContent = `
    .public-account-dialog{width:min(900px,calc(100vw - 24px));height:min(760px,calc(100dvh - 24px));max-width:none;max-height:none;padding:0;border:0;border-radius:14px;background:#fff;box-shadow:0 24px 80px rgba(5,28,40,.35);overflow:hidden}
    .public-account-dialog::backdrop{background:rgba(8,31,44,.66);backdrop-filter:blur(3px)}
    .public-account-dialog iframe{position:relative;display:block;width:100%;height:100%;border:0;background:transparent}
    .public-account-loading{position:absolute;inset:0;display:grid;place-items:center;margin:0;color:#4a6577;font-weight:700}
    .public-account-dialog.is-loaded .public-account-loading{display:none}
    .public-account-close{position:absolute;z-index:2;top:12px;right:12px;min-height:34px;padding:0 12px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#163342;font-weight:700;cursor:pointer}
    @media(max-width:600px){.public-account-dialog{width:100vw;height:100dvh;border-radius:0}.public-account-close{top:8px;right:8px}}
  `;
  document.head.append(styles);

  accountLinks.forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    if (dialog.open) return;
    dialog.showModal();
    if (!frame.src) loadFrame();
    else frame.contentWindow?.postMessage({ type: 'smartisp:open-account' }, window.location.origin);
  }));
  accountLinks.forEach(link => ['pointerenter', 'touchstart', 'focus'].forEach(type => link.addEventListener(type, loadFrame, { once: true, passive: true })));

  dialog.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });
  window.addEventListener('message', event => {
    if (event.origin !== window.location.origin) return;
    if (event.data?.type === 'smartisp:close-account') dialog.close();
    if (event.data?.type === 'smartisp:account-ready') dialog.classList.add('is-loaded');
  });
})();
