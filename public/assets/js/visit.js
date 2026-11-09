// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Provider room: loads the Jitsi IFrame API from the clinic's own server and drives the visit.
(function () {
  'use strict';

  const config = JSON.parse(document.getElementById('openvisit-config').textContent);
  const status = document.getElementById('openvisit-status');
  const endButton = document.getElementById('openvisit-end');
  const meet = document.getElementById('openvisit-meet');
  const ended = document.getElementById('openvisit-ended');
  const endedDetail = document.getElementById('openvisit-ended-detail');
  const CLOSE_TIMEOUT_MS = 5000;
  const recording = config.recording || { enabled: false, minPeople: 2 };
  let api = null;

  // Exposed for tests and support: state is loading | joined | left | ended | error.
  window.openvisit = { state: 'loading', config: { room: config.room, domain: config.domain } };

  function setState(state, text) {
    window.openvisit.state = state;
    status.dataset.state = state;
    if (text) {
      status.textContent = text;
    }
  }

  function loadApi() {
    return new Promise(function (resolve, reject) {
      if (window.JitsiMeetExternalAPI) {
        resolve();
        return;
      }
      const script = document.createElement('script');
      script.src = 'https://' + config.domain + '/external_api.js';
      script.onload = resolve;
      script.onerror = function () { reject(new Error('Could not load the video server at ' + config.domain)); };
      document.head.appendChild(script);
    });
  }

  function join() {
    api = new window.JitsiMeetExternalAPI(config.domain, {
      roomName: config.room,
      jwt: config.jwt,
      parentNode: meet,
      userInfo: { displayName: config.displayName },
      configOverwrite: {
        prejoinConfig: { enabled: true },
        disableDeepLinking: true,
        startWithAudioMuted: false,
        startWithVideoMuted: false,
        enableWelcomePage: false,
        hideConferenceSubject: false,
        subject: config.subject,
        toolbarButtons: ['microphone', 'camera', 'desktop', 'chat', 'participants-pane', 'tileview', 'settings', 'fullscreen', 'hangup'],
      },
      interfaceConfigOverwrite: {
        SHOW_JITSI_WATERMARK: false,
        SHOW_WATERMARK_FOR_GUESTS: false,
        MOBILE_APP_PROMO: false,
      },
    });

    api.addListener('videoConferenceJoined', function () {
      setState('joined', 'In the room');
      if (config.moderator) {
        api.executeCommand('toggleLobby', true);
        api.executeCommand('subject', config.subject);
      }
      reportProvider('participant.joined');
      countPeople();
    });
    if (recording.enabled) {
      api.addListener('participantJoined', countPeople);
      api.addListener('participantLeft', countPeople);
      api.addListener('recordingStatusChanged', onRecordingStatus);
    }
    api.addListener('videoConferenceLeft', function () {
      reportProvider('participant.left');
      if (window.openvisit.recording !== 'off') {
        recordingState('off');
      }
      if (window.openvisit.state !== 'ended') {
        setState('left', 'You left the room. Rejoin by reloading this page, or end the visit.');
      }
    });
    api.addListener('errorOccurred', function (event) {
      if (event && event.error && event.error.isFatal) {
        setState('error', 'Video connection failed: ' + (event.error.name || 'unknown error'));
      }
    });
  }

  // Closes the Jitsi conference for everyone, so patient and guests are not left talking to each other.
  function closeConference() {
    return new Promise(function (resolve) {
      if (!api || window.openvisit.state !== 'joined') {
        resolve();
        return;
      }
      const timer = setTimeout(resolve, CLOSE_TIMEOUT_MS);
      api.addListener('videoConferenceLeft', function () { clearTimeout(timer); resolve(); });
      api.executeCommand('endConference');
    });
  }

  function endVisit() {
    endButton.disabled = true;
    const body = new URLSearchParams({ action: 'end', session_id: String(config.sessionId), csrf_token_form: config.csrf });
    stopRecordingBeforeEnd()
      .then(function () { return fetch(config.endUrl, { method: 'POST', body: body, credentials: 'same-origin' }); })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result.ok) {
          throw new Error(result.error || 'Could not end the visit');
        }
        return closeConference().then(function () { return result; });
      })
      .then(function (result) {
        if (api) {
          api.executeCommand('hangup');
          api.dispose();
          api = null;
        }
        meet.classList.add('d-none');
        ended.classList.remove('d-none');
        endedDetail.textContent = result.durationMinutes === null || result.durationMinutes === undefined
          ? ''
          : 'Duration: ' + result.durationMinutes + ' min';
        setState('ended', 'Visit ended');
        window.openvisit.result = result;
      })
      .catch(function (error) {
        endButton.disabled = false;
        setState('error', error.message);
      });
  }

  endButton.addEventListener('click', endVisit);

  // ----- Invite panel -----
  const panel = document.getElementById('openvisit-invite');
  const toggle = document.getElementById('openvisit-invite-toggle');
  const patientLink = document.getElementById('openvisit-patient-link');
  const createPatient = document.getElementById('openvisit-patient-link-create');
  const copyPatient = document.getElementById('openvisit-patient-link-copy');
  const emailPatient = document.getElementById('openvisit-patient-link-email');
  const guestForm = document.getElementById('openvisit-guest-form');
  const guestLabel = document.getElementById('openvisit-guest-label');
  const list = document.getElementById('openvisit-links');
  const message = document.getElementById('openvisit-invite-message');
  window.openvisit.links = [];

  function say(text, isError) {
    message.textContent = text;
    message.classList.toggle('error', Boolean(isError));
  }

  function post(fields) {
    const body = new URLSearchParams(Object.assign({ session_id: String(config.sessionId), csrf_token_form: config.csrf }, fields));
    return fetch(config.endUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (result) {
        if (!result.ok) { throw new Error(result.error || 'Request failed'); }
        return result;
      });
  }

  function renderLinks(links) {
    window.openvisit.links = links;
    list.innerHTML = '';
    links.forEach(function (link) {
      const item = document.createElement('li');
      const text = document.createElement('span');
      text.textContent = (link.role === 'guest' ? (link.label || 'Guest') : 'Patient') + ' · ' + (link.revoked ? 'revoked' : 'until ' + link.expiresAt) + (link.uses ? ' · opened ' + link.uses + 'x' : '');
      if (link.revoked) { text.classList.add('revoked'); }
      item.appendChild(text);
      if (!link.revoked) {
        const revoke = document.createElement('button');
        revoke.type = 'button';
        revoke.className = 'btn btn-outline-danger btn-sm';
        revoke.textContent = 'Revoke';
        revoke.dataset.tokenId = String(link.id);
        revoke.addEventListener('click', function () {
          post({ action: 'invite.revoke', token_id: String(link.id) }).then(refresh).then(function () { say('Link revoked'); }).catch(function (e) { say(e.message, true); });
        });
        item.appendChild(revoke);
      }
      list.appendChild(item);
    });
  }

  function refresh() {
    return post({ action: 'invite.list' }).then(function (result) {
      renderLinks(result.links);
      emailPatient.classList.toggle('d-none', !result.emailEnabled);
    });
  }

  function showNewLink(link) {
    if (link.role === 'patient') {
      patientLink.value = link.url;
      copyPatient.disabled = false;
      window.openvisit.patientLink = link.url;
    } else {
      window.openvisit.lastGuestLink = link.url;
      say('Guest link for ' + (link.label || 'guest') + ' copied below. Share it once; it is not shown again.');
      patientLink.value = link.url;
      copyPatient.disabled = false;
    }
  }

  toggle.addEventListener('click', function () {
    panel.classList.toggle('d-none');
    if (!panel.classList.contains('d-none')) { refresh().catch(function (e) { say(e.message, true); }); }
  });
  createPatient.addEventListener('click', function () {
    post({ action: 'invite.create', role: 'patient' })
      .then(function (result) { showNewLink(result.link); say('Patient link ready. Earlier patient links keep working until they expire; revoke them below if needed.'); return refresh(); })
      .catch(function (e) { say(e.message, true); });
  });
  copyPatient.addEventListener('click', function () {
    const value = patientLink.value;
    const done = function () { say('Copied'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, function () { patientLink.select(); document.execCommand('copy'); done(); });
    } else {
      patientLink.select(); document.execCommand('copy'); done();
    }
  });
  emailPatient.addEventListener('click', function () {
    post({ action: 'invite.email' })
      .then(function (result) { showNewLink(result.link); say('Email sent to ' + result.sentTo); return refresh(); })
      .catch(function (e) { say(e.message, true); });
  });
  guestForm.addEventListener('submit', function (event) {
    event.preventDefault();
    post({ action: 'invite.create', role: 'guest', label: guestLabel.value })
      .then(function (result) { showNewLink(result.link); guestLabel.value = ''; return refresh(); })
      .catch(function (e) { say(e.message, true); });
  });

  // ----- Participant audit for the provider -----
  let participantId = null;
  function reportProvider(action) {
    return post({ action: action, participant_id: String(participantId || 0), display_name: config.displayName })
      .then(function (result) { if (result.participantId) { participantId = result.participantId; } })
      .catch(function () {});
  }

  // ----- Recording: the provider records once at least two people are in the call -----
  const RECORDING_START_TIMEOUT_MS = 60000;
  const RECORDING_POLL_MS = 5000;
  const RECORDING_POLL_LIMIT = 120;
  const recordButton = document.getElementById('openvisit-record');
  const recordLabel = document.getElementById('openvisit-record-label');
  const recordIndicator = document.getElementById('openvisit-rec-indicator');
  const consent = document.getElementById('openvisit-consent');
  const recordMessage = document.getElementById('openvisit-record-message');
  let recordingId = null;
  let startTimer = null;
  let stopNote = '';
  // recording is off | confirming | starting | on | stopping; stopReason is manual | people | ended.
  window.openvisit.recording = 'off';
  window.openvisit.people = 0;
  window.openvisit.recordings = [];

  function recordingState(state) {
    window.openvisit.recording = state;
    renderRecording();
  }

  function renderRecording() {
    if (!recordButton) { return; }
    const state = window.openvisit.recording;
    const enough = window.openvisit.people >= recording.minPeople;
    recordButton.disabled = !(state === 'on' || (state === 'off' && enough && window.openvisit.state === 'joined'));
    recordButton.title = enough || state === 'on' ? '' : 'Recording needs at least two people in the call';
    recordLabel.textContent = { on: 'Stop recording', starting: 'Starting…', stopping: 'Stopping…' }[state] || 'Record';
    recordIndicator.classList.toggle('d-none', state !== 'on');
    consent.classList.toggle('d-none', state !== 'confirming');
  }

  function recordingNote(text, isError) {
    recordMessage.textContent = text;
    recordMessage.classList.toggle('error', Boolean(isError));
    recordMessage.classList.toggle('d-none', text === '');
  }

  function countPeople() {
    if (!api || !recording.enabled) { return; }
    window.openvisit.people = api.getNumberOfParticipants();
    const enough = window.openvisit.people >= recording.minPeople;
    if (!enough && window.openvisit.recording === 'on') {
      stopRecording('people', 'Recording stopped: fewer than two people are in the call.');
    }
    if (!enough && window.openvisit.recording === 'confirming') {
      recordingState('off');
    }
    renderRecording();
  }

  function startRecording() {
    recordingState('starting');
    recordingNote('');
    post({ action: 'recording.start' })
      .then(function (result) {
        recordingId = result.recording.id;
        startTimer = setTimeout(function () {
          recordingFailed('The recorder did not start. Ask your administrator to check the Jibri service.');
        }, RECORDING_START_TIMEOUT_MS);
        api.executeCommand('startRecording', { mode: 'file' });
      })
      .catch(function (e) {
        recordingState('off');
        recordingNote(e.message, true);
      });
  }

  function stopRecording(reason, note) {
    if (window.openvisit.recording !== 'on') { return; }
    window.openvisit.stopReason = reason;
    stopNote = note;
    recordingState('stopping');
    recordingNote(note);
    api.executeCommand('stopRecording', 'file');
  }

  function recordingFailed(text) {
    clearTimeout(startTimer);
    if (recordingId !== null) {
      post({ action: 'recording.failed', recording_id: String(recordingId) }).catch(function () {});
    }
    recordingId = null;
    recordingState('off');
    recordingNote(text, true);
  }

  function onRecordingStatus(event) {
    if (event.mode && event.mode !== 'file') { return; }
    const state = window.openvisit.recording;
    if (event.on && state === 'starting') {
      clearTimeout(startTimer);
      recordingState('on');
      post({ action: 'recording.started', recording_id: String(recordingId) }).catch(function () {});
      return;
    }
    if (!event.on && state === 'starting' && event.error) {
      recordingFailed('The recording could not start (' + event.error + ').');
      return;
    }
    if (!event.on && (state === 'on' || state === 'stopping')) {
      const id = recordingId;
      recordingId = null;
      if (state === 'on') {
        window.openvisit.stopReason = 'manual';
        stopNote = 'Recording stopped.';
      }
      recordingState('off');
      recordingNote(stopNote + ' Saving it…');
      post({ action: 'recording.stopped', recording_id: String(id) })
        .then(function () { waitUntilSaved(id, 0); })
        .catch(function () {});
    }
  }

  // Jibri finalizes the file after the stop; the server files it and the room says so.
  function waitUntilSaved(id, attempt) {
    post({ action: 'recording.list' })
      .then(function (result) {
        window.openvisit.recordings = result.recordings;
        const saved = result.recordings.filter(function (r) { return r.id === id; })[0];
        if (saved && saved.status === 'ready') {
          recordingNote(stopNote + ' Saved: watch it from Visit history.');
          return;
        }
        if (saved && saved.status === 'failed') {
          recordingNote('The recording could not be saved.', true);
          return;
        }
        if (attempt < RECORDING_POLL_LIMIT) {
          setTimeout(function () { waitUntilSaved(id, attempt + 1); }, RECORDING_POLL_MS);
        }
      })
      .catch(function () {});
  }

  function stopRecordingBeforeEnd() {
    const state = window.openvisit.recording;
    if (!api || recordingId === null || (state !== 'on' && state !== 'starting')) {
      return Promise.resolve();
    }
    const id = recordingId;
    recordingId = null;
    clearTimeout(startTimer);
    window.openvisit.stopReason = 'ended';
    recordingState('off');
    api.executeCommand('stopRecording', 'file');
    return post({ action: 'recording.stopped', recording_id: String(id) }).catch(function () {});
  }

  if (recordButton) {
    recordButton.addEventListener('click', function () {
      if (window.openvisit.recording === 'on') {
        stopRecording('manual', 'Recording stopped.');
        return;
      }
      recordingState('confirming');
    });
    document.getElementById('openvisit-consent-yes').addEventListener('click', startRecording);
    document.getElementById('openvisit-consent-no').addEventListener('click', function () { recordingState('off'); });
  }

  loadApi().then(join).catch(function (error) {
    setState('error', error.message);
  });
})();
