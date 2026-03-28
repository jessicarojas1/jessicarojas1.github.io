/* script.js — Core site utilities */

/* ── Theme: set before CSS loads to prevent FOUC ─────────────── */
/* (This logic also runs inline in <head> via a tiny inline script) */

function _scriptInit() {

  /* ── Dark / Light mode toggle ────────────────────────────── */
  const themeBtn = document.getElementById('themeToggleBtn');

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('bsTheme', theme);
    if (themeBtn) {
      themeBtn.querySelector('.theme-icon').textContent = theme === 'dark' ? '☀️' : '🌙';
      themeBtn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
  }

  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-bs-theme') || 'dark';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
    // Sync icon on load
    const saved = localStorage.getItem('bsTheme') || 'dark';
    const icon = themeBtn.querySelector('.theme-icon');
    if (icon) icon.textContent = saved === 'dark' ? '☀️' : '🌙';
  }

  /* ── Dynamic copyright year ──────────────────────────────── */
  document.querySelectorAll('[data-year]').forEach(el => {
    el.textContent = new Date().getFullYear();
  });

  /* ── Scroll reveal ───────────────────────────────────────── */
  const reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    const obs = new IntersectionObserver(
      entries => entries.forEach(e => {
        if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
      }),
      { threshold: 0.1 }
    );
    reveals.forEach(el => obs.observe(el));
  }

  /* ── Contact form → localStorage submissions (for admin view) */
  const contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', e => {
      const name    = document.getElementById('cf-name')?.value   || '';
      const email   = document.getElementById('cf-email')?.value  || '';
      const subject = document.getElementById('cf-subject')?.value || '';
      const message = document.getElementById('cf-message')?.value || '';
      const submissions = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
      submissions.unshift({
        id: Date.now(),
        name, email, subject, message,
        date: new Date().toLocaleString(),
        read: false,
      });
      localStorage.setItem('contact_submissions', JSON.stringify(submissions));
    });
  }

  /* ── Admin: render contact submissions ────────────────────── */
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl && typeof RBAC !== 'undefined' && RBAC.isAtLeast('admin')) {
    renderInbox(inboxEl);
  }

  /* ── Admin: dynamic projects ─────────────────────────────── */
  if (typeof RBAC !== 'undefined' && RBAC.isAtLeast('editor')) {
    initDynamicProjects();
  }

  /* ── Navbar hamburger fallback (if Bootstrap JS blocked/slow) ── */
  if (typeof bootstrap === 'undefined') {
    const toggler  = document.querySelector('.navbar-toggler');
    const collapse = document.getElementById('navContent');
    if (toggler && collapse) {
      toggler.addEventListener('click', function () {
        const open = collapse.classList.toggle('show');
        toggler.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      // Close menu when a nav link is clicked on mobile
      collapse.querySelectorAll('.nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
          collapse.classList.remove('show');
          toggler.setAttribute('aria-expanded', 'false');
        });
      });
    }

    // Login modal fallback is handled by roles.js
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _scriptInit);
} else {
  _scriptInit();
}

/* ── Inbox renderer ──────────────────────────────────────────── */
function renderInbox(container) {
  const submissions = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  if (!submissions.length) {
    container.innerHTML = '<p class="text-secondary">No submissions yet.</p>';
    return;
  }
  container.innerHTML = submissions.map((s, i) => `
    <div class="card mb-2 ${s.read ? '' : 'border-primary'}">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between">
          <strong>${escHtml(s.name)}</strong>
          <small class="text-secondary">${escHtml(s.date)}</small>
        </div>
        <div class="text-secondary small">${escHtml(s.email)}</div>
        ${s.subject ? `<div class="small fw-semibold mt-1">${escHtml(s.subject)}</div>` : ''}
        <p class="mb-1 mt-1 small">${escHtml(s.message)}</p>
        <button class="btn btn-sm btn-outline-secondary" onclick="markRead(${i})">
          ${s.read ? 'Mark Unread' : 'Mark Read'}
        </button>
        <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteSubmission(${i})">Delete</button>
      </div>
    </div>
  `).join('');
}

