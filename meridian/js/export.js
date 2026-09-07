/* MERIDIAN v2 — Export & delivery.
 * Builds an inline-styled HTML email body and a plain-text version, copies both
 * to the clipboard, prints/saves as PDF, sends via the EmailJS REST API, and
 * downloads briefs as JSON. All model/user content is HTML-escaped before it
 * enters the email string. Covers the v2 schema; tolerant of v1 fields.
 * window.MERIDIAN.exporter
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const doc = root.document;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function safeUrl(u) { const s = String(u || '').trim(); return /^https?:\/\//i.test(s) ? s : ''; }
  function A(url, text) { const s = safeUrl(url); return s ? '<a href="' + esc(s) + '" style="color:#3d6fe0">' + esc(text || s) + '</a>' : esc(text || ''); }
  function arr(x) { return Array.isArray(x) ? x : (x == null ? [] : [x]); }
  function has(x) { return String(x == null ? '' : x).trim() !== ''; }

  function subjectFor(b, orgName) { return (b && has(b.subject)) ? b.subject : ((orgName || 'MERIDIAN') + ' Executive Strategic Intelligence Brief — ' + (b.date || '')); }

  const ITEM_FIELDS = [
    ['observation','Observation'], ['fact','Fact'], ['context','Context'], ['change','Change'],
    ['significance','Significance'], ['whyMatters','Why it matters'],
    ['threat','Threat'], ['observedActivity','Observed activity'], ['affected','Affected'], ['exploitation','Exploitation'], ['defenseRelevance','Defense relevance'], ['posture','Recommended posture'],
    ['observedAction','Observed action'], ['likelyObjective','Likely objective'], ['capability','Capability'], ['intentAssessment','Intent assessment'],
    ['causation','Causation'], ['actorIntent','Actor intent'],
    ['assessment','Assessment'], ['outlook','Outlook'],
    ['implications','Implications'], ['secondOrder','Second-order effect'], ['thirdOrder','Third-order effect'],
    ['orgImpact','Organizational impact'], ['orgRelevance','Organizational relevance'], ['myImpact','Personal / leadership impact'], ['recommendation','Recommendation'], ['owner','Suggested owner'], ['actionThreshold','Action threshold'], ['timeHorizon','Time horizon']
  ];

  // ---------- HTML email ----------
  function toEmailHtml(brief, branding) {
    const b = M.render.normalize(brief);
    const org = (branding && (branding.orgName || '').trim()) || 'MERIDIAN';
    const logo = branding && M.branding.sanitizeLogo(branding.logoUrl);
    const S = []; const p = s => S.push(s);
    const sec = t => p('<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #3d6fe0;padding-bottom:4px;margin:22px 0 8px;color:#1f2937">' + esc(t) + '</h2>');
    const fld = (l, v) => has(v) ? '<div style="margin:3px 0"><span style="font-weight:700;font-size:11px;text-transform:uppercase;color:#6b7280">' + esc(l) + ':</span> ' + esc(v) + '</div>' : '';
    const ulist = (title, list, mapFn) => { if (!list.length) return; sec(title); p('<ul style="margin:0;padding-left:18px">'); list.forEach(x => p('<li>' + mapFn(x) + '</li>')); p('</ul>'); };

    p('<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:0 auto;color:#111;font-size:14px;line-height:1.5">');
    p('<div style="border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px">');
    if (logo) p('<img src="' + esc(logo) + '" alt="" style="height:34px;vertical-align:middle;margin-right:8px">');
    p('<span style="font-size:18px;font-weight:800;vertical-align:middle">' + esc(org) + '</span>');
    p('<div style="font-size:12px;color:#6b7280;margin-top:4px">Executive Strategic Intelligence Brief · ' + esc(b.classification) + '</div>');
    p('<div style="font-size:12px;color:#6b7280">' + esc(b.date) + (b.reportingWindow ? ' · ' + esc(b.reportingWindow) : '') + '</div>');
    if (has(b.preheader)) p('<div style="font-size:12px;color:#374151;margin-top:4px;font-style:italic">' + esc(b.preheader) + '</div>');
    p('</div>');

    if (b.barometer.length) {
      sec('Executive Threat / Opportunity Barometer');
      p('<table role="presentation" width="100%" style="border-collapse:collapse"><tr>');
      const colors = { CRITICAL:'#ef4444', ELEVATED:'#f59e0b', WATCH:'#a16207', STABLE:'#22c55e', OPPORTUNITY:'#3b82f6' };
      b.barometer.forEach((x, i) => { const st = (x.status||'STABLE').toUpperCase(); const c = colors[st] || '#64748b'; const dir = ({UP:'↑',FLAT:'→',DOWN:'↓'})[(x.direction||'').toUpperCase()] || '';
        p('<td style="width:25%;vertical-align:top;padding:4px"><div style="border:1px solid #e5e7eb;border-left:4px solid ' + c + ';border-radius:6px;padding:6px 8px"><div style="font-size:10px;text-transform:uppercase;color:#6b7280;font-weight:700">' + esc(x.category) + '</div><div style="font-weight:800;color:' + c + '">' + esc(st) + ' ' + dir + '</div>' + (has(x.reason) ? '<div style="font-size:11px;color:#374151">' + esc(x.reason) + '</div>' : '') + '</div></td>');
        if ((i + 1) % 4 === 0) p('</tr><tr>');
      });
      p('</tr></table>');
    }

    if (b.bluf.length) {
      sec('BLUF — Bottom Line Up Front'); p('<ul style="margin:0;padding-left:18px">');
      b.bluf.forEach(x => {
        if (typeof x === 'string') { p('<li>' + esc(x) + '</li>'); return; }
        let s = '<strong>' + esc(x.what) + '</strong>';
        if (has(x.matters)) s += '<br><span style="color:#6b7280">Why: ' + esc(x.matters) + '</span>';
        if (has(x.changes) || has(x.changed)) s += '<br><span style="color:#6b7280">Could change: ' + esc(x.changes || x.changed) + '</span>';
        if (has(x.needToKnow)) s += '<br><span style="color:#6b7280">Need to know: ' + esc(x.needToKnow) + '</span>';
        p('<li style="margin-bottom:6px">' + s + '</li>');
      });
      p('</ul>');
    }

    if (b.top3.length) {
      sec('The 3 Things I Cannot Afford to Miss Today');
      b.top3.forEach((t, i) => {
        p('<div style="border:1px solid #e5e7eb;border-left:3px solid #ef4444;border-radius:6px;padding:8px;margin:6px 0">');
        p('<div style="font-weight:700">' + (i+1) + '. ' + esc(t.development) + (has(t.confidence) ? ' <span style="font-size:11px;color:#6b7280">(' + esc(t.confidence) + ')</span>' : '') + '</div>');
        p(fld('Assessment', t.assessment) + fld('Why this matters', t.whyMatters) + fld('Organizational impact', t.orgImpact) + fld('My impact', t.myImpact) + fld('Recommendation', t.recommendation));
        p('</div>');
      });
    }

    const wb = b.watchboard.length ? b.watchboard : b.watchlist;
    if (wb.length) {
      sec('Executive Watchboard');
      wb.forEach(w => { p('<div style="border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin:6px 0">');
        p('<div style="font-weight:700">' + esc(w.issue || w.development) + ' <span style="font-size:11px;color:#6b7280">' + [w.direction, w.confidence].filter(has).map(esc).join(' · ') + '</span></div>');
        p(fld('Status', w.status) + fld('Risk', w.risk) + fld('Opportunity', w.opportunity) + fld('Next indicator', w.nextIndicator || w.watchNext)); p('</div>'); });
    }

    b.sections.forEach(s => {
      sec(s.title || s.id); const items = arr(s.items);
      if (!items.length) { p('<div style="color:#6b7280">No material change.</div>'); return; }
      items.forEach(it => {
        p('<div style="margin:8px 0;padding-bottom:8px;border-bottom:1px solid #eee">');
        p('<div style="font-weight:700;font-size:15px">' + esc(it.headline) + (has(it.priority) ? ' <span style="font-size:11px;color:#b45309">[' + esc(it.priority) + ']</span>' : '') + (has(it.confidence) ? ' <span style="font-size:11px;color:#6b7280">(' + esc(it.confidence) + ')</span>' : '') + (it.indicators && has(it.indicators.level) ? ' <span style="font-size:11px;color:#111">I&amp;W: ' + esc(it.indicators.level) + '</span>' : '') + '</div>');
        ITEM_FIELDS.forEach(f => { p(fld(f[1], it[f[0]])); });
        if (it.indicators && arr(it.indicators.list).length) p(fld('Indicators', arr(it.indicators.list).join('; ')));
        if (it.alt) p(fld('Leading', it.alt.leading) + fld('Alternative', it.alt.alternative) + fld('Wildcard', it.alt.wildcard));
        if (it.scenarios) p(fld('Most likely', it.scenarios.mostLikely) + fld('Best case', it.scenarios.bestCase) + fld('Worst case', it.scenarios.worstCase) + fld('High-impact/low-prob', it.scenarios.highImpactLowProb));
        if (arr(it.impactChain).filter(has).length) p('<div style="margin:4px 0;font-size:12px"><strong>Impact chain:</strong> ' + arr(it.impactChain).filter(has).map(esc).join(' &rarr; ') + '</div>');
        if (has(it.thirtySeconds)) p('<div style="border-left:3px solid #3b82f6;background:#eff6ff;padding:5px 8px;margin-top:5px"><strong>30 seconds with leadership:</strong> ' + esc(it.thirtySeconds) + '</div>');
        if (has(it.takeaway)) p('<div style="border-left:3px solid #3d6fe0;background:#f8fafc;padding:5px 8px;margin-top:5px"><strong>Takeaway:</strong> ' + esc(it.takeaway) + '</div>');
        const srcs = arr(it.sources).filter(x => x && (x.url || x.title));
        if (srcs.length) p('<div style="font-size:12px;margin-top:4px">Sources: ' + srcs.map(x => A(x.url, x.title || x.url)).join(' · ') + '</div>');
        p('</div>');
      });
    });

    if (b.crossDomain.length) { sec('Cross-Domain Connections'); b.crossDomain.forEach(c => p('<div style="margin:5px 0"><strong>A:</strong> ' + esc(c.a) + '<br><strong>B:</strong> ' + esc(c.b) + '<br>&rarr; ' + esc(c.implication) + '</div>')); }

    if (b.strategicWarning.length) { sec('⚠ Strategic Warning'); b.strategicWarning.forEach(w => p('<div style="margin:6px 0;border:1px solid #fecaca;border-radius:6px;padding:8px">' + fld('Observed indicators', w.indicators) + fld('Assessment', w.assessment) + fld('Potential impact', w.impact) + fld('Increase concern', w.increaseConcern) + fld('Reduce concern', w.reduceConcern) + (has(w.confidence) ? '<div style="color:#6b7280;font-size:12px">Confidence: ' + esc(w.confidence) + '</div>' : '') + '</div>')); }
    if (b.patterns.length) { sec('Intelligence Patterns Detected'); b.patterns.forEach(x => p('<div style="margin:5px 0"><strong>' + esc(x.pattern) + '</strong>' + fld('Signals', x.signals) + fld('Possible meaning', x.meaning) + fld('Confirm', x.confirm) + fld('Disprove', x.disconfirm) + '</div>')); }
    if (b.techRadar.length) { sec('Technology Radar'); p('<ul style="margin:0;padding-left:18px">'); b.techRadar.forEach(t => p('<li><strong>[' + esc((t.stage||'')) + ']</strong> ' + esc(t.tech) + (has(t.note) ? ' — ' + esc(t.note) : '') + '</li>')); p('</ul>'); }
    if (b.contractRadar.length) { sec('Contracting & Acquisition Radar'); b.contractRadar.forEach(c => p('<div style="margin:5px 0"><strong>' + esc(c.program || c.agency) + (has(c.value) ? ' — ' + esc(c.value) : '') + '</strong>' + fld('Agency/customer', c.agency) + fld('Recipient/competitors', c.recipient) + fld('What', c.what) + fld('Why it matters', c.whyMatters) + fld('Opportunity signal', c.opportunitySignal) + '</div>')); }
    if (b.opportunities.length) { sec('Strategic Opportunities'); b.opportunities.forEach(o => p('<div style="margin:5px 0"><strong>' + esc(o.opportunity) + '</strong>' + fld('Evidence', o.evidence) + fld('Why it could matter', o.whyMatters) + fld('Time horizon', o.horizon) + fld('Who may benefit', o.whoBenefits) + fld('What to watch', o.watch) + '</div>')); }
    if (b.competitive.length) { sec('Industry Competitive Intelligence'); b.competitive.forEach(c => p('<div style="margin:5px 0"><strong>' + esc(c.org) + '</strong>' + fld('Action', c.action) + fld('So what', c.soWhat) + '</div>')); }
    if (b.underRadar.length) { sec('Under the Radar — What Others Are Missing'); b.underRadar.forEach(u => p('<div style="margin:5px 0"><strong>' + esc(u.development) + '</strong>' + fld('Why', u.why) + '</div>')); }

    if (b.resurfaced.length) { sec('Resurfaced Intelligence'); b.resurfaced.forEach(r => p('<div style="margin:6px 0"><strong>' + esc(r.issue) + '</strong>' + fld('Original event', r.originalEvent || r.originalTimeframe) + fld('New information', r.newInfo || r.newDevelopment) + fld('Why now', r.whyNow || r.whyResurfaced) + fld('What changed', r.whatChanged) + fld('Updated assessment', r.updatedAssessment || r.whyMattersNow) + '</div>')); }

    if (b.weakSignals.length) { sec('Weak Signals & Early Warning'); b.weakSignals.forEach(w => { if (typeof w === 'string') { p('<div>' + esc(w) + '</div>'); return; } p('<div style="margin:5px 0"><strong>' + esc(w.signal) + '</strong>' + fld('Why unusual', w.whyUnusual) + fld('Potential trend', w.potentialTrend || w.why) + fld('Would confirm', w.confirm) + fld('Would disconfirm', w.disconfirm) + '</div>'); }); }

    if (has(b.adImpact)) { sec('Impact to the Aerospace & Defense Industry'); p('<p>' + esc(b.adImpact) + '</p>'); }

    const oi = b.orgImpact;
    if (oi.immediate.length || oi.nearTerm.length || oi.strategic.length || oi.none.length) {
      sec('Impact to My Organization');
      const ob = (label, list) => { if (!list.length) return; p('<div style="font-weight:700;font-size:12px;text-transform:uppercase;color:#6b7280;margin-top:6px">' + esc(label) + '</div><ul style="margin:0;padding-left:18px">'); list.forEach(x => p('<li>' + esc(x) + '</li>')); p('</ul>'); };
      ob('Immediate', oi.immediate); ob('Near-term', oi.nearTerm); ob('Strategic', oi.strategic); ob('No material impact', oi.none);
    } else if (b.myWork && b.myWork.length) { ulist('Impact to My Work', b.myWork, x => esc(x)); }

    if (b.appliesToMe.length) { sec('How This Applies to Me'); b.appliesToMe.forEach(a => { if (typeof a === 'string') { p('<div>' + esc(a) + '</div>'); return; } p('<div style="margin:6px 0">' + (has(a.topic) ? '<strong>' + esc(a.topic) + '</strong>' : '') + fld('Understand', a.understand) + fld('Why care', a.care) + fld('Could be asked', a.couldBeAsked) + fld('Investigate', a.investigate) + fld('Discuss', a.discuss) + fld('Monitor', a.monitor) + fld('Consider changing', a.consider) + '</div>'); }); }
    else if (b.whatToKnow && b.whatToKnow.length) ulist('What I Should Know Today', b.whatToKnow, x => esc(x));

    if (b.recommendations.length) {
      sec('Executive Recommendations');
      b.recommendations.forEach(r => p('<div style="margin:6px 0"><strong>[' + esc((r.category||'WATCH')) + ']</strong> ' + esc(r.recommendation) + (has(r.confidence)?' <span style="color:#6b7280">('+esc(r.confidence)+')</span>':'') + fld('Rationale', r.rationale) + fld('Evidence', r.evidence) + fld('Timing', r.timing) + fld('Owner', r.owner) + fld('Trigger', r.trigger) + fld('Risk of action', r.riskOfAction) + fld('Risk of inaction', r.riskOfInaction) + '</div>'));
    } else if (b.actions && b.actions.length) { ulist('Executive Action Items', b.actions, a => '<strong>' + esc(a.type || 'WATCH') + ':</strong> ' + esc(a.text)); }

    if (b.decisionMemos.length) { sec('Decision Memos'); b.decisionMemos.forEach(m => { p('<div style="border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin:6px 0"><strong>Decision: ' + esc(m.decision) + '</strong>' + fld('Why now', m.whyNow) + fld('Background', m.background)); arr(m.options).forEach((o,i) => p('<div style="margin-left:8px"><strong>Option ' + (i+1) + (has(o.label)?': '+esc(o.label):'') + '</strong>' + fld('Benefits', o.benefits) + fld('Risks', o.risks) + '</div>')); p(fld('Recommended', m.recommended) + fld('Reason', m.reason) + fld('What would change it', m.whatWouldChange) + fld('Decision date', m.decisionDate) + '</div>'); }); }

    if (b.questionsToAsk.length) { sec('Questions I Should Be Asking'); p('<ul style="margin:0;padding-left:18px">'); b.questionsToAsk.forEach(q => p('<li>' + (has(q.audience) ? '<em>' + esc(q.audience) + ':</em> ' : '') + esc(q.question || q) + '</li>')); p('</ul>'); }

    const qa = b.questionsAsked.length ? b.questionsAsked : b.execQuestions;
    if (qa.length) { sec('Questions I May Be Asked'); qa.forEach(q => p('<div style="margin:5px 0"><strong>Q:</strong> ' + esc(q.question || q.q) + '<br><span style="color:#374151"><strong>A:</strong> ' + esc(q.answer || q.a) + '</span>' + (has(q.evidence) ? '<br><span style="color:#6b7280;font-size:12px">Evidence: ' + esc(q.evidence) + '</span>' : '') + (has(q.caveat) ? '<br><span style="color:#6b7280;font-size:12px">Caveat: ' + esc(q.caveat) + '</span>' : '') + '</div>')); }

    ulist('What to Watch Next — 24 to 72 Hours', b.watch2472h.length ? b.watch2472h : b.watch24h, x => esc(x));
    ulist('7–30 Day Outlook', b.watch730d, x => esc(x));
    ulist('3–12 Month Strategic Outlook', b.watch312mo, x => esc(x));

    if (b.strategicSurprise.length) { sec('Strategic Surprise Watch'); b.strategicSurprise.forEach(s => p('<div style="margin:5px 0"><strong>' + esc(s.development) + '</strong>' + fld('Why', s.why) + fld('Potential impact', s.impact) + '</div>')); }
    ulist('What Could We Be Wrong About?', b.wrongAbout, x => esc(x));
    if (b.redTeam.length) { sec('Red-Team Review'); b.redTeam.forEach(r => p('<div style="margin:5px 0">' + (has(r.issue) ? '<strong>' + esc(r.issue) + '</strong>' : '') + fld('Objection', r.objection) + fld('Analytic response', r.response) + '</div>')); }
    if (b.forecastReview.length) { sec('Intelligence Performance Review'); p('<ul style="margin:0;padding-left:18px">'); b.forecastReview.forEach(f => p('<li><strong>' + esc((f.outcome||'PENDING')) + ':</strong> ' + esc(f.priorForecast) + (has(f.date)?' ('+esc(f.date)+')':'') + (has(f.note)?' — '+esc(f.note):'') + '</li>')); p('</ul>'); }

    if (b.deepDive && (has(b.deepDive.topic) || has(b.deepDive.explanation))) { sec('2-Minute Deep Dive' + (has(b.deepDive.topic) ? ' — ' + esc(b.deepDive.topic) : '')); p('<p>' + esc(b.deepDive.explanation) + '</p>'); }
    if (has(b.bigPicture)) { sec('The Big Picture'); p('<p>' + esc(b.bigPicture) + '</p>'); }
    if (has(b.oneThing)) { p('<div style="border:2px solid #3d6fe0;border-radius:8px;padding:12px;margin:16px 0;background:#eef2ff"><div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:800;color:#3d6fe0;margin-bottom:4px">If You Remember Only One Thing Today</div><div style="font-weight:600;font-size:15px">' + esc(b.oneThing) + '</div></div>'); }

    if (b.sources.length) { sec('Source Notes'); b.sources.forEach(s => { p('<div style="margin:5px 0;font-size:13px">'); if (has(s.claim)) p('<div style="font-weight:600">' + esc(s.claim) + (has(s.confidence) ? ' <span style="color:#6b7280">(' + esc(s.confidence) + ')</span>' : '') + '</div>'); p(fld('Source', s.source) + fld('Published', s.pubDate) + fld('Event date', s.eventDate)); const prim = arr(s.primary).filter(Boolean), corr = arr(s.corroborating).filter(Boolean); if (prim.length) p('<div>Primary: ' + prim.map(u => A(u,u)).join(' · ') + '</div>'); if (corr.length) p('<div>Corroborating: ' + corr.map(u => A(u,u)).join(' · ') + '</div>'); p('</div>'); }); }

    const g = b.gaps;
    if (['confirmedFacts','assumptions','intelGaps','competing','confidenceLimits','whatWouldChange','collectionPriorities','lowConfidence','conflicting'].some(k => g[k].length)) {
      sec('Assumptions, Intelligence Gaps & Analytic Confidence');
      const gb = (l, list) => { if (!list.length) return; p('<div style="font-weight:700;font-size:12px;text-transform:uppercase;color:#6b7280;margin-top:6px">' + esc(l) + '</div><ul style="margin:0;padding-left:18px">'); list.forEach(x => { if (x && typeof x === 'object') p('<li><strong>' + esc(x.assumption) + '</strong>' + (has(x.whyRequired)?' — why: '+esc(x.whyRequired):'') + (has(x.impactIfWrong)?' · if wrong: '+esc(x.impactIfWrong):'') + '</li>'); else p('<li>' + esc(x) + '</li>'); }); p('</ul>'); };
      gb('Confirmed facts', g.confirmedFacts); gb('Assumptions', g.assumptions); gb('Intelligence gaps', g.intelGaps); gb('Competing assessments', g.competing); gb('Confidence limitations', g.confidenceLimits); gb('Conflicting information', g.conflicting); gb('Low-confidence reporting', g.lowConfidence); gb('What would change our assessment', g.whatWouldChange); gb('Collection priorities', g.collectionPriorities);
    }

    p('<div style="margin-top:20px;padding-top:8px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af">Generated from lawful, publicly available, non-classified open-source information. Not a classified product. Assessments are analytic judgments, not confirmed fact.</div>');
    p('</div>');
    return S.join('');
  }

  // ---------- Plain text ----------
  function toPlainText(brief) {
    const b = M.render.normalize(brief); const L = []; const line = s => L.push(s == null ? '' : String(s));
    const H = t => { line(''); line('== ' + t.toUpperCase() + ' =='); };
    const f = (l, v) => { if (has(v)) line('    ' + l + ': ' + v); };
    line((brief.date || '') + '  |  ' + b.classification); if (b.reportingWindow) line('Reporting window: ' + b.reportingWindow);
    if (has(b.preheader)) line(b.preheader);
    if (b.barometer.length) { H('Threat / Opportunity Barometer'); b.barometer.forEach(x => line('- ' + (x.category||'') + ': ' + (x.status||'') + ' ' + ({UP:'↑',FLAT:'→',DOWN:'↓'})[(x.direction||'').toUpperCase()] + (has(x.confidence)?' ['+x.confidence+']':'') + (has(x.reason)?' — '+x.reason:''))); }
    if (b.bluf.length) { H('BLUF'); b.bluf.forEach(x => { if (typeof x === 'string') { line('- ' + x); return; } line('- ' + x.what); f('Why', x.matters); f('Could change', x.changes || x.changed); f('Need to know', x.needToKnow); }); }
    if (b.top3.length) { H('The 3 Things I Cannot Afford to Miss'); b.top3.forEach((t,i) => { line((i+1) + '. ' + t.development + (has(t.confidence)?'  ('+t.confidence+')':'')); f('Assessment', t.assessment); f('Why matters', t.whyMatters); f('Org impact', t.orgImpact); f('My impact', t.myImpact); f('Recommendation', t.recommendation); }); }
    const wb = b.watchboard.length ? b.watchboard : b.watchlist;
    if (wb.length) { H('Executive Watchboard'); wb.forEach(w => { line('- ' + (w.issue||w.development) + '  [' + [w.direction, w.confidence].filter(has).join('/') + ']'); f('Status', w.status); f('Risk', w.risk); f('Opportunity', w.opportunity); f('Next indicator', w.nextIndicator || w.watchNext); }); }
    b.sections.forEach(s => { H(s.title || s.id); const items = arr(s.items); if (!items.length) { line('No material change.'); return; } items.forEach(it => {
      line('• ' + it.headline + (has(it.priority)?' ['+it.priority+']':'') + (has(it.confidence)?' ('+it.confidence+')':'') + (it.indicators&&has(it.indicators.level)?' I&W:'+it.indicators.level:''));
      ITEM_FIELDS.forEach(ff => f(ff[1], it[ff[0]]));
      if (it.indicators && arr(it.indicators.list).length) f('Indicators', arr(it.indicators.list).join('; '));
      if (it.alt) { f('Leading', it.alt.leading); f('Alternative', it.alt.alternative); f('Wildcard', it.alt.wildcard); }
      if (it.scenarios) { f('Most likely', it.scenarios.mostLikely); f('Best case', it.scenarios.bestCase); f('Worst case', it.scenarios.worstCase); f('High-impact/low-prob', it.scenarios.highImpactLowProb); }
      if (arr(it.impactChain).filter(has).length) f('Impact chain', arr(it.impactChain).filter(has).join(' -> '));
      f('30 seconds', it.thirtySeconds);
      f('Takeaway', it.takeaway);
      const srcs = arr(it.sources).filter(x => x && x.url); if (srcs.length) f('Sources', srcs.map(x => x.url).join('  '));
    }); });
    const ul = (t, list, mapFn) => { if (!list.length) return; H(t); list.forEach(x => line('- ' + mapFn(x))); };
    if (b.crossDomain.length) { H('Cross-Domain Connections'); b.crossDomain.forEach(c => { line('A: ' + c.a); line('B: ' + c.b); line('=> ' + c.implication); line(''); }); }
    if (b.strategicWarning.length) { H('!! Strategic Warning'); b.strategicWarning.forEach(w => { f('Indicators', w.indicators); f('Assessment', w.assessment); f('Impact', w.impact); f('Increase concern', w.increaseConcern); f('Reduce concern', w.reduceConcern); f('Confidence', w.confidence); }); }
    if (b.patterns.length) { H('Intelligence Patterns'); b.patterns.forEach(x => { line('• ' + x.pattern); f('Signals', x.signals); f('Meaning', x.meaning); f('Confirm', x.confirm); f('Disprove', x.disconfirm); }); }
    if (b.techRadar.length) { H('Technology Radar'); b.techRadar.forEach(t => line('- [' + (t.stage||'') + '] ' + t.tech + (has(t.note)?' — '+t.note:''))); }
    if (b.contractRadar.length) { H('Contracting & Acquisition Radar'); b.contractRadar.forEach(c => { line('• ' + (c.program||c.agency) + (has(c.value)?' — '+c.value:'')); f('Agency', c.agency); f('Recipient', c.recipient); f('What', c.what); f('Why', c.whyMatters); f('Opportunity', c.opportunitySignal); }); }
    if (b.opportunities.length) { H('Strategic Opportunities'); b.opportunities.forEach(o => { line('• ' + o.opportunity); f('Evidence', o.evidence); f('Why', o.whyMatters); f('Horizon', o.horizon); f('Who benefits', o.whoBenefits); f('Watch', o.watch); }); }
    if (b.competitive.length) { H('Competitive Intelligence'); b.competitive.forEach(c => { line('• ' + c.org); f('Action', c.action); f('So what', c.soWhat); }); }
    if (b.underRadar.length) { H('Under the Radar'); b.underRadar.forEach(u => { line('• ' + u.development); f('Why', u.why); }); }
    if (b.resurfaced.length) { H('Resurfaced Intelligence'); b.resurfaced.forEach(r => { line('• ' + r.issue); f('Original', r.originalEvent||r.originalTimeframe); f('New info', r.newInfo||r.newDevelopment); f('Why now', r.whyNow||r.whyResurfaced); f('What changed', r.whatChanged); f('Updated assessment', r.updatedAssessment||r.whyMattersNow); }); }
    if (b.weakSignals.length) { H('Weak Signals & Early Warning'); b.weakSignals.forEach(w => { if (typeof w === 'string') { line('- ' + w); return; } line('• ' + w.signal); f('Why unusual', w.whyUnusual); f('Potential trend', w.potentialTrend||w.why); f('Confirm', w.confirm); f('Disconfirm', w.disconfirm); }); }
    if (has(b.adImpact)) { H('Impact to the A&D Industry'); line(b.adImpact); }
    const oi = b.orgImpact;
    if (oi.immediate.length || oi.nearTerm.length || oi.strategic.length || oi.none.length) { H('Impact to My Organization'); const ob=(l,list)=>list.forEach(x=>line('['+l+'] '+x)); ob('Immediate',oi.immediate); ob('Near-term',oi.nearTerm); ob('Strategic',oi.strategic); ob('None',oi.none); }
    else if (b.myWork && b.myWork.length) ul('Impact to My Work', b.myWork, x => x);
    if (b.appliesToMe.length) { H('How This Applies to Me'); b.appliesToMe.forEach(a => { if (typeof a === 'string') { line('- ' + a); return; } if (has(a.topic)) line('• ' + a.topic); f('Understand', a.understand); f('Why care', a.care); f('Could be asked', a.couldBeAsked); f('Investigate', a.investigate); f('Discuss', a.discuss); f('Monitor', a.monitor); f('Consider', a.consider); }); }
    else if (b.whatToKnow && b.whatToKnow.length) ul('What I Should Know Today', b.whatToKnow, x => x);
    if (b.recommendations.length) { H('Executive Recommendations'); b.recommendations.forEach(r => { line('[' + (r.category||'WATCH') + '] ' + r.recommendation + (has(r.confidence)?'  ('+r.confidence+')':'')); f('Rationale', r.rationale); f('Evidence', r.evidence); f('Timing', r.timing); f('Owner', r.owner); f('Trigger', r.trigger); f('Risk of action', r.riskOfAction); f('Risk of inaction', r.riskOfInaction); }); }
    else if (b.actions && b.actions.length) ul('Executive Action Items', b.actions, a => (a.type||'WATCH') + ': ' + a.text);
    if (b.decisionMemos.length) { H('Decision Memos'); b.decisionMemos.forEach(m => { line('Decision: ' + m.decision); f('Why now', m.whyNow); f('Background', m.background); arr(m.options).forEach((o,i)=>{ line('    Option ' + (i+1) + (has(o.label)?': '+o.label:'')); f('  Benefits', o.benefits); f('  Risks', o.risks); }); f('Recommended', m.recommended); f('Reason', m.reason); f('What would change', m.whatWouldChange); f('Decision date', m.decisionDate); }); }
    if (b.questionsToAsk.length) { H('Questions I Should Be Asking'); b.questionsToAsk.forEach(q => line('- ' + (has(q.audience)?'['+q.audience+'] ':'') + (q.question||q))); }
    const qa = b.questionsAsked.length ? b.questionsAsked : b.execQuestions;
    if (qa.length) { H('Questions I May Be Asked'); qa.forEach(q => { line('Q: ' + (q.question||q.q)); line('A: ' + (q.answer||q.a)); f('Evidence', q.evidence); f('Caveat', q.caveat); }); }
    ul('Watch Next — 24 to 72h', b.watch2472h.length ? b.watch2472h : b.watch24h, x => x);
    ul('7-30 Day Outlook', b.watch730d, x => x);
    ul('3-12 Month Strategic Outlook', b.watch312mo, x => x);
    if (b.strategicSurprise.length) { H('Strategic Surprise Watch'); b.strategicSurprise.forEach(s => { line('• ' + s.development); f('Why', s.why); f('Impact', s.impact); }); }
    ul('What Could We Be Wrong About?', b.wrongAbout, x => x);
    if (b.redTeam.length) { H('Red-Team Review'); b.redTeam.forEach(r => { if (has(r.issue)) line('• ' + r.issue); f('Objection', r.objection); f('Response', r.response); }); }
    if (b.forecastReview.length) { H('Intelligence Performance Review'); b.forecastReview.forEach(x => line('[' + (x.outcome||'PENDING') + '] ' + x.priorForecast + (has(x.note)?' — '+x.note:''))); }
    const g = b.gaps;
    if (['confirmedFacts','assumptions','intelGaps','competing','confidenceLimits','whatWouldChange','collectionPriorities','lowConfidence','conflicting'].some(k => g[k].length)) {
      H('Assumptions, Intelligence Gaps & Analytic Confidence');
      const gb = (l, list) => list.forEach(x => line('[' + l + '] ' + (x && typeof x === 'object' ? (x.assumption + (has(x.impactIfWrong)?' (if wrong: '+x.impactIfWrong+')':'')) : x)));
      gb('Fact', g.confirmedFacts); gb('Assumption', g.assumptions); gb('Gap', g.intelGaps); gb('Competing', g.competing); gb('Conf-limit', g.confidenceLimits); gb('Conflict', g.conflicting); gb('Low-conf', g.lowConfidence); gb('Would-change', g.whatWouldChange); gb('Collect', g.collectionPriorities);
    }
    if (b.deepDive && (has(b.deepDive.topic) || has(b.deepDive.explanation))) { H('2-Minute Deep Dive' + (has(b.deepDive.topic) ? ' — ' + b.deepDive.topic : '')); line(b.deepDive.explanation); }
    if (has(b.bigPicture)) { H('The Big Picture'); line(b.bigPicture); }
    if (has(b.oneThing)) { H('If You Remember Only One Thing Today'); line(b.oneThing); }
    line(''); line('— Open-source, non-classified. Analytic judgments, not confirmed fact.');
    return L.join('\n');
  }

  async function copyEmail(brief, branding) {
    const html = toEmailHtml(brief, branding); const text = toPlainText(brief);
    try {
      if (root.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
        await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([text], { type: 'text/plain' }) })]);
        return 'rich';
      }
      await navigator.clipboard.writeText(text); return 'text';
    } catch (e) {
      const ta = doc.createElement('textarea'); ta.value = text; doc.body.appendChild(ta); ta.select();
      try { doc.execCommand('copy'); } finally { doc.body.removeChild(ta); }
      return 'text';
    }
  }

  function printBrief() { root.print(); }

  async function sendEmail(brief, branding) {
    const cfg = M.store.getEmail();
    if (!cfg.publicKey || !cfg.serviceId || !cfg.templateId) throw new Error('EmailJS not configured (public key, service ID, template ID required).');
    if (!cfg.to) throw new Error('No recipient email set.');
    const body = { service_id: cfg.serviceId, template_id: cfg.templateId, user_id: cfg.publicKey,
      template_params: { subject: subjectFor(brief, branding && branding.orgName), to_email: cfg.to, html: toEmailHtml(brief, branding), message: toPlainText(brief), date: brief.date || '' } };
    const res = await fetch('https://api.emailjs.com/api/v1.0/email/send', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const t = await res.text(); if (!res.ok) throw new Error('EmailJS: ' + (t || res.status)); return true;
  }

  function downloadJSON(obj, filename) {
    const blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = doc.createElement('a'); a.href = url; a.download = filename || 'meridian-brief.json';
    doc.body.appendChild(a); a.click(); doc.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  M.exporter = { toEmailHtml, toPlainText, copyEmail, printBrief, sendEmail, downloadJSON, subjectFor };
})(window);
