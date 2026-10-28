    // --- ESTADO GLOBAL ---
    let categoriesList = [];
    let productFamilies = [];
    let productsList = []; // solo la página actual (DEV-20261005-022: paginado real, ya no el catálogo completo)
    let currentProductSpecs = [];
    let currentProductGallery = [];

    const escapeHtmlEditor = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

    const renderSpecsEditor = () => {
      const list = document.getElementById('prodSpecsList');
      if (!list) return;
      list.innerHTML = currentProductSpecs.map((spec, index) => `
        <div style="display:flex; gap:6px;" data-spec-row="${index}">
          <input type="text" class="form-control" style="flex:1;" placeholder="Ej: Potencia" value="${escapeHtmlEditor(spec.label)}" data-spec-field="label">
          <input type="text" class="form-control" style="flex:1;" placeholder="Ej: 650W" value="${escapeHtmlEditor(spec.value)}" data-spec-field="value">
          <button type="button" class="btn btn-secondary btn-sm" data-remove-spec="${index}" title="Quitar"><i data-lucide="x" width="14"></i></button>
        </div>
      `).join('');
      window.lucide?.createIcons();
    };

    document.getElementById('prodSpecsList')?.addEventListener('input', e => {
      const row = e.target.closest('[data-spec-row]');
      if (!row) return;
      const index = Number(row.dataset.specRow);
      const field = e.target.dataset.specField;
      if (currentProductSpecs[index] && field) currentProductSpecs[index][field] = e.target.value;
    });
    document.getElementById('prodSpecsList')?.addEventListener('click', e => {
      const btn = e.target.closest('[data-remove-spec]');
      if (!btn) return;
      currentProductSpecs.splice(Number(btn.dataset.removeSpec), 1);
      renderSpecsEditor();
    });
    document.getElementById('btnAddSpecRow')?.addEventListener('click', () => {
      currentProductSpecs.push({ label: '', value: '' });
      renderSpecsEditor();
    });

    const renderGalleryEditor = () => {
      const list = document.getElementById('prodGalleryList');
      const addBtn = document.getElementById('btnAddGalleryImage');
      if (!list) return;
      const prodId = document.getElementById('prodId')?.value || '';
      const hasRealId = prodId && !prodId.startsWith('prod_') || productsList.some(p => String(p.id) === String(prodId));
      list.innerHTML = currentProductGallery.map(img => `
        <div style="position:relative;">
          <img src="${escapeHtmlEditor(img.image_url)}" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid var(--line);">
          <button type="button" data-remove-gallery-image="${img.id}" style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:var(--on-danger);border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;">✕</button>
        </div>
      `).join('') || '<small style="color:var(--muted);">Sin imágenes adicionales todavía.</small>';
      if (addBtn) {
        const canUpload = productsList.some(p => String(p.id) === String(prodId));
        addBtn.disabled = !canUpload;
        addBtn.title = canUpload ? '' : 'Guarda el producto primero para poder añadir imágenes a la galería.';
      }
    };

    // Si se abre otro producto antes de que llegue la galería, la respuesta vieja se descarta.
    let gallerySeq = 0;
    const loadGalleryForProduct = async productId => {
      const seq = ++gallerySeq;
      currentProductGallery = [];
      renderGalleryEditor();
      if (!productId) return;
      try {
        const res = await api(`product-images?product_id=${encodeURIComponent(productId)}`);
        if (seq !== gallerySeq) return;
        currentProductGallery = res.images || [];
      } catch (e) {
        if (seq !== gallerySeq) return;
        console.warn('No se pudo cargar la galería:', e);
      }
      renderGalleryEditor();
    };

    document.getElementById('prodGalleryList')?.addEventListener('click', async e => {
      const btn = e.target.closest('[data-remove-gallery-image]');
      if (!btn) return;
      if (!confirm('¿Quitar esta imagen de la galería del producto?')) return;
      try {
        await api(`product-images?id=${btn.dataset.removeGalleryImage}`, { method: 'DELETE' });
        currentProductGallery = currentProductGallery.filter(img => String(img.id) !== btn.dataset.removeGalleryImage);
        renderGalleryEditor();
      } catch (err) { showToast(err.message, 'error'); }
    });

    document.getElementById('btnAddGalleryImage')?.addEventListener('click', () => {
      document.getElementById('prodGalleryFileInput')?.click();
    });
    document.getElementById('prodGalleryFileInput')?.addEventListener('change', async e => {
      const files = [...(e.target.files || [])];
      if (!files.length) return;
      const productId = document.getElementById('prodId')?.value || '';
      try {
        for (const file of files) {
          const fd = new FormData();
          fd.append('image', file);
          const upRes = await api('upload-image', { method: 'POST', body: fd });
          if (upRes?.url) {
            await api('product-images', { method: 'POST', body: JSON.stringify({ product_id: productId, image_url: upRes.url }) });
          }
        }
        if ((document.getElementById('prodId')?.value || '') === productId) await loadGalleryForProduct(productId);
        showToast('Imágenes añadidas a la galería.');
      } catch (err) {
        showToast(err.message, 'error');
      } finally {
        e.target.value = '';
      }
    });
    let productsPage = 1;
    let productsPageSize = 50; // feedback: 100 por página se sentía pesado para revisar en la tabla
    let productsTotal = 0;
    let productsTotalPages = 1;
    let productsStats = { total: 0, visible: 0, with_photo: 0, without_photo: 0, by_category: [] };
    let productsStatsLoaded = false;
    let currentAdminUser = null;
    const isAdminRole = user => !!user && ['admin', 'administrator', 'administrador'].includes(String(user.role || '').trim().toLowerCase());
    let currentImportState = null;
    let targetImageProduct = null;

    // --- HELPER PARA BASE64 CON SOPORTE UTF-8 (PREVENCIÓN WAF MODSECURITY) ---
    const toUtf8Base64 = str => {
      try {
        const bytes = new TextEncoder().encode(str);
        let binary = '';
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
          binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
      } catch {
        return null;
      }
    };

    const fromUtf8Base64 = b64 => {
      try {
        const binary = atob(b64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
          bytes[i] = binary.charCodeAt(i);
        }
        return new TextDecoder().decode(bytes);
      } catch {
        return null;
      }
    };

    // --- HELPER DE LLAMADAS API CON CREDENCIALES, RUTA DIRECTA Y RESILENCIA WAF ---
    const api = async (endpoint, options = {}) => {
      const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');

      const isForm = (options.body instanceof FormData);
      const customHeaders = {};
      if (!isForm) {
        customHeaders['Content-Type'] = 'application/json';
      }

      // Construcción de ruta directa a router.php evitando depender de reescrituras de mod_rewrite
      let cleanEndpoint = endpoint.replace(/^\/?api\/auth\//, '').replace(/^\/?api\//, '');
      let baseAction = cleanEndpoint;
      let queryPart = '';
      if (cleanEndpoint.includes('?')) {
        const parts = cleanEndpoint.split('?');
        baseAction = parts[0];
        queryPart = '&' + parts[1];
      }
      let url = `/api/router.php?action=${encodeURIComponent(baseAction)}${queryPart}`;

      if (token) {
        customHeaders['Authorization'] = `Bearer ${token}`;
        customHeaders['X-Admin-Token'] = token;
      }
      const reqHeaders = { ...customHeaders, ...(options.headers || {}) };

      // Protección WAF: empaquetar automáticamente payloads JSON como Base64 para prevenir bloqueos falsos positivos de ModSecurity en Hostinger
      let finalBody = options.body;
      if (!isForm && finalBody && typeof finalBody === 'string' && (options.method === 'POST' || options.method === 'PUT')) {
        try {
          const parsed = JSON.parse(finalBody);
          if (!parsed.payload && typeof toUtf8Base64 === 'function') {
            const b64 = toUtf8Base64(JSON.stringify(parsed));
            if (b64) {
              finalBody = JSON.stringify({ payload: b64 });
            } else {
              finalBody = JSON.stringify(parsed);
            }
          } else {
            finalBody = JSON.stringify(parsed);
          }
        } catch (e) {}
      }

      let res = null;
      try {
        res = await fetch(url, {
          credentials: 'include',
          ...options,
          headers: reqHeaders,
          body: finalBody
        });
      } catch (err) {
        res = null;
      }

      // Contingencia WAF: Si el servidor devuelve 403 Forbidden en POST/PUT, reintentar con el formato alternativo
      if (res && res.status === 403 && !res.headers.get('Content-Type')?.includes('application/json') && (options.method === 'POST' || options.method === 'PUT')) {
        try {
          if (isForm && options.body instanceof FormData) {
            // Reintentar convirtiendo FormData a JSON protegido con Base64
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
                res = retryRes;
              }
            }
          } else if (!isForm && typeof options.body === 'string') {
            // Reintentar JSON empaquetado convirtiéndolo a multipart/form-data
            try {
              const parsed = JSON.parse(options.body);
              let dataObj = parsed;
              if (parsed.payload) {
                const decodedStr = fromUtf8Base64(parsed.payload) || atob(parsed.payload);
                dataObj = JSON.parse(decodedStr);
              }
              const retryFd = new FormData();
              for (const [k, v] of Object.entries(dataObj)) {
                if (v !== undefined && v !== null) {
                  retryFd.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
                }
              }
              if (parsed.payload) {
                retryFd.append('payload', parsed.payload);
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
                res = retryRes;
              }
            } catch (e) {}
          }
        } catch (retryErr) {}
      }

      if (!res) throw new Error('No se pudo conectar con el servidor.');
      const text = await res.text();
      let data = {};
      try { 
        data = text ? JSON.parse(text) : {}; 
      } catch { 
        let clean = (text || '')
          .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
          .replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
          .replace(/<[^>]*>?/gm, ' ')
          .replace(/\s+/g, ' ')
          .trim();
        if (clean.length > 150) clean = clean.substring(0, 150) + '...';
        data = { error: clean || `Error ${res.status}` }; 
      }

      if (data && data.token) {
        localStorage.setItem('smartisp.adminToken', data.token);
      }

      if (!res.ok) {
        console.warn('Respuesta de error del servidor:', res.status, data.error);
        const error = new Error(data.error || 'Ocurrió un error en la solicitud.');
        error.status = res.status;
        throw error;
      }
      return data;
    };

    // --- NOTIFICACIONES TOAST ---
    const showToast = (message, type = 'success') => {
      const toast = document.getElementById('toast');
      const text = document.getElementById('toastText');
      const icon = document.getElementById('toastIcon');
      let cleanMsg = typeof message === 'string' ? message.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim() : String(message || '');
      if (cleanMsg.length > 250) cleanMsg = cleanMsg.substring(0, 250) + '...';
      text.textContent = cleanMsg;
      toast.className = `toast-msg show ${type === 'error' ? 'toast-error' : 'toast-success'}`;
      if (icon) {
        icon.setAttribute('data-lucide', type === 'error' ? 'alert-triangle' : 'check-circle');
        lucide.createIcons();
      }
      setTimeout(() => toast.classList.remove('show'), 3500);
    };

    // --- CONTROL DE PESTAÑAS ---
    const switchTab = tabId => {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === tabId));
      lucide.createIcons();
    };

    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    // --- MODALES ---
    const modalReturnFocus = {};
    const openModal = id => {
      const modal = document.getElementById(id);
      if (modal) {
        if (!modal.classList.contains('open')) modalReturnFocus[id] = document.activeElement;
        modal.classList.add('open');
        lucide.createIcons();
        modal.querySelector('.modal-close-btn, input:not([type="hidden"]), button')?.focus();
      }
    };
    const closeModal = id => {
      const modal = document.getElementById(id);
      if (!modal || !modal.classList.contains('open')) return;
      modal.classList.remove('open');
      const back = modalReturnFocus[id];
      delete modalReturnFocus[id];
      if (back && document.contains(back)) back.focus();
    };

    // Foto del formulario de producto al abrirlo: cerrar con cambios pide confirmación.
    let productFormSaved = null;
    const productFormSnapshot = () => JSON.stringify({
      fields: [...document.querySelectorAll('#productForm input, #productForm select, #productForm textarea')]
        .filter(el => el.id && el.type !== 'file')
        .map(el => el.type === 'checkbox' ? el.checked : el.value),
      specs: currentProductSpecs
    });
    const productFormDirty = () => !!document.getElementById('productModal')?.classList.contains('open') && productFormSaved !== null && productFormSnapshot() !== productFormSaved;
    const requestCloseModal = id => {
      if (id === 'productModal' && productFormDirty() && !confirm('Hay cambios sin guardar en este producto. ¿Cerrar y descartarlos?')) return;
      closeModal(id);
    };
    document.querySelectorAll('.modal-overlay').forEach(modal => {
      modal.addEventListener('click', e => {
        if (e.target === modal) requestCloseModal(modal.id);
      });
    });
    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;
      const top = [...document.querySelectorAll('.modal-overlay.open')].pop();
      if (top) { e.preventDefault(); requestCloseModal(top.id); }
    });

    // --- FORMATEO DE MONEDA ---
    const formatMoney = amount => {
      const n = Number(amount) || 0;
      if (n <= 0) {
        return '<span class="badge-quote"><i data-lucide="file-text" width="12"></i> Solicitar cotización</span>';
      }
      return `$${n.toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    // --- MOTOR DE AFINIDAD DE CATEGORÍAS (REQUERIMIENTO CLAVE) ---
    const normalizeText = str => String(str || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    const matchCategoryAffinity = (rawCategoryStr, rawProductName, availableCats = categoriesList) => {
      const normCat = normalizeText(rawCategoryStr);
      const normProd = normalizeText(rawProductName);

      if (!availableCats || !availableCats.length) {
        return {
          category: rawCategoryStr || 'General',
          subcategory: '',
          matchedCategory: null,
          confidence: 'fallback',
          badgeText: 'Sin categorías'
        };
      }

      // 1. Análisis de texto de Categoría del Excel
      if (normCat && normCat !== 'general' && normCat !== 'otros' && normCat !== 'n a' && normCat !== 'null') {
        // 1a. Coincidencia exacta. El id va primero: conserva la etiqueta del proveedor aunque la categoría se haya renombrado.
        const exactCat = availableCats.find(cat => normalizeText(cat.id) === normCat) || availableCats.find(cat => normalizeText(cat.name) === normCat);
        if (exactCat) {
          return { category: exactCat.name, subcategory: '', matchedCategory: exactCat, confidence: 'exact', badgeText: 'Coincidencia directa' };
        }

        // 1b. Coincidencia con alguna subcategoría declarada
        for (const cat of availableCats) {
          if (Array.isArray(cat.subcategories)) {
            for (const sub of cat.subcategories) {
              if (normalizeText(sub) === normCat) {
                return { category: cat.name, subcategory: sub, matchedCategory: cat, confidence: 'exact_sub', badgeText: 'Subcategoría exacta' };
              }
            }
          }
        }

        // 1c. Coincidencia exacta con alguna palabra clave de afinidad
        for (const cat of availableCats) {
          if (Array.isArray(cat.keywords)) {
            for (const kw of cat.keywords) {
              const nKw = normalizeText(kw);
              if (nKw && (normCat === nKw || normCat.split(' ').includes(nKw))) {
                return { category: cat.name, subcategory: '', matchedCategory: cat, confidence: 'keyword', badgeText: `Afinidad: "${kw}"` };
              }
            }
          }
        }

        // 1d. Subcadena en palabras clave
        for (const cat of availableCats) {
          if (Array.isArray(cat.keywords)) {
            for (const kw of cat.keywords) {
              const nKw = normalizeText(kw);
              if (nKw.length >= 3 && (normCat.includes(nKw) || nKw.includes(normCat))) {
                return { category: cat.name, subcategory: '', matchedCategory: cat, confidence: 'keyword', badgeText: `Afinidad: "${kw}"` };
              }
            }
          }
        }
      }

      // 2. Análisis del Nombre del Producto (si la categoría en Excel vino vacía o no coincidió)
      if (normProd) {
        // 2a. Subcategorías contenidas en el nombre
        for (const cat of availableCats) {
          if (Array.isArray(cat.subcategories)) {
            for (const sub of cat.subcategories) {
              const nSub = normalizeText(sub);
              if (nSub.length >= 4 && normProd.includes(nSub)) {
                return { category: cat.name, subcategory: sub, matchedCategory: cat, confidence: 'product', badgeText: `Detectado: "${sub}"` };
              }
            }
          }
        }

        // 2b. Palabras clave de mayor longitud contenidas en el nombre
        let bestMatch = null;
        let bestLen = 0;
        let matchedKw = '';
        for (const cat of availableCats) {
          if (Array.isArray(cat.keywords)) {
            for (const kw of cat.keywords) {
              const nKw = normalizeText(kw);
              if (nKw.length >= 3) {
                const words = normProd.split(' ');
                if (words.includes(nKw) || normProd.includes(` ${nKw} `) || normProd.startsWith(`${nKw} `) || normProd.endsWith(` ${nKw}`)) {
                  if (nKw.length > bestLen) {
                    bestLen = nKw.length;
                    bestMatch = cat;
                    matchedKw = kw;
                  }
                }
              }
            }
          }
        }
        if (bestMatch) {
          return { category: bestMatch.name, subcategory: '', matchedCategory: bestMatch, confidence: 'product', badgeText: `Detectado: "${matchedKw}"` };
        }
      }

      // 3. Fallback: categoría "otros" o primera categoría
      const fallbackCat = availableCats.find(c => c.id === 'otros') || availableCats[0];
      return {
        category: rawCategoryStr || (fallbackCat ? fallbackCat.name : 'General'),
        subcategory: '',
        matchedCategory: fallbackCat,
        confidence: 'fallback',
        badgeText: rawCategoryStr ? `Texto original: "${rawCategoryStr}"` : 'General'
      };
    };

    // --- CARGA DE DATOS INICIAL ---
    // DEV-20261005-022 (panel admin): el catálogo paso de cientos a 2773+ productos y sigue
    // creciendo. Antes se traían TODOS de una sola vez (SELECT * sin límite) y se filtraba/
    // paginaba en el navegador; con miles de filas eso deja de sostenerse (la tabla y las
    // ~2700 imágenes de cada fila saturaban la pestaña de red al abrir el editor). Ahora:
    // - admin-products trae una página a la vez, filtrada en SQL con los mismos criterios
    //   que antes se aplicaban en memoria (getFilteredProducts).
    // - admin-products-stats trae los totales (tarjetas, conteo por categoría) con consultas
    //   de agregación, sin transferir las filas.
    const currentProductFilters = () => ({
      family: document.getElementById('filterFamily')?.value || 'all',
      q: (document.getElementById('productSearchInput')?.value || '').trim(),
      category: document.getElementById('filterMacroCat')?.value || 'all',
      subcategory: document.getElementById('filterSubCat')?.value || 'all',
      visible: document.getElementById('filterVisibility')?.value || 'all',
      photo: document.getElementById('filterPhoto')?.value || 'all',
      price: document.getElementById('filterPrice')?.value || 'all',
    });

    let productsPageSeq = 0;
    const loadProductsPage = async (page = productsPage) => {
      const seq = ++productsPageSeq;
      const filters = currentProductFilters();
      const params = new URLSearchParams({ page: String(page), limit: String(productsPageSize) });
      Object.entries(filters).forEach(([key, value]) => { if (value && value !== 'all') params.set(key, value); });
      const res = await api(`admin-products?${params.toString()}`);
      if (seq !== productsPageSeq) return;
      productsList = res.products || [];
      productsPage = res.page || page;
      productsTotal = res.total || 0;
      productsTotalPages = res.totalPages || 1;
      renderProductsTab();
    };

    const loadProductsStats = async () => {
      productsStats = await api('admin-products-stats');
      productsStatsLoaded = true;
      renderProductsTab();
    };
    const updateProductStats = () => {
      for(const [id,key] of [['statProductsTotal','total'],['statProductsVisible','visible'],['statProductsWithPhoto','with_photo'],['statProductsWithoutPhoto','without_photo']]) {
        const element=document.getElementById(id);if(element)element.textContent=productsStatsLoaded?(productsStats[key]??0):'—';
      }
    };

    // Llamar después de cualquier alta/edición/borrado: refresca la página visible y las
    // estadísticas (tarjetas, conteo por categoría), que ya no viven en productsList completo.
    const refreshProducts = async (resetToFirstPage = false) => {
      await Promise.all([loadProductsPage(resetToFirstPage ? 1 : productsPage), loadProductsStats()]);
    };

    const loadAllData = async () => {
      try {
        const catsRes = await api('categories');
        categoriesList = catsRes.categories || [];
        productFamilies = catsRes.macroGroups || [];
        renderCategorySelects();
        await Promise.all([loadProductsPage(1), loadProductsStats()]);
      } catch (err) {
        console.error('Error cargando catálogo:', err);
        showToast(err.message, 'error');
        const tbody = document.getElementById('productsTableBody');
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="9"><div class="state is-error catalog-state" role="alert"><i data-lucide="circle-alert"></i><strong>No se pudo cargar el catálogo</strong><span>${escapeHtmlEditor(err.message || 'Error de conexión')}</span><div class="catalog-state-actions"><button type="button" class="btn btn-secondary" onclick="location.reload()"><i data-lucide="refresh-cw" width="15"></i> Reintentar</button></div></div></td></tr>`;
          window.lucide?.createIcons();
        }
      }
    };

    // --- HELPERS SEO Y ENLACES PÚBLICOS ---
    const slugifyJs = text => {
      return String(text || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 80) || 'articulo';
    };

    const getProductPublicUrl = p => {
      if (!p || !p.id) return '#';
      const cleanId = String(p.id).replace(':', '_');
      const slug = slugifyJs(p.name);
      return `/producto/${cleanId}-${slug}`;
    };

    // --- RENDER TAB 2: PRODUCTOS ---
    const renderCategorySelects = () => {
      const macroFilter = document.getElementById('filterMacroCat');
      const subFilter = document.getElementById('filterSubCat');
      const prodCat = document.getElementById('prodCategorySelect');
      const reFrom = document.getElementById('reassignFromCategory');
      const reTo = document.getElementById('reassignToCategory');

      const familyFilter = document.getElementById('filterFamily');
      const familyValue = familyFilter.value || 'all';
      familyFilter.innerHTML = '<option value="all">Todas las familias</option>' + productFamilies.map(group => `<option value="${escapeHtmlEditor(group.id)}">${escapeHtmlEditor(group.name)}</option>`).join('') + '<option value="ungrouped">Sin familia</option>';
      familyFilter.value = [...familyFilter.options].some(option => option.value === familyValue) ? familyValue : 'all';
      const available = categoriesList.filter(cat => familyFilter.value === 'all' || (familyFilter.value === 'ungrouped' ? !cat.macroGroupId : cat.macroGroupId === familyFilter.value));
      const groupedOptions = list => [...productFamilies.map(group => ({...group, categories:list.filter(cat => cat.macroGroupId === group.id)})), {name:'Sin familia', categories:list.filter(cat => !productFamilies.some(group => group.id === cat.macroGroupId))}].filter(group => group.categories.length).map(group => `<optgroup label="${escapeHtmlEditor(group.name)}">${group.categories.map(cat => `<option value="${escapeHtmlEditor(cat.name)}">${escapeHtmlEditor(cat.name)}</option>`).join('')}</optgroup>`).join('');
      const catOptions = groupedOptions(categoriesList);

      if (macroFilter) {
        const cur = macroFilter.value;
        macroFilter.innerHTML = `<option value="all">Todas las categorías</option>${groupedOptions(available)}`;
        macroFilter.value = [...macroFilter.options].some(option => option.value === cur) ? cur : 'all';
      }
      if (prodCat) {
        const curProdCat = prodCat.value;
        prodCat.innerHTML = catOptions;
        if (curProdCat) prodCat.value = curProdCat;
      }
      if (reFrom) reFrom.innerHTML = catOptions;
      if (reTo) reTo.innerHTML = catOptions;

      updateSubcategoryOptions();
      updateProductCategoryContext();
    };

    const updateProductCategoryContext = () => {
      const cat = categoriesList.find(cat => cat.name === document.getElementById('prodCategorySelect').value);
      document.getElementById('prodCategoryFamilyInfo').textContent = cat ? `Familia: ${productFamilies.find(group => group.id === cat.macroGroupId)?.name || 'Sin familia'}` : 'Crea una categoría en Categorías para asignar este producto.';
      document.getElementById('subcatDataList').innerHTML = (cat?.subcategories || []).map(sub => `<option value="${escapeHtmlEditor(sub)}"></option>`).join('');
    };

    const updateSubcategoryOptions = () => {
      const macroVal = document.getElementById('filterMacroCat').value;
      const subFilter = document.getElementById('filterSubCat');
      if (!subFilter) return;

      const subcatSet = new Set();
      categoriesList.forEach(c => {
        const family = document.getElementById('filterFamily').value;
        if ((family === 'all' || (family === 'ungrouped' ? !c.macroGroupId : c.macroGroupId === family)) && (macroVal === 'all' || c.name === macroVal)) {
          (c.subcategories || []).forEach(s => subcatSet.add(s));
        }
      });
      // Also add any subcategories found in current products
      productsList.forEach(p => {
        if (p.subcategory && (macroVal === 'all' || p.category === macroVal)) {
          subcatSet.add(p.subcategory);
        }
      });

      const options = Array.from(subcatSet).sort().map(s => `<option value="${s}">${s}</option>`).join('');
      subFilter.innerHTML = `<option value="all">Todas las Subcategorías</option>${options}`;

      // Update datalist for product modal
      const dl = document.getElementById('subcatDataList');
      if (dl) dl.innerHTML = Array.from(subcatSet).sort().map(s => `<option value="${s}"></option>`).join('');
    };

    const getFilteredProducts = () => {
      const q = (document.getElementById('productSearchInput').value || '').toLowerCase().trim();
      const macro = document.getElementById('filterMacroCat').value;
      const sub = document.getElementById('filterSubCat').value;
      const vis = document.getElementById('filterVisibility').value;
      const photo = document.getElementById('filterPhoto').value;
      const priceFilter = document.getElementById('filterPrice')?.value || 'all';

      return productsList.filter(p => {
        if (macro !== 'all' && p.category !== macro) return false;
        if (sub !== 'all' && p.subcategory !== sub) return false;
        if (vis === 'visible' && p.visible === false) return false;
        if (vis === 'hidden' && p.visible !== false) return false;
        if (photo === 'with_photo' && !p.imageUrl) return false;
        if (photo === 'without_photo' && p.imageUrl) return false;
        if (priceFilter === 'with_price' && Number(p.price || 0) <= 0) return false;
        if (priceFilter === 'quote' && Number(p.price || 0) > 0) return false;

        if (q) {
          const matchStr = `${p.name || ''} ${p.sku || ''} ${p.category || ''} ${p.subcategory || ''} ${p.description || ''}`.toLowerCase();
          return matchStr.includes(q);
        }
        return true;
      });
    };

    const renderProductsPager = () => {
      const pager = document.getElementById('productsPager');
      if (!pager) return;
      if (productsTotalPages <= 1) { pager.innerHTML = ''; return; }
      const from = productsTotal === 0 ? 0 : (productsPage - 1) * productsPageSize + 1;
      const to = Math.min(productsPage * productsPageSize, productsTotal);
      pager.innerHTML = `
        <button type="button" class="btn btn-secondary" id="productsPagerPrev" ${productsPage <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span>${from}–${to} de ${productsTotal} · página ${productsPage} de ${productsTotalPages}</span>
        <button type="button" class="btn btn-secondary" id="productsPagerNext" ${productsPage >= productsTotalPages ? 'disabled' : ''}>Siguiente →</button>
      `;
      document.getElementById('productsPagerPrev')?.addEventListener('click', () => loadProductsPage(productsPage - 1));
      document.getElementById('productsPagerNext')?.addEventListener('click', () => loadProductsPage(productsPage + 1));
    };

    const renderProductsTab = () => {
      const tbody = document.getElementById('productsTableBody');
      const results=document.getElementById('productResults');if(results)results.textContent=`${productsTotal.toLocaleString()} resultado${productsTotal===1?'':'s'}`;
      const countTab = document.getElementById('countProdTab');
      // Las 4 tarjetas y el contador de la pestaña son del catálogo completo (admin-products-stats),
      // no de la página actual: antes salían de productsList cuando ese array traía todo; desde la
      // paginación (DEV-20261005-022) solo tiene la página visible.
      if (countTab) countTab.textContent = productsStats.total ?? 0;

      const statTotal = document.getElementById('statProductsTotal');
      const statVis = document.getElementById('statProductsVisible');
      const statWithPhoto = document.getElementById('statProductsWithPhoto');
      const statWithoutPhoto = document.getElementById('statProductsWithoutPhoto');

      if (statTotal) statTotal.textContent = productsStats.total ?? 0;
      if (statVis) statVis.textContent = productsStats.visible ?? 0;
      if (statWithPhoto) statWithPhoto.textContent = productsStats.with_photo ?? 0;
      if (statWithoutPhoto) statWithoutPhoto.textContent = productsStats.without_photo ?? 0;

      renderProductsPager();

      // Estado vacío si no hay ningún producto registrado en todo el catálogo (no solo esta página)
      updateProductStats();
      if (!productsTotal && productsStatsLoaded && Number(productsStats.total) === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="9">
              <div class="state catalog-state">
                <i data-lucide="package-open"></i>
                <strong>Tu catálogo está vacío</strong>
                <span>Importa un Excel con tus productos o registra el primero a mano. Después podrás completar fotos, precios y categorías desde aquí.</span>
                <div class="catalog-state-actions">
                  <button type="button" class="btn btn-primary" onclick="document.getElementById('btnNewProduct').click()">
                    <i data-lucide="plus" width="15"></i> Crear primer producto
                  </button>
                  <button type="button" class="btn btn-secondary" onclick="switchTab('tab-importer')">
                    <i data-lucide="file-spreadsheet" width="15"></i> Importar Excel
                  </button>
                </div>
              </div>
            </td>
          </tr>
        `;
        lucide.createIcons();
        return;
      }

      // productsList ya viene filtrada y paginada desde el servidor (loadProductsPage); no se
      // vuelve a filtrar aquí.
      const items = productsList;

      if (!items.length) {
        tbody.innerHTML = `
          <tr>
            <td colspan="9">
              <div class="state catalog-state">
                <i data-lucide="search-x"></i>
                <strong>Sin resultados</strong>
                <span>Ningún producto coincide con la búsqueda o los filtros. Prueba con menos filtros o usa «Limpiar filtros».</span>
              </div>
            </td>
          </tr>
        `;
        lucide.createIcons();
        return;
      }

      tbody.innerHTML = items.map(p => {
        const html=escapeHtmlEditor;
        const argument=html(JSON.stringify(String(p.id)));
        const isVisible=p.visible!==false;
        const img=p.imageUrl?`<img src="${html(p.imageUrl)}" alt="" loading="lazy" class="prod-thumb-img">`:`<span class="product-no-photo"><i data-lucide="image" width="22"></i><small>Sin foto</small></span>`;
        return `<tr class="product-row${selectedProducts.has(String(p.id))?' is-selected':''}" data-prod-id="${html(p.id)}">
          <td class="product-select"><input type="checkbox" class="product-check" data-select-id="${html(p.id)}" aria-label="Seleccionar ${html(p.name)}" ${selectedProducts.has(String(p.id))?'checked':''}></td>
          <td class="product-photo">${img}</td>
          <td class="product-identity"><strong class="prod-title">${html(p.name||'Sin nombre')}</strong>${p.sku?`<code class="prod-sku">SKU: ${html(p.sku)}</code>`:''}</td>
          <td class="product-category" data-label="Categoría"><span class="badge-cat">${html(p.category||'General')}</span></td>
          <td class="product-subcategory" data-label="Subcategoría">${p.subcategory?`<span class="badge-sub">${html(p.subcategory)}</span>`:'<span class="muted">Sin subcategoría</span>'}</td>
          <td class="product-price" data-label="Precio"><div class="inline-cell"><span class="inline-value" data-inline-cell="price" title="Doble clic para editar el precio"><span class="prod-price">${Number(p.price)>0?formatMoney(p.price):'Cotización'}</span>${marginText(p)}</span><button type="button" class="inline-edit-btn" data-inline-edit aria-label="Editar precio de ${html(p.name)}"><i data-lucide="pencil" width="13"></i></button></div></td>
          <td class="product-stock" data-label="Stock"><div class="inline-cell"><span class="inline-value" data-inline-cell="stock" title="Doble clic para editar el stock disponible">${stockHtml(p)}</span><button type="button" class="inline-edit-btn" data-inline-edit aria-label="Editar stock de ${html(p.name)}"><i data-lucide="pencil" width="13"></i></button></div></td>
          <td class="product-visibility"><label class="product-visibility-control"><span class="switch-toggle"><input aria-label="Visible en tienda: ${html(p.name)}" type="checkbox" ${isVisible?'checked':''} onchange="toggleProductVisibility(${argument},this.checked)"><span class="slider"></span></span><span>${isVisible?'Visible':'Oculto'}</span></label></td>
          <td class="product-actions"><div class="product-action-buttons"><button type="button" class="btn btn-secondary" onclick="openEditProductModal(${argument})">Editar</button><details class="product-more"><summary class="btn btn-secondary" aria-label="Más acciones para ${html(p.name)}">Más</summary><div class="product-more-menu"><a href="${html(getProductPublicUrl(p))}" target="_blank" rel="noopener">Ver en tienda ↗</a><button type="button" onclick="openQuickImageSearch(${argument})">Buscar imagen</button>${p.externalUrl?`<a href="${html(p.externalUrl)}" target="_blank" rel="noopener">Ficha del fabricante ↗</a>`:''}<button type="button" class="danger" onclick="deleteProduct(${argument})">Eliminar producto</button></div></details></div></td>
        </tr>`;
      }).join('');

      syncSelectionUi();
      lucide.createIcons();
    };

    let productFilterDebounceTimer = null;
    ['productSearchInput', 'filterFamily', 'filterMacroCat', 'filterSubCat', 'filterVisibility', 'filterPhoto', 'filterPrice'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', () => {
        if (id === 'filterFamily') renderCategorySelects();
        if (id === 'filterMacroCat') updateSubcategoryOptions();
        // Cualquier cambio de filtro vuelve a pedir la página 1 al servidor (ya no se filtra en
        // memoria). La búsqueda de texto se demora un poco para no disparar una petición por tecla.
        clearTimeout(productFilterDebounceTimer);
        const delay = id === 'productSearchInput' ? 350 : 0;
        productFilterDebounceTimer = setTimeout(() => loadProductsPage(1), delay);
      });
    });

    window.toggleProductVisibility = async (productId, isVisible) => {
      const prod = productsList.find(p => String(p.id) === String(productId));
      if (!prod) return;
      prod.visible = isVisible;
      try {
        const fd = new FormData();
        fd.append('id', prod.id);
        fd.append('name', prod.name);
        fd.append('price', String(prod.price || 0));
        fd.append('category', prod.category || 'General');
        fd.append('subcategory', prod.subcategory || '');
        fd.append('imageUrl', prod.imageUrl || prod.image_url || '');
        fd.append('sku', prod.sku || '');
        fd.append('description', prod.description || '');
        fd.append('visible', isVisible ? '1' : '0');
        await api('admin-products', { method: 'POST', body: fd });
        showToast(`Producto ${isVisible ? 'visible en tienda' : 'oculto de la tienda'}.`);
        refreshProducts();
      } catch (err) {
        prod.visible = !isVisible;
        renderProductsTab();
        showToast(err.message, 'error');
      }
    };

    // --- SELECCIÓN, ACCIONES MASIVAS Y EDICIÓN RÁPIDA ---
    // La selección sobrevive al cambio de página y de filtros; cada id guarda la fila para el CSV.
    const selectedProducts = new Map();
    const BULK_CHUNK = 500;
    const compactRow = p => ({ id: String(p.id), name: p.name || '', sku: p.sku || '', category: p.category || '', subcategory: p.subcategory || '', price: Number(p.price) || 0, cost: p.cost ?? null, visible: p.visible !== false });
    const moneyPlain = n => `$${(Number(n) || 0).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const marginText = p => {
      if (p.cost == null || !(Number(p.price) > 0)) return '';
      const pct = Math.round((Number(p.price) - Number(p.cost)) / Number(p.price) * 1000) / 10;
      return `<small class="prod-cost ${pct < 0 ? 'is-bad' : pct < 10 ? 'is-warn' : ''}">Costo ${moneyPlain(p.cost)} · ${String(pct).replace('.', ',')}%</small>`;
    };
    const stockHtml = p => {
      if (!p.stock) return '<span class="muted">Sin control</span>';
      const tone = p.stock.free <= 0 ? 'is-out' : p.stock.free <= 5 ? 'is-low' : '';
      return `<span class="prod-stock ${tone}">${p.stock.available}</span>${p.stock.reserved ? `<small class="muted"> · ${p.stock.reserved} reserv.</small>` : ''}`;
    };

    const syncSelectionUi = () => {
      document.querySelectorAll('#productsTableBody .product-check').forEach(box => { box.checked = selectedProducts.has(box.dataset.selectId); box.closest('tr')?.classList.toggle('is-selected', box.checked); });
      const head = document.getElementById('selectPageProducts');
      const onPage = productsList.filter(p => selectedProducts.has(String(p.id))).length;
      if (head) { head.checked = productsList.length > 0 && onPage === productsList.length; head.indeterminate = onPage > 0 && onPage < productsList.length; }
      const bar = document.getElementById('bulkBar');
      if (!bar) return;
      bar.hidden = selectedProducts.size === 0;
      document.getElementById('bulkCount').textContent = `${selectedProducts.size.toLocaleString('es-EC')} seleccionado${selectedProducts.size === 1 ? '' : 's'}`;
      const filtered = document.getElementById('bulkSelectFiltered');
      filtered.hidden = productsTotal <= productsList.length && onPage === productsList.length;
      filtered.textContent = `Seleccionar los ${productsTotal.toLocaleString('es-EC')} filtrados`;
    };

    document.getElementById('productsTableBody').addEventListener('change', e => {
      const box = e.target.closest('.product-check');
      if (!box) return;
      const p = productsList.find(item => String(item.id) === box.dataset.selectId);
      if (box.checked && p) selectedProducts.set(String(p.id), compactRow(p)); else selectedProducts.delete(box.dataset.selectId);
      syncSelectionUi();
    });
    document.getElementById('selectPageProducts')?.addEventListener('change', e => {
      productsList.forEach(p => { if (e.target.checked) selectedProducts.set(String(p.id), compactRow(p)); else selectedProducts.delete(String(p.id)); });
      syncSelectionUi();
    });
    document.getElementById('bulkClear')?.addEventListener('click', () => { selectedProducts.clear(); syncSelectionUi(); });
    document.getElementById('bulkSelectFiltered')?.addEventListener('click', async e => {
      const button = e.currentTarget;
      button.disabled = true;
      try {
        const params = new URLSearchParams({ compact: '1' });
        Object.entries(currentProductFilters()).forEach(([key, value]) => { if (value && value !== 'all') params.set(key, value); });
        const res = await api(`admin-products?${params.toString()}`);
        (res.products || []).forEach(p => selectedProducts.set(String(p.id), compactRow(p)));
        syncSelectionUi();
        showToast(res.truncated ? `Se seleccionaron los primeros ${res.max.toLocaleString('es-EC')} productos del filtro.` : `${(res.products || []).length.toLocaleString('es-EC')} productos seleccionados.`, res.truncated ? 'error' : 'success');
      } catch (err) { showToast(err.message, 'error'); }
      finally { button.disabled = false; }
    });

    // Lotes de 500 (límite del servidor); cada lote es una transacción.
    const bulkUpdate = async payload => {
      const ids = [...selectedProducts.keys()];
      const total = { updated: 0, skipped: [], changes: [] };
      for (let i = 0; i < ids.length; i += BULK_CHUNK) {
        const res = await api('admin-products-bulk-update', { method: 'POST', body: JSON.stringify({ ...payload, ids: ids.slice(i, i + BULK_CHUNK) }) });
        total.updated += Number(res.updated) || 0;
        total.skipped.push(...(res.skipped || []));
        total.changes.push(...(res.changes || []));
      }
      return total;
    };
    const bulkDone = async (res, verb) => {
      showToast(`${res.updated.toLocaleString('es-EC')} producto${res.updated === 1 ? '' : 's'} ${verb}${res.skipped.length ? ` · ${res.skipped.length} omitido${res.skipped.length === 1 ? '' : 's'} (${res.skipped[0].reason})` : ''}.`);
      selectedProducts.clear();
      await refreshProducts();
    };
    const runBulk = async (button, fn) => {
      document.querySelectorAll('#bulkBar button').forEach(b => { b.disabled = true; });
      try { await fn(); } catch (err) { showToast(err.message, 'error'); }
      finally { document.querySelectorAll('#bulkBar button').forEach(b => { b.disabled = false; }); syncSelectionUi(); }
    };

    const openBulkPrice = () => {
      const count = selectedProducts.size;
      const body = document.createElement('form');
      body.className = 'bulk-form';
      body.innerHTML = `<p class="bulk-form-note">${count.toLocaleString('es-EC')} producto${count === 1 ? '' : 's'} seleccionado${count === 1 ? '' : 's'}. Los que están a cotizar (sin precio) no se tocan.</p>
        <div class="bulk-form-row">
          <label>Ajuste<select name="dir"><option value="1">Subir</option><option value="-1">Bajar</option></select></label>
          <label>Valor<input name="value" type="number" min="0.01" step="0.01" required autofocus inputmode="decimal"></label>
          <label>En<select name="mode"><option value="percent">% del precio</option><option value="amount">USD por producto</option><option value="from_cost">Desde el costo: +15 % y +15 %</option></select></label>
        </div>
        <button type="submit" class="button small">Ver vista previa</button>
        <div class="bulk-preview" aria-live="polite"></div>`;
      let previewKey = null;
      const read = () => { const f = new FormData(body); const mode = String(f.get('mode')); if (mode === 'from_cost') return { mode, value: 0 }; const v = Math.abs(Number(f.get('value'))) * Number(f.get('dir')); return { mode, value: Math.round(v * 100) / 100 }; };
      body.addEventListener('change', () => { const fromCost = body.elements.mode.value === 'from_cost'; body.elements.value.disabled = fromCost; body.elements.dir.disabled = fromCost; });
      const view = SmartAdmin.modal({ title: 'Ajustar precio', body, actions: [{ label: 'Cancelar', value: false }, { label: 'Aplicar cambios', kind: 'primary', onClick: () => { apply(); return false; } }] });
      const applyBtn = view.el.querySelector('.panel-foot .primary');
      applyBtn.disabled = true;
      body.addEventListener('input', () => { if (previewKey !== JSON.stringify(read())) applyBtn.disabled = true; });
      body.addEventListener('submit', async e => {
        e.preventDefault();
        const op = read();
        if (op.mode !== 'from_cost' && !(Math.abs(op.value) > 0)) { showToast('Escribe un valor mayor que cero.', 'error'); return; }
        const box = body.querySelector('.bulk-preview');
        box.innerHTML = '<span class="sk sk-line"></span><span class="sk sk-line" style="width:70%"></span>';
        try {
          const res = await bulkUpdate({ op: 'price', ...op, dry_run: true });
          previewKey = JSON.stringify(op);
          applyBtn.disabled = res.updated === 0;
          box.innerHTML = `<p class="bulk-form-note"><strong>${res.updated.toLocaleString('es-EC')}</strong> cambian${res.skipped.length ? ` · ${res.skipped.length} se omiten (${op.mode === 'from_cost' ? 'sin costo registrado o ya tienen ese precio' : 'sin precio o quedarían en 0'})` : ''}.</p>` +
            (res.changes.length ? `<div class="bulk-preview-table"><table><thead><tr><th>Producto</th><th class="num">Antes</th><th class="num">Después</th></tr></thead><tbody>${res.changes.slice(0, 30).map(c => `<tr><td>${escapeHtmlEditor(c.name)}</td><td class="num">${moneyPlain(c.before)}</td><td class="num"><strong>${moneyPlain(c.after)}</strong></td></tr>`).join('')}</tbody></table></div>${res.changes.length > 30 ? `<p class="bulk-form-note">Y ${res.changes.length - 30} más.</p>` : ''}` : '');
        } catch (err) { box.innerHTML = ''; showToast(err.message, 'error'); }
      });
      const apply = async () => {
        if (applyBtn.disabled || previewKey !== JSON.stringify(read())) return;
        applyBtn.disabled = true;
        try { const res = await bulkUpdate({ op: 'price', ...read() }); view.close(true); await bulkDone(res, 'con precio actualizado'); }
        catch (err) { applyBtn.disabled = false; showToast(err.message, 'error'); }
      };
    };

    const openBulkCategory = () => {
      const body = document.createElement('form');
      body.className = 'bulk-form';
      const groups = [...productFamilies.map(group => ({ name: group.name, categories: categoriesList.filter(cat => cat.macroGroupId === group.id) })), { name: 'Sin familia', categories: categoriesList.filter(cat => !productFamilies.some(group => group.id === cat.macroGroupId)) }].filter(group => group.categories.length);
      body.innerHTML = `<p class="bulk-form-note">Mover ${selectedProducts.size.toLocaleString('es-EC')} producto${selectedProducts.size === 1 ? '' : 's'} a:</p>
        <div class="bulk-form-row">
          <label>Categoría<select name="category" required autofocus>${groups.map(group => `<optgroup label="${escapeHtmlEditor(group.name)}">${group.categories.map(cat => `<option value="${escapeHtmlEditor(cat.name)}">${escapeHtmlEditor(cat.name)}</option>`).join('')}</optgroup>`).join('')}</select></label>
          <label>Subcategoría (opcional)<input name="subcategory" list="bulkSubcatList" maxlength="100"></label>
        </div><datalist id="bulkSubcatList"></datalist>`;
      const fillSubs = () => { const cat = categoriesList.find(c => c.name === body.elements.category.value); body.querySelector('#bulkSubcatList').innerHTML = (cat?.subcategories || []).map(sub => `<option value="${escapeHtmlEditor(sub)}"></option>`).join(''); };
      body.elements.category.addEventListener('change', fillSubs);
      fillSubs();
      const view = SmartAdmin.modal({ title: 'Cambiar categoría', body, actions: [{ label: 'Cancelar', value: false }, { label: 'Mover productos', kind: 'primary', onClick: () => { apply(); return false; } }] });
      const applyBtn = view.el.querySelector('.panel-foot .primary');
      const apply = async () => {
        const category = body.elements.category.value;
        if (!category) { showToast('Elige una categoría.', 'error'); return; }
        applyBtn.disabled = true;
        try { const res = await bulkUpdate({ op: 'category', category, subcategory: body.elements.subcategory.value.trim() }); view.close(true); await bulkDone(res, 'movidos'); }
        catch (err) { applyBtn.disabled = false; showToast(err.message, 'error'); }
      };
      body.addEventListener('submit', e => { e.preventDefault(); apply(); });
    };

    const exportSelectionCsv = () => {
      const rows = [...selectedProducts.values()].map(p => [p.id, p.name, p.sku, p.category, p.subcategory, p.price ? p.price.toFixed(2).replace('.', ',') : '', p.cost != null ? Number(p.cost).toFixed(2).replace('.', ',') : '', p.visible ? 'Sí' : 'No']);
      SmartAdmin.csv(`smartisp-productos-seleccion-${new Date().toISOString().slice(0, 10)}.csv`, ['ID', 'Nombre', 'SKU', 'Categoría', 'Subcategoría', 'Precio', 'Costo', 'Visible'], rows);
      showToast(`${rows.length.toLocaleString('es-EC')} producto${rows.length === 1 ? '' : 's'} exportado${rows.length === 1 ? '' : 's'}.`);
    };

    document.getElementById('bulkBar')?.addEventListener('click', e => {
      const button = e.target.closest('[data-bulk]');
      if (!button || !selectedProducts.size) return;
      const n = selectedProducts.size.toLocaleString('es-EC');
      const action = button.dataset.bulk;
      if (action === 'price') return openBulkPrice();
      if (action === 'category') return openBulkCategory();
      if (action === 'csv') return exportSelectionCsv();
      const confirmations = {
        hide: ['Ocultar productos', `${n} producto(s) dejarán de verse en la tienda.`, 'Ocultar', { op: 'visibility', visible: false }, 'ocultos'],
        show: ['Mostrar productos', `${n} producto(s) se publicarán en la tienda.`, 'Mostrar', { op: 'visibility', visible: true }, 'visibles'],
        'track-on': ['Controlar stock', `${n} producto(s) pasarán a stock controlado. Los que aún no lo tenían empiezan con 0 unidades y no se podrán vender hasta cargar su stock en Inventario.`, 'Controlar stock', { op: 'track_stock', track: true }, 'con stock controlado'],
        'track-off': ['Quitar control de stock', `${n} producto(s) volverán a venderse sin límite. Los que tienen unidades reservadas por pedidos en curso se omiten.`, 'Quitar control', { op: 'track_stock', track: false }, 'sin control de stock'],
      };
      const [title, text, ok, payload, verb] = confirmations[action] || [];
      if (!title) return;
      SmartAdmin.confirm(title, text, ok).then(yes => { if (yes) runBulk(button, async () => bulkDone(await bulkUpdate(payload), verb)); });
    });

    // Edición rápida de precio y stock: doble clic o lápiz; Enter guarda, Esc cancela.
    const startInlineEdit = cell => {
      const kind = cell.dataset.inlineCell;
      const row = cell.closest('tr');
      const p = productsList.find(item => String(item.id) === row?.dataset.prodId);
      if (!p || cell.querySelector('.inline-input')) return;
      const current = kind === 'price' ? (Number(p.price) || 0) : (p.stock ? p.stock.available : '');
      const original = cell.innerHTML;
      cell.innerHTML = `<input type="number" class="inline-input form-control" min="0" ${kind === 'price' ? 'step="0.01" max="99999999"' : 'step="1" max="1000000"'} value="${current}" aria-label="${kind === 'price' ? 'Precio' : 'Stock disponible'} de ${escapeHtmlEditor(p.name)}" title="Enter para guardar · Esc para cancelar">`;
      const input = cell.querySelector('input');
      input.focus();
      input.select();
      let finished = false;
      const restore = () => { cell.innerHTML = original; lucide.createIcons(); };
      const finish = async save => {
        if (finished) return;
        finished = true;
        const raw = input.value.trim();
        if (!save || raw === '' || Number(raw) === Number(current)) { restore(); return; }
        const value = Number(raw);
        if (kind === 'price' ? !(value >= 0) : !(Number.isInteger(value) && value >= 0 && value <= 1000000)) { restore(); showToast(kind === 'price' ? 'El precio debe ser un número mayor o igual a 0.' : 'El stock debe ser un número entero entre 0 y 1.000.000.', 'error'); return; }
        const before = { price: p.price, stock: p.stock };
        if (kind === 'price') p.price = Math.round(value * 100) / 100;
        else p.stock = { available: value, reserved: p.stock?.reserved || 0, free: Math.max(0, value - (p.stock?.reserved || 0)) };
        renderProductsTab();
        try {
          if (kind === 'price') {
            await api('admin-products-bulk-update', { method: 'POST', body: JSON.stringify({ op: 'price', mode: 'set', value: p.price, ids: [String(p.id)] }) });
          } else {
            const res = await api('admin-inventory', { method: 'POST', body: JSON.stringify({ product_id: String(p.id), available: value }) });
            if (res.item?.tracked) p.stock = { available: res.item.available, reserved: res.item.reserved, free: res.item.free };
            renderProductsTab();
          }
          showToast(kind === 'price' ? `Precio de "${p.name}" actualizado.` : `Stock de "${p.name}" actualizado.`);
        } catch (err) {
          p.price = before.price;
          p.stock = before.stock;
          renderProductsTab();
          showToast(err.message, 'error');
        }
      };
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
      });
      input.addEventListener('blur', () => finish(false));
    };
    document.getElementById('productsTableBody').addEventListener('dblclick', e => {
      const cell = e.target.closest('[data-inline-cell]');
      if (cell && !e.target.closest('input')) startInlineEdit(cell);
    });
    document.getElementById('productsTableBody').addEventListener('click', e => {
      const button = e.target.closest('[data-inline-edit]');
      if (button) startInlineEdit(button.closest('td').querySelector('[data-inline-cell]'));
    });

    const { IVA_RATE, withIva, applyIncrements, round2 } = window.SmartPricing;
    const ivaPrefKey = 'smartisp.priceIncludesIva';
    const updateIvaHint = () => {
      const hint = document.getElementById('prodIvaHint');
      const check = document.getElementById('chkPriceIncludesIva');
      if (!hint || !check) return;
      const quote = document.getElementById('chkQuotePrice')?.checked;
      const raw = Number(document.getElementById('prodPriceInput').value) || 0;
      document.getElementById('ivaToggleRow').hidden = Boolean(quote);
      if (quote || raw <= 0) { hint.textContent = check.checked ? 'Este es el precio final que verá el cliente.' : 'Escribe el precio sin IVA: se le sumará el 15 %.'; return; }
      const final = check.checked ? raw : withIva(raw);
      const base = window.SmartPricing.withoutIva(final);
      hint.textContent = check.checked
        ? `Precio final en tienda: ${moneyPlain(final)} · sin IVA ${moneyPlain(base)} + IVA ${moneyPlain(Math.round((final - base) * 100) / 100)}`
        : `Se sumará el 15 %: precio final en tienda ${moneyPlain(final)} (IVA ${moneyPlain(Math.round((final - raw) * 100) / 100)})`;
    };
    ['prodPriceInput', 'chkQuotePrice', 'chkPriceIncludesIva'].forEach(id => document.getElementById(id)?.addEventListener('input', updateIvaHint));
    document.getElementById('chkPriceIncludesIva')?.addEventListener('change', event => {
      try { localStorage.setItem(ivaPrefKey, event.target.checked ? '1' : '0'); } catch (e) { /* almacenamiento bloqueado */ }
      updateIvaHint();
    });

    const updateMarginHint = () => {
      const hint = document.getElementById('prodMarginHint');
      const cost = document.getElementById('prodCostInput').value.trim();
      const price = Number(document.getElementById('prodPriceInput').value) || 0;
      if (cost === '' || price <= 0) { hint.textContent = 'Solo se ve en el panel: sirve para calcular tu margen. Nunca se muestra en la tienda.'; hint.style.color = ''; return; }
      const margin = price - Number(cost);
      hint.textContent = `Margen: ${moneyPlain(margin)} por unidad (${String(Math.round(margin / price * 1000) / 10).replace('.', ',')}%). Solo se ve en el panel.`;
      hint.style.color = margin < 0 ? 'var(--danger)' : '';
    };
    ['prodCostInput', 'prodPriceInput', 'chkQuotePrice'].forEach(id => document.getElementById(id)?.addEventListener('input', updateMarginHint));
    const syncPriceFromCostButton = () => { document.getElementById('btnPriceFromCost').disabled = !(Number(document.getElementById('prodCostInput').value) > 0); };
    document.getElementById('prodCostInput').addEventListener('input', syncPriceFromCostButton);
    document.getElementById('btnPriceFromCost').addEventListener('click', () => {
      const price = applyIncrements(document.getElementById('prodCostInput').value);
      if (!(price > 0)) return;
      document.getElementById('chkQuotePrice').checked = false;
      document.getElementById('chkPriceIncludesIva').checked = true;
      const input = document.getElementById('prodPriceInput');
      input.value = price.toFixed(2);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    window.deleteProduct = async productId => {
      const prod = productsList.find(p => String(p.id) === String(productId));
      if (!prod) return;
      if (!confirm(`¿Eliminar definitivamente el producto "${prod.name}"?`)) return;
      try {
        await api(`admin-products?id=${encodeURIComponent(productId)}`, { method: 'DELETE' });
        showToast('Producto eliminado.');
        refreshProducts();
      } catch (err) {
        showToast(err.message, 'error');
      }
    };

    // --- ACCIÓN: ELIMINAR TODOS LOS PRODUCTOS ---
    const btnDeleteAll = document.getElementById('btnDeleteAllProducts');
    if (btnDeleteAll) {
      btnDeleteAll.addEventListener('click', () => {
        if (!productsStats.total) {
          showToast('No hay productos en el catálogo para eliminar.', 'error');
          return;
        }
        // Total real del catálogo (productsStats), no productsList.length: desde la paginación
        // (DEV-20261005-022) productsList solo trae la página visible y mostraría un número
        // mucho menor al real en este aviso de "vas a borrar todo".
        const countSpan = document.getElementById('deleteAllCountText');
        if (countSpan) countSpan.textContent = `${productsStats.total}`;
        openModal('deleteAllModal');
      });
    }

    const btnConfirmDeleteAll = document.getElementById('btnConfirmDeleteAll');
    if (btnConfirmDeleteAll) {
      btnConfirmDeleteAll.addEventListener('click', async () => {
        try {
          btnConfirmDeleteAll.disabled = true;
          btnConfirmDeleteAll.textContent = 'Eliminando catálogo...';
          await api('admin-products-clear', { method: 'POST' });
          await refreshProducts(true);
          closeModal('deleteAllModal');
          showToast('Todos los productos han sido eliminados correctamente.');
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          btnConfirmDeleteAll.disabled = false;
          btnConfirmDeleteAll.innerHTML = '<i data-lucide="trash-2" width="15"></i> Sí, vaciar y eliminar todos';
          lucide.createIcons();
        }
      });
    }

    // --- SUBIDA LOCAL Y CONTROL DE FOTO DE PRODUCTO ---
    const btnUploadLocal = document.getElementById('btnUploadLocalImage');
    const localFileInput = document.getElementById('prodLocalFileInput');
    const btnRemoveImage = document.getElementById('btnRemoveProdImage');
    const prodImgPreview = document.getElementById('prodImagePreview');
    const prodImgUrlInput = document.getElementById('prodImageUrlInput');

    const compressImage = file => new Promise((resolve) => {
      const img = new Image();
      img.onload = () => {
        const maxDim = 900;
        let w = img.width;
        let h = img.height;
        if (w > maxDim || h > maxDim) {
          if (w > h) {
            h = Math.round((h * maxDim) / w);
            w = maxDim;
          } else {
            w = Math.round((w * maxDim) / h);
            h = maxDim;
          }
        }
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);
        canvas.toBlob(blob => {
          resolve(blob || file);
        }, 'image/jpeg', 0.82);
      };
      img.onerror = () => resolve(file);
      img.src = URL.createObjectURL(file);
    });

    if (btnUploadLocal && localFileInput) {
      btnUploadLocal.addEventListener('click', () => localFileInput.click());
      localFileInput.addEventListener('change', async e => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
          showToast('Por favor selecciona un archivo de imagen válido (PNG, JPG, WebP).', 'error');
          return;
        }
        if (file.size > 15 * 1024 * 1024) {
          showToast('La imagen es demasiado pesada (máximo 15 MB).', 'error');
          return;
        }

        const originalBtnHtml = btnUploadLocal.innerHTML;
        btnUploadLocal.disabled = true;
        btnUploadLocal.innerHTML = '<span style="font-size:12px;">Subiendo...</span>';

        try {
          const localPreviewUrl = URL.createObjectURL(file);
          prodImgPreview.src = localPreviewUrl;
          prodImgPreview.style.display = 'block';
          if (btnRemoveImage) btnRemoveImage.style.display = 'flex';

          const compressedBlob = await compressImage(file);
          const fd = new FormData();
          const safeName = (file.name || 'producto').replace(/[^a-zA-Z0-9_-]/g, '_') + '.jpg';
          fd.append('image', compressedBlob, safeName);

          let uploadUrl = '/api/router.php?action=upload-image';
          const uploadHeaders = {};
          const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
          if (token) {
            uploadHeaders['Authorization'] = `Bearer ${token}`;
            uploadHeaders['X-Admin-Token'] = token;
          }
          const uploadRes = await fetch(uploadUrl, {
            method: 'POST',
            credentials: 'include',
            headers: uploadHeaders,
            body: fd
          });

          if (uploadRes.ok) {
            const upData = await uploadRes.json();
            if (upData.url) {
              prodImgUrlInput.value = upData.url;
              prodImgPreview.src = upData.url;
              showToast('Fotografía subida y optimizada en el servidor.');
            } else {
              throw new Error('No se recibió la URL de la imagen guardada.');
            }
          } else {
            const textErr = await uploadRes.text().catch(() => '');
            let errData = {};
            try { 
              errData = JSON.parse(textErr); 
            } catch { 
              let clean = (textErr || '').replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
              if (clean.length > 150) clean = clean.substring(0, 150) + '...';
              errData = { error: clean || `Error ${uploadRes.status}` }; 
            }
            throw new Error(errData.error || `Error al subir imagen al servidor (${uploadRes.status})`);
          }
        } catch (err) {
          console.warn('Error subiendo imagen:', err);
          showToast(err.message || 'Error al procesar la foto.', 'error');
          prodImgUrlInput.value = '';
          prodImgPreview.style.display = 'none';
          if (btnRemoveImage) btnRemoveImage.style.display = 'none';
        } finally {
          btnUploadLocal.disabled = false;
          btnUploadLocal.innerHTML = originalBtnHtml;
          if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
        }
      });
    }

    if (btnRemoveImage) {
      btnRemoveImage.addEventListener('click', () => {
        prodImgUrlInput.value = '';
        prodImgPreview.src = '';
        prodImgPreview.style.display = 'none';
        btnRemoveImage.style.display = 'none';
        if (localFileInput) localFileInput.value = '';
      });
    }

    // --- MODAL PRODUCTO (NUEVO / EDITAR) ---
    const chkQuotePriceEl = document.getElementById('chkQuotePrice');
    const quotePriceBadgeEl = document.getElementById('quotePriceBadge');
    const prodPriceInputEl = document.getElementById('prodPriceInput');

    if (chkQuotePriceEl && prodPriceInputEl) {
      chkQuotePriceEl.addEventListener('change', () => {
        if (chkQuotePriceEl.checked) {
          prodPriceInputEl.value = '0';
          prodPriceInputEl.required = false;
          if (quotePriceBadgeEl) {
            quotePriceBadgeEl.style.display = 'flex';
            lucide.createIcons();
          }
        } else {
          if (quotePriceBadgeEl) quotePriceBadgeEl.style.display = 'none';
          prodPriceInputEl.value = '';
          prodPriceInputEl.required = true;
          prodPriceInputEl.focus();
        }
      });

      prodPriceInputEl.addEventListener('input', () => {
        if (Number(prodPriceInputEl.value) > 0 && chkQuotePriceEl.checked) {
          chkQuotePriceEl.checked = false;
          if (quotePriceBadgeEl) quotePriceBadgeEl.style.display = 'none';
        }
      });
    }

    document.getElementById('btnNewProduct').addEventListener('click', () => {
      document.getElementById('prodModalTitle').textContent = 'Agregar Producto Manual';
      const cleanNewId = 'prod_' + Date.now().toString(36) + Math.random().toString(36).substring(2, 8);
      document.getElementById('prodId').value = cleanNewId;
      document.getElementById('prodNameInput').value = '';
      document.getElementById('prodSkuInput').value = '';
      document.getElementById('prodPriceInput').value = '';
      document.getElementById('prodPriceInput').required = true;
      document.getElementById('prodCostInput').value = '';
      updateMarginHint();
      if (chkQuotePriceEl) chkQuotePriceEl.checked = false;
      let ivaPref = '1';
      try { ivaPref = localStorage.getItem(ivaPrefKey) ?? '1'; } catch (e) { /* almacenamiento bloqueado */ }
      document.getElementById('chkPriceIncludesIva').checked = ivaPref !== '0';
      updateIvaHint();
      if (quotePriceBadgeEl) quotePriceBadgeEl.style.display = 'none';
      document.getElementById('prodCategorySelect').value = categoriesList[0]?.name || 'General';
      updateProductCategoryContext();
      document.getElementById('prodSubcategoryInput').value = '';
      document.getElementById('prodImageUrlInput').value = '';
      document.getElementById('prodExternalUrlInput').value = '';
      document.getElementById('prodDescInput').value = '';
      document.getElementById('prodVisibleInput').checked = true;
      document.getElementById('prodContentInput').value = '';
      document.getElementById('prodWarrantyInput').value = '';
      currentProductSpecs = [];
      renderSpecsEditor();
      loadGalleryForProduct('');
      document.getElementById('prodImagePreview').style.display = 'none';
      if (document.getElementById('btnRemoveProdImage')) document.getElementById('btnRemoveProdImage').style.display = 'none';
      if (document.getElementById('btnModalDeleteProduct')) document.getElementById('btnModalDeleteProduct').style.display = 'none';
      if (localFileInput) localFileInput.value = '';

      const submitBtn = document.querySelector('#productForm button[type="submit"]');
      if (submitBtn) submitBtn.innerHTML = '<i data-lucide="plus"></i> Guardar y Publicar Producto';

      openModal('productModal');
      productFormSaved = productFormSnapshot();
      lucide.createIcons();
    });

    window.openEditProductModal = productId => {
      const p = productsList.find(item => String(item.id) === String(productId));
      if (!p) return;
      document.getElementById('prodModalTitle').textContent = `Editar: ${p.name}`;
      document.getElementById('prodId').value = p.id;
      document.getElementById('prodNameInput').value = p.name || '';
      document.getElementById('prodSkuInput').value = p.sku || '';

      const priceNum = Number(p.price || 0);
      if (priceNum <= 0) {
        if (chkQuotePriceEl) chkQuotePriceEl.checked = true;
        document.getElementById('prodPriceInput').value = '0';
        document.getElementById('prodPriceInput').required = false;
        if (quotePriceBadgeEl) quotePriceBadgeEl.style.display = 'flex';
      } else {
        if (chkQuotePriceEl) chkQuotePriceEl.checked = false;
        document.getElementById('prodPriceInput').value = p.price;
        document.getElementById('prodPriceInput').required = true;
        if (quotePriceBadgeEl) quotePriceBadgeEl.style.display = 'none';
      }

      document.getElementById('prodCostInput').value = p.cost ?? '';
      syncPriceFromCostButton();
      updateMarginHint();
      // Lo guardado ya es el precio final: editarlo con la casilla desmarcada sumaría el IVA dos veces.
      document.getElementById('chkPriceIncludesIva').checked = true;
      updateIvaHint();
      document.getElementById('prodCategorySelect').value = p.category || (categoriesList[0]?.name || 'General');
      updateProductCategoryContext();
      document.getElementById('prodSubcategoryInput').value = p.subcategory || '';
      document.getElementById('prodImageUrlInput').value = p.imageUrl || '';
      document.getElementById('prodExternalUrlInput').value = p.externalUrl || '';
      document.getElementById('prodDescInput').value = p.description || '';
      document.getElementById('prodVisibleInput').checked = p.visible !== false;
      document.getElementById('prodContentInput').value = p.contentText || '';
      document.getElementById('prodWarrantyInput').value = p.warrantyText || '';
      currentProductSpecs = Array.isArray(p.specs) ? p.specs.map(s => ({ label: s.label || '', value: s.value || '' })) : [];
      renderSpecsEditor();
      loadGalleryForProduct(p.id);

      const preview = document.getElementById('prodImagePreview');
      const removeBtn = document.getElementById('btnRemoveProdImage');
      if (p.imageUrl) {
        preview.src = p.imageUrl;
        preview.style.display = 'block';
        if (removeBtn) removeBtn.style.display = 'flex';
      } else {
        preview.style.display = 'none';
        if (removeBtn) removeBtn.style.display = 'none';
      }

      const modalDeleteBtn = document.getElementById('btnModalDeleteProduct');
      if (modalDeleteBtn) modalDeleteBtn.style.display = 'inline-flex';
      if (localFileInput) localFileInput.value = '';

      const submitBtn = document.querySelector('#productForm button[type="submit"]');
      if (submitBtn) submitBtn.innerHTML = '<i data-lucide="save"></i> Actualizar Producto';

      openModal('productModal');
      productFormSaved = productFormSnapshot();
      lucide.createIcons();
    };

    // Botón eliminar producto desde adentro del modal
    const btnModalDelete = document.getElementById('btnModalDeleteProduct');
    if (btnModalDelete) {
      btnModalDelete.addEventListener('click', async () => {
        const prodId = document.getElementById('prodId').value;
        if (!prodId) return;
        const prod = productsList.find(p => String(p.id) === String(prodId));
        if (!confirm(`¿Eliminar definitivamente el producto "${prod?.name || 'seleccionado'}"?`)) return;
        try {
          await api(`admin-products?id=${encodeURIComponent(prodId)}`, { method: 'DELETE' });
          closeModal('productModal');
          refreshProducts();
          showToast('Producto eliminado.');
        } catch (err) {
          showToast(err.message, 'error');
        }
      });
    }

    document.getElementById('prodImageUrlInput').addEventListener('input', e => {
      const preview = document.getElementById('prodImagePreview');
      const removeBtn = document.getElementById('btnRemoveProdImage');
      if (e.target.value.trim()) {
        preview.src = e.target.value.trim();
        preview.style.display = 'block';
        if (removeBtn) removeBtn.style.display = 'flex';
      } else {
        preview.style.display = 'none';
        if (removeBtn) removeBtn.style.display = 'none';
      }
    });

    document.getElementById('productForm').addEventListener('submit', async e => {
      e.preventDefault();
      const submitBtn = e.target.querySelector('button[type="submit"]');
      const origHtml = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;">⏳ Guardando producto...</span>';
      }

      try {
        const idInput = document.getElementById('prodId');
        const id = (idInput && idInput.value.trim()) ? idInput.value.trim() : ('prod_' + Date.now().toString(36) + Math.random().toString(36).substring(2, 8));
        const isQuote = document.getElementById('chkQuotePrice')?.checked;
        const rawPrice = parseFloat(document.getElementById('prodPriceInput').value);
        const includesIva = document.getElementById('chkPriceIncludesIva')?.checked !== false;
        const finalPrice = isQuote || isNaN(rawPrice) || rawPrice <= 0 ? 0 : (includesIva ? rawPrice : withIva(rawPrice));

        const name = document.getElementById('prodNameInput').value.trim();
        if (!name) {
          throw new Error('Por favor ingresa el nombre del producto.');
        }

        let imageUrl = document.getElementById('prodImageUrlInput').value.trim();

        // Si la imagen todavía es un Data URL base64, subirla primero vía multipart/form-data
        if (imageUrl && imageUrl.startsWith('data:image/')) {
          try {
            let upUrl = '/api/router.php?action=upload-image';
            const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
            const upHeaders = {};
            if (token) {
              upHeaders['Authorization'] = `Bearer ${token}`;
              upHeaders['X-Admin-Token'] = token;
            }
            const b64Parts = imageUrl.split(',');
            const mime = b64Parts[0].match(/:(.*?);/)?.[1] || 'image/jpeg';
            const byteString = atob(b64Parts[1]);
            const ab = new ArrayBuffer(byteString.length);
            const ia = new Uint8Array(ab);
            for (let i = 0; i < byteString.length; i++) {
              ia[i] = byteString.charCodeAt(i);
            }
            const blob = new Blob([ab], { type: mime });
            const fd = new FormData();
            fd.append('image', blob, 'foto.jpg');

            const upRes = await fetch(upUrl, {
              method: 'POST',
              credentials: 'include',
              headers: upHeaders,
              body: fd
            });
            if (upRes.ok) {
              const upData = await upRes.json();
              if (upData.url) {
                imageUrl = upData.url;
                document.getElementById('prodImageUrlInput').value = upData.url;
              }
            } else {
              imageUrl = '';
              console.warn('No se pudo subir la foto en base64; se guardará sin imagen para evitar bloqueo WAF 403.');
            }
          } catch (upErr) {
            imageUrl = '';
            console.warn('Error en fallback de imagen:', upErr);
          }
        }

        const product = {
          id,
          name,
          sku: document.getElementById('prodSkuInput').value.trim(),
          price: finalPrice,
          category: document.getElementById('prodCategorySelect').value || 'General',
          subcategory: document.getElementById('prodSubcategoryInput').value.trim(),
          imageUrl,
          externalUrl: document.getElementById('prodExternalUrlInput').value.trim(),
          description: document.getElementById('prodDescInput').value.trim(),
          visible: document.getElementById('prodVisibleInput').checked
        };

        const fd = new FormData();
        fd.append('id', id);
        fd.append('name', name);
        fd.append('sku', product.sku);
        fd.append('price', String(finalPrice));
        fd.append('category', product.category);
        fd.append('subcategory', product.subcategory);
        fd.append('imageUrl', imageUrl);
        fd.append('externalUrl', product.externalUrl);
        fd.append('description', product.description);
        fd.append('visible', product.visible ? '1' : '0');
        fd.append('specs', JSON.stringify(currentProductSpecs.filter(s => s.label.trim() && s.value.trim())));
        fd.append('content_text', document.getElementById('prodContentInput').value.trim());
        fd.append('warranty_text', document.getElementById('prodWarrantyInput').value.trim());
        fd.append('cost', document.getElementById('prodCostInput').value.trim());

        await api('admin-products', {
          method: 'POST',
          body: fd
        });

        productFormSaved = null;
        closeModal('productModal');
        showToast(`"${product.name}" guardado correctamente.`);
        await refreshProducts();

      } catch (err) {
        console.error('Error guardando producto:', err);
        showToast(err.message || 'Error al guardar el producto.', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origHtml;
          if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
        }
      }
    });

    // --- BUSCADOR DE FOTOS AMAZON / WEB ---
    window.openQuickImageSearch = productId => {
      const prod = productsList.find(p => String(p.id) === String(productId));
      if (!prod) return;
      targetImageProduct = prod;
      document.getElementById('searchModalProductTitle').textContent = `Para: ${prod.name}`;
      document.getElementById('imageSearchQuery').value = prod.name;
      openModal('imageSearchModal');
      executeImageSearch(prod.name, prod.category, 'all');
    };

    document.getElementById('prodCategorySelect').addEventListener('change', updateProductCategoryContext);
    document.getElementById('btnSearchProductImage').addEventListener('click', () => {
      const name = document.getElementById('prodNameInput').value;
      const cat = document.getElementById('prodCategorySelect').value;
      if (!name) return alert('Escribe el nombre del producto primero.');
      targetImageProduct = { id: 'modal-draft', name };
      document.getElementById('searchModalProductTitle').textContent = `Para: ${name}`;
      document.getElementById('imageSearchQuery').value = name;
      openModal('imageSearchModal');
      executeImageSearch(name, cat, 'all');
    });

    document.getElementById('imageSearchForm').addEventListener('submit', e => {
      e.preventDefault();
      const q = document.getElementById('imageSearchQuery').value;
      const activeStore = document.querySelector('#imageSearchModal .active-store')?.dataset.store || 'all';
      executeImageSearch(q, '', activeStore);
    });

    document.querySelectorAll('#imageSearchModal [data-store]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('#imageSearchModal [data-store]').forEach(b => b.classList.remove('active-store', 'btn-primary'));
        btn.classList.add('active-store', 'btn-primary');
        const q = document.getElementById('imageSearchQuery').value;
        executeImageSearch(q, '', btn.dataset.store);
      });
    });

    let imageSearchSeq = 0;
    let foundImages = [];
    const executeImageSearch = async (query, category = '', store = 'all') => {
      const seq = ++imageSearchSeq;
      const grid = document.getElementById('imageResultsGrid');
      foundImages = [];
      grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px;"><div class="spinner"></div> Buscando imágenes nítidas...</div>';

      try {
        const res = await api(`search-product-image?q=${encodeURIComponent(query)}&category=${encodeURIComponent(category)}&store=${encodeURIComponent(store)}&limit=15`);
        if (seq !== imageSearchSeq) return;
        const images = res.images || [];
        foundImages = images;

        if (!images.length) {
          grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px; color:var(--muted);">No se encontraron imágenes para esta búsqueda. Intenta con palabras clave más sencillas o marca y modelo.</div>';
          return;
        }

        grid.innerHTML = images.map((img, index) => `
          <div class="found-image-card" role="button" tabindex="0" data-found-image="${index}" aria-label="Usar imagen de ${escapeHtmlEditor(img.storeName || 'Web')}">
            <img src="${escapeHtmlEditor(img.thumb || img.url)}" alt="" style="width:100%; height:110px; object-fit:contain; background:#fff; padding:4px;">
            <div style="font-size:10.5px; color:var(--muted); padding:4px 6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border-top:1px solid var(--line);">
              ${escapeHtmlEditor(img.storeName || 'Web')}
            </div>
          </div>
        `).join('');
      } catch (err) {
        if (seq !== imageSearchSeq) return;
        grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:30px; color:var(--danger);">${escapeHtmlEditor(err.message)}</div>`;
      }
    };

    const pickFoundImage = e => {
      const card = e.target.closest('[data-found-image]');
      const img = card && foundImages[Number(card.dataset.foundImage)];
      if (img) selectFoundImage(img.url);
    };
    document.getElementById('imageResultsGrid')?.addEventListener('click', pickFoundImage);
    document.getElementById('imageResultsGrid')?.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickFoundImage(e); }
    });

    const selectFoundImage = async url => {
      if (!targetImageProduct || !url) return;

      if (targetImageProduct.id === 'modal-draft') {
        document.getElementById('prodImageUrlInput').value = url;
        const preview = document.getElementById('prodImagePreview');
        preview.src = url;
        preview.style.display = 'block';
        const removeBtn = document.getElementById('btnRemoveProdImage');
        if (removeBtn) removeBtn.style.display = 'flex';
        closeModal('imageSearchModal');
        return;
      }

      const imageTarget = targetImageProduct;
      const previousImage = imageTarget.imageUrl;
      imageTarget.imageUrl = url;
      try {
        const fd = new FormData();
        fd.append('id', targetImageProduct.id);
        fd.append('name', targetImageProduct.name);
        fd.append('price', String(targetImageProduct.price || 0));
        fd.append('category', targetImageProduct.category || 'General');
        fd.append('subcategory', targetImageProduct.subcategory || '');
        fd.append('imageUrl', url);
        fd.append('sku', targetImageProduct.sku || '');
        fd.append('description', targetImageProduct.description || '');
        fd.append('visible', targetImageProduct.visible !== false ? '1' : '0');
        await api('admin-products', { method: 'POST', body: fd });
        showToast('Foto asignada al producto.');
        closeModal('imageSearchModal');
        refreshProducts();
      } catch (err) {
        imageTarget.imageUrl = previousImage;
        renderProductsTab();
        showToast(err.message, 'error');
      }
    };

    // --- TAB 3: IMPORTADOR INTELIGENTE DE MÚLTIPLES ARCHIVOS EXCEL ---
    let importFilesList = [];
    let activeImportFileIndex = 0;

    const dropzone = document.getElementById('excelDropzone');
    const fileInput = document.getElementById('excelFileInput');

    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', e => {
      e.preventDefault();
      dropzone.classList.remove('dragover');
      if (e.dataTransfer?.files?.length) processExcelFiles(Array.from(e.dataTransfer.files));
    });
    fileInput.addEventListener('change', e => {
      if (e.target.files?.length) processExcelFiles(Array.from(e.target.files));
      fileInput.value = '';
    });

    let importRunning = false;
    window.addEventListener('beforeunload', e => {
      if (importRunning || productFormDirty()) { e.preventDefault(); e.returnValue = ''; }
    });

    const renderImportSummary = (imported, total, failures) => {
      const box = document.getElementById('importSummary');
      if (!box) return;
      if (!failures.length) { box.hidden = true; box.innerHTML = ''; return; }
      const shown = failures.slice(0, 50);
      box.innerHTML = `<strong>${imported} de ${total} productos importados. ${failures.length} no se pudieron guardar:</strong>`
        + `<ul>${shown.map(f => `<li>${f.row ? `#${escapeHtmlEditor(f.row)} · ` : ''}${escapeHtmlEditor(f.name || 'Sin nombre')}: ${escapeHtmlEditor(f.error || 'Error desconocido')}</li>`).join('')}</ul>`
        + (failures.length > shown.length ? `<small>... y ${failures.length - shown.length} más.</small>` : '');
      box.hidden = false;
    };

    window.resetImportFile = () => {
      if (importRunning) return;
      renderImportSummary(0, 0, []);
      importFilesList = [];
      activeImportFileIndex = 0;
      currentImportState = null;
      document.getElementById('excelFileInput').value = '';
      document.getElementById('excelDropzone').style.display = 'block';
      document.getElementById('importerWorkspace').style.display = 'none';
      document.getElementById('importProgressWrap').style.display = 'none';
    };

    const readExcelFileAsync = file => {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = e => {
          try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            if (!workbook.SheetNames || !workbook.SheetNames.length) {
              return reject(new Error(`El archivo "${file.name}" no contiene hojas de cálculo.`));
            }
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const rawRows = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });

            if (!rawRows || !rawRows.length) {
              return reject(new Error(`El archivo "${file.name}" no contiene filas legibles.`));
            }

            const headerRowIdx = detectHeaderRowIndex(rawRows);
            const headers = (rawRows[headerRowIdx] || []).map((c, i) => String(c || '').trim() || `Columna ${i + 1}`);
            const mapping = detectColumnsMapping(headers, rawRows.slice(headerRowIdx + 1, headerRowIdx + 20));

            resolve({
              id: 'file_' + Math.random().toString(36).substring(2, 9),
              fileName: file.name,
              fileSize: file.size,
              matrix: rawRows,
              headerRowIdx,
              headers,
              mapping,
              parsedProducts: []
            });
          } catch (err) {
            reject(new Error(`Error al leer "${file.name}": ${err.message}`));
          }
        };
        reader.onerror = () => reject(new Error(`No se pudo leer el archivo "${file.name}"`));
        reader.readAsArrayBuffer(file);
      });
    };

    const processExcelFiles = async files => {
      if (!files || !files.length) return;
      const validFiles = Array.from(files).filter(f => {
        const name = (f.name || '').toLowerCase();
        return name.endsWith('.xlsx') || name.endsWith('.xls') || name.endsWith('.csv');
      });

      if (!validFiles.length) {
        return alert('Por favor selecciona archivos de Excel (.xlsx, .xls) o CSV (.csv).');
      }

      const loadedStates = [];
      const errors = [];

      for (const file of validFiles) {
        const alreadyLoaded = importFilesList.some(f => f.fileName === file.name && f.fileSize === file.size);
        if (alreadyLoaded) continue;

        try {
          const state = await readExcelFileAsync(file);
          parseProductsForFile(state);
          loadedStates.push(state);
        } catch (err) {
          errors.push(err.message);
        }
      }

      if (errors.length) {
        alert('Aviso al procesar archivos:\n' + errors.join('\n'));
      }

      if (loadedStates.length) {
        importFilesList.push(...loadedStates);
        activeImportFileIndex = importFilesList.length - 1;
        currentImportState = importFilesList[activeImportFileIndex];
        setupImporterUI();
      } else if (!importFilesList.length) {
        resetImportFile();
      }
    };

    const detectHeaderRowIndex = rows => {
      const headerKeywords = ['nombre', 'producto', 'descripcion', 'precio', 'pvp', 'costo', 'sku', 'codigo', 'categoria', 'marca', 'item'];
      for (let r = 0; r < Math.min(rows.length, 15); r++) {
        const row = rows[r];
        if (!Array.isArray(row)) continue;
        let hits = 0;
        row.forEach(cell => {
          const norm = normalizeText(cell);
          if (headerKeywords.some(kw => norm.includes(kw))) hits++;
        });
        if (hits >= 2) return r;
      }
      return 0;
    };

    const detectColumnsMapping = (headers, sampleRows) => {
      const mapping = { name: null, price: null, cost: null, category: null, sku: null, imageUrl: null, description: null };
      const normHeaders = headers.map(h => normalizeText(h));

      const findMatch = regexList => {
        for (let i = 0; i < normHeaders.length; i++) {
          const h = normHeaders[i];
          if (regexList.some(r => r.test(h))) return i;
        }
        return null;
      };

      mapping.name = findMatch([/^nombre/, /^producto/, /^articulo/, /^item/, /^descripcion corta/, /^denominacion/]);
      mapping.sku = findMatch([/^sku/, /^codigo/, /^code/, /^partnumber/, /^nroparte/, /^referencia/, /^barcode/]);
      mapping.price = findMatch([/^precio/, /^pvp/, /^pvd/, /^costo/, /^valor/, /^preciounitario/, /^price/, /^importe/]);
      mapping.category = findMatch([/^categoria/, /^category/, /^linea/, /^familia/, /^grupo/, /^clasificacion/, /^rubro/]);
      mapping.imageUrl = findMatch([/^foto/, /^imagen/, /^image/, /^picture/, /^urlfoto/, /^urlimagen/]);
      mapping.description = findMatch([/^descripcion/, /^description/, /^detalle/, /^especificaciones/]);
      mapping.cost = findMatch([/^costo/, /^cost$/, /^cost /, /^precio( de)? compra/, /^preciocompra/]);

      if (mapping.name === null) {
        if (mapping.description !== null) {
          mapping.name = mapping.description;
          mapping.description = null;
        } else {
          mapping.name = 0;
        }
      }

      return mapping;
    };

    const importIncrements = () => ['importPriceMargin', 'importPriceMargin2'].map(id => Math.max(0, parseFloat(document.getElementById(id).value) || 0));

    const parseProductsForFile = fileState => {
      if (!fileState) return;
      const increments = importIncrements();
      const startRow = fileState.headerRowIdx + 1;
      const products = [];

      for (let r = startRow; r < fileState.matrix.length; r++) {
        const row = fileState.matrix[r];
        if (!row || !Array.isArray(row)) continue;

        const nameVal = fileState.mapping.name !== null ? row[fileState.mapping.name] : '';
        const name = String(nameVal || '').trim();
        if (!name) continue;

        const skuVal = fileState.mapping.sku !== null ? String(row[fileState.mapping.sku] || '').trim() : '';
        const priceProvided = fileState.mapping.price !== null && String(row[fileState.mapping.price] ?? '').trim() !== '';
        const rawPriceStr = fileState.mapping.price !== null ? String(row[fileState.mapping.price] || '0').replace(/[^0-9.,]/g, '').replace(',', '.') : '0';
        const basePrice = parseFloat(rawPriceStr) || 0;
        
        // Sin precio en el archivo queda en 0 (a cotizar).
        const finalPrice = applyIncrements(basePrice, increments);

        const rawCategory = fileState.mapping.category !== null ? String(row[fileState.mapping.category] || '').trim() : '';
        const imageUrl = fileState.mapping.imageUrl !== null ? String(row[fileState.mapping.imageUrl] || '').trim() : '';
        const description = fileState.mapping.description !== null ? String(row[fileState.mapping.description] || '').trim() : '';
        const rawCost = fileState.mapping.cost != null ? String(row[fileState.mapping.cost] ?? '').trim() : '';
        const cost = rawCost !== '' ? parseFloat(rawCost.replace(/[^0-9.,]/g, '').replace(',', '.')) : NaN;

        // Análisis de Afinidad en tiempo real
        const affinity = matchCategoryAffinity(rawCategory, name, categoriesList);

        products.push({
          name,
          sku: skuVal,
          price: finalPrice,
          basePrice,
          rawCategory,
          category: affinity.category,
          subcategory: affinity.subcategory,
          affinityInfo: affinity,
          imageUrl,
          description,
          priceProvided,
          cost: Number.isFinite(cost) && cost >= 0 ? round2(cost) : (basePrice > 0 ? round2(basePrice) : null),
          visible: true,
          fileName: fileState.fileName
        });
      }

      fileState.parsedProducts = products;
    };

    const setupImporterUI = () => {
      document.getElementById('excelDropzone').style.display = 'none';
      document.getElementById('importerWorkspace').style.display = 'block';

      renderFilesChips();
      renderActiveFileMapping();
      refreshCombinedPreview();
    };

    const renderFilesChips = () => {
      const container = document.getElementById('importFilesChipsContainer');
      if (!container) return;

      container.innerHTML = importFilesList.map((f, idx) => {
        const isActive = idx === activeImportFileIndex;
        return `
          <div class="import-file-chip ${isActive ? 'active' : ''}" onclick="selectActiveImportFile(${idx})" title="Clic para configurar columnas de este archivo">
            <i data-lucide="file-spreadsheet" width="15" style="color:${isActive ? 'var(--blue)' : 'var(--muted)'};"></i>
            <span style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.fileName}</span>
            <span class="file-count-badge">${f.parsedProducts.length} prod.</span>
            <button type="button" class="btn-remove-file" onclick="event.stopPropagation(); removeImportFile(${idx});" title="Descartar este archivo">✕</button>
          </div>
        `;
      }).join('') + `
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('excelFileInput').click()" style="padding:5px 10px; font-size:12px; margin-left:auto;">
          <i data-lucide="plus" width="13"></i> Añadir otro archivo
        </button>
      `;

      lucide.createIcons();
    };

    window.selectActiveImportFile = idx => {
      if (idx < 0 || idx >= importFilesList.length) return;
      activeImportFileIndex = idx;
      currentImportState = importFilesList[idx];
      renderFilesChips();
      renderActiveFileMapping();
    };

    window.removeImportFile = idx => {
      if (idx < 0 || idx >= importFilesList.length) return;
      importFilesList.splice(idx, 1);
      if (!importFilesList.length) {
        resetImportFile();
        return;
      }
      if (activeImportFileIndex >= importFilesList.length) {
        activeImportFileIndex = importFilesList.length - 1;
      }
      currentImportState = importFilesList[activeImportFileIndex];
      renderFilesChips();
      renderActiveFileMapping();
      refreshCombinedPreview();
    };

    const renderActiveFileMapping = () => {
      const s = importFilesList[activeImportFileIndex];
      if (!s) return;

      const label = document.getElementById('activeFileNameLabel');
      if (label) label.textContent = `"${s.fileName}"`;

      // Header row select
      const headerSel = document.getElementById('selectHeaderRow');
      headerSel.innerHTML = s.matrix.slice(0, 15).map((row, idx) => {
        const preview = row.slice(0, 4).filter(Boolean).join(' | ') || `Fila vacía (${idx + 1})`;
        return `<option value="${idx}" ${idx === s.headerRowIdx ? 'selected' : ''}>Fila ${idx + 1}: ${preview.substring(0, 45)}</option>`;
      }).join('');

      headerSel.onchange = e => {
        const newIdx = parseInt(e.target.value);
        s.headerRowIdx = newIdx;
        s.headers = (s.matrix[newIdx] || []).map((c, i) => String(c || '').trim() || `Columna ${i + 1}`);
        s.mapping = detectColumnsMapping(s.headers, s.matrix.slice(newIdx + 1, newIdx + 20));
        renderColumnDropdowns();
        parseProductsForFile(s);
        renderFilesChips();
        refreshCombinedPreview();
      };

      renderColumnDropdowns();
    };

    const renderColumnDropdowns = () => {
      const s = importFilesList[activeImportFileIndex];
      if (!s) return;

      const opts = `<option value="-1">-- No mapeada --</option>` + s.headers.map((h, i) => `<option value="${i}">[Col ${i + 1}] ${h}</option>`).join('');

      const mapElements = {
        mapColName: 'name',
        mapColPrice: 'price',
        mapColCost: 'cost',
        mapColCategory: 'category',
        mapColSku: 'sku',
        mapColImage: 'imageUrl',
        mapColDesc: 'description'
      };

      Object.entries(mapElements).forEach(([elemId, key]) => {
        const el = document.getElementById(elemId);
        if (!el) return;
        el.innerHTML = opts;
        el.value = s.mapping[key] != null ? s.mapping[key] : -1;
        el.onchange = () => {
          s.mapping[key] = parseInt(el.value) >= 0 ? parseInt(el.value) : null;
          parseProductsForFile(s);
          renderFilesChips();
          refreshCombinedPreview();
        };
      });
    };

    ['importPriceMargin', 'importPriceMargin2'].forEach(id => document.getElementById(id).addEventListener('input', () => {
      const increments = importIncrements();
      const pct = n => String(Math.round(n * 10000) / 10000).replace('.', ',');
      document.getElementById('importPriceFormula').textContent = `(precio × ${increments.map(v => pct(1 + v / 100)).join(' × ')} = × ${pct(window.SmartPricing.factor(increments))} · $100 → ${moneyPlain(applyIncrements(100, increments))})`;
      importFilesList.forEach(fileState => parseProductsForFile(fileState));
      renderFilesChips();
      refreshCombinedPreview();
    }));

    window.updateImportProductCategory = (fileIdx, prodIdx, val) => {
      if (importFilesList[fileIdx]?.parsedProducts[prodIdx]) {
        importFilesList[fileIdx].parsedProducts[prodIdx].category = val; importFilesList[fileIdx].parsedProducts[prodIdx].categoryManual = true;
      }
    };

    window.updateImportProductSubcategory = (fileIdx, prodIdx, val) => {
      if (importFilesList[fileIdx]?.parsedProducts[prodIdx]) {
        importFilesList[fileIdx].parsedProducts[prodIdx].subcategory = val; importFilesList[fileIdx].parsedProducts[prodIdx].categoryManual = true;
      }
    };

    const refreshCombinedPreview = () => {
      const allProducts = [];
      importFilesList.forEach((fileState, fIdx) => {
        fileState.parsedProducts.forEach((prod, pIdx) => {
          allProducts.push({
            ...prod,
            _fileIndex: fIdx,
            _prodIndex: pIdx
          });
        });
      });

      const totalFiles = importFilesList.length;
      document.getElementById('previewCountBadge').textContent = `Total detectados: ${allProducts.length} productos en ${totalFiles} ${totalFiles === 1 ? 'archivo' : 'archivos'}`;
      document.getElementById('importFileStats').textContent = `${totalFiles} ${totalFiles === 1 ? 'archivo cargado' : 'archivos cargados'} • ${allProducts.length} productos listos`;

      const tbody = document.getElementById('importPreviewTableBody');
      const previewItems = allProducts.slice(0, 40);

      tbody.innerHTML = previewItems.map(p => {
        const badgeClass = p.affinityInfo.confidence.startsWith('exact') ? 'exact' : (p.affinityInfo.confidence === 'keyword' ? 'keyword' : (p.affinityInfo.confidence === 'product' ? 'product' : 'fallback'));
        const isQuotation = p.price === 0;

        return `
          <tr>
            <td>
              ${p.imageUrl
                ? `<img src="${p.imageUrl}" loading="lazy" class="prod-thumb-img" style="width:34px; height:34px;">`
                : '<div class="prod-thumb-placeholder" style="width:34px; height:34px;"><i data-lucide="camera" width="14"></i></div>'
              }
            </td>
            <td>
              <strong class="prod-title" style="max-width:240px; font-size:12.5px;">${p.name}</strong>
            </td>
            <td><code>${p.sku || '—'}</code></td>
            <td>
              ${isQuotation 
                ? '<span class="badge-quote is-warn">Solicitar cotización</span>' 
                : `<strong>${formatMoney(p.price)}</strong>`
              }
            </td>
            <td>
              <span class="file-count-badge" style="font-size:10px; margin-bottom:2px; display:inline-flex; align-items:center; gap:3px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${p.fileName}"><i data-lucide="file" width="10"></i> ${p.fileName}</span>
              <div style="font-size:11px; color:var(--muted);">${p.rawCategory ? escapeHtmlEditor(p.rawCategory) : '<em>(Vacío)</em>'}</div>
            </td>
            <td>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <select class="filter-select" style="padding:4px 8px; font-size:12px; font-weight:600;" onchange="updateImportProductCategory(${p._fileIndex}, ${p._prodIndex}, this.value)">
                  ${categoriesList.map(c => `<option value="${c.name}" ${c.name === p.category ? 'selected' : ''}>${c.name}</option>`).join('')}
                </select>
                <span class="affinity-badge ${badgeClass}">${p.affinityInfo.badgeText}</span>
              </div>
            </td>
            <td>
              <input type="text" class="form-control" style="padding:4px 8px; font-size:12px;" value="${p.subcategory || ''}" placeholder="Opcional" onchange="updateImportProductSubcategory(${p._fileIndex}, ${p._prodIndex}, this.value)">
            </td>
          </tr>
        `;
      }).join('');

      if (allProducts.length > 40) {
        tbody.innerHTML += `
          <tr>
            <td colspan="7" style="text-align:center; padding:12px; background:var(--surface-2); color:var(--muted); font-size:12px;">
              ... y ${allProducts.length - 40} productos más que se importarán con este mismo mapeo.
            </td>
          </tr>
        `;
      }
      lucide.createIcons();
    };

    // --- EJECUTAR IMPORTACIÓN MASIVA EN LOTES ---
    document.getElementById('btnExecuteImport').addEventListener('click', async () => {
      const allProducts = [];
      importFilesList.forEach(f => allProducts.push(...f.parsedProducts));

      if (!allProducts.length) return alert('No hay productos válidos para importar en los archivos cargados.');

      const autoFetchPhotos = document.getElementById('chkAutoFetchImages').checked;
      const total = allProducts.length;
      const totalFiles = importFilesList.length;

      if (importRunning) return;
      if (!confirm(`¿Confirmas la importación de ${total} productos provenientes de ${totalFiles} archivo(s) a la tienda SmartISP?`)) return;

      const executeBtn = document.getElementById('btnExecuteImport');
      importRunning = true;
      executeBtn.disabled = true;
      renderImportSummary(0, 0, []);
      const progressWrap = document.getElementById('importProgressWrap');
      const progressBar = document.getElementById('importProgressBar');
      const progressPct = document.getElementById('importProgressPct');
      const progressText = document.getElementById('importProgressText');
      progressWrap.style.display = 'block';

      // 1. Auto-fetch de fotos si el usuario lo activó explícitamente (máximo 6 y con pausas seguras)
      if (autoFetchPhotos) {
        progressText.textContent = 'Buscando fotografías en internet para productos sin imagen...';
        const missingPhotos = allProducts.filter(p => !p.imageUrl).slice(0, 6);
        let fetchedCount = 0;
        for (const item of missingPhotos) {
          try {
            const res = await api(`search-product-image?q=${encodeURIComponent(item.name)}&category=${encodeURIComponent(item.category)}&limit=1`);
            if (res.images && res.images.length > 0) {
              item.imageUrl = res.images[0].url;
              fetchedCount++;
            }
          } catch (e) {}
          await new Promise(r => setTimeout(r, 600));
        }
      }

      // 2. Subida por lotes con doble contingencia (Lote rápido + Fallback unitario infalible)
      const BATCH_SIZE = 10;
      let importedCount = 0;
      const failures = [];

      for (let i = 0; i < total; i += BATCH_SIZE) {
        const batch = allProducts.slice(i, i + BATCH_SIZE);
        const currentProgress = Math.min(i + BATCH_SIZE, total);
        progressText.textContent = `Guardando productos (${currentProgress} de ${total})...`;
        const pct = Math.round((currentProgress / total) * 100);
        progressBar.style.width = `${pct}%`;
        progressPct.textContent = `${pct}%`;

        // Solo se envía lo que trae el archivo: en productos existentes, lo ausente o vacío no se toca.
        const cleanBatch = batch.map(p => ({
          id: p.id || undefined,
          name: String(p.name || '').trim(),
          description: String(p.description || '').trim(),
          price: p.priceProvided === false ? '' : (Number(p.price) || 0),
          category: p.rawCategory === '' && !p.categoryManual ? '' : String(p.category || '').trim(),
          subcategory: p.rawCategory === '' && !p.categoryManual ? '' : String(p.subcategory || '').trim(),
          imageUrl: String(p.imageUrl || p.image_url || '').trim(),
          externalUrl: String(p.externalUrl || p.external_url || '').trim(),
          sku: String(p.sku || '').trim(),
          cost: p.cost ?? undefined
        }));

        let batchSuccess = false;

        // Estrategia Nivel 1: Envío por lote (rápido y optimizado)
        try {
        const rawJson = JSON.stringify({
            action: 'import-products',
            products: cleanBatch
          });
          const b64 = toUtf8Base64(rawJson);
          const fd = new FormData();
          fd.append('action', 'import-products');
          if (b64) fd.append('payload', b64);
          fd.append('products', rawJson);

          const res = await api('import-products', {
            method: 'POST',
            body: fd
          });

          if (res && (res.ok || res.count !== undefined)) {
            const batchErrors = Array.isArray(res.errors) ? res.errors : [];
            importedCount += res.count !== undefined ? Number(res.count) || 0 : batch.length - batchErrors.length;
            batchErrors.forEach(err => {
              const index = Number(err.row);
              const source = Number.isInteger(index) && cleanBatch[index] ? cleanBatch[index] : null;
              failures.push({ name: err.name || source?.name || '', error: err.error || 'Error desconocido' });
            });
            batchSuccess = true;
          }
        } catch (batchErr) {
          console.warn('Lote masivo filtrado por WAF, activando guardado unitario resiliente:', batchErr.message);
        }

        // Estrategia Nivel 2 (Fallback infalible): Si el firewall bloqueó el lote agrupado, guardar individualmente cada producto
        if (!batchSuccess) {
          for (const prod of cleanBatch) {
            try {
              const singleJson = JSON.stringify({ action: 'import-products', products: [prod] });
              const singleFd = new FormData();
              singleFd.append('action', 'import-products');
              const singleB64 = toUtf8Base64(singleJson);
              if (singleB64) singleFd.append('payload', singleB64);
              singleFd.append('products', singleJson);
              const singleRes = await api('import-products', { method: 'POST', body: singleFd });
              const singleError = Array.isArray(singleRes?.errors) ? singleRes.errors[0] : null;
              if (singleError) throw new Error(singleError.error || 'Error desconocido');
              importedCount++;
            } catch (singleErr) {
              console.warn('Error al guardar producto individual:', prod.name, singleErr.message);
              failures.push({ row: i + cleanBatch.indexOf(prod) + 1, name: prod.name, error: singleErr.message });
            }
            await new Promise(r => setTimeout(r, 60));
          }
        }

        // Pausa preventiva entre lotes
        if (i + BATCH_SIZE < total) {
          await new Promise(r => setTimeout(r, 150));
        }
      }

      importRunning = false;
      executeBtn.disabled = false;
      if (failures.length) {
        progressText.textContent = `Importación terminada con errores: ${importedCount} de ${total} productos importados.`;
        renderImportSummary(importedCount, total, failures);
        showToast(`${importedCount} de ${total} productos importados; ${failures.length} con error. Revisa la lista.`, 'error');
        await loadAllData();
        return;
      }
      showToast(`Se importaron ${importedCount} de ${total} productos de ${totalFiles} archivo(s).`);
      progressText.textContent = `Importación finalizada: ${importedCount} de ${total} productos importados.`;
      await loadAllData();

      setTimeout(() => {
        switchTab('tab-products');
        resetImportFile();
      }, 1500);
    });

    // --- AUTENTICACIÓN Y CIERRE DE SESIÓN ---
    document.getElementById('logoutBtn')?.addEventListener('click', async () => {
      try { await api('logout', { method: 'POST' }); } catch (e) {}
      localStorage.removeItem('smartisp.accountUser');
      localStorage.removeItem('smartisp.adminToken');
      sessionStorage.removeItem('smartisp.accountUser');
      sessionStorage.removeItem('smartisp.adminToken');
      window.location.href = '/login.html?admin=1';
    });

    // Verificación de sesión de Administrador
    (async () => {
      let user = null;
      try {
        const sessionRes = await api('me');
        if (sessionRes && sessionRes.user) {
          user = sessionRes.user;
        }
      } catch (e) {
        console.info('No hay sesión administrativa activa.');
      }

      if (!isAdminRole(user)) {
        currentAdminUser = null;
        const redirect = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.replace(`/login.html?admin=1&redirect=${redirect}`);
        return;
      }
      currentAdminUser = user;
      const badge = document.getElementById('userBadge');
      if (badge) {
        badge.style.display = 'inline-flex';
        badge.style.alignItems = 'center';
        badge.style.gap = '5px';
        badge.replaceChildren();
        const icon = document.createElement('i');
        icon.dataset.lucide = 'user';
        icon.setAttribute('width', '13');
        badge.append(icon, document.createTextNode(user.email || user.name || 'Admin'));
        window.lucide?.createIcons();
      }
      // ?q= llega del buscador global (Ctrl+K): la primera carga ya sale filtrada.
      const initialQuery = (new URLSearchParams(window.location.search).get('q') || '').trim();
      const searchInput = document.getElementById('productSearchInput');
      if (initialQuery && searchInput) searchInput.value = initialQuery;

      await loadAllData();

      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('new') === '1') {
        if (!urlParams.get('tab')) switchTab('tab-products');
        document.getElementById('btnNewProduct')?.click();
      }
      const requestedTab = urlParams.get('tab');
      if (requestedTab && document.getElementById(requestedTab)) {
        switchTab(requestedTab);
      }
      // Enlaces directos desde el dashboard: ?photo=without_photo, ?visible=hidden, ?price=quote.
      let presetFilter = false;
      [['photo', 'filterPhoto'], ['visible', 'filterVisibility'], ['price', 'filterPrice']].forEach(([param, id]) => {
        const select = document.getElementById(id);
        const value = urlParams.get(param);
        if (select && value && [...select.options].some(o => o.value === value)) { select.value = value; presetFilter = true; }
      });
      const initialCat = urlParams.get('category') || urlParams.get('macro');
      if (presetFilter && !initialCat) {
        if (!requestedTab) switchTab('tab-products');
        loadProductsPage(1);
      }
      if (initialCat) {
        if (!requestedTab) switchTab('tab-products');
        const filterMacro = document.getElementById('filterMacroCat');
        if (filterMacro) {
          filterMacro.value = initialCat;
          updateSubcategoryOptions();
          loadProductsPage(1);
        }
      }
    })();
