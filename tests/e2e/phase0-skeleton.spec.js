// @ts-check
// Phase 0 journey: an administrator finds the module, configures it, and a clinician reaches it.
const { test, expect } = require('@playwright/test');
const { login, openMenuEntry, clickTopMenu, frameBySrc, globalsField } = require('./helpers');

const MODULE_PAGE = 'oe-module-openvisit/public/index.php';

test.describe('OpenVisit skeleton', () => {
  test('admin opens Telehealth from the main menu', async ({ page }) => {
    await login(page, 'admin');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const frame = frameBySrc(page, MODULE_PAGE);
    await expect(frame.getByRole('heading', { name: 'OpenVisit sessions' })).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/results/admin-module-page.png' });
  });

  test('admin configures OpenVisit in Globals and the values persist', async ({ page }) => {
    await login(page, 'admin');
    expect(await openMenuEntry(page, 'Admin', 'Config')).toBe(true);
    let frame = frameBySrc(page, 'edit_globals.php');
    await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
    await expect(frame.locator('.oe-global-tab-heading', { hasText: 'OpenVisit' })).toBeVisible();

    await expect(globalsField(frame, 'Join window before appointment')).toHaveValue('30');
    await expect(globalsField(frame, 'Join window after appointment')).toHaveValue('2');
    await expect(globalsField(frame, 'Send invitations by email')).not.toBeChecked();
    await expect(globalsField(frame, 'Allow ad-hoc visits')).toBeChecked();
    await expect(globalsField(frame, 'JWT app secret')).toHaveAttribute('type', 'password');

    await globalsField(frame, 'Jitsi domain').fill('meet.example.test');
    await globalsField(frame, 'JWT app id').fill('openvisit');
    await globalsField(frame, 'JWT app secret').fill('not-a-real-secret');
    await frame.getByRole('button', { name: 'Save' }).first().click();

    // The page reloads after save; reopen the tab and read back.
    frame = frameBySrc(page, 'edit_globals.php');
    await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
    await expect(globalsField(frame, 'Jitsi domain')).toHaveValue('meet.example.test');
    await expect(globalsField(frame, 'JWT app id')).toHaveValue('openvisit');
    // Core decrypts the secret back into the masked field for editing; at rest it is encrypted.
    await expect(globalsField(frame, 'JWT app secret')).toHaveAttribute('type', 'password');
    await page.screenshot({ path: 'tests/e2e/results/admin-globals-saved.png' });

    // Leave the stack as we found it.
    await globalsField(frame, 'Jitsi domain').fill('');
    await globalsField(frame, 'JWT app id').fill('');
    await globalsField(frame, 'JWT app secret').fill('');
    await frame.getByRole('button', { name: 'Save' }).first().click();
    frame = frameBySrc(page, 'edit_globals.php');
    await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
    await expect(globalsField(frame, 'Jitsi domain')).toHaveValue('');
  });

  test('admin opens the module config page from Manage Modules', async ({ page }) => {
    await login(page, 'admin');
    expect(await openMenuEntry(page, 'Modules', 'Manage Modules')).toBe(true);
    const installer = frameBySrc(page, 'Installer');
    const row = installer.locator('tr', { hasText: 'OpenVisit Telehealth' }).first();
    await expect(row).toContainText('Active');
    await row.locator('a[title*="onfig"], a:has(i.fa-cog), a:has(i.fa-gear)').first().click();
    const config = installer.frameLocator('iframe[src*="moduleConfig.php"]').first();
    await expect(config.getByRole('heading', { name: 'OpenVisit Telehealth' })).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/results/admin-module-config.png' });
  });

  test('physician sees Telehealth in the main menu and can open it', async ({ page }) => {
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    await expect(frameBySrc(page, MODULE_PAGE).getByRole('heading', { name: 'OpenVisit sessions' })).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/results/physician-module-page.png' });
  });

  test('menu visibility and page access agree for a non-clinical user', async ({ page }) => {
    await login(page, 'accountant');
    const inMenu = await clickTopMenu(page, 'Telehealth');
    const response = await page.goto(`/interface/modules/custom_modules/${MODULE_PAGE}`);
    expect(response?.status()).toBe(inMenu ? 200 : 403);
    if (!inMenu) {
      await expect(page.getByText('Not authorized')).toBeVisible();
    }
    await page.screenshot({ path: 'tests/e2e/results/accountant-access.png' });
  });
});
