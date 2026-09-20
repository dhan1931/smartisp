import { createRequire } from 'module';
const require = createRequire(import.meta.url);

const { createClient } = require('@supabase/supabase-js');

const supabaseUrl = process.env.SUPABASE_URL;
const supabaseKey = process.env.SUPABASE_ANON_KEY || process.env.SUPABASE_KEY;
const supabase = (supabaseUrl && supabaseKey)
  ? createClient(supabaseUrl, supabaseKey)
  : null;

async function getData() {
  if (!supabase) {
    console.log('⚠️ Variables SUPABASE_URL o SUPABASE_ANON_KEY no detectadas en el entorno local.');
    return;
  }

  const { data, error } = await supabase
    .from('products')
    .select('*');

  if (error) {
    console.error('Error connecting to Supabase:', error);
    return;
  }

  console.log('Successfully connected to Supabase! Data:', data);
}

getData();

export { supabase };
export default supabase;
