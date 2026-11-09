// @ts-check
// Phase 1 journey: admin points OpenVisit at Jitsi, a physician books a telehealth
// appointment in the calendar, starts the video visit, ends it, and the encounter exists.
const { test, expect } = require('@playwright/test');
const { login, openMenuEntry, clickTopMenu, frameBySrc, frameByUrl, waitForDialogToClose, globalsField, saveGlobals } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { search: 'Belford', first: 'Phil', last: 'Belford' };

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

test.describe('OpenVisit provider room', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('admin points OpenVisit at the Jitsi server', async ({ page }) => {
    await login(page, 'admin');
    expect(await openMenuEntry(page, 'Admin', 'Config')).toBe(true);
    let frame = frameBySrc(page, 'edit_globals.php');
    await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
    await globalsField(frame, 'Jitsi domain').fill(JITSI_DOMAIN);
    await globalsField(frame, 'JWT app id').fill(JITSI_APP_ID);
    await globalsField(frame, 'JWT app secret').fill(JITSI_SECRET);
    await saveGlobals(page, frame);

    frame = frameBySrc(page, 'edit_globals.php');
    await frame.locator('ul li a', { hasText: /^OpenVisit$/ }).click();
    await expect(globalsField(frame, 'Jitsi domain')).toHaveValue(JITSI_DOMAIN);

    // The Telehealth page no longer warns about missing setup.
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = frameBySrc(page, 'oe-module-openvisit/public/index.php');
    await expect(today.getByRole('heading', { name: /Telehealth visits today/ })).toBeVisible();
    await expect(today.locator('#openvisit-not-configured')).toHaveCount(0);
  });

  test('physician books a telehealth appointment, runs the visit and ends it', async ({ page, context }) => {
    await login(page, 'physician');

    // 1. New appointment from the calendar, in the Telehealth Visit category.
    const calendar = await frameByUrl(page, /\/main\/calendar\//);
    await calendar.getByRole('link', { name: '10:00', exact: true }).click();
    const dialog = await frameByUrl(page, /add_edit_event\.php/);
    await dialog.locator('#form_category').selectOption({ label: 'Telehealth Visit' });

    // The patient field opens a nested Patient Search dialog.
    await dialog.locator('#form_patient').click();
    const picker = await frameByUrl(page, /find_patient_popup/);
    await picker.locator('input[type="text"]').first().fill(PATIENT.search);
    await picker.locator('#submitbtn').click();
    await picker.locator('tr', { hasText: PATIENT.last }).first().click();
    await expect(dialog.locator('#form_patient')).toHaveValue(new RegExp(PATIENT.last));

    await dialog.locator('input[name="form_duration"]').fill('15');
    await dialog.locator('#form_save').click();
    await waitForDialogToClose(page, 'add_edit_event.php');

    // 2. Open the appointment again: the Start button is there.
    // The time link opens the appointment; the patient name link would open the chart.
    const eventTime = calendar.locator('a.event_time', { hasText: '10:00' }).last();
    await expect(eventTime).toBeVisible();
    await eventTime.click();
    const edit = await frameByUrl(page, /add_edit_event\.php.*eid=/);
    await edit.locator('#form_patient').waitFor();
    await expect(edit.locator('#form_patient')).toHaveValue(new RegExp(PATIENT.last));
    const start = edit.locator('#openvisit-start a');
    await expect(start).toBeVisible({ timeout: 30_000 });
    const eid = new URL(await start.getAttribute('href') ?? '', 'https://x').searchParams.get('eid');
    expect(eid).toMatch(/^\d+$/);

    // 3. Start opens the room in a new tab; the provider joins as moderator.
    const [room] = await Promise.all([context.waitForEvent('page'), start.click()]);
    await room.waitForLoadState('domcontentloaded');
    await expect(room.locator('#openvisit-status')).toBeVisible();
    // Jitsi shows its pre-join screen (camera and microphone check); the provider clicks Join.
    const jitsi = room.frameLocator('#openvisit-meet iframe').first();
    const joinButton = jitsi.getByRole('button', { name: /join meeting|join/i }).first();
    await joinButton.click({ timeout: 60_000 });
    await expect.poll(() => room.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 })
      .toBe('joined');
    await room.screenshot({ path: 'tests/e2e/results/physician-in-room.png' });

    // 4. End the visit.
    await room.locator('#openvisit-end').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    await expect(room.locator('#openvisit-ended')).toBeVisible();
    await room.screenshot({ path: 'tests/e2e/results/physician-visit-ended.png' });
    await room.close();

    // Back in OpenEMR the appointment dialog is still open; close it like a user would.
    await page.bringToFront();
    await edit.locator('#cancel').click();
    await waitForDialogToClose(page, 'add_edit_event.php');

    // 5. The Telehealth page shows the visit as ended.
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = frameBySrc(page, 'oe-module-openvisit/public/index.php');
    const row = today.locator(`#openvisit-today tr[data-eid="${eid}"]`);
    await expect(row).toContainText(PATIENT.last);
    await expect(row).toContainText('ended');
    await page.screenshot({ path: 'tests/e2e/results/physician-today-ended.png' });

    // 6. Clean up: the physician deletes the test appointment from the calendar.
    expect(await clickTopMenu(page, 'Calendar')).toBe(true);
    const calendarAgain = await frameByUrl(page, /\/main\/calendar\//);
    await calendarAgain.locator('a.event_time', { hasText: '10:00' }).last().click();
    const editAgain = await frameByUrl(page, /add_edit_event\.php.*eid=/);
    await editAgain.locator('#form_delete').click();
    await waitForDialogToClose(page, 'add_edit_event.php');
  });
});
