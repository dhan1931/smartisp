import mysql from 'mysql2/promise';

let pool = null;

export const getMysqlPool = () => {
  if (pool) return pool;

  const host = process.env.MYSQL_HOST || process.env.DB_HOST || 'localhost';
  const port = Number(process.env.MYSQL_PORT || process.env.DB_PORT || 3306);
  const database = process.env.MYSQL_DATABASE || process.env.DB_NAME || 'u606699314_smart_isp';
  const user = process.env.MYSQL_USER || process.env.DB_USER || 'u606699314_dhan';
  const password = process.env.MYSQL_PASSWORD || process.env.DB_PASSWORD || 'Dhan193111';

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
    process.env.MYSQL_DATABASE || process.env.DB_NAME || 'u606699314_smart_isp'
  );
};

