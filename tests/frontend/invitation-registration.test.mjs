import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const script = fs.readFileSync('public/js/invitation-registration.js', 'utf8');

function fixture({ valid = true, formPresent = true } = {}) {
  const handlers = {};
  const elements = {};
  function element(id, properties = {}) {
    const attrs = {};
    const result = {
      id,
      textContent: '',
      disabled: false,
      addEventListener: (event, callback) => {
        handlers[id + ':' + event] = callback;
      },
      setAttribute: (key, value) => {
        attrs[key] = value;
      },
      getAttribute: (key) => attrs[key],
      removeAttribute: (key) => {
        delete attrs[key];
      },
      focus: () => {
        result.focused = true;
      },
      ...properties,
    };
    elements[id] = result;
    return result;
  }
  const toggles = [];
  if (formPresent) {
    element('invitation-registration-form', { checkValidity: () => valid });
    element('complete-registration');
    element('registration-progress');
    for (const id of ['registration-password', 'registration-password-confirmation']) {
      element(id, { type: 'password' });
      const toggle = element(id + '-toggle');
      toggle.setAttribute('aria-controls', id);
      toggles.push(toggle);
    }
  }
  element('registration-errors');
  vm.runInNewContext(script, {
    document: {
      querySelectorAll: () => toggles,
      getElementById: (id) => elements[id] ?? null,
    },
    window: {
      addEventListener: (event, callback) => {
        handlers['window:' + event] = callback;
      },
    },
  });
  return { elements, handlers };
}

test('password visibility buttons update input type and accessible state', () => {
  const { elements, handlers } = fixture();
  const toggle = elements['registration-password-toggle'];
  handlers['registration-password-toggle:click']();
  assert.equal(elements['registration-password'].type, 'text');
  assert.equal(toggle.getAttribute('aria-pressed'), 'true');
  assert.equal(toggle.getAttribute('aria-label'), 'Hide new password');
  handlers['registration-password-toggle:click']();
  assert.equal(elements['registration-password'].type, 'password');
  assert.equal(toggle.getAttribute('aria-pressed'), 'false');
});

test('submission prevents duplicate posts and resets after browser restoration', () => {
  const { elements, handlers } = fixture();
  let prevented = 0;
  const event = {
    defaultPrevented: false,
    preventDefault: () => {
      prevented++;
    },
  };
  handlers['invitation-registration-form:submit'](event);
  assert.equal(elements['complete-registration'].disabled, true);
  assert.equal(elements['invitation-registration-form'].getAttribute('aria-busy'), 'true');
  assert.match(elements['registration-progress'].textContent, /submitted/);
  handlers['invitation-registration-form:submit'](event);
  assert.equal(prevented, 1);
  handlers['window:pageshow']();
  assert.equal(elements['complete-registration'].disabled, false);
  assert.equal(elements['registration-progress'].textContent, '');
  assert.equal(elements['invitation-registration-form'].getAttribute('aria-busy'), undefined);
});

test('invalid forms stay editable and validation feedback receives focus', () => {
  const { elements, handlers } = fixture({ valid: false });
  handlers['invitation-registration-form:submit']({ defaultPrevented: false });
  assert.equal(elements['complete-registration'].disabled, false);
  assert.equal(elements['registration-errors'].focused, true);
});

test('state pages without a registration form do not throw script errors', () => {
  const { handlers } = fixture({ formPresent: false });
  assert.doesNotThrow(() => handlers['window:pageshow']());
});
