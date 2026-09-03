// =======================================
// DATOS DE PRODUCTOS
// =======================================

const products = [
    // Laptops & Computadoras
    {
        id: 1,
        name: "Dell XPS 13 Plus",
        category: "laptops",
        brand: "dell",
        price: 1299,
        originalPrice: 1499,
        description: "Ultrabook potente con procesador de última generación",
        specs: "Intel i7, 16GB RAM, 512GB SSD",
        emoji: "💻",
        rating: 4.8
    },
    {
        id: 2,
        name: "HP Pavilion 15",
        category: "laptops",
        brand: "hp",
        price: 699,
        originalPrice: 899,
        description: "Laptop versátil para trabajo y entretenimiento",
        specs: "Intel i5, 8GB RAM, 256GB SSD",
        emoji: "💻",
        rating: 4.5
    },
    {
        id: 3,
        name: "Lenovo ThinkPad X1",
        category: "laptops",
        brand: "lenovo",
        price: 1199,
        originalPrice: 1399,
        description: "Laptop empresarial premium con excelente teclado",
        specs: "Intel i7, 16GB RAM, 512GB SSD",
        emoji: "💼",
        rating: 4.7
    },
    {
        id: 4,
        name: "MacBook Pro 14",
        category: "laptops",
        brand: "apple",
        price: 1999,
        originalPrice: 1999,
        description: "Laptop de alto rendimiento para profesionales",
        specs: "M3 Pro, 18GB RAM, 512GB SSD",
        emoji: "🍎",
        rating: 4.9
    },

    // Componentes
    {
        id: 5,
        name: "NVIDIA GeForce RTX 4070",
        category: "componentes",
        brand: "nvidia",
        price: 599,
        originalPrice: 699,
        description: "Tarjeta gráfica de alto rendimiento para gaming y IA",
        specs: "12GB GDDR6, Ray Tracing, DLSS",
        emoji: "🎮",
        rating: 4.8
    },
    {
        id: 6,
        name: "Intel Core i9-13900K",
        category: "componentes",
        brand: "intel",
        price: 589,
        originalPrice: 689,
        description: "Procesador de máximo rendimiento para estaciones de trabajo",
        specs: "24 núcleos, 5.8 GHz, 36MB caché",
        emoji: "⚡",
        rating: 4.7
    },
    {
        id: 7,
        name: "Kingston Fury Beast RGB DDR5",
        category: "componentes",
        brand: "kingston",
        price: 189,
        originalPrice: 229,
        description: "Memoria RAM DDR5 de alto rendimiento con RGB",
        specs: "32GB, 5200MHz, Latencia 38ns",
        emoji: "💾",
        rating: 4.6
    },
    {
        id: 8,
        name: "Samsung 990 Pro NVMe",
        category: "componentes",
        brand: "samsung",
        price: 149,
        originalPrice: 179,
        description: "SSD NVMe ultrarrápido PCIe 4.0",
        specs: "1TB, 7100MB/s, Disipador",
        emoji: "📀",
        rating: 4.8
    },

    // Networking
    {
        id: 9,
        name: "Cisco Catalyst 9300",
        category: "networking",
        brand: "cisco",
        price: 4999,
        originalPrice: 5499,
        description: "Switch empresarial de alto rendimiento para centros de datos",
        specs: "48 puertos Gigabit, L3, MPLS",
        emoji: "🌐",
        rating: 4.7
    },
    {
        id: 10,
        name: "Ubiquiti UniFi WiFi 6",
        category: "networking",
        brand: "ubiquiti",
        price: 299,
        originalPrice: 349,
        description: "Punto de acceso WiFi 6 profesional",
        specs: "WiFi 6, Mesh, 2x2 MIMO",
        emoji: "📡",
        rating: 4.6
    },
    {
        id: 11,
        name: "Netgear Nighthawk Router",
        category: "networking",
        brand: "netgear",
        price: 199,
        originalPrice: 249,
        description: "Router WiFi 6E de alto rendimiento para el hogar",
        specs: "WiFi 6E, 12 antenas, 10G LAN",
        emoji: "📶",
        rating: 4.5
    },
    {
        id: 12,
        name: "Fortinet FortiGate 200D",
        category: "networking",
        brand: "fortinet",
        price: 2499,
        originalPrice: 2999,
        description: "Firewall empresarial de seguridad avanzada",
        specs: "Throughput 40Gbps, IPS, Antivirus",
        emoji: "🔒",
        rating: 4.8
    },

    // Almacenamiento
    {
        id: 13,
        name: "WD Red Pro 12TB",
        category: "almacenamiento",
        brand: "wd",
        price: 349,
        originalPrice: 399,
        description: "Disco duro NAS de 12TB para almacenamiento continuo",
        specs: "12TB, 7200RPM, 256MB caché",
        emoji: "💿",
        rating: 4.7
    },
    {
        id: 14,
        name: "Seagate SkyHawk Pro 16TB",
        category: "almacenamiento",
        brand: "seagate",
        price: 459,
        originalPrice: 529,
        description: "Disco duro para sistemas de vigilancia y grabación",
        specs: "16TB, 7200RPM, 256MB caché",
        emoji: "📹",
        rating: 4.6
    },
    {
        id: 15,
        name: "NetApp AFF A220 SSD",
        category: "almacenamiento",
        brand: "netapp",
        price: 12999,
        originalPrice: 14999,
        description: "Array de almacenamiento all-flash empresarial",
        specs: "Dual Controller, 48TB SSD, HA",
        emoji: "🗄️",
        rating: 4.9
    },
    {
        id: 16,
        name: "Synology DiskStation DS920+",
        category: "almacenamiento",
        brand: "synology",
        price: 549,
        originalPrice: 649,
        description: "NAS 4-bahías para el hogar y pequeñas empresas",
        specs: "Quad Core, 4GB RAM, Hot Swap",
        emoji: "🏠",
        rating: 4.8
    },

    // Servidores
    {
        id: 17,
        name: "Dell PowerEdge R750",
        category: "servidores",
        brand: "dell",
        price: 5999,
        originalPrice: 6999,
        description: "Servidor rack de propósito general de 2U",
        specs: "Dual Xeon, 32 slots RAM, PERC12",
        emoji: "🖥️",
        rating: 4.8
    },
    {
        id: 18,
        name: "HP ProLiant DL380 Gen11",
        category: "servidores",
        brand: "hp",
        price: 7499,
        originalPrice: 8499,
        description: "Servidor de alto rendimiento para cargas de trabajo intensivas",
        specs: "Dual Xeon, 48 slots DDR5, SmartArray",
        emoji: "⚙️",
        rating: 4.7
    },
    {
        id: 19,
        name: "Lenovo ThinkSystem SR665",
        category: "servidores",
        brand: "lenovo",
        price: 4999,
        originalPrice: 5999,
        description: "Servidor EPYC de 2 sockets con procesadores AMD",
        specs: "Dual EPYC 7003, 32 slots RAM, Neptuno",
        emoji: "🚀",
        rating: 4.6
    },
    {
        id: 20,
        name: "Supermicro SuperServer 6015B-TRB",
        category: "servidores",
        brand: "supermicro",
        price: 3999,
        originalPrice: 4799,
        description: "Servidor tower compacto y versátil",
        specs: "Dual Xeon, 24 slots RAM, 12Gb SAS",
        emoji: "📦",
        rating: 4.5
    },

    // Periféricos
    {
        id: 21,
        name: "Logitech MX Master 3S",
        category: "perifericos",
        brand: "logitech",
        price: 99,
        originalPrice: 129,
        description: "Ratón inalámbrico avanzado para profesionales",
        specs: "Multi-dispositivo, Precision, Batería 70 días",
        emoji: "🖱️",
        rating: 4.9
    },
    {
        id: 22,
        name: "Corsair K95 Platinum RGB",
        category: "perifericos",
        brand: "corsair",
        price: 199,
        originalPrice: 249,
        description: "Teclado mecánico premium con macros programables",
        specs: "Cherry MX, RGB per-key, Aluminio",
        emoji: "⌨️",
        rating: 4.8
    },
    {
        id: 23,
        name: "Dell UltraSharp U2723DE",
        category: "perifericos",
        brand: "dell",
        price: 399,
        originalPrice: 499,
        description: "Monitor profesional 4K USB-C para creativos",
        specs: "27\", 4K (3840x2160), USB-C 65W",
        emoji: "🖥️",
        rating: 4.7
    },
    {
        id: 24,
        name: "BenQ PD2700U",
        category: "perifericos",
        brand: "benq",
        price: 449,
        originalPrice: 549,
        description: "Monitor profesional para diseño gráfico y vídeo",
        specs: "27\", 4K, 99.5% Adobe RGB, Hardware Calibration",
        emoji: "🎨",
        rating: 4.8
    }
];

