import express from 'express';
import session from 'express-session';
import bcrypt from 'bcryptjs';
import nodemailer from 'nodemailer';
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

const moneyFormat = value => '$' + Number(value || 0).toLocaleString('es-CL');

const getEmailTransporter = () => {
  if (process.env.SMTP_HOST && process.env.SMTP_USER) {
    return nodemailer.createTransport({
      host: process.env.SMTP_HOST,
      port: Number(process.env.SMTP_PORT || 587),
      secure: process.env.SMTP_SECURE === 'true' || Number(process.env.SMTP_PORT) === 465,
      auth: {
        user: process.env.SMTP_USER,
        pass: process.env.SMTP_PASS
      }
    });
  }
  return null;
};

const sendEmail = async ({ to, subject, html, text }) => {
  const from = process.env.EMAIL_FROM || process.env.SMTP_FROM || process.env.SMTP_USER || 'SmartISP <ventas@smartisp.com>';

  // 1. Resend
  if (process.env.RESEND_API_KEY && process.env.EMAIL_FROM) {
    try {
      const response = await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          from: process.env.EMAIL_FROM,
          to: Array.isArray(to) ? to : [to],
          subject,
          html
        })
      });
      if (response.ok) return { success: true, provider: 'resend' };
    } catch (err) {
      console.warn('Fallo al enviar correo mediante Resend:', err.message);
    }
  }

  // 2. SMTP Nodemailer
  const transporter = getEmailTransporter();
  if (transporter) {
    try {
      await transporter.sendMail({
        from,
        to,
        subject,
        html,
        text
      });
      return { success: true, provider: 'smtp' };
    } catch (err) {
      console.warn('Fallo al enviar correo mediante SMTP:', err.message);
    }
  }

  // 3. Fallback: Log in console cleanly
  console.log('\n====================================================');
  console.log(`📨 [SIMULACIÓN CORREO ELECTRÓNICO]`);
  console.log(`Destinatario: ${to}`);
  console.log(`De: ${from}`);
  console.log(`Asunto: ${subject}`);
  console.log(`Fecha: ${new Date().toLocaleString('es-CL')}`);
  console.log('----------------------------------------------------');
  console.log(text || html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 450) + '...');
  console.log('====================================================\n');
  return { success: true, provider: 'mock' };
};

