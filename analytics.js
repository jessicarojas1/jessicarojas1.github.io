/* ── Site Analytics — client-side event tracker ─────────────── */
(function () {
  'use strict';

  var STORE_KEY  = 'site_analytics_v1';
  var MAX_EVENTS = 1000;

  function getEvents() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); } catch (e) { return []; }
  }

  function saveEvents(arr) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(arr)); } catch (e) {}
  }

  function track(type, data) {
    var events = getEvents();
    var page = (window.location.pathname.split('/').pop() || 'index.html') || 'index.html';
    events.push({ type: type, ts: Date.now(), page: page, data: data || {} });
    if (events.length > MAX_EVENTS) events = events.slice(events.length - MAX_EVENTS);
    saveEvents(events);
  }

  function init() {
    var ref = '';
    try { if (document.referrer) ref = new URL(document.referrer).hostname; } catch (e) {}
    var tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    track('page_view', {
      title: document.title.split('|')[0].trim(),
      referrer: ref,
      device: /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
      tz: tz
    });
  }

  window.SiteAnalytics = { track: track, getEvents: getEvents };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
