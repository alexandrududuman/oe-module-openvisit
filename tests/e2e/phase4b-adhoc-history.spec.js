// @ts-check
// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Phase 4b journey: a physician starts a visit with a patient who has no appointment, ends it,
// finds it in the visit history, and a second call that day lands in the same encounter.
const { test, expect } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, configureOpenVisit, dockedRoom, closeDockedRoom } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const PATIENT = { search: 'Underwood', name: 'Susan Underwood' };

test.setTimeout(240_000);

// Starts an unscheduled visit from the Telehealth page, ends it, and returns its ids.
async function unscheduledCall(page) {
  expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
  const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
  await today.locator('#openvisit-adhoc-search').fill(PATIENT.search);
  await today.locator('#openvisit-adhoc-find').click();
  const result = today.locator('#openvisit-adhoc-results li', { hasText: PATIENT.name });
  await expect(result).toBeVisible();
  const pid = await result.getAttribute('data-pid');

  await result.getByRole('button', { name: /start video visit/i }).click();
  const room = await dockedRoom(page);
  await expect(room.locator('.openvisit-bar__who')).toContainText(PATIENT.name);
  await expect(room.locator('.openvisit-bar__who')).toContainText('Encounter');
  const eid = new URL(room.url()).searchParams.get('eid');
  expect(eid).toMatch(/^\d+$/);
  const encounter = (await room.locator('.openvisit-bar__who').innerText()).match(/Encounter #(\d+)/)?.[1];
  await room.locator('#openvisit-end').click();
  await expect.poll(() => room.evaluate(() => window.openvisit.state), { timeout: 30_000 }).toBe('ended');
  await closeDockedRoom(page);
  return { pid, eid, encounter };
}

test.describe('OpenVisit unscheduled visits', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');

  test('physician starts a visit without an appointment, sees it in the history, and calls again in the same encounter', async ({ page, context, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET });
    await login(page, 'physician');
    const first = await unscheduledCall(page);

    // The Telehealth page lists it as ended today, and the history page keeps it.
    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await expect(todayAgain.locator(`#openvisit-today tr[data-eid="${first.eid}"]`)).toContainText('ended');
    await todayAgain.getByRole('link', { name: /Visit history/ }).click();
    const history = await frameByUrl(page, /oe-module-openvisit\/public\/sessions\.php/);
    const row = history.locator(`#openvisit-history tr[data-eid="${first.eid}"]`);
    await expect(row).toContainText(PATIENT.name);
    await expect(row).toContainText('ended');
    await page.screenshot({ path: 'tests/e2e/results/physician-history.png' });

    // OpenEMR keeps one encounter per patient and day by default, so the second call joins the first one's.
    const second = await unscheduledCall(page);
    expect(second.eid).not.toBe(first.eid);
    expect(second.encounter).toBe(first.encounter);

    const chart = await context.newPage();
    await chart.goto(`/interface/patient_file/summary/demographics.php?set_pid=${first.pid}`);
    await chart.goto(`/interface/patient_file/encounter/encounter_top.php?set_encounter=${first.encounter}`);
    const forms = await frameByUrl(chart, /encounter\/forms\.php/);
    const card = forms.locator('#openvisit-encounter-visit');
    await expect(card).toContainText('Video visits');
    await expect(card.locator(`.ovr-visit__call[data-eid="${first.eid}"]`)).toContainText(/\d\d:\d\d to \d\d:\d\d/);
    await expect(card.locator(`.ovr-visit__call[data-eid="${second.eid}"]`)).toContainText(/\d\d:\d\d to \d\d:\d\d/);
    await chart.screenshot({ path: 'tests/e2e/results/encounter-two-calls.png' });
    await chart.close();
  });
});
