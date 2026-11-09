// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Phase 2 journey: the physician invites the patient, the patient joins from a phone with
// nothing but the link, waits in the lobby, is admitted, and the links can be revoked.
const { test, expect, devices } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, waitForDialogToClose, configureOpenVisit } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';

const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { search: 'Belford', first: 'Phil', last: 'Belford' };

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);

test.describe('OpenVisit patient access', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET (the admin step of phase 1 configures OpenEMR)');

  test('patient joins from a phone link, waits in the lobby and is admitted', async ({ page, context, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'physician');

    // Book a telehealth appointment at 11:00 (phase 1 uses 10:00).
    const calendar = await frameByUrl(page, /\/main\/calendar\//);
    await calendar.getByRole('link', { name: '11:00', exact: true }).click();
    const dialog = await frameByUrl(page, /add_edit_event\.php/);
    await dialog.locator('#form_category').selectOption({ label: 'Telehealth Visit' });
    await dialog.locator('#form_patient').click();
    const picker = await frameByUrl(page, /find_patient_popup/);
    await picker.locator('input[type="text"]').first().fill(PATIENT.search);
    await picker.locator('#submitbtn').click();
    await picker.locator('tr', { hasText: PATIENT.last }).first().click();
    await expect(dialog.locator('#form_patient')).toHaveValue(new RegExp(PATIENT.last));
    await dialog.locator('input[name="form_duration"]').fill('15');
    await dialog.locator('#form_save').click();
    await waitForDialogToClose(page, 'add_edit_event.php');

    // Start the visit from the Telehealth page this time.
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    const row = today.locator('#openvisit-today tr', { hasText: '11:00' }).filter({ hasText: PATIENT.last }).last();
    const [room] = await Promise.all([context.waitForEvent('page'), row.locator('a', { hasText: /Start video visit/ }).click()]);
    await room.waitForLoadState('domcontentloaded');
    const jitsi = room.frameLocator('#openvisit-meet iframe').first();
    await jitsi.getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => room.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');

    // Invite panel: create the patient link and a guest link.
    await room.locator('#openvisit-invite-toggle').click();
    await room.locator('#openvisit-patient-link-create').click();
    await expect(room.locator('#openvisit-patient-link')).toHaveValue(/join\.php\?t=/);
    const patientUrl = await room.locator('#openvisit-patient-link').inputValue();
    await room.locator('#openvisit-guest-label').fill('Interpreter');
    await room.locator('#openvisit-guest-form button[type="submit"]').click();
    await expect(room.locator('#openvisit-links li', { hasText: 'Interpreter' })).toBeVisible();
    const guestUrl = await room.evaluate(() => window.openvisit.lastGuestLink);
    expect(guestUrl).toMatch(/join\.php\?t=/);
    await room.screenshot({ path: 'tests/e2e/results/provider-invite-panel.png' });

    // The patient opens the link on a phone: no login, first name only, lobby.
    const phone = await browser.newContext({
      ...devices['Pixel 7'],
      ignoreHTTPSErrors: true,
      permissions: ['camera', 'microphone'],
    });
    const patient = await phone.newPage();
    await patient.goto(patientUrl);
    await expect(patient.locator('#openvisit-status')).toBeVisible();
    const patientJitsi = patient.frameLocator('#openvisit-meet iframe').first();
    await expect(patientJitsi.getByText(PATIENT.first, { exact: false }).first()).toBeVisible({ timeout: 60_000 });
    await expect(patientJitsi.getByText(PATIENT.last)).toHaveCount(0);
    await patientJitsi.getByRole('button', { name: /ask to join|join meeting|join/i }).first().click({ timeout: 60_000 });
    await patient.screenshot({ path: 'tests/e2e/results/patient-waiting.png' });

    // The provider sees the knock and admits.
    await jitsi.getByRole('button', { name: /admit/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => patient.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');
    await patient.screenshot({ path: 'tests/e2e/results/patient-in-visit.png' });

    // Revoking the guest link makes it useless.
    await room.locator('#openvisit-links li', { hasText: 'Interpreter' }).getByRole('button', { name: /revoke/i }).click();
    await expect(room.locator('#openvisit-links li.revoked, #openvisit-links li', { hasText: 'revoked' }).first()).toBeVisible();
    const guestPage = await phone.newPage();
    await guestPage.goto(guestUrl);
    await expect(guestPage.locator('#openvisit-problem')).toHaveAttribute('data-status', 'revoked');
    await guestPage.screenshot({ path: 'tests/e2e/results/guest-link-revoked.png' });
    await guestPage.close();

    // A wrong link says so without leaking anything.
    const bad = await phone.newPage();
    await bad.goto(patientUrl.replace(/t=[^&]+/, 't=not-a-real-token'));
    await expect(bad.locator('#openvisit-problem')).toHaveAttribute('data-status', 'unknown');
    await bad.close();

    // Patient leaves, provider ends; the Telehealth page shows who joined.
    await patient.close();
    await phone.close();
    await room.locator('#openvisit-end').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    await room.close();

    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    const rowAgain = todayAgain.locator('#openvisit-today tr', { hasText: '11:00' }).filter({ hasText: PATIENT.last }).last();
    await expect(rowAgain).toContainText('ended');
    await expect.poll(async () => Number(await rowAgain.locator('.openvisit-joined').innerText())).toBeGreaterThanOrEqual(2);
    await page.screenshot({ path: 'tests/e2e/results/today-after-patient-visit.png' });

    // Clean up the appointment.
    expect(await clickTopMenu(page, 'Calendar')).toBe(true);
    const calendarAgain = await frameByUrl(page, /\/main\/calendar\//);
    await calendarAgain.locator('a.event_time', { hasText: '11:00' }).last().click();
    const edit = await frameByUrl(page, /add_edit_event\.php.*eid=/);
    await edit.locator('#form_delete').click();
    await waitForDialogToClose(page, 'add_edit_event.php');
  });
});