// =======================================
// ESTADO GLOBAL
// =======================================

let cart = [];
let filteredProducts = [...products];

// =======================================
// ELEMENTOS DEL DOM
// =======================================

const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const sortSelect = document.getElementById('sortSelect');
const priceRange = document.getElementById('priceRange');
const priceValue = document.getElementById('priceValue');
const cartBtn = document.getElementById('cartBtn');
const cartCount = document.getElementById('cartCount');
const productsList = document.getElementById('productsList');
const productsView = document.getElementById('productsView');
const cartView = document.getElementById('cartView');
const cartItems = document.getElementById('cartItems');
const clearFiltersBtn = document.getElementById('clearFilters');
const continueShopping = document.getElementById('continueShopping');
const categoryFilters = document.querySelectorAll('.category-filter');
const brandFilters = document.querySelectorAll('.brand-filter');
const viewTitle = document.getElementById('viewTitle');

// =======================================
// FUNCIONES PRINCIPALES
// =======================================

// Inicializar
function init() {
    renderProducts(products);
    setupEventListeners();
}

// Configurar event listeners
function setupEventListeners() {
    searchBtn.addEventListener('click', handleSearch);
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleSearch();
    });
    
    sortSelect.addEventListener('change', handleSort);
    cartBtn.addEventListener('click', toggleCartView);
    clearFiltersBtn.addEventListener('click', clearAllFilters);
    continueShopping.addEventListener('click', toggleCartView);
    
    priceRange.addEventListener('input', () => {
        priceValue.textContent = priceRange.value;
        applyFilters();
    });

    categoryFilters.forEach(filter => {
        filter.addEventListener('change', handleCategoryFilter);
    });

    brandFilters.forEach(filter => {
        filter.addEventListener('change', handleBrandFilter);
    });
}