function markRead(idx) {
  const subs = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  if (subs[idx]) { subs[idx].read = !subs[idx].read; localStorage.setItem('contact_submissions', JSON.stringify(subs)); }
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl) renderInbox(inboxEl);
}

function deleteSubmission(idx) {
  const subs = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  subs.splice(idx, 1);
  localStorage.setItem('contact_submissions', JSON.stringify(subs));
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl) renderInbox(inboxEl);
}

/* ── Dynamic projects (editor/admin add project) ─────────────── */
function initDynamicProjects() {
  const grid = document.getElementById('projects-grid');
  const addForm = document.getElementById('add-project-form');
  if (!grid || !addForm) return;

  // Render saved projects
  renderDynamicProjects(grid);

  addForm.addEventListener('submit', e => {
    e.preventDefault();
    const title = document.getElementById('proj-title').value.trim();
    const desc  = document.getElementById('proj-desc').value.trim();
    const url   = document.getElementById('proj-url').value.trim();
    const link  = document.getElementById('proj-link').value.trim();
    if (!title) return;

    const projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
    projects.push({ id: Date.now(), title, desc, url, link });
    localStorage.setItem('dynamic_projects', JSON.stringify(projects));
    renderDynamicProjects(grid);
    addForm.reset();
    bootstrap.Modal.getInstance(document.getElementById('addProjectModal'))?.hide();
  });
}

