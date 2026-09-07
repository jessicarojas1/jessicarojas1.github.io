/* MERIDIAN v2 — Brief renderer.
 * Turns a normalized brief object into DOM. All model/user-derived strings are
 * inserted via textContent or created text nodes; only fixed structural markup
 * is authored here. URLs are scheme-checked (http/https) before becoming links.
 * Handles v2 briefs and remains tolerant of v1 fields.
 * window.MERIDIAN.render
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const doc = root.document;

  function h(tag, attrs) {
    const node = doc.createElement(tag);
    if (attrs) for (const k in attrs) {
      if (k === 'class') node.className = attrs[k];
      else if (k === 'text') node.textContent = attrs[k];
      else if (k.slice(0, 5) === 'data-' || ['href','src','title','rel','target','aria-label','role','style'].indexOf(k) >= 0) node.setAttribute(k, attrs[k]);
      else node[k] = attrs[k];
    }
    for (let i = 2; i < arguments.length; i++) {
      const c = arguments[i];
      if (c == null || c === false) continue;
      if (Array.isArray(c)) c.forEach(x => x != null && node.appendChild(typeof x === 'string' ? doc.createTextNode(x) : x));
      else node.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c);
    }
    return node;
  }
  function safeUrl(u) { const s = (u || '').trim(); return /^https?:\/\//i.test(s) ? s : ''; }
  function link(title, url) {
    const safe = safeUrl(url);
    if (!safe) return h('span', { text: title || url || '' });
    return h('a', { href: safe, target: '_blank', rel: 'noopener noreferrer', text: title || safe });
  }
  function up(s) { return (s || '').toString().trim().toUpperCase(); }
  function str(x) { return x == null ? '' : String(x); }
  function arr(x) { return Array.isArray(x) ? x : (x == null ? [] : [x]); }
  function has(x) { return str(x).trim() !== ''; }

  function priChip(v) { const c = { CRITICAL:'pri-critical', HIGH:'pri-high', MEDIUM:'pri-medium' }[up(v)]; return c ? h('span', { class:'chip '+c }, up(v)) : null; }
  function dirChip(v) {
    const map = { ESCALATING:'dir-escalating', IMPROVING:'dir-improving', DEESCALATING:'dir-improving', STABLE:'dir-stable', UNCERTAIN:'dir-uncertain' };
    const c = map[up(v)]; if (!c) return null;
    const glyph = { ESCALATING:'↑', DEESCALATING:'↓', IMPROVING:'↓', STABLE:'→', UNCERTAIN:'?' }[up(v)] || '';
    return h('span', { class:'chip '+c }, glyph + ' ' + up(v));
  }
  function confChip(v) { const c = { HIGH:'conf-high', MODERATE:'conf-moderate', LOW:'conf-low' }[up(v)]; return c ? h('span', { class:'chip '+c }, up(v)+' CONF') : null; }
  function iwBadge(level) { const L = up(level); if (!L) return null; return h('span', { class:'iw-badge iw-'+L.toLowerCase() }, L); }

  function normalize(b) {
    b = b && typeof b === 'object' ? b : {};
    b.date = str(b.date); b.reportingWindow = str(b.reportingWindow);
    b.classification = str(b.classification) || 'PUBLIC / OPEN-SOURCE / NON-CLASSIFIED';
    ['bluf','top3','watchboard','sections','crossDomain','resurfaced','weakSignals','appliesToMe','recommendations','decisionMemos','questionsToAsk','questionsAsked','watch2472h','watch730d','watch312mo','strategicSurprise','wrongAbout','redTeam','sources','forecastReview',
     // v2.1 presentation
     'barometer','techRadar','contractRadar','opportunities','competitive','underRadar','patterns','strategicWarning',
     // v1 fallbacks
     'watchlist','myWork','whatToKnow','execQuestions','watch24h','actions'].forEach(k => { b[k] = arr(b[k]); });
    b.bigPicture = str(b.bigPicture); b.oneThing = str(b.oneThing); b.subject = str(b.subject); b.preheader = str(b.preheader);
    b.deepDive = (b.deepDive && typeof b.deepDive === 'object') ? b.deepDive : null;
    b.sections = b.sections.filter(s => s && (s.title || s.id));
    b.adImpact = str(b.adImpact);
    b.orgImpact = (b.orgImpact && typeof b.orgImpact === 'object' && !Array.isArray(b.orgImpact)) ? b.orgImpact : {};
    ['immediate','nearTerm','strategic','none'].forEach(k => { b.orgImpact[k] = arr(b.orgImpact[k]); });
    b.gaps = (b.gaps && typeof b.gaps === 'object') ? b.gaps : {};
    ['confirmedFacts','assumptions','intelGaps','competing','confidenceLimits','whatWouldChange','collectionPriorities','lowConfidence','conflicting'].forEach(k => { b.gaps[k] = arr(b.gaps[k]); });
    return b;
  }

  function sectionShell(title, iconClass) {
    const sec = h('section', { class:'brief-section' });
    sec.appendChild(h('h2', {}, [h('span', { class:'sec-accent' }), h('i', { class:'bi '+(iconClass||'bi-dot') }), ' '+title]));
    const inner = h('div', { class:'sec-inner' }); sec.appendChild(inner);
    return { sec, inner };
  }
  function field(label, value) { if (!has(value)) return null; return h('div', { class:'field' }, [h('span', { class:'lbl' }, label), str(value)]); }
  function sourcesList(sources) {
    const list = arr(sources).filter(s => s && (s.url || s.title)); if (!list.length) return null;
    const ul = h('ul', { class:'src-list' }); list.forEach(s => ul.appendChild(h('li', {}, link(s.title || s.url, s.url))));
    return h('div', {}, [h('div', { class:'lbl' }, 'Sources'), ul]);
  }

  // Ordered field spec for an intel item (v2 + v1 aliases). Absent fields skip.
  const ITEM_FIELDS = [
    ['observation','Observation'], ['fact','Fact'], ['context','Context'], ['change','Change'],
    ['significance','Significance'], ['whyMatters','Why it matters'],
    ['threat','Threat'], ['observedActivity','Observed activity'], ['affected','Affected'], ['exploitation','Exploitation'], ['defenseRelevance','Defense relevance'], ['posture','Recommended posture'],
    ['observedAction','Observed action'], ['likelyObjective','Likely objective'], ['capability','Capability'], ['intentAssessment','Intent assessment'],
    ['causation','Causation'], ['actorIntent','Actor intent'],
    ['assessment','Assessment'], ['outlook','Outlook'],
    ['implications','Implications'], ['secondOrder','Second-order effect'], ['thirdOrder','Third-order effect'],
    ['orgImpact','Organizational impact'], ['orgRelevance','Organizational relevance'], ['myImpact','Personal / leadership impact'],
    ['recommendation','Recommendation'], ['owner','Suggested owner'], ['actionThreshold','Action threshold'], ['timeHorizon','Time horizon']
  ];
  function statusChip(v) { const V = up(v); if (!V) return null; return h('span', { class:'chip status-chip' }, V); }
  function ipChip(ip) { if (!ip || (!has(ip.impact) && !has(ip.probability))) return null; return h('span', { class:'chip ip-chip' }, up(ip.impact) + ' impact / ' + up(ip.probability) + ' prob'); }
  function impactChainBlock(chain) {
    const steps = arr(chain).filter(has); if (!steps.length) return null;
    const wrap = h('div', { class:'impact-chain' }, h('div', { class:'lbl' }, 'Impact chain'));
    steps.forEach((s, i) => { wrap.appendChild(h('div', { class:'ic-step' + (i === steps.length - 1 ? ' ic-last' : '') }, str(s))); });
    return wrap;
  }
  function subBlock(title, obj, pairs) {
    const rows = pairs.filter(p => has(obj[p[0]]));
    if (!rows.length) return null;
    const wrap = h('div', { class:'subblock' }, h('div', { class:'lbl' }, title));
    rows.forEach(p => wrap.appendChild(h('div', { class:'field' }, [h('span', { class:'sublbl' }, p[1]+': '), str(obj[p[0]])])));
    return wrap;
  }
  function renderItem(it) {
    if (!it || typeof it !== 'object') return null;
    const wrap = h('div', { class:'intel-item' });
    const head = h('div', { class:'d-flex flex-wrap align-items-center gap-2 mb-1' });
    head.appendChild(h('h3', { class:'mb-0 me-auto' }, str(it.headline) || 'Untitled'));
    [priChip(it.priority), statusChip(it.status), confChip(it.confidence), ipChip(it.impactProbability)].forEach(c => c && head.appendChild(c));
    if (it.indicators && has(it.indicators.level)) head.appendChild(iwBadge(it.indicators.level));
    wrap.appendChild(head);
    if (has(it.region) || has(it.category)) wrap.appendChild(h('div', { class:'item-tags' }, [it.region ? h('span', { class:'meta-tag' }, [h('i', { class:'bi bi-geo-alt' }), ' ' + str(it.region)]) : null, it.category ? h('span', { class:'meta-tag' }, [h('i', { class:'bi bi-tag' }), ' ' + str(it.category)]) : null]));
    ITEM_FIELDS.forEach(f => { const n = field(f[1], it[f[0]]); if (n) wrap.appendChild(n); });
    const chain = impactChainBlock(it.impactChain); if (chain) wrap.appendChild(chain);
    if (has(it.thirtySeconds)) wrap.appendChild(h('div', { class:'thirty-sec' }, [h('span', { class:'lbl' }, [h('i', { class:'bi bi-stopwatch' }), ' 30 seconds with leadership']), str(it.thirtySeconds)]));
    // Indicators & Warnings list
    if (it.indicators && arr(it.indicators.list).length) {
      const iw = h('div', { class:'subblock' }, h('div', { class:'lbl' }, 'Indicators & Warnings'));
      const ul = h('ul', { class:'mb-0' }); arr(it.indicators.list).forEach(x => ul.appendChild(h('li', {}, str(x)))); iw.appendChild(ul);
      wrap.appendChild(iw);
    }
    // Alternative analysis
    if (it.alt) { const b = subBlock('Alternative analysis', it.alt, [['leading','Leading'],['alternative','Alternative'],['wildcard','Wildcard']]); if (b) wrap.appendChild(b); }
    // Scenarios
    if (it.scenarios) { const b = subBlock('Scenarios', it.scenarios, [['mostLikely','Most likely'],['bestCase','Best case'],['worstCase','Worst case'],['highImpactLowProb','High-impact / low-probability']]); if (b) wrap.appendChild(b); }
    if (has(it.takeaway)) wrap.appendChild(h('div', { class:'takeaway' }, [h('span', { class:'lbl' }, 'Executive takeaway'), str(it.takeaway)]));
    const sl = sourcesList(it.sources); if (sl) wrap.appendChild(sl);
    return wrap;
  }

  const SECTION_ICONS = { strategic:'bi-globe-americas', geopolitics:'bi-globe-americas', usdefense:'bi-flag', aerospace:'bi-airplane-engines', cyber:'bi-shield-lock', ai:'bi-cpu', space:'bi-rocket-takeoff', dib:'bi-gear-wide-connected', contracting:'bi-file-earmark-text', competitors:'bi-binoculars', allies:'bi-people' };

  function renderMeta(b) {
    const wrap = h('div', { class:'brief-meta' });
    wrap.appendChild(h('span', { class:'cls-badge text-primary' }, [h('i', { class:'bi bi-shield-check' }), ' '+b.classification]));
    if (b.date) wrap.appendChild(h('span', {}, [h('i', { class:'bi bi-calendar3' }), ' '+b.date]));
    if (b.reportingWindow) wrap.appendChild(h('span', {}, [h('i', { class:'bi bi-clock' }), ' '+b.reportingWindow]));
    const m = b.meta;
    if (m && m.generatedBy) {
      wrap.appendChild(h('span', { class:'text-secondary' }, [h('i', { class:'bi bi-stars' }), ' '+m.generatedBy+(m.model ? ' · '+m.model : '')]));
      if (m.webSearch) wrap.appendChild(h('span', { class:'text-secondary' }, [h('i', { class:'bi bi-search' }), ' live search']));
      else if (m.webSearchDegraded) wrap.appendChild(h('span', { class:'text-warning' }, [h('i', { class:'bi bi-exclamation-triangle' }), ' search unavailable — lower confidence']));
    }
    return wrap;
  }

  function bulletSection(title, icon, items, mapFn) {
    if (!items || !items.length) return null;
    const { sec, inner } = sectionShell(title, icon);
    const ul = h('ul', { class:'mb-0' }); items.forEach(it => { const li = mapFn(it); if (li) ul.appendChild(li); });
    if (!ul.children.length) return null; inner.appendChild(ul); return sec;
  }

  function render(brief, metaEl, bodyEl) {
    const b = normalize(brief);
    metaEl.textContent = ''; metaEl.appendChild(renderMeta(b));
    bodyEl.textContent = '';
    const frag = doc.createDocumentFragment();
    const add = n => n && frag.appendChild(n);

    // Barometer (first screen)
    if (b.barometer.length) {
      const { sec, inner } = sectionShell('Executive Threat / Opportunity Barometer', 'bi-speedometer2');
      const grid = h('div', { class:'barometer-grid' });
      b.barometer.forEach(x => {
        const st = up(x.status) || 'STABLE';
        const cell = h('div', { class:'baro-cell baro-' + st.toLowerCase() });
        const dir = { UP:'↑', FLAT:'→', DOWN:'↓' }[up(x.direction)] || '';
        cell.appendChild(h('div', { class:'baro-cat' }, str(x.category)));
        cell.appendChild(h('div', { class:'baro-status' }, [h('span', { class:'baro-dot' }), st + (dir ? ' ' + dir : '')]));
        if (has(x.reason)) cell.appendChild(h('div', { class:'baro-reason' }, str(x.reason)));
        if (has(x.confidence)) cell.appendChild(h('div', { class:'baro-conf' }, up(x.confidence) + ' conf'));
        grid.appendChild(cell);
      });
      inner.appendChild(grid); add(sec);
    }

    // BLUF
    if (b.bluf.length) {
      const { sec, inner } = sectionShell('BLUF — Bottom Line Up Front', 'bi-lightning-charge');
      const ul = h('ul', { class:'bluf-list' });
      b.bluf.forEach(x => {
        if (typeof x === 'string') { ul.appendChild(h('li', {}, x)); return; }
        const li = h('li', {}); li.appendChild(h('div', { class:'bluf-what' }, str(x.what)));
        const sub = [];
        if (has(x.matters)) sub.push(h('div', {}, [h('span', { class:'bluf-tag' }, 'Why: '), str(x.matters)]));
        if (has(x.changes) || has(x.changed)) sub.push(h('div', {}, [h('span', { class:'bluf-tag' }, 'Could change: '), str(x.changes || x.changed)]));
        if (has(x.needToKnow)) sub.push(h('div', {}, [h('span', { class:'bluf-tag' }, 'Need to know: '), str(x.needToKnow)]));
        if (sub.length) li.appendChild(h('div', { class:'small text-secondary' }, sub));
        ul.appendChild(li);
      });
      inner.appendChild(ul); add(sec);
    }

    // Top 3
    if (b.top3.length) {
      const { sec, inner } = sectionShell('The 3 Things I Cannot Afford to Miss Today', 'bi-exclamation-diamond');
      b.top3.forEach((t, i) => {
        const card = h('div', { class:'top3-card' });
        const head = h('div', { class:'d-flex flex-wrap align-items-center gap-2 mb-1' }, h('span', { class:'top3-num' }, String(i+1)), h('span', { class:'fw-bold me-auto' }, str(t.development)));
        const c = confChip(t.confidence); if (c) head.appendChild(c);
        card.appendChild(head);
        [field('Assessment', t.assessment), field('Why this matters', t.whyMatters), field('Organizational impact', t.orgImpact), field('My impact', t.myImpact), field('Recommendation', t.recommendation)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Strategic warning (prominent)
    if (b.strategicWarning.length) {
      const { sec, inner } = sectionShell('⚠ Strategic Warning', 'bi-exclamation-triangle-fill');
      sec.classList.add('warn-section');
      b.strategicWarning.forEach(w => {
        const card = h('div', { class:'intel-item' });
        [field('Observed indicators', w.indicators), field('Assessment', w.assessment), field('Potential impact', w.impact), field('What would increase concern', w.increaseConcern), field('What would reduce concern', w.reduceConcern)].forEach(f => f && card.appendChild(f));
        const c = confChip(w.confidence); if (c) card.appendChild(c);
        inner.appendChild(card);
      });
      add(sec);
    }

    // Watchboard
    if (b.watchboard.length || b.watchlist.length) {
      const src = b.watchboard.length ? b.watchboard : b.watchlist;
      const { sec, inner } = sectionShell('Executive Watchboard', 'bi-eye');
      src.forEach(w => {
        const card = h('div', { class:'watchlist-card' });
        const head = h('div', { class:'wl-head' }, h('span', { class:'wl-dev' }, str(w.issue || w.development)));
        [dirChip(w.direction), confChip(w.confidence)].forEach(c => c && head.appendChild(c));
        card.appendChild(head);
        [field('Status', w.status), field('Risk', w.risk), field('Opportunity', w.opportunity), field('Why', w.why), field('Next indicator', w.nextIndicator || w.watchNext)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Domain sections
    b.sections.forEach(s => {
      const items = arr(s.items);
      const { sec, inner } = sectionShell(str(s.title) || str(s.id), SECTION_ICONS[s.id] || 'bi-dot');
      if (!items.length) inner.appendChild(h('div', { class:'empty-state-sm' }, 'No material change.'));
      else items.forEach(it => { const n = renderItem(it); if (n) inner.appendChild(n); });
      add(sec);
    });

    // Cross-domain connections
    if (b.crossDomain.length) {
      const { sec, inner } = sectionShell('Cross-Domain Connections', 'bi-diagram-2');
      b.crossDomain.forEach(c => {
        const card = h('div', { class:'xdomain' });
        card.appendChild(h('div', { class:'xd-line' }, [h('span', { class:'xd-tag' }, 'A'), ' ' + str(c.a)]));
        card.appendChild(h('div', { class:'xd-line' }, [h('span', { class:'xd-tag' }, 'B'), ' ' + str(c.b)]));
        card.appendChild(h('div', { class:'xd-imp' }, [h('i', { class:'bi bi-arrow-return-right' }), ' ' + str(c.implication)]));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Intelligence patterns
    if (b.patterns.length) {
      const { sec, inner } = sectionShell('Intelligence Patterns Detected', 'bi-diagram-2-fill');
      b.patterns.forEach(p => { const c = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(p.pattern))); [field('Supporting signals', p.signals), field('Possible meaning', p.meaning), field('Would confirm', p.confirm), field('Would disprove', p.disconfirm)].forEach(f => f && c.appendChild(f)); const cc = confChip(p.confidence); if (cc) c.appendChild(cc); inner.appendChild(c); }); add(sec);
    }
    // Technology radar
    if (b.techRadar.length) {
      const { sec, inner } = sectionShell('Technology Radar', 'bi-broadcast-pin');
      const grid = h('div', { class:'radar-grid' });
      b.techRadar.forEach(t => { const cell = h('div', { class:'radar-cell' }); cell.appendChild(h('span', { class:'radar-stage stage-' + (up(t.stage)||'').toLowerCase() }, up(t.stage))); cell.appendChild(h('span', { class:'radar-tech' }, str(t.tech))); if (has(t.note)) cell.appendChild(h('div', { class:'radar-note' }, str(t.note))); grid.appendChild(cell); });
      inner.appendChild(grid); add(sec);
    }
    // Contracting & acquisition radar
    if (b.contractRadar.length) {
      const { sec, inner } = sectionShell('Contracting & Acquisition Radar', 'bi-file-earmark-ruled');
      b.contractRadar.forEach(c => { const card = h('div', { class:'intel-item' }); card.appendChild(h('h3', { class:'h6 mb-1' }, [str(c.program) || str(c.agency), c.value ? h('span', { class:'contract-val' }, ' ' + str(c.value)) : null])); [field('Agency / customer', c.agency), field('Recipient / competitors', c.recipient), field('What is being acquired', c.what), field('Why it matters', c.whyMatters), field('Opportunity signal', c.opportunitySignal)].forEach(f => f && card.appendChild(f)); inner.appendChild(card); }); add(sec);
    }
    // Strategic opportunities
    if (b.opportunities.length) {
      const { sec, inner } = sectionShell('Strategic Opportunities', 'bi-lightbulb'); sec.classList.add('opp-section');
      b.opportunities.forEach(o => { const card = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(o.opportunity))); [field('Evidence', o.evidence), field('Why it could matter', o.whyMatters), field('Time horizon', o.horizon), field('Who may benefit', o.whoBenefits), field('What to watch', o.watch)].forEach(f => f && card.appendChild(f)); inner.appendChild(card); }); add(sec);
    }
    // Competitive intelligence
    if (b.competitive.length) {
      const { sec, inner } = sectionShell('Industry Competitive Intelligence', 'bi-buildings');
      b.competitive.forEach(c => { const card = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(c.org))); [field('Action', c.action), field('So what', c.soWhat)].forEach(f => f && card.appendChild(f)); inner.appendChild(card); }); add(sec);
    }
    // Under the radar
    if (b.underRadar.length) {
      const { sec, inner } = sectionShell('Under the Radar — What Others Are Missing', 'bi-eye-slash');
      b.underRadar.forEach(u => { const card = h('div', { class:'intel-item' }); card.appendChild(h('div', { class:'fw-semibold' }, str(u.development))); const w = field('Why it deserves attention', u.why); if (w) card.appendChild(w); inner.appendChild(card); }); add(sec);
    }

    // Resurfaced
    if (b.resurfaced.length) {
      const { sec, inner } = sectionShell('Resurfaced Intelligence', 'bi-arrow-repeat');
      b.resurfaced.forEach(r => {
        const card = h('div', { class:'intel-item' }, h('h3', {}, str(r.issue)));
        [field('Original event', r.originalEvent || r.originalTimeframe), field('Previous assessment', r.previousAssessment), field('New information', r.newInfo || r.newDevelopment), field('Why now', r.whyNow || r.whyResurfaced), field('What changed', r.whatChanged), field('Updated assessment', r.updatedAssessment || r.whyMattersNow)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Weak signals
    if (b.weakSignals.length) {
      const { sec, inner } = sectionShell('Weak Signals & Early Warning', 'bi-broadcast');
      b.weakSignals.forEach(w => {
        if (typeof w === 'string') { inner.appendChild(h('div', { class:'intel-item' }, w)); return; }
        const card = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(w.signal)));
        [field('Why unusual', w.whyUnusual), field('Potential trend', w.potentialTrend || w.why), field('Evidence', w.evidence), field('Would confirm', w.confirm), field('Would disconfirm', w.disconfirm)].forEach(f => f && card.appendChild(f));
        const c = confChip(w.confidence); if (c) card.appendChild(c);
        inner.appendChild(card);
      });
      add(sec);
    }

    // A&D industry impact
    if (has(b.adImpact)) { const { sec, inner } = sectionShell('Impact to the Aerospace & Defense Industry', 'bi-diagram-3'); inner.appendChild(h('p', { class:'mb-0' }, str(b.adImpact))); add(sec); }

    // Org impact (categorized)
    const oi = b.orgImpact;
    if (oi.immediate.length || oi.nearTerm.length || oi.strategic.length || oi.none.length) {
      const { sec, inner } = sectionShell('Impact to My Organization', 'bi-building');
      const block = (label, list, cls) => { if (!list.length) return; inner.appendChild(h('div', { class:'lbl mt-2 '+(cls||'') }, label)); const ul = h('ul', { class:'mb-0' }); list.forEach(x => ul.appendChild(h('li', {}, str(x)))); inner.appendChild(ul); };
      block('Immediate impact', oi.immediate); block('Near-term impact', oi.nearTerm); block('Strategic impact', oi.strategic); block('No material impact', oi.none, 'text-secondary');
      add(sec);
    }
    // v1 fallback
    if (!oi.immediate.length && !oi.nearTerm.length && !oi.strategic.length && b.myWork.length) add(bulletSection('Impact to My Work', 'bi-person-workspace', b.myWork, x => h('li', {}, str(x))));

    // How this applies to me
    if (b.appliesToMe.length) {
      const { sec, inner } = sectionShell('How This Applies to Me', 'bi-person-workspace');
      b.appliesToMe.forEach(a => {
        if (typeof a === 'string') { inner.appendChild(h('div', { class:'intel-item' }, a)); return; }
        const card = h('div', { class:'intel-item' }); if (has(a.topic)) card.appendChild(h('h3', { class:'h6 mb-1' }, str(a.topic)));
        [field('What I should understand', a.understand), field('Why I should care', a.care), field('What I could be asked', a.couldBeAsked), field('What I should investigate', a.investigate), field('Discuss with leadership', a.discuss), field('What I should monitor', a.monitor), field('What I should consider changing', a.consider)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }
    if (!b.appliesToMe.length && b.whatToKnow.length) add(bulletSection('What I Should Know Today', 'bi-mortarboard', b.whatToKnow, x => h('li', {}, str(x))));

    // Recommendations
    if (b.recommendations.length || b.actions.length) {
      const { sec, inner } = sectionShell('Executive Recommendations', 'bi-check2-square');
      if (b.recommendations.length) b.recommendations.forEach(r => {
        const card = h('div', { class:'rec-card' });
        const head = h('div', { class:'d-flex flex-wrap align-items-center gap-2 mb-1' });
        const cat = up(r.category) || 'WATCH';
        head.appendChild(h('span', { class:'action-badge act-'+(['INFORM','WATCH','VALIDATE','REVIEW','PREPARE','ENGAGE','INVESTIGATE','ACT'].indexOf(cat)>=0?cat:'WATCH') }, cat));
        head.appendChild(h('span', { class:'fw-semibold me-auto' }, str(r.recommendation)));
        const c = confChip(r.confidence); if (c) head.appendChild(c);
        card.appendChild(head);
        [field('Rationale', r.rationale), field('Evidence', r.evidence), field('Timing', r.timing), field('Owner', r.owner), field('Trigger', r.trigger), field('Risk of action', r.riskOfAction), field('Risk of inaction', r.riskOfInaction)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      else b.actions.forEach(a => { const cat = up(a.type)||'WATCH'; inner.appendChild(h('div', { class:'action-row' }, h('span', { class:'action-badge act-'+(['WATCH','REVIEW','CONSIDER','DISCUSS','ACT'].indexOf(cat)>=0?cat:'WATCH') }, cat), h('span', {}, str(a.text)))); });
      add(sec);
    }

    // Decision memos
    if (b.decisionMemos.length) {
      const { sec, inner } = sectionShell('Decision Memos', 'bi-clipboard2-check');
      b.decisionMemos.forEach(m => {
        const card = h('div', { class:'decision-memo' });
        card.appendChild(h('h3', { class:'h6' }, [h('i', { class:'bi bi-flag-fill me-1' }), 'Decision: ' + str(m.decision)]));
        [field('Why now', m.whyNow), field('Background', m.background)].forEach(f => f && card.appendChild(f));
        arr(m.options).forEach((o, i) => {
          const ob = h('div', { class:'memo-option' }, h('div', { class:'fw-semibold' }, 'Option ' + (i+1) + (has(o.label) ? ': ' + str(o.label) : '')));
          [field('Benefits', o.benefits), field('Risks', o.risks)].forEach(f => f && ob.appendChild(f));
          card.appendChild(ob);
        });
        [field('Recommended', m.recommended), field('Reason', m.reason), field('What would change it', m.whatWouldChange), field('Decision date', m.decisionDate)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Questions I should ask
    if (b.questionsToAsk.length) {
      const { sec, inner } = sectionShell('Questions I Should Be Asking', 'bi-question-circle');
      b.questionsToAsk.forEach(q => { const item = h('div', { class:'qa-item' }); if (has(q.audience)) item.appendChild(h('span', { class:'aud-tag' }, str(q.audience))); item.appendChild(h('span', { class:'qa-q' }, ' ' + str(q.question || q))); inner.appendChild(item); });
      add(sec);
    }

    // Questions I may be asked
    if (b.questionsAsked.length || b.execQuestions.length) {
      const src = b.questionsAsked.length ? b.questionsAsked : b.execQuestions;
      const { sec, inner } = sectionShell('Questions I May Be Asked', 'bi-chat-quote');
      src.forEach(q => {
        const item = h('div', { class:'qa-item' });
        item.appendChild(h('div', { class:'qa-q' }, 'Q: ' + str(q.question || q.q)));
        item.appendChild(h('div', { class:'qa-a' }, 'A: ' + str(q.answer || q.a)));
        if (has(q.evidence)) item.appendChild(h('div', { class:'small text-secondary' }, [h('span', { class:'sublbl' }, 'Evidence: '), str(q.evidence)]));
        if (has(q.caveat)) item.appendChild(h('div', { class:'small text-secondary' }, [h('span', { class:'sublbl' }, 'Caveat: '), str(q.caveat)]));
        inner.appendChild(item);
      });
      add(sec);
    }

    // Watch horizons
    add(bulletSection('What to Watch Next — 24 to 72 Hours', 'bi-hourglass-split', b.watch2472h.length ? b.watch2472h : b.watch24h, x => h('li', {}, str(x))));
    add(bulletSection('7–30 Day Outlook', 'bi-calendar-range', b.watch730d, x => h('li', {}, str(x))));
    add(bulletSection('3–12 Month Strategic Outlook', 'bi-calendar3-range', b.watch312mo, x => h('li', {}, str(x))));

    // Strategic surprise
    if (b.strategicSurprise.length) {
      const { sec, inner } = sectionShell('Strategic Surprise Watch', 'bi-lightning');
      b.strategicSurprise.forEach(s => { const card = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(s.development))); [field('Why', s.why), field('Potential impact', s.impact)].forEach(f => f && card.appendChild(f)); inner.appendChild(card); });
      add(sec);
    }

    // What could we be wrong about
    add(bulletSection('What Could We Be Wrong About?', 'bi-shuffle', b.wrongAbout, x => h('li', {}, str(x))));

    // Red team
    if (b.redTeam.length) {
      const { sec, inner } = sectionShell('Red-Team Review', 'bi-bullseye');
      b.redTeam.forEach(r => { const card = h('div', { class:'intel-item' }); if (has(r.issue)) card.appendChild(h('h3', { class:'h6 mb-1' }, str(r.issue))); [field('Red-team objection', r.objection), field('Analytic response', r.response)].forEach(f => f && card.appendChild(f)); inner.appendChild(card); });
      add(sec);
    }

    // Forecast performance review
    if (b.forecastReview.length) {
      const { sec, inner } = sectionShell('Intelligence Performance Review', 'bi-graph-up');
      b.forecastReview.forEach(f => {
        const row = h('div', { class:'action-row' });
        const o = up(f.outcome) || 'PENDING';
        row.appendChild(h('span', { class:'action-badge fc-'+(['CORRECT','INCORRECT','PARTIAL','PENDING'].indexOf(o)>=0?o:'PENDING') }, o));
        row.appendChild(h('span', {}, str(f.priorForecast) + (has(f.date) ? ' ('+str(f.date)+')' : '') + (has(f.note) ? ' — ' + str(f.note) : '')));
        inner.appendChild(row);
      });
      add(sec);
    }

    // 2-minute deep dive
    if (b.deepDive && (has(b.deepDive.topic) || has(b.deepDive.explanation))) {
      const { sec, inner } = sectionShell('2-Minute Deep Dive' + (has(b.deepDive.topic) ? ' — ' + str(b.deepDive.topic) : ''), 'bi-mortarboard');
      inner.appendChild(h('p', { class:'mb-0' }, str(b.deepDive.explanation)));
      add(sec);
    }
    // The Big Picture
    if (has(b.bigPicture)) { const { sec, inner } = sectionShell('The Big Picture', 'bi-globe'); sec.classList.add('bigpic-section'); inner.appendChild(h('p', { class:'mb-0' }, str(b.bigPicture))); add(sec); }
    // The One Thing
    if (has(b.oneThing)) { const box = h('section', { class:'one-thing' }, [h('div', { class:'one-thing-label' }, [h('i', { class:'bi bi-star-fill' }), ' If You Remember Only One Thing Today']), h('div', { class:'one-thing-body' }, str(b.oneThing))]); add(box); }

    // Sources & confidence
    if (b.sources.length) {
      const { sec, inner } = sectionShell('Source Notes', 'bi-journal-check');
      b.sources.forEach(s => {
        const card = h('div', { class:'intel-item' });
        if (has(s.claim)) card.appendChild(h('div', { class:'fw-semibold mb-1' }, str(s.claim)));
        const meta = h('div', { class:'d-flex flex-wrap gap-2 mb-1' }); const cc = confChip(s.confidence); if (cc) meta.appendChild(cc); if (has(s.reliability)) meta.appendChild(h('span', { class:'small text-secondary' }, 'reliability: '+str(s.reliability))); if (meta.children.length) card.appendChild(meta);
        [field('Source', s.source), field('Published', s.pubDate), field('Event date', s.eventDate), field('Type', s.type)].forEach(f => f && card.appendChild(f));
        const prim = arr(s.primary).filter(Boolean); const corr = arr(s.corroborating).filter(Boolean);
        const linkRow = (label, list) => { if (!list.length) return; const d = h('div', { class:'field' }, h('span', { class:'lbl' }, label)); list.forEach((u,i)=>{ if(i) d.appendChild(doc.createTextNode(' · ')); d.appendChild(link(u,u)); }); card.appendChild(d); };
        linkRow('Primary', prim); linkRow('Corroborating', corr);
        [field('Conflicting', s.conflicts), field('Unverified', s.unverified)].forEach(f => f && card.appendChild(f));
        inner.appendChild(card);
      });
      add(sec);
    }

    // Assumptions, gaps & confidence
    const g = b.gaps;
    const anyGap = ['confirmedFacts','assumptions','intelGaps','competing','confidenceLimits','whatWouldChange','collectionPriorities','lowConfidence','conflicting'].some(k => g[k].length);
    if (anyGap) {
      const { sec, inner } = sectionShell('Assumptions, Intelligence Gaps & Analytic Confidence', 'bi-clipboard-data');
      const block = (label, list) => { if (!list.length) return; inner.appendChild(h('div', { class:'lbl mt-2' }, label)); const ul = h('ul', { class:'mb-0' }); list.forEach(x => { if (x && typeof x === 'object') { const parts = [h('strong', { text: str(x.assumption) })]; if (has(x.whyRequired)) parts.push(' — why: ' + str(x.whyRequired)); if (has(x.impactIfWrong)) parts.push(' · if wrong: ' + str(x.impactIfWrong)); ul.appendChild(h('li', {}, parts)); } else ul.appendChild(h('li', {}, str(x))); }); inner.appendChild(ul); };
      block('Confirmed facts', g.confirmedFacts);
      block('Assumptions', g.assumptions);
      block('Intelligence gaps', g.intelGaps);
      block('Competing assessments', g.competing);
      block('Confidence limitations', g.confidenceLimits);
      block('Conflicting information', g.conflicting);
      block('Low-confidence reporting', g.lowConfidence);
      block('What would change our assessment', g.whatWouldChange);
      block('Collection priorities (lawful, passive, open-source)', g.collectionPriorities);
      add(sec);
    }

    bodyEl.appendChild(frag);
  }

  function headlineOf(b) {
    const n = normalize(b);
    if (n.top3.length && (n.top3[0].development)) return str(n.top3[0].development);
    if (n.bluf.length) { const x = n.bluf[0]; return typeof x === 'string' ? x : str(x.what); }
    if (n.watchboard.length) return str(n.watchboard[0].issue);
    return '(no BLUF)';
  }

  // ---------------- WEEKLY ----------------
  function renderWeekly(w, metaEl, bodyEl) {
    w = w && typeof w === 'object' ? w : {};
    metaEl.textContent = '';
    const meta = h('div', { class:'brief-meta' });
    meta.appendChild(h('span', { class:'cls-badge text-primary' }, [h('i', { class:'bi bi-shield-check' }), ' ' + (w.classification || 'PUBLIC / OPEN-SOURCE / NON-CLASSIFIED')]));
    if (w.weekOf) meta.appendChild(h('span', {}, [h('i', { class:'bi bi-calendar-week' }), ' Week of ' + w.weekOf]));
    if (w.meta && w.meta.generatedBy) meta.appendChild(h('span', { class:'text-secondary' }, [h('i', { class:'bi bi-stars' }), ' ' + w.meta.generatedBy + (w.meta.model ? ' · ' + w.meta.model : '')]));
    metaEl.appendChild(meta);
    bodyEl.textContent = '';
    const frag = doc.createDocumentFragment(); const add = n => n && frag.appendChild(n);
    if (has(w.executiveSummary)) { const { sec, inner } = sectionShell('Executive Summary', 'bi-file-text'); inner.appendChild(h('p', { class:'mb-0' }, str(w.executiveSummary))); add(sec); }
    add(bulletSection('What Changed This Week', 'bi-arrow-left-right', arr(w.whatChanged), x => h('li', {}, str(x))));
    if (arr(w.trendsEmerging).length) { const { sec, inner } = sectionShell('Trends Emerging', 'bi-graph-up-arrow'); arr(w.trendsEmerging).forEach(t => { const c = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(t.trend))); [field('Evidence', t.evidence), field('So what', t.soWhat)].forEach(f => f && c.appendChild(f)); const cc = confChip(t.confidence); if (cc) c.appendChild(cc); inner.appendChild(c); }); add(sec); }
    add(bulletSection('What Got Worse', 'bi-arrow-down-right-circle', arr(w.gotWorse), x => h('li', {}, str(x))));
    add(bulletSection('What Got Better', 'bi-arrow-up-right-circle', arr(w.gotBetter), x => h('li', {}, str(x))));
    add(bulletSection('What the News Cycle Missed', 'bi-eye-slash', arr(w.newsCycleMissed), x => h('li', {}, str(x))));
    if (arr(w.assumptionsChanged).length) { const { sec, inner } = sectionShell('Assumptions That Changed', 'bi-shuffle'); arr(w.assumptionsChanged).forEach(a => { const c = h('div', { class:'intel-item' }); [field('Was', a.was), field('Now', a.now), field('Implication', a.implication)].forEach(f => f && c.appendChild(f)); inner.appendChild(c); }); add(sec); }
    add(bulletSection('Threats Accelerating', 'bi-exclamation-triangle', arr(w.threatsAccelerating), x => h('li', {}, str(x))));
    add(bulletSection('Opportunities Emerging', 'bi-lightbulb', arr(w.opportunitiesEmerging), x => h('li', {}, str(x))));
    if (arr(w.threadMovement).length) { const { sec, inner } = sectionShell('Thread Movement', 'bi-diagram-3'); arr(w.threadMovement).forEach(t => { const c = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(t.thread))); const row = h('div', { class:'field' }, [h('span', { class:'lbl' }, 'Direction'), str(t.from) + ' → ' + str(t.to)]); c.appendChild(row); const n = field('Note', t.note); if (n) c.appendChild(n); inner.appendChild(c); }); add(sec); }
    if (arr(w.decisionsApproaching).length) { const { sec, inner } = sectionShell('Decisions Approaching', 'bi-signpost-split'); arr(w.decisionsApproaching).forEach(d => { const c = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(d.decision))); [field('By', d.by), field('Why', d.why)].forEach(f => f && c.appendChild(f)); inner.appendChild(c); }); add(sec); }
    add(bulletSection('What Leadership Should Be Discussing', 'bi-people', arr(w.leadershipDiscussion), x => h('li', {}, str(x))));
    if (arr(w.forecastLedger).length) { const { sec, inner } = sectionShell('Forecast Ledger', 'bi-graph-up'); arr(w.forecastLedger).forEach(f => { const o = up(f.outcome) || 'PENDING'; const row = h('div', { class:'action-row' }); row.appendChild(h('span', { class:'action-badge fc-'+(['CORRECT','INCORRECT','PARTIAL','PENDING'].indexOf(o)>=0?o:'PENDING') }, o)); row.appendChild(h('span', {}, str(f.forecast) + (has(f.note) ? ' — ' + str(f.note) : ''))); inner.appendChild(row); }); add(sec); }
    add(bulletSection('Outlook — Next Week', 'bi-hourglass-split', arr(w.outlookNextWeek), x => h('li', {}, str(x))));
    renderGapsBlock(w.gaps, add);
    renderFlatSources(w.sources, add);
    bodyEl.appendChild(frag);
  }

  // ---------------- MONTHLY ----------------
  function renderMonthly(m, metaEl, bodyEl) {
    m = m && typeof m === 'object' ? m : {};
    metaEl.textContent = '';
    const meta = h('div', { class:'brief-meta' });
    meta.appendChild(h('span', { class:'cls-badge text-primary' }, [h('i', { class:'bi bi-shield-check' }), ' ' + (m.classification || 'PUBLIC / OPEN-SOURCE / NON-CLASSIFIED')]));
    if (m.month) meta.appendChild(h('span', {}, [h('i', { class:'bi bi-calendar3' }), ' ' + m.month]));
    if (m.meta && m.meta.generatedBy) meta.appendChild(h('span', { class:'text-secondary' }, [h('i', { class:'bi bi-stars' }), ' ' + m.meta.generatedBy + (m.meta.model ? ' · ' + m.meta.model : '')]));
    metaEl.appendChild(meta);
    bodyEl.textContent = '';
    const frag = doc.createDocumentFragment(); const add = n => n && frag.appendChild(n);
    if (has(m.executiveSummary)) { const { sec, inner } = sectionShell('Executive Summary', 'bi-file-text'); inner.appendChild(h('p', { class:'mb-0' }, str(m.executiveSummary))); add(sec); }
    if (arr(m.strategicJudgments).length) add(bulletSection('Strategic Judgments', 'bi-compass', arr(m.strategicJudgments), x => h('li', {}, str(x))));
    if (arr(m.estimate).length) {
      const { sec, inner } = sectionShell('Strategic Estimate by Domain', 'bi-grid-3x3-gap');
      arr(m.estimate).forEach(e => {
        const card = h('div', { class:'intel-item' });
        const head = h('div', { class:'d-flex flex-wrap align-items-center gap-2 mb-1' }, h('h3', { class:'h6 mb-0 me-auto' }, str(e.domain)));
        const d = dirChip(e.trajectory); if (d) head.appendChild(d); const c = confChip(e.confidence); if (c) head.appendChild(c);
        card.appendChild(head);
        [field('Current', e.current), field('Trajectory', e.trajectory), field('Most likely', e.mostLikely), field('Risk', e.risk), field('Opportunity', e.opportunity), field('Organizational impact', e.orgImpact), field('Recommended posture', e.posture)].forEach(f => f && card.appendChild(f));
        if (arr(e.keyIndicators).length) { const iw = h('div', { class:'subblock' }, h('div', { class:'lbl' }, 'Key indicators')); const ul = h('ul', { class:'mb-0' }); arr(e.keyIndicators).forEach(x => ul.appendChild(h('li', {}, str(x)))); iw.appendChild(ul); card.appendChild(iw); }
        inner.appendChild(card);
      });
      add(sec);
    }
    const hz = m.horizon || {};
    add(bulletSection('30-Day Horizon', 'bi-hourglass-split', arr(hz.d30), x => h('li', {}, str(x))));
    add(bulletSection('90-Day Horizon', 'bi-calendar-range', arr(hz.d90), x => h('li', {}, str(x))));
    add(bulletSection('365-Day Horizon', 'bi-calendar3', arr(hz.d365), x => h('li', {}, str(x))));
    if (arr(m.decisionsApproaching).length) { const { sec, inner } = sectionShell('Decisions Approaching', 'bi-signpost-split'); arr(m.decisionsApproaching).forEach(d => { const c = h('div', { class:'intel-item' }, h('h3', { class:'h6 mb-1' }, str(d.decision))); [field('By', d.by), field('Why', d.why)].forEach(f => f && c.appendChild(f)); inner.appendChild(c); }); add(sec); }
    if (arr(m.forecastReview).length) { const { sec, inner } = sectionShell('Forecast Review', 'bi-graph-up'); arr(m.forecastReview).forEach(f => { const o = up(f.outcome) || 'PENDING'; const row = h('div', { class:'action-row' }); row.appendChild(h('span', { class:'action-badge fc-'+(['CORRECT','INCORRECT','PARTIAL','PENDING'].indexOf(o)>=0?o:'PENDING') }, o)); row.appendChild(h('span', {}, str(f.priorForecast) + (has(f.note) ? ' — ' + str(f.note) : ''))); inner.appendChild(row); }); add(sec); }
    renderGapsBlock(m.gaps, add);
    renderFlatSources(m.sources, add);
    bodyEl.appendChild(frag);
  }

  function renderGapsBlock(g, add) {
    g = (g && typeof g === 'object') ? g : {};
    const A = k => arr(g[k]);
    if (!(A('assumptions').length || A('intelGaps').length || A('whatWouldChange').length)) return;
    const { sec, inner } = sectionShell('Assumptions, Gaps & What Would Change Our View', 'bi-clipboard-data');
    const block = (label, list) => { if (!list.length) return; inner.appendChild(h('div', { class:'lbl mt-2' }, label)); const ul = h('ul', { class:'mb-0' }); list.forEach(x => ul.appendChild(h('li', {}, str(x)))); inner.appendChild(ul); };
    block('Assumptions', A('assumptions')); block('Intelligence gaps', A('intelGaps')); block('What would change our assessment', A('whatWouldChange'));
    add(sec);
  }
  function renderFlatSources(sources, add) {
    const list = arr(sources).filter(s => s && (s.url || s.claim)); if (!list.length) return;
    const { sec, inner } = sectionShell('Source Notes', 'bi-journal-check');
    list.forEach(s => { const card = h('div', { class:'intel-item' }); if (has(s.claim)) card.appendChild(h('div', { class:'fw-semibold mb-1' }, str(s.claim))); const row = h('div', {}); const cc = confChip(s.confidence); if (cc) row.appendChild(cc); if (safeUrl(s.url)) { row.appendChild(doc.createTextNode(' ')); row.appendChild(link(s.url, s.url)); } card.appendChild(row); inner.appendChild(card); });
    add(sec);
  }

  M.render = { render, renderWeekly, renderMonthly, normalize, headlineOf };
})(window);
