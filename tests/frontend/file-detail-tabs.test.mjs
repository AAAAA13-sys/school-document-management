import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const js = fs.readFileSync('public/js/dms.js', 'utf8');
const behavior = js.slice(
  js.indexOf("    const documentTabs = $('#document-tabs');"),
  js.indexOf("    $('#comment').on('input'")
);

for (const hasTabs of [true, false]) {
  test(`version preview selects its tab and download without changing other pages (tabs=${hasTabs})`, () => {
    let options, previewClick, selected, fragment;
    const values = {};
    const $ = (selector) => ({
      length: selector === '#document-tabs' && !hasTabs ? 0 : 1,
      resizable: () => {},
      tabs: (...args) => {
        if (typeof args[0] === 'object') options = args[0];
        else selected = args[2];
      },
      on: (_event, handler) => {
        previewClick = handler;
      },
      prop(name, value) {
        values[selector + ':' + name] = value;
        return this;
      },
      attr(name, value) {
        values[selector + ':' + name] = value;
        return this;
      },
      text(value) {
        values[selector + ':text'] = value;
        return this;
      },
    });
    vm.runInNewContext(behavior, {
      $,
      history: {
        replaceState: (_state, _title, hash) => {
          fragment = hash;
        },
      },
    });
    if (hasTabs) {
      options.activate(null, { newPanel: { attr: () => 'file-shares' } });
      assert.equal(fragment, '#file-shares');
    } else assert.equal(options, undefined);
    previewClick.call({
      dataset: { previewVersion: 'Version 2', versionDownload: '/files/version-2' },
    });
    assert.equal(selected, hasTabs ? 2 : undefined);
    assert.equal(values['#document-preview-frame:hidden'], false);
    assert.equal(values['#preview-unavailable:hidden'], true);
    assert.equal(values['#preview-version-label:text'], 'Version 2');
    assert.equal(values['#detail-download:href'], '/files/version-2');
    assert.equal(values['#detail-download:text'], 'Download version 2');
  });
}
