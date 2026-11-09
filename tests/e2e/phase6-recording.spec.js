// @ts-check
// Phase 6 journey: the provider records a visit. Recording stays off while the provider is alone,
// starts after the consent confirmation once the patient is in, is announced to the patient,
// stops by hand and by itself when the patient leaves, and both files play from the history.
const { test, expect, devices } = require('@playwright/test');
const { login, clickTopMenu, frameByUrl, configureOpenVisit, expectMediaFlowing } = require('./helpers');

const JITSI_DOMAIN = process.env.JITSI_DOMAIN ?? 'localhost:8443';
const JITSI_APP_ID = process.env.JITSI_APP_ID ?? 'openvisit';
const JITSI_SECRET = process.env.JITSI_JWT_SECRET ?? '';
const RECORD_SECONDS = Number(process.env.RECORD_SECONDS ?? 20);
const PATIENT = { search: 'Underwood', name: 'Susan Underwood' };

test.setTimeout(600_000);

const state = (page, key) => page.evaluate((k) => window.openvisit && window.openvisit[k], key);

async function startRecording(room) {
  await expect(room.locator('#openvisit-record')).toBeEnabled({ timeout: 30_000 });
  await room.locator('#openvisit-record').click();
  await expect(room.locator('#openvisit-consent')).toBeVisible();
  await room.getByRole('button', { name: 'Patient agreed, start recording' }).click();
  await expect.poll(() => state(room, 'recording'), { timeout: 120_000 }).toBe('on');
  await expect(room.locator('#openvisit-rec-indicator')).toBeVisible();
}

async function waitUntilSaved(room, count) {
  await expect.poll(async () => (await state(room, 'recordings')).filter((r) => r.status === 'ready').length, { timeout: 180_000, intervals: [5_000] })
    .toBe(count);
}

