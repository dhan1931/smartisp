import express from 'express';
import session from 'express-session';
import bcrypt from 'bcryptjs';
import { Pool } from 'pg';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const app = express();
const port = process.env.PORT || 3000;
const users = new Map();
const wishlists = new Map();
const orders = new Map();
const passwordResets = new Map();
const pool = process.env.DATABASE_URL ? new Pool({ connectionString: process.env.DATABASE_URL, ssl: process.env.NODE_ENV === 'production' ? { rejectUnauthorized: false } : undefined }) : null;

const demoPasswordHash = await bcrypt.hash('pepe1234', 12);
const demoUser = {
  id: 'demo-medardo',
  email: 'medardo@gmail.com',
  passwordHash: demoPasswordHash,
  name: 'Medardo',
  surname: 'Demo',
  phone: '+593 999 000 000',
  role: 'admin'
};

if (!pool) users.set(demoUser.email, demoUser);

const publicUser = user => ({
  id: user.id,
  email: user.email,
  name: user.name,
  surname: user.surname || '',
  phone: user.phone || '',
  role: user.role || (user.email === demoUser.email || (process.env.ADMIN_EMAIL && user.email === process.env.ADMIN_EMAIL.toLowerCase()) ? 'admin' : 'customer')
});
const findUserByEmail = async email => {
  if (!pool) return users.get(email);
  const result = await pool.query('SELECT id, email, password_hash AS "passwordHash", name, surname, phone FROM users WHERE email = $1 LIMIT 1', [email]);
  return result.rows[0];
};
const findUserById = async id => {
  if (!pool) return [...users.values()].find(user => user.id === id);
  const result = await pool.query('SELECT id, email, password_hash AS "passwordHash", name, surname, phone FROM users WHERE id = $1 LIMIT 1', [id]);
  return result.rows[0];
};
const sendResetEmail = async (email, resetUrl) => { if (!process.env.RESEND_API_KEY || !process.env.EMAIL_FROM) throw new Error('El servicio de correo no está configurado.'); const response = await fetch('https://api.resend.com/emails', { method: 'POST', headers: { Authorization: `Bearer ${process.env.RESEND_API_KEY}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ from: process.env.EMAIL_FROM, to: [email], subject: 'Restablece tu contraseña de SmartISP', html: `<p>Recibimos una solicitud para cambiar tu contraseña.</p><p><a href="${resetUrl}">Cambiar contraseña</a></p><p>Este enlace caduca en 1 hora y solo puede utilizarse una vez.</p>` }) }); if (!response.ok) throw new Error('No se pudo enviar el correo de recuperación.'); };
const initializeDatabase = async () => {
  if (!pool) return;
  await pool.query(`CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    surname TEXT NOT NULL,
    phone TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
  )`);
  await pool.query('INSERT INTO users (id, email, password_hash, name, surname, phone) VALUES ($1, $2, $3, $4, $5, $6) ON CONFLICT (email) DO NOTHING', [demoUser.id, demoUser.email, demoUser.passwordHash, demoUser.name, demoUser.surname, demoUser.phone]);
};

app.use(express.json());
app.use(session({
  name: 'nexotech.sid',
  secret: process.env.SESSION_SECRET || 'cambia-esta-clave-en-produccion',
  resave: false,
  saveUninitialized: false,
  cookie: {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production'
  }
}));
app.use(express.static(__dirname));

app.post('/api/auth/login', async (req, res) => {
  const email = String(req.body.email || '').trim().toLowerCase();
  const password = String(req.body.password || '');
  const remember = req.body.remember === true || req.body.remember === 'true' || req.body.remember === 'on';
  const user = await findUserByEmail(email);

  if (!user || !(await bcrypt.compare(password, user.passwordHash))) {
    return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
  }

  req.session.userId = user.id;
  req.session.cookie.maxAge = remember ? 1000 * 60 * 60 * 24 * 30 : null;
  return res.json({ user: publicUser(user) });
});

app.post('/api/auth/register', async (req, res) => {
  const name = String(req.body.name || '').trim();
  const surname = String(req.body.surname || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const phone = String(req.body.phone || '').trim();
  const password = String(req.body.password || '');

  if (name.length < 2 || surname.length < 2 || !email.includes('@') || phone.length < 7 || password.length < 6) {
    return res.status(400).json({ error: 'Completa nombre, apellido, correo, teléfono y una contraseña de 6 caracteres.' });
  }

  if (await findUserByEmail(email)) {
    return res.status(409).json({ error: 'Ya existe una cuenta con ese correo.' });
  }

  const user = { id: crypto.randomUUID(), email, passwordHash: await bcrypt.hash(password, 12), name, surname, phone };
  if (pool) {
    await pool.query('INSERT INTO users (id, email, password_hash, name, surname, phone) VALUES ($1, $2, $3, $4, $5, $6)', [user.id, user.email, user.passwordHash, user.name, user.surname, user.phone]);
  } else users.set(email, user);
  req.session.userId = user.id;
  return res.status(201).json({ user: publicUser(user) });
});

app.get('/api/auth/me', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'No hay una sesión activa.' });
  return res.json({ user: publicUser(user) });
});

app.post('/api/auth/change-password', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  const currentPassword = String(req.body.currentPassword || '');
  const newPassword = String(req.body.newPassword || '');
  const confirmation = String(req.body.confirmation || '');
  if (newPassword.length < 8) return res.status(400).json({ error: 'La nueva contraseña debe tener al menos 8 caracteres.' });
  if (newPassword !== confirmation) return res.status(400).json({ error: 'La confirmación no coincide con la nueva contraseña.' });
  if (!(await bcrypt.compare(currentPassword, user.passwordHash))) return res.status(401).json({ error: 'La contraseña actual no es correcta.' });
  user.passwordHash = await bcrypt.hash(newPassword, 12);
  if (pool) await pool.query('UPDATE users SET password_hash = $1 WHERE id = $2', [user.passwordHash, user.id]);
  return res.json({ ok: true });
});

app.post('/api/auth/request-password-reset', async (req, res) => {
  const email = String(req.body.email || '').trim().toLowerCase();
  const user = await findUserByEmail(email);
  if (!user) return res.json({ ok: true });
  const token = crypto.randomBytes(32).toString('hex');
  passwordResets.set(token, { userId: user.id, expiresAt: Date.now() + 60 * 60 * 1000 });
  const baseUrl = String(process.env.APP_URL || `http://localhost:${port}`).replace(/\/$/, '');
  try { await sendResetEmail(user.email, `${baseUrl}/reset-password.html?token=${encodeURIComponent(token)}`); } catch (error) { passwordResets.delete(token); return res.status(500).json({ error: error.message }); }
  return res.json({ ok: true });
});

app.post('/api/auth/reset-password', async (req, res) => {
  const token = String(req.body.token || '');
  const reset = passwordResets.get(token);
  const password = String(req.body.password || '');
  const confirmation = String(req.body.confirmation || '');
  if (password.length < 8) return res.status(400).json({ error: 'La contraseña debe tener al menos 8 caracteres.' });
  if (password !== confirmation) return res.status(400).json({ error: 'Las contraseñas no coinciden.' });
  if (!reset || reset.expiresAt < Date.now()) return res.status(400).json({ error: 'El enlace no es válido o ya expiró.' });
  const user = await findUserById(reset.userId);
  if (!user) return res.status(400).json({ error: 'El enlace no es válido.' });
  user.passwordHash = await bcrypt.hash(password, 12);
  if (pool) await pool.query('UPDATE users SET password_hash = $1 WHERE id = $2', [user.passwordHash, user.id]);
  passwordResets.delete(token);
  return res.json({ ok: true });
});

app.get('/api/auth/customer-wishlist', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  return res.json({ wishlist: wishlists.get(user.id) || [] });
});

