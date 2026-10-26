// Configuración (configuracion.html). Antes inline en la página; script clásico, se carga en el
// mismo punto que el inline original para conservar el orden de ejecución.
const $ = selector => document.querySelector(selector);
const DEFAULT_CONTENT = {
  brand_name: 'SmartISP',
  logo_type: 'image',
  logo_image: '/assets/img/logo-default.jpg',
  logo_height: '38',
  logo_dark_image: '/assets/img/logo-default.jpg',
  logo_dark_height: '42',
  logo_dark_invert: 'false',
  logo_text: 'smartisp',
  hero_eyebrow: 'Infraestructura para proveedores de internet',
  hero_title: 'Conecta más. Opera mejor.',
  hero_text: 'Equipamiento confiable para redes, servidores y equipos de trabajo que mantienen tu servicio siempre en línea.',
  hero_image: '',
  hero_button_text: 'Explorar soluciones',
  hero_button_link: '#catalogo',
  hero_image_position: 'center',
  hero_height: '370',
  hero_content_width: '510',
  hero_text_color: '#102c3d',
  hero_overlay_opacity: '96',
  primary_color: '#087ea4',
  footer_text: '',
  footer_whatsapp: '',
  footer_email: '',
  footer_address: '',
  footer_social_instagram: '',
  footer_social_facebook: '',
  footer_social_youtube: '',
  footer_social_linkedin: '',
  admin_email: '',
  smtp_provider: 'gmail',
  smtp_host: 'smtp.gmail.com',
  smtp_port: '465',
  smtp_user: '',
  smtp_pass: '',
  smtp_secure: 'true',
  smtp_from: '',
  resend_api_key: '',
  email_from: ''
};
const MAX_IMAGE_SIZE = 3 * 1024 * 1024;
const cropState = { image: null, fileInput: null, outputInput: null, canvas: null, box: null, canvasWidth: 0, canvasHeight: 0, offsetX: 0, offsetY: 0, dragging: false, startX: 0, startY: 0, startBoxX: 0, startBoxY: 0 };
const DEFAULT_VISIBILITY = { catalog_visible: 'true', hero_visible: 'true', benefits_visible: 'true', storefront_product_columns: '4', trust_stats_products_label_default: '', trust_stats_clients_default: '', trust_stats_projects_default: '', trust_stats_support_default: '' };
const showMessage = (element, text, error = false) => { element.textContent = text; element.classList.remove('show'); element.classList.toggle('error', error); requestAnimationFrame(() => element.classList.add('show')); };
const fileToDataUrl = file => new Promise((resolve, reject) => { if (!file) return resolve(''); if (!file.type.startsWith('image/')) return reject(new Error('Selecciona un archivo de imagen valido.')); if (file.size > MAX_IMAGE_SIZE) return reject(new Error('La imagen no puede superar 3 MB.')); const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error('No se pudo leer la imagen.')); reader.readAsDataURL(file); });
const cropRatio = () => { const value = $('#cropAspect').value; if (value === 'product') return 1; if (value === 'hero') return 16 / 6; return cropState.image.width / cropState.image.height; };
const updateCropBox = () => { const stage = $('#cropStage'); const value = $('#cropAspect').value; const ratio = cropRatio(); let width = cropState.canvasWidth * .86; let height = value === 'free' ? cropState.canvasHeight * .86 : width / ratio; if (height > cropState.canvasHeight * .86) { height = cropState.canvasHeight * .86; if (value !== 'free') width = height * ratio; } cropState.box = { x: (cropState.canvasWidth - width) / 2, y: (cropState.canvasHeight - height) / 2, w: width, h: height }; cropState.offsetX = (stage.clientWidth - cropState.canvasWidth) / 2; cropState.offsetY = (stage.clientHeight - cropState.canvasHeight) / 2; $('#cropBox').style.cssText = `left:${cropState.offsetX + cropState.box.x}px;top:${cropState.offsetY + cropState.box.y}px;width:${width}px;height:${height}px`; };
const openCropEditor = (file, fileInput, outputInput) => { if (!file.type.startsWith('image/')) throw new Error('Selecciona un archivo de imagen valido.'); if (file.size > MAX_IMAGE_SIZE) throw new Error('La imagen no puede superar 3 MB.'); const image = new Image(); image.onload = () => { cropState.image = image; cropState.fileInput = fileInput; cropState.outputInput = outputInput; cropState.canvas = $('#cropCanvas'); const scale = Math.min(720 / image.width, 500 / image.height, 1); cropState.canvasWidth = Math.round(image.width * scale); cropState.canvasHeight = Math.round(image.height * scale); cropState.canvas.width = cropState.canvasWidth; cropState.canvas.height = cropState.canvasHeight; cropState.canvas.style.width = `${cropState.canvasWidth}px`; cropState.canvas.style.height = `${cropState.canvasHeight}px`; cropState.canvas.getContext('2d').drawImage(image, 0, 0, cropState.canvasWidth, cropState.canvasHeight); $('#cropModal').hidden = false; requestAnimationFrame(updateCropBox); }; image.src = URL.createObjectURL(file); };
const closeCropEditor = clearFile => { if (clearFile && cropState.fileInput) cropState.fileInput.value = ''; $('#cropModal').hidden = true; cropState.image = null; cropState.fileInput = null; cropState.outputInput = null; };
const applyCrop = async () => {
  const { image, box, canvasWidth, outputInput, fileInput } = cropState;
  if (!image || !box) return;
  const sourceScale = image.width / canvasWidth;
  const output = document.createElement('canvas');
  const sourceWidth = Math.round(box.w * sourceScale);
  const sourceHeight = Math.round(box.h * sourceScale);
  const outputScale = Math.min(1, 1600 / Math.max(sourceWidth, sourceHeight));
  output.width = Math.max(1, Math.round(sourceWidth * outputScale));
  output.height = Math.max(1, Math.round(sourceHeight * outputScale));
  output.getContext('2d').drawImage(image, Math.round(box.x * sourceScale), Math.round(box.y * sourceScale), sourceWidth, sourceHeight, 0, 0, output.width, output.height);
  const croppedDataUrl = output.toDataURL('image/jpeg', .88);
  outputInput.value = croppedDataUrl;
  const form = outputInput.closest('form');
  if (fileInput) fileInput.value = '';
  if (form) form.dispatchEvent(new Event('image-applied'));
  closeCropEditor(true);
};
const formValues = form => Object.fromEntries(new FormData(form));
const fillForm = (form, values) => form.querySelectorAll('[name]').forEach(input => { if (input.type !== 'file' && Object.prototype.hasOwnProperty.call(values,input.name)) input.value = values[input.name] ?? ''; });
const valuesWithImage = async form => {
  const values = formValues(form);
  const fileInput = form.querySelector('input[name="hero_image_file"]');
  if (fileInput?.files[0]) values.hero_image = await fileToDataUrl(fileInput.files[0]);
  delete values.hero_image_file;

  const logoFileInput = form.querySelector('input[name="logo_image_file"]');
  if (logoFileInput?.files[0]) {
    values.logo_image = await fileToDataUrl(logoFileInput.files[0]);
  } else {
    values.logo_image = $('#logoImageHidden')?.value || '';
  }
  delete values.logo_image_file;

  const logoDarkFileInput = form.querySelector('input[name="logo_dark_image_file"]');
  if (logoDarkFileInput?.files[0]) {
    values.logo_dark_image = await fileToDataUrl(logoDarkFileInput.files[0]);
  } else {
    values.logo_dark_image = $('#logoDarkImageHidden')?.value || '';
  }
  delete values.logo_dark_image_file;

  values.logo_type = $('#logoTypeInput')?.value || 'image';
  values.logo_height = $('#logoHeightHidden')?.value || '38';
  values.logo_dark_height = $('#logoDarkHeightHidden')?.value || '42';
  values.logo_dark_invert = $('#logoDarkInvertHidden')?.value === 'true' ? 'true' : 'false';

  if (values.logo_type === 'text' && values.brand_name_alt) {
    values.brand_name = values.brand_name_alt;
  }
  delete values.brand_name_alt;

  return values;
};