function renderDynamicProjects(grid) {
  // Remove existing dynamic cards
  grid.querySelectorAll('[data-dynamic-project]').forEach(el => el.remove());

  const projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
  const isAdmin  = typeof RBAC !== 'undefined' && RBAC.isAtLeast('admin');

  projects.forEach(p => {
    const col = document.createElement('div');
    col.className = 'col';
    col.setAttribute('data-dynamic-project', p.id);
    col.innerHTML = `
      <div class="card h-100">
        <div class="card-body">
          <h3 class="card-title h5">${escHtml(p.title)}</h3>
          <p class="card-text">${escHtml(p.desc)}</p>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          ${p.link ? `<a href="${escHtml(p.link)}" class="btn btn-sm btn-primary">View Project</a>` : ''}
          ${isAdmin ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteDynamicProject(${p.id})">Delete</button>` : ''}
        </div>
      </div>`;
    grid.appendChild(col);
  });
}

function deleteDynamicProject(id) {
  let projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
  projects = projects.filter(p => p.id !== id);
  localStorage.setItem('dynamic_projects', JSON.stringify(projects));
  const grid = document.getElementById('projects-grid');
  if (grid) renderDynamicProjects(grid);
}

/* ── HTML escape helper ──────────────────────────────────────── */
function escHtml(str) {
  return String(str).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])
  );
}

/* ================================================================
   MATRIX RAIN + TYPEWRITER + CYBER TERMINAL
   ================================================================ */

/* ── Matrix rain canvas ──────────────────────────────────────── */
function initMatrixRain() {
  var canvas = document.getElementById('matrix-canvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');

  var CHARS = '01アイウエオカキクケコサシスセソABCDEFGHIJKLMNOPQRSTUVWXYZ@#$%&><';
  var SIZE  = 13;
  var cols, drops;

  function resize() {
    canvas.width  = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight;
    cols  = Math.floor(canvas.width / SIZE) || 1;
    drops = Array.from({ length: cols }, function() { return Math.random() * -40; });
  }
  resize();
  window.addEventListener('resize', resize);

  var raf, last = 0;
  function draw(ts) {
    if (ts - last < 55) { raf = requestAnimationFrame(draw); return; }
    last = ts;

    ctx.fillStyle = 'rgba(7,12,16,0.1)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.font = SIZE + 'px Courier New';

    for (var i = 0; i < cols; i++) {
      var ch = CHARS[Math.floor(Math.random() * CHARS.length)];
      var y  = drops[i] * SIZE;
      var r  = Math.random();
      ctx.fillStyle = r > 0.97 ? '#ffffff'
                    : r > 0.80 ? '#ff7a40'
                    :             'rgba(255,88,17,0.30)';
      ctx.fillText(ch, i * SIZE, y);
      if (y > canvas.height && Math.random() > 0.975) drops[i] = Math.random() * -20;
      drops[i] += 1;
    }
    raf = requestAnimationFrame(draw);
  }

  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) { raf = requestAnimationFrame(draw); }
      else { cancelAnimationFrame(raf); }
    });
  });
  obs.observe(canvas);
}

/* ── Hero subtitle typewriter ────────────────────────────────── */
function initHeroTypewriter() {
  var el = document.querySelector('.hero-subtitle');
  if (!el) return;
  var text = 'Cybersecurity Professional\u00a0·\u00a0Full-Stack Developer\u00a0·\u00a0U.S. Army Veteran';
  var i = 0;
  el.innerHTML = '<span class="cur">▋</span>';

  function type() {
    if (i < text.length) {
      el.innerHTML = text.slice(0, i + 1) + '<span class="cur">▋</span>';
      i++;
      setTimeout(type, i < 5 ? 120 : 38);
    } else {
      el.innerHTML = text + '<span class="cur">▋</span>';
      setTimeout(function() {
        var cur = el.querySelector('.cur');
        if (cur) cur.style.display = 'none';
      }, 3200);
    }
  }
  setTimeout(type, 900);
}

/* ── Cyber Terminal ──────────────────────────────────────────── */
function initTerminal() {
  var body  = document.getElementById('terminal-body');
  var input = document.getElementById('terminal-input');
  if (!body || !input) return;

  var PROMPT = '<span class="t-prompt">jrojas@secure:~$</span>&nbsp;';
  var cmdHistory = [], histIdx = -1;
  var typing = false;

  var RESPONSES = {
    help: [
      '<span class="t-accent">Available commands</span>',
      '',
      '  <span class="t-cmd">whoami</span>     ·  About Jessica Rojas',
      '  <span class="t-cmd">skills</span>     ·  Technical skill matrix',
      '  <span class="t-cmd">certs</span>      ·  Certifications & credentials',
      '  <span class="t-cmd">exp</span>        ·  Work experience',
      '  <span class="t-cmd">status</span>     ·  Live system status check',
      '  <span class="t-cmd">scan</span>       ·  Run vulnerability scan',
      '  <span class="t-cmd">isms</span>       ·  ISO 27001 document library',
      '  <span class="t-cmd">contact</span>    ·  Get in touch',
      '  <span class="t-cmd">clear</span>      ·  Clear terminal',
      '',
      '  <span class="t-dim">↑ ↓  command history  ·  Ctrl+L  clear</span>',
    ],
    whoami: [
      '<span class="t-accent">[ IDENTITY RECORD ]</span>',
      '',
      '  Name        <span class="t-val">Jessica Rojas</span>',
      '  Title       <span class="t-val">Cybersecurity Professional · Enterprise Systems Manager</span>',
      '  Service     <span class="t-val">U.S. Army National Guard — Utah</span>',
      '  Education   <span class="t-val">MS · Cybersecurity & Information Assurance</span>',
      '  Focus       <span class="t-val">CMMC · ISO 27001 · AI Governance · GRC</span>',
      '  Status      <span class="t-green">● ONLINE</span>',
    ],
    skills: [
      '<span class="t-accent">[ SKILL MATRIX ]</span>',
      '',
      '  Frameworks  <span class="t-val">CMMC · NIST SP 800-171 · ISO/IEC 27001:2022 · SOC 2</span>',
      '  Security    <span class="t-val">Pen Testing · SIEM · IAM · Risk Assessment · BCP/DR</span>',
      '  Languages   <span class="t-val">Python · JavaScript · SQL · Bash · PowerShell</span>',
      '  Platforms   <span class="t-val">AWS · Azure · Linux · Windows Server · Docker</span>',
      '  Dev         <span class="t-val">React · Node.js · REST APIs · Git · CI/CD</span>',
      '  Governance  <span class="t-val">AI Governance · GRC · Audit Management · Policy Writing</span>',
    ],
    certs: [
      '<span class="t-accent">[ CERTIFICATIONS ]</span>',
      '',
      '  <span class="t-green">✔</span>  <span class="t-val">CISSP</span>   Certified Information Systems Security Professional',
      '  <span class="t-green">✔</span>  <span class="t-val">CEH</span>     Certified Ethical Hacker',
      '  <span class="t-green">✔</span>  <span class="t-val">ECES</span>    EC-Council Certified Encryption Specialist',
      '  <span class="t-green">✔</span>  <span class="t-val">Sec+</span>    CompTIA Security+',
      '  <span class="t-green">✔</span>  <span class="t-val">CMMC L2</span> Certified Practitioner',
    ],
    exp: [
      '<span class="t-accent">[ EXPERIENCE LOG ]</span>',
      '',
      '  2022 — Present  <span class="t-val">Enterprise Systems Manager</span>',
      '  2021 — 2022     <span class="t-val">Fullstack Developer · GMRE, Inc.</span>',
      '  2020 — 2021     <span class="t-val">Associate Fullstack Programmer · Weber State University</span>',
      '  2016 — 2020     <span class="t-val">U.S. Army National Guard · Utah</span>',
    ],
    isms: [
      '<span class="t-accent">[ ISO 27001:2022 ISMS LIBRARY ]</span>',
      '',
      '  Documents    <span class="t-val">43 files</span>  (18 Policies · 12 Procedures · 12 Templates)',
      '  Standard     <span class="t-val">ISO/IEC 27001:2022 — full Annex A coverage</span>',
      '  Themes       <span class="t-val">Organizational · People · Physical · Technological</span>',
      '  Status       <span class="t-green">● AUDIT READY</span>',
      '',
      '  <span class="t-dim">→</span>  <a href="isms/index.html" class="t-link">Open ISMS Library ↗</a>',
    ],
    contact: [
      '<span class="t-accent">[ CONTACT CHANNELS ]</span>',
      '',
      '  GitHub    <a href="https://github.com/jessicarojas1" target="_blank" class="t-link">github.com/jessicarojas1</a>',
      '  LinkedIn  <a href="https://www.linkedin.com/in/jessica-rojas-33212918a" target="_blank" class="t-link">linkedin.com/in/jessica-rojas</a>',
      '  Form      <a href="contact.html" class="t-link">jessicarojas1.github.io/contact</a>',
    ],
  };

  function appendLine(html) {
    var el = document.createElement('div');
    el.className = 'terminal-line';
    el.innerHTML = html;
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
  }

  function typeLines(lines, idx, done) {
    if (idx >= lines.length) { typing = false; if (done) done(); return; }
    typing = true;
    appendLine(lines[idx]);
    setTimeout(function() { typeLines(lines, idx + 1, done); }, 38);
  }

  function runStatus() {
    typing = true;
    var checks = [
      'Firewall rules', 'IDS/IPS sensors', 'AES-256 encryption',
      'Access controls', 'TLS certificate', 'Patch baseline',
      'MFA enforcement', 'Backup integrity', 'Audit logging'
    ];
    appendLine('<span class="t-accent">[ RUNNING SYSTEM STATUS CHECK ]</span>');
    appendLine('');
    var i = 0;
    function next() {
      if (i >= checks.length) {
        setTimeout(function() {
          appendLine('');
          appendLine('<span class="t-green">✔ All systems nominal. Threat level: <strong>LOW</strong></span>');
          typing = false;
        }, 300);
        return;
      }
      var label = checks[i++];
      var line = document.createElement('div');
      line.className = 'terminal-line';
      line.innerHTML = '  <span class="t-dim">►</span> Checking ' + label + '...';
      body.appendChild(line);
      body.scrollTop = body.scrollHeight;
      setTimeout(function() {
        line.innerHTML = '  <span class="t-green">✔</span>  ' + label + ' <span class="t-dim">· · ·</span> <span class="t-green">OK</span>';
        next();
      }, 160);
    }
    next();
  }

  function runScan() {
    typing = true;
    var targets = ['auth_module', 'session_manager', 'api_gateway', 'isms_docs', 'crypto_layer', 'rbac_engine'];
    appendLine('<span class="t-accent">[ INITIATING VULNERABILITY SCAN ]</span>');
    appendLine('  <span class="t-dim">Scope: localhost · Protocol: internal</span>');
    appendLine('');
    var i = 0;
    function next() {
      if (i >= targets.length) {
        setTimeout(function() {
          appendLine('');
          appendLine('  CVEs found     <span class="t-green">0</span>');
          appendLine('  Misconfigs     <span class="t-green">0</span>');
          appendLine('  Open ports     <span class="t-green">443/tls only</span>');
          appendLine('');
          appendLine('<span class="t-green">✔ Scan complete. Environment is secure.</span>');
          typing = false;
        }, 350);
        return;
      }
      var t = targets[i++];
      var line = document.createElement('div');
      line.className = 'terminal-line';
      line.innerHTML = '  <span class="t-dim">⬡</span> scanning ' + t + '...';
      body.appendChild(line);
      body.scrollTop = body.scrollHeight;
      setTimeout(function() {
        line.innerHTML = '  <span class="t-green">✔</span> ' + t + ' <span class="t-dim">— 0 findings</span>';
        next();
      }, 200);
    }
    next();
  }

  function handleCommand(cmd) {
    cmd = cmd.trim();
    if (!cmd) return;
    cmdHistory.unshift(cmd);
    histIdx = -1;

    appendLine('');
    appendLine(PROMPT + escHtml(cmd));
    appendLine('');

    var key = cmd.toLowerCase();
    if (key === 'clear' || key === 'cls') { body.innerHTML = ''; return; }
    if (key === 'status') { runStatus(); return; }
    if (key === 'scan')   { runScan();   return; }

    if (RESPONSES[key]) {
      typeLines(RESPONSES[key], 0, null);
    } else {
      appendLine('<span class="t-err">command not found: ' + escHtml(cmd) + '</span>  <span class="t-dim">(try <span class="t-cmd">help</span>)</span>');
    }
    appendLine('');
  }

  /* Intro sequence */
  var intro = [
    '<span class="t-accent">╔══════════════════════════════════════════════╗</span>',
    '<span class="t-accent">║  SECURE SHELL · jessicarojas1.github.io      ║</span>',
    '<span class="t-accent">╚══════════════════════════════════════════════╝</span>',
    '',
    '  <span class="t-dim">Protocol: HTTPS/TLS 1.3 · Session encrypted · Auth: OK</span>',
    '',
    '  Welcome. Type <span class="t-cmd">help</span> to explore.',
    '',
  ];
  typeLines(intro, 0, null);

  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      if (typing) return;
      var cmd = input.value;
      input.value = '';
      handleCommand(cmd);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (histIdx < cmdHistory.length - 1) { histIdx++; input.value = cmdHistory[histIdx]; }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (histIdx > 0) { histIdx--; input.value = cmdHistory[histIdx]; }
      else { histIdx = -1; input.value = ''; }
    } else if (e.key === 'l' && e.ctrlKey) {
      e.preventDefault(); body.innerHTML = '';
    } else if (e.key === 'Tab') {
      e.preventDefault();
      var v = input.value.trim().toLowerCase();
      if (!v) return;
      var all = Object.keys(RESPONSES).concat(['status','scan','clear']);
      var match = all.filter(function(c) { return c.startsWith(v); });
      if (match.length === 1) input.value = match[0];
    }
  });

  /* Click anywhere in terminal body to focus input */
  body.addEventListener('click', function() { input.focus(); });
}

/* ── Boot all techy features ─────────────────────────────────── */
(function bootTechy() {
  function run() {
    initMatrixRain();
    initHeroTypewriter();
    initTerminal();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