app.post('/api/auth/customer-wishlist', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  if (Array.isArray(req.body.order)) {
    const list = wishlists.get(user.id) || [];
    const byId = new Map(list.map(item => [item.productId, item]));
    wishlists.set(user.id, req.body.order.map(productId => byId.get(String(productId))).filter(Boolean));
    return res.json({ saved: true });
  }
  const productId = String(req.body.productId || req.body.id || '').trim();
  const list = wishlists.get(user.id) || [];
  const item = { productId, name: String(req.body.name || ''), price: Number(req.body.price || 0), image: String(req.body.image || ''), category: String(req.body.category || '') };
  wishlists.set(user.id, [item, ...list.filter(saved => saved.productId !== productId)]);
  return res.json({ saved: true });
});

app.delete('/api/auth/customer-wishlist', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  const productId = String(req.query.productId || '');
  wishlists.set(user.id, (wishlists.get(user.id) || []).filter(item => item.productId !== productId));
  return res.json({ saved: false });
});

app.get('/api/auth/customer-orders', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  return res.json({ orders: orders.get(user.id) || [] });
});

app.post('/api/auth/customer-orders', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  const items = Array.isArray(req.body.items) ? req.body.items : [];
  if (!items.length) return res.status(400).json({ error: 'El carrito está vacío.' });
  const order = { id: crypto.randomUUID(), total: items.reduce((sum, item) => sum + Number(item.price || 0), 0), status: 'received', items, createdAt: new Date().toISOString() };
  orders.set(user.id, [order, ...(orders.get(user.id) || [])]);
  return res.status(201).json(order);
});

