import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const sourceImage = 'C:/Users/Dhan/.gemini/antigravity/brain/9a42d29a-f497-4d7f-8299-55fbf5c57fe5/.user_uploaded/media_1790185214723.jpg';

const rootDir = path.resolve('.');
const publicDir = path.resolve('public');

if (!fs.existsSync(publicDir)) {
  fs.mkdirSync(publicDir, { recursive: true });
}

async function generate() {
  console.log('Generating favicons from source:', sourceImage);
  
  if (!fs.existsSync(sourceImage)) {
    throw new Error(`Source image not found at: ${sourceImage}`);
  }

  // 1. Generate individual PNG sizes
  const sizes = [
    { name: 'favicon-16x16.png', size: 16 },
    { name: 'favicon-32x32.png', size: 32 },
    { name: 'favicon-48x48.png', size: 48 }, // Google Search standard (multiple of 48)
    { name: 'favicon-96x96.png', size: 96 }, // Google Search standard & original resolution
    { name: 'favicon-144x144.png', size: 144 }, // Google Search standard (multiple of 48)
    { name: 'apple-touch-icon.png', size: 180 }, // iOS Safari
    { name: 'apple-touch-icon-precomposed.png', size: 180 },
    { name: 'favicon-192x192.png', size: 192 }, // Google Search & Android PWA (multiple of 48)
    { name: 'favicon-512x512.png', size: 512 }, // Google Search, Open Graph & PWA
    { name: 'favicon.png', size: 96 } // Standard fallback PNG
  ];

  const buffers = {};

  for (const item of sizes) {
    const buf = await sharp(sourceImage)
      .resize(item.size, item.size, {
        kernel: item.size > 96 ? sharp.kernel.lanczos3 : sharp.kernel.lanczos2,
        fit: 'contain',
        background: { r: 9, g: 26, b: 37, alpha: 1 } // Navy dark matching the logo
      })
      .png({ compressionLevel: 9 })
      .toBuffer();

    buffers[item.name] = buf;
    
    // Save to root and public
    fs.writeFileSync(path.join(rootDir, item.name), buf);
    fs.writeFileSync(path.join(publicDir, item.name), buf);
    console.log(`Generated: ${item.name} (${item.size}x${item.size})`);
  }

  // 2. Generate multi-resolution favicon.ico containing 16x16, 32x32, 48x48
  const icoSizes = [16, 32, 48];
  const icoPngs = await Promise.all(
    icoSizes.map(s => sharp(sourceImage).resize(s, s).png().toBuffer())
  );

  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0); // reserved
  header.writeUInt16LE(1, 2); // type 1 = icon
  header.writeUInt16LE(icoSizes.length, 4); // count

  let offset = 6 + (16 * icoSizes.length);
  const dirEntries = [];

  for (let i = 0; i < icoSizes.length; i++) {
    const s = icoSizes[i];
    const b = icoPngs[i];
    const entry = Buffer.alloc(16);
    entry.writeUInt8(s === 256 ? 0 : s, 0); // width
    entry.writeUInt8(s === 256 ? 0 : s, 1); // height
    entry.writeUInt8(0, 2); // color count
    entry.writeUInt8(0, 3); // reserved
    entry.writeUInt16LE(1, 4); // color planes
    entry.writeUInt16LE(32, 6); // bits per pixel
    entry.writeUInt32LE(b.length, 8); // size
    entry.writeUInt32LE(offset, 12); // offset
    dirEntries.push(entry);
    offset += b.length;
  }

  const icoBuffer = Buffer.concat([header, ...dirEntries, ...icoPngs]);
  fs.writeFileSync(path.join(rootDir, 'favicon.ico'), icoBuffer);
  fs.writeFileSync(path.join(publicDir, 'favicon.ico'), icoBuffer);
  console.log('Generated: favicon.ico (multi-resolution 16x16, 32x32, 48x48)');

  // 3. Generate site.webmanifest and manifest.json
  const manifestContent = JSON.stringify({
    name: 'SmartISP | Equipamiento & Infraestructura TI',
    short_name: 'SmartISP',
    description: 'Infraestructura TI, redes de fibra óptica, servidores y telecomunicaciones.',
    start_url: '/',
    display: 'standalone',
    background_color: '#091a25',
    theme_color: '#102c3d',
    icons: [
      {
        src: '/favicon-48x48.png',
        sizes: '48x48',
        type: 'image/png'
      },
      {
        src: '/favicon-96x96.png',
        sizes: '96x96',
        type: 'image/png'
      },
      {
        src: '/favicon-192x192.png',
        sizes: '192x192',
        type: 'image/png'
      },
      {
        src: '/favicon-512x512.png',
        sizes: '512x512',
        type: 'image/png'
      }
    ]
  }, null, 2);

  fs.writeFileSync(path.join(rootDir, 'site.webmanifest'), manifestContent);
  fs.writeFileSync(path.join(publicDir, 'site.webmanifest'), manifestContent);
  fs.writeFileSync(path.join(rootDir, 'manifest.json'), manifestContent);
  fs.writeFileSync(path.join(publicDir, 'manifest.json'), manifestContent);
  console.log('Generated: site.webmanifest and manifest.json');

  // 4. Generate robots.txt ensuring Googlebot and Googlebot-Image have full access to favicons
  const robotsContent = `# Robots.txt for SmartISP
User-agent: *
Allow: /
Allow: /favicon.ico
Allow: /favicon*.png
Allow: /apple-touch-icon*.png
Allow: /site.webmanifest
Allow: /manifest.json

Sitemap: https://smart-isp.com.ec/sitemap.xml
`;

  fs.writeFileSync(path.join(rootDir, 'robots.txt'), robotsContent);
  fs.writeFileSync(path.join(publicDir, 'robots.txt'), robotsContent);
  console.log('Generated: robots.txt with Googlebot favicon access permissions');

  console.log('All favicons and metadata generated successfully!');
}

generate().catch(err => {
  console.error('Error generating favicons:', err);
  process.exit(1);
});