// --- Logo Customizer Handlers (Luz, Oscuro y Texto con Sliders 20-140px) ---
const setLogoMode = mode => {
  const typeInput = $('#logoTypeInput');
  const isText = mode === 'text';
  if (typeInput) typeInput.value = isText ? 'text' : 'image';

  document.querySelectorAll('.logo-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.logoMode === mode);
  });

  const lightPanel = $('#logoLightPanel');
  const darkPanel = $('#logoDarkPanel');
  const textPanel = $('#logoTextPanel');

  if (lightPanel) lightPanel.style.display = mode === 'light' ? 'block' : 'none';
  if (darkPanel) darkPanel.style.display = mode === 'dark' ? 'block' : 'none';
  if (textPanel) textPanel.style.display = mode === 'text' ? 'block' : 'none';
};

const syncLightHeight = val => {
  const v = String(val || 38);
  const hidden = $('#logoHeightHidden');
  const range = $('#logoHeightRange');
  const num = $('#logoHeightNum');
  const label = $('#logoHeightVal');
  const img = $('#logoImgPreview');

  if (hidden) hidden.value = v;
  if (range) range.value = v;
  if (num) num.value = v;
  if (label) label.textContent = `${v}px`;
  if (img) img.style.height = `${v}px`;
};

const syncDarkHeight = val => {
  const v = String(val || 42);
  const hidden = $('#logoDarkHeightHidden');
  const range = $('#logoDarkHeightRange');
  const num = $('#logoDarkHeightNum');
  const label = $('#logoDarkHeightVal');
  const img = $('#logoDarkImgPreview');

  if (hidden) hidden.value = v;
  if (range) range.value = v;
  if (num) num.value = v;
  if (label) label.textContent = `${v}px`;
  if (img) img.style.height = `${v}px`;
};

const setDarkInvert = inverted => {
  const isInverted = inverted === true || inverted === 'true';
  const hidden = $('#logoDarkInvertHidden');
  const chk = $('#logoDarkAutoInvert');
  const img = $('#logoDarkImgPreview');

  if (hidden) hidden.value = isInverted ? 'true' : 'false';
  if (chk) chk.checked = isInverted;
  if (img) img.style.filter = isInverted ? 'brightness(0) invert(1)' : 'none';
};

const updateLogoLightUI = dataUrl => {
  const hidden = $('#logoImageHidden');
  const preview = $('#logoImgPreview');
  const previewBox = $('#logoLightPreviewWrap');
  const promptBox = $('#logoLightPrompt');
  const height = $('#logoHeightHidden')?.value || '38';

  if (dataUrl) {
    if (hidden) hidden.value = dataUrl;
    if (preview) {
      preview.src = dataUrl;
      preview.style.height = `${height}px`;
    }
    if (previewBox) previewBox.style.display = 'block';
    if (promptBox) promptBox.style.display = 'none';
  } else {
    if (hidden) hidden.value = '';
    if (preview) preview.src = '';
    if (previewBox) previewBox.style.display = 'none';
    if (promptBox) promptBox.style.display = 'block';
    const fileInput = $('#logoFileInput');
    if (fileInput) fileInput.value = '';
  }
};