app.post('/api/auth/logout', (req, res) => {
  req.session.destroy(() => res.json({ ok: true }));
});

const inMemoryProducts = new Map();
const inMemoryContent = new Map();

const requireAdminUser = async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) {
    res.status(401).json({ error: 'Debes iniciar sesión.' });
    return null;
  }
  const role = user.role || (user.email === demoUser.email || (process.env.ADMIN_EMAIL && user.email === process.env.ADMIN_EMAIL.toLowerCase()) ? 'admin' : 'customer');
  if (role !== 'admin') {
    res.status(403).json({ error: 'No tienes permisos de administrador.' });
    return null;
  }
  return user;
};

const ensureProductsTable = async () => {
  if (!pool) return;
  await pool.query(`CREATE TABLE IF NOT EXISTS products (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    price NUMERIC(12, 2) NOT NULL DEFAULT 0,
    category TEXT NOT NULL DEFAULT '',
    image_url TEXT NOT NULL DEFAULT '',
    external_url TEXT NOT NULL DEFAULT '',
    sku TEXT NOT NULL DEFAULT '',
    visible BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
  );
  ALTER TABLE products ADD COLUMN IF NOT EXISTS external_url TEXT NOT NULL DEFAULT '';
  ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT NOT NULL DEFAULT '';`);
};

app.get('/api/auth/admin-products', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  if (pool) {
    await ensureProductsTable();
    const result = await pool.query('SELECT id, name, description, price, category, image_url AS "imageUrl", external_url AS "externalUrl", sku, visible FROM products ORDER BY created_at DESC');
    return res.json({ products: result.rows });
  }
  return res.json({ products: [...inMemoryProducts.values()] });
});

app.post('/api/auth/admin-products', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  const product = req.body || {};
  const id = String(product.id || crypto.randomUUID());
  const name = String(product.name || '').trim();
  const description = String(product.description || '').trim();
  const price = Number(product.price || 0);
  const category = String(product.category || '').trim();
  const imageUrl = String(product.imageUrl || product.image_url || '').trim();
  const externalUrl = String(product.externalUrl || product.external_url || '').trim();
  const sku = String(product.sku || '').trim();
  const visible = product.visible !== false;

  if (pool) {
    await ensureProductsTable();
    await pool.query(`INSERT INTO products (id, name, description, price, category, image_url, external_url, sku, visible, updated_at)
      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
      ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, price = EXCLUDED.price,
      category = EXCLUDED.category, image_url = EXCLUDED.image_url, external_url = EXCLUDED.external_url, sku = EXCLUDED.sku,
      visible = EXCLUDED.visible, updated_at = NOW()`,
      [id, name, description, price, category, imageUrl, externalUrl, sku, visible]);
    return res.json({ ok: true, id });
  }

  inMemoryProducts.set(id, { id, name, description, price, category, imageUrl, externalUrl, sku, visible });
  return res.json({ ok: true, id });
});

