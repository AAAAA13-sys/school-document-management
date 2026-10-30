'use strict';

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.textContent = visible ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(visible));
    const label = input.id.endsWith('confirmation') ? 'password confirmation' : 'new password';
    button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') + label);
  });
});

const registrationForm = document.getElementById('invitation-registration-form');
const registrationButton = document.getElementById('complete-registration');
const registrationProgress = document.getElementById('registration-progress');
let registrationSubmitting = false;

registrationForm?.addEventListener('submit', (event) => {
  if (registrationSubmitting) {
    event.preventDefault();
    return;
  }
  if (event.defaultPrevented || !registrationForm.checkValidity()) return;
  registrationSubmitting = true;
  registrationForm.setAttribute('aria-busy', 'true');
  registrationButton.disabled = true;
  registrationButton.textContent = 'Creating account…';
  registrationProgress.textContent = 'Registration is being submitted. Please wait.';
});

window.addEventListener('pageshow', () => {
  registrationSubmitting = false;
  registrationForm?.removeAttribute('aria-busy');
  if (registrationButton) {
    registrationButton.disabled = false;
    registrationButton.textContent = 'Complete Registration';
  }
  if (registrationProgress) registrationProgress.textContent = '';
});

document.getElementById('registration-errors')?.focus();
