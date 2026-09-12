import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';
import nodemailer from 'nodemailer';
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
  const duration = remember ? 1000 * 60 * 60 * 24 * 30 : 1000 * 60 * 60 * 24 * 7;
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
const hashResetToken = token => crypto.createHash('sha256').update(token).digest('hex');
const appUrl = req => String(process.env.APP_URL || `${req.headers['x-forwarded-proto'] || 'https'}://${req.headers.host || 'localhost'}`).replace(/\/$/, '');
const sendResetEmail = async (email, resetUrl) => { if (!process.env.RESEND_API_KEY || !process.env.EMAIL_FROM) throw new Error('El servicio de correo no está configurado.'); const response = await fetch('https://api.resend.com/emails', { method: 'POST', headers: { Authorization: `Bearer ${process.env.RESEND_API_KEY}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ from: process.env.EMAIL_FROM, to: [email], subject: 'Restablece tu contraseña de SmartISP', html: `<p>Recibimos una solicitud para cambiar tu contraseña.</p><p><a href="${resetUrl}">Cambiar contraseña</a></p><p>Este enlace caduca en 1 hora y solo puede utilizarse una vez.</p>` }) }); if (!response.ok) throw new Error('No se pudo enviar el correo de recuperación.'); };

const moneyFormat = value => '$' + Number(value || 0).toLocaleString('es-CL');

const getEmailConfig = async (database = null) => {
  const dbValues = {};
  if (database) {
    try {
      const res = await database.query(
        "SELECT content_key AS key, content_value AS value FROM site_content WHERE content_key LIKE 'smtp_%' OR content_key IN ('admin_email', 'resend_api_key', 'email_from')"
      );
      for (const row of res.rows) {
        dbValues[row.key] = row.value;
      }
    } catch (e) {}
  }

  const provider = dbValues.smtp_provider || 'gmail';
  let host = dbValues.smtp_host || process.env.SMTP_HOST;
  let port = dbValues.smtp_port || process.env.SMTP_PORT;
  let user = dbValues.smtp_user || process.env.SMTP_USER;
  let pass = dbValues.smtp_pass || process.env.SMTP_PASS;
  let secure = dbValues.smtp_secure !== undefined
    ? (dbValues.smtp_secure === 'true' || dbValues.smtp_secure === true)
    : (process.env.SMTP_SECURE === 'true' || Number(port || process.env.SMTP_PORT) === 465);
  let from = dbValues.smtp_from || process.env.SMTP_FROM || process.env.EMAIL_FROM;
  let adminEmail = dbValues.admin_email || process.env.ADMIN_EMAIL;
  let resendApiKey = dbValues.resend_api_key || process.env.RESEND_API_KEY;

  if (provider === 'gmail') {
    host = 'smtp.gmail.com';
    port = 465;
    secure = true;
  }

  if (!from && user) {
    from = `SmartISP <${user}>`;
  } else if (!from) {
    from = 'SmartISP <ventas@smartisp.com>';
  }

  return {
    provider,
    host: host || (provider === 'gmail' ? 'smtp.gmail.com' : ''),
    port: Number(port || (provider === 'gmail' ? 465 : 587)),
    secure,
    user: user ? String(user).trim() : '',
    pass: pass ? String(pass).replace(/\s+/g, '') : '',
    from,
    adminEmail,
    resendApiKey
  };
};

const sendEmailNotification = async ({ to, subject, html, text, database = null, config = null }) => {
  const cfg = config || await getEmailConfig(database);

  // 1. Resend
  if (cfg.resendApiKey && cfg.from) {
    try {
      const response = await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${cfg.resendApiKey}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          from: cfg.from,
          to: Array.isArray(to) ? to : [to],
          subject,
          html
        })
      });
      if (response.ok) {
        const data = await response.json().catch(() => ({}));
        console.log(`✅ [RESEND] Correo despachado exitosamente a: ${to} (ID: ${data.id || 'ok'})`);
        return { success: true, provider: 'resend', id: data.id };
      } else {
        const errText = await response.text();
        console.warn('Fallo al enviar correo mediante Resend:', errText);
      }
    } catch (err) {
      console.warn('Fallo al enviar correo mediante Resend:', err.message);
    }
  }

  // 2. SMTP Nodemailer (Gmail o Custom)
  if ((cfg.user && cfg.pass) || (cfg.host && cfg.user && cfg.pass)) {
    try {
      const isGmail = cfg.provider === 'gmail' || (cfg.host && cfg.host.includes('gmail.com')) || (cfg.user && cfg.user.includes('@gmail.com'));
      const transportOptions = isGmail ? {
        service: 'gmail',
        auth: {
          user: cfg.user,
          pass: cfg.pass
        }
      } : {
        host: cfg.host,
        port: cfg.port,
        secure: cfg.secure,
        auth: {
          user: cfg.user,
          pass: cfg.pass
        }
      };

      const transporter = nodemailer.createTransport(transportOptions);
      const info = await transporter.sendMail({
        from: cfg.from || (cfg.user ? `SmartISP <${cfg.user}>` : 'SmartISP <ventas@smartisp.com>'),
        to,
        subject,
        html,
        text
      });
      console.log(`✅ [SMTP ${isGmail ? 'Gmail' : cfg.host}] Correo despachado exitosamente a: ${to} - ID: ${info.messageId}`);
      return { success: true, provider: isGmail ? 'gmail' : 'smtp', messageId: info.messageId };
    } catch (err) {
      console.error(`❌ Error enviando correo vía SMTP a ${to}:`, err.message);
      throw err;
    }
  }

  // 3. Fallback: Log in console cleanly (mock)
  console.log('\n====================================================');
  console.log(`📨 [SIMULACIÓN CORREO ELECTRÓNICO - NO ENVIADO A LA BANDEJA REAL]`);
  console.log(`ℹ️ Para que los correos lleguen de verdad a la bandeja de entrada,`);
  console.log(`   configura tus credenciales de Gmail o SMTP en el panel de administrador.`);
  console.log(`Destinatario: ${to}`);
  console.log(`De: ${cfg.from}`);
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

const CATEGORY_DEFAULT_IMAGES = {
  switch: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  router: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  fibra: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  cable: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  servidor: 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=800&q=80',
  server: 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=800&q=80',
  red: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  antena: 'https://images.unsplash.com/photo-1516245834210-c4c142787335?auto=format&fit=crop&w=800&q=80',
  herramienta: 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=800&q=80',
  conector: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80',
  default: 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80'
};

const getCategoryFallbackImage = (category = '', query = '') => {
  const combined = (category + ' ' + query).toLowerCase();
  for (const [key, url] of Object.entries(CATEGORY_DEFAULT_IMAGES)) {
    if (key !== 'default' && combined.includes(key)) {
      return url;
    }
  }
  return CATEGORY_DEFAULT_IMAGES.default;
};

const cleanAmazonImageUrl = url => {
  if (!url) return '';
  return url
    .replace(/^http:\/\//i, 'https://')
    .replace(/\._[A-Z0-9,._-]+(?=\.[a-z]+$)/i, '');
};

const isAmazonUrl = url => {
  return /media-amazon\.com|images-amazon\.com|ssl-images-amazon\.com/i.test(url);
};

const searchWebImages = async (query, limit = 12, category = '', store = 'all') => {
  const rawQuery = String(query || '').trim();
  if (!rawQuery) return [];

  const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

  // 1. Limpieza rigurosa de texto
  const clean = rawQuery
    .replace(/["'(){}[\]<>*+?^$|\\]/g, ' ')
    .replace(/\b(envio gratis|garantia|oferta|nuevo|en caja|original|remate|promo|unidades|unid|pcs|kit)\b/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  // 2. Extraer marca y modelo / SKU
  const brandMatch = rawQuery.match(/\b(Cisco|Mikrotik|Ubiquiti|TP-Link|Huawei|D-Link|Tenda|ZTE|Nexxt|Panduit|Belden|Siemon|Furukawa|Hikvision|Dahua|Intel|AMD|Dell|HP|Lenovo|Grandstream|Fanvil|Yealink)\b/i);
  const modelMatch = rawQuery.match(/\b([A-Z0-9]{2,}-[A-Z0-9-]{2,}|[A-Z]{2,}\d{2,}[A-Z0-9-]*)\b/i);

  const words = clean.split(' ').filter(w => w.length > 1);
  const coreTitle = words.slice(0, 4).join(' ');

  // Variaciones de búsqueda inteligentes para tiendas
  const amazonQueries = [];
  if (brandMatch && modelMatch) amazonQueries.push(`${brandMatch[1]} ${modelMatch[1]}`);
  if (modelMatch) amazonQueries.push(modelMatch[1]);
  amazonQueries.push(coreTitle);
  if (clean !== coreTitle) amazonQueries.push(clean);

  const collectedImages = [];
  const seenUrls = new Set();

  const addImage = (url, thumb, title, src = 'web', storeName = 'Web') => {
    if (!url || !/^https?:\/\//i.test(url)) return;
    const finalUrl = isAmazonUrl(url) ? cleanAmazonImageUrl(url) : url.replace(/^http:\/\//i, 'https://');
    const finalThumb = thumb ? (isAmazonUrl(thumb) ? cleanAmazonImageUrl(thumb) : thumb.replace(/^http:\/\//i, 'https://')) : finalUrl;
    if (finalUrl.includes('transparent-pixel') || finalUrl.includes('nav-sprite')) return;
    if (seenUrls.has(finalUrl)) return;
    seenUrls.add(finalUrl);
    collectedImages.push({
      url: finalUrl,
      thumbnail: finalThumb,
      title: title || rawQuery,
      source: src,
      store: storeName
    });
  };

  // PASO 1: Scraper Directo en Amazon
  if (store === 'all' || store === 'amazon') {
    for (const q of amazonQueries.slice(0, 2)) {
      try {
        const amazonUrl = 'https://www.amazon.com/s?k=' + encodeURIComponent(q);
        const aRes = await fetch(amazonUrl, {
          headers: {
            'User-Agent': userAgent,
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'es-US,es;q=0.9,en-US;q=0.8,en;q=0.7'
          }
        });
        if (aRes.ok) {
          const html = await aRes.text();
          const regex = /https:\/\/m\.media-amazon\.com\/images\/I\/[A-Za-z0-9%_-]+\.(?:jpg|png)/gi;
          const matches = html.match(regex) || [];
          for (const m of matches) {
            addImage(m, m, `Amazon: ${q}`, 'amazon', 'Amazon');
            if (collectedImages.length >= limit) break;
          }
        }
      } catch (e) {}
      if (collectedImages.length >= limit) break;
    }
  }

  // PASO 2: Bing Images Scraper (con enfoque en Amazon y tiendas oficiales)
  const bingQueries = [];
  if (store === 'amazon') {
    for (const q of amazonQueries.slice(0, 2)) bingQueries.push(`amazon ${q}`);
  } else {
    for (const q of amazonQueries.slice(0, 2)) bingQueries.push(`amazon ${q}`);
    bingQueries.push(clean);
  }

  for (const bq of bingQueries) {
    if (collectedImages.length >= limit) break;
    try {
      const bingUrl = 'https://www.bing.com/images/search?q=' + encodeURIComponent(bq) + '&first=1&scenario=ImageBasicHover';
      const bingRes = await fetch(bingUrl, {
        headers: {
          'User-Agent': userAgent,
          'Accept-Language': 'es-ES,es;q=0.9,en;q=0.8'
        }
      });
      if (bingRes.ok) {
        const bingHtml = await bingRes.text();
        const regex = /murl&quot;:&quot;(https?:\/\/[^&]+)&quot;/g;
        let m;
        while ((m = regex.exec(bingHtml)) !== null && collectedImages.length < limit) {
          const imgUrl = m[1];
          const isAmz = isAmazonUrl(imgUrl);
          if (store === 'amazon' && !isAmz) continue;
          addImage(imgUrl, imgUrl, isAmz ? `Amazon: ${rawQuery}` : rawQuery, isAmz ? 'amazon' : 'bing', isAmz ? 'Amazon' : 'Web');
        }
      }
    } catch (e) {}
  }

  // PASO 3: Google Images Scraper
  if (collectedImages.length < limit && store !== 'amazon') {
    try {
      const gUrl = 'https://www.google.com/search?tbm=isch&q=' + encodeURIComponent(`amazon ${clean}`) + '&hl=es';
      const gRes = await fetch(gUrl, {
        headers: {
          'User-Agent': userAgent,
          'Accept-Language': 'es-ES,es;q=0.9'
        }
      });
      if (gRes.ok) {
        const gHtml = await gRes.text();
        const gRegex = /\["(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp))",\d+,\d+\]/gi;
        let gm;
        while ((gm = gRegex.exec(gHtml)) !== null && collectedImages.length < limit) {
          if (!gm[1].includes('gstatic.com') && !gm[1].includes('google.com')) {
            const isAmz = isAmazonUrl(gm[1]);
            addImage(gm[1], gm[1], isAmz ? `Amazon: ${rawQuery}` : rawQuery, isAmz ? 'amazon' : 'google', isAmz ? 'Amazon' : 'Web');
          }
        }
      }
    } catch (e) {}
  }

  // PASO 4: DuckDuckGo Scraper
  if (collectedImages.length < limit && store !== 'amazon') {
    try {
      const res1 = await fetch('https://duckduckgo.com/?q=' + encodeURIComponent(clean) + '&iax=images&ia=images', {
        headers: { 'User-Agent': userAgent }
      });
      const html = await res1.text();
      const match = /vqd=([0-9-]+)/.exec(html) || /vqd=(["'])(.*?)\1/.exec(html);
      const vqd = match ? (match[2] || match[1]) : null;
      if (vqd) {
        const res2 = await fetch('https://duckduckgo.com/i.js?l=es-es&o=json&q=' + encodeURIComponent(clean) + '&vqd=' + vqd, {
          headers: { 'User-Agent': userAgent }
        });
        const data = await res2.json();
        if (data.results && Array.isArray(data.results)) {
          for (const r of data.results) {
            if (r.image && /^https?:\/\//i.test(r.image)) {
              const isAmz = isAmazonUrl(r.image);
              addImage(r.image, r.thumbnail || r.image, r.title || rawQuery, isAmz ? 'amazon' : 'duckduckgo', isAmz ? 'Amazon' : 'Web');
              if (collectedImages.length >= limit) break;
            }
          }
        }
      }
    } catch (e) {}
  }

  // PASO 5: Wikimedia Commons (Infraestructura de redes)
  if (collectedImages.length < limit && store !== 'amazon') {
    try {
      const wikiUrl = `https://en.wikipedia.org/w/api.php?action=query&format=json&prop=pageimages&generator=search&gsrsearch=${encodeURIComponent(coreTitle)}&gsrlimit=6&piprop=thumbnail|original&pithumbsize=600`;
      const wikiRes = await fetch(wikiUrl);
      const wikiData = await wikiRes.json();
      const pages = Object.values(wikiData?.query?.pages || {});
      for (const p of pages) {
        const url = p.original?.source || p.thumbnail?.source;
        if (url) addImage(url, url, rawQuery, 'wiki', 'Wiki');
        if (collectedImages.length >= limit) break;
      }
    } catch (e) {}
  }

  // Si encontramos imágenes, priorizamos Amazon y devolvemos la lista
  if (collectedImages.length > 0) {
    collectedImages.sort((a, b) => {
      if (a.store === 'Amazon' && b.store !== 'Amazon') return -1;
      if (b.store === 'Amazon' && a.store !== 'Amazon') return 1;
      return 0;
    });
    return collectedImages.slice(0, limit);
  }

  // Fallback Temático Garantizado
  const fallbackUrl = getCategoryFallbackImage(category, rawQuery);
  return [{
    url: fallbackUrl,
    thumbnail: fallbackUrl,
    title: rawQuery,
    source: 'fallback',
    store: 'Catálogo'
  }];
};

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

const ensureCustomerTables = async database => {
  await database.query(`CREATE TABLE IF NOT EXISTS wishlists (
    user_id TEXT NOT NULL,
    product_id TEXT NOT NULL,
    product_name TEXT NOT NULL DEFAULT '',
    product_price NUMERIC(12, 2) NOT NULL DEFAULT 0,
    product_image TEXT NOT NULL DEFAULT '',
    product_category TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, product_id)
  );
  CREATE TABLE IF NOT EXISTS orders (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    total NUMERIC(12, 2) NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'received',
    items JSONB NOT NULL DEFAULT '[]'::jsonb,
    shipping JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    payment_status TEXT NOT NULL DEFAULT 'pending',
    payment_provider TEXT
  ); ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS position INTEGER NOT NULL DEFAULT 0;`);
  await database.query(`ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
    ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status TEXT NOT NULL DEFAULT 'pending';
    ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_provider TEXT;
    ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping JSONB NOT NULL DEFAULT '{}'::jsonb;`);
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

    if (action === 'change-password' && req.method === 'POST') {
      const userId = getSessionUserId(req);
      if (!userId) return res.status(401).json({ error: 'Debes iniciar sesión.' });
      const body = bodyOf(req);
      const currentPassword = String(body.currentPassword || '');
      const newPassword = String(body.newPassword || '');
      const confirmation = String(body.confirmation || '');
      if (newPassword.length < 8) return res.status(400).json({ error: 'La nueva contraseña debe tener al menos 8 caracteres.' });
      if (newPassword !== confirmation) return res.status(400).json({ error: 'La confirmación no coincide con la nueva contraseña.' });
      const result = await database.query('SELECT password_hash AS "passwordHash" FROM users WHERE id = $1 LIMIT 1', [userId]);
      if (!result.rows[0] || !(await bcrypt.compare(currentPassword, result.rows[0].passwordHash))) return res.status(401).json({ error: 'La contraseña actual no es correcta.' });
      await database.query('UPDATE users SET password_hash = $1 WHERE id = $2', [await bcrypt.hash(newPassword, 12), userId]);
      return res.status(200).json({ ok: true });
    }

    if (action === 'request-password-reset' && req.method === 'POST') {
      const email = String(bodyOf(req).email || '').trim().toLowerCase();
      const result = await database.query('SELECT id, email FROM users WHERE email = $1 LIMIT 1', [email]);
      if (!result.rows[0]) return res.status(200).json({ ok: true });
      await database.query(`CREATE TABLE IF NOT EXISTS password_resets (
        token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL, expires_at TIMESTAMPTZ NOT NULL, used_at TIMESTAMPTZ
      )`);
      await database.query('DELETE FROM password_resets WHERE user_id = $1 OR expires_at < NOW()', [result.rows[0].id]);
      const token = crypto.randomBytes(32).toString('hex');
      await database.query('INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES ($1, $2, NOW() + INTERVAL \'1 hour\')', [hashResetToken(token), result.rows[0].id]);
      await sendResetEmail(result.rows[0].email, `${appUrl(req)}/reset-password.html?token=${encodeURIComponent(token)}`);
      return res.status(200).json({ ok: true });
    }

    if (action === 'reset-password' && req.method === 'POST') {
      const body = bodyOf(req);
      const token = String(body.token || '');
      const password = String(body.password || '');
      const confirmation = String(body.confirmation || '');
      if (password.length < 8) return res.status(400).json({ error: 'La contraseña debe tener al menos 8 caracteres.' });
      if (password !== confirmation) return res.status(400).json({ error: 'Las contraseñas no coinciden.' });
      await database.query(`CREATE TABLE IF NOT EXISTS password_resets (
        token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL, expires_at TIMESTAMPTZ NOT NULL, used_at TIMESTAMPTZ
      )`);
      const reset = await database.query('SELECT user_id FROM password_resets WHERE token_hash = $1 AND used_at IS NULL AND expires_at > NOW() LIMIT 1', [hashResetToken(token)]);
      if (!reset.rows[0]) return res.status(400).json({ error: 'El enlace no es válido o ya expiró.' });
      await database.query('UPDATE users SET password_hash = $1 WHERE id = $2', [await bcrypt.hash(password, 12), reset.rows[0].user_id]);
      await database.query('UPDATE password_resets SET used_at = NOW() WHERE token_hash = $1', [hashResetToken(token)]);
      return res.status(200).json({ ok: true });
    }

    if (action === 'customer-wishlist' && (req.method === 'GET' || req.method === 'POST' || req.method === 'DELETE')) {
      const userId = getSessionUserId(req);
      if (!userId) return res.status(401).json({ error: 'Debes iniciar sesión.' });
      await ensureCustomerTables(database);
      if (req.method === 'GET') {
        const result = await database.query('SELECT product_id AS "productId", product_name AS name, product_price AS price, product_image AS image, product_category AS category FROM wishlists WHERE user_id = $1 ORDER BY position ASC, created_at DESC', [userId]);
        return res.status(200).json({ wishlist: result.rows });
      }
      const body = bodyOf(req);
      if (req.method === 'POST' && Array.isArray(body.order)) {
        for (const [position, productId] of body.order.entries()) await database.query('UPDATE wishlists SET position = $1 WHERE user_id = $2 AND product_id = $3', [position, userId, String(productId)]);
        return res.status(200).json({ saved: true });
      }
      const productId = String(req.query?.productId || body.productId || body.id || '').trim();
      if (!productId) return res.status(400).json({ error: 'El producto no es válido.' });
      if (req.method === 'DELETE') {
        await database.query('DELETE FROM wishlists WHERE user_id = $1 AND product_id = $2', [userId, productId]);
        return res.status(200).json({ saved: false });
      }
      await database.query(`INSERT INTO wishlists (user_id, product_id, product_name, product_price, product_image, product_category, position)
        VALUES ($1, $2, $3, $4, $5, $6, COALESCE((SELECT MAX(position) + 1 FROM wishlists WHERE user_id = $1), 0)) ON CONFLICT (user_id, product_id) DO UPDATE SET product_name = EXCLUDED.product_name,
        product_price = EXCLUDED.product_price, product_image = EXCLUDED.product_image, product_category = EXCLUDED.product_category`,
        [userId, productId, String(body.name || ''), Number(body.price || 0), String(body.image || ''), String(body.category || '')]);
      return res.status(200).json({ saved: true });
    }

    if (action === 'update-profile' && req.method === 'POST') {
      const userId = getSessionUserId(req);
      if (!userId) return res.status(401).json({ error: 'Debes iniciar sesión.' });
      const body = bodyOf(req);
      const name = String(body.name || '').trim();
      const surname = String(body.surname || '').trim();
      const phone = String(body.phone || '').trim();
      const email = body.email ? String(body.email).trim().toLowerCase() : '';

      if (email) {
        const existing = await database.query('SELECT id FROM users WHERE email = $1 AND id <> $2 LIMIT 1', [email, userId]);
        if (existing.rows[0]) {
          return res.status(409).json({ error: 'Ese correo ya está registrado por otro usuario.' });
        }
        await database.query('UPDATE users SET name = $1, surname = $2, phone = $3, email = $4 WHERE id = $5', [name, surname, phone, email, userId]);
      } else {
        await database.query('UPDATE users SET name = $1, surname = $2, phone = $3 WHERE id = $4', [name, surname, phone, userId]);
      }
      const result = await database.query('SELECT id, email, name, surname, phone, role FROM users WHERE id = $1 LIMIT 1', [userId]);
      return res.status(200).json({ ok: true, user: publicUser(result.rows[0]) });
    }

    if (action === 'customer-orders' && (req.method === 'GET' || req.method === 'POST')) {
      const userId = getSessionUserId(req);
      await ensureCustomerTables(database);
      if (req.method === 'GET') {
        if (!userId) return res.status(401).json({ error: 'Debes iniciar sesión.' });
        const result = await database.query('SELECT id, total, status, items, created_at AS "createdAt" FROM orders WHERE user_id = $1 ORDER BY created_at DESC', [userId]);
        return res.status(200).json({ orders: result.rows });
      }
      const body = bodyOf(req);
      const shippingDetails = {
        name: String(body.shipping?.name || '').trim().slice(0, 100),
        email: String(body.shipping?.email || '').trim().toLowerCase().slice(0, 160),
        phone: String(body.shipping?.phone || '').trim().slice(0, 30),
        address: String(body.shipping?.address || '').trim().slice(0, 200),
        city: String(body.shipping?.city || '').trim().slice(0, 80),
        notes: String(body.shipping?.notes || '').trim().slice(0, 300)
      };
      if (!shippingDetails.name || !shippingDetails.email.includes('@') || shippingDetails.phone.length < 7 || !shippingDetails.address || !shippingDetails.city) {
        return res.status(400).json({ error: 'Completa los datos de entrega.' });
      }
      const requestedItems = Array.isArray(body.items) ? body.items.slice(0, 50) : [];
      if (!requestedItems.length) return res.status(400).json({ error: 'El carrito está vacío.' });

      let customerPhone = shippingDetails.phone;
      let customerName = shippingDetails.name;
      let customerEmail = shippingDetails.email;
      let profileEmail = '';

      if (userId) {
        const userRes = await database.query('SELECT id, name, surname, email, phone FROM users WHERE id = $1 LIMIT 1', [userId]);
        if (userRes.rows[0]) {
          const user = userRes.rows[0];
          profileEmail = user.email ? String(user.email).trim().toLowerCase() : '';
          if (shippingDetails.phone && shippingDetails.phone !== user.phone) {
            await database.query('UPDATE users SET phone = $1 WHERE id = $2', [shippingDetails.phone, userId]);
            customerPhone = shippingDetails.phone;
          } else {
            customerPhone = user.phone || shippingDetails.phone;
          }
          if (!customerName) customerName = [user.name, user.surname].filter(Boolean).join(' ');
          if (!customerEmail) customerEmail = profileEmail;
        }
      } else if (shippingDetails.email) {
        // Look up if shipping email belongs to a registered customer
        const userRes = await database.query('SELECT id, name, surname, email, phone FROM users WHERE email = $1 LIMIT 1', [shippingDetails.email.toLowerCase()]);
        if (userRes.rows[0]) {
          profileEmail = userRes.rows[0].email ? String(userRes.rows[0].email).trim().toLowerCase() : '';
          if (!customerPhone) customerPhone = userRes.rows[0].phone || shippingDetails.phone;
        }
      }

      const items = requestedItems.map(item => ({
        id: String(item.id || item.name),
        name: String(item.name || 'Producto'),
        price: Number(item.price || 0),
        quantity: Math.max(1, Number(item.quantity || 1)),
        image: String(item.image || item.imageUrl || ''),
        category: String(item.category || '')
      }));

      const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
      const total = subtotal;
      const shortId = Date.now().toString().slice(-6).toUpperCase();
      const orderId = 'PED-' + shortId;

      await database.query('INSERT INTO orders (id, user_id, total, status, items, shipping, payment_status) VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb, $7)', [orderId, userId || 'guest', total, 'received', JSON.stringify({ subtotal, total, items }), JSON.stringify(shippingDetails), 'pending']);

      // 1. Send receipt email to customer AND to user profile email (TAMBIÉN al correo del perfil)
      const customerRecipients = new Set();
      if (customerEmail && customerEmail.includes('@')) customerRecipients.add(customerEmail);
      if (profileEmail && profileEmail.includes('@')) customerRecipients.add(profileEmail);

      if (customerRecipients.size > 0) {
        const customerHtml = buildCustomerReceiptEmail({
          orderId,
          customerName,
          customerPhone,
          items,
          subtotal,
          total,
          shipping: shippingDetails
        });

        for (const recipient of customerRecipients) {
          sendEmailNotification({
            to: recipient,
            subject: `🧾 Comprobante de Compra #${orderId} - SmartISP`,
            html: customerHtml,
            text: `¡Hola ${customerName}! Tu pedido #${orderId} por un total de ${moneyFormat(total)} ha sido recibido. Uno de nuestros asesores se contactará a tu celular (${customerPhone}) para coordinar pago y entrega.`,
            database
          }).catch(err => console.warn(`Error al despachar correo a ${recipient}:`, err.message));
        }
      }

      // 2. Send alert email to admin with phone
      let adminRecipient = '';
      try {
        const contentRes = await database.query("SELECT content_value FROM site_content WHERE content_key = 'admin_email' LIMIT 1");
        if (contentRes.rows[0]?.content_value) {
          adminRecipient = String(contentRes.rows[0].content_value).trim();
        }
      } catch (err) {}
      if (!adminRecipient) {
        adminRecipient = process.env.ADMIN_EMAIL || process.env.COMPANY_EMAIL || 'ventas@smartisp.com';
      }
      const adminHtml = buildAdminAlertEmail({
        orderId,
        customerName,
        customerEmail,
        customerPhone,
        items,
        subtotal,
        total,
        shipping: shippingDetails
      });
      sendEmailNotification({
        to: adminRecipient,
        subject: `🚨 NUEVO PEDIDO #${orderId} - Asesoría Requerida: ${customerName}`,
        html: adminHtml,
        text: `NUEVO PEDIDO #${orderId}: Cliente ${customerName}, Celular de contacto: ${customerPhone}, Correo: ${customerEmail}, Total: ${moneyFormat(total)}. Un asesor debe contactarlo a la brevedad.`,
        database
      }).catch(err => console.warn('Error al despachar correo al administrador:', err.message));

      return res.status(201).json({ id: orderId, shortId, subtotal, total, status: 'received' });
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
        external_url TEXT NOT NULL DEFAULT '',
        sku TEXT NOT NULL DEFAULT '',
        visible BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      );
      ALTER TABLE products ADD COLUMN IF NOT EXISTS external_url TEXT NOT NULL DEFAULT '';
      ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT NOT NULL DEFAULT ''`);
      if (req.method === 'GET') {
        const result = await database.query('SELECT id, name, description, price, category, image_url AS "imageUrl", external_url AS "externalUrl", sku, visible FROM products ORDER BY created_at DESC');
        return res.status(200).json({ products: result.rows });
      }
      if (req.method === 'DELETE') {
        const id = String(req.query?.id || '');
        await database.query('DELETE FROM products WHERE id = $1', [id]);
        return res.status(200).json({ ok: true });
      }
      const product = bodyOf(req);
      const id = String(product.id || crypto.randomUUID());
      await database.query(`INSERT INTO products (id, name, description, price, category, image_url, external_url, sku, visible, updated_at)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
        ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, price = EXCLUDED.price,
        category = EXCLUDED.category, image_url = EXCLUDED.image_url, external_url = EXCLUDED.external_url, sku = EXCLUDED.sku,
        visible = EXCLUDED.visible, updated_at = NOW()`,
        [id, String(product.name || '').trim(), String(product.description || '').trim(), Number(product.price || 0), String(product.category || '').trim(), String(product.imageUrl || '').trim(), String(product.externalUrl || '').trim(), String(product.sku || '').trim(), product.visible !== false]);
      return res.status(200).json({ ok: true, id });
    }

    if (action === 'admin-products-bulk' && req.method === 'POST') {
      if (!await requireAdmin(req, res, database)) return;
      await database.query(`CREATE TABLE IF NOT EXISTS products (
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

      const body = bodyOf(req);
      const rawItems = Array.isArray(body.products) ? body.products : [];
      if (!rawItems.length) return res.status(400).json({ error: 'No se recibieron productos para importar.' });

      // Deduplicación estricta por nombre exacto en el lote, priorizando productos con imagen
      const uniqueBatchMap = new Map();
      for (const raw of rawItems) {
        const name = String(raw.name || '').trim();
        if (!name) continue;
        const img = String(raw.imageUrl || raw.image_url || '').trim();
        if (uniqueBatchMap.has(name)) {
          const existing = uniqueBatchMap.get(name);
          // REGLA: Si dos productos repetidos vienen, uno con imagen y el otro sin imagen, prioridad al que tiene imagen
          if (!existing.imageUrl && img) {
            existing.imageUrl = img;
          }
          if ((!existing.price || Number(existing.price) <= 0) && Number(raw.price) > 0) {
            existing.price = Number(raw.price);
          }
          if (!existing.description && raw.description) {
            existing.description = String(raw.description).trim();
          }
          if (!existing.sku && raw.sku) {
            existing.sku = String(raw.sku).trim();
          }
        } else {
          uniqueBatchMap.set(name, {
            ...raw,
            name,
            imageUrl: img
          });
        }
      }

      const items = Array.from(uniqueBatchMap.values());
      let count = 0;

      await database.query('BEGIN');
      try {
        for (const item of items) {
          const name = String(item.name || '').trim();
          if (!name) continue;

          // Comprobar si ya existe un producto con el mismo nombre idéntico en base de datos
          const existingRow = await database.query('SELECT id, image_url, description, price, sku FROM products WHERE name = $1 LIMIT 1', [name]);
          let id = '';
          let imageUrl = String(item.imageUrl || item.image_url || '').trim();
          let description = String(item.description || '').trim();
          const parsedPrice = Number(item.price);
          let price = !isNaN(parsedPrice) && parsedPrice > 0 ? parsedPrice : 0;
          let sku = String(item.sku || '').trim();

          if (existingRow.rows && existingRow.rows.length > 0) {
            id = existingRow.rows[0].id;
            if (!imageUrl && existingRow.rows[0].image_url) {
              imageUrl = existingRow.rows[0].image_url;
            }
            if (!price && existingRow.rows[0].price) {
              price = Number(existingRow.rows[0].price);
            }
            if (!description && existingRow.rows[0].description) {
              description = existingRow.rows[0].description;
            }
            if (!sku && existingRow.rows[0].sku) {
              sku = existingRow.rows[0].sku;
            }
          } else {
            id = String(item.id || (sku ? `sku:${sku.toUpperCase()}` : crypto.randomUUID()));
          }

          const category = String(item.category || 'General').trim();
          const externalUrl = String(item.externalUrl || item.external_url || '').trim();
          const visible = item.visible !== false;

          await database.query(`INSERT INTO products (id, name, description, price, category, image_url, external_url, sku, visible, updated_at)
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
        await database.query('COMMIT');
      } catch (err) {
        await database.query('ROLLBACK');
        throw err;
      }
      return res.status(200).json({ ok: true, count });
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

    if (action === 'test-email' && req.method === 'POST') {
      if (!await requireAdmin(req, res, database)) return;
      const body = bodyOf(req);
      const targetEmail = String(body.to || '').trim();
      const cfg = await getEmailConfig(database);

      // Allow immediate testing with credentials passed in request body
      if (body.smtp_user) cfg.user = String(body.smtp_user).trim();
      if (body.smtp_pass) cfg.pass = String(body.smtp_pass).replace(/\s+/g, '');
      if (body.smtp_host) cfg.host = String(body.smtp_host).trim();
      if (body.smtp_port) cfg.port = Number(body.smtp_port);
      if (body.resend_api_key) cfg.resendApiKey = String(body.resend_api_key).trim();
      if (body.smtp_from || body.email_from) cfg.from = String(body.smtp_from || body.email_from).trim();
      if (!cfg.from && cfg.user) cfg.from = `SmartISP <${cfg.user}>`;

      const recipient = targetEmail || cfg.adminEmail || process.env.ADMIN_EMAIL || '';

      if (!recipient || !recipient.includes('@')) {
        return res.status(400).json({ error: 'Debes ingresar un correo de destino válido para la prueba.' });
      }

      if (!cfg.user && !cfg.resendApiKey) {
        return res.status(400).json({
          error: 'No has ingresado credenciales emisoras. Escribe tu correo de Gmail y Contraseña de Aplicación de 16 letras.'
        });
      }

      try {
        const result = await sendEmailNotification({
          to: recipient,
          subject: '🧪 Prueba Exitosa de Correo - SmartISP',
          html: `
            <div style="font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; padding: 24px; max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #d8e5e7; border-radius: 12px; color: #163342;">
              <div style="background: #102c3d; padding: 18px 24px; border-radius: 8px; margin-bottom: 20px;">
                <span style="font-size: 22px; font-weight: bold; color: #ffffff;">smart<span style="color:#087ea4">isp</span><span style="color:#f5a524">.</span></span>
              </div>
              <h2 style="color: #087ea4; margin: 0 0 12px; font-size: 20px;">✅ ¡Conexión de Correo Exitosa!</h2>
              <p style="font-size: 14.5px; line-height: 1.5; color: #334155; margin: 0 0 18px;">
                Este es un correo real de prueba despachado directamente desde el servidor de <strong>SmartISP</strong>.
              </p>
              <div style="background: #eaf7f5; border-left: 4px solid #087ea4; padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 13.5px; color: #102c3d; line-height: 1.6;">
                <div><b>Proveedor emisor:</b> ${cfg.provider === 'gmail' ? 'Gmail SMTP (smtp.gmail.com)' : (cfg.provider === 'resend' ? 'Resend API' : `SMTP Personalizado (${cfg.host})`)}</div>
                <div><b>Cuenta remitente:</b> ${cfg.from}</div>
                <div><b>Destinatario verificado:</b> ${recipient}</div>
                <div><b>Fecha y hora:</b> ${new Date().toLocaleString('es-CL')}</div>
              </div>
              <p style="font-size: 13.5px; color: #475569; line-height: 1.5; margin: 0 0 16px;">
                ¡Tu tienda está 100% lista! A partir de ahora, cuando cualquier cliente complete una compra, recibirá su comprobante oficial en su correo y el administrador recibirá la notificación con el número de celular del cliente.
              </p>
              <div style="border-top: 1px solid #e2e8f0; padding-top: 14px; font-size: 12px; color: #94a3b8; text-align: center;">
                SmartISP eCommerce Engine · Notificación de sistema
              </div>
            </div>
          `,
          text: `Conexión Exitosa de SmartISP. El servicio de correo está funcionando correctamente hacia ${recipient}.`,
          database,
          config: cfg
        });

        if (result.provider === 'mock') {
          return res.status(400).json({
            error: 'El servidor está en modo simulación (mock). Configura tus credenciales reales de Gmail o SMTP para enviar correos.'
          });
        }

        return res.status(200).json({
          ok: true,
          provider: result.provider,
          recipient,
          message: `¡Correo de prueba enviado con éxito a ${recipient}! Revisa tu bandeja de entrada o spam.`
        });
      } catch (err) {
        let msg = err.message || 'Error desconocido al enviar correo';
        if (msg.includes('Username and Password not accepted') || msg.includes('Invalid login') || msg.includes('BadCredentials')) {
          msg = 'Google rechazó la contraseña. Recuerda generar y usar una "Contraseña de aplicación" de 16 letras en myaccount.google.com/apppasswords (no uses tu contraseña normal de Google).';
        } else if (msg.includes('ETIMEDOUT') || msg.includes('ECONNREFUSED')) {
          msg = `No se pudo conectar al servidor de correo (${cfg.host}:${cfg.port}). Verifica el host y el puerto.`;
        }
        return res.status(500).json({ error: msg });
      }
    }

    if (action === 'catalog' && req.method === 'GET') {
      await database.query(`CREATE TABLE IF NOT EXISTS products (
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
      ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT NOT NULL DEFAULT ''`);
      await database.query(`CREATE TABLE IF NOT EXISTS site_content (
        content_key TEXT PRIMARY KEY,
        content_value TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      )`);
      const [products, content] = await Promise.all([
        database.query('SELECT id, name, description, price, category, image_url AS "imageUrl", external_url AS "externalUrl", sku FROM products WHERE visible = TRUE ORDER BY created_at DESC'),
        database.query('SELECT content_key AS "key", content_value AS value FROM site_content')
      ]);
      return res.status(200).json({ products: products.rows, content: content.rows });
    }

    if (action === 'search-product-image' && req.method === 'GET') {
      const query = String(req.query?.q || '').trim();
      const category = String(req.query?.category || '').trim();
      const store = String(req.query?.store || 'all').trim().toLowerCase();
      const limit = Math.min(24, Math.max(1, Number(req.query?.limit || 12)));
      const images = await searchWebImages(query, limit, category, store);
      return res.status(200).json({ images });
    }

    if (action === 'proxy-image' && req.method === 'GET') {
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
