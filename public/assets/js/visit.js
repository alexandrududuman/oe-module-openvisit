// Provider room: loads the Jitsi IFrame API from the clinic's own server and drives the visit.
(function () {
  'use strict';

  const config = JSON.parse(document.getElementById('openvisit-config').textContent);
  const status = document.getElementById('openvisit-status');
  const endButton = document.getElementById('openvisit-end');
  const meet = document.getElementById('openvisit-meet');
  const ended = document.getElementById('openvisit-ended');
  const endedDetail = document.getElementById('openvisit-ended-detail');
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
    });
    api.addListener('videoConferenceLeft', function () {
      reportProvider('participant.left');
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

  function endVisit() {
    endButton.disabled = true;
    const body = new URLSearchParams({ action: 'end', session_id: String(config.sessionId), csrf_token_form: config.csrf });
    fetch(config.endUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result.ok) {
          throw new Error(result.error || 'Could not end the visit');
        }
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

  loadApi().then(join).catch(function (error) {
    setState('error', error.message);
  });
})();
