// @ts-check
const { defineConfig } = require('@playwright/test');

// Targets the OpenEMR development-easy-light stack by default.
module.exports = defineConfig({
  testDir: 'tests/e2e',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/e2e/report' }]],
  use: {
    baseURL: process.env.OPENEMR_URL ?? 'https://localhost:9300',
    ignoreHTTPSErrors: true,
    viewport: { width: 1400, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  outputDir: 'tests/e2e/results',
});
