// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Phase 4b journey: a physician starts a visit with a patient who has no appointment, ends it,
// and finds it in the visit history.
const { test, expect } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, configureOpenVisit } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { search: 'Underwood', name: 'Susan Underwood' };

test.setTimeout(240_000);

test.describe('OpenVisit unscheduled visits', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('physician starts a visit without an appointment and sees it in the history', async ({ page, context, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);

    await today.locator('#openvisit-adhoc-search').fill(PATIENT.search);
    await today.locator('#openvisit-adhoc-find').click();
    const result = today.locator('#openvisit-adhoc-results li', { hasText: PATIENT.name });
    await expect(result).toBeVisible();

    const [room] = await Promise.all([context.waitForEvent('page'), result.getByRole('button', { name: /start video visit/i }).click()]);
    await room.waitForLoadState('domcontentloaded');
    await expect(room.locator('.openvisit-bar__who')).toContainText(PATIENT.name);
    await expect(room.locator('.openvisit-bar__who')).toContainText('Encounter');
    const eid = new URL(room.url()).searchParams.get('eid');
    expect(eid).toMatch(/^\d+$/);
    await room.locator('#openvisit-end').click();
    await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
    await room.close();

    // The Telehealth page lists it as ended today, and the history page keeps it.
    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await expect(todayAgain.locator(`#openvisit-today tr[data-eid="${eid}"]`)).toContainText('ended');
    await todayAgain.getByRole('link', { name: /Visit history/ }).click();
    const history = await frameByUrl(page, /oe-module-openvisit\/public\/sessions\.php/);
    const row = history.locator(`#openvisit-history tr[data-eid="${eid}"]`);
    await expect(row).toContainText(PATIENT.name);
    await expect(row).toContainText('ended');
    await page.screenshot({ path: 'tests/e2e/results/physician-history.png' });
  });
});
