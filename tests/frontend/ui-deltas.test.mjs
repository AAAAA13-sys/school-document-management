import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync('public/js/dms.js', 'utf8');
const css = fs.readFileSync('public/css/dms.css', 'utf8');
const documents = fs.readFileSync('resources/views/partials/documents.blade.php', 'utf8');
const admin = fs.readFileSync('resources/views/admin.blade.php', 'utf8');

// D-5 — single-open dropdown
test('opening a second row menu closes the first', () => {
  assert.match(js, /\$\(document\)\.on\('show\.bs\.dropdown'/, 'no dropdown show handler');
  assert.match(
    js,
    /bootstrap\.Dropdown\.getInstance\(toggle\)\?\.hide\(\)/,
    'other open menus are not hidden'
  );
});

// D-10 — debounced filter
test('the search filter is debounced rather than running per keystroke', () => {
  assert.match(js, /const filterSoon = \(\) => \{/, 'no debounce wrapper');
  assert.match(js, /filterTimer = setTimeout\(filter, \d+\)/, 'no debounce timer');
  assert.match(js, /on\('input', filterSoon\)/, 'the input handler still calls filter directly');
  assert.doesNotMatch(
    js,
    /input\[type="search"\]\)\.on\('input', filter\)/,
    'un-debounced binding is still present'
  );
});

// D-11 — coarse pointer gate
test('drag is disabled on coarse pointers so touch scrolling is not hijacked', () => {
  assert.match(js, /matchMedia\('\(pointer: coarse\)'\)\.matches/, 'no coarse pointer detection');
  assert.match(js, /disabled: coarsePointer/, 'draggable is not gated on pointer type');
});

// D-9 — star ARIA state
test('the star control exposes its state to assistive technology', () => {
  assert.match(
    documents,
    /aria-pressed="\{\{ \$isStarred \? 'true' : 'false' \}\}"/,
    'star has no aria-pressed'
  );
  assert.match(documents, /aria-label="\{\{ \$starVerb \}/, 'star has no state-aware label');
  assert.match(
    documents,
    /data-document-title="\{\{ \$item->title \}\}"/,
    'title not exposed for the JS label swap'
  );
  assert.match(
    js,
    /\.attr\('aria-pressed', String\(state\[id\]\.starred\)\)/,
    'JS does not update aria-pressed'
  );
});

// D-4 — sticky header
test('long tables pin their header and keep zebra striping intact', () => {
  assert.match(css, /\.table-sticky thead th \{/, 'no sticky header rule');
  assert.match(css, /position: sticky;/, 'sticky is never applied');
  assert.match(css, /\.table-scroll-y \{/, 'no scroll-height helper');
  assert.match(admin, /table table-zebra table-sticky/, 'audit table does not opt in');
  assert.match(
    admin,
    /table-responsive table-scroll-y/,
    'audit wrapper does not scroll vertically'
  );
  // Sticky header must paint its own background, otherwise body rows show
  // through it and the first stripe is lost behind the header.
  assert.match(
    css,
    /\.table-sticky thead th \{[^}]*background: var\(--canvas\)/,
    'sticky header has no opaque background'
  );
});

// Cross-cutting: no new dependency, CSRF untouched.
test('no new runtime library was introduced and CSRF handling is unchanged', () => {
  const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
  assert.deepEqual(
    Object.keys(pkg.dependencies).sort(),
    ['bootstrap', 'jquery', 'jquery-ui', 'pdfjs-dist'],
    'dependencies changed'
  );
  assert.match(js, /X-CSRF-TOKEN/, 'CSRF header removed');
});