const buildCustomerReceiptEmail = ({ orderId, customerName, customerPhone, items, subtotal, total, shipping }) => {
  const itemsHtml = items.map(item => `
    <tr>
      <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">
        <strong style="color: #102c3d;">${item.name}</strong>
        ${item.category ? `<br><small style="color: #64748b;">${item.category}</small>` : ''}
      </td>
      <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: center; color: #334155;">${item.quantity}</td>
      <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right; color: #334155;">${moneyFormat(item.price)}</td>
      <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #087ea4;">${moneyFormat(item.price * item.quantity)}</td>
    </tr>
  `).join('');

  return `
  <!DOCTYPE html>
  <html>
  <head><meta charset="UTF-8"></head>
  <body style="margin:0; padding:20px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; background-color:#f3f7f8; color:#163342;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px; margin:0 auto; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #d8e5e7; box-shadow:0 8px 24px rgba(16,44,61,0.06);">
      <tr>
        <td style="padding: 26px 32px; background-color: #102c3d; text-align: left;">
          <span style="font-size: 26px; font-weight: bold; color: #ffffff; letter-spacing: -0.05em;">smart<span style="color:#087ea4">isp</span><span style="color:#f5a524">.</span></span>
          <div style="color: #94a3b8; font-size: 13px; margin-top: 4px;">Comprobante Oficial de Compra</div>
        </td>
      </tr>
      <tr>
        <td style="padding: 32px;">
          <h2 style="margin: 0 0 12px; color: #102c3d; font-size: 22px;">¡Gracias por tu compra, ${customerName}!</h2>
          <p style="margin: 0 0 20px; color: #475569; font-size: 14.5px; line-height: 1.5;">
            Hemos recibido tu pedido con el número <strong style="color:#087ea4;">#${orderId}</strong>. A continuación tienes el comprobante con los detalles de tu compra:
          </p>

          <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #eaf7f5; border-left: 4px solid #087ea4; border-radius: 6px; margin-bottom: 24px;">
            <tr>
              <td style="padding: 14px 18px;">
                <strong style="color: #087ea4; font-size: 14px; display: block; margin-bottom: 4px;">ℹ️ Próximos pasos de tu orden:</strong>
                <p style="margin: 0; color: #163342; font-size: 13px; line-height: 1.45;">
                  Uno de nuestros asesores comerciales se pondrá en contacto contigo muy pronto a tu <b>número de celular registrado (${customerPhone})</b> para verificar tu pedido, resolver cualquier duda y coordinar la forma de pago (transferencia, tarjeta o contra entrega) y el despacho seguro de tus equipos.
                </p>
              </td>
            </tr>
          </table>

          <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 20px; font-size: 14px;">
            <thead>
              <tr style="background-color: #f8fafc;">
                <th style="padding: 10px; border-bottom: 2px solid #cbd5e1; text-align: left; color: #102c3d;">Producto</th>
                <th style="padding: 10px; border-bottom: 2px solid #cbd5e1; text-align: center; color: #102c3d;">Cant.</th>
                <th style="padding: 10px; border-bottom: 2px solid #cbd5e1; text-align: right; color: #102c3d;">Precio</th>
                <th style="padding: 10px; border-bottom: 2px solid #cbd5e1; text-align: right; color: #102c3d;">Total</th>
              </tr>
            </thead>
            <tbody>
              ${itemsHtml}
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" style="padding: 12px 10px 4px; text-align: right; color: #64748b;">Subtotal:</td>
                <td style="padding: 12px 10px 4px; text-align: right; font-weight: 600; color: #102c3d;">${moneyFormat(subtotal)}</td>
              </tr>
              <tr>
                <td colspan="3" style="padding: 4px 10px; text-align: right; color: #64748b;">Envío:</td>
                <td style="padding: 4px 10px; text-align: right; color: #0f8b44; font-weight: 600;">A coordinar con asesor</td>
              </tr>
              <tr>
                <td colspan="3" style="padding: 10px; text-align: right; font-size: 16px; font-weight: bold; color: #102c3d; border-top: 2px solid #cbd5e1;">Total a Pagar:</td>
                <td style="padding: 10px; text-align: right; font-size: 18px; font-weight: bold; color: #087ea4; border-top: 2px solid #cbd5e1;">${moneyFormat(total)}</td>
              </tr>
            </tfoot>
          </table>

          <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-size: 13px; line-height: 1.5; color: #334155;">
            <strong style="color: #102c3d; display: block; margin-bottom: 6px;">Datos de entrega y contacto registrados:</strong>
            <div><b>Nombre:</b> ${customerName}</div>
            <div><b>Teléfono / Celular:</b> ${customerPhone}</div>
            <div><b>Dirección:</b> ${shipping.address || 'No especificada'}, ${shipping.city || ''}</div>
            ${shipping.notes ? `<div><b>Notas:</b> ${shipping.notes}</div>` : ''}
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding: 20px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #94a3b8;">
          SmartISP · Equipamiento y Soluciones TI<br>
          Este comprobante electrónico es emitido para el respaldo de tu orden.
        </td>
      </tr>
    </table>
  </body>
  </html>
  `;
};

