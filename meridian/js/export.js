/* MERIDIAN — Export & delivery.
 * Builds an inline-styled HTML email body and a plain-text version, copies both
 * to the clipboard, prints/saves as PDF, sends via the EmailJS REST API (no SDK
 * load required), and downloads briefs as JSON. All model/user content is HTML-
 * escaped before it enters the email string.
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

  function subjectFor(b, orgName) {
    return (orgName || 'MERIDIAN') + ' Daily Intelligence Brief — ' + (b.date || '');
  }

  // ---------- HTML email ----------
  function toEmailHtml(brief, branding) {
    const b = M.render.normalize(brief);
    const org = (branding && (branding.orgName || '').trim()) || 'MERIDIAN';
    const logo = branding && M.branding.sanitizeLogo(branding.logoUrl);
    const S = []; // string parts
    const p = s => S.push(s);
    const sec = t => p('<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #3d6fe0;padding-bottom:4px;margin:22px 0 8px;color:#1f2937">' + esc(t) + '</h2>');
    const fld = (l, v) => v ? '<div style="margin:3px 0"><span style="font-weight:700;font-size:11px;text-transform:uppercase;color:#6b7280">' + esc(l) + ':</span> ' + esc(v) + '</div>' : '';

    p('<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:0 auto;color:#111;font-size:14px;line-height:1.5">');
    p('<div style="border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px">');
    if (logo) p('<img src="' + esc(logo) + '" alt="" style="height:34px;vertical-align:middle;margin-right:8px">');
    p('<span style="font-size:18px;font-weight:800;vertical-align:middle">' + esc(org) + '</span>');
    p('<div style="font-size:12px;color:#6b7280;margin-top:4px">Daily Executive Intelligence Brief · ' + esc(b.classification) + '</div>');
    p('<div style="font-size:12px;color:#6b7280">' + esc(b.date) + (b.reportingWindow ? ' · ' + esc(b.reportingWindow) : '') + '</div>');
    p('</div>');

    if (b.bluf.length) {
      sec('BLUF — Bottom Line Up Front');
      p('<ul style="margin:0;padding-left:18px">');
      b.bluf.forEach(x => {
        if (typeof x === 'string') { p('<li>' + esc(x) + '</li>'); return; }
        p('<li><strong>' + esc(x.what) + '</strong>' + (x.matters ? ' <span style="color:#6b7280">— Why: ' + esc(x.matters) + '</span>' : '') + (x.changed ? ' <span style="color:#6b7280">· Changed: ' + esc(x.changed) + '</span>' : '') + '</li>');
      });
      p('</ul>');
    }

    if (b.watchlist.length) {
      sec('Executive Watchlist');
      b.watchlist.forEach(w => {
        p('<div style="border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin:6px 0">');
        p('<div style="font-weight:700">' + esc(w.development) + '</div>');
        p('<div style="font-size:12px;color:#6b7280">' + [w.priority, w.direction, w.confidence].filter(Boolean).map(esc).join(' · ') + '</div>');
        p(fld('Why', w.why) + fld('Watch next', w.watchNext));
        p('</div>');
      });
    }

    b.sections.forEach(s => {
      sec(s.title || s.id);
      const items = arr(s.items);
      if (!items.length) { p('<div style="color:#6b7280">No material change.</div>'); return; }
      items.forEach(it => {
        p('<div style="margin:8px 0;padding-bottom:8px;border-bottom:1px solid #eee">');
        p('<div style="font-weight:700;font-size:15px">' + esc(it.headline) + (it.priority ? ' <span style="font-size:11px;color:#b45309">[' + esc(it.priority) + ']</span>' : '') + (it.confidence ? ' <span style="font-size:11px;color:#6b7280">(' + esc(it.confidence) + ')</span>' : '') + '</div>');
        p(fld('Threat', it.threat) + fld('Affected', it.affected) + fld('Exploitation', it.exploitation) + fld('Defensive priority', it.defensivePriority));
        p(fld('Fact', it.fact) + fld('Assessment', it.assessment) + fld('Outlook', it.outlook) + fld('Why it matters', it.whyMatters) + fld('A&D impact', it.adImpact) + fld('Org relevance', it.orgRelevance));
        if (it.takeaway) p('<div style="border-left:3px solid #3d6fe0;background:#f8fafc;padding:5px 8px;margin-top:5px"><strong>Takeaway:</strong> ' + esc(it.takeaway) + '</div>');
        const srcs = arr(it.sources).filter(x => x && (x.url || x.title));
        if (srcs.length) p('<div style="font-size:12px;margin-top:4px">Sources: ' + srcs.map(x => A(x.url, x.title || x.url)).join(' · ') + '</div>');
        p('</div>');
      });
    });

    const bl = (title, list, mapFn) => {
      if (!list.length) return; sec(title); p('<ul style="margin:0;padding-left:18px">');
      list.forEach(x => p('<li>' + mapFn(x) + '</li>')); p('</ul>');
    };
    if (b.resurfaced.length) {
      sec('Resurfaced / Continuing Developments');
      b.resurfaced.forEach(r => { p('<div style="margin:6px 0"><strong>' + esc(r.issue) + '</strong>' + fld('Original timeframe', r.originalTimeframe) + fld('New development', r.newDevelopment) + fld('Why it resurfaced', r.whyResurfaced) + fld('What changed', r.whatChanged) + fld('Why it matters now', r.whyMattersNow) + '</div>'); });
    }
    bl('Weak Signals', b.weakSignals, x => typeof x === 'string' ? esc(x) : ('<strong>' + esc(x.signal) + '</strong>' + (x.why ? ' — ' + esc(x.why) : '')));
    if (b.adImpact) { sec('Aerospace & Defense Impact'); p('<p>' + esc(b.adImpact) + '</p>'); }
    bl('Impact to My Work', b.myWork, x => esc(x));
    bl('What I Should Know Today', b.whatToKnow, x => esc(x));
    if (b.execQuestions.length) { sec('Questions Executives May Ask'); b.execQuestions.forEach(q => p('<div style="margin:5px 0"><strong>Q:</strong> ' + esc(q.q) + '<br><span style="color:#374151"><strong>A:</strong> ' + esc(q.a) + '</span></div>')); }
    bl('What to Watch — Next 24 Hours', b.watch24h, x => esc(x));
    bl('What to Watch — 7 to 30 Days', b.watch730d, x => esc(x));
    if (b.actions.length) { sec('Executive Action Items'); p('<ul style="margin:0;padding-left:18px">'); b.actions.forEach(a => p('<li><strong>' + esc((a.type || 'WATCH')) + ':</strong> ' + esc(a.text) + '</li>')); p('</ul>'); }

    if (b.sources.length) {
      sec('Source & Confidence Notes');
      b.sources.forEach(s => {
        p('<div style="margin:5px 0;font-size:13px">');
        if (s.claim) p('<div style="font-weight:600">' + esc(s.claim) + (s.confidence ? ' <span style="color:#6b7280">(' + esc(s.confidence) + ')</span>' : '') + '</div>');
        const prim = arr(s.primary).filter(Boolean), corr = arr(s.corroborating).filter(Boolean);
        if (prim.length) p('<div>Primary: ' + prim.map(u => A(u, u)).join(' · ') + '</div>');
        if (corr.length) p('<div>Corroborating: ' + corr.map(u => A(u, u)).join(' · ') + '</div>');
        p(fld('Conflicting', s.conflicts) + fld('Unverified', s.unverified));
        p('</div>');
      });
    }
    const g = b.gaps;
    if (g.assumptions.length || g.intelGaps.length || g.conflicting.length || g.lowConfidence.length || g.collectionPriorities.length) {
      sec('Assumptions, Intelligence Gaps & Confidence');
      const gb = (l, list) => { if (list.length) { p('<div style="font-weight:700;font-size:12px;text-transform:uppercase;color:#6b7280;margin-top:6px">' + esc(l) + '</div><ul style="margin:0;padding-left:18px">'); list.forEach(x => p('<li>' + esc(x) + '</li>')); p('</ul>'); } };
      gb('Assumptions', g.assumptions); gb('Intelligence gaps', g.intelGaps); gb('Conflicting information', g.conflicting);
      gb('Low-confidence reporting', g.lowConfidence); gb('Collection priorities', g.collectionPriorities);
    }

    p('<div style="margin-top:20px;padding-top:8px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af">Generated from lawful, publicly available, non-classified open-source information. Not a classified product. Assessments are analytic judgments, not confirmed fact.</div>');
    p('</div>');
    return S.join('');
  }

  // ---------- Plain text ----------
  function toPlainText(brief) {
    const b = M.render.normalize(brief);
    const L = [];
    const line = s => L.push(s == null ? '' : String(s));
    const H = t => { line(''); line('== ' + t.toUpperCase() + ' =='); };
    line((brief.date || '') + '  |  ' + b.classification);
    if (b.reportingWindow) line('Reporting window: ' + b.reportingWindow);
    if (b.bluf.length) { H('BLUF'); b.bluf.forEach(x => line('- ' + (typeof x === 'string' ? x : (x.what + (x.matters ? '  (Why: ' + x.matters + ')' : '') + (x.changed ? '  (Changed: ' + x.changed + ')' : ''))))); }
    if (b.watchlist.length) { H('Executive Watchlist'); b.watchlist.forEach(w => { line('- ' + w.development + '  [' + [w.priority, w.direction, w.confidence].filter(Boolean).join('/') + ']'); if (w.why) line('    Why: ' + w.why); if (w.watchNext) line('    Watch next: ' + w.watchNext); }); }
    b.sections.forEach(s => { H(s.title || s.id); const items = arr(s.items); if (!items.length) { line('No material change.'); return; } items.forEach(it => {
      line('• ' + it.headline + (it.priority ? ' [' + it.priority + ']' : '') + (it.confidence ? ' (' + it.confidence + ')' : ''));
      ['threat','affected','exploitation','defensivePriority','fact','assessment','outlook','whyMatters','adImpact','orgRelevance','takeaway'].forEach(k => { if (it[k]) line('    ' + k + ': ' + it[k]); });
      const srcs = arr(it.sources).filter(x => x && x.url); if (srcs.length) line('    Sources: ' + srcs.map(x => x.url).join('  '));
    }); });
    const bl = (t, list, f) => { if (list.length) { H(t); list.forEach(x => line('- ' + f(x))); } };
    if (b.resurfaced.length) { H('Resurfaced / Continuing'); b.resurfaced.forEach(r => { line('• ' + r.issue); ['originalTimeframe','newDevelopment','whyResurfaced','whatChanged','whyMattersNow'].forEach(k => r[k] && line('    ' + k + ': ' + r[k])); }); }
    bl('Weak Signals', b.weakSignals, x => typeof x === 'string' ? x : (x.signal + (x.why ? ' — ' + x.why : '')));
    if (b.adImpact) { H('Aerospace & Defense Impact'); line(b.adImpact); }
    bl('Impact to My Work', b.myWork, x => x);
    bl('What I Should Know Today', b.whatToKnow, x => x);
    if (b.execQuestions.length) { H('Questions Executives May Ask'); b.execQuestions.forEach(q => { line('Q: ' + q.q); line('A: ' + q.a); }); }
    bl('Watch — Next 24h', b.watch24h, x => x);
    bl('Watch — 7 to 30 Days', b.watch730d, x => x);
    bl('Executive Action Items', b.actions, a => (a.type || 'WATCH') + ': ' + a.text);
    const g = b.gaps;
    if (g.assumptions.length || g.intelGaps.length || g.lowConfidence.length || g.collectionPriorities.length || g.conflicting.length) {
      H('Assumptions, Intelligence Gaps & Confidence');
      const gb = (l, list) => list.forEach(x => line('[' + l + '] ' + x));
      gb('Assumption', g.assumptions); gb('Gap', g.intelGaps); gb('Conflict', g.conflicting); gb('Low-conf', g.lowConfidence); gb('Collect', g.collectionPriorities);
    }
    line(''); line('— Open-source, non-classified. Analytic judgments, not confirmed fact.');
    return L.join('\n');
  }

  // ---------- Clipboard ----------
  async function copyEmail(brief, branding) {
    const html = toEmailHtml(brief, branding);
    const text = toPlainText(brief);
    try {
      if (root.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
        await navigator.clipboard.write([new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([text], { type: 'text/plain' })
        })]);
        return 'rich';
      }
      await navigator.clipboard.writeText(text);
      return 'text';
    } catch (e) {
      // last-resort textarea fallback
      const ta = doc.createElement('textarea'); ta.value = text; doc.body.appendChild(ta); ta.select();
      try { doc.execCommand('copy'); } finally { doc.body.removeChild(ta); }
      return 'text';
    }
  }

  // ---------- Print / PDF ----------
  function printBrief() { root.print(); }

  // ---------- EmailJS REST send ----------
  async function sendEmail(brief, branding) {
    const cfg = M.store.getEmail();
    if (!cfg.publicKey || !cfg.serviceId || !cfg.templateId) throw new Error('EmailJS not configured (public key, service ID, template ID required).');
    if (!cfg.to) throw new Error('No recipient email set.');
    const html = toEmailHtml(brief, branding);
    const text = toPlainText(brief);
    const body = {
      service_id: cfg.serviceId,
      template_id: cfg.templateId,
      user_id: cfg.publicKey,
      template_params: {
        subject: subjectFor(brief, branding && branding.orgName),
        to_email: cfg.to,
        html: html,
        message: text,
        date: brief.date || ''
      }
    };
    const res = await fetch('https://api.emailjs.com/api/v1.0/email/send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    });
    const t = await res.text();
    if (!res.ok) throw new Error('EmailJS: ' + (t || res.status));
    return true;
  }

  // ---------- JSON download ----------
  function downloadJSON(obj, filename) {
    const blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = doc.createElement('a'); a.href = url; a.download = filename || 'meridian-brief.json';
    doc.body.appendChild(a); a.click(); doc.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  M.exporter = { toEmailHtml, toPlainText, copyEmail, printBrief, sendEmail, downloadJSON, subjectFor };
})(window);
