import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const required = [
  'public/pdf-viewer.html',
  'public/js/pdf-viewer.mjs',
  'public/vendor/pdfjs/pdf.mjs',
  'public/vendor/pdfjs/pdf.worker.mjs',
  'public/vendor/bootstrap.min.css',
  'public/vendor/bootstrap.bundle.min.js',
  'public/vendor/jquery.min.js',
  'public/vendor/jquery-ui.min.js',
  'public/vendor/jquery-ui.min.css',
  'public/css/dms.css',
  'public/js/dms.js',
  ...['400', '500', '600'].map((w) => `public/vendor/fonts/inter-${w}.woff2`),
];
for (const file of required) assert(fs.statSync(file).size > 0, `Missing asset: ${file}`);
assert(fs.readFileSync('public/vendor/jquery.min.js', 'utf8').includes('jQuery v3.7.1'));
assert(fs.readFileSync('public/vendor/jquery-ui.min.js', 'utf8').includes('1.14.1'));
assert(/Bootstrap\s+v5\.3\.3/.test(fs.readFileSync('public/vendor/bootstrap.min.css', 'utf8')));
for (const name of fs.readdirSync('public/assets/figma').filter((n) => n.endsWith('.svg'))) {
  const svg = fs.readFileSync(path.join('public/assets/figma', name), 'utf8');
  assert(svg.includes('<svg'), name);
  assert(/width="\d+/.test(svg) && /height="\d+/.test(svg), `Missing native geometry: ${name}`);
}
for (const dir of ['resources/views', 'public/css', 'public/js']) {
  for (const entry of fs
    .readdirSync(dir, { recursive: true })
    .filter((n) => /\.(php|css|js)$/.test(n))) {
    const file = path.join(dir, entry);
    const data = fs.readFileSync(file);
    assert(!data.subarray(0, 3).equals(Buffer.from([239, 187, 191])), `BOM: ${file}`);
    const text = new TextDecoder('utf-8', { fatal: true }).decode(data);
    if (!file.endsWith('welcome.blade.php'))
      assert(!/https:\/\/www\.figma\.com\/api\/mcp\/asset/.test(text), `Temporary URL: ${file}`);
  }
}
for (const name of ['444444', '555555', '777620', '777777', 'cc0000', 'ffffff'])
  assert(fs.statSync(`public/vendor/images/ui-icons_${name}_256x240.png`).size > 0);
console.log('Frontend assets, stack versions, SVG geometry and UTF-8 checks passed.');
