import { existsSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const collect = (directory, extensions) => {
  try {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
      const file = path.join(directory, entry.name);
      if (entry.isDirectory()) return collect(file, extensions);
      return extensions.has(path.extname(entry.name)) ? [file] : [];
    });
  } catch (error) {
    if (error.code === 'ENOENT') return [];
    throw error;
  }
};

const javascriptFiles = collect('assets/js', new Set(['.js']));
const localPhp = path.join(process.env.USERPROFILE || '', 'tools', 'php83', 'php.exe');
const phpCommand = process.env.PHP_BINARY || (process.platform === 'win32' && existsSync(localPhp) ? localPhp : 'php');
const phpFiles = [
  ...collect('api', new Set(['.php'])),
  ...collect('scripts', new Set(['.php'])),
  ...collect('tests', new Set(['.php'])),
  ...readdirSync('.').filter(file => file.endsWith('.php'))
];

const checks = [
  ...javascriptFiles.map(file => ['node', ['--check', file], file]),
  ...phpFiles.map(file => [phpCommand, ['-l', file], file])
];

if (!javascriptFiles.length || !phpFiles.length) {
  throw new Error('No se encontraron archivos PHP/JS para validar.');
}

for (const [command, args, file] of checks) {
  const result = spawnSync(command, args, { encoding: 'utf8' });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    process.stderr.write(`${file}:\n${result.stdout}${result.stderr}`);
    process.exit(result.status || 1);
  }
}

console.log(`Sintaxis correcta: ${javascriptFiles.length} archivos JS y ${phpFiles.length} PHP.`);
