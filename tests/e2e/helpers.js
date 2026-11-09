// @ts-check
const { expect } = require('@playwright/test');

const USERS = {
  admin: { user: 'admin', pass: 'pass' },
  physician: { user: 'physician', pass: 'physician' },
  accountant: { user: 'accountant', pass: 'accountant' },
};

async function login(page, role) {
  const { user, pass } = USERS[role];
  // Core uses alert() and confirm() for warnings; a user reads them and clicks OK.
  page.on('dialog', (dialog) => {
    console.log(`[dialog] ${dialog.type()}: ${dialog.message().slice(0, 160)}`);
    dialog.accept().catch(() => {});
  });
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

// Tab iframes are created empty and navigated by script, so match on the frame URL, not the src attribute.
// Returns the newest matching frame: closed dialogs can leave a hidden iframe behind.
async function frameByUrl(page, pattern, timeout = 30_000) {
  await expect.poll(() => page.frames().some((f) => pattern.test(f.url())), { timeout }).toBe(true);
  const matches = page.frames().filter((f) => pattern.test(f.url()));
  return matches[matches.length - 1];
}

async function waitForDialogToClose(page, srcPart) {
  await expect(page.locator(`iframe[src*="${srcPart}"]:visible`)).toHaveCount(0, { timeout: 30_000 });
}

function globalsField(frame, label) {
  return frame.locator('div.row.form-group', { hasText: label }).first().locator('input, select').first();
}

// Clicks Save on the Globals page and waits until the server has processed it and the page reloaded.
async function saveGlobals(page, frame) {
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('edit_globals.php') && r.request().method() === 'POST'),
    frame.getByRole('button', { name: 'Save' }).first().click(),
  ]);
  await frame.locator('ul li a', { hasText: /^Appearance$/ }).waitFor();
}

// Points OpenVisit at a Jitsi server the way an administrator does, in its own browser context.
async function configureOpenVisit(browser, { domain, appId, secret }) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await login(page, 'admin');
  await openMenuEntry(page, 'Admin', 'Config');
  let frame = frameBySrc(page, 'edit_globals.php');
  await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
  await globalsField(frame, 'Jitsi domain').fill(domain);
  await globalsField(frame, 'JWT app id').fill(appId);
  await globalsField(frame, 'JWT app secret').fill(secret);
  // Wide join windows so a same-day test appointment is joinable whatever the clock says.
  await globalsField(frame, 'Join window before appointment').fill('1440');
  await globalsField(frame, 'Join window after appointment').fill('24');
  await saveGlobals(page, frame);
  frame = frameBySrc(page, 'edit_globals.php');
  await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
  await expect(globalsField(frame, 'Jitsi domain')).toHaveValue(domain);
  await context.close();
}

module.exports = { USERS, login, openMenuEntry, clickTopMenu, frameBySrc, frameByUrl, waitForDialogToClose, globalsField, saveGlobals, configureOpenVisit };
