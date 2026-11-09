// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Phase 7 journey: the visit floats over OpenEMR. The provider moves to the calendar and the call
// tucks into the corner, the patient knocks and is admitted from the small window, the microphone
// is muted from it, and after being logged out the provider rejoins from the Telehealth page.
const { test, expect, devices } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, configureOpenVisit, callStats, expectMediaFlowing, dockedRoom, closeDockedRoom } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { search: 'Underwood', first: 'Susan', name: 'Susan Underwood' };

test.setTimeout(420_000);

const state = (frame) => frame.evaluate(() => window.openvisit && window.openvisit.state);

test.describe('OpenVisit floating window', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('the call keeps running in a corner window while the provider works elsewhere', async ({ page, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await today.locator('#openvisit-adhoc-search').fill(PATIENT.search);
    await today.locator('#openvisit-adhoc-find').click();
    await today.locator('#openvisit-adhoc-results li', { hasText: PATIENT.name }).getByRole('button', { name: /start video visit/i }).click();

    const win = page.locator('#openvisit-dock .ovd-window[data-kind="call"]');
    const room = await dockedRoom(page);
    await expect(win).toHaveAttribute('data-mode', 'full');
    await expect(win.locator('.ovd-title')).toHaveText(PATIENT.name);
    await room.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => state(room), { timeout: 60_000 }).toBe('joined');
    await expect(win).toHaveAttribute('data-state', 'joined');
    await room.locator('#openvisit-invite-toggle').click();
    await room.locator('#openvisit-patient-link-create').click();
    await expect(room.locator('#openvisit-patient-link')).toHaveValue(/join\.php\?t=/, { timeout: 30_000 });
    const patientUrl = await room.locator('#openvisit-patient-link').inputValue();
    await room.locator('#openvisit-invite-toggle').click();

    // Working elsewhere: the call tucks into the bottom-right corner.
    expect(await clickTopMenu(page, 'Calendar')).toBe(true);
    await expect(win).toHaveAttribute('data-mode', 'mini');
    const viewport = page.viewportSize() ?? { width: 0, height: 0 };
    await expect.poll(async () => {
      const box = await win.boundingBox();
      return Boolean(box && box.x + box.width > viewport.width - 40 && box.y + box.height > viewport.height - 40 && box.width < 400);
    }).toBe(true);

    // The patient knocks; the small window says who is waiting and admits them.
    const phone = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true, permissions: ['camera', 'microphone'] });
    const patient = await phone.newPage();
    await patient.goto(patientUrl);
    await patient.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /ask to join|join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect(win).toHaveAttribute('data-state', 'waiting', { timeout: 60_000 });
    await expect(win.locator('.ovd-notice')).toContainText(PATIENT.first);
    await page.screenshot({ path: 'tests/e2e/results/floating-knock.png' });
    await win.getByRole('button', { name: 'Admit' }).click();
    await expect.poll(() => state(patient), { timeout: 60_000 }).toBe('joined');
    await expect(win).toHaveAttribute('data-state', 'joined');
    await expectMediaFlowing(room, 1);
    await expectMediaFlowing(patient, 1);

    // Two people would go peer-to-peer after Jitsi's 5 s delay; the room keeps media on the bridge.
    await page.waitForTimeout(8_000);
    expect((await callStats(room)).p2p).toBe(false);
    expect((await callStats(patient)).p2p).toBe(false);

    // Mute from the small window; the room reports the change back.
    await win.locator('[data-action="mic"]').click();
    await expect(win.locator('[data-action="mic"]')).toHaveAttribute('aria-pressed', 'true');
    await page.screenshot({ path: 'tests/e2e/results/floating-mini.png' });
    await win.locator('[data-action="mic"]').click();
    await expect(win.locator('[data-action="mic"]')).toHaveAttribute('aria-pressed', 'false');

    // A reload of OpenEMR logs out and drops the call; after logging in again the provider rejoins.
    await page.reload();
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await todayAgain.locator('#openvisit-today tr', { hasText: PATIENT.name }).getByRole('link', { name: /Rejoin/ }).last().click();
    const again = await dockedRoom(page);
    await again.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => state(again), { timeout: 60_000 }).toBe('joined');
    await expectMediaFlowing(again, 1);
    await expectMediaFlowing(patient, 1);

    // End the visit from the full window.
    await expect(win).toHaveAttribute('data-mode', 'full');
    await again.locator('#openvisit-end').click();
    await expect.poll(() => state(again), { timeout: 30_000 }).toBe('ended');
    await expect(win).toHaveAttribute('data-state', 'ended');
    await expect.poll(() => state(patient), { timeout: 30_000 }).toBe('ended');
    await closeDockedRoom(page);
    await phone.close();
  });
});
