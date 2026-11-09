// @ts-check
// Install journey: an administrator adds OpenVisit to an OpenEMR that has never had it (or has an
// older release), sets it up by following the setup check, and runs a first visit with a patient
// on a phone. Run through tests/e2e/install/run.sh, which prepares the pristine OpenEMR.
const { test, expect, devices } = require('@playwright/test');
const { login, openMenuEntry, clickTopMenu, frameBySrc, frameByUrl, globalsField, saveGlobals, expectMediaFlowing } = require('../helpers');

const BASE_URL = process.env.FRESH_OPENEMR_URL ?? '';
const METHOD = process.env.INSTALL_METHOD ?? 'copy';
const UNSUPPORTED = process.env.EXPECT_UNSUPPORTED === '1';
const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { first: 'Ana', last: 'Popescu', dob: '1985-04-12' };

test.use({ baseURL: BASE_URL || undefined });
test.setTimeout(600_000);

// Opens Manage Modules from the menu the first time, then switches back to its tab like a user.
async function moduleRow(page) {
  const tab = page.locator('span.tabTitle', { hasText: /^Manage Modules$/ });
  if (await tab.count()) {
    await tab.first().click();
  } else {
    expect(await openMenuEntry(page, 'Modules', 'Manage Modules')).toBe(true);
  }
  const installer = frameBySrc(page, 'Installer');
  return { installer, row: installer.locator('tr', { hasText: 'OpenVisit Telehealth' }).first() };
}

// The Module Manager answers each action with an alert and reloads its list.
async function moduleAction(page, label) {
  const { row } = await moduleRow(page);
  await row.locator(`input[value="${label}"]`).click();
  await page.waitForLoadState('networkidle');
}

async function openGlobalsTab(page, tab) {
  expect(await openMenuEntry(page, 'Admin', 'Config')).toBe(true);
  const frame = frameBySrc(page, 'edit_globals.php');
  await frame.locator('ul li a', { hasText: new RegExp(`^${tab}$`) }).click();
  return frame;
}

async function setupCheck(page) {
  const { installer, row } = await moduleRow(page);
  await row.locator('a[onclick*="configure"]').first().click();
  const config = installer.frameLocator('iframe[src*="moduleConfig.php"]').first();
  await expect(config.locator('#openvisit-check-summary')).toBeVisible();
  return config;
}

async function checkStatus(config, id) {
  return config.locator(`#openvisit-checks li[data-check="${id}"]`).getAttribute('data-status');
}

