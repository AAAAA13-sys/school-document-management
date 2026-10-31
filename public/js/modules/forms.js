/* global jQuery */
/**
 * DMS Forms & Validation Handling
 */
export function initForms() {
  if (typeof jQuery === 'undefined') return;
  const $ = jQuery;

  // Password visibility toggle handler
  $(document).on('click', '.password-toggle', function () {
    const field = $(this).closest('.password-field');
    const input = field.find('input');
    const isPassword = input.attr('type') === 'password';
    input.attr('type', isPassword ? 'text' : 'password');
    $(this).find('.eye-show').toggle(!isPassword);
    $(this).find('.eye-hide').toggle(isPassword);
    $(this).attr('aria-label', isPassword ? 'Hide password' : 'Show password');
  });

  // CSRF setup for AJAX
  $.ajaxSetup({
    headers: {
      'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
    },
  });
}