const buildAdminAlertEmail = ({ orderId, customerName, customerEmail, customerPhone, items, subtotal, total, shipping }) => {
  const cleanPhone = String(customerPhone || '').replace(/[^\d+]/g, '');
  const rawDigits = String(customerPhone || '').replace(/\D/g, '');
  const waUrl = rawDigits ? `https://wa.me/${rawDigits}?text=${encodeURIComponent(`Hola ${customerName}, te contactamos de SmartISP respecto a tu pedido #${orderId}.`)}` : null;

  const itemsList = items.map(item => `
    <tr>
      <td style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0;">${item.name} (${item.category || 'TI'})</td>
      <td style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align:center;"><b>${item.quantity}</b></td>
      <td style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align:right;">${moneyFormat(item.price * item.quantity)}</td>
    </tr>
  `).join('');

  return `
  <!DOCTYPE html>
  <html>
  <head><meta charset="UTF-8"></head>
  <body style="margin:0; padding:20px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; background-color:#f1f5f9; color:#0f172a;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; margin:0 auto; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #cbd5e1; box-shadow:0 8px 24px rgba(0,0,0,0.08);">
      <tr>
        <td style="padding: 20px 28px; background-color: #087ea4; color: #ffffff;">
          <div style="font-size: 12px; font-weight: bold; letter-spacing: 0.1em; text-transform: uppercase; opacity: 0.9;">Alerta Comercial · Nuevo Pedido</div>
          <h2 style="margin: 4px 0 0; font-size: 22px; color: #ffffff;">🚨 Pedido #${orderId} Requiere Asesor</h2>
        </td>
      </tr>
      <tr>
        <td style="padding: 28px;">
          <div style="background-color: #f0fdf4; border: 2px solid #86efac; border-radius: 10px; padding: 18px; margin-bottom: 24px;">
            <div style="color: #166534; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
              📞 NÚMERO DE CELULAR DEL USUARIO (REGISTRADO EN PERFIL)
            </div>
            <div style="font-size: 24px; font-weight: bold; color: #15803d; margin-bottom: 12px;">
              ${customerPhone || 'Sin teléfono registrado'}
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
              ${cleanPhone ? `<a href="tel:${cleanPhone}" style="display:inline-block; background:#087ea4; color:#ffffff; padding:9px 16px; border-radius:6px; font-weight:bold; font-size:13px; text-decoration:none;">📞 Llamar al cliente</a>` : ''}
              ${waUrl ? `<a href="${waUrl}" target="_blank" style="display:inline-block; background:#16a34a; color:#ffffff; padding:9px 16px; border-radius:6px; font-weight:bold; font-size:13px; text-decoration:none;">💬 Chatear por WhatsApp</a>` : ''}
            </div>
          </div>

          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 22px; font-size: 14px; background:#f8fafc; border-radius:8px; padding:12px; border:1px solid #e2e8f0;">
            <tr><td style="padding: 5px 0; width: 140px; color: #64748b;"><b>Cliente:</b></td><td style="color:#0f172a;">${customerName}</td></tr>
            <tr><td style="padding: 5px 0; color: #64748b;"><b>Correo:</b></td><td style="color:#0f172a;"><a href="mailto:${customerEmail}" style="color:#087ea4;">${customerEmail}</a></td></tr>
            <tr><td style="padding: 5px 0; color: #64748b;"><b>Dirección:</b></td><td style="color:#0f172a;">${shipping.address || 'No indicada'}, ${shipping.city || ''}</td></tr>
            ${shipping.notes ? `<tr><td style="padding: 5px 0; color: #64748b;"><b>Notas del pedido:</b></td><td style="color:#0f172a;">${shipping.notes}</td></tr>` : ''}
          </table>

          <h4 style="margin: 0 0 10px; color: #1e293b; font-size: 15px;">Detalle de la compra:</h4>
          <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 18px; font-size: 13.5px;">
            <thead>
              <tr style="background-color: #f1f5f9; color: #475569;">
                <th style="padding: 8px 10px; text-align: left;">Producto</th>
                <th style="padding: 8px 10px; text-align: center;">Cant.</th>
                <th style="padding: 8px 10px; text-align: right;">Total</th>
              </tr>
            </thead>
            <tbody>
              ${itemsList}
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2" style="padding: 10px; text-align: right; font-weight: bold; font-size: 15px; color: #0f172a; border-top: 2px solid #cbd5e1;">Monto Total:</td>
                <td style="padding: 10px; text-align: right; font-weight: bold; font-size: 16px; color: #087ea4; border-top: 2px solid #cbd5e1;">${moneyFormat(total)}</td>
              </tr>
            </tfoot>
          </table>

          <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; font-size: 13px; color: #92400e; border-radius: 4px;">
            👉 <b>Acción Requerida:</b> Un asesor comercial debe ponerse en contacto de inmediato al número de celular indicado para confirmar la compra, método de pago y logística de despacho.
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding: 16px 28px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #94a3b8;">
          SmartISP eCommerce Engine · Sistema de notificación a asesores
        </td>
      </tr>
    </table>
  </body>
  </html>
  `;
};

app.post('/api/auth/update-profile', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  const name = String(req.body.name || user.name).trim();
  const surname = String(req.body.surname || user.surname || '').trim();
  const phone = String(req.body.phone || user.phone || '').trim();
  user.name = name;
  user.surname = surname;
  user.phone = phone;
  if (pool) {
    await pool.query('UPDATE users SET name = $1, surname = $2, phone = $3 WHERE id = $4', [name, surname, phone, user.id]);
  }
  return res.json({ ok: true, user: publicUser(user) });
});

app.get('/api/auth/customer-orders', async (req, res) => {
  const user = await findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Debes iniciar sesión.' });
  return res.json({ orders: orders.get(user.id) || [] });
});

