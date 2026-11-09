// Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
// Telehealth page: unscheduled visits. Search a patient, start, and the room opens in a new tab.
(function () {
  'use strict';
  const panel = document.getElementById('openvisit-adhoc');
  if (!panel) { return; }
  const config = JSON.parse(document.getElementById('openvisit-today-config').textContent);
  const search = document.getElementById('openvisit-adhoc-search');
  const find = document.getElementById('openvisit-adhoc-find');
  const results = document.getElementById('openvisit-adhoc-results');
  const error = document.getElementById('openvisit-adhoc-error');

  function post(fields) {
    const body = new URLSearchParams(Object.assign({ csrf_token_form: config.csrf }, fields));
    return fetch(config.apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (result) { if (!result.ok) { throw new Error(result.error || 'Request failed'); } return result; });
  }

  function render(patients) {
    results.innerHTML = '';
    if (!patients.length) {
      const empty = document.createElement('li');
      empty.className = 'list-group-item text-muted';
      empty.textContent = 'No patient found';
      results.appendChild(empty);
      return;
    }
    patients.forEach(function (patient) {
      const item = document.createElement('li');
      item.className = 'list-group-item d-flex justify-content-between align-items-center';
      item.dataset.pid = String(patient.pid);
      const label = document.createElement('span');
      label.textContent = patient.name + (patient.dob ? ' · ' + patient.dob : '') + ' · #' + patient.pid;
      const start = document.createElement('button');
      start.type = 'button';
      start.className = 'btn btn-primary btn-sm';
      start.textContent = 'Start video visit';
      start.addEventListener('click', function () {
        start.disabled = true;
        post({ action: 'visit.adhoc', pid: String(patient.pid) })
          .then(function (result) { window.openvisitOpen.call(result.url, patient.name); window.location.reload(); })
          .catch(function (e) { start.disabled = false; error.textContent = e.message; });
      });
      item.appendChild(label);
      item.appendChild(start);
      results.appendChild(item);
    });
  }

  function lookup() {
    error.textContent = '';
    post({ action: 'patient.search', q: search.value })
      .then(function (result) { render(result.patients); })
      .catch(function (e) { error.textContent = e.message; });
  }
  find.addEventListener('click', lookup);
  search.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); lookup(); } });
})();
