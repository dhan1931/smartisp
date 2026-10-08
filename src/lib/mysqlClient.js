import mysql from 'mysql2/promise';

let pool = null;

export const getMysqlPool = () => {
  if (pool) return pool;

  const host = process.env.MYSQL_HOST || process.env.DB_HOST || '';
  const port = Number(process.env.MYSQL_PORT || process.env.DB_PORT || 3306);
  const database = process.env.MYSQL_DATABASE || process.env.DB_NAME || '';
  const user = process.env.MYSQL_USER || process.env.DB_USER || '';
  const password = process.env.MYSQL_PASSWORD || process.env.DB_PASSWORD || '';

  if (!host || !database || !user || !password) {
    console.warn('⚠️ Falta la configuración de MySQL: define MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER y MYSQL_PASSWORD (entorno o .env).');
    return null;
  }

  try {
    pool = mysql.createPool({
      host,
      port,
      database,
      user,
      password,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0,
      charset: 'utf8mb4'
    });
    return pool;
  } catch (err) {
    console.warn('⚠️ No se pudo inicializar el pool de MySQL:', err.message);
    return null;
  }
};

export const isMysqlConfigured = () => {
  return Boolean(
    (process.env.MYSQL_HOST || process.env.DB_HOST) &&
    (process.env.MYSQL_DATABASE || process.env.DB_NAME) &&
    (process.env.MYSQL_USER || process.env.DB_USER) &&
    (process.env.MYSQL_PASSWORD || process.env.DB_PASSWORD)
  );
};

