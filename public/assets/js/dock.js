// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Floating windows on OpenEMR's main page: the live visit, which keeps running while the provider
// works in other tabs, and the recording player. Pages in tabs reach them through
// window.top.openvisitDock; without it they open a browser tab instead.
(function () {
  'use strict';

  if (window.openvisitDock || window.top !== window) {
    return;
  }
  // Core adds module scripts to <head>, before <body> exists.
  if (!document.body) {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    const STORE_KEY = 'openvisit.dock';
    const MARGIN = 16;
    const LEAVE_TIMEOUT_MS = 4000;
    const ICONS = {
      mic: '<path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/>',
      micOff: '<path d="M15 9.4V6a3 3 0 0 0-5.7-1.3M9 9v3a3 3 0 0 0 5.1 2.1M19 11a7 7 0 0 1-1.2 3.9M5 11a7 7 0 0 0 11.4 5.4M12 18v3M3 3l18 18"/>',
      camera: '<path d="M15 10l5-3v10l-5-3"/><rect x="3" y="6" width="12" height="12" rx="2"/>',
      cameraOff: '<path d="M15 10l5-3v10l-5-3M9 6h4a2 2 0 0 1 2 2v4M15 16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2M3 3l18 18"/>',
      shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
      expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
      popout: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
      leave: '<path d="M3.5 14.5c4.7-4.7 12.3-4.7 17 0l-2.3 2.3-3.2-1.6v-2.4a11 11 0 0 0-6 0v2.4l-3.2 1.6Z"/>',
      close: '<path d="M6 6l12 12M18 6L6 18"/>',
    };

    const root = document.createElement('div');
    root.id = 'openvisit-dock';
    document.body.appendChild(root);
    const windows = {};

    function icon(name) {
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
    }

    function button(action, label, iconName) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'ovd-button';
      b.dataset.action = action;
      b.setAttribute('aria-label', label);
      b.title = label;
      b.innerHTML = icon(iconName);
      return b;
    }

    // Where the provider likes each window; OpenEMR logs out on a reload, so the call itself is not kept.
    function remember() {
      try { sessionStorage.setItem(STORE_KEY, JSON.stringify({ positions: positions() })); } catch (e) { /* private mode */ }
    }

    function stored() {
      try { return JSON.parse(sessionStorage.getItem(STORE_KEY) || '{}') || {}; } catch (e) { return {}; }
    }

    const savedPositions = stored().positions || {};
    function positions() {
      Object.keys(windows).forEach(function (kind) {
        const w = windows[kind];
        if (w.moved) { savedPositions[kind + ':' + w.mode] = { left: w.el.offsetLeft, top: w.el.offsetTop }; }
      });
      return savedPositions;
    }

    // ----- One floating window: a bar to drag it by, a body with the room or the player -----
    function createWindow(kind, title) {
      const el = document.createElement('section');
      el.className = 'ovd-window';
      el.dataset.kind = kind;
      el.dataset.mode = 'full';
      el.dataset.state = kind === 'call' ? 'connecting' : 'player';
      el.setAttribute('role', 'dialog');

      const bar = document.createElement('header');
      bar.className = 'ovd-bar';
      const status = document.createElement('span');
      status.className = 'ovd-status';
      status.setAttribute('aria-hidden', 'true');
      const text = document.createElement('div');
      text.className = 'ovd-text';
      const heading = document.createElement('h2');
      heading.className = 'ovd-title';
      const detail = document.createElement('p');
      detail.className = 'ovd-detail';
      detail.setAttribute('role', 'status');
      text.append(heading, detail);
      const actions = document.createElement('div');
      actions.className = 'ovd-actions';
      bar.append(status, text, actions);

      const notice = document.createElement('div');
      notice.className = 'ovd-notice';
      notice.hidden = true;
      const body = document.createElement('div');
      body.className = 'ovd-body';
      el.append(bar, notice, body);
      root.appendChild(el);

      const w = { kind: kind, el: el, bar: bar, heading: heading, detail: detail, actions: actions, notice: notice, body: body, mode: 'full', moved: false };
      setTitle(w, title);
      makeDraggable(w);
      el.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && w.mode === 'full') { setMode(w, 'mini'); }
      });
      windows[kind] = w;
      return w;
    }

    function setTitle(w, title) {
      w.title = title || (w.kind === 'call' ? 'Video visit' : 'Visit recording');
      w.heading.textContent = w.title;
      w.el.setAttribute('aria-label', w.kind === 'call' ? 'Video visit with ' + w.title : w.title);
    }

    function setDetail(w, text) {
      w.detail.textContent = text;
    }

    function place(w) {
      const saved = savedPositions[w.kind + ':' + w.mode];
      const el = w.el;
      el.style.left = '';
      el.style.top = '';
      el.style.right = '';
      el.style.bottom = '';
      if (saved) {
        keepInView(w, saved.left, saved.top);
        return;
      }
      if (w.mode === 'mini') {
        const stacked = w.kind === 'player' && windows.call && windows.call.mode === 'mini';
        el.style.right = MARGIN + 'px';
        el.style.bottom = (stacked ? MARGIN * 2 + windows.call.el.offsetHeight : MARGIN) + 'px';
        return;
      }
      el.style.right = (MARGIN * 2) + 'px';
      el.style.top = (w.kind === 'player' ? 120 : 96) + 'px';
    }

    function keepInView(w, left, top) {
      const maxLeft = Math.max(MARGIN, window.innerWidth - w.el.offsetWidth - MARGIN);
      const maxTop = Math.max(MARGIN, window.innerHeight - w.el.offsetHeight - MARGIN);
      w.el.style.right = '';
      w.el.style.bottom = '';
      w.el.style.left = Math.min(Math.max(MARGIN, left), maxLeft) + 'px';
      w.el.style.top = Math.min(Math.max(MARGIN, top), maxTop) + 'px';
    }

    // A mini window settles into the nearest corner, the way a phone call bubble does.
    function snapToCorner(w) {
      const rect = w.el.getBoundingClientRect();
      const left = rect.left + rect.width / 2 < window.innerWidth / 2 ? MARGIN : window.innerWidth - rect.width - MARGIN;
      const top = rect.top + rect.height / 2 < window.innerHeight / 2 ? MARGIN : window.innerHeight - rect.height - MARGIN;
      keepInView(w, left, top);
    }

    function makeDraggable(w) {
      let start = null;
      w.bar.addEventListener('pointerdown', function (event) {
        if (event.button !== 0 || event.target.closest('button')) { return; }
        const rect = w.el.getBoundingClientRect();
        start = { x: event.clientX, y: event.clientY, left: rect.left, top: rect.top };
        w.bar.setPointerCapture(event.pointerId);
        w.el.classList.add('ovd-dragging');
      });
      w.bar.addEventListener('pointermove', function (event) {
        if (!start) { return; }
        keepInView(w, start.left + event.clientX - start.x, start.top + event.clientY - start.y);
      });
      function end() {
        if (!start) { return; }
        start = null;
        w.el.classList.remove('ovd-dragging');
        if (w.mode === 'mini') { snapToCorner(w); }
        w.moved = true;
        remember();
      }
      w.bar.addEventListener('pointerup', end);
      w.bar.addEventListener('pointercancel', end);
      w.bar.addEventListener('dblclick', function (event) {
        if (!event.target.closest('button')) { setMode(w, w.mode === 'full' ? 'mini' : 'full'); }
      });
    }

    function setMode(w, mode) {
      w.mode = mode;
      w.el.dataset.mode = mode;
      const toggle = w.actions.querySelector('[data-action="mode"]');
      if (toggle) {
        const label = mode === 'full' ? 'Minimize' : 'Expand';
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.innerHTML = icon(mode === 'full' ? 'shrink' : 'expand');
      }
      w.moved = Boolean(savedPositions[w.kind + ':' + mode]);
      place(w);
      if (w.kind === 'call') {
        tellRoom(w, { command: 'mode', mode: mode });
        remember();
      }
      if (w.kind === 'call' && windows.player && windows.player.mode === 'mini') { place(windows.player); }
    }

    function closeWindow(w) {
      w.el.remove();
      delete windows[w.kind];
      remember();
      if (w.kind === 'call' && windows.player && windows.player.mode === 'mini') { place(windows.player); }
    }

    // ----- The live visit -----
    function tellRoom(w, message) {
      if (w.frame && w.frame.contentWindow) {
        w.frame.contentWindow.postMessage(Object.assign({ openvisit: 'dock' }, message), window.location.origin);
      }
    }

    function withParams(url, params) {
      const u = new URL(url, window.location.href);
      Object.keys(params).forEach(function (k) { u.searchParams.set(k, params[k]); });
      return u.pathname + u.search;
    }

    function openCall(url, title) {
      const current = windows.call;
      if (current) {
        if (sameVisit(current.url, url)) {
          setMode(current, 'full');
          current.el.focus();
          return;
        }
        if (current.state !== 'ended' && current.state !== 'left' && !window.confirm('Leave the visit with ' + current.title + ' and open this one?')) {
          return;
        }
        leave(current, true);
      }

      const w = createWindow('call', title);
      w.url = url;
      w.state = 'connecting';
      w.el.tabIndex = -1;
      const mic = button('mic', 'Mute microphone', 'mic');
      const camera = button('camera', 'Turn camera off', 'camera');
      mic.classList.add('ovd-mini-only');
      camera.classList.add('ovd-mini-only');
      w.actions.append(mic, camera, button('mode', 'Minimize', 'shrink'), button('popout', 'Open in a browser tab', 'popout'), button('leave', 'Leave the call', 'leave'));
      w.actions.querySelector('[data-action="popout"]').classList.add('ovd-full-only');
      w.actions.querySelector('[data-action="leave"]').classList.add('ovd-danger');
      w.actions.addEventListener('click', function (event) { onCallAction(w, event); });
      w.notice.addEventListener('click', function (event) { onNoticeAction(w, event); });

      const frame = document.createElement('iframe');
      frame.title = 'Video visit room';
      frame.setAttribute('allow', 'camera; microphone; display-capture; autoplay; fullscreen; clipboard-write');
      frame.src = withParams(url, { dock: '1' });
      w.frame = frame;
      w.body.appendChild(frame);
      setDetail(w, 'Connecting');
      setMode(w, 'full');
      remember();
    }

    function sameVisit(a, b) {
      const eid = function (u) { return new URL(u, window.location.href).searchParams.get('eid'); };
      return eid(a) !== null && eid(a) === eid(b);
    }

    function onCallAction(w, event) {
      const b = event.target.closest('button');
      if (!b) { return; }
      switch (b.dataset.action) {
        case 'mic': tellRoom(w, { command: 'toggleAudio' }); break;
        case 'camera': tellRoom(w, { command: 'toggleVideo' }); break;
        case 'mode': setMode(w, w.mode === 'full' ? 'mini' : 'full'); break;
        case 'popout':
          leave(w, true);
          window.open(w.url, '_blank', 'noopener');
          break;
        case 'leave':
          if (w.state === 'ended' || w.state === 'left' || w.state === 'error') { closeWindow(w); break; }
          askToLeave(w);
          break;
      }
    }

    function askToLeave(w) {
      showNotice(w, 'leave', 'Leave the call? The visit stays open, and you can rejoin it from the Telehealth page.', [
        { action: 'confirm-leave', label: 'Leave', danger: true },
        { action: 'dismiss', label: 'Stay' },
      ]);
    }

    function onNoticeAction(w, event) {
      const b = event.target.closest('button');
      if (!b) { return; }
      if (b.dataset.action === 'confirm-leave') { leave(w, false); }
      if (b.dataset.action === 'admit') { tellRoom(w, { command: 'admit', id: b.dataset.id }); }
      if (b.dataset.action === 'dismiss') { hideNotice(w); }
    }

    function showNotice(w, kind, text, choices) {
      w.notice.hidden = false;
      w.notice.dataset.kind = kind;
      w.notice.textContent = '';
      const p = document.createElement('p');
      p.textContent = text;
      w.notice.appendChild(p);
      choices.forEach(function (c) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'ovd-choice' + (c.danger ? ' ovd-danger' : '');
        b.dataset.action = c.action;
        if (c.id) { b.dataset.id = c.id; }
        b.textContent = c.label;
        w.notice.appendChild(b);
      });
      const first = w.notice.querySelector('button');
      if (first && kind === 'leave') { first.focus(); }
    }

    function hideNotice(w) {
      w.notice.hidden = true;
      w.notice.dataset.kind = '';
    }

    function leave(w, immediately) {
      hideNotice(w);
      tellRoom(w, { command: 'leave' });
      if (immediately) {
        closeWindow(w);
        return;
      }
      w.leaving = setTimeout(function () { closeWindow(w); }, LEAVE_TIMEOUT_MS);
    }

    const STATE_TEXT = { connecting: 'Connecting', joined: 'In the call', left: 'You left the call', ended: 'Visit ended', error: 'Connection problem' };

    function onRoomStatus(w, s) {
      if (s.title) { setTitle(w, s.title); }
      w.state = s.state;
      const someoneWaiting = s.knocking && s.knocking.length > 0;
      const visual = s.state === 'joined' ? (someoneWaiting ? 'waiting' : (s.recording ? 'recording' : 'joined')) : s.state;
      w.el.dataset.state = visual;
      let detail = STATE_TEXT[s.state] || '';
      if (s.state === 'joined' && s.recording) { detail = 'Recording'; }
      if (s.state === 'joined' && someoneWaiting) { detail = s.knocking[0].name + ' is waiting'; }
      setDetail(w, detail);

      const mic = w.actions.querySelector('[data-action="mic"]');
      const camera = w.actions.querySelector('[data-action="camera"]');
      toggleButton(mic, s.audioMuted, 'Unmute microphone', 'Mute microphone', 'micOff', 'mic');
      toggleButton(camera, s.videoMuted, 'Turn camera on', 'Turn camera off', 'cameraOff', 'camera');

      if (someoneWaiting && w.notice.dataset.kind !== 'leave') {
        const first = s.knocking[0];
        const others = s.knocking.length - 1;
        showNotice(w, 'knock', first.name + ' is waiting to join' + (others > 0 ? ', with ' + others + ' more' : '') + '.', [
          { action: 'admit', label: 'Admit', id: first.id },
        ]);
      } else if (!someoneWaiting && w.notice.dataset.kind === 'knock') {
        hideNotice(w);
      }

      if (s.state === 'left' && w.leaving) {
        clearTimeout(w.leaving);
        closeWindow(w);
        return;
      }
      const leaveButton = w.actions.querySelector('[data-action="leave"]');
      if (s.state === 'ended' || s.state === 'left') {
        leaveButton.setAttribute('aria-label', 'Close');
        leaveButton.title = 'Close';
        leaveButton.innerHTML = icon('close');
        leaveButton.classList.remove('ovd-danger');
        if (s.state === 'ended') { setMode(w, 'full'); }
      }
      remember();
    }

    function toggleButton(b, on, onLabel, offLabel, onIcon, offIcon) {
      if (!b) { return; }
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', on ? onLabel : offLabel);
      b.title = on ? onLabel : offLabel;
      b.innerHTML = icon(on ? onIcon : offIcon);
      b.classList.toggle('ovd-off', Boolean(on));
    }

    window.addEventListener('message', function (event) {
      const w = windows.call;
      const data = event.data;
      if (!w || event.origin !== window.location.origin || !data || data.openvisit !== 'room') { return; }
      if (!w.frame || event.source !== w.frame.contentWindow) { return; }
      if (data.type === 'status') { onRoomStatus(w, data); }
    });

    // Moving elsewhere in OpenEMR tucks the call into its corner, like Google Meet's picture in picture.
    document.addEventListener('click', function (event) {
      const w = windows.call;
      if (!w || w.mode !== 'full' || w.state === 'ended') { return; }
      if (event.target.closest('#mainMenu .menuLabel:not(.dropdown-toggle), .tabTitle')) {
        setMode(w, 'mini');
      }
    }, true);

    window.addEventListener('resize', function () {
      Object.keys(windows).forEach(function (kind) {
        const w = windows[kind];
        keepInView(w, w.el.offsetLeft, w.el.offsetTop);
      });
    });

    // ----- The recording player -----
    function openRecording(src, title, poster) {
      const w = windows.player || createWindow('player', title);
      setTitle(w, title);
      if (!w.video) {
        w.actions.append(button('mode', 'Minimize', 'shrink'), button('close', 'Close the recording', 'close'));
        w.actions.addEventListener('click', function (event) {
          const b = event.target.closest('button');
          if (!b) { return; }
          if (b.dataset.action === 'mode') { setMode(w, w.mode === 'full' ? 'mini' : 'full'); }
          if (b.dataset.action === 'close') { w.video.pause(); closeWindow(w); }
        });
        w.video = document.createElement('video');
        w.video.controls = true;
        w.video.playsInline = true;
        w.video.preload = 'metadata';
        w.video.addEventListener('loadedmetadata', function () { setDetail(w, clock(w.video.duration)); });
        w.body.appendChild(w.video);
        setMode(w, 'full');
      }
      w.video.poster = poster || '';
      w.video.src = src;
      setDetail(w, 'Loading');
      w.video.play().catch(function () { /* the controls let the user start it */ });
    }

    function clock(seconds) {
      if (!isFinite(seconds)) { return ''; }
      const s = Math.round(seconds);
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    window.openvisitDock = {
      openCall: openCall,
      openRecording: openRecording,
      hasCall: function () { return Boolean(windows.call); },
    };

  }
})();
