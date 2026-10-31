/* global jQuery */
/**
 * DMS Validation & Status Badge Module
 */
export const fileError = (file) =>
  !file
    ? 'Choose a file.'
    : file.size > 10 * 1024 * 1024
      ? 'Choose a file no larger than 10 MB.'
      : '';

export const strongPassword = (value) =>
  Boolean(
    value &&
    value.length >= 12 &&
    /[a-z]/.test(value) &&
    /[A-Z]/.test(value) &&
    /[0-9]/.test(value) &&
    /[^a-zA-Z0-9]/.test(value)
  );

// Exposed globally for frontend contracts and tests
if (typeof window !== 'undefined') {
  window.DmsValidation = { fileError, strongPassword };
  window.DmsStatusBadge = {
    update: function (el, status) {
      if (typeof jQuery !== 'undefined') {
        const $el = jQuery(el);
        const s = String(status || '').toLowerCase();
        $el
          .removeClass('clean pending failed quarantined neutral')
          .addClass(s)
          .text(status)
          .attr('data-status', status);
      }
    },
  };
}

