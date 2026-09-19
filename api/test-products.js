import { supabase } from '../src/lib/supabaseClient.js';

/**
 * Endpoint de diagnóstico para consultar la tabla real 'products' de Supabase
 * Compatible con despliegue serverless (Vercel) y Node.js
 */
export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  if (req.method !== 'GET') {
    return res.status(405).json({
      success: false,
      rowsFound: 0,
      error: 'Método no permitido. Solo se acepta GET.'
    });
  }

  if (!supabase) {
    return res.status(503).json({
      success: false,
      rowsFound: 0,
      error: 'Cliente de Supabase no configurado. Faltan variables de entorno SUPABASE_URL o SUPABASE_ANON_KEY.'
    });
  }

  try {
    // Consulta segura sobre la tabla existente 'products' sin concatenar SQL
    const { data, error } = await supabase
      .from('products')
      .select('id, name, price')
      .limit(100);

    if (error) {
      return res.status(500).json({
        success: false,
        rowsFound: 0,
        error: error.message || 'Error al consultar la tabla de productos'
      });
    }

    return res.status(200).json({
      success: true,
      rowsFound: Array.isArray(data) ? data.length : 0
    });
  } catch (err) {
    return res.status(500).json({
      success: false,
      rowsFound: 0,
      error: err.message || 'Error interno al consultar Supabase'
    });
  }
}
