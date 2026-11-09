// @ts-check
// Phase 3 journey: an administrator runs the setup check and reads what still needs attention.
const { test, expect } = require('@playwright/test');
const { login, openMenuEntry, frameBySrc, configureOpenVisit } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';

test.describe('OpenVisit setup check', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('admin sees a ready verdict with every check explained', async ({ page, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'admin');
    expect(await openMenuEntry(page, 'Modules', 'Manage Modules')).toBe(true);
    const installer = frameBySrc(page, 'Installer');
    const row = installer.locator('tr', { hasText: 'OpenVisit Telehealth' }).first();
    await row.locator('a[title*="onfig"]').first().click();
    const config = installer.frameLocator('iframe[src*="moduleConfig.php"]').first();

    await expect(config.locator('#openvisit-check-summary')).toHaveAttribute('data-status', 'pass');
    await expect(config.locator('#openvisit-checks li[data-check="settings"]')).toHaveAttribute('data-status', 'pass');
    await expect(config.locator('#openvisit-checks li[data-check="jwt"]')).toHaveAttribute('data-status', 'pass');
    const statuses = await config.locator('#openvisit-checks li').evaluateAll((items) => items.map((li) => li.getAttribute('data-status')));
    expect(statuses).not.toContain('fail');
    await page.screenshot({ path: 'tests/e2e/results/admin-setup-check.png' });
  });
});
