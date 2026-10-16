import { getDocument, GlobalWorkerOptions } from '/vendor/pdfjs/pdf.mjs';
GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.mjs';
const previous = document.getElementById('previous'),
  next = document.getElementById('next');
const zoomIn = document.getElementById('zoom-in'),
  zoomOut = document.getElementById('zoom-out');
const count = document.getElementById('page-count'),
  canvas = document.getElementById('pdf-canvas');
let pdf,
  pageNumber = 1,
  zoom = 1,
  busy = false;
function controls() {
  previous.disabled = busy || !pdf || pageNumber <= 1;
  next.disabled = busy || !pdf || pageNumber >= pdf.numPages;
  zoomIn.disabled = busy || !pdf || zoom >= 2;
  zoomOut.disabled = busy || !pdf || zoom <= 0.5;
}
async function render() {
  busy = true;
  controls();
  try {
    const page = await pdf.getPage(pageNumber),
      base = page.getViewport({ scale: 1 });
    const scale = Math.min((document.documentElement.clientWidth - 32) / base.width, 1.5) * zoom;
    const viewport = page.getViewport({ scale }),
      ratio = Math.min(devicePixelRatio || 1, 2);
    canvas.width = Math.floor(viewport.width * ratio);
    canvas.height = Math.floor(viewport.height * ratio);
    canvas.style.width = viewport.width + 'px';
    canvas.style.height = viewport.height + 'px';
    await page.render({
      canvas,
      viewport,
      transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0],
    }).promise;
    canvas.hidden = false;
    canvas.setAttribute('aria-label', 'PDF page ' + pageNumber + ' of ' + pdf.numPages);
    count.textContent = pageNumber + ' / ' + pdf.numPages;
  } catch (error) {
    showError(error);
  } finally {
    busy = false;
    controls();
  }
}
function showError(error) {
  document.getElementById('pdf-error').hidden = false;
  document.getElementById('pdf-error').textContent =
    'Preview could not load. Check your access and scan status, or use Download.';
  count.textContent = 'Preview unavailable';
}
previous.addEventListener('click', () => {
  if (!busy && pageNumber > 1) {
    pageNumber--;
    render();
  }
});
next.addEventListener('click', () => {
  if (!busy && pageNumber < pdf.numPages) {
    pageNumber++;
    render();
  }
});
zoomIn.addEventListener('click', () => {
  if (!busy) {
    zoom = Math.min(2, zoom + 0.25);
    render();
  }
});
zoomOut.addEventListener('click', () => {
  if (!busy) {
    zoom = Math.max(0.5, zoom - 0.25);
    render();
  }
});
try {
  const file = new URLSearchParams(location.search).get('file');
  if (!/^\/files\/[a-f0-9-]{36}\/preview$/.test(file || ''))
    throw new Error('Invalid preview path');
  pdf = await getDocument({
    url: file,
    cMapUrl: '/vendor/pdfjs/cmaps/',
    cMapPacked: true,
    standardFontDataUrl: '/vendor/pdfjs/standard_fonts/',
    wasmUrl: '/vendor/pdfjs/wasm/',
    iccUrl: '/vendor/pdfjs/iccs/',
    isEvalSupported: false,
    enableXfa: false,
  }).promise;
  await render();
} catch (error) {
  showError(error);
}
