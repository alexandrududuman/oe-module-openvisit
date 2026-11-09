// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Live call journey: a provider, a patient on a phone and a guest stay in one visit, media is
// checked on every side for the whole call, and the patient drops out and is let back in.
const { test, expect, devices } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, configureOpenVisit, callStats, expectMediaFlowing, isModerator } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const CALL_SECONDS = Number(process.env.CALL_SECONDS ?? 60);
const SAMPLE_SECONDS = 15;
const PATIENT = { search: 'Underwood', first: 'Susan', name: 'Susan Underwood' };

test.setTimeout((CALL_SECONDS + 360) * 1000);

// Opens a join link in a fresh browser context and knocks; the provider admits from the room.
async function knockAndGetAdmitted(browser, url, room, deviceOptions) {
  const context = await browser.newContext({ ...deviceOptions, ignoreHTTPSErrors: true, permissions: ['camera', 'microphone'] });
  const page = await context.newPage();
  await page.goto(url);
  await knock(page, room);
  return { context, page };
}

async function knock(page, room) {
  const jitsi = page.frameLocator('#openvisit-meet iframe').first();
  await jitsi.getByRole('button', { name: /ask to join|join meeting|join/i }).first().click({ timeout: 60_000 });
  await room.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /^admit$/i }).first().click({ timeout: 60_000 });
  await expect.poll(() => page.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');
}

test.describe('OpenVisit live call', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('provider, patient and guest stay in the visit with media flowing every way', async ({ page, context, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await today.locator('#openvisit-adhoc-search').fill(PATIENT.search);
    await today.locator('#openvisit-adhoc-find').click();
    const result = today.locator('#openvisit-adhoc-results li', { hasText: PATIENT.name });
    const [room] = await Promise.all([context.waitForEvent('page'), result.getByRole('button', { name: /start video visit/i }).click()]);
    await room.waitForLoadState('domcontentloaded');
    const eid = new URL(room.url()).searchParams.get('eid');
    await room.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => room.evaluate(() => window.openvisit && window.openvisit.state), { timeout: 60_000 }).toBe('joined');

    await room.locator('#openvisit-invite-toggle').click();
    await room.locator('#openvisit-patient-link-create').click();
    await expect(room.locator('#openvisit-patient-link')).toHaveValue(/join\.php\?t=/, { timeout: 30_000 });
    const patientUrl = await room.locator('#openvisit-patient-link').inputValue();
    await room.locator('#openvisit-guest-label').fill('Interpreter');
    await room.locator('#openvisit-guest-form button[type="submit"]').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.lastGuestLink), { timeout: 30_000 }).toMatch(/join\.php\?t=/);
    const guestUrl = await room.evaluate(() => window.openvisit.lastGuestLink);
    await room.locator('#openvisit-invite-toggle').click();

    const patient = await knockAndGetAdmitted(browser, patientUrl, room, devices['Pixel 7']);
    const guest = await knockAndGetAdmitted(browser, guestUrl, room, { viewport: { width: 1280, height: 800 } });
    const everyone = [room, patient.page, guest.page];
    for (const p of everyone) {
      await expectMediaFlowing(p, 2);
    }
    // Only the provider's token carries the moderator role; patients and guests cannot admit,
    // mute, remove or record.
    expect(await isModerator(room)).toBe(true);
    expect(await isModerator(patient.page)).toBe(false);
    expect(await isModerator(guest.page)).toBe(false);
    await room.screenshot({ path: 'tests/e2e/results/live-call-provider.png' });
    await patient.page.screenshot({ path: 'tests/e2e/results/live-call-patient.png' });

    // Stay in the call; every side keeps receiving audio and video the whole time.
    const until = Date.now() + CALL_SECONDS * 1000;
    while (Date.now() < until) {
      await room.waitForTimeout(Math.min(SAMPLE_SECONDS * 1000, Math.max(0, until - Date.now())));
      for (const p of everyone) {
        expect(await p.evaluate(() => window.openvisit.state)).toBe('joined');
        await expectMediaFlowing(p, 2, 20_000);
      }
      console.log(`[live-call] ${Math.round((until - Date.now()) / 1000)}s left`, JSON.stringify(await callStats(room)));
    }

    // The patient's connection drops (page reload): back to the lobby, admitted again, media resumes.
    await patient.page.reload();
    await knock(patient.page, room);
    for (const p of everyone) {
      await expectMediaFlowing(p, 2);
    }

    // Ending the visit closes the call for everyone, not just for the provider.
    await room.locator('#openvisit-end').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    for (const p of [patient.page, guest.page]) {
      await expect.poll(() => p.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
      await expect(p.locator('#openvisit-over')).toBeVisible();
      expect(await callStats(p)).toBeNull();
    }
    await patient.page.screenshot({ path: 'tests/e2e/results/live-call-patient-ended.png' });
    await room.close();
    await patient.context.close();
    await guest.context.close();

    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    const row = todayAgain.locator(`#openvisit-today tr[data-eid="${eid}"]`);
    await expect(row).toContainText('ended');
    await expect.poll(async () => Number(await row.locator('.openvisit-joined').innerText())).toBeGreaterThanOrEqual(3);
  });
});
