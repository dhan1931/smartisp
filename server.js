import express from 'express';
import session from 'express-session';
import bcrypt from 'bcryptjs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const app = express();
const port = process.env.PORT || 3000;
const users = new Map();

const demoPasswordHash = await bcrypt.hash('pepe1234', 12);
users.set('medardo@gmail.com', {
  id: 'demo-medardo',
  email: 'medardo@gmail.com',
  passwordHash: demoPasswordHash,
  name: 'Medardo',
  surname: 'Demo',
  phone: '+593 999 000 000'
});

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
  const user = users.get(email);

  if (!user || !(await bcrypt.compare(password, user.passwordHash))) {
    return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
  }

  req.session.userId = user.id;
  return res.json({ user: { email: user.email, name: user.name, surname: user.surname || '', phone: user.phone || '' } });
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

  if (users.has(email)) {
    return res.status(409).json({ error: 'Ya existe una cuenta con ese correo.' });
  }

  const user = { id: `user-${Date.now()}`, email, passwordHash: await bcrypt.hash(password, 12), name, surname, phone };
  users.set(email, user);
  req.session.userId = user.id;
  return res.status(201).json({ user: { email: user.email, name: user.name, surname: user.surname, phone: user.phone } });
});

app.get('/api/auth/me', (req, res) => {
  const user = [...users.values()].find(candidate => candidate.id === req.session.userId);
  if (!user) return res.status(401).json({ error: 'No hay una sesión activa.' });
  return res.json({ user: { email: user.email, name: user.name, surname: user.surname || '', phone: user.phone || '' } });
});

app.post('/api/auth/logout', (req, res) => {
  req.session.destroy(() => res.json({ ok: true }));
});

app.listen(port, () => {
  console.log(`NexoTech disponible en http://localhost:${port}`);
});