test.describe('OpenVisit recording', () => {
  test.skip(JITSI_SECRET === '', 'set JITSI_JWT_SECRET to the Jitsi JWT_APP_SECRET');
  test.skip(process.env.RECORDING !== '1', 'set RECORDING=1 when the Jitsi server runs a Jibri');

  test('provider records a visit with the patient and plays it back', async ({ page, context, browser }) => {
    await configureOpenVisit(browser, { domain: JITSI_DOMAIN, appId: JITSI_APP_ID, secret: JITSI_SECRET, recording: true });
    await login(page, 'physician');
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const today = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await today.locator('#openvisit-adhoc-search').fill(PATIENT.search);
    await today.locator('#openvisit-adhoc-find').click();
    const result = today.locator('#openvisit-adhoc-results li', { hasText: PATIENT.name });
    const [room] = await Promise.all([context.waitForEvent('page'), result.getByRole('button', { name: /start video visit/i }).click()]);
    await room.waitForLoadState('domcontentloaded');
    const eid = new URL(room.url()).searchParams.get('eid');
    const jitsi = room.frameLocator('#openvisit-meet iframe').first();
    await jitsi.getByRole('button', { name: /join meeting|join/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => state(room, 'state'), { timeout: 60_000 }).toBe('joined');

    // Alone in the call: the button stays off, and the server refuses a direct request too.
    await expect.poll(() => state(room, 'people')).toBe(1);
    await expect(room.locator('#openvisit-record')).toBeDisabled();
    await expect(room.locator('#openvisit-record')).toHaveAttribute('title', /two people/);
    const refused = await room.evaluate(async () => {
      const cfg = JSON.parse(document.getElementById('openvisit-config').textContent);
      const body = new URLSearchParams({ action: 'recording.start', session_id: String(cfg.sessionId), csrf_token_form: cfg.csrf });
      return (await fetch(cfg.endUrl, { method: 'POST', body, credentials: 'same-origin' })).json();
    });
    expect(refused.ok).toBe(false);
    expect(refused.error).toMatch(/two people/);

    // The patient joins from a phone and is admitted.
    await room.locator('#openvisit-invite-toggle').click();
    await room.locator('#openvisit-patient-link-create').click();
    await expect(room.locator('#openvisit-patient-link')).toHaveValue(/join\.php\?t=/, { timeout: 30_000 });
    const patientUrl = await room.locator('#openvisit-patient-link').inputValue();
    await room.locator('#openvisit-invite-toggle').click();
    const phone = await browser.newContext({ ...devices['Pixel 7'], ignoreHTTPSErrors: true, permissions: ['camera', 'microphone'] });
    const patient = await phone.newPage();
    await patient.goto(patientUrl);
    await patient.frameLocator('#openvisit-meet iframe').first().getByRole('button', { name: /ask to join|join meeting|join/i }).first().click({ timeout: 60_000 });
    await jitsi.getByRole('button', { name: /^admit$/i }).first().click({ timeout: 60_000 });
    await expect.poll(() => state(patient, 'state'), { timeout: 60_000 }).toBe('joined');
    await expect.poll(() => state(room, 'people'), { timeout: 30_000 }).toBe(2);

    // First recording: consent confirmed, announced to the patient, stopped by the provider.
    await startRecording(room);
    await expect(patient.locator('#openvisit-recording-banner')).toBeVisible({ timeout: 30_000 });
    await expectMediaFlowing(room, 1);
    await expectMediaFlowing(patient, 1);
    await room.screenshot({ path: 'tests/e2e/results/recording-provider.png' });
    await patient.screenshot({ path: 'tests/e2e/results/recording-patient.png' });
    await room.waitForTimeout(RECORD_SECONDS * 1000);
    await room.locator('#openvisit-record').click();
    await expect.poll(() => state(room, 'recording'), { timeout: 60_000 }).toBe('off');
    expect(await state(room, 'stopReason')).toBe('manual');
    await expect(patient.locator('#openvisit-recording-banner')).toBeHidden({ timeout: 30_000 });
    await waitUntilSaved(room, 1);
    await expect(room.locator('#openvisit-record-message')).toContainText('Saved');

    // Second recording stops by itself when the patient closes the tab.
    await startRecording(room);
    await room.waitForTimeout(10_000);
    await patient.close({ runBeforeUnload: true });
    await phone.close();
    await expect.poll(() => state(room, 'people'), { timeout: 60_000 }).toBe(1);
    await expect.poll(() => state(room, 'recording'), { timeout: 60_000 }).toBe('off');
    expect(await state(room, 'stopReason')).toBe('people');
    await expect(room.locator('#openvisit-record')).toBeDisabled();
    await waitUntilSaved(room, 2);

    await room.locator('#openvisit-end').click();
    await expect.poll(() => state(room, 'state'), { timeout: 30_000 }).toBe('ended');
    await room.close();

    // Both recordings are in the visit history and the first one plays.
    await page.bringToFront();
    expect(await clickTopMenu(page, 'Telehealth')).toBe(true);
    const todayAgain = await frameByUrl(page, /oe-module-openvisit\/public\/index\.php/);
    await todayAgain.getByRole('link', { name: /Visit history/ }).click();
    const history = await frameByUrl(page, /oe-module-openvisit\/public\/sessions\.php/);
    const watch = history.locator(`#openvisit-history tr[data-eid="${eid}"] a[data-status="ready"]`);
    await expect(watch).toHaveCount(2);
    await watch.first().click();
    const player = await frameByUrl(page, /oe-module-openvisit\/public\/recording\.php\?id=/);
    const video = player.locator('#openvisit-player');
    await expect.poll(() => video.evaluate((v) => v.readyState), { timeout: 30_000 }).toBeGreaterThanOrEqual(1);
    const duration = await video.evaluate((v) => v.duration);
    expect(duration).toBeGreaterThanOrEqual(RECORD_SECONDS - 2);
    await video.evaluate((v) => { v.muted = true; return v.play(); });
    await expect.poll(() => video.evaluate((v) => v.currentTime), { timeout: 30_000 }).toBeGreaterThan(2);
    expect(await video.evaluate((v) => v.videoWidth)).toBeGreaterThan(0);
    await page.screenshot({ path: 'tests/e2e/results/recording-playback.png' });
  });
});
