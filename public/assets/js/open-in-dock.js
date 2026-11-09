// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Opens a visit or a recording in OpenVisit's floating window on OpenEMR's main page, or in a
// browser tab where that window is not available.
(function () {
  'use strict';

  function dock() {
    try {
      return window.top && window.top.openvisitDock ? window.top.openvisitDock : null;
    } catch (e) {
      return null;
    }
  }

  window.openvisitOpen = {
    call: function (url, title) {
      const d = dock();
      if (d) {
        d.openCall(url, title);
        return;
      }
      window.open(url, '_blank', 'noopener');
    },
    recording: function (stream, title, poster, page) {
      const d = dock();
      if (d) {
        d.openRecording(stream, title, poster);
        return;
      }
      window.open(page, '_blank', 'noopener');
    },
  };

  // Links and buttons marked up for it open in the window with a plain click; other clicks
  // (new tab, middle button) keep their browser meaning.
  document.addEventListener('click', function (event) {
    const target = event.target.closest('[data-openvisit-room], [data-openvisit-recording]');
    if (!target || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
      return;
    }
    event.preventDefault();
    if (target.dataset.openvisitRoom !== undefined) {
      window.openvisitOpen.call(target.getAttribute('href') || target.dataset.openvisitRoom, target.dataset.title);
      return;
    }
    window.openvisitOpen.recording(target.dataset.stream, target.dataset.title, target.dataset.poster, target.getAttribute('href'));
  });
})();
