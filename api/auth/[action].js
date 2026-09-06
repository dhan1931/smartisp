import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';
import { Pool } from 'pg';

let pool;
let databaseReady;

const getPool = () => {
  const configuredUrl = process.env.DATABASE_URL || process.env.DATABASE_POSTGRES_URL || process.env.POSTGRES_URL || process.env.POSTGRES_PRISMA_URL;
  if (!configuredUrl) throw new Error('No hay una URL de PostgreSQL configurada en Vercel.');
  const connectionUrl = new URL(configuredUrl);
  connectionUrl.searchParams.delete('sslmode');
  pool ||= new Pool({
    connectionString: connectionUrl.toString(),
    ssl: { rejectUnauthorized: false }
  });
  return pool;
};

const ensureDatabase = async () => {
  if (!databaseReady) {
    databaseReady = getPool().query(`CREATE TABLE IF NOT EXISTS users (
      id TEXT PRIMARY KEY,
      email TEXT UNIQUE NOT NULL,
      password_hash TEXT NOT NULL,
      name TEXT NOT NULL,
      surname TEXT NOT NULL,
      phone TEXT NOT NULL,
      role TEXT NOT NULL DEFAULT 'customer',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    ALTER TABLE users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'customer';`)
      .then(async result => {
        if (process.env.ADMIN_EMAIL) {
          await getPool().query("UPDATE users SET role = 'admin' WHERE email = $1", [process.env.ADMIN_EMAIL.trim().toLowerCase()]);
        }
        return result;
      });
  }
  await databaseReady;
};

const publicUser = user => ({
  id: user.id,
  email: user.email,
  name: user.name,
  surname: user.surname || '',
  phone: user.phone || '',
  role: user.role || 'customer'
});

const signSession = (userId, remember = false) => {
  const duration = remember ? 1000 * 60 * 60 * 24 * 30 : 1000 * 60 * 60 * 24;
  const payload = Buffer.from(JSON.stringify({ userId, exp: Date.now() + duration })).toString('base64url');
  const secret = process.env.SESSION_SECRET || 'cambia-esta-clave-en-produccion';
  const signature = crypto.createHmac('sha256', secret).update(payload).digest('base64url');
  return `${payload}.${signature}`;
};

const getSessionUserId = req => {
  const cookie = String(req.headers.cookie || '').split('; ').find(value => value.startsWith('smartisp_session='));
  if (!cookie) return null;
  const token = cookie.slice('smartisp_session='.length);
  const [payload, signature] = token.split('.');
  if (!payload || !signature) return null;
  const secret = process.env.SESSION_SECRET || 'cambia-esta-clave-en-produccion';
  const expected = crypto.createHmac('sha256', secret).update(payload).digest('base64url');
  if (signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) return null;
  try {
    const session = JSON.parse(Buffer.from(payload, 'base64url').toString());
    return session.exp > Date.now() ? session.userId : null;
  } catch {
    return null;
  }
};

const setSessionCookie = (res, token, remember = false) => {
  const secure = process.env.NODE_ENV === 'production' ? '; Secure' : '';
  const maxAge = remember ? '; Max-Age=2592000' : '';
  res.setHeader('Set-Cookie', `smartisp_session=${token}; Path=/; HttpOnly; SameSite=Lax${maxAge}${secure}`);
};

