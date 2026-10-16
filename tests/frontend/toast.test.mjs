import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const js = fs.readFileSync('public/js/dms.js', 'utf8');
const startIndex = js.search(/let toastTimer;/);
const endIndex = js.search(/function closeModal/);
const source = js.slice(startIndex, endIndex);
test('notification countdown starts full, reaches zero at four seconds and resets for new messages', () => {
  let now = 0,
    shown = 0,
    hidden = 0,
    value,
    message = '',
    next = 0;
  const timers = new Map();
  const progress = {
    progressbar: (option, number) => {
      value = typeof option === 'object' ? option.value : number;
      return progress;
    },
  };
  const body = {
    text: (text) => {
      if (text === undefined) return '';
      message = text;
      return body;
    },
  };
  const box = {};
  const $ = (selector) => ({
    find: (part) => (part === '.toast-body' ? body : progress),
    on: () => {},
    text: () => '',
  });
  const context = {
    $,
    document: { getElementById: () => box },
    performance: { now: () => now },
    setInterval: (fn) => {
      timers.set(++next, fn);
      return next;
    },
    clearInterval: (id) => timers.delete(id),
    bootstrap: {
      Toast: {
        getOrCreateInstance: (_box, options) => {
          assert.equal(options.autohide, false);
          return { show: () => shown++, hide: () => hidden++ };
        },
      },
    },
  };
  vm.runInNewContext(source + ';globalThis.notify=toast;', context);
  const tick = () => [...timers.values()].forEach((fn) => fn());
  context.notify('Document moved to trash. All versions are preserved.');
  assert.equal(value, 4000);
  assert.equal(shown, 1);
  assert.match(message, /All versions/);
  now = 2000;
  tick();
  assert.equal(value, 2000);
  assert.equal(hidden, 0);
  context.notify('Document restored.');
  assert.equal(timers.size, 1);
  assert.equal(value, 4000);
  now = 5999;
  tick();
  assert.equal(value, 1);
  assert.equal(hidden, 0);
  now = 6000;
  tick();
  assert.equal(value, 0);
  assert.equal(hidden, 1);
  assert.equal(timers.size, 0);
});