app.post('/api/auth/customer-orders', async (req, res) => {
  let user = await findUserById(req.session.userId);
  const shipping = req.body.shipping || {};
  const requestedItems = Array.isArray(req.body.items) ? req.body.items : [];

  if (!requestedItems.length) {
    return res.status(400).json({ error: 'El carrito está vacío.' });
  }

  // Determine customer contact info (prioritizing registered user profile phone)
  let customerName = String(shipping.name || '').trim();
  let customerEmail = String(shipping.email || '').trim().toLowerCase();
  let customerPhone = '';

  if (user) {
    if (shipping.phone && String(shipping.phone).trim()) {
      user.phone = String(shipping.phone).trim();
      customerPhone = user.phone;
      if (pool) {
        await pool.query('UPDATE users SET phone = $1 WHERE id = $2', [user.phone, user.id]);
      }
    } else {
      customerPhone = user.phone || '';
    }
    if (!customerName) customerName = [user.name, user.surname].filter(Boolean).join(' ');
    if (!customerEmail) customerEmail = user.email;
  } else {
    customerPhone = String(shipping.phone || '').trim();
    if (!customerEmail && shipping.email) customerEmail = shipping.email;
  }

  // Calculate items and total
  const items = requestedItems.map(item => ({
    id: String(item.id || item.name),
    name: String(item.name || 'Producto'),
    price: Number(item.price || 0),
    quantity: Math.max(1, Number(item.quantity || 1)),
    image: String(item.image || ''),
    category: String(item.category || '')
  }));

  const subtotal = items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
  const total = subtotal;
  const shortId = Date.now().toString().slice(-6).toUpperCase();
  const orderId = 'PED-' + shortId;

  const order = {
    id: orderId,
    shortId,
    userId: user ? user.id : 'guest',
    customerName,
    customerEmail,
    customerPhone,
    shipping,
    items,
    subtotal,
    total,
    status: 'received',
    createdAt: new Date().toISOString()
  };

  // Save order in memory
  const userOrderKey = user ? user.id : 'guest';
  orders.set(userOrderKey, [order, ...(orders.get(userOrderKey) || [])]);

  // Save order in Postgres if pool exists
  if (pool) {
    try {
      await pool.query(`CREATE TABLE IF NOT EXISTS orders (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        total NUMERIC(12, 2) NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'received',
        items JSONB NOT NULL DEFAULT '[]'::jsonb,
        shipping JSONB NOT NULL DEFAULT '{}'::jsonb,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      await pool.query(
        'INSERT INTO orders (id, user_id, total, status, items, shipping) VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb)',
        [order.id, order.userId, order.total, order.status, JSON.stringify({ items, subtotal, total }), JSON.stringify(shipping)]
      );
    } catch (err) {
      console.warn('Error guardando orden en PostgreSQL:', err.message);
    }
  }

  // 1. Send Receipt Email to Customer (Requirement 3)
  if (customerEmail) {
    const customerHtml = buildCustomerReceiptEmail({
      orderId,
      customerName,
      customerPhone,
      items,
      subtotal,
      total,
      shipping
    });
    sendEmail({
      to: customerEmail,
      subject: `🧾 Comprobante de Compra #${orderId} - SmartISP`,
      html: customerHtml,
      text: `¡Hola ${customerName}! Tu pedido #${orderId} por un total de ${moneyFormat(total)} ha sido recibido. Uno de nuestros asesores se contactará a tu celular (${customerPhone}) para coordinar pago y entrega.`
    }).catch(err => console.warn('Error al despachar correo al cliente:', err.message));
  }

  // 2. Send Notification Email to Admin / Company with User's Phone (Requirement 4)
  const adminRecipient = process.env.ADMIN_EMAIL || process.env.COMPANY_EMAIL || (demoUser ? demoUser.email : 'ventas@smartisp.com');
  const adminHtml = buildAdminAlertEmail({
    orderId,
    customerName,
    customerEmail,
    customerPhone,
    items,
    subtotal,
    total,
    shipping
  });
  sendEmail({
    to: adminRecipient,
    subject: `🚨 NUEVO PEDIDO #${orderId} - Asesoría Requerida: ${customerName}`,
    html: adminHtml,
    text: `NUEVO PEDIDO #${orderId}: Cliente ${customerName}, Celular de contacto: ${customerPhone}, Correo: ${customerEmail}, Total: ${moneyFormat(total)}. Un asesor debe contactarlo a la brevedad.`
  }).catch(err => console.warn('Error al despachar correo al administrador:', err.message));

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

app.get('/api/auth/proxy-image', async (req, res) => {
  const targetUrl = String(req.query?.url || '').trim();
  if (!targetUrl || !/^https?:\/\//i.test(targetUrl)) {
    return res.status(400).json({ error: 'URL de imagen no válida.' });
  }
  try {
    const response = await fetch(targetUrl, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
      }
    });
    if (!response.ok) {
      return res.status(response.status).json({ error: 'No se pudo descargar la imagen remota.' });
    }
    const contentType = response.headers.get('content-type') || 'image/jpeg';
    const buffer = Buffer.from(await response.arrayBuffer());
    res.setHeader('Content-Type', contentType);
    res.setHeader('Cache-Control', 'public, max-age=86400');
    res.setHeader('Access-Control-Allow-Origin', '*');
    return res.send(buffer);
  } catch (err) {
    return res.status(500).json({ error: 'Error al obtener la imagen: ' + err.message });
  }
});

await initializeDatabase();
app.listen(port, () => {
  console.log(`NexoTech disponible en http://localhost:${port}`);
});