const updateLogoDarkUI = dataUrl => {
  const hidden = $('#logoDarkImageHidden');
  const preview = $('#logoDarkImgPreview');
  const previewBox = $('#logoDarkPreviewWrap');
  const promptBox = $('#logoDarkPrompt');
  const height = $('#logoDarkHeightHidden')?.value || '42';
  const isInverted = $('#logoDarkInvertHidden')?.value === 'true';

  if (dataUrl) {
    if (hidden) hidden.value = dataUrl;
    if (preview) {
      preview.src = dataUrl;
      preview.style.height = `${height}px`;
      preview.style.filter = isInverted ? 'brightness(0) invert(1)' : 'none';
    }
    if (previewBox) previewBox.style.display = 'block';
    if (promptBox) promptBox.style.display = 'none';
  } else {
    if (hidden) hidden.value = '';
    if (preview) preview.src = '';
    if (previewBox) previewBox.style.display = 'none';
    if (promptBox) promptBox.style.display = 'block';
    const fileInput = $('#logoDarkFileInput');
    if (fileInput) fileInput.value = '';
  }
};

const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));

const updateLogoTextPreview = () => {
  const text = ($('#logoTextInput')?.value || 'smartisp').trim();
  const previewEl = $('#logoTextLivePreview');
  if (!previewEl) return;
  if (!text) {
    previewEl.innerHTML = `smart<b style="color:#087ea4;">isp</b><span style="color:#f5a524;">.</span>`;
    return;
  }
  const mid = Math.ceil(text.length / 2);
  const part1 = escapeHtml(text.slice(0, mid));
  const part2 = escapeHtml(text.slice(mid));
  previewEl.innerHTML = `${part1}<b style="color:#087ea4;">${part2}</b><span style="color:#f5a524;">.</span>`;
};

const syncLogoSettings = values => {
  const mode = values.logo_type === 'text' ? 'text' : 'light';
  setLogoMode(mode);

  syncLightHeight(values.logo_height || '38');
  syncDarkHeight(values.logo_dark_height || '42');
  setDarkInvert(values.logo_dark_invert === 'true' || values.logo_dark_invert === true);

  if ($('#brandNameInput')) $('#brandNameInput').value = values.brand_name || '';
  if ($('#brandNameAltInput')) $('#brandNameAltInput').value = values.brand_name || '';
  if ($('#logoTextInput')) $('#logoTextInput').value = values.logo_text || '';

  updateLogoLightUI(values.logo_image || '');
  updateLogoDarkUI(values.logo_dark_image || '');
  updateLogoTextPreview();
};

let logoEventsInitialized = false;
const initLogoCustomizerEvents = () => {
  if (logoEventsInitialized) return;
  logoEventsInitialized = true;

  document.querySelectorAll('.logo-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      setLogoMode(tab.dataset.logoMode);
    });
  });

  // Handlers de logo claro
  $('#chooseLogoBtn')?.addEventListener('click', () => $('#logoFileInput')?.click());
  $('#changeLogoBtn')?.addEventListener('click', () => $('#logoFileInput')?.click());
  $('#deleteLogoBtn')?.addEventListener('click', () => updateLogoLightUI(''));

  $('#logoHeightRange')?.addEventListener('input', e => syncLightHeight(e.target.value));
  $('#logoHeightNum')?.addEventListener('input', e => syncLightHeight(e.target.value));

  $('#logoFileInput')?.addEventListener('change', async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      if (!file.type.startsWith('image/')) throw new Error('Selecciona un archivo de imagen válido (PNG recomendado).');
      if (file.size > MAX_IMAGE_SIZE) throw new Error('El logotipo no puede superar 3 MB.');
      const dataUrl = await fileToDataUrl(file);
      updateLogoLightUI(dataUrl);
    } catch (err) {
      event.target.value = '';
      showMessage($('#contentMessage'), err.message, true);
    }
  });

  const dropZoneLight = $('#logoLightDropZone');
  if (dropZoneLight) {
    ['dragenter', 'dragover'].forEach(eventName => {
      dropZoneLight.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); dropZoneLight.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(eventName => {
      dropZoneLight.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); dropZoneLight.classList.remove('drag-over'); });
    });
    dropZoneLight.addEventListener('drop', async e => {
      const file = e.dataTransfer?.files?.[0];
      if (!file) return;
      try {
        if (!file.type.startsWith('image/')) throw new Error('Selecciona un archivo de imagen válido (PNG recomendado).');
        if (file.size > MAX_IMAGE_SIZE) throw new Error('El logotipo no puede superar 3 MB.');
        const dataUrl = await fileToDataUrl(file);
        updateLogoLightUI(dataUrl);
      } catch (err) {
        showMessage($('#contentMessage'), err.message, true);
      }
    });
  }

  // Handlers de logo oscuro
  $('#chooseLogoDarkBtn')?.addEventListener('click', () => $('#logoDarkFileInput')?.click());
  $('#changeLogoDarkBtn')?.addEventListener('click', () => $('#logoDarkFileInput')?.click());
  $('#deleteLogoDarkBtn')?.addEventListener('click', () => updateLogoDarkUI(''));

  $('#logoDarkHeightRange')?.addEventListener('input', e => syncDarkHeight(e.target.value));
  $('#logoDarkHeightNum')?.addEventListener('input', e => syncDarkHeight(e.target.value));

  $('#logoDarkAutoInvert')?.addEventListener('change', e => setDarkInvert(e.target.checked));

  $('#btnCopyLightToDark')?.addEventListener('click', () => {
    const lightData = $('#logoImageHidden')?.value || '';
    if (!lightData) {
      showMessage($('#contentMessage'), 'Primero sube o selecciona un logotipo para fondo claro.', true);
      return;
    }
    updateLogoDarkUI(lightData);
    showMessage($('#contentMessage'), 'Logotipo de menú claro copiado al fondo oscuro.');
  });

  $('#logoDarkFileInput')?.addEventListener('change', async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      if (!file.type.startsWith('image/')) throw new Error('Selecciona un archivo de imagen válido (PNG recomendado).');
      if (file.size > MAX_IMAGE_SIZE) throw new Error('El logotipo no puede superar 3 MB.');
      const dataUrl = await fileToDataUrl(file);
      updateLogoDarkUI(dataUrl);
    } catch (err) {
      event.target.value = '';
      showMessage($('#contentMessage'), err.message, true);
    }
  });

  const dropZoneDark = $('#logoDarkDropZone');
  if (dropZoneDark) {
    ['dragenter', 'dragover'].forEach(eventName => {
      dropZoneDark.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); dropZoneDark.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(eventName => {
      dropZoneDark.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); dropZoneDark.classList.remove('drag-over'); });
    });
    dropZoneDark.addEventListener('drop', async e => {
      const file = e.dataTransfer?.files?.[0];
      if (!file) return;
      try {
        if (!file.type.startsWith('image/')) throw new Error('Selecciona un archivo de imagen válido (PNG recomendado).');
        if (file.size > MAX_IMAGE_SIZE) throw new Error('El logotipo no puede superar 3 MB.');
        const dataUrl = await fileToDataUrl(file);
        updateLogoDarkUI(dataUrl);
      } catch (err) {
        showMessage($('#contentMessage'), err.message, true);
      }
    });
  }

  // Handlers de texto y nombre comercial
  $('#logoTextInput')?.addEventListener('input', updateLogoTextPreview);

  $('#brandNameInput')?.addEventListener('input', e => {
    const alt = $('#brandNameAltInput');
    if (alt) alt.value = e.target.value;
  });
  $('#brandNameAltInput')?.addEventListener('input', e => {
    const main = $('#brandNameInput');
    if (main) main.value = e.target.value;
  });
};