app.delete('/api/auth/admin-products', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  const id = String(req.query?.id || '');
  if (pool) {
    await ensureProductsTable();
    await pool.query('DELETE FROM products WHERE id = $1', [id]);
  } else {
    inMemoryProducts.delete(id);
  }
  return res.json({ ok: true });
});

app.post('/api/auth/admin-products-bulk', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  const items = Array.isArray(req.body.products) ? req.body.products : [];
  if (!items.length) return res.status(400).json({ error: 'No se recibieron productos para importar.' });

  let count = 0;
  if (pool) {
    await ensureProductsTable();
    await pool.query('BEGIN');
    try {
      for (const item of items) {
        const name = String(item.name || '').trim();
        if (!name) continue;
        const sku = String(item.sku || '').trim();
        const id = String(item.id || (sku ? `sku:${sku.toUpperCase()}` : crypto.randomUUID()));
        const description = String(item.description || '').trim();
        const parsedPrice = Number(item.price);
        const price = !isNaN(parsedPrice) && parsedPrice > 0 ? parsedPrice : 0;
        const category = String(item.category || 'General').trim();
        const imageUrl = String(item.imageUrl || item.image_url || '').trim();
        const externalUrl = String(item.externalUrl || item.external_url || '').trim();
        const visible = item.visible !== false;

        await pool.query(`INSERT INTO products (id, name, description, price, category, image_url, external_url, sku, visible, updated_at)
          VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
          ON CONFLICT (id) DO UPDATE SET
            name = EXCLUDED.name,
            description = CASE WHEN EXCLUDED.description <> '' THEN EXCLUDED.description ELSE products.description END,
            price = EXCLUDED.price,
            category = CASE WHEN EXCLUDED.category <> '' THEN EXCLUDED.category ELSE products.category END,
            image_url = CASE WHEN EXCLUDED.image_url <> '' THEN EXCLUDED.image_url ELSE products.image_url END,
            external_url = CASE WHEN EXCLUDED.external_url <> '' THEN EXCLUDED.external_url ELSE products.external_url END,
            sku = CASE WHEN EXCLUDED.sku <> '' THEN EXCLUDED.sku ELSE products.sku END,
            visible = EXCLUDED.visible,
            updated_at = NOW()`,
          [id, name, description, price, category, imageUrl, externalUrl, sku, visible]);
        count++;
      }
      await pool.query('COMMIT');
    } catch (err) {
      await pool.query('ROLLBACK');
      throw err;
    }
  } else {
    for (const item of items) {
      const name = String(item.name || '').trim();
      if (!name) continue;
      const sku = String(item.sku || '').trim();
      const id = String(item.id || (sku ? `sku:${sku.toUpperCase()}` : crypto.randomUUID()));
      const description = String(item.description || '').trim();
      const parsedPrice = Number(item.price);
      const price = !isNaN(parsedPrice) && parsedPrice > 0 ? parsedPrice : 0;
      const category = String(item.category || 'General').trim();
      const imageUrl = String(item.imageUrl || item.image_url || '').trim();
      const externalUrl = String(item.externalUrl || item.external_url || '').trim();
      const visible = item.visible !== false;

      const existing = inMemoryProducts.get(id) || {};
      inMemoryProducts.set(id, {
        id,
        name,
        description: description || existing.description || '',
        price,
        category: category || existing.category || 'General',
        imageUrl: imageUrl || existing.imageUrl || '',
        externalUrl: externalUrl || existing.externalUrl || '',
        sku: sku || existing.sku || '',
        visible
      });
      count++;
    }
  }

  return res.json({ ok: true, count });
});

