// @ts-check
const { expect } = require('@playwright/test');

const USERS = {
  admin: { user: 'admin', pass: 'pass' },
  physician: { user: 'physician', pass: 'physician' },
  accountant: { user: 'accountant', pass: 'accountant' },
};

async function login(page, role) {
  const { user, pass } = USERS[role];
  await page.goto('/interface/login/login.php?site=default');
  await page.fill('#authUser', user);
  await page.fill('#clearPass', pass);
  await page.click('#login-button');
  await expect(page).toHaveURL(/main\.php/);
  await expect(page.locator('#mainMenu')).toBeVisible();
  await dismissRegistrationModal(page);
}

// Admins get a product registration dialog that covers the screen. A user clicks it away.
// It shows up a few seconds after the main screen, once the first tab has loaded.
async function dismissRegistrationModal(page) {
  const modal = page.locator('.product-registration-modal.show');
  const appeared = await modal.waitFor({ state: 'visible', timeout: 10_000 }).then(() => true, () => false);
  if (!appeared) {
    return;
  }
  await modal.getByRole('button', { name: 'Ask again later' }).click();
  await expect(modal).toBeHidden();
}

// Clicks a top-level entry that opens a page directly (no dropdown). Returns whether it existed.
async function clickTopMenu(page, label) {
  const entry = page.locator('#mainMenu .menuLabel:not(.dropdown-toggle)', { hasText: new RegExp(`^${label}$`) }).first();
  if ((await entry.count()) === 0) {
    return false;
  }
  await entry.click();
  return true;
}

// Opens a top-level menu by its label, then clicks a child entry. Returns whether the child existed.
async function openMenuEntry(page, topLabel, childLabel) {
  const top = page.locator('#mainMenu .menuLabel.dropdown-toggle', { hasText: new RegExp(`^${topLabel}$`) }).first();
  await top.click();
  const child = page.locator('#mainMenu ul.menuEntries:visible .menuLabel', { hasText: new RegExp(`^${childLabel}$`) }).first();
  if ((await child.count()) === 0) {
    await page.keyboard.press('Escape');
    return false;
  }
  await child.click();
  return true;
}

function frameBySrc(page, pattern) {
  return page.frameLocator(`iframe[src*="${pattern}"]`).first();
}

function globalsField(frame, label) {
  return frame.locator('div.row.form-group', { hasText: label }).first().locator('input');
}

module.exports = { USERS, login, openMenuEntry, clickTopMenu, frameBySrc, globalsField };
