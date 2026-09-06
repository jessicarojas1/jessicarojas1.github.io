/* MERIDIAN — Brief renderer.
 * Turns a normalized brief object into DOM. All model/user-derived strings are
 * inserted via textContent or created text nodes; only fixed structural markup
 * is authored here. URLs are rendered as <a rel="noopener noreferrer"> after a
 * safe-scheme check, so a hostile "source url" cannot execute or navigate to a
 * javascript: target.
 * window.MERIDIAN.render
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const doc = root.document;

  // ---- tiny hyperscript ----
  function h(tag, attrs) {
    const node = doc.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        if (k === 'class') node.className = attrs[k];
        else if (k === 'text') node.textContent = attrs[k];
        else if (k === 'html') { /* intentionally unsupported */ }
        else if (k.slice(0, 5) === 'data-') node.setAttribute(k, attrs[k]);
        else if (k === 'href' || k === 'src' || k === 'title' || k === 'rel' || k === 'target' || k === 'aria-label' || k === 'role')
          node.setAttribute(k, attrs[k]);
        else node[k] = attrs[k];
      }
    }
    for (let i = 2; i < arguments.length; i++) {
      const c = arguments[i];
      if (c == null || c === false) continue;
      if (Array.isArray(c)) c.forEach(x => x != null && node.appendChild(typeof x === 'string' ? doc.createTextNode(x) : x));
      else node.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c);
    }
    return node;
  }
  function safeUrl(u) {
    const s = (u || '').trim();
    return /^https?:\/\//i.test(s) ? s : '';
  }
  function link(title, url) {
    const safe = safeUrl(url);
    if (!safe) return h('span', { text: title || url || '' });
    return h('a', { href: safe, target: '_blank', rel: 'noopener noreferrer', text: title || safe });
  }
  function up(s) { return (s || '').toString().trim().toUpperCase(); }

  function chip(kind, value, extraClass) {
    const v = up(value); if (!v) return null;
    return h('span', { class: 'chip ' + (extraClass || '') }, v);
  }
  function priChip(v) {
    const cls = { CRITICAL: 'pri-critical', HIGH: 'pri-high', MEDIUM: 'pri-medium' }[up(v)];
    return cls ? h('span', { class: 'chip ' + cls }, up(v)) : null;
  }
  function dirChip(v) {
    const cls = { ESCALATING: 'dir-escalating', IMPROVING: 'dir-improving', STABLE: 'dir-stable', UNCERTAIN: 'dir-uncertain' }[up(v)];
    return cls ? h('span', { class: 'chip ' + cls }, [h('span', { class: 'chip-dot', style: '' }), up(v)]) : null;
  }
  function confChip(v) {
    const cls = { HIGH: 'conf-high', MODERATE: 'conf-moderate', LOW: 'conf-low' }[up(v)];
    return cls ? h('span', { class: 'chip ' + cls }, up(v) + ' CONF') : null;
  }

  // ---- normalization ----
  function arr(x) { return Array.isArray(x) ? x : (x == null ? [] : [x]); }
  function str(x) { return x == null ? '' : String(x); }
  function normalize(b) {
    b = b && typeof b === 'object' ? b : {};
    b.date = str(b.date);
    b.reportingWindow = str(b.reportingWindow);
    b.classification = str(b.classification) || 'PUBLIC / OPEN-SOURCE / NON-CLASSIFIED';
    b.bluf = arr(b.bluf);
    b.watchlist = arr(b.watchlist);
    b.sections = arr(b.sections).filter(s => s && (s.title || s.id));
    b.resurfaced = arr(b.resurfaced);
    b.weakSignals = arr(b.weakSignals);
    b.adImpact = str(b.adImpact);
    b.myWork = arr(b.myWork);
    b.whatToKnow = arr(b.whatToKnow);
    b.execQuestions = arr(b.execQuestions);
    b.watch24h = arr(b.watch24h);
    b.watch730d = arr(b.watch730d);
    b.actions = arr(b.actions);
    b.sources = arr(b.sources);
    b.gaps = b.gaps && typeof b.gaps === 'object' ? b.gaps : {};
    ['assumptions', 'intelGaps', 'conflicting', 'lowConfidence', 'collectionPriorities'].forEach(k => { b.gaps[k] = arr(b.gaps[k]); });
    return b;
  }

  // ---- section builders ----
  function sectionShell(title, iconClass) {
    const sec = h('section', { class: 'brief-section' });
    sec.appendChild(h('h2', {}, [h('span', { class: 'sec-accent' }), h('i', { class: 'bi ' + (iconClass || 'bi-dot') }), ' ' + title]));
    const inner = h('div', { class: 'sec-inner' });
    sec.appendChild(inner);
    return { sec, inner };
  }
  function field(label, value) {
    if (!str(value).trim()) return null;
    return h('div', { class: 'field' }, [h('span', { class: 'lbl' }, label), str(value)]);
  }
  function sourcesList(sources) {
    const list = arr(sources).filter(s => s && (s.url || s.title));
    if (!list.length) return null;
    const ul = h('ul', { class: 'src-list' });
    list.forEach(s => ul.appendChild(h('li', {}, link(s.title || s.url, s.url))));
    return h('div', {}, [h('div', { class: 'lbl' }, 'Sources'), ul]);
  }
  function renderItem(it, isCyber) {
    if (!it || typeof it !== 'object') return null;
    const wrap = h('div', { class: 'intel-item' });
    const head = h('div', { class: 'd-flex flex-wrap align-items-center gap-2 mb-1' });
    head.appendChild(h('h3', { class: 'mb-0 me-auto' }, str(it.headline) || 'Untitled'));
    const p = priChip(it.priority); if (p) head.appendChild(p);
    const c = confChip(it.confidence); if (c) head.appendChild(c);
    wrap.appendChild(head);

    if (isCyber) {
      [field('Threat', it.threat), field('Affected', it.affected), field('Exploitation', it.exploitation), field('Defensive priority', it.defensivePriority)]
        .forEach(f => f && wrap.appendChild(f));
    }
    [field('Fact', it.fact), field('Assessment', it.assessment), field('Outlook', it.outlook),
     field('Why it matters', it.whyMatters), field('Aerospace & defense impact', it.adImpact),
     field('Organizational relevance', it.orgRelevance)].forEach(f => f && wrap.appendChild(f));

    if (str(it.takeaway).trim()) wrap.appendChild(h('div', { class: 'takeaway' }, [h('span', { class: 'lbl' }, 'Executive takeaway'), str(it.takeaway)]));
    const sl = sourcesList(it.sources); if (sl) wrap.appendChild(sl);
    return wrap;
  }

  const SECTION_ICONS = {
    geopolitics: 'bi-globe-americas', usdefense: 'bi-flag', aerospace: 'bi-airplane-engines',
    space: 'bi-rocket-takeoff', cyber: 'bi-shield-lock', ai: 'bi-cpu', dib: 'bi-gear-wide-connected',
    contracting: 'bi-file-earmark-text', competitors: 'bi-binoculars', allies: 'bi-people'
  };

  function renderMeta(b) {
    const wrap = h('div', { class: 'brief-meta' });
    wrap.appendChild(h('span', { class: 'cls-badge text-primary' }, [h('i', { class: 'bi bi-shield-check' }), ' ' + (b.classification)]));
    if (b.date) wrap.appendChild(h('span', {}, [h('i', { class: 'bi bi-calendar3' }), ' ' + b.date]));
    if (b.reportingWindow) wrap.appendChild(h('span', {}, [h('i', { class: 'bi bi-clock' }), ' ' + b.reportingWindow]));
    const m = b.meta;
    if (m && m.generatedBy) {
      wrap.appendChild(h('span', { class: 'text-secondary' }, [h('i', { class: 'bi bi-stars' }), ' ' + m.generatedBy + (m.model ? ' · ' + m.model : '')]));
      if (m.webSearch) wrap.appendChild(h('span', { class: 'text-secondary' }, [h('i', { class: 'bi bi-search' }), ' live search']));
      else if (m.webSearchDegraded) wrap.appendChild(h('span', { class: 'text-warning' }, [h('i', { class: 'bi bi-exclamation-triangle' }), ' search unavailable — lower confidence']));
    } else if (m && m.generatedBy === 'manual') {
      wrap.appendChild(h('span', { class: 'text-secondary' }, [h('i', { class: 'bi bi-pencil' }), ' curated']));
    }
    return wrap;
  }

  function bulletSection(title, icon, items, mapFn) {
    if (!items || !items.length) return null;
    const { sec, inner } = sectionShell(title, icon);
    const ul = h('ul', { class: 'mb-0' });
    items.forEach(it => { const li = mapFn(it); if (li) ul.appendChild(li); });
    if (!ul.children.length) return null;
    inner.appendChild(ul);
    return sec;
  }

  // ---- top-level render ----
  function render(brief, metaEl, bodyEl) {
    const b = normalize(brief);
    metaEl.textContent = ''; metaEl.appendChild(renderMeta(b));
    bodyEl.textContent = '';
    const frag = doc.createDocumentFragment();

    // BLUF
    if (b.bluf.length) {
      const { sec, inner } = sectionShell('BLUF — Bottom Line Up Front', 'bi-lightning-charge');
      const ul = h('ul', { class: 'bluf-list' });
      b.bluf.forEach(x => {
        if (typeof x === 'string') { ul.appendChild(h('li', {}, x)); return; }
        const li = h('li', {});
        li.appendChild(h('div', { class: 'bluf-what' }, str(x.what)));
        const sub = [];
        if (str(x.matters).trim()) sub.push(h('span', {}, [h('span', { class: 'bluf-tag' }, 'Why: '), str(x.matters)]));
        if (str(x.changed).trim()) sub.push(h('span', {}, [h('span', { class: 'bluf-tag' }, ' · Changed: '), str(x.changed)]));
        if (sub.length) li.appendChild(h('div', { class: 'small text-secondary' }, sub));
        ul.appendChild(li);
      });
      inner.appendChild(ul); frag.appendChild(sec);
    }

    // Watchlist
    if (b.watchlist.length) {
      const { sec, inner } = sectionShell('Executive Watchlist', 'bi-eye');
      b.watchlist.forEach(w => {
        const card = h('div', { class: 'watchlist-card' });
        const head = h('div', { class: 'wl-head' });
        head.appendChild(h('span', { class: 'wl-dev' }, str(w.development)));
        [priChip(w.priority), dirChip(w.direction), confChip(w.confidence)].forEach(c => c && head.appendChild(c));
        card.appendChild(head);
        const why = field('Why', w.why); if (why) card.appendChild(why);
        const wn = field('Watch next', w.watchNext); if (wn) card.appendChild(wn);
        inner.appendChild(card);
      });
      frag.appendChild(sec);
    }

    // Domain sections
    b.sections.forEach(s => {
      const items = arr(s.items);
      const { sec, inner } = sectionShell(str(s.title) || str(s.id), SECTION_ICONS[s.id] || 'bi-dot');
      const isCyber = s.id === 'cyber' || /cyber/i.test(str(s.title));
      if (!items.length) { inner.appendChild(h('div', { class: 'empty-state-sm' }, 'No material change.')); }
      else items.forEach(it => { const n = renderItem(it, isCyber); if (n) inner.appendChild(n); });
      frag.appendChild(sec);
    });

    // Resurfaced / continuing
    if (b.resurfaced.length) {
      const { sec, inner } = sectionShell('Resurfaced / Continuing Developments', 'bi-arrow-repeat');
      b.resurfaced.forEach(r => {
        const card = h('div', { class: 'intel-item' });
        card.appendChild(h('h3', {}, str(r.issue)));
        [field('Original timeframe', r.originalTimeframe), field('New development', r.newDevelopment),
         field('Why it resurfaced', r.whyResurfaced), field('What changed', r.whatChanged),
         field('Why it matters now', r.whyMattersNow)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      frag.appendChild(sec);
    }

    // Weak signals
    const ws = bulletSection('Weak Signals (early indicators)', 'bi-broadcast', b.weakSignals, (x) => {
      if (typeof x === 'string') return h('li', {}, x);
      return h('li', {}, [h('strong', { text: str(x.signal) }), str(x.why) ? ' — ' + str(x.why) : '']);
    });
    if (ws) frag.appendChild(ws);

    // A&D impact synthesis
    if (str(b.adImpact).trim()) {
      const { sec, inner } = sectionShell('Aerospace & Defense Impact', 'bi-diagram-3');
      inner.appendChild(h('p', { class: 'mb-0' }, str(b.adImpact)));
      frag.appendChild(sec);
    }

    // Impact to my work
    const mw = bulletSection('Impact to My Work', 'bi-person-workspace', b.myWork, (x) => h('li', {}, str(x)));
    if (mw) frag.appendChild(mw);

    // What I should know today
    const wk = bulletSection('What I Should Know Today', 'bi-mortarboard', b.whatToKnow, (x) => h('li', {}, str(x)));
    if (wk) frag.appendChild(wk);

    // Exec questions
    if (b.execQuestions.length) {
      const { sec, inner } = sectionShell('Questions Executives May Ask', 'bi-chat-quote');
      b.execQuestions.forEach(q => {
        const item = h('div', { class: 'qa-item' });
        item.appendChild(h('div', { class: 'qa-q' }, 'Q: ' + str(q.q)));
        item.appendChild(h('div', { class: 'qa-a' }, 'A: ' + str(q.a)));
        inner.appendChild(item);
      });
      frag.appendChild(sec);
    }

    // Watch next
    const w24 = bulletSection('What to Watch — Next 24 Hours', 'bi-hourglass-split', b.watch24h, (x) => h('li', {}, str(x)));
    if (w24) frag.appendChild(w24);
    const w30 = bulletSection('What to Watch — 7 to 30 Days', 'bi-calendar-range', b.watch730d, (x) => h('li', {}, str(x)));
    if (w30) frag.appendChild(w30);

    // Action items
    if (b.actions.length) {
      const { sec, inner } = sectionShell('Executive Action Items', 'bi-check2-square');
      b.actions.forEach(a => {
        const row = h('div', { class: 'action-row' });
        const t = up(a.type) || 'WATCH';
        row.appendChild(h('span', { class: 'action-badge act-' + (['WATCH','REVIEW','CONSIDER','DISCUSS','ACT'].indexOf(t) >= 0 ? t : 'WATCH') }, t));
        row.appendChild(h('span', {}, str(a.text)));
        inner.appendChild(row);
      });
      frag.appendChild(sec);
    }

    // Sources & confidence notes
    if (b.sources.length) {
      const { sec, inner } = sectionShell('Source & Confidence Notes', 'bi-journal-check');
      b.sources.forEach(s => {
        const card = h('div', { class: 'intel-item' });
        if (str(s.claim).trim()) card.appendChild(h('div', { class: 'fw-semibold mb-1' }, str(s.claim)));
        const meta = h('div', { class: 'd-flex flex-wrap gap-2 mb-1' });
        const cc = confChip(s.confidence); if (cc) meta.appendChild(cc);
        if (meta.children.length) card.appendChild(meta);
        const prim = arr(s.primary).filter(Boolean);
        const corr = arr(s.corroborating).filter(Boolean);
        if (prim.length) { const d = h('div', { class: 'field' }, [h('span', { class: 'lbl' }, 'Primary')]); prim.forEach((u, i) => { if (i) d.appendChild(doc.createTextNode(' · ')); d.appendChild(link(u, u)); }); card.appendChild(d); }
        if (corr.length) { const d = h('div', { class: 'field' }, [h('span', { class: 'lbl' }, 'Corroborating')]); corr.forEach((u, i) => { if (i) d.appendChild(doc.createTextNode(' · ')); d.appendChild(link(u, u)); }); card.appendChild(d); }
        const cf = field('Conflicting', s.conflicts); if (cf) card.appendChild(cf);
        const uv = field('Unverified', s.unverified); if (uv) card.appendChild(uv);
        inner.appendChild(card);
      });
      frag.appendChild(sec);
    }

    // Gaps
    const g = b.gaps;
    const anyGap = g.assumptions.length || g.intelGaps.length || g.conflicting.length || g.lowConfidence.length || g.collectionPriorities.length;
    if (anyGap) {
      const { sec, inner } = sectionShell('Assumptions, Intelligence Gaps & Confidence', 'bi-clipboard-data');
      const block = (label, list) => {
        if (!list.length) return;
        inner.appendChild(h('div', { class: 'lbl mt-2' }, label));
        const ul = h('ul', { class: 'mb-0' }); list.forEach(x => ul.appendChild(h('li', {}, str(x)))); inner.appendChild(ul);
      };
      block('Assumptions', g.assumptions);
      block('Intelligence gaps', g.intelGaps);
      block('Conflicting information', g.conflicting);
      block('Low-confidence reporting', g.lowConfidence);
      block('Collection priorities (lawful, passive, open-source)', g.collectionPriorities);
      frag.appendChild(sec);
    }

    bodyEl.appendChild(frag);
  }

  function headlineOf(b) {
    const n = normalize(b);
    if (n.bluf.length) { const x = n.bluf[0]; return typeof x === 'string' ? x : str(x.what); }
    if (n.watchlist.length) return str(n.watchlist[0].development);
    return '(no BLUF)';
  }

  M.render = { render, normalize, headlineOf };
})(window);
