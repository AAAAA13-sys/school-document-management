// @ts-check
import { defineConfig } from '@playwright/test';

/**
 * Playwright Test Configuration for PLP Documents DMS
 * Validates responsive viewports (375px, 768px, 1280px) and modal dialogs.
 */
export default defineConfig({
  testDir: './tests/frontend',
  testMatch: /.*responsive.*\.mjs/,
  timeout: 30000,
  use: {
    headless: true,
    channel: 'chrome',
  },
  projects: [
    {
      name: 'Mobile 375px',
      use: {
        viewport: { width: 375, height: 667 },
      },
    },
    {
      name: 'Tablet 768px',
      use: {
        viewport: { width: 768, height: 1024 },
      },
    },
    {
      name: 'Desktop 1280px',
      use: {
        viewport: { width: 1280, height: 800 },
      },
    },
  ],
});
