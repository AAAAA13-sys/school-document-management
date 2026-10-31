/* global jQuery, bootstrap */
/**
 * DMS Duplicate File Warning Modal Module
 * Intercepts HTTP 409 responses and offers new version upload or cancellation.
 */
export function showDuplicateWarningModal(existingDoc, onUploadNewVersion, onCancel) {
  let modalEl = document.getElementById('duplicate-warning-modal');
  if (!modalEl) {
    const modalHtml = `
      <div class="modal fade" id="duplicate-warning-modal" tabindex="-1" aria-labelledby="duplicate-warning-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title" id="duplicate-warning-title">Duplicate file detected</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p class="modal-subtitle">An identical file already exists in a document you can access.</p>
              <div class="duplicate-info-card p-3 my-3 rounded" style="background: var(--canvas, #f8f9fa); border: 1px solid var(--line, #e2e8f0);">
                <p class="mb-2"><strong>Document Title:</strong> <span id="duplicate-modal-doc-title">—</span></p>
                <p class="mb-0"><strong>Document Owner:</strong> <span id="duplicate-modal-doc-owner">—</span></p>
              </div>
              <p class="text-muted small mb-0">You can upload this file as a new version of the existing document, or cancel the upload.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="duplicate-modal-cancel">Cancel</button>
              <button type="button" class="btn btn-primary" id="duplicate-modal-upload-new-version">Upload as New Version</button>
            </div>
          </div>
        </div>
      </div>`;
    if (typeof jQuery !== 'undefined') {
      jQuery('body').append(modalHtml);
    } else {
      document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    modalEl = document.getElementById('duplicate-warning-modal');
  }

  const title = existingDoc?.title || 'Untitled document';
  const owner =
    existingDoc?.owner ||
    existingDoc?.owner_name ||
    existingDoc?.owner_email ||
    (existingDoc?.owner_id ? 'User #' + existingDoc.owner_id : 'Document owner');

  const titleEl = document.getElementById('duplicate-modal-doc-title');
  const ownerEl = document.getElementById('duplicate-modal-doc-owner');
  if (titleEl) titleEl.textContent = title;
  if (ownerEl) ownerEl.textContent = owner;

  const bsModal =
    typeof bootstrap !== 'undefined' && bootstrap.Modal
      ? bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;

  const uploadBtn = document.getElementById('duplicate-modal-upload-new-version');
  const cancelBtn = document.getElementById('duplicate-modal-cancel');

  if (uploadBtn) {
    uploadBtn.onclick = function () {
      if (bsModal) bsModal.hide();
      if (typeof onUploadNewVersion === 'function') onUploadNewVersion();
    };
  }

  if (cancelBtn) {
    cancelBtn.onclick = function () {
      if (bsModal) bsModal.hide();
      if (typeof onCancel === 'function') onCancel();
    };
  }

  if (bsModal) bsModal.show();
}

if (typeof window !== 'undefined') {
  window.DmsDuplicateModal = { show: showDuplicateWarningModal };
}

