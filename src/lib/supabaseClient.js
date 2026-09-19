import { createClient } from '@supabase/supabase-js';
import fs from 'node:fs';
import path from 'node:path';

/**
 * ===================================================================
 * CLIENTE REUTILIZABLE DE SUPABASE PARA SMARTISP (smart-isp.es)
 * ===================================================================
 * 
 * Lee las credenciales de Supabase inyectadas por el entorno de Hostinger,
 * Vercel o el archivo .env local.
 * 
 * Directrices de seguridad:
 * - Utiliza SUPABASE_URL y SUPABASE_ANON_KEY (con fallback a SUPABASE_KEY).
 * - Nunca utiliza ni expone SUPABASE_SERVICE_ROLE_KEY.
 * - No imprime valores de claves ni secretos en los logs del servidor.
 */

// Si no están en process.env, intentar cargar desde .env local si existe
if (!process.env.SUPABASE_URL) {
  try {
    const envPath = path.resolve(process.cwd(), '.env');
    if (fs.existsSync(envPath)) {
      const lines = fs.readFileSync(envPath, 'utf8').split('\n');
      for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line || line.startsWith('#')) continue;
        const eqIdx = line.indexOf('=');
        if (eqIdx !== -1) {
          const key = line.slice(0, eqIdx).trim();
          const value = line.slice(eqIdx + 1).trim().replace(/^["']|["']$/g, '');
          if (!process.env[key]) process.env[key] = value;
        }
      }
    }
  } catch {}
}

const supabaseUrl = (process.env.SUPABASE_URL || '').trim();
// Acepta SUPABASE_ANON_KEY con fallback a SUPABASE_KEY si Hostinger la inyecta con ese nombre
const supabaseAnonKey = (process.env.SUPABASE_ANON_KEY || process.env.SUPABASE_KEY || '').trim();

const missingVars = [];
if (!supabaseUrl) missingVars.push('SUPABASE_URL');
if (!supabaseAnonKey) missingVars.push('SUPABASE_ANON_KEY');

if (missingVars.length > 0) {
  console.warn(`⚠️ [Supabase] Configuración incompleta. Faltan las siguientes variables de entorno: ${missingVars.join(', ')}.`);
} else {
  console.log('✅ [Supabase] Variables de entorno detectadas correctamente.');
}

export const isSupabaseConfigured = () => Boolean(supabaseUrl && supabaseAnonKey);

export const supabase = isSupabaseConfigured()
  ? createClient(supabaseUrl, supabaseAnonKey, {
      auth: {
        persistSession: false,
        autoRefreshToken: false
      }
    })
  : null;

export const getSupabaseClient = () => {
  if (!supabase) {
    throw new Error('El cliente de Supabase no está inicializado. Verifique SUPABASE_URL y SUPABASE_ANON_KEY.');
  }
  return supabase;
};

export default supabase;

