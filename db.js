import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { createClient } = require('@supabase/supabase-js');

// Hostinger inyectará automáticamente SUPABASE_URL y SUPABASE_KEY
const supabaseUrl = process.env.SUPABASE_URL || '';
const supabaseKey = process.env.SUPABASE_KEY || process.env.SUPABASE_ANON_KEY || '';

export const supabase = (supabaseUrl && supabaseKey)
  ? createClient(supabaseUrl, supabaseKey)
  : null;

async function checkConnection() {
  if (!supabase) {
    console.log('⚡ Esperando que Hostinger inyecte SUPABASE_URL y SUPABASE_KEY...');
    return;
  }
  try {
    const { data, error } = await supabase
      .from('products')
      .select('*')
      .limit(1);

    if (error) {
      console.log('⚠️ Conectado a Supabase, respuesta:', error.message);
    } else {
      console.log('✅ Conexión exitosa con Supabase desde Hostinger:', data);
    }
  } catch (err) {
    console.log('❌ Error al conectar con Supabase:', err.message);
  }
}

checkConnection();

export default supabase;