test.describe('OpenVisit on a fresh OpenEMR', () => {
  test.skip(BASE_URL === '', 'run through tests/e2e/install/run.sh');
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test(`admin installs (${METHOD}), sets up and runs a first visit`, async ({ page, context, browser }) => {
    await login(page, 'admin');

    // 1. Module Manager: the module is listed as soon as its files are in place.
    let { row } = await moduleRow(page);
    await expect(row).toBeVisible();
    if (METHOD === 'upgrade') {
      // The older release is active; its schema is brought up to date from the same page.
      await expect(row).toContainText('Active');
      const sqlButton = row.locator('input[value="Install SQL"], input[value="Upgrade SQL"]').first();
      await expect(sqlButton).toBeVisible();
      await sqlButton.click();
      await page.waitForLoadState('networkidle');
      ({ row } = await moduleRow(page));
      await expect(row.locator('input[value="Install SQL"], input[value="Upgrade SQL"]')).toHaveCount(0);
    } else {
      await moduleAction(page, 'Install');
      ({ row } = await moduleRow(page));
      await expect(row).toContainText('Inactive');
      await moduleAction(page, 'Enable');
      ({ row } = await moduleRow(page));
      await expect(row).toContainText('Active');
    }
    await page.screenshot({ path: `tests/e2e/results/install-${METHOD}-module-manager.png` });

    if (UNSUPPORTED) {
      // An older core: the module stays dormant, says why, and core pages keep working.
      const config = await setupCheck(page);
      await expect(config.locator('#openvisit-check-summary')).toHaveAttribute('data-status', 'unsupported');
      await expect(config.locator('#openvisit-check-summary')).toContainText('needs OpenEMR');
      await page.screenshot({ path: `tests/e2e/results/install-${METHOD}-unsupported.png` });
      await page.goto('/interface/logout.php');
      const calendarLoaded = page.waitForResponse((r) => r.url().includes('/interface/main/calendar/index.php'));
      await login(page, 'admin');
      expect((await calendarLoaded).status()).toBe(200);
      expect(await clickTopMenu(page, 'Telehealth')).toBe(false);
      return;
    }

    // 2. Settings, as the install guide describes.
    let frame = await openGlobalsTab(page, 'OpenVisit');
    await globalsField(frame, 'Jitsi domain').fill(JITSI_DOMAIN);
    await globalsField(frame, 'JWT app id').fill(JITSI_APP_ID);
    await globalsField(frame, 'JWT app secret').fill(JITSI_SECRET);
    await saveGlobals(page, frame);

    // 3. The setup check names what is still missing on a fresh OpenEMR; the admin fixes it.
    let config = await setupCheck(page);
    await expect.poll(() => checkStatus(config, 'settings')).toBe('pass');
    await expect.poll(() => checkStatus(config, 'jwt')).not.toBe('fail');
    if ((await checkStatus(config, 'public-url')) === 'fail') {
      await expect(config.locator('#openvisit-checks li[data-check="public-url"]')).toContainText('Connectors');
      frame = await openGlobalsTab(page, 'Connectors');
      await globalsField(frame, 'Site Address').fill(BASE_URL);
      await saveGlobals(page, frame);
      config = await setupCheck(page);
    }
    const statuses = await config.locator('#openvisit-checks li').evaluateAll((items) => items.map((li) => `${li.getAttribute('data-check')}=${li.getAttribute('data-status')}`));
    console.log(`[install] setup check: ${statuses.join(' ')}`);
    expect(statuses.filter((s) => s.endsWith('=fail'))).toEqual([]);
    await page.screenshot({ path: `tests/e2e/results/install-${METHOD}-setup-check.png` });

    // 4. Menus are built at login; the Telehealth entry shows up on the next one.
    await page.goto('/interface/logout.php');
    await login(page, 'admin');

    // 5. A first patient, entered the usual way.
    expect(await openMenuEntry(page, 'Patient', 'New/Search')).toBe(true);
    const form = await frameByUrl(page, /\/interface\/new\/new\.php/);
    await form.locator('#form_fname').fill(PATIENT.first);
    await form.locator('#form_lname').fill(PATIENT.last);
    await form.locator('#form_DOB').fill(PATIENT.dob);
    await form.locator('#form_sex').selectOption({ label: 'Female' });
    await form.locator('#create').click();
    const confirm = await frameByUrl(page, /new_search_popup\.php/);
    // The button's handler is wired once the popup has loaded; older cores load it slower.
    await confirm.waitForLoadState('load');
    await confirm.getByRole('button', { name: 'Confirm Create New Patient' }).click();
    const saved = () => page.frames().some((f) => /demographics\.php/.test(f.url()));
    // OpenEMR 8.2 to 8.4 run this dialog's callback from an unload handler, which current Chrome
    // blocks; there the form's own confirm callback is called directly.
    const confirmed = await expect.poll(saved, { timeout: 10_000 }).toBe(true).then(() => true, () => false);
    if (!confirmed) {
      await form.evaluate(() => window.srcConfirmSave());
    }
    await expect.poll(saved, { timeout: 30_000 }).toBe(true);
    const leftOpen = page.locator('.modal.show .closeDlgIframe, .modal.show button.close').first();
    if (!confirmed && await leftOpen.isVisible()) {
      await leftOpen.click();
    }

    // 6. A video visit right away, with the patient joining from a phone.
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await today.locator('#openvisit-adhoc-search').fill(PATIENT.last);
    await today.locator('#openvisit-adhoc-find').click();
    const result = today.locator('#openvisit-adhoc-results li', { hasText: `${PATIENT.first} ${PATIENT.last}` });
    const [room] = await Promise.all([context.waitForEvent('page'), result.getByRole('button', { name: /start video visit/i }).click()]);
    await room.waitForLoadState('domcontentloaded');
    const eid = new URL(room.url()).searchParams.get('eid');
    await expect(room.locator('.openvisit-bar__who')).toContainText('Encounter');
    const jitsi = room.frameLocator('#openvisit-meet iframe').first();
    await jitsi.getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => room.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');

    await room.locator('#openvisit-invite-toggle').click();
    await room.locator('#openvisit-patient-link-create').click();
    await expect(room.locator('#openvisit-patient-link')).toHaveValue(new RegExp(`^${BASE_URL}/.*join\\.php\\?t=`), { timeout: 30_000 });
    const patientUrl = await room.locator('#openvisit-patient-link').inputValue();

    const phone = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true, permissions: ['camera', 'microphone'] });
    const patient = await phone.newPage();
    await patient.goto(patientUrl);
    const patientJitsi = patient.frameLocator('#openvisit-meet iframe').first();
    await patientJitsi.getByRole('button', { name: /ask to join|join meeting|join/i }).first().click({ timeout: 60_000 });
    await jitsi.getByRole('button', { name: /^admit$/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => patient.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');
    await expectMediaFlowing(room, 1);
    await expectMediaFlowing(patient, 1);
    await room.screenshot({ path: `tests/e2e/results/install-${METHOD}-first-visit.png` });

    await room.locator('#openvisit-end').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    await expect.poll(() => patient.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    await room.close();
    await phone.close();

    // 7. The visit is on record.
    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await todayAgain.getByRole('link', { name: /Visit history/ }).click();
    const history = await frameByUrl(page, /oe-module-openvisit\/public\/sessions\.php/);
    const visitRow = history.locator(`#openvisit-history tr[data-eid="${eid}"]`);
    await expect(visitRow).toContainText(`${PATIENT.first} ${PATIENT.last}`);
    await expect(visitRow).toContainText('ended');
  });
});
