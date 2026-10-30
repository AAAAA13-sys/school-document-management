import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/js/dms.js', 'utf8');
const handler = source.slice(
  source.indexOf("    $('form').on('submit', function (event) {"),
  source.indexOf("      if (this.dataset.ajaxMove === 'true'")
);

test('admin invitation submission waits for confirmation and prevents duplicate processing', () => {
  const button = { textContent: 'Resend', disabled: false, dataset: {} };
  const progress = { textContent: '' };
  const form = {
    dataset: { confirm: 'Confirm resend' },
    checkValidity: () => true,
    hasAttribute: (name) => name === 'data-invitation-submit',
    setAttribute: () => {},
    querySelector: (selector) => (selector.startsWith('button') ? button : progress),
  };
  let callback;
  const context = {
    $: () => ({
      on: (_event, fn) => {
        callback = fn;
      },
    }),
    window: { confirm: () => false },
    preview: false,
    multiUpload: false,
  };
  vm.runInNewContext(handler + '\n    });', context);
  let prevented = 0;
  const event = {
    isDefaultPrevented: () => false,
    preventDefault: () => {
      prevented++;
    },
  };
  callback.call(form, event);
  assert.equal(prevented, 1);
  assert.equal(button.disabled, false);
  context.window.confirm = () => true;
  callback.call(form, event);
  assert.equal(form.dataset.invitationSubmitting, 'true');
  assert.equal(button.disabled, true);
  assert.equal(button.dataset.invitationOriginalLabel, 'Resend');
  assert.match(progress.textContent, /wait/);
  callback.call(form, event);
  assert.equal(prevented, 2);
});
