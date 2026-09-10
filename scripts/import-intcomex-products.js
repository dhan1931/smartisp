import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { parse } from 'csv-parse/sync';
import { Pool } from 'pg';

const defaultInputDirectory = 'e:/ale/gamedev/nose/output/intcomex_por_categorias/csv';
const inputDirectory = path.resolve(process.env.INTCOMEX_CSV_DIR || defaultInputDirectory);
const shouldWrite = process.argv.includes('--write');
const batchSize = 100;

const text = value => String(value ?? '').trim();
const normalizeUrl = value => text(value).replace(/^http:\/\//i, 'https://');
const extractProductId = value => {
  const match = text(value).match(/\/product\/detail\/(\d+)/i);
  return match?.[1] || '';
};

const productKey = row => {
  const productId = extractProductId(row['Enlace del Producto']);
  if (productId) return `intcomex:${productId}`;
  const sku = text(row['SKU / Código']).toUpperCase();
  return sku && sku !== 'N/D' ? `intcomex:sku:${sku}` : '';
};

const mapRow = row => {
  const key = productKey(row);
  if (!key) return null;
  const name = text(row['Nombre del Producto']);
  const category = text(row['Categoría']);
  if (!name || !category) return null;
  return {
    id: key,
    name,
    description: text(row['Descripción']) || `Producto Intcomex de la categoría ${category}.`,
    price: 0,
    category,
    imageUrl: normalizeUrl(row['URL de la Imagen']),
    externalUrl: normalizeUrl(row['Enlace del Producto']),
    sku: text(row['SKU / Código']).toUpperCase() === 'N/D' ? '' : text(row['SKU / Código'])
  };
};

const prefer = (current, candidate) => ({
  ...current,
  imageUrl: current.imageUrl || candidate.imageUrl,
  externalUrl: current.externalUrl || candidate.externalUrl,
  sku: current.sku || candidate.sku,
  description: current.description.length >= candidate.description.length ? current.description : candidate.description
});

const readProducts = async () => {
  const files = (await fs.readdir(inputDirectory)).filter(file => /^productos_.*\.csv$/i.test(file)).sort();
  const products = new Map();
  let rowsRead = 0;
  let rowsSkipped = 0;
  for (const file of files) {
    const content = await fs.readFile(path.join(inputDirectory, file), 'utf8');
    const rows = parse(content, { columns: true, bom: true, skip_empty_lines: true, relax_column_count: true, trim: true });
    rowsRead += rows.length;
    for (const row of rows) {
      const product = mapRow(row);
      if (!product) {
        rowsSkipped += 1;
        continue;
      }
      products.set(product.id, products.has(product.id) ? prefer(products.get(product.id), product) : product);
    }
  }
  return { files, products: [...products.values()], rowsRead, rowsSkipped };
};

const ensureSchema = async database => {
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
  ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT NOT NULL DEFAULT '';
  CREATE INDEX IF NOT EXISTS products_sku_idx ON products (sku);`);
};

const writeProducts = async products => {
  const configuredUrl = process.env.DATABASE_URL || process.env.DATABASE_POSTGRES_URL || process.env.POSTGRES_URL || process.env.POSTGRES_PRISMA_URL;
  if (!configuredUrl) throw new Error('Falta DATABASE_URL (o una URL PostgreSQL equivalente).');
  const database = new Pool({ connectionString: configuredUrl, ssl: { rejectUnauthorized: false } });
  try {
    await ensureSchema(database);
    for (let index = 0; index < products.length; index += batchSize) {
      const batch = products.slice(index, index + batchSize);
      await database.query('BEGIN');
      try {
        for (const product of batch) {
          await database.query(`INSERT INTO products (id, name, description, price, category, image_url, external_url, sku, visible, updated_at)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, TRUE, NOW())
            ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description,
            category = EXCLUDED.category, image_url = CASE WHEN EXCLUDED.image_url <> '' THEN EXCLUDED.image_url ELSE products.image_url END,
            external_url = EXCLUDED.external_url, sku = EXCLUDED.sku, updated_at = NOW()`,
          [product.id, product.name, product.description, product.price, product.category, product.imageUrl, product.externalUrl, product.sku]);
        }
        await database.query('COMMIT');
      } catch (error) {
        await database.query('ROLLBACK');
        throw error;
      }
    }
  } finally {
    await database.end();
  }
};

const run = async () => {
  const result = await readProducts();
  console.log(`Archivos: ${result.files.length}`);
  console.log(`Filas leídas: ${result.rowsRead}`);
  console.log(`Productos únicos: ${result.products.length}`);
  console.log(`Filas omitidas: ${result.rowsSkipped}`);
  console.log(`Con imagen: ${result.products.filter(product => product.imageUrl).length}`);
  console.log('Precio inicial: 0 (pendiente de cotización)');
  if (!shouldWrite) {
    console.log('Modo análisis: usa "npm run import:intcomex -- --write" para guardar en PostgreSQL.');
    return;
  }
  await writeProducts(result.products);
  console.log(`Importación completada: ${result.products.length} productos insertados/actualizados.`);
};

run().catch(error => {
  console.error(`Importación fallida: ${error.message}`);
  process.exitCode = 1;
});