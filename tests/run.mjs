// Optional offline runner: node tests/run.mjs /path/to/node_modules
// Packages: @php-wasm/universal@3.1.58 and @php-wasm/node-8-3@3.1.58.
// Test runtime is never included in the installable ZIP.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
const root = fileURLToPath(new URL('../', import.meta.url));
const modules = process.argv[2];
if (!modules) throw Error('Pass the test-only node_modules directory.');
const { PHP, loadPHPRuntime } = await import(pathToFileURL(path.join(modules, '@php-wasm/universal/index.js')));
const { getPHPLoaderModule } = await import(pathToFileURL(path.join(modules, '@php-wasm/node-8-3/index.js')));
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
function copy(source, target) {
  php.mkdir(target);
  for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
    if (entry.isDirectory()) copy(path.join(source, entry.name), target + '/' + entry.name);
    else if (entry.name.endsWith('.php')) php.writeFile(target + '/' + entry.name, fs.readFileSync(path.join(source, entry.name)));
  }
}
copy(path.join(root, 'modules'), '/modules');
copy(path.join(root, 'tests'), '/tests');
const response = await php.run({ scriptPath: '/tests/run.php' });
if (response.errors || response.exitCode) throw Error(response.errors + '\n' + response.text);
const report = JSON.parse(response.text);
fs.writeFileSync(path.join(root, 'tests/validation.json'), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