const clearSessionCookie = res => {
  res.setHeader('Set-Cookie', 'smartisp_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
};

const bodyOf = req => typeof req.body === 'string' ? JSON.parse(req.body || '{}') : (req.body || {});

const getAuthenticatedUser = async (req, database) => {
  const userId = getSessionUserId(req);
  if (!userId) return null;
  const result = await database.query('SELECT id, email, name, surname, phone, role FROM users WHERE id = $1 LIMIT 1', [userId]);
  return result.rows[0] || null;
};

const requireAdmin = async (req, res, database) => {
  const user = await getAuthenticatedUser(req, database);
  if (!user) {
    res.status(401).json({ error: 'Debes iniciar sesión.' });
    return null;
  }
  if (user.role !== 'admin') {
    res.status(403).json({ error: 'No tienes permisos de administrador.' });
    return null;
  }
  return user;
};

export default async function handler(req, res) {
  const action = req.query?.action || req.url?.split('?')[0].split('/').filter(Boolean).pop();
  try {
    await ensureDatabase();
    const database = getPool();

    if (action === 'login' && req.method === 'POST') {
      const body = bodyOf(req);
      const email = String(body.email || '').trim().toLowerCase();
      const password = String(body.password || '');
      const remember = body.remember === true || body.remember === 'true' || body.remember === 'on';
      const result = await database.query('SELECT id, email, password_hash AS "passwordHash", name, surname, phone, role FROM users WHERE email = $1 LIMIT 1', [email]);
      const user = result.rows[0];
      if (!user || !(await bcrypt.compare(password, user.passwordHash))) return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
      setSessionCookie(res, signSession(user.id, remember), remember);
      return res.status(200).json({ user: publicUser(user) });
    }

    if (action === 'register' && req.method === 'POST') {
      const body = bodyOf(req);
      const name = String(body.name || '').trim();
      const surname = String(body.surname || '').trim();
      const email = String(body.email || '').trim().toLowerCase();
      const phone = String(body.phone || '').trim();
      const password = String(body.password || '');
      if (name.length < 2 || surname.length < 2 || !email.includes('@') || phone.length < 7 || password.length < 6) {
        return res.status(400).json({ error: 'Completa nombre, apellido, correo, teléfono y una contraseña de 6 caracteres.' });
      }
      const existing = await database.query('SELECT id FROM users WHERE email = $1 LIMIT 1', [email]);
      if (existing.rowCount) return res.status(409).json({ error: 'Ya existe una cuenta con ese correo.' });
      const user = { id: crypto.randomUUID(), email, name, surname, phone, passwordHash: await bcrypt.hash(password, 12) };
      const role = process.env.ADMIN_EMAIL?.trim().toLowerCase() === email ? 'admin' : 'customer';
      user.role = role;
      await database.query('INSERT INTO users (id, email, password_hash, name, surname, phone, role) VALUES ($1, $2, $3, $4, $5, $6, $7)', [user.id, user.email, user.passwordHash, user.name, user.surname, user.phone, user.role]);
      setSessionCookie(res, signSession(user.id));
      return res.status(201).json({ user: publicUser(user) });
    }

    if (action === 'me' && req.method === 'GET') {
      const userId = getSessionUserId(req);
      if (!userId) return res.status(401).json({ error: 'No hay una sesión activa.' });
      const result = await database.query('SELECT id, email, name, surname, phone, role FROM users WHERE id = $1 LIMIT 1', [userId]);
      if (!result.rows[0]) return res.status(401).json({ error: 'No hay una sesión activa.' });
      return res.status(200).json({ user: publicUser(result.rows[0]) });
    }

    if (action === 'admin-products' && (req.method === 'GET' || req.method === 'POST' || req.method === 'DELETE')) {
      if (!await requireAdmin(req, res, database)) return;
      await database.query(`CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        price NUMERIC(12, 2) NOT NULL DEFAULT 0,
        category TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '',
        visible BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      if (req.method === 'GET') {
        const result = await database.query('SELECT id, name, description, price, category, image_url AS "imageUrl", visible FROM products ORDER BY created_at DESC');
        return res.status(200).json({ products: result.rows });
      }
      if (req.method === 'DELETE') {
        const id = String(req.query?.id || '');
        await database.query('DELETE FROM products WHERE id = $1', [id]);
        return res.status(200).json({ ok: true });
      }
      const product = bodyOf(req);
      const id = String(product.id || crypto.randomUUID());
      await database.query(`INSERT INTO products (id, name, description, price, category, image_url, visible, updated_at)
        VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
        ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, price = EXCLUDED.price,
        category = EXCLUDED.category, image_url = EXCLUDED.image_url, visible = EXCLUDED.visible, updated_at = NOW()`,
        [id, String(product.name || '').trim(), String(product.description || '').trim(), Number(product.price || 0), String(product.category || '').trim(), String(product.imageUrl || '').trim(), product.visible !== false]);
      return res.status(200).json({ ok: true, id });
    }

    if (action === 'admin-content' && (req.method === 'GET' || req.method === 'POST')) {
      if (!await requireAdmin(req, res, database)) return;
      await database.query(`CREATE TABLE IF NOT EXISTS site_content (
        content_key TEXT PRIMARY KEY,
        content_value TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      if (req.method === 'GET') {
        const result = await database.query('SELECT content_key AS "key", content_value AS value FROM site_content ORDER BY content_key');
        return res.status(200).json({ content: result.rows });
      }
      const content = bodyOf(req);
      for (const [key, value] of Object.entries(content)) {
        await database.query(`INSERT INTO site_content (content_key, content_value, updated_at) VALUES ($1, $2, NOW())
          ON CONFLICT (content_key) DO UPDATE SET content_value = EXCLUDED.content_value, updated_at = NOW()`, [String(key), String(value ?? '')]);
      }
      return res.status(200).json({ ok: true });
    }

    if (action === 'catalog' && req.method === 'GET') {
      await database.query(`CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        price NUMERIC(12, 2) NOT NULL DEFAULT 0,
        category TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '',
        visible BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      await database.query(`CREATE TABLE IF NOT EXISTS site_content (
        content_key TEXT PRIMARY KEY,
        content_value TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      const [products, content] = await Promise.all([
        database.query('SELECT id, name, description, price, category, image_url AS "imageUrl" FROM products WHERE visible = TRUE ORDER BY created_at DESC'),
        database.query('SELECT content_key AS "key", content_value AS value FROM site_content')
      ]);
      return res.status(200).json({ products: products.rows, content: content.rows });
    }

    if (action === 'logout' && req.method === 'POST') {
      clearSessionCookie(res);
      return res.status(200).json({ ok: true });
    }

    return res.status(404).json({ error: 'Ruta no encontrada.' });
  } catch (error) {
    console.error('Error en autenticación:', error);
    return res.status(500).json({ error: 'No se pudo conectar con la base de datos.' });
  }
}