// Renderizar productos
function renderProducts(productsToRender) {
    productsList.innerHTML = '';
    
    if (productsToRender.length === 0) {
        productsList.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 2rem; color: #999;">No se encontraron productos</div>';
        return;
    }

    productsToRender.forEach(product => {
        const productCard = document.createElement('div');
        productCard.className = 'product-card';
        
        const ratingStars = '⭐'.repeat(Math.floor(product.rating));
        
        productCard.innerHTML = `
            <div class="product-image">${product.emoji}</div>
            <div class="product-content">
                <div class="product-category">${product.category}</div>
                <h3 class="product-name">${product.name}</h3>
                <p class="product-description">${product.description}</p>
                <div class="product-specs">${product.specs}</div>
            </div>
            <div class="product-footer">
                <div>
                    <div class="product-price">$${product.price.toLocaleString()}</div>
                    ${product.originalPrice > product.price ? `<div class="product-price-original">$${product.originalPrice}</div>` : ''}
                </div>
                <button class="add-cart-btn" data-id="${product.id}">Añadir</button>
            </div>
            <div class="product-rating">
                ${ratingStars}
                <span class="rating-number">(${product.rating})</span>
            </div>
        `;

        productCard.querySelector('.add-cart-btn').addEventListener('click', (e) => {
            addToCart(product);
            e.target.textContent = '✓ Añadido';
            setTimeout(() => {
                e.target.textContent = 'Añadir';
            }, 1500);
        });

        productsList.appendChild(productCard);
    });
}

// Búsqueda
function handleSearch() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    
    if (searchTerm === '') {
        applyFilters();
        return;
    }

    filteredProducts = products.filter(product => 
        product.name.toLowerCase().includes(searchTerm) ||
        product.description.toLowerCase().includes(searchTerm) ||
        product.specs.toLowerCase().includes(searchTerm) ||
        product.brand.toLowerCase().includes(searchTerm)
    );

    viewTitle.textContent = `Resultados para: "${searchTerm}"`;
    sortAndRender();
}

// Aplicar filtros
function applyFilters() {
    const selectedCategories = Array.from(categoryFilters)
        .filter(f => f.checked)
        .map(f => f.value);
    
    const selectedBrands = Array.from(brandFilters)
        .filter(f => f.checked)
        .map(f => f.value);
    
    const maxPrice = parseInt(priceRange.value);

    filteredProducts = products.filter(product => {
        const categoryMatch = selectedCategories.includes('todas') || selectedCategories.includes(product.category);
        const brandMatch = selectedBrands.includes('todas-marcas') || selectedBrands.includes(product.brand);
        const priceMatch = product.price <= maxPrice;

        return categoryMatch && brandMatch && priceMatch;
    });

    viewTitle.textContent = 'Todos los Productos';
    sortAndRender();
}

// Manejar filtro de categoría
function handleCategoryFilter() {
    const allChecked = Array.from(categoryFilters).every(f => !f.checked);
    if (allChecked) {
        document.querySelector('.category-filter[value="todas"]').checked = true;
    }
    
    const todasCheckbox = document.querySelector('.category-filter[value="todas"]');
    if (todasCheckbox.checked) {
        categoryFilters.forEach(f => {
            if (f.value !== 'todas') f.checked = false;
        });
    } else {
        const anyChecked = Array.from(categoryFilters).some(f => f.checked && f.value !== 'todas');
        if (!anyChecked) {
            todasCheckbox.checked = true;
        }
    }

    applyFilters();
}

