/* ── Site-wide search (Ctrl+K / ⌘K) ─────────────────────────── */
(function () {
  'use strict';

  /* ── Static search index ───────────────────────────────────── */
  var PAGES = [
    { title: 'Home',          url: 'index.html',          text: 'jessica rojas cybersecurity professional portfolio' },
    { title: 'About',         url: 'about.html',          text: 'about background military veteran army cissp biography story' },
    { title: 'Projects',      url: 'projects.html',       text: 'projects tools apps compliance tracker code builder digital twin' },
    { title: 'Resume',        url: 'resume.html',         text: 'resume certifications skills professional experience download' },
    { title: 'Timeline',      url: 'timeline.html',       text: 'timeline career history milestones' },
    { title: 'Cybersecurity', url: 'cybersecurity.html',  text: 'cybersecurity certifications frameworks cissp ceh security tools' },
    { title: 'Consultant',    url: 'consultant.html',     text: 'consulting cmmc nist services engagements' },
    { title: 'Services',      url: 'services.html',       text: 'services pricing consulting assessment implementation retainer packages' },
    { title: 'Contact',       url: 'contact.html',        text: 'contact get in touch message email' },
    { title: 'Blog',          url: 'blog.html',           text: 'blog articles cybersecurity devops compliance writing' },
    { title: 'CMMI v2.0 DEV Level 3', url: 'cmmidev3.html', text: 'cmmi v2 development maturity level 3 practice areas practices compliance framework governance' },
    { title: 'Knowledge Base', url: 'knowledge.html',     text: 'knowledge base commands terminal linux cli cheatsheet' },
  ];

  var POSTS = [
    { title: 'How to Prepare for CMMC Compliance',               id: 'cmmc',          text: 'cmmc compliance defense contractor nist 800-171' },
    { title: 'AI in Cyber Defense: Risk or Opportunity?',        id: 'ai',            text: 'ai artificial intelligence cybersecurity machine learning' },
    { title: 'Lessons from the Battlefield: Military Discipline', id: 'military',      text: 'military discipline leadership army veteran' },
    { title: 'Cyber GRC: Governance, Risk, and Compliance',      id: 'grc',           text: 'grc governance risk compliance framework' },
    { title: 'Risk and Opportunity in Modern Cybersecurity',     id: 'risk',          text: 'risk management opportunity threat modeling' },
    { title: 'Zero Trust Architecture: A Practical Guide',       id: 'zerotrust',     text: 'zero trust architecture network security identity' },
    { title: 'Deploying a Web Server on a Linux VPS',            id: 'webserver',     text: 'web server linux vps nginx apache deployment' },
    { title: 'Linux Server Hardening for CMMC',                  id: 'linuxhardening', text: 'linux hardening cmmc stig server security' },
    { title: 'Incident Response: First 48 Hours',                id: 'incidentresponse', text: 'incident response ir playbook first hours' },
    { title: 'Docker & Container Security',                      id: 'docker',        text: 'docker containers security deployment devops' },
    { title: 'API Security Best Practices',                      id: 'apisecurity',   text: 'api security rest oauth jwt best practices' },
    { title: 'CI/CD with GitHub Actions',                        id: 'deploy',        text: 'cicd github actions deployment pipeline devops' },
    { title: 'Deploying on GoDaddy VPS',                         id: 'godaddyvps',    text: 'godaddy vps linux server deployment hosting' },
  ];

  /* ── Inject modal HTML ─────────────────────────────────────── */
  function buildModal() {
    var el = document.createElement('div');
    el.id = 'siteSearchOverlay';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Site search');
    el.innerHTML = [
      '<div id="siteSearchBox">',
      '  <div id="siteSearchInputRow">',
      '    <span id="siteSearchIcon" aria-hidden="true">&#128269;</span>',
      '    <input id="siteSearchInput" type="search" placeholder="Search pages, posts, knowledge base..." autocomplete="off" spellcheck="false" />',
      '    <kbd id="siteSearchEscHint">Esc</kbd>',
      '  </div>',
      '  <div id="siteSearchResults" role="listbox"></div>',
      '  <div id="siteSearchFooter"><span>&#8593;&#8595; navigate</span><span>&#9166; open</span><span>Esc close</span></div>',
      '</div>',
    ].join('');
    document.body.appendChild(el);

    var style = document.createElement('style');
    style.textContent = [
      '#siteSearchOverlay{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;padding-top:10vh;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);}',
      '#siteSearchOverlay.open{display:flex;}',
      '#siteSearchBox{background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:12px;width:min(640px,94vw);max-height:70vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;}',
      '#siteSearchInputRow{display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid var(--bs-border-color);gap:10px;}',
      '#siteSearchIcon{font-size:1.1rem;flex-shrink:0;opacity:.6;}',
      '#siteSearchInput{flex:1;border:none;outline:none;background:transparent;font-size:1rem;color:var(--bs-body-color);}',
      '#siteSearchEscHint{font-size:.7rem;padding:2px 6px;border:1px solid var(--bs-border-color);border-radius:4px;opacity:.5;flex-shrink:0;}',
      '#siteSearchResults{overflow-y:auto;padding:8px 0;flex:1;}',
      '.ss-group-label{padding:6px 16px 2px;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--bs-secondary-color);}',
      '.ss-result{display:flex;align-items:center;gap:12px;padding:9px 16px;cursor:pointer;text-decoration:none;color:var(--bs-body-color);}',
      '.ss-result:hover,.ss-result.active{background:var(--bs-primary);color:#fff;}',
      '.ss-result-icon{font-size:1rem;flex-shrink:0;width:20px;text-align:center;}',
      '.ss-result-text{flex:1;min-width:0;}',
      '.ss-result-title{font-size:.9rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ss-result-sub{font-size:.75rem;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '#siteSearchFooter{padding:6px 16px;font-size:.7rem;opacity:.5;border-top:1px solid var(--bs-border-color);display:flex;gap:16px;}',
      '.ss-empty{padding:32px 16px;text-align:center;color:var(--bs-secondary-color);font-size:.9rem;}',
    ].join('');
    document.head.appendChild(style);

    return el;
  }

  /* ── Search logic ──────────────────────────────────────────── */
  function search(q) {
    if (!q || q.length < 1) return [];
    var lq = q.toLowerCase();
    var results = [];

    // Pages
    var pageHits = PAGES.filter(function (p) {
      return p.title.toLowerCase().includes(lq) || p.text.includes(lq);
    }).slice(0, 5).map(function (p) {
      return { type: 'page', icon: '&#128196;', title: p.title, sub: p.url, url: p.url };
    });
    if (pageHits.length) results.push({ group: 'Pages', items: pageHits });

    // Blog posts
    var postHits = POSTS.filter(function (p) {
      return p.title.toLowerCase().includes(lq) || p.text.includes(lq);
    }).slice(0, 4).map(function (p) {
      return { type: 'blog', icon: '&#9997;', title: p.title, sub: 'Blog post', url: 'blog.html?open=' + p.id };
    });
    if (postHits.length) results.push({ group: 'Blog', items: postHits });

    // KB entries from localStorage
    var kbRaw = [];
    try { kbRaw = JSON.parse(localStorage.getItem('kb_entries') || '[]'); } catch (e) {}
    var kbHits = kbRaw.filter(function (e) {
      var txt = ((e.title || '') + ' ' + (e.tags || '') + ' ' + (e.body || '').replace(/<[^>]*>/g, ' ')).toLowerCase();
      return txt.includes(lq);
    }).slice(0, 4).map(function (e) {
      return { type: 'kb', icon: '&#128218;', title: e.title || '(untitled)', sub: e.tags || 'Knowledge Base', url: 'knowledge.html?entry=' + e.id };
    });
    if (kbHits.length) results.push({ group: 'Knowledge Base', items: kbHits });

    return results;
  }

  /* ── Render results ────────────────────────────────────────── */
  function render(groups, container) {
    if (!groups.length) {
      container.innerHTML = '<div class="ss-empty">No results found</div>';
      return;
    }
    var html = '';
    groups.forEach(function (g) {
      html += '<div class="ss-group-label">' + g.group + '</div>';
      g.items.forEach(function (item) {
        html += '<a class="ss-result" href="' + item.url + '" role="option">';
        html += '<span class="ss-result-icon">' + item.icon + '</span>';
        html += '<span class="ss-result-text">';
        html += '<div class="ss-result-title">' + item.title.replace(/</g, '&lt;') + '</div>';
        html += '<div class="ss-result-sub">' + item.sub.replace(/</g, '&lt;') + '</div>';
        html += '</span></a>';
      });
    });
    container.innerHTML = html;
  }

  /* ── Keyboard navigation ───────────────────────────────────── */
  function navigate(container, delta) {
    var items = Array.from(container.querySelectorAll('.ss-result'));
    if (!items.length) return;
    var cur = container.querySelector('.ss-result.active');
    var idx = cur ? items.indexOf(cur) : -1;
    if (cur) cur.classList.remove('active');
    idx = (idx + delta + items.length) % items.length;
    items[idx].classList.add('active');
    items[idx].scrollIntoView({ block: 'nearest' });
  }

  /* ── Init ─────────────────────────────────────────────────── */
  function init() {
    var overlay = buildModal();
    var input   = document.getElementById('siteSearchInput');
    var results = document.getElementById('siteSearchResults');
    var debounce;

    function open() {
      overlay.classList.add('open');
      input.value = '';
      results.innerHTML = '';
      input.focus();
    }
    function close() { overlay.classList.remove('open'); }

    // Trigger: Ctrl+K / ⌘K
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        overlay.classList.contains('open') ? close() : open();
      }
      if (e.key === 'Escape') close();
      if (!overlay.classList.contains('open')) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); navigate(results, 1); }
      if (e.key === 'ArrowUp')   { e.preventDefault(); navigate(results, -1); }
      if (e.key === 'Enter') {
        var active = results.querySelector('.ss-result.active');
        if (active) active.click();
      }
    });

    // Close on overlay click
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });

    // Track result clicks
    results.addEventListener('click', function (ev) {
      var link = ev.target.closest('.ss-result');
      if (!link || !window.SiteAnalytics) return;
      var titleEl = link.querySelector('.ss-result-title');
      SiteAnalytics.track('search_click', {
        title: titleEl ? titleEl.textContent : '',
        url: link.getAttribute('href')
      });
    });

    // Live search
    input.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () {
        var q = input.value.trim();
        render(search(q), results);
        if (q.length >= 2 && window.SiteAnalytics) {
          SiteAnalytics.track('search', { query: q });
        }
      }, 120);
    });

    // Inject search trigger button into navbar if present
    var navRight = document.querySelector('.navbar .d-flex.align-items-center');
    if (navRight) {
      var btn = document.createElement('button');
      btn.className = 'btn btn-sm btn-outline-secondary';
      btn.setAttribute('aria-label', 'Search site (Ctrl+K)');
      btn.title = 'Search (Ctrl+K)';
      btn.textContent = '⌕';
      btn.style.fontWeight = '700';
      btn.addEventListener('click', open);
      navRight.insertBefore(btn, navRight.firstChild);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