const toUtf8Base64 = str => {
  try {
    const bytes = new TextEncoder().encode(str);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
  } catch { return null; }
};
const api = async (action, options = {}) => {
  const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
  const isForm = (options.body instanceof FormData);
  const customHeaders = {};
  if (!isForm) {
    customHeaders['Content-Type'] = 'application/json';
  }
  if (token) {
    customHeaders['Authorization'] = `Bearer ${token}`;
    customHeaders['X-Admin-Token'] = token;
  }
  const reqHeaders = { ...customHeaders, ...(options.headers || {}) };

  let cleanAction = action.replace(/^\/?api\/auth\//, '').replace(/^\/?api\//, '');
  let baseAction = cleanAction;
  let queryPart = '';
  if (cleanAction.includes('?')) {
    const parts = cleanAction.split('?');
    baseAction = parts[0];
    queryPart = '&' + parts[1];
  }
  let url = `/api/router.php?action=${encodeURIComponent(baseAction)}${queryPart}`;

  let finalBody = options.body;
  if (!isForm && finalBody && typeof finalBody === 'string' && (options.method === 'POST' || options.method === 'PUT')) {
    try {
      const parsed = JSON.parse(finalBody);
      if (!parsed.payload && typeof toUtf8Base64 === 'function') {
        const b64 = toUtf8Base64(finalBody);
        if (b64) {
          finalBody = JSON.stringify({ payload: b64 });
        }
      }
    } catch (e) {}
  }

  let response = null;
  try {
    response = await fetch(url, { credentials: 'include', ...options, headers: reqHeaders, body: finalBody });
  } catch (e) { response = null; }

  // Contingencia WAF: Si el servidor devuelve 403 Forbidden en POST/PUT, reintentar con el formato alternativo
  if (response && response.status === 403 && !response.headers.get('Content-Type')?.includes('application/json') && (options.method === 'POST' || options.method === 'PUT')) {
    try {
      if (isForm && options.body instanceof FormData) {
        const obj = {};
        for (const [k, v] of options.body.entries()) {
          obj[k] = v;
        }
        const b64 = (typeof toUtf8Base64 === 'function') ? toUtf8Base64(JSON.stringify(obj)) : null;
        if (b64) {
          const retryRes = await fetch(url, {
            credentials: 'include',
            method: options.method,
            headers: { ...reqHeaders, 'Content-Type': 'application/json' },
            body: JSON.stringify({ payload: b64 })
          });
          if (retryRes && retryRes.ok) {
            response = retryRes;
          }
        }
      } else if (!isForm && typeof options.body === 'string') {
        try {
          const parsed = JSON.parse(options.body);
          const dataObj = parsed.payload ? JSON.parse(atob(parsed.payload)) : parsed;
          const retryFd = new FormData();
          for (const [k, v] of Object.entries(dataObj)) {
            if (v !== undefined && v !== null) retryFd.append(k, String(v));
          }
          const cleanH = { ...reqHeaders };
          delete cleanH['Content-Type'];
          const retryRes = await fetch(url, {
            credentials: 'include',
            method: options.method,
            headers: cleanH,
            body: retryFd
          });
          if (retryRes && retryRes.ok) {
            response = retryRes;
          }
        } catch (e) {}
      }
    } catch (retryErr) {}
  }

  if (!response) throw new Error('No se pudo conectar con el servidor.');
  const text = await response.text();
  let result = {};
  try {
    result = text ? JSON.parse(text) : {};
  } catch {
    let clean = (text || '')
      .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
      .replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
      .replace(/<[^>]*>?/gm, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (clean.length > 150) clean = clean.substring(0, 150) + '...';
    result = { error: clean || `Respuesta inválida (${response.status})` };
  }
  if (result && result.token) {
    localStorage.setItem('smartisp.adminToken', result.token);
  }
  if (!response.ok) { const error = new Error(result.error || 'No se pudo completar la operación.'); error.status = response.status; throw error; }
  return result;
};
const updateDesignPreview = () => { const form = $('#contentForm'); const values = formValues(form); const preview = $('.design-preview-hero'); preview.style.setProperty('--preview-image', values.hero_image ? `url("${values.hero_image}")` : 'none'); preview.style.setProperty('--preview-accent', values.primary_color || '#087ea4'); preview.style.setProperty('--preview-text', values.hero_text_color || '#102c3d'); preview.style.setProperty('--preview-width', `${values.hero_content_width || 510}px`); preview.style.setProperty('--preview-overlay', `rgba(228,245,243,${Number(values.hero_overlay_opacity || 0) / 100})`); preview.style.minHeight = `${values.hero_height || 370}px`; preview.style.backgroundPosition = values.hero_image_position || 'center'; form.querySelectorAll('[data-preview]').forEach(element => { element.textContent = values[element.dataset.preview] || ''; }); form.querySelectorAll('[data-range-value]').forEach(element => { element.textContent = `${values[element.dataset.rangeValue] || 0}%`; }); };

const updateProviderPanels = provider => {
  const p = provider || 'gmail';
  const h = $('#hostingerConfigSection');
  const g = $('#gmailConfigSection');
  const c = $('#customSmtpSection');
  const r = $('#resendSection');
  if (h) h.classList.toggle('hidden', p !== 'hostinger');
  if (g) g.classList.toggle('hidden', p !== 'gmail');
  if (c) c.classList.toggle('hidden', p !== 'custom');
  if (r) r.classList.toggle('hidden', p !== 'resend');
};

// Valores pesados que no se pudieron leer: al guardar no se envían para no pisar el valor real.
const unloadedHeavy = {};

const loadContent = async () => {
  const result = await api('admin-content');
  const values = { ...DEFAULT_CONTENT, ...Object.fromEntries(result.content.map(item => [item.key, item.value])) };
  for(const key of ['logo_image','logo_dark_image'])if(!values[key]||/\/assets\/img\/logo\.(png|webp)(\?|$)/.test(values[key]))values[key]=DEFAULT_CONTENT[key];

  // Valores pesados (logos en base64) ya no vienen en la respuesta de arriba (DEV-20261005-022):
  // se piden aparte, en paralelo, solo ahora que se abre el panel de Apariencia/Contenido.
  const heavyKeys = result.content.filter(item => item.truncated).map(item => item.key);
  if (heavyKeys.length) {
    const heavyValues = await Promise.all(heavyKeys.map(key =>
      api(`admin-content?key=${encodeURIComponent(key)}`).then(r => [key, r.value || '', true]).catch(() => [key, null, false])
    ));
    const failed = [];
    heavyValues.forEach(([key, value, ok]) => {
      if (ok) { values[key] = value; delete unloadedHeavy[key]; return; }
      unloadedHeavy[key] = values[key] ?? '';
      failed.push(key);
    });
    if (failed.length) showMessage($('#contentMessage'), `No se pudo cargar ${failed.join(', ')}. Al guardar se conservará el valor actual; recarga la página para cambiarlo.`, true);
  }
  document.querySelectorAll('#contentForm [name]').forEach(input => { if (input.type !== 'file' && values[input.name] !== undefined) input.value = values[input.name]; });
  document.querySelectorAll('#visibilityForm [name]').forEach(input => { if (values[input.name] !== undefined) input.value = values[input.name]; });

  const notifForm = $('#notificationsForm');
  if (notifForm) {
    if (values.admin_email !== undefined) notifForm.querySelector('[name="admin_email"]').value = values.admin_email;
    const prov = values.smtp_provider || 'gmail';
    const sel = notifForm.querySelector('#smtpProviderSelect');
    if (sel) sel.value = prov;
    updateProviderPanels(prov);

    const hUser = notifForm.querySelector('#hostingerUser');
    const hPass = notifForm.querySelector('#hostingerPass');
    if (hUser) hUser.value = (prov === 'hostinger' ? (values.smtp_user || '') : '');
    if (hPass) hPass.value = (prov === 'hostinger' ? (values.smtp_pass || '') : '');

    const gUser = notifForm.querySelector('#gmailUser');
    const gPass = notifForm.querySelector('#gmailPass');
    if (gUser) gUser.value = (prov === 'gmail' ? (values.smtp_user || '') : '');
    if (gPass) gPass.value = (prov === 'gmail' ? (values.smtp_pass || '') : '');

    const sHost = notifForm.querySelector('[name="smtp_host"]');
    const sPort = notifForm.querySelector('[name="smtp_port"]');
    const sUser = notifForm.querySelector('[name="smtp_custom_user"]');
    const sPass = notifForm.querySelector('[name="smtp_custom_pass"]');
    const sFrom = notifForm.querySelector('[name="smtp_from"]');
    const sSec = notifForm.querySelector('[name="smtp_secure"]');
    if (sHost) sHost.value = values.smtp_host || '';
    if (sPort) sPort.value = values.smtp_port || '465';
    if (sUser) sUser.value = values.smtp_user || '';
    if (sPass) sPass.value = values.smtp_pass || '';
    if (sFrom) sFrom.value = values.smtp_from || '';
    if (sSec) sSec.checked = values.smtp_secure === 'true';

    const rKey = notifForm.querySelector('[name="resend_api_key"]');
    const eFrom = notifForm.querySelector('[name="email_from"]');
    if (rKey) rKey.value = values.resend_api_key || '';
    if (eFrom) eFrom.value = values.email_from || '';
  }
  syncLogoSettings(values);
  updateDesignPreview();
};

// DEV-20261006-001: saveForm nunca estaba definida en admin.html (bug preexistente heredado
// de la fusión original); los botones "Guardar apariencia"/"Guardar visibilidad" y sus resets
// lanzaban un ReferenceError silencioso capturado por el catch. Se define aquí tal como el
// resto del código ya la invocaba: contentForm necesita valuesWithImage (archivos/logos),
// visibilityForm son solo valores planos.
const saveForm = async (form, messageEl, successText) => {
  const button=form.querySelector('[type="submit"]');
  if(button?.disabled)return;
  if(button)button.disabled=true;
  try {
    const allValues = form.id === 'contentForm' && form.dataset.settingsSection !== 'contact' ? await valuesWithImage(form) : formValues(form);
    const values = window.smartispSettingsPayload ? window.smartispSettingsPayload(form,allValues) : allValues;
    for (const [key, placeholder] of Object.entries(unloadedHeavy)) if (key in values && String(values[key] ?? '') === String(placeholder)) delete values[key];
    await api('admin-content', { method: 'POST', body: JSON.stringify(values) });
    for (const key of Object.keys(unloadedHeavy)) if (key in values) delete unloadedHeavy[key];
    showMessage(messageEl, successText);
    form.dispatchEvent(new CustomEvent('settings-saved'));
  } finally { if(button)button.disabled=false; }
};

const isUserAdmin = u => {
  if (!u) return false;
  return ['admin', 'administrator', 'administrador'].includes(String(u.role || '').trim().toLowerCase());
};

const openPanel = async user => {
  $('#loginPanel').classList.add('hidden');
  $('#adminPanel').classList.remove('hidden');
  $('#adminIdentity').textContent = `${user.name} ${user.surname || ''} - ${user.email}`;
  initLogoCustomizerEvents();
  await loadContent();
  document.dispatchEvent(new CustomEvent('settings-loaded'));
};

$('#loginForm').addEventListener('submit', async event => {
  event.preventDefault();
  try {
    const values = Object.fromEntries(new FormData(event.currentTarget));
    const remember = values.remember === 'on';
    const result = await api('login', { method: 'POST', body: JSON.stringify(values) });
    if (!isUserAdmin(result.user)) { showDenied(result.user?.email); return; }
    if (result.token) {
      (remember ? localStorage : sessionStorage).setItem('smartisp.adminToken', result.token);
    }
    const displayUser = { id: result.user?.id, name: result.user?.name, email: result.user?.email };
    (remember ? localStorage : sessionStorage).setItem('smartisp.accountUser', JSON.stringify(displayUser));
    if (remember) localStorage.setItem('smartisp.accountRemembered', 'true');
    else localStorage.removeItem('smartisp.accountRemembered');
    await openPanel(result.user);
  } catch (error) {
    showMessage($('#loginMessage'), error.message, true);
  }
});
$('#contentForm').addEventListener('submit', async event => { event.preventDefault(); try { await saveForm(event.currentTarget, $('#contentMessage'), 'Apariencia guardada correctamente.'); } catch (error) { showMessage($('#contentMessage'), error.message, true); } });
$('#contentForm').addEventListener('input', updateDesignPreview);
$('#contentForm').addEventListener('change', updateDesignPreview);
$('#contentForm').addEventListener('image-applied', updateDesignPreview);
$('#visibilityForm').addEventListener('submit', async event => { event.preventDefault(); try { await saveForm(event.currentTarget, $('#visibilityMessage'), 'Visibilidad guardada correctamente.'); } catch (error) { showMessage($('#visibilityMessage'), error.message, true); } });
$('#smtpProviderSelect')?.addEventListener('change', event => {
  updateProviderPanels(event.target.value);
});

$('#toggleGmailPass')?.addEventListener('click', () => {
  const input = $('#gmailPass');
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  $('#toggleGmailPass').innerHTML = isPass ? '<i data-lucide="lock"></i>' : '<i data-lucide="eye"></i>';
  if (window.lucide) window.lucide.createIcons();
});

$('#toggleHostingerPass')?.addEventListener('click', () => {
  const input = $('#hostingerPass');
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  $('#toggleHostingerPass').innerHTML = isPass ? '<i data-lucide="lock"></i>' : '<i data-lucide="eye"></i>';
  if (window.lucide) window.lucide.createIcons();
});

const getNotificationPayload = () => {
  const form = $('#notificationsForm');
  if (!form) return {};
  const provider = $('#smtpProviderSelect')?.value || 'gmail';
  const raw = Object.fromEntries(new FormData(form));
  const payload = {
    admin_email: String(raw.admin_email || '').trim(),
    smtp_provider: provider
  };

  if (provider === 'hostinger') {
    payload.smtp_user = String($('#hostingerUser')?.value || '').trim();
    payload.smtp_pass = String($('#hostingerPass')?.value || '').trim();
    payload.smtp_host = 'smtp.hostinger.com';
    payload.smtp_port = '465';
    payload.smtp_secure = 'true';
    payload.smtp_from = payload.smtp_user ? `SmartISP <${payload.smtp_user}>` : '';
  } else if (provider === 'gmail') {
    payload.smtp_user = String($('#gmailUser')?.value || '').trim();
    payload.smtp_pass = String($('#gmailPass')?.value || '').replace(/\s+/g, '');
    payload.smtp_host = 'smtp.gmail.com';
    payload.smtp_port = '465';
    payload.smtp_secure = 'true';
    payload.smtp_from = payload.smtp_user ? `SmartISP <${payload.smtp_user}>` : '';
  } else if (provider === 'custom') {
    payload.smtp_host = String(raw.smtp_host || '').trim();
    payload.smtp_port = String(raw.smtp_port || '465').trim();
    payload.smtp_user = String(raw.smtp_custom_user || '').trim();
    payload.smtp_pass = String(raw.smtp_custom_pass || '').trim();
    payload.smtp_secure = form.querySelector('[name="smtp_secure"]')?.checked ? 'true' : 'false';
    payload.smtp_from = String(raw.smtp_from || '').trim();
  } else if (provider === 'resend') {
    payload.resend_api_key = String($('#resendApiKey')?.value || raw.resend_api_key || '').trim();
    payload.email_from = String($('#emailFromInput')?.value || raw.email_from || '').trim();
  }
  return payload;
};

$('#notificationsForm').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const button = form.querySelector('[type="submit"]');
  if (button?.disabled) return;
  if (button) button.disabled = true;
  const payload = getNotificationPayload();

  try {
    await api('admin-content', { method: 'POST', body: JSON.stringify(payload) });
    showMessage($('#notificationsMessage'), 'Configuración de correo guardada correctamente.');
    form.dispatchEvent(new CustomEvent('settings-saved'));
  } catch (error) {
    showMessage($('#notificationsMessage'), error.message, true);
  } finally {
    if (button) button.disabled = false;
  }
});

