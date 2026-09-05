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
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )`);
  }
  await databaseReady;
};

const publicUser = user => ({
  email: user.email,
  name: user.name,
  surname: user.surname || '',
  phone: user.phone || ''
});

const signSession = userId => {
  const payload = Buffer.from(JSON.stringify({ userId, exp: Date.now() + 1000 * 60 * 60 * 24 * 7 })).toString('base64url');
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

const setSessionCookie = (res, token) => {
  const secure = process.env.NODE_ENV === 'production' ? '; Secure' : '';
  res.setHeader('Set-Cookie', `smartisp_session=${token}; Path=/; HttpOnly; SameSite=Lax; Max-Age=604800${secure}`);
};

const clearSessionCookie = res => {
  res.setHeader('Set-Cookie', 'smartisp_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
};

const bodyOf = req => typeof req.body === 'string' ? JSON.parse(req.body || '{}') : (req.body || {});

export default async function handler(req, res) {
  const action = req.query?.action || req.url?.split('?')[0].split('/').filter(Boolean).pop();
  try {
    await ensureDatabase();
    const database = getPool();

    if (action === 'login' && req.method === 'POST') {
      const body = bodyOf(req);
      const email = String(body.email || '').trim().toLowerCase();
      const password = String(body.password || '');
      const result = await database.query('SELECT id, email, password_hash AS "passwordHash", name, surname, phone FROM users WHERE email = $1 LIMIT 1', [email]);
      const user = result.rows[0];
      if (!user || !(await bcrypt.compare(password, user.passwordHash))) return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
      setSessionCookie(res, signSession(user.id));
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
      await database.query('INSERT INTO users (id, email, password_hash, name, surname, phone) VALUES ($1, $2, $3, $4, $5, $6)', [user.id, user.email, user.passwordHash, user.name, user.surname, user.phone]);
      setSessionCookie(res, signSession(user.id));
      return res.status(201).json({ user: publicUser(user) });
    }

    if (action === 'me' && req.method === 'GET') {
      const userId = getSessionUserId(req);
      if (!userId) return res.status(401).json({ error: 'No hay una sesión activa.' });
      const result = await database.query('SELECT email, name, surname, phone FROM users WHERE id = $1 LIMIT 1', [userId]);
      if (!result.rows[0]) return res.status(401).json({ error: 'No hay una sesión activa.' });
      return res.status(200).json({ user: publicUser(result.rows[0]) });
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
