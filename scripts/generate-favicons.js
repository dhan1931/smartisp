import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

// Official high-resolution 1024x1024 master logo provided by user
const sourceImage = 'C:/Users/Dhan/.gemini/antigravity/brain/9a42d29a-f497-4d7f-8299-55fbf5c57fe5/.user_uploaded/media_1790567687952.jpg';

const rootDir = path.resolve('.');
const publicDir = path.resolve('public');
const imagesDir = path.resolve('public/images');

if (!fs.existsSync(publicDir)) {
  fs.mkdirSync(publicDir, { recursive: true });
}
if (!fs.existsSync(imagesDir)) {
  fs.mkdirSync(imagesDir, { recursive: true });
}

async function generate() {
  console.log('Generating favicons from high-res source:', sourceImage);
  
  if (!fs.existsSync(sourceImage)) {
    throw new Error(`Source image not found at: ${sourceImage}`);
  }

  // 1. Save master copies in the repository
  const master1024 = await sharp(sourceImage).png({ quality: 100 }).toBuffer();
  fs.writeFileSync(path.join(imagesDir, 'smartisp-logo-master.png'), master1024);
  fs.writeFileSync(path.join(publicDir, 'logo.png'), master1024);
  console.log('Saved master copies to public/images/smartisp-logo-master.png and public/logo.png');

  // 2. Create the circular transparent mask
  // The cyan glowing outer ring has its edge at radius 464, centered at (508, 508).
  // Everything outside is masked to 100% transparent with anti-aliased edge.
  const circleMask = await sharp(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024"><circle cx="508" cy="508" r="464" fill="white"/></svg>'))
    .resize(1024, 1024)
    .png()
    .toBuffer();

  const circularMaster = await sharp(sourceImage)
    .ensureAlpha()
    .composite([{ input: circleMask, blend: 'dest-in' }])
    .png({ compressionLevel: 9 })
    .toBuffer();

  fs.writeFileSync(path.join(imagesDir, 'smartisp-logo-circle.png'), circularMaster);
  fs.writeFileSync(path.join(publicDir, 'logo-circle.png'), circularMaster);
  console.log('Generated master circular transparent image');

  // 3. Generate individual favicon sizes
  const sizes = [
    { name: 'favicon-16x16.png', size: 16, sharpen: 0.6 },
    { name: 'favicon-32x32.png', size: 32, sharpen: 0.4 },
    { name: 'favicon-48x48.png', size: 48, sharpen: 0 },    // Primary Google Search standard (multiple of 48)
    { name: 'favicon-96x96.png', size: 96, sharpen: 0 },    // Google Search 2x (multiple of 48)
    { name: 'favicon-144x144.png', size: 144, sharpen: 0 }, // Google Search 3x (multiple of 48)
    { name: 'apple-touch-icon.png', size: 180, sharpen: 0 },// iOS Safari
    { name: 'apple-touch-icon-precomposed.png', size: 180, sharpen: 0 },
    { name: 'favicon-192x192.png', size: 192, sharpen: 0 }, // Google Search 4x & Android PWA
    { name: 'favicon-512x512.png', size: 512, sharpen: 0 }, // Google Search, Open Graph & PWA
    { name: 'favicon.png', size: 192, sharpen: 0 }          // Standard fallback PNG
  ];

  for (const item of sizes) {
    let pipeline = sharp(circularMaster)
      .resize(item.size, item.size, {
        kernel: sharp.kernel.lanczos3,
        fit: 'contain',
        background: { r: 0, g: 0, b: 0, alpha: 0 }
      });

    if (item.sharpen > 0) {
      pipeline = pipeline.sharpen({ sigma: item.sharpen });
    }

    const buf = await pipeline.png({ compressionLevel: 9 }).toBuffer();
    
    // Save to root and public
    fs.writeFileSync(path.join(rootDir, item.name), buf);
    fs.writeFileSync(path.join(publicDir, item.name), buf);
    console.log(`Generated: ${item.name} (${item.size}x${item.size})`);
  }

  // 4. Generate multi-resolution favicon.ico containing 16x16, 32x32, 48x48
  const icoSizes = [16, 32, 48];
  const icoPngs = await Promise.all(
    icoSizes.map(s => {
      let p = sharp(circularMaster).resize(s, s, { kernel: sharp.kernel.lanczos3 });
      if (s === 16) p = p.sharpen({ sigma: 0.6 });
      if (s === 32) p = p.sharpen({ sigma: 0.4 });
      return p.png().toBuffer();
    })
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

  // 5. Generate vector favicon.svg with embedded high-res circular logo
  const base64CirclePng = circularMaster.toString('base64');
  const svgContent = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="100%" height="100%">
  <image href="data:image/png;base64,${base64CirclePng}" width="1024" height="1024"/>
</svg>`;
  fs.writeFileSync(path.join(rootDir, 'favicon.svg'), svgContent);
  fs.writeFileSync(path.join(publicDir, 'favicon.svg'), svgContent);
  console.log('Generated: favicon.svg (vector wrapper for high-res rendering)');

  // 6. Generate site.webmanifest and manifest.json
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
        src: '/favicon-144x144.png',
        sizes: '144x144',
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

  // 7. Generate robots.txt ensuring Googlebot and Googlebot-Image have full access to favicons
  const robotsContent = `# Robots.txt for SmartISP
User-agent: *
Allow: /
Allow: /favicon.ico
Allow: /favicon.svg
Allow: /favicon*.png
Allow: /apple-touch-icon*.png
Allow: /site.webmanifest
Allow: /manifest.json

Sitemap: https://smart-isp.com.ec/sitemap.xml
`;

  fs.writeFileSync(path.join(rootDir, 'robots.txt'), robotsContent);
  fs.writeFileSync(path.join(publicDir, 'robots.txt'), robotsContent);
  console.log('Generated: robots.txt with Googlebot favicon access permissions');

  console.log('All favicons and metadata generated successfully from 1024x1024 source!');
}

generate().catch(err => {
  console.error('Error generating favicons:', err);
  process.exit(1);
});
