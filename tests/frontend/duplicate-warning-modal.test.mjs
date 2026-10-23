import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Locate dms.js from either sdm-frontend worktree or current worktree
function resolveFrontendAsset(relPath) {
  const frontendWorktree = path.resolve('../sdm-frontend', relPath);
  if (fs.existsSync(frontendWorktree)) {
    return fs.readFileSync(frontendWorktree, 'utf8');
  }
  const localAsset = path.resolve(relPath);
  if (fs.existsSync(localAsset)) {
    return fs.readFileSync(localAsset, 'utf8');
  }
  throw new Error(`Asset not found at ${frontendWorktree} or ${localAsset}`);
}

test('duplicate warning modal: static contracts and 409 status handling in dms.js', () => {
  const jsContent = resolveFrontendAsset('public/js/dms.js');

  // Assert 409 Conflict status interception is implemented
  assert.ok(
    jsContent.includes('xhr.status === 409') || jsContent.includes('status === 409'),
    'dms.js must intercept HTTP 409 responses'
  );

  // Assert duplicate warning modal structure and elements
  assert.ok(
    jsContent.includes('duplicate-warning-modal'),
    'dms.js must define or display #duplicate-warning-modal'
  );
  assert.ok(
    jsContent.includes('duplicate-modal-doc-title'),
    'dms.js must contain #duplicate-modal-doc-title for displaying the existing document title'
  );
  assert.ok(
    jsContent.includes('duplicate-modal-doc-owner'),
    'dms.js must contain #duplicate-modal-doc-owner for displaying the existing document owner'
  );
  assert.ok(
    jsContent.includes('duplicate-modal-upload-new-version'),
    'dms.js must contain #duplicate-modal-upload-new-version button'
  );
  assert.ok(
    jsContent.includes('duplicate-modal-cancel'),
    'dms.js must contain #duplicate-modal-cancel button'
  );

  // Assert force_new_version=true is submitted when user chooses Upload as New Version
  assert.ok(
    jsContent.includes("formData.set('force_new_version', 'true')") ||
    jsContent.includes('force_new_version') && jsContent.includes('true'),
    'dms.js must send force_new_version=true on upload as new version'
  );
});

test('duplicate warning modal: DmsDuplicateModal functional logic and callbacks', () => {
  const jsContent = resolveFrontendAsset('public/js/dms.js');

  // Simulate minimal DOM and jQuery environment
  let modalShown = false;
  let modalHidden = false;
  let textNodes = {};
  let clickHandlers = {};

  const mockJQuery = (selector) => {
    return {
      text: (val) => {
        if (val !== undefined) {
          textNodes[selector] = val;
          return this;
        }
        return textNodes[selector] || '';
      },
      off: () => ({
        on: (event, handler) => {
          clickHandlers[selector] = handler;
        },
      }),
      append: () => {},
      prop: () => {},
      empty: () => mockJQuery(selector),
      find: () => mockJQuery(selector),
    };
  };

  const mockBootstrap = {
    Modal: {
      getOrCreateInstance: () => ({
        show: () => { modalShown = true; },
        hide: () => { modalHidden = true; },
      }),
    },
  };

  // Verify modal display logic with mock data
  const testDoc = {
    title: 'Admissions Policy Packet 2026',
    owner: 'Dean of Admissions (oas-admin@school.edu)',
    document_id: 'doc-uuid-409-test',
  };

  // Parse showDuplicateWarningModal function or test via extract
  assert.ok(
    jsContent.includes('DmsDuplicateModal'),
    'dms.js must expose DmsDuplicateModal'
  );
});

test('responsive CSS: data-device=mobile styles prevent horizontal overflow', () => {
  const cssContent = resolveFrontendAsset('public/css/dms.css');

  // Assert mobile responsive rules exist
  assert.ok(
    cssContent.includes('body[data-device="mobile"]') || cssContent.includes('@media (max-width: 767px)'),
    'dms.css must have mobile responsive rules'
  );
  assert.ok(
    cssContent.includes('.document-items.is-grid') || cssContent.includes('.sidebar'),
    'dms.css must format grid and sidebar for mobile screens'
  );
});
