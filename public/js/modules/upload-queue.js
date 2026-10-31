/**
 * DMS Upload Queue Module
 * Manages parallel file uploading with concurrency bound to 2.
 */
export async function DmsUploadQueue(items, send, update) {
  async function worker() {
    let item;
    while ((item = items.find((row) => row.status === 'pending'))) {
      item.status = 'uploading';
      update();
      try {
        await send(item);
        item.status = 'saved';
        item.error = '';
      } catch (error) {
        item.status = 'failed';
        item.error = error.message || 'Upload failed.';
      }
      update();
    }
  }
  await Promise.all([worker(), worker()]);
}

if (typeof window !== 'undefined') {
  window.DmsUploadQueue = DmsUploadQueue;
}

