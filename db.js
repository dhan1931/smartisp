import { supabase, isSupabaseConfigured, getSupabaseClient } from './src/lib/supabaseClient.js';

// Re-exporta el cliente modular para mantener compatibilidad con el verificador de Hostinger
export { supabase, isSupabaseConfigured, getSupabaseClient };
export default supabase;
