import fs from 'node:fs';
import path from 'node:path';
const source = 'node_modules/pdfjs-dist',
  target = 'public/vendor/pdfjs';
fs.mkdirSync(target, { recursive: true });
for (const file of ['pdf.mjs', 'pdf.worker.mjs'])
  fs.copyFileSync(path.join(source, 'build', file), path.join(target, file));
for (const dir of ['cmaps', 'standard_fonts', 'wasm', 'iccs'])
  fs.cpSync(path.join(source, dir), path.join(target, dir), {
    recursive: true,
  });
fs.copyFileSync(path.join(source, 'LICENSE'), path.join(target, 'LICENSE'));
