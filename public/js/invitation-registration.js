'use strict';

function validateSingle(input) {
  const val = input.value || '';
  const isName = input.name === 'name' || input.id === 'full-name';
  const isEmail = input.type === 'email' || input.name === 'email';
  const pattern = input.getAttribute('pattern');
  const title = input.getAttribute('title');
  let error = '';

  if (val.length === 0) {
    if (input.dataset.touched && input.required) {
      error = isName
        ? 'Full name is required.'
        : isEmail
          ? 'Email address is required.'
          : 'This field is required.';
    }
  } else {
    if (isName) {
      if (!/^[A-Za-zÀ-ÖØ-öø-ÿ\s.\-']+$/.test(val)) {
        error =
          title || 'Full name may only contain letters, spaces, hyphens, periods, and apostrophes.';
      } else if (val.trim().length < 2) {
        error = 'Full name must be at least 2 characters.';
      }
    } else if (isEmail) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
        error = 'Please enter a valid email address.';
      }
    } else if (pattern) {
      try {
        if (!new RegExp(pattern).test(val)) {
          error = title || input.validationMessage || 'Please match the requested format.';
        }
      } catch (e) {}
    }
  }

  let feedback = input.nextElementSibling;
  if (!feedback || !feedback.classList.contains('realtime-feedback')) {
    feedback = document.createElement('div');
    feedback.className = 'invalid-feedback realtime-feedback';
    input.parentNode.insertBefore(feedback, input.nextSibling);
  }

  if (error) {
    input.classList.add('is-invalid');
    input.classList.remove('is-valid');
    feedback.textContent = error;
    feedback.style.display = 'block';
    input.setCustomValidity(error);
  } else {
    input.classList.remove('is-invalid');
    feedback.textContent = '';
    feedback.style.display = 'none';
    if (val.length > 0) {
      input.classList.add('is-valid');
    } else {
      input.classList.remove('is-valid');
    }
    input.setCustomValidity('');
  }
  return !error;
}

function attachRealtimeValidation(container) {
  const root =
    container && typeof container.querySelectorAll === 'function'
      ? container
      : typeof document !== 'undefined' && typeof document.querySelectorAll === 'function'
        ? document
        : null;
  if (!root) return;
  const inputs = root.querySelectorAll(
    'input[pattern], input[type="email"], input[name="name"], input#full-name'
  );
  if (!inputs || !inputs.forEach) return;
  inputs.forEach((input) => {
    input.addEventListener('input', () => {
      input.dataset.touched = 'true';
      validateSingle(input);
    });
    input.addEventListener('blur', () => {
      input.dataset.touched = 'true';
      validateSingle(input);
    });
    input.addEventListener('invalid', (e) => {
      e.preventDefault();
      input.dataset.touched = 'true';
      validateSingle(input);
    });
  });
}

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    const slash =
      typeof button.querySelector === 'function'
        ? button.querySelector('[data-password-slash]')
        : null;
    if (slash) slash.style.display = visible ? '' : 'none';
    button.setAttribute('aria-pressed', String(visible));
    const label = input.id.endsWith('confirmation') ? 'password confirmation' : 'new password';
    button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') + label);
  });
});

const registrationForm = document.getElementById('invitation-registration-form');
const registrationButton = document.getElementById('complete-registration');
const registrationProgress = document.getElementById('registration-progress');
let registrationSubmitting = false;

attachRealtimeValidation(registrationForm);

registrationForm?.addEventListener('submit', (event) => {
  let firstInvalid = null;
  if (typeof registrationForm.querySelectorAll === 'function') {
    registrationForm
      .querySelectorAll('input[pattern], input[type="email"], input[name="name"], input#full-name')
      .forEach((inp) => {
        inp.dataset.touched = 'true';
        if (!validateSingle(inp) && !firstInvalid) {
          firstInvalid = inp;
        }
      });
  }

  if (firstInvalid) {
    event.preventDefault();
    firstInvalid.focus();
    return;
  }

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
