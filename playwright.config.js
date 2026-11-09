// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
const { defineConfig } = require('@playwright/test');

// Targets the OpenEMR development-easy-light stack by default.
module.exports = defineConfig({
  testDir: 'tests/e2e',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  // One retry in CI; the HTML report marks such runs as flaky so they stay visible.
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/e2e/report' }]],
  use: {
    baseURL: process.env.OPENEMR_URL ?? 'https://localhost:9300',
    ignoreHTTPSErrors: true,
    viewport: { width: 1400, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    permissions: ['camera', 'microphone'],
    launchOptions: {
      // Fake devices so the Jitsi room can be joined headless.
      args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
    },
  },
  outputDir: 'tests/e2e/results',
});
