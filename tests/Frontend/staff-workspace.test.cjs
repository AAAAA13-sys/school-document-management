const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
function element(selector) {
    if (!elements.has(selector)) elements.set(selector, {
        handlers: {}, attributes: {}, value: '', label: '', events: [],
        on(event, handler) { this.handlers[event] = handler; return this; },
        attr(name, value) { if (arguments.length === 1) return this.attributes[name]; this.attributes[name] = value; return this; },
        text(value) { this.label = value; return this; },
        val(value) { if (!arguments.length) return this.value; this.value = value; return this; },
        find() { return element('search-input'); },
        trigger(event) { this.events.push(event); return this; },
    });
    return elements.get(selector);
}
function $(value) { if (typeof value === 'function') return value(); return typeof value === 'string' ? element(value) : value; }
vm.runInNewContext(fs.readFileSync('public/assets/staff-workspace.js', 'utf8'), { $ });
const password = element('#password'), toggle = element('#password-toggle');
password.attr('type', 'password');
toggle.handlers.click.call(toggle);
assert.equal(password.attr('type'), 'text');
assert.equal(toggle.attributes['aria-pressed'], 'true');
assert.equal(toggle.label, 'Hide');
toggle.handlers.click.call(toggle);
assert.equal(password.attr('type'), 'password');
assert.equal(toggle.attributes['aria-pressed'], 'false');
assert.equal(toggle.label, 'Show');
element('search-input').val('Employment contract');
let prevented = false;
element('#workspace-search').handlers.submit.call(element('#workspace-search'), { preventDefault() { prevented = true; } });
assert.ok(prevented);
assert.equal(element('#drive-search').val(), 'Employment contract');
assert.deepEqual(element('.nav-item[data-view="files"]').events, ['click']);
assert.deepEqual(element('#drive-filter').events, ['submit']);
console.log('Staff password visibility and search navigation checks passed.');