$('#testEmailButton')?.addEventListener('click', async () => {
  const form = $('#notificationsForm');
  const payload = getNotificationPayload();
  const adminEmail = payload.admin_email;

  if (!adminEmail || !adminEmail.includes('@')) {
    showMessage($('#notificationsMessage'), 'Ingresa primero el correo del administrador en el campo 1 de arriba.', true);
    form.querySelector('[name="admin_email"]')?.focus();
    return;
  }

  if (payload.smtp_provider === 'gmail') {
    if (!payload.smtp_user) {
      showMessage($('#notificationsMessage'), 'Para enviar correos por Gmail, debes ingresar tu cuenta @gmail.com en el campo de abajo.', true);
      $('#gmailUser')?.focus();
      return;
    }
    if (!payload.smtp_pass) {
      showMessage($('#notificationsMessage'), 'Ingresa la Contraseña de Aplicación de 16 caracteres generada en Google (no tu clave personal de Gmail).', true);
      $('#gmailPass')?.focus();
      return;
    }
  } else if (payload.smtp_provider === 'hostinger') {
    if (!payload.smtp_user) {
      showMessage($('#notificationsMessage'), 'Para Hostinger, ingresa tu cuenta de correo corporativo (ej: gestion@smart-isp.es).', true);
      $('#hostingerUser')?.focus();
      return;
    }
    if (!payload.smtp_pass) {
      showMessage($('#notificationsMessage'), 'Ingresa la contraseña real con la que entras al correo de Hostinger (no una contraseña inventada).', true);
      $('#hostingerPass')?.focus();
      return;
    }
  } else if (payload.smtp_provider === 'resend') {
    if (!payload.resend_api_key) {
      showMessage($('#notificationsMessage'), 'Ingresa tu API Key de Resend (inicia con re_). Es gratuita en resend.com.', true);
      $('#resendApiKey')?.focus();
      return;
    }
  }

  const btn = $('#testEmailButton');
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span>⏳ Guardando y enviando correo real...</span>';
  showMessage($('#notificationsMessage'), `Conectando con el servidor de correo y despachando a ${adminEmail}...`);

  try {
    // 1. Guardar primero automáticamente
    await api('admin-content', { method: 'POST', body: JSON.stringify(payload) });

    // 2. Despachar prueba pasando credenciales en el cuerpo
    const result = await api('test-email', {
      method: 'POST',
      body: JSON.stringify({
        to: adminEmail,
        ...payload
      })
    });
    let successNote = result.message || `¡Correo de prueba enviado exitosamente a ${adminEmail}! Revisa tu bandeja de entrada o spam.`;
    if (result.transport === 'resend') {
      successNote = `¡Excelente! Correo de prueba despachado de inmediato vía Resend REST API a ${adminEmail}. Revisa tu bandeja de entrada.`;
    } else if (result.transport === 'smtp') {
      successNote = `¡Excelente! Conexión SMTP verificada y correo despachado con éxito a ${adminEmail}.`;
    }
    showMessage($('#notificationsMessage'), successNote);
  } catch (error) {
    showMessage($('#notificationsMessage'), error.message || 'Error al enviar correo de prueba.', true);
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
});
$('#resetContentButton').addEventListener('click', () => { const form = $('#contentForm'); const defaults=window.smartispSettingsPayload?window.smartispSettingsPayload(form,DEFAULT_CONTENT):DEFAULT_CONTENT;fillForm(form,defaults);if(defaults.logo_image!==undefined){$('#logoFileInput').value='';$('#logoDarkFileInput').value='';syncLogoSettings(DEFAULT_CONTENT);}updateDesignPreview();form.dispatchEvent(new Event('input',{bubbles:true}));showMessage($('#contentMessage'),'Valores predeterminados preparados. Guarda para aplicarlos.'); });
$('#resetVisibilityButton').addEventListener('click', () => { const form = $('#visibilityForm'); fillForm(form, DEFAULT_VISIBILITY); form.dispatchEvent(new Event('input', { bubbles: true })); showMessage($('#visibilityMessage'), 'Valores predeterminados preparados. Guarda para aplicarlos.'); });
$('#clearContentButton').addEventListener('click', () => $('#resetContentButton').click());
$('#adminPanel').addEventListener('change', event => { const input = event.target; if (input.type !== 'file' || !input.files[0]) return; if (input.name === 'logo_image_file' || input.name === 'logo_dark_image_file') return; const form = input.closest('form'); const outputInput = form.querySelector('[name="hero_image"]'); if (!outputInput) return; try { openCropEditor(input.files[0], input, outputInput); } catch (error) { input.value = ''; showMessage($('#contentMessage'), error.message, true); } });
$('#cropAspect').addEventListener('change', updateCropBox);
$('#cropBox').addEventListener('pointerdown', event => { cropState.dragging = true; cropState.resizeHandle = event.target.dataset.resize || ''; cropState.startX = event.clientX; cropState.startY = event.clientY; cropState.startBoxX = cropState.box.x; cropState.startBoxY = cropState.box.y; cropState.startBoxW = cropState.box.w; cropState.startBoxH = cropState.box.h; event.currentTarget.setPointerCapture(event.pointerId); });
$('#cropBox').addEventListener('pointermove', event => { if (!cropState.dragging) return; const box = cropState.box; const dx = event.clientX - cropState.startX; const dy = event.clientY - cropState.startY; const handle = cropState.resizeHandle; if (handle) { if (handle.includes('e')) box.w = Math.max(40, Math.min(cropState.canvasWidth - box.x, cropState.startBoxW + dx)); if (handle.includes('s')) box.h = Math.max(40, Math.min(cropState.canvasHeight - box.y, cropState.startBoxH + dy)); if (handle.includes('w')) { const nextX = Math.max(0, Math.min(cropState.startBoxX + cropState.startBoxW - 40, cropState.startBoxX + dx)); box.x = nextX; box.w = cropState.startBoxW + cropState.startBoxX - nextX; } if (handle.includes('n')) { const nextY = Math.max(0, Math.min(cropState.startBoxY + cropState.startBoxH - 40, cropState.startBoxY + dy)); box.y = nextY; box.h = cropState.startBoxH + cropState.startBoxY - nextY; } } else { box.x = Math.max(0, Math.min(cropState.canvasWidth - box.w, cropState.startBoxX + dx)); box.y = Math.max(0, Math.min(cropState.canvasHeight - box.h, cropState.startBoxY + dy)); } event.currentTarget.style.left = `${cropState.offsetX + box.x}px`; event.currentTarget.style.top = `${cropState.offsetY + box.y}px`; event.currentTarget.style.width = `${box.w}px`; event.currentTarget.style.height = `${box.h}px`; });
$('#cropBox').addEventListener('pointerup', () => { cropState.dragging = false; });
$('#cancelCropButton')?.addEventListener('click', () => closeCropEditor(true));
$('#applyCropButton')?.addEventListener('click', applyCrop);

// El logout ya lo provee el navbar compartido (assets/js/admin/admin-nav.js, .admin-nav-logout).

$('#switchAccountBtn')?.addEventListener('click', async () => {
  try { await api('logout', { method: 'POST' }); } catch (e) {}
  localStorage.removeItem('smartisp.accountUser');
  localStorage.removeItem('smartisp.accountRemembered');
  localStorage.removeItem('smartisp.adminToken');
  sessionStorage.removeItem('smartisp.accountUser');
  sessionStorage.removeItem('smartisp.adminToken');
  $('#accessDeniedPanel').classList.add('hidden');
  $('#loginPanel').classList.remove('hidden');
});

const showDenied = (email) => {
  $('#deniedUserEmail').textContent = email || 'cliente';
  $('#loginPanel').classList.add('hidden');
  const ap = $('#adminPanel');
  if (ap) ap.remove(); // Elimina el dashboard del DOM para evitar exposición por manipulación de CSS (QA-027)
  $('#accessDeniedPanel').classList.remove('hidden');
};

const checkAuthAndInit = async () => {
  try {
    const res = await api('me');
    if (isUserAdmin(res.user)) await openPanel(res.user);
    else showDenied(res.user?.email);
  } catch (e) {
    const ap = $('#adminPanel');
    if (ap) ap.classList.add('hidden');
    $('#loginPanel').classList.remove('hidden');
    if (e.status && e.status !== 401) showMessage($('#loginMessage'), e.message, true);
  }
};

if (window.lucide) window.lucide.createIcons();
checkAuthAndInit();
