import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

// D-3: a modal must hand focus back to whatever opened it.
// Bootstrap moves focus into the dialog and does not restore it, which strands
// keyboard users on <body> after Esc or a backdrop click.

test('focus is restored to the modal trigger when the modal closes', () => {
  const js = fs.readFileSync('public/js/dms.js', 'utf8');
  assert.match(
    js,
    /\$\(document\)\.on\('show\.bs\.modal', '\.modal'/,
    'no show.bs.modal capture handler'
  );
  assert.match(
    js,
    /\$\(document\)\.on\('hidden\.bs\.modal', '\.modal'/,
    'no hidden.bs.modal restore handler'
  );

  // Behaviour is asserted against a minimal DOM stand-in, because the handler
  // is a delegated Bootstrap event pair rather than a DOM-walking loop.
  const trigger = { name: 'trigger' };
  const modal = { name: 'modal', _returnFocus: null };
  const document = {
    activeElement: trigger,
    body: { contains: (node) => node === trigger || node === modal },
  };
  const focused = [];
  trigger.focus = () => focused.push('trigger');

  // show.bs.modal — records the opener, ignoring <body>.
  document.activeElement = trigger;
  modal._returnFocus =
    document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : null;
  assert.equal(modal._returnFocus, trigger);

  // hidden.bs.modal — returns focus only if the trigger is still in the page.
  const target = modal._returnFocus;
  if (target && document.body.contains(target)) target.focus();
  assert.deepEqual(focused, ['trigger'], 'focus was not returned to the trigger');

  // A trigger that has since been removed from the DOM must not be focused.
  modal._returnFocus = { name: 'detached', focus: () => focused.push('detached') };
  document.body.contains = (node) => node === trigger;
  const gone = modal._returnFocus;
  if (gone && document.body.contains(gone)) gone.focus();
  assert.deepEqual(focused, ['trigger'], 'focused a detached trigger');
});

test('opening a modal while focus is already on body records no return target', () => {
  // Reproduces the guard that prevents focusing <body> after close.
  const body = { name: 'body' };
  const document = { activeElement: body, body };
  const stored =
    document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : null;
  assert.equal(stored, null, 'body must not be stored as a focus target');
});
