// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Patient and guest room: joins with a non-moderator token and waits in the lobby until admitted.
(function () {
  'use strict';

  const config = JSON.parse(document.getElementById('openvisit-config').textContent);
  const status = document.getElementById('openvisit-status');
  const meet = document.getElementById('openvisit-meet');
  const left = document.getElementById('openvisit-left');
  const over = document.getElementById('openvisit-over');
  const recordingBanner = document.getElementById('openvisit-recording-banner');
  let participantId = null;

  window.openvisit = { state: 'loading', recording: 'off' };

  function setState(state, text) {
    window.openvisit.state = state;
    status.dataset.state = state;
    status.textContent = text;
  }

  function report(action) {
    const body = new URLSearchParams({ t: config.token, action: action, participant_id: String(participantId || 0), site: config.site });
    return fetch(config.eventsUrl + '?site=' + encodeURIComponent(config.site), { method: 'POST', body: body, credentials: 'same-origin', keepalive: true })
      .then(function (r) { return r.json(); })
      .then(function (result) {
        if (result && result.participantId) { participantId = result.participantId; }
        return result || {};
      })
      .catch(function () { return {}; });
  }

  function loadApi() {
    return new Promise(function (resolve, reject) {
      const script = document.createElement('script');
      script.src = 'https://' + config.domain + '/external_api.js';
      script.onload = resolve;
      script.onerror = function () { reject(new Error('Could not reach the video server')); };
      document.head.appendChild(script);
    });
  }

  function join() {
    const api = new window.JitsiMeetExternalAPI(config.domain, {
      roomName: config.room,
      jwt: config.jwt,
      parentNode: meet,
      userInfo: { displayName: config.displayName },
      configOverwrite: {
        prejoinConfig: { enabled: true },
        disableDeepLinking: true,
        // Media always goes through the clinic's bridge: no IP addresses exchanged between
        // participants, and no P2P-to-bridge switch that can leave a sender paused after a reconnect.
        p2p: { enabled: false },
        enableWelcomePage: false,
        readOnlyName: true,
        subject: 'Video visit',
        hideConferenceSubject: true,
        toolbarButtons: ['microphone', 'camera', 'chat', 'tileview', 'settings', 'fullscreen', 'hangup'],
      },
      interfaceConfigOverwrite: { SHOW_JITSI_WATERMARK: false, MOBILE_APP_PROMO: false },
    });
    setState('waiting', 'Waiting for the clinic to let you in');
    api.addListener('videoConferenceJoined', function () {
      setState('joined', 'You are in the visit');
      report('joined');
    });
    // Patients and guests always see when the visit is being recorded.
    api.addListener('recordingStatusChanged', function (event) {
      if (event.mode && event.mode !== 'file') { return; }
      window.openvisit.recording = event.on ? 'on' : 'off';
      recordingBanner.classList.toggle('d-none', !event.on);
    });
    api.addListener('videoConferenceLeft', function () {
      recordingBanner.classList.add('d-none');
      meet.classList.add('d-none');
      setState('left', 'You left');
      report('left').then(function (result) {
        if (result.ended) {
          over.classList.remove('d-none');
          setState('ended', 'The visit has ended');
          return;
        }
        left.classList.remove('d-none');
      });
    });
    api.addListener('errorOccurred', function (event) {
      if (event && event.error && event.error.isFatal) {
        setState('error', 'Connection failed. Reload the page to try again.');
      }
    });
    window.addEventListener('pagehide', function () { if (window.openvisit.state === 'joined') { report('left'); } });
  }

  loadApi().then(join).catch(function (error) { setState('error', error.message); });
})();
