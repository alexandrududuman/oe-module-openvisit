// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Phase 4 journey: a patient with a portal account finds Join on their telehealth appointment.
const { test, expect, devices } = require('@playwright/test');
const { login, frameByUrl, waitForDialogToClose, deleteAppointment, configureOpenVisit } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PORTAL = { user: process.env.PORTAL_USER ?? 'Phil1', pass: process.env.PORTAL_PASS ?? 'phil', email: process.env.PORTAL_EMAIL ?? 'heya@invalid.email.com', first: 'Phil', last: 'Belford' };

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

test.describe('OpenVisit patient portal', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('portal patient sees Join on the telehealth appointment and reaches the room', async ({ page, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });

    // Physician books a telehealth appointment at 12:00 for the portal patient.
    await login(page, 'physician');
    const calendar = await frameByUrl(page, /\/main\/calendar\//);
    await calendar.getByRole('link', { name: '12:00', exact: true }).click();
    const dialog = await frameByUrl(page, /add_edit_event\.php/);
    await dialog.locator('#form_category').selectOption({ label: 'Telehealth Visit' });
    await dialog.locator('#form_patient').click();
    const picker = await frameByUrl(page, /find_patient_popup/);
    await picker.locator('input[type="text"]').first().fill(PORTAL.last);
    await picker.locator('#submitbtn').click();
    await picker.locator('tr', { hasText: PORTAL.last }).first().click();
    await dialog.locator('input[name="form_duration"]').fill('15');
    await dialog.locator('#form_save').click();
    await waitForDialogToClose(page, 'add_edit_event.php');

    // The patient logs into the portal on a phone.
    const phone = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true, permissions: ['camera', 'microphone'] });
    const portal = await phone.newPage();
    portal.on('dialog', (d) => d.accept().catch(() => {}));
    await portal.goto('/portal/index.php?site=default');
    await portal.getByLabel('Username').fill(PORTAL.user);
    await portal.getByLabel('Password', { exact: true }).fill(PORTAL.pass);
    const emailField = portal.getByLabel(/E-?Mail/i);
    if (await emailField.count()) {
      await emailField.fill(PORTAL.email);
    }
    await portal.getByRole('button', { name: /log in|login|sign in/i }).first().click();
    await portal.waitForURL(/portal\/home\.php/, { timeout: 60_000 });

    // The appointments card is collapsed on the dashboard; the patient opens it first.
    const join = portal.locator('.card', { hasText: /12:00 pm/i }).locator('a.openvisit-portal-join').first();
    if (!(await join.isVisible().catch(() => false))) {
      await portal.locator('a[href="#appointmentcard"], [data-target="#appointmentcard"]').first().click();
    }
    await expect(join).toBeVisible({ timeout: 30_000 });
    await portal.screenshot({ path: 'tests/e2e/results/portal-join-button.png', fullPage: true });

    const [room] = await Promise.all([phone.waitForEvent('page'), join.click()]);
    await room.waitForURL(/join\.php\?t=/, { timeout: 30_000 });
    await expect(room.locator('#openvisit-status')).toBeVisible();
    const jitsi = room.frameLocator('#openvisit-meet iframe').first();
    await expect(jitsi.getByText(PORTAL.first, { exact: false }).first()).toBeVisible({ timeout: 60_000 });
    await room.screenshot({ path: 'tests/e2e/results/portal-patient-prejoin.png' });
    await phone.close();

    // Clean up the appointment.
    await deleteAppointment(page, '12:00');
  });
});
