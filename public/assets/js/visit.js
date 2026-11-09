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
    });
    api.addListener('videoConferenceLeft', function () {
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

  loadApi().then(join).catch(function (error) {
    setState('error', error.message);
  });
})();
