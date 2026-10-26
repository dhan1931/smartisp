		let products = [];
		// DEV-20261006-002: products.indexOf(product) en cada tarjeta era O(n) por producto, O(n^2)
		// por render completo -- con 2700+ productos (y de nuevo en cada tecla al buscar, sin
		// debounce) esto se sentia. Un Map por referencia de objeto da la misma posicion en O(1).
		let productIndexMap = new Map();
		const rebuildProductIndex = () => { productIndexMap = new Map(products.map((p, i) => [p, i])); };
		let isCatalogLoading = true;
		let catalogLoadError = false;
		let fullCatalogState = 'idle'; // 'idle' | 'loading' | 'loaded'

		// Trae el resto del catálogo solo cuando una vista local realmente lo necesita.
		const ensureFullCatalog = async () => {
			if (fullCatalogState !== 'idle') return;
			fullCatalogState = 'loading';
			// El backend pagina de verdad (máximo 100 por página, DEV-20261005-022): pedir
			// "todo sin parámetros" ya no trae más de una página. Se recorren las páginas que
			// hagan falta y se arma el listado completo en el navegador para que buscar, filtrar
			// por categoría y "ver más" sigan funcionando como antes.
			const PAGE_SIZE = 100;
			const MAX_PAGES = 60; // tope de seguridad (~6000 productos); evita un bucle sin fin
			try {
				const first = await fetch(`/api/auth/catalog?page=1&limit=${PAGE_SIZE}&_t=${Date.now()}`, { cache: 'no-store' });
				if (!first.ok) throw new Error('catalog page 1 failed');
				const firstData = await first.json();
				let all = Array.isArray(firstData.products) ? firstData.products.slice() : [];
				const totalPages = Math.min(MAX_PAGES, Number(firstData.totalPages) || 1);

				for (let page = 2; page <= totalPages; page++) {
					const res = await fetch(`/api/auth/catalog?page=${page}&limit=${PAGE_SIZE}&_t=${Date.now()}`, { cache: 'no-store' });
					if (!res.ok) break;
					const data = await res.json();
					if (!Array.isArray(data.products) || !data.products.length) break;
					all = all.concat(data.products);
				}

				fullCatalogState = 'loaded';
				if (all.length > products.length) {
					products = all.map(product => ({
						...product,
						brand: product.category || 'SmartISP',
						image: getProductImageUrl(product),
						reviews: Number(product.reviews || 0),
						tag: product.tag || '',
						specs: Array.isArray(product.specs) ? product.specs : []
					}));
					rebuildProductIndex();
					render();
					if (typeof renderSidebarCategories === 'function') renderSidebarCategories();
					if (typeof checkDirectProductLanding === 'function') checkDirectProductLanding();
				}
			} catch (e) {
				fullCatalogState = 'idle'; // puede reintentarse en la próxima interacción
				console.warn('Carga del resto del catálogo:', e);
			}
		};

		let selectedFilter = 'all';
		let expandedGroups = new Set();
		let expandedCategories = new Set();

		// Todas las vistas del catálogo se consultan por páginas. Las categorías exactas usan
		// category/subcategory y los macrogrupos envían la lista de categorías asociadas.
		let serverView = { active: false, page: 1, totalPages: 1, total: 0, items: [] };
		const isServerMode = () => {
			const q = document.querySelector('#searchInput').value.trim();
			return q !== '' || selectedFilter === 'all' || !!(selectedFilter && ['macro', 'category', 'sub'].includes(selectedFilter.type));
		};
		const fetchServerPage = async reset => {
			if (reset) serverView.page = 1;
			const query = document.querySelector('#searchInput').value.trim();
			const sort = document.querySelector('#sortSelect').value;
			const params = new URLSearchParams({ page: serverView.page, limit: 36 });
			if (query) params.set('q', query);
			if (sort === 'low' || sort === 'high') params.set('sort', sort);
			if (selectedFilter && selectedFilter.type === 'category') params.set('category', selectedFilter.category);
			else if (selectedFilter && selectedFilter.type === 'sub') params.set('subcategory', selectedFilter.category);
			else if (selectedFilter && selectedFilter.type === 'macro') {
				const macroCategories = catalogCategoryRows
					.filter(row => Number(row.productCount || 0) > 0 && getMacroCategory(row.name).id === selectedFilter.id)
					.map(row => row.name);
				params.set('category_list', JSON.stringify(macroCategories));
			}
			try {
				const res = await fetch(`/api/auth/catalog?${params.toString()}&_t=${Date.now()}`, { cache: 'no-store' });
				if (!res.ok) throw new Error('catalog view fetch failed');
				const data = await res.json();
				const mapped = (data.products || []).map(product => ({
					...product,
					brand: product.category || 'SmartISP',
					image: getProductImageUrl(product),
					reviews: Number(product.reviews || 0),
					tag: product.tag || '',
					specs: Array.isArray(product.specs) ? product.specs : []
				}));
				serverView.active = true;
				serverView.total = data.total || 0;
				serverView.totalPages = data.totalPages || 1;
				// Paginacion real por numero de pagina: cada carga reemplaza la pagina mostrada,
				// nunca se acumula (antes esto era un "ver mas" infinito que concatenaba).
				serverView.items = mapped;

				// products sigue siendo donde viven los objetos que data-index/cart/ficha buscan por
				// posicion; se fusiona lo nuevo ahi (sin duplicar) para que esas partes no cambien.
				const existingIds = new Set(products.map(p => String(p.id)));
				let changed = false;
				mapped.forEach(p => { if (!existingIds.has(String(p.id))) { products.push(p); existingIds.add(String(p.id)); changed = true; } });
				if (changed) rebuildProductIndex();
			} catch (e) {
				console.warn('Error cargando la vista filtrada del catálogo:', e);
				serverView.active = false;
			}
		};
		const refreshView = async (resetPage = true) => {
			if (isServerMode()) {
				await fetchServerPage(resetPage);
			} else {
				serverView.active = false;
				ensureFullCatalog();
			}
			if (resetPage) clientPage = 1;
			render();
		};
		const renderSkeletons = (count = 8) => {
			const productsEl = document.querySelector('#products');
			if (!productsEl) return;
			productsEl.innerHTML = Array.from({ length: count }, () => `
				<article class="product skeleton-card">
					<div class="product-image skeleton-box" style="border:none;"></div>
					<div class="product-info">
						<div class="skeleton-box" style="height:12px; width:45%; margin-bottom:8px;"></div>
						<div class="skeleton-box" style="height:18px; width:90%; margin-bottom:12px;"></div>
						<div class="skeleton-box" style="height:14px; width:35%; margin-bottom:14px;"></div>
						<div class="price-row" style="margin-top:auto;">
							<div class="skeleton-box" style="height:24px; width:55%;"></div>
							<div class="skeleton-box" style="height:35px; width:35px; border-radius:4px;"></div>
						</div>
					</div>
				</article>
			`).join('');
		};
		const productKey = product => String(product?.id || product?.sku || product?.name || 'item');
		const toCartItem = (product, quantity) => ({
			id: product.id ?? '',
			sku: product.sku || '',
			name: String(product.name || ''),
			price: Math.max(0, Number(product.price || 0)),
			image: product.image || product.imageUrl || '',
			category: product.category || '',
			quantity
		});
		const normalizeCart = list => {
			const map = new Map();
			(Array.isArray(list) ? list : []).forEach(item => {
				if (!item || typeof item !== 'object') return;
				const key = productKey(item);
				const qty = Math.max(1, Math.min(99, Number(item.quantity) || 1));
				if (map.has(key)) {
					const existing = map.get(key);
					existing.quantity = Math.min(99, existing.quantity + qty);
				} else {
					map.set(key, toCartItem(item, qty));
				}
			});
			return Array.from(map.values());
		};
		const storeGet = (key, session = false) => { try { return (session ? sessionStorage : localStorage).getItem(key); } catch (e) { return null; } };
		const storeSet = (key, value, session = false) => { try { (session ? sessionStorage : localStorage).setItem(key, value); } catch (e) {} };
		const storeRemove = (key, session = false) => { try { (session ? sessionStorage : localStorage).removeItem(key); } catch (e) {} };
		let cart = [];
		try {
			cart = normalizeCart(JSON.parse(localStorage.getItem('smartisp.cart') || '[]'));
		} catch (e) { cart = []; }
		let signedIn = false;
		let wishlistIds = new Set();
		const money = value => {
			const num = Number(value || 0);
			return num > 0 ? '$' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Solicitar cotización';
		};
		const SMARTISP_FALLBACK_SVG = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(`
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 340" width="400" height="340">
  <rect width="100%" height="100%" fill="#f1f5f9"/>
  <rect x="20" y="20" width="360" height="300" rx="10" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="2"/>
  <path d="M140 130h120v80H140z" fill="none" stroke="#94a3b8" stroke-width="5" stroke-linejoin="round"/>
  <circle cx="170" cy="155" r="10" fill="#94a3b8"/>
  <path d="M140 190l35-25 35 20 30-15 20 15" fill="none" stroke="#94a3b8" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
  <text x="200" y="245" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="16" font-weight="700" fill="#475569" text-anchor="middle">SmartISP</text>
  <text x="200" y="268" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="12" fill="#94a3b8" text-anchor="middle">Imagen en actualización</text>
</svg>`.trim());

		const fallbackImage = product => {
			const cat = String(product?.category || '').toLowerCase();
			if (cat.includes('laptop') || cat.includes('notebook')) return 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=600&q=80';
			if (cat.includes('red') || cat.includes('router') || cat.includes('switch') || cat.includes('network')) return 'https://images.unsplash.com/photo-1647427060118-4911c9821b82?auto=format&fit=crop&w=600&q=80';
			if (cat.includes('servidor') || cat.includes('server')) return 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=600&q=80';
			if (cat.includes('monitor') || cat.includes('pantalla')) return 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=600&q=80';
			if (cat.includes('cable') || cat.includes('accesorio')) return 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=600&q=80';
			return SMARTISP_FALLBACK_SVG;
		};

		const isSupplierUrl = (url) => {
			if (!url || typeof url !== 'string') return false;
			const trimmed = url.trim();
			if (!/^https?:\/\//i.test(trimmed)) return false;
			if (trimmed.includes('siglo21.net') || trimmed.includes('wp-content/uploads')) return true;
			if (trimmed.includes('intcomex') || trimmed.includes('1worldsync.com')) return true;
			try {
				const parsed = new URL(trimmed);
				const host = parsed.hostname.toLowerCase();
				if (host === 'smart-isp.com.ec' || host.endsWith('.smart-isp.com.ec') || host === 'localhost' || host === '127.0.0.1') return false;
				if (host === 'images.unsplash.com') return false;
				return true;
			} catch (e) {
				return false;
			}
		};

		const toBase64Url = (str) => {
			try {
				return btoa(unescape(encodeURIComponent(str))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
			} catch (e) {
				return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
			}
		};

		const getProductImageUrl = product => {
			if (!product) return fallbackImage(null);
			const raw = product.image || product.imageUrl || '';
			if (!raw) return fallbackImage(product);
			if (raw.startsWith('/api/') || raw.startsWith('api/')) return raw;
			if (isSupplierUrl(raw)) {
				const id = product.id || product.sku || '';
				const basename = raw.split('/').pop().split('?')[0] || 'producto.jpg';
				const token = toBase64Url(raw);
				return `/api/auth/product-image?id=${encodeURIComponent(id)}&t=${token}&f=${encodeURIComponent(basename)}`;
			}
			return raw;
		};

		let toggleWishlist = (product, heartEl) => {
			if (!product) return;
			const key = productKey(product);
			if (wishlistIds.has(key)) {
				wishlistIds.delete(key);
				if (heartEl) heartEl.classList.remove('active');
			} else {
				wishlistIds.add(key);
				if (heartEl) heartEl.classList.add('active');
			}
		};

		// Familias del catálogo (category_macro_groups), con sus palabras clave, desde la API.
		// Respaldo si la API no las trae: las familias que vienen en cada categoría.
		let catalogFamilies = [];
		const OTHER_FAMILY = { id: 'otros', name: 'Otras Soluciones IT', icon: 'package', sortOrder: 99, keywords: [] };
		const getFamilies = () => {
			if (catalogFamilies.length) return catalogFamilies;
			const derived = new Map();
			catalogCategoryRows.forEach(row => {
				if (row.macroGroupId && !derived.has(row.macroGroupId)) derived.set(row.macroGroupId, { id: row.macroGroupId, name: row.macroGroupName || row.macroGroupId, icon: row.macroGroupIcon || 'package', sortOrder: Number(row.macroGroupSortOrder || 0), keywords: [] });
			});
			return [...derived.values()].sort((a, b) => a.sortOrder - b.sortOrder);
		};
		const getFamily = id => getFamilies().find(family => family.id === id) || (id === 'otros' ? OTHER_FAMILY : null);

		let catalogCategoryRows = [];
		let categoriasPageBanner = null;
		let catalogTotalCount = 0;
		const normalizeCategoryText = str => String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/_/g, ' ').trim();

		const formatCategoryName = name => {
			if (!name) return '';
			return String(name).replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
		};

		const getCategoryIcon = (name, id) => {
			const text = `${name || ''} ${id || ''}`.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
			if (text.includes('almacen') || text.includes('disco') || text.includes('ssd') || text.includes('hdd')) return 'hard-drive';
			if (text.includes('audio') || text.includes('auricular') || text.includes('sonido') || text.includes('parlante')) return 'headphones';
			if (text.includes('celular') || text.includes('smart') || text.includes('telefono') || text.includes('movil')) return 'smartphone';
			if (text.includes('cable') || text.includes('adaptador')) return 'cable';
			if (text.includes('clima') || text.includes('ventilador') || text.includes('aire')) return 'fan';
			if (text.includes('componente') || text.includes('ram') || text.includes('procesador') || text.includes('tarjeta')) return 'cpu';
			if (text.includes('laptop') || text.includes('portatil')) return 'laptop';
			if (text.includes('escritorio') || text.includes('computador') || text.includes('pc')) return 'monitor';
			if (text.includes('pantalla') || text.includes('monitor') || text.includes('proyeccion')) return 'monitor';
			if (text.includes('tv') || text.includes('televisor')) return 'tv';
			if (text.includes('consola') || text.includes('juego') || text.includes('gaming') || text.includes('silla')) return 'gamepad-2';
			if (text.includes('impres') || text.includes('multifuncional') || text.includes('toner') || text.includes('cartucho')) return 'printer';
			if (text.includes('vestible') || text.includes('reloj') || text.includes('watch')) return 'watch';
			if (text.includes('electro') || text.includes('blanca') || text.includes('hogar')) return 'home';
			if (text.includes('mochila') || text.includes('soporte') || text.includes('maletin')) return 'briefcase';
			if (text.includes('periferico') || text.includes('teclado') || text.includes('mouse')) return 'mouse';
			if (text.includes('energia') || text.includes('ups') || text.includes('proteccion de poder') || text.includes('bateria')) return 'zap';
			if (text.includes('pos') || text.includes('punto de venta') || text.includes('caja')) return 'shopping-bag';
			if (text.includes('red') || text.includes('comunicac') || text.includes('router') || text.includes('switch') || text.includes('wifi')) return 'network';
			if (text.includes('seguridad') || text.includes('vigilancia') || text.includes('camara') || text.includes('cctv')) return 'shield-check';
			if (text.includes('software') || text.includes('licencia') || text.includes('antivirus')) return 'file-code';
			if (text.includes('tablet')) return 'tablet';
			if (text.includes('accesorio')) return 'layers';
			return 'package';
		};

		const findCategoryRow = name => {
			const norm = normalizeCategoryText(name);
			return norm ? catalogCategoryRows.find(cat => normalizeCategoryText(cat.name) === norm) || null : null;
		};
		const findParentRow = row => row && row.parentCategoryId ? catalogCategoryRows.find(cat => String(cat.id) === String(row.parentCategoryId)) || null : null;

		// La familia asignada en el panel manda; una subcategoría sigue a su principal. Solo una
		// categoría sin familia se ubica por el nombre y las palabras clave de cada familia.
		const getMacroCategory = categoryName => {
			const norm = normalizeCategoryText(categoryName);
			const row = findCategoryRow(categoryName);
			const source = row && row.macroGroupId ? row : findParentRow(row);
			if (source && source.macroGroupId) {
				return getFamily(source.macroGroupId) || { id: source.macroGroupId, name: source.macroGroupName || OTHER_FAMILY.name, icon: source.macroGroupIcon || 'package', sortOrder: 999, keywords: [] };
			}
			if (norm) {
				const families = getFamilies().filter(family => family.id !== 'otros');
				const keywordsOf = family => (family.keywords || []).map(normalizeCategoryText).filter(Boolean);
				const match = families.find(family => normalizeCategoryText(family.id) === norm || normalizeCategoryText(family.name) === norm)
					|| families.find(family => keywordsOf(family).includes(norm))
					|| families.find(family => keywordsOf(family).some(kw => norm.includes(kw) || kw.includes(norm)));
				if (match) return match;
			}
			return getFamily('otros') || OTHER_FAMILY;
		};

		// Filtrar por una categoría principal incluye sus subcategorías; por una subcategoría,
		// también los productos de su principal que la llevan como subcategoría.
		const productInCategory = (product, name) => {
			const row = findCategoryRow(name);
			const parent = findParentRow(row);
			const target = normalizeCategoryText(name);
			const cat = normalizeCategoryText(product.category);
			if (cat === target) return true;
			if (row && catalogCategoryRows.some(child => String(child.parentCategoryId || '') === String(row.id) && normalizeCategoryText(child.name) === cat)) return true;
			return Boolean(parent) && cat === normalizeCategoryText(parent.name) && normalizeCategoryText(product.subcategory) === target;
		};

		const productMatchesFilter = (product, filter) => {
			if (!filter || filter === 'all' || filter.type === 'all') return true;
			const prodCat = String(product.category || '').trim();
			const prodSub = String(product.subcategory || '').trim();
			if (filter.type === 'macro') {
				const macro = getMacroCategory(prodCat) || getMacroCategory(prodSub);
				return macro && (macro.id === filter.id || normalizeCategoryText(macro.name) === normalizeCategoryText(filter.id));
			}
			if (filter.type === 'category') {
				return productInCategory(product, filter.category);
			}
			if (filter.type === 'sub') {
				return productInCategory(product, filter.category) || normalizeCategoryText(prodSub) === normalizeCategoryText(filter.category);
			}
			if (typeof filter === 'string') {
				if (filter.toLowerCase() === 'all') return true;
				const target = normalizeCategoryText(filter);
				const macro = getMacroCategory(filter);
				if (macro && (macro.id === target || normalizeCategoryText(macro.name) === target)) {
					const prodMacro = getMacroCategory(prodCat);
					return prodMacro && prodMacro.id === macro.id;
				}
				return normalizeCategoryText(prodCat) === target || normalizeCategoryText(prodSub) === target;
			}
			return true;
		};

		// Familia → categorías principales → subcategorías, con los conteos que calcula la API.
		const buildCategoryTree = () => {
			const groups = new Map();
			const ensureGroup = family => {
				if (!groups.has(family.id)) groups.set(family.id, { id: family.id, name: family.name, icon: family.icon, sortOrder: Number(family.sortOrder ?? 999), totalCount: 0, subcategories: [] });
				return groups.get(family.id);
			};
			getFamilies().forEach(ensureGroup);
			const byCount = (a, b) => b.count - a.count || a.displayName.localeCompare(b.displayName, 'es');
			if (catalogCategoryRows.length) {
				const ids = new Set(catalogCategoryRows.map(row => String(row.id)));
				const countOf = row => Number(row.totalCount ?? row.productCount ?? 0);
				const isChild = row => Boolean(row.parentCategoryId) && ids.has(String(row.parentCategoryId));
				catalogCategoryRows.forEach(row => {
					const top = isChild(row) ? findParentRow(row) : row;
					ensureGroup(getMacroCategory(top.name)).totalCount += Number(row.productCount || 0);
					if (isChild(row) || countOf(row) <= 0) return;
					const children = catalogCategoryRows
						.filter(child => isChild(child) && String(child.parentCategoryId) === String(row.id) && countOf(child) > 0)
						.map(child => ({ rawName: child.name, displayName: formatCategoryName(child.name), count: countOf(child) }))
						.sort(byCount);
					ensureGroup(getMacroCategory(row.name)).subcategories.push({ rawName: row.name, displayName: formatCategoryName(row.name), count: countOf(row), children });
				});
			} else {
				products.forEach(product => {
					const name = String(product.category || 'Otros').trim();
					const group = ensureGroup(getMacroCategory(name));
					let sub = group.subcategories.find(item => item.rawName === name);
					if (!sub) group.subcategories.push(sub = { rawName: name, displayName: formatCategoryName(name), count: 0, children: [] });
					sub.count++;
					group.totalCount++;
				});
			}
			const result = [...groups.values()].filter(group => group.totalCount > 0);
			result.forEach(group => group.subcategories.sort(byCount));
			result.sort((a, b) => a.sortOrder - b.sortOrder);
			return { totalProducts: catalogTotalCount || products.length, groups: result };
		};

		const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#39;'
		}[char]));

		const renderPublicCategoryMenu = (totalProducts, groups) => {
			const list = document.querySelector('#publicNavCatList');
			if (!list) return;

			const rows = [
				{
					href: '/tienda.html#catalogo',
					label: 'Todo el catálogo',
					count: totalProducts,
					search: 'todo catalogo'
				},
				...groups.slice(0, 8).map(group => ({
					href: `/tienda.html?macro=${encodeURIComponent(group.id)}#catalogo`,
					label: group.name,
					count: group.totalCount,
					search: `${group.name} ${group.id} ${(group.subcategories || []).slice(0, 8).map(sub => sub.displayName).join(' ')}`
				}))
			];

			list.innerHTML = rows.map(row => `
				<a href="${escapeHtml(row.href)}" data-nav-cat="${escapeHtml(normalizeCategoryText(row.search))}">
					<span>${escapeHtml(row.label)}</span>
					<small>${row.count ? `${row.count} productos` : ''}</small>
					<i data-lucide="chevron-right" width="18"></i>
				</a>
			`).join('');
		};

		const updatePublicNavCategoryCounts = (totalProducts, groups) => {
			const countByMacro = new Map(groups.map(group => [String(group.id).toLowerCase(), group.totalCount]));
			const setCount = (selector, value) => {
				const node = document.querySelector(selector);
				if (node) node.textContent = value ? `${value} productos` : '';
			};

			setCount('#navCountAll', totalProducts);
			setCount('#navCountComputacion', countByMacro.get('computacion') || 0);
			setCount('#navCountComponentes', countByMacro.get('componentes') || 0);
			setCount('#navCountRedes', countByMacro.get('redes') || 0);
			setCount('#navCountMonitores', countByMacro.get('monitores') || 0);
			renderPublicCategoryMenu(totalProducts, groups);
		};

		const renderSidebarCategories = () => {
			const container = document.querySelector('#sidebarCategories');
			if (!container) return;

			const { totalProducts, groups } = buildCategoryTree();
			updatePublicNavCategoryCounts(totalProducts, groups);
			const isAllActive = !selectedFilter || selectedFilter === 'all';
			if (document.body.classList.contains('category-drawer-open') && !expandedGroups.size && groups[0]) {
				expandedGroups.add(groups[0].id);
			}

			let html = `
				<button type="button" class="sidebar-cat-btn ${isAllActive ? 'active' : ''}" data-filter-action="all" title="Ver todo el catálogo">
					<span class="sidebar-cat-icon"><i data-lucide="layers" width="16" height="16"></i></span>
					<span class="sidebar-cat-name">Todo el catálogo</span>
					<span class="sidebar-cat-count" id="catCountAll">${totalProducts}</span>
				</button>
			`;

			groups.forEach(group => {
				const isMacroActive = !isAllActive && selectedFilter.type === 'macro' && String(selectedFilter.id).toLowerCase() === String(group.id).toLowerCase();
				const hasActiveChild = !isAllActive && ['category', 'sub'].includes(selectedFilter.type) && String(selectedFilter.macroId).toLowerCase() === String(group.id).toLowerCase();
				const isExpanded = expandedGroups.has(group.id) || isMacroActive || hasActiveChild;
				const iconName = group.icon || getCategoryIcon(group.name, group.id);

				html += `
					<div class="sidebar-group ${isExpanded ? 'is-expanded' : ''} ${isMacroActive ? 'is-active-group' : ''} ${hasActiveChild ? 'has-active-child' : ''}" data-macro-id="${escapeHtml(group.id)}">
						<div class="sidebar-group-header">
							<button type="button" class="sidebar-group-btn" data-filter-action="macro" data-macro-id="${escapeHtml(group.id)}" title="Ver todos los productos de ${escapeHtml(group.name)}">
								<span class="sidebar-cat-icon"><i data-lucide="${escapeHtml(iconName)}" width="16" height="16"></i></span>
								<span class="sidebar-cat-name">${escapeHtml(group.name)}</span>
								<span class="sidebar-cat-count">${group.totalCount}</span>
							</button>
							<button type="button" class="sidebar-group-toggle" data-toggle-macro="${escapeHtml(group.id)}" aria-label="Expandir o contraer subcategorías de ${escapeHtml(group.name)}" title="Expandir/Contraer">
								<i data-lucide="chevron-down" width="15" height="15"></i>
							</button>
						</div>
						<div class="sidebar-subcat-list" id="subcat-list-${escapeHtml(group.id)}">
							${group.subcategories.map(sub => {
								const isActiveCategory = name => !isAllActive && ['category', 'sub'].includes(selectedFilter.type) &&
									normalizeCategoryText(selectedFilter.category) === normalizeCategoryText(name);
								const button = (item, extraClass = '') => `
									<button type="button" class="sidebar-subcat-btn${extraClass} ${isActiveCategory(item.rawName) ? 'active' : ''}" data-filter-action="sub" data-macro-id="${escapeHtml(group.id)}" data-category="${escapeHtml(item.rawName)}" title="Filtrar por ${escapeHtml(item.displayName)}">
										<span class="sidebar-subcat-bullet"></span>
										<span class="sidebar-subcat-name">${escapeHtml(item.displayName)}</span>
										<span class="sidebar-subcat-count">${item.count}</span>
									</button>
								`;
								if (!sub.children || !sub.children.length) return button(sub);
								const key = normalizeCategoryText(sub.rawName);
								const isOpen = expandedCategories.has(key) || isActiveCategory(sub.rawName) || sub.children.some(child => isActiveCategory(child.rawName));
								return `
									<div class="sidebar-subcat-row ${isOpen ? 'is-open' : ''}">
										${button(sub)}
										<button type="button" class="sidebar-subcat-toggle" data-toggle-category="${escapeHtml(key)}" aria-expanded="${isOpen}" aria-label="Ver subcategorías de ${escapeHtml(sub.displayName)}"><i data-lucide="chevron-down" width="13" height="13"></i></button>
									</div>
									<div class="sidebar-subcat-children">${sub.children.map(child => button(child, ' is-child')).join('')}</div>
								`;
							}).join('')}
						</div>
					</div>
				`;
			});

			container.innerHTML = html;
			if (window.lucide && typeof window.lucide.createIcons === 'function') {
				window.lucide.createIcons();
			}
		};

		const updateSidebarActiveStates = () => {
			const isAllActive = !selectedFilter || selectedFilter === 'all';
			const allBtn = document.querySelector('[data-filter-action="all"]');
			if (allBtn) allBtn.classList.toggle('active', isAllActive);

			document.querySelectorAll('.sidebar-group').forEach(group => {
				const macroId = group.dataset.macroId;
				const isMacroActive = !isAllActive && selectedFilter.type === 'macro' && String(selectedFilter.id).toLowerCase() === String(macroId).toLowerCase();
				const hasActiveChild = !isAllActive && ['category', 'sub'].includes(selectedFilter.type) && String(selectedFilter.macroId).toLowerCase() === String(macroId).toLowerCase();

				group.classList.toggle('is-active-group', isMacroActive);
				group.classList.toggle('has-active-child', hasActiveChild);

				const groupBtn = group.querySelector('.sidebar-group-btn') || group.querySelector(':scope > .sidebar-cat-btn');
				if (groupBtn) groupBtn.classList.toggle('active', isMacroActive);

				if (isMacroActive || hasActiveChild) {
					group.classList.add('is-expanded');
					expandedGroups.add(macroId);
				}
			});

			document.querySelectorAll('.sidebar-subcat-btn').forEach(btn => {
				const isSubActive = !isAllActive && ['category', 'sub'].includes(selectedFilter.type) &&
					normalizeCategoryText(btn.dataset.category || '') === normalizeCategoryText(selectedFilter.category || '');
				btn.classList.toggle('active', isSubActive);
			});
		};

		const PRODUCTS_PAGE_SIZE = 36;
		let clientPage = 1;
		const scrollToProductsTop = () => {
			document.querySelector('#products')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
		};
		const buildPaginationHtml = (current, total) => {
			if (total <= 1) return '';
			const pages = new Set([1, 2, 3, total - 2, total - 1, total, current - 2, current - 1, current, current + 1, current + 2]);
			const items = [...pages].filter(p => p >= 1 && p <= total).sort((a, b) => a - b);
			let html = '<div class="pagination-wrap">';
			html += '<nav class="pagination" aria-label="Paginación de productos">';
			html += `<button type="button" class="pagination-btn pagination-nav" data-page="${current - 1}" ${current <= 1 ? 'disabled' : ''}><i data-lucide="chevron-left" width="16"></i> Anterior</button>`;
			let prev = 0;
			items.forEach(p => {
				if (prev && p - prev > 1) html += '<span class="pagination-ellipsis">…</span>';
				html += `<button type="button" class="pagination-btn pagination-num${p === current ? ' is-current' : ''}" data-page="${p}" ${p === current ? 'aria-current="page"' : ''}>${p}</button>`;
				prev = p;
			});
			html += `<button type="button" class="pagination-btn pagination-nav" data-page="${current + 1}" ${current >= total ? 'disabled' : ''}>Siguiente <i data-lucide="chevron-right" width="16"></i></button>`;
			html += '</nav>';
			html += `<p class="pagination-caption">Página <strong>${current}</strong> de <strong>${total}</strong></p>`;
			html += '</div>';
			return html;
		};

		const render = () => {
			if (isCatalogLoading) {
				renderSkeletons(8);
				const countEl = document.querySelector('#resultCount');
				if (countEl) countEl.textContent = 'Cargando catálogo...';
				return;
			}
			const query = document.querySelector('#searchInput').value.toLowerCase();
			const sort = document.querySelector('#sortSelect').value;
			const serverMode = isServerMode();
			// Modo servidor (catalogo, busqueda o filtro exacto): el backend ya filtro,
			// ordeno y pagino en SQL (fetchServerPage) -- se usa tal cual, sin repetir el trabajo.
			let shown = serverMode ? serverView.items : products.filter(product => productMatchesFilter(product, selectedFilter) && `${product.name} ${product.brand} ${product.category}`.toLowerCase().includes(query));
			if (!serverMode) {
				if (sort === 'low') shown.sort((a,b) => {
					const pA = Number(a.price || 0), pB = Number(b.price || 0);
					if (pA <= 0 && pB <= 0) return 0;
					if (pA <= 0) return 1;
					if (pB <= 0) return -1;
					return pA - pB;
				});
				if (sort === 'high') shown.sort((a,b) => {
					const pA = Number(a.price || 0), pB = Number(b.price || 0);
					if (pA <= 0 && pB <= 0) return 0;
					if (pA <= 0) return 1;
					if (pB <= 0) return -1;
					return pB - pA;
				});
			}
			const totalMatching = serverMode ? serverView.total : shown.length;

			let activeFilterLabel = '';
			let breadcrumbLabel = '';

			if (selectedFilter && selectedFilter !== 'all') {
				if (selectedFilter.type === 'macro') {
					const macro = getFamily(selectedFilter.id);
					activeFilterLabel = macro ? macro.name : selectedFilter.name || 'Categoría';
					breadcrumbLabel = activeFilterLabel;
				} else if (['category', 'sub'].includes(selectedFilter.type)) {
					const macro = getFamily(selectedFilter.macroId) || getMacroCategory(selectedFilter.category);
					const subName = formatCategoryName(selectedFilter.category);
					activeFilterLabel = `${macro ? macro.name + ' › ' : ''}${subName}`;
					breadcrumbLabel = activeFilterLabel;
				} else if (typeof selectedFilter === 'string') {
					const macro = getMacroCategory(selectedFilter);
					activeFilterLabel = `${macro ? macro.name + ' › ' : ''}${formatCategoryName(selectedFilter)}`;
					breadcrumbLabel = activeFilterLabel;
				}
			}

			const countEl = document.querySelector('#resultCount');
			if (countEl) {
				const filterNote = breadcrumbLabel ? ` en ${breadcrumbLabel}` : '';
				const queryNote = query ? ` para "${query}"` : '';
				const countText = serverMode
					? `Mostrando ${shown.length} de ${totalMatching} producto${totalMatching === 1 ? '' : 's'}`
					: `Mostrando ${totalMatching} producto${totalMatching === 1 ? '' : 's'}`;
				countEl.textContent = `${countText}${filterNote}${queryNote}`;
			}

			const badge = document.querySelector('#activeCategoryBadge');
			const badgeName = document.querySelector('#activeCategoryName');
			if (badge && badgeName) {
				if (activeFilterLabel) {
					badgeName.textContent = activeFilterLabel;
					badge.style.display = 'inline-flex';
				} else {
					badge.style.display = 'none';
				}
			}

			const categoryHero = document.querySelector('#categoryHero');
			if (categoryHero) {
				const catRow = selectedFilter && selectedFilter.type === 'category'
					? catalogCategoryRows.find(row => normalizeCategoryText(row.name) === normalizeCategoryText(selectedFilter.category))
					: null;
				if (catRow && catRow.bannerImageUrl) {
					document.querySelector('#categoryHeroImg').src = catRow.bannerImageUrl;
					document.querySelector('#categoryHeroImg').alt = catRow.bannerAlt || catRow.name;
					document.querySelector('#categoryHeroTitle').textContent = catRow.name;
					const descEl = document.querySelector('#categoryHeroDesc');
					descEl.textContent = catRow.description || '';
					descEl.hidden = !catRow.description;
					categoryHero.hidden = false;
				} else if (!catRow && categoriasPageBanner) {
					document.querySelector('#categoryHeroImg').src = categoriasPageBanner.image;
					document.querySelector('#categoryHeroImg').alt = categoriasPageBanner.alt;
					document.querySelector('#categoryHeroTitle').textContent = categoriasPageBanner.title;
					const descEl = document.querySelector('#categoryHeroDesc');
					descEl.textContent = categoriasPageBanner.subtitle || '';
					descEl.hidden = !categoriasPageBanner.subtitle;
					categoryHero.hidden = false;
				} else {
					categoryHero.hidden = true;
				}
			}

			const totalPagesNum = serverMode ? serverView.totalPages : Math.max(1, Math.ceil(shown.length / PRODUCTS_PAGE_SIZE));
			const currentPageNum = serverMode ? serverView.page : clientPage;
			const toDisplay = serverMode ? shown : shown.slice((clientPage - 1) * PRODUCTS_PAGE_SIZE, clientPage * PRODUCTS_PAGE_SIZE);

			const productsHtml = toDisplay.map(product => {
				const pImg = getProductImageUrl(product);
				const fbImg = fallbackImage(product);
				const pUrl = getProductUrl(product);
				const hasReviews = Number(product.reviews || 0) > 0;
				const reviewsHtml = hasReviews
					? `<div><span class="stars">★★★★★</span><span class="reviews">(${Number(product.reviews)})</span></div>`
					: `<div><span class="reviews" style="margin-left:0;font-style:italic;">Sin reseñas aún</span></div>`;
				const productIndex = productIndexMap.get(product);
				const priority = productIndex < 4;
				const pName = escapeHtml(product.name);
				const saved = wishlistIds.has(productKey(product));
				return `<article class="product" data-index="${productIndex}" tabindex="0" role="group" aria-label="Ver especificaciones de ${pName}"><div class="product-image">${product.tag ? `<span class="tag">${escapeHtml(product.tag)}</span>` : ''}<button class="heart${saved ? ' is-saved' : ''}" aria-label="Agregar a favoritos" aria-pressed="${saved}"><i data-lucide="heart" width="16"></i></button><a href="${escapeHtml(pUrl)}" class="product-card-link" aria-label="${pName}" tabindex="-1"><img src="${escapeHtml(pImg)}" alt="${pName}" width="320" height="278" loading="${priority ? 'eager' : 'lazy'}" fetchpriority="${productIndex < 2 ? 'high' : 'auto'}" data-fallback="${escapeHtml(fbImg)}" onerror="if(!this.dataset.failed){this.dataset.failed='1';this.src=this.dataset.fallback;}"></a></div><div class="product-info"><div class="product-brand">${escapeHtml(product.brand)}</div><a href="${escapeHtml(pUrl)}" class="product-title-link" title="${pName}"><h3>${pName}</h3></a>${reviewsHtml}<div class="price-row"><div class="price-block"><span class="price ${Number(product.price || 0) <= 0 ? 'is-quote' : ''}">${money(product.price)}</span>${Number(product.price || 0) > 0 ? '<span class="price-tax-label">Precios incluyen IVA</span>' : ''}</div><a href="${escapeHtml(pUrl)}" class="btn-card-view" title="Ver producto en página completa" aria-label="Ver ficha completa de ${pName}"><i data-lucide="external-link" width="15"></i></a></div><button class="add" data-index="${productIndex}" aria-label="Agregar ${pName} al carrito"><i data-lucide="shopping-cart" width="16"></i> Agregar</button></div></article>`;
			}).join('');

			const paginationHtml = buildPaginationHtml(currentPageNum, totalPagesNum);
			const paginationWrapHtml = paginationHtml ? `<div class="pagination-container" style="grid-column: 1 / -1; display:flex; justify-content:center; padding: 24px 0 16px;">${paginationHtml}</div>` : '';

			const emptyHtml = catalogLoadError
				? '<div class="empty"><p>No se pudo cargar el catálogo.</p><button type="button" class="primary" id="retryCatalogBtn">Reintentar</button></div>'
				: '<div class="empty">No encontramos productos con esa búsqueda.</div>';
			document.querySelector('#products').innerHTML = shown.length ? (productsHtml + paginationWrapHtml) : emptyHtml;
			window.lucide && window.lucide.createIcons();
			const retryCatalogBtn = document.querySelector('#retryCatalogBtn');
			if (retryCatalogBtn) retryCatalogBtn.addEventListener('click', applyRemoteSite);

			document.querySelectorAll('.pagination-btn[data-page]').forEach(btn => {
				btn.addEventListener('click', () => {
					const targetPage = Number(btn.dataset.page);
					if (!targetPage || targetPage === currentPageNum || targetPage < 1 || targetPage > totalPagesNum) return;
					if (serverMode) {
						serverView.page = targetPage;
						fetchServerPage(false).then(() => { render(); scrollToProductsTop(); });
					} else {
						ensureFullCatalog();
						clientPage = targetPage;
						render();
						scrollToProductsTop();
					}
				});
			});

			document.querySelectorAll('.add').forEach(button => button.addEventListener('click', event => {
				event.stopPropagation();
				const prod = products[button.dataset.index];
				button.innerHTML = '<span style="font-size:16px;font-weight:900;line-height:1;">✓</span>';
				button.classList.add('is-added');
				setTimeout(() => {
					button.innerHTML = '<i data-lucide="shopping-cart" width="16"></i> Agregar';
					button.classList.remove('is-added');
					if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
				}, 900);
				if (prod) addToCart(prod);
			}));
			document.querySelectorAll('.heart').forEach(button => {
				const card = button.closest('.product');
				const prod = card ? products[card.dataset.index] : null;
				if (prod) {
					button.addEventListener('click', event => {
						event.stopPropagation();
						toggleWishlist(prod, button);
					});
				} else {
					button.addEventListener('click', event => event.stopPropagation());
				}
			});
			document.querySelectorAll('.product').forEach(card => {
				const open = () => { const product = products[card.dataset.index]; if (product) window.location.href = getProductUrl(product); };
				card.addEventListener('click', event => {
					if (event.target.closest('button')) return;
					if (event.target.closest('.btn-card-view')) return;
					if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
					event.preventDefault();
					open();
				});
				card.addEventListener('keydown', event => {
					if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button') && !event.target.closest('.btn-card-view')) {
						event.preventDefault();
						open();
					}
				});
			});
		};

		// NOTIFICACIÓN TOAST DE ALTO IMPACTO: PRODUCTO AGREGADO AL CARRITO
		const cartToast = document.createElement('div');
		cartToast.className = 'cart-toast';
		cartToast.setAttribute('role', 'status');
		cartToast.setAttribute('aria-live', 'polite');
		cartToast.innerHTML = `
			<div class="cart-toast-icon-wrap" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
					<polyline points="20 6 9 17 4 12"></polyline>
				</svg>
			</div>
			<img class="cart-toast-thumb" src="" alt="" style="display: none;">
			<div class="cart-toast-content">
				<div class="cart-toast-title">¡Se agregó correctamente al carrito!</div>
				<div class="cart-toast-name"></div>
			</div>
			<button type="button" class="cart-toast-btn" id="cartToastOpenBtn">Ver carrito</button>
			<button type="button" class="cart-toast-close" aria-label="Cerrar">&times;</button>
			<div class="cart-toast-progress"><div class="cart-toast-progress-bar"></div></div>
		`;
		document.body.append(cartToast);
		let cartToastTimer;

		cartToast.querySelector('#cartToastOpenBtn')?.addEventListener('click', () => {
			cartToast.classList.remove('is-visible');
			document.querySelector('#openCart')?.click();
		});

		cartToast.querySelector('.cart-toast-close')?.addEventListener('click', () => {
			clearTimeout(cartToastTimer);
			cartToast.classList.remove('is-visible');
		});

		cartToast.addEventListener('mouseenter', () => {
			clearTimeout(cartToastTimer);
			const bar = cartToast.querySelector('.cart-toast-progress-bar');
			if (bar) bar.style.animationPlayState = 'paused';
		});
		cartToast.addEventListener('mouseleave', () => {
			const bar = cartToast.querySelector('.cart-toast-progress-bar');
			if (bar) bar.style.animationPlayState = 'running';
			cartToastTimer = setTimeout(() => {
				cartToast.classList.remove('is-visible');
			}, 2000);
		});

		const showCartToast = product => {
			clearTimeout(cartToastTimer);
			const nameEl = cartToast.querySelector('.cart-toast-name');
			const thumbEl = cartToast.querySelector('.cart-toast-thumb');
			const iconEl = cartToast.querySelector('.cart-toast-icon-wrap');
			const bar = cartToast.querySelector('.cart-toast-progress-bar');

			if (nameEl) nameEl.textContent = (product && product.name) ? product.name : 'Producto añadido';

			const imgUrl = (product && product.image) || (product && typeof fallbackImage === 'function' ? fallbackImage(product) : '');
			if (thumbEl && imgUrl) {
				thumbEl.src = imgUrl;
				thumbEl.alt = (product && product.name) ? product.name : '';
				thumbEl.style.display = 'block';
			} else if (thumbEl) {
				thumbEl.style.display = 'none';
			}

			if (iconEl) {
				iconEl.style.animation = 'none';
				void iconEl.offsetWidth;
				iconEl.style.animation = '';
			}
			if (bar) {
				bar.style.animation = 'none';
				bar.style.animationPlayState = 'running';
				void bar.offsetWidth;
				bar.style.animation = '';
			}

			cartToast.classList.remove('is-visible');
			requestAnimationFrame(() => {
				cartToast.classList.add('is-visible');
			});

			cartToastTimer = setTimeout(() => {
				cartToast.classList.remove('is-visible');
			}, 4000);
		};

		const slugifyText = text => {
			return String(text || '')
				.normalize('NFD')
				.replace(/[\u0300-\u036f]/g, '')
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-+|-+$/g, '')
				.slice(0, 80) || 'articulo';
		};

		const getProductUrl = product => {
			if (!product) return '/tienda.html';
			const pid = product.id || product.sku || '';
			const slug = slugifyText(product.name || '');
			return `/producto/${encodeURIComponent(pid)}-${slug}`;
		};

		const addToCart = product => {
			if (!product) return;
			const key = productKey(product);
			const existing = cart.find(i => productKey(i) === key);
			if (existing) {
				if ((existing.quantity || 1) >= 99) {
					alert('Has alcanzado el límite máximo de 99 unidades para este producto.');
					return;
				}
				existing.quantity = Math.min(99, (existing.quantity || 1) + 1);
			} else {
				cart.push(toCartItem(product, 1));
			}
			updateCart(true);
			showCartToast(product);
		};

		const changeCartQuantity = (index, delta) => {
			if (!cart[index]) return;
			const newQty = (Number(cart[index].quantity) || 1) + delta;
			if (newQty <= 0) {
				cart.splice(index, 1);
			} else {
				cart[index].quantity = Math.min(99, newQty);
			}
			updateCart(false);
		};

		const updateCart = (animate = true) => {
			storeSet('smartisp.cart', JSON.stringify(cart));
			const totalQuantity = cart.reduce((sum, item) => sum + (Number(item.quantity) || 1), 0);
			const count = document.querySelector('#cartCount');
			if (count) count.textContent = totalQuantity;
			const mCount = document.querySelector('#mCartCount');
			if (mCount) mCount.textContent = totalQuantity;
			if (animate) {
				if (count) {
					count.classList.remove('bump');
					void count.offsetWidth;
					count.classList.add('bump');
				}
				if (mCount) {
					mCount.classList.remove('bump');
					void mCount.offsetWidth;
					mCount.classList.add('bump');
				}
			}

			const cartItemsEl = document.querySelector('#cartItems');
			if (cartItemsEl) {
				if (!cart.length) {
					cartItemsEl.innerHTML = '<div style="padding:40px 0; text-align:center; color:var(--muted);">Tu carrito está vacío.</div>';
				} else {
					cartItemsEl.innerHTML = cart.map((item, index) => {
						const qty = Number(item.quantity) || 1;
						const unitPrice = Number(item.price) || 0;
						const lineTotal = unitPrice > 0 ? unitPrice * qty : 0;
						const pImg = getProductImageUrl(item);
						return `
							<div class="cart-item">
								<img src="${escapeHtml(pImg)}" alt="${escapeHtml(item.name)}" data-fallback="${escapeHtml(fallbackImage(item))}" onerror="this.onerror=null;this.src=this.dataset.fallback">
								<div class="cart-item-copy">
									<strong>${escapeHtml(item.name)}</strong>
									<small>${unitPrice > 0 ? money(unitPrice) : 'Cotizar'}</small>
									<div class="cart-qty-ctrls">
										<button type="button" class="cart-qty-btn" data-cart-qty-delta="-1" data-cart-index="${index}" aria-label="Restar una unidad">－</button>
										<span class="cart-qty-val">${qty}</span>
										<button type="button" class="cart-qty-btn" data-cart-qty-delta="1" data-cart-index="${index}" aria-label="Sumar una unidad">＋</button>
										${lineTotal > 0 ? `<span style="margin-left:6px; font-weight:700; color:var(--blue); font-size:12px;">= ${money(lineTotal)}</span>` : ''}
									</div>
								</div>
								<button class="remove-item" data-remove-index="${index}" aria-label="Eliminar ${escapeHtml(item.name)} del carrito">
									<i data-lucide="trash-2" width="16"></i>
								</button>
							</div>
						`;
					}).join('');
				}
			}

			const totalNum = cart.reduce((total, item) => total + (Number(item.price || 0) * (Number(item.quantity) || 1)), 0);
			const hasQuoteOnly = cart.length > 0 && cart.every(i => !Number(i.price || 0));
			const cartTotalEl = document.querySelector('#cartTotal');
			if (cartTotalEl) {
				cartTotalEl.textContent = totalNum > 0 ? money(totalNum) : (hasQuoteOnly ? 'Solicitar cotización' : '$0');
			}
			window.lucide && window.lucide.createIcons();

			document.querySelectorAll('[data-cart-qty-delta]').forEach(btn => {
				btn.addEventListener('click', () => {
					const idx = Number(btn.dataset.cartIndex);
					const delta = Number(btn.dataset.cartQtyDelta);
					changeCartQuantity(idx, delta);
				});
			});

			document.querySelectorAll('[data-remove-index]').forEach(button => button.addEventListener('click', () => {
				cart.splice(Number(button.dataset.removeIndex), 1);
				updateCart(false);
			}));
		};
		updateCart(false);
		const setCategoryFilter = filter => {
			if (!filter || filter === 'all' || (typeof filter === 'string' && filter.toLowerCase() === 'all')) {
				selectedFilter = 'all';
			} else if (typeof filter === 'string') {
				const macro = getMacroCategory(filter);
				selectedFilter = { type: 'category', macroId: macro.id, category: filter };
				expandedGroups.add(macro.id);
			} else if (filter && typeof filter === 'object') {
				selectedFilter = filter;
				if (filter.type === 'macro') expandedGroups.add(filter.id);
				if (['category', 'sub'].includes(filter.type)) expandedGroups.add(filter.macroId);
			}
			updateSidebarActiveStates();
			try { syncMiniActive(); } catch (e) { /* aún no definido durante la carga */ }
			refreshView(true);
		};
		const openCategoryDrawer = () => {
			if (!window.matchMedia('(max-width: 800px)').matches) return;
			const sidebar = document.querySelector('.store-sidebar');
			const backdrop = document.querySelector('#categoryDrawerBackdrop');
			if (!sidebar || !backdrop) return;
			if (!expandedGroups.size) {
				const firstGroup = buildCategoryTree().groups[0];
				if (firstGroup) expandedGroups.add(firstGroup.id);
			}
			sidebar.classList.add('is-mobile-open');
			sidebar.removeAttribute('inert');
			sidebar.setAttribute('aria-hidden', 'false');
			backdrop.hidden = false;
			document.body.classList.add('category-drawer-open');
			document.querySelector('#mNavCategories')?.setAttribute('aria-expanded', 'true');
			document.querySelector('#publicMenuBtn')?.setAttribute('aria-expanded', 'true');
			const menuButton = document.querySelector('#publicMenuBtn');
			if (menuButton) menuButton.innerHTML = '<i data-lucide="x" width="20"></i>';
			const activeMacro = selectedFilter && selectedFilter.type === 'macro' ? String(selectedFilter.id) : '';
			if (activeMacro) document.querySelector(`.sidebar-group[data-macro-id="${CSS.escape(activeMacro)}"]`)?.classList.add('is-expanded');
			window.lucide && window.lucide.createIcons();
			window.setTimeout(() => document.querySelector('#categoryDrawerClose')?.focus({ preventScroll: true }), 180);
		};
		const closeCategoryDrawer = () => {
			const sidebar = document.querySelector('.store-sidebar');
			const backdrop = document.querySelector('#categoryDrawerBackdrop');
			if (!sidebar || !backdrop) return;
			sidebar.classList.remove('is-mobile-open');
			// 'inert' solo tiene sentido cuando el sidebar es un drawer movil que se cierra -- en
			// escritorio es un panel siempre visible. Sin este guard (el mismo que ya usa
			// openCategoryDrawer), la primera seleccion de categoria en escritorio marcaba el
			// sidebar entero como inert para siempre: nada volvia a quitarlo (el handler de
			// 'resize' solo corre si de verdad cambia el ancho), asi que cualquier clic posterior
			// en una categoria o subcategoria quedaba bloqueado.
			if (window.matchMedia('(max-width: 800px)').matches) {
				sidebar.setAttribute('inert', '');
				sidebar.setAttribute('aria-hidden', 'true');
			} else {
				sidebar.removeAttribute('inert');
				sidebar.removeAttribute('aria-hidden');
			}
			backdrop.hidden = true;
			document.body.classList.remove('category-drawer-open');
			document.querySelector('#mNavCategories')?.setAttribute('aria-expanded', 'false');
			const menuButton = document.querySelector('#publicMenuBtn');
			menuButton?.setAttribute('aria-expanded', 'false');
			if (menuButton) menuButton.innerHTML = '<i data-lucide="menu" width="20"></i>';
			window.lucide?.createIcons();
		};
		document.querySelector('#categoryDrawerClose')?.addEventListener('click', closeCategoryDrawer);
		document.querySelector('#categoryDrawerBackdrop')?.addEventListener('click', closeCategoryDrawer);
		if (window.matchMedia('(max-width: 800px)').matches) closeCategoryDrawer();
		window.addEventListener('resize', () => {
			if (window.matchMedia('(min-width: 801px)').matches) {
				const sidebar = document.querySelector('.store-sidebar');
				sidebar?.classList.remove('is-mobile-open');
				sidebar?.removeAttribute('inert');
				sidebar?.removeAttribute('aria-hidden');
				const backdrop = document.querySelector('#categoryDrawerBackdrop');
				if (backdrop) backdrop.hidden = true;
				document.body.classList.remove('category-drawer-open');
			} else if (!document.body.classList.contains('category-drawer-open')) {
				closeCategoryDrawer();
			}
			const menuButton = document.querySelector('#publicMenuBtn');
			menuButton?.setAttribute('aria-expanded', 'false');
			if (menuButton) menuButton.innerHTML = '<i data-lucide="menu" width="20"></i>';
		});
		document.addEventListener('keydown', event => {
			if (event.key === 'Escape' && document.body.classList.contains('category-drawer-open')) {
				closeCategoryDrawer();
				document.querySelector('#publicMenuBtn')?.focus({ preventScroll: true });
			}
		});
		document.addEventListener('click', event => {
			// 1. Quitar filtro / Ver todas las categorías
			if (event.target.closest('#clearCategoryFilter') || event.target.closest('#removeCategoryPill')) {
				event.preventDefault();
				setCategoryFilter('all');
				closeCategoryDrawer();
				return;
			}

			// 2. Clic en "Todo el catálogo"
			const allBtn = event.target.closest('[data-filter-action="all"]') || event.target.closest('#sidebarAllBtn');
			if (allBtn) {
				event.preventDefault();
				setCategoryFilter('all');
				closeCategoryDrawer();
				const catEl = document.querySelector('#catalogo');
				if (catEl && window.scrollY > catEl.offsetTop + 120) {
					catEl.scrollIntoView({ behavior: 'smooth' });
				}
				return;
			}

			// 3. Toggle acordeón chevron (expandir / contraer)
			const toggleBtn = event.target.closest('[data-toggle-macro]');
			if (toggleBtn) {
				event.preventDefault();
				event.stopPropagation();
				const macroId = toggleBtn.dataset.toggleMacro;
				const group = document.querySelector(`.sidebar-group[data-macro-id="${macroId}"]`);
				if (group) {
					const isExp = group.classList.toggle('is-expanded');
					if (isExp) expandedGroups.add(macroId);
					else expandedGroups.delete(macroId);
				}
				return;
			}

			const categoryToggle = event.target.closest('[data-toggle-category]');
			if (categoryToggle) {
				event.preventDefault();
				event.stopPropagation();
				const key = categoryToggle.dataset.toggleCategory;
				const row = categoryToggle.closest('.sidebar-subcat-row');
				const open = row ? row.classList.toggle('is-open') : false;
				categoryToggle.setAttribute('aria-expanded', String(open));
				if (open) expandedCategories.add(key);
				else expandedCategories.delete(key);
				return;
			}

			// 4. Clic en Subcategoría anidada
			const subBtn = event.target.closest('.sidebar-subcat-btn') || event.target.closest('[data-filter-action="sub"]');
			if (subBtn) {
				event.preventDefault();
				const macroId = subBtn.dataset.macroId;
				const category = subBtn.dataset.category;
				setCategoryFilter({ type: 'category', macroId, category });
				closeCategoryDrawer();
				const catEl = document.querySelector('#catalogo');
				if (catEl && window.scrollY > catEl.offsetTop + 120) {
					catEl.scrollIntoView({ behavior: 'smooth' });
				}
				return;
			}

			// 5. Clic en Categoría Principal / Macro
			const macroBtn = event.target.closest('.sidebar-group-btn') || event.target.closest('[data-filter-action="macro"]');
			if (macroBtn) {
				event.preventDefault();
				const macroId = macroBtn.dataset.macroId;
				const groupEl = document.querySelector(`.sidebar-group[data-macro-id="${macroId}"]`);
				if (selectedFilter && selectedFilter.type === 'macro' && String(selectedFilter.id).toLowerCase() === String(macroId).toLowerCase()) {
					if (groupEl) {
						const isExp = groupEl.classList.toggle('is-expanded');
						if (isExp) expandedGroups.add(macroId);
						else expandedGroups.delete(macroId);
					}
				} else {
					expandedGroups.add(macroId);
					if (groupEl) groupEl.classList.add('is-expanded');
					setCategoryFilter({ type: 'macro', id: macroId });
					closeCategoryDrawer();
				}
				const catEl = document.querySelector('#catalogo');
				if (catEl && window.scrollY > catEl.offsetTop + 120) {
					catEl.scrollIntoView({ behavior: 'smooth' });
				}
				return;
			}

			// 6. Legacy filters si queda alguno en otras vistas
			const legacyFilter = event.target.closest('[data-filter]');
			if (legacyFilter && (legacyFilter.closest('#catalogo') || legacyFilter.closest('.store-sidebar'))) {
				event.preventDefault();
				setCategoryFilter(legacyFilter.dataset.filter);
				const catEl = document.querySelector('#catalogo');
				if (catEl && window.scrollY > catEl.offsetTop + 120) {
					catEl.scrollIntoView({ behavior: 'smooth' });
				}
				return;
			}
		});

		// Chrome ignora autocomplete="off" si cree que es un campo de usuario y mete el correo guardado:
		// un valor que llega sin teclear, pegar ni soltar se descarta.
		const guardAgainstAutofill = input => {
			if (!input) return;
			let typed = false;
			['keydown', 'paste', 'drop', 'compositionstart'].forEach(type => input.addEventListener(type, () => { typed = true; }, true));
			const discard = () => {
				if (typed || !input.value) return false;
				input.value = '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
				return true;
			};
			input.addEventListener('input', event => { if (!typed && event.isTrusted && discard()) event.stopImmediatePropagation(); }, true);
			[300, 1200, 3000].forEach(delay => setTimeout(() => { try { if (input.matches(':autofill, :-webkit-autofill')) discard(); } catch (e) { if (/@/.test(input.value)) discard(); } }, delay));
		};
		document.querySelectorAll('#sidebarCatSearch, #searchInput').forEach(input => {
			const unlock = () => input.removeAttribute('readonly');
			['pointerdown', 'touchstart', 'focus', 'keydown'].forEach(type => input.addEventListener(type, unlock, { once: true, passive: true }));
		});
		guardAgainstAutofill(document.querySelector('#sidebarCatSearch'));
		guardAgainstAutofill(document.querySelector('#searchInput'));

		// Filtro en tiempo real para la lista de categorías en la barra lateral
		const sidebarCatSearch = document.querySelector('#sidebarCatSearch');
		if (sidebarCatSearch) {
			sidebarCatSearch.addEventListener('input', e => {
				const q = String(e.target.value || '').toLowerCase().trim();
				document.querySelectorAll('.sidebar-group').forEach(group => {
					const text = group.textContent.toLowerCase();
					const matches = !q || text.includes(q);
					group.style.display = matches ? '' : 'none';
					if (q && matches) {
						group.classList.add('is-expanded');
					}
				});
				const allBtn = document.querySelector('[data-filter-action="all"]');
				if (allBtn) {
					allBtn.style.display = (!q || 'todo el catalogo'.includes(q)) ? '' : 'none';
				}
			});
		}
		document.querySelector('#searchForm').addEventListener('submit', event => {
			event.preventDefault();
			const term = document.querySelector('#searchInput').value.trim();
			if (!window.isCategoriasView) { window.location.href = `/categorias-destacadas${term ? `?q=${encodeURIComponent(term)}` : ''}`; return; }
			refreshView(true);
			document.querySelector('#catalogo').scrollIntoView({ behavior: 'smooth' });
		});
		let searchDebounceTimer = null;
		document.querySelector('#searchInput').addEventListener('input', () => {
			// DEV-20261006-002: antes cada tecla disparaba un render() completo (filtrar+ordenar
			// sobre todo el catalogo) sin esperar a que la persona termine de escribir.
			clearTimeout(searchDebounceTimer);
			searchDebounceTimer = setTimeout(() => { refreshView(true); }, 250);
		});
		document.querySelector('#sortSelect').addEventListener('change', () => { refreshView(true); });
		const cartDrawer = document.querySelector('#drawer');
		let cartReturnFocus = null;
		const setCartOpen = open => {
			if (!cartDrawer) return;
			const wasOpen = cartDrawer.classList.contains('open');
			cartDrawer.classList.toggle('open', open);
			document.body.classList.toggle('cart-open', open);
			if (open && !wasOpen) {
				cartReturnFocus = document.activeElement;
				setTimeout(() => document.querySelector('#closeCart')?.focus(), 50);
			} else if (!open && wasOpen) {
				cartReturnFocus?.focus?.();
				cartReturnFocus = null;
			}
		};
		document.addEventListener('keydown', event => {
			if (event.key === 'Escape' && cartDrawer?.classList.contains('open')) setCartOpen(false);
		});
		document.querySelector('#openCart').addEventListener('click', () => setCartOpen(true));
		document.querySelector('#closeCart').addEventListener('click', () => setCartOpen(false));
		cartDrawer?.addEventListener('click', event => { if (event.target.id === 'drawer') setCartOpen(false); });
		const publicMenuBtn = document.querySelector('#publicMenuBtn');
		const publicNav = document.querySelector('#publicNav');
		const publicMenuClose = document.querySelector('#publicMenuClose');
		const updateStickyNavOffset = () => {
			const header = document.querySelector('.nav');
			document.documentElement.style.setProperty('--sticky-nav-height', `${header?.offsetHeight || 70}px`);
		};
		updateStickyNavOffset();
		window.addEventListener('resize', updateStickyNavOffset);
		const closePublicNav = () => {
			publicNav.classList.remove('is-open');
			publicMenuBtn?.setAttribute('aria-expanded', 'false');
			if (publicMenuBtn) publicMenuBtn.innerHTML = '<i data-lucide="menu" width="20"></i>';
			lucide?.createIcons();
		};
		if (publicMenuBtn && publicNav) {
			publicMenuBtn.addEventListener('click', () => {
				// En catalogo (busqueda, /categorias-destacadas, filtro activo) el hamburguesa abre
				// el panel de categorias del catalogo que ya se esta viendo; en la portada curada
				// (sin catalogo visible) abre el menu de secciones (Tienda/Nosotros/Servicios/...),
				// que antes de este fix no tenia ningun disparador en movil.
				if (window.isCategoriasView) {
					if (document.body.classList.contains('category-drawer-open')) closeCategoryDrawer();
					else openCategoryDrawer();
					const isOpen = document.body.classList.contains('category-drawer-open');
					publicMenuBtn.innerHTML = isOpen ? '<i data-lucide="x" width="20"></i>' : '<i data-lucide="menu" width="20"></i>';
					lucide?.createIcons();
					return;
				}
				const isOpen = publicNav.classList.toggle('is-open');
				publicMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
				publicMenuBtn.innerHTML = isOpen ? '<i data-lucide="x" width="20"></i>' : '<i data-lucide="menu" width="20"></i>';
				lucide?.createIcons();
			});
			publicMenuClose?.addEventListener('click', () => {
				if (window.isCategoriasView) closeCategoryDrawer();
				else closePublicNav();
			});
		}
		const publicCategoryMenu = document.querySelector('#publicCategoryMenu');
		const publicCategoryTrigger = document.querySelector('#publicCategoryTrigger');
		if (publicCategoryMenu && publicCategoryTrigger) {
			publicCategoryTrigger.addEventListener('click', event => {
				event.preventDefault();
				const isOpen = publicCategoryMenu.classList.toggle('is-open');
				publicCategoryTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
				lucide?.createIcons();
			});
			document.addEventListener('click', event => {
				if (!publicCategoryMenu.contains(event.target)) {
					publicCategoryMenu.classList.remove('is-open');
					publicCategoryTrigger.setAttribute('aria-expanded', 'false');
				}
			});
			publicCategoryMenu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
				publicCategoryMenu.classList.remove('is-open');
				publicCategoryTrigger.setAttribute('aria-expanded', 'false');
			}));
		}
		const publicNavCatSearch = document.querySelector('#publicNavCatSearch');
		const publicNavCatList = document.querySelector('#publicNavCatList');
		if (publicNavCatSearch && publicNavCatList) {
			publicNavCatSearch.addEventListener('input', () => {
				const term = normalizeCategoryText(publicNavCatSearch.value);
				publicNavCatList.querySelectorAll('[data-nav-cat]').forEach(link => {
					const haystack = normalizeCategoryText(`${link.dataset.navCat || ''} ${link.textContent || ''}`);
					link.style.display = !term || haystack.includes(term) ? 'flex' : 'none';
				});
			});
		}
		document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => {
			document.querySelector(`#${button.dataset.closeModal}`)?.classList.remove('open');
		}));
		document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', event => {
			if (event.target === modal) modal.classList.remove('open');
		}));
		window.addEventListener('keydown', event => {
			if (event.key === 'Escape') document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
		});
		window.__publicBrandManaged = true;
		const renderFooterContent = content => {
			if (!content) return;
			if (content.footer_text) {
				const brandP = document.querySelector('.site-footer-brand p');
				if (brandP) brandP.textContent = content.footer_text;
			}
			const whatsapp = (content.footer_whatsapp || '').trim();
			if (whatsapp) {
				const digits = whatsapp.replace(/[^\d]/g, '');
				const waHref = `https://wa.me/${digits}`;
				const waLink = document.querySelector('#footerWhatsappLink');
				if (waLink) { waLink.href = waHref; waLink.target = '_blank'; waLink.rel = 'noopener'; waLink.hidden = false; document.querySelector('#footerWhatsappText').textContent = whatsapp; }
				const waCta = document.querySelector('#footerWhatsappCta');
				const waCard = document.querySelector('#footerWhatsappCard');
				if (waCta) { waCta.href = waHref; waCta.target = '_blank'; waCta.rel = 'noopener'; }
				if (waCard) waCard.hidden = false;
			}
			const email = (content.footer_email || '').trim();
			if (email) {
				const emailLink = document.querySelector('#footerEmailLink');
				if (emailLink) { emailLink.href = `mailto:${email}`; emailLink.hidden = false; document.querySelector('#footerEmailText').textContent = email; }
			}
			const address = (content.footer_address || '').trim();
			if (address) {
				const addressItem = document.querySelector('#footerAddressItem');
				if (addressItem) { addressItem.hidden = false; document.querySelector('#footerAddressText').textContent = address; }
			}
			const socials = [
				['footer_social_instagram', '#footerSocialInstagram'],
				['footer_social_facebook', '#footerSocialFacebook'],
				['footer_social_youtube', '#footerSocialYoutube'],
				['footer_social_linkedin', '#footerSocialLinkedin']
			];
			socials.forEach(([key, selector]) => {
				const url = (content[key] || '').trim();
				const el = document.querySelector(selector);
				if (el && url) { el.href = url; el.target = '_blank'; el.rel = 'noopener'; el.hidden = false; }
			});
			const copyright = document.querySelector('#footerCopyright');
			if (copyright) copyright.textContent = `© ${new Date().getFullYear()} SMART-ISP. Todos los derechos reservados.`;
			window.lucide?.createIcons();
		};
		const applySiteLogo = content => {
			if (!content || window.__publicBrandManaged) return;
			const mode = content.landing_logo_type || (content.landing_logo_image ? 'image' : content.logo_type || (content.logo_image ? 'image' : 'text'));
			const brand = content.landing_logo_text || content.brand_name || 'SmartISP';
			const lightHeight = parseInt(content.landing_logo_height || content.logo_height, 10) || 38;
			const darkHeight = parseInt(content.landing_logo_dark_height || content.logo_dark_height, 10) || 42;
			const darkInvert = (content.landing_logo_dark_invert === 'true' || content.landing_logo_dark_invert === true || content.logo_dark_invert === 'true' || content.logo_dark_invert === true);
			const lightImg = content.landing_logo_image || content.logo_image || '';
			const darkImg = content.landing_logo_dark_image || content.logo_dark_image || lightImg;
			const text = (content.landing_logo_text || content.logo_text || brand || 'smartisp').trim();

			// 1. Barra superior / Navbar claro (.nav .logo)
            const navLogo = document.querySelector('.nav .logo');
			if (navLogo) {
				if (mode === 'image' && lightImg) {
                    navLogo.innerHTML = `<img src="${lightImg}" alt="${brand}" style="height:${lightHeight}px; max-height:140px; width:auto; object-fit:contain; vertical-align:middle; display:inline-block;">`;
                    navLogo.querySelector('img').addEventListener('error', () => { navLogo.textContent = brand; }, { once: true });
				} else if (text) {
					const mid = Math.ceil(text.length / 2);
					const part1 = text.slice(0, mid);
					const part2 = text.slice(mid);
					navLogo.innerHTML = `${part1}<b style="color:var(--blue, #087ea4);">${part2}</b><span style="color:#f5a524;">.</span>`;
				}
			}

			// 2. Pie de página oscuro (footer .logo)
			const footLogo = document.querySelector('footer .logo');
			if (footLogo) {
				if (mode === 'image' && darkImg) {
					const filterStyle = darkInvert ? 'filter: brightness(0) invert(1);' : '';
					footLogo.innerHTML = `<img src="${darkImg}" alt="${brand}" style="height:${darkHeight}px; max-height:140px; width:auto; object-fit:contain; vertical-align:middle; display:inline-block; ${filterStyle}">`;
				} else if (text) {
					const mid = Math.ceil(text.length / 2);
					const part1 = text.slice(0, mid);
					const part2 = text.slice(mid);
					footLogo.innerHTML = `${part1}<b style="color:var(--blue, #087ea4);">${part2}</b><span style="color:#f5a524;">.</span>`;
				}
			}

			// 3. Otros posibles contenedores de .logo
			document.querySelectorAll('.logo:not(.nav .logo):not(footer .logo)').forEach(otherLogo => {
				if (mode === 'image' && lightImg) {
					otherLogo.innerHTML = `<img src="${lightImg}" alt="${brand}" style="height:${lightHeight}px; max-height:140px; width:auto; object-fit:contain; vertical-align:middle; display:inline-block;">`;
				} else if (text) {
					const mid = Math.ceil(text.length / 2);
					const part1 = text.slice(0, mid);
					const part2 = text.slice(mid);
					otherLogo.innerHTML = `${part1}<b style="color:var(--blue, #087ea4);">${part2}</b><span style="color:#f5a524;">.</span>`;
				}
			});
		};

		const applyRemoteSite = async () => {
			isCatalogLoading = true;
			catalogLoadError = false;
			render();
			try {
				let remote = null;
				let lastError = null;
				for (let attempt = 0; attempt < 3 && !remote; attempt++) {
					try {
						// En la portada curada (no modo catalogo) nadie ve la grilla de productos --
						// pedir 36 productos completos (con sus imagenes) solo para tenerlos ocultos
						// era trafico/tiempo de API desperdiciado en cada carga de /tienda.html. Solo
						// se pide el catalogo completo cuando sí se va a mostrar (/categorias-destacadas).
						const catalogLimit = window.isCategoriasView ? 36 : 1;
						const response = await fetch(`/api/auth/catalog?page=1&limit=${catalogLimit}&_t=` + Date.now(), { cache: 'no-store' });
						if (!response.ok) throw new Error(`catalog request failed (${response.status})`);
						const data = await response.json();
						if (!Array.isArray(data.products) || (Number(data.total) > 0 && data.products.length === 0)) {
							throw new Error('catalog response was empty');
						}
						remote = data;
					} catch (error) {
						lastError = error;
						if (attempt < 2) await new Promise(resolve => setTimeout(resolve, 250 * (attempt + 1)));
					}
				}
				if (!remote) throw lastError || new Error('catalog unavailable');
				catalogCategoryRows = Array.isArray(remote.categories) ? remote.categories : [];
				catalogFamilies = Array.isArray(remote.macroGroups) ? remote.macroGroups : [];
				catalogTotalCount = Number(remote.total) || 0;
				if (remote.products && remote.products.length) {
					products = remote.products.map(product => ({
						...product,
						brand: product.category || 'SmartISP',
						image: getProductImageUrl(product),
						reviews: Number(product.reviews || 0),
						tag: product.tag || '',
						specs: Array.isArray(product.specs) ? product.specs : []
					}));
					serverView = {
						active: true,
						page: Number(remote.page) || 1,
						totalPages: Number(remote.totalPages) || 1,
						total: Number(remote.total) || products.length,
						items: products.slice()
					};
					rebuildProductIndex();
					isCatalogLoading = false;
					if (selectedFilter && selectedFilter.type === 'category') {
						const macro = getMacroCategory(selectedFilter.category);
						selectedFilter.macroId = macro.id;
						expandedGroups.add(macro.id);
					}
					// La página 1 sin filtro solo sirve si la URL no pide categoría, familia o búsqueda.
					if (isServerMode() && selectedFilter !== 'all' && (selectedFilter || document.querySelector('#searchInput').value.trim())) await refreshView(true);
					else render();
					if (typeof renderSidebarCategories === 'function') renderSidebarCategories();
					renderMiniCategories();
					checkDirectProductLanding();

					// La primera vista usa solo esta página; el resto se solicita ante una acción del usuario.
				} else {
					isCatalogLoading = false;
					render();
				}
				const content = Object.fromEntries((remote.content || []).map(item => [item.key, item.value]));
				applySiteLogo(content);
				if (content.hero_title && document.querySelector('.hero h1')) document.querySelector('.hero h1').textContent = content.hero_title;
				if (content.hero_text && document.querySelector('.hero p')) document.querySelector('.hero p').textContent = content.hero_text;
				if (content.hero_image && document.querySelector('.hero')) document.querySelector('.hero').style.backgroundImage = `url("${content.hero_image}")`;
				if (content.primary_color) document.documentElement.style.setProperty('--blue', content.primary_color);
				renderFooterContent(content);
				if (content.catalog_visible === 'false' && document.querySelector('#catalogo')) document.querySelector('#catalogo').hidden = true;
				if (content.hero_visible === 'false' && document.querySelector('.hero')) document.querySelector('.hero').hidden = true;
				if (content.benefits_visible === 'false' && document.querySelector('.benefits')) document.querySelector('.benefits').hidden = true;

				// Aplicar diseño avanzado del hero directamente desde el mismo payload sin segunda petición
				const hero = document.querySelector('.hero');
				if (hero) {
					const setText = (selector, key) => { if (content[key]) { const el = document.querySelector(selector); if (el) el.textContent = content[key]; } };
					const hexToRgba = (hex, opacity) => { const value = String(hex || '').replace('#', ''); if (!/^[\da-f]{6}$/i.test(value)) return `rgba(228,245,243,${opacity})`; return `rgba(${parseInt(value.slice(0, 2), 16)},${parseInt(value.slice(2, 4), 16)},${parseInt(value.slice(4, 6), 16)},${opacity})`; };
					if (content.hero_title) document.querySelector('.hero h1').textContent = content.hero_title;
					if (content.hero_text) document.querySelector('.hero p').textContent = content.hero_text;
					if (content.hero_image) hero.style.backgroundImage = `url("${content.hero_image}")`;
					if (content.hero_visible === 'false') hero.hidden = true;
					setText('#heroEyebrow', 'hero_eyebrow');
					setText('#heroTitle', 'hero_title');
					setText('#heroText', 'hero_text');
					setText('#heroButtonText', 'hero_button_text');
					if (content.hero_button_link) { const btn = document.querySelector('#heroButton'); if (btn) btn.href = content.hero_button_link; }
					if (content.hero_image_position) hero.style.setProperty('--hero-image-position', content.hero_image_position);
					if (content.hero_height) hero.style.setProperty('--hero-height', `${Math.max(280, Math.min(700, Number(content.hero_height) || 370))}px`);
					if (content.hero_content_width) hero.style.setProperty('--hero-content-width', `${Math.max(320, Math.min(900, Number(content.hero_content_width) || 510))}px`);
					if (content.hero_text_color) hero.style.setProperty('--hero-text-color', content.hero_text_color);
					if (content.hero_overlay_opacity || content.primary_color) hero.style.setProperty('--hero-overlay', hexToRgba(content.hero_overlay_color || '#e4f5f3', Math.max(0, Math.min(1, Number(content.hero_overlay_opacity || 96) / 100))));
				}
			} catch (error) {
				console.warn('Contenido remoto no disponible:', error);
				catalogLoadError = true;
				isCatalogLoading = false;
				render();
			}
		};

		let storefrontSlides = [];
		let storefrontFeaturedProducts = [];
		let storefrontFeaturedCategoryIds = [];
		let storefrontPromoChips = [];
		let storefrontDisplayMode = 'carousel';
		let storefrontSlideIndex = 0;
		let storefrontRotationTimer = 0;
		const storefrontImageTimers = new Map();
		const campaignSlideImages = slide => {
			const images = Array.isArray(slide.images) ? [...slide.images].sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0)) : [];
			if (!images.length && slide.image_url) images.push({ image_url: slide.image_url, image_alt: slide.image_alt || slide.title });
			const primaryIndex = images.findIndex(image => image.is_primary === true || String(image.is_primary) === '1');
			return primaryIndex > 0 ? [images[primaryIndex], ...images.slice(0, primaryIndex), ...images.slice(primaryIndex + 1)] : images;
		};
		const campaignImageFocus = image => {
			const x = Math.max(0, Math.min(100, Number(image?.focal_x ?? 50)));
			const y = Math.max(0, Math.min(100, Number(image?.focal_y ?? 50)));
			const zoom = Math.max(100, Math.min(180, Number(image?.zoom_pct ?? 100))) / 100;
			return { x, y, zoom };
		};
		const applyCampaignImageFocus = (element, image) => {
			const focus = campaignImageFocus(image);
			element.style.objectPosition = `${focus.x}% ${focus.y}%`;
			element.style.transform = `scale(${focus.zoom})`;
			element.style.transformOrigin = `${focus.x}% ${focus.y}%`;
		};
		const campaignExternal = target => /^https:\/\//i.test(String(target || ''));
		const setCampaignLink = (link, target) => {
			link.href = target || '/tienda.html#catalogo';
			if (campaignExternal(link.href)) { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
			else { link.removeAttribute('target'); link.removeAttribute('rel'); }
		};
		const clearStorefrontImageTimers = (image = null) => {
			storefrontImageTimers.forEach((rotation, element) => {
				if (image && element !== image) return;
				clearInterval(rotation.timer); clearTimeout(rotation.timeout);
				storefrontImageTimers.delete(element);
			});
		};
		const startStorefrontImageRotation = (image, slide) => {
			clearStorefrontImageTimers(image);
			const images = campaignSlideImages(slide);
			if (images.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
			let index = 0;
			const baseOpacity = image.style.opacity || getComputedStyle(image).opacity || '1';
			const transitionMs = Math.max(150, Math.min(2000, Number(slide.image_transition_ms) || 1000));
			const fadeMs = transitionMs / 2;
			image.style.transition = `opacity ${fadeMs}ms ease`;
			const rotation = { timer: 0, timeout: 0 };
			rotation.timer = setInterval(() => {
				index = (index + 1) % images.length;
				image.style.opacity = '0';
				rotation.timeout = setTimeout(() => {
					rotation.timeout = 0;
					image.src = images[index].image_url;
					image.alt = images[index].image_alt || slide.title || '';
					applyCampaignImageFocus(image, images[index]);
					image.style.opacity = baseOpacity;
				}, fadeMs);
			}, Math.max(transitionMs + 100, Math.max(2, Math.min(20, Number(slide.image_interval_seconds) || 5)) * 1000));
			storefrontImageTimers.set(image, rotation);
		};
		const renderStorefrontSlide = index => {
			if (!storefrontSlides.length) return;
			if (!['carousel', 'single'].includes(storefrontDisplayMode)) return;
			storefrontSlideIndex = (index + storefrontSlides.length) % storefrontSlides.length;
			const slide = storefrontSlides[storefrontSlideIndex];
			const banner = document.querySelector('#storeCampaign');
			const isCopyPanel = slide.card_layout === 'side';
			const copyBackground = /^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color || '') ? slide.copy_background_color : '#ffffff';
			banner.classList.toggle('is-photo-background', !isCopyPanel);
			banner.classList.toggle('is-copy-panel', isCopyPanel);
			banner.style.setProperty('--campaign-image-width', `${Math.max(35, Math.min(65, Number(slide.image_width_pct ?? 55)))}%`);
			banner.style.setProperty('--copy-background', copyBackground);
			banner.classList.toggle('is-contain', slide.image_fit === 'contain');
			const image = document.querySelector('#storeCampaignImage');
			clearStorefrontImageTimers(image);
			const images = campaignSlideImages(slide);
			image.src = images[0]?.image_url || slide.image_url || '';
			image.alt = images[0]?.image_alt || slide.image_alt || slide.title || '';
			image.style.objectFit = slide.image_fit === 'contain' ? 'contain' : 'cover';
			image.style.opacity = String(Math.max(0, Math.min(100, Number(slide.image_opacity ?? 100))) / 100);
			applyCampaignImageFocus(image, images[0]);
			const overlayOpacity = Math.max(0, Math.min(100, Number(slide.overlay_opacity ?? 68))) / 100;
			banner.style.setProperty('--campaign-overlay-opacity', String(isCopyPanel ? overlayOpacity : Math.max(.55, overlayOpacity)));
			startStorefrontImageRotation(image, slide);
			const eyebrowEl = document.querySelector('#storeCampaignEyebrow');
			eyebrowEl.innerHTML = campaignEyebrowHtml(slide);
			eyebrowEl.hidden = !slide.eyebrow;
			const titleEl = document.querySelector('#storeCampaignTitle'), subtitleEl = document.querySelector('#storeCampaignSubtitle');
			const textColor = campaignTextColorStyle(slide);
			titleEl.innerHTML = campaignTitleHtml(slide); eyebrowEl.style.cssText = textColor.eyebrow;
			subtitleEl.innerHTML = campaignSubtitleHtml(slide); subtitleEl.style.cssText = textColor.subtitle;
			subtitleEl.hidden = !slide.subtitle;
			applyCampaignFontScale(eyebrowEl, slide.eyebrow_size);
			applyCampaignFontScale(titleEl, slide.title_size);
			applyCampaignFontScale(subtitleEl, slide.subtitle_size);
			renderTrustStats(slide, document.querySelector('#storeCampaignStats'));
			renderFeatureBadges(slide, document.querySelector('#storeCampaignFeatures'));
			window.lucide?.createIcons();
			document.querySelector('#storeCampaignButton').textContent = slide.button_text || 'Explorar';
			const campaignLink = document.querySelector('#storeCampaignLink');
			setCampaignLink(campaignLink, slide.target_url);
			campaignLink.hidden = !campaignButtonVisible(slide);
			applyButtonIcon(slide, 'button_icon', campaignLink, document.querySelector('#storeCampaignButton'));
			const campaignLink2 = document.querySelector('#storeCampaignLink2');
			campaignLink2.hidden = !campaignButton2Visible(slide);
			if (!campaignLink2.hidden) {
				document.querySelector('#storeCampaignButton2').textContent = slide.button2_text;
				setCampaignLink(campaignLink2, slide.button2_url || slide.target_url);
				applyButtonIcon(slide, 'button2_icon', campaignLink2, document.querySelector('#storeCampaignButton2'));
			}
			const badge = document.querySelector('#storeCampaignBadge');
			badge.textContent = slide.badge_label || '';
			badge.className = `store-campaign-badge ${['new','featured','dark'].includes(slide.badge_tone) ? slide.badge_tone : ''}`;
			badge.hidden = !slide.badge_label;
			const promo = document.querySelector('#storeCampaignPromo');
			promo.hidden = !slide.promo_chip_label;
			if (slide.promo_chip_label) {
				setCampaignLink(promo, slide.promo_chip_target_url || slide.target_url);
				const icon = document.createElement('i');
				icon.dataset.lucide = ['sparkles','star','truck'].includes(slide.promo_chip_icon) ? slide.promo_chip_icon : 'tag';
				icon.setAttribute('aria-hidden', 'true');
				const label = document.createElement('span'); label.textContent = slide.promo_chip_label;
				promo.replaceChildren(icon, label);
			}
			banner.classList.add('is-visible');
			document.querySelectorAll('.store-campaign-dot').forEach((dot, dotIndex) => dot.setAttribute('aria-current', dotIndex === storefrontSlideIndex ? 'true' : 'false'));
			window.lucide?.createIcons();
		};
		let featuredFilter = null;
		let featuredShown = [];
		const renderStorefrontFeatured = () => {
			const section = document.querySelector('#campaignFeatured');
			const grid = document.querySelector('#campaignProductsGrid');
			if (!section || !grid || (!storefrontFeaturedProducts.length && !featuredFilter)) return;
			const title = document.querySelector('#campaignFeaturedTitle');
			const aside = title?.nextElementSibling;
			if (title) title.textContent = featuredFilter ? featuredFilter.label : 'Productos destacados';
			if (aside) aside.innerHTML = featuredFilter
				? `<a class="campaign-featured-all" href="${escapeHtml(featuredFilter.href)}">Ver todo${featuredFilter.total ? ` (${featuredFilter.total})` : ''} <i data-lucide="arrow-right" width="14"></i></a>`
				: 'Selección SmartISP';
			section.classList.toggle('is-filtered', Boolean(featuredFilter));
			section.setAttribute('aria-busy', featuredFilter?.loading ? 'true' : 'false');
			featuredShown = featuredFilter ? (featuredFilter.products || []) : storefrontFeaturedProducts;
			section.classList.add('is-visible');
			section.classList.remove('is-pending');
			if (featuredFilter?.loading) {
				grid.innerHTML = Array.from({ length: 6 }, () => '<article class="campaign-product is-skeleton" aria-hidden="true"><div class="campaign-product-image"></div><div class="campaign-product-title"></div></article>').join('');
				return;
			}
			if (featuredFilter && !featuredShown.length) {
				grid.innerHTML = `<p class="campaign-featured-empty">${featuredFilter.error ? 'No se pudieron cargar los productos. Intenta de nuevo.' : 'Aún no hay productos visibles en esta categoría.'}</p>`;
				window.lucide?.createIcons();
				return;
			}
			grid.innerHTML = featuredShown.map((product, index) => {
				const imageUrl = getProductImageUrl(product);
				const productUrl = getProductUrl(product);
				return `<article class="campaign-product">
					<a class="campaign-product-image" href="${escapeHtml(productUrl)}" aria-label="Ver ${escapeHtml(product.name)}"><img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(product.name)}" width="320" height="246" loading="${index < 2 ? 'eager' : 'lazy'}" onerror="if(!this.dataset.failed){this.dataset.failed='1';this.src='${escapeHtml(fallbackImage(product))}';}"></a>
					<div class="campaign-product-category">${escapeHtml(product.category || 'SmartISP')}</div>
					<a class="campaign-product-title" href="${escapeHtml(productUrl)}">${escapeHtml(product.name || 'Producto')}</a>
					<p class="campaign-product-description">${escapeHtml(product.description || '')}</p>
					<div class="campaign-product-bottom"><strong class="campaign-product-price">${money(product.price)}</strong><button type="button" class="campaign-product-add" data-campaign-add="${index}" aria-label="Agregar ${escapeHtml(product.name)} al carrito"><i data-lucide="shopping-cart" width="15"></i> Agregar</button></div>
				</article>`;
			}).join('');
			section.classList.add('is-visible');
			section.classList.remove('is-pending');
			window.lucide?.createIcons();
		};
		let storefrontCampaignsSettled = false;
		const settleStorefrontPlaceholders = () => {
			if (storefrontCampaignsSettled) document.querySelector('#campaignFeatured')?.classList.remove('is-pending');
			if (storefrontCampaignsSettled && !isCatalogLoading) document.querySelector('#miniCategories')?.classList.remove('is-pending');
		};
		setTimeout(() => { storefrontCampaignsSettled = true; document.querySelectorAll('#campaignFeatured, #miniCategories').forEach(el => el.classList.remove('is-pending')); }, 5000);
		let miniCategoryItems = new Map();
		let featuredRequest = 0;
		const isMiniActive = (key, item) => {
			if (!window.isCategoriasView) return Boolean(featuredFilter && featuredFilter.key === key);
			if (!item || !selectedFilter || selectedFilter === 'all') return false;
			if (selectedFilter.type === 'macro') return item.categories.length !== 1 && String(selectedFilter.id) === key;
			if (selectedFilter.type === 'category' || selectedFilter.type === 'sub') return item.categories.length === 1 && normalizeCategoryText(item.categories[0]) === normalizeCategoryText(selectedFilter.category);
			return false;
		};
		const syncMiniActive = () => {
			document.querySelectorAll('#miniCategoriesGrid [data-mini-key]').forEach(card => {
				const on = isMiniActive(card.dataset.miniKey, miniCategoryItems.get(card.dataset.miniKey));
				card.classList.toggle('is-active', on);
				card.setAttribute('aria-pressed', String(on));
			});
		};
		// En el catálogo la tarjeta cambia el filtro igual que el menú lateral: sin recargar ni volver arriba.
		const selectMiniCategoryInCatalog = key => {
			const item = miniCategoryItems.get(key);
			if (!item) return;
			const params = new URLSearchParams();
			if (isMiniActive(key, item)) setCategoryFilter('all');
			else if (item.categories.length === 1) { setCategoryFilter(item.categories[0]); params.set('category', item.categories[0]); }
			else { setCategoryFilter({ type: 'macro', id: key }); params.set('macro', key); }
			const search = document.querySelector('#searchInput');
			if (search && search.value) search.value = '';
			history.pushState({ miniCategory: true }, '', `${window.location.pathname}${params.toString() ? `?${params}` : ''}`);
			syncMiniActive();
			const catalog = document.querySelector('#catalogo');
			if (catalog && catalog.getBoundingClientRect().top > window.innerHeight * .6) catalog.scrollIntoView({ behavior: 'smooth', block: 'start' });
		};
		window.addEventListener('popstate', () => {
			if (!window.isCategoriasView) return;
			const params = new URLSearchParams(window.location.search);
			const category = params.get('category') || params.get('categoria');
			const macro = params.get('macro');
			setCategoryFilter(category || (macro ? { type: 'macro', id: macro } : 'all'));
			syncMiniActive();
		});
		const selectMiniCategory = async key => {
			const item = miniCategoryItems.get(key);
			if (!item || !item.categories.length || (featuredFilter && featuredFilter.key === key)) featuredFilter = null;
			else {
				const card = document.querySelector(`#miniCategoriesGrid [data-mini-key="${CSS.escape(key)}"]`);
				featuredFilter = { key, label: item.name, href: card?.getAttribute('href') || '/categorias-destacadas', loading: true, products: [] };
			}
			syncMiniActive();
			renderStorefrontFeatured();
			const section = document.querySelector('#campaignFeatured');
			if (section && section.getBoundingClientRect().top > window.innerHeight * .7) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
			if (!featuredFilter) return;
			const filter = featuredFilter;
			const request = ++featuredRequest;
			const params = new URLSearchParams({ page: 1, limit: 12 });
			if (item.categories.length === 1) params.set('category', item.categories[0]);
			else params.set('category_list', JSON.stringify(item.categories));
			try {
				const res = await fetch(`/api/auth/catalog?${params.toString()}`, { cache: 'no-store' });
				if (!res.ok) throw new Error(String(res.status));
				const data = await res.json();
				filter.products = (data.products || []).map(product => ({ ...product, brand: product.category || 'SmartISP', image: getProductImageUrl(product), reviews: 0, tag: '', specs: [] }));
				filter.total = Number(data.total) || filter.products.length;
			} catch (e) {
				filter.error = true;
			}
			filter.loading = false;
			if (request === featuredRequest && featuredFilter === filter) renderStorefrontFeatured();
		};
		document.querySelector('#miniCategoriesGrid')?.addEventListener('click', event => {
			const card = event.target.closest('[data-mini-key]');
			if (!card || event.ctrlKey || event.metaKey || event.shiftKey || event.button) return;
			event.preventDefault();
			if (window.isCategoriasView) selectMiniCategoryInCatalog(card.dataset.miniKey);
			else selectMiniCategory(card.dataset.miniKey);
		});
		const renderMiniCategories = () => {
			settleStorefrontPlaceholders();
			const section = document.querySelector('#miniCategories');
			const grid = document.querySelector('#miniCategoriesGrid');
			if (!section || !grid || !catalogCategoryRows.length) return;
			const groups = new Map();
			catalogCategoryRows.forEach(cat => {
				const count = Number(cat.productCount || 0);
				if (!count) return;
				const family = getMacroCategory(cat.name);
				const groupId = family.id;
				if (!groups.has(groupId)) {
					groups.set(groupId, { id: groupId, name: family.name, icon: family.icon || 'package', macroImage: family.imageUrl || '', sortOrder: Number(family.sortOrder ?? 999), visible: family.visible !== false, count: 0, topCategory: null, topCount: 0, image: '', alt: '' });
				}
				const g = groups.get(groupId);
				g.count += count;
				if (count > g.topCount) { g.topCount = count; g.topCategory = cat.name; g.image = cat.iconImageUrl || ''; g.alt = cat.name; }
			});
			const sorted = [...groups.values()].filter(g => g.visible).sort((a, b) => (a.sortOrder - b.sortOrder) || (b.count - a.count));
			sorted.forEach(g => { g.image = g.macroImage || g.image; });
			const chosen = storefrontFeaturedCategoryIds.length
				? storefrontFeaturedCategoryIds.map(id => catalogCategoryRows.find(cat => String(cat.id) === String(id))).filter(cat => cat && Number(cat.productCount || 0) > 0).slice(0, 7)
				: [];
			const displayItems = chosen.length
				? chosen.map(cat => ({ id:cat.id, name:cat.name, icon:cat.icon || cat.macroGroupIcon || 'package', categories:[cat.name], image:cat.iconImageUrl || '', alt:cat.name, description:cat.description || '' }))
				: sorted.slice(0, 7).map(g => ({ ...g, categories: catalogCategoryRows.filter(cat => getMacroCategory(cat.name).id === g.id && Number(cat.productCount || 0) > 0).map(cat => cat.name) }));
			miniCategoryItems = new Map(displayItems.map(g => [String(g.id), g]));
			const cardsHtml = displayItems.map(g => {
				const href = g.categories.length === 1 ? `/categorias-destacadas?category=${encodeURIComponent(g.categories[0])}` : (g.id && g.id !== 'otros' ? `/categorias-destacadas?macro=${encodeURIComponent(g.id)}` : '/categorias-destacadas');
				const active = isMiniActive(String(g.id), g);
				return `<a class="mini-category-card${active ? ' is-active' : ''}" href="${escapeHtml(href)}" data-mini-key="${escapeHtml(String(g.id))}" role="button" aria-pressed="${active}">
					<span class="mini-category-visual">${g.image ? `<img src="${escapeHtml(g.image)}" alt="${escapeHtml(g.alt)}" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i data-lucide="${escapeHtml(g.icon)}" width="24" hidden></i>` : `<i data-lucide="${escapeHtml(g.icon)}" width="24"></i>`}</span>
					<span class="mini-category-name">${escapeHtml(g.name)}</span>
					${g.description ? `<span class="mini-category-description">${escapeHtml(g.description)}</span>` : ''}
				</a>`;
			}).join('');
			const moreHtml = `<a class="mini-category-card" href="/categorias-destacadas">
				<span class="mini-category-visual"><i data-lucide="more-horizontal" width="24"></i></span>
				<span class="mini-category-name">Más Categorías</span>
			</a>`;
			grid.innerHTML = cardsHtml + moreHtml;
			section.classList.add('is-visible');
			section.classList.remove('is-pending');
			window.lucide?.createIcons();
		};
		const stopStorefrontRotation = () => { if (storefrontRotationTimer) clearInterval(storefrontRotationTimer); storefrontRotationTimer = 0; clearStorefrontImageTimers(); };
		const startStorefrontRotation = seconds => {
			if (storefrontRotationTimer) clearInterval(storefrontRotationTimer);
			storefrontRotationTimer = 0;
			if (storefrontDisplayMode !== 'carousel' || storefrontSlides.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
			storefrontRotationTimer = setInterval(() => renderStorefrontSlide(storefrontSlideIndex + 1), Math.max(4, Math.min(20, Number(seconds) || 7)) * 1000);
		};
		// Subtitulo del banner: por defecto texto plano que SI respeta los saltos de linea que
		// escribe el admin (antes se perdian, quedaba todo en un parrafo corrido). Si el admin
		// elige un estilo de vineta (bullet_style != 'none'), cada linea no vacia se muestra como
		// item con marcador (check/circulo/cuadrado) en el color elegido (bullet_color).
		const campaignBulletMarkup = (style, color) => {
			if (style === 'check') return `<i data-lucide="check" width="14" style="color:${escapeHtml(color)}"></i>`;
			if (style === 'check-circle') return `<span class="store-campaign-bullet-mark store-campaign-bullet-check-circle" style="background:${escapeHtml(color)}"><i data-lucide="check"></i></span>`;
			return `<span class="store-campaign-bullet-mark store-campaign-bullet-${style}" style="background:${escapeHtml(color)}"></span>`;
		};
		const campaignSubtitleHtml = slide => {
			const text = String(slide.subtitle || '');
			if (!text) return '';
			const style = ['check', 'circle', 'square', 'check-circle'].includes(slide.bullet_style) ? slide.bullet_style : 'none';
			if (style === 'none') return escapeHtml(text).replace(/\n/g, '<br>');
			const color = /^#[0-9a-fA-F]{6}$/.test(slide.bullet_color || '') ? slide.bullet_color : '#16a34a';
			const items = text.split('\n').map(line => line.trim()).filter(Boolean);
			return `<ul class="store-campaign-bullets">${items.map(item => `<li>${campaignBulletMarkup(style, color)}<span>${escapeHtml(item)}</span></li>`).join('')}</ul>`;
		};
		const campaignButtonVisible = slide => !(slide.button_visible === false || slide.button_visible === 0 || slide.button_visible === '0');
		const campaignButton2Visible = slide => (slide.button2_visible === true || slide.button2_visible === 1 || slide.button2_visible === '1') && Boolean(slide.button2_text);
		const CAMPAIGN_FONT_SCALE = { sm: .8, md: 1, lg: 1.25, xl: 1.5 };
		const applyCampaignFontScale = (el, sizeKey) => {
			if (!el) return;
			const scale = CAMPAIGN_FONT_SCALE[sizeKey];
			if (!scale || scale === 1) { el.style.fontSize = ''; return; }
			const current = parseFloat(getComputedStyle(el).fontSize) || 16;
			el.style.fontSize = (current * scale).toFixed(1) + 'px';
		};
		const applyCampaignCardFontScales = (host, slides) => {
			host.querySelectorAll('[data-campaign-card]').forEach(card => {
				const slide = slides[Number(card.dataset.campaignCard)];
				if (!slide) return;
				applyCampaignFontScale(card.querySelector('.store-campaign-eyebrow'), slide.eyebrow_size);
				applyCampaignFontScale(card.querySelector('.store-campaign-title'), slide.title_size);
				applyCampaignFontScale(card.querySelector('.store-campaign-subtitle'), slide.subtitle_size);
			});
		};
		const campaignTextColorStyle = slide => {
			const subtitle = /^#[0-9a-fA-F]{6}$/.test(slide.subtitle_color || '') ? `color:${slide.subtitle_color};` : '';
			const eyebrow = /^#[0-9a-fA-F]{6}$/.test(slide.eyebrow_color || '') ? `color:${slide.eyebrow_color};` : '';
			return { subtitle, eyebrow };
		};
		const campaignTitleHtml = slide => `<span${slide.title_color ? ` style="color:${escapeHtml(slide.title_color)}"` : ''}>${escapeHtml(slide.title || '')}</span>${slide.title_line2 ? `<br><span${slide.title_line2_color ? ` style="color:${escapeHtml(slide.title_line2_color)}"` : ''}>${escapeHtml(slide.title_line2)}</span>` : ''}`;
		const campaignEyebrowHtml = slide => `${escapeHtml(slide.eyebrow || '')}${slide.eyebrow_line2 ? `<br>${escapeHtml(slide.eyebrow_line2)}` : ''}`;
		let trustStatsDataPromise = null;
		const loadTrustStatsData = () => {
			if (trustStatsDataPromise) return trustStatsDataPromise;
			trustStatsDataPromise = Promise.all([
				fetch('/api/auth/landing-content', { cache: 'no-store' }).then(r => r.ok ? r.json() : { content: [] }).catch(() => ({ content: [] })),
				fetch('/api/auth/catalog?page=1&limit=1', { cache: 'no-store' }).then(r => r.ok ? r.json() : null).catch(() => null),
			]).then(([landing, catalog]) => {
				const map = {};
				(landing.content || []).forEach(row => { map[row.key] = row.value; });
				return {
					clients: map.trust_stats_clients_default || '',
					projects: map.trust_stats_projects_default || '',
					support: map.trust_stats_support_default || '',
					productsLabel: map.trust_stats_products_label_default || 'Productos disponibles',
					// null (no "+0" fantasma) si fallo el fetch del catalogo.
					productsCount: catalog && Number(catalog.total) > 0 ? Number(catalog.total) : null,
					whatsapp: String(map.footer_whatsapp || '').replace(/\D/g, ''),
					email: String(map.footer_email || '').trim(),
				};
			});
			return trustStatsDataPromise;
		};
		const trustStatPair = text => {
			const [value, label] = String(text || '').split('\n');
			return { value: (value || '').trim(), label: (label || '').trim() };
		};
		const BUTTON_CONTACT_ICON_SVG = { whatsapp: 'message-circle', instagram: 'camera', email: 'mail', other: 'globe' };
		const applyButtonIcon = (slide, iconField, linkEl, textEl) => {
			const icon = slide[iconField];
			if (!icon || !BUTTON_CONTACT_ICON_SVG[icon] || !linkEl) return;
			let iconEl = linkEl.querySelector('.store-campaign-cta-icon');
			if (!iconEl) {
				iconEl = document.createElement('i');
				iconEl.className = 'store-campaign-cta-icon';
				linkEl.insertBefore(iconEl, textEl || linkEl.firstChild);
			}
			iconEl.dataset.lucide = BUTTON_CONTACT_ICON_SVG[icon];
			if (icon === 'whatsapp' || icon === 'email') {
				loadTrustStatsData().then(defaults => {
					if (icon === 'whatsapp' && defaults.whatsapp) {
						linkEl.href = `https://wa.me/${defaults.whatsapp}?text=${encodeURIComponent('Hola SmartISP, tengo una consulta general.')}`;
						linkEl.target = '_blank'; linkEl.rel = 'noopener noreferrer';
					} else if (icon === 'email' && defaults.email) {
						linkEl.href = `mailto:${defaults.email}`;
						linkEl.removeAttribute('target'); linkEl.removeAttribute('rel');
					}
					window.lucide?.createIcons();
				});
			} else {
				window.lucide?.createIcons();
			}
		};
		const renderTrustStats = (slide, statsEl) => {
			if (!statsEl) return;
			if (!slide.show_trust_stats) { statsEl.hidden = true; statsEl.innerHTML = ''; return; }
			loadTrustStatsData().then(defaults => {
				const clients = trustStatPair(slide.trust_stats_clients_override || defaults.clients);
				const projects = trustStatPair(slide.trust_stats_projects_override || defaults.projects);
				const support = trustStatPair(slide.trust_stats_support_override || defaults.support);
				const items = [
					defaults.productsCount ? { value: `+${defaults.productsCount.toLocaleString('es-EC')}`, label: defaults.productsLabel } : null,
					clients.value ? clients : null,
					projects.value ? projects : null,
					support.value ? support : null,
				].filter(Boolean);
				statsEl.innerHTML = items.map(item => `<div class="store-campaign-stat"><strong>${escapeHtml(item.value)}</strong>${item.label ? `<span>${escapeHtml(item.label)}</span>` : ''}</div>`).join('');
				statsEl.hidden = !items.length;
			});
		};
		const renderFeatureBadges = (slide, el) => {
			if (!el) return;
			const items = Array.isArray(slide.feature_badges) ? slide.feature_badges.filter(b => b && b.text) : [];
			el.innerHTML = items.map(item => {
				const color = /^#[0-9a-fA-F]{6}$/.test(item.color || '') ? item.color : '#087fa5';
				return `<div class="store-campaign-feature"><span class="store-campaign-feature-icon" style="--fb-color:${color}"><i data-lucide="${escapeHtml(item.icon || 'star')}"></i></span><span class="store-campaign-feature-text">${escapeHtml(item.text)}</span></div>`;
			}).join('');
			el.hidden = !items.length;
			if (items.length) window.lucide?.createIcons();
		};
		// Esqueleto del hero principal: visible por defecto (reserva espacio, sin salto de
		// layout), se desvanece cuando hay banners reales; si la zona no tiene nada, se colapsa
		// toda el area, igual que el comportamiento de siempre.
		const storeCampaignCollapse = () => { const area = document.querySelector('.store-campaign-area'); if (area) area.hidden = true; };
		const storeCampaignReveal = () => {
			const area = document.querySelector('.store-campaign-area');
			if (area) area.hidden = false;
			const skeletonEl = document.querySelector('#storeCampaignSkeleton');
			if (skeletonEl) skeletonEl.hidden = true;
			const banner = document.querySelector('#storeCampaign');
			requestAnimationFrame(() => requestAnimationFrame(() => banner?.classList.add('is-faded-in')));
		};
		const renderCampaignCard = (slide, index) => {
			const images = campaignSlideImages(slide);
			const image = images[0] || {};
			const target = slide.target_url || '/tienda.html#catalogo';
			const promoTarget = slide.promo_chip_target_url || target;
			const tone = ['new','featured','dark'].includes(slide.badge_tone) ? slide.badge_tone : '';
			const icon = ['sparkles','star','truck'].includes(slide.promo_chip_icon) ? slide.promo_chip_icon : 'tag';
			const isSide = slide.card_layout === 'side';
			const imageOpacity = Math.max(0,Math.min(100,Number(slide.image_opacity ?? 100)))/100;
			const configuredOverlayOpacity = Math.max(0,Math.min(100,Number(slide.overlay_opacity ?? 68)))/100;
			const overlayOpacity = isSide ? configuredOverlayOpacity : Math.max(.55, configuredOverlayOpacity);
			// "full" (por defecto visual del diseño original): imagen de fondo a toda la tarjeta,
			// image_width_pct no aplica -- no tiene sentido un ancho parcial en un fondo completo.
			// "side": imagen angosta a la derecha + panel de texto; el ancho real (35-65%, del
			// slider del editor) decide tanto el tamaño de imagen/texto como donde cae el
			// degradado de transición, calculado aquí en vez de una posición fija en CSS (ese
			// desfase -- banner con la franja de transición en el lugar equivocado del borde real
			// de la imagen -- era el bug real de la versión lateral).
			let mediaStyle = `object-fit:${slide.image_fit === 'cover' ? 'cover' : 'contain'};opacity:${imageOpacity}`;
			let overlayStyle = `opacity:${overlayOpacity}`;
			let mainStyle = '';
			if (isSide) {
				const imageWidth = Math.max(35, Math.min(65, Number(slide.image_width_pct ?? 55)));
				const textWidth = 100 - imageWidth;
				const copyBackground = /^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color || '') ? slide.copy_background_color : '#ffffff';
				const overlayBandWidth = 12;
				const overlayStart = Math.max(0, textWidth - overlayBandWidth / 2);
				mediaStyle = `inset:0 0 0 auto;width:${imageWidth}%;object-position:center;${mediaStyle}`;
				overlayStyle = `inset:0 auto 0 ${overlayStart}%;width:${overlayBandWidth}%;${overlayStyle}`;
				mainStyle = `width:${textWidth}%;background:${copyBackground}`;
			}
			// "full": sin ancho parcial inline -- usa el fondo a toda la tarjeta que ya define
			// la clase CSS base (.store-campaign-card-media), igual que la version original.
			const focus = campaignImageFocus(image);
			mediaStyle += `;object-position:${focus.x}% ${focus.y}%;transform:scale(${focus.zoom});transform-origin:${focus.x}% ${focus.y}%`;
			const textColor = campaignTextColorStyle(slide);
			return `<article class="store-campaign-card${isSide ? ' is-layout-side' : ' is-layout-full'}" data-campaign-card="${index}"><img class="store-campaign-card-media" data-campaign-image data-slide-index="${index}" src="${escapeHtml(image.image_url || '')}" alt="${escapeHtml(image.image_alt || slide.title || '')}" style="${mediaStyle}"><span class="store-campaign-card-overlay" style="${overlayStyle}"></span><a class="store-campaign-card-main" href="${escapeHtml(target)}" style="${mainStyle}" ${campaignExternal(target) ? 'target="_blank" rel="noopener noreferrer"' : ''}><span class="store-campaign-card-copy"><span class="store-campaign-eyebrow" style="${textColor.eyebrow}" ${slide.eyebrow?'':'hidden'}>${campaignEyebrowHtml(slide)}</span><span class="store-campaign-title">${campaignTitleHtml(slide)}</span><span class="store-campaign-subtitle" style="${textColor.subtitle}" ${slide.subtitle?'':'hidden'}>${campaignSubtitleHtml(slide)}</span><span class="store-campaign-cta" ${campaignButtonVisible(slide)?'':'hidden'}>${escapeHtml(slide.button_text||'Explorar')} →</span></span></a>${slide.badge_label?`<span class="store-campaign-badge store-campaign-card-badge ${tone}">${escapeHtml(slide.badge_label)}</span>`:''}${slide.promo_chip_label?`<a class="store-campaign-card-promo" href="${escapeHtml(promoTarget)}" ${campaignExternal(promoTarget) ? 'target="_blank" rel="noopener noreferrer"' : ''}><i data-lucide="${icon}" width="14"></i>${escapeHtml(slide.promo_chip_label)}</a>`:''}</article>`;
		};
		const startLayoutImageRotations = () => {
			clearStorefrontImageTimers();
			document.querySelectorAll('#storeCampaign .store-campaign-card [data-campaign-image]').forEach(image => {
				const slide = storefrontSlides[Number(image.dataset.slideIndex)];
				if (slide) startStorefrontImageRotation(image, slide);
			});
		};
		let secondaryCampaignSlides = [];
		const startSecondaryImageRotations = () => {
			document.querySelectorAll('#secondaryCampaign .store-campaign-card [data-campaign-image]').forEach((image, index) => {
				const slide = secondaryCampaignSlides[index];
				if (slide) startStorefrontImageRotation(image, slide);
			});
		};
		const loadSecondaryCampaigns = async () => {
			try {
				const response = await fetch(`/api/auth/store-campaigns?zone=store-secondary&_t=${Date.now()}`, { cache: 'no-store' });
				if (!response.ok) return;
				const data = await response.json();
				const section = document.querySelector('#secondaryCampaignArea');
				const host = document.querySelector('#secondaryCampaign');
				secondaryCampaignSlides = Array.isArray(data.slides) ? data.slides : [];
				if (!section || !host || !secondaryCampaignSlides.length) return;
				const mode = ['split','triple','grid','cards'].includes(data.display_mode) ? data.display_mode : 'split';
				const limit = { split: 2, triple: 3, grid: 4 }[mode] || secondaryCampaignSlides.length;
				host.className = `store-campaign is-layout is-visible is-${mode}${data.mobile_swipe ? ' is-mobile-swipe' : ''}`;
				section.classList.toggle('is-full-width', data.layout_width === 'full');
				const secondarySliceForRender = secondaryCampaignSlides.slice(0, limit);
				host.innerHTML = secondarySliceForRender.map((slide, index) => renderCampaignCard(slide, index)).join('');
				section.hidden = false;
				applyCampaignCardFontScales(host, secondarySliceForRender);
				startSecondaryImageRotations();
				window.lucide?.createIcons();
				// No tiene esqueleto propio (altura variable segun modo, uno fijo no serviria):
				// se revela directo, sin el fade con doble rAF que usa el banner principal.
				requestAnimationFrame(() => host.classList.add('is-faded-in'));
			} catch (error) {
				console.warn('No se pudieron cargar los banners secundarios:', error);
			}
		};
		// Pinta el hero rico (#storeCampaign: carousel/single/split/triple/grid/cards, con badges,
		// features y stats) a partir de la respuesta de /api/auth/store-campaigns, sin importar la
		// zona pedida -- usado tanto por la portada de la tienda como por /categorias-destacadas.
		const renderStorefrontHero = data => {
			storefrontSlides = Array.isArray(data.slides) ? data.slides : [];
			storefrontDisplayMode = ['carousel', 'single', 'split', 'triple', 'grid', 'cards'].includes(data.display_mode) ? data.display_mode : 'carousel';
			const heroHeightPct = Math.max(60, Math.min(100, Number(data.hero_height_pct) || 100));
			document.querySelector('#storeCampaign')?.style.setProperty('--hero-ar', (240 / heroHeightPct).toFixed(3));
			document.querySelector('.store-campaign-area')?.classList.toggle('is-full-width', data.layout_width === 'full');
			if (storefrontSlides.length) {
				const banner = document.querySelector('#storeCampaign');
				banner.classList.remove('is-single','is-contain','is-triple','is-layout','is-split','is-grid','is-cards');
				if (['split','triple','grid','cards'].includes(storefrontDisplayMode)) {
					banner.classList.add('is-layout', `is-${storefrontDisplayMode}`);
					const limit = { split:2, triple:3, grid:4 }[storefrontDisplayMode] || storefrontSlides.length;
					const primarySliceForRender = storefrontSlides.slice(0,limit);
					banner.innerHTML = primarySliceForRender.map((slide,index)=>renderCampaignCard(slide,index)).join('');
					banner.classList.add('is-visible');
					applyCampaignCardFontScales(banner, primarySliceForRender);
					startLayoutImageRotations();
					window.lucide?.createIcons();
				} else {
					banner.classList.toggle('is-single', storefrontDisplayMode === 'single');
					const dots = document.querySelector('#storeCampaignDots');
					dots.innerHTML = storefrontDisplayMode === 'carousel' ? storefrontSlides.map((slide,index)=>`<button class="store-campaign-dot" type="button" aria-label="Mostrar banner ${index+1}: ${escapeHtml(slide.title)}" aria-current="${index===0?'true':'false'}" data-campaign-slide="${index}"></button>`).join('') : '';
					const controls = document.querySelector('#storeCampaignControls');
					if (controls) controls.hidden = storefrontDisplayMode !== 'carousel' || storefrontSlides.length < 2;
					document.querySelector('#storeCampaignPrev')?.addEventListener('click',()=>{renderStorefrontSlide(storefrontSlideIndex-1);startStorefrontRotation(data.rotation_seconds);});
					document.querySelector('#storeCampaignNext')?.addEventListener('click',()=>{renderStorefrontSlide(storefrontSlideIndex+1);startStorefrontRotation(data.rotation_seconds);});
					dots.addEventListener('click',event=>{const dot=event.target.closest('[data-campaign-slide]');if(dot){renderStorefrontSlide(Number(dot.dataset.campaignSlide));startStorefrontRotation(data.rotation_seconds);}});
					renderStorefrontSlide(0);
					startStorefrontRotation(data.rotation_seconds);
				}
				const resumeStorefrontAnimations = () => {
					if (['carousel','single'].includes(storefrontDisplayMode)) {
						startStorefrontImageRotation(document.querySelector('#storeCampaignImage'),storefrontSlides[storefrontSlideIndex]);
						startStorefrontRotation(data.rotation_seconds);
					} else startLayoutImageRotations();
					startSecondaryImageRotations();
				};
				banner.addEventListener('mouseenter',stopStorefrontRotation);
				banner.addEventListener('mouseleave',resumeStorefrontAnimations);
				banner.addEventListener('focusin',stopStorefrontRotation);
				banner.addEventListener('focusout',resumeStorefrontAnimations);
				document.addEventListener('visibilitychange',()=>document.hidden?stopStorefrontRotation():resumeStorefrontAnimations());
				storeCampaignReveal();
			} else {
				storeCampaignCollapse();
			}
		};
		const loadStorefrontCampaigns = async () => {
			loadSecondaryCampaigns();
			try {
				const response = await fetch(`/api/auth/store-campaigns?_t=${Date.now()}`, { cache: 'no-store' });
				if (!response.ok) { storeCampaignCollapse(); return; }
				const data = await response.json();
				storefrontFeaturedCategoryIds = Array.isArray(data.featured_category_ids) ? data.featured_category_ids.map(String) : [];
				renderMiniCategories();
				storefrontPromoChips = Array.isArray(data.chips) ? data.chips : [];
				storefrontFeaturedProducts = Array.isArray(data.products) ? data.products.map(product => ({
					...product,
					brand: product.category || 'SmartISP',
					image: getProductImageUrl(product),
					reviews: Number(product.reviews || 0),
					tag: '',
					specs: []
				})) : [];
				renderStorefrontHero(data);
				const chipsHost = document.querySelector('#storePromoChips');
				if (chipsHost && storefrontPromoChips.length) {
					chipsHost.innerHTML = storefrontPromoChips.map(chip => `<a class="store-promo-chip" href="${escapeHtml(chip.target_url)}"><i data-lucide="${escapeHtml(chip.icon || 'tag')}" width="17"></i>${escapeHtml(chip.label)}</a>`).join('');
					chipsHost.hidden = false;
					window.lucide?.createIcons();
				}
				renderStorefrontFeatured();
			} catch (error) {
				console.warn('No se pudieron cargar las campañas de la tienda:', error);
				storeCampaignCollapse();
			} finally {
				storefrontCampaignsSettled = true;
				settleStorefrontPlaceholders();
			}
		};
		document.querySelector('#campaignProductsGrid')?.addEventListener('click', event => {
			const button = event.target.closest('[data-campaign-add]');
			if (!button) return;
			const product = featuredShown[Number(button.dataset.campaignAdd)];
			if (product) addToCart(product);
		});

		// Verificación de producto directo por URL (?p= o ?id=)
		const checkDirectProductLanding = async () => {
			try {
				const urlParams = new URLSearchParams(window.location.search);
				const targetId = urlParams.get('p') || urlParams.get('id');
				if (!targetId) return;

				let found = products.find(p => String(p.id).toLowerCase() === String(targetId).toLowerCase() || String(p.sku).toLowerCase() === String(targetId).toLowerCase());
				if (found) {
					window.location.replace(getProductUrl(found));
					return;
				}

				// Si el producto no estaba en la primera página de 36, buscarlo directamente en la API
				try {
					const res = await fetch(`/api/auth/catalog?q=${encodeURIComponent(targetId)}&limit=1`);
					if (res.ok) {
						const data = await res.json();
						if (data.products && data.products.length) {
							const single = {
								...data.products[0],
								brand: data.products[0].category || 'SmartISP',
								image: getProductImageUrl(data.products[0]),
								reviews: Number(data.products[0].reviews || 0),
								tag: data.products[0].tag || '',
								specs: Array.isArray(data.products[0].specs) ? data.products[0].specs : []
							};
							if (!products.some(p => p.id === single.id)) {
								products.push(single);
							}
							window.location.replace(getProductUrl(single));
						}
					}
				} catch (err) {
					console.warn('No se pudo cargar el producto solicitado:', err);
				}
			} catch (e) {
				console.warn('Error verificando enlace directo de producto:', e);
			}
		};

		// Soporte de parámetros de URL para filtrar categorías, búsquedas y carrito al cargar
		try {
			const urlParams = new URLSearchParams(window.location.search);
			const catParam = urlParams.get('categoria') || urlParams.get('category');
			const macroParam = urlParams.get('macro');
			const searchParam = urlParams.get('q') || urlParams.get('buscar') || urlParams.get('s');
			const cartParam = urlParams.get('openCart') || urlParams.get('carrito');
			const accountParam = urlParams.get('openAccount') || urlParams.get('cuenta');
			const pathIsCategorias = window.location.pathname.replace(/\/$/, '') === '/categorias-destacadas';
			window.isCategoriasView = pathIsCategorias || Boolean(catParam) || Boolean(macroParam) || Boolean(searchParam);
			if (window.isCategoriasView) {
				document.querySelector('#catalogView').hidden = false;
				document.querySelectorAll('#mNavHome,#mNavCategories').forEach(el => el.classList.toggle('active', el.id === 'mNavCategories'));
				document.querySelectorAll('.public-nav a, .public-nav-strip a').forEach(a => a.removeAttribute('aria-current'));
				document.querySelectorAll('.public-nav a[href="/categorias-destacadas"], .public-nav-strip a[href="/categorias-destacadas"]').forEach(a => a.setAttribute('aria-current', 'page'));
			}

			if (catParam) {
				const macro = getMacroCategory(catParam);
				selectedFilter = { type: 'category', macroId: macro.id, category: catParam };
				expandedGroups.add(macro.id);
			} else if (macroParam) {
				selectedFilter = { type: 'macro', id: macroParam };
				expandedGroups.add(macroParam);
			}

			if (searchParam) {
				const sInput = document.querySelector('#searchInput');
				if (sInput) {
					sInput.value = searchParam;
					setTimeout(() => {
						const catEl = document.querySelector('#catalogo');
						if (catEl) catEl.scrollIntoView({ behavior: 'smooth' });
					}, 200);
				}
			}

			if (cartParam) {
				setTimeout(() => {
					setCartOpen(true);
				}, 300);
			}
			if (accountParam) {
				urlParams.delete('openAccount');
				urlParams.delete('cuenta');
				const remainingParams = urlParams.toString();
				const cleanUrl = `${window.location.pathname}${remainingParams ? `?${remainingParams}` : ''}${window.location.hash}`;
				if (urlParams.has('embedAccount')) document.body.classList.add('account-embed');
				window.history.replaceState(window.history.state, '', cleanUrl);
				setTimeout(() => {
					document.querySelector('#openAccount')?.click();
				}, 300);
			}
		} catch (e) {
			console.warn('URL params no disponibles:', e);
		}

		const applyCategoriasViewMode = () => {
			if (!window.isCategoriasView) return;
			// El hero (.store-campaign-area) SI se muestra en modo catalogo -- usa el mismo
			// componente rico que la portada, solo que con la zona "categorias" (ver mas abajo).
			document.querySelectorAll('#storePromoChips, #campaignFeatured, .store-trust-row, #storeBrandsRow').forEach(el => { el.hidden = true; el.classList.remove('is-pending'); });
		};
		applyCategoriasViewMode();

		if (!window.isCategoriasView && window.initBrandsSection) {
			window.initBrandsSection({ containerSelector: '#storeBrandsRow', listSelector: '#storeBrandsList', titleSelector: '#storeBrandsTitle', itemClass: 'store-brand-item' });
		}

		renderSidebarCategories();
		render();
		window.lucide && window.lucide.createIcons();
		applyRemoteSite();
		// En modo catalogo (/categorias-destacadas) los banners secundarios y productos destacados
		// de la portada siguen ocultos (ver applyCategoriasViewMode), pero el hero principal SI se
		// pinta ahi -- con la zona "categorias" en vez de la zona por defecto de la tienda -- para
		// que se vea igual de rico (badges, features, marcas) que el de la portada.
		if (window.isCategoriasView) {
			fetch(`/api/auth/store-campaigns?zone=categorias&_t=${Date.now()}`, { cache: 'no-store' })
				.then(response => response.ok ? response.json() : null)
				.then(data => {
					if (!data) { storeCampaignCollapse(); return; }
					storefrontFeaturedCategoryIds = Array.isArray(data.featured_category_ids) ? data.featured_category_ids.map(String) : [];
					renderMiniCategories();
					renderStorefrontHero(data);
				})
				.catch(() => storeCampaignCollapse());
		} else {
			loadStorefrontCampaigns().then(applyCategoriasViewMode);
		}

		// =============================================================
		// CONTROLADORES DE LA BARRA INFERIOR MÓVIL (BOTTOM NAV)
		// =============================================================
		const mNavHome = document.querySelector('#mNavHome');
		if (mNavHome) {
			mNavHome.addEventListener('click', () => {
				if (window.isCategoriasView) { window.location.href = '/tienda.html'; return; }
				selectedFilter = 'all';
				if (typeof renderSidebarCategories === 'function') renderSidebarCategories();
				const searchInput = document.querySelector('#searchInput');
				if (searchInput) searchInput.value = '';
				render();
				window.scrollTo({ top: 0, behavior: 'smooth' });
				document.querySelectorAll('.mobile-nav-item').forEach(el => el.classList.remove('active'));
				mNavHome.classList.add('active');
			});
		}

		const mNavCategories = document.querySelector('#mNavCategories');
		if (mNavCategories) {
			mNavCategories.addEventListener('click', () => {
				if (!window.isCategoriasView) { window.location.href = '/categorias-destacadas'; return; }
				openCategoryDrawer();
				document.querySelectorAll('.mobile-nav-item').forEach(el => el.classList.remove('active'));
				mNavCategories.classList.add('active');
			});
		}

		const mNavSearch = document.querySelector('#mNavSearch');
		if (mNavSearch) {
			mNavSearch.addEventListener('click', () => {
				if (!window.isCategoriasView) { window.location.href = '/categorias-destacadas'; return; }
				const searchInput = document.querySelector('#searchInput');
				if (searchInput) {
					searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
					setTimeout(() => searchInput.focus(), 300);
				}
			});
		}

		const mNavCart = document.querySelector('#mNavCart');
		if (mNavCart) {
			mNavCart.addEventListener('click', () => {
				const openCart = document.querySelector('#openCart');
				if (openCart) openCart.click();
			});
		}

		const mNavAccount = document.querySelector('#mNavAccount');
		if (mNavAccount) {
			mNavAccount.addEventListener('click', () => {
				const openAccount = document.querySelector('#openAccount');
				if (openAccount) openAccount.click();
			});
		}
		// SISTEMA DE CUENTAS, PERFIL, HISTORIAL Y ACCESO ADMINISTRADOR
		const accountSystem = document.createElement('div');
		accountSystem.className = 'account-system';
		accountSystem.id = 'accountSystemModal';
		accountSystem.innerHTML = `
			<div class="account-shell">
				<div class="account-layout">
					<aside class="account-aside">
						<div>
							<div class="account-aside-mark"><i data-lucide="shield-check" width="23"></i></div>
							<h2>Tu espacio, a tu manera.</h2>
							<p>Gestiona tus compras, lista de deseos, datos personales y preferencias desde un solo lugar seguro.</p>
						</div>
						<div class="account-aside-note"><i data-lucide="lock-keyhole" width="15"></i><span>Tus datos viajan protegidos con cifrado SSL</span></div>
					</aside>
					<section class="account-main">
						<button class="account-system-close" type="button" aria-label="Cerrar ventana"><i data-lucide="x" width="18"></i></button>
						
						<!-- VISTA DE AUTENTICACIÓN (LOGIN / REGISTRO) -->
						<div id="accountAuthView">
							<div class="account-kicker">Área personal</div>
							<h2 id="accountSystemTitle">Inicia sesión</h2>
							<p class="account-main-intro">Accede a tus preferencias, favoritos y agiliza tus próximas compras.</p>
							<div class="account-tabs">
								<button class="account-tab is-active" type="button" data-account-tab="login">Iniciar sesión</button>
								<button class="account-tab" type="button" data-account-tab="register">Crear cuenta</button>
							</div>
							<div class="account-pane" id="accountLoginPane">
								<form id="systemLoginForm" method="POST" action="javascript:void(0);">
									<label class="form-field">Email<input name="email" type="email" placeholder="tu@correo.com" required autocomplete="email"></label>
									<label class="form-field">Contraseña<input name="password" type="password" placeholder="Tu contraseña" minlength="4" required autocomplete="current-password"></label>
									<div class="form-options">
										<label><input type="checkbox" name="remember" checked> Recordarme en este equipo</label>
										<a href="reset-password.html" target="_blank" rel="noopener">¿Olvidaste tu contraseña?</a>
									</div>
									<button class="primary account-submit" type="submit">Entrar <i data-lucide="arrow-right" width="16"></i></button>
									<div class="form-message" data-account-message></div>
								</form>
							</div>
							<div class="account-pane" id="accountRegisterPane" hidden>
								<form id="systemRegisterForm" method="POST" action="javascript:void(0);">
									<div class="account-grid">
										<label class="form-field">Nombre<input name="name" type="text" placeholder="Tu nombre" minlength="2" required autocomplete="given-name"></label>
										<label class="form-field">Apellido<input name="surname" type="text" placeholder="Tu apellido" minlength="2" required autocomplete="family-name"></label>
									</div>
									<label class="form-field">Email<input name="email" type="email" placeholder="tu@correo.com" required autocomplete="email"></label>
									<label class="form-field">Teléfono<input name="phone" type="tel" placeholder="+593 999 999 999" minlength="7" required autocomplete="tel"></label>
									<div class="account-grid">
										<label class="form-field">Contraseña<input name="password" type="password" placeholder="Mínimo 6 caracteres" minlength="6" required autocomplete="new-password"></label>
										<label class="form-field">Repetir contraseña<input name="confirmation" type="password" placeholder="Repite tu contraseña" minlength="6" required autocomplete="new-password"></label>
									</div>
									<button class="primary account-submit" type="submit">Crear cuenta <i data-lucide="arrow-right" width="16"></i></button>
									<div class="form-message" data-account-message></div>
								</form>
							</div>
						</div>

						<!-- VISTA DE PERFIL DE USUARIO -->
						<div id="accountProfileView" hidden>
							<div class="account-kicker">Mi Cuenta</div>
							<h2>Panel de usuario</h2>
							<p class="account-main-intro">Gestiona tus pedidos, artículos guardados, perfil y configuración.</p>
							
							<div class="profile-summary">
								<div>
									<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
										<strong data-profile-name style="font-size:18px;">Usuario</strong>
										<span class="profile-admin-badge" data-admin-badge style="display: none;"><i data-lucide="shield" width="12"></i> Administrador</span>
									</div>
									<span data-profile-email style="display:block;margin-top:2px;"></span>
								</div>
							</div>

							<div class="profile-actions">
								<button class="profile-action is-active" type="button" data-account-panel="wishlist">
									<i data-lucide="heart" width="18"></i>
									<span>Wishlist<small>Productos guardados</small></span>
								</button>
								<button class="profile-action" type="button" data-account-panel="orders">
									<i data-lucide="package-check" width="18"></i>
									<span>Pedidos e historial<small>Consulta tus compras</small></span>
								</button>
								<button class="profile-action" type="button" data-account-panel="profile">
									<i data-lucide="user-cog" width="18"></i>
									<span>Datos personales<small>Editar tu información</small></span>
								</button>
								<button class="profile-action" type="button" data-account-panel="settings">
									<i data-lucide="sliders-horizontal" width="18"></i>
									<span>Configuraciones<small>Apariencia y preferencias</small></span>
								</button>
								<a class="profile-action profile-action--admin" data-admin-profile-btn href="admin.html" style="display: none;">
									<i data-lucide="layout-dashboard" width="18"></i>
									<span>Panel de Control (Admin)<small>Acceso completo para administrar productos, catálogo y web</small></span>
								</a>
							</div>

							<!-- SECCIÓN WISHLIST -->
							<div class="settings-section hub-section" data-hub-panel="wishlist">
								<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
									<h3 style="margin:0;"><i data-lucide="heart" width="16" style="vertical-align:middle;margin-right:6px;color:#d35b63;"></i> Mi Wishlist</h3>
									<span style="font-size:12px;color:var(--muted);" data-wishlist-count>0 productos</span>
								</div>
								<div class="hub-list" data-wishlist-list></div>
							</div>

							<!-- SECCIÓN PEDIDOS -->
							<div class="settings-section hub-section" data-hub-panel="orders" hidden>
								<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
									<h3 style="margin:0;"><i data-lucide="package-check" width="16" style="vertical-align:middle;margin-right:6px;color:var(--blue);"></i> Pedidos e Historial</h3>
									<span style="font-size:12px;color:var(--muted);" data-orders-count>0 pedidos</span>
								</div>
								<div class="hub-list" data-orders-list></div>
							</div>

							<!-- SECCIÓN DATOS PERSONALES Y CONTRASEÑA -->
							<div class="settings-section" data-hub-panel="profile" hidden>
								<h3>Datos personales</h3>
								<div class="account-grid">
									<label class="form-field">Nombre<input data-edit="name" type="text"></label>
									<label class="form-field">Apellido<input data-edit="surname" type="text"></label>
								</div>
								<label class="form-field">Email<input data-edit="email" type="email" required></label>
								<label class="form-field" data-current-password-field hidden>Contraseña actual (necesaria para cambiar el correo)<input data-current-password type="password" autocomplete="current-password"></label>
								<label class="form-field">Teléfono<input data-edit="phone" type="tel"></label>
								<button class="primary account-submit" type="button" data-save-profile>Guardar cambios <i data-lucide="check" width="16"></i></button>
								<div class="form-message" data-account-message></div>

								<section class="password-section">
									<h3>Cambiar contraseña</h3>
									<p>Confirma tu contraseña actual para establecer una nueva.</p>
									<form id="passwordChangeForm" method="POST" action="javascript:void(0);">
										<label class="form-field">Contraseña actual<input name="currentPassword" type="password" required autocomplete="current-password"></label>
										<label class="form-field">Nueva contraseña<input name="newPassword" type="password" minlength="6" required autocomplete="new-password"></label>
										<label class="form-field">Repetir nueva contraseña<input name="confirmation" type="password" minlength="6" required autocomplete="new-password"></label>
										<button class="primary" type="submit">Actualizar contraseña</button>
										<div class="form-message" data-account-message></div>
									</form>
								</section>
							</div>

							<!-- SECCIÓN CONFIGURACIONES -->
							<div class="settings-section" data-hub-panel="settings" hidden>
								<h3>Preferencias y apariencia</h3>
								<label class="form-field">Tema / Apariencia
									<select data-theme-select>
										<option value="natural">Natural (Estándar)</option>
										<option value="contrast">Contraste alto</option>
										<option value="night">Modo Noche</option>
									</select>
								</label>
								<label class="setting-row">
									<span>Interfaz compacta<small>Reduce espacios para mostrar más productos en pantalla</small></span>
									<input data-compact-toggle type="checkbox">
								</label>
							</div>

							<button class="logout-button" type="button" data-system-logout><i data-lucide="log-out" width="16"></i> Cerrar sesión</button>
						</div>
					</section>
				</div>
			</div>
		`;
		document.body.append(accountSystem);

		// ESTADO GLOBAL DE LA CUENTA
		const accountState = {
			user: null,
			theme: storeGet('smartisp.theme') || 'natural',
			compact: storeGet('smartisp.compact') === 'true'
		};

		const customerData = { wishlist: [], orders: [], ordersError: false };

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

		const securePostJson = data => {
			try {
				const raw = JSON.stringify(data);
				const b64 = toUtf8Base64(raw);
				if (b64) return JSON.stringify({ payload: b64 });
				return raw;
			} catch {
				return JSON.stringify(data);
			}
		};

		const readApiResponse = async response => {
			const text = await response.text();
			try {
				return text ? JSON.parse(text) : { error: `El servidor respondió vacío (HTTP ${response.status}).` };
			} catch {
				let clean = (text || '')
					.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
					.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
					.replace(/<[^>]*>?/gm, ' ')
					.replace(/\s+/g, ' ')
					.trim();
				if (clean.length > 150) clean = clean.substring(0, 150) + '...';
				return { error: clean || `Respuesta inválida del servidor (HTTP ${response.status}).` };
			}
		};

		const accountAuthView = accountSystem.querySelector('#accountAuthView');
		const accountProfileView = accountSystem.querySelector('#accountProfileView');
		const accountMessage = form => form?.querySelector('[data-account-message]');
		const showAccountMessage = (form, text, error = false) => {
			const message = accountMessage(form);
			if (!message) return;
			message.textContent = text;
			message.classList.toggle('error', error);
			message.classList.add('show');
		};

		const showAccountTab = tab => {
			accountSystem.querySelectorAll('[data-account-tab]').forEach(button => button.classList.toggle('is-active', button.dataset.accountTab === tab));
			accountSystem.querySelector('#accountLoginPane').hidden = tab !== 'login';
			accountSystem.querySelector('#accountRegisterPane').hidden = tab !== 'register';
			accountSystem.querySelector('#accountSystemTitle').textContent = tab === 'login' ? 'Inicia sesión' : 'Crea tu cuenta';
		};

		const switchProfilePanel = panelName => {
			accountSystem.querySelectorAll('[data-account-panel]').forEach(btn => {
				btn.classList.toggle('is-active', btn.dataset.accountPanel === panelName);
			});
			accountSystem.querySelectorAll('[data-hub-panel]').forEach(panel => {
				panel.hidden = panel.dataset.hubPanel !== panelName;
			});
			window.lucide && window.lucide.createIcons();
		};

		accountSystem.querySelectorAll('[data-account-panel]').forEach(button => {
			button.addEventListener('click', () => switchProfilePanel(button.dataset.accountPanel));
		});

		// NOTIFICACIONES TOAST DE WISHLIST
		const wishlistToast = document.createElement('div');
		wishlistToast.className = 'wishlist-toast';
		wishlistToast.setAttribute('role', 'status');
		wishlistToast.setAttribute('aria-live', 'polite');
		document.body.append(wishlistToast);
		let wishlistToastTimer;

		const showWishlistToast = (text, removed = false) => {
			clearTimeout(wishlistToastTimer);
			wishlistToast.textContent = text;
			wishlistToast.classList.toggle('is-removed', removed);
			wishlistToast.classList.remove('is-visible');
			requestAnimationFrame(() => wishlistToast.classList.add('is-visible'));
			wishlistToastTimer = setTimeout(() => wishlistToast.classList.remove('is-visible'), 2800);
		};

		const customerApi = async (action, options = {}) => {
			const response = await fetch(`/api/auth/${action}`, {
				credentials: 'include',
				headers: { 'Content-Type': 'application/json' },
				...options
			});
			const result = await readApiResponse(response);
			if (!response.ok) throw new Error(result.error || 'No se pudo completar la operación.');
			return result;
		};

		const setHeartState = (heart, saved) => {
			if (!heart) return;
			heart.classList.toggle('is-saved', saved);
			heart.setAttribute('aria-pressed', String(saved));
		};
		const syncWishlistButtons = () => {
			document.querySelectorAll('.product').forEach(card => {
				const prod = products[card.dataset.index];
				if (prod) setHeartState(card.querySelector('.heart'), wishlistIds.has(productKey(prod)));
			});
		};

		const renderWishlist = () => {
			const list = accountProfileView.querySelector('[data-wishlist-list]');
			const countEl = accountProfileView.querySelector('[data-wishlist-count]');
			if (countEl) countEl.textContent = `${customerData.wishlist.length} producto${customerData.wishlist.length === 1 ? '' : 's'}`;
			if (!list) return;
			list.innerHTML = customerData.wishlist.length ? customerData.wishlist.map((item, index) => `
				<div class="hub-item" data-wishlist-item="${index}">
					<img src="${escapeHtml(getProductImageUrl(item))}" alt="" data-fallback="${escapeHtml(fallbackImage(item))}" onerror="this.onerror=null;this.src=this.dataset.fallback">
					<div style="flex:1;min-width:0;">
						<strong style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(item.name)}</strong>
						<small>${money(Number(item.price || 0))} · ${escapeHtml(item.category || 'SmartISP')}</small>
					</div>
					<div class="wishlist-tools">
						<button type="button" data-wishlist-action="up" aria-label="Subir" ${index === 0 ? 'disabled' : ''} title="Subir prioridad">↑</button>
						<button type="button" data-wishlist-action="down" aria-label="Bajar" ${index === customerData.wishlist.length - 1 ? 'disabled' : ''} title="Bajar prioridad">↓</button>
						<button type="button" data-wishlist-action="remove" aria-label="Quitar" title="Eliminar de wishlist">×</button>
					</div>
				</div>
			`).join('') : '<div class="hub-empty">Todavía no tienes productos guardados en tu wishlist. Haz clic en el corazón de cualquier producto para guardarlo aquí.</div>';
			window.lucide && window.lucide.createIcons();
		};

		const ORDER_STATUS_LABELS = { quote_requested: 'Cotización solicitada', pending: 'Pendiente', received: 'Recibido', paid: 'Pagado', preparing: 'En preparación', processing: 'En proceso', shipped: 'Enviado', delivered: 'Entregado', completed: 'Completado', cancelled: 'Cancelado', canceled: 'Cancelado', refunded: 'Reembolsado' };
		const formatOrderDate = order => {
			const raw = String(order.created_at || order.createdAt || '').trim();
			if (!raw) return '';
			const date = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(raw) ? `${raw.replace(' ', 'T')}Z` : raw);
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('es-EC', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'America/Guayaquil' });
		};
		const renderOrders = () => {
			const list = accountProfileView.querySelector('[data-orders-list]');
			const countEl = accountProfileView.querySelector('[data-orders-count]');
			if (countEl) countEl.textContent = `${customerData.orders.length} pedido${customerData.orders.length === 1 ? '' : 's'}`;
			if (!list) return;
			list.innerHTML = customerData.ordersError ? '<div class="hub-empty">No se pudo cargar tu historial de pedidos. Intenta de nuevo en un momento.</div>' : customerData.orders.length ? customerData.orders.map(order => `
				<div class="hub-item order-item">
					<div class="order-head">
						<strong>Pedido ${escapeHtml(String(order.id || ''))}</strong>
						<small>${formatOrderDate(order)}</small>
					</div>
					<small>${order.items?.items?.length || order.items?.length || 0} producto(s) · <b style="color:var(--navy);">${money(Number(order.total || 0))}</b> · <span style="color:var(--blue);">${escapeHtml(ORDER_STATUS_LABELS[String(order.status || '').toLowerCase()] || order.status || 'Pendiente')}</span></small>
				</div>
			`).join('') : '<div class="hub-empty">Aún no tienes pedidos registrados. Tus compras aparecerán aquí para seguimiento.</div>';
					window.lucide && window.lucide.createIcons();
		};

		const loadCustomerData = async () => {
			// allSettled (no Promise.all): si falla el pedido de ordenes, la wishlist igual se
			// carga y viceversa -- antes un solo fallo vaciaba ambas listas en silencio, sin
			// distinguir "nunca has comprado nada" de "fallo la consulta".
			const [wishlistResult, ordersResult] = await Promise.allSettled([
				customerApi('customer-wishlist'),
				customerApi('customer-orders')
			]);
			if (wishlistResult.status === 'fulfilled') {
				customerData.wishlist = wishlistResult.value.wishlist || [];
				wishlistIds = new Set(customerData.wishlist.map(item => String(item.productId)));
			} else {
				customerData.wishlist = [];
			}
			if (ordersResult.status === 'fulfilled') {
				customerData.orders = ordersResult.value.orders || [];
				customerData.ordersError = false;
			} else {
				customerData.orders = [];
				customerData.ordersError = true;
			}
			renderWishlist();
			renderOrders();
			syncWishlistButtons();
		};

		// Enlazar toggleWishlist con API backend
		toggleWishlist = async (product, heart = null) => {
			if (!signedIn) return openAccountSystem();
			const id = productKey(product);
			const saved = wishlistIds.has(id);
			setHeartState(heart, !saved);
			try {
				await customerApi(`customer-wishlist${saved ? `?productId=${encodeURIComponent(id)}` : ''}`, {
					method: saved ? 'DELETE' : 'POST',
					body: saved ? undefined : JSON.stringify({ productId: id, name: product.name, price: product.price, image: product.image, category: product.category })
				});
				if (saved) {
					wishlistIds.delete(id);
					customerData.wishlist = customerData.wishlist.filter(item => String(item.productId) !== id);
				} else {
					wishlistIds.add(id);
					customerData.wishlist.unshift({ productId: id, name: product.name, price: product.price, image: product.image, category: product.category });
				}
				renderWishlist();
				syncWishlistButtons();
				showWishlistToast(saved ? 'Lo eliminaste de tu wishlist' : 'Se agregó a tu wishlist', saved);
			} catch (error) {
				setHeartState(heart, saved);
			}
		};

		const saveWishlistOrder = async () => {
			await customerApi('customer-wishlist', {
				method: 'POST',
				body: JSON.stringify({ order: customerData.wishlist.map(item => item.productId) })
			});
		};

		accountProfileView.addEventListener('click', async event => {
			const button = event.target.closest('[data-wishlist-action]');
			if (!button) return;
			event.preventDefault();
			event.stopPropagation();
			const row = button.closest('[data-wishlist-item]');
			const index = Number(row?.dataset?.wishlistItem);
			if (isNaN(index)) return;
			const action = button.dataset.wishlistAction;
			try {
				if (action === 'remove') {
					await customerApi(`customer-wishlist?productId=${encodeURIComponent(customerData.wishlist[index].productId)}`, { method: 'DELETE' });
					wishlistIds.delete(String(customerData.wishlist[index].productId));
					customerData.wishlist.splice(index, 1);
				} else {
					const nextIndex = action === 'up' ? index - 1 : index + 1;
					if (nextIndex < 0 || nextIndex >= customerData.wishlist.length) return;
					[customerData.wishlist[index], customerData.wishlist[nextIndex]] = [customerData.wishlist[nextIndex], customerData.wishlist[index]];
					await saveWishlistOrder();
				}
				renderWishlist();
				syncWishlistButtons();
				if (action === 'remove') showWishlistToast('Lo eliminaste de tu wishlist', true);
			} catch (error) {
				console.warn('Error en wishlist action:', error);
			}
		});

		const completeCheckout = () => {
			if (!cart.length) {
				alert('Tu carrito está vacío. Agrega productos antes de finalizar la compra.');
				return;
			}
			window.location.href = '/checkout.html';
		};

		const applyAccountPreferences = () => {
			document.body.dataset.accountTheme = accountState.theme;
			document.body.classList.toggle('account-compact', accountState.compact);
			storeSet('smartisp.theme', accountState.theme);
			storeSet('smartisp.compact', accountState.compact);
		};

		const updateAdminAccess = user => {
			const isAdmin = Boolean(user && String(user.role || '').trim().toLowerCase() === 'admin');
			const adminNav = document.querySelector('#adminNavLink');
			if (adminNav) {
				adminNav.classList.toggle('is-visible', isAdmin);
				adminNav.style.setProperty('display', isAdmin ? 'inline-flex' : 'none', 'important');
			}
			const adminProfileBtn = accountSystem.querySelector('[data-admin-profile-btn]');
			if (adminProfileBtn) {
				adminProfileBtn.classList.toggle('is-visible', isAdmin);
				adminProfileBtn.style.setProperty('display', isAdmin ? 'flex' : 'none', 'important');
			}
			const adminBadge = accountSystem.querySelector('[data-admin-badge]');
			if (adminBadge) {
				adminBadge.style.setProperty('display', isAdmin ? 'inline-flex' : 'none', 'important');
			}
			window.lucide && window.lucide.createIcons();
		};

		const renderAccountProfile = () => {
			const user = accountState.user;
			if (!user) return;
			const nameEl = accountSystem.querySelector('[data-profile-name]');
			if (nameEl) nameEl.textContent = `${user.name || ''} ${user.surname || ''}`.trim() || 'Usuario';
			const emailEl = accountSystem.querySelector('[data-profile-email]');
			if (emailEl) emailEl.textContent = `${user.email} ${user.phone ? '· ' + user.phone : ''}`;
			accountSystem.querySelectorAll('[data-edit]').forEach(input => {
				input.value = user[input.dataset.edit] || '';
			});
			const passwordField = accountSystem.querySelector('[data-current-password-field]');
			if (passwordField) {
				passwordField.hidden = true;
				passwordField.querySelector('input').value = '';
				passwordField.querySelector('input').required = false;
			}
			const themeSel = accountSystem.querySelector('[data-theme-select]');
			if (themeSel) themeSel.value = accountState.theme;
			const compactTog = accountSystem.querySelector('[data-compact-toggle]');
			if (compactTog) compactTog.checked = accountState.compact;
			window.lucide && window.lucide.createIcons();
		};

		const openAccountSystem = () => {
			accountSystem.classList.add('is-open');
			if (accountState.user) {
				switchProfilePanel('wishlist');
			} else {
				showAccountTab('login');
				const loginInput = accountSystem.querySelector('#accountLoginPane input[name="email"]');
				if (loginInput) loginInput.focus();
			}
			window.lucide && window.lucide.createIcons();
		};

		const closeAccountSystem = () => {
			accountSystem.classList.remove('is-open');
			if (new URLSearchParams(window.location.search).has('embedAccount')) {
				window.parent.postMessage({ type: 'smartisp:close-account' }, window.location.origin);
			}
		};

		const setAccountUser = (user, remember = storeGet('smartisp.accountRemembered') === 'true') => {
			accountState.user = user;
			const displayUser = { id: user?.id, name: user?.name, email: user?.email, role: user?.role };
			storeSet('smartisp.accountUser', JSON.stringify(displayUser), !remember);
			if (remember) storeSet('smartisp.accountRemembered', 'true');
			else {
				storeRemove('smartisp.accountUser');
				storeRemove('smartisp.accountRemembered');
			}
			signedIn = true;
			const label = document.querySelector('#accountLabel');
			if (label) label.textContent = user.name || 'Mi cuenta';
			const mLabel = document.querySelector('#mAccountLabel');
			if (mLabel) mLabel.textContent = user.name ? user.name.split(' ')[0] : 'Cuenta';
			updateAdminAccess(user);
			accountAuthView.hidden = true;
			accountProfileView.hidden = false;
			renderAccountProfile();
			loadCustomerData();
			switchProfilePanel('wishlist');
		};

		const resetAccountUser = () => {
			accountState.user = null;
			storeRemove('smartisp.accountUser');
			storeRemove('smartisp.accountRemembered');
			storeRemove('smartisp.accountUser', true);
			signedIn = false;
			updateAdminAccess(null);
			const label = document.querySelector('#accountLabel');
			if (label) label.textContent = 'Mi cuenta';
			const mLabel = document.querySelector('#mAccountLabel');
			if (mLabel) mLabel.textContent = 'Cuenta';
			accountAuthView.hidden = false;
			accountProfileView.hidden = true;
			accountSystem.querySelectorAll('form').forEach(form => form.reset());
			accountSystem.querySelectorAll('[data-account-message]').forEach(message => message.classList.remove('show'));
			customerData.wishlist = [];
			customerData.orders = [];
			wishlistIds.clear();
			syncWishlistButtons();
			showAccountTab('login');
		};

		const loginRedirectTarget = () => {
			const target = new URLSearchParams(window.location.search).get('redirect') || '';
			return /^\/(?![\/\\])/.test(target) ? target : '';
		};

		// Botón Mi cuenta en barra superior
		const accountTrigger = document.querySelector('#openAccount');
		if (accountTrigger) {
			const cleanAccountTrigger = accountTrigger.cloneNode(true);
			accountTrigger.replaceWith(cleanAccountTrigger);
			cleanAccountTrigger.addEventListener('click', event => {
				event.preventDefault();
				openAccountSystem();
			});
		}

		accountSystem.querySelector('.account-system-close').addEventListener('click', closeAccountSystem);
		accountSystem.addEventListener('click', event => {
			if (event.target === accountSystem) closeAccountSystem();
		});

		accountSystem.querySelectorAll('[data-account-tab]').forEach(button => {
			button.addEventListener('click', () => showAccountTab(button.dataset.accountTab));
		});

		accountSystem.querySelector('#systemLoginForm').addEventListener('submit', async event => {
			event.preventDefault();
			const form = event.currentTarget;
			const values = Object.fromEntries(new FormData(form));
			const button = form.querySelector('button[type="submit"]');
			button.classList.add('is-loading');
			try {
				const response = await fetch('/api/router.php?action=login', {
					method: 'POST',
					credentials: 'include',
					headers: { 'Content-Type': 'application/json' },
					body: securePostJson(values)
				});
				const result = await readApiResponse(response);
				if (!response.ok) throw new Error(result.error);
				setAccountUser(result.user, true);
				if (result.token) {
					storeSet('smartisp.adminToken', result.token);
				}
				if (loginRedirectTarget()) window.location.href = loginRedirectTarget();
			} catch (error) {
				showAccountMessage(form, error.message || 'No se pudo iniciar sesión.', true);
			} finally {
				button.classList.remove('is-loading');
			}
		});

		accountSystem.querySelector('#systemRegisterForm').addEventListener('submit', async event => {
			event.preventDefault();
			const form = event.currentTarget;
			const values = Object.fromEntries(new FormData(form));
			if (values.password !== values.confirmation) {
				showAccountMessage(form, 'Las contraseñas no coinciden.', true);
				return;
			}
			const button = form.querySelector('button[type="submit"]');
			button.classList.add('is-loading');
			try {
				const response = await fetch('/api/router.php?action=register', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: securePostJson(values)
				});
				const result = await readApiResponse(response);
				if (!response.ok) throw new Error(result.error);
				setAccountUser(result.user);
				if (loginRedirectTarget()) window.location.href = loginRedirectTarget();
			} catch (error) {
				showAccountMessage(form, error.message || 'No se pudo crear la cuenta.', true);
			} finally {
				button.classList.remove('is-loading');
			}
		});

		accountSystem.querySelector('[data-system-logout]').addEventListener('click', async event => {
			const button = event.currentTarget;
			button.disabled = true;
			try {
				await fetch('/api/router.php?action=logout', { method: 'POST' });
			} finally {
				resetAccountUser();
				button.disabled = false;
				closeAccountSystem();
			}
		});

		const profileEmailInput = accountSystem.querySelector('[data-edit="email"]');
		const currentPasswordField = accountSystem.querySelector('[data-current-password-field]');
		const currentPasswordInput = accountSystem.querySelector('[data-current-password]');
		const profileEmailChanged = () => Boolean(accountState.user) && profileEmailInput.value.trim().toLowerCase() !== String(accountState.user.email || '').trim().toLowerCase();
		const syncCurrentPasswordField = () => {
			const changed = profileEmailChanged();
			currentPasswordField.hidden = !changed;
			currentPasswordInput.required = changed;
			if (!changed) currentPasswordInput.value = '';
		};
		profileEmailInput.addEventListener('input', syncCurrentPasswordField);

		accountSystem.querySelector('[data-save-profile]').addEventListener('click', async () => {
			if (!accountState.user) return;
			const panel = accountSystem.querySelector('[data-hub-panel="profile"]');
			const values = {};
			accountSystem.querySelectorAll('[data-edit]').forEach(input => {
				values[input.dataset.edit] = input.value.trim();
			});
			const emailChanged = profileEmailChanged();
			if (emailChanged && !currentPasswordInput.value) {
				showAccountMessage(panel, 'Ingresa tu contraseña actual para cambiar el correo.', true);
				currentPasswordInput.focus();
				return;
			}
			const payload = { name: values.name, surname: values.surname, phone: values.phone, email: values.email };
			if (emailChanged) payload.currentPassword = currentPasswordInput.value;
			try {
				const response = await fetch('/api/router.php?action=update-profile', {
					method: 'POST',
					credentials: 'include',
					headers: { 'Content-Type': 'application/json' },
					body: securePostJson(payload)
				});
				const result = await readApiResponse(response);
				if (!response.ok) throw new Error(result.error);
				setAccountUser(result.user || { ...accountState.user, ...values });
				switchProfilePanel('profile');
				showAccountMessage(panel, 'Datos guardados correctamente.');
			} catch (err) {
				showAccountMessage(panel, err.message || 'Error al guardar datos.', true);
			}
		});

		accountSystem.querySelector('#passwordChangeForm').addEventListener('submit', async event => {
			event.preventDefault();
			const form = event.currentTarget;
			const values = Object.fromEntries(new FormData(form));
			if (values.newPassword !== values.confirmation) {
				showAccountMessage(form, 'La confirmación no coincide con la nueva contraseña.', true);
				return;
			}
			if ((values.newPassword || '').length < 6) {
				showAccountMessage(form, 'La nueva contraseña debe tener al menos 6 caracteres.', true);
				return;
			}
			const button = form.querySelector('button[type="submit"]');
			button.disabled = true;
			try {
				const reqHeaders = { 'Content-Type': 'application/json' };
				const token = storeGet('smartisp.adminToken') || storeGet('smartisp.adminToken', true);
				if (token) {
					reqHeaders['Authorization'] = `Bearer ${token}`;
					reqHeaders['X-Admin-Token'] = token;
				}
				if (accountState.user && accountState.user.email) {
					values.email = accountState.user.email;
				}
				const response = await fetch('/api/router.php?action=change-password', {
					method: 'POST',
					credentials: 'include',
					headers: reqHeaders,
					body: securePostJson(values)
				});
				const result = await readApiResponse(response);
				if (!response.ok) throw new Error(result.error);
				if (result.token && storeGet('smartisp.adminToken')) storeSet('smartisp.adminToken', result.token);
				form.reset();
				showAccountMessage(form, '¡Contraseña actualizada correctamente!');
			} catch (error) {
				showAccountMessage(form, error.message || 'No se pudo cambiar la contraseña.', true);
			} finally {
				button.disabled = false;
			}
		});

		const checkoutBtn = document.querySelector('.checkout');
		if (checkoutBtn) checkoutBtn.addEventListener('click', completeCheckout);

		accountSystem.querySelector('[data-theme-select]').addEventListener('change', event => {
			accountState.theme = event.target.value;
			applyAccountPreferences();
		});

		accountSystem.querySelector('[data-compact-toggle]').addEventListener('change', event => {
			accountState.compact = event.target.checked;
			applyAccountPreferences();
		});

		applyAccountPreferences();
		updateAdminAccess(null);
		window.lucide && window.lucide.createIcons();

		// Restaurar sesión de localStorage / sessionStorage inmediatamente al cargar la página
		try {
			const savedUserRaw = storeGet('smartisp.accountUser') || storeGet('smartisp.accountUser', true);
			if (savedUserRaw) {
				const savedUser = JSON.parse(savedUserRaw);
				if (savedUser && (savedUser.name || savedUser.email)) {
					setAccountUser(savedUser, storeGet('smartisp.accountRemembered') === 'true');
				}
			}
		} catch (e) {
			console.warn('Error restaurando usuario guardado:', e);
		}

		// Validar y sincronizar sesión activa con backend
		fetch('/api/auth/session', { credentials: 'include' })
			.then(async response => {
				if (!response.ok) throw new Error('No hay sesión activa.');
				return response.json();
			})
			.then(result => {
				if (result && result.user) {
					setAccountUser(result.user, storeGet('smartisp.accountRemembered') === 'true');
				} else if (result && 'user' in result) {
					resetAccountUser();
				}
			})
			.catch(() => {});
