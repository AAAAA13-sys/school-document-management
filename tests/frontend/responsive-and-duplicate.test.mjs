import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Task 5: UI / Responsive Tests (Playwright) against Live Authenticated Application
 *
 * Requirements:
 * - Authenticate with actual account on http://127.0.0.1:8011
 * - Screenshot at 375px, 768px, and 1280px viewports.
 * - Assert no horizontal scroll at each viewport.
 * - Assert the duplicate warning modal appears on a 409 response.
 */

const browserTestUrl = process.env.DMS_BROWSER_TEST_URL;
const browserTestEmail = process.env.DMS_BROWSER_TEST_EMAIL;
const browserTestPassword = process.env.DMS_BROWSER_TEST_PASSWORD;
const browserTestDocument = process.env.DMS_BROWSER_TEST_DOCUMENT_ID;
const browserTestConfigured = !!(browserTestUrl && browserTestEmail && browserTestPassword);

// Dynamically resolve Playwright from local node_modules or global _npx cache
async function getChromium() {
  try {
    const pw = await import('playwright');
    return pw.chromium;
  } catch {
    const cacheBase = 'C:/Users/PLP/AppData/Local/npm-cache/_npx';
    if (fs.existsSync(cacheBase)) {
      for (const entry of fs.readdirSync(cacheBase)) {
        const candidate = path
          .join(cacheBase, entry, 'node_modules/playwright/index.mjs')
          .replace(/\\/g, '/');
        if (fs.existsSync(candidate)) {
          const pw = await import(`file:///${candidate}`);
          return pw.chromium;
        }
      }
    }
    throw new Error('Playwright chromium could not be located in local or cache directory.');
  }
}

test(
  'responsive layout has no horizontal scroll at 375px, 768px, and 1280px viewports',
  { skip: !browserTestConfigured },
  async (t) => {
    let chromium;
    try {
      chromium = await getChromium();
    } catch (e) {
      t.skip('Playwright chromium not installed in current environment');
      return;
    }
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    const screenshotDirs = [
      path.resolve('docs/ui-evidence/responsive'),
      path.resolve('docs/ui-evidence/phase-1/screenshots'),
    ];
    for (const d of screenshotDirs) {
      if (!fs.existsSync(d)) {
        fs.mkdirSync(d, { recursive: true });
      }
    }

    const viewports = [
      {
        width: 375,
        height: 667,
        name: '375px',
        label: 'viewport-375px-mobile.png',
        legacy: 'responsive_375px.png',
      },
      {
        width: 768,
        height: 1024,
        name: '768px',
        label: 'viewport-768px-tablet.png',
        legacy: 'responsive_768px.png',
      },
      {
        width: 1280,
        height: 800,
        name: '1280px',
        label: 'viewport-1280px-desktop.png',
        legacy: 'responsive_1280px.png',
      },
    ];

    try {
      // Authenticate with actual Super Admin account on live application
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(new URL('/login', browserTestUrl).href, { waitUntil: 'networkidle' });
      await page.fill('#email', browserTestEmail);
      await page.fill('#password', browserTestPassword);
      await page.click('button[type="submit"]');
      await page.waitForURL('**/admin**', { waitUntil: 'networkidle' });

      for (const vp of viewports) {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.goto(new URL('/admin', browserTestUrl).href, { waitUntil: 'networkidle' });

        // Assert no horizontal scroll: scrollWidth must be <= clientWidth
        const scrollInfo = await page.evaluate(() => {
          return {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            hasHorizontalScroll:
              document.documentElement.scrollWidth > document.documentElement.clientWidth,
          };
        });

        assert.strictEqual(
          scrollInfo.hasHorizontalScroll,
          false,
          `Horizontal scroll detected at ${vp.name} viewport: scrollWidth (${scrollInfo.scrollWidth}) > clientWidth (${scrollInfo.clientWidth})`
        );

        // Save screenshot evidence at both target paths
        const p1 = path.join(screenshotDirs[0], vp.label);
        const p2 = path.join(screenshotDirs[1], vp.legacy);
        await page.screenshot({ path: p1, fullPage: false });
        fs.copyFileSync(p1, p2);
        assert.ok(fs.existsSync(p1), `Screenshot for ${vp.name} must be generated at ${p1}`);
      }
    } finally {
      await browser.close();
    }
  }
);