// Manejar filtro de marca
function handleBrandFilter() {
    const allChecked = Array.from(brandFilters).every(f => !f.checked);
    if (allChecked) {
        document.querySelector('.brand-filter[value="todas-marcas"]').checked = true;
    }
    
    const todasCheckbox = document.querySelector('.brand-filter[value="todas-marcas"]');
    if (todasCheckbox.checked) {
        brandFilters.forEach(f => {
            if (f.value !== 'todas-marcas') f.checked = false;
        });
    } else {
        const anyChecked = Array.from(brandFilters).some(f => f.checked && f.value !== 'todas-marcas');
        if (!anyChecked) {
            todasCheckbox.checked = true;
        }
    }

    applyFilters();
}

// Limpiar todos los filtros
function clearAllFilters() {
    searchInput.value = '';
    priceRange.value = 50000;
    priceValue.textContent = '50000';
    categoryFilters.forEach(f => f.checked = f.value === 'todas');
    brandFilters.forEach(f => f.checked = f.value === 'todas-marcas');
    viewTitle.textContent = 'Todos los Productos';
    filteredProducts = [...products];
    sortAndRender();
}

// Ordenamiento
function handleSort() {
    sortAndRender();
}

function sortAndRender() {
    const sortValue = sortSelect.value;
    let sorted = [...filteredProducts];

    switch(sortValue) {
        case 'precio-asc':
            sorted.sort((a, b) => a.price - b.price);
            break;
        case 'precio-desc':
            sorted.sort((a, b) => b.price - a.price);
            break;
        case 'nombre-asc':
            sorted.sort((a, b) => a.name.localeCompare(b.name));
            break;
        case 'nombre-desc':
            sorted.sort((a, b) => b.name.localeCompare(a.name));
            break;
        default:
            // relevancia (sin cambios)
            break;
    }

    renderProducts(sorted);
}

// =======================================
// CARRITO DE COMPRAS
// =======================================

function addToCart(product) {
    const existingItem = cart.find(item => item.id === product.id);
    
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.push({
            ...product,
            quantity: 1
        });
    }

    updateCartCount();
}

function removeFromCart(productId) {
    cart = cart.filter(item => item.id !== productId);
    updateCartCount();
    renderCartView();
}

function updateQuantity(productId, newQuantity) {
    const item = cart.find(item => item.id === productId);
    if (item) {
        item.quantity = Math.max(1, newQuantity);
    }
    updateCartCount();
    renderCartView();
}

function updateCartCount() {
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    cartCount.textContent = count;
}

function toggleCartView() {
    productsView.classList.toggle('hidden');
    cartView.classList.toggle('hidden');
    
    if (!cartView.classList.contains('hidden')) {
        renderCartView();
    }
}

function renderCartView() {
    cartItems.innerHTML = '';

    if (cart.length === 0) {
        cartItems.innerHTML = '<div class="empty-cart"><p>Tu carrito está vacío</p><p>¡Continúa comprando!</p></div>';
        document.querySelector('.cart-summary').style.display = 'none';
        return;
    }

    document.querySelector('.cart-summary').style.display = 'flex';

    cart.forEach(item => {
        const cartItem = document.createElement('div');
        cartItem.className = 'cart-item';
        
        cartItem.innerHTML = `
            <div class="cart-item-image">${item.emoji}</div>
            <div class="cart-item-details">
                <h3 class="cart-item-name">${item.name}</h3>
                <div class="cart-item-price">$${item.price.toLocaleString()}</div>
            </div>
            <div class="cart-item-quantity">
                <button class="qty-btn" data-id="${item.id}" data-action="minus">−</button>
                <input type="number" class="qty-input" value="${item.quantity}" min="1" data-id="${item.id}">
                <button class="qty-btn" data-id="${item.id}" data-action="plus">+</button>
            </div>
            <button class="remove-btn" data-id="${item.id}">Eliminar</button>
        `;

        // Event listeners para cantidad
        cartItem.querySelector('.qty-btn[data-action="minus"]').addEventListener('click', () => {
            updateQuantity(item.id, item.quantity - 1);
        });

        cartItem.querySelector('.qty-btn[data-action="plus"]').addEventListener('click', () => {
            updateQuantity(item.id, item.quantity + 1);
        });

        cartItem.querySelector('.qty-input').addEventListener('change', (e) => {
            updateQuantity(item.id, parseInt(e.target.value) || 1);
        });

        cartItem.querySelector('.remove-btn').addEventListener('click', () => {
            removeFromCart(item.id);
        });

        cartItems.appendChild(cartItem);
    });

    updateCartSummary();
}

function updateCartSummary() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const shipping = subtotal > 1000 ? 0 : 50;
    const total = subtotal + shipping;

    document.getElementById('subtotal').textContent = `$${subtotal.toLocaleString()}`;
    document.getElementById('shipping').textContent = shipping === 0 ? 'Gratis' : `$${shipping}`;
    document.getElementById('total').textContent = `$${total.toLocaleString()}`;
}

// =======================================
// INICIALIZACIÓN
// =======================================

document.addEventListener('DOMContentLoaded', init);
