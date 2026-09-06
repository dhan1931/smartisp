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
const pool = process.env.DATABASE_URL ? new Pool({ connectionString: process.env.DATABASE_URL, ssl: process.env.NODE_ENV === 'production' ? { rejectUnauthorized: false } : undefined }) : null;

const demoPasswordHash = await bcrypt.hash('pepe1234', 12);
const demoUser = {
  id: 'demo-medardo',
  email: 'medardo@gmail.com',
  passwordHash: demoPasswordHash,
  name: 'Medardo',
  surname: 'Demo',
  phone: '+593 999 000 000'
};

if (!pool) users.set(demoUser.email, demoUser);

const publicUser = user => ({ email: user.email, name: user.name, surname: user.surname || '', phone: user.phone || '' });
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
    secure: process.env.NODE_ENV === 'production',
    maxAge: 1000 * 60 * 60 * 24 * 7
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
  req.session.cookie.maxAge = remember ? 1000 * 60 * 60 * 24 * 30 : 1000 * 60 * 60 * 24 * 7;
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

app.post('/api/auth/logout', (req, res) => {
  req.session.destroy(() => res.json({ ok: true }));
});

await initializeDatabase();
app.listen(port, () => {
  console.log(`NexoTech disponible en http://localhost:${port}`);
});
