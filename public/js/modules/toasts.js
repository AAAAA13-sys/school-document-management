/* global jQuery, bootstrap */
/**
 * DMS Toast Notification System
 */
let toastTimer;

export function toast(message) {
  const box = document.getElementById('feedback-toast');
  if (!box) return;
  clearInterval(toastTimer);
  if (typeof jQuery !== 'undefined') {
    jQuery(box).find('.toast-body').text(message);
    const countdown = jQuery(box).find('.toast-countdown').progressbar({ max: 4000, value: 4000 });
    const notification = bootstrap.Toast.getOrCreateInstance(box, {
      autohide: false,
      animation: false,
    });
    notification.show();
    const started = performance.now();
    toastTimer = setInterval(() => {
      const remaining = Math.max(0, 4000 - (performance.now() - started));
      countdown.progressbar('value', remaining);
      if (!remaining) {
        clearInterval(toastTimer);
        notification.hide();
      }
    }, 50);
  }
}