test(
  'duplicate warning modal appears on a 409 response',
  { skip: !browserTestConfigured || !browserTestDocument },
  async (t) => {
    let chromium;
    try {
      chromium = await getChromium();
    } catch (e) {
      t.skip('Playwright chromium not installed in current environment');
      return;
    }
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    const screenshotDirs = [
      path.resolve('docs/ui-evidence/responsive'),
      path.resolve('docs/ui-evidence/phase-1/screenshots'),
    ];
    for (const d of screenshotDirs) {
      if (!fs.existsSync(d)) {
        fs.mkdirSync(d, { recursive: true });
      }
    }

    // Temporary test file for upload
    const tempFilePath = path.resolve('tests/support/temp_duplicate_test.txt');
    fs.writeFileSync(tempFilePath, 'Sample duplicate upload payload ' + Date.now());

    try {
      // Authenticate on real server
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(new URL('/login', browserTestUrl).href, { waitUntil: 'networkidle' });
      await page.fill('#email', browserTestEmail);
      await page.fill('#password', browserTestPassword);
      await page.click('button[type="submit"]');
      await page.waitForURL('**/admin**', { waitUntil: 'networkidle' });

      // Intercept upload request to simulate 409 Conflict with duplicate matches
      await page.route('**/documents/*/versions', (route) => {
        route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({
            code: 'duplicate_file',
            message: 'A document with this content already exists in this workspace.',
            duplicates: [
              {
                document_id: '300953fc-61db-40d4-bdd8-e5810ae39b69',
                title: '[QA] PMS browser acceptance',
                owner: 'Super Admin (dms.admin@plpasig.edu.ph)',
                version_number: 1,
                can_add_version: true,
              },
            ],
          }),
        });
      });

      await page.goto(new URL('/documents/' + browserTestDocument, browserTestUrl).href, {
        waitUntil: 'networkidle',
      });

      // Switch to Version history tab where upload button resides
      await page.click('a[href="#file-versions"]');
      const uploadModalTrigger = page.locator('button[data-bs-target="#upload-modal"]');
      await uploadModalTrigger.waitFor({ state: 'visible' });
      await uploadModalTrigger.click();
      await page.waitForSelector('#upload-modal.show', { state: 'visible' });

      // Attach file and submit upload form
      await page.setInputFiles('#upload-file', {
        name: 'temp_duplicate_test.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('Sample duplicate upload payload ' + Date.now()),
      });
      await page.click('#upload-form button[type="submit"]');

      // Wait for the duplicate warning modal to appear upon receiving 409
      await page.waitForSelector('#duplicate-warning-modal', { state: 'attached', timeout: 10000 });
      const duplicateModal = page.locator('#duplicate-warning-modal');
      await duplicateModal.waitFor({ state: 'visible', timeout: 5000 });

      const isVisible = await duplicateModal.isVisible();
      assert.strictEqual(
        isVisible,
        true,
        'Duplicate warning modal must be visible upon 409 response'
      );

      // Verify document title and owner inside the duplicate warning modal
      const docTitle = await page.locator('#duplicate-modal-doc-title').innerText();
      assert.match(
        docTitle,
        /\[QA\] PMS browser acceptance/i,
        'Duplicate modal must display the existing document title'
      );

      const docOwner = await page.locator('#duplicate-modal-doc-owner').innerText();
      assert.match(
        docOwner,
        /Super Admin/i,
        'Duplicate modal must display the existing document owner'
      );

      // Verify presence of "Upload as New Version" button
      const newVersionBtn = page.locator('#duplicate-modal-upload-new-version');
      assert.strictEqual(
        await newVersionBtn.isVisible(),
        true,
        'Upload as New Version button must be visible'
      );

      // Capture screenshot evidence of duplicate warning modal
      const p1 = path.join(screenshotDirs[0], 'duplicate-warning-modal-409.png');
      const p2 = path.join(screenshotDirs[1], 'duplicate_warning_modal_409.png');
      await page.screenshot({ path: p1 });
      fs.copyFileSync(p1, p2);
      assert.ok(fs.existsSync(p1), 'Duplicate warning modal screenshot must be saved at ' + p1);
    } finally {
      if (fs.existsSync(tempFilePath)) {
        fs.unlinkSync(tempFilePath);
      }
      await browser.close();
    }
  }
);