app.get('/api/auth/admin-content', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  if (pool) {
    await pool.query(`CREATE TABLE IF NOT EXISTS site_content (
      content_key TEXT PRIMARY KEY,
      content_value TEXT NOT NULL DEFAULT '',
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )`);
    const result = await pool.query('SELECT content_key AS "key", content_value AS value FROM site_content ORDER BY content_key');
    return res.json({ content: result.rows });
  }
  return res.json({ content: [...inMemoryContent.entries()].map(([key, value]) => ({ key, value })) });
});

app.post('/api/auth/admin-content', async (req, res) => {
  if (!await requireAdminUser(req, res)) return;
  const content = req.body || {};
  if (pool) {
    for (const [key, value] of Object.entries(content)) {
      await pool.query(`INSERT INTO site_content (content_key, content_value, updated_at) VALUES ($1, $2, NOW())
        ON CONFLICT (content_key) DO UPDATE SET content_value = EXCLUDED.content_value, updated_at = NOW()`, [String(key), String(value ?? '')]);
    }
  } else {
    for (const [key, value] of Object.entries(content)) inMemoryContent.set(key, String(value ?? ''));
  }
  return res.json({ ok: true });
});

app.get('/api/auth/catalog', async (req, res) => {
  if (pool) {
    await ensureProductsTable();
    const [products, content] = await Promise.all([
      pool.query('SELECT id, name, description, price, category, image_url AS "imageUrl", external_url AS "externalUrl", sku FROM products WHERE visible = TRUE ORDER BY created_at DESC'),
      pool.query('SELECT content_key AS "key", content_value AS value FROM site_content')
    ]);
    return res.json({ products: products.rows, content: content.rows });
  }
  return res.json({
    products: [...inMemoryProducts.values()].filter(p => p.visible !== false),
    content: [...inMemoryContent.entries()].map(([key, value]) => ({ key, value }))
  });
});

const searchWebImages = async (query, limit = 8) => {
  const cleanQuery = String(query || '').trim();
  if (!cleanQuery) return [];
  try {
    const res1 = await fetch('https://duckduckgo.com/?q=' + encodeURIComponent(cleanQuery) + '&iax=images&ia=images', {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
      }
    });
    const html = await res1.text();
    const match = /vqd=([0-9-]+)/.exec(html);
    if (match && match[1]) {
      const res2 = await fetch('https://duckduckgo.com/i.js?l=es-es&o=json&q=' + encodeURIComponent(cleanQuery) + '&vqd=' + match[1], {
        headers: {
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
      });
      const data = await res2.json();
      if (data.results && Array.isArray(data.results)) {
        return data.results
          .filter(r => r.image && /^https?:\/\//i.test(r.image))
          .slice(0, limit)
          .map(r => ({
            url: r.image.replace(/^http:\/\//i, 'https://'),
            thumbnail: (r.thumbnail || r.image).replace(/^http:\/\//i, 'https://'),
            title: r.title || cleanQuery
          }));
      }
    }
  } catch (err) {
    console.warn('Error buscando imagen en DDG:', err.message);
  }

  try {
    const wikiUrl = `https://en.wikipedia.org/w/api.php?action=query&format=json&prop=pageimages&generator=search&gsrsearch=${encodeURIComponent(cleanQuery)}&gsrlimit=${limit}&piprop=thumbnail|original&pithumbsize=600`;
    const wikiRes = await fetch(wikiUrl);
    const wikiData = await wikiRes.json();
    const pages = Object.values(wikiData?.query?.pages || {});
    const images = pages
      .map(p => p.original?.source || p.thumbnail?.source)
      .filter(Boolean)
      .map(url => ({ url, thumbnail: url, title: cleanQuery }));
    if (images.length) return images;
  } catch (err) {
    console.warn('Error buscando imagen en Wikimedia:', err.message);
  }

  return [];
};

app.get('/api/auth/search-product-image', async (req, res) => {
  const query = String(req.query?.q || '').trim();
  const limit = Math.min(20, Math.max(1, Number(req.query?.limit || 8)));
  const images = await searchWebImages(query, limit);
  return res.json({ images });
});

await initializeDatabase();
app.listen(port, () => {
  console.log(`NexoTech disponible en http://localhost:${port}`);
});
